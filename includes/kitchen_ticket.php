<?php
/**
 * Kitchen ticket printing — builds and sends a thermal ticket for the items
 * just sent to the kitchen (table number + dishes). Never throws.
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/devices.php';
require_once __DIR__ . '/ThermalPrinter.php';

/**
 * Collect the dishes for $orderItemIds (with modifications + notes) and print
 * a kitchen ticket. Pass the order row if you already have it.
 *
 * @param int[] $orderItemIds order_items.id values to put on the ticket
 * @return array{ok:bool, error?:string, bytes?:int}
 */
function printKitchenTicketForOrder(int $orderId, array $orderItemIds, ?array $order = null): array
{
    $cfg     = deviceConfig('kitchen_printer');
    $printer = new ThermalPrinter($cfg);
    if (!$printer->isEnabled() || empty($cfg['enabled'])) {
        return ['ok' => false, 'error' => 'printer_not_configured'];
    }
    if (empty($orderItemIds)) {
        return ['ok' => false, 'error' => 'no_items'];
    }

    $pdo   = getDBConnection();
    $order = $order ?? getOrderById($orderId);

    // Fetch the dishes.
    $in    = implode(',', array_fill(0, count($orderItemIds), '?'));
    $stmt  = $pdo->prepare(
        "SELECT oi.id, oi.quantity, oi.notes, mi.name AS item_name
         FROM order_items oi
         JOIN menu_items mi ON oi.menu_item_id = mi.id
         WHERE oi.id IN ($in)
         ORDER BY oi.id"
    );
    $stmt->execute($orderItemIds);
    $rows = $stmt->fetchAll();

    $modStmt = $pdo->prepare(
        "SELECT component_name, action FROM order_item_modifications WHERE order_item_id = ?"
    );

    $items = [];
    foreach ($rows as $r) {
        $mods = [];
        $modStmt->execute([$r['id']]);
        foreach ($modStmt->fetchAll() as $m) {
            $sign = ($m['action'] === 'removed') ? '- ' : '+ ';
            $mods[] = $sign . $m['component_name'];
        }
        $items[] = [
            'qty'  => (int) $r['quantity'],
            'name' => (string) $r['item_name'],
            'mods' => $mods,
            'note' => (string) ($r['notes'] ?? ''),
        ];
    }

    $ticket = [
        'title'        => 'CUCINA',
        'table_label'  => 'Tavolo',
        'table'        => (string) ($order['table_number'] ?? ''),
        'order_label'  => 'Ordine',
        'order_number' => (string) ($order['order_number'] ?? ''),
        'waiter_label' => 'Cameriere',
        'waiter'       => (string) ($order['waiter_name'] ?? ''),
        'time'         => date('d/m/Y H:i'),
        'items'        => $items,
    ];

    $res = $printer->printKitchenTicket($ticket);
    logDeviceEvent('system', 'kitchen_ticket', $orderId, [
        'ok'    => $res['ok'],
        'error' => $res['error'] ?? null,
        'items' => count($items),
    ]);
    if (!$res['ok']) {
        error_log('[kitchen-print] order ' . $orderId . ' ticket NOT printed: ' . ($res['error'] ?? '?'));
    }
    return $res;
}
