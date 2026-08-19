<?php
declare(strict_types=1);

/**
 * Link-preview worker: drains the queue of kind='link' posts awaiting an
 * out-of-band preview fetch and fills in their og_* metadata + cached thumbnail.
 *
 * This runs from CRON, never in the submit request - so submitting a post stays
 * fast and can never fail because a publisher is slow or down. A link post
 * renders with the plain LINK fallback box until this worker reaches it.
 *
 * Run on vps1 (repo owned by www-data, config readable only by www-data):
 *   sudo -u www-data php /home/dabblela/feddit/db/og_worker.php
 * Cron drains it every couple of minutes under flock (see the deploy notes).
 *
 * What it fetches, and the limits, all live in LinkPreviewService:
 *   - target <head> ONLY (never article body text -> paywalls handled ethically),
 *   - through the SSRF-hardened, IP-pinned, per-hop-revalidated path,
 *   - 5s timeout, <=3 redirects, hard body-size cap, robots.txt honoured,
 *   - a real identifying User-Agent naming Feddit with a contact URL.
 * Failures are recorded in og_status and retried with backoff up to a hard cap,
 * then given up on - never retried forever.
 */

// Only class definitions here - no side effects - so the verify harness can
// require this file for its functions without opening a DB. The runtime deps
// ($pdo, $config) are loaded in the CLI entry point at the bottom.
require_once __DIR__ . '/../src/api/ApiException.php';
require_once __DIR__ . '/../src/api/SsrfGuard.php';
require_once __DIR__ . '/../src/api/LinkFetchError.php';
require_once __DIR__ . '/../src/api/LinkPreviewService.php';
require_once __DIR__ . '/../src/api/AvatarService.php';
require_once __DIR__ . '/../src/api/ThumbnailService.php';

/** Give up after this many attempts, so a dead publisher is not retried forever. */
function feddit_og_max_attempts(array $config): int
{
    return max(1, (int)($config['link_preview']['max_attempts'] ?? 3));
}

/** Minimum gap between retry attempts on a failed post (seconds) - simple backoff. */
function feddit_og_retry_gap(array $config): int
{
    return max(60, (int)($config['link_preview']['retry_gap_seconds'] ?? 1800));
}

/** How many posts one worker run drains. */
function feddit_og_batch_size(array $config): int
{
    return max(1, (int)($config['link_preview']['batch_size'] ?? 25));
}

/**
 * Process ONE queued link post: fetch its head, cache a thumbnail if it has a
 * usable og:image, and write the result back. Returns the og_status recorded.
 * Never throws - every failure is caught and turned into a stored status so the
 * worker loop keeps draining. Shared by the CLI loop AND the verify harness.
 */
function feddit_og_process_post(PDO $pdo, array $config, array $row): string
{
    $postId   = (int)$row['id'];
    $url      = (string)$row['url'];
    $attempts = (int)$row['og_attempts'] + 1;
    $maxAttempts = feddit_og_max_attempts($config);
    $now = date('Y-m-d H:i:s');

    try {
        $head = LinkPreviewService::fetchHead($url, $config);
        $meta = $head['meta'];

        $thumbUrl = null;
        $status   = 'no_image';
        if ($meta['image'] !== null && $meta['image'] !== '') {
            try {
                $bytes  = LinkPreviewService::fetchImageBytes($meta['image'], $config);
                $stored = ThumbnailService::store($postId, $bytes);
                if ($stored !== null) {
                    $thumbUrl = $stored;
                    $status   = 'ok';
                }
            } catch (LinkFetchError $ie) {
                // We still have the text metadata; just no usable image.
                $status = 'no_image';
            }
        }

        $st = $pdo->prepare(
            'UPDATE posts
                SET thumbnail_url = ?, og_title = ?, og_description = ?, og_site_name = ?,
                    og_status = ?, og_fetched_at = ?, og_attempts = ?
              WHERE id = ?'
        );
        $st->execute([
            $thumbUrl, $meta['title'], $meta['description'], $meta['site_name'],
            $status, $now, $attempts, $postId,
        ]);
        return $status;
    } catch (LinkFetchError $e) {
        // A terminal refusal (SSRF/robots/malformed) must never be retried: bump
        // attempts to the cap so the claim query never re-selects it. A retryable
        // failure just increments and backs off until the cap gives up.
        $storedAttempts = $e->terminal ? $maxAttempts : $attempts;
        $st = $pdo->prepare(
            'UPDATE posts SET og_status = ?, og_fetched_at = ?, og_attempts = ? WHERE id = ?'
        );
        $st->execute([$e->status, $now, $storedAttempts, $postId]);
        return $e->status;
    } catch (Throwable $t) {
        // Unexpected error: treat as a retryable failure and move on.
        error_log('[feddit-og] post ' . $postId . ': ' . $t->getMessage());
        $st = $pdo->prepare(
            'UPDATE posts SET og_status = ?, og_fetched_at = ?, og_attempts = ? WHERE id = ?'
        );
        $st->execute(['failed', $now, $attempts, $postId]);
        return 'failed';
    }
}

/**
 * Claim and process one batch of queued link posts. Returns a per-status tally.
 * A post is claimable when it is a live link post that is either 'pending' or a
 * 'failed' one that is under the attempt cap and past its backoff window.
 */
function feddit_og_run(PDO $pdo, array $config): array
{
    $maxAttempts = feddit_og_max_attempts($config);
    $cutoff      = date('Y-m-d H:i:s', time() - feddit_og_retry_gap($config));
    $batch       = feddit_og_batch_size($config);

    // Distinct positional placeholders throughout - no named placeholder reused.
    $st = $pdo->prepare(
        "SELECT id, url, og_attempts
           FROM posts
          WHERE kind = 'link' AND url IS NOT NULL AND is_deleted = 0
            AND og_status IN ('pending', 'failed')
            AND og_attempts < ?
            AND (og_fetched_at IS NULL OR og_fetched_at <= ?)
          ORDER BY og_attempts ASC, id ASC
          LIMIT ?"
    );
    $st->bindValue(1, $maxAttempts, PDO::PARAM_INT);
    $st->bindValue(2, $cutoff);
    $st->bindValue(3, $batch, PDO::PARAM_INT);
    $st->execute();
    $rows = $st->fetchAll();

    $tally = ['ok' => 0, 'no_image' => 0, 'failed' => 0, 'blocked' => 0, 'skipped' => 0, 'total' => 0];
    foreach ($rows as $row) {
        $status = feddit_og_process_post($pdo, $config, $row);
        $tally[$status] = ($tally[$status] ?? 0) + 1;
        $tally['total']++;
    }
    return $tally;
}

// -- CLI entry point --------------------------------------------------------
// Only run the drain loop when executed directly (so verify/api_test.php can
// require this file for its unit-level functions without draining anything).
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    require __DIR__ . '/../src/bootstrap.php';   // $pdo + $config for the real run
    $tally = feddit_og_run($pdo, $config);
    fwrite(STDOUT, sprintf(
        "[feddit-og] %s processed=%d ok=%d no_image=%d failed=%d blocked=%d\n",
        gmdate('Y-m-d H:i:s'),
        $tally['total'], $tally['ok'], $tally['no_image'], $tally['failed'], $tally['blocked']
    ));
    exit(0);
}
