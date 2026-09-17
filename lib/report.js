const PDFDocument = require('pdfkit');
const { summary } = require('./domain');
const money = (n) =>
  Number(n).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
function report(res, trades, user, month) {
  const doc = new PDFDocument({
    size: 'A4',
    margin: 44,
    bufferPages: true,
    info: { Title: `TradeJournal - ${month}` },
  });
  res.setHeader('Content-Type', 'application/pdf');
  res.setHeader('Content-Disposition', `attachment; filename="tradejournal-${month}.pdf"`);
  doc.pipe(res);
  const s = summary(trades);
  const ink = '#162d35',
    green = '#08795f';
  const text = (str, x, y, size = 10, color = ink, width = 500) =>
    doc
      .fontSize(size)
      .fillColor(color)
      .text(str, x, y, { width, lineBreak: false, ellipsis: true });
  doc.roundedRect(44, 40, 34, 34, 9).fill(green);
  doc
    .moveTo(51, 61)
    .lineTo(51, 52)
    .lineTo(55, 52)
    .lineTo(55, 61)
    .moveTo(58, 61)
    .lineTo(58, 48)
    .lineTo(62, 48)
    .lineTo(62, 61)
    .moveTo(65, 61)
    .lineTo(65, 54)
    .lineTo(69, 54)
    .lineTo(69, 61)
    .lineWidth(1.5)
    .stroke('#b2f0d0');
  doc.moveTo(51, 66).lineTo(58, 59).lineTo(64, 62).lineTo(72, 49).lineWidth(2).stroke('#ffffff');
  text('TradeJournal', 90, 45, 20);
  text('MONTHLY PERFORMANCE REPORT', 44, 100, 9, '#657c84');
  text(month, 44, 122, 28);
  text(user.email, 44, 162, 10);
  doc.roundedRect(44, 198, 507, 91, 10).fill('#edf6f2');
  text('NET P&L / INR', 60, 214, 9);
  text(money(s.net), 60, 237, 24, s.net < 0 ? '#b04452' : green);
  text(`${s.count} trades`, 345, 217, 12);
  text(`${s.winRate.toFixed(1)}% win rate`, 345, 240, 12);
  text(`Fees: INR ${money(s.fees)}`, 345, 262, 10);
  text('Trade ledger', 44, 318, 16);
  text('Closed trades • All amounts in INR • Net results include fees', 44, 343, 9, '#657c84');
  let y = 378;
  function header() {
    doc.rect(44, y, 507, 25).fill('#edf1f3');
    ['Date', 'Instrument / direction', 'Units', 'Entry', 'Exit', 'Net P&L'].forEach((v, i) =>
      text(v, [51, 117, 290, 342, 410, 475][i], y + 8, 8, ink, [65, 169, 50, 65, 63, 70][i]),
    );
    y += 34;
  }
  header();
  if (!trades.length) {
    text('No closed trades were recorded for this month.', 51, y + 10, 11);
  }
  for (const t of trades) {
    if (y > 738) {
      doc.addPage();
      y = 54;
      header();
    }
    text(String(t.trade_date).slice(0, 10), 51, y, 8, ink, 64);
    text(`${t.symbol} / ${t.side || 'Long'}`, 117, y, 9, ink, 165);
    text(`${t.quantity * (t.lot_size || 1)}`, 290, y, 9, ink, 47);
    text(money(t.entry_price), 342, y, 8, ink, 63);
    text(money(t.exit_price), 410, y, 8, ink, 62);
    text(money(t.pnl), 475, y, 8, Number(t.pnl) < 0 ? '#b04452' : green, 76);
    doc
      .moveTo(44, y + 22)
      .lineTo(551, y + 22)
      .lineWidth(0.4)
      .stroke('#e2e9ec');
    y += 34;
  }
  const pages = doc.bufferedPageRange();
  for (let i = 0; i < pages.count; i++) {
    doc.switchToPage(i);
    text('TradeJournal / Personal trading record', 44, 776, 8, '#657c84');
    text(`${i + 1} / ${pages.count}`, 503, 776, 8, '#657c84', 48);
  }
  doc.end();
}
module.exports = report;
