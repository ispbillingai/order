<?php
/**
 * WhatsApp to guests (TextMeBot): the table's QR link as soon as a guest gives
 * their number, and the bill — a non-fiscal copy of the receipt, the same
 * lines the cashier's printed bill has. Messages are queued in
 * whatsapp_outbox and sent by bin/whatsapp-worker.php in the background, so
 * the waiter never waits for TextMeBot's gap between two messages.
 * Italian guests get Italian, everyone else English.
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/TextMeBot.php';
require_once __DIR__ . '/table_requests.php';

/** WhatsApp to guests is on when the TextMeBot gateway has an API key. */
function guestWhatsappEnabled(): bool
{
    return (new TextMeBot())->enabled();
}

/** Language for a guest's messages, from their phone's country. */
function guestLang(?string $country): string
{
    return in_array(strtoupper((string) $country), ['', 'IT', 'SM', 'VA'], true) ? 'it' : 'en';
}

/** A string from lang/<lang>.php whatever the screen's language is. */
function tIn(string $lang, string $key, array $vars = []): string
{
    static $cache = [];
    $cache[$lang] ??= (is_file($f = __DIR__ . '/../lang/' . $lang . '.php') ? require $f : []);
    $s = $cache[$lang][$key] ?? $key;
    foreach ($vars as $k => $v) {
        $s = str_replace('{' . $k . '}', (string) $v, $s);
    }
    return $s;
}

function restaurantName(): string
{
    $ws = getDBConnection()->query("SELECT name FROM workspaces LIMIT 1")->fetch();
    return (string) ($ws['name'] ?? 'RistoUpgrade');
}

/** "Here is your table's link, this is what you can do with it". */
function guestTableLinkText(array $order, string $lang): string
{
    $url = tableQrUrl(tableQrToken((int) $order['table_id']));
    return tIn($lang, 'wa_link_text', [
        'restaurant' => restaurantName(),
        'table'      => $order['table_number'],
        'url'        => $url,
    ]);
}

/**
 * The bill as WhatsApp text: header, then a monospaced block with the lines
 * exactly as on the cashier's bill (dishes, cover, subtotal, discount, total).
 */
function guestBillText(int $orderId, string $lang): string
{
    calculateOrderTotals($orderId);
    $order = getOrderById($orderId);
    $W     = 30;
    $money = fn($v) => formatCurrency($v);
    $line  = function (string $left, string $right) use ($W): array {
        $room = $W - mb_strlen($right) - 1;
        if (mb_strlen($left) <= $room) {
            return [$left . str_repeat(' ', $W - mb_strlen($left) - mb_strlen($right)) . $right];
        }
        // Too long for one line: the name wrapped, the amount right-aligned under it.
        $out = explode("\n", wordwrap($left, $W, "\n", true));
        $out[] = str_repeat(' ', max(0, $W - mb_strlen($right))) . $right;
        return $out;
    };

    $rows = [];
    foreach (getOrderItems($orderId) as $it) {
        if ($it['status'] === 'cancelled') continue;
        $rows = array_merge($rows, $line((int) $it['quantity'] . 'x ' . $it['item_name'], $money($it['total_price'])));
    }
    $people = (int) $order['number_of_people'];
    if ($people > 0 && (float) $order['cover_charge_per_person'] > 0) {
        $rows = array_merge($rows, $line(tIn($lang, 'wa_bill_cover') . ' (' . $people . ')', $money($people * $order['cover_charge_per_person'])));
    }
    $rule   = str_repeat('-', $W);
    $rows[] = $rule;
    $rows   = array_merge($rows, $line(tIn($lang, 'wa_bill_subtotal'), $money($order['subtotal'])));
    if ((float) $order['discount_amount'] > 0) {
        $rows = array_merge($rows, $line(tIn($lang, 'wa_bill_discount'), '-' . $money($order['discount_amount'])));
    }
    $rows = array_merge($rows, $line(tIn($lang, 'wa_bill_total'), $money($order['total'])));

    return implode("\n", [
        '*' . restaurantName() . '*',
        tIn($lang, 'wa_bill_title'),
        tIn($lang, 'wa_bill_table') . ' ' . $order['table_number'] . ' · ' . tIn($lang, 'wa_bill_order') . ' ' . $order['order_number'],
        date('d/m/Y H:i'),
        '',
        "```\n" . implode("\n", $rows) . "\n```",
        '*' . tIn($lang, 'wa_bill_total') . ': ' . $money($order['total']) . '*',
        '',
        '_' . tIn($lang, 'wa_bill_note') . '_',
    ]);
}

/** Queue a WhatsApp and make sure the background sender is running. */
function queueGuestWhatsapp(?int $orderId, ?int $seat, string $kind, string $phone, string $body): int
{
    $pdo  = getDBConnection();
    $user = getCurrentUser();
    $pdo->prepare("INSERT INTO whatsapp_outbox (order_id, seat, kind, phone, body, created_by) VALUES (?, ?, ?, ?, ?, ?)")
        ->execute([$orderId, $seat, $kind, $phone, $body, $user['id'] ?? null]);
    $id = (int) $pdo->lastInsertId();
    startWhatsappWorker();
    return $id;
}

/**
 * Send the table's QR link to a number, once per order and number (saving the
 * same number again doesn't resend it). Returns the outbox id, or null.
 */
function sendTableLinkOnce(array $order, ?int $seat, string $phone, ?string $country): ?int
{
    if (!guestWhatsappEnabled() || $phone === '') return null;
    $stmt = getDBConnection()->prepare("
        SELECT id FROM whatsapp_outbox WHERE order_id = ? AND kind = 'table_link' AND phone = ? AND status <> 'failed' LIMIT 1
    ");
    $stmt->execute([(int) $order['id'], $phone]);
    if ($stmt->fetchColumn()) return null;
    return queueGuestWhatsapp((int) $order['id'], $seat, 'table_link', $phone, guestTableLinkText($order, guestLang($country)));
}

/** Start bin/whatsapp-worker.php in the background (it exits when the outbox is empty). */
function startWhatsappWorker(): void
{
    $worker = realpath(__DIR__ . '/../bin/whatsapp-worker.php');
    if (!$worker || !function_exists('exec')) return;
    if (PHP_OS_FAMILY === 'Windows') {
        $php = PHP_BINARY ?: 'php';
        pclose(popen('start /B "" ' . escapeshellarg($php) . ' ' . escapeshellarg($worker), 'r'));
        return;
    }
    $php = is_executable('/usr/bin/php') ? '/usr/bin/php' : 'php';
    exec(escapeshellarg($php) . ' ' . escapeshellarg($worker) . ' > /dev/null 2>&1 &');
}

/** Latest WhatsApp of a kind for an order (and seat), for the waiter's screen. */
function lastGuestWhatsapp(int $orderId, string $kind, ?int $seat = null): ?array
{
    $sql  = "SELECT status, phone, error, created_at, sent_at FROM whatsapp_outbox WHERE order_id = ? AND kind = ?"
          . ($seat === null ? " AND seat IS NULL" : " AND seat = ?") . " ORDER BY id DESC LIMIT 1";
    $stmt = getDBConnection()->prepare($sql);
    $stmt->execute($seat === null ? [$orderId, $kind] : [$orderId, $kind, $seat]);
    return $stmt->fetch() ?: null;
}

/** Seat guests' own numbers for an order: [seat => row]. */
function orderSeatGuests(int $orderId): array
{
    try {
        $stmt = getDBConnection()->prepare("SELECT * FROM order_seat_guests WHERE order_id = ?");
        $stmt->execute([$orderId]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) $out[(int) $r['seat']] = $r;
        return $out;
    } catch (PDOException $e) {
        return []; // migration 015 not applied yet
    }
}
