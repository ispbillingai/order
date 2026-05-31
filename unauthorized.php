<?php
/**
 * Unauthorized Access Page
 * Restaurant POS System
 */

require_once __DIR__ . '/includes/functions.php';

$pageTitle = 'Access Denied';

include __DIR__ . '/includes/header.php';
?>

<div style="text-align: center; padding: 100px 20px;">
    <i class="fas fa-lock" style="font-size: 4rem; color: var(--danger); margin-bottom: 24px;"></i>
    <h1 style="margin-bottom: 16px;">Access Denied</h1>
    <p class="text-muted" style="margin-bottom: 24px;">
        You don't have permission to access this page.
    </p>
    <a href="/" class="btn btn-primary">
        <i class="fas fa-home"></i> Go to Dashboard
    </a>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
