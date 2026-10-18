<?php
require_once __DIR__ . '/includes/functions.php';
require_login();

$pageTitle = 'Checkout — Brewed Delights';
$user = current_user();
$savedAddressStmt = $pdo->prepare('SELECT address, use_address_for_delivery FROM users WHERE user_id = ?');
$savedAddressStmt->execute([$user['user_id']]);
$savedAddressProfile = $savedAddressStmt->fetch();
$savedAddress = !empty($savedAddressProfile['use_address_for_delivery'])
    ? (string) ($savedAddressProfile['address'] ?? '')
    : '';

$cart = cart_get();
if (empty($cart)) {
    header('Location: cart.php');
    exit;
}

$cartEntries = [];
foreach ($cart as $lineKey => $qty) {
    [$itemId, $optionId, $addonString] = array_pad(explode(':', $lineKey, 3), 3, '');
    $cartEntries[] = ['key' => $lineKey, 'item_id' => (int) $itemId, 'option_id' => (int) $optionId,
        'addon_ids' => $addonString !== '' ? array_map('intval', explode(',', $addonString)) : [], 'qty' => $qty];
}
$ids = array_values(array_unique(array_column($cartEntries, 'item_id')));
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$stmt = $pdo->prepare("SELECT * FROM menu WHERE item_id IN ($placeholders) AND is_available = 1");
$stmt->execute($ids);
$menuById = [];
foreach ($stmt->fetchAll() as $row) {
    $menuById[$row['item_id']] = $row;
}

$lines = [];
$total = 0.0;
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
    if (!isset($menuById[$itemId])) continue;
    $item = $menuById[$itemId];
    if (in_array($item['category'], ['Add-ons', 'Syrups'], true)) continue;
    if ($optionId > 0 && (!isset($optionsById[$optionId]) || (int) $optionsById[$optionId]['item_id'] !== $itemId)) continue;
    if ($optionId === 0) {
        $optionCheck = $pdo->prepare('SELECT COUNT(*) FROM menu_options WHERE item_id = ?');
        $optionCheck->execute([$itemId]);
        if ((int) $optionCheck->fetchColumn() > 0) continue;
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
    if ($entry['addon_ids'] && !$validAddons) continue;
    $option = $optionId > 0 ? $optionsById[$optionId] : null;
    $unitPrice = ($option ? (float) $option['price'] : (float) $item['price']) + $addonTotal;
    $displayName = $item['name'] . ($option ? ' - ' . $option['label'] : '');
    if ($addonNames) $displayName .= ' + ' . implode(', ', $addonNames);
    $lineTotal = $unitPrice * $entry['qty'];
    $total += $lineTotal;
    $lines[] = ['item' => $item, 'option' => $option, 'display_name' => $displayName,
        'unit_price' => $unitPrice, 'qty' => $entry['qty'], 'line_total' => $lineTotal];
}
if (!$lines) {
    cart_clear();
    header('Location: cart.php');
    exit;
}

const DELIVERY_FEE = 49.00;
$gcash = gcash_settings();

$pointsStmt = $pdo->prepare('SELECT points_balance FROM users WHERE user_id = ?');
$pointsStmt->execute([$user['user_id']]);
$pointsBalance = (int) $pointsStmt->fetchColumn();

$error = null;
$placedOrderId = null;

$orderType = ($_POST['order_type'] ?? 'Pickup') === 'Delivery' ? 'Delivery' : 'Pickup';
$paymentMethod = ($_POST['payment_method'] ?? 'Cash') === 'GCash' ? 'GCash' : 'Cash';
$deliveryFee = $orderType === 'Delivery' ? DELIVERY_FEE : 0.0;
$preDiscountTotal = $total + $deliveryFee;

$maxRedeemable = min($pointsBalance, (int) floor($preDiscountTotal));
$pointsToRedeem = max(0, min($maxRedeemable, (int) ($_POST['points_to_redeem'] ?? 0)));
$loyaltyDiscount = $pointsToRedeem * LOYALTY_PESO_PER_POINT;
$grandTotal = max(0, $preDiscountTotal - $loyaltyDiscount);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $deliveryAddress = trim($_POST['delivery_address'] ?? '');
    $deliveryLatValue = trim((string) ($_POST['delivery_lat'] ?? ''));
    $deliveryLngValue = trim((string) ($_POST['delivery_lng'] ?? ''));
    $deliveryLat = $deliveryLatValue !== '' && is_numeric($deliveryLatValue) ? (float) $deliveryLatValue : null;
    $deliveryLng = $deliveryLngValue !== '' && is_numeric($deliveryLngValue) ? (float) $deliveryLngValue : null;
    $referenceNumber = trim($_POST['reference_number'] ?? '');

    if ($orderType === 'Delivery' && $deliveryAddress === '') {
        $error = 'Enter a delivery address, or switch to Pickup.';
    } elseif (($deliveryLatValue !== '' || $deliveryLngValue !== '')
        && ($deliveryLat === null || $deliveryLng === null
            || $deliveryLat < -90 || $deliveryLat > 90
            || $deliveryLng < -180 || $deliveryLng > 180)) {
        $error = 'The delivery location pin is invalid. Clear the pin or choose your location again.';
    } elseif ($paymentMethod === 'GCash' && !$gcash['available']) {
        $error = 'GCash payment is temporarily unavailable. Please choose Cash or contact the shop.';
    } elseif ($paymentMethod === 'GCash' && strlen($referenceNumber) < 6) {
        $error = 'Enter the GCash reference number from your payment confirmation (at least 6 characters).';
    } elseif ($pointsToRedeem > $maxRedeemable) {
        $error = 'You tried to redeem more points than available.';
    } else {
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
                $user['user_id'],
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

            $referenceCode = 'BD-' . date('ymd') . '-' . str_pad((string) $orderId, 5, '0', STR_PAD_LEFT);
            $pdo->prepare('UPDATE orders SET reference_code = ? WHERE order_id = ?')
                ->execute([$referenceCode, $orderId]);

            $itemStmt = $pdo->prepare(
                'INSERT INTO order_items (order_id, item_id, item_name, unit_price, quantity, line_total)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            foreach ($lines as $line) {
                $itemStmt->execute([
                    $orderId,
                    $line['item']['item_id'],
                    $line['display_name'],
                    $line['unit_price'],
                    $line['qty'],
                    $line['line_total'],
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
                redeem_loyalty_points($pdo, $user['user_id'], $orderId, $pointsToRedeem);
            }

            log_order_status($pdo, $orderId, 'Pending', null);

            $pdo->commit();
            cart_clear();
            $placedOrderId = $orderId;
            send_order_email($pdo, $orderId, 'placed');
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Checkout failed: ' . $ex->getMessage());
            $error = 'Something went wrong placing your order. Please try again.';
        }
    }
}

require __DIR__ . '/includes/header.php';
?>

<section class="page-section">
    <?= render_breadcrumb([['label' => 'Menu', 'href' => 'index.php'], ['label' => 'Cart', 'href' => 'cart.php'], ['label' => 'Checkout']]) ?>
    <?= render_checkout_stepper($placedOrderId ? 'confirmed' : 'checkout') ?>
    <h1>Checkout</h1>

    <?php if ($placedOrderId): ?>
        <div class="order-success">
            <svg class="success-check" viewBox="0 0 60 60" aria-hidden="true">
                <circle class="success-check-circle" cx="30" cy="30" r="26" />
                <path class="success-check-mark" d="M18 31 26 39 43 20" />
            </svg>
            <span class="confetti-dot" style="--i:0"></span><span class="confetti-dot" style="--i:1"></span>
            <span class="confetti-dot" style="--i:2"></span><span class="confetti-dot" style="--i:3"></span>
            <span class="confetti-dot" style="--i:4"></span><span class="confetti-dot" style="--i:5"></span>
        </div>
        <div class="alert alert-success">
            <p>Order <strong>#<?= $placedOrderId ?></strong> placed! Reference number:
            <strong><?= e($referenceCode) ?></strong> — keep this for your records.
            <?= $paymentMethod === 'GCash'
                ? 'We\'ll verify your GCash payment shortly.'
                : 'Please have the exact amount ready — you\'ll pay in cash.' ?></p>
            <a href="orders.php" class="btn btn-primary">Track your order</a>
        </div>
    <?php else: ?>
        <?php if ($error): ?><p class="alert alert-error"><?= e($error) ?></p><?php endif; ?>

        <div class="checkout-grid">
            <div class="checkout-summary sticky-summary">
                <h2>Order summary</h2>
                <?php foreach ($lines as $line): ?>
                    <div class="summary-line">
                        <span><?= (int) $line['qty'] ?> &times; <?= e($line['display_name']) ?></span>
                        <span><?= peso($line['line_total']) ?></span>
                    </div>
                <?php endforeach; ?>
                <div class="summary-line" id="deliveryFeeLine" style="<?= $orderType === 'Delivery' ? '' : 'display:none' ?>">
                    <span>Delivery fee</span>
                    <span><?= peso(DELIVERY_FEE) ?></span>
                </div>
                <div class="summary-line" id="loyaltyLine" style="<?= $pointsToRedeem > 0 ? '' : 'display:none' ?>">
                    <span>Loyalty points applied</span>
                    <span id="loyaltyDiscountLabel">&minus;<?= peso($loyaltyDiscount) ?></span>
                </div>
                <div class="summary-line summary-total">
                    <span>Total</span>
                    <span id="grandTotalLabel"><?= peso($grandTotal) ?></span>
                </div>
            </div>

            <form method="post" class="checkout-form" id="checkoutForm">
                <?= csrf_field() ?>
                <h2>Fulfillment</h2>
                <fieldset class="order-type-fieldset">
                    <label class="radio-label">
                        <input type="radio" name="order_type" value="Pickup" <?= $orderType === 'Pickup' ? 'checked' : '' ?>>
                        Pickup at the shop
                    </label>
                    <label class="radio-label">
                        <input type="radio" name="order_type" value="Delivery" <?= $orderType === 'Delivery' ? 'checked' : '' ?>>
                        Personal delivery (+<?= peso(DELIVERY_FEE) ?>)
                    </label>
                </fieldset>

                <div id="deliveryFields" style="<?= $orderType === 'Delivery' ? '' : 'display:none' ?>">
                    <label>Delivery address
                        <textarea name="delivery_address" rows="2" placeholder="House/unit no., street, barangay, landmark"><?= e($_POST['delivery_address'] ?? $savedAddress) ?></textarea>
                    </label>
                    <button type="button" id="pinLocationBtn" class="btn btn-ghost btn-sm"><?= icon_pin() ?> Use my current location as the drop-off pin</button>
                    <p class="form-note" id="pinStatus">This lets your rider find you precisely — optional, address alone still works.</p>
                    <input type="hidden" name="delivery_lat" id="deliveryLat" value="<?= e($_POST['delivery_lat'] ?? '') ?>">
                    <input type="hidden" name="delivery_lng" id="deliveryLng" value="<?= e($_POST['delivery_lng'] ?? '') ?>">
                </div>

                <?php if ($pointsBalance > 0): ?>
                    <h2>Loyalty points</h2>
                    <p class="form-note">You have <strong><?= $pointsBalance ?> points</strong>
                    (1 point = <?= peso(1) ?> off). Up to <?= $maxRedeemable ?> can be applied to this order.</p>
                    <label>Points to redeem
                        <input type="number" name="points_to_redeem" id="pointsToRedeem" min="0" max="<?= $maxRedeemable ?>"
                               value="<?= $pointsToRedeem ?>">
                    </label>
                    <p class="form-note" id="pointsRemaining" aria-live="polite">
                        Using <strong id="pointsUsedPreview"><?= $pointsToRedeem ?></strong> points.
                        Balance after placing this order: <strong id="pointsRemainingPreview"><?= max(0, $pointsBalance - $pointsToRedeem) ?></strong> points.
                    </p>
                <?php endif; ?>

                <h2>Payment</h2>
                <div class="trust-badges">
                    <span class="trust-badge"><?= icon_shield() ?> Secure checkout</span>
                    <?php if ($gcash['available']): ?><span class="trust-badge">GCash accepted</span><?php endif; ?>
                    <span class="trust-badge">Cash accepted</span>
                </div>
                <fieldset class="order-type-fieldset">
                    <label class="radio-label">
                        <input type="radio" name="payment_method" value="Cash" <?= $paymentMethod === 'Cash' ? 'checked' : '' ?>>
                        Cash <span id="cashHint" class="muted"></span>
                    </label>
                    <?php if ($gcash['available']): ?>
                        <label class="radio-label">
                            <input type="radio" name="payment_method" value="GCash" <?= $paymentMethod === 'GCash' ? 'checked' : '' ?>>
                            GCash
                        </label>
                    <?php else: ?>
                        <p class="form-note">GCash is unavailable because the shop has not configured a verified GCash number and account name. Choose Cash or contact the shop.</p>
                    <?php endif; ?>
                </fieldset>

                <?php if ($gcash['available']): ?>
                    <div id="gcashFields" style="<?= $paymentMethod === 'GCash' ? '' : 'display:none' ?>">
                        <div class="delivery-info">
                            <span>Send payment to GCash <strong><?= e($gcash['number']) ?></strong> (<?= e($gcash['name']) ?>)</span>
                            <span>Then enter the reference number from your GCash receipt below.</span>
                        </div>
                        <label>GCash reference number
                            <input type="text" name="reference_number" id="referenceNumber"
                                   placeholder="e.g. 1234567890123" value="<?= e($_POST['reference_number'] ?? '') ?>">
                        </label>
                        <p class="form-note">Your order will show as "Pending Verification" until staff confirms this
                        reference number against the shop's GCash account.</p>
                    </div>
                <?php endif; ?>

                <button type="submit" class="btn btn-primary btn-lg" id="payBtn">Place order — <?= peso($grandTotal) ?></button>
            </form>
        </div>

        <script>
        (function () {
            var deliveryFee = <?= json_encode(DELIVERY_FEE) ?>;
            var itemsTotal = <?= json_encode($total) ?>;
            var pointsBalance = <?= json_encode($pointsBalance) ?>;
            var loyaltyRate = <?= json_encode(LOYALTY_PESO_PER_POINT) ?>;

            var radios = document.querySelectorAll('input[name="order_type"]');
            var payRadios = document.querySelectorAll('input[name="payment_method"]');
            var deliveryFields = document.getElementById('deliveryFields');
            var deliveryFeeLine = document.getElementById('deliveryFeeLine');
            var gcashFields = document.getElementById('gcashFields');
            var cashHint = document.getElementById('cashHint');
            var loyaltyLine = document.getElementById('loyaltyLine');
            var loyaltyDiscountLabel = document.getElementById('loyaltyDiscountLabel');
            var grandTotalLabel = document.getElementById('grandTotalLabel');
            var payBtn = document.getElementById('payBtn');
            var pointsInput = document.getElementById('pointsToRedeem');
            var pointsUsedPreview = document.getElementById('pointsUsedPreview');
            var pointsRemainingPreview = document.getElementById('pointsRemainingPreview');
            var peso = function (n) { return '₱' + n.toFixed(2); };

            function isDelivery() {
                return document.querySelector('input[name="order_type"]:checked').value === 'Delivery';
            }

            function refresh() {
                var delivery = isDelivery();
                deliveryFields.style.display = delivery ? '' : 'none';
                deliveryFeeLine.style.display = delivery ? '' : 'none';
                if (cashHint) {
                    cashHint.textContent = delivery ? '(pay the rider on delivery)' : '(pay at the counter on pickup)';
                }

                var preDiscount = itemsTotal + (delivery ? deliveryFee : 0);
                var maxRedeemable = Math.min(pointsBalance, Math.floor(preDiscount));
                if (pointsInput) {
                    pointsInput.max = maxRedeemable;
                    if (parseInt(pointsInput.value || '0', 10) > maxRedeemable) {
                        pointsInput.value = maxRedeemable;
                    }
                }
                var pointsToRedeem = pointsInput ? (parseInt(pointsInput.value || '0', 10) || 0) : 0;
                if (pointsInput) {
                    pointsToRedeem = Math.max(0, Math.min(pointsBalance, maxRedeemable, pointsToRedeem));
                    pointsInput.value = pointsToRedeem;
                }
                var remaining = pointsBalance - pointsToRedeem;
                if (pointsUsedPreview) pointsUsedPreview.textContent = pointsToRedeem;
                if (pointsRemainingPreview) pointsRemainingPreview.textContent = remaining;
                var discount = pointsToRedeem * loyaltyRate;
                loyaltyLine.style.display = discount > 0 ? '' : 'none';
                loyaltyDiscountLabel.textContent = '\u2212' + peso(discount);

                var total = Math.max(0, preDiscount - discount);
                grandTotalLabel.textContent = peso(total);
                payBtn.textContent = 'Place order — ' + peso(total);
            }
            radios.forEach(function (r) { r.addEventListener('change', refresh); });
            if (pointsInput) pointsInput.addEventListener('input', refresh);

            payRadios.forEach(function (r) {
                r.addEventListener('change', function () {
                    if (gcashFields) {
                        gcashFields.style.display = document.querySelector('input[name="payment_method"]:checked').value === 'GCash' ? '' : 'none';
                    }
                });
            });

            var pinBtn = document.getElementById('pinLocationBtn');
            var pinStatus = document.getElementById('pinStatus');
            if (pinBtn) {
                pinBtn.addEventListener('click', function () {
                    if (!window.isSecureContext) {
                        pinStatus.textContent = 'Location pins require a secure (HTTPS) connection. Enter your delivery address manually for now.';
                        return;
                    }
                    if (!navigator.geolocation) {
                        pinStatus.textContent = 'Your browser doesn\'t support location — just fill in the address.';
                        return;
                    }
                    pinStatus.textContent = 'Getting your location…';
                    navigator.geolocation.getCurrentPosition(function (pos) {
                        document.getElementById('deliveryLat').value = pos.coords.latitude;
                        document.getElementById('deliveryLng').value = pos.coords.longitude;
                        pinStatus.textContent = 'Location pin captured — your rider will see exactly where to go.';
                    }, function () {
                        pinStatus.textContent = 'Could not get your location — the written address will be used instead.';
                    }, { enableHighAccuracy: true, timeout: 8000 });
                });
            }

            refresh();
        })();
        </script>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
