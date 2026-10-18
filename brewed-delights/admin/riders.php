<?php
require_once __DIR__ . '/../includes/functions.php';
require_admin();

$pageTitle = 'Riders — Admin';
$active = 'riders';
$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add_rider') {
        $name  = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $pass  = $_POST['password'] ?? '';

        if (!$name || !$email || strlen($pass) < 8) {
            $error = 'Name, email, and an 8+ character password are required.';
        } else {
            $check = $pdo->prepare('SELECT user_id FROM users WHERE email = ?');
            $check->execute([$email]);
            if ($check->fetch()) {
                $error = 'That email is already registered.';
            } else {
                $hash = password_hash($pass, PASSWORD_BCRYPT);
                $pdo->prepare(
                    'INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, "rider")'
                )->execute([$name, $email, $hash]);
                $success = 'Rider account created.';
            }
        }
    } elseif ($action === 'deactivate') {
        // We don't hard-delete riders (their past deliveries reference
        // them); instead unassign them from any active orders and leave
        // the account as-is. A fuller build would add an is_active flag.
        $riderId = (int) ($_POST['rider_id'] ?? 0);
        $pdo->prepare(
            "UPDATE orders SET rider_id = NULL WHERE rider_id = ? AND status NOT IN ('Completed','Cancelled')"
        )->execute([$riderId]);
        $success = 'Rider unassigned from active deliveries.';
    } elseif ($action === 'assign_active') {
        $riderId = (int) ($_POST['rider_id'] ?? 0);
        $orderId = (int) ($_POST['order_id'] ?? 0);
        $riderCheck = $pdo->prepare('SELECT user_id FROM users WHERE user_id = ? AND role = "rider"');
        $riderCheck->execute([$riderId]);
        if (!$riderCheck->fetch()) {
            $error = 'Select a valid rider.';
        } else {
            $assignStmt = $pdo->prepare(
                "UPDATE orders SET rider_id = ?
                 WHERE order_id = ? AND rider_id IS NULL AND order_type = 'Delivery'
                   AND status IN ('Ready', 'Out for Delivery')"
            );
            $assignStmt->execute([$riderId, $orderId]);
            if ($assignStmt->rowCount() === 1) {
                $success = 'Active delivery assigned to rider.';
            } else {
                $error = 'That delivery is no longer available to assign. Refresh and try again.';
            }
        }
    }
}

$riders = $pdo->query(
    "SELECT u.*,
        (SELECT COUNT(*) FROM orders o WHERE o.rider_id = u.user_id AND o.status IN ('Ready','Out for Delivery')) AS active_deliveries
     FROM users u WHERE u.role = 'rider' ORDER BY u.name"
)->fetchAll();
$unassignedDeliveries = $pdo->query(
    "SELECT o.order_id, o.reference_code, o.status, o.delivery_address, u.name AS customer_name
     FROM orders o JOIN users u ON u.user_id = o.user_id
     WHERE o.order_type = 'Delivery' AND o.rider_id IS NULL AND o.status IN ('Ready','Out for Delivery')
     ORDER BY FIELD(o.status, 'Out for Delivery', 'Ready'), o.created_at"
)->fetchAll();

require __DIR__ . '/includes/admin_header.php';
?>

<h1>Delivery riders</h1>
<?php if ($error): ?><p class="alert alert-error"><?= e($error) ?></p><?php endif; ?>
<?php if ($success): ?><p class="alert alert-success"><?= e($success) ?></p><?php endif; ?>

<div class="admin-grid">
    <form method="post" class="admin-form">
        <?= csrf_field() ?>
        <h2>Add a rider</h2>
        <input type="hidden" name="action" value="add_rider">
        <label>Name<input type="text" name="name" required></label>
        <label>Email<input type="email" name="email" required></label>
        <label>Temporary password<input type="password" name="password" minlength="8" required></label>
        <button type="submit" class="btn btn-primary">Create rider account</button>
        <p class="form-note">Share the email/password with the rider — they log in at the
        same <code>login.php</code> page and land on their own delivery dashboard.</p>
    </form>

    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead><tr><th>Name</th><th>Email</th><th>Active deliveries</th><th>Assignment</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($riders as $r): ?>
                    <tr>
                        <td><?= e($r['name']) ?></td>
                        <td><?= e($r['email']) ?></td>
                        <td><?= (int) $r['active_deliveries'] ?></td>
                        <td>
                            <?php if ($unassignedDeliveries): ?>
                                <form method="post" class="rider-assignment-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="assign_active">
                                    <input type="hidden" name="rider_id" value="<?= (int) $r['user_id'] ?>">
                                    <select name="order_id" aria-label="Delivery to assign to <?= e($r['name']) ?>" required>
                                        <option value="">Select an unassigned delivery</option>
                                        <?php foreach ($unassignedDeliveries as $delivery): ?>
                                            <option value="<?= (int) $delivery['order_id'] ?>">
                                                #<?= (int) $delivery['order_id'] ?> · <?= e($delivery['customer_name']) ?> · <?= e($delivery['status']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" class="btn btn-primary btn-sm">Assign active</button>
                                </form>
                            <?php else: ?>
                                <span class="muted">No unassigned active deliveries</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <form method="post" onsubmit="return confirm('Unassign this rider from active deliveries?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="deactivate">
                                <input type="hidden" name="rider_id" value="<?= (int) $r['user_id'] ?>">
                                <button type="submit" class="btn btn-remove btn-sm">Unassign active</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$riders): ?>
                    <tr><td colspan="5" class="empty-state">No riders yet — add one on the left.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/includes/admin_footer.php'; ?>
