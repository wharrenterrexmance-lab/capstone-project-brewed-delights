<?php
/**
 * FUTURE WORK: see api/auth.php for context — this whole /api folder
 * is a working foundation for a future mobile app, not used by the
 * current web system.
 *
 * GET  /api/orders.php            -> list the authenticated user's orders
 * GET  /api/orders.php?order_id=5 -> full detail for one order, including
 *                                    delivery/rider tracking fields
 * POST /api/orders.php            -> place a new order
 *
 * Header on every request: Authorization: Bearer <token>
 *
 * POST body (JSON):
 * {
 *   "order_type": "Pickup" | "Delivery",
 *   "items": [ { "item_id": 1, "option_id": 3, "addon_ids": [8, 9], "qty": 2 }, ... ],
 *   "delivery_address": "...",      // required if order_type = Delivery
 *   "delivery_lat": 14.1234,        // optional GPS pin
 *   "delivery_lng": 121.1234,
 *   "payment_method": "GCash" | "Cash",
 *   "reference_number": "...",      // required if payment_method = GCash
 *   "points_to_redeem": 0           // optional, 1 point = ₱1 off
 * }
 */
require_once __DIR__ . '/../includes/api_auth.php';

const API_DELIVERY_FEE = 49.00;

$authUser = api_authenticate($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['order_id'])) {
        $orderId = (int) $_GET['order_id'];
        $stmt = $pdo->prepare(
            'SELECT o.*, r.name AS rider_name, r.phone_number AS rider_phone
             FROM orders o
             LEFT JOIN users r ON r.user_id = o.rider_id
             WHERE o.order_id = ? AND o.user_id = ?'
        );
        $stmt->execute([$orderId, $authUser['user_id']]);
        $order = $stmt->fetch();

        if (!$order) {
            api_fail(404, 'Order not found.');
        }

        $itemsStmt = $pdo->prepare('SELECT item_name, unit_price, quantity, line_total FROM order_items WHERE order_id = ?');
        $itemsStmt->execute([$orderId]);
        $order['items'] = $itemsStmt->fetchAll();

        $paymentStmt = $pdo->prepare('SELECT method, reference_number, status FROM payments WHERE order_id = ? ORDER BY created_at DESC LIMIT 1');
        $paymentStmt->execute([$orderId]);
        $order['payment'] = $paymentStmt->fetch() ?: null;

        if ($order['rider_lat'] !== null && $order['delivery_lat'] !== null) {
            $order['rider_distance_km'] = round(
                haversine_km(
                    (float) $order['delivery_lat'], (float) $order['delivery_lng'],
                    (float) $order['rider_lat'], (float) $order['rider_lng']
                ), 2
            );
        }

        api_ok(['order' => $order]);
    }

    $stmt = $pdo->prepare(
        'SELECT o.*, r.name AS rider_name, r.phone_number AS rider_phone
         FROM orders o LEFT JOIN users r ON r.user_id = o.rider_id
         WHERE o.user_id = ? ORDER BY o.created_at DESC'
    );
    $stmt->execute([$authUser['user_id']]);
    $orders = $stmt->fetchAll();

    api_ok(['orders' => $orders]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = api_json_body();

    $orderType = ($body['order_type'] ?? 'Pickup') === 'Delivery' ? 'Delivery' : 'Pickup';
    $items = is_array($body['items'] ?? null) ? $body['items'] : [];
    $deliveryAddress = trim((string) ($body['delivery_address'] ?? ''));
    $deliveryLat = isset($body['delivery_lat']) ? (float) $body['delivery_lat'] : null;
    $deliveryLng = isset($body['delivery_lng']) ? (float) $body['delivery_lng'] : null;
    $paymentMethod = ($body['payment_method'] ?? 'Cash') === 'GCash' ? 'GCash' : 'Cash';
    $referenceNumber = trim((string) ($body['reference_number'] ?? ''));
    $pointsToRedeemReq = max(0, (int) ($body['points_to_redeem'] ?? 0));

    if (empty($items)) {
        api_fail(422, 'items must be a non-empty array of { item_id, option_id, qty }.');
    }
    foreach ($items as $requestItem) {
        if (!is_array($requestItem)) {
            api_fail(422, 'Each item must include item_id, option_id when required, and qty.');
        }
    }
    if ($orderType === 'Delivery' && $deliveryAddress === '') {
        api_fail(422, 'delivery_address is required when order_type is Delivery.');
    }
    if ($paymentMethod === 'GCash' && !gcash_settings()['available']) {
        api_fail(422, 'GCash payment is temporarily unavailable. Please choose Cash or contact the shop.');
    }
    if ($paymentMethod === 'GCash' && strlen($referenceNumber) < 6) {
        api_fail(422, 'reference_number (6+ chars) is required when payment_method is GCash.');
    }
    if ((isset($body['delivery_lat']) || isset($body['delivery_lng']))
        && (!is_numeric($body['delivery_lat'] ?? null) || !is_numeric($body['delivery_lng'] ?? null)
            || $deliveryLat < -90 || $deliveryLat > 90
            || $deliveryLng < -180 || $deliveryLng > 180)) {
        api_fail(422, 'delivery_lat and delivery_lng must be a valid coordinate pair.');
    }

    $ids = array_map(fn($i) => (int) ($i['item_id'] ?? 0), $items);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT * FROM menu WHERE item_id IN ($placeholders) AND is_available = 1 AND category NOT IN ('Add-ons', 'Syrups')");
    $stmt->execute($ids);
    $menuById = [];
    foreach ($stmt->fetchAll() as $row) {
        $menuById[$row['item_id']] = $row;
    }

    $optionIds = array_values(array_unique(array_filter(array_map(
        static fn(array $reqItem): int => (int) ($reqItem['option_id'] ?? 0),
        $items
    ))));
    $optionsById = [];
    if ($optionIds) {
        $optionPlaceholders = implode(',', array_fill(0, count($optionIds), '?'));
        $optionStmt = $pdo->prepare("SELECT option_id, item_id, label, price FROM menu_options WHERE option_id IN ($optionPlaceholders)");
        $optionStmt->execute($optionIds);
        foreach ($optionStmt->fetchAll() as $option) $optionsById[$option['option_id']] = $option;
    }

    $addonIdsByItem = [];
    $allAddonIds = [];
    foreach ($items as $itemKey => $reqItem) {
        if (isset($reqItem['addon_ids']) && !is_array($reqItem['addon_ids'])) {
            api_fail(422, 'addon_ids must be an array of add-on item IDs.');
        }
        $addonIdsByItem[$itemKey] = array_values(array_unique(array_filter(array_map(
            'intval',
            $reqItem['addon_ids'] ?? []
        ), static fn(int $id): bool => $id > 0)));
        $allAddonIds = array_merge($allAddonIds, $addonIdsByItem[$itemKey]);
    }
    $allAddonIds = array_values(array_unique($allAddonIds));
    $addonsById = [];
    if ($allAddonIds) {
        $addonPlaceholders = implode(',', array_fill(0, count($allAddonIds), '?'));
        $addonStmt = $pdo->prepare(
            "SELECT item_id, name, price, category FROM menu
             WHERE is_available = 1 AND category IN ('Add-ons', 'Syrups') AND item_id IN ($addonPlaceholders)"
        );
        $addonStmt->execute($allAddonIds);
        foreach ($addonStmt->fetchAll() as $addon) $addonsById[$addon['item_id']] = $addon;
    }

    $lines = [];
    $total = 0.0;
    foreach ($items as $itemKey => $reqItem) {
        $itemId = (int) ($reqItem['item_id'] ?? 0);
        $qty = max(1, (int) ($reqItem['qty'] ?? 1));
        if (!isset($menuById[$itemId])) {
            api_fail(422, "Item {$itemId} is not available.");
        }
        $item = $menuById[$itemId];
        $optionId = (int) ($reqItem['option_id'] ?? 0);
        if ($optionId > 0 && (!isset($optionsById[$optionId]) || (int) $optionsById[$optionId]['item_id'] !== $itemId)) {
            api_fail(422, "Option {$optionId} is not valid for item {$itemId}.");
        }
        if ($optionId === 0) {
            $optionCheck = $pdo->prepare('SELECT COUNT(*) FROM menu_options WHERE item_id = ?');
            $optionCheck->execute([$itemId]);
            if ((int) $optionCheck->fetchColumn() > 0) {
                api_fail(422, "An option_id is required for item {$itemId}.");
            }
        }
        $option = $optionId > 0 ? $optionsById[$optionId] : null;
        $addonNames = [];
        $addonTotal = 0.0;
        if ($addonIdsByItem[$itemKey] && !menu_supports_extras($item['category'])) {
            api_fail(422, 'Extras can only be added to drinks.');
        }
        foreach ($addonIdsByItem[$itemKey] as $addonId) {
            if (!isset($addonsById[$addonId])) api_fail(422, "Extra {$addonId} is not available.");
            $addonNames[] = $addonsById[$addonId]['name'];
            $addonTotal += (float) $addonsById[$addonId]['price'];
        }
        $unitPrice = ($option ? (float) $option['price'] : (float) $item['price']) + $addonTotal;
        $itemName = $item['name'] . ($option ? ' - ' . $option['label'] : '');
        if ($addonNames) $itemName .= ' + ' . implode(', ', $addonNames);
        $lineTotal = $unitPrice * $qty;
        $total += $lineTotal;
        $lines[] = ['item' => $item, 'item_name' => $itemName, 'unit_price' => $unitPrice,
            'qty' => $qty, 'line_total' => $lineTotal];
    }

    $deliveryFee = $orderType === 'Delivery' ? API_DELIVERY_FEE : 0.0;
    $preDiscountTotal = $total + $deliveryFee;

    $balStmt = $pdo->prepare('SELECT points_balance FROM users WHERE user_id = ?');
    $balStmt->execute([$authUser['user_id']]);
    $pointsBalance = (int) $balStmt->fetchColumn();

    $maxRedeemable = min($pointsBalance, (int) floor($preDiscountTotal));
    $pointsToRedeem = min($pointsToRedeemReq, $maxRedeemable);
    $loyaltyDiscount = $pointsToRedeem * LOYALTY_PESO_PER_POINT;
    $grandTotal = max(0, $preDiscountTotal - $loyaltyDiscount);

    try {
        $pdo->beginTransaction();

        $paymentStatus = $paymentMethod === 'GCash' ? 'Pending Verification' : 'Unpaid';

        $orderStmt = $pdo->prepare(
            'INSERT INTO orders
                (user_id, total_amount, status, payment_status, payment_method,
                 points_redeemed, loyalty_discount,
                 order_type, delivery_fee, delivery_address, delivery_lat, delivery_lng)
             VALUES (?, ?, "Pending", ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $orderStmt->execute([
            $authUser['user_id'],
            $grandTotal,
            $paymentStatus,
            $paymentMethod,
            $pointsToRedeem,
            $loyaltyDiscount,
            $orderType,
            $deliveryFee,
            $orderType === 'Delivery' ? $deliveryAddress : null,
            $orderType === 'Delivery' ? $deliveryLat : null,
            $orderType === 'Delivery' ? $deliveryLng : null,
        ]);
        $orderId = (int) $pdo->lastInsertId();

        $itemStmt = $pdo->prepare(
            'INSERT INTO order_items (order_id, item_id, item_name, unit_price, quantity, line_total)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        foreach ($lines as $line) {
            $itemStmt->execute([
                $orderId, $line['item']['item_id'], $line['item_name'],
                $line['unit_price'], $line['qty'], $line['line_total'],
            ]);
        }

        $pdo->prepare(
            'INSERT INTO payments (order_id, method, reference_number, status)
             VALUES (?, ?, ?, ?)'
        )->execute([
            $orderId,
            $paymentMethod,
            $paymentMethod === 'GCash' ? $referenceNumber : null,
            $paymentStatus,
        ]);

        if ($pointsToRedeem > 0) {
            redeem_loyalty_points($pdo, $authUser['user_id'], $orderId, $pointsToRedeem);
        }

        log_order_status($pdo, $orderId, 'Pending', null);

        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        error_log('API order creation failed: ' . $ex->getMessage());
        api_fail(500, 'Could not place the order. Please try again.');
    }

    api_ok([
        'order_id' => $orderId,
        'total_amount' => $grandTotal,
        'payment_status' => $paymentStatus,
        'status' => 'Pending',
    ]);
}

api_fail(405, 'Use GET or POST.');
