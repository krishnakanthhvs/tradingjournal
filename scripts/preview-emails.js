// Generates sample artifacts only; never sends email or reads production data.
const fs = require('fs');
const path = require('path');
const { build } = require('../lib/email-templates');
const { summary } = require('../lib/domain');
async function main() {
  const folder = path.join(__dirname, '../output/email-previews');
  fs.mkdirSync(folder, { recursive: true });
  const trades = [
    {
      id: 1,
      symbol: 'NIFTY 26000 CE',
      side: 'Long',
      quantity: 1,
      lot_size: 65,
      entry_price: 120,
      exit_price: 160,
      pnl: 2480,
      fees: 120,
      trade_date: '2026-09-28',
    },
    {
      id: 2,
      symbol: 'RELIANCE',
      side: 'Long',
      quantity: 20,
      lot_size: 1,
      entry_price: 1400,
      exit_price: 1380,
      pnl: -450,
      fees: 50,
      trade_date: '2026-09-29',
    },
    {
      id: 3,
      symbol: 'BANKNIFTY 58000 PE',
      side: 'Long',
      quantity: 1,
      lot_size: 30,
      entry_price: 180,
      exit_price: 280,
      pnl: 2870,
      fees: 130,
      trade_date: '2026-10-01',
    },
  ];
  const examples = [
    ['01-otp', 'otp', { code: '482916', purpose: 'email-change' }],
    ['02-test', 'test', {}],
    ['03-weekly', 'weekly', { start: '2026-09-28', summary: summary(trades) }],
    ['04-email-updated', 'email-changed', { email: 'krishna@example.com' }],
    ['05-summary-on', 'weekly-preference', { enabled: true }],
    ['06-summary-off', 'weekly-preference', { enabled: false }],
    ['07-capital-locked', 'capital-locked', { month: 'October 2026', capital: 100000 }],
  ];
  for (const [name, type, data] of examples)
    fs.writeFileSync(path.join(folder, name + '.html'), build(type, data).html);
  const pdf = await require('../lib/report').buffer(
    trades,
    { email: 'krishna@example.com' },
    '28 September 2026 - 2 October 2026',
  );
  fs.mkdirSync(path.join(__dirname, '../output/pdf'), { recursive: true });
  fs.writeFileSync(path.join(__dirname, '../output/pdf/weekly-report-sample.pdf'), pdf);
  fs.writeFileSync(
    path.join(folder, 'index.html'),
    '<h1>Email design previews · sample data</h1>' +
      examples.map(([name]) => '<p><a href="' + name + '.html">' + name + '</a></p>').join(''),
  );
  console.log('Generated seven sample email layouts and a weekly PDF.');
}
main().catch((e) => {
  console.error(e);
  process.exitCode = 1;
});
