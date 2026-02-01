<?php
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/functions.php';

app_start_protected();
$userId = get_current_user_id();

/* Next Trade # */
$nextTradeNo = 1;
$stmt = $mysqli->prepare(
  'SELECT MAX(CAST(trade_no AS UNSIGNED)) FROM trades WHERE user_id = ?'
);
$stmt->bind_param('i', $userId);
$stmt->execute();
$stmt->bind_result($maxNo);
if ($stmt->fetch() && $maxNo !== null) {
  $nextTradeNo = (int)$maxNo + 1;
}
$stmt->close();
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Add Trade Entry</title>

<style>
/* ========== BASE ========== */
body { background:#f5f6fa; }
.card {
  background:#fff;
  border:1px solid #e5e7eb;
  border-radius:8px;
  padding:16px;
  margin-bottom:16px;
}
.card-title {
  font-weight:600;
  margin-bottom:12px;
}

label {
  font-size:12px;
  color:#6b7280;
  margin-bottom:4px;
  display:block;
}

input, select {
  width:100%;
  height:40px;
  padding:8px 10px;
  border:1px solid #d1d5db;
  border-radius:6px;
  font-size:14px;
  background:#fff;
}

/* ========== TOP ROW ========== */
.form-row-top {
  display:grid;
  grid-template-columns:120px 170px 130px 140px 1fr;
  gap:14px;
  align-items:end;
}

/* ========== DATE ========== */
.date-wrap { position:relative; height: 60px;}

/* ========== MULTISELECT ========== */
.multi-select { position:relative; }
.multi-display {
  min-height:40px;
  border:1px solid #d1d5db;
  border-radius:6px;
  padding:4px 6px;
  display:flex;
  gap:6px;
  flex-wrap:wrap;
  align-items:center;
  cursor:pointer;
}
.multi-placeholder {
  color:#9ca3af;
  font-size:13px;
}
.tag {
  background:#eef2ff;
  color:#1e40af;
  font-size:12px;
  padding:3px 6px;
  border-radius:4px;
}
.tag i {
  margin-left:6px;
  cursor:pointer;
}
.multi-options {
  display:none;
  position:absolute;
  top:44px;
  left:0;
  right:0;
  background:#fff;
  border:1px solid #d1d5db;
  border-radius:6px;
  z-index:50;
}
.multi-options label {
  display:flex;
  gap:6px;
  padding:8px;
  cursor:pointer;
}
.multi-options label:hover {
  background:#f3f4f6;
}

/* ========== BALANCES ========== */
.balance-row {
  display:grid;
  grid-template-columns:repeat(4,1fr);
  gap:14px;
  margin-top:16px;
}
.input-group {
  display:flex;
}
.input-group span {
  display:flex;
  align-items:center;
  padding:0 12px;
  background:#f3f4f6;
  border:1px solid #d1d5db;
  border-right:none;
  border-radius:6px 0 0 6px;
}
.input-group input {
  border-left:none;
  border-radius:0 6px 6px 0;
}

/* ========== MARKET ========== */
.market-card {
  border:1px solid #e5e7eb;
  border-radius:8px;
  padding:12px;
  margin-top:14px;
}
.market-grid {
  display:grid;
  grid-template-columns:repeat(4,1fr);
  gap:10px;
  margin-top:8px;
}

/* ========== BUTTON ========== */
.btn {
  margin-top:18px;
  background:#2563eb;
  color:#fff;
  border:none;
  padding:10px 18px;
  border-radius:6px;
  font-size:14px;
  cursor:pointer;
}

.date-real {
  position: absolute;
  inset: 0;
  opacity: 0;
  z-index: 10;
  cursor: pointer;

  /* ⛔ remove layout influence */
  height: 100%;
  width: 100%;
  margin: 0;
  padding: 0;
  border: 0;

  /* ⛔ critical */
  line-height: 1;
  font-size: 0;
}
</style>
</head>

<body>
<div class="content">
<?php require_once __DIR__ . '/inc/topbar.php'; ?>

<main class="main">

<div class="card">
  <h2 class="card-title">Add Trade Entry</h2>

  <!-- TOP ROW -->
  <div class="form-row-top">

    <div>
      <label>Trade #</label>
      <input value="<?= $nextTradeNo ?>" readonly>
    </div>

    <div class="date-wrap">
      <label>Date</label>
      <input type="text" id="trade_date_display" readonly>
      <input type="date" id="trade_date" name="trade_date"
             max="<?= date('Y-m-d') ?>" required class="date-real">
    </div>

    <div>
      <label>Day</label>
      <input id="day" readonly>
    </div>

    <div>
      <label>No of Trades</label>
      <select id="no_trades">
        <?php for($i=1;$i<=50;$i++): ?>
          <option><?= $i ?></option>
        <?php endfor; ?>
      </select>
    </div>

    <div class="multi-select" id="instrumentSelect">
      <label>Instruments</label>
      <div class="multi-display" id="instrumentDisplay">
        <span class="multi-placeholder">Select instruments</span>
      </div>
      <div class="multi-options">
        <?php foreach(['NIFTY','BANKNIFTY','SENSEX','FINNIFTY','OTHER'] as $i): ?>
          <label><input type="checkbox" value="<?= $i ?>"> <?= $i ?></label>
        <?php endforeach; ?>
      </div>
    </div>

  </div>

  <!-- BALANCES -->
  <div class="balance-row">
    <div>
      <label>Opening Balance</label>
      <div class="input-group"><span>₹</span><input></div>
    </div>
    <div>
      <label>Closing Balance</label>
      <div class="input-group"><span>₹</span><input></div>
    </div>
    <div>
      <label>Total Profit</label>
      <div class="input-group"><span>₹</span><input readonly></div>
    </div>
    <div>
      <label>Total Loss</label>
      <div class="input-group"><span>₹</span><input readonly></div>
    </div>
  </div>

  <!-- MARKET -->
  <div id="instrumentMarketContainer"></div>

  <button class="btn">Save Entry</button>
</div>

</main>
<?php require_once __DIR__ . '/inc/footer.php'; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded',()=>{

const tradeDate=document.getElementById('trade_date');
const display=document.getElementById('trade_date_display');
const dayInput=document.getElementById('day');
const marketBox=document.getElementById('instrumentMarketContainer');

const days=['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
const months=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
const ord=n=>n+(["th","st","nd","rd"][(n%100-20)%10]||"th");

/* Date */
display.onclick=()=>tradeDate.showPicker?tradeDate.showPicker():tradeDate.focus();
tradeDate.onchange=()=>{
  const d=new Date(tradeDate.value);
  if(d.getDay()===0){ alert('Sunday not allowed'); tradeDate.value=''; return; }
  display.value=`${ord(d.getDate())} ${months[d.getMonth()]} ${d.getFullYear()}`;
  dayInput.value=days[d.getDay()];
  renderMarkets();
};

/* Multiselect */
const ms=document.getElementById('instrumentSelect');
const disp=document.getElementById('instrumentDisplay');
const opts=ms.querySelector('.multi-options');

disp.onclick=()=>opts.style.display=opts.style.display==='block'?'none':'block';

opts.querySelectorAll('input').forEach(cb=>{
  cb.onchange=()=>{
    disp.innerHTML='';
    const checked=[...opts.querySelectorAll('input:checked')];
    if(!checked.length){
      disp.innerHTML='<span class="multi-placeholder">Select instruments</span>';
      marketBox.innerHTML='';
      return;
    }
    checked.forEach(c=>{
      const t=document.createElement('span');
      t.className='tag';
      t.innerHTML=`${c.value}<i>×</i>`;
      t.onclick=()=>{c.checked=false;t.remove();renderMarkets();};
      disp.appendChild(t);
    });
    renderMarkets();
  };
});

function renderMarkets(){
  marketBox.innerHTML='';
  if(!tradeDate.value) return;
  opts.querySelectorAll('input:checked').forEach(cb=>{
    const card=document.createElement('div');
    card.className='market-card';
    card.innerHTML=`
      <strong>${cb.value} Market Summary</strong>
      <div class="market-grid">
        <input readonly>
        <input readonly>
        <input readonly>
        <input readonly>
      </div>`;
    marketBox.appendChild(card);
  });
}

});
</script>
</body>
</html>