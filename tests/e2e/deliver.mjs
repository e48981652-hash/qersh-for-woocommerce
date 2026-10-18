// Sends signed qersh.v1 deliveries to a store, the way the phone does, with the options the kit's send.mjs lacks
// (docs/17 section 11 in the Qersh repository). Builds on the valid vectors of fixtures/test-vectors.json.
//
//   node tests/e2e/deliver.mjs <webhook url> --secret qsk_... [options]
//
// Identity:   --transfer-id <id> (default: a fresh trf_…)   --delivery-id <uuid> (default: fresh)
//             --repeat N [--concurrent]   send the same delivery N times (same delivery id and signature)
// Transfer:   --amount 140 | --amount-minor 13950   --payer <number|handle>   --no-payer
//             --provider <string> (the envelope's wallet.provider, which the store only keeps as information)
//             --txn <provider txn id>   --sms-at <iso>   --late <hours>   --held <minutes>
//             --reasons a,b   --auto-ok true|false   --risk low|medium|high   --channel sms|notification
// Events:     --event transfer.received|transfer.updated|webhook.test|device.heartbeat   --forgery (sends a
//             transfer.updated with suspected_forgery after the transfer.received)   --grace <iso> (subscription.grace_until)
// Output:     one JSON line per response: {label, status, ms, head_ms, retry_after, body}: ms until the whole body
//             arrived, head_ms until the status line and headers did. Exit 0 always; the caller asserts.
import { createHmac, randomBytes, randomUUID } from 'node:crypto';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const args = process.argv.slice(2);
const url = args.find((a) => !a.startsWith('--') && /^https?:/.test(a));
const has = (name) => args.includes(name);
const flag = (name, fallback = null) => {
	const i = args.indexOf(name);
	return i >= 0 && i + 1 < args.length && !args[i + 1].startsWith('--') ? args[i + 1] : fallback;
};
const secret = flag('--secret', process.env.QERSH_WEBHOOK_SECRET);
if (!url || !secret) {
	console.error('usage: node tests/e2e/deliver.mjs <url> --secret qsk_... [options] (see the header of this file)');
	process.exit(2);
}

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
const vectors = JSON.parse(readFileSync(path.join(root, 'fixtures', 'test-vectors.json'), 'utf8'));
const pick = (name) => JSON.parse(vectors.cases.find((c) => c.name === name).body);

const eventName = flag('--event', 'transfer.received');
const lateHours = Math.max(0, Number(flag('--late', 0)) || 0);
const heldMinutes = Math.max(0, Number(flag('--held', 0)) || 0);
const captured = new Date(Date.now() - lateHours * 3_600_000 - heldMinutes * 60_000);

function envelopeFor(event) {
	const base = pick(event === 'webhook.test' ? 'valid_webhook_test' : 'valid_transfer_received');
	base.event = event;
	base.delivery_id = flag('--delivery-id', randomUUID());
	base.created_at = captured.toISOString();
	base.created_at_epoch_ms = captured.getTime();
	const grace = flag('--grace');
	if (grace) base.subscription = { ...(base.subscription || {}), grace_until: grace };
	const wallet = flag('--wallet');
	if (wallet) base.wallet = { ...base.wallet, id: wallet };
	const provider = flag('--provider');
	if (provider) base.wallet = { ...base.wallet, provider };
	return base;
}

function shapeTransfer(data) {
	data.transfer_id = flag('--transfer-id', 'trf_' + randomBytes(12).toString('hex'));
	data.received_at = new Date(captured.getTime() - 1000).toISOString();
	const smsAt = flag('--sms-at');
	if (smsAt) data.sms_timestamp = smsAt;
	else if ('sms_timestamp' in data) data.sms_timestamp = new Date(captured.getTime() - 4000).toISOString();
	const minorFlag = flag('--amount-minor');
	const amount = flag('--amount');
	if (minorFlag !== null || amount !== null) {
		const minor = minorFlag !== null ? Number(minorFlag) : Math.round(Number(amount) * 100);
		data.amount_minor = minor;
		data.amount = (minor / 100).toFixed(2);
	}
	data.counterparty = data.counterparty || {};
	if (has('--no-payer')) {
		data.counterparty = { name: data.counterparty.name || null, number: null, handle: null };
	} else if (flag('--payer')) {
		const p = flag('--payer');
		data.counterparty = p.includes('@') || /[a-z]/i.test(p) ? { ...data.counterparty, number: null, handle: p } : { ...data.counterparty, number: p, handle: null };
	}
	// Every real transfer has its own operator transaction id: a fixed one from the vector would make every run a
	// "duplicate transaction id" (rule 2, store-wide). --txn sets it, --no-txn leaves it out (a message without one).
	if (flag('--txn') !== null) data.provider_txn_id = flag('--txn');
	else if (has('--no-txn')) data.provider_txn_id = null;
	else data.provider_txn_id = String(Number('0x' + randomBytes(5).toString('hex'))).padStart(12, '0').slice(-12);
	if (flag('--channel')) data.channel = flag('--channel');
	if (flag('--reasons') !== null) data.risk_reasons = flag('--reasons') ? flag('--reasons').split(',') : [];
	if (flag('--risk')) data.risk = flag('--risk');
	if (flag('--auto-ok') !== null) data.auto_confirm_ok = flag('--auto-ok') === 'true';
	if (heldMinutes > 0 && data.checks) data.checks.balance_chain = 'merchant_confirmed';
	return data;
}

function build(event) {
	const env = envelopeFor(event);
	if (event === 'transfer.received') shapeTransfer(env.data);
	if (event === 'webhook.test' && env.data && env.data.sample) shapeTransfer(env.data.sample);
	if (event === 'device.heartbeat') env.data = { at: new Date().toISOString() };
	if (event === 'transfer.updated') {
		env.data = {
			transfer_id: flag('--transfer-id', 'trf_missing'),
			updated_fields: ['risk', 'risk_reasons', 'auto_confirm_ok'],
			risk: flag('--risk', 'low'),
			risk_reasons: flag('--reasons') ? flag('--reasons').split(',') : [],
			auto_confirm_ok: flag('--auto-ok', 'true') === 'true',
		};
	}
	return env;
}

function headers(env, body, t, redelivery) {
	const sig = createHmac('sha256', secret).update(`${t}.${body}`).digest('hex');
	return {
		'content-type': 'application/json; charset=utf-8',
		'user-agent': 'Qersh/e2e-deliver',
		'x-qersh-version': '1',
		'x-qersh-event': env.event,
		'x-qersh-delivery': env.delivery_id,
		'x-qersh-attempt': '1',
		'x-qersh-wallet': env.wallet.id,
		'x-qersh-signature': `t=${t},v1=${sig}`,
		'idempotency-key': env.delivery_id,
		...(redelivery ? { 'x-qersh-redelivery': redelivery } : {}),
	};
}

async function post(label, env, body, t, redelivery) {
	const started = Date.now();
	try {
		const res = await fetch(url, { method: 'POST', redirect: 'manual', headers: headers(env, body, t, redelivery), body, signal: AbortSignal.timeout(30_000) });
		const headMs = Date.now() - started;
		const text = await res.text();
		let parsed = text;
		try {
			parsed = JSON.parse(text);
		} catch {
			// not JSON: keep the text, the caller asserts on it
		}
		console.log(JSON.stringify({ label, status: res.status, ms: Date.now() - started, head_ms: headMs, retry_after: res.headers.get('retry-after'), body: parsed }));
		return res.status;
	} catch (e) {
		console.log(JSON.stringify({ label, status: null, ms: Date.now() - started, error: e.message }));
		return null;
	}
}

const env = build(eventName);
const body = JSON.stringify(env);
const t = Math.floor(Date.now() / 1000);
const redelivery = lateHours > 0 ? 'backfill' : null;
const repeat = Math.max(1, Number(flag('--repeat', 1)) || 1);
if (has('--concurrent')) {
	await Promise.all(Array.from({ length: repeat }, (_, i) => post(`${eventName}#${i + 1}`, env, body, t, redelivery)));
} else {
	for (let i = 0; i < repeat; i++) await post(repeat > 1 ? `${eventName}#${i + 1}` : eventName, env, body, t, redelivery);
}
if (has('--forgery') && eventName === 'transfer.received') {
	const upd = envelopeFor('transfer.updated');
	upd.delivery_id = randomUUID();
	upd.data = {
		transfer_id: env.data.transfer_id,
		updated_fields: ['checks.balance_chain', 'risk', 'risk_reasons', 'auto_confirm_ok'],
		checks: { ...(env.data.checks || {}), balance_chain: 'refuted' },
		risk: 'high',
		risk_reasons: ['balance_inconsistent', 'suspected_forgery'],
		auto_confirm_ok: false,
	};
	const ub = JSON.stringify(upd);
	await post('transfer.updated(forgery)', upd, ub, Math.floor(Date.now() / 1000), null);
}
if (has('--probe')) {
	const bad = createHmac('sha256', secret + 'x').update(`${t}.${body}`).digest('hex');
	const h = { ...headers(env, body, t, null), 'x-qersh-signature': `t=${t},v1=${bad}` };
	const r = await fetch(url, { method: 'POST', headers: h, body });
	console.log(JSON.stringify({ label: 'probe:bad-signature', status: r.status, body: await r.text() }));
}
