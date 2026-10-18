<?php
require_once __DIR__ . '/../includes/functions.php';
require_admin();

$pageTitle = 'Dashboard — Admin';
$active = 'dashboard';

$todaySales = $pdo->query(
    "SELECT COALESCE(SUM(total_amount),0) FROM orders
     WHERE payment_status = 'Paid' AND DATE(created_at) = CURDATE()"
)->fetchColumn();

$pendingCount = $pdo->query(
    "SELECT COUNT(*) FROM orders WHERE status IN ('Pending','Preparing')"
)->fetchColumn();

$completedToday = $pdo->query(
    "SELECT COUNT(*) FROM orders WHERE status = 'Completed' AND DATE(updated_at) = CURDATE()"
)->fetchColumn();

$avgRating = $pdo->query('SELECT AVG(rating) FROM feedback')->fetchColumn();

$bestSellers = $pdo->query(
        "SELECT m.name, m.category, SUM(oi.quantity) AS units_sold
         FROM order_items oi
         JOIN orders o ON o.order_id = oi.order_id
         JOIN menu m ON m.item_id = oi.item_id
         WHERE o.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
             AND o.status <> 'Cancelled'
             AND m.category NOT IN ('Add-ons', 'Syrups')
         GROUP BY m.item_id, m.name, m.category
         ORDER BY units_sold DESC, m.name
         LIMIT 5"
)->fetchAll();

$recent = $pdo->query(
    'SELECT o.*, u.name AS customer_name FROM orders o
     JOIN users u ON u.user_id = o.user_id
     ORDER BY o.created_at DESC LIMIT 8'
)->fetchAll();

require __DIR__ . '/includes/admin_header.php';
?>

<h1>Dashboard</h1>

<div class="stat-grid">
    <div class="stat-card">
        <span class="stat-label">Today's sales</span>
        <span class="stat-value"><?= peso((float) $todaySales) ?></span>
    </div>
    <div class="stat-card">
        <span class="stat-label">Orders in progress</span>
        <span class="stat-value"><?= (int) $pendingCount ?></span>
    </div>
    <div class="stat-card">
        <span class="stat-label">Completed today</span>
        <span class="stat-value"><?= (int) $completedToday ?></span>
    </div>
    <div class="stat-card">
        <span class="stat-label">Avg. rating</span>
        <span class="stat-value"><?= $avgRating ? number_format((float) $avgRating, 1) . ' ★' : '—' ?></span>
    </div>
</div>

<section class="weekly-bestsellers">
    <div class="weekly-bestsellers-head">
        <div>
            <h2>This week’s best sellers</h2>
            <p class="section-note">Food and drinks ordered in the last 7 days, excluding cancelled orders and extras.</p>
        </div>
        <a href="menu.php" class="btn btn-ghost btn-sm">Manage featured menu</a>
    </div>
    <?php if ($bestSellers): ?>
        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead><tr><th>Rank</th><th>Product</th><th>Category</th><th>Units ordered</th></tr></thead>
                <tbody>
                    <?php foreach ($bestSellers as $rank => $product): ?>
                        <tr>
                            <td><?= $rank + 1 ?></td>
                            <td><?= e($product['name']) ?></td>
                            <td><?= e($product['category']) ?></td>
                            <td><strong><?= (int) $product['units_sold'] ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <p class="empty-state">No non-cancelled orders in the last 7 days yet.</p>
    <?php endif; ?>
</section>

<h2 class="section-subhead">Recent orders</h2>
<table class="admin-table">
    <thead>
        <tr><th>#</th><th>Customer</th><th>Total</th><th>Status</th><th>Payment</th><th>Placed</th></tr>
    </thead>
    <tbody>
        <?php foreach ($recent as $o): ?>
            <tr>
                <td>#<?= (int) $o['order_id'] ?></td>
                <td><?= e($o['customer_name']) ?></td>
                <td><?= peso((float) $o['total_amount']) ?></td>
                <td><span class="badge <?= status_badge_class($o['status']) ?>"><?= e($o['status']) ?></span></td>
                <td><?= e($o['payment_status']) ?></td>
                <td><?= date('M j, g:i A', strtotime($o['created_at'])) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$recent): ?>
            <tr><td colspan="6" class="empty-state">No orders yet.</td></tr>
        <?php endif; ?>
    </tbody>
</table>

<?php require __DIR__ . '/includes/admin_footer.php'; ?>
