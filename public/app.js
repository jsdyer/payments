'use strict';
const $ = id => document.getElementById(id);
let catalog, operation = 'heartbeat', history = [], result = null, busy = false;
const csrf = document.querySelector('meta[name="csrf-token"]').content;
const format = value => JSON.stringify(value, null, 2);
function message(text = '') { $('message').textContent = text; }
async function api(action, data = {}) {
  const response = await fetch('?api=1', {method: 'POST', headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrf}, body: JSON.stringify({action, ...data})});
  const reply = await response.json();
  if (!response.ok || reply.error) throw new Error(reply.error || `HTTP ${response.status}`);
  return reply;
}
function download(name, content) {
  const url = URL.createObjectURL(new Blob([content], {type: 'application/json'}));
  const a = document.createElement('a'); a.href = url; a.download = name; a.click();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}
function selectOperation(id) {
  operation = id;
  const [name, path, type, description] = catalog.operations[id];
  $('operation-title').textContent = name;
  $('message-type').textContent = type;
  $('description').textContent = description;
  $('endpoint').textContent = `POST https://api.payments.ca/rtr-sandbox${path}`;
  document.querySelectorAll('#operations button').forEach(b => b.setAttribute('aria-current', String(b.dataset.id === id)));
  $('template').replaceChildren();
  catalog.templates.filter(t => t.operation === id).forEach(t => {
    const option = document.createElement('option'); option.value = t.id;
    option.textContent = `${t.id.startsWith('sample-') ? '' : t.id + ' · '}${t.title}`;
    $('template').append(option);
  });
  loadTemplate();
}
function loadTemplate() {
  const template = catalog.templates.find(t => t.id === $('template').value && t.operation === operation);
  $('body').value = template.request;
  $('scenario-category').textContent = template.category;
  $('scenario-description').textContent = template.description || '';
  $('trigger').textContent = template.trigger;
  $('expected').textContent = template.expected || 'See the official scenario catalog for expected outcomes.';
  $('reference').textContent = template.response || 'No reference response in the Postman sample.';
  $('trace').value = ''; $('uetr').value = ''; $('signature').value = '';
  $('fields').replaceChildren();
}
function renderFields() {
  const data = JSON.parse($('body').value); $('fields').replaceChildren();
  const walk = (value, path = []) => {
    if (value !== null && typeof value === 'object') {
      Object.entries(value).forEach(([key, child]) => walk(child, [...path, key])); return;
    }
    const wrapper = document.createElement('div'); wrapper.dataset.path = path.join('.');
    const label = document.createElement('label'); label.textContent = path.join('.');
    const input = document.createElement('input'); input.value = value === null ? 'null' : String(value);
    label.append(input); wrapper.append(label);
    input.addEventListener('change', () => {
      try {
        const current = JSON.parse($('body').value); let target = current;
        for (const key of path.slice(0, -1)) target = target[key];
        let next = input.value;
        if (typeof value === 'number') { next = Number(next); if (!Number.isFinite(next)) throw new Error('Enter a valid number.'); }
        if (typeof value === 'boolean') { if (!['true', 'false'].includes(next)) throw new Error('Use true or false.'); next = next === 'true'; }
        if (value === null) next = JSON.parse(next);
        target[path.at(-1)] = next;
        $('body').value = format(current);
      } catch (e) { message(e.message); }
    });
    $('fields').append(wrapper);
  };
  walk(data); filterFields();
}
function filterFields() {
  const query = $('field-search').value.toLowerCase();
  [...$('fields').children].forEach(el => el.hidden = !el.dataset.path.toLowerCase().includes(query));
}
function showResult(exchange) {
  result = exchange; $('download-result').disabled = false;
  $('response-meta').textContent = `HTTP ${exchange.http_status} · ${exchange.duration_ms} ms · ${exchange.time}`;
  try { $('response-body').textContent = format(JSON.parse(exchange.response_body)); }
  catch { $('response-body').textContent = exchange.response_body || '(Empty response body)'; }
  $('response-headers').textContent = exchange.response_headers.join('\n');
  $('sent-request').textContent = ['POST ' + exchange.url, ...exchange.request_headers, '', exchange.request_body].join('\n');
  const dl = document.createElement('dl');
  Object.entries(exchange.summary).forEach(([key, value]) => {
    const dt = document.createElement('dt'); dt.textContent = key;
    const dd = document.createElement('dd'); dd.textContent = String(value); dl.append(dt, dd);
  });
  $('summary').replaceChildren(dl);
}
function renderHistory() {
  $('history').replaceChildren();
  history.forEach(exchange => {
    const b = document.createElement('button');
    b.textContent = `${exchange.operation} · HTTP ${exchange.http_status} · ${new Date(exchange.time).toLocaleTimeString()}`;
    b.addEventListener('click', () => showResult(exchange)); $('history').append(b);
  });
}
async function run(task) {
  if (busy) return;
  busy = true; message();
  ['send', 'auth', 'forget', 'clear'].forEach(id => $(id).disabled = true);
  try { await task(); } catch (e) { message(e.message); }
  finally { busy = false; ['send', 'auth', 'forget', 'clear'].forEach(id => $(id).disabled = false); }
}
$('send').onclick = () => run(async () => {
  JSON.parse($('body').value);
  const exchange = await api('send', {operation, body: $('body').value, headers: {'traceability-id': $('trace').value, 'x-uetr': $('uetr').value, 'x-jws-signature': $('signature').value}});
  history = [exchange, ...history].slice(0, 10); showResult(exchange); renderHistory();
  if (exchange.http_status === 401) message('HTTP 401: this may be an intentional rejection scenario. Token cleared; this request was not retried.');
});
$('auth').onclick = () => run(async () => { const auth = await api('auth'); $('connection').textContent = `Authenticated · token expires ${new Date(auth.expires_at * 1000).toLocaleTimeString()}`; });
$('forget').onclick = () => run(async () => { await api('forget'); $('connection').textContent = 'Token forgotten. Next request will authenticate again.'; });
$('clear').onclick = () => run(async () => { await api('clear'); history = []; renderHistory(); });
$('template').onchange = loadTemplate;
$('format').onclick = () => { try { $('body').value = format(JSON.parse($('body').value)); message(); } catch (e) { message(e.message); } };
$('download-request').onclick = () => download(`rtr-${operation}.json`, $('body').value);
$('download-result').onclick = () => result && download(`rtr-${result.operation}-${Date.now()}.json`, format(result));
$('upload').onchange = async event => {
  const file = event.target.files[0]; if (!file) return;
  try { if (file.size > 262144) throw new Error('Request exceeds 256 KiB.'); const text = await file.text(); JSON.parse(text); $('body').value = text; $('fields').replaceChildren(); message('Loaded custom request. Scenario instructions describe the selected template, not this file.'); }
  catch (e) { message(e.message); }
  event.target.value = '';
};
$('fields-refresh').onclick = () => { try { renderFields(); message(); } catch (e) { message(e.message); } };
$('field-search').oninput = filterFields;
$('enquire').onclick = () => {
  try {
    const last = history.find(x => x.operation === 'payment');
    if (!last) throw new Error('Send a payment first. This copies identifiers from the latest payment request in session history.');
    const payment = JSON.parse(last.request_body).fi_to_fi_customer_credit_transfer;
    const transfer = payment.credit_transfer_transaction_information;
    selectOperation('status');
    const enquiry = JSON.parse($('body').value);
    const tx = enquiry.fi_to_fi_payment_status_request.transaction_information;
    tx.original_group_information.original_message_identification = payment.group_header.message_identification;
    tx.original_end_to_end_identification = transfer.payment_identification.end_to_end_identification;
    tx.original_uetr = transfer.payment_identification.uetr;
    if (transfer.instructing_agent) tx.instructing_agent = transfer.instructing_agent;
    if (transfer.instructed_agent) tx.instructed_agent = transfer.instructed_agent;
    $('body').value = format(enquiry); $('uetr').value = tx.original_uetr || '';
    message('Copied the last payment identifiers. Review before sending; sandbox enquiries are trigger-based, not proof of settlement.');
  } catch (e) { message(e.message); }
};
run(async () => {
  catalog = await api('catalog'); history = catalog.history;
  $('connection').textContent = catalog.configured ? `Credentials configured · ${catalog.templates.length} examples and scenarios` : 'Credentials missing. See README setup instructions.';
  Object.entries(catalog.operations).forEach(([id, op]) => {
    const b = document.createElement('button'); b.textContent = op[0]; b.dataset.id = id;
    b.onclick = () => selectOperation(id); $('operations').append(b);
  });
  selectOperation('heartbeat'); renderHistory(); if (history.length) showResult(history[0]);
});
