<?php
// app.php must load BEFORE session_start() — it sets secure cookie
// params (httponly/samesite) that only take effect if applied before
// the session actually starts.
require_once __DIR__ . '/../config/app.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/db.php';
$composerAutoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($composerAutoload)) require_once $composerAutoload;

/* ---------------------------------------------------------- *
 * Auth helpers
 * ---------------------------------------------------------- */

function current_user(): ?array {
    return $_SESSION['user'] ?? null;
}

function users_have_username(PDO $pdo): bool {
    static $available = null;
    if ($available === null) {
        $available = (bool) $pdo->query("SHOW COLUMNS FROM users LIKE 'username'")->fetch();
    }
    return $available;
}

function users_have_email_verified(PDO $pdo): bool {
    static $available = null;
    if ($available === null) {
        $available = (bool) $pdo->query("SHOW COLUMNS FROM users LIKE 'email_verified'")->fetch();
    }
    return $available;
}

function integration_settings(): array {
    static $settings = null;
    if ($settings === null) $settings = require __DIR__ . '/../config/services.php';
    return $settings;
}

function gcash_settings(): array {
    $settings = integration_settings();
    $number = trim((string) ($settings['gcash_number'] ?? ''));
    $name = trim((string) ($settings['gcash_name'] ?? ''));
    $digits = (string) preg_replace('/\D+/', '', $number);

    return [
        'available' => $name !== ''
            && preg_match('/^[+0-9][0-9 ()-]{8,20}$/', $number) === 1
            && strlen($digits) >= 10
            && strlen($digits) <= 15
            && $digits !== '09170000000',
        'number' => $number,
        'name' => $name,
    ];
}

function establish_user_session(array $user): string {
    session_regenerate_id(true);
    $_SESSION['user'] = [
        'user_id' => (int) $user['user_id'],
        'name' => $user['name'],
        'email' => $user['email'],
        'role' => $user['role'],
    ];

    return match ($user['role']) {
        'admin' => 'admin/index.php',
        'staff' => 'admin/orders.php',
        'rider' => 'rider/index.php',
        default => 'index.php',
    };
}

function send_order_email(PDO $pdo, int $orderId, string $event): bool {
    try {
    $settings = integration_settings();
    if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)
        || !$settings['smtp_host'] || !$settings['smtp_username']
        || !$settings['smtp_password'] || !$settings['mail_from']) {
        return false;
    }

    $orderStmt = $pdo->prepare(
        'SELECT o.*, u.name AS customer_name, u.email AS customer_email, u.email_verified
         FROM orders o JOIN users u ON u.user_id = o.user_id WHERE o.order_id = ?'
    );
    $orderStmt->execute([$orderId]);
    $order = $orderStmt->fetch();
    if (!$order || !(int) $order['email_verified'] || !filter_var($order['customer_email'], FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $itemsStmt = $pdo->prepare('SELECT item_name, quantity, unit_price, line_total FROM order_items WHERE order_id = ?');
    $itemsStmt->execute([$orderId]);
    $items = $itemsStmt->fetchAll();

    $eventTitle = match ($event) {
        'placed' => 'Order confirmation',
        'paid' => 'Payment confirmed receipt',
        'payment_failed' => 'Payment needs attention',
        default => str_starts_with($event, 'status:') ? 'Order update: ' . substr($event, 7) : 'Order update',
    };
    $subject = $eventTitle . ' - ' . ($order['reference_code'] ?: 'Order #' . $orderId);
    $htmlItems = '';
    $textItems = [];
    foreach ($items as $item) {
        $safeName = e($item['item_name']);
        $quantity = (int) $item['quantity'];
        $linePrice = peso((float) $item['line_total']);
        $htmlItems .= '<tr><td style="padding:8px;border-bottom:1px solid #ddd">' . $safeName
            . ' x ' . $quantity . '</td><td style="padding:8px;border-bottom:1px solid #ddd;text-align:right">'
            . $linePrice . '</td></tr>';
        $textItems[] = $item['item_name'] . ' x ' . $quantity . ' - ' . $linePrice;
    }

    $deliveryLine = $order['order_type'] === 'Delivery'
        ? '<p>Delivery address: ' . e($order['delivery_address']) . '</p>'
        : '<p>Pickup at the shop</p>';
    $html = '<!doctype html><html><body style="font-family:Arial,sans-serif;color:#30271f">'
        . '<h1>' . e($eventTitle) . '</h1><p>Hello ' . e($order['customer_name']) . ',</p>'
        . '<p>Reference: <strong>' . e($order['reference_code'] ?: 'Order #' . $orderId) . '</strong></p>'
        . '<p>Order status: ' . e($order['status']) . ' | Payment: ' . e($order['payment_status'])
        . ' (' . e($order['payment_method']) . ')</p>' . $deliveryLine
        . '<table style="border-collapse:collapse;width:100%">' . $htmlItems . '</table>'
        . '<p>Delivery fee: ' . peso((float) $order['delivery_fee']) . '</p>'
        . '<p>Loyalty discount: ' . peso((float) $order['loyalty_discount']) . '</p>'
        . '<p><strong>Total: ' . peso((float) $order['total_amount']) . '</strong></p>'
        . '<p>Thank you for ordering from Brewed Delights.</p></body></html>';
    $plain = $eventTitle . "\nReference: " . ($order['reference_code'] ?: 'Order #' . $orderId)
        . "\nStatus: " . $order['status'] . "\nPayment: " . $order['payment_status'] . ' (' . $order['payment_method'] . ')'
        . "\n" . implode("\n", $textItems) . "\nDelivery fee: " . peso((float) $order['delivery_fee'])
        . "\nLoyalty discount: " . peso((float) $order['loyalty_discount'])
        . "\nTotal: " . peso((float) $order['total_amount']);

        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $settings['smtp_host'];
        $mail->SMTPAuth = true;
        $mail->Username = $settings['smtp_username'];
        $mail->Password = $settings['smtp_password'];
        $mail->Port = (int) $settings['smtp_port'];
        $mail->SMTPSecure = $settings['smtp_encryption'];
        $mail->CharSet = 'UTF-8';
        $mail->setFrom($settings['mail_from'], $settings['mail_from_name']);
        $mail->addAddress($order['customer_email'], $order['customer_name']);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $html;
        $mail->AltBody = $plain;
        $mail->send();
        return true;
    } catch (Throwable $ex) {
        error_log('Order email failed for #' . $orderId . ': ' . $ex->getMessage());
        return false;
    }
}

function is_logged_in(): bool {
    return isset($_SESSION['user']);
}

function is_admin(): bool {
    return is_logged_in() && $_SESSION['user']['role'] === 'admin';
}

/** Redirect to login if not authenticated. Call at the top of protected pages. */
function require_login(): void {
    if (!is_logged_in()) {
        header('Location: login.php');
        exit;
    }
}

/** Redirect away if not an admin. Call at the top of every admin/*.php page. */
function require_admin(): void {
    if (!is_admin()) {
        header('Location: ../login.php');
        exit;
    }
}

function is_rider(): bool {
    return is_logged_in() && $_SESSION['user']['role'] === 'rider';
}

/** Redirect away if not a rider. Call at the top of every rider/*.php page. */
function require_rider(): void {
    if (!is_rider()) {
        header('Location: ../login.php');
        exit;
    }
}

function is_staff(): bool {
    return is_logged_in() && $_SESSION['user']['role'] === 'staff';
}

/**
 * Staff handle day-to-day order processing, payment verification, and
 * viewing feedback/loyalty — per the capstone's Staff Module use case.
 * Admin can do everything staff can, plus account/system management.
 */
function is_staff_or_admin(): bool {
    return is_staff() || is_admin();
}

/** Redirect away unless staff or admin. For pages both roles share (orders, feedback). */
function require_staff_or_admin(): void {
    if (!is_staff_or_admin()) {
        header('Location: ../login.php');
        exit;
    }
}

function e(?string $value): string {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function peso(float $amount): string {
    return '₱' . number_format($amount, 2);
}

/* ---------------------------------------------------------- *
 * CSRF protection
 * Every state-changing form must include csrf_field() and every
 * handler for a POST request must call verify_csrf() before doing
 * anything. Without this, a malicious site could trick a logged-in
 * user's browser into submitting hidden requests to this app.
 * ---------------------------------------------------------- */

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Echo this inside every <form method="post">. */
function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/** Call at the top of every POST handler, before touching the database. */
function verify_csrf(): void {
    $submitted = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', (string) $submitted)) {
        http_response_code(403);
        die('Security check failed — your form session may have expired. Please go back, refresh, and try again.');
    }
}

/* ---------------------------------------------------------- *
 * Login rate limiting
 * Tracked per email+IP pair in the login_attempts table so a bot
 * can't just clear cookies to reset its attempt count. Locks out
 * for 15 minutes after 5 failed attempts within that window.
 * ---------------------------------------------------------- */

const LOGIN_MAX_ATTEMPTS  = 5;
const LOGIN_LOCKOUT_MINS  = 15;

function client_ip(): string {
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/** Returns minutes remaining until unlock, or 0 if not locked. */
function login_lockout_remaining(PDO $pdo, string $email): int {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) AS attempts, MAX(attempted_at) AS last_attempt
         FROM login_attempts
         WHERE email = ? AND ip_address = ? AND attempted_at > (NOW() - INTERVAL ? MINUTE)'
    );
    $stmt->execute([$email, client_ip(), LOGIN_LOCKOUT_MINS]);
    $row = $stmt->fetch();

    if (!$row || (int) $row['attempts'] < LOGIN_MAX_ATTEMPTS) {
        return 0;
    }

    $elapsedMins = (time() - strtotime($row['last_attempt'])) / 60;
    $remaining = LOGIN_LOCKOUT_MINS - $elapsedMins;
    return $remaining > 0 ? (int) ceil($remaining) : 0;
}

function record_failed_login(PDO $pdo, string $email): void {
    $pdo->prepare('INSERT INTO login_attempts (email, ip_address) VALUES (?, ?)')
        ->execute([$email, client_ip()]);
}

function clear_login_attempts(PDO $pdo, string $email): void {
    $pdo->prepare('DELETE FROM login_attempts WHERE email = ? AND ip_address = ?')
        ->execute([$email, client_ip()]);
}

/* ---------------------------------------------------------- *
 * Cart helpers (session-based cart: item_id:variant_id => quantity)
 * ---------------------------------------------------------- */

function cart_line_key(int $itemId, int $variantId = 0, array $addonIds = []): string {
    $addonIds = array_values(array_unique(array_map('intval', $addonIds)));
    sort($addonIds);
    $key = $itemId . ':' . $variantId;
    return $addonIds ? $key . ':' . implode(',', $addonIds) : $key;
}

function menu_supports_extras(string $category): bool {
    return in_array($category, [
        'Signature Drinks', 'Classic Milk Tea', 'Choco Delights', 'Fruit Tea',
        'Hot Coffee', 'Cold Coffee', 'Hot Non-Coffee', 'Cold Non-Coffee',
        'Frapped Coffee', 'Frapped Non-Coffee',
    ], true);
}

function menu_item_image_path(array $item): ?string {
    $storedPath = trim((string) ($item['image_path'] ?? ''));
    if ($storedPath !== '' && is_file(__DIR__ . '/../' . $storedPath)) return $storedPath;

    static $imagesByName = null;
    if ($imagesByName === null) {
        $imagesByName = [];
        $photoDirectory = __DIR__ . '/../Products Images';
        $photoFiles = is_dir($photoDirectory) ? @scandir($photoDirectory) : false;
        if ($photoFiles !== false) {
            foreach ($photoFiles as $photoFile) {
                $extension = strtolower(pathinfo($photoFile, PATHINFO_EXTENSION));
                if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) continue;
                $normalizedName = strtolower((string) preg_replace('/[^a-z0-9]/i', '', pathinfo($photoFile, PATHINFO_FILENAME)));
                if ($normalizedName !== '') $imagesByName[$normalizedName] ??= 'Products Images/' . $photoFile;
            }
        }
    }

    $itemName = $item['name'] ?? $item['item_name'] ?? '';
    $normalizedItemName = strtolower((string) preg_replace('/[^a-z0-9]/i', '', (string) $itemName));
    if (isset($imagesByName[$normalizedItemName])) return $imagesByName[$normalizedItemName];

    $bestMatch = null;
    $bestMatchLength = 0;
    foreach ($imagesByName as $normalizedImageName => $imagePath) {
        $imageNameLength = strlen($normalizedImageName);
        if ($imageNameLength > $bestMatchLength && $imageNameLength >= 6
            && strncmp($normalizedItemName, $normalizedImageName, $imageNameLength) === 0) {
            $bestMatch = $imagePath;
            $bestMatchLength = $imageNameLength;
        }
    }

    return $bestMatch;
}

function group_menu_options(array $options): array {
    $groups = [];
    foreach ($options as $option) {
        $label = trim((string) $option['label']);
        if (preg_match('/^(.+?)\s+-\s+(Small|Medium|Large|1\s*LTR|\d+\s*oz)$/i', $label, $matches)) {
            $group = trim($matches[1]);
            $option['display_label'] = trim($matches[2]);
        } elseif (preg_match('/^(Solo|Double|Family)\b/i', $label)) {
            $group = 'Serving size';
            $option['display_label'] = $label;
        } elseif (preg_match('/^(Small|Medium|Large|1\s*LTR|\d+\s*oz)$/i', $label)) {
            $group = 'Size';
            $option['display_label'] = $label;
        } else {
            $group = 'Options';
            $option['display_label'] = $label;
        }
        $groups[$group][] = $option;
    }

    return $groups;
}

function cart_get(): array {
    $normalized = [];
    foreach ($_SESSION['cart'] ?? [] as $key => $qty) {
        $lineKey = (string) $key;
        if (preg_match('/^(\d+)$/', $lineKey, $matches)) {
            $lineKey = cart_line_key((int) $matches[1]);
        }
        if (preg_match('/^\d+:\d+(?::\d+(?:,\d+)*)?$/', $lineKey)) {
            $normalized[$lineKey] = ($normalized[$lineKey] ?? 0) + (int) $qty;
        }
    }
    $_SESSION['cart'] = $normalized;
    return $normalized;
}

function cart_count(): int {
    global $pdo;
    $cart = cart_get();
    if (!$cart) return 0;

    $partsByKey = [];
    $itemIds = [];
    $addonIds = [];
    foreach (array_keys($cart) as $lineKey) {
        $parts = array_pad(explode(':', $lineKey, 3), 3, '');
        $partsByKey[$lineKey] = $parts;
        $itemIds[] = (int) $parts[0];
        if ($parts[2] !== '') $addonIds = array_merge($addonIds, array_map('intval', explode(',', $parts[2])));
    }
    $itemIds = array_values(array_unique($itemIds));
    $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT m.item_id, m.category, mo.option_id
         FROM menu m LEFT JOIN menu_options mo ON mo.item_id = m.item_id
         WHERE m.is_available = 1 AND m.item_id IN ($placeholders)"
    );
    $stmt->execute($itemIds);
    $optionsByItem = [];
    $categoriesByItem = [];
    foreach ($stmt->fetchAll() as $row) {
        $optionsByItem[$row['item_id']][] = (int) $row['option_id'];
        $categoriesByItem[$row['item_id']] = $row['category'];
    }

    $addonIds = array_values(array_unique($addonIds));
    $addonsById = [];
    if ($addonIds) {
        $addonPlaceholders = implode(',', array_fill(0, count($addonIds), '?'));
        $addonStmt = $pdo->prepare("SELECT item_id, category FROM menu WHERE is_available = 1 AND item_id IN ($addonPlaceholders)");
        $addonStmt->execute($addonIds);
        foreach ($addonStmt->fetchAll() as $addon) $addonsById[$addon['item_id']] = $addon['category'];
    }

    foreach ($cart as $lineKey => $qty) {
        [$itemId, $optionId, $addonString] = $partsByKey[$lineKey];
        $itemId = (int) $itemId;
        $optionId = (int) $optionId;
        $options = $optionsByItem[$itemId] ?? [];
        $valid = $optionId > 0
            ? in_array($optionId, $options, true)
            : ($options && $options[0] === 0);
        if (in_array($categoriesByItem[$itemId] ?? '', ['Add-ons', 'Syrups'], true)) $valid = false;
        $selectedAddons = $addonString !== '' ? array_map('intval', explode(',', $addonString)) : [];
        if ($selectedAddons && !menu_supports_extras($categoriesByItem[$itemId] ?? '')) $valid = false;
        foreach ($selectedAddons as $addonId) {
            if (!in_array($addonsById[$addonId] ?? '', ['Add-ons', 'Syrups'], true)) $valid = false;
        }
        if (!$valid) unset($cart[$lineKey]);
    }
    $_SESSION['cart'] = $cart;
    return array_sum($cart);
}

function cart_add(int $itemId, int $variantId = 0, int $qty = 1, array $addonIds = []): void {
    $cart = cart_get();
    $lineKey = cart_line_key($itemId, $variantId, $addonIds);
    $cart[$lineKey] = ($cart[$lineKey] ?? 0) + $qty;
    $_SESSION['cart'] = $cart;
}

function cart_set_qty(string $lineKey, int $qty): void {
    if (!preg_match('/^\d+:\d+(?::\d+(?:,\d+)*)?$/', $lineKey)) return;
    $cart = cart_get();
    if ($qty <= 0) {
        unset($cart[$lineKey]);
    } else {
        $cart[$lineKey] = $qty;
    }
    $_SESSION['cart'] = $cart;
}

function cart_remove(string $lineKey): void {
    cart_set_qty($lineKey, 0);
}

function cart_clear(): void {
    $_SESSION['cart'] = [];
}

/* ---------------------------------------------------------- *
 * Loyalty logic
 * One point earned per ₱50 spent. Trigger on order completion.
 * ---------------------------------------------------------- */

function award_loyalty_points(PDO $pdo, int $orderId): void {
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare(
            'SELECT user_id, total_amount, points_awarded FROM orders WHERE order_id = ? FOR UPDATE'
        );
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();

        if (!$order || (int) $order['points_awarded'] === 1) {
            if ($ownsTransaction) $pdo->commit();
            return;
        }

        $points = (int) floor((float) $order['total_amount'] / 50);
        if ($points > 0) {
            $pdo->prepare('UPDATE users SET points_balance = points_balance + ? WHERE user_id = ?')
                ->execute([$points, $order['user_id']]);
            $pdo->prepare(
                'INSERT INTO loyalty_history (user_id, order_id, points_earned, points_redeemed)
                 VALUES (?, ?, ?, 0)'
            )->execute([$order['user_id'], $orderId, $points]);
        }

        $pdo->prepare('UPDATE orders SET points_awarded = 1 WHERE order_id = ?')
            ->execute([$orderId]);
        if ($ownsTransaction) $pdo->commit();
    } catch (Throwable $ex) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $ex;
    }
}

function status_badge_class(string $status): string {
    return [
        'Pending'          => 'badge-pending',
        'Preparing'        => 'badge-preparing',
        'Ready'            => 'badge-ready',
        'Out for Delivery' => 'badge-delivery',
        'Completed'        => 'badge-completed',
        'Cancelled'        => 'badge-cancelled',
    ][$status] ?? 'badge-pending';
}

function payment_badge_class(string $status): string {
    return [
        'Unpaid'               => 'badge-cancelled',
        'Pending Verification' => 'badge-pending',
        'Paid'                 => 'badge-ready',
        'Failed'               => 'badge-cancelled',
        'Refunded'             => 'badge-preparing',
    ][$status] ?? 'badge-pending';
}

/**
 * Records a status change in order_status_history — gives "real-time
 * order tracking" an actual timestamped audit trail instead of just a
 * single overwritten column. $changedBy is a user_id, or null for a
 * system/customer-initiated change (e.g. placing the order).
 */
function log_order_status(PDO $pdo, int $orderId, string $status, ?int $changedBy = null): void {
    $pdo->prepare(
        'INSERT INTO order_status_history (order_id, status, changed_by) VALUES (?, ?, ?)'
    )->execute([$orderId, $status, $changedBy]);
}

/* ---------------------------------------------------------- *
 * Loyalty redemption
 * 1 point = ₱1 discount, matching the 1 point per ₱50 spent earn
 * rate. Applied at checkout, capped at both the customer's balance
 * and the order's pre-discount subtotal (can't go negative).
 * ---------------------------------------------------------- */

const LOYALTY_PESO_PER_POINT = 1.0;

/**
 * Deducts points from the customer's balance and logs the redemption.
 * Call this AFTER the order row exists (needs an order_id to log against).
 * Assumes the caller already validated $points against the user's balance.
 */
function redeem_loyalty_points(PDO $pdo, int $userId, int $orderId, int $points): void {
    if ($points <= 0) {
        return;
    }

    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) $pdo->beginTransaction();

    try {
        $deduct = $pdo->prepare(
            'UPDATE users SET points_balance = points_balance - ?
             WHERE user_id = ? AND points_balance >= ?'
        );
        $deduct->execute([$points, $userId, $points]);
        if ($deduct->rowCount() !== 1) {
            throw new RuntimeException('Insufficient loyalty points for redemption.');
        }

        $pdo->prepare(
            'INSERT INTO loyalty_history (user_id, order_id, points_earned, points_redeemed)
             VALUES (?, ?, 0, ?)'
        )->execute([$userId, $orderId, $points]);
        if ($ownsTransaction) $pdo->commit();
    } catch (Throwable $ex) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $ex;
    }
}

/**
 * Straight-line distance in kilometers between two coordinates
 * (Haversine formula) — used to show "~1.2 km away" without calling
 * any mapping API.
 */
function haversine_km(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $earthRadiusKm = 6371.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2
        + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $earthRadiusKm * $c;
}

/** Turns an order's current status into a 0-100 brew-cup fill percentage. */
function brew_percent(array $steps, string $status): int {
    $idx = array_search($status, $steps, true);
    if ($idx === false) return 0;
    return (int) round((($idx + 1) / count($steps)) * 100);
}

/**
 * Renders the "brewing" progress visualization — a cup that fills as an
 * order moves through its stages. $percent is 0-100; $cancelled shows a
 * grayed-out, unfilled cup instead. Purely presentational, driven by the
 * same status data already being tracked elsewhere.
 */
function render_brew_cup(int $percent, bool $cancelled = false): string {
    $percent = $cancelled ? 0 : max(4, min(100, $percent)); // keep a sliver visible once started
    $fillHeight = round(($percent / 100) * 92); // cup interior is ~92 units tall
    $fillY = 134 - $fillHeight;
    $cancelledClass = $cancelled ? ' brew-cup-cancelled' : '';
    $showSteam = !$cancelled && $percent >= 45;
    $steamStyle = $showSteam ? '' : 'display:none;';

    ob_start();
    ?>
    <div class="brew-cup-wrap<?= $cancelledClass ?>" aria-hidden="true">
        <svg viewBox="0 0 120 150" class="brew-cup">
            <path class="brew-steam" style="<?= $steamStyle ?>" d="M45 14 C 38 26, 52 30, 45 42" />
            <path class="brew-steam" style="<?= $steamStyle ?>animation-delay:.35s" d="M60 10 C 53 22, 67 26, 60 38" />
            <path class="brew-steam" style="<?= $steamStyle ?>animation-delay:.7s" d="M75 14 C 68 26, 82 30, 75 42" />
            <!-- Fill sits behind the outline, so it's drawn first; the
                 outline stroke on top hides any slight overflow at the
                 tapered bottom corners without needing a clip-path. -->
            <rect class="brew-fill" x="26" y="<?= $fillY ?>" width="68" height="<?= $fillHeight ?>" rx="3" />
            <path class="brew-cup-outline" d="M24 42 H96 L89 130 A8 8 0 0 1 81 138 H39 A8 8 0 0 1 31 130 Z" />
            <path class="brew-cup-handle" d="M96 58 q22 0 22 20 t-22 20" />
        </svg>
    </div>
    <?php
    return ob_get_clean();
}

/* ---------------------------------------------------------- *
 * Small inline icon set — line-art SVGs matching the brand's
 * illustration style (steam-cup, brew-cup), used in place of
 * emoji for a consistent, considered look instead of OS-default
 * emoji rendering.
 * ---------------------------------------------------------- */

function icon_pin(): string {
    return '<svg class="icon icon-pin" viewBox="0 0 20 20" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 18s6-5.6 6-10.2A6 6 0 0 0 4 7.8C4 12.4 10 18 10 18Z"/><circle cx="10" cy="7.8" r="2.1"/></svg>';
}

function icon_check(): string {
    return '<svg class="icon icon-check" viewBox="0 0 20 20" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="10" cy="10" r="7.5"/><path d="M6.5 10.2 9 12.7l4.5-5.4"/></svg>';
}

function icon_x(): string {
    return '<svg class="icon icon-x" viewBox="0 0 20 20" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="10" cy="10" r="7.5"/><path d="M7.3 7.3 12.7 12.7M12.7 7.3 7.3 12.7"/></svg>';
}

function icon_clock(): string {
    return '<svg class="icon icon-clock" viewBox="0 0 20 20" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="10" cy="10" r="7.5"/><path d="M10 5.8V10l3 2"/></svg>';
}

function icon_shield(): string {
    return '<svg class="icon icon-shield" viewBox="0 0 20 20" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 2.5 16 5v5c0 4.4-3 7.5-6 8.5-3-1-6-4.1-6-8.5V5l6-2.5Z"/><path d="M7.3 10 9.2 11.9 12.8 8"/></svg>';
}

/**
 * Renders the Cart → Checkout → Confirmed progress indicator shown at
 * the top of the cart and checkout pages. Purely visual — the
 * underlying 3-page flow itself doesn't change.
 */
function render_checkout_stepper(string $activeStep): string {
    $steps = ['cart' => 'Cart', 'checkout' => 'Checkout', 'confirmed' => 'Confirmed'];
    $order = array_keys($steps);
    $activeIdx = array_search($activeStep, $order, true);

    ob_start();
    ?>
    <div class="checkout-stepper">
        <?php foreach ($order as $i => $key): ?>
            <?php
            $stateClass = $i < $activeIdx ? 'done' : ($i === $activeIdx ? 'active' : '');
            ?>
            <div class="step <?= $stateClass ?>">
                <span class="step-num"><?= $i < $activeIdx ? '✓' : $i + 1 ?></span> <?= e($steps[$key]) ?>
            </div>
            <?php if ($i < count($order) - 1): ?><div class="step-sep"></div><?php endif; ?>
        <?php endforeach; ?>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Renders a simple breadcrumb trail. $trail is an ordered list of
 * ['label' => ..., 'href' => ...|null]; the last item (no href) is
 * the current page.
 */
function render_breadcrumb(array $trail): string {
    ob_start();
    ?>
    <nav class="breadcrumb" aria-label="Breadcrumb">
        <?php foreach ($trail as $i => $crumb): ?>
            <?php if ($i > 0): ?><span class="breadcrumb-sep">/</span><?php endif; ?>
            <?php if (!empty($crumb['href'])): ?>
                <a href="<?= e($crumb['href']) ?>"><?= e($crumb['label']) ?></a>
            <?php else: ?>
                <span class="breadcrumb-current"><?= e($crumb['label']) ?></span>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>
    <?php
    return ob_get_clean();
}

/* ---------------------------------------------------------- *
 * Category icons — simple line-art matching the steam-cup /
 * brew-cup illustration style, used on the homepage's
 * "shop by category" tiles.
 * ---------------------------------------------------------- */

function icon_coffee_cup(): string {
    return '<svg viewBox="0 0 20 20" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 7h10l-1 8.5a2 2 0 0 1-2 1.5H8a2 2 0 0 1-2-1.5L5 7Z"/><path d="M4.3 7h11.4"/><path d="M15 8.7h1.1a1.7 1.7 0 0 1 0 3.4H15"/></svg>';
}

function icon_leaf(): string {
    return '<svg viewBox="0 0 20 20" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 3C14.5 5 15 11.5 10 17C5 11.5 5.5 5 10 3Z"/><path d="M10 5.5v9.5"/><path d="M10 17v1.5"/></svg>';
}

function icon_pastry(): string {
    return '<svg viewBox="0 0 20 20" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 16V9.5a6 6 0 0 1 12 0V16a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1Z"/><path d="M7 6.8 8.5 9.3M10 5.8l1.5 3M13 6.8l1 2.5"/></svg>';
}

/**
 * Maps a category name to a matching icon — falls back to the
 * category's first letter for anything an admin names that isn't
 * one of the three defaults, so this never breaks for custom
 * categories.
 */
function category_icon(string $category): string {
    $key = strtolower(trim($category));
    if ($key === 'coffee') return icon_coffee_cup();
    if (in_array($key, ['non-coffee', 'non coffee', 'noncoffee'], true)) return icon_leaf();
    if (in_array($key, ['pastry', 'pastries'], true)) return icon_pastry();
    return '<span>' . e(mb_substr($category, 0, 1)) . '</span>';
}
