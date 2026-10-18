<?php
$user = current_user();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>☕</text></svg>">
<title><?= e($pageTitle ?? 'Admin — Brewed Delights') ?></title>
<?php require __DIR__ . '/../../includes/theme_head.php'; ?>
<link rel="stylesheet" href="../assets/css/style.css?v=drawer-theme-1">
</head>
<body class="admin-body">
<div class="admin-shell">
    <aside class="admin-sidebar">
        <div class="brand-mark">Brewed&nbsp;Delights</div>
        <p class="admin-tag"><?= is_admin() ? 'Admin console' : 'Staff console' ?></p>
        <nav class="admin-nav">
            <?php if (is_admin()): ?>
                <a href="index.php" class="<?= ($active ?? '') === 'dashboard' ? 'active' : '' ?>">Dashboard</a>
                <a href="menu.php" class="<?= ($active ?? '') === 'menu' ? 'active' : '' ?>">Menu</a>
            <?php endif; ?>
            <a href="orders.php" class="<?= ($active ?? '') === 'orders' ? 'active' : '' ?>">Orders</a>
            <?php if (is_admin()): ?>
                <a href="riders.php" class="<?= ($active ?? '') === 'riders' ? 'active' : '' ?>">Riders</a>
                <a href="staff.php" class="<?= ($active ?? '') === 'staff' ? 'active' : '' ?>">Staff</a>
                <a href="accounts.php" class="<?= ($active ?? '') === 'accounts' ? 'active' : '' ?>">Accounts</a>
            <?php endif; ?>
            <a href="feedback.php" class="<?= ($active ?? '') === 'feedback' ? 'active' : '' ?>">Feedback</a>
        </nav>
        <?php require __DIR__ . '/../../includes/theme_toggle.php'; ?>
        <div class="admin-user">
            <span><?= e($user['name']) ?></span>
            <a href="../logout.php" class="btn btn-ghost btn-sm">Log out</a>
        </div>
    </aside>
    <main class="admin-main">
