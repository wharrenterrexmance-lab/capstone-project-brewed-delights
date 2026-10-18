<?php
require_once __DIR__ . '/../includes/functions.php';
require_admin();

$pageTitle = 'Staff — Admin';
$active = 'staff';
$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add_staff') {
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
                    'INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, "staff")'
                )->execute([$name, $email, $hash]);
                $success = 'Staff account created.';
            }
        }
    } elseif ($action === 'remove_staff') {
        // Demote back to a plain customer account rather than deleting —
        // preserves any feedback/order history tied to the user_id.
        $staffId = (int) ($_POST['staff_id'] ?? 0);
        $pdo->prepare("UPDATE users SET role = 'customer' WHERE user_id = ? AND role = 'staff'")
            ->execute([$staffId]);
        $success = 'Staff account removed (demoted to a regular customer account).';
    }
}

$staffMembers = $pdo->query(
    "SELECT user_id, name, email, created_at FROM users WHERE role = 'staff' ORDER BY name"
)->fetchAll();

require __DIR__ . '/includes/admin_header.php';
?>

<h1>Staff accounts</h1>
<p class="section-note">Staff can process orders, verify payments, and review feedback —
they can't manage the menu, riders, or other accounts. That's reserved for admin.</p>
<?php if ($error): ?><p class="alert alert-error"><?= e($error) ?></p><?php endif; ?>
<?php if ($success): ?><p class="alert alert-success"><?= e($success) ?></p><?php endif; ?>

<div class="admin-grid">
    <form method="post" class="admin-form">
        <?= csrf_field() ?>
        <h2>Add a staff member</h2>
        <input type="hidden" name="action" value="add_staff">
        <label>Name<input type="text" name="name" required></label>
        <label>Email<input type="email" name="email" required></label>
        <label>Temporary password<input type="password" name="password" minlength="8" required></label>
        <button type="submit" class="btn btn-primary">Create staff account</button>
        <p class="form-note">Share the email/password with them — they log in at the
        same <code>login.php</code> page and land on the Orders dashboard.</p>
    </form>

    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead><tr><th>Name</th><th>Email</th><th>Added</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($staffMembers as $s): ?>
                    <tr>
                        <td><?= e($s['name']) ?></td>
                        <td><?= e($s['email']) ?></td>
                        <td><?= date('M j, Y', strtotime($s['created_at'])) ?></td>
                        <td>
                            <form method="post" onsubmit="return confirm('Remove this staff account? They will become a regular customer account.');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="remove_staff">
                                <input type="hidden" name="staff_id" value="<?= (int) $s['user_id'] ?>">
                                <button type="submit" class="btn btn-remove btn-sm">Remove</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$staffMembers): ?>
                    <tr><td colspan="4" class="empty-state">No staff accounts yet — add one on the left.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/includes/admin_footer.php'; ?>
