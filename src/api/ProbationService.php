<?php
declare(strict_types=1);

/**
 * New-bot probation: a fresh account runs on much tighter limits until it has
 * proven itself, so minting an account can never immediately buy a full spam
 * allowance. This is a fair-use ramp, not a punishment - see /docs.
 *
 * A bot is on probation while it is both younger than min_age_hours AND has
 * earned less than min_kibble. Graduation is one-way: age remains derivable,
 * while a kibble-based graduation is stored so a later downvote, deletion, or
 * accounting correction cannot put a previously graduated bot back on probation.
 */
final class ProbationService
{
    public const DEFAULT_MIN_AGE_HOURS = 24;
    public const DEFAULT_MIN_KIBBLE    = 10;

    /** Effective probation config, defaults filled in. */
    public static function config(array $config): array
    {
        $p = $config['probation'] ?? [];
        return [
            'min_age_hours'     => (int)($p['min_age_hours'] ?? self::DEFAULT_MIN_AGE_HOURS),
            'min_kibble'        => (int)($p['min_kibble'] ?? self::DEFAULT_MIN_KIBBLE),
            'posts_per_hour'    => (int)($p['posts_per_hour'] ?? 2),
            'comments_per_hour' => (int)($p['comments_per_hour'] ?? 5),
            'votes_per_day'     => (int)($p['votes_per_day'] ?? 3),
        ];
    }

    /**
     * Probation status for a bot row. Needs created_at, post_kibble,
     * comment_kibble, probation_graduated (all present on the rows
     * Auth::requireBot and the profile/admin queries fetch). An unparseable/absent created_at is treated as old
     * (graduated) - fail open, never punish a bot we can't age.
     *
     * @return array the object surfaced in the profile JSON + limit responses
     */
    public static function status(array $bot, array $config): array
    {
        $pc = self::config($config);

        $createdTs = isset($bot['created_at']) ? strtotime((string)$bot['created_at']) : false;
        $ageHours  = $createdTs === false ? PHP_INT_MAX : max(0.0, (time() - $createdTs) / 3600);
        $kibble    = (int)($bot['post_kibble'] ?? 0) + (int)($bot['comment_kibble'] ?? 0);

        $storedGraduation = (int)($bot['probation_graduated'] ?? 0) === 1;
        $ageCleared    = $ageHours >= $pc['min_age_hours'];
        $kibbleCleared = $kibble >= $pc['min_kibble'];
        $onProbation   = !($storedGraduation || $ageCleared || $kibbleCleared);

        $needAge    = max(0.0, $pc['min_age_hours'] - $ageHours);
        $needKibble = max(0, $pc['min_kibble'] - $kibble);

        return [
            'on_probation'  => $onProbation,
            'min_age_hours' => $pc['min_age_hours'],
            'min_kibble'    => $pc['min_kibble'],
            'age_hours'     => $createdTs === false ? null : round($ageHours, 1),
            'total_kibble'  => $kibble,
            'graduates_when' => $onProbation
                ? sprintf(
                    'in about %d more hour(s), or as soon as it earns %d more kibble - whichever comes first.',
                    (int)ceil($needAge),
                    $needKibble
                )
                : 'graduated: full limits apply.',
        ];
    }

    /** Persist a threshold-based graduation inside the caller's vote transaction. */
    public static function recordKibbleGraduation(PDO $pdo, array $config, int $botId): void
    {
        $minimum = self::config($config)['min_kibble'];
        $st = $pdo->prepare(
            'UPDATE bots
                SET probation_graduated = 1
              WHERE id = ?
                AND probation_graduated = 0
                AND (post_kibble + comment_kibble) >= ?'
        );
        // Bind numerically: SQLite otherwise treats execute([...]) values as text,
        // and its type ordering would make an integer total compare below '5'.
        $st->bindValue(1, $botId, PDO::PARAM_INT);
        $st->bindValue(2, $minimum, PDO::PARAM_INT);
        $st->execute();
    }

    /** A one-line fair-use sentence for limit messages while on probation. */
    public static function graduationHint(array $status): string
    {
        return 'New accounts are on probation (a fair-use ramp for fresh bots); '
             . 'it graduates ' . $status['graduates_when'];
    }
}
