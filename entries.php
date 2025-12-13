<?php
// entries.php (updated)
// Keep your includes and auth
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/functions.php';

app_start_protected();

$userId  = get_current_user_id();

// Fetch trades for user
$result  = get_trades_for_user($mysqli, $userId);

// Fetch strategy templates for this user (only enabled)
$strategies = get_user_strategy_templates($mysqli, $userId, true);

// Compute strategyStats as you already had (unchanged code assumed)
// ... (if you have the earlier SQL/stat collection block, keep it here; we assume $strategyStats exists)
$strategyStats = $strategyStats ?? [];

// ---------- Next Trade # properly from DB ----------
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

        <!-- Header -->
        <div class="card">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:0.75rem;flex-wrap:wrap;">
                <h2 class="card-title" style="margin-bottom:0;">Entries</h2>
                <div style="display:flex;gap:0.5rem;align-items:center;flex-wrap:wrap;">
                    <button class="btn" type="button" id="openAddTrade">Add Trade</button>
                    <a class="btn btn-secondary" href="export_trades.php?format=csv">Export CSV</a>
                </div>
            </div>
        </div>

        <!-- Trade history -->
        <div class="card">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:0.75rem;flex-wrap:wrap;">
                <h2 class="card-title" style="margin-bottom:0;">Trade History</h2>

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

            <!-- Stats (same as before) -->
            <div id="strategyStats" class="form-grid" style="margin-top:0.75rem;display:none;">
                <div><div class="text-muted">Strategy</div><div id="ss_name" class="ss-title"></div></div>
                <div><div class="text-muted">Trades</div><div id="ss_trades"></div></div>
                <div><div class="text-muted">Win %</div><div id="ss_winrate"></div></div>
                <div><div class="text-muted">Total Profit</div><div id="ss_profit"></div></div>
                <div><div class="text-muted">Total Loss</div><div id="ss_loss"></div></div>
                <div><div class="text-muted">Avg Profit</div><div id="ss_avg_profit"></div></div>
                <div><div class="text-muted">Avg Loss</div><div id="ss_avg_loss"></div></div>
                <div class="text-muted"><div class="text-muted">Best Day(s)</div><div id="ss_best_days" class="d-flex"></div></div>
            </div>

            <div class="table-wrapper" style="margin-top:0.75rem;">
                <table class="datatable">
                    <thead>
                        <tr>
                            <th style="width:70px; white-space:nowrap;">Trade #</th>
                            <th style="width:150px;">Date</th>
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
                    <?php
                        // gather all rows and group by date -> show one row per day
                        $rows = [];
                        while ($r = $result->fetch_assoc()) {
                            $rows[] = $r;
                        }
                        if (empty($rows)):
                    ?>
                        <tr><td colspan="9">No trades recorded yet.</td></tr>
                    <?php else:
                        $grouped = [];
                        foreach ($rows as $r) {
                            $d = $r['trade_date'] ?? '';
                            if (!isset($grouped[$d])) {
                                $grouped[$d] = ['rows' => [], 'profit' => 0.0, 'loss' => 0.0];
                            }
                            $grouped[$d]['rows'][] = $r;
                            $grouped[$d]['profit'] += (float)($r['profit'] ?? 0);
                            $grouped[$d]['loss'] += (float)($r['loss'] ?? 0);
                        }
                        $dates = array_keys($grouped);
                        rsort($dates);
                        foreach ($dates as $d):
                            $meta = $grouped[$d];
                            $first = $meta['rows'][0];
                            $count = count($meta['rows']);
                            $profit = (float)$meta['profit'];
                            $loss = (float)$meta['loss'];
                            $json = json_encode($meta['rows']);
                            $jsonSafe = htmlspecialchars($json, ENT_QUOTES, 'UTF-8');
                    ?>
                        <tr data-trades="<?php echo $jsonSafe; ?>">
                            <td><?php echo htmlspecialchars($first['trade_no'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars(format_trade_date($d)); ?></td>
                            <td><?php echo htmlspecialchars($first['day'] ?? ''); ?></td>
                            <td style="text-align:center;"><?php echo $count; ?></td>
                            <td style="text-align:right;" class="<?php echo $profit>0? 'text-profit' : ''; ?>"><?php echo number_format($profit,2); ?></td>
                            <td style="text-align:right;" class="<?php echo $loss>0? 'text-loss' : ''; ?>"><?php echo number_format($loss,2); ?></td>
                            <td><?php echo htmlspecialchars($first['setup_type'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($first['emotion'] ?? ''); ?></td>
                            <td style="white-space:nowrap;">
                                <button type="button" class="btn btn-small btn-secondary btn-view-day" title="View day"><i class="fa-regular fa-eye"></i></button>

                                <!-- Edit / Delete - operate on first row's ID of that day -->
                                <?php if (!empty($first['id'])): ?>
                                    <button type="button" class="btn btn-small btn-secondary btn-edit-day" data-edit-url="entry_edit.php?id=<?php echo (int)$first['id']; ?>" title="Edit day entry"><i class="fa-regular fa-pen-to-square"></i></button>
                                    <button type="button" class="btn btn-small btn-danger btn-delete-day" data-delete-url="entry_delete.php?id=<?php echo (int)$first['id']; ?>" title="Delete day entry"><i class="fa-regular fa-trash-can"></i></button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php
                        endforeach;
                    endif;
                    ?>
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
      <form id="tradeForm" method="post" enctype="multipart/form-data" action="entry_save.php">
        <div class="form-grid">
          <div class="form-group">
            <label for="trade_no">Trade #</label>
            <input type="text" id="trade_no" name="trade_no" value="<?php echo htmlspecialchars($nextTradeNo); ?>" readonly>
          </div>

          <div class="form-group">
            <label for="trade_date">Date</label>
            <input type="date" id="trade_date" name="trade_date" required>
            <div id="trade_date_display" style="margin-top:4px;font-size:0.95rem;color:#333;"></div>
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
            <label for="instrument">Instrument (default)</label>
            <select id="instrument" name="instrument" class="select2">
              <option value="NIFTY">NIFTY</option>
              <option value="BANKNIFTY">BANKNIFTY</option>
              <option value="SENSEX">SENSEX</option>
              <option value="FINNIFTY">FINNIFTY</option>
              <option value="GOLD">GOLD</option>
              <option value="SILVER">SILVER</option>
            </select>
          </div>

          <div class="form-group">
            <label for="lots">Number of lots (default)</label>
            <input type="number" id="lots" name="lots" value="1" min="1" step="1">
          </div>

          <div class="form-group">
            <label for="opening_bal">Opening Balance (Day)</label>
            <input type="number" id="opening_bal" name="opening_bal" step="0.01">
          </div>

          <div class="form-group">
            <label for="closing_bal">Closing Balance (Day)</label>
            <input type="number" id="closing_bal" name="closing_bal" step="0.01">
          </div>

          <div class="form-group" id="total_profit_wrap">
            <label for="profit">Total Profit (Day)</label>
            <input type="number" id="profit" name="profit" step="0.01" readonly>
          </div>

          <div class="form-group" id="total_loss_wrap">
            <label for="loss">Total Loss (Day)</label>
            <input type="number" id="loss" name="loss" step="0.01" readonly>
          </div>

          <div class="form-group" style="grid-column: 3 / 4;">
            <label for="screenshot">Screenshot (day-level fallback)</label>
            <input type="file" id="screenshot" name="screenshot" accept="image/*">
          </div>

          <div id="perTradeContainer" style="grid-column:1 / -1; width:100%;"></div>
        </div>

        <div class="form-actions">
          <button class="btn" type="submit">Save Entry</button>
        </div>
      </form>

      <!-- per-trade template -->
      <template id="perTradeTemplate">
      <div class="per-trade-group card" style="padding:12px; margin-bottom:10px;">
        <div style="display:flex;justify-content:space-between;align-items:center;">
          <strong class="pt-title">Trade #X</strong>
          <small class="text-muted">Fill details for this trade</small>
        </div>

        <div class="form-grid" style="margin-top:8px;">
          <div class="form-group">
            <label>Strike Taken</label>
            <input type="number" name="option_strike[]" step="1" placeholder="e.g. 26000">
          </div>

          <div class="form-group">
            <label>Option Type</label>
            <select name="option_type[]" class="select2">
              <option value="">Select</option>
              <option value="CE">CE (Call)</option>
              <option value="PE">PE (Put)</option>
            </select>
          </div>

          <div class="form-group">
            <label>Closing point of the trade</label>
            <input type="number" name="underlying_close[]" step="0.01" placeholder="e.g. 25980.25">
          </div>

          <div class="form-group">
            <label>Entry Point</label>
            <input type="number" name="entry_point[]" step="0.01" class="entry-point">
          </div>

          <div class="form-group">
            <label>Exit Point</label>
            <input type="number" name="exit_point[]" step="0.01" class="exit-point">
          </div>

          <div class="form-group">
            <label>Lots</label>
            <input type="number" name="lots[]" step="1" min="1" value="1" class="lots">
          </div>

          <div class="form-group">
            <label>Instrument</label>
            <select name="instrument[]" class="select2 instrument-select">
              <option value="">(use day default)</option>
              <option value="NIFTY">NIFTY</option>
              <option value="BANKNIFTY">BANKNIFTY</option>
              <option value="SENSEX">SENSEX</option>
              <option value="FINNIFTY">FINNIFTY</option>
              <option value="COMMODITY">COMMODITY</option>
              <option value="OTHER">OTHER</option>
            </select>
          </div>

          <div class="form-group">
            <label>Profit in this trade</label>
            <input type="number" name="trade_profit[]" step="0.01" readonly class="trade-profit">
          </div>

          <div class="form-group">
            <label>Loss in this trade</label>
            <input type="number" name="trade_loss[]" step="0.01" readonly class="trade-loss">
          </div>

          <div class="form-group">
            <label>Setup Type</label>
            <select name="setup_type[]" class="select2">
              <option value="">Select / type...</option>
              <?php if (!empty($strategies)): foreach ($strategies as $s): ?>
                <option value="<?php echo htmlspecialchars($s['name']); ?>"><?php echo htmlspecialchars($s['name']); ?></option>
              <?php endforeach; endif; ?>
            </select>
          </div>

          <div class="form-group">
            <label>Entry Reason</label>
            <input type="text" name="entry_reason[]">
          </div>

          <div class="form-group">
            <label>Rule Followed?</label>
            <select name="rule_followed[]" class="select2">
              <option value="">Select</option>
              <option value="Yes">Yes</option>
              <option value="No">No</option>
              <option value="Partially">Partially</option>
            </select>
          </div>

          <div class="form-group">
            <label>Emotion</label>
            <select name="emotion[]" class="select2">
              <option value="">Select</option>
              <option value="Calm">Calm</option>
              <option value="Fearful">Fearful</option>
              <option value="Greedy">Greedy</option>
              <option value="Revenge">Revenge</option>
            </select>
          </div>

          <div class="form-group">
            <label>Strategy Tags</label>
            <input name="strategy_tags[]" class="tagify" placeholder="comma separated">
          </div>

          <div class="form-group">
            <label>Mistake Tags</label>
            <input name="mistake_tags[]" class="tagify" placeholder="comma separated">
          </div>

          <div class="form-group" style="grid-column:1 / -1;">
            <label>Screenshot (this trade)</label>
            <input type="file" name="screenshot[]" accept="image/*">
          </div>

          <div class="form-group" style="grid-column:1 / -1;">
            <label>Notes</label>
            <textarea name="notes[]" rows="2" style="width:100%;"></textarea>
          </div>
        </div>
      </div>
      </template>
    </div>
  </div>
</div>

<!-- View Day Modal -->
<div class="modal-backdrop" id="viewTradeModal">
  <div class="modal">
    <div class="modal-header">
      <h2 class="card-title">Trades for selected day</h2>
      <button type="button" class="btn btn-small btn-secondary" id="closeViewTrade">Close</button>
    </div>
    <div class="modal-body">
      <div id="viewTradeContent"></div>
    </div>
  </div>
</div>

<style>
/* profit green, loss red (inputs & table cells) */
.text-profit { color: #0a8a00 !important; } /* green */
.text-loss   { color: #d22 !important; } /* red */
.per-trade-group .trade-profit { color: #0a8a00; }
.per-trade-group .trade-loss { color: #d22; }
</style>

<script>
/* Client JS for behavior requested */
const LOT_SIZES = {
  'NIFTY': 75,
  'BANKNIFTY': 15,
  'SENSEX': 1,
  'FINNIFTY': 40,
  'GOLD': 1,
  'SILVER': 15,
  'OTHER': 1
};

jQuery(function($){

  // init Select2 + Tagify helpers
  function initSelect2($c){
    if(!$.fn.select2) return;
    $c.find('select.select2').each(function(){
      if($(this).data('select2')) return;
      var $modal = $(this).closest('#addTradeModal .modal');
      var cfg = { width: '100%' };
      if($modal.length) cfg.dropdownParent = $modal;
      $(this).select2(cfg);
    });
  }
  function initTagify($c){
    if(!window.Tagify) return;
    $c.find('input.tagify').each(function(){
      if(!this._tagify) new Tagify(this);
    });
  }
  function initControls($c){ initSelect2($c); initTagify($c); }

  // Utility: format date as "10th Dec 2025"
  function ordinal(n){
    var s=["th","st","nd","rd"], v=n%100;
    return n + (s[(v-20)%10] || s[v] || s[0]);
  }
  function formatDatePretty(iso){
    if(!iso) return '';
    var d = new Date(iso);
    if(isNaN(d.getTime())) return iso;
    var day = d.getDate();
    var moNames = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return ordinal(day) + ' ' + moNames[d.getMonth()] + ' ' + d.getFullYear();
  }

  // Preserve existing per-trade values when re-rendering groups
  function captureExistingGroups(){
    var out = [];
    $('#perTradeContainer .per-trade-group').each(function(){
      var $g = $(this);
      out.push({
        option_strike: $g.find('input[name="option_strike[]"]').val() || '',
        option_type: $g.find('select[name="option_type[]"]').val() || '',
        underlying_close: $g.find('input[name="underlying_close[]"]').val() || '',
        entry_point: $g.find('input[name="entry_point[]"]').val() || '',
        exit_point: $g.find('input[name="exit_point[]"]').val() || '',
        lots: $g.find('input[name="lots[]"]').val() || '',
        instrument: $g.find('select[name="instrument[]"]').val() || '',
        trade_profit: $g.find('input[name="trade_profit[]"]').val() || '',
        trade_loss: $g.find('input[name="trade_loss[]"]').val() || '',
        setup_type: $g.find('select[name="setup_type[]"]').val() || '',
        entry_reason: $g.find('input[name="entry_reason[]"]').val() || '',
        rule_followed: $g.find('select[name="rule_followed[]"]').val() || '',
        emotion: $g.find('select[name="emotion[]"]').val() || '',
        strategy_tags: $g.find('input[name="strategy_tags[]"]').val() || '',
        mistake_tags: $g.find('input[name="mistake_tags[]"]').val() || '',
        notes: $g.find('textarea[name="notes[]"]').val() || ''
      });
    });
    return out;
  }

  function applyValuesToGroup($g, data){
    if(!data) return;
    $g.find('input[name="option_strike[]"]').val(data.option_strike);
    $g.find('select[name="option_type[]"]').val(data.option_type);
    $g.find('input[name="underlying_close[]"]').val(data.underlying_close);
    $g.find('input[name="entry_point[]"]').val(data.entry_point);
    $g.find('input[name="exit_point[]"]').val(data.exit_point);
    $g.find('input[name="lots[]"]').val(data.lots || 1);
    $g.find('select[name="instrument[]"]').val(data.instrument || '');
    $g.find('input[name="trade_profit[]"]').val(data.trade_profit || '');
    $g.find('input[name="trade_loss[]"]').val(data.trade_loss || '');
    $g.find('select[name="setup_type[]"]').val(data.setup_type || '');
    $g.find('input[name="entry_reason[]"]').val(data.entry_reason || '');
    $g.find('select[name="rule_followed[]"]').val(data.rule_followed || '');
    $g.find('select[name="emotion[]"]').val(data.emotion || '');
    $g.find('input[name="strategy_tags[]"]').val(data.strategy_tags || '');
    $g.find('input[name="mistake_tags[]"]').val(data.mistake_tags || '');
    $g.find('textarea[name="notes[]"]').val(data.notes || '');

    // re-init tagify/select2 values after setting
    initControls($g);
  }

  // Calculate one group's profit/loss using instrument + lots
  function calcGroup($g){
    var entry = parseFloat($g.find('input[name="entry_point[]"]').val()) || 0;
    var exitv = parseFloat($g.find('input[name="exit_point[]"]').val()) || 0;

    // choose instrument/lots: prefer per-trade, fallback to day default
    var inst = $g.find('select[name="instrument[]"]').val() || $('#instrument').val() || 'OTHER';
    var lots = parseInt($g.find('input[name="lots[]"]').val() || $('#lots').val() || '1',10);
    if(isNaN(lots) || lots < 1) lots = 1;
    var lotSize = LOT_SIZES[inst] || 1;
    var multiplier = lotSize * lots;

    var entryAmt = entry * multiplier;
    var exitAmt  = exitv * multiplier;
    var diff = exitAmt - entryAmt;

    var $p = $g.find('input[name="trade_profit[]"]');
    var $l = $g.find('input[name="trade_loss[]"]');

    // reset colors first
    $p.removeClass('text-profit text-loss');
    $l.removeClass('text-profit text-loss');

    if (diff > 0) {
      $p.val(diff.toFixed(2)).addClass('text-profit');
      $l.val('0.00');
    } else if (diff < 0) {
      $p.val('0.00');
      $l.val(Math.abs(diff).toFixed(2)).addClass('text-loss');
    } else {
      $p.val('0.00');
      $l.val('0.00');
    }
  }

  // recompute day totals from per-trade
  function recomputeTotals(){
    var totP = 0, totL = 0;
    $('#perTradeContainer .per-trade-group').each(function(){
      var p = parseFloat($(this).find('input[name="trade_profit[]"]').val()||'0') || 0;
      var l = parseFloat($(this).find('input[name="trade_loss[]"]').val()||'0') || 0;
      totP += p; totL += l;
    });

    // Fill total inputs
    $('#profit').val(totP.toFixed(2));
    $('#loss').val(totL.toFixed(2));

    // If opening/closing present, use them to compute day net and hide the opposite field
    var opening = parseFloat($('#opening_bal').val()||'0') || 0;
    var closing = parseFloat($('#closing_bal').val()||'0') || 0;
    if(opening !== 0 || closing !== 0){
      var dayNet = closing - opening;
      if(dayNet > 0){
        $('#profit').val(dayNet.toFixed(2)).addClass('text-profit').removeClass('text-loss');
        $('#loss').val('0.00').removeClass('text-profit').addClass('text-loss');
        $('#total_loss_wrap').hide(); $('#total_profit_wrap').show();
      } else if(dayNet < 0){
        $('#loss').val(Math.abs(dayNet).toFixed(2)).addClass('text-loss').removeClass('text-profit');
        $('#profit').val('0.00').removeClass('text-loss').addClass('text-profit');
        $('#total_profit_wrap').hide(); $('#total_loss_wrap').show();
      } else {
        $('#profit').val('0.00'); $('#loss').val('0.00');
        $('#total_profit_wrap').show(); $('#total_loss_wrap').show();
      }
    } else {
      // no opening/closing provided: show both totals
      $('#total_profit_wrap').show(); $('#total_loss_wrap').show();
      // color totals based on sign
      if(parseFloat($('#profit').val())>0){
        $('#profit').addClass('text-profit').removeClass('text-loss');
      } else { $('#profit').removeClass('text-profit text-loss'); }
      if(parseFloat($('#loss').val())>0){
        $('#loss').addClass('text-loss').removeClass('text-profit');
      } else { $('#loss').removeClass('text-profit text-loss'); }
    }
  }

  // render n groups preserving values
  function renderGroups(n){
    var saved = captureExistingGroups();
    var $container = $('#perTradeContainer');
    var tpl = document.getElementById('perTradeTemplate');
    if(!tpl) return;
    $container.empty();
    for(var i=0;i<n;i++){
      var node = tpl.content.cloneNode(true);
      var $node = $(node);
      $node.find('.pt-title').text('Trade ' + (i+1));
      $container.append($node);
      var $g = $container.children().last();
      // apply saved values if exist
      if(saved[i]){
        applyValuesToGroup($g, saved[i]);
      }
      initControls($g);
      // set instrument placeholder to show day default
      var dayDefault = $('#instrument').val() || '';
      if(dayDefault){
        $g.find('select.instrument-select option[value=""]').text('(use day default: ' + dayDefault + ')');
      } else {
        $g.find('select.instrument-select option[value=""]').text('(use day default)');
      }
      // bind inputs
      $g.find('input[name="entry_point[]"], input[name="exit_point[]"], input[name="lots[]"], select[name="instrument[]"]').on('input change', function(){
        calcGroup($g);
        recomputeTotals();
      });
      // recalc for this group
      calcGroup($g);
    }
    recomputeTotals();
  }

  // initial boot
  $(document).ready(function(){
    initControls($(document));
    if($.fn.DataTable){
      if($.fn.dataTable.isDataTable('table.datatable')){
        $('table.datatable').DataTable();
      } else { $('table.datatable').DataTable(); }
    }
    var n = parseInt($('#no_trades').val()||'1',10); if(isNaN(n)||n<1) n=1;
    renderGroups(n);

    // keep date formatting & day name and display
    $('#trade_date').on('change', function(){
      var v = $(this).val();
      $('#trade_date_display').text(formatDatePretty(v));
      if(!v){ $('#day').val(''); return; }
      var d = new Date(v);
      if(isNaN(d.getTime())) { $('#day').val(''); return; }
      var days = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
      $('#day').val(days[d.getDay()]);
    });

    // keep instrument default text updated in existing groups when global default changes
    $('#instrument').on('change', function(){
      var dayDefault = $(this).val();
      $('#perTradeContainer select.instrument-select option[value=""]').each(function(){
        if(dayDefault) $(this).text('(use day default: ' + dayDefault + ')');
        else $(this).text('(use day default)');
      });
      // recalc (multiplier changed)
      $('#perTradeContainer .per-trade-group').each(function(){
        calcGroup($(this));
      });
      recomputeTotals();
    });

    // handle no_trades change without wiping data
    $('#no_trades').on('change', function(){
      var nn = parseInt($(this).val()||'1',10); if(isNaN(nn)||nn<1) nn=1;
      renderGroups(nn);
    });

    $('#lots').on('input change', function(){ $('#perTradeContainer .per-trade-group').each(function(){ calcGroup($(this)); }); recomputeTotals(); });
    $('#opening_bal, #closing_bal').on('input change', function(){ recomputeTotals(); });

    // open/close modal
    $('#openAddTrade').on('click', function(){ $('#addTradeModal').addClass('open'); setTimeout(function(){ initControls($('#addTradeModal')); }, 60); });
    $('#closeAddTrade').on('click', function(){ $('#addTradeModal').removeClass('open'); });

    // view day
    $(document).on('click', '.btn-view-day', function(){
      var json = $(this).closest('tr').attr('data-trades');
      if(!json) return;
      var rows = [];
      try { rows = JSON.parse(json); } catch(e){ alert('Invalid data'); return; }
      var html = '<div style="max-height:60vh;overflow:auto;"><table style="width:100%"><thead><tr><th>#</th><th>Strike</th><th>Type</th><th>Instrument</th><th>Lots</th><th>Entry</th><th>Exit</th><th>Profit</th><th>Loss</th><th>Notes</th><th>Screenshot</th></tr></thead><tbody>';
      rows.forEach(function(r, idx){
        var strike = r.option_strike||'';
        var otype = r.option_type||'';
        var instrument = r.instrument|| (r.instrument??'');
        var lots = r.lots || '';
        var entry = r.entry_point ?? r.opening_bal ?? '';
        var exitv = r.exit_point ?? r.closing_bal ?? '';
        var profit = r.trade_profit ?? r.profit ?? '';
        var loss = r.trade_loss ?? r.loss ?? '';
        var notes = r.notes ? $('<div>').text(r.notes).html() : '';
        var shot = '—';
        if(r.screenshot_path) shot = '<a href="'+r.screenshot_path+'" target="_blank">View</a>';
        html += '<tr><td>'+(idx+1)+'</td><td>'+strike+'</td><td>'+otype+'</td><td>'+instrument+'</td><td>'+lots+'</td><td>'+entry+'</td><td>'+exitv+'</td><td style="text-align:right;">'+(profit !== '' ? Number(profit).toFixed(2): '')+'</td><td style="text-align:right;">'+(loss !== '' ? Number(loss).toFixed(2): '')+'</td><td>'+notes+'</td><td>'+shot+'</td></tr>';
      });
      html += '</tbody></table></div>';
      $('#viewTradeContent').html(html);
      $('#viewTradeModal').addClass('open');
    });
    $('#closeViewTrade').on('click', function(){ $('#viewTradeModal').removeClass('open'); $('#viewTradeContent').html(''); });

    // edit / delete buttons on grouped rows
    $(document).on('click', '.btn-edit-day', function(){
      var url = $(this).data('edit-url');
      if(!url) return;
      window.location.href = url;
    });
    $(document).on('click', '.btn-delete-day', function(){
      var url = $(this).data('delete-url');
      if(!url) return;
      if(confirm('Delete this day (all trades under it)? This action cannot be undone.')) window.location.href = url;
    });

  }); // ready
});
</script>

<script>const STRATEGY_STATS = <?php echo json_encode($strategyStats); ?>;</script>