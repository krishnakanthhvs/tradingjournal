const { test } = require('node:test');
const assert = require('node:assert/strict');
test(
  'authenticated journal workflow, ownership, PDF and settings',
  { skip: process.env.RUN_INTEGRATION !== '1' },
  async () => {
    const app = require('../server'),
      pool = require('../lib/db');
    const server = app.listen(0);
    await new Promise((r) => server.once('listening', r));
    const url = `http://localhost:${server.address().port}`,
      suffix = Date.now(),
      emails = [
        `qa-${suffix}@example.invalid`,
        `qa-other-${suffix}@example.invalid`,
        `qa-updated-${suffix}@example.invalid`,
      ];
    const mailer = require('../lib/email'),
      originalSend = mailer.sendAccountEmail,
      messages = [];
    mailer.sendAccountEmail = async (to, subject, text) => {
      messages.push({ to, subject, text });
      return { id: 'stub' };
    };
    let cookie = '',
      otherCookie = '';
    async function request(path, method = 'GET', body, token = cookie) {
      const r = await fetch(url + path, {
        method,
        headers: { 'Content-Type': 'application/json', Cookie: token },
        body: body ? JSON.stringify(body) : undefined,
        redirect: 'manual',
      });
      const data = r.headers.get('content-type')?.includes('json')
        ? await r.json()
        : await r.arrayBuffer();
      return { status: r.status, data, cookie: r.headers.get('set-cookie'), headers: r.headers };
    }
    try {
      assert.equal((await request('/api/dashboard?month=2026-01')).status, 401);
      const user = await request('/api/auth/register', 'POST', {
        email: emails[0],
        password: 'test-only-password',
      });
      assert.equal(user.status, 200, JSON.stringify(user.data));
      cookie = user.cookie.split(';')[0];
      const user2 = await request(
        '/api/auth/register',
        'POST',
        { email: emails[1], password: 'test-only-password' },
        '',
      );
      assert.equal(user2.status, 200);
      otherCookie = user2.cookie.split(';')[0];
      assert.equal((await request('/api/auth/me')).data.user.email, emails[0]);
      const settings = await request('/api/settings');
      assert.equal(settings.data.weekly_email, false);
      assert.equal(
        (
          await request('/api/settings', 'PUT', {
            display_name: 'QA test',
            weekly_email: false,
            show_ticker: false,
            default_lot_size: 25,
            risk_per_trade: 1.5,
          })
        ).status,
        200,
      );
      assert.equal(
        (await request('/api/capital', 'POST', { yearMonth: '2026-01', capital: 100000 })).status,
        200,
      );
      const strategy = await request('/api/strategies', 'POST', { name: 'QA <script> strategy' });
      assert.equal(strategy.status, 201);
      const body = {
        symbol: 'NIFTY',
        instrument_type: 'Equity',
        side: 'Short',
        entry_price: 120,
        exit_price: 100,
        quantity: 2,
        lot_size: 25,
        fees: 50,
        trade_date: '2026-01-12',
        strategy_id: strategy.data.id,
        stop_loss: 130,
        target_price: 90,
      };
      const create = await request('/api/trades', 'POST', body);
      assert.equal(create.status, 201, JSON.stringify(create.data));
      assert.equal(Number(create.data.trade.pnl), 950);
      const id = create.data.trade.id;
      assert.equal((await request('/api/trades', 'POST', body, otherCookie)).status, 400);
      assert.equal((await request(`/api/trades/${id}`, 'PUT', body, otherCookie)).status, 400);
      assert.equal((await request(`/api/trades/${id}`, 'DELETE', null, otherCookie)).status, 404);
      assert.equal(
        (await request('/api/dashboard?month=2026-01', 'GET', null, otherCookie)).data.summary
          .count,
        0,
      );
      const challenge = await request('/api/challenges', 'POST', {
        name: 'QA goal',
        target: 35000,
        start_date: '2026-01-01',
        end_date: '2026-01-31',
      });
      assert.equal(challenge.status, 201);
      assert.equal(Number((await request('/api/challenges')).data[0].progress), 950);
      const cid = challenge.data.id,
        edit = {
          name: 'Updated goal',
          target: 40000,
          start_date: '2026-01-01',
          end_date: '2026-01-31',
          acknowledge_lock: true,
        };
      assert.equal((await request(`/api/challenges/${cid}`, 'GET', null, otherCookie)).status, 404);
      const breakdown = await request(`/api/challenges/${cid}`);
      assert.equal(breakdown.data.trades.length, 1);
      assert.equal(breakdown.data.summary.net, 950);
      assert.equal(
        (await request(`/api/challenges/${cid}`, 'PUT', { ...edit, acknowledge_lock: false }))
          .status,
        400,
      );
      const edits = await Promise.all([
        request(`/api/challenges/${cid}`, 'PUT', edit),
        request(`/api/challenges/${cid}`, 'PUT', edit),
      ]);
      assert.deepEqual(edits.map((r) => r.status).sort(), [200, 409]);
      assert.ok((await request(`/api/challenges/${cid}`)).data.challenge.edited_at);
      assert.equal((await request(`/api/challenges/${cid}`, 'PUT', edit)).status, 409);
      assert.equal(
        (
          await request('/api/account/email/request', 'POST', {
            email: emails[2],
            password: 'wrong',
          })
        ).status,
        400,
      );
      assert.equal(
        (
          await request('/api/account/email/request', 'POST', {
            email: emails[2],
            password: 'test-only-password',
          })
        ).status,
        200,
      );
      assert.equal((await request('/api/auth/me')).data.user.email, emails[0]);
      assert.equal(messages.at(-1).to, emails[2]);
      const code = messages.at(-1).text.match(/\b\d{6}\b/)[0];
      assert.equal(
        (await request('/api/account/email/confirm', 'POST', { code: '000000' })).status,
        400,
      );
      assert.equal((await request('/api/account/email/confirm', 'POST', { code })).status, 200);
      assert.equal((await request('/api/auth/me')).data.user.email, emails[2]);
      assert.equal((await request('/api/account/email/confirm', 'POST', { code })).status, 400);
      assert.equal(
        (await request('/api/account/email/test', 'POST', { email: 'ignored@example.invalid' }))
          .status,
        200,
      );
      assert.equal(messages.at(-1).to, emails[2]);

      assert.equal(
        (await request(`/api/trades/${id}`, 'PUT', { ...body, exit_price: 90 })).status,
        200,
      );
      assert.equal((await request('/api/dashboard?month=2026-01')).data.summary.net, 1450);
      const pdf = await request('/api/reports/monthly.pdf?month=2026-01');
      assert.equal(pdf.status, 200);
      assert.equal(Buffer.from(pdf.data).subarray(0, 4).toString(), '%PDF');
      assert.equal((await request('/api/external/trades', 'POST', body, '')).status, 401);
      assert.equal((await request('/api/dashboard?month=invalid')).status, 400);
      assert.equal((await request(`/api/trades/${id}`, 'DELETE')).status, 200);
      assert.equal(Number((await request('/api/challenges')).data[0].progress), 0);
      assert.equal((await request(`/api/challenges/${challenge.data.id}`, 'DELETE')).status, 200);
      assert.equal((await request(`/api/strategies/${strategy.data.id}`, 'DELETE')).status, 200);
      await request('/api/auth/logout', 'POST');
      assert.equal((await request('/api/auth/me')).data.loggedIn, false);
    } finally {
      mailer.sendAccountEmail = originalSend;
      await pool.query(
        "DELETE FROM session WHERE sess::jsonb->>'userId' IN (SELECT id::text FROM users WHERE email=ANY($1))",
        [emails],
      );
      await pool.query('DELETE FROM users WHERE email=ANY($1)', [emails]);
      await new Promise((r) => server.close(r));
      await pool.end();
    }
  },
);
