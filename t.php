<?php
/**
 * Customer table page — what a table's QR code opens (/t.php?k=<token>).
 * The guest sees the table's order and how each dish is doing, and can ask for
 * the bill, call the waiter or ask for a change to a dish. No login: the QR's
 * secret token identifies the table. Data comes from /api/guest.php.
 */

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/table_requests.php';
require_once __DIR__ . '/includes/restaurant.php';
require_once __DIR__ . '/includes/menu_pdf.php';
i18n_prefer_browser('it');

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
    'pick_new'     => t('guest_pick_new'),
    'swap'         => t('guest_req_swap'),
    'note_opt'     => t('guest_note_optional'),
    'change_what'  => t('guest_change_what'),
    'wa_sent'      => t('guest_wa_sent'),
    'ready_title'  => t('guest_ready_title'),
    'ready_body'   => t('guest_ready_body'),
    'consent_yes'  => t('consent_thanks'),
    'consent_no'   => t('consent_declined_toast'),
    'consent_pick' => t('consent_pick_number'),
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
[hidden] { display: none !important; }
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
.a-wa { background: #25d366; }
.actions.has-wa { grid-template-columns: repeat(2, 1fr); }
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
.step { font-weight: 700; margin: 14px 0 8px; }
.hint-small { font-size: .8rem; color: var(--muted); margin: 4px 0 0; }
.modes { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
.modes button { padding: 12px 8px; border-radius: 10px; border: 1px solid var(--line); background: #fff; font: inherit; font-weight: 700; display: flex; flex-direction: column; align-items: center; gap: 4px; color: var(--ink); }
.modes button.on { border-color: var(--p); background: #fff7ed; color: var(--p); }
.menu-cat { font-size: .78rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: var(--muted); margin: 12px 0 6px; }
.pick .price { margin-left: auto; font-weight: 700; white-space: nowrap; }
.pick .desc { display: block; font-size: .8rem; color: var(--muted); }
#menuPicks { max-height: 38vh; overflow-y: auto; }
.toast { position: fixed; left: 50%; top: 16px; transform: translateX(-50%); background: var(--ink); color: #fff; padding: 12px 18px; border-radius: 12px; z-index: 20; display: none; max-width: 90vw; text-align: center; }
.bad { text-align: center; padding: 60px 20px; }
.bill-wait { font-size: .85rem; color: var(--muted); margin: 10px 0 0; display: flex; gap: 8px; align-items: center; }
.notify-btn { width: 100%; border: 0; background: #fff7ed; border-radius: 12px; padding: 12px; font: inherit; font-weight: 700; color: var(--p); margin-bottom: 14px; cursor: pointer; box-shadow: inset 0 0 0 2px #fed7aa; }
.notify-btn i { margin-right: 6px; animation: bellRing 1.8s ease-in-out infinite; display: inline-block; }
@keyframes bellRing { 0%, 60%, 100% { transform: rotate(0); } 10%, 30% { transform: rotate(-14deg); } 20%, 40% { transform: rotate(14deg); } }
.ready-banner { position: fixed; left: 12px; right: 12px; top: calc(12px + env(safe-area-inset-top)); z-index: 30; background: var(--ok); color: #fff; border-radius: 16px; padding: 16px 18px; box-shadow: 0 10px 30px rgba(0,0,0,.25); display: flex; gap: 14px; align-items: center; animation: readyIn .35s ease-out; }
.ready-banner i { font-size: 1.8rem; }
.ready-banner strong { display: block; font-size: 1.05rem; }
.ready-banner button { margin-left: auto; background: rgba(255,255,255,.2); border: 0; color: #fff; border-radius: 10px; padding: 8px 12px; font: inherit; font-weight: 700; }
@keyframes readyIn { from { transform: translateY(-120%); } to { transform: none; } }
.consent { border: 2px solid #fed7aa; }
.consent h2 i { color: var(--p); }
.consent-text { white-space: pre-wrap; font-size: .9rem; line-height: 1.5; color: #374151; margin: 0 0 12px; max-height: 40vh; overflow-y: auto; }
.consent-btns { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-top: 10px; }
.consent-btns button { padding: 13px; border-radius: 10px; border: 0; font: inherit; font-weight: 700; cursor: pointer; }
.menu-card h2 i { color: var(--p); }
.menu-btns { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; }
.menu-btns a { display: flex; flex-direction: column; align-items: center; gap: 6px; padding: 12px 6px; border-radius: 12px; text-decoration: none; font-weight: 700; font-size: .85rem; text-align: center; }
.menu-btns a i { font-size: 1.3rem; }
.mb-view { background: #fff7ed; color: var(--p); box-shadow: inset 0 0 0 2px #fed7aa; }
.mb-pdf { background: #eff6ff; color: #1d4ed8; box-shadow: inset 0 0 0 2px #bfdbfe; }
.mb-share { background: #ecfdf5; color: #047857; box-shadow: inset 0 0 0 2px #a7f3d0; }
.contacts { text-align: center; color: var(--muted); font-size: .88rem; padding: 6px 4px 0; }
.contacts a { color: inherit; text-decoration: none; }
.contacts .c-line { margin: 4px 0; }
.contacts .c-line i { color: var(--p); margin-right: 5px; }
.contacts .socials { display: flex; justify-content: center; gap: 10px; margin-top: 10px; }
.contacts .socials a { width: 40px; height: 40px; border-radius: 50%; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,.08); display: inline-flex; align-items: center; justify-content: center; font-size: 1.15rem; color: var(--ink); }
.gate { text-align: center; padding: 28px 20px; }
.gate-icon { font-size: 2.2rem; color: var(--p); margin-bottom: 8px; }
.gate-text { color: var(--muted); margin: 6px 0 18px; }
.code-input { width: 100%; max-width: 240px; font: inherit; font-size: 1.8rem; letter-spacing: .35em; text-align: center; padding: 12px; border: 2px solid var(--line); border-radius: 12px; }
.code-input:focus { outline: none; border-color: var(--p); }
.gate-btn { display: block; width: 100%; max-width: 240px; margin: 12px auto 0; padding: 14px; border: 0; border-radius: 12px; font: inherit; font-weight: 700; }
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
<!-- Access: the code the guest got on WhatsApp with the order -->
<?php
// The whole menu: browse, download as PDF, share with the others at the table.
$mLang = currentLang() === 'it' ? 'it' : 'en';
ob_start(); ?>
    <div class="card menu-card">
        <h2><i class="fas fa-book-open"></i> <?= te('menu_card_title') ?></h2>
        <div class="menu-btns">
            <a class="mb-view" href="<?= htmlspecialchars(menuViewUrl($mLang)) ?>" target="_blank" rel="noopener"><i class="fas fa-eye"></i><?= te('menu_view') ?></a>
            <a class="mb-pdf" href="<?= htmlspecialchars(menuPdfUrl($mLang, true)) ?>"><i class="fas fa-file-arrow-down"></i><?= te('menu_download') ?></a>
            <a class="mb-share" href="<?= htmlspecialchars(menuShareUrl($mLang)) ?>" target="_blank" rel="noopener" onclick="return shareMenu(event)"><i class="fas fa-share-nodes"></i><?= te('menu_share') ?></a>
        </div>
        <p class="hint-small"><?= te('menu_share_hint') ?></p>
    </div>
<?php $menuHtml = ob_get_clean(); ?>
<?php
// The restaurant's address and links (Settings), under the page.
$rsInfo = restaurantInfo();
$rsAddr = restaurantAddressLine();
$rsSoc  = restaurantSocialLinks();
ob_start();
if ($rsAddr || !empty($rsInfo['phone']) || !empty($rsInfo['website']) || $rsSoc): ?>
    <div class="contacts">
        <?php if ($rsAddr): ?><div class="c-line"><a href="https://www.google.com/maps/search/?api=1&query=<?= urlencode($brand . ', ' . $rsAddr) ?>" target="_blank" rel="noopener"><i class="fas fa-location-dot"></i><?= htmlspecialchars($rsAddr) ?></a></div><?php endif; ?>
        <?php if (!empty($rsInfo['phone'])): ?><div class="c-line"><a href="tel:<?= htmlspecialchars(preg_replace('/[^\d+]/', '', $rsInfo['phone'])) ?>"><i class="fas fa-phone"></i><?= htmlspecialchars($rsInfo['phone']) ?></a></div><?php endif; ?>
        <?php if (!empty($rsInfo['website'])): ?><div class="c-line"><a href="<?= htmlspecialchars($rsInfo['website']) ?>" target="_blank" rel="noopener"><i class="fas fa-globe"></i><?= htmlspecialchars(preg_replace('~^https?://(www\.)?~i', '', rtrim($rsInfo['website'], '/'))) ?></a></div><?php endif; ?>
        <?php if ($rsSoc): ?>
            <div class="socials">
                <?php foreach ($rsSoc as $sl): ?><a href="<?= htmlspecialchars($sl['url']) ?>" target="_blank" rel="noopener" title="<?= $sl['label'] ?>" aria-label="<?= $sl['label'] ?>"><i class="<?= $sl['icon'] ?>"></i></a><?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
<?php endif;
$contactsHtml = ob_get_clean(); ?>
<main id="gate" hidden>
    <div class="card gate">
        <i class="fas fa-lock gate-icon"></i>
        <div id="gateCode" hidden>
            <h2><?= te('guest_gate_title') ?></h2>
            <p class="gate-text"><?= te('guest_gate_text') ?></p>
            <form onsubmit="unlock(event)">
                <input id="codeInput" class="code-input" type="text" inputmode="numeric" autocomplete="one-time-code"
                       maxlength="7" pattern="[0-9 ]{6,7}" placeholder="••••••" required>
                <button class="btn-go gate-btn" type="submit"><?= te('guest_gate_enter') ?></button>
            </form>
        </div>
        <div id="gatePhone" hidden>
            <h2><?= te('guest_gate_phone_title') ?></h2>
            <p class="gate-text"><?= te('guest_need_phone') ?></p>
        </div>
    </div>
    <?= $menuHtml ?>
    <?= $contactsHtml ?>
</main>

<main id="app" hidden>
    <button class="notify-btn" id="notifyBtn" hidden onclick="enableNotifications()"><i class="fas fa-bell"></i> <?= te('guest_notify_on') ?></button>
    <!-- Marketing consent: the guest decides for their own number -->
    <div class="card consent" id="consentCard" hidden>
        <h2><i class="fas fa-bullhorn"></i> <?= te('consent_card_title') ?></h2>
        <p class="consent-text" id="consentText"></p>
        <div id="consentPicks"></div>
        <div class="consent-btns">
            <button class="btn-no" onclick="giveConsent(false)"><?= te('consent_no') ?></button>
            <button class="btn-go" onclick="giveConsent(true)"><i class="fas fa-check"></i> <?= te('consent_yes') ?></button>
        </div>
    </div>
    <div class="card" id="requestsCard" hidden>
        <h2><?= te('guest_your_requests') ?></h2>
        <div id="requestsList"></div>
    </div>
    <div class="card">
        <h2><?= te('guest_your_order') ?></h2>
        <div id="dishes"><div class="empty"><?= te('loading') ?></div></div>
        <div class="total" id="totalRow" hidden><span><?= te('guest_to_pay') ?></span><span id="total"></span></div>
        <p class="bill-wait" id="billWait" hidden><i class="fas fa-hourglass-half"></i> <?= te('guest_bill_not_ready') ?></p>
    </div>
    <?= $menuHtml ?>
    <?= $contactsHtml ?>
</main>

<div class="actions" id="actionsBar" hidden>
    <button class="a-bill" id="btnBill" onclick="ask('bill')"><i class="fas fa-cash-register"></i><?= te('guest_btn_bill_till') ?></button>
    <button class="a-wa" id="btnBillWa" onclick="billWhatsapp()" hidden><i class="fab fa-whatsapp"></i><?= te('guest_btn_bill_wa') ?></button>
    <button class="a-waiter" onclick="ask('waiter')"><i class="fas fa-hand"></i><?= te('guest_btn_waiter') ?></button>
    <button class="a-change" id="btnChange" onclick="openChange()"><i class="fas fa-pen"></i><?= te('guest_btn_change') ?></button>
</div>

<!-- Bill on WhatsApp: to whom (when more guests left a number) -->
<div class="sheet-bg" id="waSheet" onclick="if (event.target === this) this.classList.remove('on')">
    <div class="sheet">
        <h3><i class="fab fa-whatsapp" style="color:#25d366;"></i> <?= te('guest_wa_pick') ?></h3>
        <div id="waPicks"></div>
        <div class="row"><button class="btn-no" onclick="$('waSheet').classList.remove('on')"><?= te('cancel') ?></button></div>
    </div>
</div>

<div class="sheet-bg" id="changeSheet" onclick="if (event.target === this) closeChange()">
    <div class="sheet">
        <h3><?= te('guest_change_title') ?></h3>
        <div id="changePicks"></div>
        <p id="notChangeable" class="hint-small" hidden><?= te('guest_ready_locked') ?></p>

        <div class="step"><?= te('guest_change_how') ?></div>
        <div class="modes">
            <button type="button" id="modeModify" class="on" onclick="setMode('modify')"><i class="fas fa-pen"></i><?= te('guest_mode_modify') ?></button>
            <button type="button" id="modeSwap" onclick="setMode('swap')"><i class="fas fa-right-left"></i><?= te('guest_mode_swap') ?></button>
        </div>

        <div id="swapBox" hidden>
            <div class="step"><?= te('guest_pick_new') ?></div>
            <div id="menuPicks"><div class="empty"><?= te('loading') ?></div></div>
        </div>

        <label for="changeMsg" class="step" id="msgLabel" style="display:block;"><?= te('guest_change_what') ?></label>
        <textarea id="changeMsg" maxlength="300" placeholder="<?= te('guest_change_ph') ?>"></textarea>
        <div class="row">
            <button class="btn-no" onclick="closeChange()"><?= te('cancel') ?></button>
            <button class="btn-go" onclick="sendChange()"><?= te('guest_send') ?></button>
        </div>
    </div>
</div>
<div class="toast" id="toast"></div>
<div class="ready-banner" id="readyBanner" hidden>
    <i class="fas fa-bell-concierge"></i>
    <div><strong id="readyTitle"></strong><span id="readyBody"></span></div>
    <button onclick="$('readyBanner').hidden = true">OK</button>
</div>

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

// Not let in yet: ask for the code, or explain the number is needed.
function renderLocked(s) {
    state = null;
    if (s.table) $('tableName').textContent = s.table;
    $('app').hidden = true;
    $('actionsBar').hidden = true;
    $('gate').hidden = false;
    $('gateCode').hidden = !s.has_phone;
    $('gatePhone').hidden = !!s.has_phone;
    if (s.has_phone && !renderLocked.focused) { renderLocked.focused = true; $('codeInput').focus(); }
}
async function unlock(e) {
    e.preventDefault();
    if (await send({ action: 'unlock', code: $('codeInput').value })) {
        $('codeInput').value = '';
    }
}

function render(s) {
    if (s.locked) return renderLocked(s);
    state = s;
    $('gate').hidden = true;
    $('app').hidden = false;
    $('actionsBar').hidden = false;
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
    // The bill only once the kitchen is done with every dish.
    $('btnBill').disabled = !s.bill_ready;
    $('btnBillWa').disabled = !s.bill_ready;
    $('billWait').hidden = !s.items.length || s.bill_ready;
    notifyReady(s.items);
    updateNotifyButton();
    // Two bill buttons only when a guest here left a WhatsApp number.
    const wa = (s.wa_targets || []).length > 0;
    $('btnBillWa').hidden = !wa;
    document.querySelector('.actions').classList.toggle('has-wa', wa);
    $('btnChange').disabled = !s.items.some(i => i.changeable);
    renderConsent(s.consent);

    const label = { bill: L.req_bill, waiter: L.req_waiter, change: L.req_change };
    $('requestsCard').hidden = !s.requests.length;
    $('requestsList').innerHTML = s.requests.map(r => `
        <div class="req ${r.status === 'seen' ? 'seen' : ''}">
            <i class="fas ${r.status === 'seen' ? 'fa-person-walking' : 'fa-clock'}"></i>
            <div><strong>${r.replacement_name ? esc(L.swap) + ': ' + esc(r.item_name) + ' → ' + esc(r.replacement_name) : esc(label[r.type]) + (r.item_name ? ': ' + esc(r.item_name) : '')}</strong><br>
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
        if (s.locked && !s.success) { load(); toast(s.message || L.failed); return false; }
        if (!s.success) { toast(s.message || L.failed); return false; }
        render(s);
        return true;
    } catch (e) { toast(L.failed); return false; }
}

// Marketing consent: one card per undecided number; rebuilt only when the
// numbers change, so a poll doesn't undo the guest's pick.
function renderConsent(c) {
    const box = $('consentCard');
    box.hidden = !c;
    if (!c) { renderConsent.sig = ''; return; }
    const sig = c.targets.map(t => t.key).join('|') + c.text;
    if (sig === renderConsent.sig) return;
    renderConsent.sig = sig;
    $('consentText').textContent = c.text;
    $('consentPicks').innerHTML = c.targets.length < 2 ? '' :
        `<div class="step" style="margin-top:0;">${esc(L.consent_pick)}</div>` + c.targets.map((t, i) => `
        <label class="pick"><input type="radio" name="consentTarget" value="${esc(t.key)}" ${i === 0 ? 'checked' : ''}> ${esc(t.label)}</label>`).join('');
    renderConsent.single = c.targets.length === 1 ? c.targets[0].key : null;
}
async function giveConsent(accept) {
    const target = renderConsent.single || document.querySelector('input[name=consentTarget]:checked')?.value;
    if (!target) return;
    if (await send({ action: 'consent', target, accept })) toast(accept ? L.consent_yes : L.consent_no);
}

// Share the menu: the phone's own share sheet when there is one, else WhatsApp.
function shareMenu(e) {
    if (!navigator.share) return true;               // follow the WhatsApp link
    e.preventDefault();
    navigator.share({ title: <?= json_encode(restaurantName()) ?>, text: <?= json_encode(tIn($mLang, 'menu_share_short', ['restaurant' => restaurantName()])) ?>, url: <?= json_encode(menuViewUrl($mLang)) ?> })
        .catch(() => {});
    return false;
}

async function ask(type) {
    if (await send({ type })) toast(type === 'bill' ? L.bill_sent : L.waiter_sent);
}

// Bill on WhatsApp: straight away with one number, otherwise pick the guest.
function billWhatsapp() {
    const targets = state?.wa_targets || [];
    if (targets.length === 1) return sendBillWhatsapp(targets[0]);
    $('waPicks').innerHTML = targets.map((t, i) => `
        <button class="pick" style="width:100%;background:#fff;font:inherit;text-align:left;" onclick="sendBillWhatsapp(state.wa_targets[${i}])">
            <i class="fab fa-whatsapp" style="color:#25d366;"></i> <span>${esc(t.label)}</span></button>`).join('');
    $('waSheet').classList.add('on');
}
async function sendBillWhatsapp(target) {
    $('waSheet').classList.remove('on');
    if (await send({ type: 'bill', whatsapp: target.key })) toast(L.wa_sent.replace('{who}', target.label));
}

function openChange() {
    const dishes = (state?.items || []).filter(i => i.changeable);
    $('notChangeable').hidden = dishes.length === (state?.items || []).length;
    $('changePicks').innerHTML = dishes.map(i => `
        <label class="pick"><input type="radio" name="dish" value="${i.id}">
            <span>${i.quantity}× ${esc(i.name)}${i.seat ? ' · ' + esc(L.seat) + ' ' + i.seat : ''}</span></label>`).join('');
    $('changeMsg').value = '';
    document.querySelectorAll('input[name=newdish]').forEach(r => { r.checked = false; });
    setMode('modify');
    $('changeSheet').classList.add('on');
}
function closeChange() { $('changeSheet').classList.remove('on'); }

// Modify the same dish (write what to change) or swap it for another dish
// from the menu (the note is then optional).
let changeMode = 'modify', menuLoaded = false;
function setMode(mode) {
    changeMode = mode;
    $('modeModify').classList.toggle('on', mode === 'modify');
    $('modeSwap').classList.toggle('on', mode === 'swap');
    $('swapBox').hidden = mode !== 'swap';
    $('msgLabel').textContent = mode === 'swap' ? L.note_opt : L.change_what;
    if (mode === 'swap' && !menuLoaded) loadMenu();
}
async function loadMenu() {
    try {
        const r = await fetch('/api/guest.php?menu=1&k=' + encodeURIComponent(K), { cache: 'no-store' });
        const d = await r.json();
        if (!d.success) throw new Error();
        $('menuPicks').innerHTML = d.menu.map(c => `
            <div class="menu-cat">${esc(c.name)}</div>
            ${c.items.map(i => `
                <label class="pick"><input type="radio" name="newdish" value="${i.id}">
                    <span>${esc(i.name)}${i.description ? `<span class="desc">${esc(i.description)}</span>` : ''}</span>
                    <span class="price">${esc(i.price)}</span></label>`).join('')}`).join('');
        menuLoaded = true;
    } catch (e) { $('menuPicks').innerHTML = `<div class="empty">${esc(L.failed)}</div>`; }
}

async function sendChange() {
    const dish = document.querySelector('input[name=dish]:checked');
    const message = $('changeMsg').value.trim();
    if (!dish) { toast(L.pick_dish); return; }
    const body = { type: 'change', order_item_id: parseInt(dish.value, 10), message };
    if (changeMode === 'swap') {
        const nd = document.querySelector('input[name=newdish]:checked');
        if (!nd) { toast(L.pick_new); return; }
        body.replacement_menu_item_id = parseInt(nd.value, 10);
    } else if (!message) { toast(L.write_change); return; }
    if (await send(body)) {
        closeChange();
        toast(L.change_sent);
    }
}

/* ---- "Your dish is ready": banner + sound + vibration, and a system
 * notification when the guest allowed them. Each dish is announced once. */
const SEEN_KEY = 'ready-' + K;
let seenReady = null;
function notifyReady(items) {
    const readyIds = items.filter(i => i.status === 'ready' || i.status === 'served').map(i => i.id);
    if (seenReady === null) {
        // First look: what was already ready before this page opened isn't news.
        try { seenReady = new Set(JSON.parse(sessionStorage.getItem(SEEN_KEY) || 'null') || readyIds); } catch (e) { seenReady = new Set(readyIds); }
    }
    const fresh = items.filter(i => i.status === 'ready' && !seenReady.has(i.id));
    readyIds.forEach(id => seenReady.add(id));
    try { sessionStorage.setItem(SEEN_KEY, JSON.stringify([...seenReady])); } catch (e) {}
    if (!fresh.length) return;

    const names = fresh.map(i => (i.quantity > 1 ? i.quantity + '× ' : '') + i.name).join(', ');
    const body  = L.ready_body.replace('{dish}', names);
    $('readyTitle').textContent = L.ready_title;
    $('readyBody').textContent = body;
    $('readyBanner').hidden = false;
    clearTimeout(notifyReady.h); notifyReady.h = setTimeout(() => { $('readyBanner').hidden = true; }, 12000);
    try { navigator.vibrate && navigator.vibrate([300, 150, 300, 150, 300]); } catch (e) {}
    playChime();
    showSystemNotification(L.ready_title, body);
}

/* ---- Sound. Phones keep web audio muted until the guest touches the page
 * (and again after every reload), so the context is unlocked on the first
 * touch/key, and the bell button offers it while it is still muted. ---- */
let audioCtx = null;
function audio() {
    if (!audioCtx) {
        const AC = window.AudioContext || window.webkitAudioContext;
        if (!AC) return null;
        audioCtx = new AC();
    }
    return audioCtx;
}
function soundOn() { return !!audioCtx && audioCtx.state === 'running'; }
function unlockAudio() {
    const ctx = audio();
    if (!ctx) return;
    if (ctx.state !== 'running') ctx.resume().catch(() => {});
    // A silent blip: iOS only really unlocks once something has played.
    try { const b = ctx.createBuffer(1, 1, 22050), s = ctx.createBufferSource(); s.buffer = b; s.connect(ctx.destination); s.start(0); } catch (e) {}
    setTimeout(updateNotifyButton, 300);
}
['pointerdown', 'touchstart', 'keydown'].forEach(ev => document.addEventListener(ev, unlockAudio, { passive: true }));

/** "Ding-dong" chime: four rising bell notes, played twice. */
function playChime() {
    const ctx = audio();
    if (!ctx) return;
    if (ctx.state !== 'running') ctx.resume().catch(() => {});
    const notes = [784, 988, 1175, 1568]; // G5 B5 D6 G6
    [0, 0.9].forEach(rep => notes.forEach((f, n) => {
        const t = ctx.currentTime + rep + n * 0.16;
        const o = ctx.createOscillator(), o2 = ctx.createOscillator(), g = ctx.createGain();
        o.type = 'sine'; o.frequency.value = f;
        o2.type = 'triangle'; o2.frequency.value = f * 2;   // a touch of shimmer
        o.connect(g); o2.connect(g); g.connect(ctx.destination);
        g.gain.setValueAtTime(0.0001, t);
        g.gain.exponentialRampToValueAtTime(0.45, t + 0.02);
        g.gain.exponentialRampToValueAtTime(0.0001, t + 0.55);
        o.start(t); o2.start(t); o.stop(t + 0.6); o2.stop(t + 0.6);
    }));
}

let swReg = null;
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('/guest-sw.js').then(r => { swReg = r; }).catch(() => {});
}
/** The bell button: shown while sound is muted or notifications not yet decided. */
function updateNotifyButton() {
    if (!state || !state.items || !state.items.length) { $('notifyBtn').hidden = true; return; }
    const askNotif = 'Notification' in window && Notification.permission === 'default';
    $('notifyBtn').hidden = soundOn() && !askNotif;
}
async function enableNotifications() {
    unlockAudio();
    playChime(); // the guest hears what the "dish ready" alert sounds like
    try { if ('Notification' in window && Notification.permission === 'default') await Notification.requestPermission(); } catch (e) {}
    setTimeout(updateNotifyButton, 300);
}
function showSystemNotification(title, body) {
    if (!('Notification' in window) || Notification.permission !== 'granted') return;
    const opts = { body, tag: 'dish-ready', renotify: true, silent: false, requireInteraction: false,
                   vibrate: [300, 150, 300, 150, 300], data: { url: location.href } };
    try {
        if (swReg && swReg.showNotification) swReg.showNotification(title, opts);
        else new Notification(title, opts);
    } catch (e) {}
}

load();
setInterval(load, 10000);
</script>
<?php endif; ?>
</body>
</html>
