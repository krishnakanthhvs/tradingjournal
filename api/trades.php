<?php
// api/trades.php
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/functions.php';

header('Content-Type: application/json');

$userId = get_current_user_id();
if (!$userId) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$result = handle_trade_insert($mysqli, $userId);
echo json_encode($result);
