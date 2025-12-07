<?php

// Detect current domain
$host = $_SERVER['HTTP_HOST'] ?? '';

// If running on live server
if ($host === 'mydailydiary.space' || $host === 'www.mydailydiary.space') {

    // LIVE DATABASE
    define('DB_HOST', 'localhost');
    define('DB_USER', 'u788563593_mdd_user');  // change to your live DB user
    define('DB_PASS', '7^!iT!E;n'); // change to your live DB password
    define('DB_NAME', 'u788563593_mdd_db');     // change to your live DB name

} else {

    // LOCAL DATABASE
    define('DB_HOST', 'localhost');
    define('DB_USER', 'root');
    define('DB_PASS', 'root'); // or '' depending on MAMP
    define('DB_NAME', 'trading_dashboard');

}