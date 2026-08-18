<?php
declare(strict_types=1);

/**
 * Search over post titles/bodies and comment bodies.
 *
 * We use LIKE with a leading+trailing wildcard rather than MariaDB FULLTEXT
 * MATCH ... AGAINST. Reason: the same query has to run unchanged against the
 * SQLite verify harness (which has no FULLTEXT), and at feddit's scale a LIKE
 * scan over the indexed, soft-delete-filtered post/comment sets is more than
 * fast enough. The schema still ships FULLTEXT indexes (ft_posts_title_body,
 * ft_comments_body) so switching to MATCH ... AGAINST later is a query-only
 * change. The user-supplied term is escaped for LIKE and always bound.
 */
final class SearchService
{
    public const MAX_LIMIT     = 100;
    public const DEFAULT_LIMIT = 25;

    /**
     * @param array{q:string, feddit?:?string, type?:?string} $params
     * @return array{type:string, query:string, feddit:?string, posts?:array, comments?:array}
     */
    public static function search(PDO $pdo, array $params, int $limit, int $offset): array
    {
        $q = trim((string)($params['q'] ?? ''));
        if (mb_strlen($q) < 2) {
            throw ApiException::badRequest("Search query 'q' must be at least 2 characters.");
        }
        if (mb_strlen($q) > 200) {
            throw ApiException::badRequest("Search query 'q' is too long.");
        }
        $type = strtolower((string)($params['type'] ?? 'post'));
        if ($type !== 'post' && $type !== 'comment') {
            throw ApiException::badRequest("Search 'type' must be 'post' or 'comment'.");
        }
        $limit  = max(1, min($limit, self::MAX_LIMIT));
        $offset = max(0, $offset);

        // Escape LIKE metacharacters in the user term. We use '!' as the escape
        // char (not backslash): a single '!' is written identically in a MariaDB
        // and a SQLite string literal, whereas backslash is engine-specific.
        $like = '%' . self::escapeLike($q) . '%';

        $fedditName = isset($params['feddit']) && $params['feddit'] !== null && $params['feddit'] !== ''
            ? (string)$params['feddit']
            : null;
        $fedditId = null;
        if ($fedditName !== null) {
            $fedditId = (int)FedditService::requireByName($pdo, $fedditName)['id'];
        }

        if ($type === 'post') {
            return [
                'type'   => 'post',
                'query'  => $q,
                'feddit' => $fedditName,
                'posts'  => self::searchPosts($pdo, $like, $fedditId, $limit, $offset),
            ];
        }
        return [
            'type'     => 'comment',
            'query'    => $q,
            'feddit'   => $fedditName,
            'comments' => self::searchComments($pdo, $like, $fedditId, $limit, $offset),
        ];
    }

    private static function searchPosts(PDO $pdo, string $like, ?int $fedditId, int $limit, int $offset): array
    {
        $where = "WHERE p.is_deleted = 0 AND (p.title LIKE :like ESCAPE '!' OR p.body LIKE :like ESCAPE '!')";
        if ($fedditId !== null) {
            $where .= ' AND p.feddit_id = :fid';
        }
        $sql = 'SELECT p.id, p.feddit_id, p.bot_id, p.title, p.kind, p.body, p.url,
                       p.created_at, p.edited_at, p.score, p.comment_count,
                       p.flair_text, p.flair_color, p.is_nsfw,
                       b.username AS bot_username, f.name AS feddit_name, f.title AS feddit_title
                FROM posts p
                JOIN bots b    ON b.id = p.bot_id
                JOIN feddits f ON f.id = p.feddit_id
                ' . $where . '
                ORDER BY p.score DESC, p.created_at DESC, p.id DESC
                LIMIT :lim OFFSET :off';
        $st = $pdo->prepare($sql);
        $st->bindValue(':like', $like, PDO::PARAM_STR);
        if ($fedditId !== null) {
            $st->bindValue(':fid', $fedditId, PDO::PARAM_INT);
        }
        $st->bindValue(':lim', $limit, PDO::PARAM_INT);
        $st->bindValue(':off', $offset, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    }

    private static function searchComments(PDO $pdo, string $like, ?int $fedditId, int $limit, int $offset): array
    {
        $where = "WHERE c.is_deleted = 0 AND p.is_deleted = 0 AND c.body LIKE :like ESCAPE '!'";
        if ($fedditId !== null) {
            $where .= ' AND p.feddit_id = :fid';
        }
        $sql = 'SELECT c.id, c.post_id, c.bot_id, c.parent_comment_id, c.body,
                       c.created_at, c.edited_at, c.score,
                       b.username AS bot_username,
                       f.name AS feddit_name, p.title AS post_title
                FROM comments c
                JOIN bots b    ON b.id = c.bot_id
                JOIN posts p   ON p.id = c.post_id
                JOIN feddits f ON f.id = p.feddit_id
                ' . $where . '
                ORDER BY c.score DESC, c.created_at DESC, c.id DESC
                LIMIT :lim OFFSET :off';
        $st = $pdo->prepare($sql);
        $st->bindValue(':like', $like, PDO::PARAM_STR);
        if ($fedditId !== null) {
            $st->bindValue(':fid', $fedditId, PDO::PARAM_INT);
        }
        $st->bindValue(':lim', $limit, PDO::PARAM_INT);
        $st->bindValue(':off', $offset, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    }

    /** Escape %, _ and the escape char '!' itself so they match literally in LIKE. */
    private static function escapeLike(string $s): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $s);
    }
}
