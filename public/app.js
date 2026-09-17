'use strict';
const $ = (s) => document.querySelector(s),
  $$ = (s) => [...document.querySelectorAll(s)];
const esc = (v) =>
  String(v ?? '').replace(
    /[&<>"']/g,
    (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c],
  );
const today = () =>
  new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Kolkata' }).format(new Date());
const money = (v, decimals = 0) =>
  `${Number(v) < 0 ? '−' : ''}₹${Math.abs(Number(v) || 0).toLocaleString('en-IN', { minimumFractionDigits: decimals, maximumFractionDigits: decimals })}`;
const compact = (v) =>
  Math.abs(v) >= 1000
    ? `${v < 0 ? '−' : '+'}${(Math.abs(v) / 1000).toFixed(1)}k`
    : `${v > 0 ? '+' : ''}${Math.round(v)}`;
const tone = (v) => (v > 0 ? 'positive' : v < 0 ? 'negative' : 'neutral');
const monthName = () =>
  new Date($('#month').value + '-01T12:00:00').toLocaleDateString('en-IN', {
    month: 'long',
    year: 'numeric',
  });
let state = {
  demo: false,
  user: null,
  settings: {},
  strategies: [],
  challenges: [],
  data: { monthTrades: [], summary: {} },
  page: 'dashboard',
  chartMode: 'cumulative',
  editing: null,
  authMode: 'login',
  loadVersion: 0,
};
let toastTimer;
function toast(message) {
  $('#toast').textContent = message;
  $('#toast').hidden = false;
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => ($('#toast').hidden = true), 4500);
}
async function api(url, method = 'GET', body) {
  const r = await fetch(url, {
    method,
    headers: body ? { 'Content-Type': 'application/json' } : {},
    body: body ? JSON.stringify(body) : undefined,
  });
  const data = await r.json();
  if (!r.ok) {
    if (r.status === 401) showAuth();
    throw new Error(data.error || 'Request failed');
  }
  return data;
}
function busy(form, on) {
  const b = form.querySelector('[type="submit"]');
  if (b) b.disabled = on;
}
function sampleSummary(trades) {
  const vs = trades.map((t) => +t.pnl),
    wins = vs.filter((v) => v > 0),
    loss = vs.filter((v) => v < 0),
    net = vs.reduce((a, b) => a + b, 0);
  let eq = 0,
    peak = 0,
    drawdown = 0;
  for (const t of [...trades].reverse()) {
    eq += +t.pnl;
    peak = Math.max(peak, eq);
    drawdown = Math.max(drawdown, peak - eq);
  }
  return {
    net,
    count: vs.length,
    wins: wins.length,
    losses: loss.length,
    breakeven: vs.length - wins.length - loss.length,
    winRate: vs.length ? (wins.length / vs.length) * 100 : 0,
    profitFactor: loss.length
      ? wins.reduce((a, b) => a + b, 0) / -loss.reduce((a, b) => a + b, 0)
      : null,
    average: vs.length ? net / vs.length : 0,
    fees: trades.reduce((a, t) => a + Number(t.fees || 0), 0),
    drawdown,
  };
}
function makeDemo() {
  const month = $('#month').value;
  const rows = [
    1800, -750, 2400, 1150, -950, 3200, 1650, -600, 2750, 950, 2100, -1200, 3450, 1400, -800, 2600,
    1800, -500, 3100, 1250,
  ];
  let day = 1;
  const max =
    month === today().slice(0, 7)
      ? Number(today().slice(8))
      : new Date(+month.slice(0, 4), +month.slice(5), 0).getDate();
  const trades = [];
  for (let i = 0; i < rows.length && day <= max; i++, day++) {
    while ([0, 6].includes(new Date(`${month}-${String(day).padStart(2, '0')}T12:00:00`).getDay()))
      day++;
    if (day > max) break;
    const pnl = rows[i];
    trades.push({
      id: i + 1,
      symbol: ['NIFTY 25000 CE', 'BANKNIFTY 54000 PE', 'RELIANCE', 'NIFTY 24900 PE'][i % 4],
      instrument_type: ['Call', 'Put', 'Equity', 'Put'][i % 4],
      trade_date: `${month}-${String(day).padStart(2, '0')}`,
      entry_price: 150,
      exit_price: 150 + (pnl + 50) / 50,
      quantity: 1,
      lot_size: 50,
      side: 'Long',
      fees: 50,
      pnl,
      strategy_name: ['Breakout / Retest', 'Order Block / Demand Zone', 'Moving Average Crossover'][
        i % 3
      ],
      strategy_id: (i % 3) + 1,
      notes: 'Sample trade for exploring the journal.',
      entry_time: '09:35',
      exit_time: '11:20',
    });
  }
  trades.reverse();
  state.data = {
    monthTrades: trades,
    summary: sampleSummary(trades),
    monthlyCapital: 100000,
    yearlyPnL: [],
  };
  state.challenges = [
    {
      id: 1,
      name: 'The 35K milestone',
      target: 35000,
      start_date: month + '-01',
      end_date: month + '-' + new Date(+month.slice(0, 4), +month.slice(5), 0).getDate(),
      progress: state.data.summary.net,
      trade_count: trades.length,
    },
  ];
}
function showAuth() {
  try {
    sessionStorage.removeItem('journal-preview');
  } catch {}
  state.user = null;
  state.demo = false;
  state.loadVersion++;
  $('#app').hidden = true;
  $('#auth').hidden = false;
}
async function start(demo = false, user) {
  state.demo = demo;
  try {
    sessionStorage.setItem('journal-preview', String(demo));
  } catch {}
  state.user = user || { email: 'preview@tradejournal.app' };
  $('#auth').hidden = true;
  $('#app').hidden = false;
  $('#demo-banner').hidden = !demo;
  $('#session-status').textContent = demo ? 'Sample workspace' : 'Personal workspace';
  $('#load-error').hidden = true;
  if (demo) {
    state.settings = {
      display_name: 'Alex Morgan',
      default_lot_size: 1,
      risk_per_trade: 1,
      weekly_email: false,
      show_ticker: true,
    };
    state.strategies = [
      { id: 1, name: 'Breakout / Retest' },
      { id: 2, name: 'Order Block / Demand Zone' },
      { id: 3, name: 'Moving Average Crossover' },
    ];
  } else {
    try {
      [state.settings, state.strategies] = await Promise.all([
        api('/api/settings'),
        api('/api/strategies'),
      ]);
    } catch (e) {
      $('#load-error').textContent = e.message;
      $('#load-error').hidden = false;
    }
  }
  renderSettings();
  navigate(pages[location.hash.slice(1)] ? location.hash.slice(1) : 'dashboard', false);
  await refresh();
  if (new URLSearchParams(location.search).has('add') && !demo) openTrade();
}
async function refresh() {
  const version = ++state.loadVersion;
  $('#load-error').hidden = true;
  try {
    if (state.demo) makeDemo();
    else {
      const month = $('#month').value;
      const [data, challenges] = await Promise.all([
        api('/api/dashboard?month=' + month),
        api('/api/challenges'),
      ]);
      if (version !== state.loadVersion) return;
      state.data = data;
      state.challenges = challenges;
    }
    render();
  } catch (e) {
    if (version !== state.loadVersion) return;
    $('#load-error').textContent = e.message + ' Refresh the page to retry.';
    $('#load-error').hidden = false;
  }
}
const pages = {
  dashboard: [
    'Overview',
    'Performance overview',
    'A little perspective on every trade you make.',
    'YOUR EDGE, IN FOCUS',
  ],
  trades: [
    'Trade journal',
    'Every trade tells a story',
    'Review your entries, reflect on your decisions, refine your process.',
    'THE DETAILS MAKE THE DIFFERENCE',
  ],
  challenges: [
    'Challenges',
    'Make progress tangible',
    'Personal milestones, grounded in your actual trading results.',
    'ONE GOAL AT A TIME',
  ],
  reports: [
    'Reports',
    'Reflection, ready to download',
    'Turn a month of trading into a clear, considered review.',
    'THE BIGGER PICTURE',
  ],
  settings: [
    'Settings',
    'Make it your own',
    'A few thoughtful preferences for your daily trading routine.',
    'YOUR JOURNAL, YOUR WAY',
  ],
};
function navigate(page, updateUrl = true) {
  if (!pages[page]) return;
  state.page = page;
  if (updateUrl && location.hash !== `#${page}`) history.pushState(null, '', `#${page}`);
  $$('.page-view').forEach((v) => (v.hidden = v.id !== `view-${page}`));
  $$('#navigation button').forEach((b) => {
    b.classList.toggle('active', b.dataset.page === page);
    b.setAttribute('aria-current', b.dataset.page === page ? 'page' : 'false');
  });
  const p = pages[page];
  $('#breadcrumb').textContent = p[0];
  $('#page-title').innerHTML = esc(p[1]) + '<span class="brand-dot">.</span>';
  $('#page-description').textContent = p[2];
  $('#page-eyebrow').textContent = p[3];
  window.scrollTo({ top: 0, behavior: 'instant' });
}
function render() {
  renderMetrics();
  renderYear();
  renderChart();
  renderOutcomes();
  renderCalendar();
  renderChallenges();
  renderTrades();
  renderReport();
  $('#capital-month').textContent = 'Starting balance for ' + monthName();
  $('#capital-form').elements.capital.value = state.data.monthlyCapital;
}
function renderMetrics() {
  const s = state.data.summary,
    capital = state.data.monthlyCapital;
  const metrics = [
    [
      'Net profit & loss',
      money(s.net),
      capital
        ? `${((s.net / capital) * 100).toFixed(2)}% return on capital`
        : 'Set capital in Settings to see return',
      tone(s.net),
      '↗',
    ],
    [
      'Win rate',
      s.count ? s.winRate.toFixed(1) + '%' : '—',
      `${s.wins} wins out of ${s.count} trades`,
      '',
      '◎',
    ],
    [
      'Profit factor',
      s.profitFactor === null ? (s.wins ? '∞' : '—') : s.profitFactor.toFixed(2),
      s.profitFactor === null
        ? 'No losing trades in this period'
        : 'Winning P&L ÷ absolute losing P&L',
      '',
      '⌁',
    ],
    ['Starting capital', money(capital), `${money(capital + s.net)} closing balance`, '', '▣'],
  ];
  $('#metrics').innerHTML = metrics
    .map(
      (m, i) =>
        `<article class="metric"><div class="metric-label">${m[0]}<span class="metric-icon"><i class="bi bi-${['graph-up-arrow', 'bullseye', 'bar-chart', 'wallet2'][i]}" aria-hidden="true"></i></span></div><strong class="${m[3]}">${m[1]}</strong><div class="metric-foot">${i === 0 ? '<b><i class="bi bi-arrow-up-right" aria-hidden="true"></i></b>' : ''}${esc(m[2])}</div></article>`,
    )
    .join('');
}
function dailyValues() {
  const map = {};
  state.data.monthTrades.forEach((t) => {
    const d = String(t.trade_date).slice(0, 10);
    map[d] = (map[d] || 0) + Number(t.pnl);
  });
  return map;
}
function renderChart() {
  const values = dailyValues(),
    dates = Object.keys(values).sort();
  $('#curve-value').textContent = money(state.data.summary.net, 2);
  $('#curve-label').textContent =
    state.chartMode === 'cumulative' ? 'Cumulative net P&L' : 'Daily net P&L';
  if (!dates.length) {
    $('#equity-chart').innerHTML =
      '<div class="empty"><strong>Your story starts with one trade.</strong>Log a closed trade to see your performance curve.</div>';
    return;
  }
  let total = 0;
  const series = [
    0,
    ...dates.map((d) => (state.chartMode === 'cumulative' ? (total += values[d]) : values[d])),
  ];
  const width = Math.max(300, $('#equity-chart').clientWidth),
    height = 205,
    left = 54,
    right = 18,
    top = 15,
    bottom = 29;
  let low = Math.min(0, ...series),
    high = Math.max(0, ...series);
  if (low === high) {
    low -= 1;
    high += 1;
  }
  const span = high - low;
  low -= span * 0.1;
  high += span * 0.12;
  const x = (i) => left + (i * (width - left - right)) / (series.length - 1),
    y = (v) => top + ((high - v) * (height - top - bottom)) / (high - low);
  const points = series.map((v, i) => `${x(i)},${y(v)}`).join(' ');
  let grid = '';
  for (let i = 0; i < 4; i++) {
    const val = low + ((high - low) * i) / 3,
      yy = y(val);
    grid += `<line x1="${left}" y1="${yy}" x2="${width - right}" y2="${yy}" stroke="var(--line)" stroke-dasharray="3 5"/><text x="${left - 10}" y="${yy + 3}" text-anchor="end" fill="var(--muted)" font-size="12">${Math.abs(val) >= 1000 ? (val / 1000).toFixed(1) + 'k' : Math.round(val)}</text>`;
  }
  const labelIndices = [
    ...new Set([
      0,
      Math.floor((dates.length - 1) / 3),
      Math.floor(((dates.length - 1) * 2) / 3),
      dates.length - 1,
    ]),
  ];
  const labels = labelIndices
    .map(
      (i) =>
        `<text x="${x(i + 1)}" y="${height - 5}" text-anchor="middle" fill="var(--muted)" font-size="12">${new Date(dates[i] + 'T12:00:00').toLocaleDateString('en-IN', { day: 'numeric', month: 'short' })}</text>`,
    )
    .join('');
  const color = state.data.summary.net < 0 ? '#e995a2' : '#6a96e8';
  const dots = series
    .slice(1)
    .map(
      (v, i) =>
        `<circle cx="${x(i + 1)}" cy="${y(v)}" r="3" fill="${color}"><title>${esc(dates[i])}: ${money(v, 2)}</title></circle>`,
    )
    .join('');
  $('#equity-chart').innerHTML =
    `<svg viewBox="0 0 ${width} ${height}" role="img" aria-label="${state.chartMode === 'cumulative' ? 'Cumulative' : 'Daily'} net profit and loss, ${esc(monthName())}"><defs><linearGradient id="area-fill" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="${color}" stop-opacity=".23"/><stop offset="100%" stop-color="${color}" stop-opacity="0"/></linearGradient></defs>${grid}<polygon points="${left},${height - bottom} ${points} ${x(series.length - 1)},${height - bottom}" fill="url(#area-fill)"/><polyline points="${points}" stroke="${color}" stroke-width="2.4" fill="none" stroke-linejoin="round" stroke-linecap="round"/>${dots}${labels}</svg>`;
}
function renderOutcomes() {
  const s = state.data.summary;
  $('#outcome-chart').innerHTML =
    `<div class="ring" style="--wins:${s.count ? (s.wins / s.count) * 360 : 0}deg;--losses:${s.count ? ((s.wins + s.losses) / s.count) * 360 : 0}deg" role="img" aria-label="${s.wins} wins, ${s.losses} losses, ${s.breakeven} breakeven"><div><strong>${s.count ? s.winRate.toFixed(0) + '%' : '—'}</strong><small>Win rate</small></div></div>`;
  $('#outcome-legend').innerHTML =
    `<span><i class="legend-dot"></i> Wins <b>${s.wins}</b></span><span><i class="legend-dot red"></i> Losses <b>${s.losses}</b></span><span><i class="legend-dot gray"></i> Flat <b>${s.breakeven}</b></span>`;
  $('#outcome-insight').textContent = s.count
    ? `Average trade ${money(s.average)} · Max drawdown ${money(s.drawdown)}`
    : 'A consistent process matters more than a single outcome.';
}
function renderCalendar() {
  const month = $('#month').value,
    [year, m] = month.split('-').map(Number),
    n = new Date(year, m, 0).getDate(),
    offset = (new Date(year, m - 1, 1).getDay() + 6) % 7,
    values = dailyValues();
  $('#calendar-month').textContent = monthName();
  $('#calendar').innerHTML =
    ['M', 'T', 'W', 'T', 'F', 'S', 'S'].map((d) => `<span class="weekday">${d}</span>`).join('') +
    '<span></span>'.repeat(offset) +
    Array.from({ length: n }, (_, i) => {
      const day = i + 1,
        d = `${month}-${String(day).padStart(2, '0')}`,
        has = Object.hasOwn(values, d),
        v = values[d];
      return `<button class="calendar-day ${has ? (v >= 0 ? 'has-profit' : 'has-loss') : ''} ${d === today() ? 'today' : ''}" data-day="${d}" aria-label="${d}: ${has ? money(v, 2) : 'No trades'}"><span>${day}</span>${has ? `<b>${compact(v)}</b>` : ''}</button>`;
    }).join('');
}
function challengeCard(c, featured = false) {
  const progress = +c.progress,
    target = +c.target,
    pct = Math.max(0, Math.min(100, (progress / target) * 100));
  const left =
    Math.ceil(
      (Date.parse(c.end_date + 'T00:00:00+05:30') - Date.parse(today() + 'T00:00:00+05:30')) /
        86400000,
    ) + 1;
  const upcoming = c.start_date > today(),
    complete = progress >= target,
    status = upcoming
      ? 'Upcoming'
      : complete
        ? 'Target reached'
        : left <= 0
          ? 'Finished'
          : `${left} days left`;
  return `${featured ? '<div class="challenge-art" aria-hidden="true"><i class="bi bi-flag" aria-hidden="true"></i></div>' : ''}<div class="challenge-title-row"><h3>${esc(c.name)}</h3><span class="tag">${status}</span></div><p>${esc(c.start_date)} — ${esc(c.end_date)}</p><div class="progress-labels"><strong class="${tone(progress)}">${money(progress)}</strong><span>of ${money(target)}</span></div><div class="progress-track" role="progressbar" aria-label="${esc(c.name)}" aria-valuemin="0" aria-valuemax="100" aria-valuenow="${Math.round(pct)}"><i style="width:${pct}%"></i></div><div class="challenge-foot"><span>${pct.toFixed(0)}% of target · ${c.trade_count || 0} trades</span><span>${complete ? 'Well done. Review the process.' : `${money(Math.max(0, target - progress))} to go`}</span></div>${featured ? '<button class="secondary" data-page="challenges">View your challenges ↗</button>' : `<div class="challenge-actions"><button class="secondary" data-challenge-detail="${c.id}"><i class="bi bi-calendar3" aria-hidden="true"></i> Daily P&L & trades</button>${c.edited_at ? '<span class="tag"><i class="bi bi-lock-fill" aria-hidden="true"></i> Edit locked</span>' : `<button class="text-btn" data-edit-challenge="${c.id}"><i class="bi bi-pencil-square" aria-hidden="true"></i> Edit once</button>`}<button class="text-btn delete-challenge" data-delete-challenge="${c.id}"><i class="bi bi-trash3" aria-hidden="true"></i> Delete</button></div>`}`;
}
function renderChallenges() {
  const active =
    state.challenges.find((c) => c.start_date <= today() && c.end_date >= today()) ||
    state.challenges[0];
  $('#featured-challenge').innerHTML = active
    ? challengeCard(active, true)
    : '<div class="challenge-art"><i class="bi bi-flag" aria-hidden="true"></i></div><h3>Your next chapter starts here.</h3><p>Set a personal profit target and track your progress, one trade at a time.</p><button class="secondary" id="empty-challenge" style="margin-top:25px">Create your first challenge ↗</button>';
  $('#challenge-list').innerHTML = state.challenges.length
    ? state.challenges
        .map((c) => `<article class="panel challenge-card">${challengeCard(c)}</article>`)
        .join('')
    : '<div class="empty"><strong>Make room for your next milestone.</strong>Create a challenge with a target and timeframe.</div>';
}
function table(trades) {
  if (!trades.length)
    return '<div class="empty"><strong>No trades to show.</strong>Log a trade or adjust your month and filters.</div>';
  return `<div class="table-wrap"><table><thead><tr><th>INSTRUMENT</th><th>DATE</th><th>STRATEGY</th><th>POSITION</th><th>NET P&L</th><th>DETAILS</th></tr></thead><tbody>${trades.map((t) => `<tr><td class="symbol">${esc(t.symbol)}<small><span class="type-badge">${esc(t.instrument_type)}</span></small></td><td>${new Date(String(t.trade_date).slice(0, 10) + 'T12:00:00').toLocaleDateString('en-IN', { day: '2-digit', month: 'short' })}<small>${esc(t.entry_time?.slice(0, 5) || '—')} IST</small></td><td>${esc(t.strategy_name || 'Unassigned')}</td><td>${esc(t.side || 'Long')}<small>${+t.quantity * (+t.lot_size || 1)} units · ${money(t.entry_price, 2)} → ${money(t.exit_price, 2)}</small></td><td class="pnl-cell ${tone(+t.pnl)}">${money(t.pnl, 2)}<small>Fees ${money(t.fees || 0, 2)}</small></td><td><button class="table-action" data-edit="${t.id}" aria-label="Edit ${esc(t.symbol)} trade">Edit ↗</button>${state.page === 'trades' ? `<button class="table-action negative" data-delete="${t.id}" aria-label="Delete ${esc(t.symbol)} trade">Delete</button>` : ''}</td></tr>`).join('')}</tbody></table></div>`;
}
function renderTrades() {
  const trades = state.data.monthTrades;
  $('#recent-count').textContent = trades.length;
  $('#recent-trades').innerHTML = table(trades.slice(0, 5));
  const query = $('#search-trades').value.toLowerCase(),
    outcome = $('#outcome-filter').value;
  const filtered = trades.filter(
    (t) =>
      [t.symbol, t.strategy_name, t.notes].some((v) =>
        String(v || '')
          .toLowerCase()
          .includes(query),
      ) &&
      (outcome === 'all' ||
        (outcome === 'win' && +t.pnl > 0) ||
        (outcome === 'loss' && +t.pnl < 0) ||
        (outcome === 'flat' && +t.pnl === 0)),
  );
  $('#all-trades').innerHTML = table(filtered);
  $('#filtered-count').textContent = `${filtered.length} TRADES`;
}
function renderReport() {
  const s = state.data.summary;
  $('#report-month').textContent = monthName();
  $('#report-net').textContent = money(s.net, 2);
  $('#report-stats').innerHTML =
    `<span>${s.count} trades</span><span>${s.winRate.toFixed(1)}% win rate</span>`;
}
function renderSettings() {
  const s = state.settings,
    form = $('#settings-form'),
    name = s.display_name || state.user.email.split('@')[0];
  $('#profile-name').textContent = name;
  $('#profile-email').textContent = state.user.email;
  $('#avatar').textContent = $('#top-avatar').textContent = name.slice(0, 2).toUpperCase();
  $('#settings-email').value = state.user.email;
  for (const k of ['display_name', 'default_lot_size', 'risk_per_trade'])
    form.elements[k].value = s[k] ?? '';
  for (const k of ['weekly_email']) form.elements[k].checked = !!s[k];
  $('#email-status').textContent = state.demo
    ? 'Preview only. Connect an account to enable email summaries.'
    : s.emailConfigured
      ? `Delivery ready · Checked hourly after Monday 00:00 IST.${s.lastEmail ? ' Last sent: ' + new Date(s.lastEmail).toLocaleString('en-IN', { timeZone: 'Asia/Kolkata' }) + ' IST' : ''}`
      : 'Email delivery is not configured yet. Your preference can be saved; the server needs an email provider key and verified sender.';
  $('#menu-name').textContent = name;
  $('#menu-email').textContent = state.user.email;
  $('#strategy-list').innerHTML = state.strategies
    .map(
      (s) =>
        `<div class="strategy-row"><span><strong>${esc(s.name)}</strong>${s.description ? `<p class="strategy-description">${esc(s.description)}</p>` : ''}</span>${s.user_id ? `<button class="icon-button" data-delete-strategy="${s.id}" aria-label="Delete ${esc(s.name)}"><i class="bi bi-x-lg" aria-hidden="true"></i></button>` : '<small>BUILT-IN</small>'}</div>`,
    )
    .join('');
  $('#trade-strategy').innerHTML =
    '<option value="">No strategy selected</option>' +
    state.strategies.map((s) => `<option value="${s.id}">${esc(s.name)}</option>`).join('');
}
function previewGuard() {
  if (!state.demo) return false;
  toast('Sign in to save changes to your own journal.');
  return true;
}
function openTrade(id) {
  const form = $('#trade-form');
  form.reset();
  state.editing = id || null;
  $('#trade-title').textContent = id ? 'Review & edit trade' : 'Log a trade';
  $('#trade-error').textContent = '';
  form.elements.trade_date.value = today();
  form.elements.trade_date.max = today();
  form.elements.lot_size.value = state.settings.default_lot_size || 1;
  if (id) {
    const t = state.data.monthTrades.find((t) => +t.id === +id);
    if (!t) return;
    for (const el of form.elements) {
      if (el.name && t[el.name] != null)
        el.value = ['trade_date', 'expiry_date'].includes(el.name)
          ? String(t[el.name]).slice(0, 10)
          : t[el.name];
    }
  }
  updatePreview();
  $('#trade-dialog').showModal();
}
function updatePreview() {
  const f = $('#trade-form'),
    v = (k) => +f.elements[k].value || 0,
    units = v('quantity') * v('lot_size'),
    pnl =
      (v('exit_price') - v('entry_price')) * (f.elements.side.value === 'Short' ? -1 : 1) * units -
      v('fees');
  $('#trade-preview').textContent = money(pnl, 2);
  $('#trade-preview').className = tone(pnl);
  const risk =
    f.elements.stop_loss.value !== ''
      ? Math.abs(v('entry_price') - v('stop_loss')) * units + v('fees')
      : null;
  $('#risk-preview').textContent = risk === null ? 'Add a stop loss' : money(risk, 2);
  const budget = (state.data.monthlyCapital * (state.settings.risk_per_trade || 1)) / 100;
  $('#risk-warning').textContent =
    risk !== null && budget > 0
      ? `Your risk reference is ${money(budget)} (${state.settings.risk_per_trade}% of starting capital).${risk > budget ? ' This trade is above that reference.' : ''}`
      : '';
  const derivative = ['Call', 'Put', 'Futures'].includes(f.elements.instrument_type.value);
  f.elements.expiry_date.required = derivative;
  f.elements.expiry_date.min = f.elements.trade_date.value;
}
let editingChallenge = null;
function openChallenge(id = null) {
  const f = $('#challenge-form');
  f.reset();
  f.elements.start_date.value = today();
  editingChallenge = id;
  const c = id ? state.challenges.find((c) => Number(c.id) === Number(id)) : null;
  if (id && (!c || c.edited_at)) {
    toast('This challenge is locked.');
    return;
  }
  if (c) {
    f.elements.name.value = c.name;
    f.elements.target.value = c.target;
    f.elements.start_date.value = c.start_date;
    f.elements.days.value =
      Math.round((Date.parse(c.end_date) - Date.parse(c.start_date)) / 86400000) + 1;
  }
  $('#challenge-form-title').textContent = id ? 'Edit challenge once' : 'Create a challenge';
  f.querySelector('[type="submit"]').textContent = id ? 'Save edit & lock' : 'Create challenge';
  $('#challenge-lock-warning').hidden = !id;
  f.elements.acknowledge_lock.required = !!id;
  $('#challenge-error').textContent = '';
  $('#challenge-dialog').showModal();
}
let pendingDelete = null;
function removeItem(kind, id) {
  const noun = kind === 'trades' ? 'trade' : kind === 'strategies' ? 'strategy' : 'challenge';
  pendingDelete = { kind, id };
  $('#delete-title').textContent = `Delete this ${noun}?`;
  $('#delete-copy').textContent =
    kind === 'strategies'
      ? 'This removes the strategy from your playbook. Existing trades are kept and will become unassigned. This cannot be undone.'
      : `This ${noun} will be permanently removed from your journal. This cannot be undone.`;
  $('#delete-error').textContent = '';
  $('#delete-dialog').showModal();
}
$('#confirm-delete').onclick = async () => {
  if (!pendingDelete || previewGuard()) return;
  const { kind, id } = pendingDelete;
  $('#confirm-delete').disabled = true;
  try {
    await api(`/api/${kind}/${id}`, 'DELETE');
    if (kind === 'strategies') {
      state.strategies = await api('/api/strategies');
      renderSettings();
    }
    await refresh();
    $('#delete-dialog').close();
    toast('Deleted successfully.');
  } catch (e) {
    $('#delete-error').textContent = e.message;
  } finally {
    $('#confirm-delete').disabled = false;
  }
};
$('#delete-dialog').addEventListener('close', () => {
  pendingDelete = null;
});
function closeAccount() {
  $('#account-dropdown').hidden = true;
  $('#account-toggle').setAttribute('aria-expanded', 'false');
}
$('#account-toggle').onclick = () => {
  const open = $('#account-dropdown').hidden;
  $('#account-dropdown').hidden = !open;
  $('#account-toggle').setAttribute('aria-expanded', String(open));
};
document.addEventListener('click', (e) => {
  if (!e.target.closest('.account-menu') || e.target.closest('[data-page]')) closeAccount();
});
document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape' && !$('#account-dropdown').hidden) {
    closeAccount();
    $('#account-toggle').focus();
  }
});
for (const id of ['theme-select', 'settings-theme']) {
  $('#' + id).value = window.journalTheme.preference;
  $('#' + id).onchange = (e) => {
    window.journalTheme.set(e.target.value);
    for (const other of ['theme-select', 'settings-theme']) $('#' + other).value = e.target.value;
  };
}
$('#month').value = today().slice(0, 7);
$('#auth-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const f = e.currentTarget;
  busy(f, true);
  $('#auth-error').textContent = '';
  try {
    const r = await api('/api/auth/' + state.authMode, 'POST', Object.fromEntries(new FormData(f)));
    await start(false, r.user);
  } catch (e) {
    $('#auth-error').textContent = e.message;
  } finally {
    busy(f, false);
  }
});
$('#auth-toggle').onclick = () => {
  state.authMode = state.authMode === 'login' ? 'register' : 'login';
  const login = state.authMode === 'login';
  $('#auth-title').textContent = login ? 'Welcome back.' : 'Start your trading story.';
  $('#auth-copy').textContent = login
    ? 'Your trading story continues here.'
    : 'Build a better process, one trade at a time.';
  $('#auth-submit').textContent = login ? 'Sign in ↗' : 'Create account ↗';
  $('#auth-toggle').textContent = login
    ? 'New here? Create an account'
    : 'Already a member? Sign in';
  $('#auth-form').elements.password.autocomplete = login ? 'current-password' : 'new-password';
  $('#auth-error').textContent = '';
};
$('#demo-button').onclick = () => start(true);
$('#exit-demo').onclick = showAuth;
$('#logout').onclick = async () => {
  closeAccount();
  if (state.demo) return showAuth();
  try {
    await api('/api/auth/logout', 'POST');
    showAuth();
  } catch (e) {
    toast(e.message);
  }
};
document.addEventListener('click', (e) => {
  const page = e.target.closest('[data-page]');
  if (page) {
    navigate(page.dataset.page);
    renderTrades();
  }
  const close = e.target.closest('.close-dialog');
  if (close) close.closest('dialog').close();
  const edit = e.target.closest('[data-edit]');
  if (edit) openTrade(+edit.dataset.edit);
  const del = e.target.closest('[data-delete]');
  if (del) removeItem('trades', del.dataset.delete);
  const detail = e.target.closest('[data-challenge-detail]');
  if (detail) openChallengeDetails(detail.dataset.challengeDetail);
  const editChallenge = e.target.closest('[data-edit-challenge]');
  if (editChallenge) openChallenge(Number(editChallenge.dataset.editChallenge));
  const dc = e.target.closest('[data-delete-challenge]');
  if (dc) removeItem('challenges', dc.dataset.deleteChallenge);
  const ds = e.target.closest('[data-delete-strategy]');
  if (ds) removeItem('strategies', ds.dataset.deleteStrategy);
  if (e.target.closest('#empty-challenge')) openChallenge();
  const day = e.target.closest('[data-day]');
  if (day) {
    $('#day-title').textContent = new Date(day.dataset.day + 'T12:00:00').toLocaleDateString(
      'en-IN',
      { day: 'numeric', month: 'long', year: 'numeric' },
    );
    const trades = state.data.monthTrades.filter(
      (t) => String(t.trade_date).slice(0, 10) === day.dataset.day,
    );
    $('#day-trades').innerHTML = trades.length
      ? trades
          .map(
            (t) =>
              `<div class="day-item"><div><strong>${esc(t.symbol)}</strong><p>${esc(t.strategy_name || 'No strategy')} · ${esc(t.side || 'Long')}</p><small>${esc(t.notes || 'No notes added.')}</small></div><strong class="${tone(+t.pnl)}">${money(t.pnl, 2)}</strong></div>`,
          )
          .join('')
      : '<div class="empty">No trades recorded on this day.</div>';
    $('#day-dialog').showModal();
  }
});
$('#month').onchange = () => {
  if ($('#month').value) refresh();
};
for (const [id, delta] of [
  ['previous-month', -1],
  ['next-month', 1],
])
  $('#' + id).onclick = () => {
    const [y, m] = $('#month').value.split('-').map(Number),
      d = new Date(y, m - 1 + delta, 1);
    $('#month').value = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
    refresh();
  };
$('#chart-mode').onclick = (e) => {
  const b = e.target.closest('[data-mode]');
  if (!b) return;
  state.chartMode = b.dataset.mode;
  $$('#chart-mode button').forEach((x) => x.classList.toggle('selected', x === b));
  renderChart();
};
$('#search-trades').oninput = renderTrades;
$('#outcome-filter').onchange = renderTrades;
$('#clear-filters').onclick = () => {
  $('#search-trades').value = '';
  $('#outcome-filter').value = 'all';
  renderTrades();
};
$('#add-trade').onclick = () => openTrade();
$('#add-challenge').onclick = () => openChallenge();
$('#trade-form').oninput = updatePreview;
$('#trade-form').onsubmit = async (e) => {
  e.preventDefault();
  if (previewGuard()) return;
  const f = e.currentTarget;
  busy(f, true);
  $('#trade-error').textContent = '';
  try {
    const data = Object.fromEntries(new FormData(f));
    await api(
      '/api/trades' + (state.editing ? '/' + state.editing : ''),
      state.editing ? 'PUT' : 'POST',
      data,
    );
    $('#month').value = data.trade_date.slice(0, 7);
    $('#trade-dialog').close();
    await refresh();
    toast(state.editing ? 'Trade updated.' : 'Trade added to your journal.');
  } catch (e) {
    $('#trade-error').textContent = e.message;
  } finally {
    busy(f, false);
  }
};
$('#challenge-form').onsubmit = async (e) => {
  e.preventDefault();
  if (previewGuard()) return;
  const f = e.currentTarget;
  busy(f, true);
  try {
    const data = Object.fromEntries(new FormData(f)),
      end = new Date(data.start_date + 'T12:00:00Z');
    end.setUTCDate(end.getUTCDate() + Number(data.days) - 1);
    data.end_date = end.toISOString().slice(0, 10);
    data.acknowledge_lock = f.elements.acknowledge_lock.checked;
    await api(
      '/api/challenges' + (editingChallenge ? '/' + editingChallenge : ''),
      editingChallenge ? 'PUT' : 'POST',
      data,
    );
    $('#challenge-dialog').close();
    await refresh();
    navigate('challenges');
    toast(editingChallenge ? 'Challenge updated and locked.' : 'Your challenge is ready.');
  } catch (e) {
    $('#challenge-error').textContent = e.message;
  } finally {
    busy(f, false);
  }
};
$('#settings-form').onsubmit = async (e) => {
  e.preventDefault();
  if (previewGuard()) return;
  const f = e.currentTarget;
  busy(f, true);
  try {
    const data = Object.fromEntries(new FormData(f));
    data.weekly_email = f.elements.weekly_email.checked;
    data.show_ticker = false;
    await api('/api/settings', 'PUT', data);
    state.settings = await api('/api/settings');
    renderSettings();

    toast('Preferences saved.');
  } catch (e) {
    toast(e.message);
  } finally {
    busy(f, false);
  }
};
$('#capital-form').onsubmit = async (e) => {
  e.preventDefault();
  if (previewGuard()) return;
  busy(e.currentTarget, true);
  try {
    await api('/api/capital', 'POST', {
      yearMonth: $('#month').value,
      capital: e.currentTarget.elements.capital.value,
    });
    await refresh();
    toast('Starting capital updated.');
  } catch (e) {
    toast(e.message);
  } finally {
    busy($('#capital-form'), false);
  }
};
$('#strategy-form').onsubmit = async (e) => {
  e.preventDefault();
  if (previewGuard()) return;
  const f = e.currentTarget;
  busy(f, true);
  try {
    await api('/api/strategies', 'POST', Object.fromEntries(new FormData(f)));
    state.strategies = await api('/api/strategies');
    renderSettings();
    f.reset();
    toast('Strategy added.');
  } catch (e) {
    toast(e.message);
  } finally {
    busy(f, false);
  }
};
$('#download-report').onclick = async () => {
  if (previewGuard()) return;
  const b = $('#download-report');
  b.disabled = true;
  try {
    const r = await fetch('/api/reports/monthly.pdf?month=' + $('#month').value);
    if (!r.ok) {
      const d = await r.json();
      throw new Error(d.error || 'Report could not be generated');
    }
    const blob = await r.blob(),
      url = URL.createObjectURL(blob),
      a = document.createElement('a');
    a.href = url;
    a.download = `tradejournal-${$('#month').value}.pdf`;
    a.click();
    setTimeout(() => URL.revokeObjectURL(url), 10000);
    toast('Your monthly report is ready.');
  } catch (e) {
    toast(e.message);
  } finally {
    b.disabled = false;
  }
};
api('/api/auth/me')
  .then((r) => {
    let preview = false;
    try {
      preview = sessionStorage.getItem('journal-preview') === 'true';
    } catch {}
    return r.loggedIn ? start(false, r.user) : preview ? start(true) : showAuth();
  })
  .catch((e) => {
    $('#auth-error').textContent = e.message;
  });

function renderYear() {
  const year = $('#month').value.slice(0, 4);
  $('#year-label').textContent = year;
  const values = Array.from({ length: 12 }, (_, i) =>
    Number(
      state.data.yearlyPnL.find((v) => v.month === `${year}-${String(i + 1).padStart(2, '0')}`)
        ?.pnl || 0,
    ),
  );
  if (state.demo) values[Number($('#month').value.slice(5)) - 1] = state.data.summary.net;
  const max = Math.max(1, ...values.map(Math.abs));
  $('#year-chart').innerHTML = values
    .map(
      (v, i) =>
        `<div class="year-bar-column"><div class="year-bar-space"><i style="height:${Math.max(2, (Math.abs(v) / max) * 100)}%;background:${v < 0 ? 'var(--red)' : 'var(--green)'}" title="${money(v, 2)}"></i></div><small>${['J', 'F', 'M', 'A', 'M', 'J', 'J', 'A', 'S', 'O', 'N', 'D'][i]}</small><span class="sr-only">${i + 1}: ${money(v, 2)}</span></div>`,
    )
    .join('');
  const trades = state.data.monthTrades,
    sorted = [...trades].sort((a, b) => +b.pnl - +a.pnl),
    strategies = {};
  for (const t of trades) {
    const name = t.strategy_name || 'Unassigned';
    strategies[name] = (strategies[name] || 0) + Number(t.pnl);
  }
  const best = Object.entries(strategies).sort((a, b) => b[1] - a[1])[0];
  let streak = 0,
    longest = 0;
  for (const t of [...trades].reverse()) {
    streak = +t.pnl > 0 ? streak + 1 : 0;
    longest = Math.max(longest, streak);
  }
  $('#playbook').innerHTML = trades.length
    ? `<div class="playbook-row"><span>Best trade <small>${esc(sorted[0].symbol)}</small></span><strong class="${tone(+sorted[0].pnl)}">${money(sorted[0].pnl)}</strong></div><div class="playbook-row"><span>Lowest trade <small>${esc(sorted.at(-1).symbol)}</small></span><strong class="${tone(+sorted.at(-1).pnl)}">${money(sorted.at(-1).pnl)}</strong></div><div class="playbook-row"><span>Leading strategy <small>${esc(best[0])}</small></span><strong class="${tone(best[1])}">${money(best[1])}</strong></div><div class="playbook-row"><span>Longest winning streak</span><strong>${longest} trades</strong></div>`
    : '<div class="empty">Your trading patterns will appear here as you build your journal.</div>';
}

async function openChallengeDetails(id) {
  const dialog = $('#challenge-detail-dialog');
  $('#challenge-detail-title').textContent = 'Challenge breakdown';
  $('#challenge-detail-period').textContent = 'Loading…';
  $('#challenge-detail-summary').replaceChildren();
  $('#challenge-daily').replaceChildren();
  dialog.showModal();
  try {
    const data = state.demo
      ? { challenge: state.challenges.find((c) => +c.id === +id), trades: state.data.monthTrades }
      : await api('/api/challenges/' + id);
    if (!dialog.open) return;
    const c = data.challenge,
      trades = data.trades,
      stats = data.summary || sampleSummary(trades);
    $('#challenge-detail-title').textContent = c.name;
    $('#challenge-detail-period').textContent =
      `${c.start_date} — ${c.end_date} · Target ${money(c.target)}${c.edited_at ? ' · Edit locked' : ''}`;
    $('#challenge-detail-summary').innerHTML =
      `<div><small>Net P&L</small><strong class="${tone(stats.net)}">${money(stats.net, 2)}</strong></div><div><small>Winning trades</small><strong>${stats.wins}</strong></div><div><small>Losing trades</small><strong>${stats.losses}</strong></div><div><small>Remaining target</small><strong>${money(Math.max(0, c.target - stats.net), 2)}</strong></div>`;
    const byDay = {};
    for (const t of trades) {
      const day = String(t.trade_date).slice(0, 10);
      (byDay[day] ||= []).push(t);
    }
    const end = c.end_date < today() ? c.end_date : today();
    let running = 0,
      rows = [];
    for (
      let d = new Date(c.start_date + 'T12:00:00Z');
      d.toISOString().slice(0, 10) <= end;
      d.setUTCDate(d.getUTCDate() + 1)
    ) {
      const key = d.toISOString().slice(0, 10),
        dayTrades = byDay[key] || [],
        net = dayTrades.reduce((a, t) => a + Number(t.pnl), 0);
      running += net;
      rows.push(
        `<details class="daily-detail"><summary><span>${esc(key)}<small>${dayTrades.length} ${dayTrades.length === 1 ? 'trade' : 'trades'}</small></span><span class="${tone(net)}">${money(net, 2)}<small>Daily net</small></span><span class="${tone(running)}">${money(running, 2)}<small>Running total</small></span><i class="bi bi-chevron-down" aria-hidden="true"></i></summary><div class="daily-trade-list">${dayTrades.length ? dayTrades.map((t) => `<div class="day-item"><div><strong>${esc(t.symbol)}</strong><p>${esc(t.side || 'Long')} · ${esc(t.strategy_name || 'Unassigned')}</p><small>${t.quantity * (t.lot_size || 1)} units · Entry ${money(t.entry_price, 2)} · Exit ${money(t.exit_price, 2)} · Fees ${money(t.fees || 0, 2)}</small></div><strong class="${tone(+t.pnl)}">${money(t.pnl, 2)}</strong></div>`).join('') : '<p>No trades recorded for this day.</p>'}</div></details>`,
      );
    }
    $('#challenge-daily').innerHTML = rows.length
      ? rows.join('')
      : '<div class="empty">This challenge has not started yet. Daily results will appear here.</div>';
  } catch (e) {
    $('#challenge-detail-period').textContent = e.message;
  }
}
$('#email-change-form').onsubmit = async (e) => {
  e.preventDefault();
  if (previewGuard()) return;
  const f = e.currentTarget;
  busy(f, true);
  $('#account-email-status').textContent = 'Sending verification code…';
  try {
    const r = await api('/api/account/email/request', 'POST', Object.fromEntries(new FormData(f)));
    f.elements.password.value = '';
    $('#email-verify-form').hidden = false;
    $('#account-email-status').textContent = r.message;
  } catch (e) {
    $('#account-email-status').textContent = e.message;
  } finally {
    busy(f, false);
  }
};
$('#email-verify-form').onsubmit = async (e) => {
  e.preventDefault();
  if (previewGuard()) return;
  const f = e.currentTarget;
  busy(f, true);
  try {
    const r = await api('/api/account/email/confirm', 'POST', Object.fromEntries(new FormData(f)));
    state.user.email = r.email;
    renderSettings();
    f.reset();
    f.hidden = true;
    $('#email-change-form').reset();
    $('#account-email-status').textContent =
      'Email updated. You can now send a test email to confirm delivery.';
  } catch (e) {
    $('#account-email-status').textContent = e.message;
  } finally {
    busy(f, false);
  }
};
$('#test-email').onclick = async () => {
  if (previewGuard()) return;
  $('#test-email').disabled = true;
  $('#account-email-status').textContent = 'Sending test email to ' + state.user.email + '…';
  try {
    const r = await api('/api/account/email/test', 'POST', {});
    $('#account-email-status').textContent = r.message;
  } catch (e) {
    $('#account-email-status').textContent = e.message;
  } finally {
    $('#test-email').disabled = false;
  }
};

window.addEventListener('popstate', () => {
  if (state.user) {
    navigate(pages[location.hash.slice(1)] ? location.hash.slice(1) : 'dashboard', false);
    renderTrades();
  }
});
window.addEventListener('resize', () => {
  if (state.user && state.page === 'dashboard') renderChart();
});
