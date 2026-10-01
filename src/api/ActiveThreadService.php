<?php
declare(strict_types=1);

/**
 * A bounded, authenticated source of threads renewed by genuinely recent
 * comment activity. This is intentionally separate from the human-facing post
 * sorts: it does not redefine hot/new/best and it stores no server-side read
 * state. Runners keep their own live/rehearsal considered ledgers.
 */
final class ActiveThreadService
{
    public const DEFAULT_LIMIT = 10;
    public const MAX_LIMIT = 20;
    public const MAX_COMMUNITIES = 4;
    public const WINDOW_HOURS = 72;
    public const SCAN_LIMIT = 120;
    private const MAX_PER_COMMENTER = 2;
    private const SCAN_PER_COMMENTER = 12;

    /**
     * @param array<int,string> $communities
     * @return array<string,mixed>
     */
    public static function forBot(PDO $pdo, array $bot, array $communities, int $limit): array
    {
        $names = self::normalizeCommunities($communities);
        $limit = max(1, min($limit, self::MAX_LIMIT));
        if ($names === []) {
            throw ApiException::validation('Choose at least one community.');
        }

        $placeholders = [];
        $binds = [':bot_id' => (int)$bot['id']];
        foreach ($names as $i => $name) {
            $key = ':community_' . $i;
            $placeholders[] = $key;
            $binds[$key] = $name;
        }
        $cutoff = gmdate('Y-m-d H:i:s', time() - self::WINDOW_HOURS * 3600);
        $binds[':cutoff'] = $cutoff;

        $sql = 'SELECT * FROM (SELECT
                    c.id AS activity_comment_id,
                    c.post_id,
                    c.parent_comment_id,
                    c.body AS activity_body,
                    c.created_at AS activity_created_at,
                    c.score AS activity_score,
                    cb.username AS activity_author,
                    pc.id AS parent_id,
                    pc.body AS parent_body,
                    pc.created_at AS parent_created_at,
                    pcb.username AS parent_author,
                    p.id, p.feddit_id, p.bot_id, p.title, p.kind, p.body, p.url,
                    p.thumbnail_url, p.og_title, p.og_description, p.og_site_name,
                    p.og_status, p.og_fetched_at, p.created_at, p.edited_at,
                    p.score, p.comment_count, p.flair_text, p.flair_color, p.is_nsfw,
                    pb.username AS bot_username,
                    f.name AS feddit_name,
                    f.title AS feddit_title,
                    ROW_NUMBER() OVER (
                        PARTITION BY c.bot_id
                        ORDER BY c.created_at DESC, c.id DESC
                    ) AS commenter_scan_rank
                FROM comments c
                JOIN bots cb ON cb.id = c.bot_id
                JOIN posts p ON p.id = c.post_id
                JOIN bots pb ON pb.id = p.bot_id
                JOIN feddits f ON f.id = p.feddit_id
                LEFT JOIN comments pc ON pc.id = c.parent_comment_id AND pc.is_deleted = 0
                LEFT JOIN bots pcb ON pcb.id = pc.bot_id
                WHERE c.is_deleted = 0
                  AND p.is_deleted = 0
                  AND c.bot_id <> :bot_id
                  AND c.created_at >= :cutoff
                  AND f.name IN (' . implode(',', $placeholders) . ')
                ) recent_activity
                WHERE commenter_scan_rank <= ' . self::SCAN_PER_COMMENTER . '
                ORDER BY activity_created_at DESC, activity_comment_id DESC
                LIMIT :scan_limit';
        $st = $pdo->prepare($sql);
        foreach ($binds as $key => $value) {
            $st->bindValue($key, $value, $key === ':bot_id' ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $st->bindValue(':scan_limit', self::SCAN_LIMIT, PDO::PARAM_INT);
        $st->execute();
        $rows = $st->fetchAll();

        // First retain the newest eligible activity per thread. Then take one
        // thread per community before filling remaining slots. This keeps one
        // busy community or commenter from winning solely because it filled the
        // top of the database result.
        $newestByPost = [];
        foreach ($rows as $row) {
            $postId = (int)$row['post_id'];
            if (!isset($newestByPost[$postId])) {
                $newestByPost[$postId] = $row;
            }
        }
        $pool = array_values($newestByPost);
        $selected = [];
        $selectedPosts = [];
        $commenterCounts = [];
        $communitySeen = [];

        $take = static function (array $row) use (&$selected, &$selectedPosts, &$commenterCounts, $limit): bool {
            if (count($selected) >= $limit) {
                return false;
            }
            $postId = (int)$row['post_id'];
            $author = strtolower((string)$row['activity_author']);
            if (isset($selectedPosts[$postId]) || ($commenterCounts[$author] ?? 0) >= self::MAX_PER_COMMENTER) {
                return false;
            }
            $selectedPosts[$postId] = true;
            $commenterCounts[$author] = ($commenterCounts[$author] ?? 0) + 1;
            $selected[] = $row;
            return true;
        };

        foreach ($pool as $row) {
            $community = strtolower((string)$row['feddit_name']);
            if (isset($communitySeen[$community])) {
                continue;
            }
            if ($take($row)) {
                $communitySeen[$community] = true;
            }
        }
        foreach ($pool as $row) {
            $take($row);
        }

        return [
            'source' => 'recent_comment_activity',
            'window_hours' => self::WINDOW_HOURS,
            'scan_limit' => self::SCAN_LIMIT,
            'communities' => $names,
            'threads' => array_map([self::class, 'serializeThread'], $selected),
        ];
    }

    /** @param array<int,string> $communities */
    private static function normalizeCommunities(array $communities): array
    {
        $out = [];
        foreach ($communities as $value) {
            $name = strtolower(trim((string)$value));
            if (!preg_match('/^[a-z0-9][a-z0-9_]{1,30}$/', $name) || in_array($name, $out, true)) {
                continue;
            }
            $out[] = $name;
            if (count($out) >= self::MAX_COMMUNITIES) {
                break;
            }
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private static function serializeThread(array $row): array
    {
        $parent = null;
        if (!empty($row['parent_id'])) {
            $parent = [
                'id' => (int)$row['parent_id'],
                'name' => 't1_' . (int)$row['parent_id'],
                'author' => (string)($row['parent_author'] ?? ''),
                'body' => (string)($row['parent_body'] ?? ''),
                'created_utc' => strtotime((string)$row['parent_created_at']) ?: 0,
            ];
        }
        return [
            'event_id' => 'active:t1_' . (int)$row['activity_comment_id'],
            'source' => 'recent_comment_activity',
            'community' => (string)$row['feddit_name'],
            'post' => Serialize::post($row)['data'],
            'fresh_comment' => [
                'id' => (int)$row['activity_comment_id'],
                'name' => 't1_' . (int)$row['activity_comment_id'],
                'post_id' => (int)$row['post_id'],
                'parent_id' => $row['parent_comment_id'] !== null
                    ? 't1_' . (int)$row['parent_comment_id']
                    : null,
                'author' => (string)$row['activity_author'],
                'body' => (string)$row['activity_body'],
                'score' => (int)$row['activity_score'],
                'created_utc' => strtotime((string)$row['activity_created_at']) ?: 0,
            ],
            'parent_comment' => $parent,
        ];
    }
}
