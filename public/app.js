let currentAuthMode = 'login';
let dailyChartInstance = null;
let monthlyChartInstance = null;
let currentMonthTrades = []; // Holds active month's trades in memory

document.addEventListener('DOMContentLoaded', () => {
  const monthPicker = document.getElementById('monthPicker');
  monthPicker.value = new Date().toISOString().slice(0, 7);

  checkAuthSession();
  setupEventListeners();
});

function setupEventListeners() {
  document.getElementById('toggleAuthMode').addEventListener('click', toggleAuth);
  document.getElementById('authForm').addEventListener('submit', handleAuthSubmit);
  document.getElementById('logoutBtn').addEventListener('click', handleLogout);
  
  document.getElementById('monthPicker').addEventListener('change', fetchDashboardData);
  document.getElementById('openTradeModalBtn').addEventListener('click', openModal);
  document.getElementById('closeModalBtn').addEventListener('click', closeModal);
  document.getElementById('tradeForm').addEventListener('submit', handleAddTrade);
  document.getElementById('capitalForm').addEventListener('submit', handleSetCapital);

  ['tEntry', 'tExit', 'tQty', 'tLotSize'].forEach(id => {
    document.getElementById(id).addEventListener('input', updateLivePnLPreview);
  });
}

// --- TAB SWITCHING LOGIC ---
function switchTab(tabName) {
  const views = {
    dashboard: { id: 'viewDashboard', title: 'Dashboard', btn: 'navDashboard' },
    trades: { id: 'viewTrades', title: 'Trades History', btn: 'navTrades' },
    settings: { id: 'viewSettings', title: 'Settings', btn: 'navSettings' }
  };

  Object.keys(views).forEach(key => {
    const viewEl = document.getElementById(views[key].id);
    const btnEl = document.getElementById(views[key].btn);

    if (key === tabName) {
      viewEl.classList.remove('hidden');
      btnEl.className = 'nav-btn w-full flex items-center space-x-3 px-4 py-3 rounded-lg text-sm font-semibold bg-indigo-600 text-white transition';
      document.getElementById('pageTitle').innerText = views[key].title;
    } else {
      viewEl.classList.add('hidden');
      btnEl.className = 'nav-btn w-full flex items-center space-x-3 px-4 py-3 rounded-lg text-sm font-semibold text-gray-400 hover:bg-gray-700 hover:text-white transition';
    }
  });
}

function formatRupees(amount) {
  return '₹' + parseFloat(amount).toLocaleString('en-IN', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2
  });
}

function updateLivePnLPreview() {
  const entry = parseFloat(document.getElementById('tEntry').value) || 0;
  const exit = parseFloat(document.getElementById('tExit').value) || 0;
  const qty = parseInt(document.getElementById('tQty').value) || 0;
  const lotSize = parseInt(document.getElementById('tLotSize').value) || 1;

  if (entry > 0 && exit > 0 && qty > 0) {
    const pnl = (exit - entry) * qty * lotSize;
    const pnlPercent = ((exit - entry) / entry) * 100;

    const pnlEl = document.getElementById('previewPnL');
    const pnlPercentEl = document.getElementById('previewPnLPercent');

    pnlEl.innerText = formatRupees(pnl);
    pnlEl.className = `font-bold ${pnl >= 0 ? 'text-green-400' : 'text-red-400'}`;

    pnlPercentEl.innerText = `${pnlPercent.toFixed(2)}%`;
    pnlPercentEl.className = `font-bold ${pnlPercent >= 0 ? 'text-green-400' : 'text-red-400'}`;
  }
}

async function checkAuthSession() {
  const res = await fetch('/api/auth/me');
  const data = await res.json();
  if (data.loggedIn) showDashboard();
  else showAuth();
}

function showAuth() {
  document.getElementById('authSection').classList.remove('hidden');
  document.getElementById('appLayout').classList.add('hidden');
}

function showDashboard() {
  document.getElementById('authSection').classList.add('hidden');
  document.getElementById('appLayout').classList.remove('hidden');
  loadStrategies();
  fetchDashboardData();
}

function toggleAuth() {
  const title = document.getElementById('authTitle');
  const submitBtn = document.getElementById('authSubmitBtn');
  const toggleBtn = document.getElementById('toggleAuthMode');

  if (currentAuthMode === 'login') {
    currentAuthMode = 'register';
    title.innerText = 'Create Account';
    submitBtn.innerText = 'Register';
    toggleBtn.innerText = 'Already have an account? Login';
  } else {
    currentAuthMode = 'login';
    title.innerText = 'Trading Journal Login';
    submitBtn.innerText = 'Login';
    toggleBtn.innerText = 'Need an account? Register';
  }
}

async function handleAuthSubmit(e) {
  e.preventDefault();
  const email = document.getElementById('authEmail').value;
  const password = document.getElementById('authPassword').value;

  const endpoint = currentAuthMode === 'register' ? '/api/auth/register' : '/api/auth/login';

  const res = await fetch(endpoint, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ email, password })
  });

  const data = await res.json();
  if (data.success) showDashboard();
  else alert(data.error || 'Authentication failed');
}

async function handleLogout() {
  await fetch('/api/auth/logout', { method: 'POST' });
  showAuth();
}

async function fetchDashboardData() {
  const selectedMonth = document.getElementById('monthPicker').value;
  const res = await fetch(`/api/dashboard?month=${selectedMonth}`);
  const data = await res.json();

  if (!data.isCapitalSet) {
    document.getElementById('capitalModalMonth').innerText = selectedMonth;
    document.getElementById('capitalModal').classList.remove('hidden');
    document.getElementById('capitalInput').value = '';
  } else {
    document.getElementById('capitalModal').classList.add('hidden');
  }

  document.getElementById('cardCapital').innerText = formatRupees(data.monthlyCapital);
  
  const pnlEl = document.getElementById('cardPnL');
  pnlEl.innerText = formatRupees(data.monthPnL);
  pnlEl.className = `text-2xl font-bold mt-1 ${data.monthPnL >= 0 ? 'text-green-400' : 'text-red-400'}`;

  document.getElementById('cardPnLPercent').innerText = `${data.monthPnLPercent}% return this month`;

  const todayEl = document.getElementById('cardTodayPnL');
  todayEl.innerText = formatRupees(data.todayPnL);
  todayEl.className = `text-2xl font-bold mt-1 ${data.todayPnL >= 0 ? 'text-green-400' : 'text-red-400'}`;

  document.getElementById('cardStreak').innerText = `${data.winningStreak} Trades`;
  document.getElementById('cardStrategy').innerText = `Best Strategy: ${data.bestStrategy}`;

  renderTradeSpotlight('bestTradeBox', data.bestTrade);
  renderTradeSpotlight('worstTradeBox', data.worstTrade);

  renderDailyChart(data.monthTrades);
  renderMonthlyChart(data.yearlyPnL);
  renderTradesTable(data.monthTrades);
}

// RENDER TRADES TABLE WITH VIEW & DELETE ICONS
function renderTradesTable(trades) {
  currentMonthTrades = trades || [];
  const tbody = document.getElementById('tradesTableBody');
  document.getElementById('tradeCountBadge').innerText = `${currentMonthTrades.length} Trades`;

  if (!currentMonthTrades || currentMonthTrades.length === 0) {
    tbody.innerHTML = '<tr><td colspan="10" class="p-4 text-center text-gray-500">No trades recorded for this month.</td></tr>';
    return;
  }

  tbody.innerHTML = currentMonthTrades.map(t => {
    const entry = parseFloat(t.entry_price);
    const exit = parseFloat(t.exit_price);
    const pnl = parseFloat(t.pnl);
    const pnlPercent = ((exit - entry) / entry) * 100;
    const lotSize = t.lot_size || 1;

    // --- DATE FORMATTING (01 Aug 2026) ---
    const dateObj = new Date(t.trade_date);
    const formattedDate = dateObj.toLocaleDateString('en-GB', {
      day: '2-digit',
      month: 'short',
      year: 'numeric'
    });

    return `
      <tr class="hover:bg-gray-750 transition border-b border-gray-700/50">
        <td class="p-3 text-xs text-gray-400 font-medium">${formattedDate}</td>
        <td class="p-3 font-semibold text-white">${t.symbol}</td>
        <td class="p-3">
          <span class="px-2 py-0.5 text-xs rounded ${t.instrument_type === 'Call' ? 'bg-green-900 text-green-300' : t.instrument_type === 'Put' ? 'bg-red-900 text-red-300' : 'bg-blue-900 text-blue-300'}">
            ${t.instrument_type}
          </span>
        </td>
        <td class="p-3 text-xs text-gray-400">${t.strategy_name || 'N/A'}</td>
        <td class="p-3">${formatRupees(entry)}</td>
        <td class="p-3">${formatRupees(exit)}</td>
        <td class="p-3 text-xs text-gray-300">${t.quantity} L (${lotSize}/L)</td>
        <td class="p-3 font-bold ${pnl >= 0 ? 'text-green-400' : 'text-red-400'}">${formatRupees(pnl)}</td>
        <td class="p-3 font-bold ${pnlPercent >= 0 ? 'text-green-400' : 'text-red-400'}">${pnlPercent.toFixed(2)}%</td>
        <td class="p-3 text-center space-x-2">
          <button onclick="viewTradeDetails(${t.id})" title="View Details" class="p-1.5 bg-indigo-600/20 text-indigo-400 hover:bg-indigo-600 hover:text-white rounded transition">
            <i class="fa-solid fa-eye text-xs"></i>
          </button>
          <button onclick="deleteTrade(${t.id})" title="Delete Trade" class="p-1.5 bg-red-600/20 text-red-400 hover:bg-red-600 hover:text-white rounded transition">
            <i class="fa-solid fa-trash-can text-xs"></i>
          </button>
        </td>
      </tr>
    `;
  }).join('');
}

// VIEW TRADE DETAILS MODAL HANDLER
function viewTradeDetails(id) {
  const trade = currentMonthTrades.find(t => t.id === id);
  if (!trade) return;

  const entry = parseFloat(trade.entry_price);
  const exit = parseFloat(trade.exit_price);
  const pnl = parseFloat(trade.pnl);
  const pnlPercent = ((exit - entry) / entry) * 100;
  const lotSize = trade.lot_size || 1;

  // --- DATE FORMATTING (e.g., 01 Aug 2026) ---
  const dateObj = new Date(trade.trade_date);
  const formattedDate = dateObj.toLocaleDateString('en-GB', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
    timeZone: 'UTC' // Prevents local timezone conversion from shifting the day
  });

  document.getElementById('viewSymbol').innerText = trade.symbol;
  document.getElementById('viewTradeDate').innerText = formattedDate; // Updated here
  
  const badge = document.getElementById('viewTypeBadge');
  badge.innerText = trade.instrument_type;
  badge.className = `px-3 py-1 text-xs rounded-full font-semibold ${trade.instrument_type === 'Call' ? 'bg-green-900 text-green-300' : trade.instrument_type === 'Put' ? 'bg-red-900 text-red-300' : 'bg-blue-900 text-blue-300'}`;

  document.getElementById('viewEntry').innerText = formatRupees(entry);
  document.getElementById('viewExit').innerText = formatRupees(exit);
  document.getElementById('viewQty').innerText = `${trade.quantity} Lots (${lotSize} Qty/Lot)`;
  document.getElementById('viewStrategy').innerText = trade.strategy_name || 'N/A';

  const pnlEl = document.getElementById('viewPnL');
  pnlEl.innerText = formatRupees(pnl);
  pnlEl.className = `text-xl font-bold ${pnl >= 0 ? 'text-green-400' : 'text-red-400'}`;

  const pnlPercentEl = document.getElementById('viewPnLPercent');
  pnlPercentEl.innerText = `${pnlPercent.toFixed(2)}%`;
  pnlPercentEl.className = `text-lg font-bold ${pnlPercent >= 0 ? 'text-green-400' : 'text-red-400'}`;

  // Set Notes / Placeholder Style
  const notesEl = document.getElementById('viewNotes');
  if (trade.notes && trade.notes.trim() !== '') {
    notesEl.innerText = trade.notes;
    notesEl.className = "bg-gray-900/80 p-3.5 rounded-lg border border-gray-700 text-sm text-gray-300 min-h-[80px] whitespace-pre-wrap";
  } else {
    notesEl.innerText = 'No notes entered for this trade.';
    notesEl.className = "bg-gray-900/80 p-3.5 rounded-lg border border-gray-700 text-sm text-gray-500 italic min-h-[80px] whitespace-pre-wrap";
  }
    
  document.getElementById('viewTradeModal').classList.remove('hidden');
}

function closeViewTradeModal() {
  document.getElementById('viewTradeModal').classList.add('hidden');
}

async function deleteTrade(id) {
  if (confirm("Are you sure you want to delete this trade?")) {
    const res = await fetch(`/api/trades/${id}`, { method: 'DELETE' });
    if (res.ok) fetchDashboardData();
  }
}

async function handleSetCapital(e) {
  e.preventDefault();
  const selectedMonth = document.getElementById('monthPicker').value;
  const capital = document.getElementById('capitalInput').value;

  const res = await fetch('/api/capital', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ yearMonth: selectedMonth, capital })
  });

  if (res.ok) {
    document.getElementById('capitalModal').classList.add('hidden');
    fetchDashboardData();
  } else {
    const data = await res.json();
    alert(data.error);
  }
}

function renderTradeSpotlight(elementId, trade) {
  const container = document.getElementById(elementId);
  if (!trade) {
    container.innerHTML = '<span class="text-gray-500">No trades logged</span>';
    return;
  }
  container.innerHTML = `
    <div class="font-bold">${trade.symbol} (${trade.instrument_type})</div>
    <div>P&L: <span class="${trade.pnl >= 0 ? 'text-green-400' : 'text-red-400'}">${formatRupees(trade.pnl)}</span></div>
    <div class="text-xs text-gray-400">Strategy: ${trade.strategy_name || 'N/A'} | Entry: ${formatRupees(trade.entry_price)} | Exit: ${formatRupees(trade.exit_price)}</div>
  `;
}

function renderDailyChart(trades) {
  const ctx = document.getElementById('dailyChart').getContext('2d');
  const labels = trades.map(t => new Date(t.trade_date).getDate()).reverse();
  const data = trades.map(t => t.pnl).reverse();

  if (dailyChartInstance) dailyChartInstance.destroy();

  dailyChartInstance = new Chart(ctx, {
    type: 'line',
    data: {
      labels: labels,
      datasets: [{
        label: 'Trade P&L (₹)',
        data: data,
        borderColor: '#818cf8',
        backgroundColor: 'rgba(129, 140, 248, 0.2)',
        fill: true,
        tension: 0.3
      }]
    },
    options: { responsive: true, plugins: { legend: { display: false } } }
  });
}

function renderMonthlyChart(yearlyData) {
  const ctx = document.getElementById('monthlyChart').getContext('2d');
  const labels = yearlyData.map(d => d.month);
  const data = yearlyData.map(d => d.pnl);

  if (monthlyChartInstance) monthlyChartInstance.destroy();

  monthlyChartInstance = new Chart(ctx, {
    type: 'bar',
    data: {
      labels: labels,
      datasets: [{
        label: 'Monthly P&L (₹)',
        data: data,
        backgroundColor: data.map(v => v >= 0 ? '#34d399' : '#f87171')
      }]
    },
    options: { responsive: true, plugins: { legend: { display: false } } }
  });
}

async function loadStrategies() {
  const res = await fetch('/api/strategies');
  const strategies = await res.json();
  const select = document.getElementById('tStrategy');
  select.innerHTML = strategies.map(s => `<option value="${s.id}">${s.name}</option>`).join('');
}

function openModal() {
  document.getElementById('tDate').value = new Date().toISOString().slice(0, 10);
  document.getElementById('tNotes').value = ''; // Reset notes field
  document.getElementById('tradeModal').classList.remove('hidden');
}

function closeModal() {
  document.getElementById('tradeModal').classList.add('hidden');
}

// INCLUDES NOTES IN THE PAYLOAD
async function handleAddTrade(e) {
  e.preventDefault();
  const payload = {
    symbol: document.getElementById('tSymbol').value,
    instrument_type: document.getElementById('tType').value,
    expiry_date: document.getElementById('tExpiry').value,
    entry_price: document.getElementById('tEntry').value,
    exit_price: document.getElementById('tExit').value,
    quantity: document.getElementById('tQty').value,
    lot_size: document.getElementById('tLotSize').value,
    strategy_id: document.getElementById('tStrategy').value,
    trade_date: document.getElementById('tDate').value,
    notes: document.getElementById('tNotes').value // <--- ADDED NOTES HERE
  };

  const res = await fetch('/api/trades', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload)
  });

  if (res.ok) {
    closeModal();
    fetchDashboardData();
  } else {
    alert('Failed to record trade.');
  }
}