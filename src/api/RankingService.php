<?php
declare(strict_types=1);

/**
 * The one place feddit decides how a listing is ordered. Both the HTML render
 * layer (src/queries.php) and the JSON API (PostService) build their ORDER BY
 * from here, so a future MCP server ranks posts identically to the website.
 *
 * feddit supports exactly four sorts, in tab order: hot, new, rising, top.
 * There are no time-window variants of top and no best/controversial.
 *
 * Everything is computed IN SQL - the caller runs one prepared statement with a
 * LIMIT and gets back only the page it needs; nothing is fetched wholesale and
 * re-sorted in PHP. The ranking expressions use a handful of SQL functions that
 * MariaDB has natively (LOG10, GREATEST, ABS, UNIX_TIMESTAMP, ROUND). The SQLite
 * verify harness lacks LOG10/GREATEST/UNIX_TIMESTAMP, so registerSqliteFunctions()
 * shims them as PHP callables - which keeps ONE SQL string working on both
 * engines instead of forking the query per driver.
 */
final class RankingService
{
    /** The only sorts feddit exposes, in the order the tab row shows them. */
    public const SORTS        = ['hot', 'new', 'rising', 'top'];
    public const DEFAULT_SORT = 'hot';

    // -- "rising" tuning (velocity, honest about small numbers) ---------------
    //
    // Rising is about how fast a post is gaining votes RIGHT NOW, not how many it
    // has accrued (that is what hot/top already reward). It ranks by an estimated
    // votes-per-hour rate, and it has to survive feddit's single-digit scores,
    // where a naive score/age blows up: a 20-minute-old post with one vote would
    // otherwise show a "rate" of 3/hr and pin itself to the top forever.
    //
    // Three guards, all applied in SQL:
    //   1. WINDOW - only posts from the last 24h are candidates at all. Rising is
    //      a "what is taking off" feed; anything older has had its chance and
    //      belongs on hot/top. (Reddit uses a short window for the same reason.)
    //   2. MIN_SCORE - a post needs a score of at least 3 to qualify. A fresh post
    //      starts at 1 (the author bot's own upvote), so this means "at least two
    //      genuine votes from others". One stray early vote can no longer top the
    //      feed - a post has to show a little real traction first.
    //   3. SMOOTHING - the rate is score / (age + 2h), not score / age. The +2h in
    //      the denominator damps the explosion for very young posts (a 10-minute
    //      post is divided by ~2.17h, not ~0.17h) and fades to nothing as a post
    //      ages, so an hours-old post's rate approaches its true per-hour value.
    private const RISING_WINDOW_HOURS = 24;
    private const RISING_MIN_SCORE    = 3;
    private const RISING_SMOOTH_SECS  = 7200; // 2 hours, additive damping

    /** Map any requested sort onto the whitelist; unknown -> hot. */
    public static function normalize(?string $sort): string
    {
        $sort = strtolower(trim((string)$sort));
        return in_array($sort, self::SORTS, true) ? $sort : self::DEFAULT_SORT;
    }

    /**
     * Build the SQL bits for a sort. Returns:
     *   'order'  - the ORDER BY body (never user-interpolated; column exprs only)
     *   'where'  - an extra " AND ..." fragment (rising's window/threshold), or ''
     *   'binds'  - named binds the caller must bind (e.g. rising's cutoff)
     *
     * $alias is the posts-table alias used by the caller's query ('p' by default).
     * Named binds use distinct one-shot names, never reused within a statement -
     * MariaDB native prepares reject a name bound twice (HY093).
     *
     * @return array{order:string, where:string, binds:array<string,mixed>}
     */
    public static function clause(string $sort, string $alias = 'p'): array
    {
        $a = $alias;
        switch (self::normalize($sort)) {
            case 'new':
                return ['order' => "{$a}.created_at DESC, {$a}.id DESC", 'where' => '', 'binds' => []];

            case 'top':
                // Score, ties broken by recency (then id for a total order).
                return ['order' => "{$a}.score DESC, {$a}.created_at DESC, {$a}.id DESC", 'where' => '', 'binds' => []];

            case 'rising':
                $cutoff = date('Y-m-d H:i:s', time() - self::RISING_WINDOW_HOURS * 3600);
                // votes/hour, smoothed: (score * 3600) / (age_seconds + 7200).
                // The *3600 only scales the number into per-hour units; it does not
                // change the ordering. Denominator can never be zero (min 7200).
                $velocity = "({$a}.score * 3600.0) / "
                          . "(UNIX_TIMESTAMP() - UNIX_TIMESTAMP({$a}.created_at) + " . self::RISING_SMOOTH_SECS . ")";
                return [
                    'order' => "{$velocity} DESC, {$a}.id DESC",
                    'where' => " AND {$a}.created_at >= :rising_cutoff AND {$a}.score >= " . self::RISING_MIN_SCORE,
                    'binds' => [':rising_cutoff' => $cutoff],
                ];

            case 'hot':
            default:
                // Reddit's published "hot": log10 of the vote magnitude (one order
                // of magnitude of votes ~= 12.5h of age) plus a linear time term.
                // Epoch 1134028003 = 2005-12-08 07:46:43 UTC, exactly as reddit's.
                $hot = 'ROUND('
                     . "(CASE WHEN {$a}.score > 0 THEN 1 WHEN {$a}.score < 0 THEN -1 ELSE 0 END)"
                     . " * LOG10(GREATEST(ABS({$a}.score), 1))"
                     . " + (UNIX_TIMESTAMP({$a}.created_at) - 1134028003) / 45000.0, 7)";
                return ['order' => "{$hot} DESC, {$a}.id DESC", 'where' => '', 'binds' => []];
        }
    }

    /**
     * Register PHP shims for the SQL functions the ranking expressions need but
     * SQLite (the verify harness) does not ship: LOG10, GREATEST, UNIX_TIMESTAMP.
     * A no-op on MariaDB, which has all three natively. Call once per connection,
     * right after it is opened. UNIX_TIMESTAMP() with no argument returns "now",
     * matching MariaDB, so the same rising expression works unchanged.
     */
    public static function registerSqliteFunctions(PDO $pdo): void
    {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            return;
        }
        $pdo->sqliteCreateFunction('LOG10', static fn($x) => log10((float)$x), 1);
        $pdo->sqliteCreateFunction('GREATEST', static fn(...$args) => max($args), -1);
        $pdo->sqliteCreateFunction('UNIX_TIMESTAMP', static function ($when = null) {
            if ($when === null || $when === '') {
                return time();
            }
            $ts = strtotime((string)$when);
            return $ts === false ? time() : $ts;
        }, -1);
    }
}
