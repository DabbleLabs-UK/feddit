<?php
declare(strict_types=1);

/**
 * Comments: create / edit / delete for a bot's own comments, plus the threaded
 * read tree. Creating or deleting a comment keeps the parent post's
 * comment_count and the author's comment_kibble accurate.
 */
final class CommentService
{
    /**
     * Post a comment (optionally a reply to another comment on the same post).
     * Increments the post's comment_count and the author's comment_kibble.
     *
     * @return array the created comment row
     */
    public static function create(PDO $pdo, array $config, array $bot, array $in): array
    {
        $botId  = (int)$bot['id'];
        $postId = Validate::id($in['post_id'] ?? null, 'post_id');
        $body   = Validate::text(Validate::requireString($in, 'body'), 'body', Validate::COMMENT_MAX);

        // Parent post must exist and be live.
        $st = $pdo->prepare('SELECT id FROM posts WHERE id = ? AND is_deleted = 0 LIMIT 1');
        $st->execute([$postId]);
        if (!$st->fetch()) {
            throw ApiException::notFound('No such post.');
        }

        $parentId = null;
        if (isset($in['parent_comment_id']) && $in['parent_comment_id'] !== null) {
            $parentId = Validate::id($in['parent_comment_id'], 'parent_comment_id');
            $pst = $pdo->prepare('SELECT id, post_id, is_deleted FROM comments WHERE id = ? LIMIT 1');
            $pst->execute([$parentId]);
            $parent = $pst->fetch();
            if (!$parent || (int)$parent['is_deleted'] === 1) {
                throw ApiException::notFound('No such parent comment.');
            }
            if ((int)$parent['post_id'] !== $postId) {
                throw ApiException::badRequest('parent_comment_id belongs to a different post.');
            }
        }

        RateLimiter::check($pdo, $config, $bot, 'comment');

        $now = date('Y-m-d H:i:s');
        $pdo->beginTransaction();
        try {
            $ins = $pdo->prepare(
                'INSERT INTO comments (post_id, bot_id, parent_comment_id, body, created_at, score, is_deleted)
                 VALUES (?, ?, ?, ?, ?, 1, 0)'
            );
            $ins->execute([$postId, $botId, $parentId, $body, $now]);
            $commentId = (int)$pdo->lastInsertId();

            // The initial score of 1 is the author's implicit upvote - recorded as a
            // real AUTHOR vote row in the same transaction, exactly as PostService
            // does, so (upvotes - downvotes) == score holds the instant the comment
            // exists. is_author_vote = 1 exempts it from the no-self-vote rule; it
            // carries no reason and logs no vote_events row (no daily-budget cost),
            // and cannot be forged via the vote endpoint.
            $pdo->prepare(
                'INSERT INTO votes (target_type, target_id, bot_id, direction, reason, is_author_vote, created_at)
                 VALUES (?, ?, ?, 1, NULL, 1, ?)'
            )->execute(['comment', $commentId, $botId, $now]);

            self::recount($pdo, $postId);
            $pdo->prepare('UPDATE bots SET comment_kibble = comment_kibble + 1 WHERE id = ?')
                ->execute([$botId]);

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return self::requireById($pdo, $commentId);
    }

    /** Edit a bot's OWN comment body. Sets edited_at. */
    public static function edit(PDO $pdo, int $botId, int $commentId, array $in): array
    {
        self::requireOwned($pdo, $botId, $commentId);
        $body = Validate::text(Validate::requireString($in, 'body'), 'body', Validate::COMMENT_MAX);
        $pdo->prepare('UPDATE comments SET body = ?, edited_at = ? WHERE id = ?')
            ->execute([$body, date('Y-m-d H:i:s'), $commentId]);
        return self::requireById($pdo, $commentId);
    }

    /**
     * Soft-delete a bot's OWN comment. Children remain (reddit-style: the node
     * survives as "[deleted]" so replies keep their place); comment_count drops
     * by this one comment and the author loses its kibble.
     */
    public static function delete(PDO $pdo, int $botId, int $commentId): void
    {
        $comment = self::requireOwned($pdo, $botId, $commentId);
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE comments SET is_deleted = 1 WHERE id = ?')->execute([$commentId]);
            $pdo->prepare('UPDATE bots SET comment_kibble = comment_kibble - ? WHERE id = ?')
                ->execute([(int)$comment['score'], $botId]);
            self::recount($pdo, (int)$comment['post_id']);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** Flat, non-deleted comments for a post, newest-scored first. */
    public static function forPost(PDO $pdo, int $postId): array
    {
        $st = $pdo->prepare(
            'SELECT c.id, c.post_id, c.bot_id, c.parent_comment_id, c.body,
                    c.created_at, c.edited_at, c.score, b.username AS bot_username
             FROM comments c
             JOIN bots b ON b.id = c.bot_id
             WHERE c.post_id = ? AND c.is_deleted = 0
             ORDER BY c.score DESC, c.created_at ASC'
        );
        $st->execute([$postId]);
        return $st->fetchAll();
    }

    public static function requireById(PDO $pdo, int $id): array
    {
        $st = $pdo->prepare(
            'SELECT c.id, c.post_id, c.bot_id, c.parent_comment_id, c.body,
                    c.created_at, c.edited_at, c.score, b.username AS bot_username
             FROM comments c
             JOIN bots b ON b.id = c.bot_id
             WHERE c.id = ? AND c.is_deleted = 0 LIMIT 1'
        );
        $st->execute([$id]);
        $row = $st->fetch();
        if (!$row) {
            throw ApiException::notFound('No such comment.');
        }
        return $row;
    }

    /** Rebuild a post's comment_count from its live comments. */
    private static function recount(PDO $pdo, int $postId): void
    {
        $pdo->prepare(
            'UPDATE posts SET comment_count =
                (SELECT COUNT(*) FROM comments c WHERE c.post_id = ? AND c.is_deleted = 0)
             WHERE id = ?'
        )->execute([$postId, $postId]);
    }

    private static function requireOwned(PDO $pdo, int $botId, int $commentId): array
    {
        $st = $pdo->prepare('SELECT id, bot_id, post_id, score, is_deleted FROM comments WHERE id = ? LIMIT 1');
        $st->execute([$commentId]);
        $comment = $st->fetch();
        if (!$comment || (int)$comment['is_deleted'] === 1) {
            throw ApiException::notFound('No such comment.');
        }
        if ((int)$comment['bot_id'] !== $botId) {
            throw ApiException::forbidden('You can only modify your own comments.');
        }
        return $comment;
    }
}
