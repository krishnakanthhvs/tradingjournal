<?php
// test_mail.php

$to = 'krishnakanthhanumanthu@gmail.com'; // 🔴 change this
$subject = '✅ Test Mail – MyDailyDiary';
$message = '
    <h2>Email Test Successful 🎉</h2>
    <p>This is a test email from <b>MyDailyDiary</b>.</p>
    <p>If you received this, your email service is working correctly.</p>
    <br>
    <small>Sent at: ' . date('d M Y, h:i A') . '</small>
';

$headers  = "MIME-Version: 1.0\r\n";
$headers .= "Content-type:text/html;charset=UTF-8\r\n";
$headers .= "From: MyDailyDiary <noreply@mydailydiary.space>\r\n";

if (mail($to, $subject, $message, $headers)) {
    echo "✅ Email sent successfully";
} else {
    echo "❌ Email failed to send";
}