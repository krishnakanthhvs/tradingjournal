<?php
require_once __DIR__ . '/../inc/db.php';

/**
 * Example static values (TEMP)
 * Later we replace this with live API data
 */
$symbol = 'NIFTY';

$high  = 22450.75;
$low   = 22180.20;
$close = 22340.10;

$pivot = round(($high + $low + $close) / 3, 2);
$s1    = round((2 * $pivot) - $high, 2);
$s2    = round($pivot - ($high - $low), 2);

$stmt = $mysqli->prepare("
    INSERT INTO daily_levels
    (symbol, trade_date, high, low, close, pivot, s1, s2)
    VALUES (?, CURDATE() - INTERVAL 1 DAY, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        high = VALUES(high),
        low = VALUES(low),
        close = VALUES(close),
        pivot = VALUES(pivot),
        s1 = VALUES(s1),
        s2 = VALUES(s2)
");

$stmt->bind_param(
    'sdddddd',
    $symbol, $high, $low, $close, $pivot, $s1, $s2
);

$stmt->execute();
$stmt->close();