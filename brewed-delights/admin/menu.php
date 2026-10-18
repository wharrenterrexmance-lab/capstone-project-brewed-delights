<?php
require_once __DIR__ . '/../includes/functions.php';
require_admin();

$pageTitle = 'Menu — Admin';
$active = 'menu';
$error = null;
$success = $_SESSION['menu_success'] ?? null;
unset($_SESSION['menu_success']);
$search = trim((string) ($_GET['q'] ?? ''));
if (mb_strlen($search) > 100) $search = mb_substr($search, 0, 100);
$visibility = $_GET['visibility'] ?? 'available';
if (!in_array($visibility, ['available', 'hidden', 'all'], true)) $visibility = 'available';
$menuUrl = static function (string $targetVisibility, string $query = ''): string {
    $params = ['visibility' => $targetVisibility];
    if ($query !== '') $params['q'] = $query;
    return 'menu.php?' . http_build_query($params);
};

// ---- Handle create / update / delete ----------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && empty($_FILES)
    && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    $error = 'The request exceeded PHP\'s upload limit. Choose a photo under 5 MB and try again.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error) {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $itemId      = (int) ($_POST['item_id'] ?? 0);
        $name        = trim($_POST['name'] ?? '');
        $pricingMode  = ($_POST['pricing_mode'] ?? 'single') === 'options' ? 'options' : 'single';
        $price       = (float) ($_POST['single_price'] ?? 0);
        $description = trim($_POST['description'] ?? '');
        $category    = trim($_POST['category'] ?? 'Coffee');
        $isFeatured  = isset($_POST['is_featured']) ? 1 : 0;
        $isAvailable = isset($_POST['is_available']) ? 1 : 0;
        $options = [];
        $optionLabelsSeen = [];
        $currentImagePath = null;
        $newUploadAbsolutePath = null;

        $optionLabels = is_array($_POST['option_label'] ?? null) ? $_POST['option_label'] : [];
        $optionPrices = is_array($_POST['option_price'] ?? null) ? $_POST['option_price'] : [];
        if ($pricingMode === 'options') {
            foreach ($optionLabels as $index => $labelValue) {
                $label = trim((string) $labelValue);
                $priceValue = trim((string) ($optionPrices[$index] ?? ''));
                if ($label === '' && $priceValue === '') continue;
                if ($label === '' || mb_strlen($label) > 100 || !is_numeric($priceValue) || (float) $priceValue <= 0) {
                    $error = 'Enter both a size/option name and a price greater than 0 for each row.';
                    break;
                }
                $normalizedLabel = mb_strtolower($label);
                if (isset($optionLabelsSeen[$normalizedLabel])) {
                    $error = 'Each size or option name must be unique.';
                    break;
                }
                $optionLabelsSeen[$normalizedLabel] = true;
                $options[] = ['label' => $label, 'price' => (float) $priceValue];
            }
            if (!$error && !$options) $error = 'Add at least one size or price option.';
            if (!$error) $price = min(array_column($options, 'price'));
        }

        if (!$error && (mb_strlen($name) > 120 || mb_strlen($category) > 60)) {
            $error = 'Name must be 120 characters or fewer and category 60 characters or fewer.';
        }
        if (!$error && ($name === '' || ($pricingMode === 'single' && $price <= 0))) {
            $error = $pricingMode === 'single' ? 'Name and a price above 0 are required.' : 'A product name is required.';
        }

        if ($itemId > 0) {
            $imageStmt = $pdo->prepare('SELECT image_path FROM menu WHERE item_id = ?');
            $imageStmt->execute([$itemId]);
            $currentImagePath = $imageStmt->fetchColumn() ?: null;
        }
        if ($currentImagePath === null) {
            $currentImagePath = menu_item_image_path(['name' => $name]);
        }
        $imagePath = isset($_POST['remove_image']) ? null : $currentImagePath;
        $uploadedImage = $_FILES['image_file'] ?? null;
        if (!$error && $uploadedImage && $uploadedImage['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($uploadedImage['error'] !== UPLOAD_ERR_OK) {
                $error = match ($uploadedImage['error']) {
                    UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The selected photo exceeds the server upload limit. Choose a smaller image.',
                    UPLOAD_ERR_PARTIAL => 'The photo upload was interrupted. Please choose the file again.',
                    UPLOAD_ERR_NO_TMP_DIR => 'PHP has no temporary upload folder configured.',
                    UPLOAD_ERR_CANT_WRITE => 'The server could not write the uploaded photo. Check the uploads folder permissions.',
                    UPLOAD_ERR_EXTENSION => 'A server extension stopped the photo upload.',
                    default => 'The photo could not be uploaded. Please choose it again.',
                };
            } elseif ($uploadedImage['size'] > 5 * 1024 * 1024) {
                $error = 'Photos must be 5 MB or smaller. Choose a smaller image and try again.';
            } else {
                $fileInfo = new finfo(FILEINFO_MIME_TYPE);
                $mimeType = $fileInfo->file($uploadedImage['tmp_name']);
                $allowedImages = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
                if (!isset($allowedImages[$mimeType])) {
                    $error = 'Choose a JPG, PNG, WEBP, or GIF image.';
                } else {
                    $uploadDirectory = __DIR__ . '/../Products Images';
                    if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0755, true) && !is_dir($uploadDirectory)) {
                        $error = 'Could not create the menu image folder.';
                    } else {
                        $imageFileName = bin2hex(random_bytes(16)) . '.' . $allowedImages[$mimeType];
                        if (!move_uploaded_file($uploadedImage['tmp_name'], $uploadDirectory . '/' . $imageFileName)) {
                            $error = 'Could not store the uploaded image.';
                        } else {
                            $imagePath = 'Products Images/' . $imageFileName;
                            $newUploadAbsolutePath = $uploadDirectory . '/' . $imageFileName;
                        }
                    }
                }
            }
        }

        if (!$error) {
            try {
                $pdo->beginTransaction();
                if ($itemId > 0) {
                    $pdo->prepare(
                        'UPDATE menu SET name=?, price=?, description=?, category=?, image_path=?, is_featured=?, is_available=? WHERE item_id=?'
                    )->execute([$name, $price, $description, $category, $imagePath ?: null, $isFeatured, $isAvailable, $itemId]);
                } else {
                    $pdo->prepare(
                        'INSERT INTO menu (name, price, description, category, image_path, is_featured, is_available) VALUES (?,?,?,?,?,?,?)'
                    )->execute([$name, $price, $description, $category, $imagePath ?: null, $isFeatured, $isAvailable]);
                    $itemId = (int) $pdo->lastInsertId();
                }

                $pdo->prepare('DELETE FROM menu_options WHERE item_id = ?')->execute([$itemId]);
                $optionStmt = $pdo->prepare('INSERT INTO menu_options (item_id, label, price) VALUES (?, ?, ?)');
                foreach ($options as $option) $optionStmt->execute([$itemId, $option['label'], $option['price']]);
                $pdo->commit();
            } catch (Throwable $ex) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                if ($newUploadAbsolutePath && is_file($newUploadAbsolutePath)) @unlink($newUploadAbsolutePath);
                error_log('Menu save failed: ' . $ex->getMessage());
                $error = 'Could not save this menu item. Please try again.';
            }
        }

        if (!$error) {
            $savedPhoto = $newUploadAbsolutePath !== null;
            $_SESSION['menu_success'] = $savedPhoto
                ? ($isAvailable
                    ? 'Menu item and product photo saved in Products Images. Open the storefront to check the image.'
                    : 'Product photo saved in Products Images. This item is hidden from the storefront until Available on menu is enabled.')
                : 'Menu item saved.';
            header('Location: ' . $menuUrl($visibility, $search));
            exit;
        }
    } elseif ($action === 'delete') {
        $itemId = (int) ($_POST['item_id'] ?? 0);
        // Soft-delete: menu items referenced by past orders can't be
        // hard-deleted (FK RESTRICT), so mark unavailable instead.
        $pdo->prepare('UPDATE menu SET is_available = 0 WHERE item_id = ?')->execute([$itemId]);
        header('Location: ' . $menuUrl($visibility, $search)); exit;
    }
}

$editItem = null;
$editOptions = [];
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM menu WHERE item_id = ?');
    $stmt->execute([(int) $_GET['edit']]);
    $editItem = $stmt->fetch() ?: null;
    if ($editItem) {
        $optionStmt = $pdo->prepare('SELECT label, price FROM menu_options WHERE item_id = ? ORDER BY option_id');
        $optionStmt->execute([$editItem['item_id']]);
        $editOptions = $optionStmt->fetchAll();
    }
}
$editImagePath = $editItem ? menu_item_image_path($editItem) : null;

$availabilityCounts = [
    'available' => 0,
    'hidden' => 0,
];
foreach ($pdo->query('SELECT is_available, COUNT(*) AS item_count FROM menu GROUP BY is_available')->fetchAll() as $countRow) {
    $availabilityCounts[(int) $countRow['is_available'] === 1 ? 'available' : 'hidden'] = (int) $countRow['item_count'];
}
$whereParts = [];
$queryParams = [];
if ($visibility === 'available') $whereParts[] = 'is_available = 1';
if ($visibility === 'hidden') $whereParts[] = 'is_available = 0';
if ($search !== '') {
    $whereParts[] = '(name LIKE ? OR category LIKE ? OR description LIKE ?)';
    $searchPattern = '%' . $search . '%';
    array_push($queryParams, $searchPattern, $searchPattern, $searchPattern);
}
$itemsSql = 'SELECT * FROM menu' . ($whereParts ? ' WHERE ' . implode(' AND ', $whereParts) : '') . ' ORDER BY category, name';
$itemsStmt = $pdo->prepare($itemsSql);
$itemsStmt->execute($queryParams);
$items = $itemsStmt->fetchAll();
$featuredCount = (int) $pdo->query(
    "SELECT COUNT(*) FROM menu WHERE is_featured = 1 AND is_available = 1 AND category NOT IN ('Add-ons', 'Syrups')"
)->fetchColumn();

require __DIR__ . '/includes/admin_header.php';
?>

<h1>Menu management</h1>
<?php if ($error): ?><p class="alert alert-error"><?= e($error) ?></p><?php endif; ?>
<?php if ($success): ?><p class="alert alert-success"><?= e($success) ?> <a href="../index.php">Open storefront</a></p><?php endif; ?>
<p class="menu-featured-note">
    <?= $featuredCount ? $featuredCount . ' product' . ($featuredCount === 1 ? '' : 's') . ' currently featured on the homepage.' : 'No products are featured on the homepage yet.' ?>
    Mark an available product as featured to show it in <strong>Featured this week</strong>. Customer favorites are ranked automatically from orders.
</p>

<nav class="filter-tabs menu-visibility-tabs" aria-label="Product visibility">
    <a href="<?= e($menuUrl('available', $search)) ?>" class="<?= $visibility === 'available' ? 'active' : '' ?>" <?= $visibility === 'available' ? 'aria-current="page"' : '' ?>>Available (<?= $availabilityCounts['available'] ?>)</a>
    <a href="<?= e($menuUrl('hidden', $search)) ?>" class="<?= $visibility === 'hidden' ? 'active' : '' ?>" <?= $visibility === 'hidden' ? 'aria-current="page"' : '' ?>>Hidden (<?= $availabilityCounts['hidden'] ?>)</a>
    <a href="<?= e($menuUrl('all', $search)) ?>" class="<?= $visibility === 'all' ? 'active' : '' ?>" <?= $visibility === 'all' ? 'aria-current="page"' : '' ?>>All (<?= $availabilityCounts['available'] + $availabilityCounts['hidden'] ?>)</a>
</nav>

<div class="admin-grid">
    <form method="post" class="admin-form menu-editor-form" enctype="multipart/form-data" data-menu-editor>
        <?= csrf_field() ?>
        <h2><?= $editItem ? 'Edit item' : 'Add item' ?></h2>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="item_id" value="<?= (int) ($editItem['item_id'] ?? 0) ?>">

        <label>Name<input type="text" name="name" value="<?= e($editItem['name'] ?? '') ?>" required></label>
        <label>Category<input type="text" name="category" value="<?= e($editItem['category'] ?? 'Coffee') ?>" required></label>

        <fieldset class="menu-pricing-fieldset">
            <legend>Pricing</legend>
            <div class="menu-pricing-modes">
                <label class="radio-label"><input type="radio" name="pricing_mode" value="single" data-pricing-mode <?= ($_POST['pricing_mode'] ?? ($editOptions ? 'options' : 'single')) === 'single' ? 'checked' : '' ?>> One price</label>
                <label class="radio-label"><input type="radio" name="pricing_mode" value="options" data-pricing-mode <?= ($_POST['pricing_mode'] ?? ($editOptions ? 'options' : 'single')) === 'options' ? 'checked' : '' ?>> Sizes or options</label>
            </div>
            <div data-single-price-panel>
                <label>Price (₱)<input type="number" step="0.01" min="0.01" name="single_price" data-single-price value="<?= e((string) ($_POST['single_price'] ?? $editItem['price'] ?? '')) ?>"></label>
            </div>
            <div data-options-panel>
                <div class="menu-option-editor-head"><strong>Size or option</strong><strong>Price (₱)</strong><span></span></div>
                <div class="menu-option-editor-rows" data-option-rows>
                    <?php
                    $postedOptionLabels = is_array($_POST['option_label'] ?? null) ? $_POST['option_label'] : [];
                    $postedOptionPrices = is_array($_POST['option_price'] ?? null) ? $_POST['option_price'] : [];
                    $optionRows = $postedOptionLabels
                        ? array_map(static fn($label, $index): array => ['label' => $label, 'price' => $postedOptionPrices[$index] ?? ''], $postedOptionLabels, array_keys($postedOptionLabels))
                        : $editOptions;
                    if (!$optionRows) $optionRows = [['label' => '', 'price' => '']];
                    foreach ($optionRows as $optionRow):
                    ?>
                        <div class="menu-option-editor-row" data-option-row>
                            <input type="text" name="option_label[]" value="<?= e((string) $optionRow['label']) ?>" placeholder="e.g. Medium" aria-label="Size or option name">
                            <input type="number" name="option_price[]" value="<?= e((string) $optionRow['price']) ?>" min="0.01" step="0.01" placeholder="0.00" aria-label="Option price">
                            <button type="button" class="btn btn-remove btn-sm" data-remove-option aria-label="Remove option">&times;</button>
                        </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="btn btn-ghost btn-sm" data-add-option>Add size or option</button>
                <p class="form-note">Each row becomes a selectable choice. The lowest option price is shown as the starting price.</p>
            </div>
        </fieldset>

        <label>Description<textarea name="description" rows="3"><?= e($editItem['description'] ?? '') ?></textarea></label>
        <label>Product photo<input type="file" name="image_file" accept="image/jpeg,image/png,image/webp,image/gif" data-image-file></label>
        <p class="form-note">Optional. JPG, PNG, WEBP, or GIF, up to 5 MB.</p>
        <?php if ($editImagePath): ?>
            <div class="menu-image-preview">
                <img src="../<?= e($editImagePath) ?>" alt="Current product photo" data-current-image>
                <label class="checkbox-label"><input type="checkbox" name="remove_image" value="1"> Remove current photo</label>
            </div>
        <?php endif; ?>
        <div class="menu-image-preview" data-upload-preview hidden>
            <img alt="Selected product photo preview" data-upload-preview-image>
            <span class="form-note" data-upload-file-name></span>
        </div>
        <label class="checkbox-label">
            <input type="checkbox" name="is_featured" <?= !empty($editItem['is_featured']) ? 'checked' : '' ?>>
            Feature on homepage
        </label>
        <p class="form-note menu-featured-help">Adds this product to the homepage's Featured this week section. This is separate from the sales-ranked Customer favorites section.</p>
        <label class="checkbox-label">
            <input type="checkbox" name="is_available" <?= (!$editItem || $editItem['is_available']) ? 'checked' : '' ?>>
            Available on menu
        </label>

        <button type="submit" class="btn btn-primary"><?= $editItem ? 'Save changes' : 'Add item' ?></button>
        <?php if ($editItem): ?><a href="menu.php" class="btn btn-ghost">Cancel</a><?php endif; ?>
    </form>

    <div class="admin-menu-catalog">
        <form method="get" class="admin-menu-search">
            <input type="hidden" name="visibility" value="<?= e($visibility) ?>">
            <label for="menu-product-search">Search products</label>
            <div class="admin-menu-search-controls">
                <input id="menu-product-search" type="search" name="q" maxlength="100" value="<?= e($search) ?>" placeholder="Name, category, or description">
                <button type="submit" class="btn btn-primary btn-sm">Search</button>
                <?php if ($search !== ''): ?><a href="<?= e($menuUrl($visibility)) ?>" class="btn btn-ghost btn-sm">Clear</a><?php endif; ?>
            </div>
            <?php if ($search !== ''): ?><p class="form-note"><?= count($items) ?> matching product<?= count($items) === 1 ? '' : 's' ?></p><?php endif; ?>
        </form>
        <div class="admin-table-wrap">
        <table class="admin-table">
            <thead>
                <tr><th>Photo</th><th>Name</th><th>Category</th><th>Price</th><th>Homepage</th><th>Status</th><th></th></tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                    <?php
                    $editParams = ['edit' => (int) $item['item_id'], 'visibility' => $visibility];
                    if ($search !== '') $editParams['q'] = $search;
                    $itemImagePath = menu_item_image_path($item);
                    ?>
                    <tr>
                        <td>
                            <?php if ($itemImagePath): ?>
                                <img class="admin-menu-thumbnail" src="../<?= e($itemImagePath) ?>" alt="">
                            <?php else: ?>
                                <span class="admin-menu-no-photo">No photo</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= e($item['name']) ?>
                        </td>
                        <td><?= e($item['category']) ?></td>
                        <td><?= peso((float) $item['price']) ?></td>
                        <td><?= !empty($item['is_featured']) ? '<span class="badge badge-preparing">Featured</span>' : '<span class="muted">Not featured</span>' ?></td>
                        <td>
                            <span class="badge <?= $item['is_available'] ? 'badge-ready' : 'badge-cancelled' ?>">
                                <?= $item['is_available'] ? 'Available' : 'Hidden' ?>
                            </span>
                        </td>
                        <td class="table-actions">
                            <a href="menu.php?<?= e(http_build_query($editParams)) ?>" class="btn btn-ghost btn-sm">Edit</a>
                            <form method="post" onsubmit="return confirm('Hide this item from the menu?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="item_id" value="<?= (int) $item['item_id'] ?>">
                                <button type="submit" class="btn btn-remove btn-sm">Hide</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$items): ?>
                    <tr><td colspan="7" class="muted">No products match “<?= e($search) ?>”.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<template id="menu-option-row-template">
    <div class="menu-option-editor-row" data-option-row>
        <input type="text" name="option_label[]" placeholder="e.g. Medium" aria-label="Size or option name">
        <input type="number" name="option_price[]" min="0.01" step="0.01" placeholder="0.00" aria-label="Option price">
        <button type="button" class="btn btn-remove btn-sm" data-remove-option aria-label="Remove option">&times;</button>
    </div>
</template>
<script>
(function () {
    var editor = document.querySelector('[data-menu-editor]');
    if (!editor) return;
    var singlePanel = editor.querySelector('[data-single-price-panel]');
    var optionsPanel = editor.querySelector('[data-options-panel]');
    var singlePrice = editor.querySelector('[data-single-price]');
    var optionRows = editor.querySelector('[data-option-rows]');
    var rowTemplate = document.getElementById('menu-option-row-template');

    function refreshPricingMode() {
        var selected = editor.querySelector('[data-pricing-mode]:checked');
        var hasOptions = selected && selected.value === 'options';
        singlePanel.hidden = hasOptions;
        optionsPanel.hidden = !hasOptions;
        singlePrice.required = !hasOptions;
        singlePrice.disabled = hasOptions;
        optionRows.querySelectorAll('input').forEach(function (input) { input.disabled = !hasOptions; });
        editor.querySelectorAll('[data-remove-option]').forEach(function (button) { button.disabled = !hasOptions; });
    }

    editor.querySelectorAll('[data-pricing-mode]').forEach(function (radio) {
        radio.addEventListener('change', refreshPricingMode);
    });
    editor.querySelector('[data-add-option]').addEventListener('click', function () {
        optionRows.appendChild(rowTemplate.content.cloneNode(true));
        refreshPricingMode();
        optionRows.lastElementChild.querySelector('input').focus();
    });
    editor.addEventListener('click', function (event) {
        var removeButton = event.target.closest('[data-remove-option]');
        if (!removeButton) return;
        var rows = optionRows.querySelectorAll('[data-option-row]');
        if (rows.length === 1) {
            rows[0].querySelectorAll('input').forEach(function (input) { input.value = ''; });
        } else {
            removeButton.closest('[data-option-row]').remove();
        }
    });
    editor.querySelector('[data-image-file]').addEventListener('change', function (event) {
        var file = event.target.files[0];
        var preview = editor.querySelector('[data-upload-preview]');
        if (!file) {
            preview.hidden = true;
            return;
        }
        preview.querySelector('[data-upload-preview-image]').src = URL.createObjectURL(file);
        preview.querySelector('[data-upload-file-name]').textContent = file.name;
        preview.hidden = false;
    });
    refreshPricingMode();
})();
</script>

<?php require __DIR__ . '/includes/admin_footer.php'; ?>
