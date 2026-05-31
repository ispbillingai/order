<?php
/**
 * Device & Payment Integrations Config (EXAMPLE)
 * RestoPOS — mirrors the parking app's config so behaviour matches.
 *
 * Copy this file to config/devices.php and fill in real values for the till.
 * config/devices.php is git-ignored (it holds device credentials / IPs).
 *
 * ARCHITECTURE: the cashier PHP calls the local hardware SERVER-SIDE (curl),
 * reaching each device over TAILSCALE (we no longer use ngrok). Put each
 * device's Tailscale IP/hostname below. A `curl(28) timeout` means the device
 * isn't reachable over Tailscale (offline / wrong IP / Tailscale down).
 *
 * MONEY: amounts go to the devices as integer CENTS + ISO 4217 code; the POS DB
 * stays DECIMAL. Conversion via includes/devices.php (toCents / fromCents).
 */

return [
    // ---- Currency -------------------------------------------------------
    'currency' => [
        'code'     => 978,    // ISO 4217 numeric: 978 = EUR, 840 = USD
        'symbol'   => '€',
        'decimals' => 2,
    ],

    // ---- Cashmatic automated cash machine (HTTP REST) ------------------
    // "Start payment (cash)" button. Reached over Tailscale.
    'cashmatic' => [
        'enabled'    => true,
        'base_url'   => 'http://100.x.y.z:50301',   // Cashmatic REST over Tailscale
        'username'   => 'cashmatic',
        'password'   => 'admin',
        'verify_ssl' => false,
    ],

    // ---- Card terminal / POS client (RTS WebDoReMi -> Ingenico) -------
    // "Pay by card" button. base_url is the RTS WebDoremiposWS HTTP service
    // on the till LAN (reached over Tailscale); it wraps Protocol 17 to the
    // Ingenico terminal. terminal_name is the <terminal name="…"> from RTS.
    // Leave base_url empty to hide the "Pay by card" button.
    'pos' => [
        'enabled'         => true,
        'base_url'        => 'http://100.x.y.z/WebDoremiposWS',  // RTS service (Tailscale)
        'terminal_name'   => 'Ingenico-XXXXXXXX',                // RTS terminal name
        'protocol_type'   => '0',       // 0 auto / 1 credit / 2 debit
        'connect_timeout' => 5,
        'read_timeout'    => 90,        // covers card tap + acquirer auth
    ],

    // ---- Epson fiscal printer (Registratore Telematico) ---------------
    // Drives the EFT-POS over Protocol 17 and prints the fiscal receipt via
    // its fpmate.cgi web service. Leave base_url empty to disable.
    'fiscal_printer' => [
        'enabled'    => true,
        'base_url'   => 'http://100.x.y.z',   // Epson RT printer (Tailscale)
        'operator'   => '1',
        'timeout_ms' => 35000,
        'auto_print' => true,
        'verify_ssl' => false,
    ],

    // ---- QR codes (digital receipt) -----------------------------------
    'qr' => [
        'enabled'      => true,
        'receipt_base' => 'https://your-domain.example/cashier/receipt.php',
    ],
];
