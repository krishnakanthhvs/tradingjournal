<?php
require_once __DIR__ . '/inc/functions.php';
app_start_protected();
require_once __DIR__ . '/inc/db.php';

$userId = get_current_user_id();

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=trades_export_' . date('Ymd_His') . '.csv');

$output = fopen('php://output', 'w');
fputcsv($output, [
    'Trade #','Date','Day','No of trades','Opening Bal','Closing Bal','Profit','Loss',
    'Setup Type','Entry Reason','Rule Followed?','Emotion','Strategy Tags','Mistake Tags','Notes'
]);

$stmt = $mysqli->prepare('SELECT trade_no, trade_date, day, no_trades, opening_bal, closing_bal, profit, loss,
                                setup_type, entry_reason, rule_followed, emotion, strategy_tags, mistake_tags, notes
                          FROM trades WHERE user_id = ? ORDER BY trade_date ASC, id ASC');
$stmt->bind_param('i', $userId);
$stmt->execute();
$res = $stmt->get_result();

while ($row = $res->fetch_assoc()) {
    fputcsv($output, [
        $row['trade_no'],
        $row['trade_date'],
        $row['day'],
        $row['no_trades'],
        $row['opening_bal'],
        $row['closing_bal'],
        $row['profit'],
        $row['loss'],
        $row['setup_type'],
        $row['entry_reason'],
        $row['rule_followed'],
        $row['emotion'],
        $row['strategy_tags'],
        $row['mistake_tags'],
        $row['notes']
    ]);
}
$stmt->close();
fclose($output);
exit;
