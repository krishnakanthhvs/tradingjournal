<?php
require_once __DIR__ . '/../inc/db.php';

$to = 'yourmail@gmail.com';
$subject = '📈 Daily Pivot Levels – NIFTY';

$res = $mysqli->query("
    SELECT * FROM daily_levels
    WHERE trade_date = CURDATE() - INTERVAL 1 DAY
    AND symbol = 'NIFTY'
    LIMIT 1
");

$row = $res->fetch_assoc();

$message = "
<h2>Daily Levels – NIFTY</h2>
<table border='1' cellpadding='8'>
<tr><td>High</td><td>{$row['high']}</td></tr>
<tr><td>Low</td><td>{$row['low']}</td></tr>
<tr><td>Pivot</td><td>{$row['pivot']}</td></tr>
<tr><td>S1</td><td>{$row['s1']}</td></tr>
<tr><td>S2</td><td>{$row['s2']}</td></tr>
</table>
";

$headers  = "MIME-Version: 1.0\r\n";
$headers .= "Content-type:text/html;charset=UTF-8\r\n";
$headers .= "From: MyDailyDiary <noreply@mydailydiary.space>\r\n";

mail($to, $subject, $message, $headers);