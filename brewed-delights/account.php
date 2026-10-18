<?php
require_once __DIR__ . '/includes/functions.php';
require_login();

$user = current_user();
if (is_rider()) {
    header('Location: rider/account.php');
    exit;
}
$pageTitle = 'My account — Brewed Delights';
$usernameEnabled = users_have_username($pdo);
$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $name = trim($_POST['name'] ?? '');
    $username = strtolower(trim($_POST['username'] ?? ''));
    $phone = trim($_POST['phone_number'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $useAddressForDelivery = isset($_POST['use_address_for_delivery']) ? 1 : 0;
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if ($name === '' || mb_strlen($name) > 100) {
        $error = 'Enter your name (up to 100 characters).';
    } elseif ($usernameEnabled && $username !== '' && !preg_match('/^[a-zA-Z0-9._-]{3,32}$/D', $username)) {
        $error = 'Username must be 3-32 characters using letters, numbers, dots, underscores, or hyphens.';
    } elseif (mb_strlen($phone) > 30 || ($phone !== '' && !preg_match('/^\+?[0-9][0-9 ()-]{6,28}$/D', $phone))) {
        $error = 'Enter a valid phone number, including country code if needed.';
    } elseif (mb_strlen($address) > 255) {
        $error = 'Address must be 255 characters or fewer.';
    } elseif ($useAddressForDelivery && $address === '') {
        $error = 'Enter an address before selecting it as your delivery address.';
    } elseif (($newPassword !== '' || $confirmPassword !== '') && strlen($newPassword) < 8) {
        $error = 'A new password must be at least 8 characters.';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'The new passwords do not match.';
    } else {
        $profileStmt = $pdo->prepare('SELECT password_hash FROM users WHERE user_id = ?');
        $profileStmt->execute([$user['user_id']]);
        $storedPasswordHash = $profileStmt->fetchColumn();

        if ($newPassword !== '' && $storedPasswordHash && !password_verify($currentPassword, $storedPasswordHash)) {
            $error = 'Your current password is incorrect.';
        }

        if (!$error && $usernameEnabled && $username !== '') {
            $usernameStmt = $pdo->prepare('SELECT user_id FROM users WHERE username = ? AND user_id <> ?');
            $usernameStmt->execute([$username, $user['user_id']]);
            if ($usernameStmt->fetch()) $error = 'That username is already taken.';
        }

        if (!$error) {
            if ($usernameEnabled) {
                $pdo->prepare(
                    'UPDATE users SET name = ?, username = NULLIF(?, ""), phone_number = NULLIF(?, ""), address = NULLIF(?, ""), use_address_for_delivery = ? WHERE user_id = ?'
                )->execute([$name, $username, $phone, $address, $useAddressForDelivery, $user['user_id']]);
            } else {
                $pdo->prepare(
                    'UPDATE users SET name = ?, phone_number = NULLIF(?, ""), address = NULLIF(?, ""), use_address_for_delivery = ? WHERE user_id = ?'
                )->execute([$name, $phone, $address, $useAddressForDelivery, $user['user_id']]);
            }

            if ($newPassword !== '') {
                $passwordHash = password_hash($newPassword, PASSWORD_BCRYPT);
                $pdo->prepare('UPDATE users SET password_hash = ? WHERE user_id = ?')
                    ->execute([$passwordHash, $user['user_id']]);
            }

            $_SESSION['user']['name'] = $name;
            $success = 'Your account details have been saved.';
        }
    }
}

$fields = $usernameEnabled
    ? 'name, username, email, phone_number, address, use_address_for_delivery, password_hash'
    : 'name, email, phone_number, address, use_address_for_delivery, password_hash';
$accountStmt = $pdo->prepare("SELECT $fields FROM users WHERE user_id = ?");
$accountStmt->execute([$user['user_id']]);
$account = $accountStmt->fetch();

require __DIR__ . '/includes/header.php';
?>

<section class="page-section account-page">
    <?= render_breadcrumb([['label' => 'Menu', 'href' => 'index.php'], ['label' => 'My account']]) ?>
    <h1>My account</h1>
    <p class="section-note">Manage your contact details and password. Your email address is used for account identification.</p>

    <?php if ($error): ?><p class="alert alert-error"><?= e($error) ?></p><?php endif; ?>
    <?php if ($success): ?><p class="alert alert-success"><?= e($success) ?></p><?php endif; ?>

    <form method="post" class="admin-form account-profile-form">
        <?= csrf_field() ?>
        <h2>Profile details</h2>
        <label>Full name<input type="text" name="name" maxlength="100" value="<?= e($_POST['name'] ?? $account['name']) ?>" autocomplete="name" required></label>
        <?php if ($usernameEnabled): ?>
            <label>Username<input type="text" name="username" minlength="3" maxlength="32" pattern="[A-Za-z0-9._\-]{3,32}" value="<?= e($_POST['username'] ?? $account['username'] ?? '') ?>" autocomplete="username"></label>
        <?php endif; ?>
        <label>Email<input type="email" value="<?= e($account['email']) ?>" readonly></label>
        <label>Phone number<input type="tel" name="phone_number" maxlength="30" value="<?= e($_POST['phone_number'] ?? $account['phone_number'] ?? '') ?>" autocomplete="tel" placeholder="+63 9XX XXX XXXX"></label>
        <label>Address<textarea name="address" maxlength="255" rows="3" autocomplete="street-address"><?= e($_POST['address'] ?? $account['address'] ?? '') ?></textarea></label>
        <label class="checkbox-label"><input type="checkbox" name="use_address_for_delivery" value="1" <?= (isset($_POST['use_address_for_delivery']) ? true : !empty($account['use_address_for_delivery'])) ? 'checked' : '' ?>> Use this as my delivery address</label>
        <p class="form-note">When selected, this address is suggested at checkout. You can still edit it for each order.</p>

        <h2>Password</h2>
        <?php if (!empty($account['password_hash'])): ?>
            <label>Current password<input type="password" name="current_password" autocomplete="current-password"></label>
        <?php else: ?>
            <p class="form-note">No local password is set for this account. Choose a new one here to enable password sign-in too.</p>
        <?php endif; ?>
        <label>New password<input type="password" name="new_password" minlength="8" autocomplete="new-password"></label>
        <label>Confirm new password<input type="password" name="confirm_password" minlength="8" autocomplete="new-password"></label>
        <p class="form-note">Leave both new-password fields blank to keep your current password.</p>
        <button type="submit" class="btn btn-primary">Save account</button>
    </form>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
