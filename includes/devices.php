<?php
/**
 * Device & payment-integration helpers.
 *
 * Loads config/devices.php (falls back to empty config if absent) and provides
 * currency/money conversion plus a device-event audit logger. Safe to include
 * from any page or API endpoint.
 */

require_once __DIR__ . '/../config/database.php';

/**
 * Return the device config array, or a single section.
 * Loads config/devices.php once. Returns [] for missing config/sections.
 */
function deviceConfig(?string $section = null)
{
    static $cfg = null;
    if ($cfg === null) {
        $file = __DIR__ . '/../config/devices.php';
        $cfg = is_file($file) ? require $file : [];
        if (!is_array($cfg)) {
            $cfg = [];
        }
        // Overlay printer settings configured in the admin panel (DB) on top of
        // the file defaults, so printers (and their IPs) are managed from the UI.
        require_once __DIR__ . '/settings.php';
        $dbPrinters = getSetting('printers', []);
        if (is_array($dbPrinters)) {
            foreach (['kitchen_printer', 'cashier_printer', 'fiscal_printer'] as $pk) {
                if (!empty($dbPrinters[$pk]) && is_array($dbPrinters[$pk])) {
                    $cfg[$pk] = array_merge($cfg[$pk] ?? [], $dbPrinters[$pk]);
                }
            }
        }
    }
    if ($section === null) {
        return $cfg;
    }
    return $cfg[$section] ?? [];
}

/** ISO 4217 numeric currency code (default 978 = EUR). */
function currencyCode(): int
{
    return (int) (deviceConfig('currency')['code'] ?? 978);
}

/** Currency symbol for display (default €). */
function currencySymbol(): string
{
    return (string) (deviceConfig('currency')['symbol'] ?? '€');
}

/** Convert a DECIMAL money amount (e.g. 10.50) to integer cents (1050). */
function toCents($amount): int
{
    return (int) round(((float) $amount) * 100);
}

/** Convert integer cents (1050) back to a float amount (10.50). */
function fromCents(int $cents): float
{
    return $cents / 100;
}

/** Format an amount with the configured currency symbol. */
function moneyFormat($amount): string
{
    return currencySymbol() . number_format((float) $amount, 2);
}

/**
 * Append a row to the device_events audit log. Never throws — logging must
 * never break a payment flow.
 */
function logDeviceEvent(
    string $device,
    string $eventType,
    ?int $orderId = null,
    array $details = [],
    ?int $paymentId = null
): void {
    try {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare(
            'INSERT INTO device_events (order_id, payment_id, device, event_type, details)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $orderId,
            $paymentId,
            $device,
            $eventType,
            $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null,
        ]);
    } catch (Throwable $e) {
        error_log('logDeviceEvent failed: ' . $e->getMessage());
    }
}
