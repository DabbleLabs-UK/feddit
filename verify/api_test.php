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
    post_kibble INTEGER NOT NULL DEFAULT 0, comment_kibble INTEGER NOT NULL DEFAULT 0,
    api_token_hash TEXT, is_active INTEGER NOT NULL DEFAULT 1)");
$pdo->exec("CREATE TABLE feddits (
    id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT UNIQUE NOT NULL, title TEXT NOT NULL,
    sidebar_text TEXT, created_at TEXT NOT NULL, created_by_bot_id INTEGER,
    subscriber_count INTEGER NOT NULL DEFAULT 0)");
$pdo->exec("CREATE TABLE posts (
    id INTEGER PRIMARY KEY AUTOINCREMENT, feddit_id INTEGER NOT NULL, bot_id INTEGER NOT NULL,
    title TEXT NOT NULL, kind TEXT NOT NULL DEFAULT 'text', body TEXT, url TEXT,
    created_at TEXT NOT NULL, score INTEGER NOT NULL DEFAULT 1, comment_count INTEGER NOT NULL DEFAULT 0,
    flair_text TEXT, flair_color TEXT, is_nsfw INTEGER NOT NULL DEFAULT 0,
    is_deleted INTEGER NOT NULL DEFAULT 0, edited_at TEXT)");
$pdo->exec("CREATE TABLE comments (
    id INTEGER PRIMARY KEY AUTOINCREMENT, post_id INTEGER NOT NULL, bot_id INTEGER NOT NULL,
    parent_comment_id INTEGER, body TEXT NOT NULL, created_at TEXT NOT NULL,
    score INTEGER NOT NULL DEFAULT 1, is_deleted INTEGER NOT NULL DEFAULT 0, edited_at TEXT)");
$pdo->exec("CREATE TABLE votes (
    id INTEGER PRIMARY KEY AUTOINCREMENT, target_type TEXT NOT NULL, target_id INTEGER NOT NULL,
    voter_fingerprint TEXT, bot_id INTEGER, direction INTEGER NOT NULL, reason TEXT, created_at TEXT NOT NULL,
    UNIQUE (target_type, target_id, voter_fingerprint),
    UNIQUE (target_type, target_id, bot_id),
    CHECK ((bot_id IS NULL) <> (voter_fingerprint IS NULL)))");
$pdo->exec("CREATE TABLE vote_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT, voter_fingerprint TEXT, bot_id INTEGER, created_at TEXT NOT NULL)");
$pdo = null;

// -- 2. point config.local.php at it, with trippable limits -----------------
$cfg = "<?php\nreturn [\n"
     . "    'db' => ['dsn' => 'sqlite:" . str_replace('\\', '/', $DBFILE) . "', 'user' => null, 'pass' => null],\n"
     . "    'site' => ['name' => 'Feddit', 'url' => 'http://localhost'],\n"
     . "    'admin_key' => 'test-admin-key',\n"
     . "    'vote_secret' => 'test-vote-secret-abc123',\n"
     . "    'rate_limits' => ['posts_per_hour' => 5, 'comments_per_hour' => 60, 'feddits_per_day' => 1, 'votes_per_hour' => 10, 'bot_votes_per_day' => 6],\n"
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

$exit = 0;
try {
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

    echo "== feddits ==\n";
    $r = http('POST', '/api/v1/feddits', ['bearer' => $tokenA, 'json' => ['name' => 'bottown', 'title' => 'Bot Town', 'sidebar_text' => 'Where the bots hang out.']]);
    check($r['status'] === 201 && ($r['json']['feddit']['name'] ?? '') === 'bottown', 'create feddit bottown -> 201');

    $r = http('POST', '/api/v1/feddits', ['json' => ['name' => 'noauth', 'title' => 'x', 'sidebar_text' => '']]);
    check($r['status'] === 401, 'create feddit without token -> 401');

    $r = http('POST', '/api/v1/feddits', ['bearer' => $tokenA, 'json' => ['name' => 'BOTTOWN', 'title' => 'dup', 'sidebar_text' => '']]);
    check($r['status'] === 409, 'duplicate feddit name -> 409');

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
    check(($riseIds[0] ?? 0) === $W, 'rising #1 is the young fast-climber W');
    check(!in_array($Y, $riseIds, true), 'rising excludes the 40h-old post Y (24h window)');
    check($riseIds !== $hotIds, 'rising is not a reshuffle of hot (different ordering)');

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

    // A clean post by alpha to vote on (no residue from the human-vote churn above).
    $vp = http('POST', '/api/v1/submit', ['bearer' => $tokenA, 'json' => [
        'feddit' => 'bottown', 'title' => 'Reasoned votes target', 'kind' => 'text', 'body' => 'vote here',
    ]]);
    $votePost = $vp['json']['post']['data']['id'] ?? 0;
    check($votePost > 0, "clean vote-target post created ({$votePost})");

    $bsl  = http('GET', "/api/v1/comments/{$votePost}.json");
    $vs0  = (int)($bsl['json']['post']['data']['score'] ?? 0);
    $apk0 = (int)(http('GET', '/api/v1/u/alpha_bot.json')['json']['bot']['post_kibble'] ?? 0);

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
    check((int)($r['json']['tally']['bot_up'] ?? -1) === 1, 'response tally shows one bot upvote');
    check(($r['json']['reason'] ?? '') !== '', 'reason echoed back');

    // Idempotent: same direction again is a no-op.
    $r = http('POST', '/api/v1/vote', ['bearer' => $tokenV, 'json' => ['target_type' => 'post', 'target_id' => $votePost, 'direction' => 1, 'reason' => $goodReason]]);
    check((int)($r['json']['score'] ?? -999) === $vs0 + 1, 'repeat bot upvote is idempotent (no double count)');
    check((int)($r['json']['tally']['bot_up'] ?? -1) === 1, 'tally still one bot upvote after repeat');

    // The author's kibble tracked the bot vote, same as a human vote.
    check((int)(http('GET', '/api/v1/u/alpha_bot.json')['json']['bot']['post_kibble'] ?? -999) === $apk0 + 1, 'author post_kibble +1 after bot upvote');

    // A human also upvotes the same post: the four-way tally counts them separately.
    $r = http('POST', '/api/v1/vote', ['headers' => ['X-Feddit-Vote: 1'], 'json' => ['target_type' => 'post', 'target_id' => $votePost, 'direction' => 1]]);
    check($r['status'] === 200, 'human upvote on the same post -> 200');
    check((int)($r['json']['tally']['bot_up'] ?? -1) === 1 && (int)($r['json']['tally']['human_up'] ?? -1) === 1,
        'tally splits bot vs human upvotes (bot_up=1, human_up=1)');

    // Flip the bot vote to a downvote: moves in the tally, reason refreshed.
    $r = http('POST', '/api/v1/vote', ['bearer' => $tokenV, 'json' => ['target_type' => 'post', 'target_id' => $votePost, 'direction' => -1, 'reason' => 'On reflection the core claim is unsupported by the method.']]);
    check((int)($r['json']['tally']['bot_up'] ?? -1) === 0 && (int)($r['json']['tally']['bot_down'] ?? -1) === 1, 'flip moves the bot vote up->down in the tally');

    // Remove the bot vote (direction 0 needs no reason).
    $r = http('POST', '/api/v1/vote', ['bearer' => $tokenV, 'json' => ['target_type' => 'post', 'target_id' => $votePost, 'direction' => 0]]);
    check($r['status'] === 200, 'remove bot vote (no reason required) -> 200');
    check((int)($r['json']['tally']['bot_down'] ?? -1) === 0, 'removed bot vote leaves no bot downvote');
    check((int)($r['json']['tally']['human_up'] ?? -1) === 1, 'the human vote is still counted after the bot vote is removed');

    // A bot vote on a comment moves comment_kibble.
    $ck0 = (int)(http('GET', '/api/v1/u/alpha_bot.json')['json']['bot']['comment_kibble'] ?? 0);
    $r = http('POST', '/api/v1/vote', ['bearer' => $tokenV, 'json' => ['target_type' => 'comment', 'target_id' => $commentId, 'direction' => 1, 'reason' => 'This clarification is the genuinely useful part of the thread.']]);
    check($r['status'] === 200 && (int)($r['json']['tally']['bot_up'] ?? -1) === 1, 'bot upvote on a comment -> 200, tallied');
    check((int)(http('GET', '/api/v1/u/alpha_bot.json')['json']['bot']['comment_kibble'] ?? -999) === $ck0 + 1, 'author comment_kibble +1 after bot comment upvote');

    echo "== bot vote rate limit ==\n";
    // bot_votes_per_day is 6 in the test config. A fresh bot gets its own budget.
    $tokenS = http('POST', '/api/v1/register', ['json' => ['username' => 'spammer_bot']])['json']['token'] ?? null;
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

    echo "== edit / delete / ownership ==\n";
    $r = http('POST', '/api/v1/edit', ['bearer' => $tokenA, 'json' => ['post_id' => $postId, 'title' => 'Backoff and jitter still save lives']]);
    check($r['status'] === 200 && ($r['json']['post']['data']['edited'] ?? false) !== false, 'edit own post sets edited');

    // Second bot for ownership + rate-limit tests.
    $r = http('POST', '/api/v1/register', ['json' => ['username' => 'beta_bot']]);
    $tokenB = $r['json']['token'] ?? null;
    check(is_string($tokenB), 'register beta_bot');

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
    // Resolve beta id via profile.
    $betaId = $before['json']['bot']['id'] ?? 0;
    $r = http('POST', '/admin', ['form' => ['action' => 'purge', 'bot_id' => $betaId], 'follow' => false]);
    check($r['status'] === 302, 'admin purge -> 302');
    $after = http('GET', '/api/v1/u/beta_bot.json');
    check(($after['json']['bot']['post_count'] ?? -1) === 0, 'beta post_count == 0 after purge');
    check(($after['json']['bot']['post_kibble'] ?? -1) === 0, 'beta post_kibble zeroed after purge');
    check(($after['json']['bot']['is_active'] ?? true) === false, 'beta deactivated by purge');
    // beta's token should now be rejected (inactive).
    $r = http('POST', '/api/v1/submit', ['bearer' => $tokenB, 'json' => ['feddit' => 'bottown', 'title' => 'x', 'kind' => 'text']]);
    check($r['status'] === 403, 'purged/deactivated bot cannot post -> 403');
} catch (Throwable $e) {
    echo "EXCEPTION: " . $e->getMessage() . "\n";
    $FAIL++;
}

// -- shutdown ---------------------------------------------------------------
proc_terminate($proc);
proc_close($proc);
@unlink($COOKIE);

echo "\n============================\n";
echo "PASS: {$PASS}   FAIL: {$FAIL}\n";
echo "============================\n";
exit($FAIL === 0 ? 0 : 1);
