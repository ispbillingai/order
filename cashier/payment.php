<?php
/**
 * Cashier Payment — kiosk-style, behaves like the parking app.
 * Start payment (cash machine) → Cashmatic; Pay by card → Ingenico (RTS POS);
 * both emit an Epson fiscal receipt. M-Pesa / manual stays as a fallback.
 */

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/devices.php';
requireRole(['admin', 'cashier']);

$orderId = $_GET['order'] ?? null;
if (!$orderId) { header('Location: /cashier/index.php'); exit; }

$order = getOrderById($orderId);
if (!$order || $order['status'] === 'paid') { header('Location: /cashier/index.php'); exit; }

calculateOrderTotals($orderId);
$order = getOrderById($orderId); // refresh after recalc
$orderItems = getOrderItems($orderId);

// Route the kiosk to this order's till devices (falls back to global).
$cm     = tillConfigForOrder($order, 'cashmatic');
$pos    = tillConfigForOrder($order, 'pos');
$till   = getTillById(isset($order['till_id']) ? (int) $order['till_id'] : 0);
$sym    = currencySymbol();
$jsCfg  = [
    'order_id'        => (int) $order['id'],
    'total'           => (float) $order['total'],
    'currency_symbol' => $sym,
    'cashmatic'       => !empty($cm['enabled']) && !empty($cm['base_url']),
    'pos'             => !empty($pos['enabled']) && !empty($pos['base_url']),
    'i18n'            => [
        'follow_terminal' => t('follow_terminal'),
        'starting'        => t('starting'),
        'waiting'         => t('waiting_for_cash'),
        'working'         => t('working'),
        'recording'       => t('recording'),
        'card_declined'   => t('js_card_declined'),
        'start_failed'    => t('js_start_failed'),
        'payment_failed'  => t('js_payment_failed'),
        'fiscal_no'       => t('js_fiscal_no'),
        'card_approved'   => t('js_card_approved'),
        'cash_received'   => t('js_cash_received'),
        'change_not_disp' => t('js_change_not_disp'),
        'recorded'        => t('js_recorded'),
        'failed'          => t('js_failed'),
        'pay_by_card'     => t('pay_by_card'),
        'start_cash'      => t('start_payment_cash'),
        'bill_printed'    => t('js_bill_printed'),
    ],
];

$pageTitle = "Payment - Order #{$order['order_number']}";
include __DIR__ . '/../includes/header.php';
?>
<style>
.kiosk-amount { font-size: 3.5rem; font-weight: 800; text-align: center; margin: 10px 0; color: var(--primary); }
.kiosk-amount .cur { font-size: 1.6rem; color: var(--text-secondary); margin-right: 6px; }
.kiosk-actions { display: flex; flex-direction: column; gap: 12px; margin-top: 16px; }
.kiosk-actions button { padding: 18px; font-size: 1.2rem; font-weight: 700; border: none; border-radius: var(--radius-md); cursor: pointer; }
.btn-cash { background: var(--success); color: #fff; }
.btn-card { background: var(--primary); color: #fff; }
.btn-cancel { background: var(--bg-light); color: var(--text); }
.dev-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px dashed var(--border-color); }
.hidden { display: none; }
.dev-status { text-align: center; font-size: 1.1rem; margin: 12px 0; min-height: 1.4em; }
.dev-ok { color: var(--success); } .dev-err { color: var(--danger); }
</style>

<div class="page-header">
    <h1><i class="fas fa-cash-register"></i> <?= te('process_payment') ?></h1>
    <a href="/cashier/index.php" class="btn btn-outline"><i class="fas fa-arrow-left"></i> <?= te('back') ?></a>
</div>

<div class="payment-layout" style="display:grid;grid-template-columns:1fr 420px;gap:24px;">
    <!-- Bill + discount -->
    <div>
        <div class="card mb-lg">
            <div class="card-header">
                <h2><?= te('order_no') ?><?= htmlspecialchars($order['order_number']) ?></h2>
                <div class="d-flex gap-sm align-center">
                    <span class="badge badge-info"><?= te('table') ?> <?= htmlspecialchars($order['table_number']) ?> • <?= htmlspecialchars($order['room_name']) ?></span>
                    <?php if ($till): ?>
                        <span class="badge badge-warning"><i class="fas fa-cash-register"></i> <?= htmlspecialchars($till['name']) ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body">
                <?php foreach ($orderItems as $item): ?>
                    <div class="dev-row">
                        <div><?= (int) $item['quantity'] ?>× <?= htmlspecialchars($item['item_name']) ?></div>
                        <strong><?= formatCurrency($item['total_price']) ?></strong>
                    </div>
                <?php endforeach; ?>
                <div class="dev-row">
                    <div><?= te('cover_charge') ?> (<?= (int) $order['number_of_people'] ?>)</div>
                    <strong><?= formatCurrency($order['number_of_people'] * $order['cover_charge_per_person']) ?></strong>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h2><i class="fas fa-percent"></i> <?= te('apply_discount') ?></h2></div>
            <div class="card-body">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label"><?= te('discount_type') ?></label>
                        <select id="discountType" class="form-control">
                            <option value=""><?= te('no_discount') ?></option>
                            <option value="percent" <?= $order['discount_type'] === 'percent' ? 'selected' : '' ?>><?= te('percentage') ?></option>
                            <option value="fixed" <?= $order['discount_type'] === 'fixed' ? 'selected' : '' ?>><?= te('fixed') ?></option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?= te('discount_value') ?></label>
                        <input type="number" id="discountValue" class="form-control" value="<?= $order['discount_value'] ?>" min="0" step="0.01">
                    </div>
                </div>
                <button class="btn btn-secondary" onclick="applyDiscountAction()"><i class="fas fa-tag"></i> <?= te('apply_recalc') ?></button>
            </div>
        </div>
    </div>

    <!-- Kiosk payment panel -->
    <div>
        <!-- Summary / choose method -->
        <div id="k-choose" class="card">
            <div class="card-body">
                <div style="color:var(--text-secondary);text-align:center;text-transform:uppercase;letter-spacing:.08em;font-size:.8rem;"><?= te('total_to_pay') ?></div>
                <div class="kiosk-amount"><span class="cur"><?= htmlspecialchars($sym) ?></span><?= number_format($order['total'], 2) ?></div>
                <div class="kiosk-actions">
                    <button class="btn-cancel" onclick="printBill(this)"><i class="fas fa-print"></i> <?= te('print_bill') ?></button>
                    <?php if ($jsCfg['cashmatic']): ?><button class="btn-cash" onclick="payCash()"><i class="fas fa-coins"></i> <?= te('start_payment_cash') ?></button><?php endif; ?>
                    <?php if ($jsCfg['pos']): ?><button class="btn-card" onclick="payCard()"><i class="fas fa-credit-card"></i> <?= te('pay_by_card') ?></button><?php endif; ?>
                    <button class="btn-cancel" onclick="toggleManual()"><i class="fas fa-mobile-alt"></i> <?= te('mpesa_manual') ?></button>
                    <button class="btn-cancel" onclick="location.href='/cashier/index.php'"><?= te('cancel') ?></button>
                </div>
                <p id="k-choose-err" class="dev-err" style="margin-top:10px;text-align:center;"></p>
            </div>
        </div>

        <!-- Manual fallback (M-Pesa / cash count / card-ref) -->
        <div id="k-manual" class="card hidden">
            <div class="card-body">
                <div class="form-group">
                    <label class="form-label"><?= te('method') ?></label>
                    <select id="manualMethod" class="form-control">
                        <option value="mpesa"><?= te('mpesa') ?></option>
                        <option value="cash"><?= te('cash_manual') ?></option>
                        <option value="card"><?= te('card_manual') ?></option>
                        <option value="other"><?= te('other') ?></option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label"><?= te('amount_received') ?></label>
                    <input type="number" id="manualAmount" class="form-control" step="0.01" value="<?= number_format($order['total'], 2, '.', '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label"><?= te('reference_optional') ?></label>
                    <input type="text" id="manualRef" class="form-control" placeholder="<?= te('transaction_ref') ?>">
                </div>
                <button class="btn btn-success btn-block" onclick="payManual()"><i class="fas fa-check"></i> <?= te('complete_payment') ?></button>
                <button class="btn btn-outline btn-block" style="margin-top:8px;" onclick="toggleManual()"><?= te('back') ?></button>
            </div>
        </div>

        <!-- Cash machine in progress -->
        <div id="k-cash" class="card hidden">
            <div class="card-body">
                <h2 style="text-align:center;"><?= te('insert_cash') ?></h2>
                <div class="dev-row"><span><?= te('requested') ?></span><b><span id="c-req">0.00</span> <?= htmlspecialchars($sym) ?></b></div>
                <div class="dev-row"><span><?= te('inserted') ?></span><b><span id="c-ins">0.00</span> <?= htmlspecialchars($sym) ?></b></div>
                <div class="dev-row"><span><?= te('change') ?></span><b><span id="c-disp">0.00</span> <?= htmlspecialchars($sym) ?></b></div>
                <div class="dev-status" id="c-status"></div>
                <button class="btn btn-danger btn-block" onclick="cancelCash()"><?= te('cancel_machine') ?></button>
            </div>
        </div>

        <!-- Working / result -->
        <div id="k-busy" class="card hidden"><div class="card-body"><div class="dev-status" id="busy-status"><?= te('working') ?></div></div></div>
        <div id="k-done" class="card hidden">
            <div class="card-body" style="text-align:center;">
                <div style="font-size:3rem;color:var(--success);"><i class="fas fa-check-circle"></i></div>
                <h2 class="dev-ok"><?= te('payment_received') ?></h2>
                <p id="done-receipt" style="color:var(--text-secondary);"></p>
                <a id="done-print" class="btn btn-primary btn-block" href="#"><i class="fas fa-receipt"></i> <?= te('print_order_receipt') ?></a>
                <a class="btn btn-outline btn-block" style="margin-top:8px;" href="/cashier/index.php"><?= te('done') ?></a>
            </div>
        </div>
    </div>
</div>

<script>
const CFG = <?= json_encode($jsCfg, JSON_UNESCAPED_SLASHES) ?>;
const SYM = CFG.currency_symbol;
const $ = id => document.getElementById(id);
const fmtc = c => (c / 100).toFixed(2);
let pollTimer = null, finishing = false;

function showPanel(id) {
    ['k-choose','k-manual','k-cash','k-busy','k-done'].forEach(p => $(p).classList.toggle('hidden', p !== id));
}
function toggleManual() {
    $('k-manual').classList.toggle('hidden');
    $('k-choose').classList.toggle('hidden');
}
async function post(url, body) {
    const r = await fetch(url, { method:'POST', headers:{'Content-Type':'application/json'}, credentials:'same-origin', body: JSON.stringify(body || {}) });
    return r.json();
}
function done(receiptText) {
    $('done-receipt').textContent = receiptText || '';
    $('done-print').href = '/cashier/receipt.php?order=' + CFG.order_id;
    showPanel('k-done');
}

/* ---- Closing: print the non-fiscal proforma bill (with prices) ---- */
async function printBill(btn) {
    const err = $('k-choose-err');
    err.style.color = ''; err.textContent = '';
    if (btn) btn.disabled = true;
    try {
        const r = await post('/api/print-bill.php', { order_id: CFG.order_id });
        if (!r.ok) { err.textContent = (r.error || CFG.i18n.failed); }
        else { err.style.color = 'var(--success)'; err.textContent = CFG.i18n.bill_printed; }
    } catch (e) { err.textContent = e.message; }
    finally { if (btn) btn.disabled = false; }
}

/* ---- Card (Ingenico via RTS POS) ---- */
async function payCard() {
    showPanel('k-busy'); $('busy-status').textContent = CFG.i18n.follow_terminal;
    try {
        const r = await post('/api/card-pay.php', { order_id: CFG.order_id });
        if (!r.ok) { $('k-choose-err').textContent = CFG.i18n.pay_by_card + ': ' + (r.error || CFG.i18n.card_declined); showPanel('k-choose'); return; }
        done(r.receipt && r.receipt.receipt_number ? (CFG.i18n.fiscal_no + r.receipt.receipt_number) : CFG.i18n.card_approved + (r.auth_code ? ' (' + r.auth_code + ')' : ''));
    } catch (e) { $('k-choose-err').textContent = e.message; showPanel('k-choose'); }
}

/* ---- Cash machine (Cashmatic) ---- */
async function payCash() {
    showPanel('k-cash'); finishing = false;
    $('c-req').textContent = CFG.total.toFixed(2);
    $('c-status').textContent = CFG.i18n.starting;
    const sp = await post('/api/cashmatic-start.php', { order_id: CFG.order_id });
    if (!sp.ok) { $('k-choose-err').textContent = CFG.i18n.start_cash + ': ' + (sp.error || CFG.i18n.start_failed); showPanel('k-choose'); return; }
    $('c-status').textContent = CFG.i18n.waiting;
    pollTimer = setTimeout(pollCash, 600);
}
async function pollCash() {
    if (finishing) return;
    let again = false;
    try {
        const r = await post('/api/cashmatic-poll.php');
        if (!r.ok) { $('c-status').textContent = r.error || ''; again = true; return; }
        $('c-req').textContent = fmtc(r.requested); $('c-ins').textContent = fmtc(r.inserted); $('c-disp').textContent = fmtc(r.dispensed);
        if (r.operation !== 'idle') { again = true; return; }
        finishing = true;
        const f = await post('/api/cashmatic-finish.php', { order_id: CFG.order_id });
        if (!f.ok) { alert(CFG.i18n.payment_failed + ': ' + (f.error || f.end || CFG.i18n.failed)); showPanel('k-choose'); return; }
        let msg = f.receipt && f.receipt.receipt_number ? (CFG.i18n.fiscal_no + f.receipt.receipt_number) : CFG.i18n.cash_received;
        if (f.notDispensed > 0) msg += ' — ' + CFG.i18n.change_not_disp + ' ' + SYM + fmtc(f.notDispensed);
        done(msg);
    } catch (e) { $('c-status').textContent = e.message; again = true; }
    finally { if (again && !finishing) pollTimer = setTimeout(pollCash, 400); }
}
async function cancelCash() {
    if (pollTimer) { clearTimeout(pollTimer); pollTimer = null; }
    try { await post('/api/cashmatic-cancel.php'); } catch (e) {}
    showPanel('k-choose');
}

/* ---- Manual / M-Pesa (existing process_payment) ---- */
async function payManual() {
    const method = $('manualMethod').value;
    const amount = parseFloat($('manualAmount').value) || CFG.total;
    const reference = $('manualRef').value;
    showPanel('k-busy'); $('busy-status').textContent = CFG.i18n.recording;
    try {
        const r = await post('/api/payments.php', { action:'process_payment', order_id: CFG.order_id, method, amount, reference });
        if (!r.success) { alert(r.message || CFG.i18n.payment_failed); showPanel('k-choose'); return; }
        done(CFG.i18n.recorded + ' (' + method + ')');
    } catch (e) { alert(e.message); showPanel('k-choose'); }
}

/* ---- Discount ---- */
async function applyDiscountAction() {
    const type = $('discountType').value;
    const value = parseFloat($('discountValue').value) || 0;
    try {
        const r = await post('/api/payments.php', { action:'apply_discount', order_id: CFG.order_id, discount_type: type, discount_value: value });
        if (r.success) location.reload(); else alert(r.message || CFG.i18n.failed);
    } catch (e) { alert(e.message); }
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
