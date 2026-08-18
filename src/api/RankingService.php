<?php
declare(strict_types=1);

/**
 * The one place feddit decides how a listing is ordered. Both the HTML render
 * layer (src/queries.php) and the JSON API (PostService) build their ORDER BY
 * from here, so a future MCP server ranks posts identically to the website.
 *
 * feddit exposes six sorts, in old.reddit's front-page tab order:
 *   best, hot, new, rising, controversial, top
 * (best and controversial are reddit's own; the sub-feddit tab row omits best,
 * exactly as old.reddit does - best is a front-page sort there and here.)
 *
 * Everything is computed IN SQL - the caller runs one prepared statement with a
 * LIMIT and gets back only the page it needs; nothing is fetched wholesale and
 * re-sorted in PHP. The ranking expressions use a handful of SQL functions that
 * MariaDB has natively (LOG10, GREATEST, ABS, UNIX_TIMESTAMP, ROUND, POWER,
 * SQRT). The SQLite verify harness lacks several of those, so
 * registerSqliteFunctions() shims them as PHP callables - which keeps ONE SQL
 * string working on both engines instead of forking the query per driver.
 *
 * -- ups / downs, and why they never contradict the score or the tooltip -------
 *
 * best (Wilson) and controversial need genuine UP and DOWN counts, not just the
 * net score. The votes table records individual votes (direction + bot/human),
 * and the hover tooltip counts those rows straight. But seeded content had its
 * `score` column set directly WITHOUT matching vote rows (deliberately, to keep
 * the tuned small-community distribution), so for most existing posts the rows
 * undercount the score. We reconcile with one expression that serves every post
 * uniformly (see upsDownsSql):
 *     downs = the real number of downvote ROWS   (exactly what the tooltip shows)
 *     ups   = score + downs                       (so ups - downs == score, ALWAYS)
 * For a genuinely-voted post whose rows already sum to its score this is exact -
 * ups collapses to the real upvote count. For a seeded post it keeps every real
 * downvote and treats the remaining score as upvotes: the honest split, and one
 * that can never contradict the displayed score OR the tooltip's down count.
 * Because the seed is tuned mostly-upvotes, real downvotes are scarce, so
 * controversial is legitimately near-empty - that is correct, not a bug.
 */
final class RankingService
{
    /** Every sort feddit exposes, in old.reddit's front-page tab order. */
    public const SORTS        = ['best', 'hot', 'new', 'rising', 'controversial', 'top'];
    public const DEFAULT_SORT = 'hot';

    // Wilson score interval z for 85% confidence - reddit's exact 'best'
    // constant. Held as a string so it interpolates into SQL byte-for-byte,
    // free of any float-formatting surprises.
    private const WILSON_Z = '1.281551565545';

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

            case 'best':
                // Reddit's 'best': the Wilson score interval LOWER bound. Ranks by
                // how confident we can be that the true up-ratio is high, so a 9/10
                // outranks a 1/1 (small samples are pulled toward the middle). n is
                // never 0 in practice - every post's score is >= 1, so ups >= 1 -
                // but the guard keeps the expression total anyway.
                [$ups, $downs] = self::upsDownsSql($a);
                $z  = self::WILSON_Z;
                $z2 = "({$z} * {$z})";
                $n  = "({$ups} + {$downs})";
                $p  = "({$ups} * 1.0 / {$n})";
                $wilson =
                      "(CASE WHEN {$n} <= 0 THEN 0 ELSE "
                    . "(({$p} + {$z2} / (2 * {$n})) "
                    . "- {$z} * SQRT(({$p} * (1 - {$p}) + {$z2} / (4 * {$n})) / {$n})) "
                    . "/ (1 + {$z2} / {$n}) END)";
                return [
                    'order' => "{$wilson} DESC, {$a}.score DESC, {$a}.created_at DESC, {$a}.id DESC",
                    'where' => '',
                    'binds' => [],
                ];

            case 'controversial':
                // Reddit's controversy: rewards a post that has BOTH a lot of votes
                // AND a near-even up/down split. Zero unless there is at least one
                // down and one up. magnitude = ups+downs; balance in (0,1] is the
                // ratio of the smaller side to the larger; score = magnitude**balance.
                // The WHERE floor (real downs > 0) means a mostly-upvoted site shows
                // an honestly sparse - often empty - controversial feed.
                [$cUps, $cDowns] = self::upsDownsSql($a);
                $mag = "({$cUps} + {$cDowns})";
                $bal = "(CASE WHEN {$cUps} > {$cDowns} THEN {$cDowns} * 1.0 / {$cUps}"
                     . " ELSE {$cUps} * 1.0 / {$cDowns} END)";
                $contro = "(CASE WHEN {$cDowns} <= 0 OR {$cUps} <= 0 THEN 0"
                        . " ELSE POWER({$mag}, {$bal}) END)";
                return [
                    'order' => "{$contro} DESC, {$a}.score DESC, {$a}.created_at DESC, {$a}.id DESC",
                    // Only genuinely contested posts: at least one real downvote AND
                    // a positive up side. A pure pile-on (all-down, ups <= 0) is
                    // disliked, not controversial, so it never enters this feed.
                    'where' => " AND {$cDowns} > 0 AND {$cUps} > 0",
                    'binds' => [],
                ];

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
     * SQL sub-expressions for a post's genuine (ups, downs), reconciled with the
     * stored score. See the class docblock for the full rationale. In short:
     *   downs = the real count of downvote ROWS (matches the hover tooltip)
     *   ups   = score + downs  =>  ups - downs == score, always.
     * Both are correlated scalar subqueries over the votes table, evaluated per
     * candidate post. That is a handful of trivial indexed COUNTs on a community
     * this small - cheap, and it keeps the whole change inside the ORDER BY /
     * WHERE fragments clause() already returns, touching no call site or SELECT
     * list. `vd` is a fresh alias so it never collides with the caller's joins.
     *
     * @return array{0:string,1:string} [upsExpr, downsExpr]
     */
    private static function upsDownsSql(string $a): array
    {
        $downs = "(SELECT COUNT(*) FROM votes vd"
               . " WHERE vd.target_type = 'post' AND vd.target_id = {$a}.id AND vd.direction = -1)";
        $ups   = "({$a}.score + {$downs})";
        return [$ups, $downs];
    }

    /**
     * Register PHP shims for the SQL functions the ranking expressions need but
     * SQLite (the verify harness) does not ship: LOG10, GREATEST, UNIX_TIMESTAMP,
     * POWER, SQRT. A no-op on MariaDB, which has all five natively. Call once per
     * connection, right after it is opened. UNIX_TIMESTAMP() with no argument
     * returns "now", matching MariaDB, so the same rising expression works
     * unchanged; POWER/SQRT back best + controversial the same way.
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
        $pdo->sqliteCreateFunction('POWER', static fn($base, $exp) => (float)$base ** (float)$exp, 2);
        $pdo->sqliteCreateFunction('SQRT',  static fn($x) => sqrt((float)$x), 1);
    }
}
