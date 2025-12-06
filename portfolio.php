<?php
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/functions.php';

$activePage = 'portfolio';
$allStocks  = get_nse_equity_list();

//error_log('Portfolio: loaded ' . count($allStocks) . ' NSE stocks');

app_start_protected();
$userId = get_current_user_id();

// Handle add-lot form POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_lot') {
    $symbol     = $_POST['stock_symbol'] ?? '';
    $name       = $_POST['stock_name'] ?? '';
    $trade_date = $_POST['trade_date'] ?? '';
    $qty        = $_POST['qty'] ?? '';
    $price      = $_POST['price'] ?? '';
    $charges    = $_POST['charges'] ?? '0';
    $notes      = $_POST['notes'] ?? '';

    $ok = portfolio_add_lot($mysqli, $userId, [
        'symbol'     => $symbol,
        'name'       => $name,
        'trade_date' => $trade_date,
        'qty'        => $qty,
        'price'      => $price,
        'charges'    => $charges,
        'notes'      => $notes,
    ]);

    $toast = $ok ? 'Stock added to portfolio.' : 'Failed to add stock.';
    header('Location: portfolio.php?toast=' . urlencode($toast));
    exit;
}

// Handle delete position
if (isset($_GET['delete']) && ctype_digit($_GET['delete'])) {
    $pid = (int)$_GET['delete'];
    if ($stmt = $mysqli->prepare("DELETE FROM portfolio_positions WHERE id = ? AND user_id = ?")) {
        $stmt->bind_param('ii', $pid, $userId);
        $stmt->execute();
        $stmt->close();
    }
    header('Location: portfolio.php?toast=' . urlencode('Position deleted.'));
    exit;
}

// Handle sold (mark inactive)
if (isset($_GET['sold']) && ctype_digit($_GET['sold'])) {
    $pid = (int)$_GET['sold'];
    if ($stmt = $mysqli->prepare("
        UPDATE portfolio_positions
        SET is_active = 0, updated_at = NOW()
        WHERE id = ? AND user_id = ?
    ")) {
        $stmt->bind_param('ii', $pid, $userId);
        $stmt->execute();
        $stmt->close();
    }
    header('Location: portfolio.php?toast=' . urlencode('Position marked as sold.'));
    exit;
}

/**
 * AJAX: fetch LTP for a given symbol
 * GET portfolio.php?ajax=ltp&symbol=RELIANCE
 */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'ltp' && isset($_GET['symbol'])) {
    header('Content-Type: application/json');

    $sym = strtoupper(preg_replace('/[^A-Za-z0-9_]/', '', $_GET['symbol']));
    $ltp = fetch_nse_equity_ltp($sym);

    echo json_encode([
        'symbol' => $sym,
        'ltp'    => $ltp,
    ]);
    exit;
}

// For View lots (AJAX)
if (isset($_GET['ajax']) && $_GET['ajax'] === 'lots' && isset($_GET['position_id'])) {
    $pid = (int)$_GET['position_id'];
    header('Content-Type: application/json');
    echo json_encode(get_portfolio_lots($mysqli, $userId, $pid));
    exit;
}

// Stats + data for page
$positions  = get_portfolio_positions($mysqli, $userId);

// Build symbol->LTP map for summary calc
$symbolLtp = [];
foreach ($positions as $p) {
    if ($p['last_ltp'] !== null) {
        $symbolLtp[$p['symbol']] = (float)$p['last_ltp'];
    }
}

// Summary cards
$totalInvested = 0.0;
$totalCurrent  = 0.0;
$bestStock     = null;
$worstStock    = null;

// Compute per-position P&L for summary
foreach ($positions as &$p) {
    $qty   = (float)$p['total_qty'];
    $avg   = (float)$p['avg_price'];
    $ltp   = $p['last_ltp'] !== null ? (float)$p['last_ltp'] : $avg;
    $cost  = $qty * $avg;
    $value = $qty * $ltp;
    $pnl   = $value - $cost;

    $p['_cost']  = $cost;
    $p['_value'] = $value;
    $p['_pnl']   = $pnl;

    $totalInvested += $cost;
    $totalCurrent  += $value;

    if ($bestStock === null || $pnl > $bestStock['_pnl']) {
        $bestStock = $p;
    }
    if ($worstStock === null || $pnl < $worstStock['_pnl']) {
        $worstStock = $p;
    }
}
unset($p);

// Weekly P&L = sum of (LTP - buy_price)*qty for lots in last 7 days
$weeklyPnl = 0.0;
$today     = new DateTime('now', new DateTimeZone('Asia/Kolkata'));
$weekStart = (clone $today)->modify('-6 days')->format('Y-m-d');
$todayStr  = $today->format('Y-m-d');

if ($stmt = $mysqli->prepare("
    SELECT 
        pp.symbol,
        pl.trade_date,
        pl.qty,
        pl.price
    FROM portfolio_lots pl
    JOIN portfolio_positions pp 
        ON pl.position_id = pp.id
    WHERE 
        pl.user_id = ?
        AND pl.trade_date BETWEEN ? AND ?
        AND pp.is_active = 1
")) {
    $stmt->bind_param('iss', $userId, $weekStart, $todayStr);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $sym = strtoupper($r['symbol']);
        // use cached LTP if we already fetched, else fetch once
        $ltp = $symbolLtp[$sym] ?? fetch_nse_equity_ltp($sym);
        if ($ltp === null) continue;

        $weeklyPnl += ($ltp - (float)$r['price']) * (float)$r['qty'];
    }
    $stmt->close();
}

// Optional toast
$toast = $_GET['toast'] ?? '';
if ($toast) {
    echo '<script>document.addEventListener("DOMContentLoaded",function(){document.body.setAttribute("data-toast",'
         . json_encode($toast) . ');});</script>';
}
?>
<div class="content">
    <?php require_once __DIR__ . '/inc/topbar.php'; ?>
    <main class="main">

        <!-- Header + Add Stock -->
        <div class="card" style="display:flex;justify-content:space-between;align-items:center;gap:0.75rem;">
            <div>
                <h2 class="card-title" style="margin-bottom:0;">Portfolio</h2>
                <p class="text-muted mt-1">Track all equity positions bought on NSE.</p>
            </div>
            <button class="btn btn-small" type="button" id="openAddStock">+ Add Stock</button>
        </div>

        <!-- Summary cards -->
        <div class="columns-4" style="margin-bottom:1rem;">
            <!-- Total Portfolio -->
            <?php
            $overallPnl     = $totalCurrent - $totalInvested;
            $overallPnlPct  = $totalInvested > 0 ? ($overallPnl / $totalInvested) * 100 : 0;

            $pnlColor = $overallPnl > 0 ? 'text-profit' : ($overallPnl < 0 ? 'text-loss' : 'text-muted');
            $pnlLabel = $overallPnl > 0 ? 'Overall Profit' : ($overallPnl < 0 ? 'Overall Loss' : 'No Gain/Loss');
            ?>

            <div class="card card-accent-blue">
                <h3 class="card-title">Total Portfolio Value</h3>
                <p class="text-muted mt-1">Current market value</p>

                <div class="mt-1" style="font-size:1.2rem;">
                    <strong>₹<?php echo number_format($totalCurrent, 2); ?></strong>
                </div>

                <p class="mt-1">
                    <span class="text-muted" style="font-size:0.8em;">
                        Invested: <strong style="font-size:0.8em;">₹<?php echo number_format($totalInvested, 2); ?></strong>
                    </span>
                    
                    <?php if ($overallPnl != 0): ?>
                        <span style="margin:0 6px;">|</span>

                        <span class="<?php echo $pnlColor; ?>" style="font-size: 0.8em;">
                            <?php echo $pnlLabel; ?>:
                            <strong style="font-size:0.8em;">₹<?php echo number_format(abs($overallPnl), 2); ?></strong>

                            <!-- percentage small -->
                            <span style="font-size:0.8em;">
                                (<?php echo ($overallPnl > 0 ? '+' : '-')
                                    . number_format(abs($overallPnlPct), 2); ?>%)
                            </span>
                        </span>
                    <?php endif; ?>
                </p>
            </div>

            <!-- Weekly P&L -->
            <?php
            $weeklySignClass = $weeklyPnl > 0 ? 'text-profit' : ($weeklyPnl < 0 ? 'text-loss' : 'text-muted');
            $weeklyCardClass = $weeklyPnl > 0 ? 'card-accent-green' : ($weeklyPnl < 0 ? 'card-accent-amber' : '');
            ?>
            <div class="card <?php echo $weeklyCardClass; ?>">
                <h3 class="card-title">This Week P&amp;L</h3>
                <p class="text-muted mt-1">Unrealized P&amp;L for buys in last 7 days</p>
                <div class="mt-1">
                    <span class="<?php echo $weeklySignClass; ?>">
                        <?php
                        if ($weeklyPnl > 0) {
                            echo '₹' . number_format($weeklyPnl, 2);
                        } elseif ($weeklyPnl < 0) {
                            echo '-₹' . number_format(abs($weeklyPnl), 2);
                        } else {
                            echo '₹0.00';
                        }
                        ?>
                    </span>
                </div>
            </div>

            <!-- Best stock -->
            <div class="card card-accent-green">
                <h3 class="card-title">Best Performer</h3>
                <p class="text-muted mt-1">Highest unrealized P&amp;L</p>
                <?php if ($bestStock): ?>
                    <div class="mt-1" style="font-size:0.95rem;">
                        <strong><?php echo htmlspecialchars($bestStock['symbol']); ?></strong>
                        <span class="text-muted"> · <?php echo htmlspecialchars($bestStock['name']); ?></span>
                    </div>
                    <div class="mt-1 text-profit">
                        +₹<?php echo number_format($bestStock['_pnl'], 2); ?>
                    </div>
                <?php else: ?>
                    <p class="text-muted mt-1">No positions yet.</p>
                <?php endif; ?>
            </div>

            <!-- Worst stock -->
            <div class="card card-accent-amber">
                <h3 class="card-title">Worst Performer</h3>
                <p class="text-muted mt-1">Lowest unrealized P&amp;L</p>
                <?php if ($worstStock): ?>
                    <div class="mt-1" style="font-size:0.95rem;">
                        <strong><?php echo htmlspecialchars($worstStock['symbol']); ?></strong>
                        <span class="text-muted"> · <?php echo htmlspecialchars($worstStock['name']); ?></span>
                    </div>
                    <div class="mt-1 <?php echo $worstStock['_pnl'] < 0 ? 'text-loss' : 'text-muted'; ?>">
                        <?php
                        if ($worstStock['_pnl'] < 0) {
                            echo '-₹' . number_format(abs($worstStock['_pnl']), 2);
                        } else {
                            echo '₹' . number_format($worstStock['_pnl'], 2);
                        }
                        ?>
                    </div>
                <?php else: ?>
                    <p class="text-muted mt-1">No positions yet.</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Positions table -->
        <div class="card">
            <h3 class="card-title">Open Positions</h3>
            <div class="table-wrapper">
                <table class="datatable">
                    <thead>
                        <tr>
                            <th>SL No</th>
                            <th>Stock</th>
                            <th>No of shares</th>
                            <th>Buy Price</th>
                            <th>Amount Invested</th>
                            <th>Current Value</th>
                            <th>LTP</th>
                            <th>P&amp;L</th>
                            <th style="width:160px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($positions)): ?>
                        <tr><td colspan="6">No positions yet.</td></tr>
                    <?php else: ?>
                        <?php $sl = 1; ?>
                        <?php foreach ($positions as $p): ?>
                            <?php
                                $ltp      = $p['last_ltp'] ?? $p['avg_price'];
                                $pnl      = $p['_pnl'];
                                $pnlClass = $pnl > 0 ? 'text-profit' : ($pnl < 0 ? 'text-loss' : 'text-muted');
                            ?>
                            <tr data-position-id="<?php echo (int)$p['id']; ?>">
                                <td><?php echo $sl++; ?></td>

                                <td>
                                    <strong><?php echo htmlspecialchars($p['symbol']); ?></strong><br>
                                    <span class="text-muted"><?php echo htmlspecialchars($p['name']); ?></span>
                                </td>

                                <td><?php echo number_format($p['total_qty'], 2); ?></td>

                                <!-- Buy price -->
                                <td>₹<?php echo number_format($p['avg_price'], 2); ?></td>

                                <!-- Amount invested -->
                                <td>₹<?php echo number_format($p['_cost'], 2); ?></td>

                                <!-- CURRENT VALUE (qty * LTP) -->
                                <td>₹<?php echo number_format($p['_value'], 2); ?></td>

                                <!-- LTP -->
                                <td>₹<?php echo number_format($ltp, 2); ?></td>

                                <!-- P&L -->
                                <td class="<?php echo $pnlClass; ?>">
                                    <?php
                                    if ($pnl > 0) {
                                        echo '₹' . number_format($pnl, 2);
                                    } elseif ($pnl < 0) {
                                        echo '-₹' . number_format(abs($pnl), 2);
                                    } else {
                                        echo '₹0.00';
                                    }
                                    ?>
                                </td>

                                <!-- Actions -->
                                <td style="white-space:nowrap;">
                                    <button type="button" class="btn btn-small btn-secondary btn-view-pos" title="View">
                                        <i class="fa-solid fa-eye"></i>
                                    </button>
                                    <button type="button" class="btn btn-small btn-secondary btn-sold-pos" title="Mark as Sold">
                                        <i class="fa-solid fa-check"></i>
                                    </button>
                                    <button type="button" class="btn btn-small btn-danger btn-del-pos" title="Delete">
                                        <i class="fa-solid fa-trash"></i>
                                    </button>
                                </td>
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

<!-- Add Stock Modal -->
<div class="modal-backdrop" id="addStockModal">
    <div class="modal" style="max-width:600px;">
        <div class="modal-header">
            <h2 class="card-title">Add Stock</h2>
            <button type="button" class="btn btn-small btn-secondary" id="closeAddStock">Close</button>
        </div>
        <div class="modal-body">
            <form method="post" id="addStockForm">
                <input type="hidden" name="action" value="add_lot">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="stock_symbol">Stock</label>
                        <select id="stock_symbol" name="stock_symbol" required>
                            <option value="">Select stock…</option>
                            <?php foreach ($allStocks as $s): ?>
                                <option
                                    value="<?php echo htmlspecialchars($s['symbol']); ?>"
                                    data-name="<?php echo htmlspecialchars($s['name']); ?>"
                                >
                                    <?php echo htmlspecialchars($s['symbol'] . ' - ' . $s['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Hidden input so PHP gets the name as well -->
                    <input type="hidden" id="stock_name" name="stock_name">

                    <div class="form-group">
                        <label for="trade_date">Trade Date</label>
                        <input type="date" id="trade_date" name="trade_date" required>
                    </div>
                    <div class="form-group">
                        <label for="qty">Quantity</label>
                        <input type="number" step="0.01" id="qty" name="qty" required>
                    </div>
                    <div class="form-group">
                        <label for="price">Buy Price</label>
                        <input type="number" step="0.01" id="price" name="price" required>
                    </div>

                    <div class="form-group">
                        <label for="charges">Charges (optional)</label>
                        <input type="number" step="0.01" id="charges" name="charges">
                    </div>

                    <div class="form-group" style="grid-column:1/-1;">
                        <label for="notes">Notes</label>
                        <textarea id="notes" name="notes"></textarea>
                    </div>
                </div>
                <div class="form-actions">
                    <button class="btn" type="submit">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Lots Modal -->
<div class="modal-backdrop" id="viewLotsModal">
    <div class="modal" style="max-width:700px;">
        <div class="modal-header">
            <h2 class="card-title" id="viewLotsTitle">Lots</h2>
            <button type="button" class="btn btn-small btn-secondary" id="closeViewLots">Close</button>
        </div>
        <div class="modal-body">
            <div class="table-wrapper">
                <table class="table-compact" id="lotsTable">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Qty</th>
                            <th>Price</th>
                            <th>Charges</th>
                            <th>Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- filled by JS -->
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
jQuery(function($) {
    // DataTables
    if ($.fn.DataTable && !$.fn.dataTable.isDataTable('table.datatable')) {
        $('table.datatable').DataTable();
    }

    // ----- Add Stock modal -----
    const addModal = $('#addStockModal');
    const openBtn  = $('#openAddStock');
    const closeBtn = $('#closeAddStock');
    const stockSel = $('#stock_symbol');

    openBtn.on('click', () => addModal.addClass('open'));
    closeBtn.on('click', () => addModal.removeClass('open'));

    // Prefill date as today
    $('#trade_date').val(new Date().toISOString().slice(0,10));

    // DEBUG: ensure options are present
    //console.log('Portfolio: #stock_symbol option count =', stockSel.find('option').length);

    // Enable Select2 (for search) on the existing <select>
    const optionCount = stockSel.find('option').length;
    if (optionCount > 1 && $.fn.select2) {
        stockSel.select2({
            width: '100%',
            placeholder: 'Select stock…',
            allowClear: true,
            // IMPORTANT: so dropdown shows correctly inside modal
            dropdownParent: $('#addStockModal')
        });
    }

    // When stock changes, fill hidden stock_name from data-name attribute
    stockSel.on('change', function () {
        const name = $('#stock_symbol option:selected').data('name') || '';
        $('#stock_name').val(name);
    });

    // ----- View lots modal -----
    const viewModal = $('#viewLotsModal');
    const closeView = $('#closeViewLots');
    const lotsBody  = $('#lotsTable tbody');

    closeView.on('click', () => viewModal.removeClass('open'));

    $('.btn-view-pos').on('click', function() {
        const tr    = $(this).closest('tr');
        const pid   = tr.data('position-id');
        const title = tr.find('td:nth-child(2) strong').text();
        $('#viewLotsTitle').text('Lots - ' + title);

        lotsBody.empty().append(
            '<tr><td colspan="5" class="text-muted">Loading...</td></tr>'
        );

        $.getJSON('portfolio.php', { ajax: 'lots', position_id: pid }, function(rows) {
            lotsBody.empty();
            if (!rows || !rows.length) {
                lotsBody.append(
                    '<tr><td colspan="5" class="text-muted">No lots found.</td></tr>'
                );
                return;
            }
            rows.forEach(function(r) {
                const d = r.trade_date;
                lotsBody.append(
                    '<tr>' +
                    '<td>' + d + '</td>' +
                    '<td>' + parseFloat(r.qty).toFixed(2) + '</td>' +
                    '<td>₹' + parseFloat(r.price).toFixed(2) + '</td>' +
                    '<td>' + (r.charges ? '₹' + parseFloat(r.charges).toFixed(2) : '—') + '</td>' +
                    '<td>' + (r.notes ? $('<div>').text(r.notes).html() : '') + '</td>' +
                    '</tr>'
                );
            });
        });

        viewModal.addClass('open');
    });

    // ----- Sold / Delete -----
    $('.btn-del-pos').on('click', function() {
        const tr  = $(this).closest('tr');
        const pid = tr.data('position-id');
        if (!confirm('Delete this position? All its lots will be removed.')) return;
        window.location.href = 'portfolio.php?delete=' + pid;
    });

    $('.btn-sold-pos').on('click', function() {
        const tr  = $(this).closest('tr');
        const pid = tr.data('position-id');
        if (!confirm('Mark this position as sold? It will be removed from active portfolio.')) return;
        window.location.href = 'portfolio.php?sold=' + pid;
    });
});
</script>