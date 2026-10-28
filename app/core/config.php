<?php 

$isLocal = (
    $_SERVER["SERVER_NAME"] == "localhost" || 
    $_SERVER["SERVER_NAME"] == "127.0.0.1" ||
    strpos($_SERVER["SERVER_NAME"], '192.168.') === 0 ||
    strpos($_SERVER["SERVER_NAME"], '10.') === 0
);

if($isLocal){
    define('ROOT', 'http://' . $_SERVER["SERVER_NAME"] . '/HiveSense_Website');
    define('DBSERVER', 'localhost');
    define('DBUSER', 'root');
    define('DBPASS', '');
    define('DBNAME', 'hivesense_db');
} else {
    define('ROOT', 'https://hivesense.whf.bz');
    define('DBSERVER', 'localhost');
    define('DBUSER', 'mrzubgva_hivesense_db');
    define('DBPASS', '62XzsstFkAyXMtfsU9Ug');
    define('DBNAME', 'mrzubgva_hivesense_db');
}

// ── ThingSpeak Bridge ────────────────────────────────────────
// Read-only key from your ThingSpeak channel's "API Keys" tab —
// NOT the same as the write key burned into the ESP32 firmware.
define('THINGSPEAK_CHANNEL_ID',    '3430279');
define('THINGSPEAK_READ_API_KEY',  'TP424D39J8CFWG18');

// Which hs_sensors row these polled readings get attributed to.
define('THINGSPEAK_SENSOR_ID', 1);

// Shared secret the external cron poller must send as ?secret=...
// Generate one value and hardcode it here (don't regenerate on
// every request — random_bytes() here would invalidate it constantly).
// Example of how to generate one locally: php -r "echo bin2hex(random_bytes(16));"
define('THINGSPEAK_BRIDGE_SECRET', 'a1b2c3d4e5f67890abcdef1234567890');
define('FOOD_MAX_G', 20000);
// PHP and MySQL must agree on time (Base_Model sets MySQL to +08:00)
date_default_timezone_set('Asia/Manila');

// A hive counts as offline if it has sent nothing for this many minutes
if (!defined('SENSOR_OFFLINE_MIN')) define('SENSOR_OFFLINE_MIN', 10);

// Don't show PHP errors to visitors on the live site
error_reporting(E_ALL);
ini_set('display_errors', $isLocal ? 1 : 0);
?>