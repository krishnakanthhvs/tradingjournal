<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../inc/mailer.php';

// Email recipient
$to = 'krishnakanthhanumanthu@gmail.com';

// Subject
$subject = '📊 NIFTY 50 – Real Trading Levels (' . date('d M Y') . ')';

// Sample (temporary) body – we’ll replace with real NSE data next
$html = '
<h2>NIFTY 50 – Trading Levels</h2>
<p><strong>Date:</strong> ' . date('d M Y h:i A') . '</p>
<ul>
    <li>Previous High: 22,000</li>
    <li>Previous Low: 21,750</li>
    <li>Pivot Point: 21,900</li>
    <li>Support 1: 21,800</li>
    <li>Resistance 1: 22,050</li>
</ul>
<p style="font-size:12px;color:#666;">
This is a test email via Gmail SMTP.
</p>
';

// Send
if (send_smtp_mail($to, $subject, $html)) {
    echo 'SMTP Mail sent successfully';
} else {
    echo 'SMTP Mail failed';
}

$headers  = "MIME-Version: 1.0\r\n";
$headers .= "Content-type:text/html;charset=UTF-8\r\n";
$headers .= "From: MyDailyDiary <noreply@mydailydiary.space>\r\n";

mail($to, $subject, $message, $headers);

echo "✅ Real trading email sent";