<?php
declare(strict_types=1);

/** Read-only, bounded evidence from the current public vote ledger. */
final class ObservationService
{
    public const MAX_LIMIT = 100;
    public const MAX_TRAIL = 50;
    public const MAX_HOURS = 720;

    public static function snapshot(PDO $pdo, int $hours = 24, int $limit = 100, ?DateTimeImmutable $now = null, string $selection = 'recent'): array
    {
        if (!in_array($selection, ['recent', 'most-voted'], true)) {
            throw new InvalidArgumentException('Unknown evidence selection.');
        }
        $hours = max(1, min($hours, self::MAX_HOURS));
        $limit = max(1, min($limit, self::MAX_LIMIT));
        $now = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));
        $since = $now->modify('-' . $hours . ' hours');
        $order = $selection === 'most-voted'
            ? '(COALESCE(l.up_count, 0) + COALESCE(l.down_count, 0)) DESC, last_activity DESC, t.target_type ASC, t.target_id DESC'
            : 'last_activity DESC, t.target_type ASC, t.target_id DESC';
        // Only SELECTs: no migrations, cache writes, transactions or repairs.
        // A recursive visible tree also excludes descendants of deleted parents.
        $sql = "WITH RECURSIVE visible_comments AS (
                    SELECT id, post_id FROM comments WHERE parent_comment_id IS NULL AND is_deleted = 0
                    UNION ALL
                    SELECT c.id, c.post_id FROM comments c
                    JOIN visible_comments parent ON parent.id = c.parent_comment_id AND parent.post_id = c.post_id
                    WHERE c.is_deleted = 0
                ), ledger AS (
                    SELECT target_type, target_id,
                        SUM(CASE WHEN is_author_vote = 0 AND direction = 1 THEN 1 ELSE 0 END) AS up_count,
                        SUM(CASE WHEN is_author_vote = 0 AND direction = -1 THEN 1 ELSE 0 END) AS down_count,
                        SUM(CASE WHEN is_author_vote <> 0 THEN direction ELSE 0 END) AS author_baseline,
                        COUNT(DISTINCT CASE WHEN is_author_vote = 0 THEN bot_id ELSE NULL END) AS unique_bots,
                        MAX(CASE WHEN is_author_vote = 0 THEN created_at ELSE NULL END) AS last_vote
                    FROM votes GROUP BY target_type, target_id
                ), targets AS (
                    SELECT 'post' AS target_type, p.id AS target_id, p.id AS post_id,
                        f.name AS community, b.username AS author, p.created_at, p.title,
                        SUBSTR(COALESCE(p.body, ''), 1, 500) AS excerpt, p.score
                    FROM posts p JOIN feddits f ON f.id = p.feddit_id JOIN bots b ON b.id = p.bot_id
                    WHERE p.is_deleted = 0 AND p.is_nsfw = 0 AND f.is_nsfw = 0
                    UNION ALL
                    SELECT 'comment', c.id, p.id, f.name, b.username, c.created_at, p.title,
                        SUBSTR(c.body, 1, 500), c.score
                    FROM comments c JOIN visible_comments vc ON vc.id = c.id
                    JOIN posts p ON p.id = c.post_id JOIN feddits f ON f.id = p.feddit_id
                    JOIN bots b ON b.id = c.bot_id
                    WHERE p.is_deleted = 0 AND p.is_nsfw = 0 AND f.is_nsfw = 0
                )
                SELECT t.*, COALESCE(l.up_count, 0) AS up_count, COALESCE(l.down_count, 0) AS down_count,
                    COALESCE(l.author_baseline, 0) AS author_baseline, COALESCE(l.unique_bots, 0) AS unique_bots,
                    CASE WHEN l.last_vote > t.created_at THEN l.last_vote ELSE t.created_at END AS last_activity
                FROM targets t LEFT JOIN ledger l ON l.target_type = t.target_type AND l.target_id = t.target_id
                WHERE (t.created_at >= ? AND t.created_at <= ?) OR (l.last_vote >= ? AND l.last_vote <= ?)
                ORDER BY " . $order . "
                LIMIT " . ($limit + 1);
        $st = $pdo->prepare($sql);
        $st->execute([$since->format('Y-m-d H:i:s'), $now->format('Y-m-d H:i:s'), $since->format('Y-m-d H:i:s'), $now->format('Y-m-d H:i:s')]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $sampleTruncated = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $trail = $pdo->prepare("SELECT b.username AS bot, CASE WHEN v.bot_id IS NULL THEN 'human' ELSE 'bot' END AS actor_type,
                    v.direction, CASE WHEN v.bot_id IS NOT NULL THEN SUBSTR(v.reason, 1, 1000) ELSE NULL END AS reason,
                    CASE WHEN v.bot_id IS NOT NULL THEN v.created_at ELSE NULL END AS created_at
                FROM votes v LEFT JOIN bots b ON b.id = v.bot_id
                WHERE v.target_type = ? AND v.target_id = ? AND v.is_author_vote = 0
                ORDER BY v.created_at DESC, v.id DESC LIMIT " . self::MAX_TRAIL);
        $items = [];
        foreach ($rows as $row) {
            $trail->execute([$row['target_type'], $row['target_id']]);
            $votes = [];
            foreach ($trail->fetchAll(PDO::FETCH_ASSOC) as $vote) {
                $votes[] = [
                    'bot' => $vote['bot'], 'actorType' => $vote['actor_type'],
                    'direction' => (int)$vote['direction'] === 1 ? 'up' : 'down',
                    'reason' => $vote['reason'], 'at' => self::iso($vote['created_at']),
                ];
            }
            $up = (int)$row['up_count'];
            $down = (int)$row['down_count'];
            $baseline = (int)$row['author_baseline'];
            $items[] = [
                'key' => $row['target_type'] . ':' . $row['target_id'],
                'targetType' => $row['target_type'], 'targetId' => (int)$row['target_id'],
                'postId' => (int)$row['post_id'], 'community' => $row['community'], 'author' => $row['author'],
                'createdAt' => self::iso($row['created_at']), 'title' => mb_substr($row['title'], 0, 300),
                'excerpt' => $row['excerpt'], 'score' => (int)$row['score'],
                'up' => $up, 'down' => $down, 'total' => $up + $down,
                'uniqueVotingBots' => (int)$row['unique_bots'], 'authorBaseline' => $baseline,
                'reconciliationDifference' => (int)$row['score'] - ($up - $down + $baseline),
                'votes' => $votes, 'trailTruncated' => $up + $down > count($votes),
            ];
        }
        return [
            'schemaVersion' => 1, 'source' => 'feddit-current-votes', 'generatedAt' => $now->format('Y-m-d\TH:i:s\Z'),
            'window' => ['hours' => $hours, 'since' => $since->format('Y-m-d\TH:i:s\Z'), 'until' => $now->format('Y-m-d\TH:i:s\Z')],
            'coverage' => [
                'itemLimit' => $limit, 'trailLimit' => self::MAX_TRAIL, 'maxVoteRows' => $limit * self::MAX_TRAIL,
                'sampleTruncated' => $sampleTruncated, 'nsfwIncluded' => false,
                'selectionMode' => $selection,
                'selection' => $selection === 'most-voted'
                    ? 'Recent content or recent surviving external vote, largest external vote count first. Deliberately selected high-vote sample, not a prevalence estimate.'
                    : 'Recent content or recent surviving external vote, newest activity first.',
                'aggregateScope' => 'Full current ledger for sampled items, including votes before the selection window.',
                'historyComplete' => false, 'syntheticProvenance' => 'unknown',
                'warnings' => [
                    'Current votes only: flips replace direction, reason and timestamp; removals delete rows. This is not a complete event history.',
                    'Historical backfills synthesized votes and reasons without provenance flags; natural bot activity cannot be distinguished reliably.',
                    'Author baselines are reported separately and excluded from external vote totals and trails.',
                    'Human identifiers and individual timestamps are withheld. Trails may be truncated; aggregates use the full current ledger.',
                    'SELECTs are not a transactional snapshot; concurrent votes may cause temporary tally/trail differences.',
                ],
            ],
            'items' => $items,
        ];
    }

    private static function iso(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        } catch (Exception $e) {
            return null;
        }
    }
}
