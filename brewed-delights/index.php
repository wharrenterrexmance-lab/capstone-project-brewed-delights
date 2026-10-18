<?php
require_once __DIR__ . '/includes/functions.php';

$pageTitle = 'Brewed Delights — Menu';

$stmt = $pdo->query(
    "SELECT * FROM menu WHERE is_available = 1 AND category NOT IN ('Add-ons', 'Syrups') ORDER BY category, name"
);
$items = $stmt->fetchAll();
$optionsByItem = [];
foreach ($pdo->query('SELECT option_id, item_id, label, price FROM menu_options ORDER BY option_id')->fetchAll() as $option) {
    $optionsByItem[$option['item_id']][] = $option;
}

$byCategory = [];
foreach ($items as $item) {
    $byCategory[$item['category']][] = $item;
}
$foodCategories = ['Fries', 'Nachos', 'Takoyaki'];
$menuGroups = ['Food' => [], 'Drinks' => []];
foreach ($byCategory as $category => $categoryItems) {
    $groupName = in_array($category, $foodCategories, true) ? 'Food' : 'Drinks';
    $menuGroups[$groupName][$category] = $categoryItems;
}
$menuGroups = array_filter($menuGroups);
$addonItems = $pdo->query("SELECT item_id, name, price FROM menu WHERE is_available = 1 AND category = 'Add-ons' ORDER BY name")->fetchAll();
$syrupItems = $pdo->query("SELECT item_id, name, price FROM menu WHERE is_available = 1 AND category = 'Syrups' ORDER BY name")->fetchAll();

// Homepage highlight rails. Featured is admin-curated (is_featured on the
// menu item). Best Sellers needs no curation at all — it's computed live
// from actual order history. (There's no "Best Deals" rail — loyalty
// points already cover discounts, so a separate sale-price mechanic
// would just be redundant.)
$featuredItems = $pdo->query(
    'SELECT m.* FROM menu m WHERE m.is_featured = 1 AND m.is_available = 1
        AND m.category NOT IN ("Add-ons", "Syrups")
     ORDER BY m.name'
)->fetchAll();
$featuredByGroup = ['Food' => [], 'Drinks' => []];
$featuredBadgeByItem = [];
$featuredIdsByGroup = ['Food' => [], 'Drinks' => []];
foreach ($featuredItems as $featuredItem) {
    $groupName = in_array($featuredItem['category'], $foodCategories, true) ? 'Food' : 'Drinks';
    if (count($featuredByGroup[$groupName]) < 4) {
        $featuredByGroup[$groupName][] = $featuredItem;
        $featuredBadgeByItem[$featuredItem['item_id']] = 'Featured';
        $featuredIdsByGroup[$groupName][(int) $featuredItem['item_id']] = true;
    }
}

$bestSellers = $pdo->query(
    "SELECT m.*, SUM(oi.quantity) AS sold_qty
     FROM order_items oi
     JOIN orders o ON o.order_id = oi.order_id
     JOIN menu m ON m.item_id = oi.item_id
         WHERE o.status != 'Cancelled' AND m.is_available = 1
             AND m.category NOT IN ('Add-ons', 'Syrups')
     GROUP BY m.item_id
     ORDER BY sold_qty DESC
     LIMIT 4"
)->fetchAll();

foreach ($bestSellers as $bestSeller) {
    $groupName = in_array($bestSeller['category'], $foodCategories, true) ? 'Food' : 'Drinks';
    $itemId = (int) $bestSeller['item_id'];
    if (!isset($featuredIdsByGroup[$groupName][$itemId]) && count($featuredByGroup[$groupName]) < 4) {
        $featuredByGroup[$groupName][] = $bestSeller;
        $featuredBadgeByItem[$itemId] = 'Popular';
        $featuredIdsByGroup[$groupName][$itemId] = true;
    }
}

foreach ($menuGroups as $groupName => $categories) {
    foreach ($categories as $categoryItems) {
        foreach ($categoryItems as $menuItem) {
            $itemId = (int) $menuItem['item_id'];
            if (!isset($featuredIdsByGroup[$groupName][$itemId]) && count($featuredByGroup[$groupName]) < 4) {
                $featuredByGroup[$groupName][] = $menuItem;
                $featuredBadgeByItem[$itemId] = 'Try next';
                $featuredIdsByGroup[$groupName][$itemId] = true;
            }
        }
    }
}

// Average rating trust signal — reuses existing feedback data, same
// reasoning as Best Sellers: no new collection, just a visible summary.
$ratingRow = $pdo->query('SELECT AVG(rating) AS avg_rating, COUNT(*) AS review_count FROM feedback')->fetch();
$avgRating = $ratingRow && $ratingRow['review_count'] > 0 ? round((float) $ratingRow['avg_rating'], 1) : null;
$reviewCount = $ratingRow ? (int) $ratingRow['review_count'] : 0;
$recentFeedback = $pdo->query(
    'SELECT f.rating, f.comments, f.created_at, u.name AS customer_name
     FROM feedback f
     JOIN users u ON u.user_id = f.user_id
     WHERE f.comments IS NOT NULL AND f.comments <> ""
     ORDER BY f.created_at DESC
     LIMIT 3'
)->fetchAll();

/** An item counts as "New" if added within the last 14 days. */
function is_new_item(array $item): bool {
    return !empty($item['created_at']) && strtotime($item['created_at']) >= strtotime('-14 days');
}

// Photo support: display a real photo if the file actually exists on disk,
// otherwise fall back gracefully — never a broken image icon.
$heroPhotoPath = 'assets/images/hero/hero-coffee.jpg';
$hasHeroPhoto = file_exists(__DIR__ . '/' . $heroPhotoPath);
$storyPhotoPath = 'assets/images/story/our-story.jpg';
$hasStoryPhoto = file_exists(__DIR__ . '/' . $storyPhotoPath);

/** Renders one highlight-rail card (used by Featured / Best Sellers). */
function render_highlight_card(array $item, ?string $badgeLabel, bool $isLoggedIn, array $options = []): void {
    $imagePath = menu_item_image_path($item);
    ?>
    <article class="menu-card highlight-card" data-item-id="<?= (int) $item['item_id'] ?>">
        <div class="menu-card-photo">
            <?php if ($badgeLabel): ?><span class="highlight-badge"><?= e($badgeLabel) ?></span><?php endif; ?>
            <?php if (is_new_item($item)): ?><span class="new-badge">New</span><?php endif; ?>
            <?php if ($imagePath): ?>
                <img src="<?= e($imagePath) ?>" alt="<?= e($item['name']) ?>" loading="lazy">
            <?php else: ?>
                <span class="menu-card-photo-fallback" aria-hidden="true"><?= e(mb_substr($item['name'], 0, 1)) ?></span>
            <?php endif; ?>
        </div>
        <div class="menu-card-body">
            <div class="menu-card-top">
                <h3><?= e($item['name']) ?></h3>
                <span class="price" data-item-price data-base-price="<?= e((string) $item['price']) ?>">
                    <?= $options ? 'From ' : '' ?><?= peso((float) $item['price']) ?>
                </span>
            </div>
            <p class="desc"><?= e($item['description']) ?></p>
            <?php if ($options): ?>
                <label class="highlight-option-label">Choose an option
                    <select class="menu-option-select" data-menu-option>
                        <?php foreach (group_menu_options($options) as $optionGroup => $groupOptions): ?>
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
            <?php if ($isLoggedIn): ?>
                <div class="qty-stepper" data-qty-stepper>
                    <button type="button" class="qty-decrease" aria-label="Decrease quantity">−</button>
                    <span class="qty-value">1</span>
                    <button type="button" class="qty-increase" aria-label="Increase quantity">+</button>
                </div>
                <button class="btn btn-add" data-add-to-cart="<?= (int) $item['item_id'] ?>">Add to cart</button>
            <?php else: ?>
                <a href="login.php" class="btn btn-add">Log in to order</a>
            <?php endif; ?>
        </div>
    </article>
    <?php
}

require __DIR__ . '/includes/header.php';
?>

<section class="menu-curated reveal">
    <div class="menu-curated-inner">
        <div class="menu-curated-copy">
            <p class="menu-kicker">WELCOME</p>
            <h1>A symphony of delicate<br>aromas and rich warmth.<br>Elevate your morning to<br>a gentle art form.</h1>
            <p class="menu-curated-sub">Gentle warmth. Refined flavor.</p>
            <a href="#menu" class="btn btn-primary btn-lg">Order Now</a>
        </div>
        <div class="menu-curated-art" aria-hidden="true">
            <div class="coffee-beans bean-a"></div>
            <div class="coffee-beans bean-b"></div>
            <div class="coffee-beans bean-c"></div>
            <div class="coffee-cup">
                <span class="steam steam-one"></span>
                <span class="steam steam-two"></span>
                <span class="steam steam-three"></span>
                <span class="cup-rim"></span>
            </div>
        </div>
    </div>
</section>

<?php if ($avgRating !== null): ?>
    <div class="rating-strip">
        <span class="rating-stars"><?= str_repeat('★', (int) round($avgRating)) . str_repeat('☆', 5 - (int) round($avgRating)) ?></span>
        <strong><?= $avgRating ?></strong> average, loved by our customers
        <span class="muted">(<?= $reviewCount ?> review<?= $reviewCount === 1 ? '' : 's' ?>)</span>
    </div>
<?php endif; ?>

<section class="menu-category-switcher reveal" aria-label="Choose menu type">
    <div class="menu-category-grid">
        <button type="button" class="menu-mode-card is-active" data-menu-view-group="Drinks" aria-pressed="true">
            <span class="menu-mode-icon" aria-hidden="true">
                <img src="assets/images/brand/drinks-category.png" alt="">
            </span>
            <span class="menu-mode-label">Drinks</span>
        </button>
        <button type="button" class="menu-mode-card" data-menu-view-group="Food" aria-pressed="false">
            <span class="menu-mode-icon" aria-hidden="true">
                <img src="assets/images/brand/foods-category.png" alt="">
            </span>
            <span class="menu-mode-label">Foods</span>
        </button>
    </div>
</section>

<?php if ($bestSellers): ?>
    <section class="highlight-section highlight-section-alt reveal">
        <div class="highlight-inner">
            <h2>Customers Favorites</h2>
            <div class="highlight-rail">
                <?php foreach ($bestSellers as $item): ?>
                    <?php render_highlight_card($item, 'Best seller', (bool) $user, $optionsByItem[$item['item_id']] ?? []); ?>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
<?php endif; ?>

<section id="menu" class="menu-section sidebar-menu-layout reveal">
    <?php if (count($menuGroups) > 1): ?>
        <nav class="sidebar-cats" aria-label="Jump to category">
            <h4>Menu</h4>
            <?php foreach (array_keys($menuGroups) as $i => $groupName): ?>
                <a href="#menu-group-<?= e(str_replace(' ', '-', strtolower($groupName))) ?>"
                   data-sidebar-group="<?= e($groupName) ?>"
                   class="sidebar-cat-link<?= $groupName === 'Drinks' ? ' active' : '' ?>"><?= e($groupName === 'Food' ? 'Foods' : $groupName) ?></a>
                <?php foreach (array_keys($menuGroups[$groupName]) as $category): ?>
                    <a href="#cat-<?= e(str_replace(' ', '-', strtolower($category))) ?>"
                       data-sidebar-group="<?= e($groupName) ?>"
                       class="sidebar-cat-link sidebar-subcat-link"><?= e($category) ?></a>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>

    <div class="menu-list">
        <label class="menu-search">Search menu
            <input type="search" data-menu-search placeholder="Search products or flavors">
        </label>
        <?php foreach ($menuGroups as $groupName => $categories): ?>
            <details class="menu-group" id="menu-group-<?= e(str_replace(' ', '-', strtolower($groupName))) ?>"
                     data-menu-group-name="<?= e($groupName) ?>"
                     data-menu-group <?= $groupName === array_key_first($menuGroups) ? 'open' : '' ?>>
                <summary><span><?= e($groupName === 'Food' ? 'Foods' : $groupName) ?></span><small><?= array_sum(array_map('count', $categories)) ?> items</small></summary>
                <div class="menu-group-content">
                <?php if (!empty($featuredByGroup[$groupName])): ?>
                    <section class="group-featured" aria-label="Featured <?= e($groupName === 'Food' ? 'foods' : 'drinks') ?>">
                        <div class="group-featured-heading">
                            <span class="group-featured-icon" aria-hidden="true">
                                <?php if ($groupName === 'Drinks'): ?>
                                    <img src="assets/images/brand/drinks-category.png" alt="">
                                <?php else: ?>
                                    <img src="assets/images/brand/foods-category.png" alt="">
                                <?php endif; ?>
                            </span>
                            <h2 id="featured-<?= e(strtolower($groupName)) ?>">Featured <?= e($groupName === 'Food' ? 'Foods' : 'Drinks') ?></h2>
                        </div>
                        <div class="highlight-rail" role="region" aria-labelledby="featured-<?= e(strtolower($groupName)) ?>" tabindex="0">
                            <?php foreach ($featuredByGroup[$groupName] as $item): ?>
                                <?php render_highlight_card($item, $featuredBadgeByItem[$item['item_id']] ?? 'Featured', (bool) $user, $optionsByItem[$item['item_id']] ?? []); ?>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endif; ?>
                <nav class="menu-child-categories" aria-label="<?= e($groupName) ?> categories">
                    <?php foreach (array_keys($categories) as $category): ?>
                        <a href="#cat-<?= e(str_replace(' ', '-', strtolower($category))) ?>"><?= e($category) ?></a>
                    <?php endforeach; ?>
                </nav>
            <?php foreach ($categories as $category => $catItems): ?>
            <div class="item-section" id="cat-<?= e(str_replace(' ', '-', strtolower($category))) ?>">
                <h2><?= e($category) ?></h2>
                <?php foreach ($catItems as $i => $item): ?>
                    <?php
                    $imagePath = menu_item_image_path($item);
                    $supportsExtras = menu_supports_extras($category);
                    ?>
                    <div class="item-row" data-item-id="<?= (int) $item['item_id'] ?>">
                        <span class="item-row-index"><?= str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) ?></span>
                        <div class="item-row-photo">
                            <?php if ($imagePath): ?>
                                <img src="<?= e($imagePath) ?>" alt="<?= e($item['name']) ?>" loading="lazy">
                            <?php else: ?>
                                <span class="menu-card-photo-fallback" aria-hidden="true"><?= e(mb_substr($item['name'], 0, 1)) ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="item-row-body">
                            <div class="item-row-top">
                                <h3><?= e($item['name']) ?><?php if (is_new_item($item)): ?> <span class="new-badge">New</span><?php endif; ?></h3>
                                <span class="price" data-item-price data-base-price="<?= e((string) $item['price']) ?>"><?= isset($optionsByItem[$item['item_id']]) ? 'From ' : '' ?><?= peso((float) $item['price']) ?></span>
                            </div>
                            <p class="desc"><?= e($item['description']) ?></p>
                            <?php if (!empty($optionsByItem[$item['item_id']])): ?>
                                <label class="menu-option-label">Choose an option
                                    <select class="menu-option-select" data-menu-option>
                                        <?php foreach (group_menu_options($optionsByItem[$item['item_id']]) as $optionGroup => $groupOptions): ?>
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
                            <?php if ($supportsExtras && ($addonItems || $syrupItems)): ?>
                                <details class="menu-addons">
                                    <summary>Add extras <span>(optional)</span></summary>
                                    <div class="menu-addon-list">
                                        <?php foreach ($addonItems as $addon): ?>
                                            <label><input type="checkbox" data-menu-addon value="<?= (int) $addon['item_id'] ?>" data-price="<?= e((string) $addon['price']) ?>">
                                                <?= e($addon['name']) ?> <span>+<?= peso((float) $addon['price']) ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                        <?php foreach ($syrupItems as $syrup): ?>
                                            <label><input type="checkbox" data-menu-addon value="<?= (int) $syrup['item_id'] ?>" data-price="<?= e((string) $syrup['price']) ?>">
                                                <?= e($syrup['name']) ?> <span>+<?= peso((float) $syrup['price']) ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </details>
                            <?php endif; ?>
                        </div>
                        <?php if ($user): ?>
                            <div class="qty-stepper" data-qty-stepper>
                                <button type="button" class="qty-decrease" aria-label="Decrease quantity">−</button>
                                <span class="qty-value">1</span>
                                <button type="button" class="qty-increase" aria-label="Increase quantity">+</button>
                            </div>
                            <button class="btn btn-add item-row-btn" data-add-to-cart="<?= (int) $item['item_id'] ?>">Add</button>
                        <?php else: ?>
                            <a href="login.php" class="btn btn-add item-row-btn">Log in</a>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
                    <?php endforeach; ?>
                        </div>
                    </details>
                <?php endforeach; ?>
    </div>

    <p class="empty-state" data-menu-no-results hidden>No menu items match that search.</p>

    <?php if (empty($items)): ?>
        <p class="empty-state">The menu is being updated — check back shortly.</p>
    <?php endif; ?>
</section>

<section class="homepage-feedback-results reveal">
    <div class="homepage-feedback-results-inner">
        <div class="homepage-feedback-heading">
            <div>
                <p class="story-kicker">From our customers</p>
                <h2>What people are saying</h2>
            </div>
            <?php if ($user): ?><a href="feedback.php" class="btn btn-primary">Share feedback</a><?php endif; ?>
        </div>

        <?php if ($recentFeedback): ?>
            <div class="homepage-feedback-list">
                <?php foreach ($recentFeedback as $feedback): ?>
                    <article class="homepage-feedback-card">
                        <div class="homepage-feedback-card-top">
                            <strong><?= e($feedback['customer_name']) ?></strong>
                            <span class="stars" aria-label="<?= (int) $feedback['rating'] ?> out of 5 stars">
                                <?= str_repeat('★', (int) $feedback['rating']) . str_repeat('☆', 5 - (int) $feedback['rating']) ?>
                            </span>
                        </div>
                        <p><?= e($feedback['comments']) ?></p>
                        <time datetime="<?= e($feedback['created_at']) ?>"><?= date('M j, Y', strtotime($feedback['created_at'])) ?></time>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p class="empty-state">Customer feedback will appear here after the first review is submitted.</p>
        <?php endif; ?>
    </div>
</section>

<a href="#menu" class="floating-order-btn" data-floating-order>Order now</a>

<div class="toast" id="toast" role="status" aria-live="polite"></div>

<?php require __DIR__ . '/includes/footer.php'; ?>
