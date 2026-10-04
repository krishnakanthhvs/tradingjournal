require('dotenv').config({ quiet: true });
const { randomUUID } = require('crypto');
const { sendAccountEmail, weekSubject } = require('../lib/email');
const recipient = process.argv[2] || process.env.SMTP_USER;
const date = new Date();
const indiaDate = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Kolkata' }).format(date);
const monday = new Date(indiaDate + 'T00:00:00Z');
monday.setUTCDate(monday.getUTCDate() - ((monday.getUTCDay() + 6) % 7));
const start = process.argv[3] || monday.toISOString().slice(0, 10);
if (!recipient || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(recipient)) {
  console.error('Provide a valid recipient: npm run email:test -- address@example.com');
  process.exitCode = 1;
} else {
  sendAccountEmail(
    recipient,
    weekSubject(start),
    'Your TradeJournal SMTP test was accepted for delivery. This message confirms the configured mail connection can send email. No trade data is included.',
    'smtp-test-' + randomUUID(),
  )
    .then((result) =>
      console.log('Test email accepted by mail server:', recipient, 'Message ID:', result.id),
    )
    .catch((error) => {
      console.error(error.message);
      process.exitCode = 1;
    });
}
