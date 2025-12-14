<?php
date_default_timezone_set('Asia/Kolkata');

/* ================== INCLUDES ================== */
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/mailer.php';

/* ================== LOGGING ================== */
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/mail.log');

/* ================== FETCH USER EMAILS ================== */
$emails = [];
$res = $mysqli->query("SELECT email FROM users WHERE email IS NOT NULL AND email != ''");
while ($row = $res->fetch_assoc()) {
    $emails[] = $row['email'];
}

if (empty($emails)) {
    error_log("No user emails found");
    exit;
}

/* ================== FETCH REAL NIFTY DATA (YAHOO) ================== */
function fetchNiftyOHLC() {
    $url = "https://query1.finance.yahoo.com/v8/finance/chart/%5ENSEI?range=7d&interval=1d";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT => 'Mozilla/5.0',
        CURLOPT_TIMEOUT => 10
    ]);
    $res = curl_exec($ch);
    curl_close($ch);

    if (!$res) return null;

    $json = json_decode($res, true);
    $r = $json['chart']['result'][0] ?? null;
    if (!$r) return null;

    $q = $r['indicators']['quote'][0];
    $t = $r['timestamp'];

    // last completed candle
    $i = count($t) - 2;

    return [
        'date'  => date('d M Y', $t[$i]),
        'high'  => round($q['high'][$i], 2),
        'low'   => round($q['low'][$i], 2),
        'close' => round($q['close'][$i], 2)
    ];
}

$ohlc = fetchNiftyOHLC();
if (!$ohlc) {
    error_log("Failed fetching NIFTY data");
    exit;
}

/* ================== PIVOT CALC ================== */
$H = $ohlc['high'];
$L = $ohlc['low'];
$C = $ohlc['close'];

$pivot = round(($H + $L + $C) / 3, 2);
$r1 = round((2 * $pivot) - $L, 2);
$s1 = round((2 * $pivot) - $H, 2);
$r2 = round($pivot + ($H - $L), 2);
$s2 = round($pivot - ($H - $L), 2);
$r3 = round($H + 2 * ($pivot - $L), 2);
$s3 = round($L - 2 * ($H - $pivot), 2);

/* ================== GLOBAL NEWS ================== */
$news = [];
$rss = simplexml_load_file(
    'https://news.google.com/rss/search?q=stock+market+global&hl=en-IN&gl=IN&ceid=IN:en'
);

if ($rss) {
    foreach ($rss->channel->item as $item) {
        $news[] = [
            'title' => (string)$item->title,
            'link'  => (string)$item->link
        ];
        if (count($news) >= 5) break;
    }
}

/* ================== EMAIL HTML ================== */
$today = date('d M Y');
$day   = date('l');
$time  = date('h:i A');

$logo = "https://mydailydiary.space/assets/img/logo_without_bg.png";

$html = "
<!DOCTYPE html>
<html>
<head>
<style>
body{font-family:Arial;background:#f4f6f8;margin:0;padding:20px;}
.card{max-width:720px;margin:auto;background:#fff;border-radius:10px;padding:20px;}
h2{text-align:center;margin:10px 0;}
.table{width:100%;border-collapse:collapse;}
.table td{padding:8px;border-bottom:1px solid #eee;}
.green{color:#0a8a00;font-weight:bold;}
.red{color:#d22;font-weight:bold;}
.footer{text-align:center;font-size:12px;color:#888;margin-top:20px;}
</style>
</head>
<body>

<div class='card'>
<img src='$logo' style='display:block;margin:auto;height:50px;'>

<h2>NIFTY 50 – Trading Levels</h2>
<p style='text-align:center'>$day, $today | Sent at $time IST</p>

<h3>Previous Trading Day ( {$ohlc['date']} )</h3>
<table class='table'>
<tr><td>High</td><td class='green'>{$H}</td></tr>
<tr><td>Low</td><td class='red'>{$L}</td></tr>
<tr><td>Close</td><td>{$C}</td></tr>
</table>

<h3>Today's Pivot Levels</h3>
<table class='table'>
<tr><td>Pivot</td><td><b>$pivot</b></td></tr>
<tr><td>Resistance 1</td><td class='green'>$r1</td></tr>
<tr><td>Resistance 2</td><td class='green'>$r2</td></tr>
<tr><td>Resistance 3</td><td class='green'>$r3</td></tr>
<tr><td>Support 1</td><td class='red'>$s1</td></tr>
<tr><td>Support 2</td><td class='red'>$s2</td></tr>
<tr><td>Support 3</td><td class='red'>$s3</td></tr>
</table>

<h3>Top Global Stock Market News</h3>
<ul>";
foreach ($news as $n) {
    $html .= "<li><a href='{$n['link']}'>{$n['title']}</a></li>";
}
$html .= "</ul>

<div class='footer'>
This is an automated trading insight email.<br>
Happy Trading 📈
</div>

</div>
</body>
</html>";

/* ================== SEND EMAIL ================== */
$mail = getMailer();

foreach ($emails as $to) {
    $mail->clearAddresses();
    $mail->addAddress($to);
    $mail->Subject = "📊 NIFTY 50 – Trading Levels ($today)";
    $mail->Body = $html;
    $mail->send();
}

error_log("Trading email sent successfully at $time");
echo "Mail sent successfully";