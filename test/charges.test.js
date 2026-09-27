const { test } = require('node:test');
const assert = require('node:assert/strict');
const { calculate } = require('../public/charges');
const { trade, summary } = require('../lib/domain');
const base = {
  broker: 'Other',
  fee_mode: 'estimate',
  brokerage_plan: 'standard',
  instrument_type: 'Call',
  exchange: 'NSE',
  product: 'intraday',
  side: 'Long',
  entry_price: 100,
  exit_price: 120,
  quantity: 1,
  lot_size: 100,
  trade_date: '2026-09-20',
  expiry_date: '2026-09-30',
  order_count: 2,
  per_order: 20,
};
test('options estimate separates taxes, gross and net; short side pays STT on entry', () => {
  const q = calculate(base);
  assert.equal(q.gross, 2000);
  assert.equal(q.items.Brokerage, 40);
  assert.equal(q.items['STT (sell)'], 18);
  assert.equal(q.items['Stamp duty (buy)'], 0.3);
  assert.equal(q.items['Exchange transaction charges'], 7.82);
  assert.equal(q.items['GST (18%)'], 8.61);
  assert.equal(q.total, 74.75);
  assert.equal(q.net, 1925.25);
  const short = calculate({ ...base, side: 'Short' });
  assert.equal(short.items['STT (sell)'], 15);
  assert.equal(short.items['Stamp duty (buy)'], 0.36);
  assert.equal(short.gross, -2000);
});
test('broker caps, named plans, custom orders, delivery DP GST and commodity CTT', () => {
  assert.equal(calculate({ ...base, broker: 'Sahi' }).items.Brokerage, 20);
  assert.equal(calculate({ ...base, broker: 'Kotak' }).items.Brokerage, 20);
  assert.equal(calculate({ ...base, broker: 'ICICI' }).items.Brokerage, 40);
  assert.equal(
    calculate({ ...base, broker: 'Dhan', instrument_type: 'Futures' }).items.Brokerage,
    40,
  );
  assert.equal(
    calculate({ ...base, broker: 'Zerodha', instrument_type: 'Futures' }).items.Brokerage,
    6.6,
  );
  const d = calculate({
    ...base,
    broker: 'Zerodha',
    instrument_type: 'Equity',
    product: 'delivery',
    dp_charge: 13,
  });
  assert.equal(d.items.Brokerage, 0);
  assert.equal(d.items['STT (buy + sell)'], 22);
  assert.equal(d.items['DP charges (before GST)'], 13);
  assert.equal(d.items['GST (18%)'], 2.47);
  const mcx = calculate({
    ...base,
    instrument_type: 'Commodity',
    commodity_type: 'options',
    exchange: 'MCX',
  });
  assert.equal(mcx.items['CTT (sell)'], 6);
  assert.equal(calculate({ ...base, order_count: 4 }).items.Brokerage, 80);
  assert.equal(
    calculate({
      ...base,
      broker: 'Sahi',
      brokerage_plan: 'custom',
      brokerage_override: 0,
      order_count: 4,
    }).items.Brokerage,
    0,
  );
});
test('unsupported or incomplete estimates fail; actual fees and legacy gross remain usable', () => {
  for (const change of [
    { trade_date: '2025-01-01' },
    { exchange: 'BSE' },
    { instrument_type: 'Forex' },
    { broker: 'bad' },
    { order_count: 0 },
    { broker: 'Sahi', order_count: 3 },
    { brokerage_plan: 'custom', brokerage_override: '' },
    { instrument_type: 'Equity', product: 'delivery', dp_charge: '' },
  ])
    assert.throws(() => calculate({ ...base, ...change }));
  const q = calculate({ ...base, trade_date: '2025-01-01', fee_mode: 'manual', fees: 25 });
  assert.equal(q.net, 1975);
  const t = trade({
    ...base,
    symbol: 'NIFTY',
    fees: 0,
    pnl: 999999,
    charge_details: { items: { Brokerage: 0 } },
  });
  assert.equal(t.fees, 74.75);
  assert.equal(t.pnl, 1925.25);
  const s = summary([{ pnl: 950, fees: 50, trade_date: '2026-01-01', id: 1 }]);
  assert.equal(s.gross, 1000);
  assert.equal(s.net, 950);
});
