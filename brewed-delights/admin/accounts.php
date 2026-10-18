<?php
require_once __DIR__ . '/../includes/functions.php';
require_admin();

$pageTitle = 'Accounts — Admin';
$active = 'accounts';
$error = null;
$success = null;
$usernameEnabled = users_have_username($pdo);
$emailVerificationEnabled = users_have_email_verified($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $userId = (int) ($_POST['user_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $username = strtolower(trim($_POST['username'] ?? ''));
    $newPassword = $_POST['new_password'] ?? '';

    if ($userId < 1 || $name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a valid name and email address.';
    } elseif ($usernameEnabled && $username !== '' && !preg_match('/^[a-zA-Z0-9._-]{3,32}$/D', $username)) {
        $error = 'Username must be 3-32 characters using letters, numbers, dots, underscores, or hyphens.';
    } elseif ($newPassword !== '' && strlen($newPassword) < 8) {
        $error = 'A new password must be at least 8 characters.';
    } else {
        $accountStmt = $pdo->prepare('SELECT user_id FROM users WHERE user_id = ? AND role = "customer"');
        $accountStmt->execute([$userId]);
        if (!$accountStmt->fetch()) {
            $error = 'Customer account not found.';
        } else {
            $duplicateStmt = $pdo->prepare('SELECT user_id FROM users WHERE email = ? AND user_id <> ?');
            $duplicateStmt->execute([$email, $userId]);
            if ($duplicateStmt->fetch()) {
                $error = 'That email address is already used by another account.';
            }

            if (!$error && $usernameEnabled && $username !== '') {
                $usernameStmt = $pdo->prepare('SELECT user_id FROM users WHERE username = ? AND user_id <> ?');
                $usernameStmt->execute([$username, $userId]);
                if ($usernameStmt->fetch()) $error = 'That username is already taken.';
            }

            if (!$error) {
                if ($usernameEnabled) {
                    $updateSql = 'UPDATE users SET name = ?, email = ?, username = NULLIF(?, "") WHERE user_id = ? AND role = "customer"';
                    $pdo->prepare($updateSql)->execute([$name, $email, $username, $userId]);
                } else {
                    $pdo->prepare('UPDATE users SET name = ?, email = ? WHERE user_id = ? AND role = "customer"')
                        ->execute([$name, $email, $userId]);
                }

                if ($newPassword !== '') {
                    $passwordHash = password_hash($newPassword, PASSWORD_BCRYPT);
                    $pdo->prepare('UPDATE users SET password_hash = ? WHERE user_id = ? AND role = "customer"')
                        ->execute([$passwordHash, $userId]);
                }

                $success = 'Customer account updated.';
            }
        }
    }
}

$editUser = null;
if (isset($_GET['edit'])) {
    $verificationField = $emailVerificationEnabled ? 'email_verified' : '0 AS email_verified';
    $usernameField = $usernameEnabled ? 'username' : 'NULL AS username';
    $editStmt = $pdo->prepare("SELECT user_id, name, email, $usernameField, $verificationField FROM users WHERE user_id = ? AND role = \"customer\"");
    $editStmt->execute([(int) $_GET['edit']]);
    $editUser = $editStmt->fetch() ?: null;
}

$search = trim($_GET['q'] ?? '');
$usernameField = $usernameEnabled ? 'u.username' : 'NULL AS username';
$usernameSearch = $usernameEnabled ? 'COALESCE(u.username, "")' : '""';
$verificationField = $emailVerificationEnabled ? 'u.email_verified' : '0 AS email_verified';
$customersStmt = $pdo->prepare(
        "SELECT u.user_id, u.name, u.email, $usernameField, $verificationField, u.created_at,
            COUNT(o.order_id) AS order_count
     FROM users u LEFT JOIN orders o ON o.user_id = u.user_id
         WHERE u.role = 'customer'
             AND (? = '' OR u.name LIKE ? OR u.email LIKE ? OR $usernameSearch LIKE ?)
     GROUP BY u.user_id
         ORDER BY u.created_at DESC"
);
$searchLike = '%' . $search . '%';
$customersStmt->execute([$search, $searchLike, $searchLike, $searchLike]);
$customers = $customersStmt->fetchAll();

require __DIR__ . '/includes/admin_header.php';
?>

<h1>Customer accounts</h1>
<p class="section-note">View customer accounts, update contact details, or set a new password. Passwords are never displayed. Staff, rider, and admin roles are managed separately.</p>
<?php if ($error): ?><p class="alert alert-error"><?= e($error) ?></p><?php endif; ?>
<?php if ($success): ?><p class="alert alert-success"><?= e($success) ?></p><?php endif; ?>

<?php if ($editUser): ?>
    <form method="post" class="admin-form account-edit-form">
        <?= csrf_field() ?>
        <input type="hidden" name="user_id" value="<?= (int) $editUser['user_id'] ?>">
        <h2>Edit customer</h2>
        <label>Name<input type="text" name="name" maxlength="100" value="<?= e($editUser['name']) ?>" required></label>
        <?php if ($usernameEnabled): ?>
            <label>Username<input type="text" name="username" minlength="3" maxlength="32" pattern="[A-Za-z0-9._\-]{3,32}" value="<?= e($editUser['username'] ?? '') ?>"></label>
        <?php endif; ?>
        <label>Email<input type="email" name="email" maxlength="150" value="<?= e($editUser['email']) ?>" required></label>
        <label>Set new password<input type="password" name="new_password" minlength="8" autocomplete="new-password"></label>
        <p class="form-note">Leave the password blank to keep the current password. Google-only accounts can be given a local password here.</p>
        <button type="submit" class="btn btn-primary">Save account</button>
        <a href="accounts.php" class="btn btn-ghost">Cancel</a>
    </form>
<?php endif; ?>

<form method="get" class="admin-filter-form">
    <label>Search customers<input type="search" name="q" value="<?= e($search) ?>" placeholder="Name, username, or email"></label>
    <button class="btn btn-ghost" type="submit">Search</button>
    <?php if ($search !== ''): ?><a href="accounts.php" class="btn btn-ghost">Clear</a><?php endif; ?>
</form>

<div class="admin-table-wrap">
    <table class="admin-table">
        <thead><tr><th>Customer</th><th>Username</th><th>Email</th><th>Orders</th><th>Joined</th><th></th></tr></thead>
        <tbody>
            <?php foreach ($customers as $customer): ?>
                <tr>
                    <td><?= e($customer['name']) ?></td>
                    <td><?= e($customer['username'] ?? '—') ?></td>
                    <td>
                        <?= e($customer['email']) ?>
                        <?php if (!empty($customer['email_verified'])): ?><span class="badge badge-ready">Verified</span><?php endif; ?>
                    </td>
                    <td><?= (int) $customer['order_count'] ?></td>
                    <td><?= date('M j, Y', strtotime($customer['created_at'])) ?></td>
                    <td><a href="accounts.php?edit=<?= (int) $customer['user_id'] ?><?= $search !== '' ? '&q=' . urlencode($search) : '' ?>" class="btn btn-ghost btn-sm">Manage</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$customers): ?>
                <tr><td colspan="6" class="empty-state">No customer accounts found.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require __DIR__ . '/includes/admin_footer.php'; ?>