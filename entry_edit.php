<?php
// entry_edit.php
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/functions.php';

$userId = get_current_user_id();
if (!$userId) {
    die('Not authenticated');
}

$tradeId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($tradeId <= 0) {
    die('Invalid trade id');
}

// --- Fetch trade for this user ---
$stmt = $mysqli->prepare("SELECT * FROM trades WHERE id = ? AND user_id = ? LIMIT 1");
$stmt->bind_param('ii', $tradeId, $userId);
$stmt->execute();
$trade = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$trade) {
    die('Trade not found');
}

// 24h safety check (optional, UI already enforces)
$editable = is_trade_editable($trade);

// Handle update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $editable) {
    $trade_no      = trim($_POST['trade_no'] ?? '');
    $trade_date    = trim($_POST['trade_date'] ?? '');
    $day           = trim($_POST['day'] ?? '');
    $no_trades     = (int)($_POST['no_trades'] ?? 0);
    $opening_bal   = (float)($_POST['opening_bal'] ?? 0);
    $closing_bal   = (float)($_POST['closing_bal'] ?? 0);
    $profit        = (float)($_POST['profit'] ?? 0);
    $loss          = (float)($_POST['loss'] ?? 0);
    $setup_type    = trim($_POST['setup_type'] ?? '');
    $entry_reason  = trim($_POST['entry_reason'] ?? '');
    $rule_followed = trim($_POST['rule_followed'] ?? '');
    $emotion       = trim($_POST['emotion'] ?? '');
    $strategy_tags = trim($_POST['strategy_tags'] ?? '');
    $mistake_tags  = trim($_POST['mistake_tags'] ?? '');
    $notes         = trim($_POST['notes'] ?? '');

    $sql = "
        UPDATE trades
           SET trade_no      = ?,
               trade_date    = ?,
               day           = ?,
               no_trades     = ?,
               opening_bal   = ?,
               closing_bal   = ?,
               profit        = ?,
               loss          = ?,
               setup_type    = ?,
               entry_reason  = ?,
               rule_followed = ?,
               emotion       = ?,
               strategy_tags = ?,
               mistake_tags  = ?,
               notes         = ?
         WHERE id = ? AND user_id = ?
         LIMIT 1
    ";
    if ($stmt = $mysqli->prepare($sql)) {
        $stmt->bind_param(
            'sssidd ddsssssssii',
            $trade_no,
            $trade_date,
            $day,
            $no_trades,
            $opening_bal,
            $closing_bal,
            $profit,
            $loss,
            $setup_type,
            $entry_reason,
            $rule_followed,
            $emotion,
            $strategy_tags,
            $mistake_tags,
            $notes,
            $tradeId,
            $userId
        );
    }
}
?>
<div class="content">
<main class="main">
    <div class="card">
        <h2 class="card-title">Edit Trade #<?php echo htmlspecialchars($trade['trade_no']); ?></h2>
        <?php if ($message): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        <form method="post">
            <div class="form-grid">
                <div class="form-group">
                    <label for="trade_no">Trade #</label>
                    <input type="text" id="trade_no" name="trade_no" required value="<?php echo htmlspecialchars($trade['trade_no']); ?>">
                </div>
                <div class="form-group">
                    <label for="trade_date">Date</label>
                    <input type="date" id="trade_date" name="trade_date" required value="<?php echo htmlspecialchars($trade['trade_date']); ?>">
                </div>
                <div class="form-group">
                    <label for="day">Day</label>
                    <input type="text" id="day" name="day" value="<?php echo htmlspecialchars($trade['day']); ?>">
                </div>
                <div class="form-group">
                    <label for="no_trades">No of trades</label>
                    <input type="number" id="no_trades" name="no_trades" min="0" value="<?php echo htmlspecialchars($trade['no_trades']); ?>">
                </div>
                <div class="form-group">
                    <label for="opening_bal">Opening Bal</label>
                    <input type="number" step="0.01" id="opening_bal" name="opening_bal" value="<?php echo htmlspecialchars($trade['opening_bal']); ?>">
                </div>
                <div class="form-group">
                    <label for="closing_bal">Closing Bal</label>
                    <input type="number" step="0.01" id="closing_bal" name="closing_bal" value="<?php echo htmlspecialchars($trade['closing_bal']); ?>">
                </div>
                <div class="form-group">
                    <label for="profit">Profit</label>
                    <input type="number" step="0.01" id="profit" name="profit" value="<?php echo htmlspecialchars($trade['profit']); ?>">
                </div>
                <div class="form-group">
                    <label for="loss">Loss</label>
                    <input type="number" step="0.01" id="loss" name="loss" value="<?php echo htmlspecialchars($trade['loss']); ?>">
                </div>
                <div class="form-group">
                    <label for="setup_type">Setup Type</label>
                    <input type="text" id="setup_type" name="setup_type" value="<?php echo htmlspecialchars($trade['setup_type']); ?>">
                </div>
                <div class="form-group">
                    <label for="entry_reason">Entry Reason</label>
                    <input type="text" id="entry_reason" name="entry_reason" value="<?php echo htmlspecialchars($trade['entry_reason']); ?>">
                </div>
                <div class="form-group">
                    <label for="rule_followed">Rule Followed?</label>
                    <input type="text" id="rule_followed" name="rule_followed" value="<?php echo htmlspecialchars($trade['rule_followed']); ?>">
                </div>
                <div class="form-group">
                    <label for="emotion">Emotion</label>
                    <input type="text" id="emotion" name="emotion" value="<?php echo htmlspecialchars($trade['emotion']); ?>">
                </div>
                <div class="form-group">
                    <label for="strategy_tags">Strategy Tags</label>
                    <input id="strategy_tags" name="strategy_tags" value="<?php echo htmlspecialchars($trade['strategy_tags']); ?>">
                </div>
                <div class="form-group">
                    <label for="mistake_tags">Mistake Tags</label>
                    <input id="mistake_tags" name="mistake_tags" value="<?php echo htmlspecialchars($trade['mistake_tags']); ?>">
                </div>
                <div class="form-group" style="grid-column:1/-1;">
                    <label for="notes">Notes</label>
                    <textarea id="notes" name="notes"><?php echo htmlspecialchars($trade['notes']); ?></textarea>
                </div>
            </div>
            <div class="form-actions">
                <button class="btn" type="submit">Save Changes</button>
                <a class="btn btn-secondary" href="entries.php">Cancel</a>
            </div>
        </form>
    </div>
</main>
<?php require_once __DIR__ . '/inc/footer.php'; ?>
