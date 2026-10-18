<?php
require_once __DIR__ . '/../includes/functions.php';
require_rider();
header('Content-Type: application/json');

$stmt = $pdo->prepare(
    "SELECT order_id, status
     FROM orders
     WHERE rider_id = ? AND order_type = 'Delivery'
       AND (
           status IN ('Ready', 'Out for Delivery')
           OR (status IN ('Completed', 'Cancelled') AND updated_at >= NOW() - INTERVAL 1 DAY)
       )
     ORDER BY created_at DESC"
);
$stmt->execute([current_user()['user_id']]);

echo json_encode(['ok' => true, 'orders' => $stmt->fetchAll()]);