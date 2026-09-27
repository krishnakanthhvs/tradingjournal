const charges = require('../public/charges');
const fail = (message) => {
  const e = new Error(message);
  e.status = 400;
  throw e;
};
const date = (v) =>
  typeof v === 'string' &&
  /^\d{4}-\d{2}-\d{2}$/.test(v) &&
  !isNaN(Date.parse(v)) &&
  new Date(v).toISOString().slice(0, 10) === v;
function number(v, name, min = 0, max = 1e9) {
  if (v === '' || v == null || !Number.isFinite(Number(v)) || Number(v) < min || Number(v) > max)
    fail(`Invalid ${name}`);
  return Number(v);
}
function trade(body) {
  const t = { ...body };
  t.symbol = String(t.symbol || '')
    .trim()
    .toUpperCase();
  if (!t.symbol || t.symbol.length > 50) fail('Symbol is required (up to 50 characters)');
  if (!['Equity', 'Call', 'Put', 'Futures', 'Forex', 'Commodity'].includes(t.instrument_type))
    fail('Invalid instrument');
  t.side = t.side || 'Long';
  if (!['Long', 'Short'].includes(t.side)) fail('Invalid direction');
  for (const k of ['entry_price', 'exit_price', 'quantity', 'lot_size', 'fees'])
    t[k] = number(
      t[k] ?? (k === 'lot_size' ? 1 : k === 'fees' ? 0 : undefined),
      k,
      k === 'quantity' || k === 'lot_size' ? 1 : 0,
    );
  if (t.entry_price > 99999999.99 || t.exit_price > 99999999.99)
    fail('Price exceeds supported limits');
  if (!Number.isInteger(t.quantity) || !Number.isInteger(t.lot_size))
    fail('Quantity and lot size must be whole numbers');
  if (!date(t.trade_date)) fail('Valid trade date is required');
  if (
    t.trade_date > new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Kolkata' }).format(new Date())
  )
    fail('Trade date cannot be in the future');
  if (['Call', 'Put', 'Futures'].includes(t.instrument_type) && !t.expiry_date)
    fail('Expiry date is required for derivatives');
  if (t.expiry_date && (!date(t.expiry_date) || t.expiry_date < t.trade_date))
    fail('Expiry must be on or after the trade date');
  for (const k of ['entry_time', 'exit_time'])
    if (t[k] && !/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/.test(t[k])) fail('Invalid trade time');
  for (const k of ['stop_loss', 'target_price', 'market_close_strike'])
    t[k] = t[k] == null || t[k] === '' ? null : number(t[k], k);
  if (
    t.stop_loss !== null &&
    (t.side === 'Long' ? t.stop_loss >= t.entry_price : t.stop_loss <= t.entry_price)
  )
    fail('Stop loss must be on the risk side of entry');
  if (
    t.target_price !== null &&
    (t.side === 'Long' ? t.target_price <= t.entry_price : t.target_price >= t.entry_price)
  )
    fail('Target must be on the profit side of entry');
  t.notes = String(t.notes || '').trim();
  if (t.notes.length > 5000) fail('Notes must be under 5,000 characters');
  t.broker = t.broker || 'Other';
  t.fee_mode = t.fee_mode || 'manual';
  t.product = t.product || 'intraday';
  t.exchange = t.exchange || 'NSE';
  let quote;
  try {
    quote = charges.calculate(t);
  } catch (e) {
    fail(e.message);
  }
  t.fees = quote.total;
  t.pnl = quote.net;
  t.charge_details = {
    ...quote,
    inputs: Object.fromEntries(
      [
        'brokerage_plan',
        'brokerage_override',
        'per_order',
        'order_count',
        'dp_charge',
        'commodity_type',
      ].map((k) => [k, t[k] ?? null]),
    ),
  };
  if (Math.abs(t.pnl) > 9999999999 || Math.abs(quote.gross) > 9999999999 || t.fees > 9999999999)
    fail('Trade value exceeds supported limits');
  return t;
}
function summary(trades) {
  const values = trades.map((t) => Math.round(Number(t.pnl) * 100));
  const feeCents = trades.reduce((a, t) => a + Math.round(Number(t.fees || 0) * 100), 0);
  const wins = values.filter((x) => x > 0),
    losses = values.filter((x) => x < 0);
  const net = values.reduce((a, b) => a + b, 0) / 100,
    grossWin = wins.reduce((a, b) => a + b, 0),
    grossLoss = -losses.reduce((a, b) => a + b, 0);
  let equity = 0,
    peak = 0,
    drawdown = 0;
  for (const t of [...trades].sort(
    (a, b) => String(a.trade_date).localeCompare(String(b.trade_date)) || a.id - b.id,
  )) {
    equity += Math.round(Number(t.pnl) * 100);
    peak = Math.max(peak, equity);
    drawdown = Math.max(drawdown, peak - equity);
  }
  return {
    net,
    gross: (values.reduce((a, b) => a + b, 0) + feeCents) / 100,
    count: values.length,
    wins: wins.length,
    losses: losses.length,
    breakeven: values.length - wins.length - losses.length,
    winRate: values.length ? (wins.length / values.length) * 100 : 0,
    profitFactor: grossLoss ? grossWin / grossLoss : null,
    average: values.length ? net / values.length : 0,
    fees: feeCents / 100,
    drawdown: drawdown / 100,
  };
}
function weekRange(now = new Date()) {
  const today = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Kolkata' }).format(now);
  const end = new Date(`${today}T00:00:00Z`);
  end.setUTCDate(end.getUTCDate() - ((end.getUTCDay() + 6) % 7));
  const start = new Date(end);
  start.setUTCDate(start.getUTCDate() - 7);
  return { start: start.toISOString().slice(0, 10), end: end.toISOString().slice(0, 10) };
}
module.exports = { trade, summary, date, number, fail, weekRange };
