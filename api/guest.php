<?php
/**
 * Guest API — used by the customer page a table's QR code opens (t.php).
 * No login: the table's secret QR token is the only credential, and it only
 * ever gives access to that table's current meal.
 *
 * GET  ?k=<token>                                  → the table's order + open requests
 * GET  ?k=<token>&menu=1                          → the menu (to swap a dish)
 * POST {k, type: bill|waiter|change, order_item_id?, replacement_menu_item_id?, message?} → new request
 */

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/table_requests.php';
require_once __DIR__ . '/../includes/whatsapp_guest.php';
i18n_prefer_browser('it');

header('Content-Type: application/json');
header('Cache-Control: no-store');

$input = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? (json_decode(file_get_contents('php://input'), true) ?: [])
    : $_GET;
$table = tableByQrToken((string) ($input['k'] ?? ''));
if (!$table) {
    jsonResponse(['success' => false, 'message' => t('guest_bad_qr')], 404);
}

/** What the guest may see: dishes, their progress, the total — no staff data. */
function guestState(array $table): array
{
    $order = tableCurrentOrder($table);
    $items = [];
    $total = 0.0;
    if ($order) {
        [$rows, $total] = tableMealItems((int) $order['id']);
        foreach ($rows as $r) {
            $items[] = [
                'id'        => (int) $r['id'],
                'name'      => $r['item_name'],
                'quantity'  => (int) $r['quantity'],
                'seat'      => $r['seat'] !== null ? (int) $r['seat'] : null,
                'status'    => $r['status'],
                'label'     => t('guest_st_' . $r['status']),
                'paid'      => $r['order_status'] === 'paid',
                'changeable'=> in_array($r['status'], GUEST_CHANGEABLE_STATUSES, true) && $r['order_status'] !== 'paid',
            ];
        }
    }
    $stmt = getDBConnection()->prepare("
        SELECT tr.id, tr.type, tr.status, mi.name AS item_name, rmi.name AS replacement_name
        FROM table_requests tr
        LEFT JOIN order_items oi ON oi.id = tr.order_item_id
        LEFT JOIN menu_items mi ON mi.id = oi.menu_item_id
        LEFT JOIN menu_items rmi ON rmi.id = tr.replacement_menu_item_id
        WHERE tr.table_id = ? AND tr.status <> 'done'
        ORDER BY tr.id
    ");
    $stmt->execute([$table['id']]);

    // Bill on WhatsApp: only for guests who left a number (names/last digits only).
    $waTargets = $order ? array_map(fn($t) => ['key' => $t['key'], 'label' => $t['label']], guestWhatsappTargets($order)) : [];

    return [
        'success'  => true,
        'wa_targets' => $waTargets,
        'table'    => $order ? $order['table_number'] : $table['table_number'],
        'has_order'=> (bool) $order,
        'items'    => $items,
        'total'    => $total,
        'total_fmt'=> formatCurrency($total),
        'requests' => $stmt->fetchAll(),
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // "Bill on WhatsApp": the bill request as usual, plus the receipt copy
    // sent straight away to the chosen guest's number.
    $waTarget = null;
    if (($input['type'] ?? '') === 'bill' && !empty($input['whatsapp'])) {
        $order = tableCurrentOrder($table);
        foreach ($order ? guestWhatsappTargets($order) : [] as $t) {
            if ($t['key'] === (string) $input['whatsapp']) $waTarget = $t;
        }
        if (!$waTarget) {
            jsonResponse(['success' => false, 'message' => t('guest_err_no_wa')]);
        }
        $input['message'] = 'WhatsApp → ' . $waTarget['label'];
    }

    $res = createTableRequest(
        $table,
        (string) ($input['type'] ?? ''),
        isset($input['order_item_id']) ? (int) $input['order_item_id'] : null,
        (string) ($input['message'] ?? ''),
        !empty($input['replacement_menu_item_id']) ? (int) $input['replacement_menu_item_id'] : null
    );
    if (!$res['ok']) {
        jsonResponse(['success' => false, 'message' => t('guest_err_' . $res['error'])]);
    }

    if ($waTarget) {
        // Tapping twice doesn't send two receipts: once every 3 minutes per number.
        $stmt = getDBConnection()->prepare("
            SELECT 1 FROM whatsapp_outbox WHERE kind = 'bill' AND phone = ? AND status <> 'failed'
              AND created_at > NOW() - INTERVAL 3 MINUTE LIMIT 1
        ");
        $stmt->execute([$waTarget['phone']]);
        if (!$stmt->fetchColumn()) {
            $lang = guestLang($waTarget['country']);
            $body = $waTarget['seat'] ? guestSeatBillText((int) $order['id'], $waTarget['seat'], $lang)
                                      : guestBillText((int) $order['id'], $lang);
            queueGuestWhatsapp((int) $order['id'], $waTarget['seat'], 'bill', $waTarget['phone'], $body);
        }
    }
    jsonResponse(guestState($table) + ['request_id' => $res['id'], 'wa_sent_to' => $waTarget['label'] ?? null]);
}

// The menu, for swapping a dish for another one.
if (!empty($_GET['menu'])) {
    jsonResponse(['success' => true, 'menu' => guestMenu()]);
}

jsonResponse(guestState($table));
