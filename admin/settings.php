<?php
/**
 * Admin Settings
 * Restaurant POS System
 */

require_once __DIR__ . '/../includes/functions.php';
requireRole(['admin']);

$pdo = getDBConnection();

// Get workspace settings
$stmt = $pdo->query("SELECT * FROM workspaces LIMIT 1");
$workspace = $stmt->fetch();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_workspace') {
        $stmt = $pdo->prepare("
            UPDATE workspaces 
            SET name = ?, cover_charge = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $_POST['name'],
            $_POST['cover_charge'],
            $workspace['id']
        ]);
        
        header('Location: /admin/settings.php?success=saved');
        exit;
    }
}

$pageTitle = t('settings');

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-cog"></i> <?= te('settings') ?></h1>
</div>

<?php if (isset($_GET['success'])): ?>
    <div class="alert alert-success mb-lg" style="background: rgba(39,174,96,0.1); color: var(--success); padding: 16px; border-radius: 8px;">
        <i class="fas fa-check-circle"></i> <?= te('msg_settings_saved') ?>
    </div>
<?php endif; ?>

<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: var(--space-lg);">
    <!-- Workspace Settings -->
    <div class="card">
        <div class="card-header">
            <h2><i class="fas fa-building"></i> <?= te('restaurant_settings') ?></h2>
        </div>
        <form method="POST">
            <div class="card-body">
                <input type="hidden" name="action" value="update_workspace">

                <div class="form-group">
                    <label class="form-label"><?= te('restaurant_name') ?></label>
                    <input type="text" name="name" class="form-control"
                           value="<?= htmlspecialchars($workspace['name']) ?>" required>
                </div>

                <div class="form-group">
                    <label class="form-label"><?= te('cover_charge_per') ?></label>
                    <input type="number" name="cover_charge" class="form-control"
                           step="0.01" value="<?= $workspace['cover_charge'] ?>" required>
                </div>
            </div>
            <div class="card-footer">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> <?= te('save_settings') ?>
                </button>
            </div>
        </form>
    </div>
    
    <!-- System Info -->
    <div class="card">
        <div class="card-header">
            <h2><i class="fas fa-info-circle"></i> <?= te('system_info') ?></h2>
        </div>
        <div class="card-body">
            <table class="data-table">
                <tr>
                    <td><strong><?= te('application') ?></strong></td>
                    <td><?= APP_NAME ?></td>
                </tr>
                <tr>
                    <td><strong><?= te('version') ?></strong></td>
                    <td><?= APP_VERSION ?></td>
                </tr>
                <tr>
                    <td><strong><?= te('php_version') ?></strong></td>
                    <td><?= phpversion() ?></td>
                </tr>
                <tr>
                    <td><strong><?= te('database') ?></strong></td>
                    <td>MySQL</td>
                </tr>
                <tr>
                    <td><strong><?= te('timezone') ?></strong></td>
                    <td><?= date_default_timezone_get() ?></td>
                </tr>
                <tr>
                    <td><strong><?= te('server_time') ?></strong></td>
                    <td><?= date('Y-m-d H:i:s') ?></td>
                </tr>
            </table>
        </div>
    </div>
    
    <!-- Quick Stats -->
    <div class="card">
        <div class="card-header">
            <h2><i class="fas fa-database"></i> <?= te('database_stats') ?></h2>
        </div>
        <div class="card-body">
            <?php
            $stmt = $pdo->query("SELECT COUNT(*) as c FROM users WHERE active = 1");
            $userCount = $stmt->fetch()['c'];
            
            $stmt = $pdo->query("SELECT COUNT(*) as c FROM menu_items WHERE active = 1");
            $menuCount = $stmt->fetch()['c'];
            
            $stmt = $pdo->query("SELECT COUNT(*) as c FROM tables_restaurant");
            $tableCount = $stmt->fetch()['c'];
            
            $stmt = $pdo->query("SELECT COUNT(*) as c FROM orders");
            $orderCount = $stmt->fetch()['c'];
            
            $stmt = $pdo->query("SELECT COUNT(*) as c FROM rooms WHERE active = 1");
            $roomCount = $stmt->fetch()['c'];
            ?>
            <table class="data-table">
                <tr>
                    <td><strong><?= te('active_users') ?></strong></td>
                    <td><?= $userCount ?></td>
                </tr>
                <tr>
                    <td><strong><?= te('rooms') ?></strong></td>
                    <td><?= $roomCount ?></td>
                </tr>
                <tr>
                    <td><strong><?= te('tables') ?></strong></td>
                    <td><?= $tableCount ?></td>
                </tr>
                <tr>
                    <td><strong><?= te('menu_items') ?></strong></td>
                    <td><?= $menuCount ?></td>
                </tr>
                <tr>
                    <td><strong><?= te('total_orders') ?></strong></td>
                    <td><?= $orderCount ?></td>
                </tr>
            </table>
        </div>
    </div>
    
    <!-- Danger Zone -->
    <div class="card">
        <div class="card-header" style="background: rgba(231,76,60,0.1);">
            <h2 class="text-danger"><i class="fas fa-exclamation-triangle"></i> <?= te('maintenance') ?></h2>
        </div>
        <div class="card-body">
            <p class="text-muted mb-md">
                <?= te('irreversible_warning') ?>
            </p>

            <div class="d-flex gap-sm" style="flex-wrap: wrap;">
                <a href="/admin/orders.php" class="btn btn-outline">
                    <i class="fas fa-list"></i> <?= te('view_all_orders') ?>
                </a>
                <a href="/admin/activity.php" class="btn btn-outline">
                    <i class="fas fa-history"></i> <?= te('activity_log') ?>
                </a>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
