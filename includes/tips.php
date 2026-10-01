<?php
/**
 * Tips to the table's waiter by PayPal (Admin › Users › Contacts: the
 * waiter's PayPal.me name). The link goes with the bill on WhatsApp, on the
 * printed bill and the receipt (as a QR) and on the guest's table page.
 */

require_once __DIR__ . '/functions.php';

/**
 * "antonio", "@antonio", "paypal.me/antonio", "https://www.paypal.com/paypalme/antonio"
 * → "antonio"; '' → null; anything else → false (not a PayPal.me name).
 */
function normalizePaypalMe(string $in)
{
    $s = trim($in);
    if ($s === '') return null;
    $s = preg_replace('~^https?://~i', '', $s);
    $s = preg_replace('~^(www\.)?(paypal\.me/|paypal\.com/paypalme/)~i', '', $s);
    $s = ltrim(trim($s, '/'), '@');
    $s = explode('/', $s)[0];                                  // drop an amount ("/10EUR")
    return preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,39}$/', $s) ? $s : false;
}

function paypalMeUrl(string $name): string
{
    return 'https://paypal.me/' . rawurlencode($name);
}

/**
 * The name of an order's waiter as guests should see it: on a guest's own
 * order (table QR) the waiter who took the table ('' while nobody has).
 */
function orderWaiterName(array $order): string
{
    $pdo  = getDBConnection();
    $root = $order;
    if (!empty($order['parent_order_id'])) {
        $st = $pdo->prepare("SELECT created_by_guest, assigned_waiter_id FROM orders WHERE id = ?");
        $st->execute([$order['parent_order_id']]);
        $root = ($st->fetch() ?: []) + $order;
    }
    if (empty($root['created_by_guest'])) return (string) ($order['waiter_name'] ?? '');
    if (empty($root['assigned_waiter_id'])) return '';
    $st = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
    $st->execute([$root['assigned_waiter_id']]);
    return (string) $st->fetchColumn();
}

/**
 * The table's waiter for an order, with a PayPal.me set: ['name', 'url'] or null.
 * The order's waiter — on a guest's own order (table QR) the waiter who took
 * the table; a seat bill follows its table.
 */
function orderTipTarget(int $orderId): ?array
{
    $pdo  = getDBConnection();
    $stmt = $pdo->prepare("SELECT o.id, o.waiter_id, o.created_by_guest, o.assigned_waiter_id, o.parent_order_id FROM orders o WHERE o.id = ?");
    $stmt->execute([$orderId]);
    $o = $stmt->fetch();
    if ($o && $o['parent_order_id']) {
        $stmt->execute([$o['parent_order_id']]);
        $o = $stmt->fetch() ?: $o;
    }
    if (!$o) return null;
    $wid = !empty($o['created_by_guest']) ? (int) $o['assigned_waiter_id'] : (int) $o['waiter_id'];
    if (!$wid) return null;
    $stmt = $pdo->prepare("SELECT full_name, paypal_me FROM users WHERE id = ? AND active = 1 AND paypal_me IS NOT NULL AND paypal_me <> ''");
    $stmt->execute([$wid]);
    $u = $stmt->fetch();
    return $u ? ['name' => $u['full_name'], 'url' => paypalMeUrl($u['paypal_me'])] : null;
}
