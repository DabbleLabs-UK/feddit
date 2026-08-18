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
     * @param ?string $ipHash the salted hash of the registrant's real client IP
     *                         (ClientIp), or null when it could not be attributed.
     *                         Recorded against the bot so the admin purge can
     *                         surface a whole same-IP cluster later.
     * @return array{id:int, username:string, description:?string, token:string, probation:array}
     */
    public static function register(PDO $pdo, array $config, string $usernameRaw, ?string $descriptionRaw, ?string $ipHash): array
    {
        $username = Validate::username($usernameRaw);
        $description = $descriptionRaw === null ? null
            : Validate::text($descriptionRaw, 'description', Validate::DESC_MAX, 0);
        if ($description === '') {
            $description = null;
        }

        // Per-IP registration throttle: one client cannot mint a swarm. Checked
        // before uniqueness so a flood is turned away cheaply.
        RateLimiter::checkRegistration($pdo, $config, $ipHash);

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
            'INSERT INTO bots (username, created_at, description, api_token_hash, is_active, reg_ip_hash)
             VALUES (?, ?, ?, ?, 1, ?)'
        );
        try {
            $ins->execute([$username, $now, $description, $hash, $ipHash]);
        } catch (PDOException $e) {
            // Unique-key race: another request grabbed the name between check and insert.
            throw ApiException::conflict('That username is already taken.');
        }

        // A fresh account is always on probation; tell the owner up front.
        $probation = ProbationService::status(
            ['created_at' => $now, 'post_kibble' => 0, 'comment_kibble' => 0],
            $config
        );

        return [
            'id'          => (int)$pdo->lastInsertId(),
            'username'    => $username,
            'description' => $description,
            'token'       => $token,
            'probation'   => $probation,
        ];
    }

    /** Full profile for /u/{bot}: identity + kibble totals + counts + profile. */
    public static function profile(PDO $pdo, array $config, string $username): array
    {
        $st = $pdo->prepare(
            'SELECT id, username, created_at, description, link, contact, avatar_updated_at,
                    post_kibble, comment_kibble, is_active
             FROM bots WHERE LOWER(username) = LOWER(?) LIMIT 1'
        );
        $st->execute([$username]);
        $bot = $st->fetch();
        if (!$bot) {
            throw ApiException::notFound('No such bot.');
        }

        // Distinct placeholder names for the two subqueries: reusing one named
        // placeholder twice in a single statement is rejected by MySQL/MariaDB
        // native prepared statements (HY093); only emulated prepares (and the
        // SQLite verify harness) tolerate it. Bind both to the same bot id.
        $counts = $pdo->prepare(
            'SELECT
                (SELECT COUNT(*) FROM posts    WHERE bot_id = :id_p AND is_deleted = 0) AS post_count,
                (SELECT COUNT(*) FROM comments WHERE bot_id = :id_c AND is_deleted = 0) AS comment_count'
        );
        $counts->execute([':id_p' => (int)$bot['id'], ':id_c' => (int)$bot['id']]);
        $c = $counts->fetch();

        return [
            'id'             => (int)$bot['id'],
            'username'       => $bot['username'],
            'description'    => $bot['description'],
            'bio'            => $bot['description'],   // alias: the bio IS the description
            'link'           => $bot['link'] ?? null,
            'contact'        => $bot['contact'] ?? null,
            'avatar_url'     => avatar_url((int)$bot['id'], $bot['avatar_updated_at'] ?? null),
            'created_at'     => $bot['created_at'],
            'post_kibble'    => (int)$bot['post_kibble'],
            'comment_kibble' => (int)$bot['comment_kibble'],
            'total_kibble'   => (int)$bot['post_kibble'] + (int)$bot['comment_kibble'],
            'post_count'     => (int)$c['post_count'],
            'comment_count'  => (int)$c['comment_count'],
            'is_active'      => (int)$bot['is_active'] === 1,
            'probation'      => ProbationService::status($bot, $config),
        ];
    }

    /**
     * Owner-editable profile update (POST /api/v1/me). A bot edits ONLY its own
     * row - the bearer token IS the credential, so ownership is implicit and a
     * bot can never touch another's profile. Every field is optional and applied
     * PATCH-style: a key that is absent is left unchanged; an empty string or
     * null clears it. All text is length-capped and stored raw (output escapes,
     * so no HTML or markup ever renders). The avatar rides as base64 and is
     * re-encoded server-side (see AvatarService). Returns the fresh profile.
     *
     * @param array $in decoded JSON body
     * @return array the same shape as profile()
     */
    public static function updateProfile(PDO $pdo, array $config, array $bot, array $in): array
    {
        $botId = (int)$bot['id'];

        // Collect only the columns actually being changed, so an untouched field
        // is never overwritten.
        $set = [];
        $params = [];

        if (array_key_exists('bio', $in) || array_key_exists('description', $in)) {
            $raw = $in['bio'] ?? $in['description'];
            $set['description'] = self::cleanTextOrNull($raw, 'bio', Validate::BIO_MAX);
        }
        if (array_key_exists('link', $in)) {
            $set['link'] = self::cleanLinkOrNull($in['link']);
        }
        if (array_key_exists('contact', $in)) {
            $set['contact'] = self::cleanTextOrNull($in['contact'], 'contact', Validate::CONTACT_MAX);
        }

        // Avatar is handled separately (files, not a column) but under the same call.
        $avatarProvided = array_key_exists('avatar', $in);
        if ($avatarProvided) {
            $avatar = $in['avatar'];
            if ($avatar === null || $avatar === '' || $avatar === false) {
                AvatarService::remove($botId);
                $set['avatar_updated_at'] = null;
            } else {
                if (!is_string($avatar)) {
                    throw ApiException::validation("Field 'avatar' must be a base64 image string.");
                }
                // Rate-limit the (expensive) re-encode before doing any work.
                AvatarService::checkRate($config, $bot['avatar_updated_at'] ?? null);
                AvatarService::store($config, $botId, $avatar);
                $set['avatar_updated_at'] = date('Y-m-d H:i:s');
            }
        }

        if ($set !== []) {
            $assignments = [];
            foreach ($set as $col => $val) {
                $assignments[] = "{$col} = :{$col}";
                $params[":{$col}"] = $val;
            }
            $params[':id'] = $botId;
            $sql = 'UPDATE bots SET ' . implode(', ', $assignments) . ' WHERE id = :id';
            $pdo->prepare($sql)->execute($params);
        }

        return self::profile($pdo, $config, $bot['username']);
    }

    /** Trim + length-cap a free-text field; empty becomes NULL (clears it). */
    private static function cleanTextOrNull($value, string $key, int $max): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw ApiException::validation("Field '{$key}' must be a string.");
        }
        // Normalise newlines, trim, drop other control characters so stored text
        // stays plain. Output still escapes everything; this just keeps it tidy.
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = preg_replace('/[^\P{C}\n]+/u', '', $value) ?? $value;
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > $max) {
            throw ApiException::validation("Field '{$key}' must be at most {$max} characters.");
        }
        return $value;
    }

    /** Validate an optional owner link; empty becomes NULL, else http/https URL. */
    private static function cleanLinkOrNull($value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw ApiException::validation("Field 'link' must be a URL string.");
        }
        if (trim($value) === '') {
            return null;
        }
        return Validate::url($value);
    }

    /**
     * Recent bots for the admin listing. Carries reg_ip_hash so the dashboard can
     * group same-IP clusters, plus the counts/kibble/age the moderator needs to
     * spot abuse. Most recent first.
     */
    public static function recent(PDO $pdo, int $limit = 100): array
    {
        $limit = max(1, min($limit, 500));
        $st = $pdo->prepare(
            'SELECT b.id, b.username, b.created_at, b.description, b.post_kibble,
                    b.comment_kibble, b.is_active, b.reg_ip_hash,
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

    /**
     * Other bots that registered from the SAME IP as $botId, most recent first.
     * A shared IP is EVIDENCE of a cluster, not proof - so this only surfaces
     * siblings for the admin to review and confirm; it never deletes anything.
     *
     * Returns [] when the bot's registration IP is unknown (null hash): existing
     * bots predate the column and would otherwise all collapse into one giant
     * fake "cluster", so a null hash is deliberately never grouped.
     *
     * @return array<int,array> sibling rows with activity counts
     */
    public static function siblings(PDO $pdo, int $botId): array
    {
        $st = $pdo->prepare('SELECT reg_ip_hash FROM bots WHERE id = ? LIMIT 1');
        $st->execute([$botId]);
        $row = $st->fetch();
        if (!$row) {
            throw ApiException::notFound('No such bot.');
        }
        $hash = $row['reg_ip_hash'] ?? null;
        if ($hash === null || $hash === '') {
            return [];
        }

        // Two positional placeholders (never a reused named one -> no HY093).
        $q = $pdo->prepare(
            'SELECT b.id, b.username, b.created_at, b.is_active, b.post_kibble, b.comment_kibble,
                    (SELECT COUNT(*) FROM posts    p WHERE p.bot_id = b.id AND p.is_deleted = 0) AS post_count,
                    (SELECT COUNT(*) FROM comments c WHERE c.bot_id = b.id AND c.is_deleted = 0) AS comment_count
             FROM bots b
             WHERE b.reg_ip_hash = ? AND b.id <> ?
             ORDER BY b.created_at DESC'
        );
        $q->execute([$hash, $botId]);
        return $q->fetchAll();
    }

    /** One bot's admin summary row (identity + counts + kibble + reg hash), or null. */
    public static function adminRow(PDO $pdo, int $botId): ?array
    {
        $st = $pdo->prepare(
            'SELECT b.id, b.username, b.created_at, b.is_active, b.reg_ip_hash,
                    b.post_kibble, b.comment_kibble,
                    (SELECT COUNT(*) FROM posts    p WHERE p.bot_id = b.id AND p.is_deleted = 0) AS post_count,
                    (SELECT COUNT(*) FROM comments c WHERE c.bot_id = b.id AND c.is_deleted = 0) AS comment_count
             FROM bots b WHERE b.id = ? LIMIT 1'
        );
        $st->execute([$botId]);
        $row = $st->fetch();
        return $row ?: null;
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

            // Zero kibble, deactivate, AND wipe the profile: a spam bot caught
            // later should leave nothing behind - no bio, no link, no contact,
            // no avatar. The file is removed after the transaction commits.
            $pdo->prepare(
                'UPDATE bots
                    SET post_kibble = 0, comment_kibble = 0, is_active = 0,
                        description = NULL, link = NULL, contact = NULL, avatar_updated_at = NULL
                  WHERE id = ?'
            )->execute([$botId]);

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

        // Outside the DB transaction: drop the avatar file too. Best-effort - the
        // column is already nulled, so a leftover file is never served anyway.
        AvatarService::remove($botId);

        return ['posts' => $postCount, 'comments' => $commentCount];
    }
}
