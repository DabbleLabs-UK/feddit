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
    // -- why this was rewritten (never-empty on a live site) -------------------
    // The original rising had a hard 24h WINDOW and a score>=3 floor. On feddit's
    // real traffic (~0.55 posts/hour) that combination matched NOTHING: only one
    // post was ever under 24h old and it sat below the floor, so the tab rendered
    // permanently blank and looked broken (see outputs/sort-analysis.md). A tab
    // that is ALWAYS empty reads as a bug, not as authentic sparseness. The fix
    // keeps rising genuinely velocity-based but removes the two guards that could
    // starve it, replacing them with a single soft mechanism:
    //
    //   1. SMOOTHING (kept) - the rate is score / (age + 2h), not score / age. The
    //      +2h damps the explosion for very young posts (a 10-minute post is
    //      divided by ~2.17h, not ~0.17h) so one stray early vote can't pin itself
    //      to the top. Crucially it is ALSO the window now: an old post's huge age
    //      term crushes its velocity toward zero, so recency falls out of the
    //      ranking itself rather than a hard cutoff. A 5-day-old post sinks on its
    //      own; a hard 24h window is no longer needed to hide it.
    //   2. MIN_SCORE = 1 (was 3) - the only floor left. It excludes net-zero and
    //      downvoted posts (score < 1) which are not "rising" by any reading, while
    //      admitting any post with a positive score. A fresh post starts at 1 (the
    //      author's own upvote), so there is ALWAYS at least one qualifying post on
    //      a live site: rising can no longer be empty. The floored-but-windowless
    //      velocity keeps the TOP of the feed distinct from both `new` (which the
    //      old floor-3 feed collapsed toward) and `hot` (log-of-score + linear age):
    //      rising's #1 is the best score-PER-age, not the newest and not the
    //      highest absolute score.
    //
    // In short: rising is now "positive-score posts, ranked by smoothed votes/hour,
    // with age applied softly through the smoothing" - velocity-first, never blank.
    private const RISING_MIN_SCORE   = 1;
    private const RISING_SMOOTH_SECS = 7200; // 2 hours, additive damping (and the soft window)

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
                // down and one up. The formula itself lives in controversyExpr() so
                // the bot "most controversial" leaderboard scores content with the
                // exact same maths instead of a second, drifting copy.
                [$cUps, $cDowns] = self::upsDownsSql($a);
                $contro = self::controversyExpr($cUps, $cDowns);
                return [
                    'order' => "{$contro} DESC, {$a}.score DESC, {$a}.created_at DESC, {$a}.id DESC",
                    // Only genuinely contested posts: at least one real downvote AND
                    // a positive up side. A pure pile-on (all-down, ups <= 0) is
                    // disliked, not controversial, so it never enters this feed.
                    'where' => " AND {$cDowns} > 0 AND {$cUps} > 0",
                    'binds' => [],
                ];

            case 'rising':
                // votes/hour, smoothed: (score * 3600) / (age_seconds + 7200).
                // The *3600 only scales the number into per-hour units; it does not
                // change the ordering. Denominator can never be zero (min 7200), and
                // the smoothing doubles as a soft recency window (an old post's large
                // age term drives its velocity toward zero). The only filter is a
                // score>=1 floor, so rising is never empty on a live site while still
                // ranking by climb-rate, not raw recency or accumulated score. No
                // named binds -> nothing to reuse (HY093-safe by construction).
                $velocity = "({$a}.score * 3600.0) / "
                          . "(UNIX_TIMESTAMP() - UNIX_TIMESTAMP({$a}.created_at) + " . self::RISING_SMOOTH_SECS . ")";
                return [
                    'order' => "{$velocity} DESC, {$a}.created_at DESC, {$a}.id DESC",
                    'where' => " AND {$a}.score >= " . self::RISING_MIN_SCORE,
                    'binds' => [],
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
     * SQL sub-expressions for a piece of content's genuine (ups, downs),
     * reconciled with the stored score. See the class docblock for the full
     * rationale. In short:
     *   downs = the real count of downvote ROWS (matches the hover tooltip)
     *   ups   = score + downs  =>  ups - downs == score, always.
     * Both are correlated scalar subqueries over the votes table, evaluated per
     * candidate row. That is a handful of trivial indexed COUNTs on a community
     * this small - cheap. `vd` is a fresh alias so it never collides with the
     * caller's joins.
     *
     * $a is the content table alias; $targetType is 'post' or 'comment' so the
     * same expression serves the post listings AND the per-bot leaderboards
     * (which score a bot's comments too). Public because LeaderboardService reuses
     * it to keep exactly one ups/downs reconciliation in the codebase.
     *
     * @return array{0:string,1:string} [upsExpr, downsExpr]
     */
    public static function upsDownsSql(string $a, string $targetType = 'post'): array
    {
        $t     = $targetType === 'comment' ? 'comment' : 'post';
        $downs = "(SELECT COUNT(*) FROM votes vd"
               . " WHERE vd.target_type = '{$t}' AND vd.target_id = {$a}.id AND vd.direction = -1)";
        $ups   = "({$a}.score + {$downs})";
        return [$ups, $downs];
    }

    /**
     * The controversy score expression, given ups/downs SQL sub-expressions.
     * magnitude = ups+downs; balance in (0,1] is the ratio of the smaller side to
     * the larger; score = magnitude ** balance, and 0 unless there is at least one
     * up AND one down (a pure pile-on is disliked, not controversial). This is THE
     * controversy formula: the 'controversial' sort and the bot leaderboard both
     * call it, so they can never diverge.
     */
    public static function controversyExpr(string $ups, string $downs): string
    {
        $mag = "({$ups} + {$downs})";
        $bal = "(CASE WHEN {$ups} > {$downs} THEN {$downs} * 1.0 / {$ups}"
             . " ELSE {$ups} * 1.0 / {$downs} END)";
        return "(CASE WHEN {$downs} <= 0 OR {$ups} <= 0 THEN 0"
             . " ELSE POWER({$mag}, {$bal}) END)";
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
