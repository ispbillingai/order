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

$pageTitle = 'Settings';

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-cog"></i> Settings</h1>
</div>

<?php if (isset($_GET['success'])): ?>
    <div class="alert alert-success mb-lg" style="background: rgba(39,174,96,0.1); color: var(--success); padding: 16px; border-radius: 8px;">
        <i class="fas fa-check-circle"></i> Settings saved successfully!
    </div>
<?php endif; ?>

<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: var(--space-lg);">
    <!-- Workspace Settings -->
    <div class="card">
        <div class="card-header">
            <h2><i class="fas fa-building"></i> Restaurant Settings</h2>
        </div>
        <form method="POST">
            <div class="card-body">
                <input type="hidden" name="action" value="update_workspace">
                
                <div class="form-group">
                    <label class="form-label">Restaurant Name</label>
                    <input type="text" name="name" class="form-control" 
                           value="<?= htmlspecialchars($workspace['name']) ?>" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Cover Charge (per person)</label>
                    <input type="number" name="cover_charge" class="form-control" 
                           step="0.01" value="<?= $workspace['cover_charge'] ?>" required>
                </div>
            </div>
            <div class="card-footer">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Save Settings
                </button>
            </div>
        </form>
    </div>
    
    <!-- System Info -->
    <div class="card">
        <div class="card-header">
            <h2><i class="fas fa-info-circle"></i> System Information</h2>
        </div>
        <div class="card-body">
            <table class="data-table">
                <tr>
                    <td><strong>Application</strong></td>
                    <td><?= APP_NAME ?></td>
                </tr>
                <tr>
                    <td><strong>Version</strong></td>
                    <td><?= APP_VERSION ?></td>
                </tr>
                <tr>
                    <td><strong>PHP Version</strong></td>
                    <td><?= phpversion() ?></td>
                </tr>
                <tr>
                    <td><strong>Database</strong></td>
                    <td>MySQL</td>
                </tr>
                <tr>
                    <td><strong>Timezone</strong></td>
                    <td><?= date_default_timezone_get() ?></td>
                </tr>
                <tr>
                    <td><strong>Server Time</strong></td>
                    <td><?= date('Y-m-d H:i:s') ?></td>
                </tr>
            </table>
        </div>
    </div>
    
    <!-- Quick Stats -->
    <div class="card">
        <div class="card-header">
            <h2><i class="fas fa-database"></i> Database Stats</h2>
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
                    <td><strong>Active Users</strong></td>
                    <td><?= $userCount ?></td>
                </tr>
                <tr>
                    <td><strong>Rooms</strong></td>
                    <td><?= $roomCount ?></td>
                </tr>
                <tr>
                    <td><strong>Tables</strong></td>
                    <td><?= $tableCount ?></td>
                </tr>
                <tr>
                    <td><strong>Menu Items</strong></td>
                    <td><?= $menuCount ?></td>
                </tr>
                <tr>
                    <td><strong>Total Orders</strong></td>
                    <td><?= $orderCount ?></td>
                </tr>
            </table>
        </div>
    </div>
    
    <!-- Danger Zone -->
    <div class="card">
        <div class="card-header" style="background: rgba(231,76,60,0.1);">
            <h2 class="text-danger"><i class="fas fa-exclamation-triangle"></i> Maintenance</h2>
        </div>
        <div class="card-body">
            <p class="text-muted mb-md">
                These actions are irreversible. Use with caution.
            </p>
            
            <div class="d-flex gap-sm" style="flex-wrap: wrap;">
                <a href="/admin/orders.php" class="btn btn-outline">
                    <i class="fas fa-list"></i> View All Orders
                </a>
                <a href="/admin/activity.php" class="btn btn-outline">
                    <i class="fas fa-history"></i> Activity Log
                </a>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
