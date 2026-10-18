<?php
require_once __DIR__ . '/includes/functions.php';
if (is_logged_in()) { header('Location: index.php'); exit; }

$usernameEnabled = users_have_username($pdo);
$settings = integration_settings();
$googleReady = !empty($settings['google_client_id']) && class_exists(\Google\Client::class);
$googleNonce = '';
if ($googleReady) {
    $googleNonce = bin2hex(random_bytes(32));
    $_SESSION['google_login_nonce'] = $googleNonce;
}
$error = match ($_GET['oauth_error'] ?? '') {
    'unavailable' => 'Google sign-in is not configured on this site yet.',
    'account' => 'Google sign-in is only available for customer accounts.',
    'failed' => 'Google sign-in could not be completed. Please try again.',
    default => null,
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $login = trim($_POST['login'] ?? $_POST['email'] ?? '');
    $pass  = $_POST['password'] ?? '';
    $usernameLogin = $usernameEnabled && !str_contains($login, '@');
    $loginIdentifier = $usernameLogin ? 'username:' . strtolower($login) : strtolower($login);

    $lockedMins = login_lockout_remaining($pdo, $loginIdentifier);
    if ($lockedMins > 0) {
        $error = "Too many failed attempts. Please try again in about {$lockedMins} minute(s).";
    } else {
        $stmt = $pdo->prepare($usernameLogin
            ? 'SELECT * FROM users WHERE username = ?'
            : 'SELECT * FROM users WHERE email = ?');
        $stmt->execute([$usernameLogin ? strtolower($login) : $login]);
        $user = $stmt->fetch();

        if (!$user || empty($user['password_hash']) || !password_verify($pass, $user['password_hash'])) {
            record_failed_login($pdo, $loginIdentifier);
            $error = 'Incorrect email or password.';
        } else {
            clear_login_attempts($pdo, $loginIdentifier);
            header('Location: ' . establish_user_session($user));
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
<title>Log in — Brewed Delights</title>
<?php require __DIR__ . '/includes/theme_head.php'; ?>
<link rel="stylesheet" href="assets/css/style.css?v=14">
</head>
<body class="auth-page auth-login">
<div class="auth-theme"><?php require __DIR__ . '/includes/theme_toggle.php'; ?></div>
<div class="auth-card">
    <p class="auth-eyebrow">BREWED DELIGHTS</p>
    <h1 class="brand-mark">Welcome back</h1>
    <p class="auth-sub">Sign in to order your favorites.</p>

    <?php if ($error): ?><p class="alert alert-error"><?= e($error) ?></p><?php endif; ?>

    <form method="post" novalidate>
        <?= csrf_field() ?>
        <label><?= $usernameEnabled ? 'Username or email' : 'Email' ?><input
            type="<?= $usernameEnabled ? 'text' : 'email' ?>" name="login"
            autocomplete="<?= $usernameEnabled ? 'username' : 'email' ?>" required autofocus></label>
        <label>Password<input type="password" name="password" required></label>
        <button type="submit" class="btn btn-primary">Log in</button>
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
    <p class="auth-foot">New here? <a href="register.php">Create an account</a></p>
</div>
<script src="assets/js/theme.js?v=2"></script>
</body>
</html>
