<?php

declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

/**
 * Read an environment variable with a fallback default.
 */
function db_env(string $key, string $default = ''): string
{
    $value = getenv($key);

    return ($value === false || $value === '') ? $default : $value;
}

/**
 * Build (once per request) the shared PDO connection.
 *
 * @throws PDOException when the server cannot be reached or credentials are wrong.
 */
function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
        error_log('[db] pdo_mysql is not enabled in this PHP build.');

        throw new RuntimeException('pdo_mysql driver is not enabled.');
    }

    $host    = db_env('DB_HOST', '127.0.0.1');
    $port    = db_env('DB_PORT', '3306');
    // CORRECTION : syndic_app avec underscore pour correspondre à schema.sql
    $name    = db_env('DB_NAME', 'syndic_app'); 
    $user    = db_env('DB_USER', 'root');
    // CORRECTION : Ajustez la valeur par défaut selon votre environnement local (ex: '' pour XAMPP/WAMP, ou votre mot de passe root)
    $pass    = db_env('DB_PASS', 'root-pwd'); 
    $charset = 'utf8mb4';

    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $host, $port, $name, $charset);

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_STRINGIFY_FETCHES  => false,
        PDO::ATTR_PERSISTENT         => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$charset} COLLATE utf8mb4_unicode_ci",
    ];

    try {
        $pdo = new PDO($dsn, $user, $pass, $options);
        print_r($pdo);
    } catch (PDOException $e) {
        error_log('[db] connection failed: ' . $e->getMessage());

        // Transmettre le vrai message d'erreur PDO en dev local pour diagnostiquer facilement
        throw new PDOException('Database connection failed: ' . $e->getMessage(), (int)$e->getCode(), $e);
    }

    return $pdo;
}

/**
 * Shared connection handle available to every script that includes this file.
 *
 * @var PDO $pdo
 */
$pdo = db();