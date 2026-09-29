<?php
/**
 * Admin Settings
 * Restaurant POS System
 */

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/TextMeBot.php';
require_once __DIR__ . '/../includes/Mailer.php';
requireRole(['admin']);

$pdo = getDBConnection();

// Get workspace settings
$stmt = $pdo->query("SELECT * FROM workspaces LIMIT 1");
$workspace = $stmt->fetch();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_workspace') {
        $stmt = $pdo->prepare("
            UPDATE workspaces 
            SET name = ?, cover_charge = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $_POST['name'],
            $_POST['cover_charge'],
            $workspace['id']
        ]);
        
        header('Location: /admin/settings.php?success=saved');
        exit;
    }

    // WhatsApp gateway (TextMeBot). The API key is write-only: blank keeps the
    // saved one, the checkbox removes it. Never logged.
    if ($action === 'update_textmebot') {
        $old = (array) getSetting('textmebot', []);
        $key = trim($_POST['tmb_api_key'] ?? '');
        if (!empty($_POST['tmb_clear_key'])) {
            $key = '';
        } elseif ($key === '') {
            $key = (string) ($old['api_key'] ?? '');
        }
        setSetting('textmebot', [
            'api_key'         => $key,
            'endpoint'        => trim($_POST['tmb_endpoint'] ?? '') ?: TextMeBot::DEFAULT_ENDPOINT,
            'min_gap_seconds' => max(5, min(60, (int) ($_POST['tmb_gap'] ?? 8))),
        ]);
        logActivity('textmebot_settings_saved', 'settings', null, ['key_set' => $key !== '']);
        header('Location: /admin/settings.php?success=saved#whatsapp');
        exit;
    }

    // Outgoing email (password reset links). SMTP password is write-only.
    if ($action === 'update_mail') {
        $old  = (array) getSetting('mail', []);
        $pass = (string) ($_POST['smtp_pass'] ?? '');
        if ($pass === '') $pass = (string) ($old['smtp']['pass'] ?? '');
        $secure = in_array($_POST['smtp_secure'] ?? 'tls', ['tls', 'ssl', ''], true) ? $_POST['smtp_secure'] : 'tls';
        setSetting('mail', [
            'from_name'  => trim($_POST['from_name'] ?? ''),
            'from_email' => trim($_POST['from_email'] ?? ''),
            'smtp'       => [
                'host'   => trim($_POST['smtp_host'] ?? ''),
                'port'   => (int) ($_POST['smtp_port'] ?? 587) ?: 587,
                'secure' => $secure,
                'user'   => trim($_POST['smtp_user'] ?? ''),
                'pass'   => $pass,
            ],
        ]);
        logActivity('mail_settings_saved', 'settings', null, ['host' => trim($_POST['smtp_host'] ?? '')]);
        header('Location: /admin/settings.php?success=saved#email');
        exit;
    }

    if ($action === 'test_mail') {
        $to  = trim($_POST['mail_test_to'] ?? '');
        $res = (new Mailer())->send($to, t('mail_test_subject'), '<p>' . htmlspecialchars(($workspace['name'] ?? '') . ' — ' . t('mail_test_body')) . '</p>');
        logActivity('mail_test', 'settings', null, ['ok' => $res['ok']]);
        $_SESSION['mail_test'] = ['ok' => $res['ok'], 'msg' => $res['ok'] ? t('mail_test_ok') : t('mail_test_fail') . ': ' . $res['error']];
        header('Location: /admin/settings.php#email');
        exit;
    }

    if ($action === 'test_textmebot') {
        $res = (new TextMeBot())->send((string) ($_POST['tmb_test_to'] ?? ''), ($workspace['name'] ?? t('app_name')) . ' — test WhatsApp ✅');
        logActivity('textmebot_test', 'settings', null, ['ok' => $res['ok'], 'http' => $res['http']]);
        $_SESSION['tmb_test'] = $res['ok']
            ? ['ok' => true, 'msg' => t('tmb_test_ok')]
            : ['ok' => false, 'msg' => t('tmb_test_fail') . ': ' . (
                $res['error'] === 'not_configured' ? t('tmb_not_configured')
                : ($res['error'] === 'bad_phone' ? t('tmb_bad_phone') : TextMeBot::failureReason($res)))];
        header('Location: /admin/settings.php#whatsapp');
        exit;
    }
}

$mail     = (array) getSetting('mail', []);
$smtp     = (array) ($mail['smtp'] ?? []);
$smtpOn   = trim((string) ($smtp['host'] ?? '')) !== '';
$mailTest = $_SESSION['mail_test'] ?? null;
unset($_SESSION['mail_test']);

$tmb      = (array) getSetting('textmebot', []);
$tmbKeyOn = trim((string) ($tmb['api_key'] ?? '')) !== '';
$tmbTest  = $_SESSION['tmb_test'] ?? null;
unset($_SESSION['tmb_test']);

$pageTitle = t('settings');

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-cog"></i> <?= te('settings') ?></h1>
</div>

<?php if (isset($_GET['success'])): ?>
    <div class="alert alert-success mb-lg" style="background: rgba(39,174,96,0.1); color: var(--success); padding: 16px; border-radius: 8px;">
        <i class="fas fa-check-circle"></i> <?= te('msg_settings_saved') ?>
    </div>
<?php endif; ?>

<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: var(--space-lg);">
    <!-- Workspace Settings -->
    <div class="card">
        <div class="card-header">
            <h2><i class="fas fa-building"></i> <?= te('restaurant_settings') ?></h2>
        </div>
        <form method="POST">
            <div class="card-body">
                <input type="hidden" name="action" value="update_workspace">

                <div class="form-group">
                    <label class="form-label"><?= te('restaurant_name') ?></label>
                    <input type="text" name="name" class="form-control"
                           value="<?= htmlspecialchars($workspace['name']) ?>" required>
                </div>

                <div class="form-group">
                    <label class="form-label"><?= te('cover_charge_per') ?></label>
                    <input type="number" name="cover_charge" class="form-control"
                           step="0.01" value="<?= $workspace['cover_charge'] ?>" required>
                </div>
            </div>
            <div class="card-footer">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> <?= te('save_settings') ?>
                </button>
            </div>
        </form>
    </div>
    
    <!-- WhatsApp gateway (TextMeBot), as in the CRM -->
    <div class="card" id="whatsapp">
        <div class="card-header">
            <h2><i class="fab fa-whatsapp" style="color:#25d366;"></i> <?= te('tmb_title') ?></h2>
            <span class="badge badge-<?= $tmbKeyOn ? 'success' : 'warning' ?>"><?= $tmbKeyOn ? te('tmb_active') : te('tmb_inactive') ?></span>
        </div>
        <form method="POST">
            <div class="card-body">
                <input type="hidden" name="action" value="update_textmebot">
                <div class="form-group">
                    <label class="form-label"><?= te('tmb_api_key') ?></label>
                    <input type="password" name="tmb_api_key" class="form-control" autocomplete="new-password"
                           placeholder="<?= $tmbKeyOn ? te('tmb_key_set') : '' ?>">
                    <small class="text-muted"><?= te('tmb_api_key_hint') ?></small>
                    <?php if ($tmbKeyOn): ?>
                        <label style="display:flex;gap:6px;align-items:center;margin-top:6px;font-size:.85rem;">
                            <input type="checkbox" name="tmb_clear_key" value="1"> <?= te('tmb_clear_key') ?>
                        </label>
                    <?php endif; ?>
                </div>
                <div class="form-group">
                    <label class="form-label"><?= te('tmb_endpoint') ?></label>
                    <input type="url" name="tmb_endpoint" class="form-control"
                           value="<?= htmlspecialchars($tmb['endpoint'] ?? TextMeBot::DEFAULT_ENDPOINT) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label"><?= te('tmb_gap') ?></label>
                    <input type="number" name="tmb_gap" class="form-control" min="5" max="60"
                           value="<?= (int) ($tmb['min_gap_seconds'] ?? 8) ?>">
                    <small class="text-muted"><?= te('tmb_gap_hint') ?></small>
                </div>
            </div>
            <div class="card-footer">
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> <?= te('save_settings') ?></button>
            </div>
        </form>
        <form method="POST" class="card-body" style="border-top:1px solid var(--border-color);">
            <input type="hidden" name="action" value="test_textmebot">
            <label class="form-label"><?= te('tmb_test_title') ?></label>
            <?php if ($tmbTest): ?>
                <div class="alert alert-<?= $tmbTest['ok'] ? 'success' : 'danger' ?>" style="padding:10px 14px;border-radius:8px;margin-bottom:10px;background:<?= $tmbTest['ok'] ? 'rgba(39,174,96,.1)' : 'rgba(231,76,60,.1)' ?>;color:var(--<?= $tmbTest['ok'] ? 'success' : 'danger' ?>);">
                    <?= htmlspecialchars($tmbTest['msg']) ?>
                </div>
            <?php endif; ?>
            <div class="d-flex gap-sm">
                <input type="tel" name="tmb_test_to" class="form-control" required placeholder="<?= te('tmb_test_ph') ?>" <?= $tmbKeyOn ? '' : 'disabled' ?>>
                <button type="submit" class="btn btn-success" <?= $tmbKeyOn ? '' : 'disabled' ?> style="white-space:nowrap;"><i class="fab fa-whatsapp"></i> <?= te('tmb_test_btn') ?></button>
            </div>
            <small class="text-muted"><?= te('tmb_test_hint') ?></small>
        </form>
    </div>

    <!-- Outgoing email (SMTP): password-reset links -->
    <div class="card" id="email">
        <div class="card-header">
            <h2><i class="fas fa-envelope"></i> <?= te('mail_title') ?></h2>
            <span class="badge badge-<?= $smtpOn ? 'success' : 'warning' ?>"><?= $smtpOn ? te('tmb_active') : te('tmb_inactive') ?></span>
        </div>
        <form method="POST">
            <div class="card-body">
                <input type="hidden" name="action" value="update_mail">
                <p class="text-muted" style="margin-top:0;font-size:.85rem;"><?= te('mail_intro') ?></p>
                <div class="d-flex gap-sm">
                    <div class="form-group" style="flex:1;"><label class="form-label"><?= te('mail_from_name') ?></label>
                        <input type="text" name="from_name" class="form-control" value="<?= htmlspecialchars($mail['from_name'] ?? ($workspace['name'] ?? '')) ?>"></div>
                    <div class="form-group" style="flex:1;"><label class="form-label"><?= te('mail_from_email') ?></label>
                        <input type="email" name="from_email" class="form-control" value="<?= htmlspecialchars($mail['from_email'] ?? '') ?>" placeholder="noreply@…"></div>
                </div>
                <div class="d-flex gap-sm">
                    <div class="form-group" style="flex:2;"><label class="form-label"><?= te('mail_smtp_host') ?></label>
                        <input type="text" name="smtp_host" class="form-control" value="<?= htmlspecialchars($smtp['host'] ?? '') ?>" placeholder="smtps.aruba.it"></div>
                    <div class="form-group" style="flex:1;"><label class="form-label"><?= te('mail_smtp_port') ?></label>
                        <input type="number" name="smtp_port" class="form-control" value="<?= (int) ($smtp['port'] ?? 587) ?>"></div>
                    <div class="form-group" style="flex:1;"><label class="form-label"><?= te('mail_smtp_secure') ?></label>
                        <select name="smtp_secure" class="form-control">
                            <?php foreach (['tls' => 'STARTTLS', 'ssl' => 'SSL', '' => '—'] as $v => $l): ?>
                                <option value="<?= $v ?>" <?= ($smtp['secure'] ?? 'tls') === $v ? 'selected' : '' ?>><?= $l ?></option>
                            <?php endforeach; ?>
                        </select></div>
                </div>
                <div class="d-flex gap-sm">
                    <div class="form-group" style="flex:1;"><label class="form-label"><?= te('mail_smtp_user') ?></label>
                        <input type="text" name="smtp_user" class="form-control" value="<?= htmlspecialchars($smtp['user'] ?? '') ?>" autocomplete="off"></div>
                    <div class="form-group" style="flex:1;"><label class="form-label"><?= te('mail_smtp_pass') ?></label>
                        <input type="password" name="smtp_pass" class="form-control" autocomplete="new-password"
                               placeholder="<?= !empty($smtp['pass']) ? te('tmb_key_set') : '' ?>"></div>
                </div>
            </div>
            <div class="card-footer">
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> <?= te('save_settings') ?></button>
            </div>
        </form>
        <form method="POST" class="card-body" style="border-top:1px solid var(--border-color);">
            <input type="hidden" name="action" value="test_mail">
            <label class="form-label"><?= te('mail_test_title') ?></label>
            <?php if ($mailTest): ?>
                <div style="padding:10px 14px;border-radius:8px;margin-bottom:10px;background:<?= $mailTest['ok'] ? 'rgba(39,174,96,.1)' : 'rgba(231,76,60,.1)' ?>;color:var(--<?= $mailTest['ok'] ? 'success' : 'danger' ?>);">
                    <?= htmlspecialchars($mailTest['msg']) ?>
                </div>
            <?php endif; ?>
            <div class="d-flex gap-sm">
                <input type="email" name="mail_test_to" class="form-control" required placeholder="nome@esempio.it" <?= $smtpOn ? '' : 'disabled' ?>>
                <button type="submit" class="btn btn-success" <?= $smtpOn ? '' : 'disabled' ?> style="white-space:nowrap;"><i class="fas fa-paper-plane"></i> <?= te('mail_test_btn') ?></button>
            </div>
        </form>
    </div>

    <!-- System Info -->
    <div class="card">
        <div class="card-header">
            <h2><i class="fas fa-info-circle"></i> <?= te('system_info') ?></h2>
        </div>
        <div class="card-body">
            <table class="data-table">
                <tr>
                    <td><strong><?= te('application') ?></strong></td>
                    <td><?= APP_NAME ?></td>
                </tr>
                <tr>
                    <td><strong><?= te('version') ?></strong></td>
                    <td><?= APP_VERSION ?></td>
                </tr>
                <tr>
                    <td><strong><?= te('php_version') ?></strong></td>
                    <td><?= phpversion() ?></td>
                </tr>
                <tr>
                    <td><strong><?= te('database') ?></strong></td>
                    <td>MySQL</td>
                </tr>
                <tr>
                    <td><strong><?= te('timezone') ?></strong></td>
                    <td><?= date_default_timezone_get() ?></td>
                </tr>
                <tr>
                    <td><strong><?= te('server_time') ?></strong></td>
                    <td><?= date('Y-m-d H:i:s') ?></td>
                </tr>
            </table>
        </div>
    </div>
    
    <!-- Quick Stats -->
    <div class="card">
        <div class="card-header">
            <h2><i class="fas fa-database"></i> <?= te('database_stats') ?></h2>
        </div>
        <div class="card-body">
            <?php
            $stmt = $pdo->query("SELECT COUNT(*) as c FROM users WHERE active = 1");
            $userCount = $stmt->fetch()['c'];
            
            $stmt = $pdo->query("SELECT COUNT(*) as c FROM menu_items WHERE active = 1");
            $menuCount = $stmt->fetch()['c'];
            
            $stmt = $pdo->query("SELECT COUNT(*) as c FROM tables_restaurant");
            $tableCount = $stmt->fetch()['c'];
            
            $stmt = $pdo->query("SELECT COUNT(*) as c FROM orders");
            $orderCount = $stmt->fetch()['c'];
            
            $stmt = $pdo->query("SELECT COUNT(*) as c FROM rooms WHERE active = 1");
            $roomCount = $stmt->fetch()['c'];
            ?>
            <table class="data-table">
                <tr>
                    <td><strong><?= te('active_users') ?></strong></td>
                    <td><?= $userCount ?></td>
                </tr>
                <tr>
                    <td><strong><?= te('rooms') ?></strong></td>
                    <td><?= $roomCount ?></td>
                </tr>
                <tr>
                    <td><strong><?= te('tables') ?></strong></td>
                    <td><?= $tableCount ?></td>
                </tr>
                <tr>
                    <td><strong><?= te('menu_items') ?></strong></td>
                    <td><?= $menuCount ?></td>
                </tr>
                <tr>
                    <td><strong><?= te('total_orders') ?></strong></td>
                    <td><?= $orderCount ?></td>
                </tr>
            </table>
        </div>
    </div>
    
    <!-- Danger Zone -->
    <div class="card">
        <div class="card-header" style="background: rgba(231,76,60,0.1);">
            <h2 class="text-danger"><i class="fas fa-exclamation-triangle"></i> <?= te('maintenance') ?></h2>
        </div>
        <div class="card-body">
            <p class="text-muted mb-md">
                <?= te('irreversible_warning') ?>
            </p>

            <div class="d-flex gap-sm" style="flex-wrap: wrap;">
                <a href="/admin/orders.php" class="btn btn-outline">
                    <i class="fas fa-list"></i> <?= te('view_all_orders') ?>
                </a>
                <a href="/admin/activity.php" class="btn btn-outline">
                    <i class="fas fa-history"></i> <?= te('activity_log') ?>
                </a>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
