/* One set of fee fields for authenticated and email-verified quick entry. */
window.ChargeForm = {
  mount(form) {
    const container = document.createElement('section');
    container.className = 'charge-section';
    container.innerHTML = `<div class="form-divider">BROKER & CHARGES</div>
    <div class="form-row"><label>Broker<select name="broker">${TradeCharges.brokers.map((b) => `<option>${b}</option>`).join('')}</select></label>
    <label>Charges method<select name="fee_mode"><option value="estimate">Calculate estimate</option><option value="manual">Actual / manual total</option></select></label></div>
    <div class="estimate-fields"><div class="form-row three">
    <label>Exchange<select name="exchange"><option>NSE</option><option>MCX</option><option>BSE</option><option>Other</option></select></label>
    <label>Equity product<select name="product"><option value="intraday">Intraday</option><option value="delivery">Delivery</option></select></label>
    <label>Commodity contract<select name="commodity_type"><option value="futures">Non-agri futures</option><option value="options">Options</option></select></label></div>
    <div class="form-row"><label>Brokerage plan<select name="brokerage_plan"><option value="standard">Published standard plan</option><option value="custom">My plan — enter total brokerage</option></select></label>
    <label>Executed orders (entry + exit)<input name="order_count" type="number" min="2" max="1000" step="1" value="2" required></label></div>
    <div class="form-row three"><label>Custom brokerage, before GST (₹)<input name="brokerage_override" type="number" min="0" step="0.01" placeholder="Required for custom plan"></label>
    <label>Other broker: per order (₹)<input name="per_order" type="number" min="0" step="0.01" value="20"></label>
    <label>Allocated DP fee, before GST (₹)<input name="dp_charge" type="number" min="0" step="0.01" placeholder="Delivery only"></label></div>
    <p class="fine">For delivery, allocate the DP fee from your broker’s tariff once per applicable debit; enter 0 if already allocated. ICICI delivery requires your plan’s total brokerage. Multiple executed orders, promotional plans and special settlements may need actual charges.</p>
    <p class="fine">Estimates use schedules reviewed 26 Sep 2026, for trades from Sep 2026 onward. <a class="charge-source" target="_blank" rel="noopener">Broker schedule</a> · <a href="/charge-rates.html" target="_blank" rel="noopener">Rates & assumptions</a></p></div>
    <div class="charge-breakdown" aria-live="polite"></div><p class="charge-error negative" role="alert"></p>`;
    form.querySelector('.pnl-preview').before(container);
    form.elements.broker.value = 'Other';
    return container;
  },
  update(form) {
    const data = Object.fromEntries(new FormData(form));
    const manual = data.fee_mode === 'manual';
    form.elements.brokerage_plan.options[0].textContent =
      data.broker === 'ICICI'
        ? 'iValue (intraday & derivatives)'
        : data.broker === 'Kotak'
          ? 'Trade Free (after first 30 days)'
          : data.broker === 'Angel One'
            ? 'Angel Plus'
            : 'Published standard (no promotions)';
    form.elements.commodity_type.closest('label').hidden = data.instrument_type !== 'Commodity';
    form.elements.product.closest('label').hidden = data.instrument_type !== 'Equity';
    form.elements.dp_charge.closest('label').hidden =
      data.instrument_type !== 'Equity' || data.product !== 'delivery';
    form.elements.per_order.closest('label').hidden = data.broker !== 'Other';
    form.elements.brokerage_override.closest('label').hidden = data.brokerage_plan !== 'custom';

    form.querySelector('.estimate-fields').hidden = manual;
    form.elements.fees.readOnly = !manual;
    form.elements.order_count.required = !manual;
    form.elements.brokerage_override.required = !manual && data.brokerage_plan === 'custom';
    form.elements.dp_charge.required =
      !manual && data.instrument_type === 'Equity' && data.product === 'delivery';
    const source = form.querySelector('.charge-source');
    source.href = TradeCharges.sources[data.broker];
    const error = form.querySelector('.charge-error');
    error.textContent = '';
    try {
      if (data.entry_price === '' || data.exit_price === '') {
        form.querySelector('.charge-breakdown').textContent =
          'Enter entry and exit prices to see gross P&L, charges and net P&L.';
        if (!manual) form.elements.fees.value = '0';
        return null;
      }
      const quote = TradeCharges.calculate(data);
      if (!manual) form.elements.fees.value = quote.total.toFixed(2);
      const fmt = (n) => Number(n).toLocaleString('en-IN', { style: 'currency', currency: 'INR' });
      form.querySelector('.charge-breakdown').innerHTML =
        `<div class="charge-totals"><span>Gross P&L<strong>${fmt(quote.gross)}</strong></span><span>Total charges${manual ? '' : ' (est.)'}<strong>${fmt(quote.total)}</strong></span><span>Net P&L<strong>${fmt(quote.net)}</strong></span></div><details><summary>Charges breakdown</summary>${Object.entries(
          quote.items,
        )
          .map(([k, v]) => `<div class="charge-line"><span>${k}</span><b>${fmt(v)}</b></div>`)
          .join('')}</details>`;
      form.elements.fees.setCustomValidity('');
      return quote;
    } catch (e) {
      error.textContent = e.message;
      form.querySelector('.charge-breakdown').replaceChildren();
      form.elements.fees.setCustomValidity(''); // Readonly fields cannot enforce validity; submit validates explicitly.
      return null;
    }
  },
};
