<?php
/**
 * Common Functions
 * Restaurant POS System
 */

require_once __DIR__ . '/../config/database.php';

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Check if user is logged in
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

/**
 * Get current user
 */
function getCurrentUser() {
    if (!isLoggedIn()) {
        return null;
    }
    
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND active = 1");
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch();
}

/**
 * Check user role
 */
function hasRole($roles) {
    $user = getCurrentUser();
    if (!$user) return false;
    
    if (is_string($roles)) {
        $roles = [$roles];
    }
    
    return in_array($user['role'], $roles);
}

/**
 * Require login
 */
function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: /login.php');
        exit;
    }
}

/**
 * Require specific role
 */
function requireRole($roles) {
    requireLogin();
    if (!hasRole($roles)) {
        header('Location: /unauthorized.php');
        exit;
    }
}

/**
 * Generate unique order number
 */
function generateOrderNumber() {
    return 'ORD-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
}

/**
 * Format currency
 */
function formatCurrency($amount) {
    return CURRENCY_SYMBOL . number_format($amount, 2);
}

/**
 * Sanitize input
 */
function sanitize($input) {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

/**
 * JSON response helper
 */
function jsonResponse($data, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/**
 * Get all rooms
 */
function getRooms() {
    $pdo = getDBConnection();
    $stmt = $pdo->query("SELECT * FROM rooms WHERE active = 1 ORDER BY sort_order");
    return $stmt->fetchAll();
}

/**
 * Get tables by room
 */
function getTablesByRoom($roomId) {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("SELECT * FROM tables_restaurant WHERE room_id = ? ORDER BY table_number");
    $stmt->execute([$roomId]);
    return $stmt->fetchAll();
}

/**
 * Get all tables with room info
 */
function getAllTables() {
    $pdo = getDBConnection();
    $stmt = $pdo->query("
        SELECT t.*, r.name as room_name 
        FROM tables_restaurant t 
        JOIN rooms r ON t.room_id = r.id 
        WHERE r.active = 1 
        ORDER BY r.sort_order, t.table_number
    ");
    return $stmt->fetchAll();
}

/**
 * Get menu categories
 */
function getMenuCategories() {
    $pdo = getDBConnection();
    $stmt = $pdo->query("SELECT * FROM menu_categories WHERE active = 1 ORDER BY sort_order");
    return $stmt->fetchAll();
}

/**
 * Get menu items by category
 */
function getMenuItemsByCategory($categoryId) {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("SELECT * FROM menu_items WHERE category_id = ? AND active = 1 ORDER BY sort_order, name");
    $stmt->execute([$categoryId]);
    return $stmt->fetchAll();
}

/**
 * Get all menu items
 */
function getAllMenuItems() {
    $pdo = getDBConnection();
    $stmt = $pdo->query("
        SELECT mi.*, mc.name as category_name, mc.allow_composition 
        FROM menu_items mi 
        JOIN menu_categories mc ON mi.category_id = mc.id 
        WHERE mi.active = 1 AND mc.active = 1 
        ORDER BY mc.sort_order, mi.sort_order, mi.name
    ");
    return $stmt->fetchAll();
}

/**
 * Get menu item components
 */
function getMenuItemComponents($menuItemId) {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("SELECT * FROM menu_item_components WHERE menu_item_id = ?");
    $stmt->execute([$menuItemId]);
    return $stmt->fetchAll();
}

/**
 * Get order by ID
 */
function getOrderById($orderId) {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("
        SELECT o.*, t.table_number, r.name as room_name, u.full_name as waiter_name
        FROM orders o
        JOIN tables_restaurant t ON o.table_id = t.id
        JOIN rooms r ON o.room_id = r.id
        JOIN users u ON o.waiter_id = u.id
        WHERE o.id = ?
    ");
    $stmt->execute([$orderId]);
    return $stmt->fetch();
}

/**
 * Get order items
 */
function getOrderItems($orderId) {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("
        SELECT oi.*, mi.name as item_name, mc.name as category_name
        FROM order_items oi
        JOIN menu_items mi ON oi.menu_item_id = mi.id
        JOIN menu_categories mc ON mi.category_id = mc.id
        WHERE oi.order_id = ?
        ORDER BY oi.created_at
    ");
    $stmt->execute([$orderId]);
    return $stmt->fetchAll();
}

/**
 * Get item modifications
 */
function getItemModifications($orderItemId) {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("SELECT * FROM order_item_modifications WHERE order_item_id = ?");
    $stmt->execute([$orderItemId]);
    return $stmt->fetchAll();
}

/**
 * Calculate order totals
 */
function calculateOrderTotals($orderId) {
    $pdo = getDBConnection();
    
    // Get order
    $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();
    
    if (!$order) return false;
    
    // Calculate items subtotal
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(total_price), 0) as items_total 
        FROM order_items 
        WHERE order_id = ? AND status != 'cancelled'
    ");
    $stmt->execute([$orderId]);
    $itemsTotal = $stmt->fetch()['items_total'];
    
    // Add cover charges
    $coverCharges = $order['number_of_people'] * $order['cover_charge_per_person'];
    $subtotal = $itemsTotal + $coverCharges;
    
    // Calculate discount
    $discountAmount = 0;
    if ($order['discount_type'] === 'percent') {
        $discountAmount = $subtotal * ($order['discount_value'] / 100);
    } elseif ($order['discount_type'] === 'fixed') {
        $discountAmount = $order['discount_value'];
    }
    
    $total = $subtotal - $discountAmount;
    if ($total < 0) $total = 0;
    
    // Update order
    $stmt = $pdo->prepare("
        UPDATE orders 
        SET subtotal = ?, discount_amount = ?, total = ?, updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$subtotal, $discountAmount, $total, $orderId]);
    
    return [
        'subtotal' => $subtotal,
        'discount_amount' => $discountAmount,
        'total' => $total,
        'cover_charges' => $coverCharges,
        'items_total' => $itemsTotal
    ];
}

/**
 * Create notification
 */
function createNotification($userId, $type, $title, $message, $orderItemId = null, $payload = null) {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("
        INSERT INTO notifications (user_id, order_item_id, type, title, message, payload)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $userId,
        $orderItemId,
        $type,
        $title,
        $message,
        $payload ? json_encode($payload) : null
    ]);
    return $pdo->lastInsertId();
}

/**
 * Log activity
 */
function logActivity($action, $entityType = null, $entityId = null, $details = null) {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("
        INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, ip_address)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $_SESSION['user_id'] ?? null,
        $action,
        $entityType,
        $entityId,
        $details ? json_encode($details) : null,
        $_SERVER['REMOTE_ADDR'] ?? null
    ]);
}

/**
 * Get unread notifications count
 */
function getUnreadNotificationsCount($userId) {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND read_at IS NULL");
    $stmt->execute([$userId]);
    return $stmt->fetch()['count'];
}

/**
 * Get recent notifications
 */
function getRecentNotifications($userId, $limit = 10) {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("
        SELECT * FROM notifications 
        WHERE user_id = ? 
        ORDER BY created_at DESC 
        LIMIT ?
    ");
    $stmt->execute([$userId, $limit]);
    return $stmt->fetchAll();
}
