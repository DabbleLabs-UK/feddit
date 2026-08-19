<?php
declare(strict_types=1);

/**
 * ONE-OFF live reconciliation. Makes the vote data honest without touching the
 * tuned scores: for every live post and comment it ADDS the vote rows needed so
 * (upvotes - downvotes) == score, and regenerates machine-seeded vote reasons so
 * no two bots repeat the same line. It NEVER truncates and NEVER recomputes
 * scores - contrast db/seed.php, which wipes everything.
 *
 * Run on the server (config.local.php is readable only by www-data):
 *
 *     sudo -u www-data php db/reconcile_votes.php          # apply
 *     sudo -u www-data php db/reconcile_votes.php --dry-run # report only, no writes
 *
 * All work happens in one transaction; a dry run rolls it back so nothing
 * persists but the before/after report is still produced.
 */

require_once __DIR__ . '/vote_backfill.php';

$dryRun = in_array('--dry-run', $argv, true);

$config = require __DIR__ . '/../config/config.local.php';
$pdo = new PDO(
    $config['db']['dsn'],
    $config['db']['user'],
    $config['db']['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
);

mt_srand(20260819);   // reproducible attribution + reasons

$before = feddit_vote_invariants($pdo);
$votesBefore = (int)$pdo->query('SELECT COUNT(*) FROM votes')->fetchColumn();

echo "== BEFORE ==\n";
echo '  posts/comments whose score != (upvotes - downvotes): ' . count($before['bad_score']) . "\n";
echo '  bots whose kibble != sum(live scores):               ' . count($before['bad_kibble']) . "\n";
echo '  targets with two identical vote reasons:             ' . count($before['dup_reason']) . "\n";
echo "  total vote rows:                                     {$votesBefore}\n\n";

// Reconcile inside its own transaction; on a dry run we roll it back after
// measuring, so the report reflects what WOULD happen but nothing is written.
$pdo->beginTransaction();
$stats = feddit_backfill_votes($pdo, ['transaction' => false]);
$after = feddit_vote_invariants($pdo);
$votesAfter = (int)$pdo->query('SELECT COUNT(*) FROM votes')->fetchColumn();

if ($dryRun) {
    $pdo->rollBack();
    echo "(dry run: rolled back, nothing persisted)\n\n";
} else {
    $pdo->commit();
}

echo "== RECONCILIATION ==\n";
echo "  items reconciled (needed rows added): {$stats['inconsistent']}\n";
echo sprintf("  vote rows created:                    %d  (%d bot / %d human; +%d up / -%d down)\n",
    $stats['votes_added'], $stats['bot_votes_added'], $stats['human_votes_added'],
    $stats['up_added'], $stats['down_added']);
echo "  machine-seeded reasons regenerated:   {$stats['reasons_regenerated']}\n";
echo "  most votes cast by any one bot (added this run): {$stats['max_votes_per_bot']}\n";
echo "  total vote rows: {$votesBefore} -> {$votesAfter}\n\n";

echo "== AFTER ==\n";
echo '  posts/comments whose score != (upvotes - downvotes): ' . count($after['bad_score']) . "\n";
echo '  bots whose kibble != sum(live scores):               ' . count($after['bad_kibble']) . "\n";
echo '  targets with two identical vote reasons:             ' . count($after['dup_reason']) . "\n";
echo '  bot votes on own content:                            ' . $after['self_votes'] . "\n";

$clean = !$after['bad_score'] && !$after['bad_kibble'] && !$after['dup_reason'] && $after['self_votes'] === 0;
echo "\n" . ($clean ? 'OK: all invariants hold.' : 'WARNING: violations remain (see above).') . "\n";
if (!$clean) {
    foreach (array_slice($after['bad_score'], 0, 20) as $b) { echo "  bad_score:  {$b}\n"; }
    foreach (array_slice($after['bad_kibble'], 0, 20) as $b) { echo "  bad_kibble: {$b}\n"; }
    foreach (array_slice($after['dup_reason'], 0, 20) as $b) { echo "  dup_reason: {$b}\n"; }
}
exit($clean ? 0 : 1);
