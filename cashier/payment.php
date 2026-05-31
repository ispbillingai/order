<?php
/**
 * Cashier Payment Processing
 * Restaurant POS System
 */

require_once __DIR__ . '/../includes/functions.php';
requireRole(['admin', 'cashier']);

$orderId = $_GET['order'] ?? null;
if (!$orderId) {
    header('Location: /cashier/index.php');
    exit;
}

$order = getOrderById($orderId);
if (!$order || $order['status'] === 'paid') {
    header('Location: /cashier/index.php');
    exit;
}

$orderItems = getOrderItems($orderId);

// Recalculate totals
$totals = calculateOrderTotals($orderId);
$order = getOrderById($orderId); // Refresh after recalc

$pageTitle = "Payment - Order #{$order['order_number']}";

include __DIR__ . '/../includes/header.php';
?>

<style>
.payment-layout {
    display: grid;
    grid-template-columns: 1fr 400px;
    gap: var(--space-xl);
}

@media (max-width: 1024px) {
    .payment-layout {
        grid-template-columns: 1fr;
    }
}

.bill-items {
    max-height: 400px;
    overflow-y: auto;
}

.bill-item {
    display: flex;
    justify-content: space-between;
    padding: var(--space-sm) 0;
    border-bottom: 1px dashed var(--border-color);
}

.bill-item:last-child {
    border-bottom: none;
}

.bill-item .qty {
    color: var(--text-secondary);
    margin-right: var(--space-sm);
}

.numpad {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: var(--space-sm);
}

.numpad button {
    padding: var(--space-lg);
    font-size: 1.5rem;
    font-weight: 700;
    background: var(--bg-light);
    border: none;
    border-radius: var(--radius-md);
    cursor: pointer;
    transition: all var(--transition-fast);
}

.numpad button:hover {
    background: var(--primary);
    color: white;
}

.numpad button.action {
    background: var(--secondary);
    color: white;
}
</style>

<div class="page-header">
    <h1><i class="fas fa-receipt"></i> Process Payment</h1>
    <a href="/cashier/index.php" class="btn btn-outline">
        <i class="fas fa-arrow-left"></i> Back
    </a>
</div>

<div class="payment-layout">
    <!-- Order Details -->
    <div>
        <div class="card mb-lg">
            <div class="card-header">
                <h2>Order #<?= htmlspecialchars($order['order_number']) ?></h2>
                <span class="badge badge-info">
                    Table <?= htmlspecialchars($order['table_number']) ?> • <?= htmlspecialchars($order['room_name']) ?>
                </span>
            </div>
            <div class="card-body">
                <div class="d-flex justify-between mb-md">
                    <span><strong>Waiter:</strong> <?= htmlspecialchars($order['waiter_name']) ?></span>
                    <span><strong>Guests:</strong> <?= $order['number_of_people'] ?></span>
                </div>
                
                <div class="bill-items">
                    <?php foreach ($orderItems as $item): ?>
                        <div class="bill-item">
                            <div>
                                <span class="qty"><?= $item['quantity'] ?>×</span>
                                <?= htmlspecialchars($item['item_name']) ?>
                                <?php if ($item['notes']): ?>
                                    <small class="text-muted d-block"><?= htmlspecialchars($item['notes']) ?></small>
                                <?php endif; ?>
                            </div>
                            <strong><?= formatCurrency($item['total_price']) ?></strong>
                        </div>
                    <?php endforeach; ?>
                    
                    <div class="bill-item">
                        <div>Cover Charge (<?= $order['number_of_people'] ?> × <?= formatCurrency($order['cover_charge_per_person']) ?>)</div>
                        <strong><?= formatCurrency($order['number_of_people'] * $order['cover_charge_per_person']) ?></strong>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Discount Section -->
        <div class="card">
            <div class="card-header">
                <h2><i class="fas fa-percent"></i> Apply Discount</h2>
            </div>
            <div class="card-body">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Discount Type</label>
                        <select id="discountType" class="form-control">
                            <option value="">No Discount</option>
                            <option value="percent" <?= $order['discount_type'] === 'percent' ? 'selected' : '' ?>>Percentage (%)</option>
                            <option value="fixed" <?= $order['discount_type'] === 'fixed' ? 'selected' : '' ?>>Fixed Amount</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Value</label>
                        <input type="number" id="discountValue" class="form-control" 
                               value="<?= $order['discount_value'] ?>" min="0" step="0.01">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Reason (optional)</label>
                    <input type="text" id="discountReason" class="form-control" placeholder="e.g., Manager approval, Loyalty discount">
                </div>
                <button class="btn btn-secondary" onclick="applyDiscountAction()">
                    <i class="fas fa-tag"></i> Apply Discount
                </button>
            </div>
        </div>
    </div>
    
    <!-- Payment Section -->
    <div>
        <div class="payment-summary">
            <h2>Payment Summary</h2>
            
            <div class="total-row" style="color: rgba(255,255,255,0.7);">
                <span>Subtotal</span>
                <span id="subtotalDisplay"><?= formatCurrency($order['subtotal']) ?></span>
            </div>
            
            <?php if ($order['discount_amount'] > 0): ?>
                <div class="total-row" style="color: #e74c3c;">
                    <span>Discount</span>
                    <span id="discountDisplay">-<?= formatCurrency($order['discount_amount']) ?></span>
                </div>
            <?php endif; ?>
            
            <div class="payment-amount" id="totalDisplay">
                <?= formatCurrency($order['total']) ?>
            </div>
            
            <label class="form-label" style="color: white;">Payment Method</label>
            <div class="payment-methods">
                <div class="payment-method selected" data-method="cash" onclick="selectMethod(this)">
                    <i class="fas fa-money-bill-wave"></i>
                    <span>Cash</span>
                </div>
                <div class="payment-method" data-method="card" onclick="selectMethod(this)">
                    <i class="fas fa-credit-card"></i>
                    <span>Card</span>
                </div>
                <div class="payment-method" data-method="mpesa" onclick="selectMethod(this)">
                    <i class="fas fa-mobile-alt"></i>
                    <span>M-Pesa</span>
                </div>
            </div>
        </div>
        
        <div class="card">
            <div class="card-body">
                <div class="form-group">
                    <label class="form-label">Amount Received</label>
                    <input type="text" id="amountReceived" class="form-control" 
                           value="<?= number_format($order['total'], 2) ?>" 
                           style="font-size: 1.5rem; text-align: right; font-weight: 700;">
                </div>
                
                <div class="form-group" id="changeSection" style="display: none;">
                    <label class="form-label">Change Due</label>
                    <div id="changeAmount" style="font-size: 1.5rem; font-weight: 700; color: var(--success);">
                        $0.00
                    </div>
                </div>
                
                <div class="form-group" id="referenceSection" style="display: none;">
                    <label class="form-label">Reference / Transaction ID</label>
                    <input type="text" id="paymentReference" class="form-control" placeholder="Enter reference number">
                </div>
                
                <div class="numpad mb-lg">
                    <button onclick="numpadInput('1')">1</button>
                    <button onclick="numpadInput('2')">2</button>
                    <button onclick="numpadInput('3')">3</button>
                    <button onclick="numpadInput('4')">4</button>
                    <button onclick="numpadInput('5')">5</button>
                    <button onclick="numpadInput('6')">6</button>
                    <button onclick="numpadInput('7')">7</button>
                    <button onclick="numpadInput('8')">8</button>
                    <button onclick="numpadInput('9')">9</button>
                    <button onclick="numpadInput('.')">.</button>
                    <button onclick="numpadInput('0')">0</button>
                    <button onclick="numpadClear()" class="action">C</button>
                </div>
                
                <button class="btn btn-success btn-lg btn-block" onclick="completePayment()">
                    <i class="fas fa-check-circle"></i> Complete Payment
                </button>
            </div>
        </div>
    </div>
</div>

<script>
const orderId = <?= $orderId ?>;
const orderTotal = <?= $order['total'] ?>;
let selectedMethod = 'cash';

function selectMethod(el) {
    document.querySelectorAll('.payment-method').forEach(m => m.classList.remove('selected'));
    el.classList.add('selected');
    selectedMethod = el.dataset.method;
    
    // Show/hide reference field
    document.getElementById('referenceSection').style.display = 
        selectedMethod !== 'cash' ? 'block' : 'none';
    
    // Show/hide change section
    document.getElementById('changeSection').style.display = 
        selectedMethod === 'cash' ? 'block' : 'none';
}

function numpadInput(char) {
    const input = document.getElementById('amountReceived');
    let value = input.value.replace(/[^0-9.]/g, '');
    
    if (char === '.' && value.includes('.')) return;
    
    value += char;
    input.value = value;
    calculateChange();
}

function numpadClear() {
    document.getElementById('amountReceived').value = '';
    calculateChange();
}

function calculateChange() {
    const received = parseFloat(document.getElementById('amountReceived').value) || 0;
    const change = received - orderTotal;
    
    document.getElementById('changeAmount').textContent = 
        change >= 0 ? formatCurrency(change) : '-' + formatCurrency(Math.abs(change));
    document.getElementById('changeAmount').style.color = 
        change >= 0 ? 'var(--success)' : 'var(--danger)';
}

async function applyDiscountAction() {
    const type = document.getElementById('discountType').value;
    const value = parseFloat(document.getElementById('discountValue').value) || 0;
    const reason = document.getElementById('discountReason').value;
    
    try {
        const result = await applyDiscount(orderId, type, value, reason);
        if (result.success) {
            showToast('Discount applied!', 'success');
            location.reload();
        }
    } catch (error) {
        showToast('Failed to apply discount', 'error');
    }
}

async function completePayment() {
    const amount = parseFloat(document.getElementById('amountReceived').value) || 0;
    const reference = document.getElementById('paymentReference').value;
    
    if (amount < orderTotal) {
        showToast('Amount received is less than total', 'error');
        return;
    }
    
    if (selectedMethod !== 'cash' && !reference) {
        showToast('Please enter transaction reference', 'warning');
        return;
    }
    
    try {
        const result = await processPayment(orderId, selectedMethod, amount, reference);
        if (result.success) {
            showToast('Payment successful!', 'success');
            
            // Show print dialog or redirect
            if (confirm('Print receipt?')) {
                window.open(`/cashier/receipt.php?order=${orderId}`, '_blank');
            }
            
            setTimeout(() => {
                window.location.href = '/cashier/index.php';
            }, 1000);
        }
    } catch (error) {
        showToast('Payment failed', 'error');
    }
}

// Initial calculation
calculateChange();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
