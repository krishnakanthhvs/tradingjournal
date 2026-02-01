<?php
require_once __DIR__ . '/../inc/functions.php';
require_once __DIR__ . '/../inc/cache.php';

header('Content-Type: application/json');

$market = cache_get('market_snapshot', 15);

if (!$market) {
    $market = get_market_snapshot();
    cache_set('market_snapshot', $market);
}

echo json_encode([
    'success' => true,
    'is_open' => $market['open'] ?? false,
    'market'  => $market
]);