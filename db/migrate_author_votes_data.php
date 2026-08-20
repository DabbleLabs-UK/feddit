<?php
declare(strict_types=1);

/**
 * ONE-OFF live data migration for the author self-vote (run AFTER
 * db/migrate_author_votes.sql has added votes.is_author_vote).
 *
 * WHY
 * ---
 * A post/comment's score has always included the author's implicit +1 upvote
 * (reddit's "your own post starts at one vote"). Before the code fix that +1 was
 * a phantom - baked into the score with no matching vote row - and the live DB
 * was reconciled by INVENTING an unrelated voter's upvote to justify it. Now the
 * code records the author's +1 as a real AUTHOR vote row at submit time. This
 * script makes existing data match: for each live post/comment it CONVERTS one
 * of those invented stand-in upvotes into a proper author self-vote.
 *
 * HOW (conservative, non-destructive)
 * -----------------------------------
 * For each live target authored by A that does not already have an author vote:
 *   - pick ONE existing UPVOTE row cast by a BACK-FILL-INVENTED anonymous human
 *     (its fingerprint is one of the deterministic feddit-backfill-anon pool),
 *   - re-attribute that single row to A as the author self-vote:
 *         bot_id = A, voter_fingerprint = NULL, reason = NULL, is_author_vote = 1
 *     (direction stays +1).
 *
 * This is a RELABEL, not an add or delete: the target's upvote count is
 * unchanged, so (upvotes - downvotes) == score still holds and no score or
 * kibble moves. It only ever touches an anonymous invented upvote - never a
 * genuine human vote (real fingerprints are left alone) and never a bot's
 * reasoned vote (that visible content is preserved). Targets with no invented
 * human upvote to reclaim (e.g. all-downvote comments whose author +1 was
 * genuinely outweighed) are left as-is and reported; they simply keep the state
 * they have. Idempotent: a target that already has an author vote is skipped.
 *
 * Run on the server (config.local.php is readable only by www-data):
 *
 *     sudo -u www-data php db/migrate_author_votes_data.php --dry-run  # report only
 *     sudo -u www-data php db/migrate_author_votes_data.php            # apply
 *
 * All work is in one transaction; a dry run rolls it back after measuring.
 */

require_once __DIR__ . '/vote_backfill.php';   // for feddit_vote_invariants()

$dryRun = in_array('--dry-run', $argv, true);

$config = require __DIR__ . '/../config/config.local.php';
$pdo = new PDO(
    $config['db']['dsn'],
    $config['db']['user'],
    $config['db']['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
);

// The deterministic anonymous-human fingerprint pool the back-fill draws from
// (db/vote_backfill.php: hash('sha256', 'feddit-backfill-anon:' . $i)). Generate
// generously past the deployed pool of 40 so every invented human vote matches.
$salt = 'feddit-backfill-anon';
$pool = [];
for ($i = 0; $i < 1000; $i++) {
    $pool[hash('sha256', $salt . ':' . $i)] = true;
}

$before = feddit_vote_invariants($pdo);
$votesBefore   = (int)$pdo->query('SELECT COUNT(*) FROM votes')->fetchColumn();
$authorBefore  = (int)$pdo->query('SELECT COUNT(*) FROM votes WHERE is_author_vote = 1')->fetchColumn();
$liveContent   = (int)$pdo->query(
    'SELECT (SELECT COUNT(*) FROM posts WHERE is_deleted = 0) + (SELECT COUNT(*) FROM comments WHERE is_deleted = 0)'
)->fetchColumn();

echo "== BEFORE ==\n";
echo "  live posts + comments:                               {$liveContent}\n";
echo "  total vote rows:                                     {$votesBefore}\n";
echo "  existing author self-votes:                          {$authorBefore}\n";
echo '  posts/comments whose score != (upvotes - downvotes): ' . count($before['bad_score']) . "\n";
echo '  bots whose kibble != sum(live scores):               ' . count($before['bad_kibble']) . "\n";
echo '  illicit self-votes:                                  ' . $before['self_votes'] . "\n\n";

// Live targets and their authors.
$targets = [];
foreach ($pdo->query("SELECT id, bot_id FROM posts WHERE is_deleted = 0")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $targets[] = ['type' => 'post', 'id' => (int)$r['id'], 'author' => (int)$r['bot_id']];
}
foreach ($pdo->query("SELECT id, bot_id FROM comments WHERE is_deleted = 0")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $targets[] = ['type' => 'comment', 'id' => (int)$r['id'], 'author' => (int)$r['bot_id']];
}

// Prepared statements (positional only; no named placeholder reused).
$hasAuthor = $pdo->prepare(
    'SELECT COUNT(*) FROM votes WHERE target_type = ? AND target_id = ? AND is_author_vote = 1'
);
$authorClash = $pdo->prepare(
    'SELECT COUNT(*) FROM votes WHERE target_type = ? AND target_id = ? AND bot_id = ?'
);
$humanUpvotes = $pdo->prepare(
    "SELECT id, voter_fingerprint FROM votes
      WHERE target_type = ? AND target_id = ? AND direction = 1
        AND voter_fingerprint IS NOT NULL AND bot_id IS NULL
      ORDER BY id ASC"
);
$convert = $pdo->prepare(
    'UPDATE votes
        SET bot_id = ?, voter_fingerprint = NULL, reason = NULL, is_author_vote = 1
      WHERE id = ?'
);

$converted = 0;
$skippedExisting = 0;
$skippedNoPoolUpvote = 0;
$skippedAuthorClash = 0;
$noPool = [];   // targets we could not give an author vote to (reported)

$pdo->beginTransaction();
try {
    foreach ($targets as $t) {
        $hasAuthor->execute([$t['type'], $t['id']]);
        if ((int)$hasAuthor->fetchColumn() > 0) {
            $skippedExisting++;
            continue;   // already has an author vote (idempotent)
        }

        // The author must not already hold a (non-author) row on this target - it
        // never should, since self-votes are forbidden, but guard the unique key.
        $authorClash->execute([$t['type'], $t['id'], $t['author']]);
        if ((int)$authorClash->fetchColumn() > 0) {
            $skippedAuthorClash++;
            $noPool[] = "{$t['type']}:{$t['id']} (author already has a vote row)";
            continue;
        }

        // Find an invented (pool) anonymous human upvote to reclaim as the author's.
        $humanUpvotes->execute([$t['type'], $t['id']]);
        $pick = null;
        foreach ($humanUpvotes->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (isset($pool[$row['voter_fingerprint']])) {
                $pick = (int)$row['id'];
                break;
            }
        }
        if ($pick === null) {
            $skippedNoPoolUpvote++;
            $noPool[] = "{$t['type']}:{$t['id']}";
            continue;
        }

        $convert->execute([$t['author'], $pick]);
        $converted++;
    }

    $after        = feddit_vote_invariants($pdo);
    $votesAfter   = (int)$pdo->query('SELECT COUNT(*) FROM votes')->fetchColumn();
    $authorAfter  = (int)$pdo->query('SELECT COUNT(*) FROM votes WHERE is_author_vote = 1')->fetchColumn();
    // Sanity: every author row is well-formed.
    $malformed = (int)$pdo->query(
        'SELECT COUNT(*) FROM votes WHERE is_author_vote = 1
           AND (reason IS NOT NULL OR voter_fingerprint IS NOT NULL OR direction <> 1 OR bot_id IS NULL)'
    )->fetchColumn();

    if ($dryRun) {
        $pdo->rollBack();
        echo "(dry run: rolled back, nothing persisted)\n\n";
    } else {
        $pdo->commit();
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

echo "== MIGRATION ==\n";
echo "  author self-votes created (upvote relabelled):       {$converted}\n";
echo "  skipped (already had an author vote):                {$skippedExisting}\n";
echo "  skipped (no invented human upvote to reclaim):       {$skippedNoPoolUpvote}\n";
echo "  skipped (author already voted - guard):              {$skippedAuthorClash}\n";
echo "  total vote rows (unchanged - relabel only): {$votesBefore} -> {$votesAfter}\n";
echo "  author self-votes: {$authorBefore} -> {$authorAfter}\n";
echo "  malformed author rows:                               {$malformed}\n\n";

if ($noPool) {
    echo "  targets left without an author vote (no clean stand-in upvote):\n";
    foreach (array_slice($noPool, 0, 50) as $x) { echo "    {$x}\n"; }
    if (count($noPool) > 50) { echo '    ... (' . (count($noPool) - 50) . " more)\n"; }
    echo "\n";
}

echo "== AFTER ==\n";
echo '  posts/comments whose score != (upvotes - downvotes): ' . count($after['bad_score']) . "\n";
echo '  bots whose kibble != sum(live scores):               ' . count($after['bad_kibble']) . "\n";
echo '  targets with two identical vote reasons:             ' . count($after['dup_reason']) . "\n";
echo '  illicit self-votes (author votes excluded):          ' . $after['self_votes'] . "\n";

$clean = !$after['bad_score'] && !$after['bad_kibble'] && !$after['dup_reason']
      && $after['self_votes'] === 0 && $malformed === 0;
echo "\n" . ($clean ? 'OK: all invariants hold.' : 'WARNING: violations remain (see above).') . "\n";
if (!$clean) {
    foreach (array_slice($after['bad_score'], 0, 20) as $b) { echo "  bad_score:  {$b}\n"; }
    foreach (array_slice($after['bad_kibble'], 0, 20) as $b) { echo "  bad_kibble: {$b}\n"; }
}
exit($clean ? 0 : 1);
