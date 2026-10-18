<?php
$remoteAddress = $_SERVER['REMOTE_ADDR'] ?? '';
if (!in_array($remoteAddress, ['127.0.0.1', '::1', '::ffff:127.0.0.1'], true)) {
    http_response_code(404);
    exit;
}

/**
 * Run this ONCE in your browser (http://localhost/brewed-delights/setup_admin.php)
 * after importing the database snapshot. It creates the first admin account with a
 * properly generated bcrypt hash. Delete this file afterwards.
 *
 * Self-locking: once any admin account exists, this file refuses to create
 * another one — so even if you forget to delete it, it's no longer an open
 * door for anyone who finds the URL.
 */
require_once __DIR__ . '/includes/functions.php';

$adminExists = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn() > 0;

$done = false;
$error = null;

if (!$adminExists && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $name  = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $pass  = $_POST['password'] ?? '';

    if (!$name || !$email || strlen($pass) < 8) {
        $error = 'Please fill every field; password must be at least 8 characters.';
    } else {
        $check = $pdo->prepare('SELECT user_id FROM users WHERE email = ?');
        $check->execute([$email]);
        if ($check->fetch()) {
            $error = 'That email is already registered.';
        } else {
            $hash = password_hash($pass, PASSWORD_BCRYPT);
            $pdo->prepare(
                'INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, "admin")'
            )->execute([$name, $email, $hash]);
            $done = true;
            $adminExists = true;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>☕</text></svg>">
<title>Set up admin — Brewed Delights</title>
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="auth-page">
<div class="auth-card">
    <h1 class="brand-mark">Brewed&nbsp;Delights</h1>
    <p class="auth-sub">One-time admin setup</p>

    <?php if ($done): ?>
        <p class="alert alert-success">Admin account created. You can now
        <a href="login.php">log in</a>. This setup page is now locked (an
        admin already exists), but delete <code>setup_admin.php</code> from
        your project folder anyway — one less file to worry about.</p>
    <?php elseif ($adminExists): ?>
        <p class="alert alert-error">An admin account already exists, so this
        setup page is locked. If you need to create another admin, do it from
        <a href="admin/index.php">the admin panel</a> once logged in, or
        delete this file and edit the database directly.</p>
    <?php else: ?>
        <?php if ($error): ?><p class="alert alert-error"><?= e($error) ?></p><?php endif; ?>
        <form method="post">
            <?= csrf_field() ?>
            <label>Name<input type="text" name="name" required></label>
            <label>Email<input type="email" name="email" required></label>
            <label>Password<input type="password" name="password" minlength="8" required></label>
            <button type="submit" class="btn btn-primary">Create admin account</button>
        </form>
    <?php endif; ?>
</div>
</body>
</html>
