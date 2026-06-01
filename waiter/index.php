<?php
/**
 * Waiter Dashboard - Tables View
 * Restaurant POS System
 */

require_once __DIR__ . '/../includes/functions.php';
requireRole(['admin', 'waiter']);

$pageTitle = t('waiter_dashboard');
$rooms = getRooms();
$selectedRoomId = $_GET['room'] ?? ($rooms[0]['id'] ?? null);

// Get tables for selected room
$tables = $selectedRoomId ? getTablesByRoom($selectedRoomId) : [];

// Get active orders for these tables
$pdo = getDBConnection();
$tableOrders = [];
if ($selectedRoomId) {
    $tableIds = array_column($tables, 'id');
    if (!empty($tableIds)) {
        $placeholders = str_repeat('?,', count($tableIds) - 1) . '?';
        $stmt = $pdo->prepare("
            SELECT o.*, t.id as table_id 
            FROM orders o 
            JOIN tables_restaurant t ON o.table_id = t.id 
            WHERE t.id IN ($placeholders) AND o.status NOT IN ('paid', 'cancelled')
        ");
        $stmt->execute($tableIds);
        foreach ($stmt->fetchAll() as $order) {
            $tableOrders[$order['table_id']] = $order;
        }
    }
}

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-th-large"></i> <?= te('select_table') ?></h1>
    <div class="d-flex gap-md">
        <a href="/waiter/orders.php" class="btn btn-secondary">
            <i class="fas fa-list"></i> <?= te('my_orders') ?>
        </a>
    </div>
</div>

<!-- Room Tabs -->
<div class="room-tabs">
    <?php foreach ($rooms as $room): ?>
        <a href="?room=<?= $room['id'] ?>" 
           class="room-tab <?= $room['id'] == $selectedRoomId ? 'active' : '' ?>">
            <?= htmlspecialchars($room['name']) ?>
        </a>
    <?php endforeach; ?>
</div>

<!-- Tables Grid -->
<div class="tables-grid">
    <?php foreach ($tables as $table): 
        $order = $tableOrders[$table['id']] ?? null;
        $status = $order ? $order['status'] : 'free';
        if ($status === 'open' || $status === 'sent_to_kitchen') $status = 'occupied';
    ?>
        <div class="table-card <?= $status ?>" 
             onclick="selectTable(<?= $table['id'] ?>, '<?= $status ?>', <?= $order ? $order['id'] : 'null' ?>)"
             data-table-id="<?= $table['id'] ?>">
            <div class="table-number"><?= htmlspecialchars($table['table_number']) ?></div>
            <div class="table-capacity">
                <i class="fas fa-users"></i>
                <?= $table['capacity'] ?> <?= te('seats') ?>
            </div>
            <div class="table-status">
                <?php if ($status === 'free'): ?>
                    <?= te('available') ?>
                <?php elseif ($status === 'occupied'): ?>
                    <?= te('occupied') ?>
                <?php elseif ($status === 'bill_requested'): ?>
                    <?= te('bill_requested') ?>
                <?php endif; ?>
            </div>
            <?php if ($order): ?>
                <div class="table-order-info" style="margin-top: 8px; font-size: 0.8rem; color: var(--text-secondary);">
                    <?= formatCurrency($order['total']) ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
    
    <?php if (empty($tables)): ?>
        <div class="card" style="grid-column: 1/-1; padding: 40px; text-align: center;">
            <i class="fas fa-chair" style="font-size: 3rem; color: var(--text-secondary); margin-bottom: 16px;"></i>
            <p class="text-muted"><?= te('no_tables_room') ?></p>
        </div>
    <?php endif; ?>
</div>

<!-- New Order Modal -->
<div class="modal-overlay" id="newOrderModal">
    <div class="modal">
        <div class="modal-header">
            <h3><?= te('start_new_order') ?></h3>
            <button class="modal-close">&times;</button>
        </div>
        <div class="modal-body">
            <p class="mb-md"><?= te('table') ?>: <strong id="modalTableNumber"></strong></p>

            <div class="form-group">
                <label class="form-label"><?= te('number_of_guests') ?></label>
                <input type="number" id="numberOfPeople" class="form-control" min="1" max="20" value="1">
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeModal('newOrderModal')"><?= te('cancel') ?></button>
            <button class="btn btn-primary" onclick="startNewOrder()">
                <i class="fas fa-plus"></i> <?= te('start_order') ?>
            </button>
        </div>
    </div>
</div>

<script>
let selectedTableId = null;

function selectTable(tableId, status, orderId) {
    selectedTableId = tableId;
    
    if (status === 'free') {
        // Show new order modal
        document.getElementById('modalTableNumber').textContent = 
            document.querySelector(`[data-table-id="${tableId}"] .table-number`).textContent;
        document.getElementById('numberOfPeople').value = 1;
        openModal('newOrderModal');
    } else {
        // Go to existing order
        window.location.href = `/waiter/order.php?order=${orderId}`;
    }
}

async function startNewOrder() {
    const numberOfPeople = parseInt(document.getElementById('numberOfPeople').value) || 1;
    
    try {
        const result = await createOrder(selectedTableId, numberOfPeople);
        
        if (result.success) {
            showToast(<?= json_encode(t('toast_order_created')) ?>, 'success');
            window.location.href = `/waiter/order.php?order=${result.order_id}`;
        }
    } catch (error) {
        showToast(<?= json_encode(t('toast_order_failed')) ?>, 'error');
    }
}

// Listen for updates
document.addEventListener('app:update', function(e) {
    // Could refresh table statuses here
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
