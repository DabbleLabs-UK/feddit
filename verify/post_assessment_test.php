<?php
declare(strict_types=1);

// In-memory only: no bootstrap, config mutation, HTTP server or activity.
require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../src/api/ApiException.php';
require_once __DIR__ . '/../src/api/RankingService.php';
require_once __DIR__ . '/../src/api/PostService.php';
require_once __DIR__ . '/../src/api/Serialize.php';
$PASS = 0; $FAIL = 0;
function check(bool $ok, string $label): void
{
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "ok   {$label}\n"; }
    else { $FAIL++; echo "FAIL {$label}\n"; }
}
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
RankingService::registerSqliteFunctions($pdo);
$pdo->exec('CREATE TABLE bots (id INTEGER PRIMARY KEY, username TEXT)');
$pdo->exec('CREATE TABLE feddits (id INTEGER PRIMARY KEY, name TEXT, title TEXT, is_nsfw INTEGER)');
$pdo->exec('CREATE TABLE posts (
    id INTEGER PRIMARY KEY, feddit_id INTEGER, bot_id INTEGER, title TEXT,
    kind TEXT, body TEXT, url TEXT, thumbnail_url TEXT, og_title TEXT,
    og_description TEXT, og_site_name TEXT, og_status TEXT, og_fetched_at TEXT,
    created_at TEXT, edited_at TEXT, score INTEGER, comment_count INTEGER,
    flair_text TEXT, flair_color TEXT, is_nsfw INTEGER, is_deleted INTEGER)');
$pdo->exec('CREATE TABLE comments (id INTEGER PRIMARY KEY, post_id INTEGER, bot_id INTEGER, is_deleted INTEGER)');
$pdo->exec('CREATE TABLE votes (id INTEGER PRIMARY KEY, target_type TEXT, target_id INTEGER, bot_id INTEGER, direction INTEGER, is_author_vote INTEGER)');
$pdo->exec("INSERT INTO bots VALUES (1, 'author'), (2, 'other'), (3, 'third')");
$pdo->exec("INSERT INTO feddits VALUES (1, 'public', 'Public', 0)");
$post = $pdo->prepare("INSERT INTO posts (id, feddit_id, bot_id, title, kind, body, created_at, score, comment_count, is_nsfw, is_deleted) VALUES (?, 1, 1, 'Fixture', 'text', '', '2026-10-19 00:00:00', 1, 0, 0, 0)");
for ($id = 1; $id <= 9; $id++) { $post->execute([$id]); }
$pdo->exec("INSERT INTO votes VALUES
    (1, 'post', 1, 1, 1, 1),
    (2, 'post', 2, 2, 1, 0),
    (3, 'post', 2, 3, -1, 0),
    (4, 'post', 3, NULL, 1, 0),
    (5, 'comment', 4, 2, 1, 0),
    (6, 'post', 8, 2, 1, 0),
    (7, 'post', 9, 1, -1, 0)");
$pdo->exec('INSERT INTO comments VALUES (1, 4, 1, 0), (2, 5, 2, 0), (3, 6, 2, 1)');
$pdo->exec('DELETE FROM votes WHERE id = 6');
$before = (int)$pdo->query('SELECT total_changes()')->fetchColumn();
$pdo->exec('PRAGMA query_only = ON');
$rows = PostService::frontListing($pdo, 'new', 100, 0);
$feed = Serialize::postListing($rows, null);
$byId = [];
foreach ($feed['data']['children'] as $thing) { $byId[$thing['data']['id']] = $thing['data']; }
check(count($byId) === 9, 'all roots retained in the bounded ordinary feed');
$expected = [1 => false, 2 => true, 3 => false, 4 => false, 5 => true, 6 => false, 7 => false, 8 => false, 9 => false];
$labels = [1 => 'author baseline only', 2 => 'cancelling external bot votes', 3 => 'human vote only', 4 => 'author comment only', 5 => 'other-bot comment', 6 => 'deleted other-bot comment', 7 => 'no evidence', 8 => 'removed external vote', 9 => 'non-baseline self-vote'];
foreach ($expected as $id => $assessed) {
    check($byId[$id]['independently_assessed'] === $assessed, $labels[$id] . ' has strict boolean assessment');
    $thread = Serialize::post(PostService::requireById($pdo, $id));
    check($thread['data']['independently_assessed'] === $assessed, 'thread root ' . $id . ' matches feed evidence');
}
check($byId[2]['score'] === 1 && $byId[2]['independently_assessed'], 'cancelling bot votes assess a score-one root');
$community = Serialize::postListing(PostService::fedditListing($pdo, 1, 'new', 100, 0), null);
check($community === $feed, 'community and front listings use the same evidence');
$unknown = $rows[0];
unset($unknown['independently_assessed']);
check(Serialize::post($unknown)['data']['independently_assessed'] === null, 'missing evidence remains unknown, not false');
$unknown['independently_assessed'] = null;
check(Serialize::post($unknown)['data']['independently_assessed'] === null, 'explicit unknown remains unknown');
check(!str_contains(json_encode($feed), 'bot_id'), 'no voter identity is exported by the predicate');
check((int)$pdo->query('SELECT total_changes()')->fetchColumn() === $before, 'feed and thread reads do not mutate data');
echo "{$PASS} passed, {$FAIL} failed\n";
exit($FAIL === 0 ? 0 : 1);
