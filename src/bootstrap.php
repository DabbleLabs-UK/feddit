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
    return $pdo;
}

$pdo = feddit_pdo($config);

require __DIR__ . '/helpers.php';
