<?php
/**
 * Orders API
 * Restaurant POS System
 */

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/kitchen_ticket.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    jsonResponse(['success' => false, 'message' => 'Unauthorized'], 401);
}

$pdo = getDBConnection();
$user = getCurrentUser();

/**
 * A waiter may recall an order and keep working on it (add a dish, change a
 * quantity, cancel a dish) right up until it is paid or cancelled.
 */
function orderIsEditable(PDO $pdo, int $orderId): bool
{
    $stmt = $pdo->prepare("SELECT status FROM orders WHERE id = ?");
    $stmt->execute([$orderId]);
    $status = $stmt->fetchColumn();

    return $status !== false && !in_array($status, ['paid', 'cancelled'], true);
}

// Handle GET requests
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';
    
    if ($action === 'get') {
        $orderId = $_GET['order_id'] ?? null;
        if (!$orderId) {
            jsonResponse(['success' => false, 'message' => 'Order ID required']);
        }
        
        $order = getOrderById($orderId);
        $items = getOrderItems($orderId);
        
        jsonResponse(['success' => true, 'order' => $order, 'items' => $items]);
    }
    
    jsonResponse(['success' => false, 'message' => 'Invalid action']);
}

// Handle POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';
    
    switch ($action) {
        case 'create':
            // Create new order
            $tableId = $input['table_id'] ?? null;
            $numberOfPeople = $input['number_of_people'] ?? 1;
            
            if (!$tableId) {
                jsonResponse(['success' => false, 'message' => 'Table ID required']);
            }
            
            // Check if table is free
            $stmt = $pdo->prepare("SELECT * FROM tables_restaurant WHERE id = ?");
            $stmt->execute([$tableId]);
            $table = $stmt->fetch();
            
            if (!$table) {
                jsonResponse(['success' => false, 'message' => 'Table not found']);
            }
            
            // Get workspace cover charge
            $stmt = $pdo->query("SELECT cover_charge FROM workspaces LIMIT 1");
            $workspace = $stmt->fetch();
            $coverCharge = $workspace['cover_charge'] ?? COVER_CHARGE_DEFAULT;
            
            // Create order
            $orderNumber = generateOrderNumber();
            $stmt = $pdo->prepare("
                INSERT INTO orders (order_number, table_id, room_id, waiter_id, number_of_people, cover_charge_per_person, status)
                VALUES (?, ?, ?, ?, ?, ?, 'open')
            ");
            $stmt->execute([
                $orderNumber,
                $tableId,
                $table['room_id'],
                $user['id'],
                $numberOfPeople,
                $coverCharge
            ]);
            
            $orderId = $pdo->lastInsertId();
            
            // Update table status
            $stmt = $pdo->prepare("UPDATE tables_restaurant SET status = 'occupied', current_order_id = ? WHERE id = ?");
            $stmt->execute([$orderId, $tableId]);
            
            // Calculate initial totals (just cover charges)
            calculateOrderTotals($orderId);
            
            logActivity('order_created', 'orders', $orderId);
            
            jsonResponse(['success' => true, 'order_id' => $orderId, 'order_number' => $orderNumber]);
            break;
            
        case 'add_item':
            $orderId = $input['order_id'] ?? null;
            $menuItemId = $input['menu_item_id'] ?? null;
            $quantity = $input['quantity'] ?? 1;
            $notes = $input['notes'] ?? '';
            $modifications = $input['modifications'] ?? [];
            
            if (!$orderId || !$menuItemId) {
                jsonResponse(['success' => false, 'message' => 'Order ID and Menu Item ID required']);
            }

            // A recalled order can take new dishes; a closed one cannot.
            if (!orderIsEditable($pdo, (int) $orderId)) {
                jsonResponse(['success' => false, 'message' => 'Order is closed']);
            }

            // Get menu item
            $stmt = $pdo->prepare("SELECT * FROM menu_items WHERE id = ?");
            $stmt->execute([$menuItemId]);
            $menuItem = $stmt->fetch();
            
            if (!$menuItem) {
                jsonResponse(['success' => false, 'message' => 'Menu item not found']);
            }
            
            // Calculate price with modifications
            $unitPrice = $menuItem['base_price'];
            foreach ($modifications as $mod) {
                if ($mod['action'] === 'added' && isset($mod['extra_price'])) {
                    $unitPrice += $mod['extra_price'];
                }
            }
            
            $totalPrice = $unitPrice * $quantity;
            
            // Insert order item
            $stmt = $pdo->prepare("
                INSERT INTO order_items (order_id, menu_item_id, quantity, unit_price, total_price, notes)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$orderId, $menuItemId, $quantity, $unitPrice, $totalPrice, $notes]);
            
            $orderItemId = $pdo->lastInsertId();
            
            // Insert modifications
            if (!empty($modifications)) {
                $stmt = $pdo->prepare("
                    INSERT INTO order_item_modifications (order_item_id, component_name, action, extra_price)
                    VALUES (?, ?, ?, ?)
                ");
                foreach ($modifications as $mod) {
                    $stmt->execute([
                        $orderItemId,
                        $mod['component_name'],
                        $mod['action'],
                        $mod['extra_price'] ?? 0
                    ]);
                }
            }
            
            // Recalculate order totals
            calculateOrderTotals($orderId);
            
            jsonResponse(['success' => true, 'order_item_id' => $orderItemId]);
            break;
            
        case 'update_quantity':
            $orderItemId = $input['order_item_id'] ?? null;
            $quantity = $input['quantity'] ?? 1;
            
            if (!$orderItemId) {
                jsonResponse(['success' => false, 'message' => 'Order Item ID required']);
            }
            
            // Get current item
            $stmt = $pdo->prepare("SELECT * FROM order_items WHERE id = ?");
            $stmt->execute([$orderItemId]);
            $item = $stmt->fetch();

            if (!$item) {
                jsonResponse(['success' => false, 'message' => 'Item not found']);
            }
            if ($item['status'] === 'cancelled') {
                jsonResponse(['success' => false, 'message' => 'Item is cancelled']);
            }
            if (!orderIsEditable($pdo, (int) $item['order_id'])) {
                jsonResponse(['success' => false, 'message' => 'Order is closed']);
            }

            $oldQty  = (int) $item['quantity'];
            $wasSent = $item['status'] !== 'pending';

            // Update quantity
            $totalPrice = $item['unit_price'] * $quantity;
            $stmt = $pdo->prepare("UPDATE order_items SET quantity = ?, total_price = ? WHERE id = ?");
            $stmt->execute([$quantity, $totalPrice, $orderItemId]);

            // Recalculate totals
            calculateOrderTotals($item['order_id']);

            // The dish is already being prepared: the work point has to be told
            // it changed, otherwise it cooks the old quantity.
            $print = null;
            if ($wasSent && (int) $quantity !== $oldQty) {
                $print = printOrderChangeTicket(
                    (int) $item['order_id'],
                    (int) $orderItemId,
                    TICKET_CHANGE,
                    $oldQty
                );
                logActivity('order_item_changed', 'order_items', (int) $orderItemId);
            }

            jsonResponse([
                'success'     => true,
                'reprinted'   => $print !== null,
                'printed'     => $print['ok'] ?? null,
                'print_error' => $print['error'] ?? null,
            ]);
            break;

        case 'remove_item':
            $orderItemId = $input['order_item_id'] ?? null;

            if (!$orderItemId) {
                jsonResponse(['success' => false, 'message' => 'Order Item ID required']);
            }

            // Get the item first — its status decides whether a work point is
            // already cooking it.
            $stmt = $pdo->prepare("SELECT id, order_id, status FROM order_items WHERE id = ?");
            $stmt->execute([$orderItemId]);
            $item = $stmt->fetch();

            if (!$item) {
                jsonResponse(['success' => false, 'message' => 'Item not found']);
            }
            if ($item['status'] === 'cancelled') {
                jsonResponse(['success' => true]); // already gone — nothing to undo
            }
            if (!orderIsEditable($pdo, (int) $item['order_id'])) {
                jsonResponse(['success' => false, 'message' => 'Order is closed']);
            }

            $wasSent = $item['status'] !== 'pending';

            // Cancel the dish at its work point BEFORE the row is marked
            // cancelled, so the slip can still name the dish.
            $print = null;
            if ($wasSent) {
                $print = printOrderChangeTicket(
                    (int) $item['order_id'],
                    (int) $orderItemId,
                    TICKET_VOID
                );
                logActivity('order_item_voided', 'order_items', (int) $orderItemId);
            }

            // Update status to cancelled
            $stmt = $pdo->prepare("UPDATE order_items SET status = 'cancelled' WHERE id = ?");
            $stmt->execute([$orderItemId]);

            // Drop it from the kitchen display too.
            $stmt = $pdo->prepare("DELETE FROM kitchen_tickets WHERE order_item_id = ?");
            $stmt->execute([$orderItemId]);

            // Recalculate totals
            calculateOrderTotals($item['order_id']);

            jsonResponse([
                'success'     => true,
                'reprinted'   => $print !== null,
                'printed'     => $print['ok'] ?? null,
                'print_error' => $print['error'] ?? null,
            ]);
            break;
            
        case 'send_to_kitchen':
            $orderId = $input['order_id'] ?? null;

            if (!$orderId) {
                jsonResponse(['success' => false, 'message' => 'Order ID required']);
            }

            $order = getOrderById($orderId);
            if (!$order) {
                jsonResponse(['success' => false, 'message' => 'Order not found']);
            }
            if (!orderIsEditable($pdo, (int) $orderId)) {
                jsonResponse(['success' => false, 'message' => 'Order is closed']);
            }

            // Dishes added after the order was first sent print as an ADDITION,
            // so the work point tops up the table instead of re-cooking it.
            $kind = ($order['status'] === 'open') ? TICKET_NEW : TICKET_ADDITION;

            // Capture the items being sent NOW (still 'pending') so the kitchen
            // ticket prints exactly these dishes — not ones already in the kitchen.
            $stmt = $pdo->prepare("SELECT id FROM order_items WHERE order_id = ? AND status = 'pending'");
            $stmt->execute([$orderId]);
            $sentItemIds = array_map('intval', array_column($stmt->fetchAll(), 'id'));

            if (empty($sentItemIds)) {
                jsonResponse(['success' => false, 'message' => 'No new items to send']);
            }

            // Update pending items to in_kitchen
            $stmt = $pdo->prepare("
                UPDATE order_items
                SET status = 'in_kitchen', sent_to_kitchen_at = NOW()
                WHERE order_id = ? AND status = 'pending'
            ");
            $stmt->execute([$orderId]);

            // Update order status
            $stmt = $pdo->prepare("UPDATE orders SET status = 'sent_to_kitchen' WHERE id = ?");
            $stmt->execute([$orderId]);

            // Create kitchen tickets for pending items
            $stmt = $pdo->prepare("
                INSERT INTO kitchen_tickets (order_id, order_item_id, status)
                SELECT ?, id, 'queued' FROM order_items
                WHERE order_id = ? AND status = 'in_kitchen'
                AND id NOT IN (SELECT order_item_id FROM kitchen_tickets WHERE order_id = ?)
            ");
            $stmt->execute([$orderId, $orderId, $orderId]);

            logActivity('sent_to_kitchen', 'orders', $orderId);

            // Print the work-point tickets (table number + dishes only, no
            // prices): one slip per area the dishes belong to. Non-fatal — if a
            // printer is offline the order is still sent.
            $print = printKitchenTicketForOrder((int) $orderId, $sentItemIds, $order, $kind);

            jsonResponse([
                'success'     => true,
                'addition'    => $kind === TICKET_ADDITION,
                'items'       => count($sentItemIds),
                'tickets'     => $print['tickets'] ?? 0,
                'printed'     => $print['ok'],
                'print_error' => $print['error'] ?? null,
            ]);
            break;
            
        case 'request_bill':
            $orderId = $input['order_id'] ?? null;
            // Till the waiter routed the bill to (NULL = no specific till).
            $tillId  = (isset($input['till_id']) && (int) $input['till_id'] > 0) ? (int) $input['till_id'] : null;

            if (!$orderId) {
                jsonResponse(['success' => false, 'message' => 'Order ID required']);
            }

            // Update order status and stamp the chosen till.
            $stmt = $pdo->prepare("UPDATE orders SET status = 'bill_requested', till_id = ? WHERE id = ?");
            $stmt->execute([$tillId, $orderId]);
            
            // Update table status
            $stmt = $pdo->prepare("
                UPDATE tables_restaurant SET status = 'bill_requested' 
                WHERE current_order_id = ?
            ");
            $stmt->execute([$orderId]);
            
            // Notify cashiers
            $stmt = $pdo->query("SELECT id FROM users WHERE role = 'cashier' AND active = 1");
            $cashiers = $stmt->fetchAll();
            
            $order = getOrderById($orderId);
            foreach ($cashiers as $cashier) {
                createNotification(
                    $cashier['id'],
                    'bill_requested',
                    'Bill Requested',
                    "Table {$order['table_number']} is ready to pay",
                    null,
                    ['order_id' => $orderId]
                );
            }
            
            logActivity('bill_requested', 'orders', $orderId);
            
            jsonResponse(['success' => true]);
            break;
            
        default:
            jsonResponse(['success' => false, 'message' => 'Invalid action']);
    }
}

jsonResponse(['success' => false, 'message' => 'Invalid request method']);
