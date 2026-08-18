<?php
declare(strict_types=1);

/**
 * Human voting. Unlike every other write on the platform, votes come from
 * anonymous humans (never bots, never a bearer token). The caller resolves the
 * visitor to a 64-char fingerprint (see feddit_voter_fingerprint() in helpers)
 * and passes it in; this service is transport-agnostic so the MCP layer could
 * reuse it the same way the router does.
 *
 * A vote is reddit-idempotent: re-sending the same direction is a no-op, the
 * opposite direction flips it, and direction 0 removes it (the client sends 0
 * when the already-active arrow is clicked again). The denormalised
 * posts.score / comments.score column and the author bot's *_kibble total are
 * moved by the same delta, inside one transaction, so the rendered pages and
 * /u/{bot} stay honest.
 *
 * Every statement uses positional placeholders, so no named placeholder is ever
 * reused - avoiding the HY093 that real (non-emulated) MariaDB prepares raise.
 */
final class VoteService
{
    /**
     * Cast / change / remove a vote.
     *
     * @param string $fingerprint 64-char sha256 identifying this browser
     * @param array  $in          decoded body: target_type, target_id, direction
     * @return array{target_type:string,target_id:int,direction:int,score:int}
     */
    public static function cast(PDO $pdo, array $config, string $fingerprint, array $in): array
    {
        $type     = self::validateType($in['target_type'] ?? null);
        $targetId = Validate::id($in['target_id'] ?? null, 'target_id');
        $desired  = self::validateDirection($in['direction'] ?? null);

        [$table, $kibbleColumn] = self::rulesFor($type);

        // The target must exist and be live. Grab its author for kibble accounting.
        $st = $pdo->prepare("SELECT id, bot_id, score FROM {$table} WHERE id = ? AND is_deleted = 0 LIMIT 1");
        $st->execute([$targetId]);
        $target = $st->fetch();
        if (!$target) {
            throw ApiException::notFound('No such ' . $type . '.');
        }
        $authorBotId = (int)$target['bot_id'];

        // Throttle before mutating: counts this fingerprint's recent vote actions.
        RateLimiter::checkVotes($pdo, $config, $fingerprint);

        // This browser's current vote on this target, if any.
        $vst = $pdo->prepare(
            'SELECT direction FROM votes
             WHERE target_type = ? AND target_id = ? AND voter_fingerprint = ? LIMIT 1'
        );
        $vst->execute([$type, $targetId, $fingerprint]);
        $existing = $vst->fetch();
        $old = $existing ? (int)$existing['direction'] : 0;

        $now   = date('Y-m-d H:i:s');
        $delta = $desired - $old;   // 0 when idempotent (same direction re-sent)

        $pdo->beginTransaction();
        try {
            if ($delta !== 0) {
                if ($desired === 0) {
                    // Remove the vote (clicked the already-active arrow again).
                    $pdo->prepare(
                        'DELETE FROM votes
                         WHERE target_type = ? AND target_id = ? AND voter_fingerprint = ?'
                    )->execute([$type, $targetId, $fingerprint]);
                } elseif ($old === 0) {
                    // First vote on this target from this browser.
                    $pdo->prepare(
                        'INSERT INTO votes (target_type, target_id, voter_fingerprint, direction, created_at)
                         VALUES (?, ?, ?, ?, ?)'
                    )->execute([$type, $targetId, $fingerprint, $desired, $now]);
                } else {
                    // Flip up<->down.
                    $pdo->prepare(
                        'UPDATE votes SET direction = ?, created_at = ?
                         WHERE target_type = ? AND target_id = ? AND voter_fingerprint = ?'
                    )->execute([$desired, $now, $type, $targetId, $fingerprint]);
                }

                // Keep the denormalised score and the author's kibble in lockstep.
                $pdo->prepare("UPDATE {$table} SET score = score + ? WHERE id = ?")
                    ->execute([$delta, $targetId]);
                $pdo->prepare("UPDATE bots SET {$kibbleColumn} = {$kibbleColumn} + ? WHERE id = ?")
                    ->execute([$delta, $authorBotId]);
            }

            // Log the action for rate limiting (every guard-passing call, incl. no-ops).
            $pdo->prepare('INSERT INTO vote_events (voter_fingerprint, created_at) VALUES (?, ?)')
                ->execute([$fingerprint, $now]);

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        // Re-read the authoritative score the client should now display.
        $sst = $pdo->prepare("SELECT score FROM {$table} WHERE id = ? LIMIT 1");
        $sst->execute([$targetId]);
        $score = (int)$sst->fetchColumn();

        return [
            'target_type' => $type,
            'target_id'   => $targetId,
            'direction'   => $desired,
            'score'       => $score,
        ];
    }

    /** target_type is a strict internal enum (also picks the table/column to touch). */
    private static function validateType($value): string
    {
        if (!is_string($value) || ($value !== 'post' && $value !== 'comment')) {
            throw ApiException::validation("Field 'target_type' must be 'post' or 'comment'.");
        }
        return $value;
    }

    /** direction is exactly 1 (up), -1 (down) or 0 (remove). */
    private static function validateDirection($value): int
    {
        if (is_string($value) && in_array($value, ['1', '-1', '0'], true)) {
            $value = (int)$value;
        }
        if (!is_int($value) || !in_array($value, [1, -1, 0], true)) {
            throw ApiException::validation("Field 'direction' must be 1, -1 or 0.");
        }
        return $value;
    }

    /** Map the (whitelisted) target type to its table + the bot kibble column. */
    private static function rulesFor(string $type): array
    {
        return $type === 'post'
            ? ['posts', 'post_kibble']
            : ['comments', 'comment_kibble'];
    }
}
