// Sends a signed sample delivery to any URL, exactly as the phone would. Use it to test your
// endpoint before a real transfer, including the two negative probes.
//
//   node kit/sink/send.mjs https://shop.example/qersh --secret qsk_...            # transfer.received, 150.00 EGP (whole pounds, as *9# transfers arrive)
//   node kit/sink/send.mjs http://localhost:8787/qersh --secret qsk_... --event webhook.test
//   node kit/sink/send.mjs https://shop.example/qersh --secret qsk_... --probe   # also sends a bad signature + a stale t (both must get 4xx)
//   node kit/sink/send.mjs https://shop.example/qersh --secret qsk_... --late 72 # a backfill: the money arrived 72 hours ago and the phone sends it now
//                                                                               # (X-Qersh-Redelivery: backfill); it must not pay an order opened after that time
//   node kit/sink/send.mjs https://shop.example/qersh --secret qsk_... --held 15 # held for the balance check: the money arrived 15 minutes ago, no
//                                                                               # redelivery header; match it by received_at like any other delivery
//   node kit/sink/send.mjs https://shop.example/qersh --secret qsk_... --forgery # then a transfer.updated with suspected_forgery for the same transfer:
//                                                                               # answer ok and stop the order it paid
//   node kit/sink/send.mjs https://shop.example/qersh --secret qsk_... --setup-link [--label "My shop"] [--provider vodafone_cash]
//                                                                               # sends nothing: prints the qersh://setup link for that URL and secret
import { createHmac, randomUUID } from 'node:crypto';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const args = process.argv.slice(2);
const url = args.find((a) => !a.startsWith('--'));
const flag = (name, fallback) => { const i = args.indexOf(name); return i >= 0 && args[i + 1] ? args[i + 1] : fallback; };
const secret = flag('--secret', process.env.QERSH_WEBHOOK_SECRET);
const eventName = flag('--event', 'transfer.received');
const amount = flag('--amount', null);
// --late <hours>: send it the way the phone sends what it kept (a backfill). The body keeps the capture time, so it
// carries the time the money arrived; the signature is fresh; the header X-Qersh-Redelivery says backfill.
const lateHours = Math.max(0, Number(flag('--late', 0)) || 0);
// --held <minutes>: what the phone sends after holding a transfer for the balance check (at most 15 minutes): the money
// arrived that long ago, there is no redelivery header, and the merchant's answer is in it (balance_chain merchant_confirmed).
const heldMinutes = Math.max(0, Number(flag('--held', 0)) || 0);
// --forgery: after the transfer.received, a transfer.updated for the same transfer that says suspected_forgery. Your
// endpoint must answer ok and stop the order that transfer paid.
const forgery = args.includes('--forgery');
if (!url || !secret) { console.error('usage: node send.mjs <url> --secret qsk_... [--event transfer.received|webhook.test] [--amount 199] [--late 72] [--held 15] [--forgery] [--probe] [--setup-link]'); process.exit(1); }

// The link the merchant pastes into "إضافة محفظة" in the app (the app calls it "رابط مطوّرك"). No events
// parameter: listing events switches off every event that is not listed.
if (args.includes('--setup-link')) {
  const label = flag('--label', null);
  const provider = flag('--provider', null);
  console.log('qersh://setup?v=1&url=' + encodeURIComponent(url) + '&secret=' + encodeURIComponent(secret) +
    (label ? '&label=' + encodeURIComponent(label) : '') + (provider ? '&provider=' + encodeURIComponent(provider) : ''));
  process.exit(0);
}

const vectors = JSON.parse(readFileSync(path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', 'test-vectors.json'), 'utf8'));
const base = JSON.parse(vectors.cases.find((c) => c.name === (eventName === 'webhook.test' ? 'valid_webhook_test' : 'valid_transfer_received')).body);
const now = new Date(Date.now() - lateHours * 3_600_000 - heldMinutes * 60_000); // when the phone captured the message
base.delivery_id = randomUUID();
base.created_at = now.toISOString();
base.created_at_epoch_ms = now.getTime();
// The vector's times are fixed in the past. A store matches by data.received_at (when the money arrived), so the sample
// must say "just now" (or --late hours ago), otherwise every test would look like a weeks-old delivery.
const transfer = eventName === 'webhook.test' ? base.data.sample : base.data;
if (transfer?.received_at) {
  transfer.received_at = new Date(now.getTime() - 1000).toISOString();
  if (transfer.sms_timestamp) transfer.sms_timestamp = new Date(now.getTime() - 4000).toISOString();
}
if (amount && eventName !== 'webhook.test') {
  const minor = Math.round(Number(amount) * 100);
  base.data.amount = (minor / 100).toFixed(2);
  base.data.amount_minor = minor;
  base.data.provider_txn_id = String(Math.floor(Math.random() * 1e12)).padStart(12, '0');
}
if (heldMinutes > 0 && eventName !== 'webhook.test' && base.data.checks) base.data.checks.balance_chain = 'merchant_confirmed';

/** The transfer.updated the phone sends when the balance check refutes a transfer the store already has. */
function forgeryUpdate() {
  const at = new Date();
  return {
    ...base, event: 'transfer.updated', delivery_id: randomUUID(), created_at: at.toISOString(), created_at_epoch_ms: at.getTime(),
    data: {
      transfer_id: base.data.transfer_id, updated_fields: ['checks.balance_chain', 'risk', 'risk_reasons', 'auto_confirm_ok'],
      checks: { ...base.data.checks, balance_chain: 'refuted' }, risk: 'high', risk_reasons: ['balance_inconsistent', 'suspected_forgery'], auto_confirm_ok: false,
    },
  };
}

async function send(label, body, t, signSecret, envelope = base, redelivery = lateHours > 0 ? 'backfill' : null) {
  const sig = createHmac('sha256', signSecret).update(`${t}.${body}`).digest('hex');
  const started = Date.now();
  let res;
  try {
    res = await fetch(url, {
      method: 'POST', redirect: 'manual',
      headers: {
        'content-type': 'application/json; charset=utf-8', 'user-agent': 'Qersh/kit-send',
        'x-qersh-version': '1', 'x-qersh-event': envelope.event, 'x-qersh-delivery': envelope.delivery_id, 'x-qersh-attempt': '1',
        'x-qersh-wallet': envelope.wallet.id, 'x-qersh-signature': `t=${t},v1=${sig}`, 'idempotency-key': envelope.delivery_id,
        'webhook-id': envelope.delivery_id, 'webhook-timestamp': String(t),
        'webhook-signature': 'v1,' + createHmac('sha256', signSecret).update(`${envelope.delivery_id}.${t}.${body}`).digest('base64'),
        ...(redelivery ? { 'x-qersh-redelivery': redelivery } : {}),
      },
      body,
      signal: AbortSignal.timeout(20_000),
    });
  } catch (e) {
    console.log(`${label.padEnd(18)} NETWORK ERROR: ${e.message}`);
    return null;
  }
  const text = await res.text();
  console.log(`${label.padEnd(18)} HTTP ${res.status}  ${Date.now() - started} ms  ${text.slice(0, 200).replace(/\s+/g, ' ')}`);
  return res.status;
}

const body = JSON.stringify(base);
const t = Math.floor(Date.now() / 1000);
const ok = await send(base.event, body, t, secret);
if (forgery && eventName === 'transfer.received') {
  const update = forgeryUpdate();
  await send('transfer.updated', JSON.stringify(update), Math.floor(Date.now() / 1000), secret, update, null);
  console.log('expected: HTTP 2xx with {"status":"ok"}, and the order this transfer paid is stopped for a human (suspected_forgery)');
}
if (args.includes('--probe')) {
  const bad = await send('bad signature', body, t, secret + 'x');
  const stale = await send('stale timestamp', body, t - 3600, secret);
  const verdict = (s) => (s !== null && s >= 400 && s < 500 ? 'rejected ✓' : 'ACCEPTED ✗ — your endpoint does not verify this');
  console.log(`\nverdict: signature check ${verdict(bad)}; timestamp window ${verdict(stale)}`);
}
if (ok !== null && ok >= 300 && ok < 400) console.log('note: your endpoint redirects (3xx); the app treats that as an error — use the final URL.');
