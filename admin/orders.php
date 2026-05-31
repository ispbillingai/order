<?php
/**
 * Admin Orders List
 * Restaurant POS System
 */

require_once __DIR__ . '/../includes/functions.php';
requireRole(['admin']);

$pdo = getDBConnection();

// Filters
$status = $_GET['status'] ?? '';
$date = $_GET['date'] ?? date('Y-m-d');

// Build query
$sql = "
    SELECT o.*, t.table_number, r.name as room_name, u.full_name as waiter_name
    FROM orders o
    JOIN tables_restaurant t ON o.table_id = t.id
    JOIN rooms r ON o.room_id = r.id
    JOIN users u ON o.waiter_id = u.id
    WHERE 1=1
";
$params = [];

if ($status) {
    $sql .= " AND o.status = ?";
    $params[] = $status;
}

if ($date) {
    $sql .= " AND DATE(o.opened_at) = ?";
    $params[] = $date;
}

$sql .= " ORDER BY o.opened_at DESC LIMIT 100";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll();

$pageTitle = 'All Orders';

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-list-alt"></i> All Orders</h1>
</div>

<!-- Filters -->
<div class="card mb-lg">
    <div class="card-body">
        <form method="GET" class="d-flex gap-md align-center" style="flex-wrap: wrap;">
            <div class="form-group" style="margin: 0;">
                <label class="form-label">Date</label>
                <input type="date" name="date" class="form-control" value="<?= $date ?>">
            </div>
            <div class="form-group" style="margin: 0;">
                <label class="form-label">Status</label>
                <select name="status" class="form-control">
                    <option value="">All Statuses</option>
                    <option value="open" <?= $status === 'open' ? 'selected' : '' ?>>Open</option>
                    <option value="sent_to_kitchen" <?= $status === 'sent_to_kitchen' ? 'selected' : '' ?>>In Kitchen</option>
                    <option value="bill_requested" <?= $status === 'bill_requested' ? 'selected' : '' ?>>Bill Requested</option>
                    <option value="paid" <?= $status === 'paid' ? 'selected' : '' ?>>Paid</option>
                    <option value="cancelled" <?= $status === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                </select>
            </div>
            <div class="form-group" style="margin: 0;">
                <label class="form-label">&nbsp;</label>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-filter"></i> Filter
                </button>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <table class="data-table">
        <thead>
            <tr>
                <th>Order #</th>
                <th>Table</th>
                <th>Waiter</th>
                <th>Guests</th>
                <th>Subtotal</th>
                <th>Discount</th>
                <th>Total</th>
                <th>Status</th>
                <th>Opened</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($orders as $order): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($order['order_number']) ?></strong></td>
                    <td>
                        <?= htmlspecialchars($order['table_number']) ?>
                        <small class="text-muted">(<?= htmlspecialchars($order['room_name']) ?>)</small>
                    </td>
                    <td><?= htmlspecialchars($order['waiter_name']) ?></td>
                    <td><?= $order['number_of_people'] ?></td>
                    <td><?= formatCurrency($order['subtotal']) ?></td>
                    <td>
                        <?php if ($order['discount_amount'] > 0): ?>
                            <span class="text-danger">-<?= formatCurrency($order['discount_amount']) ?></span>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                    <td><strong class="text-primary"><?= formatCurrency($order['total']) ?></strong></td>
                    <td>
                        <span class="badge badge-<?= 
                            $order['status'] === 'paid' ? 'success' : 
                            ($order['status'] === 'cancelled' ? 'danger' : 
                            ($order['status'] === 'bill_requested' ? 'warning' : 'info')) 
                        ?>">
                            <?= ucfirst(str_replace('_', ' ', $order['status'])) ?>
                        </span>
                    </td>
                    <td><?= date('H:i', strtotime($order['opened_at'])) ?></td>
                    <td>
                        <a href="/waiter/order.php?order=<?= $order['id'] ?>" class="btn btn-sm btn-outline">
                            <i class="fas fa-eye"></i>
                        </a>
                        <?php if ($order['status'] === 'bill_requested'): ?>
                            <a href="/cashier/payment.php?order=<?= $order['id'] ?>" class="btn btn-sm btn-success">
                                <i class="fas fa-money-bill"></i>
                            </a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($orders)): ?>
                <tr>
                    <td colspan="10" class="text-center text-muted" style="padding: 40px;">
                        No orders found for the selected criteria.
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
