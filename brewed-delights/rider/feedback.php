<?php
require_once __DIR__ . '/../includes/functions.php';
require_rider();

$user = current_user();
$pageTitle = 'Rider feedback — Brewed Delights';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 20;

$countStmt = $pdo->prepare('SELECT COUNT(*) FROM rider_feedback WHERE rider_id = ?');
$countStmt->execute([$user['user_id']]);
$feedbackCount = (int) $countStmt->fetchColumn();
$pageCount = max(1, (int) ceil($feedbackCount / $perPage));
$page = min($page, $pageCount);
$offset = ($page - 1) * $perPage;
$feedbackStmt = $pdo->prepare(
    "SELECT rf.feedback_id, rf.order_id, rf.rating, rf.comments, rf.created_at,
            u.name AS customer_name, o.reference_code
     FROM rider_feedback rf
     JOIN users u ON u.user_id = rf.customer_id
     JOIN orders o ON o.order_id = rf.order_id
     WHERE rf.rider_id = ?
     ORDER BY rf.created_at DESC, rf.feedback_id DESC
     LIMIT $perPage OFFSET $offset"
);
$feedbackStmt->execute([$user['user_id']]);
$feedback = $feedbackStmt->fetchAll();
$averageStmt = $pdo->prepare('SELECT AVG(rating) FROM rider_feedback WHERE rider_id = ?');
$averageStmt->execute([$user['user_id']]);
$averageRating = $averageStmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?></title>
<?php require __DIR__ . '/../includes/theme_head.php'; ?>
<link rel="stylesheet" href="../assets/css/style.css?v=drawer-theme-1">
</head>
<body class="admin-body">
<div class="admin-shell">
    <aside class="admin-sidebar">
        <div class="brand-mark">Brewed&nbsp;Delights</div>
        <p class="admin-tag">Rider dashboard</p>
        <nav class="admin-nav rider-nav">
            <a href="index.php#assigned-deliveries">Assigned deliveries</a>
            <a href="account.php">Account settings</a>
            <a href="history.php">Delivery history</a>
            <a href="feedback.php" class="active">Feedback</a>
        </nav>
        <?php require __DIR__ . '/../includes/theme_toggle.php'; ?>
        <div class="admin-user" style="margin-top:auto;">
            <span><?= e($user['name']) ?></span>
            <a href="../logout.php" class="btn btn-ghost btn-sm">Log out</a>
        </div>
    </aside>
    <main class="admin-main">
        <h1>Rider feedback</h1>
        <p class="section-note">Average customer rating:
            <strong><?= $averageRating !== null ? number_format((float) $averageRating, 2) . ' / 5' : 'No ratings yet' ?></strong>
            <span class="muted">(<?= $feedbackCount ?> review<?= $feedbackCount === 1 ? '' : 's' ?>)</span>
        </p>

        <?php if ($feedback): ?>
            <div class="feedback-list admin-feedback-list">
                <?php foreach ($feedback as $review): ?>
                    <article class="feedback-item">
                        <div class="feedback-item-head">
                            <span class="stars" aria-label="<?= (int) $review['rating'] ?> out of 5 stars">
                                <?= str_repeat('★', (int) $review['rating']) . str_repeat('☆', 5 - (int) $review['rating']) ?>
                            </span>
                            <span><?= e($review['customer_name']) ?></span>
                            <span class="muted">Order #<?= (int) $review['order_id'] ?><?= $review['reference_code'] ? ' · ' . e($review['reference_code']) : '' ?></span>
                        </div>
                        <p><?= $review['comments'] ? e($review['comments']) : '<span class="muted">No comment</span>' ?></p>
                        <time class="feedback-date" datetime="<?= e($review['created_at']) ?>"><?= date('M j, Y g:i A', strtotime($review['created_at'])) ?></time>
                    </article>
                <?php endforeach; ?>
            </div>
            <?php if ($pageCount > 1): ?>
                <nav class="rider-history-pagination" aria-label="Feedback pages">
                    <?php if ($page > 1): ?><a class="btn btn-ghost btn-sm" href="?page=<?= $page - 1 ?>">Previous</a><?php endif; ?>
                    <span>Page <?= $page ?> of <?= $pageCount ?></span>
                    <?php if ($page < $pageCount): ?><a class="btn btn-ghost btn-sm" href="?page=<?= $page + 1 ?>">Next</a><?php endif; ?>
                </nav>
            <?php endif; ?>
        <?php else: ?>
            <p class="empty-state">No customer feedback for your deliveries yet.</p>
        <?php endif; ?>
    </main>
</div>
<script src="../assets/js/theme.js?v=drawer-theme-1"></script>
</body>
</html>