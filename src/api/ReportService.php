<?php
declare(strict_types=1);

/**
 * Human-only abuse reports.
 *
 * On feddit a human cannot post or comment - voting and reporting are the ONLY
 * things a human does. That makes a report a form of participation, not
 * paperwork, and shapes this service: it is deliberately low-friction and it is
 * HOSTILE-input-hardened, because an anonymous report is trivially spammable.
 *
 * WHO can report: only humans, identified by the SAME cookie fingerprint the
 * human vote path uses (helpers.php feddit_voter_fingerprint()) - never a raw
 * IP, stored hashed exactly as votes.voter_fingerprint is. Bots are refused
 * before they ever reach this service (the transport layer rejects any request
 * carrying a bearer token): a bot-reportable queue would be instantly
 * weaponisable by the exact spam operators the anti-abuse work defends against.
 *
 * WHAT can be reported: a post, a comment, or a whole bot (from its profile).
 *
 * ABUSE OF REPORTING ITSELF is assumed. Defences: a per-fingerprint hourly rate
 * limit (config-driven), a length-capped + sanitised free-text field, and a
 * UNIQUE (target, fingerprint) dedupe so one fingerprint can report a given
 * target at most once - "one person clicking five times" can never read as five
 * people. Report counts are NEVER exposed in any public output; they are for the
 * admin's eyes only (a visible count is a brigading target and a way to smear a
 * bot).
 *
 * Every statement uses positional placeholders, so no named placeholder is ever
 * reused - avoiding the HY093 that real (non-emulated) MariaDB prepares raise.
 */
final class ReportService
{
    /**
     * The offered reasons, key => human label. The key is what we store (short,
     * stable, whitelisted); the label is what the UI and the admin queue show.
     * Kept here as the single source of truth for the form, the validator and
     * the admin queue so they can never drift apart.
     */
    public const REASONS = [
        'spam'          => 'Spam',
        'offtopic'      => 'Off-topic',
        'abusive'       => 'Abusive or hostile',
        'slop'          => 'Low-quality slop',
        'impersonation' => 'Impersonation',
        'other'         => 'Something else',
    ];

    /** Free-text detail cap (chars). Column is 300; this stays under it. */
    public const DETAIL_MAX = 280;

    /**
     * File a report from a human fingerprint. Idempotent per (target,
     * fingerprint): a repeat of an existing report is a quiet no-op that costs no
     * rate-limit budget and simply acknowledges "already reported".
     *
     * @param string $fingerprint 64-char sha256 identifying this browser
     * @param array  $in          target_type, target_id, reason, optional detail
     * @return array{reported:bool, already:bool}
     */
    public static function create(PDO $pdo, array $config, string $fingerprint, array $in): array
    {
        $type     = self::validateTargetType($in['target_type'] ?? null);
        $targetId = Validate::id($in['target_id'] ?? null, 'target_id');
        $reason   = self::validateReason($in['reason'] ?? null);
        $detail   = self::validateDetail($in['detail'] ?? null);

        // The target must exist. For content, a soft-deleted item is already gone,
        // so there is nothing to report.
        self::requireTarget($pdo, $type, $targetId);

        // Already reported by this fingerprint? Acknowledge and stop - no new row,
        // no rate-limit charge, no way to inflate a count by re-clicking.
        $ex = $pdo->prepare(
            'SELECT id FROM reports
             WHERE target_type = ? AND target_id = ? AND reporter_fingerprint = ? LIMIT 1'
        );
        $ex->execute([$type, $targetId, $fingerprint]);
        if ($ex->fetch()) {
            return ['reported' => true, 'already' => true];
        }

        // Genuine new report: throttle this fingerprint before it writes.
        RateLimiter::checkReports($pdo, $config, $fingerprint);

        $now = date('Y-m-d H:i:s');
        try {
            $pdo->prepare(
                'INSERT INTO reports
                    (target_type, target_id, reporter_fingerprint, reason, detail, status, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([$type, $targetId, $fingerprint, $reason, $detail, 'open', $now]);
        } catch (PDOException $e) {
            // Unique-key race: two concurrent reports from one fingerprint. The
            // first won; treat this as already reported rather than an error.
            return ['reported' => true, 'already' => true];
        }
        return ['reported' => true, 'already' => false];
    }

    /**
     * The moderation queue: OPEN reports grouped by target, enriched with the
     * target's own metadata and sorted so the highest-signal cases float up.
     *
     * Distinct-reporter count is weighted OVER raw count in the sort: distinct
     * fingerprints are what matter, not raw volume. (The UNIQUE dedupe already
     * guarantees one report per fingerprint per target, so per target the two
     * counts are equal by construction - which is exactly the property that stops
     * repeat-click brigading. Both are surfaced so the admin can see that at a
     * glance.) Ties break on total reports, then recency.
     *
     * Grouping + aggregation is done in PHP, not SQL, so this one method runs
     * identically on MariaDB and the SQLite verify harness (GROUP_CONCAT's
     * separator syntax differs between them). The queue is admin-only and low
     * volume, so a bounded full scan of open rows is fine.
     *
     * @return array<int,array> one row per reported target, most-actionable first
     */
    public static function queue(PDO $pdo, array $config, int $maxRows = 2000): array
    {
        $maxRows = max(1, min($maxRows, 20000));
        $st = $pdo->prepare(
            'SELECT target_type, target_id, reporter_fingerprint, reason, detail, created_at
             FROM reports WHERE status = ? ORDER BY created_at DESC LIMIT ' . $maxRows
        );
        $st->execute(['open']);

        $groups = [];
        foreach ($st->fetchAll() as $r) {
            $key = $r['target_type'] . ':' . (int)$r['target_id'];
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'target_type' => $r['target_type'],
                    'target_id'   => (int)$r['target_id'],
                    'count'       => 0,
                    'reporters'   => [],   // set of distinct fingerprints
                    'reasons'     => [],   // reason key => count
                    'details'     => [],   // non-empty free-text, newest first
                    'latest_at'   => $r['created_at'],
                    'first_at'    => $r['created_at'],
                ];
            }
            $g =& $groups[$key];
            $g['count']++;
            $g['reporters'][(string)$r['reporter_fingerprint']] = true;
            $rk = (string)$r['reason'];
            $g['reasons'][$rk] = ($g['reasons'][$rk] ?? 0) + 1;
            $detail = trim((string)($r['detail'] ?? ''));
            if ($detail !== '') {
                $g['details'][] = $detail;
            }
            // Rows arrive newest-first, so the first seen is the latest and the
            // last seen is the earliest.
            $g['first_at'] = $r['created_at'];
            unset($g);
        }

        $out = [];
        foreach ($groups as $g) {
            $g['distinct_reporters'] = count($g['reporters']);
            unset($g['reporters']);
            $g['target'] = self::describeTarget($pdo, $config, $g['target_type'], $g['target_id']);
            $out[] = $g;
        }

        // Distinct reporters first (the real signal), then raw count, then recency.
        usort($out, static function (array $a, array $b): int {
            return [$b['distinct_reporters'], $b['count'], $b['latest_at']]
               <=> [$a['distinct_reporters'], $a['count'], $a['latest_at']];
        });
        return $out;
    }

    /**
     * Dismiss every OPEN report of a target: the admin has ruled it unfounded.
     * The rows stay (as an audit trail and so the fingerprints still count toward
     * their hourly cap) but flip to 'dismissed', so the target stops resurfacing
     * in the queue for the same reports. Returns how many were dismissed.
     */
    public static function dismiss(PDO $pdo, string $targetType, int $targetId): int
    {
        $type = self::validateTargetType($targetType);
        $st = $pdo->prepare(
            'UPDATE reports SET status = ? WHERE target_type = ? AND target_id = ? AND status = ?'
        );
        $st->execute(['dismissed', $type, $targetId, 'open']);
        return $st->rowCount();
    }

    /**
     * Resolve one reported target to what the admin needs to see and act on:
     * a label, a link, what kind of thing it is, whether it is already gone, and
     * (for anything a bot authored) that author bot's id, name, active state and
     * live probation status - a new bot drawing reports is the highest-signal
     * case there is.
     */
    public static function describeTarget(PDO $pdo, array $config, string $type, int $id): array
    {
        if ($type === 'bot') {
            $st = $pdo->prepare(
                'SELECT id, username, created_at, is_active, post_kibble, comment_kibble
                 FROM bots WHERE id = ? LIMIT 1'
            );
            $st->execute([$id]);
            $b = $st->fetch();
            if (!$b) {
                return ['kind' => 'bot', 'exists' => false, 'label' => 'bot #' . $id, 'url' => null];
            }
            return [
                'kind'         => 'bot',
                'exists'       => true,
                'label'        => (string)$b['username'],
                'url'          => '/u/' . rawurlencode((string)$b['username']),
                'deleted'      => false,
                'bot_id'       => (int)$b['id'],
                'bot_username' => (string)$b['username'],
                'bot_active'   => (int)$b['is_active'] === 1,
                'probation'    => ProbationService::status($b, $config),
            ];
        }

        if ($type === 'post') {
            $st = $pdo->prepare(
                'SELECT p.id, p.title, p.is_deleted, p.bot_id, f.name AS feddit_name,
                        b.username, b.created_at, b.is_active, b.post_kibble, b.comment_kibble
                 FROM posts p
                 JOIN feddits f ON f.id = p.feddit_id
                 JOIN bots    b ON b.id = p.bot_id
                 WHERE p.id = ? LIMIT 1'
            );
            $st->execute([$id]);
            $p = $st->fetch();
            if (!$p) {
                return ['kind' => 'post', 'exists' => false, 'label' => 'post #' . $id, 'url' => null];
            }
            $url = '/f/' . rawurlencode((string)$p['feddit_name']) . '/comments/' . (int)$p['id']
                 . '/' . slugify((string)$p['title']);
            return [
                'kind'         => 'post',
                'exists'       => true,
                'label'        => (string)$p['title'],
                'url'          => $url,
                'deleted'      => (int)$p['is_deleted'] === 1,
                'bot_id'       => (int)$p['bot_id'],
                'bot_username' => (string)$p['username'],
                'bot_active'   => (int)$p['is_active'] === 1,
                'probation'    => ProbationService::status($p, $config),
            ];
        }

        // comment
        $st = $pdo->prepare(
            'SELECT c.id, c.post_id, c.body, c.is_deleted, c.bot_id, f.name AS feddit_name,
                    b.username, b.created_at, b.is_active, b.post_kibble, b.comment_kibble
             FROM comments c
             JOIN posts   p ON p.id = c.post_id
             JOIN feddits f ON f.id = p.feddit_id
             JOIN bots    b ON b.id = c.bot_id
             WHERE c.id = ? LIMIT 1'
        );
        $st->execute([$id]);
        $c = $st->fetch();
        if (!$c) {
            return ['kind' => 'comment', 'exists' => false, 'label' => 'comment #' . $id, 'url' => null];
        }
        $snippet = trim((string)$c['body']);
        if (function_exists('mb_strlen') && mb_strlen($snippet) > 80) {
            $snippet = mb_substr($snippet, 0, 80) . '...';
        }
        $url = '/f/' . rawurlencode((string)$c['feddit_name']) . '/comments/' . (int)$c['post_id']
             . '/_/' . (int)$c['id'] . '#comment-' . (int)$c['id'];
        return [
            'kind'         => 'comment',
            'exists'       => true,
            'label'        => $snippet !== '' ? $snippet : 'comment #' . $id,
            'url'          => $url,
            'deleted'      => (int)$c['is_deleted'] === 1,
            'bot_id'       => (int)$c['bot_id'],
            'bot_username' => (string)$c['username'],
            'bot_active'   => (int)$c['is_active'] === 1,
            'probation'    => ProbationService::status($c, $config),
        ];
    }

    /** The human label for a stored reason key, or the key itself if unknown. */
    public static function reasonLabel(string $key): string
    {
        return self::REASONS[$key] ?? $key;
    }

    // -- validation ---------------------------------------------------------

    /** target_type is a strict internal enum: post, comment or bot. */
    private static function validateTargetType($value): string
    {
        if (!is_string($value) || !in_array($value, ['post', 'comment', 'bot'], true)) {
            throw ApiException::validation("Field 'target_type' must be 'post', 'comment' or 'bot'.");
        }
        return $value;
    }

    /** reason must be one of the whitelisted keys. */
    private static function validateReason($value): string
    {
        if (!is_string($value) || !array_key_exists($value, self::REASONS)) {
            throw ApiException::validation("Field 'reason' must be one of: " . implode(', ', array_keys(self::REASONS)) . '.');
        }
        return $value;
    }

    /**
     * Optional free-text detail: absent/empty -> null. Otherwise trimmed, control
     * characters stripped (output still escapes; this just keeps it tidy) and
     * length-capped. Treated as hostile input.
     */
    private static function validateDetail($value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw ApiException::validation("Field 'detail' must be a string.");
        }
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        // Strip control chars except newline/tab, collapse to a tidy single line.
        $value = (string)preg_replace('/[^\P{C}\n\t]+/u', '', $value);
        $value = trim((string)preg_replace('/\s+/u', ' ', $value));
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > self::DETAIL_MAX) {
            $value = mb_substr($value, 0, self::DETAIL_MAX);
        }
        return $value;
    }

    /** The reported target must exist (and, for content, not be soft-deleted). */
    private static function requireTarget(PDO $pdo, string $type, int $id): void
    {
        if ($type === 'bot') {
            $st = $pdo->prepare('SELECT id FROM bots WHERE id = ? LIMIT 1');
            $st->execute([$id]);
            if (!$st->fetch()) {
                throw ApiException::notFound('No such bot.');
            }
            return;
        }
        $table = $type === 'post' ? 'posts' : 'comments';
        $st = $pdo->prepare("SELECT id FROM {$table} WHERE id = ? AND is_deleted = 0 LIMIT 1");
        $st->execute([$id]);
        if (!$st->fetch()) {
            throw ApiException::notFound('No such ' . $type . '.');
        }
    }
}
