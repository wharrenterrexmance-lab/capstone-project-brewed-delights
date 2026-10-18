<?php
/** Expects $pageTitle to be set by the including page. */
$user = current_user();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<meta name="description" content="Order ahead from Brewed Delights Coffee Shop — browse the menu, pay by GCash or cash, and track your order in real time.">
<meta property="og:title" content="Brewed Delights Coffee Shop">
<meta property="og:description" content="Order ahead, skip the line, and track your cup in real time.">
<meta property="og:type" content="website">
<meta property="og:site_name" content="Brewed Delights">
<meta property="og:image" content="assets/images/brand/brewed-delights-logo.png">
<link rel="icon" type="image/png" href="assets/images/brand/brewed-delights-logo.png">
<title><?= e($pageTitle ?? 'Brewed Delights') ?></title>
<?php require __DIR__ . '/theme_head.php'; ?>
<link rel="stylesheet" href="assets/css/style.css?v=19">
</head>
<body class="<?= $pageTitle === 'Brewed Delights — Menu' ? 'home-page' . ($user ? ' user-home' : '') : '' ?>">
<header class="site-header">
    <div class="header-inner">
        <a href="index.php" class="brand-mark" aria-label="Brewed Delights home">
            <img src="assets/images/brand/brewed-delights-logo.png" alt="" class="brand-logo">
            <span>Brewed&nbsp;Delights</span>
        </a>
        <button type="button" class="nav-toggle" data-nav-toggle aria-label="Toggle menu" aria-expanded="false" aria-controls="mainNav">
            <span></span><span></span><span></span>
        </button>
        <nav class="main-nav" id="mainNav" data-main-nav>
            <a href="index.php">Menu</a>
            <?php if ($user): ?>
                <a href="orders.php">My orders</a>
                <a href="account.php">Account</a>
                <a href="feedback.php">Feedback</a>
                <a href="cart.php" class="nav-cart">
                    Cart
                    <?php if (cart_count() > 0): ?>
                        <span class="cart-badge"><?= cart_count() ?></span>
                    <?php endif; ?>
                </a>
                <span class="nav-points" title="Loyalty points">★ <?php
                    $pts = $pdo->prepare('SELECT points_balance FROM users WHERE user_id = ?');
                    $pts->execute([$user['user_id']]);
                    echo (int) $pts->fetchColumn();
                ?></span>
                <a href="logout.php" class="btn btn-ghost">Log out</a>
            <?php else: ?>
                <a href="login.php" class="btn btn-ghost">Log in</a>
                <a href="register.php" class="btn btn-primary">Sign up</a>
            <?php endif; ?>
            <?php require __DIR__ . '/theme_toggle.php'; ?>
        </nav>
    </div>
</header>
<main>
