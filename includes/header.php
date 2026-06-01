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
<html lang="<?= htmlspecialchars(currentLang()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= sanitize($pageTitle) ?> - RestoPOS</title>
    <style>
        .lang-switch { display:inline-flex; gap:2px; padding:3px; border:1px solid rgba(0,0,0,.12); border-radius:999px; margin-right:10px; }
        .lang-switch a { padding:3px 9px; border-radius:999px; font-size:12px; font-weight:700; text-decoration:none; color:inherit; opacity:.6; }
        .lang-switch a.active { background:var(--primary,#e74c3c); color:#fff; opacity:1; }
    </style>
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
                    <i class="fas fa-cog"></i> <?= te('nav_admin') ?>
                </a>
            <?php endif; ?>

            <?php if (hasRole(['admin', 'waiter'])): ?>
                <a href="/waiter/index.php" class="<?= strpos($currentPage, 'waiter') !== false || $currentPage === 'tables' ? 'active' : '' ?>">
                    <i class="fas fa-clipboard-list"></i> <?= te('nav_orders') ?>
                </a>
            <?php endif; ?>

            <?php if (hasRole(['admin', 'cashier'])): ?>
                <a href="/cashier/index.php" class="<?= strpos($currentPage, 'cashier') !== false ? 'active' : '' ?>">
                    <i class="fas fa-cash-register"></i> <?= te('nav_cashier') ?>
                </a>
            <?php endif; ?>

            <?php if (hasRole(['admin', 'kitchen'])): ?>
                <a href="/kitchen/index.php" class="<?= strpos($currentPage, 'kitchen') !== false ? 'active' : '' ?>">
                    <i class="fas fa-fire-burner"></i> <?= te('nav_kitchen') ?>
                </a>
            <?php endif; ?>
        </div>
        
        <div class="nav-user">
            <div class="lang-switch" aria-label="<?= te('language') ?>">
                <?php foreach (langLabels() as $label => $code): ?>
                    <a href="<?= htmlspecialchars(langSwitchUrl($code)) ?>" class="<?= $code === currentLang() ? 'active' : '' ?>"><?= $label ?></a>
                <?php endforeach; ?>
            </div>
            <div class="notifications-bell" id="notificationsBell">
                <i class="fas fa-bell"></i>
                <?php if ($unreadCount > 0): ?>
                    <span class="badge"><?= $unreadCount ?></span>
                <?php endif; ?>
            </div>
            
            <div class="user-info">
                <span class="user-name"><?= sanitize($currentUser['full_name']) ?></span>
                <span class="user-role"><?= te('role_' . $currentUser['role']) ?></span>
            </div>

            <a href="/logout.php" class="btn-logout" title="<?= te('logout') ?>">
                <i class="fas fa-sign-out-alt"></i>
            </a>
        </div>
        <?php else: ?>
        <div class="nav-links">
            <div class="lang-switch" aria-label="<?= te('language') ?>">
                <?php foreach (langLabels() as $label => $code): ?>
                    <a href="<?= htmlspecialchars(langSwitchUrl($code)) ?>" class="<?= $code === currentLang() ? 'active' : '' ?>"><?= $label ?></a>
                <?php endforeach; ?>
            </div>
            <a href="/login.php"><?= te('nav_login') ?></a>
        </div>
        <?php endif; ?>
    </nav>

    <div class="notifications-panel" id="notificationsPanel">
        <div class="notifications-header">
            <h3><?= te('notifications') ?></h3>
            <button class="btn-mark-read" onclick="markAllRead()"><?= te('mark_all_read') ?></button>
        </div>
        <div class="notifications-list" id="notificationsList">
            <!-- Notifications loaded via JS -->
        </div>
    </div>
    
    <main class="main-content">
