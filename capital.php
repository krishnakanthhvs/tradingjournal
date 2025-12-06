<?php
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/functions.php';

app_start_protected();
$userId = get_current_user_id();

// Fetch capital history for this user
$rows = get_capital_history($mysqli, $userId);

// Helper to format year + month as "Dec-2025"
function format_month_label(int $year, int $month): string {
    $dt = DateTime::createFromFormat('!Y-n', $year . '-' . $month);
    if (!$dt) {
        return $year . '-' . $month;
    }
    return $dt->format('M-Y'); // e.g. "Dec-2025"
}
?>
<div class="content">
    <?php require_once __DIR__ . '/inc/topbar.php'; ?>
    <main class="main">
        <div class="card">
            <h2 class="card-title">Capital History</h2>
            <p class="text-muted mt-1">
                Month-wise capital and P&amp;L summary.
            </p>

            <div class="table-wrapper">
                <table class="datatable">
                    <thead>
                        <tr>
                            <th>SL No</th>
                            <th>Month</th>
                            <th>Capital</th>
                            <th>Profit</th>
                            <th>Loss</th>
                            <th>Capital Remaining</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($rows)): ?>
                        <tr>
                            <td colspan="6" class="text-muted">No capital records yet.</td>
                        </tr>
                    <?php else: ?>
                        <?php $sl = 1; ?>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td><?php echo $sl++; ?></td>
                                <td><?php echo htmlspecialchars(format_month_label($row['year'], $row['month'])); ?></td>
                                <td>₹<?php echo number_format($row['capital'], 2); ?></td>
                                <td class="text-profit">₹<?php echo number_format($row['month_profit'], 2); ?></td>
                                <td class="text-loss">₹<?php echo number_format($row['month_loss'], 2); ?></td>
                                <td><strong>₹<?php echo number_format($row['capital_remaining'], 2); ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
    <?php require_once __DIR__ . '/inc/footer.php'; ?>
</div>