<?php
/**
 * Status API (for polling updates)
 * Restaurant POS System
 */

require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    jsonResponse(['success' => false, 'message' => 'Unauthorized'], 401);
}

$pdo = getDBConnection();
$user = getCurrentUser();

// Get various status counts
$data = [
    'success' => true,
    'timestamp' => time()
];

// Unread notifications
$data['unread_notifications'] = getUnreadNotificationsCount($user['id']);

// Role-specific data
switch ($user['role']) {
    case 'waiter':
        // Count of ready dishes for this waiter
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count 
            FROM order_items oi
            JOIN orders o ON oi.order_id = o.id
            WHERE o.waiter_id = ? AND oi.status = 'ready'
        ");
        $stmt->execute([$user['id']]);
        $data['ready_dishes'] = $stmt->fetch()['count'];
        
        // Active orders
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count 
            FROM orders 
            WHERE waiter_id = ? AND status NOT IN ('paid', 'cancelled')
        ");
        $stmt->execute([$user['id']]);
        $data['active_orders'] = $stmt->fetch()['count'];
        break;
        
    case 'kitchen':
        // Pending items
        $stmt = $pdo->query("
            SELECT COUNT(*) as count 
            FROM order_items 
            WHERE status IN ('pending', 'in_kitchen')
        ");
        $data['pending_items'] = $stmt->fetch()['count'];
        break;
        
    case 'cashier':
        // Bills requested
        $stmt = $pdo->query("
            SELECT COUNT(*) as count 
            FROM orders 
            WHERE status = 'bill_requested'
        ");
        $data['pending_bills'] = $stmt->fetch()['count'];
        break;
        
    case 'admin':
        // All active orders
        $stmt = $pdo->query("
            SELECT COUNT(*) as count 
            FROM orders 
            WHERE status NOT IN ('paid', 'cancelled')
        ");
        $data['active_orders'] = $stmt->fetch()['count'];
        
        // Today's revenue
        $stmt = $pdo->query("
            SELECT COALESCE(SUM(total), 0) as total 
            FROM orders 
            WHERE status = 'paid' AND DATE(closed_at) = CURDATE()
        ");
        $data['today_revenue'] = $stmt->fetch()['total'];
        break;
}

jsonResponse($data);
