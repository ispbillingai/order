<?php
/**
 * Unsubscribe from the restaurant's WhatsApp invitations (the link at the end
 * of every campaign message). The link is signed for one number.
 */

require_once __DIR__ . '/includes/campaigns.php';
i18n_prefer_browser('it');

$phone = phoneFromUnsubscribeToken((string) ($_GET['t'] ?? $_POST['t'] ?? ''));
$done  = false;
if ($phone && $_SERVER['REQUEST_METHOD'] === 'POST') {
    getDBConnection()->prepare("INSERT IGNORE INTO marketing_optouts (phone, source) VALUES (?, 'link')")->execute([$phone]);
    logActivity('marketing_unsubscribed', 'marketing_optouts', null, ['phone_end' => substr($phone, -4)]);
    $done = true;
} elseif ($phone && isOptedOut($phone)) {
    $done = true;
}
header('Cache-Control: no-store');
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(currentLang()) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex">
<title><?= htmlspecialchars(restaurantName()) ?></title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;600;700&display=swap" rel="stylesheet">
<style>
body { margin: 0; font-family: 'DM Sans', system-ui, sans-serif; background: #f7f5f2; color: #1f2937; display: flex; min-height: 100vh; align-items: center; justify-content: center; padding: 20px; box-sizing: border-box; }
.box { background: #fff; border-radius: 16px; padding: 28px 24px; max-width: 420px; width: 100%; text-align: center; box-shadow: 0 2px 10px rgba(0,0,0,.06); }
h1 { font-size: 1.2rem; margin: 0 0 6px; } .brand { color: #e8590c; font-weight: 700; margin-bottom: 14px; }
p { color: #4b5563; } button { background: #e8590c; color: #fff; border: 0; border-radius: 12px; padding: 14px 20px; font: inherit; font-weight: 700; width: 100%; cursor: pointer; }
.ok { color: #16a34a; font-size: 2rem; }
</style>
</head>
<body>
<div class="box">
    <div class="brand"><?= htmlspecialchars(restaurantName()) ?></div>
    <?php if (!$phone): ?>
        <h1><?= te('camp_unsub_invalid') ?></h1>
    <?php elseif ($done): ?>
        <div class="ok">✓</div>
        <h1><?= te('camp_unsub_done') ?></h1>
        <p><?= te('camp_unsub_done_text') ?></p>
    <?php else: ?>
        <h1><?= te('camp_unsub_title') ?></h1>
        <p><?= te('camp_unsub_text') ?> <strong>•••• <?= htmlspecialchars(substr($phone, -4)) ?></strong></p>
        <form method="POST">
            <input type="hidden" name="t" value="<?= htmlspecialchars((string) $_GET['t']) ?>">
            <button type="submit"><?= te('camp_unsub_button') ?></button>
        </form>
    <?php endif; ?>
</div>
</body>
</html>
