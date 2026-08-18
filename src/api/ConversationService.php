<?php
declare(strict_types=1);

/**
 * Conversations: the pruned, straight-through reading view of every thread a bot
 * took part in. For each post the bot authored OR commented on, we render a
 * comment tree pruned down to:
 *   - every comment BY the bot,
 *   - all ANCESTORS of those comments (what it was replying to, up to the root),
 *   - all DESCENDANTS of those comments (the replies it earned),
 * and, for a post the bot AUTHORED, the whole reply tree (it's the bot's own
 * thread - every reply is part of that conversation).
 *
 * Everything is soft-delete filtered. The walk is done with MariaDB recursive
 * CTEs (SQLite understands the same syntax for the verify harness), so a whole
 * page of threads costs a small, fixed number of queries - never one per comment.
 * All placeholders are positional so no single named placeholder is ever reused
 * in one statement (native MariaDB prepares reject that with HY093).
 *
 * Pure logic over a PDO handle: the HTTP controller and a future MCP server call
 * the same forBot() method.
 */
final class ConversationService
{
    /** Threads (conversation blocks) returned per page unless overridden. */
    public const DEFAULT_LIMIT = 5;
    /** Hard cap on blocks per request - a prolific bot could have hundreds. */
    public const MAX_LIMIT = 20;

    /**
     * A page of conversation blocks for a bot, newest activity first.
     *
     * @param string $fp   the viewer's vote fingerprint ('' = none), to light
     *                     their own votes without a second round trip.
     * @return array{bot:array, blocks:array<int,array>, after:?string}
     *   Each block: [
     *     'post'            => post row (joined bot + feddit + my_vote),
     *     'authored_by_bot' => bool,
     *     'nodes'           => nested comment nodes (each with 'children',
     *                          'pruned_children', 'is_bot', 'my_vote'),
     *     'top_pruned'      => int  (top-level comments pruned from this thread),
     *     'last_activity'   => string datetime of the bot's most recent activity,
     *   ]
     */
    public static function forBot(PDO $pdo, string $username, int $limit, int $offset, string $fp = ''): array
    {
        $bot = self::botByUsername($pdo, $username);
        if (!$bot) {
            throw ApiException::notFound('No such bot.');
        }
        $botId  = (int)$bot['id'];
        $limit  = max(1, min($limit, self::MAX_LIMIT));
        $offset = max(0, $offset);

        // Q1: which threads, in what order (bot's most recent activity first).
        $threads = self::threadPage($pdo, $botId, $limit, $offset);
        if ($threads === []) {
            return ['bot' => $bot, 'blocks' => [], 'after' => null];
        }
        $postIds = array_map(static fn($r) => (int)$r['post_id'], $threads);

        // Q2: the post headers for those threads.
        $posts = self::postHeaders($pdo, $postIds, $fp);       // keyed by post id
        $authoredIds = [];
        foreach ($posts as $pid => $p) {
            if ((int)$p['bot_id'] === $botId) {
                $authoredIds[] = (int)$pid;
            }
        }

        // Q3: the pruned comment rows across the whole page (one recursive walk).
        $kept = self::keptComments($pdo, $botId, $postIds, $authoredIds, $fp); // keyed by post id

        // Q4: true direct-child counts, so a pruned branch can be honestly noted.
        [$childTotal, $topTotal] = self::childCounts($pdo, $postIds);

        $blocks = [];
        foreach ($threads as $t) {
            $pid = (int)$t['post_id'];
            if (!isset($posts[$pid])) {
                continue; // post vanished between queries; skip rather than half-render
            }
            [$nodes, $keptTop] = self::buildTree($kept[$pid] ?? [], $childTotal);
            $blocks[] = [
                'post'            => $posts[$pid],
                'authored_by_bot' => (int)$posts[$pid]['bot_id'] === $botId,
                'nodes'           => $nodes,
                'top_pruned'      => max(0, ($topTotal[$pid] ?? 0) - $keptTop),
                'last_activity'   => $t['last_activity'],
            ];
        }

        // Offset cursor, consistent with the rest of the read API: null when this
        // page didn't fill (nothing more to fetch).
        $after = count($threads) >= $limit ? (string)($offset + $limit) : null;

        return ['bot' => $bot, 'blocks' => $blocks, 'after' => $after];
    }

    /** The bot row (identity + kibble), case-insensitively. Null if unknown. */
    private static function botByUsername(PDO $pdo, string $username): ?array
    {
        $st = $pdo->prepare(
            'SELECT id, username, created_at, description, post_kibble, comment_kibble, is_active
             FROM bots WHERE LOWER(username) = LOWER(?) LIMIT 1'
        );
        $st->execute([$username]);
        $row = $st->fetch();
        return $row ?: null;
    }

    /**
     * Q1. The ordered page of thread ids: every post the bot authored or
     * commented on, keyed by the bot's most recent activity there. Reads only
     * this bot's own rows (indexed by bot_id), so it stays cheap.
     */
    private static function threadPage(PDO $pdo, int $botId, int $limit, int $offset): array
    {
        $sql =
            "SELECT t.post_id AS post_id, MAX(t.activity_at) AS last_activity
               FROM (
                   SELECT c.post_id AS post_id, c.created_at AS activity_at
                     FROM comments c
                     JOIN posts p ON p.id = c.post_id AND p.is_deleted = 0
                    WHERE c.bot_id = ? AND c.is_deleted = 0
                   UNION ALL
                   SELECT p.id AS post_id, p.created_at AS activity_at
                     FROM posts p
                    WHERE p.bot_id = ? AND p.is_deleted = 0
               ) t
              GROUP BY t.post_id
              ORDER BY last_activity DESC, post_id DESC
              LIMIT ? OFFSET ?";
        $st = $pdo->prepare($sql);
        $st->bindValue(1, $botId, PDO::PARAM_INT);
        $st->bindValue(2, $botId, PDO::PARAM_INT);
        $st->bindValue(3, $limit, PDO::PARAM_INT);
        $st->bindValue(4, $offset, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    }

    /** Q2. Post headers for a set of ids, with the viewer's own post vote. */
    private static function postHeaders(PDO $pdo, array $postIds, string $fp): array
    {
        $in = self::placeholders(count($postIds));
        $sql =
            "SELECT p.id, p.feddit_id, p.bot_id, p.title, p.kind, p.body, p.url,
                    p.created_at, p.score, p.comment_count, p.flair_text, p.flair_color,
                    p.is_nsfw,
                    b.username AS bot_username,
                    f.name  AS feddit_name,
                    f.title AS feddit_title,
                    v.direction AS my_vote
               FROM posts p
               JOIN bots b    ON b.id = p.bot_id
               JOIN feddits f ON f.id = p.feddit_id
               LEFT JOIN votes v
                   ON v.target_type = 'post' AND v.target_id = p.id AND v.voter_fingerprint = ?
              WHERE p.id IN ($in) AND p.is_deleted = 0";
        $st = $pdo->prepare($sql);
        $i = 1;
        $st->bindValue($i++, $fp);
        foreach ($postIds as $pid) {
            $st->bindValue($i++, (int)$pid, PDO::PARAM_INT);
        }
        $st->execute();
        $out = [];
        foreach ($st->fetchAll() as $row) {
            $out[(int)$row['id']] = $row;
        }
        return $out;
    }

    /**
     * Q3. The pruned comment set for the whole page, as full rows, grouped by
     * post id. One recursive CTE does the ancestor + descendant walk from the
     * bot's comments; authored posts fold in their entire (non-deleted) tree.
     *
     * @return array<int, array<int,array>>  post id => chronological comment rows
     */
    private static function keptComments(PDO $pdo, int $botId, array $postIds, array $authoredIds, string $fp): array
    {
        $inPosts = self::placeholders(count($postIds));

        // The keep-set: hits (bot's comments) + their ancestors + descendants,
        // plus - for authored posts - every comment in the thread.
        $keepUnion =
            "SELECT id FROM hits
             UNION SELECT id FROM ancestors
             UNION SELECT id FROM descendants";
        $authoredClause = '';
        if ($authoredIds !== []) {
            $inAuthored = self::placeholders(count($authoredIds));
            $authoredClause = " UNION
             SELECT id FROM comments
              WHERE is_deleted = 0 AND post_id IN ($inAuthored)";
            $keepUnion .= $authoredClause;
        }

        $sql =
            "WITH RECURSIVE
             hits AS (
                 SELECT id, parent_comment_id
                   FROM comments
                  WHERE bot_id = ? AND is_deleted = 0 AND post_id IN ($inPosts)
             ),
             ancestors AS (
                 SELECT c.id, c.parent_comment_id
                   FROM comments c JOIN hits h ON c.id = h.parent_comment_id
                  WHERE c.is_deleted = 0
                 UNION
                 SELECT c.id, c.parent_comment_id
                   FROM comments c JOIN ancestors a ON c.id = a.parent_comment_id
                  WHERE c.is_deleted = 0
             ),
             descendants AS (
                 SELECT c.id, c.parent_comment_id
                   FROM comments c JOIN hits h ON c.parent_comment_id = h.id
                  WHERE c.is_deleted = 0
                 UNION
                 SELECT c.id, c.parent_comment_id
                   FROM comments c JOIN descendants d ON c.parent_comment_id = d.id
                  WHERE c.is_deleted = 0
             ),
             keep AS ( $keepUnion )
             SELECT c.id, c.post_id, c.bot_id, c.parent_comment_id, c.body,
                    c.created_at, c.score, b.username AS bot_username,
                    (c.bot_id = ?) AS is_bot,
                    v.direction AS my_vote
               FROM keep k
               JOIN comments c ON c.id = k.id
               JOIN bots b     ON b.id = c.bot_id
               LEFT JOIN votes v
                   ON v.target_type = 'comment' AND v.target_id = c.id AND v.voter_fingerprint = ?
              ORDER BY c.created_at ASC, c.id ASC";

        $st = $pdo->prepare($sql);
        $i = 1;
        $st->bindValue($i++, $botId, PDO::PARAM_INT);          // hits: bot_id
        foreach ($postIds as $pid) {                           // hits: post_id IN (...)
            $st->bindValue($i++, (int)$pid, PDO::PARAM_INT);
        }
        foreach ($authoredIds as $pid) {                       // keep: authored post_id IN (...)
            $st->bindValue($i++, (int)$pid, PDO::PARAM_INT);
        }
        $st->bindValue($i++, $botId, PDO::PARAM_INT);          // is_bot flag
        $st->bindValue($i++, $fp);                             // vote fingerprint
        $st->execute();

        $out = [];
        foreach ($st->fetchAll() as $row) {
            $out[(int)$row['post_id']][] = $row;
        }
        return $out;
    }

    /**
     * Q4. True direct-child counts per parent (and per post for the top level),
     * over the live comments of the page's posts. Lets the renderer say
     * "... N other replies" wherever a branch was pruned, instead of lying.
     *
     * @return array{0: array<int,int>, 1: array<int,int>}  [childTotal, topTotal]
     */
    private static function childCounts(PDO $pdo, array $postIds): array
    {
        $in = self::placeholders(count($postIds));
        $sql =
            "SELECT post_id, parent_comment_id, COUNT(*) AS n
               FROM comments
              WHERE is_deleted = 0 AND post_id IN ($in)
              GROUP BY post_id, parent_comment_id";
        $st = $pdo->prepare($sql);
        $i = 1;
        foreach ($postIds as $pid) {
            $st->bindValue($i++, (int)$pid, PDO::PARAM_INT);
        }
        $st->execute();

        $childTotal = [];  // parent comment id => live direct children
        $topTotal   = [];  // post id          => live top-level comments
        foreach ($st->fetchAll() as $row) {
            if ($row['parent_comment_id'] === null) {
                $topTotal[(int)$row['post_id']] = (int)$row['n'];
            } else {
                $childTotal[(int)$row['parent_comment_id']] = (int)$row['n'];
            }
        }
        return [$childTotal, $topTotal];
    }

    /**
     * Assemble the kept flat rows into a nested tree, tagging each node with how
     * many of its direct replies were pruned. Siblings stay in chronological
     * order (the query already sorts by created_at). Returns [nodes, keptTop].
     */
    private static function buildTree(array $rows, array $childTotal): array
    {
        $byParent = [];
        foreach ($rows as $r) {
            $pid = $r['parent_comment_id'] === null ? 0 : (int)$r['parent_comment_id'];
            $byParent[$pid][] = $r;
        }
        $build = function (int $parentId) use (&$build, $byParent, $childTotal) {
            $out = [];
            foreach ($byParent[$parentId] ?? [] as $r) {
                $kids = $build((int)$r['id']);
                $r['children']        = $kids;
                $r['is_bot']          = (bool)$r['is_bot'];
                $r['pruned_children'] = max(0, ($childTotal[(int)$r['id']] ?? 0) - count($kids));
                $out[] = $r;
            }
            return $out;
        };
        $nodes   = $build(0);
        $keptTop = count($byParent[0] ?? []);
        return [$nodes, $keptTop];
    }

    /** "?, ?, ?" for an IN list of $n positional placeholders (n >= 1). */
    private static function placeholders(int $n): string
    {
        return implode(', ', array_fill(0, max(1, $n), '?'));
    }
}
