<?php
/**
 * Order ticket printing — when an order is sent, the dishes are split by their
 * WORK POINT (kitchen, bar, pizza oven, grill...) and one thermal slip is
 * printed at each area's printer. A dish's work point comes from its menu
 * category (menu_categories.station_id). Categories with no work point — or a
 * work point without a printer IP — fall back to the default kitchen printer
 * (deviceConfig('kitchen_printer')). Never throws.
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/devices.php';
require_once __DIR__ . '/ThermalPrinter.php';

/**
 * Split $orderItemIds by work point and print one ticket per area. Returns an
 * aggregate result ('ok' is true if at least one ticket printed).
 *
 * @param int[] $orderItemIds order_items.id values just sent
 * @return array{ok:bool, error?:string, tickets?:int}
 */
function printKitchenTicketForOrder(int $orderId, array $orderItemIds, ?array $order = null): array
{
    if (empty($orderItemIds)) {
        return ['ok' => false, 'error' => 'no_items'];
    }

    $pdo   = getDBConnection();
    $order = $order ?? getOrderById($orderId);

    // Resolve each sent item's work point (and its printer) via its category.
    $in  = implode(',', array_fill(0, count($orderItemIds), '?'));
    $sql = "SELECT oi.id AS order_item_id,
                   s.id AS station_id, s.name AS station_name,
                   s.printer_enabled, s.printer_host, s.printer_port,
                   s.printer_width, s.printer_codepage
            FROM order_items oi
            JOIN menu_items mi      ON oi.menu_item_id = mi.id
            JOIN menu_categories mc ON mi.category_id = mc.id
            LEFT JOIN stations s    ON mc.station_id = s.id AND s.active = 1 AND s.type = 'prep'
            WHERE oi.id IN ($in)
            ORDER BY oi.id";
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($orderItemIds);
        $rows = $stmt->fetchAll();
    } catch (Throwable $e) {
        // stations table / station_id column not migrated yet: route everything
        // to the single default kitchen printer (legacy behaviour).
        $rows = array_map(
            static fn($id) => ['order_item_id' => (int) $id, 'station_id' => null],
            $orderItemIds
        );
    }

    // Bucket order-item ids per destination. The 'kitchen' key is the default
    // printer used for any dish whose category has no (active) work point.
    $groups = [];
    foreach ($rows as $r) {
        $hasStation = !empty($r['station_id']) && !empty($r['printer_host']);
        if ($hasStation) {
            $key = 'station_' . $r['station_id'];
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'title' => mb_strtoupper((string) $r['station_name'], 'UTF-8'),
                    'cfg'   => [
                        'enabled'  => (int) $r['printer_enabled'] === 1,
                        'host'     => (string) $r['printer_host'],
                        'port'     => (int) ($r['printer_port'] ?? 9100),
                        'width'    => (int) ($r['printer_width'] ?? 32),
                        'codepage' => (int) ($r['printer_codepage'] ?? 2),
                    ],
                    'ids'   => [],
                ];
            }
            $groups[$key]['ids'][] = (int) $r['order_item_id'];
        } else {
            if (!isset($groups['kitchen'])) {
                $groups['kitchen'] = [
                    'title' => 'CUCINA',
                    'cfg'   => deviceConfig('kitchen_printer'),
                    'ids'   => [],
                ];
            }
            $groups['kitchen']['ids'][] = (int) $r['order_item_id'];
        }
    }

    $anyOk      = false;
    $firstError = null;
    foreach ($groups as $g) {
        $res = printStationTicket($pdo, $g['cfg'], (string) $g['title'], $orderId, $g['ids'], $order);
        if (!empty($res['ok'])) {
            $anyOk = true;
        } elseif ($firstError === null) {
            $firstError = $res['error'] ?? 'error';
        }
    }

    return ['ok' => $anyOk, 'error' => $anyOk ? null : $firstError, 'tickets' => count($groups)];
}

/**
 * Build the dish list for $orderItemIds (with modifications + notes) and print
 * one ticket to the printer described by $cfg. Never throws.
 *
 * @param int[] $orderItemIds
 * @return array{ok:bool, error?:string, bytes?:int}
 */
function printStationTicket(
    PDO $pdo,
    array $cfg,
    string $title,
    int $orderId,
    array $orderItemIds,
    ?array $order
): array {
    $printer = new ThermalPrinter($cfg);
    if (!$printer->isEnabled() || empty($cfg['enabled'])) {
        return ['ok' => false, 'error' => 'printer_not_configured'];
    }
    if (empty($orderItemIds)) {
        return ['ok' => false, 'error' => 'no_items'];
    }

    $in   = implode(',', array_fill(0, count($orderItemIds), '?'));
    $stmt = $pdo->prepare(
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
            $sign   = ($m['action'] === 'removed') ? '- ' : '+ ';
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
        'title'        => $title !== '' ? $title : 'CUCINA',
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
        'station' => $title,
        'ok'      => $res['ok'],
        'error'   => $res['error'] ?? null,
        'items'   => count($items),
    ]);
    if (empty($res['ok'])) {
        error_log('[kitchen-print] order ' . $orderId . ' [' . $title . '] NOT printed: ' . ($res['error'] ?? '?'));
    }
    return $res;
}
