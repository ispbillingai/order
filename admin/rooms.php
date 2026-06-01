<?php
/**
 * Admin Rooms & Tables Management
 * Restaurant POS System
 */

require_once __DIR__ . '/../includes/functions.php';
requireRole(['admin']);

$pdo = getDBConnection();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add_room') {
        $stmt = $pdo->prepare("INSERT INTO rooms (workspace_id, name, sort_order) VALUES (1, ?, ?)");
        $stmt->execute([$_POST['name'], $_POST['sort_order'] ?? 0]);
        header('Location: /admin/rooms.php?success=room_added');
        exit;
    }
    
    if ($action === 'add_table') {
        $stmt = $pdo->prepare("INSERT INTO tables_restaurant (room_id, table_number, capacity) VALUES (?, ?, ?)");
        $stmt->execute([$_POST['room_id'], $_POST['table_number'], $_POST['capacity'] ?? 4]);
        header('Location: /admin/rooms.php?success=table_added');
        exit;
    }
    
    if ($action === 'delete_table') {
        // Only delete if not in use
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM orders WHERE table_id = ? AND status NOT IN ('paid', 'cancelled')");
        $stmt->execute([$_POST['table_id']]);
        if ($stmt->fetch()['count'] == 0) {
            $stmt = $pdo->prepare("DELETE FROM tables_restaurant WHERE id = ?");
            $stmt->execute([$_POST['table_id']]);
            header('Location: /admin/rooms.php?success=table_deleted');
        } else {
            header('Location: /admin/rooms.php?error=table_in_use');
        }
        exit;
    }
    
    if ($action === 'bulk_add_tables') {
        $roomId = $_POST['room_id'];
        $prefix = $_POST['prefix'] ?? 'T';
        $count = intval($_POST['count']);
        $startFrom = intval($_POST['start_from'] ?? 1);
        $capacity = intval($_POST['capacity'] ?? 4);
        
        $stmt = $pdo->prepare("INSERT INTO tables_restaurant (room_id, table_number, capacity) VALUES (?, ?, ?)");
        for ($i = 0; $i < $count; $i++) {
            $tableNumber = $prefix . ($startFrom + $i);
            $stmt->execute([$roomId, $tableNumber, $capacity]);
        }
        header('Location: /admin/rooms.php?success=tables_added');
        exit;
    }
}

$rooms = getRooms();

$pageTitle = t('rooms_tables');

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-door-open"></i> <?= te('rooms_tables') ?></h1>
    <div class="d-flex gap-sm">
        <button class="btn btn-primary" onclick="openModal('addRoomModal')">
            <i class="fas fa-plus"></i> <?= te('add_room') ?>
        </button>
        <button class="btn btn-success" onclick="openModal('addTableModal')">
            <i class="fas fa-chair"></i> <?= te('add_table') ?>
        </button>
        <button class="btn btn-outline" onclick="openModal('bulkAddModal')">
            <i class="fas fa-layer-group"></i> <?= te('bulk_add_tables') ?>
        </button>
    </div>
</div>

<?php if (isset($_GET['success'])): ?>
    <div class="alert alert-success mb-lg" style="background: rgba(39,174,96,0.1); color: var(--success); padding: 16px; border-radius: 8px;">
        <i class="fas fa-check-circle"></i>
        <?php
        switch ($_GET['success']) {
            case 'room_added': echo te('msg_room_added'); break;
            case 'table_added': echo te('msg_table_added'); break;
            case 'tables_added': echo te('msg_tables_added'); break;
            case 'table_deleted': echo te('msg_table_deleted'); break;
        }
        ?>
    </div>
<?php endif; ?>

<?php if (isset($_GET['error'])): ?>
    <div class="alert alert-danger mb-lg" style="background: rgba(231,76,60,0.1); color: var(--danger); padding: 16px; border-radius: 8px;">
        <i class="fas fa-exclamation-circle"></i>
        <?php if ($_GET['error'] === 'table_in_use'): ?>
            <?= te('err_table_in_use') ?>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php foreach ($rooms as $room): 
    $tables = getTablesByRoom($room['id']);
?>
<div class="card mb-lg">
    <div class="card-header">
        <h2><i class="fas fa-door-open"></i> <?= htmlspecialchars($room['name']) ?></h2>
        <span class="badge badge-primary"><?= count($tables) ?> <?= te('tables_count') ?></span>
    </div>
    <div class="card-body">
        <?php if (empty($tables)): ?>
            <p class="text-muted text-center" style="padding: 40px;">
                <?= te('no_tables_buttons') ?>
            </p>
        <?php else: ?>
            <div class="tables-grid">
                <?php foreach ($tables as $table): ?>
                    <div class="table-card <?= $table['status'] ?>" style="cursor: default;">
                        <div class="table-number"><?= htmlspecialchars($table['table_number']) ?></div>
                        <div class="table-capacity">
                            <i class="fas fa-users"></i> <?= $table['capacity'] ?> <?= te('seats') ?>
                        </div>
                        <div class="table-status"><?= htmlspecialchars($table['status'] === 'free' ? t('available') : ($table['status'] === 'occupied' ? t('occupied') : ($table['status'] === 'bill_requested' ? t('bill_requested') : ucfirst($table['status'])))) ?></div>

                        <?php if ($table['status'] === 'free'): ?>
                            <form method="POST" style="margin-top: 10px;" onsubmit="return confirm('<?= te('delete_table_confirm') ?>');">
                                <input type="hidden" name="action" value="delete_table">
                                <input type="hidden" name="table_id" value="<?= $table['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-danger">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>

<?php if (empty($rooms)): ?>
    <div class="card" style="padding: 60px; text-align: center;">
        <i class="fas fa-door-open" style="font-size: 3rem; color: var(--text-secondary); margin-bottom: 16px;"></i>
        <h3 class="text-muted"><?= te('no_rooms') ?></h3>
        <p class="text-muted"><?= te('create_first_room') ?></p>
        <button class="btn btn-primary mt-lg" onclick="openModal('addRoomModal')">
            <i class="fas fa-plus"></i> <?= te('add_first_room') ?>
        </button>
    </div>
<?php endif; ?>

<!-- Add Room Modal -->
<div class="modal-overlay" id="addRoomModal">
    <div class="modal">
        <div class="modal-header">
            <h3><?= te('add_room') ?></h3>
            <button class="modal-close">&times;</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="add_room">

                <div class="form-group">
                    <label class="form-label"><?= te('room_name') ?></label>
                    <input type="text" name="name" class="form-control" required placeholder="<?= te('ph_room_example') ?>">
                </div>

                <div class="form-group">
                    <label class="form-label"><?= te('sort_order') ?></label>
                    <input type="number" name="sort_order" class="form-control" value="0">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('addRoomModal')"><?= te('cancel') ?></button>
                <button type="submit" class="btn btn-primary"><?= te('add_room') ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Add Table Modal -->
<div class="modal-overlay" id="addTableModal">
    <div class="modal">
        <div class="modal-header">
            <h3><?= te('add_table') ?></h3>
            <button class="modal-close">&times;</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="add_table">

                <div class="form-group">
                    <label class="form-label"><?= te('room') ?></label>
                    <select name="room_id" class="form-control" required>
                        <?php foreach ($rooms as $room): ?>
                            <option value="<?= $room['id'] ?>"><?= htmlspecialchars($room['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label"><?= te('table_number_name') ?></label>
                        <input type="text" name="table_number" class="form-control" required placeholder="<?= te('ph_table_example') ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?= te('capacity') ?></label>
                        <input type="number" name="capacity" class="form-control" value="4" min="1">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('addTableModal')"><?= te('cancel') ?></button>
                <button type="submit" class="btn btn-success"><?= te('add_table') ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Bulk Add Tables Modal -->
<div class="modal-overlay" id="bulkAddModal">
    <div class="modal">
        <div class="modal-header">
            <h3><?= te('bulk_add_tables') ?></h3>
            <button class="modal-close">&times;</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="bulk_add_tables">

                <div class="form-group">
                    <label class="form-label"><?= te('room') ?></label>
                    <select name="room_id" class="form-control" required>
                        <?php foreach ($rooms as $room): ?>
                            <option value="<?= $room['id'] ?>"><?= htmlspecialchars($room['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label"><?= te('table_prefix') ?></label>
                        <input type="text" name="prefix" class="form-control" value="T" placeholder="<?= te('ph_prefix_example') ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?= te('number_of_tables') ?></label>
                        <input type="number" name="count" class="form-control" value="10" min="1" max="50">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label"><?= te('start_from') ?></label>
                        <input type="number" name="start_from" class="form-control" value="1" min="1">
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?= te('default_capacity') ?></label>
                        <input type="number" name="capacity" class="form-control" value="4" min="1">
                    </div>
                </div>

                <p class="text-muted"><?= te('bulk_hint') ?></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('bulkAddModal')"><?= te('cancel') ?></button>
                <button type="submit" class="btn btn-success"><?= te('add_tables_btn') ?></button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
