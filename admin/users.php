<?php
/**
 * Admin Users Management
 * Restaurant POS System
 */

require_once __DIR__ . '/../includes/functions.php';
requireRole(['admin']);

$pdo = getDBConnection();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add_user') {
        $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO users (username, password, full_name, role, email, phone) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $_POST['username'],
            $password,
            $_POST['full_name'],
            $_POST['role'],
            $_POST['email'] ?? null,
            $_POST['phone'] ?? null
        ]);
        header('Location: /admin/users.php?success=user_added');
        exit;
    }
    
    if ($action === 'toggle_status') {
        $stmt = $pdo->prepare("UPDATE users SET active = NOT active WHERE id = ? AND id != ?");
        $stmt->execute([$_POST['user_id'], $_SESSION['user_id']]);
        header('Location: /admin/users.php?success=status_updated');
        exit;
    }
    
    if ($action === 'reset_password') {
        $password = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
        $stmt->execute([$password, $_POST['user_id']]);
        header('Location: /admin/users.php?success=password_reset');
        exit;
    }
}

// Get all users
$stmt = $pdo->query("SELECT * FROM users ORDER BY role, full_name");
$users = $stmt->fetchAll();

$pageTitle = t('user_management');

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-users"></i> <?= te('user_management') ?></h1>
    <button class="btn btn-primary" onclick="openModal('addUserModal')">
        <i class="fas fa-user-plus"></i> <?= te('add_user') ?>
    </button>
</div>

<?php if (isset($_GET['success'])): ?>
    <div class="alert alert-success mb-lg" style="background: rgba(39,174,96,0.1); color: var(--success); padding: 16px; border-radius: 8px;">
        <i class="fas fa-check-circle"></i>
        <?php
        switch ($_GET['success']) {
            case 'user_added': echo te('msg_user_added'); break;
            case 'status_updated': echo te('msg_status_updated'); break;
            case 'password_reset': echo te('msg_password_reset'); break;
        }
        ?>
    </div>
<?php endif; ?>

<div class="card">
    <table class="data-table">
        <thead>
            <tr>
                <th><?= te('user') ?></th>
                <th><?= te('username') ?></th>
                <th><?= te('role') ?></th>
                <th><?= te('contact') ?></th>
                <th><?= te('status') ?></th>
                <th><?= te('actions') ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($users as $user): ?>
                <tr>
                    <td>
                        <div class="d-flex align-center gap-sm">
                            <div style="width: 40px; height: 40px; background: var(--primary); color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700;">
                                <?= strtoupper(substr($user['full_name'], 0, 1)) ?>
                            </div>
                            <strong><?= htmlspecialchars($user['full_name']) ?></strong>
                        </div>
                    </td>
                    <td><?= htmlspecialchars($user['username']) ?></td>
                    <td>
                        <span class="badge badge-<?= 
                            $user['role'] === 'admin' ? 'danger' : 
                            ($user['role'] === 'waiter' ? 'info' : 
                            ($user['role'] === 'cashier' ? 'success' : 'warning')) 
                        ?>">
                            <?= te('role_' . $user['role']) ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($user['email']): ?>
                            <div><i class="fas fa-envelope text-muted"></i> <?= htmlspecialchars($user['email']) ?></div>
                        <?php endif; ?>
                        <?php if ($user['phone']): ?>
                            <div><i class="fas fa-phone text-muted"></i> <?= htmlspecialchars($user['phone']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($user['active']): ?>
                            <span class="badge badge-success"><?= te('active') ?></span>
                        <?php else: ?>
                            <span class="badge badge-danger"><?= te('inactive') ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="d-flex gap-sm">
                            <button class="btn btn-sm btn-outline" onclick="openResetModal(<?= $user['id'] ?>, '<?= htmlspecialchars($user['username']) ?>')">
                                <i class="fas fa-key"></i>
                            </button>
                            
                            <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="action" value="toggle_status">
                                    <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                    <button type="submit" class="btn btn-sm <?= $user['active'] ? 'btn-warning' : 'btn-success' ?>">
                                        <i class="fas fa-<?= $user['active'] ? 'ban' : 'check' ?>"></i>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Add User Modal -->
<div class="modal-overlay" id="addUserModal">
    <div class="modal">
        <div class="modal-header">
            <h3><?= te('add_new_user') ?></h3>
            <button class="modal-close">&times;</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="add_user">

                <div class="form-group">
                    <label class="form-label"><?= te('full_name') ?></label>
                    <input type="text" name="full_name" class="form-control" required>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label"><?= te('username') ?></label>
                        <input type="text" name="username" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?= te('password') ?></label>
                        <input type="password" name="password" class="form-control" required minlength="6">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label"><?= te('role') ?></label>
                    <select name="role" class="form-control" required>
                        <option value="waiter"><?= te('role_waiter') ?></option>
                        <option value="cashier"><?= te('role_cashier') ?></option>
                        <option value="kitchen"><?= te('role_kitchen') ?></option>
                        <option value="admin"><?= te('role_admin') ?></option>
                    </select>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label"><?= te('email_optional') ?></label>
                        <input type="email" name="email" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?= te('phone_optional') ?></label>
                        <input type="text" name="phone" class="form-control">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('addUserModal')"><?= te('cancel') ?></button>
                <button type="submit" class="btn btn-primary"><?= te('add_user') ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Reset Password Modal -->
<div class="modal-overlay" id="resetPasswordModal">
    <div class="modal">
        <div class="modal-header">
            <h3><?= te('reset_password') ?></h3>
            <button class="modal-close">&times;</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="reset_password">
                <input type="hidden" name="user_id" id="resetUserId">

                <p class="mb-md"><?= te('reset_password_for') ?> <strong id="resetUsername"></strong></p>

                <div class="form-group">
                    <label class="form-label"><?= te('new_password') ?></label>
                    <input type="password" name="new_password" class="form-control" required minlength="6">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('resetPasswordModal')"><?= te('cancel') ?></button>
                <button type="submit" class="btn btn-warning"><?= te('reset_password') ?></button>
            </div>
        </form>
    </div>
</div>

<script>
function openResetModal(userId, username) {
    document.getElementById('resetUserId').value = userId;
    document.getElementById('resetUsername').textContent = username;
    openModal('resetPasswordModal');
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
