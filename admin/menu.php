<?php
/**
 * Admin Menu Management
 * Restaurant POS System
 */

require_once __DIR__ . '/../includes/functions.php';
requireRole(['admin']);

$pdo = getDBConnection();

/**
 * Save an uploaded menu image to assets/uploads/menu and return its web path,
 * or null if no valid image was uploaded. Accepts JPG/PNG/WebP/GIF up to 5MB.
 */
function saveMenuImage(string $field): ?string
{
    if (empty($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return null;
    }
    $f = $_FILES[$field];
    if ($f['size'] > 5 * 1024 * 1024) {
        return null;
    }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $f['tmp_name']);
    finfo_close($finfo);
    $extMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    if (!isset($extMap[$mime])) {
        return null;
    }
    $dir = __DIR__ . '/../assets/uploads/menu';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $name = 'item_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $extMap[$mime];
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) {
        return null;
    }
    return '/assets/uploads/menu/' . $name;
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_category') {
        $stmt = $pdo->prepare("INSERT INTO menu_categories (name, description, sort_order, allow_composition, icon, color) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $_POST['name'],
            $_POST['description'],
            $_POST['sort_order'] ?? 0,
            isset($_POST['allow_composition']) ? 1 : 0,
            $_POST['icon'] ?? 'utensils',
            $_POST['color'] ?? '#e74c3c'
        ]);
        header('Location: /admin/menu.php?success=category_added');
        exit;
    }
    
    if ($action === 'add_item') {
        $imageUrl = saveMenuImage('image');
        $stmt = $pdo->prepare("INSERT INTO menu_items (category_id, name, description, base_price, preparation_time, image_url) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $_POST['category_id'],
            $_POST['name'],
            $_POST['description'],
            $_POST['base_price'],
            $_POST['preparation_time'] ?? 15,
            $imageUrl
        ]);
        header('Location: /admin/menu.php?category=' . (int) $_POST['category_id'] . '&success=item_added');
        exit;
    }

    if ($action === 'upload_image') {
        $itemId   = (int) ($_POST['item_id'] ?? 0);
        $catId    = (int) ($_POST['category_id'] ?? 0);
        $imageUrl = saveMenuImage('image');
        if ($itemId && $imageUrl) {
            $stmt = $pdo->prepare("UPDATE menu_items SET image_url = ? WHERE id = ?");
            $stmt->execute([$imageUrl, $itemId]);
            header('Location: /admin/menu.php?category=' . $catId . '&success=photo_updated');
        } else {
            header('Location: /admin/menu.php?category=' . $catId . '&error=photo_failed');
        }
        exit;
    }

    if ($action === 'delete_item') {
        $stmt = $pdo->prepare("UPDATE menu_items SET active = 0 WHERE id = ?");
        $stmt->execute([$_POST['item_id']]);
        header('Location: /admin/menu.php?success=item_deleted');
        exit;
    }
    
    if ($action === 'add_component') {
        $stmt = $pdo->prepare("INSERT INTO menu_item_components (menu_item_id, component_name, is_default, extra_price, removable) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([
            $_POST['menu_item_id'],
            $_POST['component_name'],
            isset($_POST['is_default']) ? 1 : 0,
            $_POST['extra_price'] ?? 0,
            isset($_POST['removable']) ? 1 : 0
        ]);
        header('Location: /admin/menu.php?item=' . $_POST['menu_item_id'] . '&success=component_added');
        exit;
    }
}

$categories = getMenuCategories();
$selectedCategoryId = $_GET['category'] ?? ($categories[0]['id'] ?? null);
$menuItems = $selectedCategoryId ? getMenuItemsByCategory($selectedCategoryId) : [];

// For component editing
$editItemId = $_GET['item'] ?? null;
$editItem = null;
$itemComponents = [];
if ($editItemId) {
    $stmt = $pdo->prepare("SELECT * FROM menu_items WHERE id = ?");
    $stmt->execute([$editItemId]);
    $editItem = $stmt->fetch();
    $itemComponents = getMenuItemComponents($editItemId);
}

$pageTitle = t('menu_management');

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-utensils"></i> <?= te('menu_management') ?></h1>
    <div class="d-flex gap-sm">
        <button class="btn btn-primary" onclick="openModal('addCategoryModal')">
            <i class="fas fa-folder-plus"></i> <?= te('menu_add_category') ?>
        </button>
        <button class="btn btn-success" onclick="openModal('addItemModal')">
            <i class="fas fa-plus"></i> <?= te('menu_add_item') ?>
        </button>
    </div>
</div>

<?php if (isset($_GET['success'])): ?>
    <div class="alert alert-success mb-lg" style="background: rgba(39,174,96,0.1); color: var(--success); padding: 16px; border-radius: 8px;">
        <i class="fas fa-check-circle"></i>
        <?php
        switch ($_GET['success']) {
            case 'category_added': echo te('msg_category_added'); break;
            case 'item_added': echo te('msg_item_added'); break;
            case 'item_deleted': echo te('msg_item_deleted'); break;
            case 'component_added': echo te('msg_component_added'); break;
            case 'photo_updated': echo te('msg_photo_updated'); break;
        }
        ?>
    </div>
<?php endif; ?>

<?php if (isset($_GET['error']) && $_GET['error'] === 'photo_failed'): ?>
    <div class="alert alert-danger mb-lg" style="background: rgba(231,76,60,0.1); color: var(--danger); padding: 16px; border-radius: 8px;">
        <i class="fas fa-exclamation-circle"></i> <?= te('err_photo_failed') ?>
    </div>
<?php endif; ?>

<div style="display: grid; grid-template-columns: 250px 1fr; gap: var(--space-lg);">
    <!-- Categories Sidebar -->
    <div class="card">
        <div class="card-header">
            <h2><?= te('categories') ?></h2>
        </div>
        <div style="padding: var(--space-sm);">
            <?php foreach ($categories as $cat): ?>
                <a href="?category=<?= $cat['id'] ?>" 
                   class="d-flex align-center gap-sm" 
                   style="padding: 12px; border-radius: 8px; text-decoration: none; color: inherit; <?= $cat['id'] == $selectedCategoryId ? 'background: var(--primary); color: white;' : '' ?>">
                    <i class="fas fa-<?= htmlspecialchars($cat['icon'] ?: 'utensils') ?>"></i>
                    <span style="flex: 1;"><?= htmlspecialchars($cat['name']) ?></span>
                    <span class="badge" style="<?= $cat['id'] == $selectedCategoryId ? 'background: rgba(255,255,255,0.2); color: white;' : '' ?>">
                        <?= count(getMenuItemsByCategory($cat['id'])) ?>
                    </span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
    
    <!-- Menu Items -->
    <div class="card">
        <div class="card-header">
            <h2><?= te('menu_items') ?></h2>
        </div>
        <?php if (empty($menuItems)): ?>
            <div class="card-body text-center" style="padding: 60px;">
                <i class="fas fa-pizza-slice" style="font-size: 3rem; color: var(--text-secondary); margin-bottom: 16px;"></i>
                <p class="text-muted"><?= te('no_items_cat') ?></p>
                <button class="btn btn-primary mt-md" onclick="openModal('addItemModal')">
                    <i class="fas fa-plus"></i> <?= te('add_first_item') ?>
                </button>
            </div>
        <?php else: ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th><?= te('name') ?></th>
                        <th><?= te('description') ?></th>
                        <th><?= te('price') ?></th>
                        <th><?= te('prep_time') ?></th>
                        <th><?= te('actions') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($menuItems as $item): ?>
                        <tr>
                            <td>
                                <div class="d-flex align-center gap-sm">
                                    <?php if (!empty($item['image_url'])): ?>
                                        <img src="<?= htmlspecialchars($item['image_url']) ?>" alt="" style="width:42px;height:42px;object-fit:cover;border-radius:6px;">
                                    <?php else: ?>
                                        <span style="width:42px;height:42px;border-radius:6px;background:var(--bg-light,#f3f4f6);display:flex;align-items:center;justify-content:center;color:var(--text-secondary);"><i class="fas fa-image"></i></span>
                                    <?php endif; ?>
                                    <strong><?= htmlspecialchars($item['name']) ?></strong>
                                </div>
                            </td>
                            <td class="text-muted"><?= htmlspecialchars(substr($item['description'] ?? '', 0, 50)) ?>...</td>
                            <td><strong class="text-primary"><?= formatCurrency($item['base_price']) ?></strong></td>
                            <td><?= $item['preparation_time'] ?> <?= te('minutes_short') ?></td>
                            <td>
                                <div class="d-flex gap-sm">
                                    <button type="button" class="btn btn-sm btn-outline" onclick="openPhotoModal(<?= $item['id'] ?>, <?= htmlspecialchars(json_encode($item['name']), ENT_QUOTES) ?>)">
                                        <i class="fas fa-image"></i> <?= te('photo') ?>
                                    </button>
                                    <a href="?category=<?= $selectedCategoryId ?>&item=<?= $item['id'] ?>" class="btn btn-sm btn-outline">
                                        <i class="fas fa-list"></i> <?= te('components') ?>
                                    </a>
                                    <form method="POST" style="display: inline;" onsubmit="return confirm('<?= te('remove_item_confirm') ?>');">
                                        <input type="hidden" name="action" value="delete_item">
                                        <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-danger">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php if ($editItem): ?>
<!-- Components Editor -->
<div class="card mt-lg">
    <div class="card-header">
        <h2><i class="fas fa-puzzle-piece"></i> <?= te('components_for') ?> <?= htmlspecialchars($editItem['name']) ?></h2>
        <a href="?category=<?= $selectedCategoryId ?>" class="btn btn-sm btn-outline">
            <i class="fas fa-times"></i> <?= te('close') ?>
        </a>
    </div>
    <div class="card-body">
        <form method="POST" class="form-row mb-lg">
            <input type="hidden" name="action" value="add_component">
            <input type="hidden" name="menu_item_id" value="<?= $editItem['id'] ?>">
            
            <div class="form-group">
                <label class="form-label"><?= te('component_name') ?></label>
                <input type="text" name="component_name" class="form-control" required placeholder="<?= te('component_name') ?>">
            </div>

            <div class="form-group">
                <label class="form-label"><?= te('extra_price') ?></label>
                <input type="number" name="extra_price" class="form-control" step="0.01" value="0">
            </div>

            <div class="form-group">
                <label class="form-label">&nbsp;</label>
                <div class="d-flex gap-md">
                    <label><input type="checkbox" name="is_default" checked> <?= te('default_label') ?></label>
                    <label><input type="checkbox" name="removable" checked> <?= te('removable') ?></label>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">&nbsp;</label>
                <button type="submit" class="btn btn-success">
                    <i class="fas fa-plus"></i> <?= te('add') ?>
                </button>
            </div>
        </form>

        <?php if (empty($itemComponents)): ?>
            <p class="text-muted"><?= te('no_components') ?></p>
        <?php else: ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th><?= te('component') ?></th>
                        <th><?= te('default_label') ?></th>
                        <th><?= te('extra_price') ?></th>
                        <th><?= te('removable') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($itemComponents as $comp): ?>
                        <tr>
                            <td><?= htmlspecialchars($comp['component_name']) ?></td>
                            <td>
                                <?php if ($comp['is_default']): ?>
                                    <span class="badge badge-success"><?= te('yes') ?></span>
                                <?php else: ?>
                                    <span class="badge badge-warning"><?= te('addon') ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?= $comp['extra_price'] > 0 ? formatCurrency($comp['extra_price']) : '-' ?></td>
                            <td><?= $comp['removable'] ? te('yes') : te('no') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Add Category Modal -->
<div class="modal-overlay" id="addCategoryModal">
    <div class="modal">
        <div class="modal-header">
            <h3><?= te('menu_add_category') ?></h3>
            <button class="modal-close">&times;</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="add_category">

                <div class="form-group">
                    <label class="form-label"><?= te('category_name') ?></label>
                    <input type="text" name="name" class="form-control" required>
                </div>

                <div class="form-group">
                    <label class="form-label"><?= te('description') ?></label>
                    <textarea name="description" class="form-control" rows="2"></textarea>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label"><?= te('icon_fa') ?></label>
                        <input type="text" name="icon" class="form-control" value="utensils" placeholder="e.g., pizza-slice">
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?= te('sort_order') ?></label>
                        <input type="number" name="sort_order" class="form-control" value="0">
                    </div>
                </div>

                <div class="form-group">
                    <label>
                        <input type="checkbox" name="allow_composition" checked>
                        <?= te('allow_composition') ?>
                    </label>
                    <small class="text-muted d-block"><?= te('uncheck_simple') ?></small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('addCategoryModal')"><?= te('cancel') ?></button>
                <button type="submit" class="btn btn-primary"><?= te('menu_add_category') ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Add Item Modal -->
<div class="modal-overlay" id="addItemModal">
    <div class="modal">
        <div class="modal-header">
            <h3><?= te('add_menu_item') ?></h3>
            <button class="modal-close">&times;</button>
        </div>
        <form method="POST" enctype="multipart/form-data">
            <div class="modal-body">
                <input type="hidden" name="action" value="add_item">

                <div class="form-group">
                    <label class="form-label"><?= te('category') ?></label>
                    <select name="category_id" class="form-control" required>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat['id'] ?>" <?= $cat['id'] == $selectedCategoryId ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cat['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label"><?= te('item_name') ?></label>
                    <input type="text" name="name" class="form-control" required>
                </div>

                <div class="form-group">
                    <label class="form-label"><?= te('description') ?></label>
                    <textarea name="description" class="form-control" rows="2"></textarea>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label"><?= te('price') ?></label>
                        <input type="number" name="base_price" class="form-control" step="0.01" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?= te('prep_time_min') ?></label>
                        <input type="number" name="preparation_time" class="form-control" value="15">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label"><?= te('photo_optional') ?></label>
                    <input type="file" name="image" class="form-control" accept="image/jpeg,image/png,image/webp,image/gif">
                    <small class="text-muted d-block"><?= te('photo_hint') ?></small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('addItemModal')"><?= te('cancel') ?></button>
                <button type="submit" class="btn btn-success"><?= te('menu_add_item') ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Upload Photo Modal -->
<div class="modal-overlay" id="photoModal">
    <div class="modal">
        <div class="modal-header">
            <h3><?= te('item_photo') ?>: <span id="photoItemName"></span></h3>
            <button class="modal-close">&times;</button>
        </div>
        <form method="POST" enctype="multipart/form-data">
            <div class="modal-body">
                <input type="hidden" name="action" value="upload_image">
                <input type="hidden" name="item_id" id="photoItemId">
                <input type="hidden" name="category_id" value="<?= (int) $selectedCategoryId ?>">
                <div class="form-group">
                    <label class="form-label"><?= te('choose_photo') ?></label>
                    <input type="file" name="image" class="form-control" accept="image/jpeg,image/png,image/webp,image/gif" required>
                    <small class="text-muted d-block"><?= te('photo_hint') ?></small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('photoModal')"><?= te('cancel') ?></button>
                <button type="submit" class="btn btn-primary"><?= te('upload_photo') ?></button>
            </div>
        </form>
    </div>
</div>

<script>
function openPhotoModal(itemId, itemName) {
    document.getElementById('photoItemId').value = itemId;
    document.getElementById('photoItemName').textContent = itemName;
    openModal('photoModal');
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
