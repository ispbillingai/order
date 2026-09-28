<?php
/**
 * Waiter Order Page - Add/Edit Items
 * Restaurant POS System
 */

require_once __DIR__ . '/../includes/functions.php';
requireRole(['admin', 'waiter']);

$orderId = $_GET['order'] ?? null;
if (!$orderId) {
    header('Location: /waiter/index.php');
    exit;
}

$order = getOrderById($orderId);
if (!$order) {
    header('Location: /waiter/index.php');
    exit;
}

$orderItems = getOrderItems($orderId);
$categories = getMenuCategories();
$tills      = getTills();

// A sent order can be recalled: the waiter keeps adding dishes and changing
// sent ones until the order is paid. Dishes added now print as an ADDITION at
// their work point; changing or cancelling a dish already in preparation
// prints a change slip there.
$isEditable  = !in_array($order['status'], ['paid', 'cancelled'], true);
$liveItems   = array_filter($orderItems, fn($i) => $i['status'] !== 'cancelled');
$pendingCount = count(array_filter($liveItems, fn($i) => $i['status'] === 'pending'));
$sentCount    = count($liveItems) - $pendingCount;
$wasSent      = $order['status'] !== 'open';
$selectedCategoryId = $_GET['category'] ?? ($categories[0]['id'] ?? null);
$menuItems = $selectedCategoryId ? getMenuItemsByCategory($selectedCategoryId) : [];

// Get category info for composition check
$pdo = getDBConnection();
$stmt = $pdo->prepare("SELECT allow_composition FROM menu_categories WHERE id = ?");
$stmt->execute([$selectedCategoryId]);
$categoryInfo = $stmt->fetch();
$allowComposition = $categoryInfo ? $categoryInfo['allow_composition'] : 0;

// Joined tables (large party): the tables this order sits on, and the free
// tables that could be added to it.
$orderTables = getOrderTables($orderId);
$freeTables  = [];
if ($isEditable) {
    $freeTables = $pdo->query("
        SELECT t.id, t.table_number, t.capacity, r.name AS room_name
        FROM tables_restaurant t
        JOIN rooms r ON t.room_id = r.id
        WHERE r.active = 1
          AND NOT EXISTS (SELECT 1 FROM orders o
                          WHERE (o.table_id = t.id OR o.id = t.current_order_id)
                            AND o.status NOT IN ('paid', 'cancelled')
                            AND o.parent_order_id IS NULL)
        ORDER BY r.sort_order, r.name, t.table_number + 0, t.table_number
    ")->fetchAll();
}
$seats = array_sum(array_map('intval', array_column($orderTables, 'capacity')));

// Bill by seat. Each dish sits on a seat (0 = shared by the table); a seat can
// be billed on its own, which splits it into a seat bill. On a seat bill
// itself all of this is fixed: it is that one seat.
$isSeatBill  = !empty($order['parent_order_id']);
$seatBills   = [];
$billedSeats = [];
if (!$isSeatBill) {
    $stmt = $pdo->prepare("SELECT id, order_number, seat, total, status FROM orders WHERE parent_order_id = ? AND status <> 'cancelled' ORDER BY seat, id");
    $stmt->execute([$orderId]);
    $seatBills   = $stmt->fetchAll();
    $billedSeats = array_map('intval', array_column($seatBills, 'seat'));
}
$itemsBySeat = [];
$seatTotals  = [];
foreach ($orderItems as $it) {
    $s = (int) $it['seat'];
    $itemsBySeat[$s][] = $it;
    if ($it['status'] !== 'cancelled') {
        $seatTotals[$s] = ($seatTotals[$s] ?? 0) + (float) $it['total_price'];
    }
}
ksort($itemsBySeat);
$seatCount = max(1, (int) $order['number_of_people'] + count(array_unique($billedSeats)), max(array_keys($itemsBySeat) ?: [0]));
// Group the dish list by seat once any dish is on a seat.
$showSeatGroups = !$isSeatBill && (bool) array_filter(array_keys($itemsBySeat));

// Other occupied tables that could be merged into this one.
$mergeOrders = [];
if ($isEditable && !$isSeatBill) {
    $stmt = $pdo->prepare("
        SELECT o.id, o.order_number, o.total, o.number_of_people,
               COALESCE(o.table_label, t.table_number) AS table_number, r.name AS room_name
        FROM orders o
        JOIN tables_restaurant t ON t.id = o.table_id
        JOIN rooms r ON r.id = o.room_id
        WHERE o.id <> ? AND o.parent_order_id IS NULL AND o.channel = 'dine_in'
          AND o.status NOT IN ('paid', 'cancelled')
        ORDER BY r.sort_order, r.name, t.table_number + 0, t.table_number
    ");
    $stmt->execute([$orderId]);
    $mergeOrders = $stmt->fetchAll();
}

$pageTitle = "Order #{$order['order_number']}";

include __DIR__ . '/../includes/header.php';
?>

<style>
.order-page {
    display: grid;
    grid-template-columns: 1fr 380px;
    gap: var(--space-lg);
    align-items: start;
}

@media (max-width: 1024px) {
    .order-page {
        grid-template-columns: 1fr;
    }
}

/* A cancelled dish stays visible (it may already have been cooked) but is
   clearly struck out and no longer billed. */
.order-item.item-cancelled {
    opacity: .55;
}
.order-item.item-cancelled .item-name,
.order-item.item-cancelled .item-total {
    text-decoration: line-through;
}

.join-list { display: flex; flex-direction: column; gap: 6px; }
.join-row { display: flex; justify-content: space-between; align-items: center; gap: 10px; padding: 8px 10px; border: 1px solid var(--border-color); border-radius: 8px; }
.join-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(90px, 1fr)); gap: 8px; max-height: 50vh; overflow-y: auto; }
.join-room { grid-column: 1 / -1; font-size: .8rem; font-weight: 700; text-transform: uppercase; color: var(--text-secondary); margin-top: 6px; }
.join-pick { display: flex; align-items: center; gap: 6px; padding: 10px; border: 1px solid var(--border-color); border-radius: 8px; cursor: pointer; }
.join-pick:has(input:checked) { border-color: var(--primary); background: rgba(59, 130, 246, .08); }

/* Bill by seat */
.seat-bar { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; padding: 10px 0; margin-bottom: 6px; border-bottom: 1px solid var(--border-color); }
.seat-bar-label { font-size: .8rem; color: var(--text-secondary); margin-right: 2px; }
.seat-chip { min-width: 40px; padding: 8px 10px; border: 1px solid var(--border-color); border-radius: 8px; background: var(--bg-light, #f3f4f6); font-weight: 700; cursor: pointer; }
.seat-chip.active { background: var(--primary); border-color: var(--primary); color: #fff; }
.seat-chip.billed { opacity: .5; cursor: not-allowed; }
.seat-group-head { display: flex; justify-content: space-between; align-items: center; gap: 8px; padding: 8px 4px 4px; margin-top: 6px; border-bottom: 2px solid var(--border-color); font-size: .9rem; }
.seat-pill { margin-left: 6px; padding: 2px 8px; border: 1px dashed var(--border-color); border-radius: 999px; background: transparent; font-size: .75rem; color: var(--text-secondary); cursor: pointer; }
.seat-bills { margin-top: 10px; }
.seat-bill-row { display: flex; justify-content: space-between; align-items: center; padding: 8px 4px; border-bottom: 1px dashed var(--border-color); color: inherit; text-decoration: none; }

.recall-note {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 14px;
    margin-bottom: var(--space-md);
    border-radius: 8px;
    background: rgba(59, 130, 246, .1);
    border-left: 3px solid var(--info, #3b82f6);
    font-size: .9rem;
}
</style>

<div class="page-header">
    <h1>
        <i class="fas fa-clipboard-list"></i> 
        Order #<?= htmlspecialchars($order['order_number']) ?>
    </h1>
    <div class="d-flex gap-md align-center">
        <span class="badge badge-<?= $order['status'] === 'open' ? 'warning' : 'info' ?>">
            <?= htmlspecialchars(statusLabel($order['status'])) ?>
        </span>
        <a href="/waiter/index.php" class="btn btn-outline">
            <i class="fas fa-arrow-left"></i> <?= te('tables_btn') ?>
        </a>
    </div>
</div>

<div class="order-info mb-lg" style="display: flex; gap: 24px; flex-wrap: wrap;">
    <div>
        <strong><?= te('table') ?>:</strong> <?= htmlspecialchars($order['table_number']) ?>
        <?php if (count($orderTables) > 1): ?>
            <span class="text-muted">(<?= $seats ?> <?= te('seats') ?>)</span>
        <?php endif; ?>
        <?php if ($isEditable && !$isSeatBill): ?>
            <button type="button" class="btn btn-sm btn-outline" style="margin-left:8px;" onclick="openModal('joinTablesModal')">
                <i class="fas fa-link"></i> <?= te('join_tables') ?>
            </button>
        <?php endif; ?>
    </div>
    <div><strong><?= te('room') ?>:</strong> <?= htmlspecialchars($order['room_name']) ?></div>
    <div><strong><?= te('guests') ?>:</strong> <?= $order['number_of_people'] ?></div>
    <div><strong><?= te('waiter') ?>:</strong> <?= htmlspecialchars($order['waiter_name']) ?></div>
</div>

<div class="order-page">
    <!-- Menu Section -->
    <div class="menu-section">
        <!-- Categories -->
        <div class="categories-nav">
            <?php foreach ($categories as $cat): ?>
                <a href="?order=<?= $orderId ?>&category=<?= $cat['id'] ?>" 
                   class="category-btn <?= $cat['id'] == $selectedCategoryId ? 'active' : '' ?>">
                    <i class="fas fa-<?= htmlspecialchars($cat['icon'] ?: 'utensils') ?>"></i>
                    <span><?= htmlspecialchars($cat['name']) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
        
        <!-- Menu Items -->
        <div class="menu-grid">
            <?php foreach ($menuItems as $item): ?>
                <div class="menu-item" onclick="selectMenuItem(<?= htmlspecialchars(json_encode($item)) ?>, <?= $allowComposition ?>)">
                    <div class="item-name"><?= htmlspecialchars($item['name']) ?></div>
                    <?php if ($item['description']): ?>
                        <div class="item-desc"><?= htmlspecialchars($item['description']) ?></div>
                    <?php endif; ?>
                    <div class="item-price"><?= formatCurrency($item['base_price']) ?></div>
                </div>
            <?php endforeach; ?>
            
            <?php if (empty($menuItems)): ?>
                <div class="card" style="grid-column: 1/-1; padding: 40px; text-align: center;">
                    <p class="text-muted"><?= te('no_items_cat') ?></p>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Order Panel -->
    <div class="order-panel">
        <div class="order-header">
            <h3><?= te('current_order') ?></h3>
            <div class="order-info">
                <?= te('table') ?> <?= htmlspecialchars($order['table_number']) ?> • <?= $order['number_of_people'] ?> <?= te('guests') ?>
            </div>
        </div>

        <?php if ($isEditable && $wasSent && $sentCount > 0): ?>
            <div class="recall-note">
                <i class="fas fa-rotate-left"></i>
                <span><?= te('recall_hint') ?></span>
            </div>
        <?php endif; ?>

        <?php if ($isSeatBill): ?>
            <div class="recall-note">
                <i class="fas fa-user"></i>
                <span><?= te('seat_bill_note') ?>
                    <a href="/waiter/order.php?order=<?= (int) $order['parent_order_id'] ?>"><?= te('seat_bill_open_table') ?></a></span>
            </div>
        <?php elseif ($isEditable): ?>
            <!-- Which seat new dishes go to -->
            <div class="seat-bar">
                <span class="seat-bar-label"><?= te('seat_adding_to') ?>:</span>
                <button type="button" class="seat-chip" data-seat="0" onclick="setActiveSeat(0)"><i class="fas fa-utensils"></i> <?= te('seat_shared') ?></button>
                <?php for ($s = 1; $s <= $seatCount; $s++): $billed = in_array($s, $billedSeats, true); ?>
                    <button type="button" class="seat-chip<?= $billed ? ' billed' : '' ?>" data-seat="<?= $s ?>"
                            <?= $billed ? 'disabled title="' . te('seat_billed') . '"' : 'onclick="setActiveSeat(' . $s . ')"' ?>>
                        <?= $s ?><?= $billed ? ' <i class="fas fa-check"></i>' : '' ?>
                    </button>
                <?php endfor; ?>
            </div>
        <?php endif; ?>

        <div class="order-items" id="orderItemsList">
            <?php if (empty($orderItems)): ?>
                <div class="text-center text-muted" style="padding: 40px;">
                    <i class="fas fa-shopping-basket" style="font-size: 2rem; margin-bottom: 16px;"></i>
                    <p><?= te('no_items_yet') ?></p>
                </div>
            <?php else: ?>
                <?php foreach ($itemsBySeat as $seatNo => $seatItems): ?>
                <?php if ($showSeatGroups): ?>
                    <div class="seat-group-head">
                        <strong><?= $seatNo ? te('seat') . ' ' . $seatNo : te('seat_shared') ?></strong>
                        <span class="d-flex align-center gap-sm">
                            <?= formatCurrency($seatTotals[$seatNo] ?? 0) ?>
                            <?php if ($seatNo && $isEditable && !empty($seatTotals[$seatNo])): ?>
                                <button type="button" class="btn btn-sm btn-warning" onclick="billSeat(<?= (int) $seatNo ?>)">
                                    <i class="fas fa-receipt"></i> <?= te('seat_bill_btn') ?>
                                </button>
                            <?php endif; ?>
                        </span>
                    </div>
                <?php endif; ?>
                <?php foreach ($seatItems as $item):
                    $mods = getItemModifications($item['id']);
                    $isCancelled = $item['status'] === 'cancelled';
                    // A dish already at a work point: editing it reprints there.
                    $isSentItem  = !$isCancelled && $item['status'] !== 'pending';
                    $canEditItem = $isEditable && !$isCancelled;
                ?>
                    <div class="order-item<?= $isCancelled ? ' item-cancelled' : '' ?>" data-item-id="<?= $item['id'] ?>">
                        <div class="item-details">
                            <div class="item-name"><?= htmlspecialchars($item['item_name']) ?></div>
                            <?php if ($item['notes'] || !empty($mods)): ?>
                                <div class="item-mods">
                                    <?php if ($item['notes']): ?>
                                        <div><i class="fas fa-sticky-note"></i> <?= htmlspecialchars($item['notes']) ?></div>
                                    <?php endif; ?>
                                    <?php foreach ($mods as $mod): ?>
                                        <div>
                                            <?= $mod['action'] === 'removed' ? '−' : '+' ?>
                                            <?= htmlspecialchars($mod['component_name']) ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            <div class="mt-sm">
                                <span class="badge badge-<?= $isCancelled ? 'danger' : ($item['status'] === 'pending' ? 'warning' : ($item['status'] === 'ready' ? 'success' : 'info')) ?>">
                                    <?= htmlspecialchars(statusLabel($item['status'])) ?>
                                </span>
                                <?php if ($canEditItem && !$isSeatBill): ?>
                                    <button type="button" class="seat-pill" onclick="openSeatMove(<?= (int) $item['id'] ?>, <?= (int) $item['seat'] ?>)" title="<?= te('seat_move_title') ?>">
                                        <i class="fas fa-chair"></i> <?= $item['seat'] ? te('seat') . ' ' . (int) $item['seat'] : te('seat_shared') ?>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="item-qty">
                            <?php if ($canEditItem): ?>
                                <button onclick="changeQuantity(<?= $item['id'] ?>, -1, <?= $isSentItem ? 'true' : 'false' ?>)">−</button>
                                <span><?= $item['quantity'] ?></span>
                                <button onclick="changeQuantity(<?= $item['id'] ?>, 1, <?= $isSentItem ? 'true' : 'false' ?>)">+</button>
                            <?php else: ?>
                                <span><?= $item['quantity'] ?>x</span>
                            <?php endif; ?>
                        </div>
                        <div class="item-total"><?= formatCurrency($item['total_price']) ?></div>
                    </div>
                <?php endforeach; ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <?php if ($seatBills): ?>
            <div class="seat-bills">
                <div class="seat-group-head"><strong><?= te('seat_bills') ?></strong></div>
                <?php foreach ($seatBills as $sb): ?>
                    <a class="seat-bill-row" href="/waiter/order.php?order=<?= (int) $sb['id'] ?>">
                        <span><i class="fas fa-user"></i> <?= te('seat') ?> <?= (int) $sb['seat'] ?></span>
                        <span class="d-flex align-center gap-sm">
                            <?= formatCurrency($sb['total']) ?>
                            <span class="badge badge-<?= $sb['status'] === 'paid' ? 'success' : 'warning' ?>"><?= htmlspecialchars(statusLabel($sb['status'])) ?></span>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="order-totals">
            <div class="total-row">
                <span><?= te('items_label') ?></span>
                <span id="itemsTotal"><?= formatCurrency($order['subtotal'] - ($order['number_of_people'] * $order['cover_charge_per_person'])) ?></span>
            </div>
            <div class="total-row">
                <span><?= te('cover') ?> (<?= $order['number_of_people'] ?> × <?= formatCurrency($order['cover_charge_per_person']) ?>)</span>
                <span><?= formatCurrency($order['number_of_people'] * $order['cover_charge_per_person']) ?></span>
            </div>
            <?php if ($order['discount_amount'] > 0): ?>
                <div class="total-row text-danger">
                    <span><?= te('discount') ?></span>
                    <span>-<?= formatCurrency($order['discount_amount']) ?></span>
                </div>
            <?php endif; ?>
            <div class="total-row grand-total">
                <span><?= te('total') ?></span>
                <span id="grandTotal"><?= formatCurrency($order['total']) ?></span>
            </div>
        </div>

        <div class="order-actions">
            <?php if ($isEditable && $pendingCount > 0): ?>
                <button class="btn btn-primary" onclick="sendOrderToKitchen()">
                    <i class="fas fa-fire"></i>
                    <?= $wasSent ? te('send_additions') : te('send_to_kitchen') ?>
                    <span class="badge badge-light"><?= $pendingCount ?></span>
                </button>
            <?php endif; ?>
            <button class="btn btn-warning" onclick="billSeat(null)">
                <i class="fas fa-receipt"></i> <?= te('bill') ?>
            </button>
        </div>
    </div>
</div>

<?php if (!empty($tills)): ?>
<!-- Choose till to send the bill to -->
<div class="modal-overlay" id="tillPickModal">
    <div class="modal" style="max-width: 460px;">
        <div class="modal-header">
            <h3><?= te('send_bill_to_till') ?></h3>
            <button class="modal-close">&times;</button>
        </div>
        <div class="modal-body">
            <p class="text-muted"><?= te('send_bill_to_till_hint') ?></p>
            <div style="display:flex;flex-direction:column;gap:10px;margin-top:10px;">
                <?php foreach ($tills as $till): ?>
                    <button class="btn btn-success btn-lg" onclick="closeModal('tillPickModal'); requestBillAction(<?= (int) $till['id'] ?>)">
                        <i class="fas fa-cash-register"></i> <?= htmlspecialchars($till['name']) ?>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeModal('tillPickModal')"><?= te('cancel') ?></button>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($isEditable && !$isSeatBill): ?>
<!-- Join tables (large party): one order, one bill across several tables -->
<div class="modal-overlay" id="joinTablesModal">
    <div class="modal" style="max-width: 560px;">
        <div class="modal-header">
            <h3><i class="fas fa-link"></i> <?= te('join_tables') ?></h3>
            <button class="modal-close">&times;</button>
        </div>
        <div class="modal-body">
            <label class="form-label"><?= te('join_current_tables') ?></label>
            <div class="join-list">
                <?php foreach ($orderTables as $ot): ?>
                    <div class="join-row">
                        <span><strong><?= htmlspecialchars($ot['table_number']) ?></strong>
                            <span class="text-muted">· <?= htmlspecialchars($ot['room_name']) ?> · <?= (int) $ot['capacity'] ?> <?= te('seats') ?></span></span>
                        <?php if (!$ot['is_primary']): ?>
                            <button type="button" class="btn btn-sm btn-outline" onclick="unjoinTable(<?= (int) $ot['id'] ?>)"><i class="fas fa-unlink"></i> <?= te('join_remove') ?></button>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <label class="form-label" style="margin-top:16px;"><?= te('join_add_tables') ?></label>
            <?php if (!$freeTables): ?>
                <p class="text-muted"><?= te('join_no_free') ?></p>
            <?php else: ?>
                <div class="join-grid">
                    <?php $lastRoom = null; foreach ($freeTables as $ft): ?>
                        <?php if ($ft['room_name'] !== $lastRoom): $lastRoom = $ft['room_name']; ?>
                            <div class="join-room"><?= htmlspecialchars($ft['room_name']) ?></div>
                        <?php endif; ?>
                        <label class="join-pick">
                            <input type="checkbox" name="join_table" value="<?= (int) $ft['id'] ?>">
                            <span><?= htmlspecialchars($ft['table_number']) ?> <small class="text-muted">(<?= (int) $ft['capacity'] ?>)</small></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <?php if ($freeTables): ?>
                    <button class="btn btn-primary" style="margin-top:10px;" onclick="joinTables()"><i class="fas fa-link"></i> <?= te('join_confirm') ?></button>
                <?php endif; ?>
            <?php endif; ?>

            <label class="form-label" style="margin-top:20px;"><i class="fas fa-object-group"></i> <?= te('merge_title') ?></label>
            <p class="text-muted" style="font-size:.85rem;margin:0 0 8px;"><?= te('merge_hint') ?></p>
            <?php if (!$mergeOrders): ?>
                <p class="text-muted"><?= te('merge_none') ?></p>
            <?php else: ?>
                <div class="join-list" style="max-height:30vh;overflow-y:auto;">
                    <?php foreach ($mergeOrders as $mo): ?>
                        <div class="join-row">
                            <span><strong><?= htmlspecialchars($mo['table_number']) ?></strong>
                                <span class="text-muted">· <?= htmlspecialchars($mo['room_name']) ?> · <?= (int) $mo['number_of_people'] ?> <?= te('guests') ?> · <?= formatCurrency($mo['total']) ?></span></span>
                            <button type="button" class="btn btn-sm btn-primary"
                                    onclick="mergeOrder(<?= (int) $mo['id'] ?>, <?= htmlspecialchars(json_encode($mo['table_number'])) ?>)">
                                <i class="fas fa-object-group"></i> <?= te('merge_btn') ?>
                            </button>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeModal('joinTablesModal')"><?= te('cancel') ?></button>
        </div>
    </div>
</div>

<!-- Move a dish to another seat -->
<div class="modal-overlay" id="seatMoveModal">
    <div class="modal" style="max-width: 420px;">
        <div class="modal-header">
            <h3><i class="fas fa-chair"></i> <?= te('seat_move_title') ?></h3>
            <button class="modal-close">&times;</button>
        </div>
        <div class="modal-body">
            <div class="seat-bar" style="border:none;padding:0;">
                <button type="button" class="seat-chip" data-move-seat="0" onclick="moveItemToSeat(0)"><i class="fas fa-utensils"></i> <?= te('seat_shared') ?></button>
                <?php for ($s = 1; $s <= $seatCount; $s++): if (in_array($s, $billedSeats, true)) continue; ?>
                    <button type="button" class="seat-chip" data-move-seat="<?= $s ?>" onclick="moveItemToSeat(<?= $s ?>)"><?= $s ?></button>
                <?php endfor; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Add Item Modal -->
<div class="modal-overlay" id="addItemModal">
    <div class="modal" style="max-width: 600px;">
        <div class="modal-header">
            <h3 id="modalItemName"><?= te('menu_add_item') ?></h3>
            <button class="modal-close">&times;</button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label class="form-label"><?= te('quantity') ?></label>
                <div class="d-flex align-center gap-md">
                    <button class="btn btn-outline btn-icon" onclick="adjustModalQty(-1)">−</button>
                    <input type="number" id="modalQuantity" class="form-control" value="1" min="1" max="99" style="width: 80px; text-align: center;">
                    <button class="btn btn-outline btn-icon" onclick="adjustModalQty(1)">+</button>
                    <span style="margin-left: auto; font-size: 1.25rem; font-weight: 700;" id="modalItemPrice"></span>
                </div>
            </div>
            
            <div id="componentsSection" class="hidden">
                <label class="form-label"><?= te('customize') ?></label>
                <div id="componentsList" style="display: grid; gap: 8px;"></div>
            </div>

            <div class="form-group mt-lg">
                <label class="form-label"><?= te('special_instructions') ?></label>
                <textarea id="modalNotes" class="form-control" rows="2" placeholder="<?= te('special_instr_ph') ?>"></textarea>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeModal('addItemModal')"><?= te('cancel') ?></button>
            <button class="btn btn-primary" onclick="confirmAddItem()">
                <i class="fas fa-plus"></i> <?= te('add_to_order') ?>
            </button>
        </div>
    </div>
</div>

<script>
const orderId = <?= $orderId ?>;
let selectedItem = null;
let itemComponents = [];
const T = {
    added: <?= json_encode(t('toast_item_added')) ?>,
    addFailed: <?= json_encode(t('toast_item_add_failed')) ?>,
    confirmRemove: <?= json_encode(t('confirm_remove_item')) ?>,
    removed: <?= json_encode(t('toast_item_removed')) ?>,
    qtyUpdated: <?= json_encode(t('toast_qty_updated')) ?>,
    updateFailed: <?= json_encode(t('toast_update_failed')) ?>,
    sentKitchen: <?= json_encode(t('toast_sent_kitchen')) ?>,
    sendKitchenFailed: <?= json_encode(t('toast_send_kitchen_failed')) ?>,
    sentAdditions: <?= json_encode(t('toast_sent_additions')) ?>,
    confirmChangeSent: <?= json_encode(t('confirm_change_sent')) ?>,
    confirmVoidSent: <?= json_encode(t('confirm_void_sent')) ?>,
    workPointNotified: <?= json_encode(t('toast_work_point_notified')) ?>,
    workPointPrintFailed: <?= json_encode(t('toast_work_point_print_failed')) ?>,
    billRequested: <?= json_encode(t('toast_bill_requested')) ?>,
    billFailed: <?= json_encode(t('toast_bill_failed')) ?>,
    joinPick: <?= json_encode(t('join_pick_one')) ?>,
    joined: <?= json_encode(t('toast_tables_joined')) ?>,
    seatBill: <?= json_encode(t('toast_seat_bill')) ?>,
    seatMoved: <?= json_encode(t('toast_seat_moved')) ?>,
    confirmMerge: <?= json_encode(t('merge_confirm')) ?>,
    merged: <?= json_encode(t('toast_merged')) ?>,
};
const HAS_TILLS = <?= !empty($tills) ? 'true' : 'false' ?>;
const SEAT_BILL = <?= $isSeatBill ? 'true' : 'false' ?>;

/* ---- Bill by seat ----
 * The chosen seat survives the reload after each added dish, so the waiter
 * can take one guest's whole order without re-picking the seat. */
const SEAT_KEY = 'order-seat-' + orderId;
let activeSeat = 0;
try { activeSeat = parseInt(sessionStorage.getItem(SEAT_KEY), 10) || 0; } catch (e) {}
function setActiveSeat(seat) {
    const chip = document.querySelector(`.seat-bar [data-seat="${seat}"]`);
    if (!chip || chip.disabled) seat = 0; // seat gone (billed) → back to the table
    activeSeat = seat;
    try { sessionStorage.setItem(SEAT_KEY, String(seat)); } catch (e) {}
    document.querySelectorAll('.seat-bar [data-seat]').forEach(c => c.classList.toggle('active', parseInt(c.dataset.seat, 10) === seat));
}
setActiveSeat(activeSeat);

// seat = null → the whole table's bill; a number → just that seat.
let pendingBillSeat = null;
function billSeat(seat) {
    pendingBillSeat = seat;
    if (HAS_TILLS) openModal('tillPickModal'); else requestBillAction();
}

let seatMoveItemId = null;
function openSeatMove(orderItemId, currentSeat) {
    seatMoveItemId = orderItemId;
    document.querySelectorAll('[data-move-seat]').forEach(c => c.classList.toggle('active', parseInt(c.dataset.moveSeat, 10) === currentSeat));
    openModal('seatMoveModal');
}
async function moveItemToSeat(seat) {
    try {
        await apiCall('/api/orders.php', 'POST', { action: 'set_item_seat', order_item_id: seatMoveItemId, seat });
        showToast(T.seatMoved, 'success');
        location.reload();
    } catch (e) { /* apiCall already showed the reason */ }
}

/* ---- Merge another occupied table into this order ---- */
async function mergeOrder(sourceOrderId, tableLabel) {
    if (!await confirmAction(`${T.confirmMerge} ${tableLabel}?`)) return;
    try {
        await apiCall('/api/orders.php', 'POST', { action: 'merge_order', order_id: orderId, source_order_id: sourceOrderId });
        showToast(T.merged, 'success');
        location.reload();
    } catch (e) { /* apiCall already showed the reason */ }
}

/* ---- Joined tables (large party) ---- */
async function joinTables() {
    const ids = [...document.querySelectorAll('input[name=join_table]:checked')].map(c => parseInt(c.value, 10));
    if (!ids.length) { showToast(T.joinPick, 'error'); return; }
    try {
        await apiCall('/api/orders.php', 'POST', { action: 'join_tables', order_id: orderId, table_ids: ids });
        showToast(T.joined, 'success');
        location.reload();
    } catch (e) { /* apiCall already showed the reason */ }
}
async function unjoinTable(tableId) {
    try {
        await apiCall('/api/orders.php', 'POST', { action: 'unjoin_table', order_id: orderId, table_id: tableId });
        location.reload();
    } catch (e) { /* apiCall already showed the reason */ }
}

async function selectMenuItem(item, allowComposition) {
    selectedItem = item;
    
    document.getElementById('modalItemName').textContent = item.name;
    document.getElementById('modalQuantity').value = 1;
    document.getElementById('modalItemPrice').textContent = formatCurrency(item.base_price);
    document.getElementById('modalNotes').value = '';
    
    // Load components if allowed
    const componentsSection = document.getElementById('componentsSection');
    const componentsList = document.getElementById('componentsList');
    
    if (allowComposition) {
        try {
            const response = await fetch(`/api/menu.php?action=components&item_id=${item.id}`);
            const data = await response.json();
            
            if (data.success && data.components.length > 0) {
                itemComponents = data.components;
                componentsList.innerHTML = data.components.map(comp => `
                    <label style="display: flex; align-items: center; gap: 8px; padding: 8px; background: var(--bg-light); border-radius: 6px; cursor: pointer;">
                        <input type="checkbox" 
                               data-component-id="${comp.id}"
                               data-component-name="${escapeHtml(comp.component_name)}"
                               data-is-default="${comp.is_default}"
                               data-extra-price="${comp.extra_price}"
                               ${comp.is_default ? 'checked' : ''}>
                        <span style="flex: 1;">${escapeHtml(comp.component_name)}</span>
                        ${comp.extra_price > 0 ? `<span class="text-primary">+${formatCurrency(comp.extra_price)}</span>` : ''}
                    </label>
                `).join('');
                componentsSection.classList.remove('hidden');
            } else {
                componentsSection.classList.add('hidden');
            }
        } catch (error) {
            componentsSection.classList.add('hidden');
        }
    } else {
        componentsSection.classList.add('hidden');
    }
    
    openModal('addItemModal');
    updateModalPrice();
}

function adjustModalQty(delta) {
    const input = document.getElementById('modalQuantity');
    let val = parseInt(input.value) + delta;
    if (val < 1) val = 1;
    if (val > 99) val = 99;
    input.value = val;
    updateModalPrice();
}

function updateModalPrice() {
    if (!selectedItem) return;
    
    const qty = parseInt(document.getElementById('modalQuantity').value) || 1;
    let price = parseFloat(selectedItem.base_price) * qty;
    
    // Add extras
    document.querySelectorAll('#componentsList input[type="checkbox"]').forEach(cb => {
        const isDefault = cb.dataset.isDefault === '1';
        const extraPrice = parseFloat(cb.dataset.extraPrice) || 0;
        
        if (cb.checked && !isDefault && extraPrice > 0) {
            price += extraPrice * qty;
        }
    });
    
    document.getElementById('modalItemPrice').textContent = formatCurrency(price);
}

// Add event listeners for component checkboxes
document.getElementById('componentsList').addEventListener('change', updateModalPrice);

async function confirmAddItem() {
    if (!selectedItem) return;
    
    const quantity = parseInt(document.getElementById('modalQuantity').value) || 1;
    const notes = document.getElementById('modalNotes').value.trim();
    
    // Gather modifications
    const modifications = [];
    document.querySelectorAll('#componentsList input[type="checkbox"]').forEach(cb => {
        const isDefault = cb.dataset.isDefault === '1';
        const isChecked = cb.checked;
        
        if (isDefault && !isChecked) {
            // Removed default component
            modifications.push({
                component_name: cb.dataset.componentName,
                action: 'removed',
                extra_price: 0
            });
        } else if (!isDefault && isChecked) {
            // Added optional component
            modifications.push({
                component_name: cb.dataset.componentName,
                action: 'added',
                extra_price: parseFloat(cb.dataset.extraPrice) || 0
            });
        }
    });
    
    try {
        const result = await addItemToOrder(orderId, selectedItem.id, quantity, notes, modifications, SEAT_BILL ? null : activeSeat);
        
        if (result.success) {
            showToast(T.added, 'success');
            closeModal('addItemModal');
            location.reload(); // Refresh to show new item
        }
    } catch (error) {
        showToast(T.addFailed, 'error');
    }
}

/**
 * isSent = the dish is already at a work point. Changing it there is a real
 * action in the kitchen, not just a line on a screen: confirm it, then say
 * whether the work point's printer actually heard about it.
 */
async function changeQuantity(orderItemId, delta, isSent = false) {
    const itemEl = document.querySelector(`[data-item-id="${orderItemId}"]`);
    const qtySpan = itemEl.querySelector('.item-qty span');
    const currentQty = parseInt(qtySpan.textContent);
    let newQty = currentQty + delta;

    if (newQty < 1) {
        if (!await confirmAction(isSent ? T.confirmVoidSent : T.confirmRemove)) return;
        newQty = 0;
    } else if (isSent && !await confirmAction(T.confirmChangeSent)) {
        return;
    }

    try {
        const result = newQty === 0
            ? await removeItem(orderItemId)
            : await updateItemQuantity(orderItemId, newQty);

        if (!result.success) {
            showToast(result.message || T.updateFailed, 'error');
            return;
        }

        if (result.reprinted) {
            showToast(result.printed ? T.workPointNotified : T.workPointPrintFailed,
                      result.printed ? 'success' : 'error');
        } else {
            showToast(newQty === 0 ? T.removed : T.qtyUpdated, newQty === 0 ? 'info' : 'success');
        }
        setTimeout(() => location.reload(), result.reprinted ? 1400 : 300);
    } catch (error) {
        showToast(T.updateFailed, 'error');
    }
}

async function sendOrderToKitchen() {
    try {
        const result = await sendToKitchen(orderId);

        if (!result.success) {
            showToast(result.message || T.sendKitchenFailed, 'error');
            return;
        }

        // One slip per work point the dishes belong to; a dead printer must not
        // read as a clean send.
        if (!result.printed) {
            showToast(T.workPointPrintFailed, 'error');
        } else {
            showToast(result.addition ? T.sentAdditions : T.sentKitchen, 'success');
        }
        setTimeout(() => location.reload(), result.printed ? 300 : 1400);
    } catch (error) {
        showToast(T.sendKitchenFailed, 'error');
    }
}

async function requestBillAction(tillId = null) {
    const seat = pendingBillSeat;
    pendingBillSeat = null;
    try {
        if (seat) {
            const body = { action: 'request_seat_bill', order_id: orderId, seat };
            if (tillId) body.till_id = tillId;
            await apiCall('/api/orders.php', 'POST', body);
            showToast(T.seatBill, 'success');
            location.reload();
            return;
        }
        const result = await requestBill(orderId, tillId);
        if (result.success) {
            showToast(T.billRequested, 'success');
            location.reload();
        }
    } catch (error) {
        showToast(T.billFailed, 'error');
    }
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
