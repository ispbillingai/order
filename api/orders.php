<?php
/**
 * Orders API
 * Restaurant POS System
 */

require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    jsonResponse(['success' => false, 'message' => 'Unauthorized'], 401);
}

$pdo = getDBConnection();
$user = getCurrentUser();

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
            
            // Update quantity
            $totalPrice = $item['unit_price'] * $quantity;
            $stmt = $pdo->prepare("UPDATE order_items SET quantity = ?, total_price = ? WHERE id = ?");
            $stmt->execute([$quantity, $totalPrice, $orderItemId]);
            
            // Recalculate totals
            calculateOrderTotals($item['order_id']);
            
            jsonResponse(['success' => true]);
            break;
            
        case 'remove_item':
            $orderItemId = $input['order_item_id'] ?? null;
            
            if (!$orderItemId) {
                jsonResponse(['success' => false, 'message' => 'Order Item ID required']);
            }
            
            // Get order ID first
            $stmt = $pdo->prepare("SELECT order_id FROM order_items WHERE id = ?");
            $stmt->execute([$orderItemId]);
            $item = $stmt->fetch();
            
            if (!$item) {
                jsonResponse(['success' => false, 'message' => 'Item not found']);
            }
            
            // Update status to cancelled
            $stmt = $pdo->prepare("UPDATE order_items SET status = 'cancelled' WHERE id = ?");
            $stmt->execute([$orderItemId]);
            
            // Recalculate totals
            calculateOrderTotals($item['order_id']);
            
            jsonResponse(['success' => true]);
            break;
            
        case 'send_to_kitchen':
            $orderId = $input['order_id'] ?? null;

            if (!$orderId) {
                jsonResponse(['success' => false, 'message' => 'Order ID required']);
            }

            // Capture the items being sent NOW (still 'pending') so the kitchen
            // ticket prints exactly these dishes — not ones already in the kitchen.
            $stmt = $pdo->prepare("SELECT id FROM order_items WHERE order_id = ? AND status = 'pending'");
            $stmt->execute([$orderId]);
            $sentItemIds = array_map('intval', array_column($stmt->fetchAll(), 'id'));

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

            // Print the kitchen ticket (table number + dishes only, no prices).
            // Non-fatal: if the printer is offline the order is still sent.
            $print = ['ok' => false, 'error' => 'no_items'];
            if (!empty($sentItemIds)) {
                require_once __DIR__ . '/../includes/kitchen_ticket.php';
                $print = printKitchenTicketForOrder((int) $orderId, $sentItemIds);
            }

            jsonResponse(['success' => true, 'printed' => $print['ok'], 'print_error' => $print['error'] ?? null]);
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
