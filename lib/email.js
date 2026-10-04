const { summary, weekRange } = require('./domain');
const money = (n) => `INR ${Number(n).toLocaleString('en-IN', { maximumFractionDigits: 2 })}`;
const smtpConfigured = () =>
  Boolean(process.env.SMTP_HOST && process.env.SMTP_USER && process.env.SMTP_PASS);
const configured = () =>
  Boolean(process.env.EMAIL_FROM && (smtpConfigured() || process.env.RESEND_API_KEY));
function weekSubject(start) {
  const monday = new Date(start + 'T00:00:00Z');
  const friday = new Date(monday);
  friday.setUTCDate(friday.getUTCDate() + 4);
  const format = (date) =>
    date.toLocaleDateString('en-GB', {
      day: 'numeric',
      month: 'long',
      year: 'numeric',
      timeZone: 'UTC',
    });
  return 'Trading Journal - Week ' + format(monday) + ' to ' + format(friday);
}
async function deliver(payload, key) {
  if (!configured()) {
    const error = new Error(
      'Email delivery is not configured. Configure SMTP or an email provider and sender on the server.',
    );
    error.status = 503;
    throw error;
  }
  if (smtpConfigured()) {
    const transport = require('nodemailer').createTransport({
      host: process.env.SMTP_HOST,
      port: Number(process.env.SMTP_PORT || 465),
      secure: process.env.SMTP_SECURE !== 'false',
      requireTLS: true,
      auth: { user: process.env.SMTP_USER, pass: process.env.SMTP_PASS },
      connectionTimeout: 15000,
      greetingTimeout: 15000,
      socketTimeout: 20000,
      disableFileAccess: true,
      disableUrlAccess: true,
    });
    try {
      const result = await transport.sendMail(payload);
      if (!result.accepted?.length || result.rejected?.length)
        throw new Error('Recipient rejected');
      return { id: result.messageId };
    } catch {
      const error = new Error(
        'SMTP delivery failed. Check the SMTP credentials, sender, and connection.',
      );
      error.status = 502;
      throw error;
    } finally {
      transport.close();
    }
  }
  const response = await fetch('https://api.resend.com/emails', {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${process.env.RESEND_API_KEY}`,
      'Content-Type': 'application/json',
      'Idempotency-Key': key,
    },
    body: JSON.stringify(payload),
    signal: AbortSignal.timeout(15000),
  });
  if (!response.ok) {
    const error = new Error(
      'The email provider could not accept the message. Check the sender configuration and try again.',
    );
    error.status = 502;
    throw error;
  }
  return response.json();
}
async function sendWeekly(pool, now = new Date()) {
  if (!configured()) return { configured: false, sent: 0 };
  const client = await pool.connect();
  let sent = 0;
  try {
    const lock = await client.query('SELECT pg_try_advisory_lock(73519024) AS locked');
    if (!lock.rows[0].locked) return { sent: 0 };
    const { start } = weekRange(now);
    const saturday = new Date(start + 'T00:00:00Z');
    saturday.setUTCDate(saturday.getUTCDate() + 5);
    const end = saturday.toISOString().slice(0, 10);
    const users = await client.query(
      'SELECT u.id,u.email FROM users u JOIN user_settings s ON s.user_id=u.id WHERE s.weekly_email=true',
    );
    for (const u of users.rows) {
      const existing = await client.query(
        'SELECT * FROM email_deliveries WHERE user_id=$1 AND week_start=$2',
        [u.id, start],
      );
      if (existing.rows[0]?.sent_at) continue;
      // SMTP cannot deduplicate an uncertain delivery. Require review instead of resending.
      if (smtpConfigured() && existing.rows[0]?.attempted_at) {
        console.error('Weekly SMTP delivery requires reconciliation for user', u.id, start);
        continue;
      }
      // Resend retains idempotency keys for 24 hours. Stop uncertain retries before expiry.
      if (
        existing.rows[0]?.attempted_at &&
        now - new Date(existing.rows[0].attempted_at) > 23 * 60 * 60 * 1000
      ) {
        console.error('Weekly delivery requires provider reconciliation for user', u.id, start);
        continue;
      }
      let payload = existing.rows[0]?.payload;
      if (!payload) {
        const trades = await client.query(
          'SELECT pnl,fees,trade_date,id FROM trades WHERE user_id=$1 AND trade_date >= $2::date AND trade_date < $3::date',
          [u.id, start, end],
        );
        const s = summary(trades.rows);
        payload = {
          from: process.env.EMAIL_FROM,
          to: [u.email],
          subject: weekSubject(start),
          html: `<div style="font-family:Arial;color:#152b35;max-width:560px;margin:30px auto"><h2>TradeJournal / Weekly review</h2><p>${weekSubject(start)} · Monday–Friday, Asia/Kolkata</p><h1>${money(s.net)}</h1><p>Net realised P&amp;L after fees</p><hr><p>${s.count} trades · ${s.wins} wins · ${s.losses} losses · ${s.winRate.toFixed(1)}% win rate</p><p>Gross P&amp;L: ${money(s.gross)}</p><p>Charges: ${money(s.fees)} · Average trade: ${money(s.average)}</p><p>Take a moment to review your decisions, not just your results.</p><p style="font-size:12px;color:#687b82">You enabled weekly summaries in TradeJournal. Turn them off anytime in Settings → Weekly email summary.</p></div>`,
        };
        await client.query(
          'INSERT INTO email_deliveries(user_id,week_start,payload) VALUES($1,$2,$3) ON CONFLICT DO NOTHING',
          [u.id, start, payload],
        );
      }
      try {
        await client.query(
          'UPDATE email_deliveries SET attempted_at=COALESCE(attempted_at,now()) WHERE user_id=$1 AND week_start=$2',
          [u.id, start],
        );
        const result = await deliver(payload, `weekly-${u.id}-${start}`);
        await client.query(
          'UPDATE email_deliveries SET sent_at=now(),provider_id=$3 WHERE user_id=$1 AND week_start=$2',
          [u.id, start, result.id],
        );
        sent++;
      } catch (e) {
        console.error('Weekly delivery failed for user', u.id, e.message);
      }
    }
    return { configured: true, sent };
  } finally {
    await client.query('SELECT pg_advisory_unlock(73519024)');
    client.release();
  }
}
module.exports = { sendWeekly, configured, weekSubject };

// Account messages are separate from weekly opt-in and never contain trade data.
module.exports.sendAccountEmail = async function (to, subject, text, key) {
  return deliver({ from: process.env.EMAIL_FROM, to: [to], subject, text }, key);
};
