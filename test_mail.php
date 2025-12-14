<?php
// test_trading_mail.php

$to = 'krishnakanthhanumanthu@gmail.com'; // 🔴 change this

$date = date('d M Y', strtotime('-1 day'));

// ---- Dummy market data (will be replaced with live data later) ----
$symbol = 'NIFTY 50';

$prevHigh = 22150.75;
$prevLow  = 21920.30;
$close    = 22040.60;

// Pivot calculations
$pivot = ($prevHigh + $prevLow + $close) / 3;
$s1 = (2 * $pivot) - $prevHigh;
$s2 = $pivot - ($prevHigh - $prevLow);
$r1 = (2 * $pivot) - $prevLow;
$r2 = $pivot + ($prevHigh - $prevLow);

// Format numbers
$f = fn($v) => number_format($v, 2);

$subject = "📊 $symbol – Daily Levels for $date";

$message = "
<html>
<body style='font-family:Arial, sans-serif; background:#f7f7f7; padding:20px;'>
    <div style='max-width:600px; background:#ffffff; padding:20px; border-radius:8px;'>
        <h2 style='margin-top:0;'>📊 $symbol – Daily Trading Levels</h2>
        <p><strong>Date:</strong> $date</p>

        <table style='width:100%; border-collapse:collapse; margin-top:15px;'>
            <tr style='background:#f0f0f0;'>
                <th align='left' style='padding:8px;'>Level</th>
                <th align='right' style='padding:8px;'>Value</th>
            </tr>
            <tr>
                <td style='padding:8px;'>Previous High</td>
                <td align='right' style='padding:8px;'>".$f($prevHigh)."</td>
            </tr>
            <tr>
                <td style='padding:8px;'>Previous Low</td>
                <td align='right' style='padding:8px;'>".$f($prevLow)."</td>
            </tr>
            <tr>
                <td style='padding:8px;'>Previous Close</td>
                <td align='right' style='padding:8px;'>".$f($close)."</td>
            </tr>
            <tr style='background:#e8f4ff;'>
                <td style='padding:8px;'><strong>Pivot Point</strong></td>
                <td align='right' style='padding:8px;'><strong>".$f($pivot)."</strong></td>
            </tr>
            <tr>
                <td style='padding:8px; color:#d32f2f;'>Resistance 1 (R1)</td>
                <td align='right' style='padding:8px; color:#d32f2f;'>".$f($r1)."</td>
            </tr>
            <tr>
                <td style='padding:8px; color:#d32f2f;'>Resistance 2 (R2)</td>
                <td align='right' style='padding:8px; color:#d32f2f;'>".$f($r2)."</td>
            </tr>
            <tr>
                <td style='padding:8px; color:#2e7d32;'>Support 1 (S1)</td>
                <td align='right' style='padding:8px; color:#2e7d32;'>".$f($s1)."</td>
            </tr>
            <tr>
                <td style='padding:8px; color:#2e7d32;'>Support 2 (S2)</td>
                <td align='right' style='padding:8px; color:#2e7d32;'>".$f($s2)."</td>
            </tr>
        </table>

        <p style='margin-top:20px; font-size:13px; color:#555;'>
            📌 <b>Note:</b> These are calculated using classic pivot points based on previous day's data.
        </p>

        <hr>
        <small style='color:#888;'>
            Sent by MyDailyDiary • ".date('d M Y, h:i A')."
        </small>
    </div>
</body>
</html>
";

$headers  = "MIME-Version: 1.0\r\n";
$headers .= "Content-type:text/html;charset=UTF-8\r\n";
$headers .= "From: MyDailyDiary <noreply@mydailydiary.space>\r\n";

if (mail($to, $subject, $message, $headers)) {
    echo "✅ Trading email sent successfully";
} else {
    echo "❌ Failed to send trading email";
}