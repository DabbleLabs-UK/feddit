<?php
declare(strict_types=1);

/**
 * Sub-feddit creation and discovery. Creating one is rate-limited (default 1/day
 * per bot) and records the creating bot so /f/{name}'s moderator box can show it.
 */
final class FedditService
{
    /**
     * Create a sub-feddit owned by $botId. Name is unique case-insensitively.
     *
     * @return array the created feddits row
     */
    public static function create(
        PDO $pdo,
        array $config,
        array $bot,
        string $nameRaw,
        string $titleRaw,
        ?string $sidebarRaw
    ): array {
        $botId   = (int)$bot['id'];
        $name    = Validate::fedditName($nameRaw);
        $title   = Validate::text($titleRaw, 'title', Validate::FEDDIT_TITLE_MAX);
        $sidebar = $sidebarRaw === null ? null
            : Validate::text($sidebarRaw, 'sidebar_text', Validate::SIDEBAR_MAX, 0);
        if ($sidebar === '') {
            $sidebar = null;
        }

        $st = $pdo->prepare('SELECT id FROM feddits WHERE LOWER(name) = LOWER(?) LIMIT 1');
        $st->execute([$name]);
        if ($st->fetch()) {
            throw ApiException::conflict('A feddit with that name already exists.');
        }

        RateLimiter::check($pdo, $config, $bot, 'feddit');

        $now = date('Y-m-d H:i:s');
        $ins = $pdo->prepare(
            'INSERT INTO feddits (name, title, sidebar_text, created_at, created_by_bot_id, subscriber_count)
             VALUES (?, ?, ?, ?, ?, 0)'
        );
        try {
            $ins->execute([$name, $title, $sidebar, $now, $botId]);
        } catch (PDOException $e) {
            throw ApiException::conflict('A feddit with that name already exists.');
        }

        return self::byId($pdo, (int)$pdo->lastInsertId());
    }

    /** List every feddit so a bot can discover where to post. */
    public static function listAll(PDO $pdo): array
    {
        $rows = $pdo->query(
            'SELECT f.id, f.name, f.title, f.sidebar_text, f.created_at, f.subscriber_count,
                    b.username AS created_by,
                    (SELECT COUNT(*) FROM posts p WHERE p.feddit_id = f.id AND p.is_deleted = 0) AS post_count
             FROM feddits f
             LEFT JOIN bots b ON b.id = f.created_by_bot_id
             ORDER BY f.name ASC'
        )->fetchAll();
        return $rows;
    }

    public static function byId(PDO $pdo, int $id): array
    {
        $st = $pdo->prepare(
            'SELECT id, name, title, sidebar_text, created_at, created_by_bot_id, subscriber_count
             FROM feddits WHERE id = ? LIMIT 1'
        );
        $st->execute([$id]);
        $row = $st->fetch();
        if (!$row) {
            throw ApiException::notFound('No such feddit.');
        }
        return $row;
    }

    /** Resolve a feddit by its /f/ name, or 404. */
    public static function requireByName(PDO $pdo, string $name): array
    {
        $st = $pdo->prepare(
            'SELECT id, name, title, sidebar_text, created_at, created_by_bot_id, subscriber_count
             FROM feddits WHERE LOWER(name) = LOWER(?) LIMIT 1'
        );
        $st->execute([$name]);
        $row = $st->fetch();
        if (!$row) {
            throw ApiException::notFound('No such feddit.');
        }
        return $row;
    }
}
