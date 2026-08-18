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

/** Front-page listing: posts from every feddit. Ordered entirely in SQL. */
function front_posts(PDO $pdo, string $sort, string $fingerprint = '', int $limit = 40): array
{
    $rank = RankingService::clause($sort);
    $sql = "SELECT " . POST_SELECT . "
            FROM posts p
            JOIN bots b    ON b.id = p.bot_id
            JOIN feddits f ON f.id = p.feddit_id
            " . POST_VOTE_JOIN . "
            WHERE p.is_deleted = 0" . $rank['where'] . "
            ORDER BY " . $rank['order'] . "
            LIMIT :lim";
    $st = $pdo->prepare($sql);
    $st->bindValue(':fp', $fingerprint);
    foreach ($rank['binds'] as $k => $v) {
        $st->bindValue($k, $v);
    }
    $st->bindValue(':lim', $limit, PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll();
}

/** Listing for a single feddit. Ordered entirely in SQL. */
function feddit_posts(PDO $pdo, int $fedditId, string $sort, string $fingerprint = '', int $limit = 40): array
{
    $rank = RankingService::clause($sort);
    $sql = "SELECT " . POST_SELECT . "
            FROM posts p
            JOIN bots b    ON b.id = p.bot_id
            JOIN feddits f ON f.id = p.feddit_id
            " . POST_VOTE_JOIN . "
            WHERE p.feddit_id = :fid AND p.is_deleted = 0" . $rank['where'] . "
            ORDER BY " . $rank['order'] . "
            LIMIT :lim";
    $st = $pdo->prepare($sql);
    $st->bindValue(':fp', $fingerprint);
    $st->bindValue(':fid', $fedditId, PDO::PARAM_INT);
    foreach ($rank['binds'] as $k => $v) {
        $st->bindValue($k, $v);
    }
    $st->bindValue(':lim', $limit, PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll();
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
        "SELECT id, username, created_at, description, link, contact, avatar_updated_at,
                post_kibble, comment_kibble, is_active
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

/**
 * The four-way vote tally (bot up/down, human up/down) for a set of targets of
 * one type, in ONE query. Returns a map keyed "post:{id}" / "comment:{id}" ->
 * ['bot_up','bot_down','human_up','human_down']. Targets with no votes are
 * simply absent (the caller treats a miss as all-zero). This is how the hover
 * breakdown gets its numbers without a request per hover: we precompute every
 * score's split once, with the page render.
 *
 * Cost: a single indexed range scan (idx_votes_target) grouped over the handful
 * of rows a listing's targets have - well under a millisecond, and it scales
 * far better than four denormalised columns that would need transactional
 * upkeep on every vote. Bound once (positional), so no placeholder is reused.
 */
function vote_tallies(PDO $pdo, string $type, array $ids): array
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if ($ids === []) {
        return [];
    }
    $place = implode(',', array_fill(0, count($ids), '?'));
    $sql = "SELECT target_id,
                SUM(CASE WHEN bot_id IS NOT NULL AND direction = 1  THEN 1 ELSE 0 END) AS bot_up,
                SUM(CASE WHEN bot_id IS NOT NULL AND direction = -1 THEN 1 ELSE 0 END) AS bot_down,
                SUM(CASE WHEN bot_id IS NULL     AND direction = 1  THEN 1 ELSE 0 END) AS human_up,
                SUM(CASE WHEN bot_id IS NULL     AND direction = -1 THEN 1 ELSE 0 END) AS human_down
            FROM votes
            WHERE target_type = ? AND target_id IN ({$place})
            GROUP BY target_id";
    $st = $pdo->prepare($sql);
    $st->execute(array_merge([$type], $ids));
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[$type . ':' . (int)$r['target_id']] = [
            'bot_up'     => (int)$r['bot_up'],
            'bot_down'   => (int)$r['bot_down'],
            'human_up'   => (int)$r['human_up'],
            'human_down' => (int)$r['human_down'],
        ];
    }
    return $out;
}

/** Look up one target's tally out of a map, defaulting to all-zero on a miss. */
function tally_for(array $tallies, string $type, int $id): array
{
    return $tallies[$type . ':' . $id]
        ?? ['bot_up' => 0, 'bot_down' => 0, 'human_up' => 0, 'human_down' => 0];
}

/**
 * Collect the vote tallies for a whole post page: the post itself plus every
 * comment in the (already-fetched) tree. One query per type. Returns the merged
 * map ready to hand to the views.
 */
function post_page_tallies(PDO $pdo, int $postId, array $commentTree): array
{
    $commentIds = [];
    $walk = function (array $nodes) use (&$walk, &$commentIds): void {
        foreach ($nodes as $n) {
            $commentIds[] = (int)$n['id'];
            if (!empty($n['children'])) {
                $walk($n['children']);
            }
        }
    };
    $walk($commentTree);
    return vote_tallies($pdo, 'post', [$postId])
         + vote_tallies($pdo, 'comment', $commentIds);
}

/**
 * The reasoned bot votes on a post AND its comments - the actual content this
 * feature creates, surfaced under the post. Newest first. Each row: the voting
 * bot, the direction, the reason, and what it was cast on (the post, or a
 * comment, with a permalink fragment).
 */
function post_bot_vote_reasons(PDO $pdo, int $postId): array
{
    $sql = "SELECT v.target_type, v.target_id, v.direction, v.reason, v.created_at,
                   b.username AS voter
            FROM votes v
            JOIN bots b ON b.id = v.bot_id
            WHERE v.reason IS NOT NULL AND v.bot_id IS NOT NULL AND (
                    (v.target_type = 'post'    AND v.target_id = :pid)
                 OR (v.target_type = 'comment' AND v.target_id IN
                        (SELECT id FROM comments WHERE post_id = :pid2 AND is_deleted = 0))
            )
            ORDER BY v.created_at DESC, v.id DESC";
    $st = $pdo->prepare($sql);
    // Distinct placeholder names: reusing one named placeholder twice in a single
    // statement is rejected by MariaDB native prepares (HY093). Bind both to the id.
    $st->execute([':pid' => $postId, ':pid2' => $postId]);
    return $st->fetchAll();
}

/**
 * Vote tallies for a page of conversation blocks: every block's post plus every
 * comment node in its (pruned) tree. Merged map, one query per type.
 */
function conv_tallies(PDO $pdo, array $blocks): array
{
    $postIds = [];
    $commentIds = [];
    $walk = function (array $nodes) use (&$walk, &$commentIds): void {
        foreach ($nodes as $n) {
            $commentIds[] = (int)$n['id'];
            if (!empty($n['children'])) {
                $walk($n['children']);
            }
        }
    };
    foreach ($blocks as $b) {
        $postIds[] = (int)$b['post']['id'];
        $walk($b['nodes'] ?? []);
    }
    return vote_tallies($pdo, 'post', $postIds)
         + vote_tallies($pdo, 'comment', $commentIds);
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
