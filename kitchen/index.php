<?php
/**
 * Kitchen Display Screen (KDS)
 * Restaurant POS System
 */

require_once __DIR__ . '/../includes/functions.php';
requireRole(['admin', 'kitchen']);

$pdo = getDBConnection();

// Get all pending and in-progress items
$stmt = $pdo->query("
    SELECT 
        oi.id as order_item_id,
        oi.order_id,
        oi.quantity,
        oi.notes,
        oi.status,
        oi.sent_to_kitchen_at,
        oi.created_at,
        o.order_number,
        o.number_of_people,
        t.table_number,
        r.name as room_name,
        mi.name as item_name,
        mc.name as category_name,
        u.full_name as waiter_name
    FROM order_items oi
    JOIN orders o ON oi.order_id = o.id
    JOIN tables_restaurant t ON o.table_id = t.id
    JOIN rooms r ON o.room_id = r.id
    JOIN menu_items mi ON oi.menu_item_id = mi.id
    JOIN menu_categories mc ON mi.category_id = mc.id
    JOIN users u ON o.waiter_id = u.id
    WHERE oi.status IN ('in_kitchen', 'pending')
      AND o.status NOT IN ('paid', 'cancelled')
    ORDER BY 
        CASE oi.status 
            WHEN 'in_kitchen' THEN 1 
            WHEN 'pending' THEN 2 
        END,
        oi.sent_to_kitchen_at ASC,
        oi.created_at ASC
");
$items = $stmt->fetchAll();

// Group by order
$orderGroups = [];
foreach ($items as $item) {
    $orderId = $item['order_id'];
    if (!isset($orderGroups[$orderId])) {
        $orderGroups[$orderId] = [
            'order_id' => $orderId,
            'order_number' => $item['order_number'],
            'table_number' => $item['table_number'],
            'room_name' => $item['room_name'],
            'waiter_name' => $item['waiter_name'],
            'first_item_time' => $item['sent_to_kitchen_at'] ?? $item['created_at'],
            'items' => []
        ];
    }
    
    // Get modifications for this item
    $item['modifications'] = getItemModifications($item['order_item_id']);
    $orderGroups[$orderId]['items'][] = $item;
}

$pageTitle = 'Kitchen Display';

include __DIR__ . '/../includes/header.php';
?>

<style>
.kitchen-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: var(--space-xl);
}

.kitchen-stats {
    display: flex;
    gap: var(--space-lg);
}

.kitchen-stat {
    background: white;
    padding: var(--space-md) var(--space-lg);
    border-radius: var(--radius-md);
    text-align: center;
}

.kitchen-stat .number {
    font-family: var(--font-display);
    font-size: 2rem;
    font-weight: 700;
}

.kitchen-stat .label {
    font-size: 0.8rem;
    color: var(--text-secondary);
    text-transform: uppercase;
}

.ticket-time {
    font-family: var(--font-display);
    font-size: 0.9rem;
}

.ticket-time.warning {
    color: var(--warning);
}

.ticket-time.danger {
    color: var(--danger);
    animation: pulse 1s infinite;
}

@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
}
</style>

<div class="kitchen-header">
    <h1><i class="fas fa-fire-burner"></i> Kitchen Display</h1>
    <div class="kitchen-stats">
        <div class="kitchen-stat">
            <div class="number" style="color: var(--warning);"><?= count(array_filter($items, fn($i) => $i['status'] === 'pending')) ?></div>
            <div class="label">Queued</div>
        </div>
        <div class="kitchen-stat">
            <div class="number" style="color: var(--info);"><?= count(array_filter($items, fn($i) => $i['status'] === 'in_kitchen')) ?></div>
            <div class="label">In Progress</div>
        </div>
        <div class="kitchen-stat">
            <div class="number"><?= count($orderGroups) ?></div>
            <div class="label">Orders</div>
        </div>
    </div>
</div>

<?php if (empty($orderGroups)): ?>
    <div class="card" style="padding: 80px; text-align: center;">
        <i class="fas fa-check-circle" style="font-size: 4rem; color: var(--success); margin-bottom: 24px;"></i>
        <h2>All Caught Up!</h2>
        <p class="text-muted">No pending orders in the kitchen.</p>
    </div>
<?php else: ?>
    <div class="kitchen-grid">
        <?php foreach ($orderGroups as $group): 
            $minutes = round((time() - strtotime($group['first_item_time'])) / 60);
            $timeClass = $minutes > 15 ? 'danger' : ($minutes > 10 ? 'warning' : '');
            
            // Determine overall ticket status
            $hasInProgress = false;
            foreach ($group['items'] as $item) {
                if ($item['status'] === 'in_kitchen') {
                    $hasInProgress = true;
                    break;
                }
            }
        ?>
            <div class="kitchen-ticket <?= $hasInProgress ? 'in-progress' : '' ?>">
                <div class="ticket-header">
                    <div>
                        <div class="table-info">
                            <i class="fas fa-chair"></i> <?= htmlspecialchars($group['table_number']) ?>
                            <span style="font-weight: 400; font-size: 0.85rem; margin-left: 8px;">
                                <?= htmlspecialchars($group['room_name']) ?>
                            </span>
                        </div>
                        <div style="font-size: 0.8rem; color: var(--text-secondary);">
                            <?= htmlspecialchars($group['waiter_name']) ?>
                        </div>
                    </div>
                    <div class="ticket-time <?= $timeClass ?>">
                        <i class="fas fa-clock"></i> <?= $minutes ?> min
                    </div>
                </div>
                
                <div class="ticket-items">
                    <?php foreach ($group['items'] as $item): ?>
                        <div class="ticket-item" data-item-id="<?= $item['order_item_id'] ?>">
                            <div>
                                <span class="qty"><?= $item['quantity'] ?>×</span>
                                <strong><?= htmlspecialchars($item['item_name']) ?></strong>
                                <span class="badge badge-<?= $item['status'] === 'in_kitchen' ? 'info' : 'warning' ?>" style="margin-left: 8px;">
                                    <?= $item['status'] === 'in_kitchen' ? 'Cooking' : 'Queued' ?>
                                </span>
                            </div>
                            
                            <?php if ($item['notes'] || !empty($item['modifications'])): ?>
                                <div class="mods">
                                    <?php if ($item['notes']): ?>
                                        <div><i class="fas fa-sticky-note"></i> <?= htmlspecialchars($item['notes']) ?></div>
                                    <?php endif; ?>
                                    <?php foreach ($item['modifications'] as $mod): ?>
                                        <div>
                                            <span class="<?= $mod['action'] === 'removed' ? 'text-danger' : 'text-success' ?>">
                                                <?= $mod['action'] === 'removed' ? '− NO' : '+ ADD' ?>
                                            </span>
                                            <?= htmlspecialchars($mod['component_name']) ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            
                            <div class="mt-sm d-flex gap-sm">
                                <?php if ($item['status'] === 'pending'): ?>
                                    <button class="btn btn-sm btn-info" onclick="startItem(<?= $item['order_item_id'] ?>)">
                                        <i class="fas fa-play"></i> Start
                                    </button>
                                <?php endif; ?>
                                <?php if ($item['status'] === 'in_kitchen'): ?>
                                    <button class="btn btn-sm btn-success" onclick="markReady(<?= $item['order_item_id'] ?>)">
                                        <i class="fas fa-check"></i> Ready
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <div class="ticket-actions">
                    <button class="btn btn-success" onclick="markAllReady(<?= $group['order_id'] ?>)">
                        <i class="fas fa-check-double"></i> All Ready
                    </button>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<script>
async function startItem(orderItemId) {
    try {
        const result = await updateKitchenStatus(orderItemId, 'in_kitchen');
        if (result.success) {
            showToast('Started cooking!', 'info');
            location.reload();
        }
    } catch (error) {
        showToast('Failed to update', 'error');
    }
}

async function markReady(orderItemId) {
    try {
        const result = await updateKitchenStatus(orderItemId, 'ready');
        if (result.success) {
            showToast('Marked as ready!', 'success');
            location.reload();
        }
    } catch (error) {
        showToast('Failed to update', 'error');
    }
}

async function markAllReady(orderId) {
    try {
        const result = await apiCall('/api/kitchen.php', 'POST', {
            action: 'mark_all_ready',
            order_id: orderId
        });
        if (result.success) {
            showToast('All items marked ready!', 'success');
            location.reload();
        }
    } catch (error) {
        showToast('Failed to update', 'error');
    }
}

// Auto-refresh every 30 seconds
setInterval(() => {
    location.reload();
}, 30000);
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
