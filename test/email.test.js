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
      const payload = JSON.parse(options.body);
      assert.deepEqual(payload.to, ['registered@example.invalid']);
      assert.match(payload.html, /Your week, in perspective/);
      assert.equal(payload.attachments[0].filename, 'tradejournal-week-2026-09-07.pdf');
      assert.equal(
        Buffer.from(payload.attachments[0].content, 'base64').subarray(0, 4).toString(),
        '%PDF',
      );
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

test('SMTP uses TLS, registered destination and Monday–Friday subject', async () => {
  const nodemailer = require('nodemailer');
  const email = require('../lib/email');
  const original = nodemailer.createTransport;
  const names = ['SMTP_HOST', 'SMTP_PORT', 'SMTP_SECURE', 'SMTP_USER', 'SMTP_PASS', 'EMAIL_FROM'];
  const saved = Object.fromEntries(names.map((name) => [name, process.env[name]]));
  let closed = false;
  try {
    Object.assign(process.env, {
      SMTP_HOST: 'smtp.example.invalid',
      SMTP_PORT: '465',
      SMTP_SECURE: 'true',
      SMTP_USER: 'test@example.invalid',
      SMTP_PASS: 'test-only',
      EMAIL_FROM: 'test@example.invalid',
    });
    nodemailer.createTransport = (options) => {
      assert.equal(options.secure, true);
      assert.equal(options.requireTLS, true);
      return {
        sendMail: async (payload) => {
          assert.deepEqual(payload.to, ['recipient@example.invalid']);
          assert.equal(
            payload.subject,
            'Trading Journal - Week 28 September 2026 to 2 October 2026',
          );
          return { accepted: payload.to, rejected: [], messageId: 'smtp-test' };
        },
        close: () => {
          closed = true;
        },
      };
    };
    const result = await email.sendAccountEmail(
      'recipient@example.invalid',
      email.weekSubject('2026-09-28'),
      'Test',
    );
    assert.equal(result.id, 'smtp-test');
    assert.equal(closed, true);
  } finally {
    nodemailer.createTransport = original;
    for (const name of names) {
      if (saved[name] === undefined) delete process.env[name];
      else process.env[name] = saved[name];
    }
  }
});
