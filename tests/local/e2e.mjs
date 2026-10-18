// Local end-to-end runs against a test store served by `php -S` with tests/local/router.php (see README.md here).
//   node tests/local/e2e.mjs [--only name,name]
// Environment: QERSH_SITE (the site directory, default ../local-site next to this repository), QERSH_BASE (default
// http://127.0.0.1:8899), QERSH_WALLETS (the file setup-wallets.php wrote), QERSH_PHP (default "php").
// Checks out through the Store API (what Checkout Blocks uses), sends signed deliveries with tests/e2e/deliver.mjs,
// runs cron and Action Scheduler with run-cron.php, and reads the order with order-state.php. The two test wallets
// (`main` and `second`) are told apart by their label: a wallet has no type, and the plugin never reads the
// envelope's wallet.provider to decide anything. Prints PASS/FAIL per scenario and exits 1 if any fails.
import { execFileSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { existsSync, readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const REPO = path.resolve(here, '..', '..');
const SITE = (process.env.QERSH_SITE || path.resolve(REPO, '..', 'local-site')).replace(/\\/g, '/');
const BASE = (process.env.QERSH_BASE || 'http://127.0.0.1:8899').replace(/\/+$/, '');
const PHP = process.env.QERSH_PHP || 'php';
const WALLETS = process.env.QERSH_WALLETS || path.join(path.dirname(SITE), 'qersh-wallets.local.json');
if (!existsSync(WALLETS)) {
	console.error(`No wallets file at ${WALLETS}: run "php tests/local/setup-wallets.php" first (or set QERSH_WALLETS).`);
	process.exit(2);
}
const cfg = JSON.parse(readFileSync(WALLETS, 'utf8'));
const W = Object.fromEntries(cfg.wallets.map((w) => [w.label, w]));
for (const label of ['main', 'second']) {
	if (!W[label]) {
		console.error(`No "${label}" wallet in ${WALLETS}: run "php tests/local/setup-wallets.php" again.`);
		process.exit(2);
	}
}
const only = (() => {
	const i = process.argv.indexOf('--only');
	return i > 0 ? process.argv[i + 1].split(',') : null;
})();

// The plugin's own reader, the one the order page runs (assets/js/txn-normalize.js, a copy of Core\TxnId).
const N = createRequire(import.meta.url)(path.join(REPO, 'assets/js/txn-normalize.js'));
const STORE_NUMBERS = cfg.wallets.map((w) => w.receive_address);

const childEnv = { ...process.env, QERSH_SITE: SITE, QERSH_BASE: BASE };
const php = (script, ...args) => execFileSync(PHP, [path.join(here, script), ...args.map(String)], { encoding: 'utf8', timeout: 120000, env: childEnv });
const state = (id, ...extra) => JSON.parse(php('order-state.php', id, ...extra).trim().split('\n').pop());
const cron = () => php('run-cron.php');
/** A signed delivery; the envelope's wallet.provider is the vector's own unless args holds --provider. */
function deliver(wallet, args, url = wallet.url) {
	const out = execFileSync('node', [path.join(REPO, 'tests/e2e/deliver.mjs'), url, '--secret', wallet.key, '--wallet', wallet.id, ...args.map(String)], { encoding: 'utf8', timeout: 120000 });
	return out.trim().split('\n').filter(Boolean).map((l) => JSON.parse(l));
}
const randPayer = () => '011' + String(10000000 + (Number('0x' + randomBytes(3).toString('hex')) % 89999999)).padStart(8, '0');
/** An invented transaction id of `length` digits that never reads as a mobile number (its first digit is 3 to 9). */
function randTxn(length = 12) {
	const bytes = randomBytes(length);
	return String(3 + (bytes[0] % 7)) + Array.from(bytes.subarray(1), (b) => b % 10).join('');
}
/** A whole wallet message as a customer pastes it: an invented wording, the amount, the payer, a balance, a date, the id. */
function pastedMessage(payer, txn) {
	const raw = [`تم استلام تحويل بمبلغ 140.00 جنيه من الرقم ${payer}`, `رقم العملية ${txn}`, 'رصيدك الحالي 288.40 جنيه', '18/10/2026 03:45 م'].join('\n');
	const res = N.canonicalTyped(raw, payer, STORE_NUMBERS);
	if (!res.ok || res.txn !== txn) throw new Error(`the order page would not read ${txn} out of the message: ${JSON.stringify(res)}`);
	return raw;
}
/** What a delivery without a payer number sends: the vector's name only, the id, and the phone's own "may confirm". */
const payerless = (txn, amount = '140') => ['--no-payer', '--txn', txn, '--amount', amount, '--reasons', '', '--auto-ok', 'true', '--risk', 'low'];

// The four products of setup-store.php, by price in piasters (the Store API gives prices as minor-unit strings).
async function products() {
	const r = await fetch(`${BASE}/wp-json/wc/store/v1/products?search=${encodeURIComponent('Qersh test')}&per_page=20`);
	if (!r.ok) throw new Error('products ' + r.status + ': run "php tests/local/setup-store.php" first');
	const out = {};
	for (const p of await r.json()) out[Number(p.prices.price)] = p.id;
	for (const minor of [10000, 13950, 14000]) {
		if (!out[minor]) throw new Error(`no "Qersh test" product at ${minor / 100} EGP: run "php tests/local/setup-store.php" first`);
	}
	return out;
}
const P = await products();
// One test delivery per wallet first (what the app sends when the merchant taps "Send test"): it warms the webhook
// path of `php -S` (the first request after the code changed compiles the plugin again), so the reply times below
// measure the store, not the warm-up.
for (const w of Object.values(W)) deliver(w, ['--event', 'webhook.test']);

/** One Store API checkout with the method: { ok, status, body }, never throws on a refusal. */
async function placeOrder({ productId, payer, wallet }) {
	const start = await fetch(`${BASE}/wp-json/wc/store/v1/cart`);
	const token = start.headers.get('cart-token');
	const nonce = start.headers.get('nonce');
	const h = { 'content-type': 'application/json', 'cart-token': token, nonce };
	const add = await fetch(`${BASE}/wp-json/wc/store/v1/cart/add-item`, { method: 'POST', headers: h, body: JSON.stringify({ id: productId, quantity: 1 }) });
	if (!add.ok) throw new Error('add-item ' + add.status + ' ' + (await add.text()).slice(0, 300));
	// The billing phone is the payer when the payer is a customer's number (as a customer usually fills it).
	const phone = N.checkPayer(payer, STORE_NUMBERS).ok ? payer : '01155500011';
	const address = { first_name: 'Test', last_name: 'Customer', address_1: '1 Test St', city: 'Cairo', state: 'EGC', postcode: '11511', country: 'EG', email: 'customer@example.test', phone };
	const payment_data = [{ key: 'qersh_payer', value: payer }];
	if (wallet) payment_data.push({ key: 'qersh_wallet', value: wallet.id });
	const res = await fetch(`${BASE}/wp-json/wc/store/v1/checkout`, { method: 'POST', headers: h, body: JSON.stringify({ billing_address: address, shipping_address: address, payment_method: 'qersh', payment_data }) });
	return { ok: res.ok, status: res.status, body: await res.json().catch(() => null) };
}
async function checkout({ productId, payer, wallet }) {
	const { ok, status, body } = await placeOrder({ productId, payer, wallet });
	if (!ok) throw new Error('checkout ' + status + ' ' + JSON.stringify(body).slice(0, 400));
	// The money of a delivery arrives "one second before now": an order placed in the same second would look newer
	// than the transfer (skew_min = 0, docs/17 §4.6), so the row is moved a minute back, as a real wait would.
	state(body.order_id, '--backdate-created', 60);
	return { id: body.order_id, key: body.order_key, body };
}
async function orderStatus(o) {
	const r = await fetch(`${BASE}/wp-json/qersh/v1/order-status`, { method: 'POST', headers: { 'content-type': 'application/json' }, body: JSON.stringify({ order_id: o.id, key: o.key }) });
	return { http: r.status, body: await r.json().catch(() => null), cache: r.headers.get('cache-control') };
}
async function claim(o, txn) {
	const r = await fetch(`${BASE}/wp-json/qersh/v1/order-txn`, { method: 'POST', headers: { 'content-type': 'application/json' }, body: JSON.stringify({ order_id: o.id, key: o.key, txn }) });
	return { http: r.status, body: await r.json().catch(() => null) };
}

const results = [];
async function scenario(name, fn) {
	if (only && !only.includes(name)) return;
	const started = Date.now();
	try {
		const detail = await fn();
		results.push({ name, ok: true, ms: Date.now() - started, detail });
		console.log(`PASS ${name} (${Date.now() - started} ms)`);
	} catch (e) {
		results.push({ name, ok: false, error: e.message });
		console.log(`FAIL ${name}: ${e.message}`);
	}
}
const expect = (cond, msg, data) => {
	if (!cond) throw new Error(msg + (data !== undefined ? ' :: ' + JSON.stringify(data).slice(0, 700) : ''));
};
const paid = (s) => ['processing', 'completed'].includes(s.status);

await scenario('payer-match', async () => {
	const payer = randPayer();
	const o = await checkout({ productId: P[14000], payer, wallet: W.main });
	const before = state(o.id);
	expect(before.status === 'pending', 'order should wait pending', before);
	const r = deliver(W.main, ['--payer', payer, '--amount', '140'])[0];
	expect(r.status === 200 && r.body.status === 'matched', 'reply should be 200 matched', r);
	expect(Boolean(r.body.reference), 'reply carries the order number', r);
	expect(r.ms < 5000, 'reply within 5 s', r);
	cron();
	const after = state(o.id);
	expect(paid(after), 'order completed', after);
	const st = await orderStatus(o);
	expect(st.http === 200 && st.body && st.body.status === 'paid', 'status route says paid', st);
	expect(/no-store|no-cache/.test(st.cache || ''), 'status route not cacheable', st);
	return { order: o.id, ms: r.ms };
});

await scenario('payer-number-only', async () => {
	// The payer is an e-wallet number: an address, a name or one of the store's own numbers is refused at checkout.
	const refused = {};
	for (const value of ['qershtest@instapay', 'Test Customer', W.second.receive_address]) {
		const r = await placeOrder({ productId: P[10000], payer: value, wallet: W.main });
		expect(!r.ok && r.status >= 400 && r.status < 500, `checkout refuses the payer "${value}"`, r);
		refused[value] = r.status;
	}
	return refused;
});

await scenario('duplicate-and-concurrent', async () => {
	const payer = randPayer();
	const o = await checkout({ productId: P[10000], payer, wallet: W.main });
	const stockBefore = Object.values(state(o.id).stock)[0];
	const rs = deliver(W.main, ['--payer', payer, '--amount', '100', '--repeat', '6', '--concurrent']);
	const ok = rs.filter((x) => x.status === 200);
	const busy = rs.filter((x) => x.status === 429);
	expect(ok.length >= 1 && ok.length + busy.length === 6, 'six copies: 200 or 429 only', rs);
	expect(ok.every((x) => x.body.status === 'matched'), 'every 200 says matched', ok);
	cron();
	const after = state(o.id);
	expect(paid(after), 'order completed once', after);
	const stockAfter = Object.values(after.stock)[0];
	expect(stockBefore - stockAfter === 0 || stockBefore - stockAfter === 1, 'stock reduced at most once', { stockBefore, stockAfter });
	return { ok: ok.length, busy: busy.length, stockBefore, stockAfter };
});

await scenario('bad-signature-and-unknown-wallet', async () => {
	const r = deliver(W.main, ['--payer', randPayer(), '--amount', '1', '--probe']);
	const probe = r.find((x) => x.label === 'probe:bad-signature');
	expect(probe && probe.status === 401, 'bad signature → 401', probe);
	const unknown = await fetch(`${BASE}/wp-json/qersh/v1/webhook/aaaaaaaaaaaa`, { method: 'POST', headers: { 'content-type': 'application/json' }, body: '{}' });
	expect(unknown.status === 404, 'unknown wallet → 404', unknown.status);
	return { probe: probe.status, unknown: unknown.status };
});

await scenario('partial-then-rest', async () => {
	const payer = randPayer();
	const o = await checkout({ productId: P[14000], payer, wallet: W.main });
	const r1 = deliver(W.main, ['--payer', payer, '--amount', '100'])[0];
	expect(r1.status === 200 && r1.body.status === 'partial', 'first transfer partial', r1);
	const mid = state(o.id);
	expect(mid.status === 'pending' && Number(mid.row.paid_minor) === 10000, 'still pending with 100 paid', mid);
	const r2 = deliver(W.main, ['--payer', payer, '--amount', '40'])[0];
	expect(r2.status === 200 && r2.body.status === 'matched', 'second transfer completes', r2);
	cron();
	expect(paid(state(o.id)), 'order completed', state(o.id));
});

await scenario('personal-transfer', async () => {
	const r = deliver(W.main, ['--payer', randPayer(), '--amount', '77'])[0];
	expect(r.status === 200 && r.body.status === 'unmatched', 'no order → unmatched', r);
	expect(/شخصي|personal/i.test(r.body.note || ''), 'note says personal transfer', r);
});

await scenario('round-up-139.50', async () => {
	const payer = randPayer();
	await checkout({ productId: P[13950], payer, wallet: W.main });
	const r = deliver(W.main, ['--payer', payer, '--amount', '140'])[0];
	expect(r.status === 200 && r.body.status === 'matched', '140 for 139.50 → matched', r);
});

await scenario('provider-is-ignored', async () => {
	// The same payer-matched transfer, whatever the envelope's wallet.provider says (a known value, another one, or a
	// string the plugin has never seen): the same verdict, and the value is only kept on the transfer's row.
	const out = {};
	for (const provider of ['vodafone_cash', 'orange_cash', 'etisalat_cash', 'not_a_wallet_type']) {
		const payer = randPayer();
		const o = await checkout({ productId: P[10000], payer, wallet: W.second });
		const r = deliver(W.second, ['--payer', payer, '--amount', '100', '--provider', provider])[0];
		expect(r.status === 200 && r.body.status === 'matched' && String(r.body.reference) === String(o.id), `wallet.provider ${provider}: matched`, r);
		cron();
		const after = state(o.id);
		expect(paid(after), `wallet.provider ${provider}: order completed`, after);
		expect(after.transfers.length === 1 && after.transfers[0].provider === provider, `wallet.provider ${provider} kept on the row as information`, after.transfers);
		out[provider] = r.body.status;
	}
	return out;
});

await scenario('txn-claim-first', async () => {
	// The second wallet: the transaction-id box is the same for every wallet.
	const payer = randPayer();
	const txn = randTxn();
	const o = await checkout({ productId: P[14000], payer, wallet: W.second });
	const s0 = await orderStatus(o);
	expect(s0.http === 200 && s0.body.status === 'waiting' && s0.body.claim === false, 'the order page takes an id, none saved yet', s0);
	const c = await claim(o, pastedMessage(payer, txn));
	expect(c.http === 200 && c.body && c.body.status === 'saved' && c.body.txn === txn, 'claim saved with the id read out of a pasted message', c);
	const s1 = await orderStatus(o);
	expect(s1.http === 200 && s1.body.claim === true, 'the status route says an id is saved', s1);
	const r = deliver(W.second, payerless(txn))[0];
	expect(r.status === 200 && r.body.status === 'matched' && String(r.body.reference) === String(o.id), 'a delivery with no payer number matched by the typed id', r);
	cron();
	const after = state(o.id);
	expect(paid(after), 'order completed', after);
	expect(after.meta._qersh_claim_txn === txn, 'the canonical id is on the order', after.meta);
});

await scenario('txn-delivery-first', async () => {
	const payer = randPayer();
	const txn = randTxn();
	const o = await checkout({ productId: P[14000], payer, wallet: W.main });
	const r = deliver(W.main, payerless(txn))[0];
	expect(r.status === 200 && r.body.status === 'review', 'no claim yet → review', r);
	// The id typed in groups, as a customer may copy it, is read whole.
	const c = await claim(o, `${txn.slice(0, 4)} ${txn.slice(4, 8)} ${txn.slice(8)}`);
	expect(c.http === 200 && c.body.status === 'saved' && c.body.txn === txn, 'claim saved', c);
	cron();
	cron();
	const after = state(o.id);
	expect(paid(after), 'late claim completes the order', after);
});

await scenario('masked-delivery-first', async () => {
	// A masked sender number is payerless too: the typed id decides it the same way.
	const payer = randPayer();
	const txn = randTxn(15);
	const o = await checkout({ productId: P[14000], payer, wallet: W.second });
	const r = deliver(W.second, ['--payer', '0111****445', '--txn', txn, '--amount', '140', '--reasons', '', '--auto-ok', 'true', '--risk', 'low'])[0];
	expect(r.status === 200 && r.body.status === 'review', 'masked sender, no claim yet → review', r);
	const c = await claim(o, txn);
	expect(c.http === 200 && c.body.status === 'saved', 'claim saved', c);
	cron();
	cron();
	const after = state(o.id);
	expect(paid(after), 'late claim completes the order', after);
});

await scenario('txn-other-wallet', async () => {
	// A typed id never reaches across wallets: the order waits on `main`, the transfer with that id reached `second`.
	const payer = randPayer();
	const txn = randTxn();
	const o = await checkout({ productId: P[14000], payer, wallet: W.main });
	const c = await claim(o, txn);
	expect(c.http === 200 && c.body.status === 'saved', 'claim saved', c);
	const r = deliver(W.second, payerless(txn))[0];
	expect(r.status === 200 && r.body.status !== 'matched', 'a transfer on another wallet does not match the order', r);
	cron();
	cron();
	const after = state(o.id);
	expect(after.status === 'pending' && Number(after.row.paid_minor) === 0, 'the order still waits', after);
	return { reply: r.body.status };
});

await scenario('payer-wins-over-txn', async () => {
	// A typed id decides only payerless transfers: a transfer that names a payer goes by its payer, never by an id
	// another order typed.
	const payerA = randPayer();
	const payerB = randPayer();
	const txn = randTxn();
	const a = await checkout({ productId: P[10000], payer: payerA, wallet: W.main });
	const b = await checkout({ productId: P[10000], payer: payerB, wallet: W.main });
	const c = await claim(b, txn);
	expect(c.http === 200 && c.body.status === 'saved', 'claim saved on order B', c);
	const r = deliver(W.main, ['--payer', payerA, '--txn', txn, '--amount', '100'])[0];
	expect(r.status === 200 && r.body.status === 'matched' && String(r.body.reference) === String(a.id), "the payer's own order is paid", r);
	cron();
	expect(paid(state(a.id)), 'order A completed', state(a.id));
	const sb = state(b.id);
	expect(sb.status === 'pending' && Number(sb.row.paid_minor) === 0, 'order B still waits', sb);
});

await scenario('forgery-after-paid', async () => {
	const payer = randPayer();
	const o = await checkout({ productId: P[10000], payer, wallet: W.main });
	const rs = deliver(W.main, ['--payer', payer, '--amount', '100', '--forgery']);
	expect(rs[0].status === 200 && rs[0].body.status === 'matched', 'matched first', rs);
	expect(rs[1].status === 200 && rs[1].body.status === 'ok', 'update answered ok', rs);
	cron();
	const after = state(o.id);
	expect(after.status === 'on-hold' || after.meta._qersh_flag, 'order stopped or flagged', after);
});

await scenario('fallback-wc-api', async () => {
	const payer = randPayer();
	const o = await checkout({ productId: P[10000], payer, wallet: W.main });
	const url = W.main.fallback || `${BASE}/?wc-api=qersh&qw=${W.main.id}`;
	const r = deliver(W.main, ['--payer', payer, '--amount', '100'], url)[0];
	expect(r.status === 200 && r.body.status === 'matched', 'fallback address works', r);
	return { order: o.id };
});

await scenario('expiry-cancels', async () => {
	const payer = randPayer();
	const o = await checkout({ productId: P[10000], payer, wallet: W.main });
	state(o.id, '--expire-now');
	cron();
	const after = state(o.id, '--run-expiry');
	expect(after.status === 'cancelled' && after.row.state === 'expired', 'expired order cancelled', after);
});

const failed = results.filter((r) => !r.ok);
console.log(`\n${results.length - failed.length}/${results.length} scenarios passed`);
process.exit(failed.length ? 1 : 0);
