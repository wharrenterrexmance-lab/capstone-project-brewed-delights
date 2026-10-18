<?php
/**
 * Environment / error-display config.
 *
 * Production-safe by default. Set APP_ENV=development only on a local
 * development server; displaying PHP errors publicly can expose internals.
 */
$configuredEnvironment = strtolower((string) (getenv('APP_ENV') ?: 'production'));
define('APP_ENV', $configuredEnvironment === 'development' ? 'development' : 'production');

$logsDir = __DIR__ . '/../logs';
if (!is_dir($logsDir)) {
    @mkdir($logsDir, 0755, true);
}

if (APP_ENV === 'production') {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
} else {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
}
error_reporting(E_ALL);
ini_set('log_errors', '1');
ini_set('error_log', $logsDir . '/error.log');

/** Session cookie hardening — applied before session_start() runs. */
if (session_status() === PHP_SESSION_NONE) {
    // A TLS-terminating reverse proxy must overwrite X-Forwarded-Proto.
    $isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,   // JS can't read the session cookie
        'samesite' => 'Lax',  // basic cross-site request protection
        'secure'   => $isHttps,
    ]);
}
