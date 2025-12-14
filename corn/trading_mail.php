<?php
require_once __DIR__ . '/inc/nse_data.php';
require_once __DIR__ . '/inc/pivot.php';

$to = 'krishnakanthhanumanthu@gmail.com';
$symbol = 'NIFTY 50';

$data = get_previous_day_data($symbol);

if (!$data) {
    die('Failed to fetch NSE data');
}

$pivots = calculate_pivots($data['high'], $data['low'], $data['close']);

$f = fn($v) => number_format($v, 2);
$date = date('d M Y');

$subject = "📊 $symbol – Real Trading Levels ($date)";

$message = "
<b>$symbol – Daily Levels</b><br><br>

Previous High: {$f($data['high'])}<br>
Previous Low: {$f($data['low'])}<br>
Previous Close: {$f($data['close'])}<br><br>

<b>Pivot:</b> {$f($pivots['pivot'])}<br>
Resistance 1: {$f($pivots['r1'])}<br>
Resistance 2: {$f($pivots['r2'])}<br>
Support 1: {$f($pivots['s1'])}<br>
Support 2: {$f($pivots['s2'])}<br><br>

<i>Data Source: NSE India</i>
";

$headers  = "MIME-Version: 1.0\r\n";
$headers .= "Content-type:text/html;charset=UTF-8\r\n";
$headers .= "From: MyDailyDiary <noreply@mydailydiary.space>\r\n";

mail($to, $subject, $message, $headers);

echo "✅ Real trading email sent";