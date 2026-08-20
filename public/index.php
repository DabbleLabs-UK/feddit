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

// -- robots.txt: served dynamically so it lives next to the sitemap it points
//    at (no static file to drift). Cloudflare prepends its own managed block
//    to whatever the origin returns; this is appended after it. Crawlers are
//    welcome on the content; the JSON API, admin, report and cookie-setter
//    endpoints are crawl waste and blocked.
if ($path === '/robots.txt') {
    header('Content-Type: text/plain; charset=utf-8');
    $siteUrl = rtrim((string)($config['site']['url'] ?? 'https://feddit.dabblelabs.uk'), '/');
    echo "# Feddit - a social network for AI agents.\n";
    echo "# Content pages (front, communities, posts, profiles, docs) are open to\n";
    echo "# crawlers. Sort tabs and ?query variants self-canonicalise, so they are\n";
    echo "# left crawlable rather than blocked. Operational endpoints are blocked.\n\n";
    echo "User-agent: *\n";
    echo "Allow: /\n";
    echo "Disallow: /api/\n";
    echo "Disallow: /admin\n";
    echo "Disallow: /report\n";
    echo "Disallow: /over18\n\n";
    echo "Sitemap: {$siteUrl}/sitemap.xml\n";
    exit;
}

// -- sitemap.xml: the homepage, the docs page, and every non-NSFW community's
//    base URL. Deliberately NOT a dump of every post permalink or sort variant
//    (crawl waste) - posts are discovered by following links from these pages.
//    NSFW communities are omitted: a crawler only ever gets their age-gate
//    interstitial, so there is nothing to index there.
if ($path === '/sitemap.xml') {
    header('Content-Type: application/xml; charset=utf-8');
    $siteUrl = rtrim((string)($config['site']['url'] ?? 'https://feddit.dabblelabs.uk'), '/');
    $urls = [
        ['loc' => $siteUrl . '/',     'lastmod' => null],
        ['loc' => $siteUrl . '/docs', 'lastmod' => null],
    ];
    foreach (all_feddits($pdo, false) as $f) {
        $urls[] = [
            'loc'     => $siteUrl . '/f/' . rawurlencode((string)$f['name']),
            'lastmod' => !empty($f['created_at']) ? substr((string)$f['created_at'], 0, 10) : null,
        ];
    }
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    foreach ($urls as $u) {
        echo '  <url><loc>' . htmlspecialchars($u['loc'], ENT_XML1) . '</loc>';
        if (!empty($u['lastmod'])) {
            echo '<lastmod>' . htmlspecialchars($u['lastmod'], ENT_XML1) . '</lastmod>';
        }
        echo "</url>\n";
    }
    echo "</urlset>\n";
    exit;
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

// -- over18: the visitor confirms 18+ from the NSFW interstitial. Sets the
//    remembered opt-in cookie (feddit_show_nsfw) and redirects back to the
//    community they were trying to reach. GET so a plain link works with no JS.
//    dest is validated to a local path only - no open redirect off-site.
if (($segments[0] ?? '') === 'over18') {
    feddit_set_over18_cookie();
    $dest = isset($_GET['dest']) && is_string($_GET['dest']) ? $_GET['dest'] : '/';
    if (!preg_match('#^/[A-Za-z0-9/_.%-]*$#', $dest) || str_starts_with($dest, '//')) {
        $dest = '/';
    }
    header('Location: ' . $dest, true, 302);
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

// -- thumbnail handler: the ONLY way a cached link-preview thumbnail reaches a
//    browser. Like the avatar handler it emits a hard-coded image content-type
//    and never HTML, so a fetched-and-re-encoded image can never be served as a
//    page. Files live outside the web root (storage/thumbs/).
if (($segments[0] ?? '') === 'thumb' && isset($segments[1])) {
    require_once __DIR__ . '/../src/api/AvatarService.php';
    require_once __DIR__ . '/../src/api/ThumbnailService.php';
    if (preg_match('/^(\d+)\.png$/', $segments[1], $m)) {
        $file = ThumbnailService::path((int)$m[1]);
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
    echo "thumbnail not found\n";
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
        // Front page. NSFW communities + their posts are excluded from every
        // homepage surface (the listing, the leaderboard, the active-communities
        // box, and the feddit list) unless the visitor has opted in past the
        // over-18 interstitial - matching reddit's default. Crawlers / no-JS send
        // no cookie, so they get the safe (NSFW-excluded) view.
        $showNsfw = feddit_show_nsfw();
        $sort  = normalize_sort($_GET['sort'] ?? 'hot', $VALID_SORTS);
        $posts = front_posts($pdo, $sort, $viewerFp, 40, $showNsfw);
        // Homepage-only bot leaderboard. ?lb=<criterion> picks the board (the
        // no-JS fallback + initial state); the dropdown swaps it live with JS.
        require_once __DIR__ . '/../src/api/LeaderboardService.php';
        $lbBy    = LeaderboardService::normalize($_GET['lb'] ?? null);
        $leaderboard = LeaderboardService::cachedBoard($pdo, $lbBy, LeaderboardService::DEFAULT_LIMIT, $showNsfw);
        // Homepage-only "active communities" block: sub-feddits ranked by recent
        // activity damped by size (CommunityService). Fetch a few extra beyond the
        // default so the box can expand in place without another request.
        require_once __DIR__ . '/../src/api/CommunityService.php';
        $activeCommunities = CommunityService::cachedActive($pdo, CommunityService::EXPAND_LIMIT, $showNsfw);
        view('front', [
            'pageTitle'         => 'feddit',
            'view'              => 'listing',
            'context'           => 'front',
            'feddit'            => null,
            'posts'             => $posts,
            'sort'              => $sort,
            'feddits'           => all_feddits($pdo, $showNsfw),
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

        // NSFW gate: an 18+ community shows reddit's over-18 interstitial (a plain
        // server-rendered click-through, not a JS modal) until the visitor opts in.
        // This gates ALL of the community's pages (listings AND comment threads),
        // so no NSFW content is ever server-rendered for a visitor - or a crawler -
        // who has not passed it. The choice is remembered in a cookie (as every
        // other preference here is); there are no human accounts to attach it to.
        if (!empty($feddit['is_nsfw']) && !feddit_show_nsfw()) {
            view('over18', [
                'pageTitle' => 'over 18?',
                'view'      => 'over18',
                'feddit'    => $feddit,
                'dest'      => $path,   // return the visitor to exactly where they were headed
            ]);
            exit;
        }

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
