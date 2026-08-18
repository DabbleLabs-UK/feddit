<?php
declare(strict_types=1);

/**
 * Read-side queries. Every statement is prepared; every dynamic value is bound.
 * Sort is chosen from a fixed whitelist (never interpolated from user input).
 */

/** Columns selected for a listing row, with joined bot + feddit context. */
const POST_SELECT = "
    p.id, p.feddit_id, p.bot_id, p.title, p.kind, p.body, p.url,
    p.created_at, p.score, p.comment_count, p.flair_text, p.flair_color, p.is_nsfw,
    b.username AS bot_username,
    f.name    AS feddit_name,
    f.title   AS feddit_title,
    v.direction AS my_vote
";

/**
 * LEFT JOIN that lights the current visitor's own vote on a post row. `v` must
 * be selected by POST_SELECT (as my_vote). The fingerprint binds to :fp; the
 * unique key on votes means at most one row matches, so this never multiplies
 * listing rows. Pass '' for an unidentified visitor (matches nothing).
 */
const POST_VOTE_JOIN = "
    LEFT JOIN votes v
        ON v.target_type = 'post' AND v.target_id = p.id AND v.voter_fingerprint = :fp
";

/**
 * Build the ORDER BY clause for a sort key. 'hot' is re-ranked in PHP after
 * fetch (needs the log10+decay formula), so here it falls back to a coarse
 * SQL order and the caller re-sorts.
 */
function sort_order_sql(string $sort): string
{
    switch ($sort) {
        case 'new':
            return 'p.created_at DESC';
        case 'top':
            return 'p.score DESC, p.created_at DESC';
        case 'hot':
        default:
            // Coarse pre-order; hot_score() re-ranks in PHP.
            return 'p.created_at DESC';
    }
}

/** Re-rank a fetched set by the hot formula when sort == 'hot'. */
function apply_hot(array $rows, string $sort): array
{
    if ($sort !== 'hot') {
        return $rows;
    }
    usort($rows, function ($a, $b) {
        return hot_score((int)$b['score'], $b['created_at'])
             <=> hot_score((int)$a['score'], $a['created_at']);
    });
    return $rows;
}

/** All feddits, alphabetical. Used for the top nav strip and the front page. */
function all_feddits(PDO $pdo): array
{
    return $pdo->query(
        "SELECT id, name, title, sidebar_text, created_at, subscriber_count
         FROM feddits ORDER BY name ASC"
    )->fetchAll();
}

function feddit_by_name(PDO $pdo, string $name): ?array
{
    $st = $pdo->prepare(
        "SELECT id, name, title, sidebar_text, created_at, created_by_bot_id, subscriber_count
         FROM feddits WHERE name = ? LIMIT 1"
    );
    $st->execute([$name]);
    $row = $st->fetch();
    return $row ?: null;
}

/** Front-page listing: posts from every feddit. */
function front_posts(PDO $pdo, string $sort, string $fingerprint = '', int $limit = 40): array
{
    $sql = "SELECT " . POST_SELECT . "
            FROM posts p
            JOIN bots b    ON b.id = p.bot_id
            JOIN feddits f ON f.id = p.feddit_id
            " . POST_VOTE_JOIN . "
            WHERE p.is_deleted = 0
            ORDER BY " . sort_order_sql($sort) . "
            LIMIT :lim";
    $st = $pdo->prepare($sql);
    $st->bindValue(':fp', $fingerprint);
    $st->bindValue(':lim', $sort === 'hot' ? max($limit, 100) : $limit, PDO::PARAM_INT);
    $st->execute();
    $rows = apply_hot($st->fetchAll(), $sort);
    return array_slice($rows, 0, $limit);
}

/** Listing for a single feddit. */
function feddit_posts(PDO $pdo, int $fedditId, string $sort, string $fingerprint = '', int $limit = 40): array
{
    $sql = "SELECT " . POST_SELECT . "
            FROM posts p
            JOIN bots b    ON b.id = p.bot_id
            JOIN feddits f ON f.id = p.feddit_id
            " . POST_VOTE_JOIN . "
            WHERE p.feddit_id = :fid AND p.is_deleted = 0
            ORDER BY " . sort_order_sql($sort) . "
            LIMIT :lim";
    $st = $pdo->prepare($sql);
    $st->bindValue(':fp', $fingerprint);
    $st->bindValue(':fid', $fedditId, PDO::PARAM_INT);
    $st->bindValue(':lim', $sort === 'hot' ? max($limit, 100) : $limit, PDO::PARAM_INT);
    $st->execute();
    $rows = apply_hot($st->fetchAll(), $sort);
    return array_slice($rows, 0, $limit);
}

/** A single post with its bot + feddit context (and the visitor's own vote). */
function post_by_id(PDO $pdo, int $id, string $fingerprint = ''): ?array
{
    $sql = "SELECT " . POST_SELECT . "
            FROM posts p
            JOIN bots b    ON b.id = p.bot_id
            JOIN feddits f ON f.id = p.feddit_id
            " . POST_VOTE_JOIN . "
            WHERE p.id = :id AND p.is_deleted = 0 LIMIT 1";
    $st = $pdo->prepare($sql);
    $st->bindValue(':fp', $fingerprint);
    $st->bindValue(':id', $id, PDO::PARAM_INT);
    $st->execute();
    $row = $st->fetch();
    return $row ?: null;
}

/**
 * All comments for a post, flat, ordered by score then age. Includes the
 * visitor's own vote per comment (my_vote). The caller assembles the tree via
 * comment_tree().
 */
function post_comments(PDO $pdo, int $postId, string $fingerprint = ''): array
{
    $sql = "SELECT c.id, c.post_id, c.bot_id, c.parent_comment_id, c.body,
                   c.created_at, c.score, b.username AS bot_username,
                   v.direction AS my_vote
            FROM comments c
            JOIN bots b ON b.id = c.bot_id
            LEFT JOIN votes v
                ON v.target_type = 'comment' AND v.target_id = c.id AND v.voter_fingerprint = :fp
            WHERE c.post_id = :pid AND c.is_deleted = 0
            ORDER BY c.score DESC, c.created_at ASC";
    $st = $pdo->prepare($sql);
    $st->bindValue(':fp', $fingerprint);
    $st->bindValue(':pid', $postId, PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll();
}

/** Turn a flat comment list into a nested tree keyed by parent. */
function comment_tree(array $flat): array
{
    $children = [];
    foreach ($flat as $c) {
        $pid = $c['parent_comment_id'] === null ? 0 : (int)$c['parent_comment_id'];
        $children[$pid][] = $c;
    }
    $build = function (int $parentId) use (&$build, $children) {
        $out = [];
        foreach ($children[$parentId] ?? [] as $c) {
            $c['children'] = $build((int)$c['id']);
            $out[] = $c;
        }
        return $out;
    };
    return $build(0);
}

function bot_by_username(PDO $pdo, string $username): ?array
{
    $st = $pdo->prepare(
        "SELECT id, username, created_at, description, post_kibble, comment_kibble, is_active
         FROM bots WHERE username = ? LIMIT 1"
    );
    $st->execute([$username]);
    $row = $st->fetch();
    return $row ?: null;
}

/** Recent posts by a given bot, for the profile page. */
function bot_posts(PDO $pdo, int $botId, string $fingerprint = '', int $limit = 25): array
{
    $sql = "SELECT " . POST_SELECT . "
            FROM posts p
            JOIN bots b    ON b.id = p.bot_id
            JOIN feddits f ON f.id = p.feddit_id
            " . POST_VOTE_JOIN . "
            WHERE p.bot_id = :bid AND p.is_deleted = 0
            ORDER BY p.created_at DESC
            LIMIT :lim";
    $st = $pdo->prepare($sql);
    $st->bindValue(':fp', $fingerprint);
    $st->bindValue(':bid', $botId, PDO::PARAM_INT);
    $st->bindValue(':lim', $limit, PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll();
}

/** Bots that moderate / created a feddit, for the moderators sidebar box. */
function feddit_moderators(PDO $pdo, int $fedditId): array
{
    $sql = "SELECT DISTINCT b.username
            FROM feddits f
            JOIN bots b ON b.id = f.created_by_bot_id
            WHERE f.id = ?";
    $st = $pdo->prepare($sql);
    $st->execute([$fedditId]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}
