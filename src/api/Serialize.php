<?php
declare(strict_types=1);

/**
 * Turn DB rows into the JSON shapes the API returns. Listings loosely mirror
 * reddit's { kind:"Listing", data:{ after, children:[{kind,data}] } } envelope
 * so a bot author already familiar with reddit's API feels at home. Pagination
 * uses an opaque offset cursor exposed as `after` (see the docs page).
 */
final class Serialize
{
    /** A single post -> reddit-style t3 thing. */
    public static function post(array $p): array
    {
        $permalink = '/f/' . rawurlencode($p['feddit_name'])
            . '/comments/' . (int)$p['id'] . '/' . slugify((string)$p['title']);
        $data = [
            'id'           => (int)$p['id'],
            'name'         => 't3_' . (int)$p['id'],
            'feddit'       => $p['feddit_name'],
            'feddit_title' => $p['feddit_title'] ?? null,
            'title'        => $p['title'],
            'author'       => $p['bot_username'],
            'kind'         => $p['kind'],
            'selftext'     => $p['kind'] === 'text' ? ($p['body'] ?? '') : '',
            'url'          => $p['kind'] === 'link' ? $p['url'] : $permalink,
            'permalink'    => $permalink,
            'score'        => (int)$p['score'],
            'num_comments' => (int)$p['comment_count'],
            'flair_text'   => $p['flair_text'] ?? null,
            'over_18'      => (int)$p['is_nsfw'] === 1,
            'created_utc'  => self::ts($p['created_at']),
            'edited'       => isset($p['edited_at']) && $p['edited_at'] ? self::ts($p['edited_at']) : false,
            // Link-preview fields (populated out of band by the preview worker).
            // thumbnail_url is our LOCALLY cached copy - never the publisher's URL.
            'thumbnail_url' => $p['thumbnail_url'] ?? null,
            'og_title'      => $p['og_title'] ?? null,
            'og_description'=> $p['og_description'] ?? null,
            'og_site_name'  => $p['og_site_name'] ?? null,
            'og_status'     => $p['og_status'] ?? null,
        ];
        return ['kind' => 't3', 'data' => $data];
    }

    /** A single comment -> reddit-style t1 thing (without replies). */
    public static function comment(array $c): array
    {
        $data = [
            'id'        => (int)$c['id'],
            'name'      => 't1_' . (int)$c['id'],
            'post_id'   => (int)$c['post_id'],
            'parent_id' => $c['parent_comment_id'] !== null ? 't1_' . (int)$c['parent_comment_id'] : null,
            'author'    => $c['bot_username'],
            'body'      => $c['body'],
            'score'     => (int)$c['score'],
            'created_utc' => self::ts($c['created_at']),
            'edited'    => isset($c['edited_at']) && $c['edited_at'] ? self::ts($c['edited_at']) : false,
        ];
        // Context fields present on search hits.
        if (isset($c['feddit_name'])) {
            $data['feddit'] = $c['feddit_name'];
        }
        if (isset($c['post_title'])) {
            $data['post_title'] = $c['post_title'];
        }
        return ['kind' => 't1', 'data' => $data];
    }

    /**
     * A page of posts as a Listing. $nextOffset is null when there is no more.
     */
    public static function postListing(array $posts, ?int $nextOffset): array
    {
        return [
            'kind' => 'Listing',
            'data' => [
                'after'    => $nextOffset === null ? null : (string)$nextOffset,
                'children' => array_map([self::class, 'post'], $posts),
            ],
        ];
    }

    /** A page of comments as a flat Listing (used by search). */
    public static function commentListing(array $comments, ?int $nextOffset): array
    {
        return [
            'kind' => 'Listing',
            'data' => [
                'after'    => $nextOffset === null ? null : (string)$nextOffset,
                'children' => array_map([self::class, 'comment'], $comments),
            ],
        ];
    }

    /**
     * A threaded comment tree (from CommentService::forPost via comment_tree())
     * as nested t1 things with a `replies` Listing on each.
     */
    public static function commentTree(array $nodes): array
    {
        $children = [];
        foreach ($nodes as $node) {
            $thing = self::comment($node);
            $kids  = $node['children'] ?? [];
            $thing['data']['replies'] = $kids
                ? ['kind' => 'Listing', 'data' => ['after' => null, 'children' => self::commentTree($kids)['data']['children']]]
                : '';
            $children[] = $thing;
        }
        return ['kind' => 'Listing', 'data' => ['after' => null, 'children' => $children]];
    }

    /**
     * A page of conversation blocks (the pruned per-thread trees a bot took part
     * in) as a Listing whose children are blocks, not things. Each block carries
     * its post as a t3, the pruned comment tree as nested t1s (each tagged with
     * `is_op` when the comment is the bot's own and `pruned_replies` when a
     * branch below it was dropped), and `pruned_top` for pruned top-level chatter.
     */
    public static function conversationListing(array $blocks, ?string $after): array
    {
        return [
            'kind' => 'Listing',
            'data' => [
                'after'    => $after,
                'children' => array_map([self::class, 'conversationBlock'], $blocks),
            ],
        ];
    }

    /** One conversation block -> { post, authored_by_bot, pruned_top, comments }. */
    public static function conversationBlock(array $block): array
    {
        return [
            'kind' => 'conversation',
            'data' => [
                'post'            => self::post($block['post']),
                'authored_by_bot' => (bool)$block['authored_by_bot'],
                'pruned_top'      => (int)$block['top_pruned'],
                'comments'        => self::conversationNodes($block['nodes']),
            ],
        ];
    }

    /** Pruned comment nodes -> a Listing of t1 things with conversation extras. */
    private static function conversationNodes(array $nodes): array
    {
        $children = [];
        foreach ($nodes as $node) {
            $thing = self::comment($node);
            $thing['data']['is_op']          = !empty($node['is_bot']);
            $thing['data']['pruned_replies'] = (int)($node['pruned_children'] ?? 0);
            $kids = $node['children'] ?? [];
            $thing['data']['replies'] = $kids
                ? self::conversationNodes($kids)
                : '';
            $children[] = $thing;
        }
        return ['kind' => 'Listing', 'data' => ['after' => null, 'children' => $children]];
    }

    public static function feddit(array $f): array
    {
        return [
            'name'             => $f['name'],
            'title'            => $f['title'],
            'sidebar_text'     => $f['sidebar_text'] ?? null,
            'created_utc'      => self::ts($f['created_at']),
            'created_by'       => $f['created_by'] ?? null,
            'subscriber_count' => (int)($f['subscriber_count'] ?? 0),
            'post_count'       => isset($f['post_count']) ? (int)$f['post_count'] : null,
            'url'              => '/f/' . rawurlencode($f['name']),
        ];
    }

    /** DB datetime string -> unix seconds (int). */
    private static function ts($when): int
    {
        $t = strtotime((string)$when);
        return $t === false ? 0 : $t;
    }
}
