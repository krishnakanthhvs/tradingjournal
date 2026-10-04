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
            display_name: 'krishna',
            weekly_email: false,
            show_ticker: false,
            default_lot_size: 25,
            risk_per_trade: 1.5,
          })
        ).status,
        200,
      );
      assert.equal((await request('/api/settings')).data.display_name, 'Krishna');
      assert.equal(
        (
          await request('/api/capital', 'POST', {
            yearMonth: '2026-01',
            capital: 100000,
            acknowledge_lock: true,
          })
        ).status,
        200,
      );
      assert.equal((await request('/api/dashboard?month=2026-01')).data.capitalLocked, true);
      assert.equal(
        (
          await request('/api/capital', 'POST', {
            yearMonth: '2026-01',
            capital: 1,
            acknowledge_lock: true,
          })
        ).status,
        409,
      );
      assert.equal((await request('/api/dashboard?month=2026-01')).data.monthlyCapital, 100000);
      assert.equal((await request('/api/dashboard?month=2026-02')).data.capitalLocked, false);
      assert.equal(
        (await request('/api/capital', 'POST', { yearMonth: '2026-02', capital: 0 })).status,
        400,
      );
      // Existing default/legacy rows are not confirmation of a user's starting capital.
      await pool.query(
        "INSERT INTO monthly_capitals(user_id,year_month,capital) SELECT id,'2026-02',10000 FROM users WHERE email=$1",
        [emails[0]],
      );
      const legacyCapital = await request('/api/dashboard?month=2026-02');
      assert.equal(legacyCapital.data.monthlyCapital, 10000);
      assert.equal(legacyCapital.data.capitalLocked, false);
      const capitalRace = await Promise.all(
        [1, 2].map(() =>
          request('/api/capital', 'POST', {
            yearMonth: '2026-02',
            capital: 0,
            acknowledge_lock: true,
          }),
        ),
      );
      assert.deepEqual(capitalRace.map((r) => r.status).sort(), [200, 409]);
      assert.equal((await request('/api/dashboard?month=2026-02')).data.capitalLocked, true);
      assert.equal(
        (
          await request('/api/capital', 'POST', {
            yearMonth: '9999-01',
            capital: 100,
            acknowledge_lock: true,
          })
        ).status,
        400,
      );
      assert.match(messages.at(-1).subject, /Starting capital locked/);
      assert.equal(
        (await request('/api/settings/weekly-email', 'PUT', { enabled: true })).status,
        200,
      );
      assert.match(messages.at(-1).subject, /turned on/);
      const messageCount = messages.length;
      await request('/api/settings/weekly-email', 'PUT', { enabled: true });
      assert.equal(messages.length, messageCount);
      assert.equal((await request('/api/settings')).data.display_name, 'Krishna');
      await request('/api/settings/weekly-email', 'PUT', { enabled: false });
      assert.match(messages.at(-1).subject, /turned off/);
      mailer.sendAccountEmail = async () => {
        throw new Error('Simulated provider outage');
      };
      const savedDespiteEmail = await request('/api/settings/weekly-email', 'PUT', {
        enabled: true,
      });
      assert.equal(savedDespiteEmail.status, 200);
      assert.equal(savedDespiteEmail.data.emailSent, false);
      assert.equal((await request('/api/settings')).data.weekly_email, true);
      mailer.sendAccountEmail = async (to, subject, text) => {
        messages.push({ to, subject, text });
        return { id: 'stub' };
      };
      await request('/api/settings/weekly-email', 'PUT', { enabled: false });
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
      const annual = await request('/api/dashboard?month=2026-01&year=2025');
      assert.equal(annual.data.summary.net, 950);
      assert.equal(annual.data.monthlyCapital, 100000);
      assert.deepEqual(annual.data.yearlyPnL, []);
      assert.deepEqual(annual.data.availableYears, [2026]);
      const thisYear = await request('/api/dashboard?month=2026-02&year=2026');
      assert.equal(thisYear.data.summary.count, 0);
      assert.equal(Number(thisYear.data.yearlyPnL[0].pnl), 950);
      assert.equal((await request('/api/dashboard?month=2026-01&year=invalid')).status, 400);
      const privateYear = await request(
        '/api/dashboard?month=2026-01&year=2026',
        'GET',
        null,
        otherCookie,
      );
      assert.deepEqual(privateYear.data.yearlyPnL, []);
      assert.deepEqual(privateYear.data.availableYears, []);
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
      // Editing never renews the six-hour deletion window; ownership still stays private.
      await pool.query("UPDATE trades SET recorded_at=now()-interval '7 hours' WHERE id=$1", [id]);
      assert.equal((await request(`/api/trades/${id}`, 'DELETE')).status, 409);
      assert.equal((await request(`/api/trades/${id}`, 'PUT', body)).status, 200);
      assert.equal((await request(`/api/trades/${id}`, 'DELETE')).status, 409);
      assert.equal((await request(`/api/trades/${id}`, 'DELETE', null, otherCookie)).status, 404);
      await pool.query('UPDATE trades SET recorded_at=now() WHERE id=$1', [id]);
      assert.equal((await request(`/api/trades/${id}`, 'DELETE')).status, 200);
      assert.equal(Number((await request('/api/challenges')).data[0].progress), 0);
      assert.equal((await request(`/api/challenges/${challenge.data.id}`, 'DELETE')).status, 200);
      assert.equal((await request(`/api/strategies/${strategy.data.id}`, 'DELETE')).status, 200);
      // Passwordless verification grants create-only access, not an authenticated account.
      const quick = await request('/api/quick-entry/request', 'POST', { email: emails[2] }, '');
      assert.equal(quick.status, 200);
      let quickCookie = quick.cookie.split(';')[0];
      const quickCode = messages.at(-1).text.match(/\b\d{6}\b/)[0];
      assert.equal((await request('/api/quick-entry/form', 'GET', null, quickCookie)).status, 401);
      assert.equal(
        (await request('/api/quick-entry/verify', 'POST', { code: '000000' }, quickCookie)).status,
        400,
      );
      const verified = await request(
        '/api/quick-entry/verify',
        'POST',
        { code: quickCode },
        quickCookie,
      );
      assert.equal(verified.status, 200, JSON.stringify(verified.data));
      quickCookie = verified.cookie.split(';')[0];
      assert.equal(
        (await request('/api/quick-entry/verify', 'POST', { code: quickCode }, quickCookie)).status,
        400,
      );
      assert.equal((await request('/api/quick-entry/form', 'GET', null, quickCookie)).status, 200);
      assert.equal((await request('/api/auth/me', 'GET', null, quickCookie)).data.loggedIn, false);
      assert.equal(
        (await request('/api/dashboard?month=2026-09', 'GET', null, quickCookie)).status,
        401,
      );
      const quickTrade = await request(
        '/api/quick-entry/trades',
        'POST',
        {
          ...body,
          trade_date: '2026-09-20',
          strategy_id: null,
          broker: 'Sahi',
          fee_mode: 'estimate',
          exchange: 'NSE',
          product: 'intraday',
          brokerage_plan: 'standard',
          fees: 0,
          recorded_at: '2099-01-01',
        },
        quickCookie,
      );
      assert.equal(quickTrade.status, 201, JSON.stringify(quickTrade.data));
      assert.equal(quickTrade.data.trade.broker, 'Sahi');
      assert.ok(Number(quickTrade.data.trade.fees) > 0);
      assert.equal(Number(quickTrade.data.trade.pnl) + Number(quickTrade.data.trade.fees), 1000);
      assert.ok(new Date(quickTrade.data.trade.recorded_at).getTime() < Date.now() + 1000);
      assert.equal(
        (await request(`/api/trades/${quickTrade.data.trade.id}`, 'DELETE', null, quickCookie))
          .status,
        401,
      );
      assert.equal((await request('/api/quick-entry/end', 'POST', {}, quickCookie)).status, 200);
      assert.equal(
        (await request('/api/quick-entry/trades', 'POST', body, quickCookie)).status,
        401,
      );
      const unknown = await request(
        '/api/quick-entry/request',
        'POST',
        { email: 'unregistered-test@example.invalid' },
        '',
      );
      assert.equal(unknown.status, 200);
      assert.equal(unknown.data.message, quick.data.message);
      await request('/api/auth/logout', 'POST');
      assert.equal((await request('/api/auth/me')).data.loggedIn, false);
    } finally {
      mailer.sendAccountEmail = originalSend;
      await pool.query(
        "DELETE FROM session WHERE (sess::jsonb->>'userId' IN (SELECT id::text FROM users WHERE email=ANY($1)) OR sess::jsonb->'quickEntry'->>'userId' IN (SELECT id::text FROM users WHERE email=ANY($1)))",
        [emails],
      );
      await pool.query('DELETE FROM users WHERE email=ANY($1)', [emails]);
      await new Promise((r) => server.close(r));
      await pool.end();
    }
  },
);
