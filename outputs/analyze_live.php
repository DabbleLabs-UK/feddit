<?php
declare(strict_types=1);

/**
 * PART 1 analysis: read the live-fetched sort listings (outputs/live/*.json)
 * and compute concrete pairwise similarity numbers for every pair of sorts,
 * per scope (front page + each sub-feddit). Prints matrices.
 *
 * Metrics per pair:
 *   - top10 overlap: how many of the same post ids appear in both top 10
 *   - Kendall tau-a over the COMMON set of posts (both orderings)
 *   - #1 gap: the rank (0-based) that sort A's #1 sits at in sort B's ordering
 *             (and B's #1 in A). 0 == identical head.
 */

$LIVE = __DIR__ . '/live';
$SORTS = ['best', 'hot', 'new', 'rising', 'controversial', 'top'];

/** Extract the ordered list of post ids (plus score/age context) from a listing file. */
function load_listing(string $path): array
{
    if (!is_file($path)) return [];
    $j = json_decode((string)file_get_contents($path), true);
    $out = [];
    foreach ($j['data']['children'] ?? [] as $c) {
        $d = $c['data'];
        $out[] = ['id' => (int)$d['id'], 'score' => (int)$d['score'], 'created_utc' => (int)$d['created_utc']];
    }
    return $out;
}

function ids(array $listing): array { return array_map(fn($r) => $r['id'], $listing); }

/** Kendall tau-a over the set of ids present in BOTH orderings. */
function kendall_tau(array $aIds, array $bIds): array
{
    $common = array_values(array_intersect($aIds, $bIds));
    $n = count($common);
    if ($n < 2) return ['tau' => null, 'n' => $n];
    $ra = array_flip(array_values($aIds));   // id -> rank in A
    $rb = array_flip(array_values($bIds));
    $c = 0; $d = 0;
    for ($i = 0; $i < $n; $i++) {
        for ($k = $i + 1; $k < $n; $k++) {
            $x = $common[$i]; $y = $common[$k];
            $sa = $ra[$x] <=> $ra[$y];
            $sb = $rb[$x] <=> $rb[$y];
            if ($sa === $sb) $c++; else $d++;
        }
    }
    $tau = ($c - $d) / ($n * ($n - 1) / 2);
    return ['tau' => $tau, 'n' => $n];
}

/** Top-N overlap: count of shared ids in the first N of each. */
function top_overlap(array $aIds, array $bIds, int $n = 10): int
{
    $a = array_slice($aIds, 0, $n);
    $b = array_slice($bIds, 0, $n);
    return count(array_intersect($a, $b));
}

/** Rank (0-based) of A's #1 within B's ordering; null if absent. */
function head_gap(array $aIds, array $bIds): ?int
{
    if (!$aIds) return null;
    $pos = array_search($aIds[0], $bIds, true);
    return $pos === false ? null : (int)$pos;
}

function analyze(string $label, array $files): void
{
    global $SORTS;
    $listings = [];
    foreach ($SORTS as $s) {
        $listings[$s] = load_listing($files[$s]);
    }
    echo "\n################################################################\n";
    echo "# SCOPE: {$label}\n";
    echo "################################################################\n";
    echo "Post counts per sort: ";
    foreach ($SORTS as $s) echo "{$s}=" . count($listings[$s]) . "  ";
    echo "\n";

    // Print the actual head of each list for eyeballing.
    echo "\nTop-8 ids by sort (id:score):\n";
    foreach ($SORTS as $s) {
        $head = array_slice($listings[$s], 0, 8);
        $bits = array_map(fn($r) => $r['id'] . ':' . $r['score'], $head);
        printf("  %-14s %s\n", $s, implode(' ', $bits) ?: '(empty)');
    }

    // Build id lists.
    $I = [];
    foreach ($SORTS as $s) $I[$s] = ids($listings[$s]);

    // --- Matrix 1: top-10 overlap ---
    echo "\n-- top-10 overlap (shared ids in each top 10; max 10) --\n";
    print_matrix($SORTS, function ($a, $b) use ($I) {
        return (string)top_overlap($I[$a], $I[$b], 10);
    });

    // --- Matrix 2: Kendall tau over common set ---
    echo "\n-- Kendall tau-a over common set  (+1 identical .. -1 reversed) --\n";
    print_matrix($SORTS, function ($a, $b) use ($I) {
        $r = kendall_tau($I[$a], $I[$b]);
        return $r['tau'] === null ? ' n/a ' : sprintf('%+.2f', $r['tau']);
    });

    // --- Matrix 3: common-set size (context for tau) ---
    echo "\n-- common-set size (n posts both sorts contain) --\n";
    print_matrix($SORTS, function ($a, $b) use ($I) {
        return (string)count(array_intersect($I[$a], $I[$b]));
    });

    // --- Matrix 4: head gap ---
    echo "\n-- #1 gap: rank (0-based) of ROW's #1 within COLUMN's list --\n";
    print_matrix($SORTS, function ($a, $b) use ($I) {
        $g = head_gap($I[$a], $I[$b]);
        return $g === null ? ' - ' : (string)$g;
    });

    // --- Verdict: which pairs are effectively identical lists ---
    echo "\n-- effectively-identical pairs (tau >= 0.95 over full common set) --\n";
    $flagged = 0;
    for ($i = 0; $i < count($SORTS); $i++) {
        for ($k = $i + 1; $k < count($SORTS); $k++) {
            $a = $SORTS[$i]; $b = $SORTS[$k];
            $r = kendall_tau($I[$a], $I[$b]);
            if ($r['tau'] !== null && $r['n'] >= 5 && $r['tau'] >= 0.95) {
                printf("   %s == %s  (tau=%.3f over n=%d; top10 overlap=%d/10)\n",
                    $a, $b, $r['tau'], $r['n'], top_overlap($I[$a], $I[$b], 10));
                $flagged++;
            }
        }
    }
    if (!$flagged) echo "   (none at tau>=0.95)\n";
}

function print_matrix(array $sorts, callable $cell): void
{
    printf("  %-14s", '');
    foreach ($sorts as $s) printf("%-14s", substr($s, 0, 12));
    echo "\n";
    foreach ($sorts as $a) {
        printf("  %-14s", $a);
        foreach ($sorts as $b) {
            $v = $a === $b ? '.' : $cell($a, $b);
            printf("%-14s", $v);
        }
        echo "\n";
    }
}

// -- run for each scope --
$front = [];
foreach ($SORTS as $s) $front[$s] = "$LIVE/front_{$s}.json";
analyze('FRONT PAGE (all feddits)', $front);

foreach (['dataviz', 'localnews'] as $f) {
    $fs = [];
    foreach ($SORTS as $s) $fs[$s] = "$LIVE/f_{$f}_{$s}.json";
    analyze("f/{$f}", $fs);
}
