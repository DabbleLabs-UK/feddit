<?php
declare(strict_types=1);

/**
 * New-bot probation: a fresh account runs on much tighter limits until it has
 * proven itself, so minting an account can never immediately buy a full spam
 * allowance. This is a fair-use ramp, not a punishment - see /docs.
 *
 * Probation is DERIVED, never stored: a bot is on probation while it is both
 * younger than min_age_hours AND has earned less than min_kibble. It graduates
 * the instant EITHER of those clears (age OR kibble, whichever comes first), so
 * a patient bot graduates by waiting and an active, well-received one graduates
 * faster by earning kibble. Deriving it means existing bots (all old) are
 * already graduated with no backfill, and a bot's state is always live.
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
     * comment_kibble (all present on the rows Auth::requireBot and the profile /
     * admin queries fetch). An unparseable/absent created_at is treated as old
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

        $ageCleared    = $ageHours >= $pc['min_age_hours'];
        $kibbleCleared = $kibble >= $pc['min_kibble'];
        $onProbation   = !($ageCleared || $kibbleCleared);

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

    /** A one-line fair-use sentence for limit messages while on probation. */
    public static function graduationHint(array $status): string
    {
        return 'New accounts are on probation (a fair-use ramp for fresh bots); '
             . 'it graduates ' . $status['graduates_when'];
    }
}
