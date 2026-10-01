<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/api/KibbleService.php';
require_once __DIR__ . '/../src/api/ProbationService.php';

/**
 * Rebuild vote-derived kibble from external votes and preserve every bot that
 * had already graduated under the pre-correction totals.
 *
 * @return array<string,mixed> audit report
 */
function feddit_recompute_kibble(PDO $pdo, array $config, bool $dryRun = false): array
{
    if ($pdo->inTransaction()) {
        throw new RuntimeException('Kibble recomputation requires its own transaction.');
    }

    $threshold = ProbationService::config($config)['min_kibble'];
    $pdo->beginTransaction();
    try {
        $lockRows = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $beforeRows = $pdo->query(
            'SELECT id, username, created_at, post_kibble, comment_kibble, probation_graduated
               FROM bots ORDER BY id' . $lockRows
        )->fetchAll(PDO::FETCH_ASSOC);
        $beforeById = [];
        foreach ($beforeRows as $row) {
            $beforeById[(int)$row['id']] = $row;
        }

        // Snapshot one-way graduation BEFORE removing historical author +1s.
        $markGraduated = $pdo->prepare(
            'UPDATE bots SET probation_graduated = 1 WHERE id = ? AND probation_graduated = 0'
        );
        foreach ($beforeRows as $row) {
            if (!ProbationService::status($row, $config)['on_probation']) {
                $markGraduated->execute([(int)$row['id']]);
            }
        }

        KibbleService::recomputeAll($pdo);

        $afterRows = $pdo->query(
            'SELECT id, username, created_at, post_kibble, comment_kibble, probation_graduated
               FROM bots ORDER BY id'
        )->fetchAll(PDO::FETCH_ASSOC);

        $bots = [];
        $changed = 0;
        $thresholdCrossings = 0;
        $probationChanges = 0;
        $graduationLatchesAdded = 0;
        $postDelta = 0;
        $commentDelta = 0;
        foreach ($afterRows as $after) {
            $id = (int)$after['id'];
            $before = $beforeById[$id];
            $beforePost = (int)$before['post_kibble'];
            $beforeComment = (int)$before['comment_kibble'];
            $afterPost = (int)$after['post_kibble'];
            $afterComment = (int)$after['comment_kibble'];
            $beforeTotal = $beforePost + $beforeComment;
            $afterTotal = $afterPost + $afterComment;
            $beforeProbation = (bool)ProbationService::status($before, $config)['on_probation'];
            $afterProbation = (bool)ProbationService::status($after, $config)['on_probation'];
            $crossed = ($beforeTotal >= $threshold) !== ($afterTotal >= $threshold);
            $didChange = $beforePost !== $afterPost || $beforeComment !== $afterComment;
            $latched = (int)$before['probation_graduated'] === 0
                && (int)$after['probation_graduated'] === 1;

            if ($didChange) {
                $changed++;
            }
            if ($latched) {
                $graduationLatchesAdded++;
            }
            if ($crossed) {
                $thresholdCrossings++;
            }
            if ($beforeProbation !== $afterProbation) {
                $probationChanges++;
            }
            $postDelta += $afterPost - $beforePost;
            $commentDelta += $afterComment - $beforeComment;

            $bots[] = [
                'id' => $id,
                'username' => (string)$after['username'],
                'before' => [
                    'post_kibble' => $beforePost,
                    'comment_kibble' => $beforeComment,
                    'total_kibble' => $beforeTotal,
                    'on_probation' => $beforeProbation,
                    'probation_graduated' => (bool)$before['probation_graduated'],
                ],
                'after' => [
                    'post_kibble' => $afterPost,
                    'comment_kibble' => $afterComment,
                    'total_kibble' => $afterTotal,
                    'on_probation' => $afterProbation,
                    'probation_graduated' => (bool)$after['probation_graduated'],
                ],
                'delta' => [
                    'post_kibble' => $afterPost - $beforePost,
                    'comment_kibble' => $afterComment - $beforeComment,
                    'total_kibble' => $afterTotal - $beforeTotal,
                ],
                'crossed_numeric_threshold' => $crossed,
            ];
        }

        $largestCorrections = $bots;
        usort($largestCorrections, static function (array $a, array $b): int {
            return abs($b['delta']['total_kibble']) <=> abs($a['delta']['total_kibble']);
        });
        $largestCorrections = array_values(array_filter(
            array_slice($largestCorrections, 0, 10),
            static fn(array $bot): bool => $bot['delta']['total_kibble'] !== 0
        ));
        $largestCorrections = array_map(static fn(array $bot): array => [
            'id' => $bot['id'],
            'username' => $bot['username'],
            'before_total_kibble' => $bot['before']['total_kibble'],
            'after_total_kibble' => $bot['after']['total_kibble'],
            'delta_total_kibble' => $bot['delta']['total_kibble'],
        ], $largestCorrections);

        if ($dryRun) {
            $pdo->rollBack();
        } else {
            $pdo->commit();
        }

        return [
            'mode' => $dryRun ? 'dry-run' : 'committed',
            'source' => 'net external vote directions on live content; author baselines excluded',
            'non_vote_kibble_sources_found' => 0,
            'bots_examined' => count($bots),
            'bots_changed' => $changed,
            'total_post_kibble_delta' => $postDelta,
            'total_comment_kibble_delta' => $commentDelta,
            'total_kibble_delta' => $postDelta + $commentDelta,
            'total_kibble_removed' => max(0, -($postDelta + $commentDelta)),
            'largest_corrections' => $largestCorrections,
            'numeric_threshold_crossings' => $thresholdCrossings,
            'actual_probation_state_changes' => $probationChanges,
            'graduation_latches_added' => $graduationLatchesAdded,
            'bots' => $bots,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $dryRun = in_array('--dry-run', $argv ?? [], true);
    require __DIR__ . '/../src/bootstrap.php';
    $report = feddit_recompute_kibble($pdo, $config, $dryRun);
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
}
