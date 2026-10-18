<?php
require_once __DIR__ . '/includes/functions.php';
if (is_logged_in()) { header('Location: index.php'); exit; }

$error = null;
$usernameEnabled = users_have_username($pdo);
$settings = integration_settings();
$googleReady = !empty($settings['google_client_id']) && class_exists(\Google\Client::class);
$googleNonce = '';
if ($googleReady) {
    $googleNonce = bin2hex(random_bytes(32));
    $_SESSION['google_login_nonce'] = $googleNonce;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $name  = trim($_POST['name'] ?? '');
    $username = strtolower(trim($_POST['username'] ?? ''));
    $email = trim($_POST['email'] ?? '');
    $pass  = $_POST['password'] ?? '';
    $confirm = $_POST['confirm'] ?? '';

    if (!$name || !$email || !$pass || ($usernameEnabled && !$username)) {
        $error = 'All fields are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a valid email address.';
    } elseif ($usernameEnabled && !preg_match('/^[a-zA-Z0-9._-]{3,32}$/D', $username)) {
        $error = 'Username must be 3-32 characters using letters, numbers, dots, underscores, or hyphens.';
    } elseif (strlen($pass) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($pass !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $stmt = $pdo->prepare('SELECT user_id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $error = 'An account with that email already exists.';
        } else {
            if ($usernameEnabled) {
                $usernameStmt = $pdo->prepare('SELECT user_id FROM users WHERE username = ?');
                $usernameStmt->execute([$username]);
                if ($usernameStmt->fetch()) $error = 'That username is already taken.';
            }
        }

        if (!$error) {
            $hash = password_hash($pass, PASSWORD_BCRYPT);
            if ($usernameEnabled) {
                $pdo->prepare(
                    'INSERT INTO users (name, username, email, password_hash, role) VALUES (?, ?, ?, ?, "customer")'
                )->execute([$name, $username, $email, $hash]);
            } else {
                $pdo->prepare(
                    'INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, "customer")'
                )->execute([$name, $email, $hash]);
            }

            $userId = (int) $pdo->lastInsertId();
            header('Location: ' . establish_user_session([
                'user_id' => $userId,
                'name' => $name,
                'email' => $email,
                'role' => 'customer',
            ]));
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" type="image/png" href="assets/images/brand/brewed-delights-logo.png">
<title>Create account — Brewed Delights</title>
<?php require __DIR__ . '/includes/theme_head.php'; ?>
<link rel="stylesheet" href="assets/css/style.css?v=13">
</head>
<body class="auth-page auth-register">
<div class="auth-theme"><?php require __DIR__ . '/includes/theme_toggle.php'; ?></div>
<div class="auth-card">
    <p class="auth-eyebrow">BREWED DELIGHTS</p>
    <h1 class="brand-mark">Your coffee awaits</h1>
    <p class="auth-sub">Create your account</p>

    <?php if ($error): ?><p class="alert alert-error"><?= e($error) ?></p><?php endif; ?>

    <form method="post" novalidate>
        <?= csrf_field() ?>
        <label>Full name<input type="text" name="name" value="<?= e($_POST['name'] ?? '') ?>" required></label>
        <?php if ($usernameEnabled): ?>
            <label>Username<input type="text" name="username" value="<?= e($_POST['username'] ?? '') ?>" minlength="3" maxlength="32" pattern="[A-Za-z0-9._\-]{3,32}" autocomplete="username" required></label>
        <?php endif; ?>
        <label>Email<input type="email" name="email" value="<?= e($_POST['email'] ?? '') ?>" required></label>
        <label>Password<input type="password" name="password" minlength="8" required></label>
        <label>Confirm password<input type="password" name="confirm" minlength="8" required></label>
        <button type="submit" class="btn btn-primary">Create account</button>
    </form>
    <?php if ($googleReady): ?>
        <div class="auth-divider"><span>or</span></div>
        <div id="g_id_onload"
             data-client_id="<?= e($settings['google_client_id']) ?>"
             data-nonce="<?= e($googleNonce) ?>"
             data-callback="handleGoogleCredential"
             data-auto_prompt="false"></div>
        <div class="g_id_signin" data-type="standard" data-size="large" data-theme="outline"
             data-text="continue_with" data-shape="rectangular" data-width="320"></div>
        <form id="google-login-form" method="post" action="google_login.php" hidden>
            <?= csrf_field() ?>
            <input type="hidden" name="credential" value="">
        </form>
        <script>
            window.handleGoogleCredential = function (response) {
                var form = document.getElementById('google-login-form');
                form.querySelector('[name="credential"]').value = response.credential;
                form.submit();
            };
        </script>
        <script src="https://accounts.google.com/gsi/client" async defer></script>
    <?php endif; ?>
    <p class="auth-foot">Already have an account? <a href="login.php">Log in</a></p>
</div>
<script src="assets/js/theme.js?v=2"></script>
</body>
</html>
