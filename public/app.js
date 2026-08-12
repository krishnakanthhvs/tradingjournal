let currentAuthMode = 'login';
let dailyChartInstance = null;
let monthlyChartInstance = null;
let currentMonthTrades = []; // Holds active month's trades in memory

document.addEventListener('DOMContentLoaded', () => {
  const monthPicker = document.getElementById('monthPicker');
  if (monthPicker) {
    monthPicker.value = new Date().toISOString().slice(0, 7);
  }

  checkAuthSession();
  setupEventListeners();
});

function setupEventListeners() {
  document.getElementById('toggleAuthMode')?.addEventListener('click', toggleAuth);
  document.getElementById('authForm')?.addEventListener('submit', handleAuthSubmit);
  document.getElementById('logoutBtn')?.addEventListener('click', handleLogout);
  
  document.getElementById('monthPicker')?.addEventListener('change', fetchDashboardData);
  document.getElementById('openTradeModalBtn')?.addEventListener('click', openModal);
  document.getElementById('closeModalBtn')?.addEventListener('click', closeModal);
  document.getElementById('tradeForm')?.addEventListener('submit', handleAddTrade);
  document.getElementById('capitalForm')?.addEventListener('submit', handleSetCapital);

  // Bind Strategy Form Submit
  document.getElementById('addStrategyForm')?.addEventListener('submit', handleAddStrategy);

  ['tEntry', 'tExit', 'tQty', 'tLotSize'].forEach(id => {
    document.getElementById(id)?.addEventListener('input', updateLivePnLPreview);
  });
}

// --- HELPER TO GET LOCAL YYYY-MM-DD DATE ---
function getLocalDateKey(dateInput) {
  if (!dateInput) return '';
  const d = new Date(dateInput);
  if (isNaN(d.getTime())) return '';
  const year = d.getFullYear();
  const month = String(d.getMonth() + 1).padStart(2, '0');
  const day = String(d.getDate()).padStart(2, '0');
  return `${year}-${month}-${day}`;
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
      if (viewEl) viewEl.classList.remove('hidden');
      if (btnEl) btnEl.className = 'nav-btn w-full flex items-center space-x-3 px-4 py-3 rounded-lg text-sm font-semibold bg-indigo-600 text-white transition';
      document.getElementById('pageTitle').innerText = views[key].title;
    } else {
      if (viewEl) viewEl.classList.add('hidden');
      if (btnEl) btnEl.className = 'nav-btn w-full flex items-center space-x-3 px-4 py-3 rounded-lg text-sm font-semibold text-gray-400 hover:bg-gray-800 hover:text-white transition';
    }
  });

  if (tabName === 'settings') {
    loadStrategies();
  }
}

function formatRupees(amount) {
  return '₹' + parseFloat(amount || 0).toLocaleString('en-IN', {
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

    if (pnlEl) {
      pnlEl.innerText = formatRupees(pnl);
      pnlEl.className = `font-bold ${pnl >= 0 ? 'text-green-400' : 'text-red-400'}`;
    }

    if (pnlPercentEl) {
      pnlPercentEl.innerText = `${pnlPercent.toFixed(2)}%`;
      pnlPercentEl.className = `font-bold ${pnlPercent >= 0 ? 'text-green-400' : 'text-red-400'}`;
    }
  }
}

// --- AUTHENTICATION & SESSION MANAGEMENT ---
async function checkAuthSession() {
  try {
    const res = await fetch('/api/auth/me');
    const data = await res.json();
    if (data.loggedIn) {
      const email = data.user?.email || data.email || 'User';
      showDashboard(email);
    } else {
      showAuth();
    }
  } catch (err) {
    showAuth();
  }
}

function showAuth() {
  document.getElementById('authSection')?.classList.remove('hidden');
  document.getElementById('appLayout')?.classList.add('hidden');
}

function showDashboard(userEmail = '') {
  document.getElementById('authSection')?.classList.add('hidden');
  document.getElementById('appLayout')?.classList.remove('hidden');

  // Set logged in user email above Logout button
  if (userEmail) {
    const emailEl = document.getElementById('userEmailDisplay');
    if (emailEl) emailEl.innerText = userEmail;
  }

  loadStrategies();
  fetchDashboardData();
}

function toggleAuth() {
  const title = document.getElementById('authTitle');
  const submitBtn = document.getElementById('authSubmitBtn');
  const toggleBtn = document.getElementById('toggleAuthMode');

  if (currentAuthMode === 'login') {
    currentAuthMode = 'register';
    if (title) title.innerText = 'Create Account';
    if (submitBtn) submitBtn.innerText = 'Register';
    if (toggleBtn) toggleBtn.innerText = 'Already have an account? Login';
  } else {
    currentAuthMode = 'login';
    if (title) title.innerText = 'Trading Journal Login';
    if (submitBtn) submitBtn.innerText = 'Login';
    if (toggleBtn) toggleBtn.innerText = 'Need an account? Register';
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
  if (data.success) {
    const loggedInEmail = data.user?.email || email;
    showDashboard(loggedInEmail);
  } else {
    alert(data.error || 'Authentication failed');
  }
}

async function handleLogout() {
  await fetch('/api/auth/logout', { method: 'POST' });
  showAuth();
}

// --- FETCH DASHBOARD DATA ---
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

  renderSmallCalendarWidget(data.monthTrades || []);
  renderDailyChart(data.monthTrades || []);
  renderMonthlyChart(data.yearlyPnL || []);
  renderTradesTable(data.monthTrades || []);
}

// RENDER SMALL SIDE-BY-SIDE CALENDAR WIDGET
function renderSmallCalendarWidget(trades = []) {
  const grid = document.getElementById('smallCalendarGrid');
  const monthPickerVal = document.getElementById('monthPicker')?.value || new Date().toISOString().slice(0, 7);
  if (!grid) return;

  grid.innerHTML = '';

  const [year, month] = monthPickerVal.split('-').map(Number);

  const firstDayIndex = new Date(year, month - 1, 1).getDay();
  const totalDays = new Date(year, month, 0).getDate();

  const dailyPnLMap = {};
  let totalMonthPnL = 0;

  trades.forEach(trade => {
    if (trade.trade_date) {
      const dateKey = getLocalDateKey(trade.trade_date);
      if (dateKey) {
        const pnl = parseFloat(trade.pnl || 0);
        dailyPnLMap[dateKey] = (dailyPnLMap[dateKey] || 0) + pnl;
        totalMonthPnL += pnl;
      }
    }
  });

  const totalPnLEl = document.getElementById('smallCalendarTotalPnL');
  if (totalPnLEl) {
    const sign = totalMonthPnL > 0 ? '+' : '';
    totalPnLEl.innerText = `${sign}${formatRupees(totalMonthPnL)}`;
    
    if (totalMonthPnL > 0) {
      totalPnLEl.className = 'text-xs font-bold font-mono px-1.5 py-0.5 rounded bg-emerald-950/80 border border-emerald-800/80 text-emerald-400';
    } else if (totalMonthPnL < 0) {
      totalPnLEl.className = 'text-xs font-bold font-mono px-1.5 py-0.5 rounded bg-rose-950/80 border border-rose-800/80 text-rose-400';
    } else {
      totalPnLEl.className = 'text-xs font-bold font-mono px-1.5 py-0.5 rounded bg-gray-800 border border-gray-700 text-gray-400';
    }
  }

  for (let i = 0; i < firstDayIndex; i++) {
    const emptyCell = document.createElement('div');
    emptyCell.className = 'h-8 bg-gray-950/30 border border-gray-800/20 rounded';
    grid.appendChild(emptyCell);
  }

  for (let day = 1; day <= totalDays; day++) {
    const monthStr = String(month).padStart(2, '0');
    const dayStr = String(day).padStart(2, '0');
    const dateKey = `${year}-${monthStr}-${dayStr}`;

    const hasTrades = dailyPnLMap.hasOwnProperty(dateKey);
    const pnlVal = hasTrades ? dailyPnLMap[dateKey] : null;

    let bgStyle = 'bg-gray-800/40 border-gray-700/30 text-gray-500';
    let pnlText = '';

    if (hasTrades) {
      if (pnlVal > 0) {
        bgStyle = 'bg-[#0a2e1d] border-[#145234] text-green-400 hover:bg-[#0e3b26] cursor-pointer shadow-sm';
        pnlText = `+${formatCompactPnL(pnlVal)}`;
      } else if (pnlVal < 0) {
        bgStyle = 'bg-[#3b0a0a] border-[#5c1414] text-red-400 hover:bg-[#4a0d0d] cursor-pointer shadow-sm';
        pnlText = formatCompactPnL(pnlVal);
      } else {
        bgStyle = 'bg-gray-800 border-gray-600 text-gray-300 hover:bg-gray-700 cursor-pointer';
        pnlText = '₹0';
      }
    }

    const cell = document.createElement('div');
    cell.className = `h-8 p-0.5 border rounded flex flex-col justify-between transition ${bgStyle}`;
    
    cell.onclick = () => {
      openDayTradesModal(dateKey, trades);
    };

    cell.innerHTML = `
      <div class="text-[9px] font-mono font-bold leading-none text-center pt-0.5 truncate">${pnlText}</div>
      <div class="text-[9px] font-semibold text-right pr-0.5 text-white/80 leading-none">${day}</div>
    `;

    grid.appendChild(cell);
  }
}

function formatCompactPnL(num) {
  const abs = Math.abs(num);
  if (abs >= 1000) {
    return `${(num / 1000).toFixed(1)}K`;
  }
  return `${Math.round(num)}`;
}

function navigateMonth(offset) {
  const picker = document.getElementById('monthPicker');
  const [y, m] = picker.value.split('-').map(Number);
  const d = new Date(y, m - 1 + offset, 1);
  picker.value = d.toISOString().slice(0, 7);
  fetchDashboardData();
}

function renderTradesTable(trades) {
  currentMonthTrades = trades || [];
  const tbody = document.getElementById('tradesTableBody');
  const badge = document.getElementById('tradeCountBadge');
  if (badge) badge.innerText = `${currentMonthTrades.length} Trades`;

  if (!tbody) return;

  if (!currentMonthTrades || currentMonthTrades.length === 0) {
    tbody.innerHTML = '<tr><td colspan="12" class="p-4 text-center text-gray-500">No trades recorded for this month.</td></tr>';
    return;
  }

  tbody.innerHTML = currentMonthTrades.map(t => {
    const entry = parseFloat(t.entry_price || 0);
    const exit = parseFloat(t.exit_price || 0);
    const mktClose = t.market_close_strike ? parseFloat(t.market_close_strike) : null;
    const pnl = parseFloat(t.pnl || 0);
    const pnlPercent = entry > 0 ? ((exit - entry) / entry) * 100 : 0;
    const lotSize = t.lot_size || 1;

    const formattedDate = new Date(t.trade_date).toLocaleDateString('en-GB', {
      day: '2-digit',
      month: 'short',
      year: 'numeric'
    });

    const entryTime = t.entry_time ? t.entry_time.slice(0, 5) : '--:--';
    const exitTime = t.exit_time ? t.exit_time.slice(0, 5) : '--:--';

    return `
      <tr class="hover:bg-gray-750 transition border-b border-gray-700/50">
        <td class="p-3 text-xs text-gray-400 font-medium">${formattedDate}</td>
        <td class="p-3 font-semibold text-white">${t.symbol}</td>
        <td class="p-3">
          <span class="px-2 py-0.5 text-xs rounded ${t.instrument_type === 'Call' ? 'bg-indigo-950 text-indigo-300 border border-indigo-800' : t.instrument_type === 'Put' ? 'bg-amber-950 text-amber-300 border border-amber-800' : 'bg-blue-900 text-blue-300'}">
            ${t.instrument_type}
          </span>
        </td>
        <td class="p-3 text-xs font-mono text-indigo-300">${entryTime} / ${exitTime}</td>
        <td class="p-3 text-xs text-gray-400">${t.strategy_name || 'N/A'}</td>
        <td class="p-3">${formatRupees(entry)}</td>
        <td class="p-3">${formatRupees(exit)}</td>
        <td class="p-3 text-amber-400 font-medium">${mktClose !== null ? formatRupees(mktClose) : '-'}</td>
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

// --- VIEW COMPLETE TRADE DETAILS ---
function viewTradeDetails(id) {
  const trade = currentMonthTrades.find(t => t.id === id);
  if (!trade) return;

  const entry = parseFloat(trade.entry_price || 0);
  const exit = parseFloat(trade.exit_price || 0);
  const mktClose = trade.market_close_strike ? parseFloat(trade.market_close_strike) : null;
  const pnl = parseFloat(trade.pnl || 0);
  const pnlPercent = entry > 0 ? ((exit - entry) / entry) * 100 : 0;
  const lotSize = trade.lot_size || 1;

  const formatDate = (dStr) => {
    if (!dStr) return '---';
    const d = new Date(dStr);
    return isNaN(d.getTime()) ? '---' : d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
  };

  document.getElementById('viewSymbol').innerText = trade.symbol || 'N/A';
  
  const badge = document.getElementById('viewTypeBadge');
  badge.innerText = trade.instrument_type || 'N/A';
  badge.className = `px-3 py-1 text-xs rounded-full font-semibold ${
    trade.instrument_type === 'Call' ? 'bg-indigo-950 text-indigo-300 border border-indigo-800' :
    trade.instrument_type === 'Put' ? 'bg-amber-950 text-amber-300 border border-amber-800' :
    'bg-gray-800 text-gray-300'
  }`;

  document.getElementById('viewTradeDate').innerText = formatDate(trade.trade_date);
  document.getElementById('viewExpiryDate').innerText = formatDate(trade.expiry_date);
  document.getElementById('viewStrategy').innerText = trade.strategy_name || 'N/A';

  document.getElementById('viewEntry').innerText = formatRupees(entry);
  document.getElementById('viewExit').innerText = formatRupees(exit);
  document.getElementById('viewMarketClose').innerText = mktClose !== null ? formatRupees(mktClose) : '---';

  document.getElementById('viewQty').innerText = `${trade.quantity} Lot(s) [${lotSize} Qty]`;
  document.getElementById('viewEntryTime').innerText = trade.entry_time || '---';
  document.getElementById('viewExitTime').innerText = trade.exit_time || '---';

  const pnlEl = document.getElementById('viewPnL');
  pnlEl.innerText = `${pnl >= 0 ? '+' : ''}${formatRupees(pnl)}`;
  pnlEl.className = `text-xl font-bold mt-0.5 ${pnl >= 0 ? 'text-green-400' : 'text-red-400'}`;

  const pnlPercentEl = document.getElementById('viewPnLPercent');
  pnlPercentEl.innerText = `${pnlPercent >= 0 ? '+' : ''}${pnlPercent.toFixed(2)}%`;
  pnlPercentEl.className = `text-lg font-bold mt-0.5 ${pnlPercent >= 0 ? 'text-green-400' : 'text-red-400'}`;

  const notesEl = document.getElementById('viewNotes');
  if (trade.notes && trade.notes.trim() !== '') {
    notesEl.innerText = trade.notes;
    notesEl.className = "bg-gray-900/80 p-3.5 rounded-lg border border-gray-700 text-sm text-gray-300 min-h-[80px] whitespace-pre-wrap";
  } else {
    notesEl.innerText = 'No notes entered for this trade.';
    notesEl.className = "bg-gray-900/80 p-3.5 rounded-lg border border-gray-700 text-sm text-gray-500 italic min-h-[80px] whitespace-pre-wrap";
  }

  document.getElementById('viewTradeModal')?.classList.remove('hidden');
}

function closeViewTradeModal() {
  document.getElementById('viewTradeModal')?.classList.add('hidden');
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
  if (!container) return;
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

function renderDailyChart(trades = []) {
  const chartCanvas = document.getElementById('dailyPnLChart');
  if (!chartCanvas) return;

  const aggregatedDailyPnL = {};

  trades.forEach(trade => {
    if (!trade.trade_date) return;
    const dateKey = getLocalDateKey(trade.trade_date);
    if (!dateKey) return;

    const pnl = parseFloat(trade.pnl || 0);
    aggregatedDailyPnL[dateKey] = (aggregatedDailyPnL[dateKey] || 0) + pnl;
  });

  const sortedDates = Object.keys(aggregatedDailyPnL).sort();

  const labels = sortedDates.map(dateStr => parseInt(dateStr.split('-')[2], 10));
  const dataValues = sortedDates.map(dateStr => aggregatedDailyPnL[dateStr]);

  if (window.dailyChartInstance && typeof window.dailyChartInstance.destroy === 'function') {
    window.dailyChartInstance.destroy();
  }

  const ctx = chartCanvas.getContext('2d');
  window.dailyChartInstance = new Chart(ctx, {
    type: 'line',
    data: {
      labels: labels,
      datasets: [{
        label: 'Daily P&L',
        data: dataValues,
        borderColor: '#6366f1',
        backgroundColor: 'rgba(99, 102, 241, 0.15)',
        fill: true,
        tension: 0.35,
        pointRadius: 4,
        pointBackgroundColor: '#818cf8'
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: {
        x: { grid: { color: 'rgba(255, 255, 255, 0.05)' }, ticks: { color: '#9ca3af' } },
        y: { grid: { color: 'rgba(255, 255, 255, 0.05)' }, ticks: { color: '#9ca3af' } }
      }
    }
  });
}

function renderMonthlyChart(yearlyData) {
  const chartCanvas = document.getElementById('monthlyChart');
  if (!chartCanvas) return;

  const ctx = chartCanvas.getContext('2d');
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

// --- LOAD & DISPLAY STRATEGIES IN SETTINGS AND DROPDOWN ---
async function loadStrategies() {
  try {
    const res = await fetch('/api/strategies');
    const strategies = await res.json();

    const selectEl = document.getElementById('tStrategy');
    if (selectEl) {
      selectEl.innerHTML = strategies.length === 0
        ? `<option value="">No strategies available (Add in Settings)</option>`
        : strategies.map(s => `<option value="${s.id}">${s.name}</option>`).join('');
    }

    const tbody = document.getElementById('strategiesTableBody');
    const badge = document.getElementById('strategyCountBadge');

    if (badge) {
      badge.innerText = `${strategies.length} ${strategies.length === 1 ? 'Strategy' : 'Strategies'}`;
    }

    if (tbody) {
      if (strategies.length === 0) {
        tbody.innerHTML = `<tr><td colspan="4" class="p-4 text-center text-gray-500">No strategies added yet.</td></tr>`;
      } else {
        tbody.innerHTML = strategies.map((s, idx) => `
          <tr class="hover:bg-gray-800/40 transition">
            <td class="p-3 font-mono text-gray-500">${idx + 1}</td>
            <td class="p-3 font-bold text-indigo-400">${s.name}</td>
            <td class="p-3 text-gray-400 text-xs whitespace-pre-wrap">${s.description || '<span class="text-gray-600 italic">No explanation provided</span>'}</td>
            <td class="p-3 text-right">
              <button onclick="deleteStrategy(${s.id})" title="Delete Strategy" class="p-1.5 bg-red-600/20 text-red-400 hover:bg-red-600 hover:text-white rounded-lg transition">
                <i class="fa-solid fa-trash-can text-xs"></i>
              </button>
            </td>
          </tr>
        `).join('');
      }
    }
  } catch (err) {
    console.error('Failed to load strategies:', err);
  }
}

async function handleAddStrategy(e) {
  e.preventDefault();
  const nameInput = document.getElementById('newStrategySettingsName');
  const descInput = document.getElementById('newStrategySettingsDesc');

  const name = nameInput?.value.trim();
  const description = descInput?.value.trim();

  if (!name) return;

  try {
    const res = await fetch('/api/strategies', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ 
        name: name, 
        description: description || null 
      })
    });

    if (res.ok) {
      nameInput.value = '';
      if (descInput) descInput.value = '';
      await loadStrategies();
    } else {
      const err = await res.json();
      alert(err.error || 'Failed to add strategy.');
    }
  } catch (error) {
    console.error('Error adding strategy:', error);
    alert('An error occurred while saving the strategy.');
  }
}

async function deleteStrategy(id) {
  if (!confirm('Are you sure you want to delete this strategy?')) return;

  try {
    const res = await fetch(`/api/strategies/${id}`, { method: 'DELETE' });
    if (res.ok) {
      await loadStrategies();
    } else {
      const err = await res.json();
      alert(err.error || 'Failed to delete strategy.');
    }
  } catch (error) {
    console.error('Error deleting strategy:', error);
  }
}

function openModal() {
  loadStrategies();

  const tDate = document.getElementById('tDate');
  if (tDate) tDate.value = new Date().toISOString().slice(0, 10);

  const tNotes = document.getElementById('tNotes');
  if (tNotes) tNotes.value = '';

  document.getElementById('tradeModal')?.classList.remove('hidden');
}

function closeModal() {
  document.getElementById('tradeModal')?.classList.add('hidden');
}

async function handleAddTrade(e) {
  e.preventDefault();

  const tradeData = {
    symbol: document.getElementById('tSymbol').value,
    instrument_type: document.getElementById('tType').value,
    expiry_date: document.getElementById('tExpiry').value || null,
    entry_price: parseFloat(document.getElementById('tEntry').value),
    exit_price: parseFloat(document.getElementById('tExit').value),
    quantity: parseInt(document.getElementById('tQty').value),
    lot_size: parseInt(document.getElementById('tLotSize').value) || 1,
    strategy_id: document.getElementById('tStrategy').value || null,
    trade_date: document.getElementById('tDate').value,
    entry_time: document.getElementById('tEntryTime')?.value || null,
    exit_time: document.getElementById('tExitTime')?.value || null,
    market_close_strike: document.getElementById('tMarketCloseStrike')?.value 
      ? parseFloat(document.getElementById('tMarketCloseStrike').value) 
      : null,
    notes: document.getElementById('tNotes')?.value || null
  };

  const res = await fetch('/api/trades', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(tradeData)
  });

  if (res.ok) {
    closeModal();
    fetchDashboardData();
  } else {
    alert('Failed to save trade');
  }
}

// OPEN DAY TRADES MODAL HANDLER
function openDayTradesModal(dateKey, trades = []) {
  const modal = document.getElementById('dayTradesModal');
  const titleEl = document.getElementById('dayTradesDate');
  const pnlEl = document.getElementById('dayTradesTotalPnL');
  const listEl = document.getElementById('dayTradesList');

  if (!modal || !listEl) return;

  const dayTrades = trades.filter(t => {
    if (!t.trade_date) return false;
    return getLocalDateKey(t.trade_date) === dateKey;
  });

  const [y, m, d] = dateKey.split('-').map(Number);
  const formattedDate = new Date(y, m - 1, d).toLocaleDateString('en-IN', {
    day: '2-digit',
    month: 'short',
    year: 'numeric'
  });

  if (titleEl) titleEl.innerText = `Trades for ${formattedDate}`;

  const totalPnL = dayTrades.reduce((acc, t) => acc + parseFloat(t.pnl || 0), 0);
  const pnlColorClass = totalPnL >= 0 ? 'text-green-400' : 'text-red-400';
  const pnlSign = totalPnL > 0 ? '+' : '';

  if (pnlEl) {
    pnlEl.innerHTML = `Total P&L: <span class="font-bold ${pnlColorClass}">${pnlSign}${formatRupees(totalPnL)}</span> (${dayTrades.length} ${dayTrades.length === 1 ? 'Trade' : 'Trades'})`;
  }

  listEl.innerHTML = '';

  if (dayTrades.length === 0) {
    listEl.innerHTML = `
      <div class="text-center py-8 text-gray-500 text-sm">
        No trades recorded for ${formattedDate}.
      </div>
    `;
  } else {
    dayTrades.forEach((trade) => {
      const pnl = parseFloat(trade.pnl || 0);
      const isProfit = pnl >= 0;

      const tradeCard = document.createElement('div');
      tradeCard.className = 'bg-gray-900/80 p-3.5 rounded-lg border border-gray-700/60 flex justify-between items-center';

      tradeCard.innerHTML = `
        <div>
          <div class="flex items-center space-x-2">
            <span class="font-bold text-white text-sm">${trade.symbol}</span>
            <span class="text-[10px] px-2 py-0.5 rounded font-semibold uppercase ${
              trade.instrument_type === 'Call' || trade.type === 'Call'
                ? 'bg-indigo-950 text-indigo-300 border border-indigo-800'
                : 'bg-amber-950 text-amber-300 border border-amber-800'
            }">${trade.instrument_type || trade.type || 'N/A'}</span>
          </div>
          <div class="text-xs text-gray-400 mt-1">
            Strategy: <span class="text-gray-200 font-medium">${trade.strategy_name || trade.strategy || 'N/A'}</span>
          </div>
          <div class="text-[11px] text-gray-400 mt-0.5">
            Entry: ₹${trade.entry_price || 0} | Exit: ₹${trade.exit_price || 0} | Qty: ${trade.quantity || 0}
          </div>
          ${trade.notes ? `<div class="text-[11px] italic text-gray-400 mt-1">Note: "${trade.notes}"</div>` : ''}
        </div>

        <div class="text-right">
          <div class="font-bold text-sm ${isProfit ? 'text-green-400' : 'text-red-400'}">
            ${isProfit ? '+' : ''}${formatRupees(pnl)}
          </div>
        </div>
      `;

      listEl.appendChild(tradeCard);
    });
  }

  modal.classList.remove('hidden');
}

function closeDayTradesModal() {
  const modal = document.getElementById('dayTradesModal');
  if (modal) modal.classList.add('hidden');
}

window.addEventListener('click', (e) => {
  const modal = document.getElementById('dayTradesModal');
  if (e.target === modal) {
    closeDayTradesModal();
  }
});