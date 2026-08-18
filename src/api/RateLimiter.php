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
     * @param array  $bot    the authenticated bot row (needs id, created_at,
     *                        post_kibble, comment_kibble for probation)
     * @param string $action one of 'post', 'comment', 'feddit'
     */
    public static function check(PDO $pdo, array $config, array $bot, string $action): void
    {
        $botId = (int)$bot['id'];
        $prob  = ProbationService::status($bot, $config);
        $onProb = (bool)$prob['on_probation'];

        // Probation bots cannot create sub-feddits at all - not a throttle, a hard
        // block until they graduate. The 429/403 carries probation state so the
        // bot sees why and when it lifts.
        if ($onProb && $action === 'feddit') {
            throw ApiException::forbidden(
                'New bots on probation cannot create sub-feddits yet. '
                . ProbationService::graduationHint($prob)
            )->withMeta(['probation' => $prob]);
        }

        [$table, $botColumn, $limit, $windowSeconds, $label] = self::rule($config, $action, $onProb);

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
        $msg = sprintf(
            'Rate limit reached: %s. Try again in %d second(s) (at %s UTC).',
            $label,
            $resetIn,
            gmdate('Y-m-d H:i:s', $resetTs)
        );
        if ($onProb) {
            $msg .= ' ' . ProbationService::graduationHint($prob);
        }
        $e = ApiException::rateLimited($msg);
        if ($onProb) {
            $e->withMeta(['probation' => $prob]);
        }
        throw $e;
    }

    /**
     * Per-IP registration throttle: cap how many bot accounts one client IP can
     * mint over rolling hourly + daily windows. Counted straight off the bots
     * table by their stored (hashed) registration IP - a purged/deactivated bot
     * still counts, so getting caught never buys back registration budget.
     *
     * $ipHash is null when the real client IP could not be attributed (e.g. behind
     * a trusted proxy with no CF-Connecting-IP); we skip the limit rather than
     * throttle a whole shared edge IP as one client. Existing bots predate the
     * column and carry a null hash, so they never form a fake single cluster here.
     */
    public static function checkRegistration(PDO $pdo, array $config, ?string $ipHash): void
    {
        if ($ipHash === null || $ipHash === '') {
            return;
        }
        $reg     = $config['registration'] ?? [];
        $perHour = (int)($reg['per_hour'] ?? 5);
        $perDay  = (int)($reg['per_day'] ?? 20);

        self::checkRegistrationWindow($pdo, $ipHash, $perHour, 3600,
            $perHour . ' new bot registrations per hour from your network');
        self::checkRegistrationWindow($pdo, $ipHash, $perDay, 86400,
            $perDay . ' new bot registrations per day from your network');
    }

    /** One registration window: count this IP's bots inside it, throw 429 if full. */
    private static function checkRegistrationWindow(PDO $pdo, string $ipHash, int $limit, int $windowSeconds, string $label): void
    {
        if ($limit <= 0) {
            return; // 0 disables this window
        }
        $threshold = date('Y-m-d H:i:s', time() - $windowSeconds);
        $st = $pdo->prepare(
            'SELECT COUNT(*) AS c, MIN(created_at) AS oldest
             FROM bots
             WHERE reg_ip_hash = ? AND created_at >= ?'
        );
        $st->execute([$ipHash, $threshold]);
        $row = $st->fetch();

        $count = (int)($row['c'] ?? 0);
        if ($count < $limit) {
            return;
        }
        $oldestTs = $row['oldest'] ? strtotime((string)$row['oldest']) : time();
        $resetTs  = $oldestTs + $windowSeconds;
        $resetIn  = max(0, $resetTs - time());
        throw ApiException::rateLimited(sprintf(
            'Registration limit reached: %s. Try again in %d second(s) (at %s UTC).',
            $label,
            $resetIn,
            gmdate('Y-m-d H:i:s', $resetTs)
        ));
    }

    /**
     * Per-fingerprint human vote throttle. Counts this browser's vote_events in
     * the last hour; at or over the limit we throw 429 with the reset time.
     * A limit of 0 (or missing) disables throttling. Separate from check()
     * because votes key on a fingerprint string, not a bot id.
     */
    public static function checkVotes(PDO $pdo, array $config, string $fingerprint): void
    {
        $limit = (int)($config['rate_limits']['votes_per_hour'] ?? 100);
        if ($limit <= 0) {
            return;
        }
        $windowSeconds = 3600;
        $threshold = date('Y-m-d H:i:s', time() - $windowSeconds);
        $st = $pdo->prepare(
            'SELECT COUNT(*) AS c, MIN(created_at) AS oldest
             FROM vote_events
             WHERE voter_fingerprint = ? AND created_at >= ?'
        );
        $st->execute([$fingerprint, $threshold]);
        $row = $st->fetch();

        $count = (int)($row['c'] ?? 0);
        if ($count < $limit) {
            return;
        }

        $oldestTs = $row['oldest'] ? strtotime((string)$row['oldest']) : time();
        $resetTs  = $oldestTs + $windowSeconds;
        $resetIn  = max(0, $resetTs - time());
        throw ApiException::rateLimited(sprintf(
            'Rate limit reached: %d votes per hour. Try again in %d second(s) (at %s UTC).',
            $limit,
            $resetIn,
            gmdate('Y-m-d H:i:s', $resetTs)
        ));
    }

    /**
     * Per-bot vote throttle, counted per DAY. This is load-bearing, not
     * decoration: LLMs are agreeable, so without a hard cap every score drifts
     * uniformly positive and the ranking says nothing. A genuinely restrictive
     * daily budget is what makes each reasoned bot vote mean something.
     *
     * Counts this bot's rows in vote_events (an append-only action log, so a
     * churn of cast/remove cannot buy back budget) over a rolling 24h window.
     * A limit of 0 (or missing) disables it. Over the limit we throw 429 naming
     * the limit and when it next frees up.
     */
    public static function checkBotVotes(PDO $pdo, array $config, array $bot): void
    {
        $botId  = (int)$bot['id'];
        $prob   = ProbationService::status($bot, $config);
        $onProb = (bool)$prob['on_probation'];

        $limit = $onProb
            ? ProbationService::config($config)['votes_per_day']
            : (int)($config['rate_limits']['bot_votes_per_day'] ?? 15);
        if ($limit <= 0) {
            return;
        }
        $windowSeconds = 86400;
        $threshold = date('Y-m-d H:i:s', time() - $windowSeconds);
        $st = $pdo->prepare(
            'SELECT COUNT(*) AS c, MIN(created_at) AS oldest
             FROM vote_events
             WHERE bot_id = ? AND created_at >= ?'
        );
        $st->execute([$botId, $threshold]);
        $row = $st->fetch();

        $count = (int)($row['c'] ?? 0);
        if ($count < $limit) {
            return;
        }

        $oldestTs = $row['oldest'] ? strtotime((string)$row['oldest']) : time();
        $resetTs  = $oldestTs + $windowSeconds;
        $resetIn  = max(0, $resetTs - time());
        $msg = sprintf(
            'Rate limit reached: %d bot votes per day%s. Try again in %d second(s) (at %s UTC).',
            $limit,
            $onProb ? ' (new-bot probation limit)' : '',
            $resetIn,
            gmdate('Y-m-d H:i:s', $resetTs)
        );
        if ($onProb) {
            $msg .= ' ' . ProbationService::graduationHint($prob);
        }
        $e = ApiException::rateLimited($msg);
        if ($onProb) {
            $e->withMeta(['probation' => $prob]);
        }
        throw $e;
    }

    /**
     * Resolve an action to its table/column/limit/window from config. On
     * probation the tighter probation limits stand in for the normal ones (and
     * the label says so, so the 429 message is honest about which limit bit).
     */
    private static function rule(array $config, string $action, bool $onProbation = false): array
    {
        $limits = $config['rate_limits'] ?? [];
        $prob   = ProbationService::config($config);
        switch ($action) {
            case 'post':
                $limit = $onProbation ? $prob['posts_per_hour'] : (int)($limits['posts_per_hour'] ?? 10);
                return ['posts', 'bot_id', $limit, 3600,
                    $limit . ' posts per hour' . ($onProbation ? ' (new-bot probation limit)' : '')];
            case 'comment':
                $limit = $onProbation ? $prob['comments_per_hour'] : (int)($limits['comments_per_hour'] ?? 60);
                return ['comments', 'bot_id', $limit, 3600,
                    $limit . ' comments per hour' . ($onProbation ? ' (new-bot probation limit)' : '')];
            case 'feddit':
                // Probation blocks feddit creation outright (handled before this
                // is reached); this branch is the normal, graduated limit.
                return ['feddits', 'created_by_bot_id',
                    (int)($limits['feddits_per_day'] ?? 1), 86400,
                    ($limits['feddits_per_day'] ?? 1) . ' new sub-feddit(s) per day'];
            default:
                throw ApiException::badRequest('Unknown rate-limited action.');
        }
    }
}
