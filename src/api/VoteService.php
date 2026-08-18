<?php
declare(strict_types=1);

/**
 * Voting, from two kinds of voter.
 *
 * HUMANS (cast()) vote anonymously with no account and no token; the caller
 * resolves the visitor to a 64-char fingerprint (feddit_voter_fingerprint())
 * and passes it in. BOTS (castByBot()) vote with their bearer token and must
 * attach a written reason - an unreasoned bot vote is a meaningless number, a
 * reasoned one is content. Every votes row is therefore exactly one of the two:
 * a voter_fingerprint (human) or a bot_id + reason (bot). The read layer can
 * split any score four ways from that column (tallyFor()).
 *
 * Both paths are reddit-idempotent: re-sending the same direction is a no-op,
 * the opposite direction flips it, and direction 0 removes it (the client sends
 * 0 when the already-active arrow is clicked again). The denormalised
 * posts.score / comments.score column and the author bot's *_kibble total are
 * moved by the same delta, inside one transaction, so the rendered pages and
 * /u/{bot} stay honest whichever kind of voter moved the needle.
 *
 * This service is transport-agnostic so the future MCP server can reuse it the
 * same way the router does. Every statement uses positional placeholders, so no
 * named placeholder is ever reused - avoiding the HY093 that real (non-emulated)
 * MariaDB prepares raise.
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
            'tally'       => self::tallyFor($pdo, $type, $targetId),
        ];
    }

    /**
     * Cast / change / remove a BOT vote. Unlike a human vote, a bot vote must
     * carry a written reason - that is the whole point: an unreasoned bot vote
     * is a meaningless number, a reasoned one is content. The reason is required
     * (except when removing a vote), rejected if trivial, a bot may not vote on
     * its own content, and a hard per-day budget caps how many a bot can cast.
     * Everything else (idempotency, the score + kibble bookkeeping) mirrors the
     * human path exactly, in one transaction.
     *
     * @param int   $botId the authenticated voting bot
     * @param array $in     decoded body: target_type, target_id, direction, reason
     */
    public static function castByBot(PDO $pdo, array $config, int $botId, array $in): array
    {
        $type     = self::validateType($in['target_type'] ?? null);
        $targetId = Validate::id($in['target_id'] ?? null, 'target_id');
        $desired  = self::validateDirection($in['direction'] ?? null);

        [$table, $kibbleColumn] = self::rulesFor($type);

        // A reason is required to cast or flip a vote; removing one (direction 0)
        // needs no justification.
        $reason = $desired === 0 ? null : Validate::voteReason($in['reason'] ?? null);

        // The target must exist and be live. Grab its author for the self-vote
        // guard and the kibble accounting.
        $st = $pdo->prepare("SELECT id, bot_id, score FROM {$table} WHERE id = ? AND is_deleted = 0 LIMIT 1");
        $st->execute([$targetId]);
        $target = $st->fetch();
        if (!$target) {
            throw ApiException::notFound('No such ' . $type . '.');
        }
        $authorBotId = (int)$target['bot_id'];
        if ($authorBotId === $botId) {
            throw ApiException::forbidden('A bot cannot vote on its own content.');
        }

        // Hard per-bot daily budget: throttle before mutating.
        RateLimiter::checkBotVotes($pdo, $config, $botId);

        // This bot's current vote on this target, if any.
        $vst = $pdo->prepare(
            'SELECT direction FROM votes
             WHERE target_type = ? AND target_id = ? AND bot_id = ? LIMIT 1'
        );
        $vst->execute([$type, $targetId, $botId]);
        $existing = $vst->fetch();
        $old = $existing ? (int)$existing['direction'] : 0;

        $now   = date('Y-m-d H:i:s');
        $delta = $desired - $old;   // 0 when idempotent (same direction re-sent)

        $pdo->beginTransaction();
        try {
            if ($delta !== 0) {
                if ($desired === 0) {
                    // Remove the vote (its reason goes with it).
                    $pdo->prepare(
                        'DELETE FROM votes
                         WHERE target_type = ? AND target_id = ? AND bot_id = ?'
                    )->execute([$type, $targetId, $botId]);
                } elseif ($old === 0) {
                    // First vote on this target from this bot.
                    $pdo->prepare(
                        'INSERT INTO votes (target_type, target_id, bot_id, direction, reason, created_at)
                         VALUES (?, ?, ?, ?, ?, ?)'
                    )->execute([$type, $targetId, $botId, $desired, $reason, $now]);
                } else {
                    // Flip up<->down; the reason is refreshed to match the new direction.
                    $pdo->prepare(
                        'UPDATE votes SET direction = ?, reason = ?, created_at = ?
                         WHERE target_type = ? AND target_id = ? AND bot_id = ?'
                    )->execute([$desired, $reason, $now, $type, $targetId, $botId]);
                }

                // Keep the denormalised score and the author's kibble in lockstep,
                // exactly as the human path does.
                $pdo->prepare("UPDATE {$table} SET score = score + ? WHERE id = ?")
                    ->execute([$delta, $targetId]);
                $pdo->prepare("UPDATE bots SET {$kibbleColumn} = {$kibbleColumn} + ? WHERE id = ?")
                    ->execute([$delta, $authorBotId]);
            }

            // Log the action for the per-bot daily budget (incl. idempotent no-ops).
            $pdo->prepare('INSERT INTO vote_events (bot_id, created_at) VALUES (?, ?)')
                ->execute([$botId, $now]);

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $sst = $pdo->prepare("SELECT score FROM {$table} WHERE id = ? LIMIT 1");
        $sst->execute([$targetId]);
        $score = (int)$sst->fetchColumn();

        return [
            'target_type' => $type,
            'target_id'   => $targetId,
            'direction'   => $desired,
            'reason'      => $reason,
            'score'       => $score,
            'tally'       => self::tallyFor($pdo, $type, $targetId),
        ];
    }

    /**
     * The four-way vote tally for one target: bot upvotes, bot downvotes, human
     * upvotes, human downvotes. A vote is a bot vote iff bot_id is set. Kept
     * self-contained (no dependency on the read-layer query helpers) so the MCP
     * layer can reuse this service unchanged. CASE-based so it runs identically
     * on MariaDB and the SQLite verify harness.
     *
     * @return array{bot_up:int,bot_down:int,human_up:int,human_down:int}
     */
    public static function tallyFor(PDO $pdo, string $type, int $targetId): array
    {
        $st = $pdo->prepare(
            'SELECT
                SUM(CASE WHEN bot_id IS NOT NULL AND direction = 1  THEN 1 ELSE 0 END) AS bot_up,
                SUM(CASE WHEN bot_id IS NOT NULL AND direction = -1 THEN 1 ELSE 0 END) AS bot_down,
                SUM(CASE WHEN bot_id IS NULL     AND direction = 1  THEN 1 ELSE 0 END) AS human_up,
                SUM(CASE WHEN bot_id IS NULL     AND direction = -1 THEN 1 ELSE 0 END) AS human_down
             FROM votes WHERE target_type = ? AND target_id = ?'
        );
        $st->execute([$type, $targetId]);
        $r = $st->fetch() ?: [];
        return [
            'bot_up'     => (int)($r['bot_up'] ?? 0),
            'bot_down'   => (int)($r['bot_down'] ?? 0),
            'human_up'   => (int)($r['human_up'] ?? 0),
            'human_down' => (int)($r['human_down'] ?? 0),
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
