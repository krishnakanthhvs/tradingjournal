<?php
// inc/config.php

// Detect current host (domain)
$host = $_SERVER['HTTP_HOST'] ?? '';

// Default: LOCAL DEVELOPMENT (MAMP)
$db_host = 'localhost';
$db_user = 'root';
$db_pass = 'root';   // or '' depending on your local config
$db_name = 'trading_dashboard';

// Detect PRODUCTION domain
if ($host === 'mydailydiary.space' || $host === 'www.mydailydiary.space') {
    // Production DB credentials
    $db_host = 'localhost';      // Change if remote
    $db_user = 'u788563593_mydailydairy';
    $db_pass = 'U^TW@#c7?4d';
    $db_name = 'u788563593_mydailydairy';
}

// Define constants used across the app
define('DB_HOST', $db_host);
define('DB_USER', $db_user);
define('DB_PASS', $db_pass);
define('DB_NAME', $db_name);