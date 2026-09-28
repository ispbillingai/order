<?php
/**
 * Customer table page — what a table's QR code opens (/t.php?k=<token>).
 * The guest sees the table's order and how each dish is doing, and can ask for
 * the bill, call the waiter or ask for a change to a dish. No login: the QR's
 * secret token identifies the table. Data comes from /api/guest.php.
 */

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/table_requests.php';

$token = (string) ($_GET['k'] ?? '');
$table = tableByQrToken($token);
$ws    = getDBConnection()->query("SELECT name FROM workspaces LIMIT 1")->fetch();
$brand = $ws['name'] ?? t('app_name');

$L = [
    'bill_sent'    => t('guest_bill_sent'),
    'waiter_sent'  => t('guest_waiter_sent'),
    'change_sent'  => t('guest_change_sent'),
    'req_open'     => t('guest_req_open'),
    'req_seen'     => t('guest_req_seen'),
    'req_bill'     => t('guest_req_bill'),
    'req_waiter'   => t('guest_req_waiter'),
    'req_change'   => t('guest_req_change'),
    'seat'         => t('seat'),
    'pick_dish'    => t('guest_pick_dish'),
    'write_change' => t('guest_write_change'),
    'failed'       => t('guest_failed'),
    'paid'         => t('guest_paid'),
];
header('Cache-Control: no-store');
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(currentLang()) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="robots" content="noindex">
<title><?= htmlspecialchars($brand) ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;600;700&display=swap" rel="stylesheet">
<style>
:root { --p: #e8590c; --ink: #1f2937; --muted: #6b7280; --line: #e5e7eb; --bg: #f7f5f2; --ok: #16a34a; }
* { box-sizing: border-box; }
body { margin: 0; font-family: 'DM Sans', system-ui, sans-serif; background: var(--bg); color: var(--ink); padding: env(safe-area-inset-top) 0 calc(120px + env(safe-area-inset-bottom)); }
header { background: var(--ink); color: #fff; padding: 18px 18px 22px; }
header .brand { font-size: .85rem; opacity: .75; letter-spacing: .04em; text-transform: uppercase; }
header h1 { margin: 4px 0 0; font-size: 1.6rem; }
.lang { float: right; font-size: .8rem; }
.lang a { color: #fff; opacity: .6; text-decoration: none; margin-left: 8px; font-weight: 700; }
.lang a.on { opacity: 1; text-decoration: underline; }
main { padding: 16px; max-width: 560px; margin: 0 auto; }
.card { background: #fff; border-radius: 14px; padding: 14px 16px; box-shadow: 0 1px 3px rgba(0,0,0,.06); margin-bottom: 14px; }
.card h2 { font-size: 1rem; margin: 0 0 8px; }
.dish { display: flex; justify-content: space-between; gap: 10px; padding: 10px 0; border-bottom: 1px solid var(--line); }
.dish:last-child { border-bottom: 0; }
.dish .n { font-weight: 600; }
.dish .s { font-size: .8rem; color: var(--muted); margin-top: 2px; }
.st { font-size: .75rem; font-weight: 700; padding: 3px 9px; border-radius: 999px; white-space: nowrap; align-self: center; }
.st-pending { background: #fef3c7; color: #92400e; }
.st-in_kitchen { background: #dbeafe; color: #1e40af; }
.st-ready { background: #dcfce7; color: #166534; }
.st-served { background: #f3f4f6; color: #4b5563; }
.total { display: flex; justify-content: space-between; font-size: 1.2rem; font-weight: 700; padding-top: 10px; }
.empty { text-align: center; color: var(--muted); padding: 18px 0; }
.req { display: flex; align-items: center; gap: 10px; padding: 8px 0; font-size: .95rem; }
.req i { color: var(--p); }
.req.seen i { color: var(--ok); }
.actions { position: fixed; left: 0; right: 0; bottom: 0; background: #fff; border-top: 1px solid var(--line); padding: 10px 12px calc(10px + env(safe-area-inset-bottom)); display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; }
.actions button { border: 0; border-radius: 12px; padding: 12px 6px; font: inherit; font-weight: 700; font-size: .85rem; color: #fff; display: flex; flex-direction: column; align-items: center; gap: 6px; cursor: pointer; }
.actions button i { font-size: 1.3rem; }
.actions button:disabled { opacity: .45; }
.a-bill { background: var(--ok); } .a-waiter { background: #2563eb; } .a-change { background: var(--p); }
.sheet-bg { position: fixed; inset: 0; background: rgba(0,0,0,.45); display: none; align-items: flex-end; z-index: 10; }
.sheet-bg.on { display: flex; }
.sheet { background: #fff; width: 100%; border-radius: 18px 18px 0 0; padding: 18px 16px calc(18px + env(safe-area-inset-bottom)); max-height: 85vh; overflow-y: auto; }
.sheet h3 { margin: 0 0 12px; }
.pick { display: flex; align-items: center; gap: 10px; padding: 12px; border: 1px solid var(--line); border-radius: 10px; margin-bottom: 8px; }
.pick:has(input:checked) { border-color: var(--p); background: #fff7ed; }
textarea { width: 100%; border: 1px solid var(--line); border-radius: 10px; padding: 10px; font: inherit; min-height: 80px; margin-top: 6px; }
.sheet .row { display: flex; gap: 8px; margin-top: 12px; }
.sheet .row button { flex: 1; padding: 13px; border-radius: 10px; border: 0; font: inherit; font-weight: 700; }
.btn-go { background: var(--p); color: #fff; } .btn-no { background: #f3f4f6; }
.toast { position: fixed; left: 50%; top: 16px; transform: translateX(-50%); background: var(--ink); color: #fff; padding: 12px 18px; border-radius: 12px; z-index: 20; display: none; max-width: 90vw; text-align: center; }
.bad { text-align: center; padding: 60px 20px; }
</style>
</head>
<body>
<header>
    <span class="lang">
        <?php foreach (langLabels() as $label => $code): ?>
            <a href="<?= htmlspecialchars(langSwitchUrl($code)) ?>" class="<?= $code === currentLang() ? 'on' : '' ?>"><?= $label ?></a>
        <?php endforeach; ?>
    </span>
    <div class="brand"><?= htmlspecialchars($brand) ?></div>
    <h1><?php if ($table): ?><?= te('table') ?> <span id="tableName"><?= htmlspecialchars($table['table_number']) ?></span><?php else: ?><?= te('guest_welcome') ?><?php endif; ?></h1>
</header>

<?php if (!$table): ?>
    <main><div class="card bad"><i class="fas fa-qrcode" style="font-size:2.5rem;color:var(--muted);"></i><p><?= te('guest_bad_qr') ?></p></div></main>
<?php else: ?>
<main>
    <div class="card" id="requestsCard" hidden>
        <h2><?= te('guest_your_requests') ?></h2>
        <div id="requestsList"></div>
    </div>
    <div class="card">
        <h2><?= te('guest_your_order') ?></h2>
        <div id="dishes"><div class="empty"><?= te('loading') ?></div></div>
        <div class="total" id="totalRow" hidden><span><?= te('guest_to_pay') ?></span><span id="total"></span></div>
    </div>
</main>

<div class="actions">
    <button class="a-bill" id="btnBill" onclick="ask('bill')"><i class="fas fa-receipt"></i><?= te('guest_btn_bill') ?></button>
    <button class="a-waiter" onclick="ask('waiter')"><i class="fas fa-hand"></i><?= te('guest_btn_waiter') ?></button>
    <button class="a-change" id="btnChange" onclick="openChange()"><i class="fas fa-pen"></i><?= te('guest_btn_change') ?></button>
</div>

<div class="sheet-bg" id="changeSheet" onclick="if (event.target === this) closeChange()">
    <div class="sheet">
        <h3><?= te('guest_change_title') ?></h3>
        <div id="changePicks"></div>
        <label for="changeMsg" style="font-weight:600;"><?= te('guest_change_what') ?></label>
        <textarea id="changeMsg" maxlength="300" placeholder="<?= te('guest_change_ph') ?>"></textarea>
        <div class="row">
            <button class="btn-no" onclick="closeChange()"><?= te('cancel') ?></button>
            <button class="btn-go" onclick="sendChange()"><?= te('guest_send') ?></button>
        </div>
    </div>
</div>
<div class="toast" id="toast"></div>

<script>
const K = <?= json_encode($token) ?>;
const L = <?= json_encode($L, JSON_UNESCAPED_UNICODE) ?>;
let state = null;
const $ = id => document.getElementById(id);
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

function toast(msg) {
    const t = $('toast'); t.textContent = msg; t.style.display = 'block';
    clearTimeout(toast.h); toast.h = setTimeout(() => t.style.display = 'none', 3500);
}

function render(s) {
    state = s;
    if (s.table) $('tableName').textContent = s.table;
    $('dishes').innerHTML = s.items.length ? s.items.map(i => `
        <div class="dish">
            <div><div class="n">${i.quantity}× ${esc(i.name)}</div>
                 <div class="s">${i.seat ? esc(L.seat) + ' ' + i.seat : ''}${i.paid ? (i.seat ? ' · ' : '') + esc(L.paid) : ''}</div></div>
            <span class="st st-${esc(i.status)}">${esc(i.label)}</span>
        </div>`).join('')
        : `<div class="empty"><?= te('guest_no_order') ?></div>`;
    $('totalRow').hidden = !s.has_order;
    $('total').textContent = s.total_fmt;
    $('btnBill').disabled = !s.has_order;
    $('btnChange').disabled = !s.items.some(i => i.changeable);

    const label = { bill: L.req_bill, waiter: L.req_waiter, change: L.req_change };
    $('requestsCard').hidden = !s.requests.length;
    $('requestsList').innerHTML = s.requests.map(r => `
        <div class="req ${r.status === 'seen' ? 'seen' : ''}">
            <i class="fas ${r.status === 'seen' ? 'fa-person-walking' : 'fa-clock'}"></i>
            <div><strong>${esc(label[r.type])}${r.item_name ? ': ' + esc(r.item_name) : ''}</strong><br>
                 <small>${esc(r.status === 'seen' ? L.req_seen : L.req_open)}</small></div>
        </div>`).join('');
}

async function load() {
    try {
        const r = await fetch('/api/guest.php?k=' + encodeURIComponent(K), { cache: 'no-store' });
        const s = await r.json();
        if (s.success) render(s);
    } catch (e) { /* offline for a moment — next poll retries */ }
}

async function send(body) {
    try {
        const r = await fetch('/api/guest.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(Object.assign({ k: K }, body)) });
        const s = await r.json();
        if (!s.success) { toast(s.message || L.failed); return false; }
        render(s);
        return true;
    } catch (e) { toast(L.failed); return false; }
}

async function ask(type) {
    if (await send({ type })) toast(type === 'bill' ? L.bill_sent : L.waiter_sent);
}

function openChange() {
    const dishes = (state?.items || []).filter(i => i.changeable);
    $('changePicks').innerHTML = dishes.map(i => `
        <label class="pick"><input type="radio" name="dish" value="${i.id}">
            <span>${i.quantity}× ${esc(i.name)}${i.seat ? ' · ' + esc(L.seat) + ' ' + i.seat : ''}</span></label>`).join('');
    $('changeMsg').value = '';
    $('changeSheet').classList.add('on');
}
function closeChange() { $('changeSheet').classList.remove('on'); }
async function sendChange() {
    const dish = document.querySelector('input[name=dish]:checked');
    const message = $('changeMsg').value.trim();
    if (!dish) { toast(L.pick_dish); return; }
    if (!message) { toast(L.write_change); return; }
    if (await send({ type: 'change', order_item_id: parseInt(dish.value, 10), message })) {
        closeChange();
        toast(L.change_sent);
    }
}

load();
setInterval(load, 15000);
</script>
<?php endif; ?>
</body>
</html>
