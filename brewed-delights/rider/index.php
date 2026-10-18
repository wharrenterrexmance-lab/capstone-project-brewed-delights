<?php
require_once __DIR__ . '/../includes/functions.php';
require_rider();

$pageTitle = 'My deliveries — Rider';
$user = current_user();
$actionError = $_SESSION['rider_action_error'] ?? null;
unset($_SESSION['rider_action_error']);
$riderPhoneStmt = $pdo->prepare('SELECT phone_number FROM users WHERE user_id = ?');
$riderPhoneStmt->execute([$user['user_id']]);
$riderPhone = (string) ($riderPhoneStmt->fetchColumn() ?: '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $orderId   = (int) ($_POST['order_id'] ?? 0);
    $newStatus = $_POST['status'] ?? '';
    $allowed   = ['Out for Delivery', 'Completed'];

    if ($orderId && in_array($newStatus, $allowed, true)) {
        // Only allow a rider to update orders assigned to them.
        $check = $pdo->prepare(
            'SELECT order_id, status, order_type, payment_method, payment_status
             FROM orders WHERE order_id = ? AND rider_id = ?'
        );
        $check->execute([$orderId, $user['user_id']]);
        $assignedOrder = $check->fetch();
        $validTransition = $assignedOrder
            && $assignedOrder['order_type'] === 'Delivery'
            && (($assignedOrder['status'] === 'Ready' && $newStatus === 'Out for Delivery')
                || ($assignedOrder['status'] === 'Out for Delivery' && $newStatus === 'Completed'));

        if ($validTransition) {
            $cashNeedsConfirmation = $newStatus === 'Completed'
                && $assignedOrder['payment_method'] === 'Cash'
                && $assignedOrder['payment_status'] !== 'Paid';

            if ($cashNeedsConfirmation && ($_POST['confirm_cash'] ?? '') !== '1') {
                $_SESSION['rider_action_error'] = 'Confirm that you collected the cash before marking this delivery complete.';
            } else {
                try {
                    $pdo->beginTransaction();
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
                        $pdo->prepare('UPDATE orders SET payment_status = "Paid" WHERE order_id = ?')
                            ->execute([$orderId]);
                    }
                    $statusUpdate = $pdo->prepare(
                        'UPDATE orders SET status = ? WHERE order_id = ? AND rider_id = ? AND status = ?'
                    );
                    $statusUpdate->execute([$newStatus, $orderId, $user['user_id'], $assignedOrder['status']]);
                    if ($statusUpdate->rowCount() !== 1) {
                        $pdo->rollBack();
                        $_SESSION['rider_action_error'] = 'This order was already updated. Refresh and check its latest status.';
                    } else {
                        log_order_status($pdo, $orderId, $newStatus, $user['user_id']);
                        if ($newStatus === 'Completed') award_loyalty_points($pdo, $orderId);
                        $pdo->commit();
                        send_order_email($pdo, $orderId, 'status:' . $newStatus);
                        if ($cashNeedsConfirmation) send_order_email($pdo, $orderId, 'paid');
                    }
                } catch (Throwable $ex) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    error_log('Rider order update failed for order #' . $orderId . ': ' . $ex->getMessage());
                    $_SESSION['rider_action_error'] = 'Could not update this delivery. Please try again.';
                }
            }
        } else {
            $_SESSION['rider_action_error'] = 'That delivery status update is no longer valid. Refresh and try again.';
        }
    }
    header('Location: index.php');
    exit;
}

$stmt = $pdo->prepare(
    "SELECT o.*, u.name AS customer_name, u.email AS customer_email
     FROM orders o JOIN users u ON u.user_id = o.user_id
     WHERE o.rider_id = ? AND o.status IN ('Ready','Out for Delivery')
     ORDER BY FIELD(o.status,'Out for Delivery','Ready'), o.created_at"
);
$stmt->execute([$user['user_id']]);
$deliveries = $stmt->fetchAll();

$itemsStmt = $pdo->prepare('SELECT item_name, quantity FROM order_items WHERE order_id = ?');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>☕</text></svg>">
<title><?= e($pageTitle) ?></title>
<?php require __DIR__ . '/../includes/theme_head.php'; ?>
<?php if (array_filter($deliveries, static fn(array $order): bool => $order['delivery_lat'] !== null && $order['delivery_lng'] !== null)): ?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<?php endif; ?>
<link rel="stylesheet" href="../assets/css/style.css?v=drawer-theme-2">
</head>
<body class="admin-body">
<div class="admin-shell">
    <aside class="admin-sidebar">
        <div class="brand-mark">Brewed&nbsp;Delights</div>
        <p class="admin-tag">Rider dashboard</p>
        <nav class="admin-nav rider-nav">
            <a href="index.php#assigned-deliveries" class="active">Assigned deliveries</a>
            <a href="account.php">Account settings</a>
            <a href="history.php">Delivery history</a>
            <a href="feedback.php">Feedback</a>
        </nav>
        <?php require __DIR__ . '/../includes/theme_toggle.php'; ?>
        <div class="admin-user" style="margin-top:auto;">
            <span><?= e($user['name']) ?></span>
            <a href="../logout.php" class="btn btn-ghost btn-sm">Log out</a>
        </div>
    </aside>
    <main class="admin-main rider-dashboard" data-rider-dashboard>
        <h1 id="assigned-deliveries">Assigned deliveries</h1>
        <?php if ($actionError): ?><p class="alert alert-error"><?= e($actionError) ?></p><?php endif; ?>
        <?php if ($riderPhone === ''): ?>
            <p class="form-note">Add a phone number in <a href="account.php">My account</a> so customers can contact you during an active delivery.</p>
        <?php endif; ?>

        <div class="orders-list">
            <?php foreach ($deliveries as $order): ?>
                <?php
                $itemsStmt->execute([$order['order_id']]);
                $orderItems = $itemsStmt->fetchAll();
                ?>
                <article class="order-card" data-rider-order-id="<?= (int) $order['order_id'] ?>">
                    <div class="order-card-head">
                        <div>
                            <strong>Order #<?= (int) $order['order_id'] ?></strong>
                            <span class="order-date"><?= e($order['customer_name']) ?></span>
                        </div>
                        <span class="badge <?= status_badge_class($order['status']) ?>"><?= e($order['status']) ?></span>
                    </div>

                    <ul class="order-items">
                        <?php foreach ($orderItems as $oi): ?>
                            <li><?= (int) $oi['quantity'] ?> &times; <?= e($oi['item_name']) ?></li>
                        <?php endforeach; ?>
                    </ul>

                    <div class="delivery-info">
                        <span><?= icon_pin() ?> <?= e($order['delivery_address']) ?></span>
                        <?php if ($order['delivery_lat'] !== null && $order['delivery_lng'] !== null): ?>
                            <a href="https://www.openstreetmap.org/?mlat=<?= (float) $order['delivery_lat'] ?>&mlon=<?= (float) $order['delivery_lng'] ?>#map=17/<?= (float) $order['delivery_lat'] ?>/<?= (float) $order['delivery_lng'] ?>"
                               target="_blank" rel="noopener">Open pinned location</a>
                        <?php endif; ?>
                    </div>

                    <?php if ($order['delivery_lat'] !== null && $order['delivery_lng'] !== null): ?>
                        <div class="rider-destination-map-wrap">
                            <h3>Customer drop-off location</h3>
                            <div class="live-rider-map rider-destination-map"
                                 data-rider-destination-map
                                 data-lat="<?= (float) $order['delivery_lat'] ?>"
                                 data-lng="<?= (float) $order['delivery_lng'] ?>"
                                 role="img"
                                 aria-label="Map showing the customer's pinned delivery location"></div>
                            <p class="form-note" data-rider-map-status>Loading map…</p>
                        </div>
                    <?php else: ?>
                        <p class="form-note">The customer did not share a map pin. Use the delivery address above.</p>
                    <?php endif; ?>

                    <?php if ($order['status'] === 'Out for Delivery'): ?>
                        <div class="location-share-box" data-location-share-box>
                            <label class="checkbox-label">
                                <input type="checkbox" data-location-toggle>
                                Share my live location
                            </label>
                            <p class="form-note" data-location-status>Off — the customer on this active delivery cannot see your live location.</p>
                        </div>
                    <?php endif; ?>

                    <form method="post" class="status-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="order_id" value="<?= (int) $order['order_id'] ?>">
                        <?php if ($order['status'] === 'Ready'): ?>
                            <input type="hidden" name="status" value="Out for Delivery">
                            <button type="submit" class="btn btn-primary btn-sm">Confirm pickup &amp; start delivery</button>
                        <?php else: ?>
                            <input type="hidden" name="status" value="Completed">
                            <?php if ($order['payment_method'] === 'Cash' && $order['payment_status'] !== 'Paid'): ?>
                                <label class="checkbox-label">
                                    <input type="checkbox" name="confirm_cash" value="1" required>
                                    I collected <?= peso((float) $order['total_amount']) ?> in cash
                                </label>
                            <?php endif; ?>
                            <button type="submit" class="btn btn-primary btn-sm">Mark delivered</button>
                        <?php endif; ?>
                    </form>
                </article>
            <?php endforeach; ?>

            <?php if (!$deliveries): ?>
                <p class="empty-state">No deliveries assigned to you right now.</p>
            <?php endif; ?>
        </div>
    </main>
</div>

<script>
(function () {
    var toggles = Array.from(document.querySelectorAll('[data-location-toggle]'));
    var watchId = null;
    var sharingKey = 'bd-rider-location-sharing-<?= (int) $user['user_id'] ?>';

    function setSharingState(checked, message) {
        toggles.forEach(function (toggle) { toggle.checked = checked; });
        document.querySelectorAll('[data-location-status]').forEach(function (status) {
            status.textContent = message;
        });
    }

    function stopWatching() {
        if (watchId !== null) navigator.geolocation.clearWatch(watchId);
        watchId = null;
    }

    function sendLocation(lat, lng) {
        var body = new URLSearchParams();
        body.append('lat', lat);
        body.append('lng', lng);
        var token = document.querySelector('meta[name="csrf-token"]').content;
        fetch('location.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-Token': token
            },
            body: body.toString()
        }).then(function (response) {
            return response.json().then(function (data) {
                if (!response.ok || !data.ok) throw new Error('Location update rejected.');
                return data;
            });
        }).then(function (data) {
            if (data.updated_orders > 0) {
                setSharingState(true, 'Sharing live location — updated at ' + new Date().toLocaleTimeString());
            } else {
                setSharingState(true, 'Location received, but no active delivery is assigned.');
            }
        }).catch(function () {
            setSharingState(true, 'Location could not be sent. Check your connection and try again.');
        });
    }

    function startWatching() {
        if (!window.isSecureContext) {
            localStorage.removeItem(sharingKey);
            setSharingState(false, 'Live location requires HTTPS. Open the secure site URL to share your location.');
            return;
        }
        if (!navigator.geolocation) {
            localStorage.removeItem(sharingKey);
            setSharingState(false, 'This browser does not support location sharing.');
            return;
        }
        localStorage.setItem(sharingKey, 'on');
        setSharingState(true, 'Requesting your location…');
        watchId = navigator.geolocation.watchPosition(function (pos) {
            sendLocation(pos.coords.latitude, pos.coords.longitude);
        }, function (error) {
            stopWatching();
            var message = error.code === 1
                ? 'Location permission was denied. Allow location access in your browser settings.'
                : 'Could not read your location. Check GPS and browser location permissions, then try again.';
            localStorage.removeItem(sharingKey);
            setSharingState(false, message);
        }, { enableHighAccuracy: true, maximumAge: 5000, timeout: 10000 });
    }

    if (!toggles.length) {
        localStorage.removeItem(sharingKey);
    } else if (localStorage.getItem(sharingKey) === 'on') {
        startWatching();
    }

    toggles.forEach(function (toggle) {
        toggle.addEventListener('change', function () {
            if (toggle.checked) {
                if (watchId === null) startWatching();
            } else {
                stopWatching();
                localStorage.removeItem(sharingKey);
                setSharingState(false, 'Off — the customer cannot see your live location.');
            }
        });
    });
})();
</script>
<?php if (array_filter($deliveries, static fn(array $order): bool => $order['delivery_lat'] !== null && $order['delivery_lng'] !== null)): ?>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
(function () {
    'use strict';
    var mapContainers = document.querySelectorAll('[data-rider-destination-map]');
    if (!mapContainers.length) return;
    if (!window.L) {
        document.querySelectorAll('[data-rider-map-status]').forEach(function (status) {
            status.textContent = 'Map could not load. Use “Open pinned location” above instead.';
        });
        return;
    }

    mapContainers.forEach(function (container) {
        var lat = parseFloat(container.getAttribute('data-lat'));
        var lng = parseFloat(container.getAttribute('data-lng'));
        var map = window.L.map(container, { scrollWheelZoom: false }).setView([lat, lng], 16);
        window.L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap contributors</a>'
        }).addTo(map);
        window.L.circleMarker([lat, lng], {
            radius: 9, color: '#fff', weight: 2, fillColor: '#743b62', fillOpacity: 1
        }).addTo(map).bindPopup('Customer drop-off location');
        var status = container.parentElement.querySelector('[data-rider-map-status]');
        if (status) status.remove();
    });
})();
</script>
<?php endif; ?>
<script src="../assets/js/theme.js?v=drawer-theme-1"></script>
</body>
</html>
