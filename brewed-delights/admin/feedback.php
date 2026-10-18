<?php
require_once __DIR__ . '/../includes/functions.php';
require_staff_or_admin();

$pageTitle = 'Feedback — Admin';
$active = 'feedback';
$view = $_GET['view'] ?? 'general';
if (!in_array($view, ['general', 'rider'], true)) $view = 'general';

$generalFeedback = $riderFeedback = [];
$avgGeneralRating = $avgRiderRating = null;

if ($view === 'general') {
    $generalFeedback = $pdo->query(
        "SELECT f.feedback_id, f.rating, f.comments, f.created_at, f.order_id,
                u.name AS customer_name, 'General' AS feedback_type,
            NULL AS item_name, NULL AS item_id, NULL AS item_image_path, o.reference_code
         FROM feedback f
         JOIN users u ON u.user_id = f.user_id
         LEFT JOIN orders o ON o.order_id = f.order_id
         UNION ALL
         SELECT pf.feedback_id, pf.rating, pf.comments, pf.created_at, pf.order_id,
                c.name AS customer_name, 'Product' AS feedback_type,
              oi.item_name, oi.item_id, m.image_path AS item_image_path, o.reference_code
         FROM product_feedback pf
         JOIN users c ON c.user_id = pf.customer_id
         JOIN order_items oi ON oi.order_item_id = pf.order_item_id
         JOIN orders o ON o.order_id = pf.order_id
          LEFT JOIN menu m ON m.item_id = oi.item_id
         ORDER BY created_at DESC, feedback_id DESC"
    )->fetchAll();
    $avgGeneralRating = $pdo->query(
        'SELECT AVG(rating) FROM (
            SELECT rating FROM feedback
            UNION ALL
            SELECT rating FROM product_feedback
         ) AS all_customer_feedback'
    )->fetchColumn();
} else {
    $riderFeedback = $pdo->query(
        'SELECT rf.*, c.name AS customer_name, r.name AS rider_name, o.reference_code
         FROM rider_feedback rf
         JOIN users c ON c.user_id = rf.customer_id
         JOIN users r ON r.user_id = rf.rider_id
         JOIN orders o ON o.order_id = rf.order_id
         ORDER BY rf.created_at DESC'
    )->fetchAll();
    $avgRiderRating = $pdo->query('SELECT AVG(rating) FROM rider_feedback')->fetchColumn();
}

require __DIR__ . '/includes/admin_header.php';
?>

<h1>Feedback</h1>

<nav class="filter-tabs" aria-label="Feedback type">
    <a href="?view=general" class="<?= $view === 'general' ? 'active' : '' ?>" <?= $view === 'general' ? 'aria-current="page"' : '' ?>>General feedback</a>
    <a href="?view=rider" class="<?= $view === 'rider' ? 'active' : '' ?>" <?= $view === 'rider' ? 'aria-current="page"' : '' ?>>Rider feedback</a>
</nav>

<?php if ($view === 'general'): ?>
<h2 class="section-subhead">General feedback</h2>
<p class="section-note">Average customer and product rating:
    <strong><?= $avgGeneralRating !== null ? number_format((float) $avgGeneralRating, 2) . ' / 5' : 'No ratings yet' ?></strong>
</p>

<div class="feedback-list admin-feedback-list">
    <?php foreach ($generalFeedback as $review): ?>
        <article class="feedback-item">
            <div class="feedback-item-head">
                <?php if ($review['feedback_type'] === 'Product'):
                    $productImagePath = menu_item_image_path([
                        'item_id' => $review['item_id'],
                        'name' => $review['item_name'],
                        'image_path' => $review['item_image_path'],
                    ]);
                ?>
                    <?php if ($productImagePath): ?><img class="feedback-product-thumb" src="../<?= e($productImagePath) ?>" alt="<?= e($review['item_name']) ?>"><?php endif; ?>
                <?php endif; ?>
                <span class="stars" aria-label="<?= (int) $review['rating'] ?> out of 5 stars">
                    <?= str_repeat('★', (int) $review['rating']) . str_repeat('☆', 5 - (int) $review['rating']) ?>
                </span>
                <span><?= e($review['customer_name']) ?> · <?= e($review['feedback_type']) ?><?= $review['item_name'] ? ': ' . e($review['item_name']) : '' ?></span>
                <?php if ($review['order_id']): ?><span class="muted">Order #<?= (int) $review['order_id'] ?><?= $review['reference_code'] ? ' · ' . e($review['reference_code']) : '' ?></span><?php endif; ?>
            </div>
            <p><?= $review['comments'] ? e($review['comments']) : '<span class="muted">No comment</span>' ?></p>
            <time class="feedback-date" datetime="<?= e($review['created_at']) ?>"><?= date('M j, Y g:i A', strtotime($review['created_at'])) ?></time>
        </article>
    <?php endforeach; ?>

    <?php if (!$generalFeedback): ?>
        <p class="empty-state">No general feedback submitted yet.</p>
    <?php endif; ?>
</div>
<?php else: ?>

<h2 class="section-subhead">Rider feedback</h2>
<p class="section-note">Average rider rating:
    <strong><?= $avgRiderRating ? number_format((float) $avgRiderRating, 2) . ' / 5' : 'No rider ratings yet' ?></strong>
</p>
<div class="feedback-list admin-feedback-list">
    <?php foreach ($riderFeedback as $review): ?>
        <article class="feedback-item">
            <div class="feedback-item-head">
                <span class="stars" aria-label="<?= (int) $review['rating'] ?> out of 5 stars">
                    <?= str_repeat('★', (int) $review['rating']) . str_repeat('☆', 5 - (int) $review['rating']) ?>
                </span>
                <span><?= e($review['customer_name']) ?> reviewed rider <?= e($review['rider_name']) ?></span>
                <span class="muted">Order #<?= (int) $review['order_id'] ?></span>
            </div>
            <p><?= $review['comments'] ? e($review['comments']) : '<span class="muted">No comment</span>' ?></p>
            <span class="feedback-date"><?= date('M j, Y g:i A', strtotime($review['created_at'])) ?></span>
        </article>
    <?php endforeach; ?>
    <?php if (!$riderFeedback): ?>
        <p class="empty-state">No rider feedback submitted yet.</p>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php require __DIR__ . '/includes/admin_footer.php'; ?>
