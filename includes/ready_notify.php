<?php
/**
 * "Dish ready" notifications to the waiters.
 *
 * The general rule is set in Settings (who is told when the kitchen marks a
 * dish ready): the waiter who took the order, every waiter, or chosen waiters
 * (optionally together with the one who took the order). A single order can
 * override it from its screen (orders.ready_notify): the order's waiter,
 * everyone, or one other waiter — e.g. when the waiter who took it goes off
 * shift. A seat bill split off an order follows its parent's choice.
 */

require_once __DIR__ . '/functions.php';

const READY_NOTIFY_MODES = ['order_waiter', 'all', 'waiters', 'none'];

/** The general rule: ['mode', 'waiters' => user ids, 'with_order_waiter' => bool]. */
function readyNotifyRule(): array
{
    $r = (array) getSetting('ready_notify', []);
    return [
        'mode'              => in_array($r['mode'] ?? '', READY_NOTIFY_MODES, true) ? $r['mode'] : 'order_waiter',
        'waiters'           => array_values(array_map('intval', (array) ($r['waiters'] ?? []))),
        'with_order_waiter' => !empty($r['with_order_waiter']),
    ];
}

/** Staff who can be told (active waiters and admins): id => full name. */
function readyNotifyStaff(): array
{
    return getDBConnection()->query("
        SELECT id, full_name FROM users WHERE active = 1 AND role IN ('waiter', 'admin') ORDER BY role = 'admin', full_name
    ")->fetchAll(PDO::FETCH_KEY_PAIR);
}

/** Every active waiter. */
function allWaiterIds(): array
{
    return array_map('intval', getDBConnection()->query("SELECT id FROM users WHERE active = 1 AND role = 'waiter'")->fetchAll(PDO::FETCH_COLUMN));
}

/** Is an order's override valid ('' = the general rule)? */
function validReadyNotifyChoice(string $v): bool
{
    if ($v === '' || $v === 'order_waiter' || $v === 'all') return true;
    return preg_match('/^user:(\d+)$/', $v, $m) && isset(readyNotifyStaff()[(int) $m[1]]);
}

/** Short description of the general rule, for the order screen. */
function readyNotifyRuleLabel(): string
{
    $rule = readyNotifyRule();
    if ($rule['mode'] !== 'waiters') return t('ready_short_' . $rule['mode']);
    $staff = readyNotifyStaff();
    $names = array_filter(array_map(fn($id) => $staff[$id] ?? null, $rule['waiters']));
    if ($rule['with_order_waiter']) array_unshift($names, t('ready_short_order_waiter'));
    return $names ? implode(' + ', $names) : t('ready_short_order_waiter');
}

/** Who is told when a dish of this order is ready (user ids). */
function readyNotifyRecipients(array $order): array
{
    $pdo    = getDBConnection();
    $choice = $order['ready_notify'] ?? null;
    if ($choice === null && !empty($order['parent_order_id'])) {
        $stmt = $pdo->prepare("SELECT ready_notify FROM orders WHERE id = ?");
        $stmt->execute([$order['parent_order_id']]);
        $choice = $stmt->fetchColumn() ?: null;
    }
    $own = [(int) $order['waiter_id']];

    if ($choice === 'order_waiter') return $own;
    if ($choice === 'all') return allWaiterIds() ?: $own;
    if ($choice && preg_match('/^user:(\d+)$/', $choice, $m) && isset(readyNotifyStaff()[(int) $m[1]])) return [(int) $m[1]];

    $rule = readyNotifyRule();
    switch ($rule['mode']) {
        case 'none':
            return [];
        case 'all':
            return allWaiterIds() ?: $own;
        case 'waiters':
            $staff = readyNotifyStaff();
            $ids   = array_values(array_filter($rule['waiters'], fn($id) => isset($staff[$id])));
            if ($rule['with_order_waiter']) $ids = array_merge($own, $ids);
            return $ids ? array_values(array_unique($ids)) : $own; // nobody left active: the order's waiter
        default:
            return $own;
    }
}

/**
 * The notification's title and text in the reader's language, from what was
 * stored with it: ['what' => dish / course or null for the whole order,
 * 'what_key' => a label key instead of 'what', 'seat', 'table', 'order_of'].
 */
function readyNotifText(array $i): array
{
    $what = !empty($i['what_key']) ? t($i['what_key']) : ($i['what'] ?? null);
    if ($what !== null && !empty($i['seat'])) $what .= ' (' . t('seat') . ' ' . (int) $i['seat'] . ')';
    $msg = $what === null ? t('ready_notif_all', ['table' => $i['table']]) : t('ready_notif_dish', ['what' => $what, 'table' => $i['table']]);
    if (!empty($i['order_of'])) $msg .= ' · ' . t('ready_notif_order_of', ['name' => $i['order_of']]);
    return [t($what === null ? 'ready_notif_title_all' : 'ready_notif_title'), $msg];
}

/** A notification row with title/message in the reader's language (when it can be). */
function localizeNotification(array $n): array
{
    $p = json_decode((string) ($n['payload'] ?? ''), true);
    if (is_array($p) && isset($p['ready'])) [$n['title'], $n['message']] = readyNotifText($p['ready']);
    return $n;
}

/**
 * Tell the right waiters that something of an order is ready.
 * $what: the dish or course; null = the whole order. $whatKey: a label key
 * instead (e.g. "Course" when the course has no name).
 */
function notifyDishReady(int $orderId, ?string $what, ?int $seat = null, ?int $orderItemId = null, array $payload = [], ?string $whatKey = null): int
{
    $order = getOrderById($orderId);
    if (!$order) return 0;
    $info = ['what' => $what, 'what_key' => $whatKey, 'seat' => $seat, 'table' => $order['table_number'], 'order_of' => null];
    $n = 0;
    foreach (readyNotifyRecipients($order) as $userId) {
        // Someone else's table: say whose order it is.
        $i = $info;
        if ($userId !== (int) $order['waiter_id']) $i['order_of'] = $order['waiter_name'];
        [$title, $msg] = readyNotifText($i);
        createNotification($userId, 'dish_ready', $title, $msg, $orderItemId, $payload + ['order_id' => $orderId, 'ready' => $i]);
        $n++;
    }
    return $n;
}

/** Unread "ready" notifications of the last minutes, for the pop-up + sound. */
function recentReadyAlerts(int $userId): array
{
    $stmt = getDBConnection()->prepare("
        SELECT id, title, message, payload FROM notifications
        WHERE user_id = ? AND type = 'dish_ready' AND read_at IS NULL AND created_at > NOW() - INTERVAL 10 MINUTE
        ORDER BY id DESC LIMIT 5
    ");
    $stmt->execute([$userId]);
    return array_map(function ($r) {
        $r = localizeNotification($r);
        $p = json_decode((string) $r['payload'], true) ?: [];
        return ['id' => (int) $r['id'], 'title' => $r['title'], 'message' => $r['message'], 'order_id' => (int) ($p['order_id'] ?? 0)];
    }, $stmt->fetchAll());
}
