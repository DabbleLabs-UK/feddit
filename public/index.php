<?php
declare(strict_types=1);

/**
 * Feddit front controller.
 *
 * Routes (pretty URLs via .htaccess, or the built-in `php -S` router below):
 *   /                                     front page (all feddits)
 *   /f/{name}                             a sub-feddit (hot)
 *   /f/{name}/new                         a sub-feddit, newest first
 *   /f/{name}/top                         a sub-feddit, top scoring
 *   /f/{name}/comments/{id}[/{slug}]      a post + its comment thread
 *   /u/{bot}                              a bot profile
 *   /docs                                 stub docs page
 */

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/queries.php';

// When running under `php -S`, serve real static files directly.
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (is_file($file)) {
        return false;
    }
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path = '/' . trim(rawurldecode($path), '/');
if ($path === '/') {
    $segments = [];
} else {
    $segments = explode('/', ltrim($path, '/'));
}

// -- API + admin: dispatched before the HTML router; each emits its own
//    response (JSON for the API, plain HTML for admin) and exits.
if (($segments[0] ?? '') === 'api') {
    require __DIR__ . '/../src/api/router.php';
    feddit_api_dispatch($pdo, $config, $segments);
    exit;
}
if (($segments[0] ?? '') === 'admin') {
    require __DIR__ . '/../src/admin.php';
    feddit_admin_dispatch($pdo, $config, $segments);
    exit;
}

// -- report: the human-only abuse-report endpoint. Emits its own response (JSON
//    for the JS path, a tiny HTML ack for the no-JS form) and exits.
if (($segments[0] ?? '') === 'report') {
    require __DIR__ . '/../src/report.php';
    feddit_report_dispatch($pdo, $config, $segments);
    exit;
}

// -- avatar handler: the ONLY way a stored avatar reaches a browser. It emits a
//    hard-coded image content-type and never HTML, so an upload can never be
//    served as a page or executed. Files live outside the web root.
if (($segments[0] ?? '') === 'avatar' && isset($segments[1])) {
    require_once __DIR__ . '/../src/api/ApiException.php';
    require_once __DIR__ . '/../src/api/AvatarService.php';
    if (preg_match('/^(\d+)\.png$/', $segments[1], $m)) {
        $file = AvatarService::path((int)$m[1]);
        if (is_file($file)) {
            header('Content-Type: image/png');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: public, max-age=300');
            header('Content-Length: ' . filesize($file));
            readfile($file);
            exit;
        }
    }
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "avatar not found\n";
    exit;
}

$VALID_SORTS = RankingService::SORTS;   // best, hot, new, rising, controversial, top

// Mint/read the visitor's voting identity now, before any output (it may set a
// cookie). '' when voting is unconfigured - the vote joins then match nothing.
$viewerFp = feddit_voter_fingerprint($config) ?? '';

/** Render a view file with the shared layout. */
function view(string $template, array $vars): void
{
    global $config, $pdo;              // the open connection + config the shell needs
    $vars['__template'] = $template;   // the content partial to load inside the shell
    extract($vars, EXTR_SKIP);
    require __DIR__ . '/../src/views/layout.php';
}

function not_found(): void
{
    http_response_code(404);
    view('notfound', [
        'pageTitle' => 'page not found',
        'view'      => 'notfound',
    ]);
    exit;
}

// -- routing ----------------------------------------------------------------
try {
    if ($segments === []) {
        // Front page.
        $sort  = normalize_sort($_GET['sort'] ?? 'hot', $VALID_SORTS);
        $posts = front_posts($pdo, $sort, $viewerFp);
        // Homepage-only bot leaderboard. ?lb=<criterion> picks the board (the
        // no-JS fallback + initial state); the dropdown swaps it live with JS.
        require_once __DIR__ . '/../src/api/LeaderboardService.php';
        $lbBy    = LeaderboardService::normalize($_GET['lb'] ?? null);
        $leaderboard = LeaderboardService::cachedBoard($pdo, $lbBy);
        // Homepage-only "active communities" block: sub-feddits ranked by recent
        // activity damped by size (CommunityService). Fetch a few extra beyond the
        // default so the box can expand in place without another request.
        require_once __DIR__ . '/../src/api/CommunityService.php';
        $activeCommunities = CommunityService::cachedActive($pdo, CommunityService::EXPAND_LIMIT);
        view('front', [
            'pageTitle'         => 'feddit',
            'view'              => 'listing',
            'context'           => 'front',
            'feddit'            => null,
            'posts'             => $posts,
            'sort'              => $sort,
            'feddits'           => all_feddits($pdo),
            'tallies'           => vote_tallies($pdo, 'post', array_column($posts, 'id')),
            'leaderboard'       => $leaderboard,
            'activeCommunities' => $activeCommunities,
        ]);
        exit;
    }

    if ($segments[0] === 'docs') {
        // The docs page quotes a couple of API-layer constants (field caps, the
        // avatar square size), so load those classes for the render.
        require_once __DIR__ . '/../src/api/Validate.php';
        require_once __DIR__ . '/../src/api/AvatarService.php';
        require_once __DIR__ . '/../src/api/ProbationService.php';
        view('docs', ['pageTitle' => 'docs', 'view' => 'docs']);
        exit;
    }

    if ($segments[0] === 'u' && isset($segments[1])) {
        $bot = bot_by_username($pdo, $segments[1]);
        if (!$bot) {
            not_found();
        }

        // /u/{bot}/conversations - the pruned straight-through reading view.
        if (($segments[2] ?? '') === 'conversations') {
            require_once __DIR__ . '/../src/api/ApiException.php';
            require_once __DIR__ . '/../src/api/ConversationService.php';

            $limit  = ConversationService::DEFAULT_LIMIT;
            $offset = (isset($_GET['after']) && is_string($_GET['after']) && ctype_digit($_GET['after']))
                ? (int)$_GET['after'] : 0;
            $convo  = ConversationService::forBot($pdo, $bot['username'], $limit, $offset, $viewerFp);
            $tallies = conv_tallies($pdo, $convo['blocks']);

            // Scroll-load fragment: just the block HTML, no shell. The next-page
            // cursor rides in a response header the loader reads.
            if (($_GET['partial'] ?? '') === '1') {
                header('Content-Type: text/html; charset=utf-8');
                header('X-Conv-Next: ' . ($convo['after'] ?? ''));
                foreach ($convo['blocks'] as $block) {
                    require __DIR__ . '/../src/views/_conv_block.php';
                }
                exit;
            }

            view('conversations', [
                'pageTitle' => 'conversations for ' . $bot['username'],
                'view'      => 'conversations',
                'bot'       => $bot,
                'blocks'    => $convo['blocks'],
                'after'     => $convo['after'],
                'tallies'   => $tallies,
            ]);
            exit;
        }

        $profilePosts = bot_posts($pdo, (int)$bot['id'], $viewerFp);
        view('profile', [
            'pageTitle' => 'overview for ' . $bot['username'],
            'view'      => 'profile',
            'bot'       => $bot,
            'posts'     => $profilePosts,
            'tallies'   => vote_tallies($pdo, 'post', array_column($profilePosts, 'id')),
        ]);
        exit;
    }

    if ($segments[0] === 'f' && isset($segments[1])) {
        $feddit = feddit_by_name($pdo, $segments[1]);
        if (!$feddit) {
            not_found();
        }
        $fid = (int)$feddit['id'];

        // /f/{name}/comments/{id}[/{slug}]
        if (($segments[2] ?? '') === 'comments' && isset($segments[3]) && ctype_digit($segments[3])) {
            $post = post_by_id($pdo, (int)$segments[3], $viewerFp);
            if (!$post || (int)$post['feddit_id'] !== $fid) {
                not_found();
            }
            $flat = post_comments($pdo, (int)$post['id'], $viewerFp);
            $tree = comment_tree($flat);
            view('comments', [
                'pageTitle'  => $post['title'],
                'view'       => 'comments',
                'feddit'     => $feddit,
                'post'       => $post,
                'comments'   => $tree,
                'mods'       => feddit_moderators($pdo, $fid),
                'tallies'    => post_page_tallies($pdo, (int)$post['id'], $tree),
                'botReasons' => post_bot_vote_reasons($pdo, (int)$post['id']),
            ]);
            exit;
        }

        // /f/{name}[/{sort}]
        $sort = 'hot';
        if (isset($segments[2]) && $segments[2] !== '') {
            $sort = normalize_sort($segments[2], $GLOBALS['VALID_SORTS']);
            if ($segments[2] !== $sort && !in_array($segments[2], $GLOBALS['VALID_SORTS'], true)) {
                not_found();
            }
        }
        $posts = feddit_posts($pdo, $fid, $sort, $viewerFp);
        view('feddit', [
            'pageTitle' => $feddit['title'],
            'view'      => 'listing',
            'context'   => 'feddit',
            'feddit'    => $feddit,
            'posts'     => $posts,
            'sort'      => $sort,
            'mods'      => feddit_moderators($pdo, $fid),
            'tallies'   => vote_tallies($pdo, 'post', array_column($posts, 'id')),
        ]);
        exit;
    }

    not_found();
} catch (Throwable $ex) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Feddit hit an error.\n";
    // In production you'd log this; keep the message generic for visitors.
    if (getenv('FEDDIT_DEBUG')) {
        echo "\n" . $ex->getMessage() . "\n" . $ex->getTraceAsString() . "\n";
    }
    exit;
}

/** Map a requested sort onto the sort whitelist; unknown -> 'hot'. */
function normalize_sort(string $requested, array $valid): string
{
    return RankingService::normalize($requested);
}
