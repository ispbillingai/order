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
        // Use the existing workspace; create a default one if the table is empty
        // (don't hardcode id=1 — the FK fails if no such workspace exists).
        $workspaceId = $pdo->query("SELECT id FROM workspaces ORDER BY id LIMIT 1")->fetchColumn();
        if (!$workspaceId) {
            $pdo->prepare("INSERT INTO workspaces (name, cover_charge) VALUES ('Main Restaurant', 2.50)")->execute();
            $workspaceId = $pdo->lastInsertId();
        }
        $stmt = $pdo->prepare("INSERT INTO rooms (workspace_id, name, sort_order) VALUES (?, ?, ?)");
        $stmt->execute([$workspaceId, $_POST['name'], $_POST['sort_order'] ?? 0]);
        header('Location: /admin/rooms.php?success=room_added');
        exit;
    }
    
    if ($action === 'add_table') {
        $stmt = $pdo->prepare("INSERT INTO tables_restaurant (room_id, table_number, capacity) VALUES (?, ?, ?)");
        $stmt->execute([$_POST['room_id'], $_POST['table_number'], $_POST['capacity'] ?? 4]);
        header('Location: /admin/rooms.php?success=table_added');
        exit;
    }

    if ($action === 'edit_table') {
        $stmt = $pdo->prepare("UPDATE tables_restaurant SET room_id = ?, table_number = ?, capacity = ? WHERE id = ?");
        $stmt->execute([$_POST['room_id'], $_POST['table_number'], $_POST['capacity'] ?? 4, (int) $_POST['table_id']]);
        header('Location: /admin/rooms.php?success=table_updated');
        exit;
    }

    if ($action === 'edit_room') {
        $stmt = $pdo->prepare("UPDATE rooms SET name = ?, sort_order = ? WHERE id = ?");
        $stmt->execute([$_POST['name'], $_POST['sort_order'] ?? 0, (int) $_POST['room_id']]);
        header('Location: /admin/rooms.php?success=room_updated');
        exit;
    }

    if ($action === 'delete_room') {
        $rid = (int) $_POST['room_id'];
        // Refuse if the room still has tables — delete/move them first.
        $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM tables_restaurant WHERE room_id = ?");
        $stmt->execute([$rid]);
        if ((int) $stmt->fetch()['c'] === 0) {
            $pdo->prepare("DELETE FROM rooms WHERE id = ?")->execute([$rid]);
            header('Location: /admin/rooms.php?success=room_deleted');
        } else {
            header('Location: /admin/rooms.php?error=room_has_tables');
        }
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
            case 'room_updated': echo te('msg_room_updated'); break;
            case 'room_deleted': echo te('msg_room_deleted'); break;
            case 'table_added': echo te('msg_table_added'); break;
            case 'tables_added': echo te('msg_tables_added'); break;
            case 'table_updated': echo te('msg_table_updated'); break;
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
        <?php elseif ($_GET['error'] === 'room_has_tables'): ?>
            <?= te('err_room_has_tables') ?>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php foreach ($rooms as $room): 
    $tables = getTablesByRoom($room['id']);
?>
<div class="card mb-lg">
    <div class="card-header">
        <h2><i class="fas fa-door-open"></i> <?= htmlspecialchars($room['name']) ?></h2>
        <div class="d-flex align-center gap-sm">
            <span class="badge badge-primary"><?= count($tables) ?> <?= te('tables_count') ?></span>
            <button type="button" class="btn btn-sm btn-outline"
                onclick='openEditRoom(<?= htmlspecialchars(json_encode(["id" => $room["id"], "name" => $room["name"], "sort_order" => $room["sort_order"]]), ENT_QUOTES) ?>)'>
                <i class="fas fa-edit"></i> <?= te('edit') ?>
            </button>
            <form method="POST" style="display:inline;" onsubmit="return confirm('<?= te('delete_room_confirm') ?>');">
                <input type="hidden" name="action" value="delete_room">
                <input type="hidden" name="room_id" value="<?= $room['id'] ?>">
                <button type="submit" class="btn btn-sm btn-danger"><i class="fas fa-trash"></i> <?= te('delete') ?></button>
            </form>
        </div>
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

                        <div class="d-flex gap-sm" style="margin-top: 10px; justify-content: center;">
                            <button type="button" class="btn btn-sm btn-outline"
                                onclick='openEditTable(<?= htmlspecialchars(json_encode(["id" => $table["id"], "room_id" => $room["id"], "table_number" => $table["table_number"], "capacity" => $table["capacity"]]), ENT_QUOTES) ?>)'
                                title="<?= te('edit') ?>">
                                <i class="fas fa-edit"></i>
                            </button>
                            <?php if ($table['status'] === 'free'): ?>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('<?= te('delete_table_confirm') ?>');">
                                    <input type="hidden" name="action" value="delete_table">
                                    <input type="hidden" name="table_id" value="<?= $table['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger" title="<?= te('delete') ?>">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
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

<!-- Edit Room Modal -->
<div class="modal-overlay" id="editRoomModal">
    <div class="modal">
        <div class="modal-header">
            <h3><?= te('edit_room') ?></h3>
            <button class="modal-close">&times;</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="edit_room">
                <input type="hidden" name="room_id" id="er_id">
                <div class="form-group">
                    <label class="form-label"><?= te('room_name') ?></label>
                    <input type="text" name="name" id="er_name" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label"><?= te('sort_order') ?></label>
                    <input type="number" name="sort_order" id="er_sort" class="form-control" value="0">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('editRoomModal')"><?= te('cancel') ?></button>
                <button type="submit" class="btn btn-primary"><?= te('save') ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Table Modal -->
<div class="modal-overlay" id="editTableModal">
    <div class="modal">
        <div class="modal-header">
            <h3><?= te('edit_table') ?></h3>
            <button class="modal-close">&times;</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="edit_table">
                <input type="hidden" name="table_id" id="et_id">
                <div class="form-group">
                    <label class="form-label"><?= te('room') ?></label>
                    <select name="room_id" id="et_room" class="form-control" required>
                        <?php foreach ($rooms as $room): ?>
                            <option value="<?= $room['id'] ?>"><?= htmlspecialchars($room['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label"><?= te('table_number_name') ?></label>
                        <input type="text" name="table_number" id="et_number" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?= te('capacity') ?></label>
                        <input type="number" name="capacity" id="et_capacity" class="form-control" min="1">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('editTableModal')"><?= te('cancel') ?></button>
                <button type="submit" class="btn btn-primary"><?= te('save') ?></button>
            </div>
        </form>
    </div>
</div>

<script>
function openEditRoom(room) {
    document.getElementById('er_id').value = room.id;
    document.getElementById('er_name').value = room.name;
    document.getElementById('er_sort').value = room.sort_order;
    openModal('editRoomModal');
}
function openEditTable(table) {
    document.getElementById('et_id').value = table.id;
    document.getElementById('et_room').value = table.room_id;
    document.getElementById('et_number').value = table.table_number;
    document.getElementById('et_capacity').value = table.capacity;
    openModal('editTableModal');
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
