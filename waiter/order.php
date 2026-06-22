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
$selectedCategoryId = $_GET['category'] ?? ($categories[0]['id'] ?? null);
$menuItems = $selectedCategoryId ? getMenuItemsByCategory($selectedCategoryId) : [];

// Get category info for composition check
$pdo = getDBConnection();
$stmt = $pdo->prepare("SELECT allow_composition FROM menu_categories WHERE id = ?");
$stmt->execute([$selectedCategoryId]);
$categoryInfo = $stmt->fetch();
$allowComposition = $categoryInfo ? $categoryInfo['allow_composition'] : 0;

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
    <div><strong><?= te('table') ?>:</strong> <?= htmlspecialchars($order['table_number']) ?></div>
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

        <div class="order-items" id="orderItemsList">
            <?php if (empty($orderItems)): ?>
                <div class="text-center text-muted" style="padding: 40px;">
                    <i class="fas fa-shopping-basket" style="font-size: 2rem; margin-bottom: 16px;"></i>
                    <p><?= te('no_items_yet') ?></p>
                </div>
            <?php else: ?>
                <?php foreach ($orderItems as $item): 
                    $mods = getItemModifications($item['id']);
                ?>
                    <div class="order-item" data-item-id="<?= $item['id'] ?>">
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
                                <span class="badge badge-<?= $item['status'] === 'pending' ? 'warning' : ($item['status'] === 'ready' ? 'success' : 'info') ?>">
                                    <?= htmlspecialchars(statusLabel($item['status'])) ?>
                                </span>
                            </div>
                        </div>
                        <div class="item-qty">
                            <?php if ($item['status'] === 'pending'): ?>
                                <button onclick="changeQuantity(<?= $item['id'] ?>, -1)">−</button>
                                <span><?= $item['quantity'] ?></span>
                                <button onclick="changeQuantity(<?= $item['id'] ?>, 1)">+</button>
                            <?php else: ?>
                                <span><?= $item['quantity'] ?>x</span>
                            <?php endif; ?>
                        </div>
                        <div class="item-total"><?= formatCurrency($item['total_price']) ?></div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
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
            <?php if ($order['status'] === 'open'): ?>
                <button class="btn btn-primary" onclick="sendOrderToKitchen()">
                    <i class="fas fa-fire"></i> <?= te('send_to_kitchen') ?>
                </button>
            <?php endif; ?>
            <?php if (!empty($tills)): ?>
                <button class="btn btn-warning" onclick="openModal('tillPickModal')">
                    <i class="fas fa-receipt"></i> <?= te('bill') ?>
                </button>
            <?php else: ?>
                <button class="btn btn-warning" onclick="requestBillAction()">
                    <i class="fas fa-receipt"></i> <?= te('bill') ?>
                </button>
            <?php endif; ?>
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
    billRequested: <?= json_encode(t('toast_bill_requested')) ?>,
    billFailed: <?= json_encode(t('toast_bill_failed')) ?>,
};

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
        const result = await addItemToOrder(orderId, selectedItem.id, quantity, notes, modifications);
        
        if (result.success) {
            showToast(T.added, 'success');
            closeModal('addItemModal');
            location.reload(); // Refresh to show new item
        }
    } catch (error) {
        showToast(T.addFailed, 'error');
    }
}

async function changeQuantity(orderItemId, delta) {
    const itemEl = document.querySelector(`[data-item-id="${orderItemId}"]`);
    const qtySpan = itemEl.querySelector('.item-qty span');
    let currentQty = parseInt(qtySpan.textContent);
    let newQty = currentQty + delta;
    
    if (newQty < 1) {
        if (await confirmAction(T.confirmRemove)) {
            newQty = 0;
        } else {
            return;
        }
    }

    try {
        if (newQty === 0) {
            await removeItem(orderItemId);
            showToast(T.removed, 'info');
        } else {
            await updateItemQuantity(orderItemId, newQty);
            showToast(T.qtyUpdated, 'success');
        }
        location.reload();
    } catch (error) {
        showToast(T.updateFailed, 'error');
    }
}

async function sendOrderToKitchen() {
    try {
        const result = await sendToKitchen(orderId);
        if (result.success) {
            showToast(T.sentKitchen, 'success');
            location.reload();
        }
    } catch (error) {
        showToast(T.sendKitchenFailed, 'error');
    }
}

async function requestBillAction(tillId = null) {
    try {
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
