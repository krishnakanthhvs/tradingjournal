<?php
header('Content-Type: application/json');

$cacheFile = __DIR__ . '/lot_sizes_cache.json';
$cacheTTL  = 24 * 60 * 60; // 24 hours

// ✅ Serve from cache if fresh
if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTTL) {
    echo file_get_contents($cacheFile);
    exit;
}

/**
 * NOTE:
 * Dhan does NOT provide an official API.
 * Lot sizes are stable per quarter.
 * We hard-map values but keep them centrally controlled.
 */
$lotSizes = [
    'NIFTY'       => 65,
    'BANKNIFTY'   => 30,
    'FINNIFTY'    => 60,
    'MIDCPNIFTY'  => 120,
    'SENSEX'      => 20,
    'OTHER'       => 1
];

$data = [
    'success' => true,
    'updated' => date('Y-m-d H:i:s'),
    'lots'    => $lotSizes
];

// Save cache
file_put_contents($cacheFile, json_encode($data, JSON_PRETTY_PRINT));

echo json_encode($data);