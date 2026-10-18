<?php
/**
 * FUTURE WORK: see api/auth.php for context — this whole /api folder
 * is a working foundation for a future mobile app, not used by the
 * current web system.
 *
 * GET /api/menu.php
 * Public — no token required. Returns available menu items grouped
 * by category, same data the website's homepage shows.
 */
require_once __DIR__ . '/../includes/api_auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    api_fail(405, 'Use GET.');
}

$items = $pdo->query(
    'SELECT item_id, name, price, description, category
    FROM menu WHERE is_available = 1 AND category NOT IN ("Add-ons", "Syrups") ORDER BY category, name'
)->fetchAll();

$optionStmt = $pdo->query(
    'SELECT mo.option_id, mo.item_id, mo.label, mo.price
     FROM menu_options mo JOIN menu m ON m.item_id = mo.item_id
     WHERE m.is_available = 1 ORDER BY mo.option_id'
);
$optionsByItem = [];
foreach ($optionStmt->fetchAll() as $option) {
    $optionsByItem[$option['item_id']][] = $option;
}
foreach ($items as &$item) {
    $item['options'] = $optionsByItem[$item['item_id']] ?? [];
}
unset($item);

$extras = $pdo->query(
    'SELECT item_id, name, price, category
     FROM menu WHERE is_available = 1 AND category IN ("Add-ons", "Syrups") ORDER BY category, name'
)->fetchAll();

api_ok(['items' => $items, 'extras' => $extras]);
