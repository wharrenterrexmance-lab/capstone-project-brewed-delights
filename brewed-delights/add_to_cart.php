<?php
require_once __DIR__ . '/includes/functions.php';
header('Content-Type: application/json');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Please log in first.']);
    exit;
}

$submittedToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', (string) $submittedToken)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Security check failed — please refresh the page.']);
    exit;
}

$itemId = (int) ($_POST['item_id'] ?? 0);
$variantId = (int) ($_POST['variant_id'] ?? 0);
$addonIds = is_array($_POST['addon_ids'] ?? null)
    ? array_values(array_unique(array_filter(array_map('intval', $_POST['addon_ids']), static fn(int $id): bool => $id > 0)))
    : [];
$qty    = max(1, (int) ($_POST['qty'] ?? 1));

$stmt = $pdo->prepare('SELECT item_id, name, category FROM menu WHERE item_id = ? AND is_available = 1');
$stmt->execute([$itemId]);
$item = $stmt->fetch();

if (!$item) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Item not found.']);
    exit;
}
if (in_array($item['category'], ['Add-ons', 'Syrups'], true)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Choose extras while ordering a drink.']);
    exit;
}

$optionLabel = '';
if ($variantId > 0) {
    $optionStmt = $pdo->prepare('SELECT label FROM menu_options WHERE option_id = ? AND item_id = ?');
    $optionStmt->execute([$variantId, $itemId]);
    $optionLabel = (string) $optionStmt->fetchColumn();
    if ($optionLabel === '') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Please choose a valid size or option.']);
        exit;
    }
} else {
    $optionStmt = $pdo->prepare('SELECT COUNT(*) FROM menu_options WHERE item_id = ?');
    $optionStmt->execute([$itemId]);
    if ((int) $optionStmt->fetchColumn() > 0) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Please choose a size or option.']);
        exit;
    }
}

$addonNames = [];
if ($addonIds) {
    if (!menu_supports_extras($item['category'])) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Extras can only be added to drinks.']);
        exit;
    }
    $addonPlaceholders = implode(',', array_fill(0, count($addonIds), '?'));
    $addonStmt = $pdo->prepare(
        "SELECT item_id, name, category FROM menu
         WHERE is_available = 1 AND item_id IN ($addonPlaceholders)"
    );
    $addonStmt->execute($addonIds);
    $addons = $addonStmt->fetchAll();
    if (count($addons) !== count($addonIds)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'One or more extras are unavailable.']);
        exit;
    }
    foreach ($addons as $addon) {
        if (!in_array($addon['category'], ['Add-ons', 'Syrups'], true)) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Please select valid drink extras.']);
            exit;
        }
        $addonNames[] = $addon['name'];
    }
}

cart_add($itemId, $variantId, $qty, $addonIds);
$displayName = $item['name'] . ($optionLabel !== '' ? ' - ' . $optionLabel : '');
if ($addonNames) $displayName .= ' + ' . implode(', ', $addonNames);

echo json_encode([
    'ok'         => true,
    'item_name'  => $displayName,
    'cart_count' => cart_count(),
]);
