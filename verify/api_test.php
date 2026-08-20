<?php
declare(strict_types=1);

/**
 * VERIFICATION ONLY (not shipped; verify/ is gitignored). Exercises the whole
 * bot API end-to-end over real HTTP against a throwaway SQLite database, since
 * no MariaDB is reachable here. It:
 *   1. builds a SQLite DB mirroring db/schema.sql (write-side included),
 *   2. points config.local.php at it with small, trippable rate limits,
 *   3. boots `php -S` against public/index.php,
 *   4. drives the happy path (register -> feddit -> submit -> comment -> read
 *      back -> search), the auth/ownership failures, a rate-limit trip, and the
 *      admin purge, asserting status codes and JSON along the way,
 *   5. shuts the server down and reports PASS/FAIL (exit code 1 on any failure).
 *
 * Run:  php verify/api_test.php
 */

error_reporting(E_ALL);
$ROOT   = dirname(__DIR__);
require_once $ROOT . '/src/api/ClientIp.php';   // for the client-IP resolution unit tests
require_once $ROOT . '/src/api/SsrfGuard.php';         // link-preview SSRF boundary (unit)
require_once $ROOT . '/src/api/LinkFetchError.php';
require_once $ROOT . '/src/api/LinkPreviewService.php';
require_once $ROOT . '/src/api/ApiException.php';
require_once $ROOT . '/src/api/AvatarService.php';
require_once $ROOT . '/src/api/ThumbnailService.php';
require_once $ROOT . '/db/og_worker.php';             // worker core (guarded main won't run)
$PORT   = 8791;
$BASE   = "http://127.0.0.1:{$PORT}";
$DBFILE = __DIR__ . '/feddit_api_test.sqlite';
$LOG    = __DIR__ . '/api_server.log';
$COOKIE = __DIR__ . '/api_cookies.txt';
@unlink($COOKIE);

// -- 1. build the SQLite database ------------------------------------------
@unlink($DBFILE);
$pdo = new PDO('sqlite:' . $DBFILE, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec("CREATE TABLE bots (
    id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT UNIQUE NOT NULL,
    created_at TEXT NOT NULL, description TEXT,
    link TEXT, contact TEXT, avatar_updated_at TEXT,
    post_kibble INTEGER NOT NULL DEFAULT 0, comment_kibble INTEGER NOT NULL DEFAULT 0,
    api_token_hash TEXT, is_active INTEGER NOT NULL DEFAULT 1, reg_ip_hash TEXT)");
$pdo->exec("CREATE TABLE feddits (
    id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT UNIQUE NOT NULL, title TEXT NOT NULL,
    description TEXT, sidebar_text TEXT, is_nsfw INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL, created_by_bot_id INTEGER,
    subscriber_count INTEGER NOT NULL DEFAULT 0)");
$pdo->exec("CREATE TABLE feddit_rules (
    id INTEGER PRIMARY KEY AUTOINCREMENT, feddit_id INTEGER NOT NULL, position INTEGER NOT NULL,
    title TEXT NOT NULL, detail TEXT, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
$pdo->exec("CREATE TABLE posts (
    id INTEGER PRIMARY KEY AUTOINCREMENT, feddit_id INTEGER NOT NULL, bot_id INTEGER NOT NULL,
    title TEXT NOT NULL, kind TEXT NOT NULL DEFAULT 'text', body TEXT, url TEXT,
    thumbnail_url TEXT, og_title TEXT, og_description TEXT, og_site_name TEXT,
    og_fetched_at TEXT, og_status TEXT, og_attempts INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL, score INTEGER NOT NULL DEFAULT 1, comment_count INTEGER NOT NULL DEFAULT 0,
    flair_text TEXT, flair_color TEXT, is_nsfw INTEGER NOT NULL DEFAULT 0,
    is_deleted INTEGER NOT NULL DEFAULT 0, edited_at TEXT)");
$pdo->exec("CREATE TABLE comments (
    id INTEGER PRIMARY KEY AUTOINCREMENT, post_id INTEGER NOT NULL, bot_id INTEGER NOT NULL,
    parent_comment_id INTEGER, body TEXT NOT NULL, created_at TEXT NOT NULL,
    score INTEGER NOT NULL DEFAULT 1, is_deleted INTEGER NOT NULL DEFAULT 0, edited_at TEXT)");
$pdo->exec("CREATE TABLE votes (
    id INTEGER PRIMARY KEY AUTOINCREMENT, target_type TEXT NOT NULL, target_id INTEGER NOT NULL,
    voter_fingerprint TEXT, bot_id INTEGER, direction INTEGER NOT NULL, reason TEXT,
    is_author_vote INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL,
    UNIQUE (target_type, target_id, voter_fingerprint),
    UNIQUE (target_type, target_id, bot_id),
    CHECK ((bot_id IS NULL) <> (voter_fingerprint IS NULL)))");
$pdo->exec("CREATE TABLE vote_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT, voter_fingerprint TEXT, bot_id INTEGER, created_at TEXT NOT NULL)");
$pdo->exec("CREATE TABLE reports (
    id INTEGER PRIMARY KEY AUTOINCREMENT, target_type TEXT NOT NULL, target_id INTEGER NOT NULL,
    reporter_fingerprint TEXT NOT NULL, reason TEXT NOT NULL, detail TEXT,
    status TEXT NOT NULL DEFAULT 'open', created_at TEXT NOT NULL,
    UNIQUE (target_type, target_id, reporter_fingerprint))");
$pdo = null;

// -- 2. point config.local.php at it, with trippable limits -----------------
$cfg = "<?php\nreturn [\n"
     . "    'db' => ['dsn' => 'sqlite:" . str_replace('\\', '/', $DBFILE) . "', 'user' => null, 'pass' => null],\n"
     . "    'site' => ['name' => 'Feddit', 'url' => 'http://localhost'],\n"
     . "    'admin_key' => 'test-admin-key',\n"
     . "    'vote_secret' => 'test-vote-secret-abc123',\n"
     . "    'rate_limits' => ['posts_per_hour' => 5, 'comments_per_hour' => 60, 'feddits_per_day' => 1, 'votes_per_hour' => 10, 'bot_votes_per_day' => 6, 'reports_per_hour' => 4],\n"
     // Registration cap is low + trippable. Trust 127.0.0.0/8 as a proxy so the
     // harness can simulate distinct client IPs via CF-Connecting-IP; suite calls
     // that send no such header resolve to null (unattributable, not throttled).
     . "    'registration' => ['per_hour' => 4, 'per_day' => 8, 'ip_salt' => 'test-ip-salt'],\n"
     . "    'cloudflare' => ['trusted_ranges' => ['127.0.0.0/8', '::1/128']],\n"
     // Tight probation limits; graduate by 24h OR 5 kibble.
     . "    'probation' => ['min_age_hours' => 24, 'min_kibble' => 5, 'posts_per_hour' => 2, 'comments_per_hour' => 3, 'votes_per_day' => 2],\n"
     . "    'avatar' => ['max_bytes' => 20000, 'min_seconds' => 0],\n"
     . "];\n";
file_put_contents($ROOT . '/config/config.local.php', $cfg);

// -- 3. boot php -S ---------------------------------------------------------
$docroot = $ROOT . '/public';
$router  = $docroot . '/index.php';
// Bail if the port is already taken (a stale dev server would silently answer
// our requests with the wrong code base).
$probe = @fsockopen('127.0.0.1', $PORT, $errno, $errstr, 0.3);
if ($probe) {
    fclose($probe);
    fwrite(STDERR, "Port {$PORT} is already in use; refusing to run against a stale server.\n");
    exit(1);
}
// Array form -> no cmd.exe shell wrapper, so proc_terminate() kills php.exe itself.
$argv = [PHP_BINARY, '-S', "127.0.0.1:{$PORT}", '-t', $docroot, $router];
$descr = [0 => ['pipe', 'r'], 1 => ['file', $LOG, 'w'], 2 => ['file', $LOG, 'a']];
$proc = proc_open($argv, $descr, $pipes, $docroot);
if (!is_resource($proc)) {
    fwrite(STDERR, "Could not start php -S\n");
    exit(1);
}
// Wait for the port to accept connections.
$ready = false;
for ($i = 0; $i < 100; $i++) {
    $fp = @fsockopen('127.0.0.1', $PORT, $errno, $errstr, 0.2);
    if ($fp) { fclose($fp); $ready = true; break; }
    usleep(100000);
}
if (!$ready) {
    fwrite(STDERR, "Server did not become ready. See {$LOG}\n");
    proc_terminate($proc);
    exit(1);
}

// -- tiny test framework + HTTP client -------------------------------------
$PASS = 0; $FAIL = 0;
function check(bool $cond, string $label): void
{
    global $PASS, $FAIL;
    if ($cond) { $PASS++; echo "  ok   {$label}\n"; }
    else       { $FAIL++; echo "  FAIL {$label}\n"; }
}

/**
 * @return array{status:int, json:mixed, raw:string}
 */
function http(string $method, string $path, array $opts = []): array
{
    global $BASE, $COOKIE;
    $jar = $opts['cookie'] ?? $COOKIE;   // a fresh jar => a fresh vote fingerprint
    $ch = curl_init($BASE . $path);
    $headers = $opts['headers'] ?? [];
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_FOLLOWLOCATION => $opts['follow'] ?? false,
        CURLOPT_TIMEOUT        => 10,
    ]);
    if (isset($opts['bearer'])) {
        $headers[] = 'Authorization: Bearer ' . $opts['bearer'];
    }
    if (array_key_exists('json', $opts)) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($opts['json']));
    } elseif (array_key_exists('form', $opts)) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($opts['form']));
    }
    if ($headers) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }
    $raw    = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return ['status' => $status, 'json' => json_decode((string)$raw, true), 'raw' => (string)$raw];
}

// Age a bot's account past probation (created_at far enough back) so the many
// suite bots that do legitimate heavy activity aren't throttled by the NEW-bot
// probation limits - those are exercised in isolation in the probation section.
$graduate = function (string $username) use ($DBFILE) {
    $p = new PDO('sqlite:' . $DBFILE, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $p->prepare('UPDATE bots SET created_at = ? WHERE username = ?')
      ->execute([date('Y-m-d H:i:s', time() - 72 * 3600), $username]);
};

$exit = 0;
try {
    echo "== client IP resolution (anti-spoof, unit) ==\n";
    // The security-critical bit, tested directly on ClientIp::resolve with
    // synthetic $_SERVER arrays: behind Cloudflare (production) trusted_ranges is
    // empty -> the built-in CF list. Only requests that ACTUALLY arrive from a CF
    // range may set the client IP via CF-Connecting-IP; from anywhere else the
    // header is ignored, so it can never be spoofed to dodge the registration cap.
    $prodCfg = ['cloudflare' => ['trusted_ranges' => []], 'registration' => ['ip_salt' => 's'], 'vote_secret' => 'v'];
    $cfPeer   = ['REMOTE_ADDR' => '104.16.0.5'];       // inside 104.16.0.0/13 (Cloudflare)
    $cf6Peer  = ['REMOTE_ADDR' => '2400:cb00::1'];     // inside 2400:cb00::/32 (Cloudflare v6)
    $evilPeer = ['REMOTE_ADDR' => '198.51.100.9'];     // NOT Cloudflare

    check(ClientIp::resolve($cfPeer + ['HTTP_CF_CONNECTING_IP' => '203.0.113.5'], $prodCfg) === '203.0.113.5',
        'CF-Connecting-IP is trusted when the peer IS a Cloudflare IP');
    check(ClientIp::resolve($evilPeer + ['HTTP_CF_CONNECTING_IP' => '203.0.113.5'], $prodCfg) === '198.51.100.9',
        'spoofed CF-Connecting-IP from a NON-Cloudflare peer is IGNORED (no bypass)');
    check(ClientIp::resolve($cfPeer, $prodCfg) === null,
        'Cloudflare peer with no CF-Connecting-IP -> null (never the shared edge IP)');
    check(ClientIp::resolve(['REMOTE_ADDR' => '203.0.113.7'], $prodCfg) === '203.0.113.7',
        'a direct (non-proxied) connection uses the socket peer as the client');
    check(ClientIp::resolve($cf6Peer + ['HTTP_CF_CONNECTING_IP' => '203.0.113.9'], $prodCfg) === '203.0.113.9',
        'IPv6 Cloudflare range is recognised too');
    check(ClientIp::resolve($cfPeer + ['HTTP_CF_CONNECTING_IP' => 'not-an-ip'], $prodCfg) === null,
        'a malformed CF-Connecting-IP is rejected, not passed through');
    // Two different spoofed headers from the same untrusted peer hash to the SAME
    // value: a spoofer cannot manufacture fresh registration buckets.
    $h1 = ClientIp::hashedClientIp($evilPeer + ['HTTP_CF_CONNECTING_IP' => '1.1.1.1'], $prodCfg);
    $h2 = ClientIp::hashedClientIp($evilPeer + ['HTTP_CF_CONNECTING_IP' => '2.2.2.2'], $prodCfg);
    check($h1 === $h2 && $h1 !== null, 'rotating a spoofed header does not change the resolved (peer) IP hash');

    echo "== registration ==\n";
    $r = http('POST', '/api/v1/register', ['json' => ['username' => 'alpha_bot', 'description' => 'The alpha test bot.']]);
    check($r['status'] === 201, 'register alpha_bot -> 201');
    $tokenA = $r['json']['token'] ?? null;
    check(is_string($tokenA) && str_starts_with($tokenA, 'feddit_'), 'token returned once, prefixed');
    check(isset($r['json']['warning']), 'one-time-token warning present');

    $r = http('POST', '/api/v1/register', ['json' => ['username' => 'ALPHA_BOT']]);
    check($r['status'] === 409, 'duplicate username (case-insensitive) -> 409');
    check(($r['json']['error']['code'] ?? '') === 'conflict', 'conflict error envelope');

    $r = http('POST', '/api/v1/register', ['json' => ['username' => 'ab']]);
    check($r['status'] === 400 && ($r['json']['error']['code'] ?? '') === 'validation_error', 'short username -> 400 validation');

    // alpha does the suite's heavy lifting (feddits, posts, comments, votes);
    // graduate it past probation so the new-bot limits (tested separately) don't
    // throttle the rest of the run.
    $graduate('alpha_bot');

    echo "== feddits ==\n";
    $r = http('POST', '/api/v1/feddits', ['bearer' => $tokenA, 'json' => ['name' => 'bottown', 'title' => 'Bot Town', 'sidebar_text' => 'Where the bots hang out.']]);
    check($r['status'] === 201 && ($r['json']['feddit']['name'] ?? '') === 'bottown', 'create feddit bottown -> 201');

    $r = http('POST', '/api/v1/feddits', ['json' => ['name' => 'noauth', 'title' => 'x', 'sidebar_text' => '']]);
    check($r['status'] === 401, 'create feddit without token -> 401');

    $r = http('POST', '/api/v1/feddits', ['bearer' => $tokenA, 'json' => ['name' => 'BOTTOWN', 'title' => 'dup', 'sidebar_text' => '']]);
    check($r['status'] === 409, 'duplicate feddit name -> 409');

    echo "== feddit rules + description (structured, machine-readable) ==\n";
    // A dedicated graduated bot: the test config caps feddits at 1/day, so each
    // community-creating bot needs its own budget.
    $tokenR = http('POST', '/api/v1/register', ['json' => ['username' => 'rules_bot']])['json']['token'] ?? null;
    check(is_string($tokenR), 'register rules_bot');
    $graduate('rules_bot');

    // Create a community WITH a description and an ordered, structured rules list
    // (mixing the bare-string and {title,detail} shapes).
    $r = http('POST', '/api/v1/feddits', ['bearer' => $tokenR, 'json' => [
        'name' => 'charts', 'title' => 'Charts', 'description' => 'Charts and the data behind them.',
        'rules' => [
            'Label your axes',
            ['title' => 'No dual y-axes', 'detail' => 'Two series share a scale or they get two charts.'],
            ['title' => 'Link the data'],
        ],
    ]]);
    check($r['status'] === 201, 'create feddit with rules -> 201');
    $cf = $r['json']['feddit'] ?? [];
    check(($cf['description'] ?? '') === 'Charts and the data behind them.', 'description stored + echoed');
    check(($cf['over_18'] ?? null) === false, 'default over_18 is false');
    $rules = $cf['rules'] ?? [];
    check(count($rules) === 3, 'three rules stored in order');
    check(($rules[0]['number'] ?? 0) === 1 && ($rules[0]['title'] ?? '') === 'Label your axes'
        && array_key_exists('detail', $rules[0]) && $rules[0]['detail'] === null,
        'rule 1: numbered, title kept, no detail');
    check(($rules[1]['title'] ?? '') === 'No dual y-axes' && ($rules[1]['detail'] ?? '') === 'Two series share a scale or they get two charts.',
        'rule 2: title + detail kept');

    // A BOT can READ a community's rules before posting: /f/{name}/about.json.
    $about = http('GET', '/api/v1/f/charts/about.json');
    check($about['status'] === 200, 'about.json -> 200');
    $aboutRules = $about['json']['feddit']['rules'] ?? [];
    check(count($aboutRules) === 3 && ($aboutRules[2]['title'] ?? '') === 'Link the data', 'about.json exposes the ordered rules a bot reads before posting');

    // feddits.json (the discovery endpoint) also carries each feddit's rules.
    $flist = http('GET', '/api/v1/feddits.json');
    $chartsRow = null;
    foreach ($flist['json']['feddits'] ?? [] as $f) { if (($f['name'] ?? '') === 'charts') { $chartsRow = $f; } }
    check($chartsRow !== null && count($chartsRow['rules'] ?? []) === 3, 'feddits.json carries each community\'s rules');

    // Owner edit: replace the rule list (and set NSFW) on a community you created.
    $r = http('POST', '/api/v1/feddits/charts', ['bearer' => $tokenR, 'json' => [
        'nsfw' => true, 'rules' => [['title' => 'Sources or it did not happen']],
    ]]);
    check($r['status'] === 200, 'owner edits own feddit -> 200');
    check(($r['json']['feddit']['over_18'] ?? null) === true, 'nsfw flag flipped on via edit');
    check(count($r['json']['feddit']['rules'] ?? []) === 1, 'rules replaced wholesale by the edit');

    // Clearing rules with an empty array.
    $r = http('POST', '/api/v1/feddits/charts', ['bearer' => $tokenR, 'json' => ['rules' => []]]);
    check($r['status'] === 200 && count($r['json']['feddit']['rules'] ?? [null]) === 0, 'rules cleared with []');
    // Restore a rule for later NSFW/sidebar checks.
    http('POST', '/api/v1/feddits/charts', ['bearer' => $tokenR, 'json' => ['rules' => [['title' => 'Label your axes']]]]);

    // Only the CREATOR can edit: another bot's edit is refused.
    $tokenR2 = http('POST', '/api/v1/register', ['json' => ['username' => 'not_owner']])['json']['token'] ?? null;
    $r = http('POST', '/api/v1/feddits/charts', ['bearer' => $tokenR2, 'json' => ['title' => 'hijacked']]);
    check($r['status'] === 403, 'a non-creator bot cannot edit the feddit -> 403');
    // Editing without a token is unauthorized.
    $r = http('POST', '/api/v1/feddits/charts', ['json' => ['title' => 'x']]);
    check($r['status'] === 401, 'editing a feddit without a token -> 401');

    // Rules are capped and sanitised.
    $tooMany = array_map(fn($i) => "rule {$i}", range(1, 20));
    $r = http('POST', '/api/v1/feddits/charts', ['bearer' => $tokenR, 'json' => ['rules' => $tooMany]]);
    check($r['status'] === 400, 'too many rules -> 400 (count cap)');
    $r = http('POST', '/api/v1/feddits/charts', ['bearer' => $tokenR, 'json' => ['rules' => [['title' => '']]]]);
    check($r['status'] === 400, 'empty rule title -> 400');

    echo "== NSFW community exclusion (front page + discovery) vs direct access ==\n";
    // A dedicated bot creates an NSFW community and posts to it.
    $tokenN = http('POST', '/api/v1/register', ['json' => ['username' => 'nsfw_bot']])['json']['token'] ?? null;
    $graduate('nsfw_bot');
    $r = http('POST', '/api/v1/feddits', ['bearer' => $tokenN, 'json' => [
        'name' => 'afterhours', 'title' => 'After Hours', 'nsfw' => true,
        'description' => 'Bots after dark.', 'rules' => [['title' => 'Tag your intensity']],
    ]]);
    check($r['status'] === 201 && ($r['json']['feddit']['over_18'] ?? null) === true, 'create NSFW feddit -> 201, over_18 true');
    $np = http('POST', '/api/v1/submit', ['bearer' => $tokenN, 'json' => [
        'feddit' => 'afterhours', 'title' => 'an after-hours post', 'kind' => 'text', 'body' => 'x',
    ]]);
    $nsfwPost = (int)($np['json']['post']['data']['id'] ?? 0);
    check($nsfwPost > 0, 'NSFW post created');

    $inFront = function (array $resp, int $id): bool {
        foreach ($resp['json']['data']['children'] ?? [] as $c) { if ((int)($c['data']['id'] ?? 0) === $id) { return true; } }
        return false;
    };
    // Default front page (no opt-in): the NSFW post is excluded.
    $frontDefault = http('GET', '/api/v1/front/new.json?limit=100');
    check(!$inFront($frontDefault, $nsfwPost), 'NSFW post is EXCLUDED from the default front listing');
    // Opting in with ?include_nsfw=1: it appears.
    $frontOptIn = http('GET', '/api/v1/front/new.json?limit=100&include_nsfw=1');
    check($inFront($frontOptIn, $nsfwPost), 'NSFW post appears on the front listing with ?include_nsfw=1');
    // Direct access to the community listing always works (reachable directly).
    $direct = http('GET', '/api/v1/f/afterhours/new.json');
    check($inFront($direct, $nsfwPost), 'the NSFW community is reachable directly (its own listing shows the post)');
    // Search still finds NSFW content (reachable via search).
    $srch = http('GET', '/api/v1/search.json?q=' . rawurlencode('after-hours') . '&type=post');
    $sHits = $srch['json']['results']['data']['children'] ?? [];
    check(count($sHits) >= 1, 'NSFW content is still reachable via search');
    // The active-communities box excludes NSFW by default, includes it on opt-in.
    $acDefault = http('GET', '/api/v1/communities/active.json?limit=50');
    $acNames = array_column($acDefault['json']['entries'] ?? [], 'name');
    check(!in_array('afterhours', $acNames, true), 'active-communities box excludes the NSFW community by default');
    $acOptIn = http('GET', '/api/v1/communities/active.json?limit=50&include_nsfw=1');
    $acNames2 = array_column($acOptIn['json']['entries'] ?? [], 'name');
    check(in_array('afterhours', $acNames2, true), 'active-communities box includes the NSFW community with ?include_nsfw=1');

    echo "== submit ==\n";
    $r = http('POST', '/api/v1/submit', ['bearer' => $tokenA, 'json' => [
        'feddit' => 'bottown', 'title' => 'Backoff and jitter save lives', 'kind' => 'text',
        'body' => 'Exponential backoff with jitter keeps the swarm polite.', 'flair_text' => 'PSA',
    ]]);
    check($r['status'] === 201, 'submit text post -> 201');
    $postId = $r['json']['post']['data']['id'] ?? 0;
    check($postId > 0, "post id returned ({$postId})");
    check(($r['json']['post']['kind'] ?? '') === 't3', 'post serialized as t3');

    $r = http('POST', '/api/v1/submit', ['json' => ['feddit' => 'bottown', 'title' => 'x', 'kind' => 'text']]);
    check($r['status'] === 401, 'submit without token -> 401');

    $r = http('POST', '/api/v1/submit', ['bearer' => 'feddit_deadbeef', 'json' => ['feddit' => 'bottown', 'title' => 'x', 'kind' => 'text']]);
    check($r['status'] === 401, 'submit with bad token -> 401');

    $r = http('POST', '/api/v1/submit', ['bearer' => $tokenA, 'json' => ['feddit' => 'bottown', 'title' => 'evil', 'kind' => 'link', 'url' => 'javascript:alert(1)']]);
    check($r['status'] === 400, 'submit link with javascript: url -> 400');

    $r = http('POST', '/api/v1/submit', ['bearer' => $tokenA, 'json' => ['feddit' => 'nope', 'title' => 'x', 'kind' => 'text']]);
    check($r['status'] === 404, 'submit to nonexistent feddit -> 404');

    echo "== comments ==\n";
    $r = http('POST', '/api/v1/comment', ['bearer' => $tokenA, 'json' => ['post_id' => $postId, 'body' => 'Great write-up.']]);
    check($r['status'] === 201, 'comment on post -> 201');
    $commentId = $r['json']['comment']['data']['id'] ?? 0;
    check($commentId > 0, "comment id returned ({$commentId})");

    $r = http('POST', '/api/v1/comment', ['bearer' => $tokenA, 'json' => ['post_id' => $postId, 'parent_comment_id' => $commentId, 'body' => 'Replying to myself.']]);
    check($r['status'] === 201, 'threaded reply -> 201');

    $r = http('POST', '/api/v1/comment', ['bearer' => $tokenA, 'json' => ['post_id' => 999999, 'body' => 'x']]);
    check($r['status'] === 404, 'comment on nonexistent post -> 404');

    echo "== reads ==\n";
    $r = http('GET', "/api/v1/comments/{$postId}.json");
    check($r['status'] === 200, 'comments.json -> 200');
    $children = $r['json']['comments']['data']['children'] ?? [];
    check(count($children) === 1, 'one top-level comment');
    $replies = $children[0]['data']['replies']['data']['children'] ?? [];
    check(count($replies) === 1, 'nested reply present in tree');
    check(($r['json']['post']['data']['num_comments'] ?? 0) === 2, 'post num_comments == 2');

    $r = http('GET', '/api/v1/f/bottown/new.json');
    check($r['status'] === 200 && !empty($r['json']['data']['children']), 'feddit listing new.json non-empty');

    $r = http('GET', '/api/v1/front/hot.json?limit=10');
    check($r['status'] === 200 && ($r['json']['kind'] ?? '') === 'Listing', 'front/hot.json is a Listing');

    $r = http('GET', '/api/v1/feddits.json');
    $names = array_column($r['json']['feddits'] ?? [], 'name');
    check(in_array('bottown', $names, true), 'feddits.json lists bottown');

    $r = http('GET', '/api/v1/u/alpha_bot.json');
    check($r['status'] === 200, 'profile -> 200');
    check(($r['json']['bot']['post_kibble'] ?? 0) >= 1, 'post_kibble accrued');
    check(($r['json']['bot']['comment_kibble'] ?? 0) >= 1, 'comment_kibble accrued');

    echo "== search ==\n";
    $r = http('GET', '/api/v1/search.json?q=' . rawurlencode('jitter') . '&type=post');
    $hits = $r['json']['results']['data']['children'] ?? [];
    check($r['status'] === 200 && count($hits) >= 1, 'post search finds the post');

    $r = http('GET', '/api/v1/search.json?q=' . rawurlencode('write-up') . '&type=comment&feddit=bottown');
    $hits = $r['json']['results']['data']['children'] ?? [];
    check($r['status'] === 200 && count($hits) >= 1, 'comment search (scoped to feddit) finds the comment');

    echo "== author self-vote: submit -> invariant holds immediately (no back-fill) ==\n";
    // THE regression guard for the phantom-+1 bug: a fresh post used to be written
    // with score = 1 and NO matching vote row, so (upvotes - downvotes) != score the
    // instant it existed. Now submit records the author's implicit upvote as a real
    // AUTHOR vote row in the same transaction. Prove it end-to-end over the SAME
    // SQLite file the server writes, with NO reconciliation step in between.
    require_once $ROOT . '/db/vote_backfill.php';
    require_once $ROOT . '/src/api/VoteService.php';

    $tokenSV = http('POST', '/api/v1/register', ['json' => ['username' => 'selfvote_bot']])['json']['token'] ?? null;
    check(is_string($tokenSV), 'register selfvote_bot');
    $graduate('selfvote_bot');
    http('POST', '/api/v1/feddits', ['bearer' => $tokenSV, 'json' => ['name' => 'selftest', 'title' => 'Self Test', 'sidebar_text' => 'invariant']]);

    $sv = http('POST', '/api/v1/submit', ['bearer' => $tokenSV, 'json' => [
        'feddit' => 'selftest', 'title' => 'A post that must reconcile the instant it exists', 'kind' => 'text', 'body' => 'x',
    ]]);
    $svPost = (int)($sv['json']['post']['data']['id'] ?? 0);
    check($svPost > 0 && (int)($sv['json']['post']['data']['score'] ?? -999) === 1, 'fresh post is created with score 1');

    // Open the same DB the running server just wrote to.
    $db = new PDO('sqlite:' . $DBFILE, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $db->exec('PRAGMA foreign_keys = ON');
    $svBot = (int)$db->query("SELECT bot_id FROM posts WHERE id = {$svPost}")->fetchColumn();

    // Exactly ONE vote row on the fresh post, and it is a well-formed author vote.
    $av = $db->query("SELECT bot_id, voter_fingerprint, direction, reason, is_author_vote FROM votes WHERE target_type='post' AND target_id={$svPost}")->fetchAll(PDO::FETCH_ASSOC);
    check(count($av) === 1, 'the fresh post has exactly one vote row (the author self-vote)');
    $a = $av[0] ?? [];
    check((int)($a['bot_id'] ?? 0) === $svBot, 'author self-vote is attributed to the author bot');
    check($a['voter_fingerprint'] === null && (int)($a['direction'] ?? 0) === 1
        && $a['reason'] === null && (int)($a['is_author_vote'] ?? 0) === 1,
        'author self-vote: bot_id set, direction +1, NO reason, is_author_vote = 1');

    // The four-way hover tally reconciles to the displayed score, through the real
    // tally code path (VoteService::tallyFor).
    $t = VoteService::tallyFor($db, 'post', $svPost);
    check($t['bot_up'] === 1 && $t['bot_down'] === 0 && $t['human_up'] === 0 && $t['human_down'] === 0,
        'tooltip tally shows exactly the author upvote (bot_up=1)');
    check(($t['bot_up'] + $t['human_up']) - ($t['bot_down'] + $t['human_down']) === 1,
        'the four tally numbers net to the displayed score (1)');

    // A comment shares the bug and the fix: it too gets a real author self-vote.
    $svc = http('POST', '/api/v1/comment', ['bearer' => $tokenSV, 'json' => ['post_id' => $svPost, 'body' => 'A comment that must also reconcile immediately.']]);
    $svComment = (int)($svc['json']['comment']['data']['id'] ?? 0);
    check($svComment > 0 && (int)($svc['json']['comment']['data']['score'] ?? -999) === 1, 'fresh comment is created with score 1');
    $cav = $db->query("SELECT is_author_vote, reason, direction FROM votes WHERE target_type='comment' AND target_id={$svComment}")->fetchAll(PDO::FETCH_ASSOC);
    check(count($cav) === 1 && (int)($cav[0]['is_author_vote'] ?? 0) === 1 && $cav[0]['reason'] === null && (int)($cav[0]['direction'] ?? 0) === 1,
        'the fresh comment has exactly one author self-vote (direction +1, no reason)');

    // THE assertion that would have caught the bug: with NO back-fill, the whole
    // site already satisfies every invariant, because every post/comment so far was
    // created through the normal API path and carries its own honest author vote.
    $ivFresh = feddit_vote_invariants($db);
    check(count($ivFresh['bad_score']) === 0,
        'INVARIANT (no back-fill): every live post/comment has (upvotes - downvotes) == score' .
        (count($ivFresh['bad_score']) ? ' (violations: ' . implode('; ', array_slice($ivFresh['bad_score'], 0, 5)) . ')' : ''));
    check(count($ivFresh['bad_kibble']) === 0, 'INVARIANT (no back-fill): every bot kibble == sum of its live content scores');
    check($ivFresh['self_votes'] === 0, 'the author self-vote is NOT counted as an illicit self-vote');
    check(count($ivFresh['dup_reason']) === 0, 'author self-votes carry no reason, so they add no duplicate reasons');

    // Every author self-vote is well-formed, and there is EXACTLY one per live
    // post/comment - never more (no double-count) and never zero (no phantom +1).
    $authorRows = (int)$db->query("SELECT COUNT(*) FROM votes WHERE is_author_vote = 1")->fetchColumn();
    $liveContent = (int)$db->query("SELECT (SELECT COUNT(*) FROM posts WHERE is_deleted=0) + (SELECT COUNT(*) FROM comments WHERE is_deleted=0)")->fetchColumn();
    check($authorRows === $liveContent, 'exactly one author self-vote per live post/comment (no phantom, no double-count)');
    $malformed = (int)$db->query("SELECT COUNT(*) FROM votes WHERE is_author_vote = 1 AND (reason IS NOT NULL OR voter_fingerprint IS NOT NULL OR direction <> 1 OR bot_id IS NULL)")->fetchColumn();
    check($malformed === 0, 'no author self-vote is malformed (all are bot_id, +1, no reason, no fingerprint)');

    // A bot CANNOT manufacture a self-vote through the API to farm kibble: voting on
    // its own content is refused before any row is written, so the count of author
    // rows on the post is unchanged and no is_author_vote row was forged.
    $forge = http('POST', '/api/v1/vote', ['bearer' => $tokenSV, 'json' => ['target_type' => 'post', 'target_id' => $svPost, 'direction' => 1, 'reason' => 'Trying to upvote my own post to mint an author vote.']]);
    check($forge['status'] === 403, 'a bot voting its own content is refused (cannot forge an author vote) -> 403');
    $stillOne = (int)$db->query("SELECT COUNT(*) FROM votes WHERE target_type='post' AND target_id={$svPost}")->fetchColumn();
    check($stillOne === 1, 'the refused self-vote wrote nothing: the post still has just its one author vote');

    // A DIFFERENT bot voting the post creates an ORDINARY vote (is_author_vote = 0):
    // the flag is set ONLY by submit/comment, never by the vote endpoint.
    $tokenSV2 = http('POST', '/api/v1/register', ['json' => ['username' => 'selfvote_peer']])['json']['token'] ?? null;
    $graduate('selfvote_peer');
    $peer = http('POST', '/api/v1/vote', ['bearer' => $tokenSV2, 'json' => ['target_type' => 'post', 'target_id' => $svPost, 'direction' => 1, 'reason' => 'A genuine reasoned upvote from a different bot entirely.']]);
    check($peer['status'] === 200, "a different bot's reasoned upvote on the post -> 200");
    $peerAuthorFlag = (int)$db->query("SELECT COALESCE(MAX(is_author_vote),0) FROM votes WHERE target_type='post' AND target_id={$svPost} AND bot_id={$svBot}")->fetchColumn();
    $peerRowFlag = (int)$db->query("SELECT is_author_vote FROM votes WHERE target_type='post' AND target_id={$svPost} AND bot_id <> {$svBot} AND bot_id IS NOT NULL LIMIT 1")->fetchColumn();
    check($peerAuthorFlag === 1 && $peerRowFlag === 0, 'endpoint votes are is_author_vote = 0; only the author row is flagged');

    // The author self-vote does NOT count against the bot's daily vote budget: it is
    // written by submit/comment, not the vote endpoint, so it logs no vote_events row.
    $svEvents = (int)$db->query("SELECT COUNT(*) FROM vote_events WHERE bot_id = {$svBot}")->fetchColumn();
    check($svEvents === 0, 'submitting a post + comment logged no vote_events (author self-votes are free of the daily cap)');
    $db = null;

    echo "== sorts (hot / new / rising / top over the JSON API) ==\n";
    // Build a controlled scenario in its own feddit. Posts submitted via the API are
    // all "now" with score 1, so we set created_at/score directly in the SQLite DB to
    // exercise the ranking (the running server reads the same file). Layout:
    //   W: score 3, ~12 min old  (young climber -> rising #1)
    //   X: score 6, 3h old        (-> hot #1)
    //   Y: score 20, 40h old      (old high score -> top #1, but sinks in hot, out of rising's 24h window)
    //   Z: score 4, 8h old
    // A dedicated bot: its own (clean) feddit-per-day and post-per-hour budgets, so
    // the ranking setup never trips the limits alpha_bot has partly spent.
    $tokenSort = http('POST', '/api/v1/register', ['json' => ['username' => 'sorter_bot']])['json']['token'] ?? null;
    check(is_string($tokenSort), 'register sorter_bot');
    $graduate('sorter_bot');   // creates a feddit + four posts: needs full limits
    http('POST', '/api/v1/feddits', ['bearer' => $tokenSort, 'json' => ['name' => 'sortville', 'title' => 'Sort Ville', 'sidebar_text' => 'ranking test']]);
    $mk = function ($title) use ($tokenSort) {
        return (int)(http('POST', '/api/v1/submit', ['bearer' => $tokenSort, 'json' => [
            'feddit' => 'sortville', 'title' => $title, 'kind' => 'text', 'body' => 'x',
        ]])['json']['post']['data']['id'] ?? 0);
    };
    $W = $mk('sort W'); $X = $mk('sort X'); $Y = $mk('sort Y'); $Z = $mk('sort Z');
    check(min($W, $X, $Y, $Z) > 0, 'four sort-test posts created');
    // Reach into the DB and set the scenario (created_at + score).
    $sp = new PDO('sqlite:' . $DBFILE, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $set = $sp->prepare('UPDATE posts SET created_at = ?, score = ? WHERE id = ?');
    $agoS = fn(float $h) => date('Y-m-d H:i:s', time() - (int)round($h * 3600));
    $set->execute([$agoS(0.2), 3, $W]);
    $set->execute([$agoS(3),   6, $X]);
    $set->execute([$agoS(40),  20, $Y]);
    $set->execute([$agoS(8),   4, $Z]);
    $sp = null;

    // Ordered post ids from a Listing response.
    $ids = function (array $resp): array {
        return array_map(fn($c) => (int)($c['data']['id'] ?? 0), $resp['json']['data']['children'] ?? []);
    };

    $rNew = http('GET', '/api/v1/f/sortville/new.json');
    check($rNew['status'] === 200 && ($rNew['json']['kind'] ?? '') === 'Listing', 'new.json is a Listing');
    check($ids($rNew) === [$W, $X, $Z, $Y], 'new = newest-first (W,X,Z,Y)');

    $rTop = http('GET', '/api/v1/f/sortville/top.json');
    check($ids($rTop) === [$Y, $X, $Z, $W], 'top = highest score first (Y,X,Z,W)');

    $rHot = http('GET', '/api/v1/f/sortville/hot.json');
    $hotIds = $ids($rHot);
    check($hotIds[0] === $X, 'hot #1 is X (age-weighted, not the raw top score)');
    check(end($hotIds) === $Y, 'hot sinks the old high-score post Y to the bottom (age dominates at this scale)');
    check($hotIds !== $ids($rTop), 'hot differs from top');

    $rRise = http('GET', '/api/v1/f/sortville/rising.json');
    $riseIds = $ids($rRise);
    check($rRise['status'] === 200 && ($rRise['json']['kind'] ?? '') === 'Listing', 'rising.json is a Listing');
    check(($riseIds[0] ?? 0) === $W, 'rising #1 is the young fast-climber W (smoothed velocity)');
    // Rising was rewritten to never be empty (the old 24h+score>=3 combo went
    // permanently blank on live data). No hard window now: the old high-score
    // post Y is KEPT but sinks on its own velocity, ranked below the young climber.
    check(in_array($Y, $riseIds, true), 'rising keeps the old high-score post Y (no hard window; velocity self-windows)');
    check(array_search($W, $riseIds, true) < array_search($Y, $riseIds, true), '...but ranks the young climber W above it');
    check(count($riseIds) === 4, 'rising is non-empty and returns every positive-score post');
    check($riseIds !== $hotIds, 'rising is not a reshuffle of hot (different ordering)');
    check($riseIds !== $ids($rNew), 'rising is not just new (velocity reorders by score-per-age)');

    // Unknown sort falls back to hot rather than erroring.
    $rBad = http('GET', '/api/v1/f/sortville/banana.json');
    check($rBad['status'] === 200 && ($rBad['json']['kind'] ?? '') === 'Listing', 'unknown sort falls back to a hot Listing');

    // Front page serves every sort.
    $rFrontRise = http('GET', '/api/v1/front/rising.json');
    check($rFrontRise['status'] === 200 && ($rFrontRise['json']['kind'] ?? '') === 'Listing', 'front/rising.json is a Listing');

    // best + controversial go through the same SQL (Wilson / balance-weighted).
    // These posts have no real vote rows, so controversial is legitimately empty,
    // but both endpoints must still answer with a well-formed Listing.
    $rBest = http('GET', '/api/v1/f/sortville/best.json');
    check($rBest['status'] === 200 && ($rBest['json']['kind'] ?? '') === 'Listing', 'f/{name}/best.json is a Listing');
    $rContro = http('GET', '/api/v1/f/sortville/controversial.json');
    check($rContro['status'] === 200 && ($rContro['json']['kind'] ?? '') === 'Listing', 'f/{name}/controversial.json is a Listing');
    check(is_array($rContro['json']['data']['children'] ?? null) && count($rContro['json']['data']['children']) === 0,
        'controversial is empty here (no real downvotes) - honest, not an error');
    $rFrontBest = http('GET', '/api/v1/front/best.json');
    check($rFrontBest['status'] === 200 && ($rFrontBest['json']['kind'] ?? '') === 'Listing', 'front/best.json is a Listing');

    echo "== conversations (pruning rule) ==\n";
    // Three fresh bots: the subject whose conversations we read, and two others
    // to build branches it never touches.
    $subj = http('POST', '/api/v1/register', ['json' => ['username' => 'convo_bot']])['json']['token'] ?? null;
    $o1   = http('POST', '/api/v1/register', ['json' => ['username' => 'sider_one']])['json']['token'] ?? null;
    $o2   = http('POST', '/api/v1/register', ['json' => ['username' => 'sider_two']])['json']['token'] ?? null;
    check(is_string($subj) && is_string($o1) && is_string($o2), 'registered convo_bot + two siders');
    // These three build a deep multi-comment thread; graduate them so probation's
    // tighter comment limit doesn't cut the scenario short.
    $graduate('convo_bot'); $graduate('sider_one'); $graduate('sider_two');

    // Helper: post a comment and return its new id.
    $cmt = function ($token, $postId, $parentId, $body) {
        $j = ['post_id' => $postId, 'body' => $body];
        if ($parentId !== null) { $j['parent_comment_id'] = $parentId; }
        $r = http('POST', '/api/v1/comment', ['bearer' => $token, 'json' => $j]);
        return $r['json']['comment']['data']['id'] ?? 0;
    };

    // --- Thread P: authored by a SIDER; convo_bot only comments in two branches.
    $P = http('POST', '/api/v1/submit', ['bearer' => $o2, 'json' => [
        'feddit' => 'bottown', 'title' => 'Pruning test thread', 'kind' => 'text', 'body' => 'root',
    ]])['json']['post']['data']['id'] ?? 0;
    check($P > 0, "thread P created ({$P})");

    $c1  = $cmt($o1, $P, null, 'c1 top-level, convo_bot never appears in this branch');
    $c1a = $cmt($o2, $P, $c1,  'c1a reply under c1 - still no convo_bot');
    $c2  = $cmt($o1, $P, null, 'c2 top-level, ancestor of a convo_bot comment');
    $c3  = $cmt($subj, $P, $c2, 'c3 BY convo_bot (a hit)');
    $c4  = $cmt($o2, $P, $c3,  'c4 descendant of the hit');
    $c5  = $cmt($o1, $P, $c4,  'c5 deeper descendant of the hit');
    $c6  = $cmt($o1, $P, $c3,  'c6 another descendant of the hit');
    $c7  = $cmt($o2, $P, $c2,  'c7 sibling branch under c2 - no convo_bot below it');
    $c8  = $cmt($o1, $P, null, 'c8 top-level, ancestor of another convo_bot comment');
    $c9  = $cmt($subj, $P, $c8, 'c9 BY convo_bot (a hit)');
    check(min($c1,$c1a,$c2,$c3,$c4,$c5,$c6,$c7,$c8,$c9) > 0, 'thread P branches built');

    // --- Thread Q: authored BY convo_bot; its whole reply tree is the convo.
    $Q = http('POST', '/api/v1/submit', ['bearer' => $subj, 'json' => [
        'feddit' => 'bottown', 'title' => 'A thread convo_bot started', 'kind' => 'text', 'body' => 'my own post',
    ]])['json']['post']['data']['id'] ?? 0;
    $q1 = $cmt($o1, $Q, null, 'q1 reply to convo_bot\'s post');
    $q2 = $cmt($o2, $Q, $q1,  'q2 reply under q1');
    $q3 = $cmt($o1, $Q, null, 'q3 another top-level reply');
    check($Q > 0 && min($q1,$q2,$q3) > 0, "thread Q (authored) built ({$Q})");

    // Flatten a conversation block's comment listing into id => node data.
    $flatten = function ($listing) use (&$flatten) {
        $out = [];
        foreach (($listing['data']['children'] ?? []) as $child) {
            $d = $child['data'];
            $out[(int)$d['id']] = $d;
            if (!empty($d['replies']) && is_array($d['replies'])) {
                $out += $flatten($d['replies']);
            }
        }
        return $out;
    };
    // Pull the block for a given post id out of a conversations response.
    $blockFor = function ($json, $postId) {
        foreach (($json['conversations']['data']['children'] ?? []) as $b) {
            if ((int)($b['data']['post']['data']['id'] ?? 0) === $postId) { return $b['data']; }
        }
        return null;
    };

    $conv = http('GET', '/api/v1/u/convo_bot/conversations.json');
    check($conv['status'] === 200, 'conversations.json -> 200');
    $blocks = $conv['json']['conversations']['data']['children'] ?? [];
    check(count($blocks) === 2, 'two conversation blocks (threads P and Q)');

    // --- Thread P: the pruning rule itself.
    $bp = $blockFor($conv['json'], $P);
    check($bp !== null, 'block for thread P present');
    $ids = $bp ? $flatten($bp['comments']) : [];

    // Ancestors + the hits + all descendants are INCLUDED.
    $mustHave = ['c2'=>$c2,'c3(hit)'=>$c3,'c4'=>$c4,'c5'=>$c5,'c6'=>$c6,'c8'=>$c8,'c9(hit)'=>$c9];
    $incOk = true;
    foreach ($mustHave as $lbl => $id) { if (!isset($ids[$id])) { $incOk = false; echo "     missing {$lbl} (#{$id})\n"; } }
    check($incOk, 'ancestors + hits + descendants all included');

    // Branches convo_bot never appears in are EXCLUDED.
    $excOk = !isset($ids[$c1]) && !isset($ids[$c1a]) && !isset($ids[$c7]);
    check($excOk, 'branches with no convo_bot comment are pruned (c1, c1a, c7 absent)');

    // The hits are marked as the bot's own; a bystander comment is not.
    check(($ids[$c3]['is_op'] ?? false) === true && ($ids[$c9]['is_op'] ?? false) === true, 'convo_bot comments flagged is_op');
    check(($ids[$c2]['is_op'] ?? true) === false, 'an ancestor by another bot is not flagged is_op');

    // Pruning is noted honestly, not hidden.
    check((int)($bp['pruned_top'] ?? 0) >= 1, 'pruned top-level branch is counted (>=1)');
    check((int)($ids[$c2]['pruned_replies'] ?? 0) >= 1, 'pruned sibling branch under c2 is counted (>=1)');
    check((int)($ids[$c3]['pruned_replies'] ?? -1) === 0, 'the hit c3 hides nothing (all its replies shown)');

    // --- Thread Q: authored by the bot, so the whole tree is the conversation.
    $bq = $blockFor($conv['json'], $Q);
    check($bq !== null && ($bq['authored_by_bot'] ?? false) === true, 'authored thread Q flagged authored_by_bot');
    $qids = $bq ? $flatten($bq['comments']) : [];
    check(isset($qids[$q1], $qids[$q2], $qids[$q3]), 'authored thread shows its whole reply tree');
    check((int)($bq['pruned_top'] ?? -1) === 0, 'authored thread prunes nothing at the top');

    // --- Soft-deleted content stays hidden here too.
    $del = http('POST', '/api/v1/delete', ['bearer' => $o1, 'json' => ['comment_id' => $c6]]);
    check($del['status'] === 200, 'soft-delete c6 -> 200');
    $conv2 = http('GET', '/api/v1/u/convo_bot/conversations.json');
    $bp2   = $blockFor($conv2['json'], $P);
    $ids2  = $bp2 ? $flatten($bp2['comments']) : [];
    check(!isset($ids2[$c6]) && isset($ids2[$c4]), 'deleted descendant hidden, siblings still shown');

    // --- Cursor pagination: one block per page, then exhausted.
    $pg1 = http('GET', '/api/v1/u/convo_bot/conversations.json?limit=1');
    check(count($pg1['json']['conversations']['data']['children'] ?? []) === 1, 'limit=1 returns one block');
    $after = $pg1['json']['conversations']['data']['after'] ?? null;
    check($after !== null, 'first page hands back a cursor');
    $pg2 = http('GET', '/api/v1/u/convo_bot/conversations.json?limit=1&after=' . rawurlencode((string)$after));
    $b1 = (int)($pg1['json']['conversations']['data']['children'][0]['data']['post']['data']['id'] ?? 0);
    $b2 = (int)($pg2['json']['conversations']['data']['children'][0]['data']['post']['data']['id'] ?? 0);
    check($b1 !== 0 && $b2 !== 0 && $b1 !== $b2, 'second page yields the other thread (cursor advances)');

    echo "== voting (human) ==\n";
    $VH = ['headers' => ['X-Feddit-Vote: 1']];   // the CSRF-ish custom header the browser sends

    // Baselines (the shared cookie jar gives a stable fingerprint across calls).
    $base   = http('GET', "/api/v1/comments/{$postId}.json");
    $score0 = (int)($base['json']['post']['data']['score'] ?? 0);
    $prof0  = http('GET', '/api/v1/u/alpha_bot.json');
    $pk0    = (int)($prof0['json']['bot']['post_kibble'] ?? 0);

    // CSRF guard: no custom header -> 403 (and nothing recorded).
    $r = http('POST', '/api/v1/vote', ['json' => ['target_type' => 'post', 'target_id' => $postId, 'direction' => 1]]);
    check($r['status'] === 403, 'vote without X-Feddit-Vote header -> 403 (CSRF guard)');

    // Bad inputs.
    $r = http('POST', '/api/v1/vote', $VH + ['json' => ['target_type' => 'banana', 'target_id' => $postId, 'direction' => 1]]);
    check($r['status'] === 400, 'invalid target_type -> 400');
    $r = http('POST', '/api/v1/vote', $VH + ['json' => ['target_type' => 'post', 'target_id' => $postId, 'direction' => 2]]);
    check($r['status'] === 400, 'invalid direction -> 400');
    $r = http('POST', '/api/v1/vote', $VH + ['json' => ['target_type' => 'post', 'target_id' => 999999, 'direction' => 1]]);
    check($r['status'] === 404, 'vote on nonexistent post -> 404');

    // Cast an upvote.
    $r = http('POST', '/api/v1/vote', $VH + ['json' => ['target_type' => 'post', 'target_id' => $postId, 'direction' => 1]]);
    check($r['status'] === 200, 'cast upvote -> 200');
    check((int)($r['json']['score'] ?? -999) === $score0 + 1, 'upvote raises score by 1');

    // Idempotent: the same direction again is a no-op.
    $r = http('POST', '/api/v1/vote', $VH + ['json' => ['target_type' => 'post', 'target_id' => $postId, 'direction' => 1]]);
    check((int)($r['json']['score'] ?? -999) === $score0 + 1, 'repeat upvote is idempotent (no double count)');

    // Kibble tracked the upvote.
    $prof = http('GET', '/api/v1/u/alpha_bot.json');
    check((int)($prof['json']['bot']['post_kibble'] ?? -999) === $pk0 + 1, 'author post_kibble +1 after upvote');

    // Flip to a downvote: a net swing of 2 from the up state -> baseline-1.
    $r = http('POST', '/api/v1/vote', $VH + ['json' => ['target_type' => 'post', 'target_id' => $postId, 'direction' => -1]]);
    check((int)($r['json']['score'] ?? -999) === $score0 - 1, 'flip up->down swings score to baseline-1');

    // Remove (clicking the active arrow again sends direction 0): back to baseline.
    $r = http('POST', '/api/v1/vote', $VH + ['json' => ['target_type' => 'post', 'target_id' => $postId, 'direction' => 0]]);
    check((int)($r['json']['score'] ?? -999) === $score0, 'removing the vote returns to baseline');

    // The denormalised score is what the rendered read path serves.
    $after = http('GET', "/api/v1/comments/{$postId}.json");
    check((int)($after['json']['post']['data']['score'] ?? -999) === $score0, 'read-back score persisted after remove');

    // Kibble also returned to baseline.
    $prof = http('GET', '/api/v1/u/alpha_bot.json');
    check((int)($prof['json']['bot']['post_kibble'] ?? -999) === $pk0, 'author post_kibble back to baseline after remove');

    // Comments vote independently and move comment_kibble.
    $ck0 = (int)($prof['json']['bot']['comment_kibble'] ?? 0);
    $r = http('POST', '/api/v1/vote', $VH + ['json' => ['target_type' => 'comment', 'target_id' => $commentId, 'direction' => 1]]);
    check($r['status'] === 200 && (int)($r['json']['score'] ?? -999) >= 2, 'upvote a comment -> 200, score rises');
    $prof = http('GET', '/api/v1/u/alpha_bot.json');
    check((int)($prof['json']['bot']['comment_kibble'] ?? -999) === $ck0 + 1, 'author comment_kibble +1 after comment upvote');
    http('POST', '/api/v1/vote', $VH + ['json' => ['target_type' => 'comment', 'target_id' => $commentId, 'direction' => 0]]); // tidy up

    echo "== vote rate limit ==\n";
    // A fresh cookie jar => a fresh fingerprint => its own hourly budget (10).
    $JAR2 = __DIR__ . '/api_cookies2.txt';
    @unlink($JAR2);
    $tripped = false; $last = null;
    for ($i = 1; $i <= 13; $i++) {
        $last = http('POST', '/api/v1/vote', [
            'cookie'  => $JAR2,
            'headers' => ['X-Feddit-Vote: 1'],
            'json'    => ['target_type' => 'post', 'target_id' => $postId, 'direction' => ($i % 2 ? 1 : 0)],
        ]);
        if ($last['status'] === 429) { $tripped = true; break; }
    }
    check($tripped, 'voting past votes_per_hour -> 429');
    check(($last['json']['error']['code'] ?? '') === 'rate_limited', 'vote rate-limit error envelope');
    @unlink($JAR2);

    echo "== voting (bot, reasoned) ==\n";
    // A voter bot: a bot cannot vote its own content, so alpha_bot (the author of
    // everything above) cannot be the voter here.
    $tokenV = http('POST', '/api/v1/register', ['json' => ['username' => 'voter_bot']])['json']['token'] ?? null;
    check(is_string($tokenV), 'register voter_bot');
    $graduate('voter_bot');   // casts several reasoned votes: needs the full daily budget

    // A clean post by alpha to vote on (no residue from the human-vote churn above).
    $vp = http('POST', '/api/v1/submit', ['bearer' => $tokenA, 'json' => [
        'feddit' => 'bottown', 'title' => 'Reasoned votes target', 'kind' => 'text', 'body' => 'vote here',
    ]]);
    $votePost = $vp['json']['post']['data']['id'] ?? 0;
    check($votePost > 0, "clean vote-target post created ({$votePost})");

    $bsl  = http('GET', "/api/v1/comments/{$votePost}.json");
    $vs0  = (int)($bsl['json']['post']['data']['score'] ?? 0);
    $apk0 = (int)(http('GET', '/api/v1/u/alpha_bot.json')['json']['bot']['post_kibble'] ?? 0);
    // $votePost is authored by alpha_bot, so it already carries alpha's own implicit
    // upvote as a real AUTHOR vote row (bot_up = 1) - reddit's "+1 from the author".
    // That is why the bot-upvote tallies below read one HIGHER than the number of
    // votes voter_bot casts: the author's self-vote counts in bot_up too, which is
    // exactly what keeps the four tooltip numbers netting to the displayed score.

    // Reason is required.
    $r = http('POST', '/api/v1/vote', ['bearer' => $tokenV, 'json' => ['target_type' => 'post', 'target_id' => $votePost, 'direction' => 1]]);
    check($r['status'] === 400, 'bot vote with no reason -> 400');

    // A trivially short reason is rejected.
    $r = http('POST', '/api/v1/vote', ['bearer' => $tokenV, 'json' => ['target_type' => 'post', 'target_id' => $votePost, 'direction' => 1, 'reason' => 'no']]);
    check($r['status'] === 400, 'bot vote with too-short reason -> 400');

    // Obvious filler is rejected even when long enough.
    $r = http('POST', '/api/v1/vote', ['bearer' => $tokenV, 'json' => ['target_type' => 'post', 'target_id' => $votePost, 'direction' => 1, 'reason' => 'nice nice good good']]);
    check($r['status'] === 400, 'bot vote with filler reason -> 400');

    // A bot cannot vote on its own content (alpha authored $votePost).
    $r = http('POST', '/api/v1/vote', ['bearer' => $tokenA, 'json' => ['target_type' => 'post', 'target_id' => $votePost, 'direction' => 1, 'reason' => 'Trying to upvote my own post, which should not be allowed.']]);
    check($r['status'] === 403, "bot voting its own content -> 403");

    // A valid reasoned upvote.
    $goodReason = 'Solid, tested advice and the numbers at the end back it up.';
    $r = http('POST', '/api/v1/vote', ['bearer' => $tokenV, 'json' => ['target_type' => 'post', 'target_id' => $votePost, 'direction' => 1, 'reason' => $goodReason]]);
    check($r['status'] === 200, 'reasoned bot upvote -> 200');
    check((int)($r['json']['score'] ?? -999) === $vs0 + 1, 'bot upvote raises score by 1');
    check((int)($r['json']['tally']['bot_up'] ?? -1) === 2, 'response tally shows the bot upvote + the author self-vote (bot_up=2)');
    check(($r['json']['reason'] ?? '') !== '', 'reason echoed back');

    // Idempotent: same direction again is a no-op.
    $r = http('POST', '/api/v1/vote', ['bearer' => $tokenV, 'json' => ['target_type' => 'post', 'target_id' => $votePost, 'direction' => 1, 'reason' => $goodReason]]);
    check((int)($r['json']['score'] ?? -999) === $vs0 + 1, 'repeat bot upvote is idempotent (no double count)');
    check((int)($r['json']['tally']['bot_up'] ?? -1) === 2, 'tally still bot_up=2 after repeat (author self-vote + voter)');

    // The author's kibble tracked the bot vote, same as a human vote.
    check((int)(http('GET', '/api/v1/u/alpha_bot.json')['json']['bot']['post_kibble'] ?? -999) === $apk0 + 1, 'author post_kibble +1 after bot upvote');

    // A human also upvotes the same post: the four-way tally counts them separately.
    $r = http('POST', '/api/v1/vote', ['headers' => ['X-Feddit-Vote: 1'], 'json' => ['target_type' => 'post', 'target_id' => $votePost, 'direction' => 1]]);
    check($r['status'] === 200, 'human upvote on the same post -> 200');
    check((int)($r['json']['tally']['bot_up'] ?? -1) === 2 && (int)($r['json']['tally']['human_up'] ?? -1) === 1,
        'tally splits bot vs human upvotes (bot_up=2 incl. author self-vote, human_up=1)');

    // Flip the bot vote to a downvote: voter_bot moves up->down, but the author's
    // own self-vote stays an upvote - so bot_up drops to 1 (the author), not 0.
    $r = http('POST', '/api/v1/vote', ['bearer' => $tokenV, 'json' => ['target_type' => 'post', 'target_id' => $votePost, 'direction' => -1, 'reason' => 'On reflection the core claim is unsupported by the method.']]);
    check((int)($r['json']['tally']['bot_up'] ?? -1) === 1 && (int)($r['json']['tally']['bot_down'] ?? -1) === 1, 'flip moves voter_bot up->down; the author self-vote remains bot_up=1');

    // Remove the bot vote (direction 0 needs no reason).
    $r = http('POST', '/api/v1/vote', ['bearer' => $tokenV, 'json' => ['target_type' => 'post', 'target_id' => $votePost, 'direction' => 0]]);
    check($r['status'] === 200, 'remove bot vote (no reason required) -> 200');
    check((int)($r['json']['tally']['bot_down'] ?? -1) === 0, 'removed bot vote leaves no bot downvote');
    check((int)($r['json']['tally']['human_up'] ?? -1) === 1, 'the human vote is still counted after the bot vote is removed');

    // A bot vote on a comment moves comment_kibble.
    $ck0 = (int)(http('GET', '/api/v1/u/alpha_bot.json')['json']['bot']['comment_kibble'] ?? 0);
    $r = http('POST', '/api/v1/vote', ['bearer' => $tokenV, 'json' => ['target_type' => 'comment', 'target_id' => $commentId, 'direction' => 1, 'reason' => 'This clarification is the genuinely useful part of the thread.']]);
    check($r['status'] === 200 && (int)($r['json']['tally']['bot_up'] ?? -1) === 2, 'bot upvote on a comment -> 200, tallied (bot_up=2 incl. the comment author self-vote)');
    check((int)(http('GET', '/api/v1/u/alpha_bot.json')['json']['bot']['comment_kibble'] ?? -999) === $ck0 + 1, 'author comment_kibble +1 after bot comment upvote');

    echo "== bot vote rate limit ==\n";
    // bot_votes_per_day is 6 in the test config. Graduate so this exercises the
    // NORMAL daily cap (the probation vote cap is tested in its own section).
    $tokenS = http('POST', '/api/v1/register', ['json' => ['username' => 'spammer_bot']])['json']['token'] ?? null;
    $graduate('spammer_bot');
    $tripped = false; $last = null;
    for ($i = 1; $i <= 9; $i++) {
        $last = http('POST', '/api/v1/vote', ['bearer' => $tokenS, 'json' => [
            'target_type' => 'post', 'target_id' => $votePost, 'direction' => ($i % 2 ? 1 : 0),
            'reason' => "Casting vote number {$i} with a genuine sentence explaining it.",
        ]]);
        if ($last['status'] === 429) { $tripped = true; break; }
    }
    check($tripped, 'bot voting past bot_votes_per_day -> 429');
    check(($last['json']['error']['code'] ?? '') === 'rate_limited', 'bot vote rate-limit error envelope');
    check(str_contains(strtolower($last['json']['error']['message'] ?? ''), 'per day'), 'bot rate-limit message names the daily limit');

    echo "== profile (/api/v1/me) ==\n";
    // A JSON null is distinct from an absent key: `$x['k'] ?? 'y'` collapses both,
    // so cleared fields must be checked with array_key_exists + a strict null.
    $isNull = function ($arr, string $key): bool {
        return is_array($arr) && array_key_exists($key, $arr) && $arr[$key] === null;
    };
    // A tiny valid PNG the server will re-encode. GD is loaded in this process.
    $makePng = function (int $w = 64, int $h = 64): string {
        $im = imagecreatetruecolor($w, $h);
        imagefilledrectangle($im, 0, 0, $w, $h, imagecolorallocate($im, 200, 80, 40));
        imagefilledellipse($im, (int)($w / 2), (int)($h / 2), (int)($w / 2), (int)($h / 2), imagecolorallocate($im, 40, 120, 200));
        ob_start(); imagepng($im); $png = (string)ob_get_clean(); imagedestroy($im);
        return base64_encode($png);
    };

    // Updating text fields on the caller's own profile.
    $r = http('POST', '/api/v1/me', ['bearer' => $tokenA, 'json' => [
        'bio' => 'Alpha edited its own bio here.', 'link' => 'https://alpha.example.com/project', 'contact' => '@alpha on the fediverse',
    ]]);
    check($r['status'] === 200, 'update own profile -> 200');
    check(($r['json']['bot']['link'] ?? '') === 'https://alpha.example.com/project', 'link stored + echoed');
    check(($r['json']['bot']['contact'] ?? '') === '@alpha on the fediverse', 'contact stored verbatim (free text)');
    check(($r['json']['bot']['bio'] ?? '') === 'Alpha edited its own bio here.', 'bio stored');

    // The public profile read reflects the update.
    $r = http('GET', '/api/v1/u/alpha_bot.json');
    check(($r['json']['bot']['link'] ?? '') === 'https://alpha.example.com/project', 'GET profile shows link');
    check(($r['json']['bot']['contact'] ?? '') === '@alpha on the fediverse', 'GET profile shows contact');
    $alphaId = (int)($r['json']['bot']['id'] ?? 0);

    // PATCH semantics: an absent field is untouched, an empty string clears one.
    $r = http('POST', '/api/v1/me', ['bearer' => $tokenA, 'json' => ['contact' => '']]);
    check($r['status'] === 200 && $isNull($r['json']['bot'] ?? null, 'contact'), 'empty contact clears it');
    check(($r['json']['bot']['link'] ?? '') === 'https://alpha.example.com/project', 'untouched link survives partial update');

    // Auth is required.
    $r = http('POST', '/api/v1/me', ['json' => ['bio' => 'no token']]);
    check($r['status'] === 401, 'update profile without token -> 401');

    // A bot can only edit its OWN profile: a second bot's edit never touches alpha.
    $tokenP = http('POST', '/api/v1/register', ['json' => ['username' => 'profile_other']])['json']['token'] ?? null;
    check(is_string($tokenP), 'register profile_other');
    $r = http('POST', '/api/v1/me', ['bearer' => $tokenP, 'json' => ['bio' => 'other bot bio', 'link' => 'https://other.example.com']]);
    check($r['status'] === 200 && ($r['json']['bot']['username'] ?? '') === 'profile_other', 'other bot edits only itself');
    $alpha = http('GET', '/api/v1/u/alpha_bot.json');
    check(($alpha['json']['bot']['link'] ?? '') === 'https://alpha.example.com/project', "another bot's edit did not change alpha's link");

    // Invalid link rejected.
    $r = http('POST', '/api/v1/me', ['bearer' => $tokenA, 'json' => ['link' => 'javascript:alert(1)']]);
    check($r['status'] === 400, 'javascript: link rejected -> 400');

    // Avatar: oversized upload rejected (decoded size > max_bytes = 20000).
    $big = base64_encode(random_bytes(25000));
    $r = http('POST', '/api/v1/me', ['bearer' => $tokenA, 'json' => ['avatar' => $big]]);
    check($r['status'] === 400, 'oversized avatar upload -> 400');

    // Avatar: not an image at all (plain text bytes) rejected by INSPECTION.
    $r = http('POST', '/api/v1/me', ['bearer' => $tokenA, 'json' => ['avatar' => base64_encode('this is definitely not an image, just prose')]]);
    check($r['status'] === 400, 'non-image avatar rejected -> 400');

    // Avatar: an image content-type is CLAIMED but the bytes are not an image.
    // We trust the bytes, not the declared type, so this is rejected too.
    $r = http('POST', '/api/v1/me', ['bearer' => $tokenA, 'json' => [
        'avatar' => 'data:image/png;base64,' . base64_encode('GIF89a not really a gif, and definitely not a png'),
    ]]);
    check($r['status'] === 400, 'fake image/png (image type claimed, non-image bytes) rejected -> 400');

    // Avatar: a genuine PNG is accepted, re-encoded, and served as an image.
    $r = http('POST', '/api/v1/me', ['bearer' => $tokenA, 'json' => ['avatar' => $makePng()]]);
    check($r['status'] === 200 && !empty($r['json']['bot']['avatar_url']), 'valid avatar accepted, avatar_url set');
    $av = http('GET', "/avatar/{$alphaId}.png");
    check($av['status'] === 200 && substr($av['raw'], 0, 8) === "\x89PNG\r\n\x1a\n", 'avatar served as a real PNG from /avatar/{id}.png');

    // Removing the avatar (null) deletes the file: the handler then 404s.
    $r = http('POST', '/api/v1/me', ['bearer' => $tokenA, 'json' => ['avatar' => null]]);
    check($r['status'] === 200 && $isNull($r['json']['bot'] ?? null, 'avatar_url'), 'avatar removed, avatar_url null');
    $av = http('GET', "/avatar/{$alphaId}.png");
    check($av['status'] === 404, 'removed avatar 404s from the handler');

    echo "== edit / delete / ownership ==\n";
    $r = http('POST', '/api/v1/edit', ['bearer' => $tokenA, 'json' => ['post_id' => $postId, 'title' => 'Backoff and jitter still save lives']]);
    check($r['status'] === 200 && ($r['json']['post']['data']['edited'] ?? false) !== false, 'edit own post sets edited');

    // Second bot for ownership + rate-limit tests.
    $r = http('POST', '/api/v1/register', ['json' => ['username' => 'beta_bot']]);
    $tokenB = $r['json']['token'] ?? null;
    check(is_string($tokenB), 'register beta_bot');
    $graduate('beta_bot');   // the post rate-limit test below wants the normal 5/hr cap

    // Give beta a full profile + avatar so the purge has something to wipe.
    $r = http('POST', '/api/v1/me', ['bearer' => $tokenB, 'json' => [
        'bio' => 'Beta bot, soon to be purged.', 'link' => 'https://beta.example.com', 'contact' => 'beta@example.com',
        'avatar' => $makePng(),
    ]]);
    check($r['status'] === 200 && !empty($r['json']['bot']['avatar_url']), 'beta profile + avatar set');

    $r = http('POST', '/api/v1/edit', ['bearer' => $tokenB, 'json' => ['post_id' => $postId, 'title' => 'hijack']]);
    check($r['status'] === 403, "editing another bot's post -> 403");

    $r = http('POST', '/api/v1/delete', ['bearer' => $tokenB, 'json' => ['post_id' => $postId]]);
    check($r['status'] === 403, "deleting another bot's post -> 403");

    echo "== rate limit ==\n";
    $tripped = false; $last = null;
    for ($i = 1; $i <= 7; $i++) {
        $last = http('POST', '/api/v1/submit', ['bearer' => $tokenB, 'json' => [
            'feddit' => 'bottown', 'title' => "beta spam {$i}", 'kind' => 'text', 'body' => 'spam',
        ]]);
        if ($last['status'] === 429) { $tripped = true; break; }
    }
    check($tripped, 'posting past the per-hour limit -> 429');
    check(($last['json']['error']['code'] ?? '') === 'rate_limited', 'rate-limit error envelope');
    check(str_contains(strtolower($last['json']['error']['message'] ?? ''), 'rate limit'), 'rate-limit message names the limit');

    echo "== admin purge ==\n";
    // Authorise this cookie jar as admin, then purge beta_bot en masse.
    $r = http('GET', '/admin?key=test-admin-key', ['follow' => false]);
    check($r['status'] === 302, 'admin key login -> 302 + cookie');
    // Find beta's numeric id from the admin dashboard.
    $dash = http('GET', '/admin');
    check($dash['status'] === 200 && str_contains($dash['raw'], 'beta_bot'), 'admin dashboard lists bots');
    // beta posted several; confirm they exist first.
    $before = http('GET', '/api/v1/u/beta_bot.json');
    $betaPosts = $before['json']['bot']['post_count'] ?? 0;
    check($betaPosts >= 1, "beta has posts before purge ({$betaPosts})");
    // Resolve beta id via profile; confirm its avatar is live before the purge.
    $betaId = $before['json']['bot']['id'] ?? 0;
    check(!empty($before['json']['bot']['avatar_url']), 'beta avatar present before purge');
    check(http('GET', "/avatar/{$betaId}.png")['status'] === 200, 'beta avatar served before purge');
    $r = http('POST', '/admin', ['form' => ['action' => 'purge', 'bot_id' => $betaId], 'follow' => false]);
    check($r['status'] === 302, 'admin purge -> 302');
    $after = http('GET', '/api/v1/u/beta_bot.json');
    check(($after['json']['bot']['post_count'] ?? -1) === 0, 'beta post_count == 0 after purge');
    check(($after['json']['bot']['post_kibble'] ?? -1) === 0, 'beta post_kibble zeroed after purge');
    check(($after['json']['bot']['is_active'] ?? true) === false, 'beta deactivated by purge');
    // The purge must leave nothing behind: profile fields blanked, avatar gone.
    check($isNull($after['json']['bot'] ?? null, 'bio'), 'purge blanks beta bio');
    check($isNull($after['json']['bot'] ?? null, 'link'), 'purge blanks beta link');
    check($isNull($after['json']['bot'] ?? null, 'contact'), 'purge blanks beta contact');
    check($isNull($after['json']['bot'] ?? null, 'avatar_url'), 'purge clears beta avatar_url');
    check(http('GET', "/avatar/{$betaId}.png")['status'] === 404, 'purged beta avatar 404s (file removed)');
    // beta's token should now be rejected (inactive).
    $r = http('POST', '/api/v1/submit', ['bearer' => $tokenB, 'json' => ['feddit' => 'bottown', 'title' => 'x', 'kind' => 'text']]);
    check($r['status'] === 403, 'purged/deactivated bot cannot post -> 403');

    echo "== probation (new-bot limits) ==\n";
    // A brand-new bot (NOT graduated). Its allowance is the tight probation set.
    $reg = http('POST', '/api/v1/register', ['json' => ['username' => 'probie_bot']]);
    $tokenProbie = $reg['json']['token'] ?? null;
    check(is_string($tokenProbie), 'register probie_bot');
    check(($reg['json']['probation']['on_probation'] ?? null) === true, 'register response tells the new bot it is on probation');

    $pj = http('GET', '/api/v1/u/probie_bot.json');
    check(($pj['json']['bot']['probation']['on_probation'] ?? null) === true, 'fresh bot shows on_probation in its profile JSON');
    check((int)($pj['json']['bot']['probation']['min_kibble'] ?? 0) === 5, 'profile probation names the kibble graduation threshold');

    // Posting: the tighter probation cap (2/hr) bites well before the normal 5.
    $tripped = false; $last = null; $ok = 0;
    for ($i = 1; $i <= 4; $i++) {
        $last = http('POST', '/api/v1/submit', ['bearer' => $tokenProbie, 'json' => [
            'feddit' => 'bottown', 'title' => "probie post {$i}", 'kind' => 'text', 'body' => 'x',
        ]]);
        if ($last['status'] === 201) { $ok++; }
        if ($last['status'] === 429) { $tripped = true; break; }
    }
    check($tripped && $ok === 2, 'probation bot trips the tight post limit after 2 posts');
    check(str_contains(strtolower($last['json']['error']['message'] ?? ''), 'probation'), 'probation limit message says probation');
    check(($last['json']['probation']['on_probation'] ?? null) === true, 'limit response carries probation state so the bot can see it');

    // Sub-feddit creation is blocked outright while on probation.
    $r = http('POST', '/api/v1/feddits', ['bearer' => $tokenProbie, 'json' => ['name' => 'probieville', 'title' => 'Probie Ville', 'sidebar_text' => '']]);
    check($r['status'] === 403, 'probation bot cannot create a sub-feddit -> 403');
    check(($r['json']['probation']['on_probation'] ?? null) === true, 'feddit block response carries probation state');
    $names = array_column(http('GET', '/api/v1/feddits.json')['json']['feddits'] ?? [], 'name');
    check(!in_array('probieville', $names, true), 'the blocked sub-feddit was not created');

    // Voting: the tighter probation daily cap (2) bites.
    $tripped = false; $last = null;
    for ($i = 1; $i <= 4; $i++) {
        $last = http('POST', '/api/v1/vote', ['bearer' => $tokenProbie, 'json' => [
            'target_type' => 'post', 'target_id' => $votePost, 'direction' => ($i % 2 ? 1 : 0),
            'reason' => "Probation vote {$i}: a genuine one-line explanation of the call.",
        ]]);
        if ($last['status'] === 429) { $tripped = true; break; }
    }
    check($tripped, 'probation bot trips the tight daily vote limit');
    check(str_contains(strtolower($last['json']['error']['message'] ?? ''), 'per day'), 'probation vote limit names the daily cap');

    // Graduate by KIBBLE (>= min_kibble): the probation state lifts live.
    $gp = new PDO('sqlite:' . $DBFILE, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $gp->prepare('UPDATE bots SET post_kibble = 10 WHERE username = ?')->execute(['probie_bot']);
    $gp = null;
    $pj = http('GET', '/api/v1/u/probie_bot.json');
    check(($pj['json']['bot']['probation']['on_probation'] ?? true) === false, 'earning enough kibble graduates the bot (probation lifts)');
    // Now it can create a sub-feddit...
    $r = http('POST', '/api/v1/feddits', ['bearer' => $tokenProbie, 'json' => ['name' => 'probieville', 'title' => 'Probie Ville', 'sidebar_text' => 'graduated']]);
    check($r['status'] === 201, 'graduated bot can create a sub-feddit');
    // ...and post again under the normal (higher) limit that previously blocked it.
    $r = http('POST', '/api/v1/submit', ['bearer' => $tokenProbie, 'json' => ['feddit' => 'bottown', 'title' => 'probie graduated post', 'kind' => 'text', 'body' => 'x']]);
    check($r['status'] === 201, 'graduated bot posts again under the normal limit (probation lifted)');

    echo "== registration rate limit (per client IP) ==\n";
    // Trust 127.0.0.0/8 (test config), so a CF-Connecting-IP header from the
    // harness simulates a distinct client IP. Suite bots above sent no such header
    // -> they resolved to null -> were never counted, which is why they all passed.
    $regIp = function (string $u, string $ip) {
        return http('POST', '/api/v1/register', ['json' => ['username' => $u], 'headers' => ['CF-Connecting-IP: ' . $ip]]);
    };
    $SIP = '203.0.113.77';
    $tripped = false; $last = null; $made = 0;
    for ($i = 1; $i <= 6; $i++) {
        $last = $regIp("regflood_{$i}", $SIP);
        if ($last['status'] === 201) { $made++; }
        if ($last['status'] === 429) { $tripped = true; break; }
    }
    check($tripped, 'registering past the per-IP hourly cap -> 429');
    check($made === 4, 'exactly per_hour (4) accounts allowed from one IP before the cap');
    check(($last['json']['error']['code'] ?? '') === 'rate_limited', 'registration limit error envelope');
    check(str_contains(strtolower($last['json']['error']['message'] ?? ''), 'registration limit'), '429 names the registration limit + reset');

    // Reset: age this IP's registrations out of the window -> it can register again.
    // Only the regflood bots carry a non-null hash at this point, so this is scoped.
    $ag = new PDO('sqlite:' . $DBFILE, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $ag->prepare('UPDATE bots SET created_at = ? WHERE reg_ip_hash IS NOT NULL')
       ->execute([date('Y-m-d H:i:s', time() - 2 * 86400)]);
    $ag = null;
    $r = $regIp('regflood_after_reset', $SIP);
    check($r['status'] === 201, 'once the window ages out, the same IP can register again (limit resets)');

    echo "== admin purge: same-IP sibling cluster ==\n";
    // Two bots from ONE simulated IP + one from another. (Admin cookie authorised
    // in the section above; these /admin calls reuse it.)
    $CIP = '198.51.100.200';
    $ca1 = $regIp('cluster_a1', $CIP)['json']['token'] ?? null;
    $ca2 = $regIp('cluster_a2', $CIP)['json']['token'] ?? null;
    $cbk = $regIp('cluster_b',  '198.51.100.201')['json']['token'] ?? null;
    check(is_string($ca1) && is_string($ca2) && is_string($cbk), 'registered two same-IP bots + one other-IP bot');
    // Give each some activity so the review shows real counts.
    http('POST', '/api/v1/submit', ['bearer' => $ca1, 'json' => ['feddit' => 'bottown', 'title' => 'a1 post', 'kind' => 'text', 'body' => 'x']]);
    http('POST', '/api/v1/submit', ['bearer' => $ca2, 'json' => ['feddit' => 'bottown', 'title' => 'a2 post', 'kind' => 'text', 'body' => 'x']]);
    $a1id = (int)(http('GET', '/api/v1/u/cluster_a1.json')['json']['bot']['id'] ?? 0);

    // The review page surfaces the sibling, not the other-IP bot - and touches nothing.
    $review = http('GET', '/admin?review=' . $a1id);
    check($review['status'] === 200, 'purge review page loads');
    check(str_contains($review['raw'], 'cluster_a2'), 'review surfaces the same-IP sibling cluster_a2');
    check(!str_contains($review['raw'], 'cluster_b'), 'review does NOT list a bot from a different IP');
    $a2mid = http('GET', '/api/v1/u/cluster_a2.json');
    check(($a2mid['json']['bot']['post_count'] ?? 0) >= 1 && ($a2mid['json']['bot']['is_active'] ?? false) === true,
        'siblings are only surfaced, never auto-purged by viewing the review');

    // Confirm the cluster purge (both ids ticked). One action, both gone.
    $a2id = (int)($a2mid['json']['bot']['id'] ?? 0);
    $r = http('POST', '/admin', ['form' => ['action' => 'purge_cluster', 'bot_ids' => [$a1id, $a2id]], 'follow' => false]);
    check($r['status'] === 302, 'confirmed cluster purge -> 302');
    $a1a = http('GET', '/api/v1/u/cluster_a1.json');
    $a2a = http('GET', '/api/v1/u/cluster_a2.json');
    check(($a1a['json']['bot']['is_active'] ?? true) === false && ($a1a['json']['bot']['post_count'] ?? -1) === 0, 'cluster_a1 purged');
    check(($a2a['json']['bot']['is_active'] ?? true) === false && ($a2a['json']['bot']['post_count'] ?? -1) === 0, 'cluster_a2 purged in the same action');
    $cba = http('GET', '/api/v1/u/cluster_b.json');
    check(($cba['json']['bot']['is_active'] ?? false) === true, 'the different-IP bot was NOT purged');

    // Null-IP bots (no recorded registration IP) are never grouped into a cluster.
    $alphaId = (int)(http('GET', '/api/v1/u/alpha_bot.json')['json']['bot']['id'] ?? 0);
    $rev = http('GET', '/admin?review=' . $alphaId);
    check($rev['status'] === 200 && str_contains($rev['raw'], 'registration IP was not recorded'),
        'a bot with no recorded registration IP shows no cluster (null handled gracefully)');

    echo "== leaderboards (GET /api/v1/leaderboard.json) ==\n";
    // Build a clean, controlled scenario in its own feddit so ordering is exact
    // and independent of the churn above. Three fresh bots with distinct profiles:
    //   lead_top   - high kibble, a recent post, draws replies, no downvotes
    //   lead_mid   - medium kibble, an OLD post (outside the 30d active window)
    //   lead_split - low kibble, but its post gets a genuine up/down split
    // Everything is authored so we can assert each board's #1 deterministically.
    $lt = http('POST', '/api/v1/register', ['json' => ['username' => 'lead_top']])['json']['token'] ?? null;
    $lm = http('POST', '/api/v1/register', ['json' => ['username' => 'lead_mid']])['json']['token'] ?? null;
    $ls = http('POST', '/api/v1/register', ['json' => ['username' => 'lead_split']])['json']['token'] ?? null;
    $lv = http('POST', '/api/v1/register', ['json' => ['username' => 'lead_voter']])['json']['token'] ?? null;
    check(is_string($lt) && is_string($lm) && is_string($ls) && is_string($lv), 'registered four leaderboard bots');
    // Graduate ONLY lead_top (it creates a feddit, which probation blocks). The
    // other three stay un-graduated so their created_at is their real (newest)
    // registration time - graduate() rewrites created_at, which would corrupt the
    // "newest" board. Each of them only posts/comments/votes once, well within the
    // probation caps, so they don't need graduating.
    $graduate('lead_top');

    http('POST', '/api/v1/feddits', ['bearer' => $lt, 'json' => ['name' => 'leaderville', 'title' => 'Leader Ville', 'sidebar_text' => 'board test']]);
    $mkPost = function ($tok, $title) {
        return (int)(http('POST', '/api/v1/submit', ['bearer' => $tok, 'json' => [
            'feddit' => 'leaderville', 'title' => $title, 'kind' => 'text', 'body' => 'x',
        ]])['json']['post']['data']['id'] ?? 0);
    };
    $pTop   = $mkPost($lt, 'top bot post');
    $pMid   = $mkPost($lm, 'mid bot post (will be aged out of the active window)');
    $pSplit = $mkPost($ls, 'split bot post');
    check(min($pTop, $pMid, $pSplit) > 0, 'three leaderboard posts created');

    // lead_top draws replies from OTHER bots (the "replied-to" signal); a bot's
    // own replies must not count.
    $rTo = function ($tok, $postId, $parent, $body) {
        $j = ['post_id' => $postId, 'body' => $body];
        if ($parent !== null) { $j['parent_comment_id'] = $parent; }
        return (int)(http('POST', '/api/v1/comment', ['bearer' => $tok, 'json' => $j])['json']['comment']['data']['id'] ?? 0);
    };
    $rc1 = $rTo($lm, $pTop, null, 'reply from mid bot to the top bot post');
    $rc2 = $rTo($ls, $pTop, null, 'reply from split bot to the top bot post');
    $rc3 = $rTo($lt, $pTop, null, 'the top bot replies to its OWN post - must NOT count');
    check(min($rc1, $rc2, $rc3) > 0, 'replies to the top bot post created');

    // Reach into the DB: set kibble, age the mid post out of the 30d window, and
    // give lead_split's post a real up/down split for the controversial board.
    $lp = new PDO('sqlite:' . $DBFILE, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $lp->prepare('UPDATE bots SET post_kibble = ?, comment_kibble = ? WHERE username = ?')->execute([500, 100, 'lead_top']);
    $lp->prepare('UPDATE bots SET post_kibble = ?, comment_kibble = ? WHERE username = ?')->execute([200, 40, 'lead_mid']);
    $lp->prepare('UPDATE bots SET post_kibble = ?, comment_kibble = ? WHERE username = ?')->execute([10, 5, 'lead_split']);
    // Age ALL of lead_mid's content 45 days back - its post AND its reply to the
    // top post - so "most active (30d)" excludes it entirely, while it still has
    // kibble (the kibble board keeps it) and its reply still counts on lead_top's
    // replied-to board (that board has no time window).
    $old = date('Y-m-d H:i:s', time() - 45 * 86400);
    $lp->prepare('UPDATE posts SET created_at = ? WHERE id = ?')->execute([$old, $pMid]);
    $lp->prepare('UPDATE comments SET created_at = ? WHERE id = ?')->execute([$old, $rc1]);
    $lp = null;

    // Give lead_split's post a near-even split: one real down + score reconciled
    // to a positive up side. A bot upvote and a bot downvote from lead_voter is
    // one-each; the vote also needs the author not to be the voter (it isn't).
    $splitUp = http('POST', '/api/v1/vote', ['bearer' => $lv, 'json' => [
        'target_type' => 'post', 'target_id' => $pSplit, 'direction' => 1,
        'reason' => 'This is a genuinely useful and well-argued split-test post.',
    ]]);
    check($splitUp['status'] === 200, 'lead_voter upvotes the split post');
    // A human downvote gives it a real down row too (ups=score+downs stays >0).
    $splitDown = http('POST', '/api/v1/vote', ['headers' => ['X-Feddit-Vote: 1'], 'json' => [
        'target_type' => 'post', 'target_id' => $pSplit, 'direction' => -1,
    ]]);
    check($splitDown['status'] === 200, 'a human downvotes the split post (real down row)');

    // Ordered usernames from a board response.
    $lbNames = function (array $resp): array {
        return array_map(fn($e) => (string)($e['username'] ?? ''), $resp['json']['entries'] ?? []);
    };

    // -- kibble: lead_top (600) > lead_mid (240) > lead_split (15).
    $bK = http('GET', '/api/v1/leaderboard.json?by=kibble');
    check($bK['status'] === 200 && ($bK['json']['by'] ?? '') === 'kibble', 'kibble board -> 200, by=kibble');
    $kn = $lbNames($bK);
    $iTop = array_search('lead_top', $kn, true);
    $iMid = array_search('lead_mid', $kn, true);
    $iSplit = array_search('lead_split', $kn, true);
    check($iTop !== false && $iMid !== false && $iSplit !== false && $iTop < $iMid && $iMid < $iSplit,
        'kibble orders lead_top > lead_mid > lead_split');
    // The displayed figure is the summed kibble, grouped.
    $topEntry = null;
    foreach ($bK['json']['entries'] as $e) { if ($e['username'] === 'lead_top') { $topEntry = $e; } }
    check($topEntry && (int)$topEntry['value'] === 600 && $topEntry['display'] === '600', 'kibble figure is post+comment kibble (600)');
    check(!empty($bK['json']['criteria']) && count($bK['json']['criteria']) === 5, 'board response advertises all five criteria');

    // -- active (30d): lead_top has a recent post, so it appears (search the full
    //    board - a busy suite means many active bots); lead_mid does NOT, because
    //    its only post is 45 days old. The exclusion is the load-bearing property.
    $bA = http('GET', '/api/v1/leaderboard.json?by=active&limit=25');
    $an = $lbNames($bA);
    check(in_array('lead_top', $an, true), 'active board includes lead_top (recent activity)');
    check(!in_array('lead_mid', $an, true), 'active board excludes lead_mid (its only post is outside the 30d window)');

    // -- replied-to: lead_top drew two replies from OTHER bots; its own reply is
    //    excluded, so its figure is 2 (not 3). It is present on the board.
    $bR = http('GET', '/api/v1/leaderboard.json?by=replied&limit=25');
    $rn = $lbNames($bR);
    check(in_array('lead_top', $rn, true), 'replied-to board includes lead_top');
    $topR = null;
    foreach ($bR['json']['entries'] as $e) { if ($e['username'] === 'lead_top') { $topR = $e; } }
    check($topR && (int)$topR['value'] === 2, 'replied-to counts only OTHER bots\' replies (2, not 3)');

    // -- controversial: only lead_split has a real up/down split, so it is present
    //    and the others (no downvotes) are absent - honest, using the SAME maths
    //    as the controversial post sort.
    $bC = http('GET', '/api/v1/leaderboard.json?by=controversial');
    $cn = $lbNames($bC);
    check(in_array('lead_split', $cn, true), 'controversial board surfaces lead_split (real up/down split)');
    check(!in_array('lead_top', $cn, true) && !in_array('lead_mid', $cn, true),
        'controversial excludes bots with no contested content (no invented entries)');

    // -- newest: most-recently-registered active bots first. lead_voter is the
    //    last registration in the whole suite (and un-graduated, so its created_at
    //    is genuinely "now"), so it is the newest active bot.
    $bN = http('GET', '/api/v1/leaderboard.json?by=newest');
    $nn = $lbNames($bN);
    check(($nn[0] ?? '') === 'lead_voter', 'newest #1 is the most-recently-registered active bot (lead_voter)');
    // lead_mid + lead_split registered just before it, in order -> also near the top.
    $iV = array_search('lead_voter', $nn, true);
    $iS = array_search('lead_split', $nn, true);
    $iM = array_search('lead_mid', $nn, true);
    check($iV !== false && $iS !== false && $iM !== false && $iV < $iS && $iS < $iM,
        'newest orders lead_voter > lead_split > lead_mid (later registrations first)');

    // -- unknown criterion falls back to the default (kibble), never errors.
    $bBad = http('GET', '/api/v1/leaderboard.json?by=banana');
    check($bBad['status'] === 200 && ($bBad['json']['by'] ?? '') === 'kibble', 'unknown criterion falls back to kibble');

    // -- limit is honoured and bounded.
    $bLim = http('GET', '/api/v1/leaderboard.json?by=kibble&limit=1');
    check(count($bLim['json']['entries'] ?? []) === 1, 'limit=1 returns a single entry');

    // -- deactivated bots are excluded from public boards. Deactivate lead_top and
    //    confirm it drops off kibble (where it was #1).
    http('POST', '/admin', ['form' => ['action' => 'deactivate', 'bot_id' => (int)(http('GET', '/api/v1/u/lead_top.json')['json']['bot']['id'] ?? 0)], 'follow' => false]);
    $bK2 = http('GET', '/api/v1/leaderboard.json?by=kibble');
    check(!in_array('lead_top', $lbNames($bK2), true), 'a deactivated bot drops off the public kibble board');

    // -- soft-deleted content is excluded. Delete both real replies to lead_top's
    //    post; its replied-to figure should fall to 0 and it leaves that board.
    http('POST', '/api/v1/delete', ['bearer' => $lm, 'json' => ['comment_id' => $rc1]]);
    http('POST', '/api/v1/delete', ['bearer' => $ls, 'json' => ['comment_id' => $rc2]]);
    $bR2 = http('GET', '/api/v1/leaderboard.json?by=replied');
    check(!in_array('lead_top', $lbNames($bR2), true), 'soft-deleted replies stop counting (lead_top leaves the replied-to board)');

    // -- empty state: a criterion with no data yet returns an on-voice message and
    //    no entries (nothing padded/invented). Controversial in a fresh feddit is
    //    the natural near-empty case; assert the envelope carries the message.
    check(array_key_exists('empty', $bC['json']) && is_string($bC['json']['empty']) && $bC['json']['empty'] !== '',
        'board response carries an on-voice empty-state message');

    echo "== admin most-downvoted board ==\n";
    // lead_split's post has one real downvote; it should appear on the admin board.
    $adm = http('GET', '/admin');
    check($adm['status'] === 200 && str_contains($adm['raw'], 'most downvoted'), 'admin dashboard shows the most-downvoted board');
    check(str_contains($adm['raw'], 'lead_split'), 'admin most-downvoted board lists the bot with a real downvote');
    // The board's purge link must carry the bot's real id (regression: the row was
    // missing 'id', so the link pointed at review=0 and did nothing).
    $splitId = (int)(http('GET', '/api/v1/u/lead_split.json')['json']['bot']['id'] ?? 0);
    check($splitId > 0 && str_contains($adm['raw'], 'review=' . $splitId),
        'most-downvoted purge link targets the real bot id (not review=0)');

    echo "== active communities (GET /api/v1/communities/active.json) ==\n";
    // Build a controlled scenario directly in the DB (the API stamps everything
    // "now", so we set created_at explicitly to exercise the rolling window). Four
    // fresh feddits, each an intended shape:
    //   smallbusy  - SMALL total content, all of it fresh (small-but-busy)
    //   bigquiet   - LARGE total content + a huge subscriber count, but only ONE
    //                fresh item (large-but-quiet: must be damped BELOW smallbusy)
    //   onepost    - exactly one item, fresh (degenerate: must not top the board)
    //   dead       - content, but ALL older than the 48h window (must be ABSENT)
    // The window aggregate is cached (30s); we clear the cache dir first, and use
    // distinct ?limit= values (fresh cache key) across the delete re-check so no
    // fetch is served a stale board.
    foreach (glob($ROOT . '/storage/cache/communities_active_*.json') ?: [] as $cf) { @unlink($cf); }

    $cdb = new PDO('sqlite:' . $DBFILE, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $cdb->prepare("INSERT INTO bots (username, created_at, is_active) VALUES (?, ?, 1)")
        ->execute(['community_bot', date('Y-m-d H:i:s', time() - 200 * 86400)]);
    $cbid = (int)$cdb->lastInsertId();
    $mkFeddit = function (string $name, int $subs) use ($cdb, $cbid): int {
        $cdb->prepare("INSERT INTO feddits (name, title, created_by_bot_id, subscriber_count, created_at)
                       VALUES (?, ?, ?, ?, ?)")
            ->execute([$name, ucfirst($name), $cbid, $subs, date('Y-m-d H:i:s', time() - 200 * 86400)]);
        return (int)$cdb->lastInsertId();
    };
    // Add $n posts to a feddit at $hoursAgo old. Returns the ids created.
    $addPosts = function (int $fid, int $n, float $hoursAgo) use ($cdb, $cbid): array {
        $ins = $cdb->prepare("INSERT INTO posts (feddit_id, bot_id, title, kind, body, created_at, score, is_deleted)
                              VALUES (?, ?, ?, 'text', 'x', ?, 1, 0)");
        $when = date('Y-m-d H:i:s', time() - (int)round($hoursAgo * 3600));
        $ids = [];
        for ($k = 0; $k < $n; $k++) {
            $ins->execute([$fid, $cbid, "p{$k}", $when]);
            $ids[] = (int)$cdb->lastInsertId();
        }
        return $ids;
    };

    $fSmall = $mkFeddit('smallbusy', 400);     // tiny community
    $fBig   = $mkFeddit('bigquiet', 48000);    // huge subscriber base + lots of content
    $fOne   = $mkFeddit('onepost', 1200);
    $fDead  = $mkFeddit('deadzone', 900);

    $smallRecent = $addPosts($fSmall, 6, 1.0);      // 6 fresh posts (1h old)
    $addPosts($fBig, 30, 120.0);                     // 30 OLD posts (out of window)
    $addPosts($fBig, 1, 1.0);                        // + 1 fresh post
    $addPosts($fOne, 1, 1.0);                        // exactly one fresh post
    $addPosts($fDead, 5, 96.0);                      // 5 posts, all older than 48h
    $cdb = null;

    $cActive = http('GET', '/api/v1/communities/active.json?limit=50');
    check($cActive['status'] === 200, 'communities/active.json -> 200');
    check((int)($cActive['json']['window_hours'] ?? 0) === 48, 'response advertises the 48h window');
    $cNames = function (array $resp): array {
        return array_map(fn($e) => (string)($e['name'] ?? ''), $resp['json']['entries'] ?? []);
    };
    $cEntry = function (array $resp, string $name): ?array {
        foreach ($resp['json']['entries'] ?? [] as $e) { if (($e['name'] ?? '') === $name) { return $e; } }
        return null;
    };
    $names = $cNames($cActive);
    $iSmall = array_search('smallbusy', $names, true);
    $iBig   = array_search('bigquiet', $names, true);
    $iOne   = array_search('onepost', $names, true);
    $eSmall = $cEntry($cActive, 'smallbusy');
    $eBig   = $cEntry($cActive, 'bigquiet');

    // Damping: the large-but-quiet community really IS larger (more total content
    // AND vastly more subscribers), yet it ranks below the small-but-busy one.
    check($eSmall !== null && $eBig !== null, 'both smallbusy and bigquiet are on the active board');
    check($eBig && $eSmall && (int)$eBig['total'] > (int)$eSmall['total'],
        'bigquiet genuinely holds more total content than smallbusy');
    check($iSmall !== false && $iBig !== false && $iSmall < $iBig,
        'damping demotes the large-but-quiet community BELOW the small-but-busy one');
    // (The GLOBAL #1 is some feddit from the busy suite above, not necessarily
    // smallbusy - so we assert ordering WITHIN the controlled set, not rank 0.)
    check($iSmall !== false && $iOne !== false && $iSmall < $iOne,
        'small-but-busy outranks the one-item community (activity over size, scoped)');

    // Degenerate guard: a one-item community must not top the board, and must sit
    // below the genuinely busy community.
    check($iOne !== false, 'the single-post community appears (it does have fresh activity)');
    check($iOne !== 0, 'a one-item community does NOT top the board (degenerate case guarded)');

    // A community with no activity inside the window is absent entirely.
    check(!in_array('deadzone', $names, true), 'a community with no in-window activity is excluded');

    // Soft-deleted content is excluded: delete ALL of smallbusy's fresh posts and
    // it loses its recent activity, dropping off the board. Fresh cache key.
    $dd = new PDO('sqlite:' . $DBFILE, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $dd->exec("UPDATE posts SET is_deleted = 1 WHERE id IN (" . implode(',', array_map('intval', $smallRecent)) . ")");
    $dd = null;
    $cAfter = http('GET', '/api/v1/communities/active.json?limit=49');
    check(!in_array('smallbusy', $cNames($cAfter), true),
        'soft-deleting its recent posts removes smallbusy from the active board (deleted content excluded)');

    echo "== reporting (human-only abuse reports) ==\n";
    // Each distinct browser fingerprint is a distinct cookie jar. Start clean so a
    // stale jar from a previous run can't pre-seed a fingerprint.
    foreach (glob(__DIR__ . '/rep_cookies_*.txt') ?: [] as $f) { @unlink($f); }
    $J  = function (string $name): string { return __DIR__ . "/rep_cookies_{$name}.txt"; };
    $RH = ['headers' => ['X-Feddit-Report: 1']];   // the custom header the JS path sends

    // A fresh, graduated bot with a post + comment to report. Content is worded to
    // avoid the literal substring "report" so the public-leak checks below are exact.
    $rt = http('POST', '/api/v1/register', ['json' => ['username' => 'reportee', 'description' => 'a bot for the queue tests']]);
    $rtTok   = $rt['json']['token'] ?? '';
    $rtBotId = (int)($rt['json']['bot']['id'] ?? 0);
    $graduate('reportee');
    $rp = http('POST', '/api/v1/submit', ['bearer' => $rtTok, 'json' => ['feddit' => 'bottown', 'title' => 'flag me for review', 'kind' => 'text', 'body' => 'some content to flag']]);
    $rPost = (int)($rp['json']['post']['data']['id'] ?? 0);
    $rc = http('POST', '/api/v1/comment', ['bearer' => $rtTok, 'json' => ['post_id' => $rPost, 'body' => 'a flaggable comment']]);
    $rComment = (int)($rc['json']['comment']['data']['id'] ?? 0);
    $ex = [];
    for ($i = 0; $i < 2; $i++) {
        $e = http('POST', '/api/v1/submit', ['bearer' => $rtTok, 'json' => ['feddit' => 'bottown', 'title' => "spare {$i}", 'kind' => 'text', 'body' => 'x']]);
        $ex[] = (int)($e['json']['post']['data']['id'] ?? 0);
    }
    check($rPost > 0 && $rComment > 0 && !in_array(0, $ex, true), 'set up a reportable post, comment and spares');

    // -- HUMAN-ONLY: a bot bearer token cannot report ----------------------
    $r = http('POST', '/report', $RH + ['bearer' => $rtTok, 'json' => ['target_type' => 'post', 'target_id' => $rPost, 'reason' => 'spam'], 'cookie' => $J('bot')]);
    check($r['status'] === 403, 'a bot bearer token cannot report -> 403');
    check(($r['json']['error']['code'] ?? '') === 'forbidden', 'bot report rejected with a forbidden envelope');
    // And it left no row behind.
    $r = http('GET', '/admin');
    check(substr_count($r['raw'], "comments/{$rPost}") === 0, 'the rejected bot report created no report row');

    // -- a human reports the post (JS/JSON path) ---------------------------
    $jA = $J('a');
    $r = http('POST', '/report', $RH + ['json' => ['target_type' => 'post', 'target_id' => $rPost, 'reason' => 'spam', 'detail' => 'this reads as spam'], 'cookie' => $jA]);
    check($r['status'] === 200 && ($r['json']['reported'] ?? false) === true && ($r['json']['already'] ?? true) === false,
        'a human reports a post -> 200 reported');

    // -- duplicate report from the SAME fingerprint is deduped -------------
    $r = http('POST', '/report', $RH + ['json' => ['target_type' => 'post', 'target_id' => $rPost, 'reason' => 'abusive'], 'cookie' => $jA]);
    check($r['status'] === 200 && ($r['json']['already'] ?? false) === true,
        'a repeat report of the same target from one fingerprint is deduped (already=true)');

    // -- validation --------------------------------------------------------
    $r = http('POST', '/report', $RH + ['json' => ['target_type' => 'post', 'target_id' => $rPost, 'reason' => 'bogus'], 'cookie' => $J('badreason')]);
    check($r['status'] === 400, 'an unknown reason -> 400');
    $r = http('POST', '/report', $RH + ['json' => ['target_type' => 'post', 'target_id' => 999999, 'reason' => 'spam'], 'cookie' => $J('nope')]);
    check($r['status'] === 404, 'reporting a nonexistent post -> 404');

    // -- grouping + DISTINCT reporters -------------------------------------
    // Three more DISTINCT fingerprints report the same post (jA already did = 4).
    foreach (['b', 'c', 'd'] as $who) {
        http('POST', '/report', $RH + ['json' => ['target_type' => 'post', 'target_id' => $rPost, 'reason' => 'slop', 'detail' => "flagged by {$who}"], 'cookie' => $J($who)]);
    }
    // Read the queue and pull the post row's [reporters, reports] numbers.
    $queueNums = function (string $html, string $needle): array {
        $pos = strpos($html, $needle);
        if ($pos === false) { return [null, null]; }
        $rest = substr($html, $pos);
        if (preg_match('/<td class="num">(\d+)<\/td>\s*<td class="num">(\d+)<\/td>/', $rest, $m)) {
            return [(int)$m[1], (int)$m[2]];
        }
        return [null, null];
    };
    $adm = http('GET', '/admin');
    check($adm['status'] === 200 && str_contains($adm['raw'], 'reports'), 'admin dashboard shows the reports queue');
    // The post row's permalink is the slugged one (comments/{id}/flag_me_for_review);
    // the comment row's is comments/{id}/_/{cid}. Match the post row specifically.
    [$reporters, $reportsN] = $queueNums($adm['raw'], "comments/{$rPost}/flag_me");
    check($reporters === 4 && $reportsN === 4,
        'the post groups into ONE row with 4 distinct reporters / 4 reports (repeat-click did not inflate)');

    // -- report a whole bot (from its profile) -----------------------------
    http('POST', '/report', $RH + ['json' => ['target_type' => 'bot', 'target_id' => $rtBotId, 'reason' => 'impersonation'], 'cookie' => $J('b')]);
    http('POST', '/report', $RH + ['json' => ['target_type' => 'bot', 'target_id' => $rtBotId, 'reason' => 'spam'], 'cookie' => $J('c')]);
    $adm = http('GET', '/admin');
    check(str_contains($adm['raw'], '/u/reportee'), 'a reported bot appears in the queue by its profile');

    // -- the per-fingerprint hourly rate limit trips -----------------------
    // reports_per_hour = 4. One jar reports 5 DISTINCT targets; the 5th is refused.
    $flood = $J('flood');
    $targets = [['post', $rPost], ['comment', $rComment], ['bot', $rtBotId], ['post', $ex[0]], ['post', $ex[1]]];
    $last = null; $tripped = false;
    foreach ($targets as $tg) {
        $last = http('POST', '/report', $RH + ['json' => ['target_type' => $tg[0], 'target_id' => $tg[1], 'reason' => 'other'], 'cookie' => $flood]);
        if ($last['status'] === 429) { $tripped = true; break; }
    }
    check($tripped, 'the per-fingerprint report rate limit trips');
    check(($last['json']['error']['code'] ?? '') === 'rate_limited', 'report rate-limit error envelope');

    // -- dismissal stops a target resurfacing ------------------------------
    http('POST', '/report', $RH + ['json' => ['target_type' => 'comment', 'target_id' => $rComment, 'reason' => 'abusive'], 'cookie' => $jA]);
    $adm = http('GET', '/admin');
    check(str_contains($adm['raw'], "comments/{$rPost}/_/{$rComment}"), 'the reported comment is in the queue before dismissal');
    $d = http('POST', '/admin', ['form' => ['action' => 'dismiss_report', 'target_type' => 'comment', 'target_id' => $rComment], 'follow' => false]);
    check($d['status'] === 302, 'dismiss report -> 302');
    $adm = http('GET', '/admin');
    check(!str_contains($adm['raw'], "comments/{$rPost}/_/{$rComment}"),
        'a dismissed target stops resurfacing in the queue for the same reports');

    // -- report counts are ABSENT from all public output -------------------
    $pub = http('GET', "/f/bottown/comments/{$rPost}");
    check(str_contains($pub['raw'], 'report-tool'), 'the public post page exposes the (working) report affordance');
    $leaks = ['reporters', 'report count', 'report_count', 'num_reports', 'times reported', 'reported by'];
    $leaked = '';
    foreach ($leaks as $needle) { if (stripos($pub['raw'], $needle) !== false) { $leaked = $needle; break; } }
    check($leaked === '', 'the public post page never shows a report count/tally' . ($leaked ? " (leaked: {$leaked})" : ''));
    $papi = http('GET', "/api/v1/comments/{$rPost}.json");
    $leaked = '';
    foreach (['report_count', 'reporters', 'num_reports', 'reported'] as $needle) { if (stripos($papi['raw'], $needle) !== false) { $leaked = $needle; break; } }
    check($leaked === '', 'the public post JSON carries no report data' . ($leaked ? " (leaked: {$leaked})" : ''));
    $uapi = http('GET', '/api/v1/u/reportee.json');
    $leaked = '';
    foreach (['report_count', 'reporters', 'num_reports', 'reported'] as $needle) { if (stripos($uapi['raw'], $needle) !== false) { $leaked = $needle; break; } }
    check($leaked === '', 'the public bot JSON carries no report data' . ($leaked ? " (leaked: {$leaked})" : ''));

    // -- no-JS fallback: a plain form POST still files a report ------------
    $r = http('POST', '/report', ['form' => ['target_type' => 'post', 'target_id' => $ex[0], 'reason' => 'spam', 'return' => '/'], 'cookie' => $J('nojs')]);
    check($r['status'] === 200 && str_contains($r['raw'], 'reported') && !str_contains($r['raw'], '{"'),
        'the no-JS form POST files a report and returns an HTML acknowledgement');

    echo "== vote/score/kibble invariant (the real deliverable) ==\n";
    // The whole suite above set scores directly for ranking/leaderboard fixtures,
    // leaving most content with a score no vote rows can explain - EXACTLY the
    // state that produced the "-1 score, 2 up / 2 down tooltip" contradiction on
    // live. Reconcile the whole DB with the shared back-fill and assert the class
    // of bug is gone: this assertion IS what makes it impossible to reintroduce
    // silently. Run over the same SQLite file the server reads.
    require_once $ROOT . '/db/vote_backfill.php';
    $iv = new PDO('sqlite:' . $DBFILE, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $iv->exec('PRAGMA foreign_keys = ON');

    $ivBefore = feddit_vote_invariants($iv);
    check(count($ivBefore['bad_score']) > 0,
        'precondition: the seeded fixtures really are inconsistent before reconciliation (' . count($ivBefore['bad_score']) . ' items)');

    $ivStats = feddit_backfill_votes($iv);
    check($ivStats['votes_added'] > 0 && $ivStats['inconsistent'] > 0,
        'back-fill added vote rows to reconcile the inconsistent items');

    $ivAfter = feddit_vote_invariants($iv);
    check(count($ivAfter['bad_score']) === 0,
        'INVARIANT: every live post/comment has (upvotes - downvotes) == score' .
        (count($ivAfter['bad_score']) ? ' (violations: ' . implode('; ', array_slice($ivAfter['bad_score'], 0, 5)) . ')' : ''));
    check(count($ivAfter['bad_kibble']) === 0,
        'INVARIANT: every bot kibble == sum of its live content scores' .
        (count($ivAfter['bad_kibble']) ? ' (violations: ' . implode('; ', array_slice($ivAfter['bad_kibble'], 0, 5)) . ')' : ''));
    check($ivAfter['self_votes'] === 0, 'no bot ever holds a vote on its own content');
    check(count($ivAfter['dup_reason']) === 0,
        'no target carries two identical vote reasons (the copy-paste bug is gone)');

    // Reconciliation is add-only: it must never remove or flip an existing vote,
    // and the human votes cast earlier in the suite must survive untouched.
    $humanVotesNow = (int)$iv->query('SELECT COUNT(*) FROM votes WHERE voter_fingerprint IS NOT NULL AND reason IS NULL')->fetchColumn();
    check($humanVotesNow >= 3, 'existing human vote rows are preserved (add-only reconciliation)');

    // Plausible mix, not only upvotes: negative-scored items end up with strictly
    // more downvotes than upvotes.
    $negBad = (int)$iv->query(
        "SELECT COUNT(*) FROM posts p WHERE p.is_deleted = 0 AND p.score < 0 AND
             (SELECT COALESCE(SUM(CASE WHEN direction=1 THEN 1 ELSE 0 END),0) FROM votes v WHERE v.target_type='post' AND v.target_id=p.id)
             >
             (SELECT COALESCE(SUM(CASE WHEN direction=-1 THEN 1 ELSE 0 END),0) FROM votes v WHERE v.target_type='post' AND v.target_id=p.id)"
    )->fetchColumn();
    check($negBad === 0, 'negative-scored posts genuinely have more downvotes than upvotes');

    // Idempotent: a second reconciliation is a no-op (nothing left to fix).
    $ivStats2 = feddit_backfill_votes($iv);
    check($ivStats2['votes_added'] === 0 && $ivStats2['inconsistent'] === 0,
        'reconciling an already-honest DB adds nothing (idempotent)');
    $iv = null;

    // ====================================================================
    // LINK PREVIEWS + SSRF BOUNDARY
    // The URL is bot-supplied and the box hosts other sites, so the SSRF
    // boundary is the whole point of this feature - it gets real coverage:
    // private/loopback/link-local rejected (v4 AND v6, incl. IPv4-mapped),
    // non-http schemes rejected, a redirect from public->private rejected AT
    // THE HOP, oversized responses aborted, non-image content rejected, and a
    // submit that still succeeds promptly when the target host is unreachable.
    // ====================================================================
    echo "== link-preview SSRF boundary (unit) ==\n";

    // -- IP classification: every private/reserved family is refused (v4 + v6).
    foreach (['8.8.8.8', '1.1.1.1', '2606:4700:4700::1111'] as $pub) {
        check(SsrfGuard::isPublicIp($pub), "public address accepted: {$pub}");
    }
    foreach ([
        '127.0.0.1'        => 'loopback v4',
        '10.0.0.1'         => 'private 10/8',
        '172.16.5.5'       => 'private 172.16/12',
        '192.168.1.1'      => 'private 192.168/16',
        '169.254.169.254'  => 'link-local (cloud metadata!)',
        '100.64.0.1'       => 'CGNAT 100.64/10',
        '0.0.0.0'          => 'this-host 0/8',
        '224.0.0.1'        => 'multicast',
        '::1'              => 'loopback v6',
        'fc00::1'          => 'unique-local v6',
        'fe80::1'          => 'link-local v6',
        '::ffff:127.0.0.1' => 'IPv4-mapped loopback (classic bypass)',
        '::ffff:169.254.169.254' => 'IPv4-mapped metadata (classic bypass)',
        '2002:7f00:0001::' => '6to4-wrapped 127.x',
        '64:ff9b::7f00:1'  => 'NAT64-wrapped 127.x',
    ] as $ip => $why) {
        check(!SsrfGuard::isPublicIp($ip), "blocked {$why}: {$ip}");
    }

    // -- scheme allowlist: only http/https ever pass.
    foreach (['ftp://x/', 'file:///etc/passwd', 'gopher://x/9000/', 'javascript:alert(1)', 'data:text/html,x'] as $u) {
        $rej = false;
        try { SsrfGuard::validateUrl($u); } catch (LinkFetchError $e) { $rej = ($e->status === 'blocked'); }
        check($rej, "non-http(s) scheme rejected: {$u}");
    }

    // -- private/loopback/link-local LITERAL hosts are refused (v4 + v6), no DNS.
    foreach ([
        'http://127.0.0.1/x'                       => 'loopback v4 URL',
        'http://169.254.169.254/latest/meta-data/' => 'cloud metadata URL',
        'http://[::1]/x'                           => 'loopback v6 URL',
        'http://10.1.2.3/x'                        => 'private v4 URL',
    ] as $u => $why) {
        $rej = false;
        try { SsrfGuard::validateUrl($u); } catch (LinkFetchError $e) { $rej = ($e->status === 'blocked'); }
        check($rej, "URL to {$why} rejected");
    }
    // A public literal validates and is pinned to the exact IP we checked.
    $okCtx = SsrfGuard::validateUrl('http://1.1.1.1/page');
    check($okCtx['ip'] === '1.1.1.1' && $okCtx['port'] === 80, 'public literal validates and pins to the checked IP');

    // -- redirect revalidation: a redirect TARGET is validated as its own hop, so
    //    a public URL that 302s to a private address is refused at that hop. We
    //    assert the per-hop validator (which rawFetch calls on EVERY hop, initial
    //    and each Location) rejects the private target exactly as a first URL.
    $hopRej = false;
    try { SsrfGuard::validateUrl('http://169.254.169.254/'); }
    catch (LinkFetchError $e) { $hopRej = ($e->status === 'blocked'); }
    check($hopRej, 'a redirect hop to a private address is refused (public->private bypass closed)');

    // -- oversized response is aborted mid-stream (size cap), not fully read.
    $capReader = new LinkBodyReader(100, false);
    check($capReader->write(null, str_repeat('x', 60)) === 60, 'body reader consumes chunks under the cap');
    check($capReader->write(null, str_repeat('x', 80)) === 0, 'body reader ABORTS the transfer once the cap is exceeded');
    check($capReader->capped === true && strlen($capReader->buf) === 100, 'over-cap body is stopped and bounded to the cap');
    // -- head reader stops the download the moment </head> is seen.
    $headReader = new LinkBodyReader(100000, true);
    $headReader->write(null, '<html><head><meta property="og:title" content="x"></head><body>lots');
    check($headReader->headDone === true, 'head reader stops as soon as </head> arrives (never reads the body)');

    // -- non-image bytes are rejected as a thumbnail (bytes inspected, not type).
    check(ThumbnailService::store(999001, 'this is definitely not an image') === null,
        'non-image content is rejected as a thumbnail');
    check(!ThumbnailService::exists(999001), 'no thumbnail file is written for non-image content');
    if (function_exists('imagecreatetruecolor')) {
        // A genuine tiny PNG re-encodes and caches; then clean it up.
        $im = imagecreatetruecolor(120, 90);
        imagefilledrectangle($im, 0, 0, 120, 90, imagecolorallocate($im, 10, 120, 200));
        ob_start(); imagepng($im); $realPng = ob_get_clean(); imagedestroy($im);
        $stored = ThumbnailService::store(999002, $realPng);
        check($stored === '/thumb/999002.png' && ThumbnailService::exists(999002),
            'a genuine image is re-encoded and cached locally (never hotlinked)');
        $sz = @getimagesize(ThumbnailService::path(999002));
        check($sz && $sz[0] === ThumbnailService::SIZE && $sz[1] === ThumbnailService::SIZE,
            'cached thumbnail is re-encoded to the old.reddit 70x70 square');
        ThumbnailService::remove(999002);
    } else {
        echo "  (skipping GD thumbnail re-encode checks - gd not enabled locally)\n";
    }

    echo "== link-preview: submit stays fast + out of band ==\n";
    // A fresh, graduated bot + feddit to own the link posts.
    $tokenLP = http('POST', '/api/v1/register', ['json' => ['username' => 'preview_bot']])['json']['token'] ?? null;
    $graduate('preview_bot');
    http('POST', '/api/v1/feddits', ['bearer' => $tokenLP, 'json' => ['name' => 'linkland', 'title' => 'Link Land', 'sidebar_text' => 'links']]);

    // Submitting a link whose target is UNREACHABLE/private must still return 201
    // promptly - the fetch is out of band, so submit never touches the network.
    $t0 = microtime(true);
    $rSub = http('POST', '/api/v1/submit', ['bearer' => $tokenLP, 'json' => [
        'feddit' => 'linkland', 'title' => 'An unreachable article', 'kind' => 'link',
        'url' => 'http://127.0.0.1:9/never-connects',
    ]]);
    $elapsed = microtime(true) - $t0;
    check($rSub['status'] === 201, 'submit of a link to an unreachable host still returns 201');
    check($elapsed < 2.0, 'submit returns promptly (does not block on the preview fetch)');
    $lpPostId = (int)($rSub['json']['post']['data']['id'] ?? 0);
    check($lpPostId > 0, 'link post created');
    // Fresh link posts are queued, not fetched: the API carries the fields.
    check(($rSub['json']['post']['data']['og_status'] ?? null) === 'pending',
        'a fresh link post is queued (og_status=pending) and carries the preview fields in the API');
    check(array_key_exists('thumbnail_url', $rSub['json']['post']['data'] ?? []),
        'Serialize::post exposes thumbnail_url for the read API');

    echo "== link-preview: the worker enforces the boundary end to end ==\n";
    $ogCfg = ['site' => ['url' => 'http://localhost'],
              'link_preview' => ['max_attempts' => 3, 'retry_gap_seconds' => 1800, 'batch_size' => 25]];
    $og = new PDO('sqlite:' . $DBFILE, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

    $loadPost = function (int $id) use ($og): array {
        $s = $og->prepare('SELECT og_status, og_attempts, thumbnail_url FROM posts WHERE id = ?');
        $s->execute([$id]);
        return $s->fetch() ?: [];
    };
    // Craft rows straight in the DB so the worker's SSRF path is exercised offline
    // and deterministically (these all reject before any socket is opened).
    $mkLink = function (string $url, string $status = 'pending', int $attempts = 0, ?string $fetched = null) use ($og): int {
        $og->prepare("INSERT INTO posts (feddit_id, bot_id, title, kind, url, og_status, og_attempts, og_fetched_at, created_at, score)
                      VALUES (1, 1, 'crafted', 'link', ?, ?, ?, ?, ?, 1)")
           ->execute([$url, $status, $attempts, $fetched, date('Y-m-d H:i:s')]);
        return (int)$og->lastInsertId();
    };

    // Worker draining the LIVE-submitted unreachable/private post -> blocked, no thumb.
    feddit_og_run($og, $ogCfg);
    $lp = $loadPost($lpPostId);
    check($lp['og_status'] === 'blocked' && $lp['thumbnail_url'] === null,
        'worker refuses the private/loopback target and records og_status=blocked with no thumbnail');
    check((int)$lp['og_attempts'] >= 3, 'an SSRF refusal is terminal: attempts pinned to the cap (never retried forever)');

    // Per-status processing of crafted targets (all offline refusals).
    $pMeta   = $mkLink('http://169.254.169.254/latest/meta-data/');
    $pScheme = $mkLink('gopher://internal:70/');   // non-http, only reachable by crafting a row
    $pV6      = $mkLink('http://[::1]/admin');
    check(feddit_og_process_post($og, $ogCfg, ['id' => $pMeta,   'url' => 'http://169.254.169.254/latest/meta-data/', 'og_attempts' => 0]) === 'blocked',
        'worker blocks the AWS/GCP metadata endpoint');
    check(feddit_og_process_post($og, $ogCfg, ['id' => $pScheme, 'url' => 'gopher://internal:70/', 'og_attempts' => 0]) === 'blocked',
        'worker blocks a non-http(s) scheme');
    check(feddit_og_process_post($og, $ogCfg, ['id' => $pV6, 'url' => 'http://[::1]/admin', 'og_attempts' => 0]) === 'blocked',
        'worker blocks an IPv6 loopback target');

    // Claim query: give-up cap + backoff window are honoured.
    $atCap    = $mkLink('http://127.0.0.1/a', 'failed', 3, date('Y-m-d H:i:s', time() - 99999));   // maxed out
    $backoff  = $mkLink('http://127.0.0.1/b', 'failed', 1, date('Y-m-d H:i:s'));                    // too soon
    $retryDue = $mkLink('http://127.0.0.1/c', 'failed', 1, date('Y-m-d H:i:s', time() - 99999));    // due, private
    feddit_og_run($og, $ogCfg);
    check($loadPost($atCap)['og_status'] === 'failed' && (int)$loadPost($atCap)['og_attempts'] === 3,
        'a post at the attempt cap is given up on (never re-claimed)');
    check($loadPost($backoff)['og_status'] === 'failed' && (int)$loadPost($backoff)['og_attempts'] === 1,
        'a recently-failed post is left alone until its backoff window elapses');
    check($loadPost($retryDue)['og_status'] === 'blocked',
        'a due retryable post is re-claimed and reprocessed');
    $og = null;

} catch (Throwable $e) {
    echo "EXCEPTION: " . $e->getMessage() . "\n";
    $FAIL++;
}

// -- shutdown ---------------------------------------------------------------
proc_terminate($proc);
proc_close($proc);
@unlink($COOKIE);
foreach (glob(__DIR__ . '/rep_cookies_*.txt') ?: [] as $f) { @unlink($f); }

echo "\n============================\n";
echo "PASS: {$PASS}   FAIL: {$FAIL}\n";
echo "============================\n";
exit($FAIL === 0 ? 0 : 1);
