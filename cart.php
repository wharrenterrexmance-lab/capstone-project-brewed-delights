<?php
require_once __DIR__ . '/includes/functions.php';
require_login();

$pageTitle = 'Your cart — Brewed Delights';

// Handle quantity updates / removals
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    $lineKey = (string) ($_POST['line_key'] ?? '');

    if ($action === 'update') {
        $qty = max(1, min(20, (int) ($_POST['qty'] ?? 1)));
        cart_set_qty($lineKey, $qty);
        if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
            header('Content-Type: application/json');
            echo json_encode(['ok' => true, 'cart_count' => cart_count()]);
            exit;
        }
    } elseif ($action === 'remove') {
        cart_remove($lineKey);
    } elseif ($action === 'clear') {
        cart_clear();
    }
    header('Location: cart.php');
    exit;
}

$cart = cart_get();
$lines = [];
$total = 0.0;

if ($cart) {
    $cartEntries = [];
    foreach ($cart as $lineKey => $qty) {
        [$itemId, $optionId, $addonString] = array_pad(explode(':', $lineKey, 3), 3, '');
        $cartEntries[] = ['key' => $lineKey, 'item_id' => (int) $itemId, 'option_id' => (int) $optionId,
            'addon_ids' => $addonString !== '' ? array_map('intval', explode(',', $addonString)) : [], 'qty' => $qty];
    }
    $ids = array_values(array_unique(array_column($cartEntries, 'item_id')));
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT * FROM menu WHERE is_available = 1 AND item_id IN ($placeholders)");
    $stmt->execute($ids);
    $menuById = [];
    foreach ($stmt->fetchAll() as $row) {
        $menuById[$row['item_id']] = $row;
    }

    $optionIds = array_values(array_unique(array_filter(array_column($cartEntries, 'option_id'))));
    $optionsById = [];
    if ($optionIds) {
        $optionPlaceholders = implode(',', array_fill(0, count($optionIds), '?'));
        $optionStmt = $pdo->prepare("SELECT option_id, item_id, label, price FROM menu_options WHERE option_id IN ($optionPlaceholders)");
        $optionStmt->execute($optionIds);
        foreach ($optionStmt->fetchAll() as $option) $optionsById[$option['option_id']] = $option;
    }
    $addonIds = array_values(array_unique(array_merge([], ...array_map(
        static fn(array $entry): array => $entry['addon_ids'],
        $cartEntries
    ))));
    $addonsById = [];
    if ($addonIds) {
        $addonPlaceholders = implode(',', array_fill(0, count($addonIds), '?'));
        $addonStmt = $pdo->prepare("SELECT item_id, name, price, category FROM menu WHERE is_available = 1 AND item_id IN ($addonPlaceholders)");
        $addonStmt->execute($addonIds);
        foreach ($addonStmt->fetchAll() as $addon) $addonsById[$addon['item_id']] = $addon;
    }

    foreach ($cartEntries as $entry) {
        $itemId = $entry['item_id'];
        $optionId = $entry['option_id'];
        if (!isset($menuById[$itemId]) || in_array($menuById[$itemId]['category'], ['Add-ons', 'Syrups'], true)) {
            cart_remove($entry['key']);
            continue;
        }
        $item = $menuById[$itemId];
        if ($optionId > 0 && (!isset($optionsById[$optionId]) || (int) $optionsById[$optionId]['item_id'] !== $itemId)) {
            cart_remove($entry['key']);
            continue;
        }
        if ($optionId === 0) {
            $optionCheck = $pdo->prepare('SELECT COUNT(*) FROM menu_options WHERE item_id = ?');
            $optionCheck->execute([$itemId]);
            if ((int) $optionCheck->fetchColumn() > 0) {
                cart_remove($entry['key']);
                continue;
            }
        }
        $validAddons = menu_supports_extras($item['category']);
        $addonTotal = 0.0;
        $addonNames = [];
        foreach ($entry['addon_ids'] as $addonId) {
            if (!isset($addonsById[$addonId]) || !in_array($addonsById[$addonId]['category'], ['Add-ons', 'Syrups'], true)) {
                $validAddons = false;
                break;
            }
            $addonTotal += (float) $addonsById[$addonId]['price'];
            $addonNames[] = $addonsById[$addonId]['name'];
        }
        if ($entry['addon_ids'] && !$validAddons) {
            cart_remove($entry['key']);
            continue;
        }
        $option = $optionId > 0 ? $optionsById[$optionId] : null;
        $unitPrice = ($option ? (float) $option['price'] : (float) $item['price']) + $addonTotal;
        $displayName = $item['name'] . ($option ? ' - ' . $option['label'] : '');
        if ($addonNames) $displayName .= ' + ' . implode(', ', $addonNames);
        $lineTotal = $unitPrice * $entry['qty'];
        $total += $lineTotal;
        $lines[] = ['item' => $item, 'option' => $option, 'display_name' => $displayName,
            'unit_price' => $unitPrice, 'line_key' => $entry['key'], 'qty' => $entry['qty'], 'line_total' => $lineTotal];
    }
}

// "Goes well with your order" — a couple of other available items not
// already in the cart. Reuses the same add-to-cart button/AJAX pattern
// used everywhere else; just a different spot to browse from.
$crossSell = [];
$crossSellOptionsByItem = [];
if ($lines) {
    $inCartIds = array_values(array_unique(array_column($cartEntries, 'item_id')));
    $placeholders2 = implode(',', array_fill(0, count($inCartIds), '?'));
    $beverageCategories = ['Signature Drinks', 'Classic Milk Tea', 'Choco Delights', 'Fruit Tea',
        'Hot Coffee', 'Cold Coffee', 'Hot Non-Coffee', 'Cold Non-Coffee', 'Frapped Coffee', 'Frapped Non-Coffee'];
    $beverageCategoryPlaceholders = implode(',', array_fill(0, count($beverageCategories), '?'));
    $beverageStmt = $pdo->prepare(
        "SELECT m.* FROM menu m WHERE m.is_available = 1 AND m.item_id NOT IN ($placeholders2)
         AND m.category IN ($beverageCategoryPlaceholders) ORDER BY RAND() LIMIT 1"
    );
    $beverageStmt->execute(array_merge($inCartIds, $beverageCategories));
    $crossSell = $beverageStmt->fetchAll();

    $excludedIds = $inCartIds;
    if ($crossSell) $excludedIds[] = (int) $crossSell[0]['item_id'];
    $excludedPlaceholders = implode(',', array_fill(0, count($excludedIds), '?'));
    $crossSellStmt = $pdo->prepare(
        "SELECT * FROM menu WHERE is_available = 1 AND item_id NOT IN ($excludedPlaceholders)
         AND category NOT IN ('Add-ons', 'Syrups')
         AND NOT EXISTS (SELECT 1 FROM menu_options mo WHERE mo.item_id = menu.item_id)
         ORDER BY RAND() LIMIT 2"
    );
    $crossSellStmt->execute($excludedIds);
    $crossSell = array_merge($crossSell, $crossSellStmt->fetchAll());

    $crossSellIds = array_column($crossSell, 'item_id');
    if ($crossSellIds) {
        $crossSellOptionPlaceholders = implode(',', array_fill(0, count($crossSellIds), '?'));
        $crossSellOptionStmt = $pdo->prepare(
            "SELECT option_id, item_id, label, price FROM menu_options
             WHERE item_id IN ($crossSellOptionPlaceholders) ORDER BY option_id"
        );
        $crossSellOptionStmt->execute($crossSellIds);
        foreach ($crossSellOptionStmt->fetchAll() as $option) {
            $crossSellOptionsByItem[$option['item_id']][] = $option;
        }
    }
}

require __DIR__ . '/includes/header.php';
?>

<section class="page-section">
    <?= render_breadcrumb([['label' => 'Menu', 'href' => 'index.php'], ['label' => 'Cart']]) ?>
    <?= render_checkout_stepper('cart') ?>
    <h1>Your cart</h1>

    <?php if (empty($lines)): ?>
        <p class="empty-state">Your cart is empty. <a href="index.php">Browse the menu</a>.</p>
    <?php else: ?>
        <div class="cart-table">
            <?php foreach ($lines as $line): ?>
                <form method="post" class="cart-row" data-cart-row>
                    <?= csrf_field() ?>
                    <input type="hidden" name="line_key" value="<?= e($line['line_key']) ?>">
                    <input type="hidden" name="action" value="update">
                    <div class="cart-row-name">
                        <strong><?= e($line['display_name']) ?></strong>
                        <span><?= peso($line['unit_price']) ?> each</span>
                    </div>
                    <input type="number" name="qty" min="1" max="20" value="<?= (int) $line['qty'] ?>" class="qty-input" data-cart-qty aria-label="Quantity">
                    <span class="cart-row-total" data-cart-line-total data-unit-price="<?= e((string) $line['unit_price']) ?>" data-line-total="<?= e((string) $line['line_total']) ?>"><?= peso($line['line_total']) ?></span>
                    <span class="cart-update-status" data-cart-update-status aria-live="polite"></span>
                    <button type="submit" name="action" value="remove" class="btn btn-remove" aria-label="Remove">&times;</button>
                </form>
            <?php endforeach; ?>
        </div>

        <div class="cart-summary">
            <span>Total</span>
            <strong data-cart-grand-total><?= peso($total) ?></strong>
        </div>

        <div class="cart-actions">
            <form method="post"><?= csrf_field() ?><button type="submit" name="action" value="clear" class="btn btn-ghost">Clear cart</button></form>
            <a href="checkout.php" class="btn btn-primary btn-lg">Proceed to checkout</a>
        </div>

        <?php if ($crossSell): ?>
            <div class="crosssell-strip">
                <h3>Goes well with your order</h3>
                <div class="crosssell-row">
                    <?php foreach ($crossSell as $csItem): ?>
                        <div class="crosssell-item" data-item-id="<?= (int) $csItem['item_id'] ?>">
                            <div class="thumb">
                                <?php $crossSellImagePath = menu_item_image_path($csItem); ?>
                                <?php if ($crossSellImagePath): ?>
                                    <img src="<?= e($crossSellImagePath) ?>" alt="<?= e($csItem['name']) ?>" loading="lazy">
                                <?php else: ?>
                                    <span class="menu-card-photo-fallback"><?= e(mb_substr($csItem['name'], 0, 1)) ?></span>
                                <?php endif; ?>
                            </div>
                            <strong><?= e($csItem['name']) ?></strong><br>
                            <span class="price" data-item-price data-base-price="<?= e((string) $csItem['price']) ?>">
                                <?= !empty($crossSellOptionsByItem[$csItem['item_id']]) ? 'From ' : '' ?><?= peso((float) $csItem['price']) ?>
                            </span>
                            <?php if (!empty($crossSellOptionsByItem[$csItem['item_id']])): ?>
                                <label class="crosssell-option-label">Choose an option
                                    <select data-menu-option>
                                        <?php foreach (group_menu_options($crossSellOptionsByItem[$csItem['item_id']]) as $optionGroup => $groupOptions): ?>
                                            <optgroup label="<?= e($optionGroup) ?>">
                                                <?php foreach ($groupOptions as $option): ?>
                                                    <option value="<?= (int) $option['option_id'] ?>" data-price="<?= e((string) $option['price']) ?>">
                                                        <?= e($option['display_label']) ?> · <?= peso((float) $option['price']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </optgroup>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                            <?php endif; ?>
                            <button class="btn btn-add btn-sm" data-add-to-cart="<?= (int) $csItem['item_id'] ?>">Add</button>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</section>

<div class="toast" id="toast" role="status" aria-live="polite"></div>

<?php require __DIR__ . '/includes/footer.php'; ?>
