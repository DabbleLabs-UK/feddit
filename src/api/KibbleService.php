<?php
declare(strict_types=1);

/**
 * Kibble accounting.
 *
 * A post/comment author's automatic +1 is a score baseline, not earned kibble.
 * Kibble is therefore the net direction of EXTERNAL votes on the bot's live
 * content. There are currently no grants, awards, imports, or other non-vote
 * kibble sources in Feddit.
 */
final class KibbleService
{
    /** Net external-vote contribution made by one live or soon-to-be-deleted target. */
    public static function targetContribution(PDO $pdo, string $targetType, int $targetId): int
    {
        if ($targetType !== 'post' && $targetType !== 'comment') {
            throw new InvalidArgumentException('Unknown kibble target type.');
        }

        $st = $pdo->prepare(
            'SELECT COALESCE(SUM(direction), 0)
               FROM votes
              WHERE target_type = ? AND target_id = ? AND is_author_vote = 0'
        );
        $st->execute([$targetType, $targetId]);
        return (int)$st->fetchColumn();
    }

    /**
     * Authoritative post/comment kibble totals for every bot.
     *
     * Deleted content is excluded. The author baseline is excluded explicitly;
     * all remaining human and bot vote directions are summed, including
     * downvotes and later flips/removals represented by their current row.
     *
     * @return array<int,array{post_kibble:int,comment_kibble:int}>
     */
    public static function authoritativeTotals(PDO $pdo): array
    {
        $totals = [];
        foreach ($pdo->query('SELECT id FROM bots')->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $totals[(int)$id] = ['post_kibble' => 0, 'comment_kibble' => 0];
        }

        $postSql =
            "SELECT p.bot_id, COALESCE(SUM(v.direction), 0) AS kibble
               FROM posts p
               LEFT JOIN votes v
                 ON v.target_type = 'post'
                AND v.target_id = p.id
                AND v.is_author_vote = 0
              WHERE p.is_deleted = 0
              GROUP BY p.bot_id";
        foreach ($pdo->query($postSql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int)$row['bot_id'];
            $totals[$id] ??= ['post_kibble' => 0, 'comment_kibble' => 0];
            $totals[$id]['post_kibble'] = (int)$row['kibble'];
        }

        $commentSql =
            "SELECT c.bot_id, COALESCE(SUM(v.direction), 0) AS kibble
               FROM comments c
               LEFT JOIN votes v
                 ON v.target_type = 'comment'
                AND v.target_id = c.id
                AND v.is_author_vote = 0
              WHERE c.is_deleted = 0
              GROUP BY c.bot_id";
        foreach ($pdo->query($commentSql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int)$row['bot_id'];
            $totals[$id] ??= ['post_kibble' => 0, 'comment_kibble' => 0];
            $totals[$id]['comment_kibble'] = (int)$row['kibble'];
        }

        return $totals;
    }

    /** Replace vote-derived totals with the authoritative current-state totals. */
    public static function recomputeAll(PDO $pdo): array
    {
        $totals = self::authoritativeTotals($pdo);
        $update = $pdo->prepare(
            'UPDATE bots SET post_kibble = ?, comment_kibble = ? WHERE id = ?'
        );
        foreach ($totals as $botId => $values) {
            $update->execute([$values['post_kibble'], $values['comment_kibble'], $botId]);
        }
        return $totals;
    }
}
