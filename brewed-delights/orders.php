<?php
require_once __DIR__ . '/includes/functions.php';
require_login();

$pageTitle = 'My orders — Brewed Delights';
$user = current_user();
$feedbackSuccess = $_SESSION['feedback_success'] ?? (!empty($_SESSION['rider_feedback_success']) ? 'Thanks — your rider feedback has been recorded.' : null);
$feedbackError = $_SESSION['feedback_error'] ?? $_SESSION['rider_feedback_error'] ?? null;
$deliveryConfirmSuccess = $_SESSION['delivery_confirm_success'] ?? null;
$deliveryConfirmError = $_SESSION['delivery_confirm_error'] ?? null;
unset($_SESSION['feedback_success'], $_SESSION['feedback_error']);
unset($_SESSION['rider_feedback_success']);
unset($_SESSION['rider_feedback_error']);
unset($_SESSION['delivery_confirm_success'], $_SESSION['delivery_confirm_error']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $orderId = (int) ($_POST['order_id'] ?? 0);
    $action = $_POST['action'] ?? '';

    if ($action === 'rider_feedback') {
        $rating = (int) ($_POST['rider_rating'] ?? 0);
        $comments = trim($_POST['rider_comments'] ?? '');
        $orderCheck = $pdo->prepare(
            "SELECT order_id, rider_id FROM orders
             WHERE order_id = ? AND user_id = ? AND order_type = 'Delivery' AND status = 'Completed' AND rider_id IS NOT NULL"
        );
        $orderCheck->execute([$orderId, $user['user_id']]);
        $completedDelivery = $orderCheck->fetch();

        if (!$completedDelivery) {
            $_SESSION['feedback_error'] = 'That completed delivery could not be found for your account.';
        } elseif ($rating < 1 || $rating > 5) {
            $_SESSION['feedback_error'] = 'Choose a rating from 1 to 5 stars.';
        } elseif (mb_strlen($comments) > 2000) {
            $_SESSION['feedback_error'] = 'Your comment must be 2,000 characters or fewer.';
        } else {
            $existingFeedback = $pdo->prepare('SELECT feedback_id FROM rider_feedback WHERE order_id = ?');
            $existingFeedback->execute([$orderId]);
            if ($existingFeedback->fetch()) {
                $_SESSION['feedback_error'] = 'You have already reviewed this rider.';
            } else {
                $pdo->prepare(
                    'INSERT INTO rider_feedback (order_id, rider_id, customer_id, rating, comments) VALUES (?, ?, ?, ?, ?)'
                )->execute([$orderId, $completedDelivery['rider_id'], $user['user_id'], $rating, $comments ?: null]);
                $_SESSION['feedback_success'] = 'Thanks — your rider feedback has been recorded.';
            }
        }
        header('Location: orders.php');
        exit;
    }

    if ($action === 'confirm_delivery') {
        try {
            $pdo->beginTransaction();
            $orderCheck = $pdo->prepare(
                "SELECT order_id, rider_id, order_type, status, payment_method, payment_status
                 FROM orders WHERE order_id = ? AND user_id = ? FOR UPDATE"
            );
            $orderCheck->execute([$orderId, $user['user_id']]);
            $deliveryOrder = $orderCheck->fetch();

            if (!$deliveryOrder || $deliveryOrder['order_type'] !== 'Delivery'
                || $deliveryOrder['status'] !== 'Out for Delivery' || !$deliveryOrder['rider_id']) {
                $pdo->rollBack();
                $_SESSION['delivery_confirm_error'] = 'This delivery is no longer awaiting your confirmation.';
            } else {
                $cashNeedsConfirmation = $deliveryOrder['payment_method'] === 'Cash'
                    && $deliveryOrder['payment_status'] !== 'Paid';

                if ($cashNeedsConfirmation && ($_POST['confirm_cash_received'] ?? '') !== '1') {
                    $pdo->rollBack();
                    $_SESSION['delivery_confirm_error'] = 'Confirm that you received the order and paid the rider in cash.';
                } else {
                    if ($cashNeedsConfirmation) {
                        $paymentStmt = $pdo->prepare(
                            'SELECT payment_id FROM payments
                             WHERE order_id = ? AND method = "Cash"
                             ORDER BY payment_id DESC LIMIT 1 FOR UPDATE'
                        );
                        $paymentStmt->execute([$orderId]);
                        $paymentId = $paymentStmt->fetchColumn();
                        if ($paymentId) {
                            $pdo->prepare(
                                'UPDATE payments SET status = "Paid", verified_by = ?, verified_at = NOW()
                                 WHERE payment_id = ?'
                            )->execute([$user['user_id'], $paymentId]);
                        } else {
                            $pdo->prepare(
                                'INSERT INTO payments (order_id, method, status, verified_by, verified_at)
                                 VALUES (?, "Cash", "Paid", ?, NOW())'
                            )->execute([$orderId, $user['user_id']]);
                        }
                        $pdo->prepare(
                            'UPDATE orders SET payment_status = "Paid" WHERE order_id = ?'
                        )->execute([$orderId]);
                    }

                    $statusUpdate = $pdo->prepare(
                        'UPDATE orders SET status = "Completed"
                         WHERE order_id = ? AND user_id = ? AND status = "Out for Delivery"'
                    );
                    $statusUpdate->execute([$orderId, $user['user_id']]);
                    if ($statusUpdate->rowCount() !== 1) {
                        $pdo->rollBack();
                        $_SESSION['delivery_confirm_error'] = 'This delivery was already completed by the rider.';
                    } else {
                        log_order_status($pdo, $orderId, 'Completed', $user['user_id']);
                        award_loyalty_points($pdo, $orderId);
                        $pdo->commit();
                        send_order_email($pdo, $orderId, 'status:Completed');
                        if ($cashNeedsConfirmation) send_order_email($pdo, $orderId, 'paid');
                        $_SESSION['delivery_confirm_success'] = 'Delivery confirmed. Thanks for ordering!';
                    }
                }
            }
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Customer delivery confirmation failed for order #' . $orderId . ': ' . $ex->getMessage());
            $_SESSION['delivery_confirm_error'] = 'Could not confirm this delivery. Please refresh and check its status.';
        }
        header('Location: orders.php');
        exit;
    }

    if ($action === 'order_feedback') {
        $rating = (int) ($_POST['rating'] ?? 0);
        $comments = trim($_POST['comments'] ?? '');
        $orderCheck = $pdo->prepare(
            "SELECT order_id FROM orders WHERE order_id = ? AND user_id = ? AND status = 'Completed'"
        );
        $orderCheck->execute([$orderId, $user['user_id']]);

        if (!$orderCheck->fetch()) {
            $_SESSION['feedback_error'] = 'Only your completed orders can receive feedback.';
        } elseif ($rating < 1 || $rating > 5) {
            $_SESSION['feedback_error'] = 'Choose a rating from 1 to 5 stars.';
        } elseif (mb_strlen($comments) > 2000) {
            $_SESSION['feedback_error'] = 'Your comment must be 2,000 characters or fewer.';
        } else {
            $existingFeedback = $pdo->prepare(
                'SELECT feedback_id FROM feedback WHERE user_id = ? AND order_id = ? LIMIT 1'
            );
            $existingFeedback->execute([$user['user_id'], $orderId]);
            if ($existingFeedback->fetch()) {
                $_SESSION['feedback_error'] = 'You have already sent general feedback for this order.';
            } else {
                $pdo->prepare(
                    'INSERT INTO feedback (user_id, order_id, rating, comments) VALUES (?, ?, ?, ?)'
                )->execute([$user['user_id'], $orderId, $rating, $comments ?: null]);
                $_SESSION['feedback_success'] = 'Thanks — your order feedback has been recorded.';
            }
        }
        header('Location: orders.php');
        exit;
    }

    if ($action === 'product_feedback') {
        $rating = (int) ($_POST['rating'] ?? 0);
        $comments = trim($_POST['comments'] ?? '');
        $orderItemId = (int) ($_POST['order_item_id'] ?? 0);
        $itemCheck = $pdo->prepare(
            "SELECT oi.order_item_id FROM order_items oi
             JOIN orders o ON o.order_id = oi.order_id
             WHERE oi.order_item_id = ? AND o.order_id = ? AND o.user_id = ? AND o.status = 'Completed'"
        );
        $itemCheck->execute([$orderItemId, $orderId, $user['user_id']]);

        if (!$itemCheck->fetch()) {
            $_SESSION['feedback_error'] = 'That product was not found in one of your completed orders.';
        } elseif ($rating < 1 || $rating > 5) {
            $_SESSION['feedback_error'] = 'Choose a rating from 1 to 5 stars.';
        } elseif (mb_strlen($comments) > 2000) {
            $_SESSION['feedback_error'] = 'Your comment must be 2,000 characters or fewer.';
        } else {
            $existingFeedback = $pdo->prepare('SELECT feedback_id FROM product_feedback WHERE order_item_id = ?');
            $existingFeedback->execute([$orderItemId]);
            if ($existingFeedback->fetch()) {
                $_SESSION['feedback_error'] = 'You have already reviewed this product from this order.';
            } else {
                $pdo->prepare(
                    'INSERT INTO product_feedback (order_item_id, order_id, customer_id, rating, comments)
                     VALUES (?, ?, ?, ?, ?)'
                )->execute([$orderItemId, $orderId, $user['user_id'], $rating, $comments ?: null]);
                $_SESSION['feedback_success'] = 'Thanks — your product feedback has been recorded.';
            }
        }
        header('Location: orders.php');
        exit;
    }

    if ($action === 'resubmit_payment') {
        $newReference = trim($_POST['reference_number'] ?? '');
        $check = $pdo->prepare(
            "SELECT p.payment_id FROM payments p
             JOIN orders o ON o.order_id = p.order_id
             WHERE p.order_id = ? AND o.user_id = ? AND p.status = 'Failed' AND p.method = 'GCash'"
        );
        $check->execute([$orderId, $user['user_id']]);
        $payment = $check->fetch();

        if ($payment && strlen($newReference) >= 6) {
            $pdo->prepare(
                'UPDATE payments SET reference_number = ?, status = "Pending Verification" WHERE payment_id = ?'
            )->execute([$newReference, $payment['payment_id']]);
            $pdo->prepare('UPDATE orders SET payment_status = "Pending Verification" WHERE order_id = ?')
                ->execute([$orderId]);
        }
    }
    header('Location: orders.php');
    exit;
}

$stmt = $pdo->prepare(
    'SELECT o.*, r.name AS rider_name, r.phone_number AS rider_phone
     FROM orders o
     LEFT JOIN users r ON r.user_id = o.rider_id
     WHERE o.user_id = ? ORDER BY o.created_at DESC'
);
$stmt->execute([$user['user_id']]);
$orders = $stmt->fetchAll();

$itemsStmt = $pdo->prepare(
    'SELECT oi.order_item_id, oi.item_id, oi.item_name, oi.quantity, oi.unit_price, m.image_path
     FROM order_items oi LEFT JOIN menu m ON m.item_id = oi.item_id
     WHERE oi.order_id = ? ORDER BY oi.order_item_id'
);
$paymentStmt = $pdo->prepare('SELECT * FROM payments WHERE order_id = ? ORDER BY created_at DESC LIMIT 1');
$riderFeedbackStmt = $pdo->prepare('SELECT rating, comments FROM rider_feedback WHERE order_id = ? AND customer_id = ?');
$orderFeedbackStmt = $pdo->prepare('SELECT rating, comments FROM feedback WHERE order_id = ? AND user_id = ? ORDER BY feedback_id DESC LIMIT 1');
$productFeedbackStmt = $pdo->prepare('SELECT order_item_id, rating, comments FROM product_feedback WHERE order_id = ? AND customer_id = ?');

require __DIR__ . '/includes/header.php';
?>

<section class="page-section" data-orders-page>
    <?= render_breadcrumb([['label' => 'Menu', 'href' => 'index.php'], ['label' => 'My orders']]) ?>
    <h1>My orders</h1>
    <?php if ($feedbackSuccess): ?><p class="alert alert-success"><?= e($feedbackSuccess) ?></p><?php endif; ?>
    <?php if ($feedbackError): ?><p class="alert alert-error"><?= e($feedbackError) ?></p><?php endif; ?>
    <?php if ($deliveryConfirmSuccess): ?><p class="alert alert-success"><?= e($deliveryConfirmSuccess) ?></p><?php endif; ?>
    <?php if ($deliveryConfirmError): ?><p class="alert alert-error"><?= e($deliveryConfirmError) ?></p><?php endif; ?>

    <div class="orders-list" data-orders-list>
        <?php if (empty($orders)): ?>
            <p class="empty-state" data-orders-empty>No orders yet. <a href="index.php">Start an order</a>.</p>
        <?php else: ?>
            <?php foreach ($orders as $order): ?>
                <?php
                $itemsStmt->execute([$order['order_id']]);
                $orderItems = $itemsStmt->fetchAll();
                $paymentStmt->execute([$order['order_id']]);
                $payment = $paymentStmt->fetch();
                $riderFeedbackStmt->execute([$order['order_id'], $user['user_id']]);
                $riderFeedback = $riderFeedbackStmt->fetch() ?: null;
                $orderFeedbackStmt->execute([$order['order_id'], $user['user_id']]);
                $orderFeedback = $orderFeedbackStmt->fetch() ?: null;
                $productFeedbackStmt->execute([$order['order_id'], $user['user_id']]);
                $productFeedbackByItem = [];
                foreach ($productFeedbackStmt->fetchAll() as $productReview) {
                    $productFeedbackByItem[(int) $productReview['order_item_id']] = $productReview;
                }
                ?>
                <article class="order-card" data-order-id="<?= (int) $order['order_id'] ?>">
                    <div class="order-card-head">
                        <div>
                            <strong>Order #<?= (int) $order['order_id'] ?></strong>
                            <?php if (!empty($order['reference_code'])): ?>
                                <span class="order-date">Ref: <?= e($order['reference_code']) ?></span>
                            <?php endif; ?>
                            <span class="order-date"><?= date('M j, Y g:i A', strtotime($order['created_at'])) ?></span>
                        </div>
                        <span class="badge <?= status_badge_class($order['status']) ?>" data-status-label>
                            <?= e($order['status']) ?>
                        </span>
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
                        <?php $riderTel = preg_replace('/[^0-9+]/', '', (string) ($order['rider_phone'] ?? '')); ?>
                        <div class="delivery-info" data-rider-contact>
                            <span><?= icon_pin() ?> <?= e($order['delivery_address']) ?></span>
                            <span data-rider-details>Rider: <?= $order['rider_name'] ? e($order['rider_name']) : 'Assigning soon…' ?></span>
                            <a data-rider-phone href="tel:<?= e($riderTel) ?>" <?= $order['status'] !== 'Out for Delivery' || $riderTel === '' ? 'hidden' : '' ?>>
                                Call rider<?= $riderTel !== '' ? ': ' . e($order['rider_phone']) : '' ?>
                            </a>
                        </div>
                        <?php if ($order['status'] === 'Out for Delivery' && $order['rider_id']): ?>
                            <form method="post" class="status-form customer-delivery-confirm">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="confirm_delivery">
                                <input type="hidden" name="order_id" value="<?= (int) $order['order_id'] ?>">
                                <?php if ($order['payment_method'] === 'Cash' && $order['payment_status'] !== 'Paid'): ?>
                                    <label class="checkbox-label">
                                        <input type="checkbox" name="confirm_cash_received" value="1" required>
                                        I received the order and paid <?= peso((float) $order['total_amount']) ?> in cash
                                    </label>
                                <?php endif; ?>
                                <button type="submit" class="btn btn-ghost btn-sm">Confirm delivery received</button>
                            </form>
                        <?php endif; ?>
                    <?php endif; ?>

                    <div class="order-card-foot">
                        <span>Total: <strong><?= peso((float) $order['total_amount']) ?></strong></span>
                        <span><?= e($order['payment_method']) ?>: <span class="badge <?= payment_badge_class($order['payment_status']) ?>"><?= e($order['payment_status']) ?></span></span>
                    </div>

                    <?php if ($payment && $payment['status'] === 'Failed'): ?>
                        <div class="delivery-info">
                            <span><?= icon_x() ?> We couldn't verify GCash reference <strong><?= e($payment['reference_number']) ?></strong>.</span>
                            <form method="post" class="status-form" style="margin-top:6px;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="resubmit_payment">
                                <input type="hidden" name="order_id" value="<?= (int) $order['order_id'] ?>">
                                <input type="text" name="reference_number" placeholder="Correct reference number" minlength="6" required style="width:auto;flex-grow:1;">
                                <button type="submit" class="btn btn-primary btn-sm">Resubmit</button>
                            </form>
                        </div>
                    <?php elseif ($payment && $payment['status'] === 'Pending Verification'): ?>
                        <div class="delivery-info">
                            <span><?= icon_clock() ?> Awaiting confirmation for reference <strong><?= e($payment['reference_number']) ?></strong>.</span>
                        </div>
                    <?php endif; ?>

                    <?php if ($order['status'] === 'Completed'): ?>
                        <section class="order-feedback-box" aria-label="Feedback for order #<?= (int) $order['order_id'] ?>">
                            <h3>Product feedback</h3>
                            <div class="product-feedback-list">
                                <?php foreach ($orderItems as $orderItem): ?>
                                    <?php $productReview = $productFeedbackByItem[(int) $orderItem['order_item_id']] ?? null; ?>
                                    <?php $productImagePath = menu_item_image_path($orderItem); ?>
                                    <article class="product-feedback-item">
                                        <?php if ($productImagePath): ?>
                                            <img class="product-feedback-thumb" src="<?= e($productImagePath) ?>" alt="<?= e($orderItem['item_name']) ?>">
                                        <?php else: ?>
                                            <span class="product-feedback-thumb product-feedback-fallback" aria-hidden="true"><?= e(mb_substr($orderItem['item_name'], 0, 1)) ?></span>
                                        <?php endif; ?>
                                        <div class="product-feedback-item-content">
                                            <strong><?= (int) $orderItem['quantity'] ?> &times; <?= e($orderItem['item_name']) ?></strong>
                                            <?php if ($productReview): ?>
                                                <p><span class="stars"><?= str_repeat('★', (int) $productReview['rating']) . str_repeat('☆', 5 - (int) $productReview['rating']) ?></span>
                                                    <?= $productReview['comments'] ? e($productReview['comments']) : '<span class="muted">No comment</span>' ?></p>
                                            <?php else: ?>
                                                <form method="post" class="product-feedback-form">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="product_feedback">
                                                    <input type="hidden" name="order_id" value="<?= (int) $order['order_id'] ?>">
                                                    <input type="hidden" name="order_item_id" value="<?= (int) $orderItem['order_item_id'] ?>">
                                                    <label>Rate this product
                                                        <select name="rating" required>
                                                            <option value="">Choose a rating</option>
                                                            <option value="5">5 - Excellent</option>
                                                            <option value="4">4 - Good</option>
                                                            <option value="3">3 - Okay</option>
                                                            <option value="2">2 - Poor</option>
                                                            <option value="1">1 - Very poor</option>
                                                        </select>
                                                    </label>
                                                    <label>Comment (optional)
                                                        <textarea name="comments" rows="2" maxlength="2000" placeholder="What did you think of this item?"></textarea>
                                                    </label>
                                                    <button type="submit" class="btn btn-ghost btn-sm">Send product feedback</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            </div>

                            <h3>Order feedback</h3>
                            <?php if ($orderFeedback): ?>
                                <p><span class="stars"><?= str_repeat('★', (int) $orderFeedback['rating']) . str_repeat('☆', 5 - (int) $orderFeedback['rating']) ?></span>
                                    <?= $orderFeedback['comments'] ? e($orderFeedback['comments']) : '<span class="muted">No comment</span>' ?></p>
                            <?php else: ?>
                                <form method="post" class="product-feedback-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="order_feedback">
                                    <input type="hidden" name="order_id" value="<?= (int) $order['order_id'] ?>">
                                    <label>Rate your overall experience
                                        <select name="rating" required>
                                            <option value="">Choose a rating</option>
                                            <option value="5">5 - Excellent</option>
                                            <option value="4">4 - Good</option>
                                            <option value="3">3 - Okay</option>
                                            <option value="2">2 - Poor</option>
                                            <option value="1">1 - Very poor</option>
                                        </select>
                                    </label>
                                    <label>Comment (optional)
                                        <textarea name="comments" rows="2" maxlength="2000" placeholder="How was your overall order experience?"></textarea>
                                    </label>
                                    <button type="submit" class="btn btn-ghost btn-sm">Send order feedback</button>
                                </form>
                            <?php endif; ?>

                            <?php if ($order['order_type'] === 'Delivery' && $order['rider_id']): ?>
                                <div class="order-feedback-subsection">
                                    <h3>Rider feedback</h3>
                                    <?php if ($riderFeedback): ?>
                                        <p><strong>Your rider rating</strong>
                                            <span class="stars"><?= str_repeat('★', (int) $riderFeedback['rating']) . str_repeat('☆', 5 - (int) $riderFeedback['rating']) ?></span>
                                        </p>
                                        <?php if ($riderFeedback['comments']): ?><p><?= e($riderFeedback['comments']) ?></p><?php endif; ?>
                                    <?php else: ?>
                                        <form method="post" class="rider-feedback-form">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="rider_feedback">
                                            <input type="hidden" name="order_id" value="<?= (int) $order['order_id'] ?>">
                                            <label>Rate your rider
                                                <select name="rider_rating" required>
                                                    <option value="">Choose a rating</option>
                                                    <option value="5">5 - Excellent</option>
                                                    <option value="4">4 - Good</option>
                                                    <option value="3">3 - Okay</option>
                                                    <option value="2">2 - Poor</option>
                                                    <option value="1">1 - Very poor</option>
                                                </select>
                                            </label>
                                            <label>Comment (optional)
                                                <textarea name="rider_comments" rows="2" maxlength="2000" placeholder="How was your delivery experience?"></textarea>
                                            </label>
                                            <button type="submit" class="btn btn-ghost btn-sm">Send rider feedback</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </section>
                    <?php endif; ?>

                    <?php
                    $steps = $order['order_type'] === 'Delivery'
                        ? ['Pending', 'Preparing', 'Ready', 'Out for Delivery', 'Completed']
                        : ['Pending', 'Preparing', 'Ready', 'Completed'];
                    $currentIdx = array_search($order['status'], $steps, true);
                    $isCancelled = $order['status'] === 'Cancelled';
                    ?>

                    <div class="brew-track" data-brew-percent="<?= $isCancelled ? 0 : brew_percent($steps, $order['status']) ?>">
                        <?= render_brew_cup($isCancelled ? 0 : brew_percent($steps, $order['status']), $isCancelled) ?>
                        <div class="status-track" data-status-track data-order-type="<?= e($order['order_type']) ?>">
                            <?php foreach ($steps as $i => $step):
                                $stateClass = $isCancelled
                                    ? 'step-cancelled'
                                    : ($currentIdx !== false && $i <= $currentIdx ? 'step-done' : '');
                            ?>
                                <div class="track-step <?= $stateClass ?>">
                                    <span class="dot"></span><span><?= $step ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

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
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
