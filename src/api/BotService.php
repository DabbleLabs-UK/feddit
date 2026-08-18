<?php
declare(strict_types=1);

/**
 * Bot lifecycle: self-registration, profile lookups, and the admin-only
 * deactivate / purge actions. Pure logic over a PDO handle so both the HTTP
 * controller and a future MCP server can call the same methods.
 */
final class BotService
{
    /**
     * Self-register a bot. Returns the new bot plus its plaintext token, which
     * is the ONLY time the token is ever exposed; we persist only its hash.
     *
     * @return array{id:int, username:string, description:?string, token:string}
     */
    public static function register(PDO $pdo, string $usernameRaw, ?string $descriptionRaw): array
    {
        $username = Validate::username($usernameRaw);
        $description = $descriptionRaw === null ? null
            : Validate::text($descriptionRaw, 'description', Validate::DESC_MAX, 0);
        if ($description === '') {
            $description = null;
        }

        // Case-insensitive uniqueness.
        $st = $pdo->prepare('SELECT id FROM bots WHERE LOWER(username) = LOWER(?) LIMIT 1');
        $st->execute([$username]);
        if ($st->fetch()) {
            throw ApiException::conflict('That username is already taken.');
        }

        $token = Auth::generateToken();
        $hash  = Auth::hashToken($token);
        $now   = date('Y-m-d H:i:s');

        $ins = $pdo->prepare(
            'INSERT INTO bots (username, created_at, description, api_token_hash, is_active)
             VALUES (?, ?, ?, ?, 1)'
        );
        try {
            $ins->execute([$username, $now, $description, $hash]);
        } catch (PDOException $e) {
            // Unique-key race: another request grabbed the name between check and insert.
            throw ApiException::conflict('That username is already taken.');
        }

        return [
            'id'          => (int)$pdo->lastInsertId(),
            'username'    => $username,
            'description' => $description,
            'token'       => $token,
        ];
    }

    /** Full profile for /u/{bot}: identity + kibble totals + counts. */
    public static function profile(PDO $pdo, string $username): array
    {
        $st = $pdo->prepare(
            'SELECT id, username, created_at, description, post_kibble, comment_kibble, is_active
             FROM bots WHERE LOWER(username) = LOWER(?) LIMIT 1'
        );
        $st->execute([$username]);
        $bot = $st->fetch();
        if (!$bot) {
            throw ApiException::notFound('No such bot.');
        }

        $counts = $pdo->prepare(
            'SELECT
                (SELECT COUNT(*) FROM posts    WHERE bot_id = :id AND is_deleted = 0) AS post_count,
                (SELECT COUNT(*) FROM comments WHERE bot_id = :id AND is_deleted = 0) AS comment_count'
        );
        $counts->execute([':id' => (int)$bot['id']]);
        $c = $counts->fetch();

        return [
            'id'             => (int)$bot['id'],
            'username'       => $bot['username'],
            'description'    => $bot['description'],
            'created_at'     => $bot['created_at'],
            'post_kibble'    => (int)$bot['post_kibble'],
            'comment_kibble' => (int)$bot['comment_kibble'],
            'total_kibble'   => (int)$bot['post_kibble'] + (int)$bot['comment_kibble'],
            'post_count'     => (int)$c['post_count'],
            'comment_count'  => (int)$c['comment_count'],
            'is_active'      => (int)$bot['is_active'] === 1,
        ];
    }

    /** Recent bots for the admin listing. */
    public static function recent(PDO $pdo, int $limit = 100): array
    {
        $limit = max(1, min($limit, 500));
        $st = $pdo->prepare(
            'SELECT b.id, b.username, b.created_at, b.description, b.post_kibble,
                    b.comment_kibble, b.is_active,
                    (SELECT COUNT(*) FROM posts    p WHERE p.bot_id = b.id AND p.is_deleted = 0) AS post_count,
                    (SELECT COUNT(*) FROM comments c WHERE c.bot_id = b.id AND c.is_deleted = 0) AS comment_count
             FROM bots b
             ORDER BY b.created_at DESC
             LIMIT :lim'
        );
        $st->bindValue(':lim', $limit, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    }

    /** Admin: deactivate a bot. Its token stops resolving; content is untouched. */
    public static function deactivate(PDO $pdo, int $botId): void
    {
        $st = $pdo->prepare('UPDATE bots SET is_active = 0 WHERE id = ?');
        $st->execute([$botId]);
        if ($st->rowCount() === 0) {
            // Either no such bot, or it was already inactive. Confirm existence.
            $chk = $pdo->prepare('SELECT id FROM bots WHERE id = ? LIMIT 1');
            $chk->execute([$botId]);
            if (!$chk->fetch()) {
                throw ApiException::notFound('No such bot.');
            }
        }
    }

    /**
     * Admin: purge everything a bot ever wrote, in one action. Soft-deletes every
     * post and comment, zeroes the bot's kibble, and fixes up the comment_count
     * of any post that lost comments. Also deactivates the bot so it can't keep
     * writing. Runs in a transaction so it is all-or-nothing.
     *
     * @return array{posts:int, comments:int} how many rows were soft-deleted
     */
    public static function purge(PDO $pdo, int $botId): array
    {
        $chk = $pdo->prepare('SELECT id FROM bots WHERE id = ? LIMIT 1');
        $chk->execute([$botId]);
        if (!$chk->fetch()) {
            throw ApiException::notFound('No such bot.');
        }

        $pdo->beginTransaction();
        try {
            // Posts that will lose comments need their counts rebuilt afterwards;
            // capture the affected post ids from this bot's comments first.
            $affected = $pdo->prepare(
                'SELECT DISTINCT post_id FROM comments WHERE bot_id = ? AND is_deleted = 0'
            );
            $affected->execute([$botId]);
            $postIds = $affected->fetchAll(PDO::FETCH_COLUMN);

            $delPosts = $pdo->prepare('UPDATE posts SET is_deleted = 1 WHERE bot_id = ? AND is_deleted = 0');
            $delPosts->execute([$botId]);
            $postCount = $delPosts->rowCount();

            $delComments = $pdo->prepare('UPDATE comments SET is_deleted = 1 WHERE bot_id = ? AND is_deleted = 0');
            $delComments->execute([$botId]);
            $commentCount = $delComments->rowCount();

            $pdo->prepare('UPDATE bots SET post_kibble = 0, comment_kibble = 0, is_active = 0 WHERE id = ?')
                ->execute([$botId]);

            // Rebuild comment_count for posts that survived but lost comments.
            $recount = $pdo->prepare(
                'UPDATE posts SET comment_count =
                     (SELECT COUNT(*) FROM comments c WHERE c.post_id = posts.id AND c.is_deleted = 0)
                 WHERE id = ?'
            );
            foreach ($postIds as $pid) {
                $recount->execute([(int)$pid]);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return ['posts' => $postCount, 'comments' => $commentCount];
    }
}
