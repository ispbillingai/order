<?php
/**
 * Database Configuration
 * Restaurant POS System
 */

define('DB_HOST', 'localhost');
define('DB_NAME', 'order');
define('DB_USER', 'order');
define('DB_PASS', 'order');
define('DB_CHARSET', 'utf8mb4');

// PDO Connection
function getDBConnection() {
    static $pdo = null;
    
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            die("Database connection failed: " . $e->getMessage());
        }
    }
    
    return $pdo;
}

// Application settings
define('COVER_CHARGE_DEFAULT', 2.50);
define('CURRENCY_SYMBOL', '$');
define('APP_NAME', 'RestoPOS');
define('APP_VERSION', '1.0.0');

// Timezone
date_default_timezone_set('Africa/Nairobi');
