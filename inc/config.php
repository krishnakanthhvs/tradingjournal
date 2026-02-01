<?php

// Detect environment safely
$isCli = (php_sapi_name() === 'cli');

// Detect host (only exists in browser)
$host = $_SERVER['HTTP_HOST'] ?? '';

// LIVE SERVER
if (
    $isCli || 
    $host === 'mydailydiary.space' || 
    $host === 'www.mydailydiary.space'
) {
    define('DB_HOST', 'localhost');
    define('DB_USER', 'u788563593_mydailydairy');
    define('DB_PASS', '7^!iT!E;n');
    define('DB_NAME', 'u788563593_mydailydairy');
}
else {
    // LOCAL
    define('DB_HOST', 'localhost');
    define('DB_USER', 'root');
    define('DB_PASS', 'root');
    define('DB_NAME', 'trading_dashboard');
}