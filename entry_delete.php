<?php
// entry_delete.php

// 1) Make sure DB is loaded first so $mysqli exists
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/functions.php';

app_start_protected();

$userId = get_current_user_id();
$id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Only proceed if we have a valid ID + logged-in user
if ($id > 0 && $userId) {

    // Optional but recommended: enforce the 24h rule on the server side as well
    if ($stmt = $mysqli->prepare('SELECT created_at FROM trades WHERE id = ? AND user_id = ? LIMIT 1')) {
        $stmt->bind_param('ii', $id, $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        $stmt->close();

        if ($row) {
            // Re-use your existing helper
            if (is_trade_editable($row)) {
                if ($del = $mysqli->prepare('DELETE FROM trades WHERE id = ? AND user_id = ?')) {
                    $del->bind_param('ii', $id, $userId);
                    $del->execute();
                    $del->close();
                    $msg = 'Trade deleted';
                } else {
                    $msg = 'Could not prepare delete statement';
                }
            } else {
                $msg = 'This trade can no longer be deleted (24h window expired).';
            }
        } else {
            $msg = 'Trade not found';
        }
    } else {
        $msg = 'Could not prepare lookup statement';
    }
} else {
    $msg = 'Invalid trade id';
}

// Redirect back with a toast message
header('Location: entries.php?toast=' . urlencode($msg));
exit;