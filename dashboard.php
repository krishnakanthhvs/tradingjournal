<?php
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/cache.php';

app_start_protected();
$userId = get_current_user_id();
$nseHolidays = get_nse_holidays();

// Handle monthly capital save from dashboard
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['monthly_capital'])) {
    $now   = new DateTime('now', new DateTimeZone('Asia/Kolkata'));
    $year  = (int)$now->format('Y');
    $month = (int)$now->format('m');

    $raw     = trim($_POST['monthly_capital']);
    $capital = $raw === '' ? 0.0 : (float)$raw;

    if (set_user_monthly_capital($mysqli, $userId, $year, $month, $capital)) {
        header('Location: dashboard.php?toast=' . urlencode('Monthly capital locked.'));
        exit;
    } else {
        header('Location: dashboard.php?toast=' . urlencode('Capital is already locked for this month.'));
        exit;
    }
}

// stats AFTER possible update
$statsCacheKey = 'dashboard_stats_user_' . $userId;

$stats = cache_get($statsCacheKey, 120); // 2 minutes

if (!$stats) {
    $stats = get_dashboard_stats($mysqli, $userId);
    cache_set($statsCacheKey, $stats);
}

// Live market snapshot
$market = cache_get('market_snapshot', 60); // 60 sec cache

if (!$market) {
    $market = get_market_snapshot();
    cache_set('market_snapshot', $market);
}

// For the remaining days note
$nowDT       = new DateTime('now', new DateTimeZone('Asia/Kolkata'));
$endOfMonth  = (clone $nowDT)->modify('last day of this month');
$remainingDays = (int)$nowDT->diff($endOfMonth)->format('%a'); // days until month end

// Optional toast via GET
$toast = $_GET['toast'] ?? '';
if ($toast) {
    echo '<script>document.addEventListener("DOMContentLoaded",function(){document.body.setAttribute("data-toast",' . json_encode($toast) . ');});</script>';
}

$monthlyCapital = (float)$stats['monthly_capital'];
$monthlyPnl     = (float)$stats['monthly_pnl'];

$monthlyPct = $monthlyCapital > 0
    ? ($monthlyPnl / $monthlyCapital) * 100
    : 0;

$progressPct = max(min(abs($monthlyPct), 100), 0);
?>

<div class="content">
    <?php require_once __DIR__ . '/inc/topbar.php'; ?>
    <main class="main">
        <div style="margin-bottom: 2rem">
            <div class="dashboard-hero">
                <!-- Left: Greeting -->
                <div class="dashboard-greeting">
                    <h2 class="card-title">
                        Welcome back, <?php echo htmlspecialchars(get_current_name() ?: get_current_username()); ?> 👋
                    </h2>
                </div>

                <!-- Right: Market Snapshot -->
                <div class="market-summary">
                    <div class="market-summary-header">
                        <span class="text-muted">NSE Snapshot</span>
                        <?php $isOpen = is_market_open_ist(); ?>
                        <span class="<?php echo $isOpen ? 'badge-open' : 'badge-closed'; ?>">
                            <?php echo $isOpen ? 'Market Open' : 'Market Closed'; ?>
                        </span>
                    </div>

                    <div class="market-grid">
                        <?php
                        // $market comes from get_market_snapshot()

                        // 1) Build dynamic order:
                        $preferred = ['nifty', 'sensex', 'banknifty', 'btc', 'eth'];   // always first if available
                        $ordered   = [];

                        // Add preferred in order (only if present)
                        foreach ($preferred as $key) {
                            if (isset($market[$key])) {
                                $ordered[] = $key;
                            }
                        }

                        // Add any other keys from $market that are not already in $ordered
                        foreach ($market as $key => $val) {
                            if (!in_array($key, $ordered, true)) {
                                $ordered[] = $key;
                            }
                        }

                        // 2) Render cards for each key in $ordered
                        foreach ($ordered as $key):
                            $idx = $market[$key] ?? null;
                            if (!$idx) continue;

                            $last   = $idx['last']   ?? null;
                            $change = $idx['change'] ?? null;
                            $pct    = $idx['change_pct'] ?? null;

                            $isUp   = $change !== null && $change > 0;
                            $isDown = $change !== null && $change < 0;

                            $cardClass =
                                $isUp   ? 'market-card-up' :
                                ($isDown ? 'market-card-down' : 'market-card-flat');

                            // optional: support dynamic unit from backend, else fallback
                            $unit = $idx['unit'] ?? (
                                in_array($key, ['usd_inr'], true)
                                    ? ''      // no "pts" for these
                                    : ' pts'  // default for indices & VIX
                            );
                        ?>
                            <div class="market-card <?php echo $cardClass; ?>" data-key="<?php echo $key; ?>">
                                <div class="market-card-title">
                                    <?php echo htmlspecialchars($idx['label']); ?>
                                </div>

                                <div class="market-card-body">
                                    <div class="market-last" data-field="last">
                                        <?php echo $last !== null ? number_format((float)$last, 2) : '—'; ?>
                                    </div>

                                    <?php if ($change !== null): ?>
                                        <?php $sign = $change > 0 ? '+' : ($change < 0 ? '−' : ''); ?>
                                        <div class="market-change" data-field="change">
                                            <?php echo $sign . number_format(abs($change), 2) . $unit; ?>
                                            <?php if ($pct !== null): ?>
                                                <span data-field="pct">
                                                    (<?php echo $sign . number_format(abs($pct), 2); ?>%)
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Daily / Weekly / Monthly P&L cards -->
        <div class="columns-4" style="margin-bottom: 1rem;">
            <div class="card card-accent-green">
                <h3 class="card-title">This Month Capital</h3>
                <p class="text-muted mt-1">Starting capital for the current month</p>

                <?php if (empty($stats['capital_locked'])): ?>
                    <!-- Editable state: show textbox + Save with confirm -->
                    <form id="capitalForm" method="post" style="margin-top:0.5rem; display:flex; gap:0.5rem; align-items:center;">
                        <div style="flex:1;">
                            <input
                                type="number"
                                step="0.01"
                                name="monthly_capital"
                                id="monthly_capital"
                                value="<?php echo htmlspecialchars(number_format($stats['monthly_capital'], 2, '.', '')); ?>"
                                style="width:100%; border-radius:0.5rem; border:1px solid var(--border-subtle); padding:0.4rem 0.6rem; font-size:0.9rem;"
                                required
                            >
                        </div>
                        <button class="btn btn-small" type="submit">Save</button>
                    </form>
                    <div class="mt-1" style="font-size:0.9rem;">
                        <span class="text-muted">Current: </span>
                        <strong>₹<?php echo number_format($stats['monthly_capital'], 2); ?></strong>
                    </div>
                    <p class="capital-note">
                        Once saved, this month's capital will be locked.
                    </p>

                <?php else: ?>
                    <!-- Locked state: hide textbox, show amount + note -->
                    <div class="mt-1" style="font-size:1.2rem;">
                        <strong>₹<?php echo number_format($stats['monthly_capital'], 2); ?></strong>
                    </div>
                    <p class="text-muted mt-1" style="font-size:0.8rem;">
                        This month capital is locked and you can update the capital again in
                        <strong><?php echo $remainingDays; ?></strong> day<?php echo $remainingDays == 1 ? '' : 's'; ?> (next month).
                    </p>
                <?php endif; ?>
            </div>

            <div class="card card-accent-blue">
                <h3 class="card-title">Today P&amp;L</h3>
                <p class="text-muted mt-1">Current trading day (IST)</p>
                <div class="mt-1" style="font-size:1.2rem;">
                    <?php
                    $d = $stats['daily_pnl'];
                    $signClass = $d > 0 ? 'text-profit' : ($d < 0 ? 'text-loss' : 'text-muted');
                    ?>
                    <span class="<?php echo $signClass; ?>">
                        <?php
                        if ($d > 0) {
                            echo '₹' . number_format($d, 2);
                        } elseif ($d < 0) {
                            echo '-₹' . number_format(abs($d), 2);
                        } else {
                            echo '₹0.00';
                        }
                        ?>
                    </span>
                </div>
            </div>

            <div class="card card-accent-purple">
                <h3 class="card-title">Last 7 Days P&amp;L</h3>
                <p class="text-muted mt-1">Rolling 7 calendar days</p>
                <div class="mt-1" style="font-size:1.2rem;">
                    <?php
                    $w = $stats['weekly_pnl'];
                    $signClass = $w > 0 ? 'text-profit' : ($w < 0 ? 'text-loss' : 'text-muted');
                    ?>
                    <span class="<?php echo $signClass; ?>">
                        <?php
                        if ($w > 0) {
                            echo '₹' . number_format($w, 2);
                        } elseif ($w < 0) {
                            echo '-₹' . number_format(abs($w), 2);
                        } else {
                            echo '₹0.00';
                        }
                        ?>
                    </span>
                </div>
            </div>

            <div class="card card-accent-amber">
                <h3 class="card-title">This Month P&amp;L</h3>
                <p class="text-muted mt-1">Calendar month</p>
                <div class="mt-1" style="font-size:1.2rem;">
                    <?php
                    $m = $stats['monthly_pnl'];
                    $signClass = $m > 0 ? 'text-profit' : ($m < 0 ? 'text-loss' : 'text-muted');
                    ?>
                    <span class="<?php echo $signClass; ?>">
                        <?php
                        if ($m > 0) {
                            echo '₹' . number_format($m, 2);
                        } elseif ($m < 0) {
                            echo '-₹' . number_format(abs($m), 2);
                        } else {
                            echo '₹0.00';
                        }
                        ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- Performance summary + Equity curve -->
        <div class="columns-2">
            <div class="card">
                <h3 class="card-title">P&amp;L by Weekday</h3>
                <p class="text-muted mt-1">Net P&amp;L aggregated by day of week</p>
                <canvas id="weekdayChart" style="max-height:260px;"></canvas>
            </div>

            <div class="card">
                <h3 class="card-title">Daily P&amp;L</h3>
                <canvas id="equityChart" style="max-height:260px;"></canvas>
            </div>
        </div>

        <!-- P&L by weekday + Top 5 best/worst days -->
        <div class="columns-2" style="margin-top:1rem;">

            <!-- ================= PERFORMANCE SUMMARY CARD ================= -->
            <div class="card">

                <h3 class="card-title">Performance Summary</h3>

                <!-- SUMMARY METRICS -->
                <div class="form-grid" style="margin-bottom:1.25rem;">
                    <div>
                        <div class="text-muted">Total Profit</div>
                        <div class="text-profit">₹<?php echo number_format($stats['total_profit'], 2); ?></div>
                    </div>

                    <div>
                        <div class="text-muted">Total Loss</div>
                        <div class="text-loss">-₹<?php echo number_format($stats['total_loss'], 2); ?></div>
                    </div>

                    <div>
                        <div class="text-muted">Net P&amp;L</div>
                        <div>
                            <?php
                            echo $stats['net'] > 0
                                ? '<span class="text-profit">₹' . number_format($stats['net'], 2) . '</span>'
                                : ($stats['net'] < 0
                                    ? '<span class="text-loss">-₹' . number_format(abs($stats['net']), 2) . '</span>'
                                    : '₹0.00');
                            ?>
                        </div>
                    </div>

                    <div>
                        <div class="text-muted">Trades</div>
                        <div><?php echo (int)$stats['trade_count']; ?></div>
                    </div>

                    <div>
                        <div class="text-muted">Win Rate</div>
                        <div>
                            <?php echo number_format(
                                $stats['trade_count'] ? ($stats['wins'] / $stats['trade_count']) * 100 : 0,
                                1
                            ); ?>%
                        </div>
                    </div>

                    <div>
                        <div class="text-muted">Avg P&amp;L / Trade</div>
                        <div>₹<?php echo number_format($stats['avg_profit_per_trade'], 2); ?></div>
                    </div>

                    <div>
                        <div class="text-muted">Best Setup</div>
                        <div><?php echo $stats['best_setup'] ?: 'N/A'; ?></div>
                    </div>

                    <div>
                        <div class="text-muted">Mistake Trades</div>
                        <div><?php echo (int)$stats['mistake_trade_count']; ?></div>
                    </div>
                </div>

                <hr class="divider">
                <!-- MONTHLY PERFORMANCE -->
                <h4 class="section-title" style="margin-top:0.5rem;">Monthly Performance</h4>

                <div style="margin-top:0.5rem;">
                    <div style="display:flex;justify-content:space-between;font-size:0.9rem;">
                        <span class="text-muted">P&amp;L %</span>
                        <span class="<?php echo $monthlyPct >= 0 ? 'text-profit' : 'text-loss'; ?>">
                            <?php echo number_format($monthlyPct, 2); ?>%
                        </span>
                    </div>

                    <div class="progress-bar">
                        <div class="progress-fill <?php echo $monthlyPct >= 0 ? 'bg-profit' : 'bg-loss'; ?>"
                            style="width:<?php echo $progressPct; ?>%"></div>
                    </div>

                    <div style="display:flex;justify-content:space-between;font-size:0.85rem; margin-bottom: 1rem">
                        <span class="text-muted">Used: ₹<?php echo number_format(abs($monthlyPnl), 2); ?></span>
                        <span class="text-muted">
                            Remaining: ₹<?php echo number_format(
                                max($monthlyCapital - abs($monthlyPnl), 0),
                                2
                            ); ?>
                        </span>
                    </div>
                </div>                            

                <hr class="divider">

                <!-- BEST / WORST DAYS -->
                <div class="columns-2" style="gap:1rem;">
                    <div>
                        <h4 class="section-title" style="margin-top: 1rem">Top 5 Best Days</h4>
                        <table class="table-compact">
                            <thead><tr><th>Date</th><th>Net</th></tr></thead>
                            <tbody>
                            <?php if (empty($stats['best_days'])): ?>
                                <tr><td colspan="2" class="text-muted">No data</td></tr>
                            <?php else: foreach ($stats['best_days'] as $d): ?>
                                <tr>
                                    <td><?php echo format_trade_date($d['date']); ?></td>
                                    <td class="text-profit">₹<?php echo number_format($d['net'], 2); ?></td>
                                </tr>
                            <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div>
                        <h4 class="section-title" style="margin-top: 1rem">Top 5 Worst Days</h4>
                        <table class="table-compact">
                            <thead><tr><th>Date</th><th>Net</th></tr></thead>
                            <tbody>
                            <?php if (empty($stats['worst_days'])): ?>
                                <tr><td colspan="2" class="text-muted">No data</td></tr>
                            <?php else: foreach ($stats['worst_days'] as $d): ?>
                                <tr>
                                    <td><?php echo format_trade_date($d['date']); ?></td>
                                    <td class="text-loss">-₹<?php echo number_format(abs($d['net']), 2); ?></td>
                                </tr>
                            <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>

            <!-- ================= NSE HOLIDAYS CARD ================= -->
            <div class="card">
                <h3 class="card-title">NSE Market Holidays</h3>
                <p class="text-muted mt-1">Equity trading holidays (official NSE)</p>

                <table class="table-compact">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Day</th>
                            <th>Holiday</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($nseHolidays)): ?>
                        <tr><td colspan="3" class="text-muted">Unable to load holidays</td></tr>
                    <?php else: foreach ($nseHolidays as $h): ?>
                        <tr>
                            <td><?php echo format_trade_date($h['date']); ?></td>
                            <td><?php echo htmlspecialchars($h['day']); ?></td>
                            <td><?php echo htmlspecialchars($h['name']); ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>

                <div class="mt-1" style="font-size:0.85rem;">
                    <a href="https://www.nseindia.com/resources/exchange-communication-holidays"
                    target="_blank"
                    class="text-muted">
                        View on NSE website →
                    </a>
                </div>
            </div>

        </div>                        
    </main>
    <?php require_once __DIR__ . '/inc/footer.php'; ?>
</div>

<script>
    function formatDate(d) {
        const date = new Date(d);
        if (isNaN(date)) return d;

        const day   = String(date.getDate()).padStart(2, '0');
        const month = date.toLocaleString('en-US', { month: 'short' });
        const year  = date.getFullYear();

        return `${day}-${month}-${year}`;
    }
    // Equity curve line chart
    // Daily P&L line chart
    const equityCtx = document.getElementById('equityChart');
    if (equityCtx && window.Chart) {
        const points = <?php echo json_encode($stats['daily_points']); ?>;
        const labels = points.map(p => formatDate(p.date));
        const data   = points.map(p => p.pnl);

        new Chart(equityCtx, {
            type: 'line',
            data: {
                labels,
                datasets: [{
                    label: 'Daily P&L',
                    data,
                    tension: 0.3
                }]
            },
            options: {
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    x: { display: true },
                    y: { 
                        display: true,
                        beginAtZero: true
                    }
                }
            }
        });
    }

    // Weekday P&L bar chart
    const weekdayCtx = document.getElementById('weekdayChart');
    if (weekdayCtx && window.Chart) {
        const weekdayData = <?php echo json_encode($stats['weekday_pnl']); ?>;
        const wLabels = weekdayData.map(d => d.label);
        const wValues = weekdayData.map(d => d.net);

        new Chart(weekdayCtx, {
            type: 'bar',
            data: {
                labels: wLabels,
                datasets: [{
                    label: 'Net P&L',
                    data: wValues
                }]
            },
            options: {
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    x: { display: true },
                    y: { display: true }
                }
            }
        });
    }

    document.getElementById('capitalForm').addEventListener('submit', function(e) {
        e.preventDefault();

        const val = document.getElementById('monthly_capital').value;
        if (!val) return;

        if (!confirm(`You are setting this month's trading capital to ₹${val}.\n\nOnce saved, it cannot be changed.\n\nDo you want to lock it?`)) {
            return; // cancelled
        }

        // submit after confirmation
        this.submit();
    });
</script>

<script>
let marketTimer = null;

function refreshMarket() {
    fetch('/api/market_snapshot.php')
        .then(r => r.json())
        .then(res => {
            if (!res.success || !res.market) return;

            Object.keys(res.market).forEach(key => {
                const card = document.querySelector(`.market-card[data-key="${key}"]`);
                if (!card) return;

                const m = res.market[key];

                /* ---------- LAST PRICE ---------- */
                const lastEl = card.querySelector('[data-field="last"]');
                if (lastEl) {
                    lastEl.textContent = formatNumber(m.last, 2);
                }

                /* ---------- CHANGE ---------- */
                const changeEl = card.querySelector('[data-field="change"]');
                if (changeEl && m.change !== null) {
                    const sign = m.change > 0 ? '+' : m.change < 0 ? '−' : '';
                    changeEl.firstChild.textContent =
                        sign + formatNumber(Math.abs(m.change), 2) + (m.unit ?? '');
                }

                /* ---------- PERCENT ---------- */
                const pctEl = card.querySelector('[data-field="pct"]');
                if (pctEl && m.change_pct !== null) {
                    const sign = m.change_pct > 0 ? '+' : m.change_pct < 0 ? '−' : '';
                    pctEl.textContent =
                        `(${sign}${formatNumber(Math.abs(m.change_pct), 2)}%)`;
                }

                /* ---------- CARD COLOR ---------- */
                card.classList.remove(
                    'market-card-up',
                    'market-card-down',
                    'market-card-flat'
                );

                card.classList.add(
                    m.change > 0
                        ? 'market-card-up'
                        : m.change < 0
                        ? 'market-card-down'
                        : 'market-card-flat'
                );
            });

            // Stop polling if market closed
            if (!res.is_open && marketTimer) {
                clearInterval(marketTimer);
                marketTimer = null;
            }
        })
        .catch(() => {});
}

document.addEventListener('DOMContentLoaded', () => {
    refreshMarket();
    marketTimer = setInterval(refreshMarket, 15000); // 15 sec
});
</script>

<script>
    function formatNumber(num, decimals = 2) {
        if (num === null || num === undefined || isNaN(num)) return '—';

        return Number(num).toLocaleString('en-IN', {
            minimumFractionDigits: decimals,
            maximumFractionDigits: decimals
        });
    }
</script>