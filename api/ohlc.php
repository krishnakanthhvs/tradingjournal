<?php
header('Content-Type: application/json');

$date   = $_GET['date'] ?? '';
$symbol = $_GET['symbol'] ?? '';

if (!$date || !$symbol) {
    echo json_encode(['success' => false, 'msg' => 'Missing params']);
    exit;
}

$symbolMap = [
    'NIFTY'     => '^NSEI',
    'BANKNIFTY' => '^NSEBANK',
    'FINNIFTY'  => '^NSEFIN',
    'SENSEX'    => '^BSESN'
];

if (!isset($symbolMap[$symbol])) {
    echo json_encode(['success' => false, 'msg' => 'Invalid symbol']);
    exit;
}

$from = strtotime($date . ' 00:00:00');
$to   = strtotime($date . ' 23:59:59');

$yahooSymbol = urlencode($symbolMap[$symbol]);

$url = "https://query1.finance.yahoo.com/v8/finance/chart/{$yahooSymbol}?period1={$from}&period2={$to}&interval=1d";

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_USERAGENT => 'Mozilla/5.0',
    CURLOPT_TIMEOUT => 10
]);

$res = curl_exec($ch);
curl_close($ch);

$data = json_decode($res, true);
$quote = $data['chart']['result'][0]['indicators']['quote'][0] ?? null;

if (!$quote || empty($quote['open'][0])) {
    echo json_encode([
        'success' => false,
        'msg' => 'Market closed / no data'
    ]);
    exit;
}

$open   = round($quote['open'][0], 2);
$close  = round($quote['close'][0], 2);
$points = round($close - $open, 2);

$trend = $points > 0 ? 'Bullish' : ($points < 0 ? 'Bearish' : 'Sideways');

echo json_encode([
    'success' => true,
    'open'    => $open,
    'close'   => $close,
    'points'  => $points,
    'trend'   => $trend
]);