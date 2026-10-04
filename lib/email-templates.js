const escape = (value) =>
  String(value ?? '').replace(
    /[&<>"']/g,
    (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c],
  );
const money = (n) =>
  '₹' +
  Number(n || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const formatDate = (date) =>
  new Date(date + 'T00:00:00Z').toLocaleDateString('en-GB', {
    day: 'numeric',
    month: 'long',
    year: 'numeric',
    timeZone: 'UTC',
  });
function weekSubject(start) {
  const end = new Date(start + 'T00:00:00Z');
  end.setUTCDate(end.getUTCDate() + 4);
  return (
    'Trading Journal - Week ' +
    formatDate(start) +
    ' to ' +
    formatDate(end.toISOString().slice(0, 10))
  );
}
const note = (text) =>
  '<p style="font-size:13px;line-height:1.7;color:#63748e;margin:24px 0 0">' +
  escape(text) +
  '</p>';
const box = (label, value, color = '#234ec4') =>
  '<div style="padding:24px;background:#f1f5ff;border:1px solid #dce5fa;border-radius:12px;margin:24px 0"><p style="font-size:11px;letter-spacing:1.5px;text-transform:uppercase;color:#63748e;margin:0 0 12px">' +
  escape(label) +
  '</p><p style="font-size:30px;font-weight:700;color:' +
  color +
  ';margin:0;word-break:break-word">' +
  escape(value) +
  '</p></div>';
function shell(title, eyebrow, body, preheader) {
  return (
    '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body style="margin:0;background:#eef2f8;color:#172b4d;font-family:Arial,Helvetica,sans-serif"><div style="display:none;max-height:0;overflow:hidden">' +
    escape(preheader) +
    '</div><table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr><td align="center" style="padding:32px 12px"><table role="presentation" width="600" cellspacing="0" cellpadding="0" style="width:100%;max-width:600px;background:white;border:1px solid #dfe6f0;border-radius:18px;overflow:hidden"><tr><td style="padding:26px 32px;background:#172b4d;color:white;font-size:21px;font-weight:bold"><span style="color:#a9c3ff">↗</span> TradeJournal<span style="color:#a9c3ff">.</span></td></tr><tr><td style="padding:32px"><p style="font-size:11px;letter-spacing:2px;color:#4164ad;font-weight:bold;margin:0 0 16px">' +
    escape(eyebrow) +
    '</p><h1 style="font-size:28px;line-height:1.25;letter-spacing:-.6px;margin:0 0 18px">' +
    escape(title) +
    '</h1><div style="font-size:15px;line-height:1.7;color:#435674">' +
    body +
    '</div></td></tr><tr><td style="padding:22px 32px;border-top:1px solid #e6ebf3;background:#fafbfe;color:#70819b;font-size:12px;line-height:1.7">A better process. One trade at a time.<br>TradeJournal · All journal amounts in INR · Asia/Kolkata</td></tr></table></td></tr></table></body></html>'
  );
}
function build(type, data = {}) {
  let title, eyebrow, body, text, subject;
  switch (type) {
    case 'otp':
      subject =
        data.purpose === 'quick-entry'
          ? 'Your TradeJournal quick-entry code'
          : 'Verify your TradeJournal email';
      title = 'Your code. Your journal.';
      eyebrow = 'VERIFY YOUR EMAIL';
      text =
        'Your verification code is ' +
        data.code +
        '. It expires in 10 minutes. ' +
        (data.purpose === 'quick-entry'
          ? 'This code allows adding trades only.'
          : 'Use this code to verify your new email address.') +
        ' Never share this code. If you did not request it, ignore this email.';
      body =
        '<p>Enter this code to ' +
        (data.purpose === 'quick-entry'
          ? 'add a trade securely, without signing in.'
          : 'confirm your new email address.') +
        '</p>' +
        box('One-time verification code', data.code) +
        '<p style="margin:0"><b>Valid for 10 minutes.</b> Never share this code.</p>' +
        note('If you did not request this code, you can safely ignore this email.');
      break;
    case 'test':
      subject = data.subject || 'TradeJournal - Email connection test';
      title = 'You’re connected.';
      eyebrow = 'EMAIL DELIVERY TEST';
      text =
        'Your TradeJournal test email has arrived. Your email connection is working. Weekly summaries are sent only when enabled. This test does not change your preferences.';
      body =
        '<p>This test message confirms that TradeJournal can reach your inbox.</p>' +
        box('Delivery check', 'Connection successful') +
        '<p>Verification codes, account confirmations, and your enabled weekly reviews will arrive here.</p>' +
        note(
          'This is a test email. It contains no trade data and does not change your weekly summary preference.',
        );
      break;
    case 'weekly': {
      subject = weekSubject(data.start);
      title = 'Your week, in perspective.';
      eyebrow = 'THE WEEKLY REVIEW';
      const s = data.summary;
      text =
        subject +
        '. Net P&L ' +
        money(s.net) +
        '. Gross ' +
        money(s.gross) +
        '. Charges ' +
        money(s.fees) +
        '. ' +
        s.count +
        ' trades. Your detailed PDF is attached.';
      body =
        '<p>' +
        escape(subject.replace('Trading Journal - Week ', '')) +
        '<br><span style="font-size:12px">Monday–Friday · Closed trades · Asia/Kolkata</span></p>' +
        box('Net realised P&L', money(s.net), s.net < 0 ? '#b53854' : '#167556') +
        '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="font-size:14px"><tr><td style="padding:10px 0">Gross P&amp;L</td><td align="right"><b>' +
        escape(money(s.gross)) +
        '</b></td></tr><tr><td style="padding:10px 0;border-bottom:1px solid #e6ebf3">Total charges</td><td align="right" style="border-bottom:1px solid #e6ebf3"><b>' +
        escape(money(s.fees)) +
        '</b></td></tr><tr><td style="padding:14px 0">Trades / win rate</td><td align="right"><b>' +
        s.count +
        ' / ' +
        Number(s.winRate).toFixed(1) +
        '%</b></td></tr><tr><td>Wins / losses / breakeven</td><td align="right"><b>' +
        s.wins +
        ' / ' +
        s.losses +
        ' / ' +
        s.breakeven +
        '</b></td></tr></table>' +
        '<div style="border:1px solid #dce5fa;border-radius:10px;padding:18px;margin-top:24px;background:#f8faff"><b style="color:#234ec4">PDF · Your complete weekly report</b><br><span style="font-size:13px">Attached: totals, charges, and the trade-by-trade ledger.</span></div>' +
        note(
          'Review the decisions behind your results. You can turn weekly summaries off anytime in Settings.',
        );
      break;
    }
    case 'email-changed':
      subject = 'TradeJournal - Email address updated';
      title = 'Your email is updated.';
      eyebrow = 'ACCOUNT CONFIRMATION';
      text =
        'Your registered email is now ' +
        data.email +
        '. Future verification codes and enabled summaries will be sent there. If you did not make this change, contact the journal administrator.';
      body =
        '<p>Your new email address has been verified and saved.</p><div style="padding:20px;background:#f1f5ff;border-radius:12px;margin:24px 0;overflow-wrap:anywhere"><small>REGISTERED EMAIL</small><br><strong style="font-size:18px;color:#234ec4">' +
        escape(data.email) +
        '</strong></div><p>Future verification codes and enabled weekly summaries will be sent to this address.</p>' +
        note('If you did not make this change, contact the journal administrator.');
      break;
    case 'weekly-preference':
      subject = 'TradeJournal - Weekly summaries turned ' + (data.enabled ? 'on' : 'off');
      title = data.enabled ? 'Your weekly review is on.' : 'Weekly emails are off.';
      eyebrow = 'PREFERENCE UPDATED';
      text =
        subject +
        '. ' +
        (data.enabled
          ? 'Your Monday–Friday trade summary and PDF will be sent to your registered email after the week closes.'
          : 'You will no longer receive weekly trade summaries. Verification codes and account confirmations will still be sent.');
      body =
        box(
          'Weekly email summary',
          data.enabled ? 'ON' : 'OFF',
          data.enabled ? '#167556' : '#63748e',
        ) +
        '<p>' +
        (data.enabled
          ? 'Your Monday–Friday results and a detailed PDF will be delivered to your registered email after the week closes.'
          : 'You will no longer receive weekly trade summaries. Your saved trades and reports remain available in the dashboard.') +
        '</p>' +
        note(
          data.enabled
            ? 'Delivery is checked hourly from Monday, Asia/Kolkata. You can turn this off anytime in Settings.'
            : 'Verification codes and account confirmations will still be sent. You can turn summaries back on anytime in Settings.',
        );
      break;
    case 'capital-locked':
      subject = 'TradeJournal - Starting capital locked for ' + data.month;
      title = 'A clear starting point.';
      eyebrow = 'CAPITAL CONFIRMED';
      text =
        'Starting capital of ' +
        money(data.capital) +
        ' is saved and locked for ' +
        data.month +
        '. It cannot be edited. Each new month has its own starting capital.';
      body =
        '<p>Your starting capital for <b>' +
        escape(data.month) +
        '</b> is confirmed.</p>' +
        box('Starting capital · ' + data.month, money(data.capital)) +
        '<p><b>Saved and locked.</b> This month’s amount can no longer be edited.</p>' +
        note(
          'Each new month has a separate starting capital entry. This setting supports returns and risk planning; it does not change your trade P&L.',
        );
      break;
    default:
      throw new Error('Unknown email template');
  }
  return { subject, text, html: shell(title, eyebrow, body, text) };
}
module.exports = { build, weekSubject };
