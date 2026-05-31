<?php
/**
 * Database Configuration (EXAMPLE)
 * Restaurant POS System
 *
 * Copy this file to database.php and fill in your real credentials.
 * database.php is git-ignored so secrets stay out of the repository.
 */

define('DB_HOST', 'localhost');
define('DB_NAME', 'your_database_name');
define('DB_USER', 'your_database_user');
define('DB_PASS', 'your_database_password');
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
