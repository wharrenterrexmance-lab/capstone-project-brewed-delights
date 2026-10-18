<?php
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json');

if (!is_rider()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not authorized.']);
    exit;
}

$submittedToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', (string) $submittedToken)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Security check failed.']);
    exit;
}

$lat = isset($_POST['lat']) ? (float) $_POST['lat'] : null;
$lng = isset($_POST['lng']) ? (float) $_POST['lng'] : null;

if ($lat === null || $lng === null || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid coordinates.']);
    exit;
}

$user = current_user();

// Update every order this rider currently has "Out for Delivery" — a rider
// realistically carries one active run at a time, but this covers multi-drop
// runs too without needing a separate live-location table.
$stmt = $pdo->prepare(
    "UPDATE orders
     SET rider_lat = ?, rider_lng = ?, rider_location_updated_at = NOW()
     WHERE rider_id = ? AND status = 'Out for Delivery'"
);
$stmt->execute([$lat, $lng, $user['user_id']]);

echo json_encode(['ok' => true, 'updated_orders' => $stmt->rowCount()]);
