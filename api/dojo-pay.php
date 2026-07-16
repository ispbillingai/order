<?php
/**
 * Card payment for an order via a Dojo terminal (Dojo Cloud API "Pay at
 * Counter"). Sibling of card-pay.php (Ingenico/RTS): charge the card, confirm
 * the order paid, then emit the fiscal receipt. Body: { order_id }.
 *
 * The whole intent -> terminal-session -> poll dance happens inside
 * DojoClient::pay(), so this endpoint blocks until the terminal reaches a final
 * state (approved / declined / timeout), exactly like the Ingenico flow.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/devices.php';
require_once __DIR__ . '/../includes/DojoClient.php';
require_once __DIR__ . '/../includes/order_payment.php';

header('Content-Type: application/json');

$u = isLoggedIn() ? getCurrentUser() : null;
if (!$u || !in_array($u['role'], ['admin', 'cashier'], true)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit;
}

$input   = json_decode(file_get_contents('php://input'), true) ?: [];
$orderId = (int) ($input['order_id'] ?? 0);
$order   = getOrderById($orderId);
if (!$order) { echo json_encode(['ok' => false, 'error' => 'order_not_found']); exit; }
if ($order['status'] === 'paid') { echo json_encode(['ok' => false, 'error' => 'already_paid']); exit; }

$amount = toCents($order['total']);
if ($amount <= 0) { echo json_encode(['ok' => false, 'error' => 'bad_amount']); exit; }

$dojo = new DojoClient(tillConfigForOrder($order, 'dojo'));
if (!$dojo->enabled()) { echo json_encode(['ok' => false, 'error' => 'dojo_not_configured']); exit; }

// 1) Charge the card. If this fails, nothing was captured — safe to retry.
$ref  = 'order-' . $orderId;
$desc = 'Order #' . ($order['order_number'] ?? $orderId);
$auth = $dojo->pay($amount, currencyCode(), $ref, $desc);
if (!$auth['ok']) {
    logDeviceEvent('dojo', 'payment_fail', $orderId, [
        'stage'      => 'authorize',
        'error'      => $auth['error'] ?? '?',
        'status'     => $auth['status'] ?? '',
        'session_id' => $auth['session_id'] ?? '',
    ]);
    echo json_encode(['ok' => false, 'error' => $auth['error'] ?? 'card_declined', 'stage' => 'authorize']);
    exit;
}

// 2) Card charged — record the payment and close the order.
$conf = confirmOrderPayment($orderId, 'dojo', $amount, [
    'card_transaction_id' => $auth['operation_number'] ?? ($auth['session_id'] ?? null),
    'card_auth_code'      => $auth['auth_code'] ?? null,
    'card_pan_masked'     => $auth['pan'] ?? null,
    'reference'           => $auth['auth_code'] ?? null,
    'device_meta'         => [
        'provider'          => 'dojo',
        'payment_intent_id' => $auth['payment_intent_id'] ?? null,
        'session_id'        => $auth['session_id'] ?? null,
        'session'           => $auth['raw'] ?? null,
    ],
]);
if (!$conf['ok']) {
    // Card WAS charged but we couldn't record it — log loudly for reconciliation.
    error_log('[dojo-pay] CARD CHARGED but confirm failed for order ' . $orderId
        . ' intent=' . ($auth['payment_intent_id'] ?? '?') . ' error=' . ($conf['error'] ?? '?'));
    logDeviceEvent('dojo', 'confirm_failed_after_charge', $orderId, [
        'payment_intent_id' => $auth['payment_intent_id'] ?? '',
        'session_id'        => $auth['session_id'] ?? '',
        'error'             => $conf['error'] ?? '?',
    ]);
    echo json_encode(['ok' => false, 'error' => $conf['error'] ?? 'confirm_failed', 'stage' => 'confirm']);
    exit;
}

// 3) Emit the fiscal receipt (paymentType card) on this till's fiscal printer.
$fiscal = emitFiscalForOrder($orderId, $conf['payment_id'], $amount, 'dojo', $order);

echo json_encode([
    'ok'           => true,
    'amount_cents' => $amount,
    'auth_code'    => $auth['auth_code'] ?? '',
    'receipt'      => !empty($fiscal['ok']) ? $fiscal : null,
]);
