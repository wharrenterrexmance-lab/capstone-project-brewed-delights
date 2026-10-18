<?php
require_once __DIR__ . '/functions.php';

/**
 * Minimal bearer-token auth for the JSON API (/api/*.php).
 * Separate from the session-based login used by the website itself —
 * this is what a future mobile app (or any external client) would use.
 *
 * Flow:
 *   1. Client POSTs email+password to /api/auth.php
 *   2. Server returns a random 64-char token (shown once, stored as a
 *      SHA-256 hash in api_tokens — never stored raw, same principle
 *      as password hashing)
 *   3. Client sends "Authorization: Bearer <token>" on every later request
 */

header('Content-Type: application/json');

/** Send a JSON error and stop execution. */
function api_fail(int $httpCode, string $message): never {
    http_response_code($httpCode);
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}

/** Send a JSON success payload and stop execution. */
function api_ok(array $data = []): never {
    echo json_encode(['ok' => true] + $data);
    exit;
}

/** Reads the Authorization header across Apache and other server configurations. */
function api_authorization_header(): string {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if ($header === '' && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        $header = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    }
    return $header;
}

/**
 * Issues a new token for a user. Returns the RAW token — this is the
 * only moment it's ever visible; only its hash is stored.
 */
function api_issue_token(PDO $pdo, int $userId, ?string $label = null, int $validDays = 30): string {
    $rawToken = bin2hex(random_bytes(32)); // 64 hex chars
    $hash = hash('sha256', $rawToken);

    $pdo->prepare(
        'INSERT INTO api_tokens (user_id, token_hash, label, expires_at)
         VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? DAY))'
    )->execute([$userId, $hash, $label, $validDays]);

    return $rawToken;
}

/**
 * Reads the Authorization header, validates the token, and returns the
 * authenticated user's row. Fails the request (401) if missing/invalid/expired.
 */
function api_authenticate(PDO $pdo): array {
    $header = api_authorization_header();
    if (!preg_match('/Bearer\s+([A-Za-z0-9]+)/i', $header, $m)) {
        api_fail(401, 'Missing or malformed Authorization header. Expected: Bearer <token>.');
    }

    $hash = hash('sha256', $m[1]);
    $stmt = $pdo->prepare(
        'SELECT t.*, u.user_id, u.name, u.email, u.role
         FROM api_tokens t
         JOIN users u ON u.user_id = t.user_id
         WHERE t.token_hash = ? AND t.revoked = 0 AND t.expires_at > NOW()'
    );
    $stmt->execute([$hash]);
    $row = $stmt->fetch();

    if (!$row) {
        api_fail(401, 'Invalid, expired, or revoked token.');
    }

    $pdo->prepare('UPDATE api_tokens SET last_used_at = NOW() WHERE token_id = ?')
        ->execute([$row['token_id']]);

    return [
        'user_id' => (int) $row['user_id'],
        'name'    => $row['name'],
        'email'   => $row['email'],
        'role'    => $row['role'],
    ];
}

/** Reads a JSON request body into an associative array (empty array on failure). */
function api_json_body(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}
