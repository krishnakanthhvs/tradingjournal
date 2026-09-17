const { summary, weekRange } = require('./domain');
const money = (n) => `INR ${Number(n).toLocaleString('en-IN', { maximumFractionDigits: 2 })}`;
const configured = () => Boolean(process.env.RESEND_API_KEY && process.env.EMAIL_FROM);
async function sendWeekly(pool, now = new Date()) {
  if (!configured()) return { configured: false, sent: 0 };
  const client = await pool.connect();
  let sent = 0;
  try {
    const lock = await client.query('SELECT pg_try_advisory_lock(73519024) AS locked');
    if (!lock.rows[0].locked) return { sent: 0 };
    const { start, end } = weekRange(now);
    const users = await client.query(
      'SELECT u.id,u.email FROM users u JOIN user_settings s ON s.user_id=u.id WHERE s.weekly_email=true',
    );
    for (const u of users.rows) {
      const existing = await client.query(
        'SELECT * FROM email_deliveries WHERE user_id=$1 AND week_start=$2',
        [u.id, start],
      );
      if (existing.rows[0]?.sent_at) continue;
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
          subject: `Your weekly trading journal | ${start}`,
          html: `<div style="font-family:Arial;color:#152b35;max-width:560px;margin:30px auto"><h2>TradeJournal / Weekly review</h2><p>${start} to ${end} (end exclusive), Asia/Kolkata</p><h1>${money(s.net)}</h1><p>Net realised P&amp;L after fees</p><hr><p>${s.count} trades · ${s.wins} wins · ${s.losses} losses · ${s.winRate.toFixed(1)}% win rate</p><p>Fees: ${money(s.fees)} · Average trade: ${money(s.average)}</p><p>Take a moment to review your decisions, not just your results.</p><p style="font-size:12px;color:#687b82">You enabled weekly summaries in TradeJournal. Turn them off anytime in Settings → Weekly email summary.</p></div>`,
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
        const response = await fetch('https://api.resend.com/emails', {
          method: 'POST',
          headers: {
            Authorization: `Bearer ${process.env.RESEND_API_KEY}`,
            'Content-Type': 'application/json',
            'Idempotency-Key': `weekly-${u.id}-${start}`,
          },
          body: JSON.stringify(payload),
          signal: AbortSignal.timeout(15000),
        });
        if (!response.ok) throw new Error(`Email provider returned ${response.status}`);
        const result = await response.json();
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
module.exports = { sendWeekly, configured };

// Account messages are separate from weekly opt-in and never contain trade data.
module.exports.sendAccountEmail = async function (to, subject, text, key) {
  if (!configured()) {
    const error = new Error(
      'Email delivery is not configured. Add the provider key and verified sender on the server.',
    );
    error.status = 503;
    throw error;
  }
  const response = await fetch('https://api.resend.com/emails', {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${process.env.RESEND_API_KEY}`,
      'Content-Type': 'application/json',
      'Idempotency-Key': key,
    },
    body: JSON.stringify({ from: process.env.EMAIL_FROM, to: [to], subject, text }),
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
};
