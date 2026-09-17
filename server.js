require('dotenv').config({ quiet: true });
const express = require('express');
const session = require('express-session');
const bcrypt = require('bcryptjs');
const path = require('path');
const crypto = require('crypto');
const pool = require('./lib/db');
const { trade, summary, date, number, fail } = require('./lib/domain');
const email = require('./lib/email');
const app = express();
const production = process.env.NODE_ENV === 'production';
if (production && !process.env.SESSION_SECRET)
  throw new Error('SESSION_SECRET is required in production');
if (process.env.TRUST_PROXY === '1') app.set('trust proxy', 1);
app.disable('x-powered-by');
app.use(express.json({ limit: '32kb' }));
app.use((req, res, next) => {
  res.setHeader('X-Content-Type-Options', 'nosniff');
  res.setHeader('Referrer-Policy', 'same-origin');
  res.setHeader('X-Frame-Options', 'SAMEORIGIN');
  if (req.path.startsWith('/api/')) res.setHeader('Cache-Control', 'no-store');
  if (!['GET', 'HEAD', 'OPTIONS'].includes(req.method) && req.headers.origin) {
    try {
      if (new URL(req.headers.origin).host !== req.get('host'))
        return res.status(403).json({ error: 'Cross-origin request blocked' });
    } catch {
      return res.status(403).json({ error: 'Invalid origin' });
    }
  }
  next();
});
const PgStore = require('connect-pg-simple')(session);
app.use(
  session({
    name: 'tj.sid',
    store: new PgStore({ pool, createTableIfMissing: true }),
    secret: process.env.SESSION_SECRET || crypto.randomBytes(32).toString('hex'),
    resave: false,
    saveUninitialized: false,
    cookie: { httpOnly: true, sameSite: 'lax', secure: production, maxAge: 7 * 86400000 },
  }),
);
const wrap = (fn) => (req, res, next) => Promise.resolve(fn(req, res, next)).catch(next);
const auth = (req, res, next) =>
  req.session.userId ? next() : res.status(401).json({ error: 'Please sign in to continue' });
const { rateLimit } = require('express-rate-limit');
app.use(
  '/api/auth',
  rateLimit({
    windowMs: 15 * 60 * 1000,
    limit: 60,
    standardHeaders: 'draft-7',
    legacyHeaders: false,
    message: { error: 'Too many attempts. Try again in 15 minutes.' },
  }),
);
const setSession = (req, id) =>
  new Promise((resolve, reject) =>
    req.session.regenerate((e) => {
      if (e) return reject(e);
      req.session.userId = id;
      req.session.save((e) => (e ? reject(e) : resolve()));
    }),
  );
for (const mode of ['login', 'register'])
  app.post(
    `/api/auth/${mode}`,
    wrap(async (req, res) => {
      const address = String(req.body.email || '')
          .trim()
          .toLowerCase(),
        password = String(req.body.password || '');
      if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(address) || address.length > 255)
        fail('Enter a valid email address');
      if (
        (mode === 'register' && password.length < 8) ||
        !password.length ||
        Buffer.byteLength(password) > 72
      )
        fail('Use a password of 8–72 bytes');
      let user;
      if (mode === 'register') {
        const hash = await bcrypt.hash(password, 12);
        try {
          user = (
            await pool.query(
              'INSERT INTO users(email,password_hash) VALUES($1,$2) RETURNING id,email',
              [address, hash],
            )
          ).rows[0];
        } catch (e) {
          if (e.code === '23505') fail('An account already exists with this email');
          throw e;
        }
      } else {
        user = (
          await pool.query('SELECT id,email,password_hash FROM users WHERE lower(email)=$1', [
            address,
          ])
        ).rows[0];
        if (!user || !(await bcrypt.compare(password, user.password_hash)))
          return res.status(400).json({ error: 'Email or password is incorrect' });
      }
      await setSession(req, user.id);
      res.json({ success: true, user: { id: user.id, email: user.email } });
    }),
  );
app.post('/api/auth/logout', (req, res, next) =>
  req.session.destroy((e) => {
    if (e) return next(e);
    res.clearCookie('tj.sid');
    res.json({ success: true });
  }),
);
app.get(
  '/api/auth/me',
  wrap(async (req, res) => {
    if (!req.session.userId) return res.json({ loggedIn: false });
    const user = (await pool.query('SELECT id,email FROM users WHERE id=$1', [req.session.userId]))
      .rows[0];
    res.json({ loggedIn: !!user, user });
  }),
);
app.use('/api', auth);
const monthValue = (v) => {
  if (!/^\d{4}-(0[1-9]|1[0-2])$/.test(v || '')) fail('Select a valid month');
  return v;
};
async function monthTrades(id, month) {
  return (
    await pool.query(
      `SELECT t.*,s.name AS strategy_name FROM trades t LEFT JOIN strategies s ON s.id=t.strategy_id WHERE t.user_id=$1 AND t.trade_date >= $2::date AND t.trade_date < $2::date+interval '1 month' ORDER BY t.trade_date DESC,t.id DESC`,
      [id, month + '-01'],
    )
  ).rows;
}
app.get(
  '/api/dashboard',
  wrap(async (req, res) => {
    const month = monthValue(req.query.month),
      id = req.session.userId;
    const [trades, cap, year] = await Promise.all([
      monthTrades(id, month),
      pool.query('SELECT capital FROM monthly_capitals WHERE user_id=$1 AND year_month=$2', [
        id,
        month,
      ]),
      pool.query(
        `SELECT to_char(trade_date,'YYYY-MM') AS month,sum(pnl) AS pnl FROM trades WHERE user_id=$1 AND trade_date >= $2::date AND trade_date < $2::date + interval '1 year' GROUP BY 1 ORDER BY 1`,
        [id, month.slice(0, 4) + '-01-01'],
      ),
    ]);
    res.json({
      monthTrades: trades,
      summary: summary(trades),
      monthlyCapital: Number(cap.rows[0]?.capital || 0),
      yearlyPnL: year.rows,
    });
  }),
);
app.post(
  '/api/capital',
  wrap(async (req, res) => {
    const month = monthValue(req.body.yearMonth),
      capital = number(req.body.capital, 'capital', 0, 9999999999);
    await pool.query(
      'INSERT INTO monthly_capitals(user_id,year_month,capital) VALUES($1,$2,$3) ON CONFLICT(user_id,year_month) DO UPDATE SET capital=EXCLUDED.capital',
      [req.session.userId, month, capital],
    );
    res.json({ success: true });
  }),
);
app.get(
  '/api/strategies',
  wrap(async (req, res) =>
    res.json(
      (
        await pool.query(
          'SELECT * FROM strategies WHERE user_id=$1 OR user_id IS NULL ORDER BY id',
          [req.session.userId],
        )
      ).rows,
    ),
  ),
);
app.post(
  '/api/strategies',
  wrap(async (req, res) => {
    const name = String(req.body.name || '').trim(),
      description = String(req.body.description || '').trim();
    if (!name || name.length > 100 || description.length > 2000)
      fail('Enter a strategy name (up to 100 characters)');
    res
      .status(201)
      .json(
        (
          await pool.query(
            'INSERT INTO strategies(user_id,name,description) VALUES($1,$2,$3) RETURNING *',
            [req.session.userId, name, description],
          )
        ).rows[0],
      );
  }),
);
app.delete(
  '/api/strategies/:id',
  wrap(async (req, res) => {
    const r = await pool.query('DELETE FROM strategies WHERE id=$1 AND user_id=$2 RETURNING id', [
      req.params.id,
      req.session.userId,
    ]);
    if (!r.rowCount)
      return res.status(404).json({ error: 'Strategy not found or is a built-in strategy' });
    res.json({ success: true });
  }),
);
const fields = [
  'symbol',
  'instrument_type',
  'expiry_date',
  'entry_price',
  'exit_price',
  'quantity',
  'lot_size',
  'pnl',
  'strategy_id',
  'trade_date',
  'entry_time',
  'exit_time',
  'market_close_strike',
  'notes',
  'side',
  'fees',
  'stop_loss',
  'target_price',
];
async function saveTrade(req, res) {
  const t = trade(req.body),
    id = req.session.userId;
  if (t.strategy_id) {
    const found = await pool.query(
      'SELECT id FROM strategies WHERE id=$1 AND (user_id=$2 OR user_id IS NULL)',
      [t.strategy_id, id],
    );
    if (!found.rowCount) fail('Choose one of your strategies');
  }
  const values = fields.map((k) => (t[k] === '' || t[k] === undefined ? null : t[k]));
  let result;
  if (req.params.id)
    result = await pool.query(
      `UPDATE trades SET ${fields.map((k, i) => `${k}=$${i + 1}`).join(',')} WHERE id=$19 AND user_id=$20 RETURNING *`,
      [...values, req.params.id, id],
    );
  else
    result = await pool.query(
      `INSERT INTO trades(${fields.join(',')},user_id) VALUES(${[...values, id].map((_, i) => '$' + (i + 1)).join(',')}) RETURNING *`,
      [...values, id],
    );
  if (!result.rowCount) return res.status(404).json({ error: 'Trade not found' });
  res.status(req.params.id ? 200 : 201).json({ trade: result.rows[0] });
}
app.post('/api/trades', wrap(saveTrade));
app.put('/api/trades/:id', wrap(saveTrade));
app.delete(
  '/api/trades/:id',
  wrap(async (req, res) => {
    const r = await pool.query('DELETE FROM trades WHERE id=$1 AND user_id=$2 RETURNING id', [
      req.params.id,
      req.session.userId,
    ]);
    if (!r.rowCount) return res.status(404).json({ error: 'Trade not found' });
    res.json({ success: true });
  }),
);
app.get(
  '/api/settings',
  wrap(async (req, res) => {
    const id = req.session.userId;
    await pool.query('INSERT INTO user_settings(user_id) VALUES($1) ON CONFLICT DO NOTHING', [id]);
    const settings = (await pool.query('SELECT * FROM user_settings WHERE user_id=$1', [id]))
      .rows[0];
    const last = (
      await pool.query('SELECT max(sent_at) AS sent_at FROM email_deliveries WHERE user_id=$1', [
        id,
      ])
    ).rows[0];
    res.json({ ...settings, emailConfigured: email.configured(), lastEmail: last.sent_at });
  }),
);
app.put(
  '/api/settings',
  wrap(async (req, res) => {
    const b = req.body,
      name = String(b.display_name || '').trim();
    if (name.length > 80) fail('Name is too long');
    if (typeof b.weekly_email !== 'boolean' || typeof b.show_ticker !== 'boolean')
      fail('Invalid preferences');
    const risk = number(b.risk_per_trade, 'risk percentage', 0.1, 100),
      lot = number(b.default_lot_size, 'lot size', 1, 100000);
    if (!Number.isInteger(lot)) fail('Lot size must be a whole number');
    await pool.query(
      `INSERT INTO user_settings(user_id,display_name,weekly_email,risk_per_trade,default_lot_size,show_ticker) VALUES($1,$2,$3,$4,$5,$6) ON CONFLICT(user_id) DO UPDATE SET display_name=$2,weekly_email=$3,risk_per_trade=$4,default_lot_size=$5,show_ticker=$6`,
      [req.session.userId, name, b.weekly_email, risk, lot, b.show_ticker],
    );
    res.json({ success: true });
  }),
);
const accountEmailLimit = rateLimit({
  windowMs: 60 * 60 * 1000,
  limit: 10,
  keyGenerator: (req) => String(req.session.userId),
  standardHeaders: 'draft-7',
  legacyHeaders: false,
  message: { error: 'Too many email requests. Please try again in an hour.' },
});
app.post(
  '/api/account/email/request',
  accountEmailLimit,
  wrap(async (req, res) => {
    const address = String(req.body.email || '')
      .trim()
      .toLowerCase();
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(address) || address.length > 255)
      fail('Enter a valid email address');
    const user = (
      await pool.query('SELECT email,password_hash FROM users WHERE id=$1', [req.session.userId])
    ).rows[0];
    if (!(await bcrypt.compare(String(req.body.password || ''), user.password_hash)))
      fail('Current password is incorrect');
    if (address === user.email.toLowerCase())
      fail('This is already your registered email. Use Send test email instead.');
    if ((await pool.query('SELECT id FROM users WHERE lower(email)=$1', [address])).rowCount)
      fail('This email is unavailable');
    const code = String(crypto.randomInt(100000, 1000000));
    const hash = crypto.createHash('sha256').update(code).digest('hex');
    // Persist before sending: an ambiguous provider response must not invalidate a received code.
    await pool.query(
      `INSERT INTO email_changes(user_id,email,code_hash,expires_at,attempts) VALUES($1,$2,$3,now()+interval '10 minutes',0) ON CONFLICT(user_id) DO UPDATE SET email=$2,code_hash=$3,expires_at=EXCLUDED.expires_at,attempts=0`,
      [req.session.userId, address, hash],
    );
    await email.sendAccountEmail(
      address,
      'Verify your TradeJournal email',
      `Your verification code is ${code}. It expires in 10 minutes. If you did not request this change, ignore this email.`,
      crypto.randomUUID(),
    );
    res.json({
      success: true,
      message: 'Verification code sent. Your registered email has not changed yet.',
    });
  }),
);
app.post(
  '/api/account/email/confirm',
  accountEmailLimit,
  wrap(async (req, res) => {
    const client = await pool.connect();
    try {
      await client.query('BEGIN');
      const pending = (
        await client.query('SELECT * FROM email_changes WHERE user_id=$1 FOR UPDATE', [
          req.session.userId,
        ])
      ).rows[0];
      if (!pending || new Date(pending.expires_at) < new Date() || pending.attempts >= 5) {
        await client.query('ROLLBACK');
        return res
          .status(400)
          .json({ error: 'Code expired or too many attempts. Request a new code.' });
      }
      const hash = crypto
        .createHash('sha256')
        .update(String(req.body.code || ''))
        .digest('hex');
      if (hash !== pending.code_hash) {
        await client.query('UPDATE email_changes SET attempts=attempts+1 WHERE user_id=$1', [
          req.session.userId,
        ]);
        await client.query('COMMIT');
        return res.status(400).json({ error: 'Incorrect verification code' });
      }
      await client.query('UPDATE users SET email=$2 WHERE id=$1', [
        req.session.userId,
        pending.email,
      ]);
      await client.query('DELETE FROM email_changes WHERE user_id=$1', [req.session.userId]);
      await client.query('COMMIT');
      res.json({ success: true, email: pending.email });
    } catch (e) {
      await client.query('ROLLBACK');
      if (e.code === '23505') return res.status(400).json({ error: 'This email is unavailable' });
      throw e;
    } finally {
      client.release();
    }
  }),
);
app.post(
  '/api/account/email/test',
  accountEmailLimit,
  wrap(async (req, res) => {
    const user = (await pool.query('SELECT email FROM users WHERE id=$1', [req.session.userId]))
      .rows[0];
    await email.sendAccountEmail(
      user.email,
      'Your TradeJournal test email',
      'Your email connection is working. Weekly trade summaries will be sent here when enabled in Settings. This test does not change your weekly email preference.',
      `test-${req.session.userId}-${Math.floor(Date.now() / 60000)}`,
    );
    res.json({
      success: true,
      message: `Test email accepted for ${user.email}. Check your inbox and spam folder.`,
    });
  }),
);
app.put(
  '/api/challenges/:id',
  wrap(async (req, res) => {
    const b = req.body,
      name = String(b.name || '').trim(),
      target = number(b.target, 'target', 1, 9999999999);
    if (!name || name.length > 100) fail('Give your challenge a name');
    if (!date(b.start_date) || !date(b.end_date) || b.end_date < b.start_date)
      fail('Choose a valid challenge period');
    if (b.acknowledge_lock !== true)
      fail('Confirm that this edit will permanently lock the challenge settings');
    const result = await pool.query(
      'UPDATE challenges SET name=$3,target=$4,start_date=$5,end_date=$6,edited_at=now() WHERE id=$1 AND user_id=$2 AND edited_at IS NULL RETURNING *',
      [req.params.id, req.session.userId, name, target, b.start_date, b.end_date],
    );
    if (!result.rowCount)
      return res
        .status(409)
        .json({ error: 'This challenge is locked or unavailable. Only one edit is allowed.' });
    res.json(result.rows[0]);
  }),
);
app.get(
  '/api/challenges/:id',
  wrap(async (req, res) => {
    const challenge = (
      await pool.query('SELECT * FROM challenges WHERE id=$1 AND user_id=$2', [
        req.params.id,
        req.session.userId,
      ])
    ).rows[0];
    if (!challenge) return res.status(404).json({ error: 'Challenge not found' });
    const trades = (
      await pool.query(
        `SELECT t.*,s.name AS strategy_name FROM trades t LEFT JOIN strategies s ON s.id=t.strategy_id WHERE t.user_id=$1 AND t.trade_date >= $2::date AND t.trade_date < $3::date+interval '1 day' ORDER BY t.trade_date ASC,t.id ASC`,
        [req.session.userId, challenge.start_date, challenge.end_date],
      )
    ).rows;
    res.json({ challenge, trades, summary: summary(trades) });
  }),
);
app.get(
  '/api/challenges',
  wrap(async (req, res) =>
    res.json(
      (
        await pool.query(
          `SELECT c.*,COALESCE(sum(t.pnl),0) AS progress,count(t.id) AS trade_count FROM challenges c LEFT JOIN trades t ON t.user_id=c.user_id AND t.trade_date>=c.start_date AND t.trade_date<c.end_date+interval '1 day' WHERE c.user_id=$1 GROUP BY c.id ORDER BY c.end_date DESC`,
          [req.session.userId],
        )
      ).rows,
    ),
  ),
);
app.post(
  '/api/challenges',
  wrap(async (req, res) => {
    const b = req.body,
      name = String(b.name || '').trim(),
      target = number(b.target, 'target', 1, 9999999999);
    if (!name || name.length > 100) fail('Give your challenge a name');
    if (!date(b.start_date) || !date(b.end_date) || b.end_date < b.start_date)
      fail('Choose a valid challenge period');
    res
      .status(201)
      .json(
        (
          await pool.query(
            'INSERT INTO challenges(user_id,name,target,start_date,end_date) VALUES($1,$2,$3,$4,$5) RETURNING *',
            [req.session.userId, name, target, b.start_date, b.end_date],
          )
        ).rows[0],
      );
  }),
);
app.delete(
  '/api/challenges/:id',
  wrap(async (req, res) => {
    const r = await pool.query('DELETE FROM challenges WHERE id=$1 AND user_id=$2 RETURNING id', [
      req.params.id,
      req.session.userId,
    ]);
    if (!r.rowCount) return res.status(404).json({ error: 'Challenge not found' });
    res.json({ success: true });
  }),
);
app.get(
  '/api/reports/monthly.pdf',
  wrap(async (req, res) => {
    const month = monthValue(req.query.month),
      trades = await monthTrades(req.session.userId, month),
      user = (await pool.query('SELECT email FROM users WHERE id=$1', [req.session.userId]))
        .rows[0];
    require('./lib/report')(res, trades, user, month);
  }),
);
app.use('/api', (req, res) => res.status(404).json({ error: 'API route not found' }));
app.get('/quick-add.html', (req, res) => res.redirect('/?add=1'));
app.use(
  '/vendor/bootstrap-icons',
  express.static(path.join(__dirname, 'node_modules/bootstrap-icons/font')),
);
app.use(express.static(path.join(__dirname, 'public')));
app.use((err, req, res, next) => {
  console.error(err.message);
  if (res.headersSent) return next(err);
  res.status(err.status || 500).json({
    error: err.status
      ? err.message
      : 'Unable to complete this request. Check the server and database connection.',
  });
});
if (require.main === module) {
  app.listen(process.env.PORT || 3000, () =>
    console.log(`TradeJournal running on http://localhost:${process.env.PORT || 3000}`),
  );
  const run = () =>
    email.sendWeekly(pool).catch((e) => console.error('Weekly scheduler:', e.message));
  run();
  setInterval(run, 60 * 60 * 1000).unref();
}
module.exports = app;
