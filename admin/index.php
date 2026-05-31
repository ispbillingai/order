<?php
/**
 * Admin Dashboard
 * Restaurant POS System
 */

require_once __DIR__ . '/../includes/functions.php';
requireRole(['admin']);

$pdo = getDBConnection();

// Get statistics
$stats = [];

// Today's orders
$stmt = $pdo->query("SELECT COUNT(*) as count, COALESCE(SUM(total), 0) as total FROM orders WHERE DATE(opened_at) = CURDATE()");
$stats['today'] = $stmt->fetch();

// Active orders
$stmt = $pdo->query("SELECT COUNT(*) as count FROM orders WHERE status NOT IN ('paid', 'cancelled')");
$stats['active_orders'] = $stmt->fetch()['count'];

// Tables
$stmt = $pdo->query("SELECT COUNT(*) as total, SUM(CASE WHEN status != 'free' THEN 1 ELSE 0 END) as occupied FROM tables_restaurant");
$stats['tables'] = $stmt->fetch();

// Users
$stmt = $pdo->query("SELECT COUNT(*) as count FROM users WHERE active = 1");
$stats['users'] = $stmt->fetch()['count'];

// Menu items
$stmt = $pdo->query("SELECT COUNT(*) as count FROM menu_items WHERE active = 1");
$stats['menu_items'] = $stmt->fetch()['count'];

// Recent orders
$stmt = $pdo->query("
    SELECT o.*, t.table_number, u.full_name as waiter_name
    FROM orders o
    JOIN tables_restaurant t ON o.table_id = t.id
    JOIN users u ON o.waiter_id = u.id
    ORDER BY o.created_at DESC
    LIMIT 10
");
$recentOrders = $stmt->fetchAll();

$pageTitle = 'Admin Dashboard';

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-tachometer-alt"></i> Dashboard</h1>
</div>

<!-- Stats -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon primary">
            <i class="fas fa-receipt"></i>
        </div>
        <div>
            <div class="stat-value"><?= $stats['today']['count'] ?></div>
            <div class="stat-label">Orders Today</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon success">
            <i class="fas fa-dollar-sign"></i>
        </div>
        <div>
            <div class="stat-value"><?= formatCurrency($stats['today']['total']) ?></div>
            <div class="stat-label">Revenue Today</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon warning">
            <i class="fas fa-clipboard-list"></i>
        </div>
        <div>
            <div class="stat-value"><?= $stats['active_orders'] ?></div>
            <div class="stat-label">Active Orders</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon info">
            <i class="fas fa-chair"></i>
        </div>
        <div>
            <div class="stat-value"><?= $stats['tables']['occupied'] ?? 0 ?>/<?= $stats['tables']['total'] ?? 0 ?></div>
            <div class="stat-label">Tables Occupied</div>
        </div>
    </div>
</div>

<!-- Quick Links -->
<div class="card mb-lg">
    <div class="card-header">
        <h2>Quick Actions</h2>
    </div>
    <div class="card-body">
        <div class="d-flex gap-md" style="flex-wrap: wrap;">
            <a href="/admin/rooms.php" class="btn btn-primary">
                <i class="fas fa-door-open"></i> Manage Rooms & Tables
            </a>
            <a href="/admin/menu.php" class="btn btn-secondary">
                <i class="fas fa-utensils"></i> Menu Management
            </a>
            <a href="/admin/users.php" class="btn btn-outline">
                <i class="fas fa-users"></i> Users (<?= $stats['users'] ?>)
            </a>
            <a href="/admin/reports.php" class="btn btn-outline">
                <i class="fas fa-chart-bar"></i> Reports
            </a>
            <a href="/admin/settings.php" class="btn btn-outline">
                <i class="fas fa-cog"></i> Settings
            </a>
        </div>
    </div>
</div>

<!-- Recent Orders -->
<div class="card">
    <div class="card-header">
        <h2>Recent Orders</h2>
        <a href="/admin/orders.php" class="btn btn-sm btn-outline">View All</a>
    </div>
    <table class="data-table">
        <thead>
            <tr>
                <th>Order #</th>
                <th>Table</th>
                <th>Waiter</th>
                <th>Total</th>
                <th>Status</th>
                <th>Time</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($recentOrders as $order): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($order['order_number']) ?></strong></td>
                    <td><?= htmlspecialchars($order['table_number']) ?></td>
                    <td><?= htmlspecialchars($order['waiter_name']) ?></td>
                    <td><strong><?= formatCurrency($order['total']) ?></strong></td>
                    <td>
                        <span class="badge badge-<?= 
                            $order['status'] === 'paid' ? 'success' : 
                            ($order['status'] === 'cancelled' ? 'danger' : 
                            ($order['status'] === 'bill_requested' ? 'warning' : 'info')) 
                        ?>">
                            <?= ucfirst(str_replace('_', ' ', $order['status'])) ?>
                        </span>
                    </td>
                    <td><?= date('H:i', strtotime($order['created_at'])) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
