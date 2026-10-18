<?php
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json');

if (!is_staff_or_admin()) {
    http_response_code(401);
    echo json_encode(['ok' => false]);
    exit;
}

// Include recent terminal states so open admin pages can remove completed
// orders from active filters and add them to the Completed filter.
$stmt = $pdo->query(
    "SELECT o.order_id, o.status, o.payment_status, o.order_type,
            o.delivery_lat, o.delivery_lng, o.rider_lat, o.rider_lng,
            o.rider_location_updated_at
     FROM orders o
      WHERE o.status IN ('Pending','Preparing','Ready','Out for Delivery')
          OR (o.status IN ('Completed','Cancelled') AND o.updated_at >= NOW() - INTERVAL 1 DAY)
     ORDER BY o.created_at DESC"
);

echo json_encode(['ok' => true, 'orders' => $stmt->fetchAll()]);
