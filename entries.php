<?php
// entries.php
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/functions.php';

app_start_protected();

$userId  = get_current_user_id();
$result  = get_trades_for_user($mysqli, $userId);

// Fetch strategy templates for this user (only enabled)
$strategies = get_user_strategy_templates($mysqli, $userId, true);

// ---------- Precompute stats per strategy (by setup_type) ----------

$strategyStats = [];

// totals, win%, avg profit/loss, best/worst trade
$sql = "
    SELECT
        setup_type,
        COALESCE(SUM(profit), 0) AS total_profit,
        COALESCE(SUM(loss),  0) AS total_loss,
        COUNT(*)                 AS trade_count,
        SUM(CASE WHEN (profit - loss) > 0 THEN 1 ELSE 0 END) AS wins,
        MAX(profit - loss) AS best_trade,
        MIN(profit - loss) AS worst_trade,
        AVG(CASE WHEN (profit - loss) > 0 THEN (profit - loss) END) AS avg_profit,
        AVG(CASE WHEN (profit - loss) < 0 THEN (profit - loss) END) AS avg_loss
    FROM trades
    WHERE user_id = ?
      AND setup_type IS NOT NULL
      AND setup_type <> ''
    GROUP BY setup_type
";
if ($stmt = $mysqli->prepare($sql)) {
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->bind_result(
        $setupType,
        $totalProfit,
        $totalLoss,
        $tradeCount,
        $wins,
        $bestTrade,
        $worstTrade,
        $avgProfit,
        $avgLoss
    );
    while ($stmt->fetch()) {
        $tradeCount = (int)$tradeCount;
        $wins       = (int)$wins;
        $winRate    = $tradeCount > 0 ? ($wins / $tradeCount) * 100 : 0;

        $strategyStats[$setupType] = [
            'total_profit' => (float)$totalProfit,
            'total_loss'   => (float)$totalLoss,
            'trades'       => $tradeCount,
            'wins'         => $wins,
            'win_rate'     => $winRate,
            'best_trade'   => (float)$bestTrade,
            'worst_trade'  => (float)$worstTrade,
            'avg_profit'   => (float)$avgProfit,
            'avg_loss'     => (float)$avgLoss,
            'best_days'    => [], // filled below
        ];
    }
    $stmt->close();
}

// top 2 days per strategy (by net P&L)
$sql = "
    SELECT
        setup_type,
        trade_date,
        COALESCE(SUM(profit - loss), 0) AS net
    FROM trades
    WHERE user_id = ?
      AND setup_type IS NOT NULL
      AND setup_type <> ''
    GROUP BY setup_type, trade_date
    ORDER BY setup_type ASC, net DESC
";
if ($stmt = $mysqli->prepare($sql)) {
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->bind_result($setupType, $tradeDate, $net);
    $BEST_DAY_LIMIT = 1; // or 2 or 3 or however many you want
    while ($stmt->fetch()) {
        if (!isset($strategyStats[$setupType])) {
            continue;
        }
        if (count($strategyStats[$setupType]['best_days']) >= $BEST_DAY_LIMIT) {
            continue;
        }
        $strategyStats[$setupType]['best_days'][] = [
            'date' => format_trade_date($tradeDate),
            'net'  => (float)$net,
        ];
    }
    $stmt->close();
}

// ---------- Next Trade # (auto-increment based on user's trades) ----------
$nextTradeNo = 1;
if ($stmt = $mysqli->prepare('SELECT MAX(CAST(trade_no AS UNSIGNED)) AS max_no FROM trades WHERE user_id = ?')) {
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->bind_result($maxNo);
    if ($stmt->fetch() && $maxNo !== null) {
        $nextTradeNo = (int)$maxNo + 1;
    }
    $stmt->close();
}

// Optional toast message via GET
$toast = $_GET['toast'] ?? '';
if ($toast) {
    echo '<script>document.addEventListener("DOMContentLoaded",function(){document.body.setAttribute("data-toast",' . json_encode($toast) . ');});</script>';
}
?>

<div class="content">
    <?php require_once __DIR__ . '/inc/topbar.php'; ?>
    <main class="main">

        <!-- Header card with Add + Export buttons -->
        <div class="card">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:0.75rem;flex-wrap:wrap;">
                <h2 class="card-title" style="margin-bottom:0;">Entries</h2>
                <div style="display:flex;gap:0.5rem;align-items:center;flex-wrap:wrap;">
                    <button class="btn" type="button" id="openAddTrade">Add Trade</button>
                    <a class="btn btn-secondary" href="export_trades.php?format=csv">Export CSV</a>
                </div>
            </div>
        </div>

        <!-- Trade history table with filter + stats -->
        <div class="card">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:0.75rem;flex-wrap:wrap;">
                <h2 class="card-title" style="margin-bottom:0;">Trade History</h2>

                <!-- Filter + Clear in one row -->
                <div class="strategy-filter-wrap">
                    <select id="strategyFilter" class="select2">
                        <option value="">Filter strategy…</option>
                        <?php foreach ($strategies as $s): ?>
                            <option value="<?php echo htmlspecialchars($s['name']); ?>">
                                <?php echo htmlspecialchars($s['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <button type="button"
                            id="clearStrategyFilter"
                            class="btn btn-small btn-secondary btn-icon-only"
                            title="Clear filter">
                        <i class="fa-solid fa-rotate-left"></i>
                    </button>
                </div>
            </div>

            <!-- Strategy stats summary (shown when a strategy is selected) -->
            <div id="strategyStats" class="form-grid" style="margin-top:0.75rem;display:none;">
                <div>
                    <div class="text-muted">Strategy</div>
                    <div id="ss_name" class="ss-title"></div>
                </div>
                <div>
                    <div class="text-muted">Trades</div>
                    <div id="ss_trades"></div>
                </div>
                <div>
                    <div class="text-muted">Win %</div>
                    <div id="ss_winrate"></div>
                </div>
                <div>
                    <div class="text-muted">Total Profit</div>
                    <div id="ss_profit"></div>
                </div>
                <div>
                    <div class="text-muted">Total Loss</div>
                    <div id="ss_loss"></div>
                </div>
                <div>
                    <div class="text-muted">Avg Profit</div>
                    <div id="ss_avg_profit"></div>
                </div>
                <div>
                    <div class="text-muted">Avg Loss</div>
                    <div id="ss_avg_loss"></div>
                </div>
                <div class="text-muted">
                    <div class="text-muted">Best Day(s)</div>
                    <div id="ss_best_days" class="d-flex"></div>
                </div>
            </div>

            <div class="table-wrapper" style="margin-top:0.75rem;">
                <table class="datatable">
                    <thead>
                        <tr>
                            <th style="width:70px; white-space:nowrap;">Trade #</th>
                            <th style="width:110px;">Date</th>
                            <th style="width:90px;">Day</th>
                            <th style="width:80px; text-align:center;">No of trades</th>
                            <th style="width:90px; text-align:right;">Profit</th>
                            <th style="width:90px; text-align:right;">Loss</th>
                            <th>Setup Type</th>
                            <th style="width:110px;">Emotion</th>
                            <th style="width:230px; white-space:nowrap;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($result->num_rows === 0): ?>
                        <tr><td colspan="9">No trades recorded yet.</td></tr>
                    <?php else: ?>
                        <?php while ($row = $result->fetch_assoc()): ?>
                            <?php
                                $json      = htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8');
                                $editable  = is_trade_editable($row);
                                $createdAt = $row['created_at'] ?? null;
                            ?>
                            <tr data-trade="<?php echo $json; ?>">
                                <td><?php echo htmlspecialchars($row['trade_no']); ?></td>
                                <td><?php echo htmlspecialchars(format_trade_date($row['trade_date'])); ?></td>
                                <td><?php echo htmlspecialchars($row['day']); ?></td>
                                <td style="text-align:center;"><?php echo htmlspecialchars($row['no_trades']); ?></td>
                                <td style="text-align:right;"><?php echo htmlspecialchars($row['profit']); ?></td>
                                <td style="text-align:right;"><?php echo htmlspecialchars($row['loss']); ?></td>
                                <td><?php echo htmlspecialchars($row['setup_type']); ?></td>
                                <td><?php echo htmlspecialchars($row['emotion']); ?></td>
                                <td style="white-space:nowrap;">
                                    <!-- View always active -->
                                    <button type="button"
                                            class="btn btn-small btn-secondary btn-view-trade"
                                            title="View trade">
                                        <i class="fa-regular fa-eye"></i>
                                    </button>

                                    <?php if ($createdAt): ?>
                                        <!-- Edit button -->
                                        <button type="button"
                                                class="btn btn-small btn-secondary btn-edit-trade"
                                                data-edit-url="entry_edit.php?id=<?php echo (int)$row['id']; ?>"
                                                <?php echo $editable ? '' : 'disabled'; ?>
                                                title="Edit trade">
                                            <i class="fa-regular fa-pen-to-square"></i>
                                        </button>

                                        <!-- Delete button -->
                                        <button type="button"
                                                class="btn btn-small btn-danger btn-delete-trade"
                                                data-delete-url="entry_delete.php?id=<?php echo (int)$row['id']; ?>"
                                                <?php echo $editable ? '' : 'disabled'; ?>
                                                title="Delete trade">
                                            <i class="fa-regular fa-trash-can"></i>
                                        </button>

                                        <?php if ($editable): ?>
                                            <!-- NEW rows (< 24h): live countdown -->
                                            <div class="edit-delete-countdown text-muted"
                                                 data-created="<?php echo htmlspecialchars($createdAt); ?>"
                                                 style="display:block;font-size:0.75rem;margin-top:0.25rem;">
                                                <!-- filled by JS -->
                                            </div>
                                        <?php else: ?>
                                            <!-- OLD rows (> 24h): static message -->
                                            <div class="text-muted"
                                                 style="display:block;font-size:0.75rem;margin-top:0.25rem;">
                                                You can't edit this data anymore.
                                            </div>
                                        <?php endif; ?>

                                    <?php else: ?>
                                        <span class="text-muted" style="font-size:0.75rem;">No timestamp</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
    <?php require_once __DIR__ . '/inc/footer.php'; ?>
</div>

<!-- Add Trade Modal -->
<div class="modal-backdrop" id="addTradeModal">
    <div class="modal">
        <div class="modal-header">
            <h2 class="card-title">Add Trade Entry</h2>
            <button type="button" class="btn btn-small btn-secondary" id="closeAddTrade">Close</button>
        </div>
        <div class="modal-body">
            <form id="tradeForm" method="post" enctype="multipart/form-data">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="trade_no">Trade #</label>
                        <input type="text" id="trade_no" name="trade_no"
                               value="<?php echo htmlspecialchars($nextTradeNo); ?>" readonly>
                    </div>
                    <div class="form-group">
                        <label for="trade_date">Date</label>
                        <input type="date" id="trade_date" name="trade_date" required>
                    </div>
                    <div class="form-group">
                        <label for="day">Day</label>
                        <input type="text" id="day" name="day" readonly>
                    </div>

                    <div class="form-group">
                        <label for="no_trades">No of trades</label>
                        <select id="no_trades" name="no_trades">
                            <?php for ($i = 1; $i <= 50; $i++): ?>
                                <option value="<?php echo $i; ?>"><?php echo $i; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="opening_bal">Opening Bal</label>
                        <input type="number" step="0.01" id="opening_bal" name="opening_bal">
                    </div>
                    <div class="form-group">
                        <label for="closing_bal">Closing Bal</label>
                        <input type="number" step="0.01" id="closing_bal" name="closing_bal">
                    </div>
                    <div class="form-group">
                        <label for="profit">Profit</label>
                        <input type="number" step="0.01" id="profit" name="profit" readonly>
                    </div>
                    <div class="form-group">
                        <label for="loss">Loss</label>
                        <input type="number" step="0.01" id="loss" name="loss" readonly>
                    </div>
                    <div class="form-group">
                        <label for="setup_type">Setup Type</label>
                        <select id="setup_type" name="setup_type" class="select2">
                            <option value="">Select / type...</option>
                            <?php if (!empty($strategies)): ?>
                                <?php foreach ($strategies as $s): ?>
                                    <option value="<?php echo htmlspecialchars($s['name']); ?>">
                                        <?php echo htmlspecialchars($s['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <option value="" disabled>
                                    No strategies found. Add some in the Strategies page.
                                </option>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="entry_reason">Entry Reason</label>
                        <input type="text" id="entry_reason" name="entry_reason">
                    </div>
                    <div class="form-group">
                        <label for="rule_followed">Rule Followed?</label>
                        <select id="rule_followed" name="rule_followed" class="select2">
                            <option value="">Select</option>
                            <option value="Yes">Yes</option>
                            <option value="No">No</option>
                            <option value="Partially">Partially</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="emotion">Emotion</label>
                        <select id="emotion" name="emotion" class="select2">
                            <option value="">Select</option>
                            <option value="Calm">Calm</option>
                            <option value="Fearful">Fearful</option>
                            <option value="Greedy">Greedy</option>
                            <option value="Revenge">Revenge</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="strategy_tags">Strategy Tags</label>
                        <input id="strategy_tags" name="strategy_tags" class="tagify">
                    </div>
                    <div class="form-group">
                        <label for="mistake_tags">Mistake Tags</label>
                        <input id="mistake_tags" name="mistake_tags" class="tagify">
                    </div>
                    <div class="form-group">
                        <label for="screenshot">Screenshot</label>
                        <input type="file" id="screenshot" name="screenshot" accept="image/*">
                    </div>
                    <div class="form-group" style="grid-column:1/-1;">
                        <label for="notes">Notes</label>
                        <textarea id="notes" name="notes"></textarea>
                    </div>
                </div>
                <div class="form-actions">
                    <button class="btn" type="submit">Save Entry</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Trade Modal -->
<div class="modal-backdrop" id="viewTradeModal">
    <div class="modal">
        <div class="modal-header">
            <h2 class="card-title">Trade Details</h2>
            <button type="button" class="btn btn-small btn-secondary" id="closeViewTrade">Close</button>
        </div>
        <div class="modal-body">
            <div class="form-grid">
                <div class="form-group">
                    <label>Trade #</label>
                    <div class="read-value" id="v_trade_no"></div>
                </div>
                <div class="form-group">
                    <label>Date</label>
                    <div class="read-value" id="v_trade_date"></div>
                </div>
                <div class="form-group">
                    <label>Day</label>
                    <div class="read-value" id="v_day"></div>
                </div>
                <div class="form-group">
                    <label>No of trades</label>
                    <div class="read-value" id="v_no_trades"></div>
                </div>
                <div class="form-group">
                    <label>Opening Bal</label>
                    <div class="read-value" id="v_opening_bal"></div>
                </div>
                <div class="form-group">
                    <label>Closing Bal</label>
                    <div class="read-value" id="v_closing_bal"></div>
                </div>
                <div class="form-group">
                    <label>Profit</label>
                    <div class="read-value" id="v_profit"></div>
                </div>
                <div class="form-group">
                    <label>Loss</label>
                    <div class="read-value" id="v_loss"></div>
                </div>
                <div class="form-group">
                    <label>Setup Type</label>
                    <div class="read-value" id="v_setup_type"></div>
                </div>
                <div class="form-group">
                    <label>Entry Reason</label>
                    <div class="read-value" id="v_entry_reason"></div>
                </div>
                <div class="form-group">
                    <label>Rule Followed?</label>
                    <div class="read-value" id="v_rule_followed"></div>
                </div>
                <div class="form-group">
                    <label>Emotion</label>
                    <div class="read-value" id="v_emotion"></div>
                </div>
                <div class="form-group">
                    <label>Strategy Tags</label>
                    <div class="read-value" id="v_strategy_tags"></div>
                </div>
                <div class="form-group">
                    <label>Mistake Tags</label>
                    <div class="read-value" id="v_mistake_tags"></div>
                </div>
                <div class="form-group">
                    <label>Screenshot</label>
                    <div class="read-value" id="v_screenshot"></div>
                </div>
                <div class="form-group" style="grid-column:1/-1;">
                    <label>Notes</label>
                    <div class="read-value" id="v_notes" style="white-space:pre-wrap;"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Edit Trade Modal (iframe) -->
<div class="modal-backdrop" id="editTradeModal">
    <div class="modal" style="max-width:1000px;width:100%;height:90vh;">
        <div class="modal-header">
            <h2 class="card-title">Edit Trade</h2>
            <button type="button" class="btn btn-small btn-secondary" id="closeEditTrade">Close</button>
        </div>
        <div class="modal-body" style="height:calc(100% - 40px);">
            <iframe id="editTradeFrame"
                    src=""
                    style="border:none;width:100%;height:100%;"></iframe>
        </div>
    </div>
</div>

<script>
    const STRATEGY_STATS = <?php echo json_encode($strategyStats); ?>;
</script>

<script>
jQuery(function ($) {
    const MS_24H = 24 * 60 * 60 * 1000;

    // ---------- DataTables instance ----------
    let dt = null;
    if ($.fn.DataTable) {
        if ($.fn.dataTable.isDataTable('table.datatable')) {
            dt = $('table.datatable').DataTable();
        } else {
            dt = $('table.datatable').DataTable();
        }
    }

    function formatDateDisplay(isoDate) {
        if (!isoDate) return '';
        const parts = isoDate.split('-');
        if (parts.length !== 3) return isoDate;
        const [y, m, d] = parts;
        const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        const mi = parseInt(m, 10) - 1;
        return d + '-' + months[mi] + '-' + y;
    }

    function formatRupees(val) {
        if (val === null || val === undefined || isNaN(val)) return '₹0.00';
        const num = Number(val);
        const sign = num < 0 ? '-' : '';
        return sign + '₹' + Math.abs(num).toFixed(2);
    }

    // ---------- STRATEGY FILTER + STATS ----------

    const statsMap = (typeof STRATEGY_STATS !== 'undefined') ? STRATEGY_STATS : {};
    const statsBox = document.getElementById('strategyStats');

    function applyStrategyFilter(name) {
        if (dt) {
            if (!name) {
                dt.column(6).search('').draw();
            } else {
                dt.column(6).search(name, false, false).draw();
            }
            return;
        }

        const rows = document.querySelectorAll('table.datatable tbody tr');
        rows.forEach(function (tr) {
            const json = tr.getAttribute('data-trade');
            if (!json) {
                tr.style.display = '';
                return;
            }
            let row;
            try {
                row = JSON.parse(json);
            } catch (e) {
                tr.style.display = '';
                return;
            }
            const setup = row.setup_type || '';
            tr.style.display = (!name || setup === name) ? '' : 'none';
        });
    }

    $('#strategyFilter').on('change', function () {
        const name = this.value;

        // 1) Filter table
        applyStrategyFilter(name);

        // 2) Update stats
        if (!name || !statsMap[name]) {
            if (statsBox) statsBox.style.display = 'none';
            return;
        }

        const s = statsMap[name];

        document.getElementById('ss_name').textContent    = name;
        document.getElementById('ss_trades').textContent  = s.trades || 0;
        document.getElementById('ss_winrate').textContent = (s.win_rate || 0).toFixed(1) + '%';

        document.getElementById('ss_profit').innerHTML =
            '<span class="text-profit">' + formatRupees(s.total_profit) + '</span>';

        document.getElementById('ss_loss').innerHTML =
            '<span class="text-loss">' + formatRupees(-Math.abs(s.total_loss)) + '</span>';

        document.getElementById('ss_avg_profit').innerHTML =
            s.avg_profit
                ? '<span class="text-profit">' + formatRupees(s.avg_profit) + '</span>'
                : '₹0.00';

        document.getElementById('ss_avg_loss').innerHTML =
            s.avg_loss
                ? '<span class="text-loss">' + formatRupees(-Math.abs(s.avg_loss)) + '</span>'
                : '₹0.00';

        // ---- Best days as badges ----
        const bestWrap = document.getElementById('ss_best_days');
        if (bestWrap) {
            if (!s.best_days || !s.best_days.length) {
                bestWrap.innerHTML = '<span class="text-muted">No data yet</span>';
            } else {
                bestWrap.innerHTML = s.best_days.map(d =>
                    `<span class="ss-badge">${d.date} (${formatRupees(d.net)})</span>`
                ).join('');
            }
        }

        if (statsBox) statsBox.style.display = 'grid';
    });

    // Clear / reset filter
    $('#clearStrategyFilter').on('click', function () {
        $('#strategyFilter').val('').trigger('change');
    });

    // ---------- 24h countdown for Edit / Delete ----------
    function updateEditDeleteCountdowns() {
        const now = Date.now();

        document.querySelectorAll('.edit-delete-countdown').forEach(function (el) {
            const createdStr = el.getAttribute('data-created');
            if (!createdStr) return;

            const created = new Date(createdStr.replace(' ', 'T'));
            if (isNaN(created.getTime())) return;

            const expires = created.getTime() + MS_24H;
            const diff    = expires - now;

            const cell = el.closest('td');
            const btns = cell
                ? cell.querySelectorAll('.btn-edit-trade, .btn-delete-trade')
                : [];

            if (diff <= 0) {
                el.textContent = "You can't edit this data anymore.";
                btns.forEach(btn => {
                    btn.disabled = true;
                    btn.classList.add('btn-soft-disabled');
                    btn.classList.remove('btn-live-timer');
                });
                return;
            }

            const totalSeconds = Math.floor(diff / 1000);
            const hours   = Math.floor(totalSeconds / 3600);
            const minutes = Math.floor((totalSeconds % 3600) / 60);
            const seconds = totalSeconds % 60;

            el.textContent =
                'You can edit this data within ' +
                String(hours).padStart(2,'0')   + ':' +
                String(minutes).padStart(2,'0') + ':' +
                String(seconds).padStart(2,'0');

            btns.forEach(btn => {
                btn.disabled = false;
                btn.classList.remove('btn-soft-disabled');
                if (btn.classList.contains('btn-edit-trade')) {
                    btn.classList.add('btn-live-timer');
                }
            });
        });
    }

    // ---------- Add Trade modal ----------
    const addModal    = document.getElementById('addTradeModal');
    const openAddBtn  = document.getElementById('openAddTrade');
    const closeAddBtn = document.getElementById('closeAddTrade');

    if (openAddBtn && addModal) {
        openAddBtn.addEventListener('click', () => addModal.classList.add('open'));
    }
    if (closeAddBtn && addModal) {
        closeAddBtn.addEventListener('click', () => addModal.classList.remove('open'));
    }

    // ---------- Auto day + P&L calc ----------
    const dateInput   = document.getElementById('trade_date');
    const dayInput    = document.getElementById('day');
    const openInput   = document.getElementById('opening_bal');
    const closeInput  = document.getElementById('closing_bal');
    const profitInput = document.getElementById('profit');
    const lossInput   = document.getElementById('loss');

    if (dateInput && dayInput) {
        dateInput.addEventListener('change', function () {
            if (!this.value) {
                dayInput.value = '';
                return;
            }
            const d = new Date(this.value);
            if (isNaN(d.getTime())) {
                dayInput.value = '';
                return;
            }
            const days = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
            dayInput.value = days[d.getDay()];
        });
    }

    function recalcPnL() {
        if (!openInput || !closeInput || !profitInput || !lossInput) return;
        const o = parseFloat(openInput.value);
        const c = parseFloat(closeInput.value);
        if (isNaN(o) || isNaN(c)) {
            profitInput.value = '';
            lossInput.value   = '';
            return;
        }
        const diff = c - o;
        if (diff > 0) {
            profitInput.value = diff.toFixed(2);
            lossInput.value   = '0.00';
        } else if (diff < 0) {
            profitInput.value = '0.00';
            lossInput.value   = Math.abs(diff).toFixed(2);
        } else {
            profitInput.value = '0.00';
            lossInput.value   = '0.00';
        }
    }

    if (openInput && closeInput) {
        openInput.addEventListener('input', recalcPnL);
        closeInput.addEventListener('input', recalcPnL);
    }

    // ---------- View Trade modal ----------
    const viewModal = document.getElementById('viewTradeModal');
    const closeView = document.getElementById('closeViewTrade');

    if (closeView && viewModal) {
        closeView.addEventListener('click', () => viewModal.classList.remove('open'));
    }

    document.querySelectorAll('.btn-view-trade').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const tr   = this.closest('tr');
            const json = tr ? tr.getAttribute('data-trade') : null;
            if (!json) return;

            let row;
            try { row = JSON.parse(json); } catch (e) { return; }

            document.getElementById('v_trade_no').textContent      = row.trade_no || '';
            document.getElementById('v_trade_date').textContent    = formatDateDisplay(row.trade_date || '');
            document.getElementById('v_day').textContent           = row.day || '';
            document.getElementById('v_no_trades').textContent     = row.no_trades || '';
            document.getElementById('v_opening_bal').textContent   = row.opening_bal || '';
            document.getElementById('v_closing_bal').textContent   = row.closing_bal || '';
            document.getElementById('v_profit').textContent        = row.profit || '';
            document.getElementById('v_loss').textContent          = row.loss || '';
            document.getElementById('v_setup_type').textContent    = row.setup_type || '';
            document.getElementById('v_entry_reason').textContent  = row.entry_reason || '';
            document.getElementById('v_rule_followed').textContent = row.rule_followed || '';
            document.getElementById('v_emotion').textContent       = row.emotion || '';
            document.getElementById('v_strategy_tags').textContent = row.strategy_tags || '';
            document.getElementById('v_mistake_tags').textContent  = row.mistake_tags || '';
            document.getElementById('v_notes').textContent         = row.notes || '';

            const shotEl = document.getElementById('v_screenshot');
            if (shotEl) {
                if (row.screenshot_path) {
                    shotEl.innerHTML = '<a href="' + row.screenshot_path + '" target="_blank">View Screenshot</a>';
                } else {
                    shotEl.textContent = '—';
                }
            }

            viewModal.classList.add('open');
        });
    });

    // ---------- Edit Trade modal (iframe) ----------
    const editModal = document.getElementById('editTradeModal');
    const editFrame = document.getElementById('editTradeFrame');
    const closeEdit = document.getElementById('closeEditTrade');

    if (closeEdit && editModal && editFrame) {
        closeEdit.addEventListener('click', function () {
            editModal.classList.remove('open');
            editFrame.src = '';
        });
    }

    document.querySelectorAll('.btn-edit-trade').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (this.disabled) return;
            const url = this.getAttribute('data-edit-url');
            if (!url) return;
            editFrame.src = url;
            editModal.classList.add('open');
        });
    });

    // ---------- Delete button ----------
    document.querySelectorAll('.btn-delete-trade').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (this.disabled) return;
            const url = this.getAttribute('data-delete-url');
            if (!url) return;
            if (confirm('Delete this entry? This action cannot be undone.')) {
                window.location.href = url;
            }
        });
    });

    // ---------- Start countdown loop ----------
    updateEditDeleteCountdowns();
    setInterval(updateEditDeleteCountdowns, 1000);
});
</script>