<?php
/**
 * Cashier Dashboard
 * Restaurant POS System
 */

require_once __DIR__ . '/../includes/functions.php';
requireRole(['admin', 'cashier']);

$pdo = getDBConnection();

// Get all tables with their current status - FIXED QUERY
try {
    $stmt = $pdo->query("
        SELECT 
            t.id,
            t.table_number,
            t.capacity,
            t.status,
            t.current_order_id,
            r.name as room_name,
            o.id as order_id,
            o.order_number,
            o.total,
            o.status as order_status,
            o.number_of_people
        FROM tables_restaurant t
        JOIN rooms r ON t.room_id = r.id
        LEFT JOIN orders o ON t.id = o.table_id AND o.status NOT IN ('paid', 'cancelled')
        WHERE r.active = 1
        ORDER BY r.sort_order, t.table_number
    ");
    $tables = $stmt->fetchAll();
} catch (Exception $e) {
    $tables = [];
}

// Get orders awaiting payment - FIXED QUERY
try {
    $stmt = $pdo->query("
        SELECT 
            o.id,
            o.order_number,
            o.total,
            o.status,
            o.number_of_people,
            o.updated_at,
            t.table_number,
            r.name as room_name,
            u.full_name as waiter_name
        FROM orders o
        JOIN tables_restaurant t ON o.table_id = t.id
        JOIN rooms r ON o.room_id = r.id
        JOIN users u ON o.waiter_id = u.id
        WHERE o.status = 'bill_requested'
        ORDER BY o.updated_at ASC
    ");
    $pendingBills = $stmt->fetchAll();
} catch (Exception $e) {
    $pendingBills = [];
}

// Today's stats
try {
    $stmt = $pdo->query("
        SELECT 
            COUNT(*) as total_orders,
            COALESCE(SUM(total), 0) as total_revenue
        FROM orders 
        WHERE status = 'paid' 
        AND DATE(closed_at) = CURDATE()
    ");
    $todayStats = $stmt->fetch();
} catch (Exception $e) {
    $todayStats = ['total_orders' => 0, 'total_revenue' => 0];
}

$pageTitle = t('nav_cashier');

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-cash-register"></i> <?= te('cashier_dashboard') ?></h1>
</div>

<!-- Stats -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon primary">
            <i class="fas fa-receipt"></i>
        </div>
        <div>
            <div class="stat-value"><?= $todayStats['total_orders'] ?? 0 ?></div>
            <div class="stat-label"><?= te('orders_today') ?></div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon success">
            <i class="fas fa-euro-sign"></i>
        </div>
        <div>
            <div class="stat-value"><?= formatCurrency($todayStats['total_revenue'] ?? 0) ?></div>
            <div class="stat-label"><?= te('revenue_today') ?></div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon warning">
            <i class="fas fa-hourglass-half"></i>
        </div>
        <div>
            <div class="stat-value"><?= is_array($pendingBills) ? count($pendingBills) : 0 ?></div>
            <div class="stat-label"><?= te('pending_bills') ?></div>
        </div>
    </div>
</div>

<?php if (!empty($pendingBills)): ?>
<!-- Pending Bills -->
<div class="card mb-lg">
    <div class="card-header">
        <h2><i class="fas fa-exclamation-circle text-warning"></i> <?= te('bills_awaiting') ?></h2>
    </div>
    <table class="data-table">
        <thead>
            <tr>
                <th><?= te('table') ?></th>
                <th><?= te('order_no') ?></th>
                <th><?= te('guests') ?></th>
                <th><?= te('waiter') ?></th>
                <th><?= te('total') ?></th>
                <th><?= te('actions') ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($pendingBills as $bill): ?>
                <tr>
                    <td>
                        <strong><?= htmlspecialchars($bill['table_number'] ?? '') ?></strong>
                        <span class="text-muted">(<?= htmlspecialchars($bill['room_name'] ?? '') ?>)</span>
                    </td>
                    <td><?= htmlspecialchars($bill['order_number'] ?? '') ?></td>
                    <td><?= $bill['number_of_people'] ?? 0 ?></td>
                    <td><?= htmlspecialchars($bill['waiter_name'] ?? 'Unknown') ?></td>
                    <td><strong class="text-primary" style="font-size: 1.1rem;"><?= formatCurrency($bill['total'] ?? 0) ?></strong></td>
                    <td>
                        <a href="/cashier/payment.php?order=<?= $bill['id'] ?>" class="btn btn-sm btn-success">
                            <i class="fas fa-money-bill"></i> <?= te('process_payment') ?>
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<!-- All Tables -->
<div class="card">
    <div class="card-header">
        <h2><?= te('table_overview') ?></h2>
    </div>
    <div class="card-body">
        <div class="tables-grid">
            <?php foreach ($tables as $table): 
                $status = $table['order_id'] ? ($table['order_status'] === 'bill_requested' ? 'bill_requested' : 'occupied') : 'free';
            ?>
                <div class="table-card <?= $status ?>" 
                     <?php if ($table['order_id']): ?>
                     onclick="window.location.href='/cashier/payment.php?order=<?= $table['order_id'] ?>'"
                     <?php endif; ?>
                     style="<?= $table['order_id'] ? '' : 'cursor: default;' ?>">
                    <div class="table-number"><?= htmlspecialchars($table['table_number'] ?? '') ?></div>
                    <div class="table-capacity">
                        <i class="fas fa-users"></i>
                        <?= $table['capacity'] ?? 4 ?>
                    </div>
                    <div class="table-status">
                        <?php if ($status === 'free'): ?>
                            <?= te('available') ?>
                        <?php elseif ($status === 'occupied'): ?>
                            <?= te('occupied') ?>
                        <?php elseif ($status === 'bill_requested'): ?>
                            <i class="fas fa-bell"></i> <?= te('bill_requested') ?>
                        <?php endif; ?>
                    </div>
                    <?php if ($table['order_id'] && isset($table['total'])): ?>
                        <div style="margin-top: 8px; font-weight: 700; color: var(--primary);">
                            <?= formatCurrency($table['total']) ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>