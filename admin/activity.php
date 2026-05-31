<?php
/**
 * Admin Activity Log
 * Restaurant POS System
 */

require_once __DIR__ . '/../includes/functions.php';
requireRole(['admin']);

$pdo = getDBConnection();

// Get recent activity
$stmt = $pdo->query("
    SELECT al.*, u.full_name, u.username
    FROM activity_log al
    LEFT JOIN users u ON al.user_id = u.id
    ORDER BY al.created_at DESC
    LIMIT 100
");
$activities = $stmt->fetchAll();

$pageTitle = 'Activity Log';

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-history"></i> Activity Log</h1>
</div>

<div class="card">
    <table class="data-table">
        <thead>
            <tr>
                <th>Time</th>
                <th>User</th>
                <th>Action</th>
                <th>Entity</th>
                <th>Details</th>
                <th>IP Address</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($activities as $activity): ?>
                <tr>
                    <td><?= date('M j, H:i:s', strtotime($activity['created_at'])) ?></td>
                    <td>
                        <?php if ($activity['full_name']): ?>
                            <?= htmlspecialchars($activity['full_name']) ?>
                            <small class="text-muted">(<?= htmlspecialchars($activity['username']) ?>)</small>
                        <?php else: ?>
                            <span class="text-muted">System</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge badge-<?= 
                            strpos($activity['action'], 'create') !== false ? 'success' : 
                            (strpos($activity['action'], 'delete') !== false ? 'danger' : 
                            (strpos($activity['action'], 'login') !== false ? 'info' : 'primary')) 
                        ?>">
                            <?= htmlspecialchars($activity['action']) ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($activity['entity_type']): ?>
                            <?= htmlspecialchars($activity['entity_type']) ?>
                            <?php if ($activity['entity_id']): ?>
                                <small class="text-muted">#<?= $activity['entity_id'] ?></small>
                            <?php endif; ?>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($activity['details']): ?>
                            <code style="font-size: 0.75rem;"><?= htmlspecialchars(substr($activity['details'], 0, 50)) ?></code>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                    <td><small><?= htmlspecialchars($activity['ip_address'] ?? '-') ?></small></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($activities)): ?>
                <tr>
                    <td colspan="6" class="text-center text-muted" style="padding: 40px;">
                        No activity recorded yet.
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
