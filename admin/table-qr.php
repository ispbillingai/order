<?php
/**
 * Admin — table QR codes. One QR per table, to print and put on the table:
 * guests scan it to see their order, ask for the bill, call the waiter or ask
 * for a change to a dish (t.php). "New code" replaces a table's secret, so any
 * copy printed before stops working (e.g. a QR photographed and shared).
 */

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/table_requests.php';
requireRole(['admin']);

$pdo = getDBConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'regenerate') {
    $tableId = (int) ($_POST['table_id'] ?? 0);
    if ($tableId) {
        regenerateTableQrToken($tableId);
        logActivity('table_qr_regenerated', 'tables_restaurant', $tableId);
    }
    header('Location: /admin/table-qr.php?done=1#table-' . $tableId);
    exit;
}

$tables = $pdo->query("
    SELECT t.id, t.table_number, r.name AS room_name
    FROM tables_restaurant t JOIN rooms r ON r.id = t.room_id
    WHERE r.active = 1 AND t.table_number <> 'GLOVO'
    ORDER BY r.sort_order, r.name, t.table_number + 0, t.table_number
")->fetchAll();
foreach ($tables as &$tb) {
    $tb['url'] = tableQrUrl(tableQrToken((int) $tb['id']));
}
unset($tb);

$ws    = $pdo->query("SELECT name FROM workspaces LIMIT 1")->fetch();
$brand = $ws['name'] ?? t('app_name');

$pageTitle = t('table_qr_title');
include __DIR__ . '/../includes/header.php';
?>
<style>
.qr-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: 16px; }
.qr-card { background: #fff; border: 1px solid var(--border-color); border-radius: 14px; padding: 16px; text-align: center; break-inside: avoid; }
.qr-card .brand { font-size: .75rem; text-transform: uppercase; letter-spacing: .06em; color: var(--text-secondary); }
.qr-card .tno { font-size: 1.8rem; font-weight: 800; margin: 2px 0 10px; }
.qr-card .qr { display: flex; justify-content: center; margin: 0 auto 10px; }
.qr-card .hint { font-size: .8rem; color: var(--text-secondary); }
.qr-card .room { font-size: .75rem; color: var(--text-secondary); margin-top: 2px; }
.qr-tools { display: flex; gap: 6px; justify-content: center; margin-top: 10px; }
@media print {
    .main-nav, .admin-sidebar, .page-header, .qr-tools, .no-print, .main-footer, .table-requests-bar { display: none !important; }
    .qr-grid { grid-template-columns: repeat(3, 1fr); }
    .qr-card { border: 1px dashed #999; }
    body { background: #fff; }
}
</style>

<div class="page-header">
    <h1><i class="fas fa-qrcode"></i> <?= te('table_qr_title') ?></h1>
    <button class="btn btn-primary" onclick="window.print()"><i class="fas fa-print"></i> <?= te('table_qr_print') ?></button>
</div>

<p class="text-muted no-print"><?= te('table_qr_intro') ?></p>
<?php if (isset($_GET['done'])): ?>
    <div class="alert alert-success no-print"><?= te('table_qr_regenerated') ?></div>
<?php endif; ?>

<div class="qr-grid">
    <?php foreach ($tables as $tb): ?>
        <div class="qr-card" id="table-<?= (int) $tb['id'] ?>">
            <div class="brand"><?= htmlspecialchars($brand) ?></div>
            <div class="tno"><?= te('table') ?> <?= htmlspecialchars($tb['table_number']) ?></div>
            <div class="qr" data-url="<?= htmlspecialchars($tb['url']) ?>"></div>
            <div class="hint"><?= te('table_qr_hint') ?></div>
            <div class="room"><?= htmlspecialchars($tb['room_name']) ?></div>
            <div class="qr-tools">
                <a class="btn btn-sm btn-outline" href="<?= htmlspecialchars($tb['url']) ?>" target="_blank"><i class="fas fa-up-right-from-square"></i> <?= te('table_qr_open') ?></a>
                <form method="POST" onsubmit="return confirm(<?= htmlspecialchars(json_encode(t('table_qr_regen_confirm'))) ?>);">
                    <input type="hidden" name="action" value="regenerate">
                    <input type="hidden" name="table_id" value="<?= (int) $tb['id'] ?>">
                    <button class="btn btn-sm btn-outline" type="submit"><i class="fas fa-rotate"></i> <?= te('table_qr_regen') ?></button>
                </form>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
document.querySelectorAll('.qr[data-url]').forEach(el => {
    new QRCode(el, { text: el.dataset.url, width: 170, height: 170, correctLevel: QRCode.CorrectLevel.M });
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
