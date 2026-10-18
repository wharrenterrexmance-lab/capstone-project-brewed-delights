<?php
require_once __DIR__ . '/../includes/functions.php';
require_rider();

$user = current_user();
$pageTitle = 'Rider account — Brewed Delights';
$usernameEnabled = users_have_username($pdo);
$error = null;
$success = null;
$feedbackSummaryStmt = $pdo->prepare(
    'SELECT COUNT(*) AS review_count, AVG(rating) AS average_rating
     FROM rider_feedback WHERE rider_id = ?'
);
$feedbackSummaryStmt->execute([$user['user_id']]);
$feedbackSummary = $feedbackSummaryStmt->fetch();
$recentFeedbackStmt = $pdo->prepare(
    'SELECT rf.rating, rf.comments, rf.created_at, o.reference_code
     FROM rider_feedback rf
     JOIN orders o ON o.order_id = rf.order_id
     WHERE rf.rider_id = ?
     ORDER BY rf.created_at DESC, rf.feedback_id DESC
     LIMIT 3'
);
$recentFeedbackStmt->execute([$user['user_id']]);
$recentFeedback = $recentFeedbackStmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $name = trim($_POST['name'] ?? '');
    $username = strtolower(trim($_POST['username'] ?? ''));
    $phone = trim($_POST['phone_number'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if ($name === '' || mb_strlen($name) > 100) {
        $error = 'Enter your full name (up to 100 characters).';
    } elseif ($usernameEnabled && ($username === '' || !preg_match('/^[a-zA-Z0-9._-]{3,32}$/D', $username))) {
        $error = 'Username must be 3-32 characters using letters, numbers, dots, underscores, or hyphens.';
    } elseif (mb_strlen($phone) > 30 || ($phone !== '' && !preg_match('/^\+?[0-9][0-9 ()-]{6,28}$/D', $phone))) {
        $error = 'Enter a valid phone number, including country code if needed.';
    } elseif (mb_strlen($address) > 255) {
        $error = 'Address must be 255 characters or fewer.';
    } elseif (($newPassword !== '' || $confirmPassword !== '') && strlen($newPassword) < 8) {
        $error = 'A new password must be at least 8 characters.';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'The new passwords do not match.';
    } else {
        $passwordStmt = $pdo->prepare('SELECT password_hash FROM users WHERE user_id = ? AND role = "rider"');
        $passwordStmt->execute([$user['user_id']]);
        $passwordHash = $passwordStmt->fetchColumn();

        if ($newPassword !== '' && $passwordHash && !password_verify($currentPassword, $passwordHash)) {
            $error = 'Your current password is incorrect.';
        }

        if (!$error && $usernameEnabled) {
            $duplicateStmt = $pdo->prepare('SELECT user_id FROM users WHERE username = ? AND user_id <> ?');
            $duplicateStmt->execute([$username, $user['user_id']]);
            if ($duplicateStmt->fetch()) $error = 'That username is already taken.';
        }

        if (!$error) {
            if ($usernameEnabled) {
                $pdo->prepare(
                    'UPDATE users SET name = ?, username = ?, phone_number = NULLIF(?, ""), address = NULLIF(?, "") WHERE user_id = ? AND role = "rider"'
                )->execute([$name, $username, $phone, $address, $user['user_id']]);
            } else {
                $pdo->prepare(
                    'UPDATE users SET name = ?, phone_number = NULLIF(?, ""), address = NULLIF(?, "") WHERE user_id = ? AND role = "rider"'
                )->execute([$name, $phone, $address, $user['user_id']]);
            }

            if ($newPassword !== '') {
                $newHash = password_hash($newPassword, PASSWORD_BCRYPT);
                $pdo->prepare('UPDATE users SET password_hash = ? WHERE user_id = ? AND role = "rider"')
                    ->execute([$newHash, $user['user_id']]);
            }

            $_SESSION['user']['name'] = $name;
            $_SESSION['user']['email'] = $user['email'];
            $success = 'Rider account details saved.';
        }
    }
}

$profileFields = $usernameEnabled
    ? 'name, username, email, phone_number, address, password_hash'
    : 'name, email, phone_number, address, password_hash';
$profileStmt = $pdo->prepare("SELECT $profileFields FROM users WHERE user_id = ? AND role = 'rider'");
$profileStmt->execute([$user['user_id']]);
$profile = $profileStmt->fetch();

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<title><?= e($pageTitle) ?></title>
<?php require __DIR__ . '/../includes/theme_head.php'; ?>
<link rel="stylesheet" href="../assets/css/style.css?v=drawer-theme-1">
</head>
<body class="admin-body">
<div class="admin-shell">
    <aside class="admin-sidebar">
        <div class="brand-mark">Brewed&nbsp;Delights</div>
        <p class="admin-tag">Rider dashboard</p>
        <nav class="admin-nav">
            <a href="index.php">My deliveries</a>
            <a href="account.php" class="active">Account settings</a>
            <a href="history.php">Delivery history</a>
            <a href="feedback.php">Feedback</a>
        </nav>
        <?php require __DIR__ . '/../includes/theme_toggle.php'; ?>
        <div class="admin-user" style="margin-top:auto;">
            <span><?= e($profile['name']) ?></span>
            <a href="../logout.php" class="btn btn-ghost btn-sm">Log out</a>
        </div>
    </aside>
    <main class="admin-main rider-account-main">
        <h1>Rider account settings</h1>
        <p class="section-note">Keep your rider contact details current so customers and the shop can identify and reach you.</p>
        <?php if ($error): ?><p class="alert alert-error"><?= e($error) ?></p><?php endif; ?>
        <?php if ($success): ?><p class="alert alert-success"><?= e($success) ?></p><?php endif; ?>

        <section class="rider-feedback-box" aria-labelledby="recent-feedback-title">
            <h2 id="recent-feedback-title">Customer feedback</h2>
            <p class="section-note">
                <strong><?= $feedbackSummary['average_rating'] !== null ? number_format((float) $feedbackSummary['average_rating'], 2) . ' / 5' : 'No ratings yet' ?></strong>
                <span class="muted">(<?= (int) $feedbackSummary['review_count'] ?> review<?= (int) $feedbackSummary['review_count'] === 1 ? '' : 's' ?>)</span>
                <a href="feedback.php">View all feedback</a>
            </p>
            <?php if ($recentFeedback): ?>
                <div class="feedback-list">
                    <?php foreach ($recentFeedback as $review): ?>
                        <article class="feedback-item">
                            <div class="feedback-item-head">
                                <span class="stars" aria-label="<?= (int) $review['rating'] ?> out of 5 stars"><?= str_repeat('★', (int) $review['rating']) . str_repeat('☆', 5 - (int) $review['rating']) ?></span>
                                <span class="muted"><?= $review['reference_code'] ? e($review['reference_code']) : 'Delivery review' ?></span>
                            </div>
                            <p><?= $review['comments'] ? e($review['comments']) : '<span class="muted">No comment</span>' ?></p>
                            <time class="feedback-date" datetime="<?= e($review['created_at']) ?>"><?= date('M j, Y', strtotime($review['created_at'])) ?></time>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="empty-state">Customer reviews for your completed deliveries will appear here.</p>
            <?php endif; ?>
        </section>

        <form method="post" class="admin-form rider-profile-form">
            <?= csrf_field() ?>
            <h2>Rider profile</h2>
            <label>Full name<input type="text" name="name" maxlength="100" value="<?= e($_POST['name'] ?? $profile['name']) ?>" autocomplete="name" required></label>
            <?php if ($usernameEnabled): ?>
                <label>Username<input type="text" name="username" minlength="3" maxlength="32" pattern="[A-Za-z0-9._\-]{3,32}" value="<?= e($_POST['username'] ?? $profile['username'] ?? '') ?>" autocomplete="username" required></label>
            <?php endif; ?>
            <label>Email<input type="email" value="<?= e($profile['email']) ?>" readonly></label>
            <label>Phone number<input type="tel" name="phone_number" maxlength="30" value="<?= e($_POST['phone_number'] ?? $profile['phone_number'] ?? '') ?>" autocomplete="tel" placeholder="+63 9XX XXX XXXX"></label>
            <label>Home address<input type="text" name="address" maxlength="255" value="<?= e($_POST['address'] ?? $profile['address'] ?? '') ?>" autocomplete="street-address" placeholder="Your personal address"></label>
            <p class="form-note">This is your personal address, not a customer delivery address. Your phone number is shared with a customer only for their assigned delivery while it is out for delivery.</p>

            <h2>Password</h2>
            <?php if (!empty($profile['password_hash'])): ?>
                <label>Current password<input type="password" name="current_password" autocomplete="current-password"></label>
            <?php else: ?>
                <p class="form-note">No local password is set. Choose a new one here to enable password sign-in.</p>
            <?php endif; ?>
            <label>New password<input type="password" name="new_password" minlength="8" autocomplete="new-password"></label>
            <label>Confirm new password<input type="password" name="confirm_password" minlength="8" autocomplete="new-password"></label>
            <p class="form-note">Leave both new-password fields blank to keep your current password.</p>
            <button type="submit" class="btn btn-primary">Save rider profile</button>
        </form>

    </main>
</div>
<script src="../assets/js/theme.js?v=drawer-theme-1"></script>
</body>
</html>