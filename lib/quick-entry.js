const crypto = require('crypto');
const { rateLimit } = require('express-rate-limit');
module.exports = function quickEntry(app, { pool, email, wrap, fail, saveTrade }) {
  const router = require('express').Router();
  router.use(
    rateLimit({
      windowMs: 15 * 60000,
      limit: 30,
      standardHeaders: 'draft-7',
      legacyHeaders: false,
      message: { error: 'Too many attempts. Please try again in 15 minutes.' },
    }),
  );
  const digest = (code, nonce) =>
    crypto
      .createHash('sha256')
      .update(code + nonce)
      .digest('hex');
  router.post(
    '/request',
    wrap(async (req, res) => {
      const address = String(req.body.email || '')
        .trim()
        .toLowerCase();
      if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(address) || address.length > 255)
        fail('Enter your registered email address');
      const code = String(crypto.randomInt(100000, 1000000));
      const nonce = crypto.randomBytes(32).toString('hex');
      const user = (await pool.query('SELECT id,email FROM users WHERE lower(email)=$1', [address]))
        .rows[0];
      if (user) {
        const saved = await pool.query(
          `INSERT INTO quick_entry_codes(email,user_id,code_hash,expires_at) VALUES($1,$2,$3,now()+interval '10 minutes') ON CONFLICT(email) DO UPDATE SET user_id=$2,code_hash=$3,expires_at=EXCLUDED.expires_at,attempts=0,requested_at=now() WHERE quick_entry_codes.requested_at < now()-interval '60 seconds' RETURNING user_id`,
          [address, user.id, digest(code, nonce)],
        );
        if (saved.rowCount) {
          req.session.quickEmail = address;
          req.session.quickNonce = nonce;
          delete req.session.quickEntry;
          try {
            await email.sendTemplate(
              address,
              'otp',
              { code, purpose: 'quick-entry' },
              crypto.randomUUID(),
            );
          } catch (e) {
            console.error('Quick-entry email delivery:', e.message);
          }
        }
      }
      res.json({
        message:
          'If this email is registered, a code has been sent. Check your inbox and spam folder. Wait 60 seconds before requesting another code.',
      });
    }),
  );
  router.post(
    '/verify',
    wrap(async (req, res) => {
      const client = await pool.connect();
      try {
        await client.query('BEGIN');
        const row = (
          await client.query('SELECT * FROM quick_entry_codes WHERE email=$1 FOR UPDATE', [
            req.session.quickEmail || '',
          ])
        ).rows[0];
        if (!row || row.attempts >= 5 || new Date(row.expires_at).getTime() <= Date.now()) {
          await client.query('ROLLBACK');
          return res
            .status(400)
            .json({ error: 'Code expired or unavailable. Request another code.' });
        }
        if (row.code_hash !== digest(String(req.body.code || ''), req.session.quickNonce || '')) {
          await client.query('UPDATE quick_entry_codes SET attempts=attempts+1 WHERE email=$1', [
            row.email,
          ]);
          await client.query('COMMIT');
          return res.status(400).json({ error: 'Incorrect code. Please try again.' });
        }
        await client.query('DELETE FROM quick_entry_codes WHERE email=$1', [row.email]);
        await client.query('COMMIT');
        await new Promise((resolve, reject) =>
          req.session.regenerate((e) => (e ? reject(e) : resolve())),
        );
        req.session.quickEntry = {
          userId: row.user_id,
          email: row.email,
          expires: Date.now() + 20 * 60000,
        };
        res.json({ success: true });
      } catch (e) {
        await client.query('ROLLBACK');
        throw e;
      } finally {
        client.release();
      }
    }),
  );
  router.use(
    wrap(async (req, res, next) => {
      const grant = req.session.quickEntry;
      if (!grant || grant.expires <= Date.now())
        return res
          .status(401)
          .json({ error: 'Verify your email to open a 20-minute quick-entry session.' });
      const user = (
        await pool.query('SELECT id FROM users WHERE id=$1 AND lower(email)=$2', [
          grant.userId,
          grant.email,
        ])
      ).rows[0];
      if (!user) return res.status(401).json({ error: 'Email changed. Please verify again.' });
      req.quickUserId = user.id;
      next();
    }),
  );
  router.get(
    '/form',
    wrap(async (req, res) => {
      const strategies = (
        await pool.query(
          'SELECT id,name FROM strategies WHERE user_id=$1 OR user_id IS NULL ORDER BY id',
          [req.quickUserId],
        )
      ).rows;
      res.json({
        strategies,
        expires: req.session.quickEntry.expires,
        email: req.session.quickEntry.email,
      });
    }),
  );
  router.post('/trades', wrap(saveTrade));
  router.post('/end', (req, res) => {
    delete req.session.quickEntry;
    res.json({ success: true });
  });
  router.use((req, res) =>
    res.status(404).json({ error: 'Quick entry only supports adding trades.' }),
  );
  app.use('/api/quick-entry', router);
};
