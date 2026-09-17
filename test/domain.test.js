const { test } = require('node:test');
const assert = require('node:assert/strict');
const { trade, summary, weekRange } = require('../lib/domain');
const base = {
  symbol: 'NIFTY',
  instrument_type: 'Equity',
  entry_price: 100,
  exit_price: 120,
  quantity: 2,
  lot_size: 25,
  fees: 50,
  trade_date: '2026-01-02',
};
test('long and short P&L includes position size and fees', () => {
  assert.equal(trade(base).pnl, 950);
  assert.equal(trade({ ...base, side: 'Short' }).pnl, -1050);
  assert.equal(trade({ ...base, side: 'Short', exit_price: 80 }).pnl, 950);
});
test('invalid quantities, dates, numbers and risk plans are rejected', () => {
  for (const data of [
    { quantity: -1 },
    { quantity: 1.5 },
    { entry_price: 'NaN' },
    { trade_date: '2026-02-30' },
    { trade_date: '2099-01-01' },
    { stop_loss: 110 },
    { target_price: 90 },
    { expiry_date: '2025-01-01' },
    { entry_time: '25:00' },
  ])
    assert.throws(() => trade({ ...base, ...data }));
});
test('summary handles breakeven, losses, drawdown and empty journals', () => {
  const rows = [100, -60, -70, 0, 150].map((pnl, i) => ({
    pnl,
    id: i,
    trade_date: `2026-01-0${i + 1}`,
    fees: 10,
  }));
  const s = summary(rows);
  assert.equal(s.net, 120);
  assert.equal(s.winRate, 40);
  assert.equal(s.breakeven, 1);
  assert.equal(s.drawdown, 130);
  assert.equal(s.fees, 50);
  assert.equal(summary([]).profitFactor, null);
  assert.equal(summary([]).winRate, 0);
});
test('weekly report uses previous complete Monday–Sunday in India', () => {
  assert.deepEqual(weekRange(new Date('2026-09-13T18:29:00Z')), {
    start: '2026-08-31',
    end: '2026-09-07',
  });
  assert.deepEqual(weekRange(new Date('2026-09-13T18:30:00Z')), {
    start: '2026-09-07',
    end: '2026-09-14',
  });
});
