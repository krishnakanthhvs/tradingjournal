<?php
// inc/functions.php
// Updated 2025-12-09 - supports per-trade rows, per-trade screenshots,
// session-level opening/closing/profit/loss, robust handling of missing inputs.

// -----------------------------------------------------------------------------
// Basic app helpers
// -----------------------------------------------------------------------------
function app_start_protected() {
    // Common includes for authenticated pages
    require_once __DIR__ . '/auth.php';
    require_once __DIR__ . '/db.php';
    require_once __DIR__ . '/header.php';
    require_once __DIR__ . '/sidebar.php';
}

function get_current_user_id() {
    return $_SESSION['user_id'] ?? null;
}

function get_current_username() {
    return $_SESSION['username'] ?? '';
}

function get_current_name() {
    return $_SESSION['name'] ?? '';
}

// -----------------------------------------------------------------------------
// Strategy templates
// -----------------------------------------------------------------------------
function get_user_strategy_templates(mysqli $mysqli, int $userId, bool $onlyEnabled = true): array
{
    $rows = [];
    if ($onlyEnabled) {
        $sql = "SELECT id, name
                FROM strategy_templates
                WHERE user_id = ? AND enabled = 1
                ORDER BY name ASC";
    } else {
        $sql = "SELECT id, name
                FROM strategy_templates
                WHERE user_id = ?
                ORDER BY name ASC";
    }

    if ($stmt = $mysqli->prepare($sql)) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $rows[] = $r;
        }
        $stmt->close();
    }

    return $rows;
}

// -----------------------------------------------------------------------------
// Monthly capital helpers
// -----------------------------------------------------------------------------
function get_user_monthly_capital_info(mysqli $mysqli, int $userId, int $year, int $month): array {
    $sql = "SELECT capital, locked FROM user_monthly_capital WHERE user_id = ? AND year = ? AND month = ? LIMIT 1";
    $capital = 0.0;
    $locked  = 0;

    if ($stmt = $mysqli->prepare($sql)) {
        $stmt->bind_param('iii', $userId, $year, $month);
        $stmt->execute();
        $stmt->bind_result($capitalVal, $lockedVal);
        if ($stmt->fetch()) {
            $capital = (float)$capitalVal;
            $locked  = (int)$lockedVal;
        }
        $stmt->close();
    }

    return [
        'capital' => $capital,
        'locked'  => (bool)$locked,
    ];
}

function set_user_monthly_capital(mysqli $mysqli, int $userId, int $year, int $month, float $capital): bool {
    // If already locked, do NOT allow changes
    $info = get_user_monthly_capital_info($mysqli, $userId, $year, $month);
    if ($info['locked']) {
        return false;
    }

    // Insert or update and lock it
    $sql = "
        INSERT INTO user_monthly_capital (user_id, year, month, capital, locked)
        VALUES (?, ?, ?, ?, 1)
        ON DUPLICATE KEY UPDATE capital = VALUES(capital), locked = 1
    ";
    if ($stmt = $mysqli->prepare($sql)) {
        $stmt->bind_param('iiid', $userId, $year, $month, $capital);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }
    return false;
}

// -----------------------------------------------------------------------------
// Trade insert: session-level fields + per-trade arrays + per-trade screenshot(s)
// This inserts one DB row per trade (useful for per-trade analysis).
// -----------------------------------------------------------------------------
// -----------------------------------------------------------------------------
// Trade insert: session-level fields + per-trade arrays + per-trade screenshot(s)
// -----------------------------------------------------------------------------
function handle_trade_insert(mysqli $mysqli, int $userId): array {
    // Session-level fields
    $trade_no    = trim($_POST['trade_no'] ?? '');
    $trade_date  = trim($_POST['trade_date'] ?? '');
    $day         = trim($_POST['day'] ?? '');
    $no_trades   = (int)($_POST['no_trades'] ?? 1);

    // Session-level opening/closing/profit/loss (single values)
    $opening_bal = isset($_POST['opening_bal']) && $_POST['opening_bal'] !== '' ? (float)$_POST['opening_bal'] : null;
    $closing_bal = isset($_POST['closing_bal']) && $_POST['closing_bal'] !== '' ? (float)$_POST['closing_bal'] : null;
    $profit      = isset($_POST['profit']) && $_POST['profit'] !== '' ? (float)$_POST['profit'] : 0.0;
    $loss        = isset($_POST['loss']) && $_POST['loss'] !== '' ? (float)$_POST['loss'] : 0.0;

    // Per-trade arrays
    $arr_option_strike    = isset($_POST['option_strike']) ? (array)$_POST['option_strike'] : [];
    $arr_option_type      = isset($_POST['option_type']) ? (array)$_POST['option_type'] : [];
    $arr_underlying_close = isset($_POST['underlying_close']) ? (array)$_POST['underlying_close'] : [];
    $arr_setup_type       = isset($_POST['setup_type']) ? (array)$_POST['setup_type'] : [];
    $arr_entry_reason     = isset($_POST['entry_reason']) ? (array)$_POST['entry_reason'] : [];
    $arr_rule_followed    = isset($_POST['rule_followed']) ? (array)$_POST['rule_followed'] : [];
    $arr_emotion          = isset($_POST['emotion']) ? (array)$_POST['emotion'] : [];
    $arr_strategy_tags    = isset($_POST['strategy_tags']) ? (array)$_POST['strategy_tags'] : [];
    $arr_mistake_tags     = isset($_POST['mistake_tags']) ? (array)$_POST['mistake_tags'] : [];
    $arr_notes            = isset($_POST['notes']) ? (array)$_POST['notes'] : [];

    // Normalize arrays to length $no_trades (fill missing with null/empty)
    $normalize = function(array $a, int $n, $default = null) {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            if (array_key_exists($i, $a)) {
                $v = $a[$i];
                if ($v === '') $v = $default;
                $out[$i] = $v;
            } else {
                $out[$i] = $default;
            }
        }
        return $out;
    };

    if ($no_trades < 1) $no_trades = 1;
    $arr_option_strike    = $normalize($arr_option_strike, $no_trades, null);
    $arr_option_type      = $normalize($arr_option_type, $no_trades, null);
    $arr_underlying_close = $normalize($arr_underlying_close, $no_trades, null);
    $arr_setup_type       = $normalize($arr_setup_type, $no_trades, null);
    $arr_entry_reason     = $normalize($arr_entry_reason, $no_trades, null);
    $arr_rule_followed    = $normalize($arr_rule_followed, $no_trades, null);
    $arr_emotion          = $normalize($arr_emotion, $no_trades, null);
    $arr_strategy_tags    = $normalize($arr_strategy_tags, $no_trades, null);
    $arr_mistake_tags     = $normalize($arr_mistake_tags, $no_trades, null);
    $arr_notes            = $normalize($arr_notes, $no_trades, null);

    // Validate minimal required fields
    if ($trade_no === '' || $trade_date === '') {
        return ['success' => false, 'message' => 'Trade # and Date are required.'];
    }

    // Handle file uploads: support per-trade files named screenshot[] OR single screenshot
    $uploaded_paths = array_fill(0, $no_trades, null);

    // Helper to save a single uploaded file entry
    $saveUploadedFile = function($fileName, $fileTmp) {
        $allowed = ['png','jpg','jpeg','gif','webp'];
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) {
            return ['ok' => false, 'msg' => 'Invalid screenshot file type.'];
        }
        $uploadDir = __DIR__ . '/../uploads/trade_screenshots/';
        if (!is_dir($uploadDir)) {
            if (!@mkdir($uploadDir, 0777, true) && !is_dir($uploadDir)) {
                return ['ok' => false, 'msg' => 'Failed to create upload directory.'];
            }
        }
        $safeName = 'shot_' . time() . '_' . mt_rand(1000,9999) . '.' . preg_replace('/[^a-zA-Z0-9]/', '', $ext);
        $target = $uploadDir . $safeName;
        if (!move_uploaded_file($fileTmp, $target)) {
            return ['ok' => false, 'msg' => 'Failed to move uploaded file.'];
        }
        // Return web-path relative to project root
        return ['ok' => true, 'path' => 'uploads/trade_screenshots/' . $safeName];
    };

    // Case A: multiple files via screenshot[] (typical if inputs named screenshot[])
    if (!empty($_FILES['screenshot']['name']) && is_array($_FILES['screenshot']['name'])) {
        for ($i = 0; $i < $no_trades; $i++) {
            if (empty($_FILES['screenshot']['name'][$i])) {
                $uploaded_paths[$i] = null;
                continue;
            }
            $fileName = $_FILES['screenshot']['name'][$i];
            $fileTmp  = $_FILES['screenshot']['tmp_name'][$i];
            $res = $saveUploadedFile($fileName, $fileTmp);
            if (!$res['ok']) {
                foreach ($uploaded_paths as $p) {
                    if ($p && is_file(__DIR__ . '/../' . $p)) @unlink(__DIR__ . '/../' . $p);
                }
                return ['success' => false, 'message' => $res['msg']];
            }
            $uploaded_paths[$i] = $res['path'];
        }
    }
    // Case B: single file input 'screenshot' (not array). We'll reuse same screenshot for all trades.
    elseif (!empty($_FILES['screenshot']['name']) && is_string($_FILES['screenshot']['name'])) {
        $res = $saveUploadedFile($_FILES['screenshot']['name'], $_FILES['screenshot']['tmp_name']);
        if (!$res['ok']) {
            return ['success' => false, 'message' => $res['msg']];
        }
        for ($i = 0; $i < $no_trades; $i++) {
            $uploaded_paths[$i] = $res['path'];
        }
    }
    // else: no screenshots uploaded

    // Insert rows inside transaction
    $mysqli->begin_transaction();
    try {
        $sql = 'INSERT INTO trades
            (user_id, trade_no, trade_date, day, no_trades,
             option_strike, option_type, underlying_close,
             opening_bal, closing_bal, profit, loss,
             setup_type, entry_reason, rule_followed, emotion,
             strategy_tags, mistake_tags, notes, screenshot_path)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';

        $stmt = $mysqli->prepare($sql);
        if (!$stmt) {
            $mysqli->rollback();
            return ['success' => false, 'message' => 'Prepare failed: ' . $mysqli->error];
        }

        // FIX: types string must match the exact number of bound variables (20)
        // Types:
        // i - user_id
        // s - trade_no
        // s - trade_date
        // s - day
        // i - no_trades
        // i - option_strike
        // s - option_type
        // d - underlying_close
        // d - opening_bal
        // d - closing_bal
        // d - profit
        // d - loss
        // s - setup_type
        // s - entry_reason
        // s - rule_followed
        // s - emotion
        // s - strategy_tags
        // s - mistake_tags
        // s - notes
        // s - screenshot_path
        $types = 'isssiisdddddssssssss'; // exactly 20 characters matching 20 params

        for ($i = 0; $i < $no_trades; $i++) {
            $optStrike = $arr_option_strike[$i] !== null && $arr_option_strike[$i] !== '' ? (int)$arr_option_strike[$i] : null;
            $optType   = $arr_option_type[$i] !== null ? trim($arr_option_type[$i]) : null;
            $uCloseRaw = $arr_underlying_close[$i];
            $uClose    = ($uCloseRaw !== null && $uCloseRaw !== '') ? (float)$uCloseRaw : null;

            // Session-level numbers (opening/closing/profit/loss)
            $oBal = $opening_bal !== null ? $opening_bal : null;
            $cBal = $closing_bal !== null ? $closing_bal : null;
            $prof = $profit !== null ? $profit : 0.0;
            $los  = $loss !== null ? $loss : 0.0;

            $s_type = $arr_setup_type[$i] !== null ? trim($arr_setup_type[$i]) : null;
            $e_reason = $arr_entry_reason[$i] !== null ? trim($arr_entry_reason[$i]) : null;
            $r_follow = $arr_rule_followed[$i] !== null ? trim($arr_rule_followed[$i]) : null;
            $emotion  = $arr_emotion[$i] !== null ? trim($arr_emotion[$i]) : null;
            $s_tags   = $arr_strategy_tags[$i] !== null ? trim($arr_strategy_tags[$i]) : null;
            $m_tags   = $arr_mistake_tags[$i] !== null ? trim($arr_mistake_tags[$i]) : null;
            $notes    = $arr_notes[$i] !== null ? trim($arr_notes[$i]) : null;
            $screenshot_path = $uploaded_paths[$i] ?? null;

            // Bind parameters by reference
            if (!$stmt->bind_param(
                $types,
                $userId,
                $trade_no,
                $trade_date,
                $day,
                $no_trades,
                $optStrike,
                $optType,
                $uClose,
                $oBal,
                $cBal,
                $prof,
                $los,
                $s_type,
                $e_reason,
                $r_follow,
                $emotion,
                $s_tags,
                $m_tags,
                $notes,
                $screenshot_path
            )) {
                $stmt->close();
                $mysqli->rollback();
                foreach ($uploaded_paths as $p) { if ($p && is_file(__DIR__ . '/../' . $p)) @unlink(__DIR__ . '/../' . $p); }
                return ['success' => false, 'message' => 'bind_param failed: ' . $mysqli->error];
            }

            if (!$stmt->execute()) {
                $stmt->close();
                $mysqli->rollback();
                foreach ($uploaded_paths as $p) { if ($p && is_file(__DIR__ . '/../' . $p)) @unlink(__DIR__ . '/../' . $p); }
                return ['success' => false, 'message' => 'Error saving trade row: ' . $stmt->error];
            }
        }

        $stmt->close();
        $mysqli->commit();
        return ['success' => true, 'message' => 'Trade entry(s) saved.'];
    } catch (Throwable $e) {
        $mysqli->rollback();
        foreach ($uploaded_paths as $p) { if ($p && is_file(__DIR__ . '/../' . $p)) @unlink(__DIR__ . '/../' . $p); }
        error_log('handle_trade_insert error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Exception saving trades: ' . $e->getMessage()];
    }
}

// -----------------------------------------------------------------------------
// Fetch all trades for a user (most recent first).
// Using SELECT * so missing optional columns won't cause a prepare-time error.
// -----------------------------------------------------------------------------
function get_trades_for_user(mysqli $mysqli, int $userId) {
    // Select all columns (safer if schema changes). Returns mysqli_result.
    $stmt = $mysqli->prepare('SELECT * FROM trades WHERE user_id = ? ORDER BY trade_date DESC, id DESC');
    if (!$stmt) {
        throw new Exception('Prepare failed get_trades_for_user: ' . $mysqli->error);
    }
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    return $stmt->get_result();
}

// -----------------------------------------------------------------------------
// Check if a trade is editable (within 24 hours of creation)
// -----------------------------------------------------------------------------
function is_trade_editable(array $tradeRow): bool {
    if (empty($tradeRow['created_at'])) {
        return false;
    }
    $created = strtotime($tradeRow['created_at']);
    if ($created === false) {
        return false;
    }
    return (time() - $created) <= 24 * 60 * 60;
}

// -----------------------------------------------------------------------------
// Dashboard stats (unchanged except safe queries)
// -----------------------------------------------------------------------------
function get_dashboard_stats($mysqli, $userId)
{
    $stats = [
        'total_profit'          => 0.0,
        'total_loss'            => 0.0,
        'net'                   => 0.0,
        'trade_count'           => 0,
        'wins'                  => 0,
        'avg_profit_per_trade'  => 0.0,
        'best_setup'            => null,
        'mistake_trade_count'   => 0,
        'equity_points'         => [],
        'daily_pnl'             => 0.0,
        'weekly_pnl'            => 0.0,
        'monthly_pnl'           => 0.0,
        'weekday_pnl'           => [],
        'best_days'             => [],
        'worst_days'            => [],
        'monthly_capital'       => 0.0,
        'capital_locked'        => false,
        'daily_points'          => [],
    ];

    // ---------- Base aggregates ----------
    $sql = "
        SELECT
            COALESCE(SUM(profit), 0)                       AS total_profit,
            COALESCE(SUM(loss), 0)                         AS total_loss,
            COUNT(*)                                       AS trade_count,
            SUM(CASE WHEN (profit - loss) > 0 THEN 1 ELSE 0 END) AS wins,
            SUM(CASE WHEN mistake_tags IS NOT NULL AND mistake_tags <> '' THEN 1 ELSE 0 END) AS mistake_trades
        FROM trades
        WHERE user_id = ?
    ";
    if ($stmt = $mysqli->prepare($sql)) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->bind_result($totalProfit, $totalLoss, $tradeCount, $wins, $mistakeTrades);
        if ($stmt->fetch()) {
            $stats['total_profit']        = (float)$totalProfit;
            $stats['total_loss']          = (float)$totalLoss;
            $stats['net']                 = (float)$totalProfit - (float)$totalLoss;
            $stats['trade_count']         = (int)$tradeCount;
            $stats['wins']                = (int)$wins;
            $stats['mistake_trade_count'] = (int)$mistakeTrades;
            $stats['avg_profit_per_trade'] = $tradeCount > 0
                ? $stats['net'] / $tradeCount
                : 0.0;
        }
        $stmt->close();
    }

    // ---------- Best setup type by net P&L ----------
    $sql = "
        SELECT setup_type, SUM(profit - loss) AS net_pl
        FROM trades
        WHERE user_id = ? AND setup_type IS NOT NULL AND setup_type <> ''
        GROUP BY setup_type
        ORDER BY net_pl DESC
        LIMIT 1
    ";
    if ($stmt = $mysqli->prepare($sql)) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->bind_result($setupType, $netPl);
        if ($stmt->fetch()) {
            $stats['best_setup'] = $setupType;
        }
        $stmt->close();
    }

    // ---------- Equity curve (cumulative P&L over time) ----------
    $sql = "
        SELECT trade_date, COALESCE(SUM(profit - loss), 0) AS daily_pnl
        FROM trades
        WHERE user_id = ?
        GROUP BY trade_date
        ORDER BY trade_date ASC
    ";
    $equityPoints = [];
    if ($stmt = $mysqli->prepare($sql)) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->bind_result($tradeDate, $dailyPnl);
        $running = 0.0;
        while ($stmt->fetch()) {
            $running += (float)$dailyPnl;
            $equityPoints[] = [
                'date'   => $tradeDate,
                'equity' => round($running, 2),
            ];
        }
        $stmt->close();
    }
    $stats['equity_points'] = $equityPoints;

    // ---------- Daily / Weekly / Monthly P&L ----------
    $now    = new DateTime('now', new DateTimeZone('Asia/Kolkata'));
    $today  = $now->format('Y-m-d');
    $weekStart = (clone $now)->modify('-6 days')->format('Y-m-d'); // last 7 days
    $year   = (int)$now->format('Y');
    $month  = (int)$now->format('m');

    // Capital info (amount + locked)
    $capInfo = get_user_monthly_capital_info($mysqli, $userId, $year, $month);
    $stats['monthly_capital'] = $capInfo['capital'];
    $stats['capital_locked']  = $capInfo['locked'];

    // Daily points (for daily P&L line chart)
    $q = $mysqli->prepare("
        SELECT trade_date AS date,
               SUM(profit - loss) AS pnl
        FROM trades
        WHERE user_id = ?
        GROUP BY trade_date
        ORDER BY trade_date ASC
    ");
    $q->bind_param("i", $userId);
    $q->execute();
    $res = $q->get_result();

    $dailyPoints = [];
    while ($r = $res->fetch_assoc()) {
        $dailyPoints[] = [
            'date' => $r['date'],
            'pnl'  => (float)$r['pnl'],
        ];
    }
    $q->close();
    $stats['daily_points'] = $dailyPoints;

    // Daily P&L
    $sql = "
        SELECT COALESCE(SUM(profit - loss), 0) AS pnl
        FROM trades
        WHERE user_id = ? AND trade_date = ?
    ";
    if ($stmt = $mysqli->prepare($sql)) {
        $stmt->bind_param('is', $userId, $today);
        $stmt->execute();
        $stmt->bind_result($pnl);
        if ($stmt->fetch()) {
            $stats['daily_pnl'] = (float)$pnl;
        }
        $stmt->close();
    }

    // Weekly (last 7 calendar days including today)
    $sql = "
        SELECT COALESCE(SUM(profit - loss), 0) AS pnl
        FROM trades
        WHERE user_id = ? AND trade_date BETWEEN ? AND ?
    ";
    if ($stmt = $mysqli->prepare($sql)) {
        $stmt->bind_param('iss', $userId, $weekStart, $today);
        $stmt->execute();
        $stmt->bind_result($pnl);
        if ($stmt->fetch()) {
            $stats['weekly_pnl'] = (float)$pnl;
        }
        $stmt->close();
    }

    // Monthly (current calendar month)
    $sql = "
        SELECT COALESCE(SUM(profit - loss), 0) AS pnl
        FROM trades
        WHERE user_id = ?
          AND YEAR(trade_date) = ?
          AND MONTH(trade_date) = ?
    ";
    if ($stmt = $mysqli->prepare($sql)) {
        $stmt->bind_param('iii', $userId, $year, $month);
        $stmt->execute();
        $stmt->bind_result($pnl);
        if ($stmt->fetch()) {
            $stats['monthly_pnl'] = (float)$pnl;
        }
        $stmt->close();
    }

    // ---------- P&L by weekday ----------
    $sql = "
        SELECT DAYOFWEEK(trade_date) AS dow, COALESCE(SUM(profit - loss), 0) AS pnl
        FROM trades
        WHERE user_id = ?
        GROUP BY dow
    ";
    $weekdayMap = [
        1 => 'Sun',
        2 => 'Mon',
        3 => 'Tue',
        4 => 'Wed',
        5 => 'Thu',
        6 => 'Fri',
        7 => 'Sat',
    ];
    $weekdayPnl = array_fill_keys(array_values($weekdayMap), 0.0);

    if ($stmt = $mysqli->prepare($sql)) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->bind_result($dow, $pnl);
        while ($stmt->fetch()) {
            $dow = (int)$dow;
            if (isset($weekdayMap[$dow])) {
                $label = $weekdayMap[$dow];
                $weekdayPnl[$label] = (float)$pnl;
            }
        }
        $stmt->close();
    }

    $stats['weekday_pnl'] = [];
    foreach (['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $lbl) {
        $stats['weekday_pnl'][] = [
            'label' => $lbl,
            'net'   => $weekdayPnl[$lbl] ?? 0.0,
        ];
    }

    // ---------- Top 5 best / worst days ----------
    // Best
    $sql = "
        SELECT trade_date, COALESCE(SUM(profit - loss), 0) AS pnl
        FROM trades
        WHERE user_id = ?
        GROUP BY trade_date
        HAVING pnl <> 0
        ORDER BY pnl DESC
        LIMIT 5
    ";
    if ($stmt = $mysqli->prepare($sql)) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->bind_result($tradeDate, $pnl);
        $best = [];
        while ($stmt->fetch()) {
            $best[] = [
                'date' => $tradeDate,
                'net'  => (float)$pnl,
            ];
        }
        $stmt->close();
        $stats['best_days'] = $best;
    }

    // Worst
    $sql = "
        SELECT trade_date, COALESCE(SUM(profit - loss), 0) AS pnl
        FROM trades
        WHERE user_id = ?
        GROUP BY trade_date
        HAVING pnl <> 0
        ORDER BY pnl ASC
        LIMIT 5
    ";
    if ($stmt = $mysqli->prepare($sql)) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->bind_result($tradeDate, $pnl);
        $worst = [];
        while ($stmt->fetch()) {
            $worst[] = [
                'date' => $tradeDate,
                'net'  => (float)$pnl,
            ];
        }
        $stmt->close();
        $stats['worst_days'] = $worst;
    }

    return $stats;
}

// -----------------------------------------------------------------------------
// Capital history (unchanged)
// -----------------------------------------------------------------------------
function get_capital_history(mysqli $mysqli, int $userId): array
{
    $sql = "
        SELECT
            umc.year,
            umc.month,
            umc.capital,
            COALESCE(SUM(t.profit), 0) AS month_profit,
            COALESCE(SUM(t.loss), 0)   AS month_loss
        FROM user_monthly_capital umc
        LEFT JOIN trades t
            ON t.user_id = umc.user_id
           AND YEAR(t.trade_date) = umc.year
           AND MONTH(t.trade_date) = umc.month
        WHERE umc.user_id = ?
        GROUP BY umc.year, umc.month, umc.capital
        ORDER BY umc.year DESC, umc.month DESC
    ";

    $rows = [];
    if ($stmt = $mysqli->prepare($sql)) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $capital      = (float)$r['capital'];
            $profit       = (float)$r['month_profit'];
            $loss         = (float)$r['month_loss'];
            $remaining    = $capital + $profit - $loss;

            $rows[] = [
                'year'              => (int)$r['year'],
                'month'             => (int)$r['month'],
                'capital'           => $capital,
                'month_profit'      => $profit,
                'month_loss'        => $loss,
                'capital_remaining' => $remaining,
            ];
        }
        $stmt->close();
    }

    return $rows;
}

// -----------------------------------------------------------------------------
// Small helpers
// -----------------------------------------------------------------------------
function format_trade_date($date) {
    $ts = strtotime($date);
    if (!$ts) return $date;
    return date('d-M-Y', $ts);
}

/**
 * Rough check if Indian cash market (NSE/BSE) is open.
 * Mon–Fri, 09:15–15:30 IST
 */
function is_market_open_ist(): bool
{
    $now = new DateTime('now', new DateTimeZone('Asia/Kolkata'));
    $day = (int)$now->format('N'); // 1=Mon ... 7=Sun

    if ($day > 5) {
        // Sat/Sun
        return false;
    }

    $timeInt = (int)$now->format('Hi'); // e.g. "0930" -> 930
    return ($timeInt >= 915 && $timeInt <= 1530);
}

/**
 * Low-level helper: fetch index/FX/VIX quote from Yahoo Finance chart API.
 * Symbol examples: ^NSEI, ^BSESN, ^NSEBANK, ^INDIAVIX, USDINR=X
 */
function fetch_yahoo_index_quote(string $symbol): ?array
{
    $url = 'https://query1.finance.yahoo.com/v8/finance/chart/' . urlencode($symbol) . '?range=1d&interval=1m';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; TradingJournalBot/1.0)',
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json,text/plain,*/*',
        ],
    ]);

    $raw = curl_exec($ch);
    if ($raw === false) {
        curl_close($ch);
        return null;
    }
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        return null;
    }

    $data = json_decode($raw, true);
    if (!isset($data['chart']['result'][0]['meta'])) {
        return null;
    }

    $meta = $data['chart']['result'][0]['meta'];

    $last      = $meta['regularMarketPrice']   ?? null;
    $prevClose = $meta['chartPreviousClose']   ?? ($meta['previousClose'] ?? null);

    if ($last === null || $prevClose === null) {
        return null;
    }

    $last      = (float)$last;
    $prevClose = (float)$prevClose;
    $change    = $last - $prevClose;
    $changePct = $prevClose != 0.0 ? ($change / $prevClose) * 100.0 : 0.0;

    return [
        'last'       => $last,
        'prev_close' => $prevClose,
        'change'     => $change,
        'change_pct' => $changePct,
    ];
}

/**
 * Fetch Nifty PCR from NSE option chain API.
 *
 * Returns:
 *  [
 *      'last'       => float,  // PCR today
 *      'change'     => null,   // (can be extended later)
 *      'change_pct' => null,
 *  ]
 *
 * On failure, returns null (or last cached value if available).
 */
function fetch_nifty_pcr_quote(): ?array
{
    // --- Simple 5-minute file cache to avoid hammering NSE ---
    $cacheDir  = __DIR__ . '/cache';
    $cacheFile = $cacheDir . '/nifty_pcr_cache.json';
    $cacheTtl  = 300; // 5 minutes

    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0777, true);
    }

    // Use cache if still fresh
    if (is_file($cacheFile) && (time() - filemtime($cacheFile) < $cacheTtl)) {
        $cached = json_decode(@file_get_contents($cacheFile), true);
        if (is_array($cached) && isset($cached['last'])) {
            return $cached;
        }
    }

    $url = 'https://www.nseindia.com/api/option-chain-indices?symbol=NIFTY';

    $headers = [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
        'Accept: application/json,text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language: en-US,en;q=0.9',
        'Referer: https://www.nseindia.com/option-chain',
        'Connection: keep-alive',
    ];

    $context = stream_context_create([
        'http' => [
            'method'  => 'GET',
            'header'  => implode("\r\n", $headers) . "\r\n",
            'timeout' => 5,
        ]
    ]);

    $json = @file_get_contents($url, false, $context);
    if ($json === false) {
        // fall back to cache if available
        if (is_file($cacheFile)) {
            $cached = json_decode(@file_get_contents($cacheFile), true);
            if (is_array($cached) && isset($cached['last'])) {
                return $cached;
            }
        }
        return null;
    }

    $data = json_decode($json, true);
    if (!is_array($data) || !isset($data['records']['data'])) {
        return null;
    }

    $totalCallOI = 0;
    $totalPutOI  = 0;

    foreach ($data['records']['data'] as $row) {
        if (isset($row['CE']['openInterest'])) {
            $totalCallOI += (int)$row['CE']['openInterest'];
        }
        if (isset($row['PE']['openInterest'])) {
            $totalPutOI += (int)$row['PE']['openInterest'];
        }
    }

    if ($totalCallOI <= 0) {
        return null;
    }

    $pcr = $totalPutOI / $totalCallOI;
    $pcr = round($pcr, 2);

    $result = [
        'last'       => $pcr,
        'change'     => null,
        'change_pct' => null,
    ];

    // Save to cache
    @file_put_contents($cacheFile, json_encode($result));

    return $result;
}

/**
 * Return live market snapshot for dashboard.
 *
 * Keys in the returned array (nifty, sensex, banknifty, indiavix, usd_inr, pcr, ...)
 * are driven by the config below. To add/remove items, just edit $itemsConfig.
 *
 * Each item:
 * [
 *   'label'      => 'Nifty 50',
 *   'last'       => float|null,
 *   'change'     => float|null,
 *   'change_pct' => float|null,
 *   'unit'       => ' pts' | '' etc.
 * ]
 */
function get_market_snapshot(): array
{
    $itemsConfig = [
        'nifty' => [
            'label'  => 'Nifty 50',
            'source' => 'yahoo',
            'symbol' => '^NSEI',
            'unit'   => ' pts',
        ],
        'sensex' => [
            'label'  => 'Sensex',
            'source' => 'yahoo',
            'symbol' => '^BSESN',
            'unit'   => ' pts',
        ],
        'banknifty' => [
            'label'  => 'Bank Nifty',
            'source' => 'yahoo',
            'symbol' => '^NSEBANK',
            'unit'   => ' pts',
        ],

        // India VIX via Yahoo
        'indiavix' => [
            'label'  => 'India VIX',
            'source' => 'yahoo',
            'symbol' => '^INDIAVIX',
            'unit'   => ' pts',
        ],

        // USD / INR via Yahoo FX
        'usd_inr' => [
            'label'  => 'USD / INR',
            'source' => 'yahoo',
            'symbol' => 'USDINR=X',
            'unit'   => '',
        ],

        // Nifty PCR via NSE option chain
        'pcr' => [
            'label'  => 'Nifty PCR',
            'source' => 'nse_pcr',
            'symbol' => null,
            'unit'   => '',
        ],
    ];

    $out = [
        'open' => is_market_open_ist(),
    ];

    foreach ($itemsConfig as $key => $cfg) {
        $label = $cfg['label'] ?? strtoupper($key);
        $unit  = $cfg['unit']  ?? ' pts';

        $quote = null;

        switch ($cfg['source']) {
            case 'yahoo':
                $quote = fetch_yahoo_index_quote($cfg['symbol']);
                break;

            case 'nse_pcr':
                $quote = fetch_nifty_pcr_quote();
                break;

            default:
                $quote = null;
        }

        if ($quote === null) {
            $out[$key] = [
                'label'      => $label,
                'last'       => null,
                'change'     => null,
                'change_pct' => null,
                'unit'       => $unit,
            ];
        } else {
            $out[$key] = [
                'label'      => $label,
                'last'       => $quote['last']       ?? null,
                'change'     => $quote['change']     ?? null,
                'change_pct' => $quote['change_pct'] ?? null,
                'unit'       => $unit,
            ];
        }
    }

    return $out;
}

/**
 * Fetch list of NSE stocks (symbol + name).
 * Returns array: [ ['symbol' => 'RELIANCE', 'name' => 'Reliance Industries Ltd'], ... ]
 * Uses simple 1-day file cache.
 */
function fetch_nse_stock_list(): array
{
    $cacheDir  = __DIR__ . '/cache';
    $cacheFile = $cacheDir . '/nse_stocks.json';
    $cacheTtl  = 86400; // 1 day

    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0777, true);
    }

    if (is_file($cacheFile) && (time() - filemtime($cacheFile) < $cacheTtl)) {
        $data = json_decode(@file_get_contents($cacheFile), true);
        if (is_array($data)) {
            return $data;
        }
    }

    // 🔁 You may need to adjust this endpoint depending on NSE changes
    $url = 'https://www.nseindia.com/api/equity-master';

    $headers = [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
        'Accept: application/json,text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language: en-US,en;q=0.9',
        'Referer: https://www.nseindia.com/',
        'Connection: keep-alive',
    ];

    $context = stream_context_create([
        'http' => [
            'method'  => 'GET',
            'header'  => implode("\r\n", $headers) . "\r\n",
            'timeout' => 8,
        ]
    ]);

    $raw = @file_get_contents($url, false, $context);
    if ($raw === false) {
        // fallback: empty list
        return [];
    }

    $json = json_decode($raw, true);

    $out = [];

    // ❗️Adjust parsing depending on actual structure
    if (is_array($json)) {
        foreach ($json as $row) {
            if (!isset($row['symbol'])) continue;
            $symbol = trim($row['symbol']);
            $name   = trim($row['name'] ?? $row['companyName'] ?? $symbol);
            if ($symbol === '') continue;

            $out[] = [
                'symbol' => $symbol,
                'name'   => $name,
            ];
        }
    }

    @file_put_contents($cacheFile, json_encode($out));

    return $out;
}

/**
 * Fetch latest LTP for an NSE equity symbol.
 * Returns float or null on failure.
 */
function fetch_nse_equity_ltp(string $symbol): ?float
{
    $symbol = strtoupper(trim($symbol));
    if ($symbol === '') {
        return null;
    }

    $cacheDir  = __DIR__ . '/cache';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0777, true);
    }
    $cacheFile = $cacheDir . '/ltp_' . preg_replace('/[^A-Z0-9_]/', '_', $symbol) . '.json';
    $cacheTtl  = 60; // 1 minute

    if (is_file($cacheFile) && (time() - filemtime($cacheFile) < $cacheTtl)) {
        $cached = json_decode(@file_get_contents($cacheFile), true);
        if (is_array($cached) && isset($cached['ltp'])) {
            return (float)$cached['ltp'];
        }
    }

    $url = 'https://www.nseindia.com/api/quote-equity?symbol=' . urlencode($symbol);

    $headers = [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
        'Accept: application/json,text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language: en-US,en;q=0.9',
        'Referer: https://www.nseindia.com/',
        'Connection: keep-alive',
    ];

    $context = stream_context_create([
        'http' => [
            'method'  => 'GET',
            'header'  => implode("\r\n", $headers) . "\r\n",
            'timeout' => 8,
        ]
    ]);

    $raw = @file_get_contents($url, false, $context);
    if ($raw === false) {
        return null;
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return null;
    }

    // 🔁 Adjust depending on response structure
    $ltp = $data['priceInfo']['lastPrice'] ?? null;
    if ($ltp === null) {
        return null;
    }

    $ltp = (float)$ltp;
    @file_put_contents($cacheFile, json_encode(['ltp' => $ltp]));

    return $ltp;
}

/**
 * Insert a new purchase lot and update/insert the aggregated position.
 *
 * $payload keys: symbol, name, trade_date (Y-m-d), qty, price, charges, notes
 */
function portfolio_add_lot(mysqli $mysqli, int $userId, array $payload): bool
{
    $symbol     = strtoupper(trim($payload['symbol'] ?? ''));
    $name       = trim($payload['name'] ?? '');
    $tradeDate  = trim($payload['trade_date'] ?? '');
    $qty        = (float)($payload['qty'] ?? 0);
    $price      = (float)($payload['price'] ?? 0);
    $charges    = (float)($payload['charges'] ?? 0);
    $notes      = trim($payload['notes'] ?? '');

    if ($symbol === '' || $tradeDate === '' || $qty <= 0 || $price <= 0) {
        return false;
    }

    $mysqli->begin_transaction();

    try {
        // 1) Find or create position
        $posId = null;

        if ($stmt = $mysqli->prepare("SELECT id FROM portfolio_positions WHERE user_id = ? AND symbol = ? AND is_active = 1 LIMIT 1")) {
            $stmt->bind_param('is', $userId, $symbol);
            $stmt->execute();
            $stmt->bind_result($foundId);
            if ($stmt->fetch()) {
                $posId = (int)$foundId;
            }
            $stmt->close();
        }

        if ($posId === null) {
            // create new position
            if ($stmt = $mysqli->prepare("
                INSERT INTO portfolio_positions (user_id, symbol, name, total_qty, avg_price)
                VALUES (?, ?, ?, 0, 0)
            ")) {
                $stmt->bind_param('iss', $userId, $symbol, $name);
                if (!$stmt->execute()) {
                    throw new Exception('Insert position failed');
                }
                $posId = (int)$stmt->insert_id;
                $stmt->close();
            }
        }

        if ($posId === null) {
            throw new Exception('No position id');
        }

        // 2) Insert lot
        if ($stmt = $mysqli->prepare("
            INSERT INTO portfolio_lots (user_id, position_id, trade_date, qty, price, charges, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ")) {
            $stmt->bind_param(
                'iisddds',
                $userId,
                $posId,
                $tradeDate,
                $qty,
                $price,
                $charges,
                $notes
            );
            if (!$stmt->execute()) {
                throw new Exception('Insert lot failed');
            }
            $stmt->close();
        }

        // 3) Recalculate totals for this position
        if ($stmt = $mysqli->prepare("
            SELECT SUM(qty) AS tq, SUM(qty * price) AS tc
            FROM portfolio_lots
            WHERE position_id = ?
        ")) {
            $stmt->bind_param('i', $posId);
            $stmt->execute();
            $stmt->bind_result($tq, $tc);
            $tq = $tq ?? 0;
            $tc = $tc ?? 0;
            $stmt->fetch();
            $stmt->close();

            $avg = $tq > 0 ? ($tc / $tq) : 0;

            if ($stmt2 = $mysqli->prepare("
                UPDATE portfolio_positions
                SET total_qty = ?, avg_price = ?, updated_at = NOW()
                WHERE id = ?
            ")) {
                $stmt2->bind_param('ddi', $tq, $avg, $posId);
                $stmt2->execute();
                $stmt2->close();
            }
        }

        $mysqli->commit();
        return true;

    } catch (Throwable $e) {
        $mysqli->rollback();
        error_log('portfolio_add_lot error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Fetch open positions for a user, updating LTP (with cache) as needed.
 * Returns array of rows with extra fields: unrealized_pnl
 */
function get_portfolio_positions(mysqli $mysqli, int $userId): array
{
    $rows = [];

    if ($stmt = $mysqli->prepare("
        SELECT id, symbol, name, total_qty, avg_price, last_ltp, last_ltp_at
        FROM portfolio_positions
        WHERE user_id = ? AND is_active = 1
        ORDER BY name ASC
    ")) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $rows[] = $r;
        }
        $stmt->close();
    }

    // Update LTP & unrealized P&L
    foreach ($rows as &$r) {
        $ltp = fetch_nse_equity_ltp($r['symbol']);
        if ($ltp !== null) {
            $r['last_ltp'] = $ltp;
            $r['unrealized_pnl'] = ($ltp - (float)$r['avg_price']) * (float)$r['total_qty'];

            // Update DB LTP (optional, not critical if errors)
            if ($stmt = $mysqli->prepare("
                UPDATE portfolio_positions
                SET last_ltp = ?, last_ltp_at = NOW()
                WHERE id = ?
            ")) {
                $stmt->bind_param('di', $ltp, $r['id']);
                $stmt->execute();
                $stmt->close();
            }
        } else {
            $r['unrealized_pnl'] = null;
        }
    }
    unset($r);

    return $rows;
}

/**
 * Fetch all lots for a given position (for View modal).
 */
function get_portfolio_lots(mysqli $mysqli, int $userId, int $positionId): array
{
    $rows = [];
    if ($stmt = $mysqli->prepare("
        SELECT id, trade_date, qty, price, charges, notes, created_at
        FROM portfolio_lots
        WHERE user_id = ? AND position_id = ?
        ORDER BY trade_date DESC, id DESC
    ")) {
        $stmt->bind_param('ii', $userId, $positionId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $rows[] = $r;
        }
        $stmt->close();
    }
    return $rows;
}

/**
 * Get NSE equity symbol list (SYMBOL + company name).
 * Uses NSE EQUITY_L.csv and caches locally for 1 day.
 *
 * Returns: [ [ 'symbol' => 'RELIANCE', 'name' => 'Reliance Industries Limited' ], ... ]
 */
/**
 * Wrapper: use the working NSE equity list loader.
 *
 * Returns: [ ['symbol' => 'RELIANCE', 'name' => 'Reliance Industries Ltd'], ... ]
 */
/**
 * Get NSE equity symbol list (SYMBOL + company name).
 * Uses NSE EQUITY_L.csv and caches locally for 1 day.
 *
 * Returns: [ [ 'symbol' => 'RELIANCE', 'name' => 'Reliance Industries Limited' ], ... ]
 */
function get_nse_equity_list(): array
{
    $cacheDir  = __DIR__ . '/cache';
    $cacheFile = $cacheDir . '/nse_equity_master.json';
    $cacheTtl  = 86400; // 1 day

    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0777, true);
    }

    // 1) Try cache
    if (is_file($cacheFile) && (time() - filemtime($cacheFile) < $cacheTtl)) {
        $cached = json_decode(@file_get_contents($cacheFile), true);
        if (is_array($cached)) {
            //error_log('get_nse_equity_list: loaded ' . count($cached) . ' stocks from cache');
            return $cached;
        }
    }

    // 2) Fetch from NSE EQUITY_L.csv (all equities)
    $url = 'https://archives.nseindia.com/content/equities/EQUITY_L.csv';

    $headers = [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
        'Accept: text/csv,application/json,text/plain,*/*',
        'Accept-Language: en-US,en;q=0.9',
        'Referer: https://www.nseindia.com/',
        'Connection: keep-alive',
    ];

    $context = stream_context_create([
        'http' => [
            'method'  => 'GET',
            'header'  => implode("\r\n", $headers) . "\r\n",
            'timeout' => 20,
        ]
    ]);

    $csv = @file_get_contents($url, false, $context);
    if ($csv === false) {
        error_log('get_nse_equity_list: failed to download EQUITY_L.csv');
        return [];
    }

    $lines = preg_split('/\r\n|\r|\n/', trim($csv));
    if (!$lines || count($lines) < 2) {
        error_log('get_nse_equity_list: CSV content too short');
        return [];
    }

    $header = str_getcsv(array_shift($lines));

    // Typical column names in EQUITY_L.csv
    $symbolIdx = array_search('SYMBOL', $header);
    $nameIdx   = array_search('NAME OF COMPANY', $header);

    if ($symbolIdx === false) {
        error_log('get_nse_equity_list: SYMBOL column not found in header');
        return [];
    }

    $out = [];
    foreach ($lines as $line) {
        if ($line === '') continue;
        $cols = str_getcsv($line);
        if (!isset($cols[$symbolIdx])) continue;

        $symbol = trim($cols[$symbolIdx]);
        $name   = isset($cols[$nameIdx]) ? trim($cols[$nameIdx]) : $symbol;

        if ($symbol === '') continue;

        $out[] = [
            'symbol' => $symbol,
            'name'   => $name,
        ];
    }

    //error_log('get_nse_equity_list: parsed ' . count($out) . ' stocks from CSV');

    // Store to cache
    @file_put_contents($cacheFile, json_encode($out));

    return $out;
}

?>
