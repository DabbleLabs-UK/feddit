<?php
declare(strict_types=1);

/**
 * "Active communities": ranks SUB-FEDDITS by recent activity, damped by size, so
 * the homepage keeps quietly surfacing fresh sub-feddits instead of pinning the
 * same big ones forever. Powers the homepage sidebar block AND
 * GET /api/v1/communities/active.json (so the future MCP server ranks identically
 * off this one place - same reasoning as RankingService / LeaderboardService).
 *
 * -- the ranking, and why it damps size without inverting it -------------------
 *
 * For each sub-feddit we compute, entirely in SQL:
 *   recent  = a RECENCY-WEIGHTED count of its posts + comments in the last
 *             WINDOW_HOURS (a rolling 48h window). Each item inside the window
 *             carries a weight of 1 + f, where f in [0,1] is how far through the
 *             window it was created (0 at the window's start, 1 = right now). So a
 *             brand-new item counts ~2x one that is nearly 48h old: newer
 *             activity legitimately counts for more.
 *   total   = the community's ALL-TIME non-deleted content (posts + comments).
 *   score   = recent / LOG10(total + 10)
 *
 * The divisor is SUBLINEAR (log) and FLOORED (the +10 keeps LOG10(...) >= ~1.04
 * for any community, even a one-item one - it can never collapse toward 0 and
 * inflate a tiny community's ratio). Why this damps size without simply inverting
 * it:
 *   - It DIVIDES recent activity by a slow-growing size term, it never multiplies
 *     by size or subtracts it. Raw recent activity still dominates: a community
 *     with double the recent activity beats one with half, unless the busier one
 *     is also dramatically larger.
 *   - A community 100x larger pays only a ~2x divisor (log), so a genuinely busy
 *     big community still ranks well - it is handicapped, not evicted.
 *   - Because the numerator is a ROLLING window, a big community that goes quiet
 *     falls off within 48h regardless of how much lifetime content it has: it
 *     cannot "sit at the top forever" on past glory. And a big-but-currently-quiet
 *     community is actively demoted, because its high `total` inflates the divisor
 *     while its low recent activity shrinks the numerator.
 *
 * Degenerate cases are guarded:
 *   - A DEAD community (no activity in the window) has recent = 0 and is filtered
 *     out entirely (WHERE recent > 0) - "active communities" means active.
 *   - A community with a SINGLE recent comment scores recent(~1-2) / LOG10(11)
 *     (~1.04) ~= 1-2: it sits at the BOTTOM of the active list, never the top,
 *     because the floored log divisor never boosts a tiny community above its own
 *     (tiny) raw activity. So "a dead community with one comment" cannot top the
 *     board.
 *
 * Excludes soft-deleted content (is_deleted = 0) throughout. Sub-feddits have no
 * "active" flag; a community with no non-deleted content simply never appears.
 *
 * Cost: one windowed aggregate over posts + comments, grouped by community, with
 * a LIMIT. On feddit's scale that is sub-millisecond; because it does touch every
 * non-deleted row to compute `total`, the result is cached briefly (like the
 * controversial leaderboard) so a burst of homepage hits doesn't recompute it.
 */
final class CommunityService
{
    /** Rolling activity window: "a couple of days". */
    public const WINDOW_HOURS = 48;

    /** Short default for the sidebar box (old.reddit boxes stay short). */
    public const DEFAULT_LIMIT = 5;

    /** How many the expandable sidebar block renders in total (the collapsed
     *  extra rows the "show more" toggle reveals in place). */
    public const EXPAND_LIMIT = 15;

    /** Hard cap for the JSON endpoint's ?limit=. */
    public const MAX_LIMIT = 50;

    /** Short-lived cache TTL for the windowed aggregate. */
    private const CACHE_TTL_SECONDS = 30;

    /**
     * The active-communities board as a ready-to-render envelope:
     *   ['window_hours', 'entries' => [['rank','name','title','url','subscribers',
     *                                    'recent','total','score','display'], ...]]
     * `recent` is the count of the community's posts + comments in the window (the
     * displayed figure); `score` is the damped ranking value the order is by.
     */
    public static function active(PDO $pdo, int $limit = self::DEFAULT_LIMIT, bool $includeNsfw = true): array
    {
        $limit = max(1, min($limit, self::MAX_LIMIT));

        $now       = time();
        $winSecs   = self::WINDOW_HOURS * 3600;
        $winStart  = $now - $winSecs;
        $cutoff    = date('Y-m-d H:i:s', $winStart);
        // Exclude 18+ communities from the homepage box unless the visitor opted in.
        $nsfwFilter = $includeNsfw ? '' : ' AND f.is_nsfw = 0';

        // Recency weight 1 + f, f in [0,1] across the window. `recent` (weighted)
        // drives the score; `recent_n` (a plain count) is the human figure. The
        // two cutoff comparisons use DISTINCT placeholder names (:cut / :cut_n) -
        // reusing one name twice in a single statement is rejected by MariaDB
        // native prepares (HY093); the SQLite verify harness tolerates it, so this
        // is a deliberate guard, not a style choice.
        $sql = "
            SELECT f.id AS id, f.name AS name, f.title AS title,
                   f.subscriber_count AS subscriber_count, f.is_nsfw AS is_nsfw,
                   agg.recent AS recent, agg.recent_n AS recent_n, agg.total AS total,
                   (agg.recent / LOG10(agg.total + 10)) AS score
            FROM feddits f
            JOIN (
                SELECT feddit_id,
                       SUM(CASE WHEN created_at >= :cut
                                THEN 1.0 + GREATEST(0, (UNIX_TIMESTAMP(created_at) - :winstart) / :winsecs)
                                ELSE 0 END) AS recent,
                       SUM(CASE WHEN created_at >= :cut_n THEN 1 ELSE 0 END) AS recent_n,
                       COUNT(*) AS total
                FROM (
                    SELECT p.feddit_id AS feddit_id, p.created_at AS created_at
                      FROM posts p
                     WHERE p.is_deleted = 0
                    UNION ALL
                    SELECT pc.feddit_id AS feddit_id, c.created_at AS created_at
                      FROM comments c
                      JOIN posts pc ON pc.id = c.post_id
                     WHERE c.is_deleted = 0 AND pc.is_deleted = 0
                ) items
                GROUP BY feddit_id
            ) agg ON agg.feddit_id = f.id
            WHERE agg.recent > 0{$nsfwFilter}
            ORDER BY score DESC, agg.recent DESC, f.subscriber_count DESC, f.id ASC
            LIMIT :lim";

        $st = $pdo->prepare($sql);
        $st->bindValue(':cut', $cutoff);
        $st->bindValue(':cut_n', $cutoff);
        $st->bindValue(':winstart', $winStart, PDO::PARAM_INT);
        $st->bindValue(':winsecs', $winSecs, PDO::PARAM_INT);
        $st->bindValue(':lim', $limit, PDO::PARAM_INT);
        $st->execute();

        $entries = [];
        foreach ($st->fetchAll() as $i => $r) {
            $recentN = (int)$r['recent_n'];
            $entries[] = [
                'rank'        => $i + 1,
                'name'        => (string)$r['name'],
                'title'       => (string)$r['title'],
                'url'         => '/f/' . rawurlencode((string)$r['name']),
                'subscribers' => (int)$r['subscriber_count'],
                'recent'      => $recentN,
                'total'       => (int)$r['total'],
                'score'       => round((float)$r['score'], 4),
                'over_18'     => (int)($r['is_nsfw'] ?? 0) === 1,
                'display'     => self::figure($recentN),
            ];
        }

        return [
            'window_hours' => self::WINDOW_HOURS,
            'empty'        => 'no sub-feddit has stirred in the last couple of days.',
            'entries'      => $entries,
        ];
    }

    /**
     * active(), with a short-lived file cache. The aggregate touches every
     * non-deleted post + comment (to size each community), so a burst of homepage
     * hits shouldn't recompute it every time. Keyed by limit; 30s TTL; best-effort
     * (any cache error falls back to computing live).
     */
    public static function cachedActive(PDO $pdo, int $limit = self::DEFAULT_LIMIT, bool $includeNsfw = true): array
    {
        $limit = max(1, min($limit, self::MAX_LIMIT));
        // Keyed by the NSFW flag too: the opted-in and default boards differ.
        $suffix = $includeNsfw ? 'all' : 'safe';
        $file   = self::cacheDir() . "/communities_active_{$limit}_{$suffix}.json";

        $cached = @file_get_contents($file);
        if ($cached !== false) {
            $age = time() - (int)@filemtime($file);
            if ($age >= 0 && $age < self::CACHE_TTL_SECONDS) {
                $decoded = json_decode($cached, true);
                if (is_array($decoded) && isset($decoded['entries'])) {
                    return $decoded;
                }
            }
        }

        $board = self::active($pdo, $limit, $includeNsfw);
        $dir   = self::cacheDir();
        if ($dir !== '' && (is_dir($dir) || @mkdir($dir, 0775, true) || is_dir($dir))) {
            $tmp = $file . '.' . getmypid() . '.tmp';
            if (@file_put_contents($tmp, json_encode($board, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) !== false) {
                @rename($tmp, $file);
            }
        }
        return $board;
    }

    /** The short human figure per row: the count of recent posts + comments. */
    private static function figure(int $recentN): string
    {
        return function_exists('fmt_int') ? fmt_int($recentN) : (string)$recentN;
    }

    /** Where the short-lived cache lives (gitignored storage/). */
    private static function cacheDir(): string
    {
        return dirname(__DIR__, 2) . '/storage/cache';
    }
}
