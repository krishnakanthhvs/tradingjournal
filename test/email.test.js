const { test } = require('node:test');
const assert = require('node:assert/strict');
const { sendWeekly } = require('../lib/email');
test('weekly delivery is recorded once, uses registered recipient and never sends without configuration', async () => {
  const oldKey = process.env.RESEND_API_KEY,
    oldFrom = process.env.EMAIL_FROM,
    oldFetch = global.fetch;
  let sent = 0,
    record,
    queries = [];
  const client = {
    query: async (sql, args) => {
      queries.push(sql);
      if (sql.includes('pg_try_advisory_lock')) return { rows: [{ locked: true }] };
      if (sql.includes('JOIN user_settings'))
        return { rows: [{ id: 42, email: 'registered@example.invalid' }] };
      if (sql.startsWith('SELECT * FROM email_deliveries')) return { rows: record ? [record] : [] };
      if (sql.startsWith('SELECT pnl'))
        return { rows: [{ id: 1, pnl: 100, fees: 10, trade_date: '2026-09-08' }] };
      if (sql.startsWith('INSERT INTO email_deliveries')) record = { payload: args[2] };
      if (sql.includes('SET attempted_at')) record.attempted_at = '2026-09-14T01:00:00Z';
      if (sql.includes('SET sent_at')) record.sent_at = '2026-09-14T01:00:01Z';
      return { rows: [] };
    },
    release: () => {},
  };
  const pool = { connect: async () => client };
  try {
    delete process.env.RESEND_API_KEY;
    assert.deepEqual(await sendWeekly(pool), { configured: false, sent: 0 });
    process.env.RESEND_API_KEY = 'test-only';
    process.env.EMAIL_FROM = 'test@example.invalid';
    global.fetch = async (url, options) => {
      sent++;
      assert.equal(url, 'https://api.resend.com/emails');
      assert.deepEqual(JSON.parse(options.body).to, ['registered@example.invalid']);
      assert.equal(options.headers['Idempotency-Key'], 'weekly-42-2026-09-07');
      return { ok: true, json: async () => ({ id: 'test-provider-id' }) };
    };
    const now = new Date('2026-09-14T01:00:00Z');
    assert.equal((await sendWeekly(pool, now)).sent, 1);
    assert.equal((await sendWeekly(pool, now)).sent, 0);
    assert.equal(sent, 1);
    assert.ok(queries.some((q) => q.includes('s.weekly_email=true')));
  } finally {
    global.fetch = oldFetch;
    if (oldKey === undefined) delete process.env.RESEND_API_KEY;
    else process.env.RESEND_API_KEY = oldKey;
    if (oldFrom === undefined) delete process.env.EMAIL_FROM;
    else process.env.EMAIL_FROM = oldFrom;
  }
});
