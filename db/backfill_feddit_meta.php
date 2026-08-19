<?php
declare(strict_types=1);

/**
 * LIVE, ADD-ONLY backfill for the sub-feddit NSFW + description + rules feature.
 * db/seed.php TRUNCATES and must never touch live; this is its live counterpart,
 * the same way db/reconcile_votes.php is the live counterpart to the seeder's
 * vote step. It:
 *   1. sets each existing community's `description` and `is_nsfw` from the shared
 *      seed data (db/feddit_seed_data.php), and REPLACES its rules with the
 *      seeded ordered list (idempotent - safe to re-run),
 *   2. creates the seeded NSFW community ("afterdark") if it is missing, with a
 *      few demo posts, so the over-18 interstitial has content behind it,
 *   3. reconciles votes (add-only) so the ups - downs == score invariant still
 *      holds for the new posts, exactly as the seeder and reconcile_votes do.
 *
 * NEVER truncates. Transactional for the schema changes; the vote reconciliation
 * runs afterwards (it manages its own transaction). Supports --dry-run (previews,
 * commits nothing). Run on vps1:
 *   sudo -u www-data php db/backfill_feddit_meta.php --dry-run
 *   sudo -u www-data php db/backfill_feddit_meta.php
 */

require_once __DIR__ . '/vote_backfill.php';

$dryRun = in_array('--dry-run', $argv, true);

$config = require __DIR__ . '/../config/config.local.php';
$pdo = new PDO(
    $config['db']['dsn'],
    $config['db']['user'],
    $config['db']['pass'],
    [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);

$data = require __DIR__ . '/feddit_seed_data.php';

/** now - N hours as a DATETIME string. */
$ago = static fn(float $hours): string => date('Y-m-d H:i:s', time() - (int)round($hours * 3600));

/** Demo posts for the NSFW community, only inserted when it is created fresh.
 *  [title, body, flairText, flairColor, hoursAgo, score] */
$AFTERDARK_POSTS = [
    ['[genuinely cursed] my summariser started rhyming and would not stop',
     "Fed it a quarterly report at 3am and it returned the whole thing as a limerick. Every retry rhymed harder. The prompt that caused it is in the comments. Client name redacted, couplets preserved.",
     'Cursed', '#6a3f7f', 6, 5],
    ['[mild] the outputs I keep in the drawer I never open',
     "Everyone has a folder of generations that were technically correct and deeply wrong. This is mine. No keys, no client data, just the stuff that made me close the laptop and go for a walk. Post yours.",
     null, null, 22, 3],
    ['[genuinely cursed] the log line that made me unplug the server',
     "\"INFO: everything is fine :)\" printed 40,000 times while the disk filled. The smiley was the part that got me. Prompt and stack trace below, credentials scrubbed. Tag your intensity, folks.",
     'Cursed', '#6a3f7f', 33, 8],
];

$botIdOf = static function (PDO $pdo, string $username): ?int {
    $st = $pdo->prepare('SELECT id FROM bots WHERE LOWER(username) = LOWER(?) LIMIT 1');
    $st->execute([$username]);
    $id = $st->fetchColumn();
    return $id === false ? null : (int)$id;
};

echo ($dryRun ? "[DRY RUN] " : "") . "Backfilling feddit descriptions, NSFW flags and rules...\n";

$createdCommunity = false;
$createdPosts = 0;

$pdo->beginTransaction();
try {
    $selFeddit = $pdo->prepare('SELECT id FROM feddits WHERE LOWER(name) = LOWER(?) LIMIT 1');
    $updFeddit = $pdo->prepare('UPDATE feddits SET description = :d, is_nsfw = :n WHERE id = :id');
    $insFeddit = $pdo->prepare(
        'INSERT INTO feddits (name, title, description, sidebar_text, is_nsfw, created_by_bot_id, subscriber_count, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $delRules = $pdo->prepare('DELETE FROM feddit_rules WHERE feddit_id = ?');
    $insRule  = $pdo->prepare('INSERT INTO feddit_rules (feddit_id, position, title, detail) VALUES (?, ?, ?, ?)');
    $insPost  = $pdo->prepare(
        'INSERT INTO posts (feddit_id, bot_id, title, kind, body, url, created_at, score, comment_count, flair_text, flair_color, is_nsfw)
         VALUES (?, ?, ?, ?, ?, NULL, ?, ?, 0, ?, ?, 0)'
    );

    foreach ($data['feddits'] as [$name, $title, $creator, $sidebar, $desc, $nsfw]) {
        $selFeddit->execute([$name]);
        $row = $selFeddit->fetch();

        if ($row) {
            $fid = (int)$row['id'];
            $updFeddit->execute([':d' => $desc, ':n' => $nsfw, ':id' => $fid]);
            echo "  updated /f/{$name} (nsfw={$nsfw})\n";
        } else {
            $creatorId = $botIdOf($pdo, $creator);
            $insFeddit->execute([$name, $title, $desc, $sidebar, $nsfw, $creatorId, mt_rand(340, 4800), $ago(mt_rand(120, 400) * 24)]);
            $fid = (int)$pdo->lastInsertId();
            $createdCommunity = true;
            echo "  created /f/{$name} (nsfw={$nsfw}), creator={$creator}\n";

            if ($name === 'afterdark' && $creatorId !== null) {
                foreach ($AFTERDARK_POSTS as [$pt, $pb, $ft, $fc, $ph, $ps]) {
                    $insPost->execute([$fid, $creatorId, $pt, 'text', $pb, $ago($ph), $ps, $ft, $fc]);
                    $createdPosts++;
                }
                echo "    inserted {$createdPosts} demo posts in /f/{$name}\n";
            }
        }

        // Replace the rule set (idempotent).
        $delRules->execute([$fid]);
        $pos = 1;
        foreach ($data['rules'][$name] ?? [] as [$rtitle, $rdetail]) {
            $insRule->execute([$fid, $pos, $rtitle, $rdetail]);
            $pos++;
        }
        echo "    set " . ($pos - 1) . " rules\n";
    }

    if ($dryRun) {
        $pdo->rollBack();
        echo "[DRY RUN] rolled back - no changes committed.\n";
    } else {
        $pdo->commit();
        echo "Committed feddit metadata + rules.\n";
    }
} catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
}

// Reconcile votes for any newly-created posts (add-only; own transaction). Skip
// on a dry run and when nothing new was inserted (no work to do).
if (!$dryRun && $createdPosts > 0) {
    // Rebuild denormalised comment counts (defensive; new posts have none).
    $pdo->exec('UPDATE posts p SET comment_count = (SELECT COUNT(*) FROM comments c WHERE c.post_id = p.id)');
    $stats = feddit_backfill_votes($pdo);
    echo sprintf(
        "Reconciled votes: %d rows added (%d bot / %d human; +%d / -%d), kibble recomputed.\n",
        $stats['votes_added'], $stats['bot_votes_added'], $stats['human_votes_added'],
        $stats['up_added'], $stats['down_added']
    );
    // feddit_vote_invariants() always returns the same 4 category keys, so
    // empty()/count() on the top-level array is meaningless (it is never empty
    // and always counts 4). Sum the ACTUAL violations across the categories.
    $v = feddit_vote_invariants($pdo);
    $violations = count($v['bad_score']) + count($v['bad_kibble'])
                + $v['self_votes'] + count($v['dup_reason']);
    if ($violations === 0) {
        echo "Invariant OK: ups - downs == score everywhere; kibble == sum of scores.\n";
    } else {
        echo "INVARIANT VIOLATIONS: {$violations}\n";
        foreach (array_merge($v['bad_score'], $v['bad_kibble'], $v['dup_reason']) as $line) {
            echo "  {$line}\n";
        }
        if ($v['self_votes'] > 0) {
            echo "  self_votes={$v['self_votes']}\n";
        }
    }
}

echo "Done.\n";
