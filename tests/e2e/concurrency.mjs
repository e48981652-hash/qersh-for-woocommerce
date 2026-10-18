// Concurrency on a real database (docs/17 §11 nightly "the concurrency test on MariaDB too", §14.1): run by the
// nightly MariaDB job on wp-env (.wp-env.test.json), with no browser.
//   QERSH_E2E_BASE=http://localhost:8890 QERSH_E2E_WP_ENV_CONFIG=.wp-env.test.json node tests/e2e/concurrency.mjs
// Prepares the store and two test wallets (tests/e2e/setup/store.php, wallets.php; written to tests/e2e/.auth/), then:
// 1. a transfer that names no payer number: six POSTs of the transaction id and six copies of the delivery with that
//    id, all at once; the route and the delivery take the same lock, txn:<id> (MatchPolicy::txn_lock()) ← one
//    decision, one completion (stock reduced once, one transfer on the order), no 5xx (no deadlock);
// 2. two transfers of one payer at the same time ← each pays one of its two orders;
// 3. two crossing manual attaches (payer A's transfer to payer B's order and B's to A's) at the same instant ← both
//    succeed (the locks are taken in one order), no database error.
// Prints PASS/FAIL per scenario; exit 1 when one fails.
import { randomBytes, randomUUID } from 'node:crypto';
import { mkdirSync, writeFileSync } from 'node:fs';
import { BASE, createOrder, deliverAsync, orderState, postTxn, randPayer, randTxn, state, STATE_DIR, STATE_FILE, wallet, wp, wpAsync } from './utils.mjs';

const paid = (s) => ['processing', 'completed'].includes(s.status);
const show = (x) => JSON.stringify(x).slice(0, 1500);
const check = (cond, message, data) => {
	if (!cond) throw new Error(message + (data === undefined ? '' : ' :: ' + show(data)));
};
async function settled(id, tries = 4) {
	let s = orderState(id, true);
	for (let i = 1; i < tries && !paid(s); i++) s = orderState(id, true);
	return s;
}

const store = wp('store.php', 'checkout=blocks');
const wallets = wp('wallets.php');
mkdirSync(STATE_DIR, { recursive: true });
writeFileSync(STATE_FILE, JSON.stringify({ ...store, ...wallets, base: BASE }, null, '\t'));
console.log(`concurrency: WordPress ${store.versions.wp}, WooCommerce ${store.versions.wc}, PHP ${store.versions.php}, HPOS ${store.hpos ? 'on' : 'off'}, ${BASE}`);

const results = [];
async function scenario(name, fn) {
	const started = Date.now();
	try {
		await fn();
		results.push(true);
		console.log(`PASS ${name} (${Date.now() - started} ms)`);
	} catch (e) {
		results.push(false);
		console.log(`FAIL ${name}: ${e.message}`);
	}
}

await scenario('txn-and-delivery-together', async () => {
	const w = wallet('second');
	const product = state().products['150000'];
	const order = createOrder({ product, w, payer: randPayer(), backdate: 60 });
	const before = orderState(order.id);
	const txn = randTxn();
	const [claims, copies] = await Promise.all([
		Promise.all(Array.from({ length: 6 }, () => postTxn(order, txn))),
		deliverAsync(w, ['--no-payer', '--txn', txn, '--amount', '1500', '--reasons', '', '--auto-ok', 'true', '--risk', 'low', '--delivery-id', randomUUID(), '--repeat', '6', '--concurrent']),
	]);
	check(claims.every((c) => [200, 409, 429].includes(c.http)), 'the transaction-id POSTs answer 200, 409 or 429 only', claims);
	check(claims.some((c) => c.http === 200), 'one transaction-id POST at least is saved', claims);
	check(copies.length === 6 && copies.every((r) => r.status === 200 || r.status === 429), 'the deliveries answer 200 or 429 only', copies);
	const after = await settled(order.id);
	check(paid(after), 'the order is paid', after);
	const key = String(product);
	check(before.stock[key] - after.stock[key] === 1, 'stock reduced exactly once', { before: before.stock, after: after.stock });
	check(after.transfers.length === 1, 'one transfer on the order', after.transfers);
});

await scenario('one-payer-two-transfers-together', async () => {
	const w = wallet('main');
	const payer = randPayer();
	const product = state().products['10000'];
	const first = createOrder({ product, w, payer, backdate: 120 });
	const second = createOrder({ product, w, payer, backdate: 60 });
	const send = async () => {
		let r = (await deliverAsync(w, ['--payer', payer, '--amount', '100']))[0];
		if (r.status === 429) r = (await deliverAsync(w, ['--payer', payer, '--amount', '100']))[0];
		return r;
	};
	const replies = await Promise.all([send(), send()]);
	check(replies.every((r) => r.status === 200 && r.body.status === 'matched'), 'both transfers matched', replies);
	check(replies.map((r) => String(r.body.reference)).sort().join() === [first.id, second.id].map(String).sort().join(), 'each pays one order', replies);
	for (const o of [first, second]) check(paid(orderState(o.id, true)), `order ${o.id} is paid`);
});

await scenario('crossing-manual-attaches', async () => {
	const w = wallet('main');
	const product = state().products['10000'];
	const a = { payer: randPayer(), transfer: 'trf_' + randomBytes(12).toString('hex') };
	const b = { payer: randPayer(), transfer: 'trf_' + randomBytes(12).toString('hex') };
	for (const p of [a, b]) {
		const [r] = await deliverAsync(w, ['--payer', p.payer, '--amount', '100', '--transfer-id', p.transfer]);
		check(r.status === 200 && r.body.status === 'unmatched', 'a transfer with no order is personal', r);
	}
	const orderA = createOrder({ product, w, payer: a.payer });
	const orderB = createOrder({ product, w, payer: b.payer });
	const at = Date.now() + 15_000; // both WP-CLI processes are loaded by then and call apply_attach() together
	const [ab, ba] = await Promise.all([wpAsync('attach.php', `transfer=${a.transfer}`, `order=${orderB.id}`, `at=${at}`), wpAsync('attach.php', `transfer=${b.transfer}`, `order=${orderA.id}`, `at=${at}`)]);
	check(ab.ok && ba.ok, 'both attaches succeed', { ab, ba });
	check(!ab.db_error && !ba.db_error, 'no database error (deadlock)', { ab, ba });
	for (const o of [orderA, orderB]) check(paid(await settled(o.id)), `order ${o.id} is paid`);
});

const failed = results.filter((ok) => !ok).length;
console.log(`\n${results.length - failed}/${results.length} concurrency scenarios passed`);
process.exit(failed ? 1 : 0);
