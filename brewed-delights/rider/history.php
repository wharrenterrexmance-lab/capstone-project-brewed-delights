<?php
require_once __DIR__ . '/../includes/functions.php';
require_rider();

$user = current_user();
$pageTitle = 'Delivery history — Rider';
$perPage = 20;
$page = max(1, (int) ($_GET['page'] ?? 1));

$countStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM orders
     WHERE rider_id = ? AND order_type = 'Delivery' AND status IN ('Completed', 'Cancelled')"
);
$countStmt->execute([$user['user_id']]);
$historyCount = (int) $countStmt->fetchColumn();
$pageCount = max(1, (int) ceil($historyCount / $perPage));
$page = min($page, $pageCount);
$offset = ($page - 1) * $perPage;
$historyStmt = $pdo->prepare(
    "SELECT o.order_id, o.reference_code, o.status, o.delivery_address, o.total_amount,
            o.created_at, u.name AS customer_name,
            COALESCE(
                (SELECT h.changed_at FROM order_status_history h
                 WHERE h.order_id = o.order_id AND h.status = o.status
                 ORDER BY h.history_id DESC LIMIT 1),
                o.updated_at
            ) AS status_changed_at
     FROM orders o JOIN users u ON u.user_id = o.user_id
     WHERE o.rider_id = ? AND o.order_type = 'Delivery' AND o.status IN ('Completed', 'Cancelled')
     ORDER BY status_changed_at DESC, o.order_id DESC
     LIMIT $perPage OFFSET $offset"
);
$historyStmt->execute([$user['user_id']]);
$deliveryHistory = $historyStmt->fetchAll();

$historyItemsByOrder = [];
if ($deliveryHistory) {
    $historyIds = array_map('intval', array_column($deliveryHistory, 'order_id'));
    $itemPlaceholders = implode(',', array_fill(0, count($historyIds), '?'));
    $historyItemsStmt = $pdo->prepare(
        "SELECT order_id, item_name, quantity FROM order_items
         WHERE order_id IN ($itemPlaceholders) ORDER BY order_item_id"
    );
    $historyItemsStmt->execute($historyIds);
    foreach ($historyItemsStmt->fetchAll() as $item) {
        $historyItemsByOrder[$item['order_id']][] = (int) $item['quantity'] . ' x ' . $item['item_name'];
    }
}
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
            <a href="history.php" class="active">Delivery history</a>
            <a href="feedback.php">Feedback</a>
        </nav>
        <?php require __DIR__ . '/../includes/theme_toggle.php'; ?>
        <div class="admin-user" style="margin-top:auto;">
            <span><?= e($user['name']) ?></span>
            <a href="../logout.php" class="btn btn-ghost btn-sm">Log out</a>
        </div>
    </aside>
    <main class="admin-main">
        <h1>Delivery history</h1>
        <p class="section-note">Completed and cancelled deliveries assigned to your rider account.</p>
        <?php if ($deliveryHistory): ?>
            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead><tr><th>Order</th><th>Customer</th><th>Delivery address</th><th>Items</th><th>Status</th><th>Updated</th></tr></thead>
                    <tbody>
                        <?php foreach ($deliveryHistory as $historyOrder): ?>
                            <tr>
                                <td>
                                    <strong>#<?= (int) $historyOrder['order_id'] ?></strong>
                                    <?php if ($historyOrder['reference_code']): ?><br><span class="order-date"><?= e($historyOrder['reference_code']) ?></span><?php endif; ?>
                                </td>
                                <td><?= e($historyOrder['customer_name']) ?></td>
                                <td><?= e($historyOrder['delivery_address'] ?? '—') ?></td>
                                <td><?= e(implode(', ', $historyItemsByOrder[$historyOrder['order_id']] ?? [])) ?></td>
                                <td><span class="badge <?= status_badge_class($historyOrder['status']) ?>"><?= e($historyOrder['status']) ?></span></td>
                                <td><?= date('M j, Y g:i A', strtotime($historyOrder['status_changed_at'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($pageCount > 1): ?>
                <nav class="rider-history-pagination" aria-label="Delivery history pages">
                    <?php if ($page > 1): ?><a class="btn btn-ghost btn-sm" href="?page=<?= $page - 1 ?>">Previous</a><?php endif; ?>
                    <span>Page <?= $page ?> of <?= $pageCount ?></span>
                    <?php if ($page < $pageCount): ?><a class="btn btn-ghost btn-sm" href="?page=<?= $page + 1 ?>">Next</a><?php endif; ?>
                </nav>
            <?php endif; ?>
        <?php else: ?>
            <p class="empty-state">No completed or cancelled deliveries are recorded for your account yet.</p>
        <?php endif; ?>
    </main>
</div>
<script src="../assets/js/theme.js?v=drawer-theme-1"></script>
</body>
</html>