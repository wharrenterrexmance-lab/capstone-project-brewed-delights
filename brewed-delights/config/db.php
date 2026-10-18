<?php
require_once __DIR__ . '/app.php';

/** Database connection settings can be supplied by the deployment environment. */
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'brewed_delights_current');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    error_log('DB connection failed: ' . $e->getMessage());
    if (APP_ENV === 'production') {
        die('Something went wrong connecting to the database. Please try again shortly.');
    }
    die('Database connection failed. Check config/db.php and confirm the '
        . '"' . DB_NAME . '" database has been imported. (' . $e->getMessage() . ')');
}
