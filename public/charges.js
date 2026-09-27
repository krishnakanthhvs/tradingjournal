/* Shared browser/server calculator. Published schedules reviewed 26 September 2026.
 * Estimates for closed, ordinary resident retail trades only; contract notes prevail. */
(function (root) {
  const brokers = ['Zerodha', 'Groww', 'Angel One', 'Dhan', 'Sahi', 'ICICI', 'Kotak', 'Other'];
  const sources = {
    Zerodha: 'https://zerodha.com/charges/',
    Groww: 'https://groww.in/pricing',
    'Angel One': 'https://www.angelone.in/exchange-transaction-charges',
    Dhan: 'https://dhan.co/pricing/',
    Sahi: 'https://www.sahi.com/pricing',
    ICICI: 'https://www.icicidirect.com/brokerage',
    Kotak: 'https://www.kotakneo.com/pricing/trade-free-plan/',
    Other: 'https://zerodha.com/charges/',
  };
  const round = (n) => Math.round((n + Number.EPSILON) * 100) / 100;
  function calculate(t) {
    const broker = t.broker || 'Other';
    if (!brokers.includes(broker)) throw Error('Select a broker');
    const gross = round(
      (Number(t.exit_price) - Number(t.entry_price)) *
        (t.side === 'Short' ? -1 : 1) *
        Number(t.quantity) *
        Number(t.lot_size || 1),
    );
    const numeric = (key, fallback = 0) => {
      const n = Number(t[key] ?? fallback);
      if (!Number.isFinite(n) || n < 0 || n > 1e9)
        throw Error(`Invalid ${key.replaceAll('_', ' ')}`);
      return n;
    };
    if ((t.fee_mode || 'manual') === 'manual') {
      const total = round(numeric('fees'));
      return {
        gross,
        total,
        net: round(gross - total),
        items: { 'Actual / manual charges': total },
        version: 'manual',
      };
    }
    if (t.fee_mode !== 'estimate') throw Error('Invalid charges mode');
    if (String(t.trade_date) < '2026-09-01')
      throw Error('For trades before September 2026, enter actual contract-note charges.');
    if (!['Equity', 'Call', 'Put', 'Futures', 'Commodity'].includes(t.instrument_type))
      throw Error('Use actual charges for this instrument.');
    const commodity = t.instrument_type === 'Commodity';
    const equity = t.instrument_type === 'Equity';
    const option =
      ['Call', 'Put'].includes(t.instrument_type) || (commodity && t.commodity_type === 'options');
    const delivery = equity && t.product === 'delivery';
    if (!['intraday', 'delivery'].includes(t.product)) throw Error('Select intraday or delivery');
    if (delivery && t.side === 'Short')
      throw Error('Use actual charges for short delivery / special settlement.');
    if ((commodity && t.exchange !== 'MCX') || (!commodity && t.exchange !== 'NSE'))
      throw Error(
        'Automatic estimates support NSE equity/F&O and MCX non-agricultural commodities. Use actual charges for other exchanges.',
      );
    if (commodity && !['futures', 'options'].includes(t.commodity_type))
      throw Error('Select commodity futures or options');
    const units = numeric('quantity') * numeric('lot_size', 1);
    const buy = numeric(t.side === 'Short' ? 'exit_price' : 'entry_price') * units;
    const sell = numeric(t.side === 'Short' ? 'entry_price' : 'exit_price') * units;
    const turnover = buy + sell;
    const orders = numeric('order_count', 2);
    if (!Number.isInteger(orders) || orders < 2 || orders > 1000)
      throw Error('Enter 2–1,000 executed orders including entry and exit.');
    if (!['standard', 'custom'].includes(t.brokerage_plan || 'standard'))
      throw Error('Select a brokerage plan');
    const custom = t.brokerage_plan === 'custom';
    if (!custom && broker === 'ICICI' && delivery)
      throw Error('For ICICI delivery, enter your plan’s total brokerage before GST.');
    if (custom && (t.brokerage_override == null || t.brokerage_override === ''))
      throw Error('Enter your plan’s total brokerage before GST.');
    if (delivery && (t.dp_charge == null || t.dp_charge === ''))
      throw Error('Enter the allocated DP fee before GST; enter 0 if already allocated.');
    if (commodity && broker === 'Groww' && !custom)
      throw Error('For Groww commodity trades, enter actual brokerage for your plan.');
    if (!custom && orders !== 2 && broker !== 'Other')
      throw Error(
        'For multiple fills/orders, select custom brokerage and enter the contract-note brokerage total.',
      );
    function leg(value) {
      if (broker === 'ICICI') return 20; // iValue, excluding delivery
      if (broker === 'Kotak')
        return delivery ? value * 0.002 : equity ? Math.min(10, value * 0.0005) : 10; // Trade Free, after introductory period
      if (broker === 'Other') return numeric('per_order', 20);
      if (broker === 'Sahi') return equity ? Math.min(10, value * 0.0005) : 10;
      if (broker === 'Zerodha') return delivery ? 0 : option ? 20 : Math.min(20, value * 0.0003);
      if (broker === 'Dhan') return delivery ? 0 : equity ? Math.min(20, value * 0.0003) : 20;
      if (broker === 'Groww')
        return equity ? Math.min(20, Math.max(value * 0.001, Math.min(5, value * 0.025))) : 20;
      if (broker === 'Angel One') return equity ? Math.max(5, Math.min(20, value * 0.001)) : 20;
      return 0;
    }
    const brokerage = custom
      ? numeric('brokerage_override')
      : broker === 'Other'
        ? orders * leg(0)
        : leg(buy) + leg(sell);
    const dp = delivery ? numeric('dp_charge') : 0;
    const exchange =
      turnover *
      (commodity
        ? option
          ? 0.000418
          : 0.000021
        : equity
          ? 0.000030699
          : option
            ? 0.000355299
            : 0.000018299);
    const sebi = turnover * 0.000001;
    const ipft = commodity ? 0 : turnover * 0.000000001;
    const items = {
      Brokerage: round(brokerage),
      [commodity ? 'CTT (sell)' : delivery ? 'STT (buy + sell)' : 'STT (sell)']: round(
        delivery
          ? turnover * 0.001
          : sell *
              (commodity
                ? option
                  ? 0.0005
                  : 0.0001
                : equity
                  ? 0.00025
                  : option
                    ? 0.0015
                    : 0.0005),
      ),
      'Stamp duty (buy)': round(buy * (delivery ? 0.00015 : equity || option ? 0.00003 : 0.00002)),
      'Exchange transaction charges': round(exchange),
      'SEBI turnover fees': round(sebi),
      IPFT: round(ipft),
      'DP charges (before GST)': round(dp),
      'GST (18%)': round((brokerage + exchange + sebi + ipft + dp) * 0.18),
    };
    const total = round(Object.values(items).reduce((a, b) => a + b, 0));
    return {
      gross,
      total,
      net: round(gross - total),
      items,
      version: '2026-09-26',
      source: sources[broker],
    };
  }
  const api = { brokers, sources, calculate };
  if (typeof module === 'object' && module.exports) module.exports = api;
  else root.TradeCharges = api;
})(typeof window === 'undefined' ? globalThis : window);
