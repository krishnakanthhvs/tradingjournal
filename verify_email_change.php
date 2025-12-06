<?php
require_once __DIR__ . '/inc/db.php';

$token = $_GET['token'] ?? '';

if ($token === '') {
    die('Invalid token.');
}

$stmt = $mysqli->prepare("
    SELECT id, pending_email
    FROM users
    WHERE email_change_token = ?
      AND pending_email IS NOT NULL
    LIMIT 1
");
$stmt->bind_param('s', $token);
$stmt->execute();
$stmt->bind_result($userId, $pendingEmail);

if ($stmt->fetch()) {
    $stmt->close();

    $up = $mysqli->prepare("
        UPDATE users
        SET email = ?, pending_email = NULL, email_change_token = NULL
        WHERE id = ?
        LIMIT 1
    ");
    $up->bind_param('si', $pendingEmail, $userId);
    if ($up->execute()) {
        echo 'Your email has been updated successfully. You can close this window.';
    } else {
        echo 'Failed to update email.';
    }
    $up->close();

} else {
    $stmt->close();
    echo 'Invalid or expired token.';
}