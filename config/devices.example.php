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

    // ---- Card terminal / POS client (Ingenico/Nexi) -------------------
    // "Pay by card" button. The POS client charges the card; the fiscal
    // printer below then prints the receipt. Reached over Tailscale.
    'pos' => [
        'enabled'     => true,
        'host'        => '100.x.y.z',   // card terminal / POS bridge (Tailscale)
        'port'        => 5040,
        'terminal_id' => '',
        'timeout_ms'  => 35000,         // card tap can take ~30s
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
