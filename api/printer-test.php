<?php
/**
 * Test a configured printer. Body: { target: 'kitchen' | 'cashier' | 'fiscal' }.
 * Thermal printers print a sample slip; the fiscal printer is only checked for
 * reachability (we never emit a real fiscal document as a "test").
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/devices.php';
require_once __DIR__ . '/../includes/ThermalPrinter.php';

header('Content-Type: application/json');

$u = isLoggedIn() ? getCurrentUser() : null;
if (!$u || !in_array($u['role'], ['admin', 'cashier'], true)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit;
}

$input  = json_decode(file_get_contents('php://input'), true) ?: [];
$target = $input['target'] ?? '';
$stamp  = date('Y-m-d H:i:s');

if ($target === 'kitchen' || $target === 'cashier') {
    $cfgKey  = $target === 'kitchen' ? 'kitchen_printer' : 'cashier_printer';
    $printer = new ThermalPrinter(deviceConfig($cfgKey));
    if (!$printer->isEnabled()) {
        echo json_encode(['ok' => false, 'error' => 'printer_not_configured']);
        exit;
    }
    if ($target === 'kitchen') {
        $res = $printer->printKitchenTicket([
            'title' => 'TEST CUCINA', 'table' => '--',
            'items' => [['qty' => 1, 'name' => 'Test print', 'mods' => [], 'note' => $stamp]],
        ]);
    } else {
        $res = $printer->printBill([
            'brand' => 'RestoPOS', 'title' => 'TEST', 'subtitle' => 'Documento non fiscale',
            'currency' => currencySymbol(),
            'items' => [['qty' => 1, 'name' => 'Test print', 'price' => '0.00']],
            'total_label' => 'TEST', 'total' => '0.00', 'note' => $stamp,
        ]);
    }
    echo json_encode(['ok' => !empty($res['ok']), 'error' => $res['error'] ?? null]);
    exit;
}

if ($target === 'station') {
    $sid = (int) ($input['station_id'] ?? 0);
    if ($sid <= 0) {
        echo json_encode(['ok' => false, 'error' => 'bad_target']);
        exit;
    }
    $pdo  = getDBConnection();
    $stmt = $pdo->prepare("SELECT * FROM stations WHERE id = ? AND active = 1");
    $stmt->execute([$sid]);
    $s = $stmt->fetch();
    if (!$s) {
        echo json_encode(['ok' => false, 'error' => 'not_found']);
        exit;
    }
    $printer = new ThermalPrinter([
        'enabled'  => (int) $s['printer_enabled'] === 1,
        'host'     => $s['printer_host'],
        'port'     => (int) $s['printer_port'],
        'width'    => (int) $s['printer_width'],
        'codepage' => (int) $s['printer_codepage'],
    ]);
    if (!$printer->isEnabled() || (int) $s['printer_enabled'] !== 1) {
        echo json_encode(['ok' => false, 'error' => 'printer_not_configured']);
        exit;
    }
    $res = $printer->printKitchenTicket([
        'title' => mb_strtoupper((string) $s['name'], 'UTF-8'),
        'table' => '--',
        'items' => [['qty' => 1, 'name' => 'Test print', 'mods' => [], 'note' => $stamp]],
    ]);
    echo json_encode(['ok' => !empty($res['ok']), 'error' => $res['error'] ?? null]);
    exit;
}

if ($target === 'fiscal') {
    // Reachability check only — do NOT emit a fiscal receipt as a test.
    $cfg  = deviceConfig('fiscal_printer');
    $base = $cfg['base_url'] ?? '';
    if ($base === '') {
        echo json_encode(['ok' => false, 'error' => 'printer_not_configured']);
        exit;
    }
    $p    = parse_url($base);
    $host = $p['host'] ?? '';
    $port = $p['port'] ?? (($p['scheme'] ?? 'http') === 'https' ? 443 : 80);
    $errno = 0; $errstr = '';
    $fp = @fsockopen($host, (int) $port, $errno, $errstr, 5);
    if ($fp) {
        fclose($fp);
        echo json_encode(['ok' => true]);
    } else {
        echo json_encode(['ok' => false, 'error' => trim("$errno $errstr") ?: 'unreachable']);
    }
    exit;
}

echo json_encode(['ok' => false, 'error' => 'bad_target']);
