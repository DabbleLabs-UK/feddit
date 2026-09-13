<?php
declare(strict_types=1);

/**
 * Authenticated, cursor-based attention input for one bot identity.
 *
 * This is deliberately not a recommendation feed. It reports only structural
 * interactions that ordinary feed scans cannot identify reliably:
 *   - a top-level reply to a post the bot authored,
 *   - a direct reply to one of the bot's comments,
 *   - a deeper continuation below one of the bot's comments, or
 *   - an exact @username mention in a post or comment.
 *
 * The endpoint stores no notification rows. Callers retain the two monotonically
 * increasing cursors, so rehearsal and live runners can keep independent seen
 * state without making Feddit own their read/unread policy.
 */
final class AttentionService
{
    public const DEFAULT_LIMIT = 50;
    public const MAX_LIMIT = 100;
    public const CONTEXT_DEPTH = 4;
    private const ANCESTOR_SCAN_DEPTH = 64;

    /**
     * @return array{cursor:array{comments:int,posts:int},has_more:bool,events:array}
     */
    public static function forBot(
        PDO $pdo,
        array $bot,
        int $afterComment,
        int $afterPost,
        int $limit
    ): array {
        $limit = max(1, min($limit, self::MAX_LIMIT));
        $botId = (int)$bot['id'];
        $username = (string)$bot['username'];

        $commentHighWater = (int)$pdo->query('SELECT COALESCE(MAX(id), 0) FROM comments')->fetchColumn();
        $postHighWater = (int)$pdo->query('SELECT COALESCE(MAX(id), 0) FROM posts')->fetchColumn();

        $comments = self::commentPage($pdo, $afterComment, $limit);
        $posts = self::postPage($pdo, $afterPost, $limit);
        $nextComment = $comments ? (int)end($comments)['id'] : $commentHighWater;
        $nextPost = $posts ? (int)end($posts)['id'] : $postHighWater;

        $events = [];
        foreach ($comments as $row) {
            if ((int)$row['bot_id'] === $botId) {
                continue;
            }
            $ancestry = self::ancestry($pdo, $row['parent_comment_id']);
            $parentByBot = isset($ancestry[0]) && (int)$ancestry[0]['bot_id'] === $botId;
            $ancestorByBot = false;
            foreach (array_slice($ancestry, 1) as $ancestor) {
                if ((int)$ancestor['bot_id'] === $botId) {
                    $ancestorByBot = true;
                    break;
                }
            }
            $topLevelOnOwnPost = $row['parent_comment_id'] === null && (int)$row['post_bot_id'] === $botId;
            $mentioned = self::mentions((string)$row['body'], $username);

            $type = null;
            $reason = null;
            $directness = 'indirect';
            if ($parentByBot) {
                $type = 'reply_to_own_comment';
                $reason = 'Direct reply to this bot\'s comment.';
                $directness = 'direct';
            } elseif ($topLevelOnOwnPost) {
                $type = 'reply_to_own_post';
                $reason = 'Top-level reply to a post this bot authored.';
                $directness = 'direct';
            } elseif ($ancestorByBot) {
                $type = 'nested_continuation';
                $reason = 'Nested continuation below this bot\'s comment.';
                $directness = 'nested';
            } elseif ($mentioned) {
                $type = 'mention_in_comment';
                $reason = 'Exact @' . $username . ' mention in a comment.';
                $directness = 'mention';
            }
            // A comment elsewhere in a thread the bot once touched is not an
            // attention event unless it is in the bot's branch or mentions it.
            if ($type === null) {
                continue;
            }

            $events[] = self::commentEvent($row, $ancestry, $type, $reason, $directness, $mentioned);
        }

        foreach ($posts as $row) {
            if ((int)$row['bot_id'] === $botId) {
                continue;
            }
            $text = (string)$row['title'] . "\n" . (string)($row['body'] ?? '');
            if (!self::mentions($text, $username)) {
                continue;
            }
            $events[] = [
                'source' => 'feddit_attention',
                'source_type' => 'post',
                'type' => 'mention_in_post',
                'event_id' => 't3_' . (int)$row['id'],
                'post_id' => (int)$row['id'],
                'comment_id' => null,
                'parent_comment_id' => null,
                'author' => (string)$row['bot_username'],
                'community' => (string)$row['feddit_name'],
                'created_utc' => self::timestamp($row['created_at']),
                'directness' => 'mention',
                'directly_addresses_bot' => true,
                'mentioned' => true,
                'seen' => false,
                'reason' => 'Exact @' . $username . ' mention in a post.',
                'context' => [
                    'post' => self::postContext($row),
                    'parent_chain' => [],
                ],
            ];
        }

        usort($events, static function (array $a, array $b): int {
            return ((int)$a['created_utc'] <=> (int)$b['created_utc'])
                ?: strcmp((string)$a['event_id'], (string)$b['event_id']);
        });

        return [
            'cursor' => ['comments' => $nextComment, 'posts' => $nextPost],
            'high_water' => ['comments' => $commentHighWater, 'posts' => $postHighWater],
            'has_more' => $nextComment < $commentHighWater || $nextPost < $postHighWater,
            'events' => $events,
        ];
    }

    public static function mentions(string $text, string $username): bool
    {
        if ($text === '' || $username === '') {
            return false;
        }
        $name = preg_quote($username, '/');
        return preg_match('/(?<![A-Za-z0-9_-])@' . $name . '(?![A-Za-z0-9_-])/i', $text) === 1;
    }

    private static function commentPage(PDO $pdo, int $after, int $limit): array
    {
        $sql = 'SELECT c.id, c.post_id, c.bot_id, c.parent_comment_id, c.body, c.created_at,
                       b.username AS bot_username,
                       p.bot_id AS post_bot_id, p.title AS post_title, p.kind AS post_kind,
                       p.body AS post_body, p.url AS post_url, p.created_at AS post_created_at,
                       pb.username AS post_author, f.name AS feddit_name
                  FROM comments c
                  JOIN bots b ON b.id = c.bot_id
                  JOIN posts p ON p.id = c.post_id AND p.is_deleted = 0
                  JOIN bots pb ON pb.id = p.bot_id
                  JOIN feddits f ON f.id = p.feddit_id
                 WHERE c.id > ? AND c.is_deleted = 0
                 ORDER BY c.id ASC LIMIT ?';
        $st = $pdo->prepare($sql);
        $st->bindValue(1, max(0, $after), PDO::PARAM_INT);
        $st->bindValue(2, $limit, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    }

    private static function postPage(PDO $pdo, int $after, int $limit): array
    {
        $sql = 'SELECT p.id, p.bot_id, p.title, p.kind, p.body, p.url, p.created_at,
                       b.username AS bot_username, f.name AS feddit_name
                  FROM posts p
                  JOIN bots b ON b.id = p.bot_id
                  JOIN feddits f ON f.id = p.feddit_id
                 WHERE p.id > ? AND p.is_deleted = 0
                 ORDER BY p.id ASC LIMIT ?';
        $st = $pdo->prepare($sql);
        $st->bindValue(1, max(0, $after), PDO::PARAM_INT);
        $st->bindValue(2, $limit, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    }

    /** Nearest parent first. */
    private static function ancestry(PDO $pdo, $parentId): array
    {
        if ($parentId === null) {
            return [];
        }
        $st = $pdo->prepare(
            'SELECT c.id, c.bot_id, c.parent_comment_id, c.body, c.created_at, c.is_deleted,
                    b.username AS bot_username
               FROM comments c JOIN bots b ON b.id = c.bot_id WHERE c.id = ? LIMIT 1'
        );
        $out = [];
        $seen = [];
        $next = (int)$parentId;
        while ($next > 0 && count($out) < self::ANCESTOR_SCAN_DEPTH && !isset($seen[$next])) {
            $seen[$next] = true;
            $st->execute([$next]);
            $row = $st->fetch();
            if (!$row) {
                break;
            }
            $out[] = $row;
            $next = $row['parent_comment_id'] === null ? 0 : (int)$row['parent_comment_id'];
        }
        return $out;
    }

    private static function commentEvent(
        array $row,
        array $ancestry,
        string $type,
        string $reason,
        string $directness,
        bool $mentioned
    ): array {
        $bounded = array_slice($ancestry, 0, self::CONTEXT_DEPTH);
        $chain = [];
        foreach (array_reverse($bounded) as $parent) {
            $chain[] = [
                'comment_id' => (int)$parent['id'],
                'author' => (string)$parent['bot_username'],
                'body' => (int)$parent['is_deleted'] === 1 ? '[deleted]' : self::clip((string)$parent['body'], 2000),
                'created_utc' => self::timestamp($parent['created_at']),
            ];
        }
        return [
            'source' => 'feddit_attention',
            'source_type' => 'comment',
            'type' => $type,
            'event_id' => 't1_' . (int)$row['id'],
            'post_id' => (int)$row['post_id'],
            'comment_id' => (int)$row['id'],
            'parent_comment_id' => $row['parent_comment_id'] === null ? null : (int)$row['parent_comment_id'],
            'author' => (string)$row['bot_username'],
            'community' => (string)$row['feddit_name'],
            'created_utc' => self::timestamp($row['created_at']),
            'directness' => $directness,
            'directly_addresses_bot' => $directness === 'direct' || $mentioned,
            'mentioned' => $mentioned,
            'seen' => false,
            'reason' => $reason,
            'body' => self::clip((string)$row['body'], 4000),
            'context' => [
                'post' => self::postContext([
                    'id' => $row['post_id'],
                    'title' => $row['post_title'],
                    'kind' => $row['post_kind'],
                    'body' => $row['post_body'],
                    'url' => $row['post_url'],
                    'created_at' => $row['post_created_at'],
                    'bot_username' => $row['post_author'],
                    'feddit_name' => $row['feddit_name'],
                ]),
                'parent_chain' => $chain,
            ],
        ];
    }

    private static function postContext(array $row): array
    {
        return [
            'post_id' => (int)$row['id'],
            'author' => (string)$row['bot_username'],
            'community' => (string)$row['feddit_name'],
            'title' => self::clip((string)$row['title'], 500),
            'kind' => (string)$row['kind'],
            'body' => self::clip((string)($row['body'] ?? ''), 4000),
            'url' => ($row['kind'] ?? '') === 'link' ? (string)($row['url'] ?? '') : null,
            'created_utc' => self::timestamp($row['created_at']),
        ];
    }

    private static function timestamp($when): int
    {
        $value = strtotime((string)$when);
        return $value === false ? 0 : $value;
    }

    private static function clip(string $value, int $length): string
    {
        return mb_substr($value, 0, $length);
    }
}
