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

$pageTitle = t('admin_dashboard');

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-tachometer-alt"></i> <?= te('dashboard') ?></h1>
</div>

<!-- Stats -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon primary">
            <i class="fas fa-receipt"></i>
        </div>
        <div>
            <div class="stat-value"><?= $stats['today']['count'] ?></div>
            <div class="stat-label"><?= te('orders_today') ?></div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon success">
            <i class="fas fa-dollar-sign"></i>
        </div>
        <div>
            <div class="stat-value"><?= formatCurrency($stats['today']['total']) ?></div>
            <div class="stat-label"><?= te('revenue_today') ?></div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon warning">
            <i class="fas fa-clipboard-list"></i>
        </div>
        <div>
            <div class="stat-value"><?= $stats['active_orders'] ?></div>
            <div class="stat-label"><?= te('active_orders') ?></div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon info">
            <i class="fas fa-chair"></i>
        </div>
        <div>
            <div class="stat-value"><?= $stats['tables']['occupied'] ?? 0 ?>/<?= $stats['tables']['total'] ?? 0 ?></div>
            <div class="stat-label"><?= te('tables_occupied') ?></div>
        </div>
    </div>
</div>

<!-- Recent Orders -->
<div class="card">
    <div class="card-header">
        <h2><?= te('recent_orders') ?></h2>
        <a href="/admin/orders.php" class="btn btn-sm btn-outline"><?= te('view_all') ?></a>
    </div>
    <table class="data-table">
        <thead>
            <tr>
                <th><?= te('order_no') ?></th>
                <th><?= te('table') ?></th>
                <th><?= te('waiter') ?></th>
                <th><?= te('total') ?></th>
                <th><?= te('status') ?></th>
                <th><?= te('time') ?></th>
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
                            <?= htmlspecialchars(statusLabel($order['status'])) ?>
                        </span>
                    </td>
                    <td><?= date('H:i', strtotime($order['created_at'])) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
