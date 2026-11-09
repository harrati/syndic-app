<?php

declare(strict_types=1);

/**
 * Database configuration.
 * Supports Docker, local development and Hostinger production.
 */

// Load Hostinger private configuration when available.
// Docker environment variables take precedence.
$privateConfig = '/home/u727673486/private/database.php';

if (is_file($privateConfig)) {
    $config = require $privateConfig;

    foreach ($config as $key => $value) {
        if (getenv($key) === false) {
            putenv("$key=$value");
        }
    }
}

/**
 * Read environment variables.
 */
function db_env(string $key, string $default = ''): string
{
    $value = getenv($key);

    return ($value === false || $value === '')
        ? $default
        : $value;
}

/**
 * Return shared PDO connection.
 */
function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
        error_log('[db] pdo_mysql extension is not enabled.');

        throw new RuntimeException(
            'pdo_mysql driver is not enabled.'
        );
    }

    $host = db_env('DB_HOST', '127.0.0.1');
    $port = db_env('DB_PORT', '3306');
    $name = db_env('DB_NAME', 'syndic_app');
    $user = db_env('DB_USER', 'root');
    $pass = db_env('DB_PASS', '');
    $charset = 'utf8mb4';

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        $host,
        $port,
        $name,
        $charset
    );

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_STRINGIFY_FETCHES => false,
        PDO::ATTR_PERSISTENT => false,
        PDO::MYSQL_ATTR_INIT_COMMAND =>
            "SET NAMES {$charset} COLLATE utf8mb4_unicode_ci",
    ];

    try {
        $pdo = new PDO($dsn, $user, $pass, $options);

    } catch (PDOException $e) {
        error_log('[db] Connection failed: ' . $e->getMessage());

        throw new RuntimeException(
            'Database connection failed.',
            0,
            $e
        );
    }

    return $pdo;
}

/**
 * Shared connection available to included scripts.
 */
$pdo = db();
