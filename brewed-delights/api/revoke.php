<?php
/**
 * FUTURE WORK: see api/auth.php for context — this whole /api folder
 * is a working foundation for a future mobile app, not used by the
 * current web system.
 *
 * POST /api/revoke.php
 * Header: Authorization: Bearer <token>
 * Revokes the token used to make this request — equivalent to "log out"
 * for an API client. The client should discard the token afterward.
 */
require_once __DIR__ . '/../includes/api_auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_fail(405, 'Use POST.');
}

$header = api_authorization_header();
if (!preg_match('/Bearer\s+([A-Za-z0-9]+)/i', $header, $m)) {
    api_fail(401, 'Missing or malformed Authorization header.');
}

$hash = hash('sha256', $m[1]);
$pdo->prepare('UPDATE api_tokens SET revoked = 1 WHERE token_hash = ?')->execute([$hash]);

api_ok(['message' => 'Token revoked.']);
