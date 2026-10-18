<?php
require_once __DIR__ . '/includes/functions.php';
require_login();

$pageTitle = 'Feedback — Brewed Delights';
$user = current_user();
$error = null;
$success = false;

// Only let customers attach feedback to their own completed orders
$ordersStmt = $pdo->prepare(
    "SELECT o.order_id, o.created_at FROM orders o
     WHERE o.user_id = ? AND o.status = 'Completed'
       AND NOT EXISTS (
           SELECT 1 FROM feedback f WHERE f.user_id = o.user_id AND f.order_id = o.order_id
       )
     ORDER BY o.created_at DESC"
);
$ordersStmt->execute([$user['user_id']]);
$completedOrders = $ordersStmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $rating   = (int) ($_POST['rating'] ?? 0);
    $comments = trim($_POST['comments'] ?? '');
    $orderId  = ($_POST['order_id'] ?? '') !== '' ? (int) $_POST['order_id'] : null;

    if ($rating < 1 || $rating > 5) {
        $error = 'Please choose a rating from 1 to 5.';
    } elseif (mb_strlen($comments) > 2000) {
        $error = 'Your comment must be 2,000 characters or fewer.';
    } else {
        if ($orderId) {
            $check = $pdo->prepare(
                "SELECT o.order_id FROM orders o
                 WHERE o.order_id = ? AND o.user_id = ? AND o.status = 'Completed'
                   AND NOT EXISTS (
                       SELECT 1 FROM feedback f WHERE f.user_id = o.user_id AND f.order_id = o.order_id
                   )"
            );
            $check->execute([$orderId, $user['user_id']]);
            if (!$check->fetch()) {
                $error = 'That order is not available for feedback or has already been reviewed.';
            }
        }

        if (!$error) {
            $pdo->prepare(
                'INSERT INTO feedback (user_id, order_id, rating, comments) VALUES (?, ?, ?, ?)'
            )->execute([$user['user_id'], $orderId, $rating, $comments ?: null]);
            $success = true;
        }
    }
}

$myFeedbackStmt = $pdo->prepare('SELECT * FROM feedback WHERE user_id = ? ORDER BY created_at DESC');
$myFeedbackStmt->execute([$user['user_id']]);
$myFeedback = $myFeedbackStmt->fetchAll();

require __DIR__ . '/includes/header.php';
?>

<section class="page-section">
    <?= render_breadcrumb([['label' => 'Menu', 'href' => 'index.php'], ['label' => 'Feedback']]) ?>
    <h1>Feedback</h1>

    <?php if ($success): ?>
        <p class="alert alert-success">Thanks — your feedback has been recorded.</p>
    <?php endif; ?>
    <?php if ($error): ?><p class="alert alert-error"><?= e($error) ?></p><?php endif; ?>

    <form method="post" class="feedback-form">
        <?= csrf_field() ?>
        <fieldset class="rating-fieldset">
            <legend>Your rating</legend>
            <?php for ($i = 5; $i >= 1; $i--): ?>
                <label class="star-label">
                    <input type="radio" name="rating" value="<?= $i ?>" required>
                    <?= $i ?>&#9733;
                </label>
            <?php endfor; ?>
        </fieldset>

        <?php if ($completedOrders): ?>
            <label>Related order (optional)
                <select name="order_id">
                    <option value="">General feedback</option>
                    <?php foreach ($completedOrders as $o): ?>
                        <option value="<?= (int) $o['order_id'] ?>">
                            Order #<?= (int) $o['order_id'] ?> — <?= date('M j, Y', strtotime($o['created_at'])) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        <?php endif; ?>

        <label>Comments
            <textarea name="comments" rows="4" placeholder="Tell us what you liked, or what we can do better."></textarea>
        </label>

        <button type="submit" class="btn btn-primary">Submit feedback</button>
    </form>

    <?php if ($myFeedback): ?>
        <h2 class="section-subhead">Your past feedback</h2>
        <div class="feedback-list">
            <?php foreach ($myFeedback as $fb): ?>
                <div class="feedback-item">
                    <span class="stars"><?= str_repeat('★', (int) $fb['rating']) . str_repeat('☆', 5 - (int) $fb['rating']) ?></span>
                    <p><?= e($fb['comments']) ?: '<span class="muted">No comment</span>' ?></p>
                    <span class="feedback-date"><?= date('M j, Y', strtotime($fb['created_at'])) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
