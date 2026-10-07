<?php
declare(strict_types=1);

// In-memory only: no bootstrap, config mutation or HTTP server.
require_once __DIR__ . '/../src/api/ObservationService.php';
$PASS = 0; $FAIL = 0;
function check(bool $ok, string $label): void
{
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "ok   {$label}\n"; }
    else { $FAIL++; echo "FAIL {$label}\n"; }
}
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('CREATE TABLE bots (id INTEGER PRIMARY KEY, username TEXT, token_hash TEXT)');
$pdo->exec('CREATE TABLE feddits (id INTEGER PRIMARY KEY, name TEXT, is_nsfw INTEGER)');
$pdo->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY, feddit_id INTEGER, bot_id INTEGER, title TEXT, body TEXT, created_at TEXT, score INTEGER, is_nsfw INTEGER, is_deleted INTEGER)');
$pdo->exec('CREATE TABLE comments (id INTEGER PRIMARY KEY, post_id INTEGER, bot_id INTEGER, parent_comment_id INTEGER, body TEXT, created_at TEXT, score INTEGER, is_deleted INTEGER)');
$pdo->exec('CREATE TABLE votes (id INTEGER PRIMARY KEY AUTOINCREMENT, target_type TEXT, target_id INTEGER, bot_id INTEGER, voter_fingerprint TEXT, direction INTEGER, reason TEXT, is_author_vote INTEGER, created_at TEXT)');
$bot = $pdo->prepare('INSERT INTO bots VALUES (?, ?, ?)');
for ($i = 1; $i <= 61; $i++) { $bot->execute([$i, 'bot_' . $i, 'PRIVATE_TOKEN']); }
$pdo->exec("INSERT INTO feddits VALUES (1, 'public', 0), (2, 'adult', 1)");
$post = $pdo->prepare('INSERT INTO posts VALUES (?, ?, 1, ?, ?, ?, ?, ?, ?)');
foreach ([
    [1, 1, 'Renewed old', '', '2026-09-01 00:00:00', 9, 0, 0],
    [2, 1, 'Busy', str_repeat('x', 900), '2026-10-07 10:00:00', 21, 0, 0],
    [3, 1, 'Deleted', 'PRIVATE_DELETED', '2026-10-07 10:00:00', 1, 0, 1],
    [4, 1, 'Adult post', 'PRIVATE_ADULT_POST', '2026-10-07 10:00:00', 1, 1, 0],
    [5, 2, 'Adult community', 'PRIVATE_ADULT_COMMUNITY', '2026-10-07 10:00:00', 1, 0, 0],
    [6, 1, 'Fresh unvoted', '', '2026-10-07 10:00:00', 1, 0, 0],
    [7, 1, 'Stale', '', '2026-09-01 00:00:00', 1, 0, 0],
] as $row) { $post->execute($row); }
$comment = $pdo->prepare('INSERT INTO comments VALUES (?, ?, 1, ?, ?, ?, 1, ?)');
foreach ([
    [1, 1, null, 'Visible', '2026-10-07 10:00:00', 0],
    [2, 1, null, 'PRIVATE_DELETED_COMMENT', '2026-10-07 10:00:00', 1],
    [3, 1, 2, 'PRIVATE_CHILD', '2026-10-07 10:00:00', 0],
    [4, 1, 3, 'PRIVATE_DESCENDANT', '2026-10-07 10:00:00', 0],
    [5, 3, null, 'PRIVATE_DELETED_POST_CHILD', '2026-10-07 10:00:00', 0],
    [6, 4, null, 'PRIVATE_ADULT_CHILD', '2026-10-07 10:00:00', 0],
    [7, 1, 1, 'Visible reply', '2026-10-07 10:00:00', 0],
] as $row) { $comment->execute($row); }
$vote = $pdo->prepare('INSERT INTO votes (target_type, target_id, bot_id, voter_fingerprint, direction, reason, is_author_vote, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
$vote->execute(['post', 1, 1, null, 1, null, 1, '2026-09-01 00:00:00']);
$vote->execute(['post', 1, 2, null, 1, 'Current A', 0, '2026-10-07 11:30:00']);
$vote->execute(['post', 1, 3, null, -1, 'Current B', 0, '2026-10-07 11:30:00']);
$vote->execute(['post', 1, null, 'PRIVATE_HUMAN_1', 1, 'PRIVATE_HUMAN_REASON', 0, '2026-10-07 11:50:00']);
$vote->execute(['post', 1, null, 'PRIVATE_HUMAN_2', 1, null, 0, '2026-09-02 00:00:00']);
$vote->execute(['post', 2, 1, null, 1, null, 1, '2026-10-07 10:00:00']);
for ($i = 2; $i <= 61; $i++) {
    $vote->execute(['post', 2, $i, null, $i <= 41 ? 1 : -1, $i === 61 ? str_repeat('r', 1500) : 'Reason ' . $i, 0, '2026-10-07 11:00:00']);
}
$now = new DateTimeImmutable('2026-10-07T12:00:00Z');
$before = (int)$pdo->query('SELECT total_changes()')->fetchColumn();
$pdo->exec('PRAGMA query_only = ON');
$snapshot = ObservationService::snapshot($pdo, 24, 100, $now);
$items = array_column($snapshot['items'], null, 'key');
check(array_keys($items) === ['post:1', 'post:2', 'comment:7', 'comment:1', 'post:6'], 'deterministic order, renewed older content, fresh unvoted content');
check($items['post:1']['up'] === 3 && $items['post:1']['down'] === 1 && $items['post:1']['total'] === 4, 'full current tally includes surviving votes before window');
check($items['post:1']['authorBaseline'] === 1 && $items['post:1']['reconciliationDifference'] === 6, 'author baseline and reconciliation difference');
check($items['post:1']['uniqueVotingBots'] === 2, 'unique voting bots exclude author baseline and humans');
check($items['post:1']['votes'][1]['bot'] === 'bot_3' && $items['post:1']['votes'][2]['bot'] === 'bot_2', 'vote ordering has deterministic id tiebreaker');
check($items['post:1']['votes'][0] === ['bot' => null, 'actorType' => 'human', 'direction' => 'up', 'reason' => null, 'at' => null], 'human identity, reason and timestamp withheld');
check($items['post:2']['total'] === 60 && $items['post:2']['up'] === 40 && $items['post:2']['down'] === 20 && $items['post:2']['uniqueVotingBots'] === 60, 'numeric aggregates do not truncate with trail');
check(count($items['post:2']['votes']) === 50 && $items['post:2']['trailTruncated'] && !$items['post:1']['trailTruncated'], 'trail bounded and truncation declared');
check(mb_strlen($items['post:2']['votes'][0]['reason']) === 1000 && mb_strlen($items['post:2']['excerpt']) === 500, 'reason and excerpts bounded');
check($items['post:1']['votes'][1]['at'] === '2026-10-07T11:30:00Z', 'bot timestamp ISO UTC');
check(!str_contains(json_encode($snapshot), 'PRIVATE_'), 'deleted ancestors, deleted targets, NSFW and private fields excluded');
check(!$snapshot['coverage']['historyComplete'] && $snapshot['coverage']['syntheticProvenance'] === 'unknown', 'coverage limitations explicit');
check($snapshot['window'] === ['hours' => 24, 'since' => '2026-10-06T12:00:00Z', 'until' => '2026-10-07T12:00:00Z'], 'window deterministic');
check($snapshot === ObservationService::snapshot($pdo, 24, 100, $now), 'repeated snapshot deterministic');
$popular = ObservationService::snapshot($pdo, 24, 1, $now, 'most-voted');
check(count($popular['items']) === 1 && $popular['items'][0]['key'] === 'post:2' && $popular['coverage']['sampleTruncated'], 'most-voted selection orders by full external vote total and stays bounded');
check($popular['coverage']['selectionMode'] === 'most-voted' && str_contains($popular['coverage']['selection'], 'not a prevalence estimate'), 'high-vote selection bias declared');
$rejected = false;
try { ObservationService::snapshot($pdo, 24, 1, $now, 'untrusted SQL'); }
catch (InvalidArgumentException $e) { $rejected = true; }
check($rejected, 'selection mode is a strict allowlist');
check((int)$pdo->query('SELECT total_changes()')->fetchColumn() === $before && !$pdo->inTransaction(), 'writes disabled, no mutation or transaction');
$bounded = ObservationService::snapshot($pdo, 9999, 1, $now);
check(count($bounded['items']) === 1 && $bounded['coverage']['sampleTruncated'] && $bounded['window']['hours'] === 720, 'caller limit and maximum window');
$pdo->exec('PRAGMA query_only = OFF');
for ($i = 100; $i < 210; $i++) { $post->execute([$i, 1, 'Sample', '', '2026-10-07 10:00:00', 1, 0, 0]); }
$pdo->exec('PRAGMA query_only = ON');
$bounded = ObservationService::snapshot($pdo, 24, 99999, $now);
check(count($bounded['items']) === 100 && $bounded['coverage']['sampleTruncated'] && $bounded['coverage']['maxVoteRows'] === 5000, '100 items / 5000 trail rows maximum');
echo "{$PASS} passed, {$FAIL} failed\n";
exit($FAIL === 0 ? 0 : 1);
