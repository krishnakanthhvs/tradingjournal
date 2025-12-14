<?php
function get_previous_day_data($symbol = 'NIFTY 50') {

    $symbolMap = [
        'NIFTY 50'   => 'NIFTY',
        'BANKNIFTY' => 'BANKNIFTY',
        'SENSEX'    => 'SENSEX'
    ];

    if (!isset($symbolMap[$symbol])) {
        return null;
    }

    $url = "https://www.nseindia.com/api/equity-stockIndices?index=" . urlencode($symbol);

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            "User-Agent: Mozilla/5.0",
            "Accept: application/json",
            "Referer: https://www.nseindia.com/"
        ]
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    if (!$response) return null;

    $json = json_decode($response, true);
    if (!isset($json['data'][0])) return null;

    $data = $json['data'][0];

    return [
        'high'  => (float)$data['dayHigh'],
        'low'   => (float)$data['dayLow'],
        'close' => (float)$data['previousClose']
    ];
}