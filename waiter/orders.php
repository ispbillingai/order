<?php
/**
 * Waiter Orders List
 * Restaurant POS System
 */

require_once __DIR__ . '/../includes/functions.php';
requireRole(['admin', 'waiter']);

$user = getCurrentUser();
$pdo = getDBConnection();

// Get active orders for this waiter (or all if admin)
$sql = "
    SELECT o.*, t.table_number, r.name as room_name,
           (SELECT COUNT(*) FROM order_items WHERE order_id = o.id AND status != 'cancelled') as item_count
    FROM orders o
    JOIN tables_restaurant t ON o.table_id = t.id
    JOIN rooms r ON o.room_id = r.id
    WHERE o.status NOT IN ('paid', 'cancelled')
";

if ($user['role'] !== 'admin') {
    $sql .= " AND o.waiter_id = ?";
}

$sql .= " ORDER BY o.opened_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($user['role'] !== 'admin' ? [$user['id']] : []);
$orders = $stmt->fetchAll();

$pageTitle = 'My Orders';

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-list-alt"></i> My Active Orders</h1>
    <a href="/waiter/index.php" class="btn btn-primary">
        <i class="fas fa-plus"></i> New Order
    </a>
</div>

<?php if (empty($orders)): ?>
    <div class="card" style="padding: 60px; text-align: center;">
        <i class="fas fa-clipboard" style="font-size: 3rem; color: var(--text-secondary); margin-bottom: 16px;"></i>
        <h3 class="text-muted">No Active Orders</h3>
        <p class="text-muted">Start a new order by selecting a table.</p>
        <a href="/waiter/index.php" class="btn btn-primary mt-lg">
            <i class="fas fa-th-large"></i> Go to Tables
        </a>
    </div>
<?php else: ?>
    <div class="card">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Order #</th>
                    <th>Table</th>
                    <th>Room</th>
                    <th>Guests</th>
                    <th>Items</th>
                    <th>Total</th>
                    <th>Status</th>
                    <th>Time</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($orders as $order): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($order['order_number']) ?></strong></td>
                        <td><?= htmlspecialchars($order['table_number']) ?></td>
                        <td><?= htmlspecialchars($order['room_name']) ?></td>
                        <td><?= $order['number_of_people'] ?></td>
                        <td><?= $order['item_count'] ?></td>
                        <td><strong><?= formatCurrency($order['total']) ?></strong></td>
                        <td>
                            <span class="badge badge-<?= 
                                $order['status'] === 'open' ? 'warning' : 
                                ($order['status'] === 'sent_to_kitchen' ? 'info' : 
                                ($order['status'] === 'bill_requested' ? 'success' : 'primary')) 
                            ?>">
                                <?= ucfirst(str_replace('_', ' ', $order['status'])) ?>
                            </span>
                        </td>
                        <td><?= date('H:i', strtotime($order['opened_at'])) ?></td>
                        <td>
                            <a href="/waiter/order.php?order=<?= $order['id'] ?>" class="btn btn-sm btn-primary">
                                <i class="fas fa-edit"></i> View
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
