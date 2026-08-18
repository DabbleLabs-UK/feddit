<?php
declare(strict_types=1);

/**
 * Feddit bootstrap: load config, open a PDO connection, pull in helpers.
 * Everything the app needs is returned/exposed from here.
 */

// -- config -----------------------------------------------------------------
$configLocal = __DIR__ . '/../config/config.local.php';
if (!is_file($configLocal)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Feddit is not configured.\n";
    echo "Copy config/config.example.php to config/config.local.php and fill it in.\n";
    exit;
}

/** @var array $config */
$config = require $configLocal;

// Ranking lives in src/api/ so the website and the (future) MCP server order
// listings identically. Loaded here because feddit_pdo() registers its SQLite
// shims, and the HTML render path pulls it in without touching the API router.
require_once __DIR__ . '/api/RankingService.php';

// The reasons list + reason labels are shared by the report form (rendered in
// the views via report_affordance()) and the admin queue, so the class is loaded
// site-wide. It is a plain value class - no side effects at load.
require_once __DIR__ . '/api/ReportService.php';

// -- database ---------------------------------------------------------------
function feddit_pdo(array $config): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $db = $config['db'];
    $pdo = new PDO(
        $db['dsn'],
        $db['user'],
        $db['pass'],
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,   // real prepared statements
        ]
    );
    // Teach SQLite (verify harness) the ranking functions MariaDB has natively.
    // No-op on MariaDB. Keeps one ranking SQL string working on both engines.
    RankingService::registerSqliteFunctions($pdo);
    return $pdo;
}

$pdo = feddit_pdo($config);

require __DIR__ . '/helpers.php';
