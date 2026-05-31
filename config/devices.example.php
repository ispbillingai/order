<?php
/**
 * Device & Payment Integrations Config (EXAMPLE)
 * RestoPOS
 *
 * Copy this file to config/devices.php and fill in real values for the till.
 * config/devices.php is git-ignored (it holds device credentials / IPs).
 *
 * MONEY NOTE: the POS stores amounts as DECIMAL (e.g. 10.50). Payment devices
 * (Cashmatic, Ingenico, Epson fiscal) work in INTEGER CENTS (1050) plus an
 * ISO 4217 numeric currency code. Conversion is handled by includes/devices.php
 * (toCents / fromCents). The database stays in DECIMAL.
 */

return [
    // ---- Currency -------------------------------------------------------
    'currency' => [
        'code'     => 978,    // ISO 4217 numeric: 978 = EUR, 840 = USD
        'symbol'   => '€',
        'decimals' => 2,
    ],

    // ---- Cashmatic automated cash machine (HTTP, browser-direct) --------
    // The Cashmatic local REST server runs on the till PC; the cashier's
    // browser calls it directly over HTTPS. Install the shipped server.pem
    // as a trusted cert on the till, or keep verify_ssl => false.
    'cashmatic' => [
        'enabled'    => true,
        'base_url'   => 'https://127.0.0.1:50301',
        'username'   => 'cashmatic',
        'password'   => 'admin',
        'verify_ssl' => false,
    ],

    // ---- Ingenico card terminal (raw TCP/XML, via local bridge) --------
    // The terminal speaks raw TCP with 4-byte-length-framed XML on
    // 127.0.0.1:9999 (CMP protocol 2.0). A browser cannot speak raw TCP, so
    // bin/ingenico-bridge.php exposes a localhost HTTP endpoint that the
    // browser calls; the bridge relays to the terminal. Run the bridge on
    // the till PC (uses your XAMPP PHP).
    'card' => [
        'enabled'       => true,
        'bridge_url'    => 'http://127.0.0.1:9998',  // browser -> our PHP bridge
        'terminal_ip'   => '127.0.0.1',              // bridge -> terminal
        'terminal_port' => 9999,
        'timeout'       => 120,                       // seconds (terminal tx timeout)
    ],

    // ---- Epson fiscal printer (ePOS-Print XML over HTTP, browser-direct)
    'fiscal' => [
        'enabled'    => true,
        'device_url' => 'https://192.168.1.50/cgi-bin/fpmate.cgi', // printer IP
        'device_id'  => 'local_printer',
        'timeout'    => 10,        // seconds
        'auto_print' => true,      // auto-print fiscal receipt after a payment
        'verify_ssl' => false,
    ],

    // ---- QR codes (digital receipt) ------------------------------------
    'qr' => [
        'enabled'      => true,
        // Base URL used to build the digital-receipt link encoded in the QR.
        'receipt_base' => 'https://your-domain.example/cashier/receipt.php',
    ],
];
