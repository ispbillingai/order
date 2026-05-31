<?php
/**
 * Header Include
 * Restaurant POS System
 */

$currentUser = getCurrentUser();
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
$pageTitle = $pageTitle ?? 'RestoPOS';
$unreadCount = $currentUser ? getUnreadNotificationsCount($currentUser['id']) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= sanitize($pageTitle) ?> - RestoPOS</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Space+Mono:wght@700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="/assets/css/style.css">
    <?php if (isset($extraCss)): ?>
        <?php foreach ((array)$extraCss as $css): ?>
            <link rel="stylesheet" href="<?= $css ?>">
        <?php endforeach; ?>
    <?php endif; ?>
</head>
<body class="role-<?= $currentUser['role'] ?? 'guest' ?>">
    <nav class="main-nav">
        <div class="nav-brand">
            <i class="fas fa-utensils"></i>
            <span>RestoPOS</span>
        </div>
        
        <?php if ($currentUser): ?>
        <div class="nav-links">
            <?php if (hasRole(['admin'])): ?>
                <a href="/admin/index.php" class="<?= strpos($currentPage, 'admin') !== false ? 'active' : '' ?>">
                    <i class="fas fa-cog"></i> Admin
                </a>
            <?php endif; ?>
            
            <?php if (hasRole(['admin', 'waiter'])): ?>
                <a href="/waiter/index.php" class="<?= strpos($currentPage, 'waiter') !== false || $currentPage === 'tables' ? 'active' : '' ?>">
                    <i class="fas fa-clipboard-list"></i> Orders
                </a>
            <?php endif; ?>
            
            <?php if (hasRole(['admin', 'cashier'])): ?>
                <a href="/cashier/index.php" class="<?= strpos($currentPage, 'cashier') !== false ? 'active' : '' ?>">
                    <i class="fas fa-cash-register"></i> Cashier
                </a>
            <?php endif; ?>
            
            <?php if (hasRole(['admin', 'kitchen'])): ?>
                <a href="/kitchen/index.php" class="<?= strpos($currentPage, 'kitchen') !== false ? 'active' : '' ?>">
                    <i class="fas fa-fire-burner"></i> Kitchen
                </a>
            <?php endif; ?>
        </div>
        
        <div class="nav-user">
            <div class="notifications-bell" id="notificationsBell">
                <i class="fas fa-bell"></i>
                <?php if ($unreadCount > 0): ?>
                    <span class="badge"><?= $unreadCount ?></span>
                <?php endif; ?>
            </div>
            
            <div class="user-info">
                <span class="user-name"><?= sanitize($currentUser['full_name']) ?></span>
                <span class="user-role"><?= ucfirst($currentUser['role']) ?></span>
            </div>
            
            <a href="/logout.php" class="btn-logout" title="Logout">
                <i class="fas fa-sign-out-alt"></i>
            </a>
        </div>
        <?php else: ?>
        <div class="nav-links">
            <a href="/login.php">Login</a>
        </div>
        <?php endif; ?>
    </nav>
    
    <div class="notifications-panel" id="notificationsPanel">
        <div class="notifications-header">
            <h3>Notifications</h3>
            <button class="btn-mark-read" onclick="markAllRead()">Mark all read</button>
        </div>
        <div class="notifications-list" id="notificationsList">
            <!-- Notifications loaded via JS -->
        </div>
    </div>
    
    <main class="main-content">
