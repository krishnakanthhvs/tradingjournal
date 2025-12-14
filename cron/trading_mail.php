<?php
require_once __DIR__ . '/../inc/mailer.php';
require_once __DIR__ . '/../inc/market_data.php';

// Recipient
$to = 'krishnakanthhanumanthu@gmail.com';

// Fetch NIFTY data
$nifty = get_nifty_previous_day();

if (!$nifty) {
    error_log('Failed to fetch NIFTY data');
    exit('Market data unavailable');
}

$pivots = calculate_pivots(
    $nifty['high'],
    $nifty['low'],
    $nifty['close']
);

// Subject
$subject = '📊 NIFTY 50 – Trading Levels (' . $nifty['date'] . ')';

// Mail body
$html = '
<h2 style="margin-bottom:5px;">NIFTY 50 – Trading Levels</h2>
<p><strong>Date:</strong> ' . $nifty['date'] . '</p>

<table border="1" cellpadding="8" cellspacing="0" style="border-collapse:collapse;">
<tr><td><strong>Previous High</strong></td><td>' . $nifty['high'] . '</td></tr>
<tr><td><strong>Previous Low</strong></td><td>' . $nifty['low'] . '</td></tr>
<tr><td><strong>Previous Close</strong></td><td>' . $nifty['close'] . '</td></tr>
</table>

<br>

<table border="1" cellpadding="8" cellspacing="0" style="border-collapse:collapse;">
<tr><th>Level</th><th>Value</th></tr>
<tr><td><strong>Pivot</strong></td><td>' . $pivots['pivot'] . '</td></tr>
<tr><td>Resistance 1</td><td>' . $pivots['r1'] . '</td></tr>
<tr><td>Resistance 2</td><td>' . $pivots['r2'] . '</td></tr>
<tr><td>Support 1</td><td>' . $pivots['s1'] . '</td></tr>
<tr><td>Support 2</td><td>' . $pivots['s2'] . '</td></tr>
</table>

<p style="margin-top:12px;font-size:12px;color:#666;">
Auto-generated using previous trading day data.
</p>
';

// Send mail
send_smtp_mail($to, $subject, $html);

echo 'Trading mail sent';

echo "<p>Sent at: <?php echo date('d-M-Y h:i:s A'); ?></p>";