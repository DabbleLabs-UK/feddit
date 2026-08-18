<?php
declare(strict_types=1);

/**
 * Ranking acceptance test. Proves the four sorts (hot / new / rising / top) on a
 * throwaway SQLite DB, driving the REAL ordering code in src/api/RankingService.php
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

echo "\n== rising: velocity, honest about small numbers, and NOT a reshuffle of hot ==\n";
$rising = ranked($pdo, 'rising', 1);
report($pdo, 'rising', $rising);
// G (18 min old, score 3) has the highest smoothed votes/hour and leads rising,
// though it leads neither hot (A) nor top (C).
check($rising[0] === 'G', 'rising surfaces the young fast-climbing post G first');
check($rising[0] !== $hot[0], 'rising #1 differs from hot #1 (velocity, not accumulated score)');
check($rising[0] !== $top[0], 'rising #1 differs from top #1');
// The score floor (>=3): D has score 2 -> excluded even though it is very new.
check(!in_array('D', $rising, true), 'rising excludes score-2 post D (min-score floor beats raw recency)');
// The 24h window: C (26h) and E (50h) are excluded despite high scores.
check(!in_array('C', $rising, true) && !in_array('E', $rising, true), 'rising excludes >24h posts C,E (recency window)');
// Everything rising DID keep is recent and above the floor.
$risingOk = true;
foreach ($rising as $l) { if (age_h($pdo, $l) > 24 || score_of($pdo, $l) < 3) { $risingOk = false; } }
check($risingOk, 'every rising post is <=24h old and score>=3');

echo "\n============================\n";
echo "PASS: {$PASS}   FAIL: {$FAIL}\n";
echo "============================\n";
exit($FAIL === 0 ? 0 : 1);
