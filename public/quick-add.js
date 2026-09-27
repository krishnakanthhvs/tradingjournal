const $ = (s) => document.querySelector(s);
const form = $('#trade-form');
ChargeForm.mount(form);
let expires = 0;
const today = () =>
  new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Kolkata' }).format(new Date());
const fmt = (n) => Number(n).toLocaleString('en-IN', { style: 'currency', currency: 'INR' });
async function request(path, body) {
  const r = await fetch('/api/quick-entry/' + path, {
    method: body ? 'POST' : 'GET',
    headers: { 'Content-Type': 'application/json' },
    body: body ? JSON.stringify(body) : undefined,
  });
  const data = await r.json();
  if (!r.ok) {
    if (r.status === 401) {
      $('#verify-panel').hidden = false;
      $('#quick-trade-panel').hidden = true;
    }
    throw Error(data.error);
  }
  return data;
}
function resetTrade() {
  form.reset();
  form.elements.broker.value = 'Other';
  form.elements.trade_date.value = form.elements.trade_date.max = today();
  update();
}
function update() {
  const quote = ChargeForm.update(form);
  $('#trade-preview').textContent = quote ? fmt(quote.net) : '—';
  const stop = form.elements.stop_loss.value;
  $('#risk-preview').textContent = stop
    ? fmt(
        Math.abs(+form.elements.entry_price.value - Number(stop)) *
          +form.elements.quantity.value *
          +form.elements.lot_size.value +
          (quote?.total || 0),
      )
    : 'Add a stop loss';
  form.elements.expiry_date.required = ['Call', 'Put', 'Futures'].includes(
    form.elements.instrument_type.value,
  );
  form.elements.expiry_date.min = form.elements.trade_date.value;
}
async function openForm() {
  const data = await request('form');
  expires = data.expires;
  $('#trade-strategy').replaceChildren(
    new Option('No strategy selected', ''),
    ...data.strategies.map((s) => new Option(s.name, s.id)),
  );
  $('#verify-panel').hidden = true;
  $('#quick-trade-panel').hidden = false;
  $('#quick-identity').textContent =
    `Adding to ${data.email} · Session ends ${new Date(expires).toLocaleTimeString()}`;
  resetTrade();
  form.elements.symbol.focus();
}
async function submitWith(button, fn, target) {
  button.disabled = true;
  try {
    await fn();
  } catch (e) {
    $(target).textContent = e.message;
  } finally {
    button.disabled = false;
  }
}
$('#email-form').onsubmit = (e) => {
  e.preventDefault();
  submitWith(
    e.submitter,
    async () => {
      const data = await request('request', Object.fromEntries(new FormData(e.target)));
      $('#verify-message').textContent = data.message;
      $('#code-form').hidden = false;
      $('#code-form input').focus();
    },
    '#verify-message',
  );
};
$('#code-form').onsubmit = (e) => {
  e.preventDefault();
  submitWith(
    e.submitter,
    async () => {
      await request('verify', Object.fromEntries(new FormData(e.target)));
      await openForm();
    },
    '#verify-message',
  );
};
form.oninput = update;
form.onsubmit = (e) => {
  e.preventDefault();
  if (!ChargeForm.update(form)) return;
  submitWith(
    e.submitter,
    async () => {
      const data = Object.fromEntries(new FormData(form));
      await request('trades', data);
      resetTrade();
      $('#trade-error').textContent = '';
      $('#quick-success').textContent =
        `${data.symbol} saved to your journal. You can add another trade.`;
      $('#quick-success').scrollIntoView({ block: 'nearest' });
    },
    '#trade-error',
  );
};
$('#end-session').onclick = async () => {
  try {
    await request('end', {});
  } finally {
    location.href = '/quick-add.html';
  }
};
setInterval(() => {
  if (expires && Date.now() >= expires) {
    expires = 0;
    $('#quick-trade-panel').hidden = true;
    $('#verify-panel').hidden = false;
    $('#verify-message').textContent = 'Your session ended. Request a new code to continue.';
  }
}, 10000);
openForm().catch(() => {});
