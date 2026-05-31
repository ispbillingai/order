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
            <?= ucfirst(str_replace('_', ' ', $order['status'])) ?>
        </span>
        <a href="/waiter/index.php" class="btn btn-outline">
            <i class="fas fa-arrow-left"></i> Tables
        </a>
    </div>
</div>

<div class="order-info mb-lg" style="display: flex; gap: 24px; flex-wrap: wrap;">
    <div><strong>Table:</strong> <?= htmlspecialchars($order['table_number']) ?></div>
    <div><strong>Room:</strong> <?= htmlspecialchars($order['room_name']) ?></div>
    <div><strong>Guests:</strong> <?= $order['number_of_people'] ?></div>
    <div><strong>Waiter:</strong> <?= htmlspecialchars($order['waiter_name']) ?></div>
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
                    <p class="text-muted">No items in this category.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Order Panel -->
    <div class="order-panel">
        <div class="order-header">
            <h3>Current Order</h3>
            <div class="order-info">
                Table <?= htmlspecialchars($order['table_number']) ?> • <?= $order['number_of_people'] ?> guests
            </div>
        </div>
        
        <div class="order-items" id="orderItemsList">
            <?php if (empty($orderItems)): ?>
                <div class="text-center text-muted" style="padding: 40px;">
                    <i class="fas fa-shopping-basket" style="font-size: 2rem; margin-bottom: 16px;"></i>
                    <p>No items yet. Select from menu.</p>
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
                                    <?= ucfirst($item['status']) ?>
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
                <span>Items</span>
                <span id="itemsTotal"><?= formatCurrency($order['subtotal'] - ($order['number_of_people'] * $order['cover_charge_per_person'])) ?></span>
            </div>
            <div class="total-row">
                <span>Cover (<?= $order['number_of_people'] ?> × <?= formatCurrency($order['cover_charge_per_person']) ?>)</span>
                <span><?= formatCurrency($order['number_of_people'] * $order['cover_charge_per_person']) ?></span>
            </div>
            <?php if ($order['discount_amount'] > 0): ?>
                <div class="total-row text-danger">
                    <span>Discount</span>
                    <span>-<?= formatCurrency($order['discount_amount']) ?></span>
                </div>
            <?php endif; ?>
            <div class="total-row grand-total">
                <span>Total</span>
                <span id="grandTotal"><?= formatCurrency($order['total']) ?></span>
            </div>
        </div>
        
        <div class="order-actions">
            <?php if ($order['status'] === 'open'): ?>
                <button class="btn btn-primary" onclick="sendOrderToKitchen()">
                    <i class="fas fa-fire"></i> Send to Kitchen
                </button>
            <?php endif; ?>
            <button class="btn btn-warning" onclick="requestBillAction()">
                <i class="fas fa-receipt"></i> Bill
            </button>
        </div>
    </div>
</div>

<!-- Add Item Modal -->
<div class="modal-overlay" id="addItemModal">
    <div class="modal" style="max-width: 600px;">
        <div class="modal-header">
            <h3 id="modalItemName">Add Item</h3>
            <button class="modal-close">&times;</button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label class="form-label">Quantity</label>
                <div class="d-flex align-center gap-md">
                    <button class="btn btn-outline btn-icon" onclick="adjustModalQty(-1)">−</button>
                    <input type="number" id="modalQuantity" class="form-control" value="1" min="1" max="99" style="width: 80px; text-align: center;">
                    <button class="btn btn-outline btn-icon" onclick="adjustModalQty(1)">+</button>
                    <span style="margin-left: auto; font-size: 1.25rem; font-weight: 700;" id="modalItemPrice"></span>
                </div>
            </div>
            
            <div id="componentsSection" class="hidden">
                <label class="form-label">Customize</label>
                <div id="componentsList" style="display: grid; gap: 8px;"></div>
            </div>
            
            <div class="form-group mt-lg">
                <label class="form-label">Special Instructions</label>
                <textarea id="modalNotes" class="form-control" rows="2" placeholder="e.g., well done, no onions, allergies..."></textarea>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeModal('addItemModal')">Cancel</button>
            <button class="btn btn-primary" onclick="confirmAddItem()">
                <i class="fas fa-plus"></i> Add to Order
            </button>
        </div>
    </div>
</div>

<script>
const orderId = <?= $orderId ?>;
let selectedItem = null;
let itemComponents = [];

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
            showToast('Item added!', 'success');
            closeModal('addItemModal');
            location.reload(); // Refresh to show new item
        }
    } catch (error) {
        showToast('Failed to add item', 'error');
    }
}

async function changeQuantity(orderItemId, delta) {
    const itemEl = document.querySelector(`[data-item-id="${orderItemId}"]`);
    const qtySpan = itemEl.querySelector('.item-qty span');
    let currentQty = parseInt(qtySpan.textContent);
    let newQty = currentQty + delta;
    
    if (newQty < 1) {
        if (await confirmAction('Remove this item from order?')) {
            newQty = 0;
        } else {
            return;
        }
    }
    
    try {
        if (newQty === 0) {
            await removeItem(orderItemId);
            showToast('Item removed', 'info');
        } else {
            await updateItemQuantity(orderItemId, newQty);
            showToast('Quantity updated', 'success');
        }
        location.reload();
    } catch (error) {
        showToast('Failed to update', 'error');
    }
}

async function sendOrderToKitchen() {
    try {
        const result = await sendToKitchen(orderId);
        if (result.success) {
            showToast('Order sent to kitchen!', 'success');
            location.reload();
        }
    } catch (error) {
        showToast('Failed to send to kitchen', 'error');
    }
}

async function requestBillAction() {
    try {
        const result = await requestBill(orderId);
        if (result.success) {
            showToast('Bill requested - Cashier notified!', 'success');
            location.reload();
        }
    } catch (error) {
        showToast('Failed to request bill', 'error');
    }
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
