<?php
declare(strict_types=1);

// Uses the real configured connection; bootstrap has no migration or write path.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../src/api/ObservationService.php';
$options = getopt('', ['window-hours:', 'limit:', 'bootstrap:', 'selection:', 'help']);
if (isset($options['help'])) {
    echo "Usage: php bin/export-evidence.php --window-hours=24 --limit=100 [--selection=recent|most-voted] [--bootstrap=/path/to/src/bootstrap.php]\n";
    exit(0);
}
foreach (['window-hours', 'limit'] as $name) {
    if (isset($options[$name]) && (!is_string($options[$name]) || !ctype_digit($options[$name]))) {
        fwrite(STDERR, "Options must be positive integers.\n");
        exit(1);
    }
}
$selection = $options['selection'] ?? 'recent';
if (!is_string($selection) || !in_array($selection, ['recent', 'most-voted'], true)) {
    fwrite(STDERR, "Selection must be recent or most-voted.\n");
    exit(1);
}
$bootstrap = $options['bootstrap'] ?? __DIR__ . '/../src/bootstrap.php';
if (!is_string($bootstrap) || !is_file($bootstrap) || !is_file(dirname($bootstrap) . '/../config/config.local.php')) {
    fwrite(STDERR, "Feddit is not configured; provide its existing src/bootstrap.php and config/config.local.php.\n");
    exit(1);
}
try {
    require $bootstrap;
    echo json_encode(ObservationService::snapshot($pdo, (int)($options['window-hours'] ?? 24), (int)($options['limit'] ?? 100), null, $selection), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Evidence export failed. Check the configured database and schema.\n");
    exit(1);
}
