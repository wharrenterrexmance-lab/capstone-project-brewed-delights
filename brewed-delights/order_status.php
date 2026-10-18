<?php
require_once __DIR__ . '/includes/functions.php';
header('Content-Type: application/json');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false]);
    exit;
}

$user = current_user();
$stmt = $pdo->prepare(
        'SELECT o.order_id, o.reference_code, o.created_at, o.total_amount,
            o.payment_method, o.status, o.payment_status, o.order_type,
            o.delivery_address, o.loyalty_discount, o.points_redeemed,
            o.delivery_lat, o.delivery_lng, o.rider_lat, o.rider_lng,
            o.rider_location_updated_at, r.name AS rider_name,
            r.phone_number AS rider_phone
     FROM orders o
     LEFT JOIN users r ON r.user_id = o.rider_id
     WHERE o.user_id = ? ORDER BY o.created_at DESC'
);
$stmt->execute([$user['user_id']]);
$orders = $stmt->fetchAll();
$orderIds = array_map('intval', array_column($orders, 'order_id'));
$itemsByOrder = [];
$paymentsByOrder = [];
if ($orderIds) {
    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
    $itemsStmt = $pdo->prepare("SELECT order_id, item_name, quantity FROM order_items WHERE order_id IN ($placeholders) ORDER BY order_item_id");
    $itemsStmt->execute($orderIds);
    foreach ($itemsStmt->fetchAll() as $item) $itemsByOrder[$item['order_id']][] = $item;

    $paymentStmt = $pdo->prepare(
        "SELECT p.order_id, p.status, p.reference_number
         FROM payments p
         JOIN (SELECT order_id, MAX(payment_id) AS payment_id FROM payments
               WHERE order_id IN ($placeholders) GROUP BY order_id) latest
           ON latest.payment_id = p.payment_id"
    );
    $paymentStmt->execute($orderIds);
    foreach ($paymentStmt->fetchAll() as $payment) $paymentsByOrder[$payment['order_id']] = $payment;
}
foreach ($orders as &$order) {
    $order['created_at_display'] = date('M j, Y g:i A', strtotime($order['created_at']));
    $order['items'] = $itemsByOrder[$order['order_id']] ?? [];
    $order['payment'] = $paymentsByOrder[$order['order_id']] ?? null;
}
unset($order);

echo json_encode(['ok' => true, 'orders' => $orders]);
