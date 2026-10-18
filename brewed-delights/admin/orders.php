<?php
require_once __DIR__ . '/../includes/functions.php';
require_staff_or_admin();

$pageTitle = 'Orders — Admin';
$active = 'orders';
$currentUser = current_user();
$statusError = $_SESSION['order_status_error'] ?? null;
unset($_SESSION['order_status_error']);
$filter = $_GET['filter'] ?? 'active';
$validFilters = ['active', 'Pending', 'Preparing', 'Ready', 'Out for Delivery', 'Completed', 'Cancelled'];
if (!in_array($filter, $validFilters, true)) $filter = 'active';
$search = trim((string) ($_GET['q'] ?? ''));
if (mb_strlen($search) > 100) $search = mb_substr($search, 0, 100);
$filterUrl = static function (string $target) use ($search): string {
    $params = ['filter' => $target];
    if ($search !== '') $params['q'] = $search;
    return '?' . http_build_query($params);
};
$nextStatuses = static function (string $status, string $orderType): array {
    return match ($status) {
        'Pending' => ['Preparing', 'Cancelled'],
        'Preparing' => ['Ready', 'Cancelled'],
        'Ready' => $orderType === 'Delivery'
            ? ['Out for Delivery', 'Cancelled']
            : ['Completed', 'Cancelled'],
        'Out for Delivery' => ['Completed', 'Cancelled'],
        default => [],
    };
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action  = $_POST['action'] ?? 'update_status';
    $orderId = (int) ($_POST['order_id'] ?? 0);

    if ($action === 'assign_rider') {
        $riderId = (int) ($_POST['rider_id'] ?? 0);
        if ($orderId && $riderId) {
            $orderCheck = $pdo->prepare('SELECT order_type, status, rider_id FROM orders WHERE order_id = ?');
            $orderCheck->execute([$orderId]);
            $assignmentOrder = $orderCheck->fetch();
            $riderCheck = $pdo->prepare("SELECT user_id FROM users WHERE user_id = ? AND role = 'rider'");
            $riderCheck->execute([$riderId]);

            if (!$assignmentOrder || $assignmentOrder['order_type'] !== 'Delivery'
                || !in_array($assignmentOrder['status'], ['Pending', 'Preparing', 'Ready'], true)) {
                $_SESSION['order_status_error'] = 'Riders can only be assigned to active deliveries before they are out for delivery.';
            } elseif (!$riderCheck->fetch()) {
                $_SESSION['order_status_error'] = 'Choose a valid rider account.';
            } elseif ((int) $assignmentOrder['rider_id'] !== $riderId) {
                $assignStmt = $pdo->prepare(
                    'UPDATE orders SET rider_id = ?
                     WHERE order_id = ? AND order_type = "Delivery" AND status IN ("Pending", "Preparing", "Ready")'
                );
                $assignStmt->execute([$riderId, $orderId]);
                if ($assignStmt->rowCount() !== 1) {
                    $_SESSION['order_status_error'] = 'The order changed while assigning the rider. Refresh and try again.';
                }
            }
        }
    } elseif ($action === 'verify_payment') {
        // Staff/admin manually checks the submitted GCash reference number
        // against the shop's own GCash app (or confirms cash was collected),
        // then confirms it here.
        try {
            $pdo->beginTransaction();
            $paymentBefore = $pdo->prepare('SELECT payment_status FROM orders WHERE order_id = ? FOR UPDATE');
            $paymentBefore->execute([$orderId]);
            $previousPaymentStatus = $paymentBefore->fetchColumn();
            $paymentStmt = $pdo->prepare(
                'SELECT payment_id FROM payments WHERE order_id = ? ORDER BY payment_id DESC LIMIT 1 FOR UPDATE'
            );
            $paymentStmt->execute([$orderId]);
            $paymentId = $paymentStmt->fetchColumn();

            if ($previousPaymentStatus === false || !$paymentId) {
                $pdo->rollBack();
                $_SESSION['order_status_error'] = 'The order payment could not be found.';
            } else {
                $pdo->prepare(
                    'UPDATE payments SET status = "Paid", verified_by = ?, verified_at = NOW() WHERE payment_id = ?'
                )->execute([$currentUser['user_id'], $paymentId]);
                $pdo->prepare('UPDATE orders SET payment_status = "Paid" WHERE order_id = ?')
                    ->execute([$orderId]);
                $pdo->commit();
                if ($previousPaymentStatus !== 'Paid') send_order_email($pdo, $orderId, 'paid');
            }
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Payment verification failed for order #' . $orderId . ': ' . $ex->getMessage());
            $_SESSION['order_status_error'] = 'Could not update this payment. Please try again.';
        }
    } elseif ($action === 'fail_payment') {
        try {
            $pdo->beginTransaction();
            $paymentBefore = $pdo->prepare('SELECT payment_status FROM orders WHERE order_id = ? FOR UPDATE');
            $paymentBefore->execute([$orderId]);
            $previousPaymentStatus = $paymentBefore->fetchColumn();
            $paymentStmt = $pdo->prepare(
                'SELECT payment_id FROM payments WHERE order_id = ? ORDER BY payment_id DESC LIMIT 1 FOR UPDATE'
            );
            $paymentStmt->execute([$orderId]);
            $paymentId = $paymentStmt->fetchColumn();

            if ($previousPaymentStatus === false || !$paymentId) {
                $pdo->rollBack();
                $_SESSION['order_status_error'] = 'The order payment could not be found.';
            } else {
                $pdo->prepare('UPDATE payments SET status = "Failed" WHERE payment_id = ?')
                    ->execute([$paymentId]);
                $pdo->prepare('UPDATE orders SET payment_status = "Failed" WHERE order_id = ?')
                    ->execute([$orderId]);
                $pdo->commit();
                if ($previousPaymentStatus !== 'Failed') send_order_email($pdo, $orderId, 'payment_failed');
            }
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Payment failure update failed for order #' . $orderId . ': ' . $ex->getMessage());
            $_SESSION['order_status_error'] = 'Could not update this payment. Please try again.';
        }
    } else {
        $newStatus = $_POST['status'] ?? '';
        if ($orderId) {
            try {
                $pdo->beginTransaction();
                $statusBefore = $pdo->prepare('SELECT status, order_type FROM orders WHERE order_id = ? FOR UPDATE');
                $statusBefore->execute([$orderId]);
                $orderBefore = $statusBefore->fetch();

                if (!$orderBefore) {
                    $pdo->rollBack();
                    $_SESSION['order_status_error'] = 'That order could not be found.';
                } elseif ($newStatus === $orderBefore['status']) {
                    $pdo->commit();
                } elseif (!in_array($newStatus, $nextStatuses($orderBefore['status'], $orderBefore['order_type']), true)) {
                    $pdo->rollBack();
                    $_SESSION['order_status_error'] = 'That status change is not allowed from the order\'s current state.';
                } else {
                    $pdo->prepare('UPDATE orders SET status = ? WHERE order_id = ?')
                        ->execute([$newStatus, $orderId]);
                    log_order_status($pdo, $orderId, $newStatus, $currentUser['user_id']);
                    if ($newStatus === 'Completed') award_loyalty_points($pdo, $orderId);
                    $pdo->commit();
                    send_order_email($pdo, $orderId, 'status:' . $newStatus);
                }
            } catch (Throwable $ex) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('Order status update failed for order #' . $orderId . ': ' . $ex->getMessage());
                $_SESSION['order_status_error'] = 'Could not update this order. Please try again.';
            }
        } else {
            $_SESSION['order_status_error'] = 'That order could not be found.';
        }
    }
    $redirectParams = ['filter' => $filter];
    if ($search !== '') $redirectParams['q'] = $search;
    header('Location: orders.php?' . http_build_query($redirectParams));
    exit;
}

$whereParts = [];
$queryParams = [];
if ($filter === 'active') {
    $whereParts[] = "o.status IN ('Pending','Preparing','Ready','Out for Delivery')";
} elseif ($filter !== 'active') {
    $whereParts[] = 'o.status = ?';
    $queryParams[] = $filter;
}
if ($search !== '') {
    $whereParts[] = '(CAST(o.order_id AS CHAR) LIKE ? OR o.reference_code LIKE ? OR u.name LIKE ? OR u.email LIKE ?)';
    $searchPattern = '%' . $search . '%';
    array_push($queryParams, $searchPattern, $searchPattern, $searchPattern, $searchPattern);
}
$where = $whereParts ? 'WHERE ' . implode(' AND ', $whereParts) : '';

$ordersStmt = $pdo->prepare(
    "SELECT o.*, u.name AS customer_name, u.email AS customer_email, r.name AS rider_name
     FROM orders o
     JOIN users u ON u.user_id = o.user_id
     LEFT JOIN users r ON r.user_id = o.rider_id
     $where
     ORDER BY o.created_at DESC"
);
$ordersStmt->execute($queryParams);
$orders = $ordersStmt->fetchAll();

$riders = $pdo->query(
    "SELECT u.user_id, u.name, COUNT(o.order_id) AS active_deliveries
     FROM users u
     LEFT JOIN orders o ON o.rider_id = u.user_id AND o.order_type = 'Delivery'
         AND o.status IN ('Ready', 'Out for Delivery')
     WHERE u.role = 'rider'
     GROUP BY u.user_id, u.name
     ORDER BY active_deliveries, u.name"
)->fetchAll();
$recommendedRiderId = $riders ? (int) $riders[0]['user_id'] : null;

$itemsStmt = $pdo->prepare('SELECT item_name, quantity FROM order_items WHERE order_id = ?');
$paymentStmt = $pdo->prepare('SELECT * FROM payments WHERE order_id = ? ORDER BY created_at DESC LIMIT 1');
$historyStmt = $pdo->prepare(
    'SELECT h.status, h.changed_at, u.name AS changed_by_name
     FROM order_status_history h
     LEFT JOIN users u ON u.user_id = h.changed_by
     WHERE h.order_id = ? ORDER BY h.changed_at ASC'
);

require __DIR__ . '/includes/admin_header.php';
?>

<h1>Orders</h1>
<?php if ($statusError): ?><p class="alert alert-error"><?= e($statusError) ?></p><?php endif; ?>

<form method="get" class="admin-order-search">
    <input type="hidden" name="filter" value="<?= e($filter) ?>">
    <label for="order-search">Search orders</label>
    <div class="admin-order-search-controls">
        <input id="order-search" type="search" name="q" maxlength="100" value="<?= e($search) ?>" placeholder="Order number, reference, customer or email">
        <button type="submit" class="btn btn-primary btn-sm">Search</button>
        <?php if ($search !== ''): ?><a class="btn btn-ghost btn-sm" href="<?= e($filterUrl($filter)) ?>">Clear</a><?php endif; ?>
    </div>
</form>

<div class="filter-tabs">
    <a href="<?= e($filterUrl('active')) ?>" class="<?= $filter === 'active' ? 'active' : '' ?>">Active</a>
    <a href="<?= e($filterUrl('Pending')) ?>" class="<?= $filter === 'Pending' ? 'active' : '' ?>">Pending</a>
    <a href="<?= e($filterUrl('Preparing')) ?>" class="<?= $filter === 'Preparing' ? 'active' : '' ?>">Preparing</a>
    <a href="<?= e($filterUrl('Ready')) ?>" class="<?= $filter === 'Ready' ? 'active' : '' ?>">Ready</a>
    <a href="<?= e($filterUrl('Out for Delivery')) ?>" class="<?= $filter === 'Out for Delivery' ? 'active' : '' ?>">Out for delivery</a>
    <a href="<?= e($filterUrl('Completed')) ?>" class="<?= $filter === 'Completed' ? 'active' : '' ?>">Completed</a>
    <a href="<?= e($filterUrl('Cancelled')) ?>" class="<?= $filter === 'Cancelled' ? 'active' : '' ?>">Cancelled</a>
</div>

<div class="orders-list">
    <?php foreach ($orders as $order): ?>
        <?php
        $itemsStmt->execute([$order['order_id']]);
        $orderItems = $itemsStmt->fetchAll();
        $paymentStmt->execute([$order['order_id']]);
        $payment = $paymentStmt->fetch();
        $historyStmt->execute([$order['order_id']]);
        $history = $historyStmt->fetchAll();
        $statusSince = $order['created_at'];
        foreach ($history as $historyEvent) {
            if ($historyEvent['status'] === $order['status']) $statusSince = $historyEvent['changed_at'];
        }
        $statusMinutes = max(0, (int) floor((time() - strtotime($statusSince)) / 60));
        ?>
        <article class="order-card admin-order-card" data-order-id="<?= (int) $order['order_id'] ?>">
            <div class="order-card-head">
                <div>
                    <strong>Order #<?= (int) $order['order_id'] ?></strong>
                    <?php if (!empty($order['reference_code'])): ?>
                        <span class="order-date">Ref: <?= e($order['reference_code']) ?></span>
                    <?php endif; ?>
                    <span class="order-date"><?= e($order['customer_name']) ?></span>
                    <span class="order-date"><?= date('M j, g:i A', strtotime($order['created_at'])) ?></span>
                    <?php if (!in_array($order['status'], ['Completed', 'Cancelled'], true)): ?>
                        <span class="order-age">In this status for <?= $statusMinutes < 60 ? $statusMinutes . ' min' : floor($statusMinutes / 60) . ' hr ' . ($statusMinutes % 60) . ' min' ?></span>
                    <?php endif; ?>
                </div>
                <span class="badge <?= $order['order_type'] === 'Delivery' ? 'badge-delivery' : 'badge-pending' ?>">
                    <?= e($order['order_type']) ?>
                </span>
                <span class="badge <?= status_badge_class($order['status']) ?>" data-status-label><?= e($order['status']) ?></span>
            </div>

            <ul class="order-items">
                <?php foreach ($orderItems as $oi): ?>
                    <li><?= (int) $oi['quantity'] ?> &times; <?= e($oi['item_name']) ?></li>
                <?php endforeach; ?>
                <?php if ((float) $order['loyalty_discount'] > 0): ?>
                    <li>Loyalty points applied: &minus;<?= peso((float) $order['loyalty_discount']) ?> (<?= (int) $order['points_redeemed'] ?> pts)</li>
                <?php endif; ?>
            </ul>

            <?php if ($order['order_type'] === 'Delivery'): ?>
                <div class="delivery-info">
                    <span><?= icon_pin() ?> <?= e($order['delivery_address']) ?></span>
                    <span>Delivery fee: <?= peso((float) $order['delivery_fee']) ?></span>
                    <span>Rider: <?= $order['rider_name'] ? e($order['rider_name']) : '— not assigned —' ?></span>
                </div>
            <?php endif; ?>

            <?php if (
                $order['order_type'] === 'Delivery'
                && $order['status'] === 'Out for Delivery'
                && $order['delivery_lat'] && $order['delivery_lng']
            ): ?>
                <div class="rider-map-wrap"
                     data-rider-map
                     data-delivery-lat="<?= (float) $order['delivery_lat'] ?>"
                     data-delivery-lng="<?= (float) $order['delivery_lng'] ?>"
                     data-rider-lat="<?= $order['rider_lat'] !== null ? (float) $order['rider_lat'] : '' ?>"
                     data-rider-lng="<?= $order['rider_lng'] !== null ? (float) $order['rider_lng'] : '' ?>">
                    <p class="map-caption">Rider location <span data-map-caption-detail>— waiting for rider to share location…</span></p>
                </div>
            <?php endif; ?>

            <div class="order-card-foot">
                <span>Total: <strong><?= peso((float) $order['total_amount']) ?></strong></span>
                <span>Payment: <span class="badge <?= payment_badge_class($order['payment_status']) ?>"><?= e($order['payment_status']) ?></span></span>
            </div>

            <?php if ($payment): ?>
                <div class="delivery-info payment-verify-box">
                    <span>Method: <strong><?= e($payment['method']) ?></strong></span>
                    <?php if ($payment['method'] === 'GCash'): ?>
                        <span>Reference #: <strong><?= e($payment['reference_number']) ?></strong> — check this against your GCash app</span>
                    <?php endif; ?>
                    <?php if ($payment['status'] === 'Paid'): ?>
                        <span><?= icon_check() ?> Confirmed<?= $payment['verified_at'] ? ' on ' . date('M j, g:i A', strtotime($payment['verified_at'])) : '' ?></span>
                    <?php elseif (in_array($payment['status'], ['Unpaid', 'Pending Verification'], true)): ?>
                        <form method="post" class="status-form" style="margin-top:6px;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="verify_payment">
                            <input type="hidden" name="order_id" value="<?= (int) $order['order_id'] ?>">
                            <button type="submit" class="btn btn-primary btn-sm">
                                <?= $payment['method'] === 'GCash' ? 'Confirm reference # matches — mark Paid' : 'Confirm cash received — mark Paid' ?>
                            </button>
                        </form>
                        <?php if ($payment['method'] === 'GCash'): ?>
                            <form method="post" class="status-form" style="margin-top:4px;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="fail_payment">
                                <input type="hidden" name="order_id" value="<?= (int) $order['order_id'] ?>">
                                <button type="submit" class="btn btn-remove btn-sm">Reference doesn't match — mark Failed</button>
                            </form>
                        <?php endif; ?>
                    <?php elseif ($payment['status'] === 'Failed'): ?>
                        <span><?= icon_x() ?> Payment failed — customer needs to resubmit a reference number.</span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($order['order_type'] === 'Delivery' && $riders && in_array($order['status'], ['Pending', 'Preparing', 'Ready'], true)): ?>
                <form method="post" class="status-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="assign_rider">
                    <input type="hidden" name="order_id" value="<?= (int) $order['order_id'] ?>">
                    <select name="rider_id" aria-label="Assign delivery rider">
                        <?php foreach ($riders as $r): ?>
                            <option value="<?= (int) $r['user_id'] ?>" <?= (int) $order['rider_id'] === (int) $r['user_id'] || (!$order['rider_id'] && (int) $r['user_id'] === $recommendedRiderId) ? 'selected' : '' ?>>
                                <?= e($r['name']) ?> — <?= (int) $r['active_deliveries'] ?> active<?= !$order['rider_id'] && (int) $r['user_id'] === $recommendedRiderId ? ' · suggested' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-ghost btn-sm">Assign</button>
                </form>
                <p class="form-note">Suggestion is based on the fewest active deliveries; you can choose another rider.</p>
            <?php endif; ?>

            <?php if ($history): ?>
                <details class="order-history">
                    <summary>View status history (<?= count($history) ?>)</summary>
                    <ul class="order-history-list">
                        <?php foreach ($history as $h): ?>
                            <li>
                                <span class="badge <?= status_badge_class($h['status']) ?>"><?= e($h['status']) ?></span>
                                <span class="order-history-meta">
                                    <?= date('M j, g:i A', strtotime($h['changed_at'])) ?>
                                    — <?= $h['changed_by_name'] ? e($h['changed_by_name']) : 'customer' ?>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </details>
            <?php endif; ?>

            <form method="post" class="status-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="order_id" value="<?= (int) $order['order_id'] ?>">
                <select name="status">
                    <?php
                    $statusOptions = array_merge(
                        [$order['status']],
                        $nextStatuses($order['status'], $order['order_type'])
                    );
                    foreach ($statusOptions as $s): ?>
                        <option value="<?= $s ?>" <?= $order['status'] === $s ? 'selected' : '' ?>><?= $s ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-primary btn-sm">Update status</button>
            </form>
        </article>
    <?php endforeach; ?>

    <?php if (!$orders): ?>
        <p class="empty-state">No orders in this view.</p>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/admin_footer.php'; ?>
