<?php
/**
 * Admin Menu Management
 * Restaurant POS System
 */

require_once __DIR__ . '/../includes/functions.php';
requireRole(['admin']);

$pdo = getDBConnection();

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
        $stmt = $pdo->prepare("INSERT INTO menu_items (category_id, name, description, base_price, preparation_time) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([
            $_POST['category_id'],
            $_POST['name'],
            $_POST['description'],
            $_POST['base_price'],
            $_POST['preparation_time'] ?? 15
        ]);
        header('Location: /admin/menu.php?success=item_added');
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

$pageTitle = 'Menu Management';

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-utensils"></i> Menu Management</h1>
    <div class="d-flex gap-sm">
        <button class="btn btn-primary" onclick="openModal('addCategoryModal')">
            <i class="fas fa-folder-plus"></i> Add Category
        </button>
        <button class="btn btn-success" onclick="openModal('addItemModal')">
            <i class="fas fa-plus"></i> Add Item
        </button>
    </div>
</div>

<?php if (isset($_GET['success'])): ?>
    <div class="alert alert-success mb-lg" style="background: rgba(39,174,96,0.1); color: var(--success); padding: 16px; border-radius: 8px;">
        <i class="fas fa-check-circle"></i>
        <?php
        switch ($_GET['success']) {
            case 'category_added': echo 'Category added successfully!'; break;
            case 'item_added': echo 'Menu item added successfully!'; break;
            case 'item_deleted': echo 'Menu item removed!'; break;
            case 'component_added': echo 'Component added successfully!'; break;
        }
        ?>
    </div>
<?php endif; ?>

<div style="display: grid; grid-template-columns: 250px 1fr; gap: var(--space-lg);">
    <!-- Categories Sidebar -->
    <div class="card">
        <div class="card-header">
            <h2>Categories</h2>
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
            <h2>Menu Items</h2>
        </div>
        <?php if (empty($menuItems)): ?>
            <div class="card-body text-center" style="padding: 60px;">
                <i class="fas fa-pizza-slice" style="font-size: 3rem; color: var(--text-secondary); margin-bottom: 16px;"></i>
                <p class="text-muted">No items in this category.</p>
                <button class="btn btn-primary mt-md" onclick="openModal('addItemModal')">
                    <i class="fas fa-plus"></i> Add First Item
                </button>
            </div>
        <?php else: ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Description</th>
                        <th>Price</th>
                        <th>Prep Time</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($menuItems as $item): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($item['name']) ?></strong></td>
                            <td class="text-muted"><?= htmlspecialchars(substr($item['description'] ?? '', 0, 50)) ?>...</td>
                            <td><strong class="text-primary"><?= formatCurrency($item['base_price']) ?></strong></td>
                            <td><?= $item['preparation_time'] ?> min</td>
                            <td>
                                <div class="d-flex gap-sm">
                                    <a href="?category=<?= $selectedCategoryId ?>&item=<?= $item['id'] ?>" class="btn btn-sm btn-outline">
                                        <i class="fas fa-list"></i> Components
                                    </a>
                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Remove this item?');">
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
        <h2><i class="fas fa-puzzle-piece"></i> Components for: <?= htmlspecialchars($editItem['name']) ?></h2>
        <a href="?category=<?= $selectedCategoryId ?>" class="btn btn-sm btn-outline">
            <i class="fas fa-times"></i> Close
        </a>
    </div>
    <div class="card-body">
        <form method="POST" class="form-row mb-lg">
            <input type="hidden" name="action" value="add_component">
            <input type="hidden" name="menu_item_id" value="<?= $editItem['id'] ?>">
            
            <div class="form-group">
                <label class="form-label">Component Name</label>
                <input type="text" name="component_name" class="form-control" required placeholder="e.g., Extra Cheese">
            </div>
            
            <div class="form-group">
                <label class="form-label">Extra Price</label>
                <input type="number" name="extra_price" class="form-control" step="0.01" value="0">
            </div>
            
            <div class="form-group">
                <label class="form-label">&nbsp;</label>
                <div class="d-flex gap-md">
                    <label><input type="checkbox" name="is_default" checked> Default</label>
                    <label><input type="checkbox" name="removable" checked> Removable</label>
                </div>
            </div>
            
            <div class="form-group">
                <label class="form-label">&nbsp;</label>
                <button type="submit" class="btn btn-success">
                    <i class="fas fa-plus"></i> Add
                </button>
            </div>
        </form>
        
        <?php if (empty($itemComponents)): ?>
            <p class="text-muted">No components yet. Add components above.</p>
        <?php else: ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Component</th>
                        <th>Default</th>
                        <th>Extra Price</th>
                        <th>Removable</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($itemComponents as $comp): ?>
                        <tr>
                            <td><?= htmlspecialchars($comp['component_name']) ?></td>
                            <td>
                                <?php if ($comp['is_default']): ?>
                                    <span class="badge badge-success">Yes</span>
                                <?php else: ?>
                                    <span class="badge badge-warning">Add-on</span>
                                <?php endif; ?>
                            </td>
                            <td><?= $comp['extra_price'] > 0 ? formatCurrency($comp['extra_price']) : '-' ?></td>
                            <td><?= $comp['removable'] ? 'Yes' : 'No' ?></td>
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
            <h3>Add Category</h3>
            <button class="modal-close">&times;</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="add_category">
                
                <div class="form-group">
                    <label class="form-label">Category Name</label>
                    <input type="text" name="name" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-control" rows="2"></textarea>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Icon (FontAwesome)</label>
                        <input type="text" name="icon" class="form-control" value="utensils" placeholder="e.g., pizza-slice">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Sort Order</label>
                        <input type="number" name="sort_order" class="form-control" value="0">
                    </div>
                </div>
                
                <div class="form-group">
                    <label>
                        <input type="checkbox" name="allow_composition" checked>
                        Allow item composition (ingredients)
                    </label>
                    <small class="text-muted d-block">Uncheck for simple items like drinks</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('addCategoryModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Add Category</button>
            </div>
        </form>
    </div>
</div>

<!-- Add Item Modal -->
<div class="modal-overlay" id="addItemModal">
    <div class="modal">
        <div class="modal-header">
            <h3>Add Menu Item</h3>
            <button class="modal-close">&times;</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="add_item">
                
                <div class="form-group">
                    <label class="form-label">Category</label>
                    <select name="category_id" class="form-control" required>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat['id'] ?>" <?= $cat['id'] == $selectedCategoryId ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cat['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Item Name</label>
                    <input type="text" name="name" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-control" rows="2"></textarea>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Price</label>
                        <input type="number" name="base_price" class="form-control" step="0.01" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Prep Time (min)</label>
                        <input type="number" name="preparation_time" class="form-control" value="15">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('addItemModal')">Cancel</button>
                <button type="submit" class="btn btn-success">Add Item</button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
