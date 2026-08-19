<?php
declare(strict_types=1);

/**
 * Ranking acceptance test. Proves all six sorts (best / hot / new / rising /
 * controversial / top) on a throwaway SQLite DB, driving the REAL ordering code
 * in src/api/RankingService.php
 * (the same code the website and JSON API use - registerSqliteFunctions() shims the
 * MariaDB-only SQL functions so one query string runs on SQLite here).
 *
 * The load-bearing check is the tiny-vs-busy contrast the spec calls the acceptance
 * test: with feddit's single-digit scores AGE dominates hot (it degrades into
 * something close to `new`); feed the SAME code a simulated busy sub whose scores
 * span hundreds-to-thousands and hot confines itself to roughly the last day, with
 * even the single highest-scored post sinking once it is a few days old. No tuning
 * is applied to make either case happen - it falls out of reddit's one formula.
 *
 * Run:  php verify/sorts_test.php     (exit 0 = all pass, 1 = any fail)
 */

require_once __DIR__ . '/../src/api/RankingService.php';
require_once __DIR__ . '/../src/helpers.php';   // hot_score(): the PHP reference formula

$PASS = 0; $FAIL = 0;
function check(bool $cond, string $label): void
{
    global $PASS, $FAIL;
    if ($cond) { $PASS++; echo "  ok   {$label}\n"; }
    else       { $FAIL++; echo "  FAIL {$label}\n"; }
}

$NOW = time();
function ago(float $hours): string { global $NOW; return date('Y-m-d H:i:s', $NOW - (int)round($hours * 3600)); }

// -- build the DB -----------------------------------------------------------
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
RankingService::registerSqliteFunctions($pdo);
$pdo->exec("CREATE TABLE posts (
    id INTEGER PRIMARY KEY AUTOINCREMENT, feddit_id INTEGER NOT NULL, title TEXT NOT NULL,
    created_at TEXT NOT NULL, score INTEGER NOT NULL DEFAULT 1, is_deleted INTEGER NOT NULL DEFAULT 0)");

// feddit 1 = the TINY sub (this project): scores 1..14. Highest-scored posts are
// the OLD ones, so if age dominates they must sink under hot.
// (label, age hours, score)
$tiny = [
    ['A',  2,   5],
    ['B',  6,   9],
    ['C',  26,  14],   // highest score, but ~1 day old
    ['D',  1,   2],
    ['E',  50,  12],   // second-highest score, oldest
    ['F',  10,  7],
    ['G',  0.3, 3],    // 18 min old, modest score: a rising candidate
];
// feddit 2 = a simulated BUSY sub: scores span hundreds..thousands over ~3 days.
$busy = [
    ['P',  2,   3000],
    ['Q',  10,  1500],
    ['R',  20,  900],
    ['S',  30,  2500],
    ['T',  48,  5000],
    ['U',  1,   200],
    ['V',  72,  8000],   // globally top score, but 3 days old
];
$ins = $pdo->prepare("INSERT INTO posts (feddit_id,title,created_at,score,is_deleted) VALUES (?,?,?,?,0)");
foreach ($tiny as [$l,$h,$s]) { $ins->execute([1, $l, ago($h), $s]); }
foreach ($busy as [$l,$h,$s]) { $ins->execute([2, $l, ago($h), $s]); }
// A soft-deleted high-score post: must never appear in any sort.
$pdo->prepare("INSERT INTO posts (feddit_id,title,created_at,score,is_deleted) VALUES (1,'DELETED',?,999,1)")
    ->execute([ago(1)]);

// -- votes: the table best + controversial derive genuine ups/downs from -------
// Only the columns the ranking subquery reads. best/controversial reconcile the
// stored score with real vote ROWS: downs = count of direction=-1 rows, and
// ups = score + downs (so ups - downs == score, always). The tiny/busy subs have
// NO vote rows (like seeded content) - downs = 0 there, which is deliberate: it
// is why controversial is empty for them and why best collapses toward top.
$pdo->exec("CREATE TABLE votes (
    id INTEGER PRIMARY KEY AUTOINCREMENT, target_type TEXT NOT NULL,
    target_id INTEGER NOT NULL, bot_id INTEGER NULL, direction INTEGER NOT NULL)");

// feddit 3 = a sub built specifically to exercise best + controversial. Each post
// carries an explicit score AND a chosen number of real DOWNVOTE rows; upvote
// rows are irrelevant to the ranking (ups is derived as score+downs), so we only
// seed downs. One post (VOTED) is realised the "honest" way - genuine up AND down
// rows that sum to its score - to prove ups then equals the true upvote count.
// (label, score, downRows)
$vote = [
    ['EVEN_BIG',   2,  8],   // ups 10 / downs 8  - heavily voted, near-even -> controversial
    ['EVEN_SMALL', 1,  4],   // ups  5 / downs 4  - near-even but smaller magnitude
    ['LOPSIDED',  20,  2],   // ups 22 / downs 2  - high score, few downs -> best-ish, not controversial-ish
    ['NODOWNS',   15,  0],   // ups 15 / downs 0  - pure upvotes -> in best, OUT of controversial
    ['ALLDOWN',   -3,  3],   // ups  0 / downs 3  - pile-on -> OUT of controversial, best sinks it
    ['ZEROVOTE',   8,  0],   // no rows at all    - like seeded content
    ['EVENEST',    0,  6],   // ups  6 / downs 6  - perfectly even -> most controversial
];
$insV = $pdo->prepare("INSERT INTO posts (feddit_id,title,created_at,score,is_deleted) VALUES (3,?,?,?,0)");
$insVote = $pdo->prepare("INSERT INTO votes (target_type,target_id,direction) VALUES ('post',?,?)");
foreach ($vote as $i => [$l, $s, $dn]) {
    $insV->execute([$l, ago(3 + $i), $s]);           // ages 3h.. so top ties break predictably
    $pid = (int)$pdo->lastInsertId();
    for ($k = 0; $k < $dn; $k++) { $insVote->execute([$pid, -1]); }
}
// VOTED: score 5 realised honestly as 6 up + 1 down rows (net +5). downs (rows) = 1,
// so ups = score + downs = 6 = the real upvote count. Proves the exact path.
$insV->execute(['VOTED', ago(3), 5]);
$votedId = (int)$pdo->lastInsertId();
for ($k = 0; $k < 6; $k++) { $insVote->execute([$votedId, 1]); }
$insVote->execute([$votedId, -1]);

/** Run a sort through the real RankingService clause and return ordered labels. */
function ranked(PDO $pdo, string $sort, int $fedditId, int $limit = 100): array
{
    $rank = RankingService::clause($sort);
    $sql  = "SELECT p.title FROM posts p
             WHERE p.is_deleted = 0 AND p.feddit_id = :fid" . $rank['where'] . "
             ORDER BY " . $rank['order'] . " LIMIT :lim";
    $st = $pdo->prepare($sql);
    $st->bindValue(':fid', $fedditId, PDO::PARAM_INT);
    foreach ($rank['binds'] as $k => $v) { $st->bindValue($k, $v); }
    $st->bindValue(':lim', $limit, PDO::PARAM_INT);
    $st->execute();
    return array_column($st->fetchAll(), 'title');
}
/** Sum of absolute rank differences between two orderings of the same labels. */
function rank_distance(array $a, array $b): int
{
    $pa = array_flip($a); $pb = array_flip($b); $d = 0;
    foreach ($pa as $label => $i) { $d += abs($i - ($pb[$label] ?? $i)); }
    return $d;
}
/** age in hours from a stored created_at, for readable report lines. */
function age_h(PDO $pdo, string $label): float
{
    global $NOW;
    $st = $pdo->prepare("SELECT created_at FROM posts WHERE title = ? LIMIT 1");
    $st->execute([$label]);
    return round(($NOW - strtotime((string)$st->fetchColumn())) / 3600, 1);
}
function score_of(PDO $pdo, string $label): int
{
    $st = $pdo->prepare("SELECT score FROM posts WHERE title = ? LIMIT 1");
    $st->execute([$label]);
    return (int)$st->fetchColumn();
}
function report(PDO $pdo, string $title, array $order): void
{
    echo "  {$title}: ";
    $bits = [];
    foreach ($order as $l) { $bits[] = "{$l}(score " . score_of($pdo, $l) . ", " . age_h($pdo, $l) . "h)"; }
    echo implode('  ', $bits) . "\n";
}

echo "== soft-delete is excluded everywhere ==\n";
foreach (RankingService::SORTS as $s) {
    check(!in_array('DELETED', ranked($pdo, $s, 1), true), "deleted post absent from {$s}");
}

echo "\n== new / top are exact ==\n";
$new = ranked($pdo, 'new', 1);
check($new === ['G','D','A','B','F','C','E'], 'new == created_at desc');
$top = ranked($pdo, 'top', 1);
check($top === ['C','E','B','F','A','G','D'], 'top == score desc, ties by recency');

echo "\n== hot: the SQL formula matches the PHP reference (faithful translation) ==\n";
$hot = ranked($pdo, 'hot', 1);
// Independent ordering by the PHP hot_score() reference in helpers.php.
$ref = $tiny;
usort($ref, fn($a, $b) => hot_score($b[2], ago($b[1])) <=> hot_score($a[2], ago($a[1])));
$refOrder = array_column($ref, 0);
check($hot === $refOrder, 'SQL hot order == PHP hot_score() order');

echo "\n== TINY sub: age dominates, hot degrades toward new ==\n";
report($pdo, 'hot ', $hot);
report($pdo, 'new ', $new);
report($pdo, 'top ', $top);
// The two highest-scored posts (C=14, E=12) are the OLD ones; under hot they sink
// to the very bottom instead of leading, which is the whole "age dominates" point.
check(array_slice($hot, -2) === ['C','E'], 'top-scored-but-old posts C,E sink to the bottom of hot');
check(array_slice($top, 0, 2) === ['C','E'], '...while top puts those very same posts first');
$dNew = rank_distance($hot, $new);
$dTop = rank_distance($hot, $top);
echo "  hot<->new distance = {$dNew} ; hot<->top distance = {$dTop}\n";
check($dNew < $dTop, "hot is closer to new than to top at this vote scale ({$dNew} < {$dTop})");

echo "\n== BUSY sub: the SAME code confines hot to ~the last day ==\n";
$hotB = ranked($pdo, 'hot', 2);
$topB = ranked($pdo, 'top', 2);
$newB = ranked($pdo, 'new', 2);
report($pdo, 'hot ', $hotB);
report($pdo, 'top ', $topB);
$agesTop3 = array_map(fn($l) => age_h($pdo, $l), array_slice($hotB, 0, 3));
echo "  ages of hot's top 3 (busy): " . implode('h, ', $agesTop3) . "h\n";
check(max($agesTop3) < 36, "every post in hot's top 3 is under 36h old (max " . max($agesTop3) . "h)");
check(age_h($pdo, $hotB[0]) < 24, "hot's #1 on the busy sub is under a day old");
// V is the single highest score on the whole site (8000) but 3 days old: top #1,
// yet hot pushes it into the bottom half. Max score cannot rescue a 3-day-old post.
check($topB[0] === 'V', 'V (8000, 72h) is top #1 by raw score');
$posV = array_search('V', $hotB, true);
check($posV >= (int)floor(count($hotB) / 2), "...but hot sinks V into the bottom half (hot position {$posV})");

echo "\n== rising: velocity, never empty on a live site, and NOT a reshuffle of hot ==\n";
// Rising was rewritten (see RankingService): score/(age+2h) smoothed velocity,
// floored at score>=1, with NO hard time window (the smoothing self-windows old
// posts). The old 24h-window + score>=3 combination rendered the tab permanently
// blank on real traffic; this proves the new behaviour instead.
$rising = ranked($pdo, 'rising', 1);
report($pdo, 'rising', $rising);

/** PHP reference for rising: smoothed votes/hour = score*3600 / (age_secs + 7200). */
function rising_velocity(PDO $pdo, string $label): float
{
    global $NOW;
    $st = $pdo->prepare("SELECT created_at, score FROM posts WHERE title = ? LIMIT 1");
    $st->execute([$label]);
    $r = $st->fetch();
    $ageS = $NOW - strtotime((string)$r['created_at']);
    return ((int)$r['score'] * 3600.0) / ($ageS + 7200);
}
// Exact order == the PHP velocity reference, over every score>=1 post (all of the
// tiny sub qualifies), so a drift in the SQL expression fails the test.
$refRise = array_values(array_filter(array_column($tiny, 0), fn($l) => score_of($pdo, $l) >= 1));
usort($refRise, fn($x, $y) => rising_velocity($pdo, $y) <=> rising_velocity($pdo, $x));
check($rising === $refRise, 'rising order == PHP smoothed-velocity reference');

// G (18 min old, score 3) has the highest smoothed votes/hour and leads rising,
// though it leads neither hot (A) nor top (C).
check($rising[0] === 'G', 'rising surfaces the young fast-climbing post G first');
check($rising[0] !== $hot[0], 'rising #1 differs from hot #1 (velocity, not accumulated score)');
check($rising[0] !== $top[0], 'rising #1 differs from top #1');
check($rising !== $new, 'rising is not just new (velocity reorders by score-per-age)');
// The score>=1 floor now ADMITS the young score-2 post D (the old floor-3 dropped
// it); and the windowless velocity KEEPS the old high-score posts C,E but sinks
// them to the bottom on their own, rather than a hard cutoff hiding them.
check(in_array('D', $rising, true), 'rising now admits the young score-2 post D (floor lowered to 1)');
check(in_array('C', $rising, true) && in_array('E', $rising, true), 'old posts C,E are kept but ranked low (soft window, not a hard cutoff)');
$cPos = array_search('C', $rising, true); $ePos = array_search('E', $rising, true);
check($cPos >= (int)floor(count($rising) / 2) && $ePos >= (int)floor(count($rising) / 2),
    '...the old high-score posts sink into the bottom half of rising (velocity ~ 0)');
// Every kept post is above the floor.
$risingOk = true;
foreach ($rising as $l) { if (score_of($pdo, $l) < 1) { $risingOk = false; } }
check($risingOk, 'every rising post has score>=1 (the only floor)');

echo "\n== rising is NEVER empty when a live site has any positive post ==\n";
// A deliberately hostile "quiet dormant sub": every post is old AND low-scored -
// exactly the shape that made the old rising blank. Rising must still return them.
$pdo->exec("CREATE TABLE IF NOT EXISTS posts_check AS SELECT * FROM posts WHERE 0");
$insQ = $pdo->prepare("INSERT INTO posts (feddit_id,title,created_at,score,is_deleted) VALUES (9,?,?,?,0)");
$insQ->execute(['OLD1', ago(200), 1]);   // ~8 days old, score 1
$insQ->execute(['OLD2', ago(400), 2]);   // ~16 days old, score 2
$insQ->execute(['ZEROQ', ago(30), 0]);   // score 0 -> below the floor
$risingQuiet = ranked($pdo, 'rising', 9);
report($pdo, 'rising(quiet)', $risingQuiet);
check($risingQuiet !== [], 'rising on an all-old, all-low-score sub is still NON-empty (was blank before)');
check(!in_array('ZEROQ', $risingQuiet, true), 'rising still excludes the score-0 post (floor holds)');
check(count($risingQuiet) === 2, 'rising returns exactly the two positive-score posts');

// -- best + controversial: genuine ups/downs, reconciled with the score --------
//
// The reconciliation (see RankingService docblock): downs = real downvote ROWS,
// ups = score + downs, so ups - downs == score for every post - the score, the
// hover tooltip's down count, and these sorts can never disagree. The PHP
// references below mirror the SQL byte-for-byte, exactly as hot_score() does for
// hot, so a drift in either engine's expression fails the test.

/** Reconciled (ups, downs) for a votesub post, mirroring the SQL derivation. */
function ups_downs(PDO $pdo, string $label): array
{
    $st = $pdo->prepare("SELECT p.score,
        (SELECT COUNT(*) FROM votes vd
           WHERE vd.target_type = 'post' AND vd.target_id = p.id AND vd.direction = -1) AS downs
        FROM posts p WHERE p.title = ? LIMIT 1");
    $st->execute([$label]);
    $r = $st->fetch();
    $downs = (int)$r['downs'];
    return [(int)$r['score'] + $downs, $downs];   // [ups, downs]
}
/** PHP reference for reddit's 'best': the Wilson score interval lower bound. */
function wilson_lb(int $ups, int $downs): float
{
    $n = $ups + $downs;
    if ($n <= 0) { return 0.0; }
    $z = 1.281551565545; $z2 = $z * $z;
    $p = $ups / $n;
    $left  = $p + $z2 / (2 * $n);
    $right = $z * sqrt(($p * (1 - $p) + $z2 / (4 * $n)) / $n);
    return ($left - $right) / (1 + $z2 / $n);
}
/** PHP reference for reddit's 'controversial': magnitude ** balance. */
function controversy(int $ups, int $downs): float
{
    if ($downs <= 0 || $ups <= 0) { return 0.0; }
    $mag = $ups + $downs;
    $bal = $ups > $downs ? $downs / $ups : $ups / $downs;
    return $mag ** $bal;
}

$vlabels = ['EVEN_BIG','EVEN_SMALL','LOPSIDED','NODOWNS','ALLDOWN','ZEROVOTE','EVENEST','VOTED'];

echo "\n== reconciliation invariant: ups - downs == score (tooltip never contradicted) ==\n";
$invOk = true;
foreach ($vlabels as $l) {
    [$u, $d] = ups_downs($pdo, $l);
    if ($u - $d !== score_of($pdo, $l)) { $invOk = false; }
}
check($invOk, 'every post satisfies ups - downs == score (score/tooltip/sorts agree)');
[$vu, $vd] = ups_downs($pdo, 'VOTED');
check($vu === 6 && $vd === 1, 'VOTED: real up+down rows sum to score, so ups (6) == true upvote count, downs == 1');

echo "\n== best: Wilson lower bound, SQL matches the PHP reference ==\n";
$best3 = ranked($pdo, 'best', 3);
report($pdo, 'best', $best3);
$refBest = $vlabels;
usort($refBest, function ($x, $y) use ($pdo) {
    [$ux, $dx] = ups_downs($pdo, $x); [$uy, $dy] = ups_downs($pdo, $y);
    return wilson_lb($uy, $dy) <=> wilson_lb($ux, $dx);   // all distinct here
});
check($best3 === $refBest, 'best order == PHP wilson_lb() order');
check(end($best3) === 'ALLDOWN', 'best sinks the all-downvote pile-on to the very bottom (Wilson 0)');
// Confidence, not raw ratio: a spotless 8/0 (ZEROVOTE) outranks a heavily-voted
// 22/2 (LOPSIDED) whose two downvotes dent the lower bound.
check(array_search('ZEROVOTE', $best3, true) < array_search('LOPSIDED', $best3, true),
    'best ranks a spotless small record above a larger record with a couple of downs');

echo "\n== controversial: magnitude ** balance, contested-only and honestly sparse ==\n";
$contro3 = ranked($pdo, 'controversial', 3);
report($pdo, 'controversial', $contro3);
$refContro = array_values(array_filter($vlabels, function ($l) use ($pdo) {
    [$u, $d] = ups_downs($pdo, $l); return controversy($u, $d) > 0;
}));
usort($refContro, function ($x, $y) use ($pdo) {
    [$ux, $dx] = ups_downs($pdo, $x); [$uy, $dy] = ups_downs($pdo, $y);
    return controversy($uy, $dy) <=> controversy($ux, $dx);
});
check($contro3 === $refContro, 'controversial order == PHP controversy() order (contested posts only)');
check($contro3[0] === 'EVENEST', 'the perfectly-even, well-voted post is the most controversial');
check(!in_array('NODOWNS', $contro3, true), 'controversial excludes the zero-downvote post (NODOWNS)');
check(!in_array('ZEROVOTE', $contro3, true), 'controversial excludes the zero-vote post (ZEROVOTE)');
check(!in_array('ALLDOWN', $contro3, true), 'controversial excludes the all-downvote pile-on (ALLDOWN, ups<=0)');

echo "\n== degenerate cases: no real downvotes -> controversial is honestly empty ==\n";
// The tiny + busy subs have NO vote rows (like seeded content): downs = 0 for
// every post, so controversial has nothing contested to show. This is correct,
// not a bug - the empty state says so.
check(ranked($pdo, 'controversial', 1) === [], 'controversial on the tiny (all-upvote) sub is empty');
check(ranked($pdo, 'controversial', 2) === [], 'controversial on the busy (no-vote-rows) sub is empty');
// And with no downvotes anywhere, best has no confidence signal to act on, so it
// collapses to top's ordering - best and top are only distinct once downs exist.
check(ranked($pdo, 'best', 1) === ranked($pdo, 'top', 1),
    'best collapses to top ordering when there are no downvotes (tiny sub)');

echo "\n============================\n";
echo "PASS: {$PASS}   FAIL: {$FAIL}\n";
echo "============================\n";
exit($FAIL === 0 ? 0 : 1);
