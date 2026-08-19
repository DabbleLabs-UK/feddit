<?php
declare(strict_types=1);

/**
 * PART 2: drive the REAL RankingService against SYNTHETIC datasets in SQLite and
 * measure how pairwise similarity moves as the data grows. Nothing here touches
 * live data - it builds throwaway in-memory DBs.
 *
 * Independent knobs:
 *   N        - number of posts (volume)
 *   ceiling  - max score; scores are drawn log-uniform in [1, ceiling], so the
 *              spread in ORDERS OF MAGNITUDE is log10(ceiling)
 *   rate     - posts per hour (sets how close in time adjacent posts are)
 *   avgDowns - mean downvote ROWS per post (only matters for best vs top)
 *
 * The underlying maths for hot vs new: hot = sign*log10(|score|) + t/45000.
 * 45000s = 12.5h, so one ORDER OF MAGNITUDE of score buys 12.5h of age. A post
 * can therefore only jump other posts within ~ (12.5h * spread) of its own age.
 * With `rate` posts/hour that is ~ 12.5 * spread * rate rank positions. Below we
 * confirm that empirically and locate the threshold.
 */

require_once __DIR__ . '/../src/api/RankingService.php';

$NOW = time();

function make_pdo(): PDO
{
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    RankingService::registerSqliteFunctions($pdo);
    $pdo->exec("CREATE TABLE posts (
        id INTEGER PRIMARY KEY AUTOINCREMENT, feddit_id INTEGER NOT NULL DEFAULT 1,
        title TEXT NOT NULL DEFAULT '', created_at TEXT NOT NULL,
        score INTEGER NOT NULL DEFAULT 1, is_deleted INTEGER NOT NULL DEFAULT 0)");
    $pdo->exec("CREATE TABLE votes (
        id INTEGER PRIMARY KEY AUTOINCREMENT, target_type TEXT NOT NULL,
        target_id INTEGER NOT NULL, direction INTEGER NOT NULL)");
    return $pdo;
}

/**
 * Populate N posts. Post k (k=0 newest) sits at age k/rate hours (+/- jitter).
 * Score is log-uniform in [1, ceiling]. If avgDowns>0 each post also gets a
 * Poisson-ish number of downvote rows (mean avgDowns, capped so ups stay >0).
 */
function populate(PDO $pdo, int $N, float $ceiling, float $rate, float $avgDowns, int $seed): void
{
    global $NOW;
    mt_srand($seed);
    $insP = $pdo->prepare("INSERT INTO posts (id,feddit_id,created_at,score) VALUES (?,1,?,?)");
    $insV = $pdo->prepare("INSERT INTO votes (target_type,target_id,direction) VALUES ('post',?,?)");
    $logC = log10(max($ceiling, 1.0));
    for ($k = 0; $k < $N; $k++) {
        $jit = (mt_rand() / mt_getrandmax() - 0.5) / max($rate, 0.0001); // +/-0.5 post-spacing
        $ageH = $k / $rate + $jit;
        if ($ageH < 0) $ageH = 0;
        $created = date('Y-m-d H:i:s', $NOW - (int)round($ageH * 3600));
        $u = mt_rand() / mt_getrandmax();
        $score = (int)round(10 ** ($u * $logC));   // log-uniform in [1, ceiling]
        if ($score < 1) $score = 1;
        $pid = $k + 1;
        $insP->execute([$pid, $created, $score]);
        if ($avgDowns > 0) {
            // number of downvotes ~ Poisson(avgDowns), but never enough to make ups<=0
            $downs = poisson($avgDowns);
            $downs = min($downs, max(0, $score - 1) + $downs); // allow ups=score+downs always>0
            for ($d = 0; $d < $downs; $d++) $insV->execute([$pid, -1]);
        }
    }
}

/** Small Poisson sampler (Knuth). */
function poisson(float $lambda): int
{
    $L = exp(-$lambda); $k = 0; $p = 1.0;
    do { $k++; $p *= mt_rand() / mt_getrandmax(); } while ($p > $L);
    return $k - 1;
}

/** Ordered ids for a sort via the REAL RankingService clause. */
function ranked_ids(PDO $pdo, string $sort): array
{
    $rank = RankingService::clause($sort);
    $sql = "SELECT p.id FROM posts p WHERE p.is_deleted = 0" . $rank['where']
         . " ORDER BY " . $rank['order'];
    $st = $pdo->prepare($sql);
    foreach ($rank['binds'] as $k => $v) $st->bindValue($k, $v);
    $st->execute();
    return array_map('intval', array_column($st->fetchAll(), 'id'));
}

/** Kendall tau over identical-set orderings via inversion count (O(n log n)). */
function tau_same_set(array $a, array $b): float
{
    $n = count($a);
    if ($n < 2) return 1.0;
    $rankInA = array_flip($a);            // id -> position in A
    $seq = [];
    foreach ($b as $id) $seq[] = $rankInA[$id]; // B as a permutation of A's positions
    $inv = count_inversions($seq);
    $pairs = $n * ($n - 1) / 2;
    return 1.0 - 2.0 * $inv / $pairs;     // concordant-discordant / pairs
}

/** Inversions in an int array via Fenwick tree. Values are 0..n-1. */
function count_inversions(array $seq): int
{
    $n = count($seq);
    $bit = array_fill(0, $n + 1, 0);
    $inv = 0;
    // count, from right, how many already-seen are smaller (standard)
    for ($i = $n - 1; $i >= 0; $i--) {
        $v = $seq[$i];               // 0..n-1
        // sum of counts for indices < v
        for ($x = $v; $x > 0; $x -= $x & (-$x)) $inv += $bit[$x];
        for ($x = $v + 1; $x <= $n; $x += $x & (-$x)) $bit[$x]++;
    }
    return $inv;
}

function top_overlap(array $a, array $b, int $n = 10): int
{
    return count(array_intersect(array_slice($a, 0, $n), array_slice($b, 0, $n)));
}

/** Average a metric-pair over several seeds. Returns [tau, top10, headDiffFrac]. */
function measure(string $sortA, string $sortB, int $N, float $ceiling, float $rate, float $avgDowns, int $seeds = 6): array
{
    $tau = 0.0; $ov = 0.0; $headDiff = 0.0;
    for ($s = 1; $s <= $seeds; $s++) {
        $pdo = make_pdo();
        populate($pdo, $N, $ceiling, $rate, $avgDowns, 1000 * $s + 7);
        $a = ranked_ids($pdo, $sortA);
        $b = ranked_ids($pdo, $sortB);
        // For hot/new/best/top the sets are identical (full N). Guard anyway.
        if (count($a) === count($b) && !array_diff($a, $b)) {
            $tau += tau_same_set($a, $b);
        } else {
            // fallback: tau over common set is 1 if empty
            $tau += 1.0;
        }
        $ov += top_overlap($a, $b, 10);
        $headDiff += ($a && $b && $a[0] !== $b[0]) ? 1.0 : 0.0;
    }
    return ['tau' => $tau / $seeds, 'top10' => $ov / $seeds, 'headDiff' => $headDiff / $seeds];
}

// ===========================================================================
echo "=======================================================================\n";
echo " HOT vs NEW  -- tau (top10 overlap /10)   [avgDowns=0]\n";
echo " rows = score ceiling (spread in OoM);  cols = posting rate (posts/hour)\n";
echo " N fixed = 1000.  tau ~ +1 means hot IS new; lower means it diverges.\n";
echo "=======================================================================\n";
$rates    = [0.55, 2, 10, 50, 200];
$ceilings = [15, 100, 1000, 10000];
printf("%-18s", "ceiling\\rate");
foreach ($rates as $r) printf("%-16s", $r . "/h");
echo "\n";
foreach ($ceilings as $c) {
    printf("%-18s", sprintf("%d (%.1f OoM)", $c, log10($c)));
    foreach ($rates as $r) {
        $m = measure('hot', 'new', 1000, (float)$c, (float)$r, 0.0, 4);
        printf("%-16s", sprintf("%+.2f (%.1f)", $m['tau'], $m['top10']));
    }
    echo "\n";
}

echo "\n(today's live cell is ceiling~15, rate~0.55/h -> top-left corner)\n";

// ---- HOT vs NEW: locate the divergence threshold along rate, per ceiling ----
echo "\n=======================================================================\n";
echo " HOT vs NEW threshold hunt: at what posting rate does hot's #1 stop\n";
echo " matching new's #1, and tau fall below 0.9 / 0.7 ?  (N=1000)\n";
echo "=======================================================================\n";
foreach ([15, 100, 1000, 10000] as $c) {
    $firstHeadDiff = null; $tau90 = null; $tau70 = null;
    foreach ([0.55, 1, 2, 5, 10, 20, 50, 100, 200, 500] as $r) {
        $m = measure('hot', 'new', 1000, (float)$c, (float)$r, 0.0, 4);
        if ($firstHeadDiff === null && $m['headDiff'] >= 0.5) $firstHeadDiff = $r;
        if ($tau90 === null && $m['tau'] < 0.90) $tau90 = $r;
        if ($tau70 === null && $m['tau'] < 0.70) $tau70 = $r;
    }
    printf("  ceiling %-6d (%.1f OoM): tau<0.9 at ~%s/h, tau<0.7 at ~%s/h, #1 flips at ~%s/h\n",
        $c, log10($c),
        $tau90 ?? '>500', $tau70 ?? '>500', $firstHeadDiff ?? '>500');
}

// ---- VOLUME effect (does simply having MORE posts help?) ----
echo "\n=======================================================================\n";
echo " HOT vs NEW: does VOLUME alone change anything? (ceiling 15, rate 0.55/h\n";
echo " = today's spread & rate, just more posts)\n";
echo "=======================================================================\n";
foreach ([40, 200, 1000, 5000] as $N) {
    $m = measure('hot', 'new', $N, 15.0, 0.55, 0.0, 4);
    printf("  N=%-6d  tau=%+.2f  top10=%.1f/10  #1 differs=%d%%\n",
        $N, $m['tau'], $m['top10'], (int)round($m['headDiff'] * 100));
}

// ===========================================================================
echo "\n=======================================================================\n";
echo " BEST vs TOP  -- how many downvotes per post before best != top ?\n";
echo " tau (top10 /10, #1 differs %).  N=200.\n";
echo "=======================================================================\n";
foreach ([15, 1000] as $c) {
    echo "  score ceiling {$c} (" . sprintf('%.1f', log10($c)) . " OoM):\n";
    foreach ([0.0, 0.25, 0.5, 1.0, 2.0, 5.0, 10.0] as $dn) {
        $m = measure('best', 'top', 200, (float)$c, 2.0, $dn, 6);
        printf("     avgDowns=%-5s tau=%+.2f  top10=%.1f/10  #1differs=%d%%\n",
            rtrim(rtrim(number_format($dn, 2), '0'), '.'), $m['tau'], $m['top10'], (int)round($m['headDiff'] * 100));
    }
}
echo "\n(feddit today: downvotes are scarce - most posts have 0 downvote rows)\n";
