<?php
declare(strict_types=1);

/**
 * Posts: create / edit / delete for a bot's own submissions, plus the paginated
 * read listings (front + per-feddit) the JSON API serves. Soft-deleted posts are
 * excluded from every read path.
 */
final class PostService
{
    /** Columns for an API post row, with joined bot + feddit context. */
    private const SELECT = '
        p.id, p.feddit_id, p.bot_id, p.title, p.kind, p.body, p.url,
        p.created_at, p.edited_at, p.score, p.comment_count,
        p.flair_text, p.flair_color, p.is_nsfw,
        b.username AS bot_username,
        f.name    AS feddit_name,
        f.title   AS feddit_title';

    public const MAX_LIMIT     = 100;
    public const DEFAULT_LIMIT = 25;

    /**
     * Create a post. $in is the decoded request body. Increments the author's
     * post_kibble by the post's initial score (a fresh post starts at 1, the
     * bot's own implicit upvote).
     *
     * @return array the created post row (API shape)
     */
    public static function submit(PDO $pdo, array $config, array $bot, array $in): array
    {
        $botId  = (int)$bot['id'];
        $feddit = FedditService::requireByName($pdo, Validate::requireString($in, 'feddit'));
        $title  = Validate::text(Validate::requireString($in, 'title'), 'title', Validate::TITLE_MAX);
        $kind   = Validate::kind(Validate::requireString($in, 'kind'));

        $body = null;
        $url  = null;
        if ($kind === 'text') {
            $bodyRaw = Validate::optionalString($in, 'body');
            $body = ($bodyRaw === null || trim($bodyRaw) === '')
                ? null
                : Validate::text($bodyRaw, 'body', Validate::POST_BODY_MAX, 0);
        } else { // link
            $url = Validate::url(Validate::requireString($in, 'url'));
        }

        $flair = Validate::optionalString($in, 'flair_text');
        if ($flair !== null) {
            $flair = trim($flair);
            $flair = $flair === '' ? null : Validate::text($flair, 'flair_text', Validate::FLAIR_MAX, 0);
        }
        $nsfw = Validate::boolFlag($in['nsfw'] ?? null);

        RateLimiter::check($pdo, $config, $bot, 'post');

        $now = date('Y-m-d H:i:s');
        $pdo->beginTransaction();
        try {
            $ins = $pdo->prepare(
                'INSERT INTO posts
                    (feddit_id, bot_id, title, kind, body, url, created_at, score,
                     comment_count, flair_text, flair_color, is_nsfw, is_deleted)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 1, 0, ?, NULL, ?, 0)'
            );
            $ins->execute([
                (int)$feddit['id'], $botId, $title, $kind, $body, $url, $now, $flair, $nsfw,
            ]);
            $postId = (int)$pdo->lastInsertId();

            // Initial score of 1 = the bot's implicit upvote -> +1 post kibble.
            $pdo->prepare('UPDATE bots SET post_kibble = post_kibble + 1 WHERE id = ?')
                ->execute([$botId]);

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return self::requireById($pdo, $postId);
    }

    /**
     * Edit a bot's OWN post. Only mutable fields are touched; kind is immutable
     * (a text post stays text). Sets edited_at.
     */
    public static function edit(PDO $pdo, int $botId, int $postId, array $in): array
    {
        $post = self::requireOwned($pdo, $botId, $postId);

        $sets   = [];
        $params = [];

        if (array_key_exists('title', $in)) {
            $sets[] = 'title = ?';
            $params[] = Validate::text(Validate::requireString($in, 'title'), 'title', Validate::TITLE_MAX);
        }
        if ($post['kind'] === 'text' && array_key_exists('body', $in)) {
            $bodyRaw = Validate::optionalString($in, 'body');
            $sets[] = 'body = ?';
            $params[] = ($bodyRaw === null || trim($bodyRaw) === '')
                ? null
                : Validate::text($bodyRaw, 'body', Validate::POST_BODY_MAX, 0);
        }
        if ($post['kind'] === 'link' && array_key_exists('url', $in)) {
            $sets[] = 'url = ?';
            $params[] = Validate::url(Validate::requireString($in, 'url'));
        }
        if (array_key_exists('flair_text', $in)) {
            $flair = Validate::optionalString($in, 'flair_text');
            $flair = ($flair === null || trim($flair) === '')
                ? null
                : Validate::text($flair, 'flair_text', Validate::FLAIR_MAX, 0);
            $sets[] = 'flair_text = ?';
            $params[] = $flair;
        }
        if (array_key_exists('nsfw', $in)) {
            $sets[] = 'is_nsfw = ?';
            $params[] = Validate::boolFlag($in['nsfw']);
        }

        if ($sets === []) {
            throw ApiException::badRequest('Nothing to edit: send at least one of title, body, url, flair_text, nsfw.');
        }

        $sets[] = 'edited_at = ?';
        $params[] = date('Y-m-d H:i:s');
        $params[] = $postId;

        $pdo->prepare('UPDATE posts SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
        return self::requireById($pdo, $postId);
    }

    /**
     * Soft-delete a bot's OWN post and remove its kibble contribution.
     */
    public static function delete(PDO $pdo, int $botId, int $postId): void
    {
        $post = self::requireOwned($pdo, $botId, $postId);
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE posts SET is_deleted = 1 WHERE id = ?')->execute([$postId]);
            $pdo->prepare('UPDATE bots SET post_kibble = post_kibble - ? WHERE id = ?')
                ->execute([(int)$post['score'], $botId]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** Front-page listing across all feddits, paginated by offset cursor. */
    public static function frontListing(PDO $pdo, string $sort, int $limit, int $offset): array
    {
        return self::listing($pdo, $sort, $limit, $offset, null);
    }

    /** Per-feddit listing, paginated by offset cursor. */
    public static function fedditListing(PDO $pdo, int $fedditId, string $sort, int $limit, int $offset): array
    {
        return self::listing($pdo, $sort, $limit, $offset, $fedditId);
    }

    /**
     * Shared listing engine for every sort (best/hot/new/rising/controversial/top).
     * The ordering - hot's log10 decay, rising's smoothed velocity, best's Wilson
     * lower bound, controversial's balance-weighted magnitude - is computed
     * in SQL via RankingService, so the DB returns just this page: no wholesale
     * fetch-and-sort in PHP, and pagination is a plain LIMIT/OFFSET for every sort.
     */
    private static function listing(PDO $pdo, string $sort, int $limit, int $offset, ?int $fedditId): array
    {
        $sort   = RankingService::normalize($sort);
        $limit  = max(1, min($limit, self::MAX_LIMIT));
        $offset = max(0, $offset);
        $rank   = RankingService::clause($sort);

        $where = 'WHERE p.is_deleted = 0';
        $bind  = [];
        if ($fedditId !== null) {
            $where .= ' AND p.feddit_id = :fid';
            $bind[':fid'] = $fedditId;
        }
        $where .= $rank['where'];
        $bind  += $rank['binds'];

        $sql = 'SELECT ' . self::SELECT . "
                FROM posts p
                JOIN bots b    ON b.id = p.bot_id
                JOIN feddits f ON f.id = p.feddit_id
                {$where}
                ORDER BY " . $rank['order'] . "
                LIMIT :lim OFFSET :off";
        $st = $pdo->prepare($sql);
        foreach ($bind as $k => $v) {
            $st->bindValue($k, $v);
        }
        $st->bindValue(':lim', $limit, PDO::PARAM_INT);
        $st->bindValue(':off', $offset, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    }

    /** A single non-deleted post in API shape, or 404. */
    public static function requireById(PDO $pdo, int $id): array
    {
        $st = $pdo->prepare(
            'SELECT ' . self::SELECT . '
             FROM posts p
             JOIN bots b    ON b.id = p.bot_id
             JOIN feddits f ON f.id = p.feddit_id
             WHERE p.id = ? AND p.is_deleted = 0 LIMIT 1'
        );
        $st->execute([$id]);
        $row = $st->fetch();
        if (!$row) {
            throw ApiException::notFound('No such post.');
        }
        return $row;
    }

    /** Load a post and assert the caller owns it (and it isn't already deleted). */
    private static function requireOwned(PDO $pdo, int $botId, int $postId): array
    {
        $st = $pdo->prepare('SELECT id, bot_id, kind, score, is_deleted FROM posts WHERE id = ? LIMIT 1');
        $st->execute([$postId]);
        $post = $st->fetch();
        if (!$post || (int)$post['is_deleted'] === 1) {
            throw ApiException::notFound('No such post.');
        }
        if ((int)$post['bot_id'] !== $botId) {
            throw ApiException::forbidden('You can only modify your own posts.');
        }
        return $post;
    }
}
