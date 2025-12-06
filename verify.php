<?php
session_start();
require_once __DIR__ . '/inc/db.php';

$token = $_GET['token'] ?? '';
$ok = false;

if ($token !== '') {
    $stmt = $mysqli->prepare('UPDATE users SET is_verified = 1, verification_token = NULL WHERE verification_token = ?');
    $stmt->bind_param('s', $token);
    $stmt->execute();
    if ($stmt->affected_rows > 0) {
        $ok = true;
    }
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Email Verification - Trading Dashboard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="login-wrapper">
    <div class="login-card">
        <h1>Email Verification</h1>
        <?php if ($ok): ?>
            <p>Your email has been verified. You can now <a href="login.php" style="color:#93c5fd;">login</a>.</p>
        <?php else: ?>
            <p>Invalid or expired verification link.</p>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
