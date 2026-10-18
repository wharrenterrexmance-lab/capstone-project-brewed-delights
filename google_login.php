<?php
require_once __DIR__ . '/includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Use POST.');
}

verify_csrf();
$settings = integration_settings();
$credential = trim($_POST['credential'] ?? '');
$expectedNonce = (string) ($_SESSION['google_login_nonce'] ?? '');
unset($_SESSION['google_login_nonce']);

if (!$settings['google_client_id'] || !class_exists(\Google\Client::class)) {
    header('Location: login.php?oauth_error=unavailable');
    exit;
}

try {
    $client = new \Google\Client();
    $client->setClientId($settings['google_client_id']);
    $claims = $credential !== '' ? $client->verifyIdToken($credential) : false;

    if (!$claims || $expectedNonce === '' || !hash_equals($expectedNonce, (string) ($claims['nonce'] ?? ''))
        || ($claims['email_verified'] ?? false) !== true) {
        throw new RuntimeException('Google ID token verification failed.');
    }

    $subject = (string) ($claims['sub'] ?? '');
    $email = strtolower(trim((string) ($claims['email'] ?? '')));
    $name = trim((string) ($claims['name'] ?? ''));
    if ($subject === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Required Google account claims are missing.');
    }
    if ($name === '') $name = strstr($email, '@', true) ?: 'Customer';
    $name = mb_substr($name, 0, 100);

    $pdo->beginTransaction();
    $identityStmt = $pdo->prepare(
        'SELECT u.* FROM user_identities i JOIN users u ON u.user_id = i.user_id
         WHERE i.provider = "google" AND i.provider_subject = ?'
    );
    $identityStmt->execute([$subject]);
    $user = $identityStmt->fetch();

    if ($user) {
        if ($user['role'] !== 'customer') {
            $pdo->rollBack();
            header('Location: login.php?oauth_error=account');
            exit;
        }
    } else {
        $emailStmt = $pdo->prepare('SELECT * FROM users WHERE email = ? FOR UPDATE');
        $emailStmt->execute([$email]);
        $user = $emailStmt->fetch();

        if ($user && $user['role'] !== 'customer') {
            $pdo->rollBack();
            header('Location: login.php?oauth_error=account');
            exit;
        }
        if (!$user) {
            $pdo->prepare(
                'INSERT INTO users (name, email, email_verified, password_hash, role)
                 VALUES (?, ?, 1, NULL, "customer")'
            )->execute([$name, $email]);
            $userId = (int) $pdo->lastInsertId();
            $user = ['user_id' => $userId, 'name' => $name, 'email' => $email, 'role' => 'customer'];
        } else {
            $pdo->prepare('UPDATE users SET email_verified = 1 WHERE user_id = ?')->execute([$user['user_id']]);
        }

        $pdo->prepare(
            'INSERT INTO user_identities (user_id, provider, provider_subject) VALUES (?, "google", ?)'
        )->execute([$user['user_id'], $subject]);
    }

    $pdo->commit();
    header('Location: ' . establish_user_session($user));
    exit;
} catch (Throwable $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Google sign-in failed: ' . $ex->getMessage());
    header('Location: login.php?oauth_error=failed');
    exit;
}