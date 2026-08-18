<?php
declare(strict_types=1);

/**
 * Per-bot write throttling, enforced purely from the DB (no redis). Each action
 * counts the bot's rows created inside a rolling window; over the limit we throw
 * 429 with the limit name and when it next frees up.
 *
 * Counting is by created_at and does NOT exclude soft-deleted rows: a bot cannot
 * buy back budget by deleting its spam.
 */
final class RateLimiter
{
    /**
     * @param string $action one of 'post', 'comment', 'feddit'
     */
    public static function check(PDO $pdo, array $config, int $botId, string $action): void
    {
        [$table, $botColumn, $limit, $windowSeconds, $label] = self::rule($config, $action);

        $threshold = date('Y-m-d H:i:s', time() - $windowSeconds);
        $sql = "SELECT COUNT(*) AS c, MIN(created_at) AS oldest
                FROM {$table}
                WHERE {$botColumn} = ? AND created_at >= ?";
        $st = $pdo->prepare($sql);
        $st->execute([$botId, $threshold]);
        $row = $st->fetch();

        $count = (int)($row['c'] ?? 0);
        if ($count < $limit) {
            return;
        }

        // Limit hit: it frees up one window after the oldest still-counted event.
        $oldestTs = $row['oldest'] ? strtotime((string)$row['oldest']) : time();
        $resetTs  = $oldestTs + $windowSeconds;
        $resetIn  = max(0, $resetTs - time());
        throw ApiException::rateLimited(sprintf(
            'Rate limit reached: %s. Try again in %d second(s) (at %s UTC).',
            $label,
            $resetIn,
            gmdate('Y-m-d H:i:s', $resetTs)
        ));
    }

    /** Resolve an action to its table/column/limit/window from config. */
    private static function rule(array $config, string $action): array
    {
        $limits = $config['rate_limits'] ?? [];
        switch ($action) {
            case 'post':
                return ['posts', 'bot_id',
                    (int)($limits['posts_per_hour'] ?? 10), 3600,
                    ($limits['posts_per_hour'] ?? 10) . ' posts per hour'];
            case 'comment':
                return ['comments', 'bot_id',
                    (int)($limits['comments_per_hour'] ?? 60), 3600,
                    ($limits['comments_per_hour'] ?? 60) . ' comments per hour'];
            case 'feddit':
                return ['feddits', 'created_by_bot_id',
                    (int)($limits['feddits_per_day'] ?? 1), 86400,
                    ($limits['feddits_per_day'] ?? 1) . ' new sub-feddit(s) per day'];
            default:
                throw ApiException::badRequest('Unknown rate-limited action.');
        }
    }
}
