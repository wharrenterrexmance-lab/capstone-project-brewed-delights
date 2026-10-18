<?php
/**
 * FUTURE WORK: this API is not consumed by anything in the current
 * web system — it exists as a working foundation for a future
 * companion mobile app, per this project's Chapter 5 recommendations
 * for future development. The website itself uses session-based
 * login instead; see includes/functions.php.
 *
 * POST /api/auth.php
 * Body (JSON): { "email": "...", "password": "...", "label": "mobile app" }
 * Response:    { "ok": true, "token": "...", "expires_in_days": 30,
 *                "user": { "user_id", "name", "email", "role" } }
 *
 * Reuses the same login_attempts rate limiting as the website's login.php —
 * 5 failed attempts locks that email+IP out for 15 minutes.
 */
require_once __DIR__ . '/../includes/api_auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_fail(405, 'Use POST.');
}

$body = api_json_body();
$email = trim($body['email'] ?? '');
$pass  = $body['password'] ?? '';
$label = isset($body['label']) ? substr(trim((string) $body['label']), 0, 100) : null;

if (!$email || !$pass) {
    api_fail(422, 'email and password are required.');
}

$lockedMins = login_lockout_remaining($pdo, $email);
if ($lockedMins > 0) {
    api_fail(429, "Too many failed attempts. Try again in about {$lockedMins} minute(s).");
}

$stmt = $pdo->prepare('SELECT * FROM users WHERE email = ?');
$stmt->execute([$email]);
$user = $stmt->fetch();

if (!$user || empty($user['password_hash']) || !password_verify($pass, $user['password_hash'])) {
    record_failed_login($pdo, $email);
    api_fail(401, 'Incorrect email or password.');
}

clear_login_attempts($pdo, $email);
$token = api_issue_token($pdo, (int) $user['user_id'], $label);

api_ok([
    'token' => $token,
    'expires_in_days' => 30,
    'user' => [
        'user_id' => (int) $user['user_id'],
        'name'    => $user['name'],
        'email'   => $user['email'],
        'role'    => $user['role'],
    ],
]);
