<?php
/**
 * Fetch previous day OHLC for NIFTY 50
 * Symbol: ^NSEI
 */
function get_nifty_previous_day(): ?array
{
    $url = 'https://query1.finance.yahoo.com/v8/finance/chart/%5ENSEI?range=5d&interval=1d';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_USERAGENT => 'Mozilla/5.0'
    ]);

    $json = curl_exec($ch);
    curl_close($ch);

    if (!$json) return null;

    $data = json_decode($json, true);
    if (
        !isset($data['chart']['result'][0]['indicators']['quote'][0])
    ) {
        return null;
    }

    $quotes = $data['chart']['result'][0]['indicators']['quote'][0];
    $timestamps = $data['chart']['result'][0]['timestamp'];

    // Take LAST COMPLETE DAY (ignore today)
    $count = count($timestamps);
    if ($count < 2) return null;

    $idx = $count - 2;

    return [
        'date'  => date('d M Y', $timestamps[$idx]),
        'high'  => round($quotes['high'][$idx], 2),
        'low'   => round($quotes['low'][$idx], 2),
        'close' => round($quotes['close'][$idx], 2),
    ];
}

/**
 * Calculate pivot levels
 */
function calculate_pivots(float $high, float $low, float $close): array
{
    $pivot = ($high + $low + $close) / 3;

    return [
        'pivot' => round($pivot, 2),
        'r1'    => round((2 * $pivot) - $low, 2),
        's1'    => round((2 * $pivot) - $high, 2),
        'r2'    => round($pivot + ($high - $low), 2),
        's2'    => round($pivot - ($high - $low), 2),
    ];
}