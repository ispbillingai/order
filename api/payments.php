<?php
/**
 * Payments API
 * Restaurant POS System
 */

require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    jsonResponse(['success' => false, 'message' => 'Unauthorized'], 401);
}

$pdo = getDBConnection();
$user = getCurrentUser();

// Handle POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';
    
    switch ($action) {
        case 'apply_discount':
            $orderId = $input['order_id'] ?? null;
            $discountType = $input['discount_type'] ?? null;
            $discountValue = $input['discount_value'] ?? 0;
            $reason = $input['reason'] ?? '';
            
            if (!$orderId) {
                jsonResponse(['success' => false, 'message' => 'Order ID required']);
            }
            
            // Update order discount
            $stmt = $pdo->prepare("
                UPDATE orders 
                SET discount_type = ?, discount_value = ? 
                WHERE id = ?
            ");
            $stmt->execute([
                $discountType ?: null,
                $discountValue,
                $orderId
            ]);
            
            // Recalculate totals
            $totals = calculateOrderTotals($orderId);
            
            logActivity('discount_applied', 'orders', $orderId, [
                'type' => $discountType,
                'value' => $discountValue,
                'reason' => $reason
            ]);
            
            jsonResponse([
                'success' => true, 
                'totals' => $totals
            ]);
            break;
            
        case 'process_payment':
            $orderId = $input['order_id'] ?? null;
            $method = $input['method'] ?? 'cash';
            $amount = $input['amount'] ?? 0;
            $reference = $input['reference'] ?? '';
            
            if (!$orderId || !$amount) {
                jsonResponse(['success' => false, 'message' => 'Order ID and amount required']);
            }
            
            // Get order
            $order = getOrderById($orderId);
            if (!$order) {
                jsonResponse(['success' => false, 'message' => 'Order not found']);
            }
            
            if ($amount < $order['total']) {
                jsonResponse(['success' => false, 'message' => 'Insufficient payment amount']);
            }
            
            // Record payment
            $stmt = $pdo->prepare("
                INSERT INTO payments (order_id, amount, method, reference, received_by)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $orderId,
                $amount,
                $method,
                $reference ?: null,
                $user['id']
            ]);
            
            // Update order status
            $stmt = $pdo->prepare("UPDATE orders SET status = 'paid', closed_at = NOW() WHERE id = ?");
            $stmt->execute([$orderId]);
            
            // Free up the table
            $stmt = $pdo->prepare("
                UPDATE tables_restaurant 
                SET status = 'free', current_order_id = NULL 
                WHERE current_order_id = ?
            ");
            $stmt->execute([$orderId]);
            
            // Notify waiter
            createNotification(
                $order['waiter_id'],
                'table_paid',
                'Payment Complete',
                "Table {$order['table_number']} payment received - " . formatCurrency($amount),
                null,
                ['order_id' => $orderId]
            );
            
            logActivity('payment_received', 'orders', $orderId, [
                'method' => $method,
                'amount' => $amount
            ]);
            
            jsonResponse([
                'success' => true,
                'change' => $amount - $order['total']
            ]);
            break;
            
        default:
            jsonResponse(['success' => false, 'message' => 'Invalid action']);
    }
}

jsonResponse(['success' => false, 'message' => 'Invalid request method']);
