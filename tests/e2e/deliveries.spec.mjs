// The delivery scenarios of docs/17 §1 criteria 2 and 3 (and §11, §14.1), in each of the four jobs of e2e.yml
// ({Blocks, classic} × {HPOS on, off}; REST, or the ?wc-api=qersh fallback in the "Disable REST API" job):
// - the kit's own sender (fixtures/sink/send.mjs, a byte copy) against a store reset before each scenario: --probe
//   (both probes 401), --late 72 and --held 15 (the order that was open when the money arrived is paid, a second
//   order of the same payer opened now is not), --forgery (the paid order is stopped and flagged);
// - our sender (tests/e2e/deliver.mjs): one delivery id sent six times at once and again later gets the same reply
//   and completes the order once; two transfers of one payer at the same time pay its two orders; an Orange Cash
//   transfer that arrives before the customer types its transaction id pays the order once the id is typed;
// - the reply time: with every email taking 5 seconds (tests/e2e/mu/qersh-test-mail.php), a delivery that pays an
//   order is answered within 3 seconds, and "Processing order" and "New order" go out after one Action Scheduler run.
// The orders of the scenarios without a browser are made on the server through the method's process_payment()
// (tests/e2e/setup/order.php); the reply-time scenario uses the job's checkout.
import { randomBytes, randomUUID } from 'node:crypto';
import { expect, test } from '@playwright/test';
import { BASE, createOrder, deliver, deliverAsync, FINISH, KIT, kitSend, mail, orderState, placeOrder, postTxn, randPayer, randTxn, resetKit, REST_OFF, SLOW, state, wallet } from './utils.mjs';

const paid = (s) => ['processing', 'completed'].includes(s.status);
const show = (x) => JSON.stringify(x).slice(0, 1500);
/** The orders' rows were opened at least this long before the money arrived (docs/17 §11). */
const MARGIN = 5 * 60;

/** A delivery the store was too busy to take (429) is sent again once, as the phone does. */
async function deliverRetried(w, args) {
	let reply = (await deliverAsync(w, args))[0];
	if (reply.status === 429) reply = (await deliverAsync(w, args))[0];
	return reply;
}

/** The order's state after running Action Scheduler until it is paid (at most `tries` batches). */
async function settled(id, tries = 3) {
	let s = orderState(id, true);
	for (let i = 1; i < tries && !paid(s); i++) s = orderState(id, true);
	return s;
}

test.describe(`the kit's sender against a reset store (${REST_OFF ? 'fallback address' : 'REST'})`, () => {
	test.beforeEach(() => {
		resetKit();
	});

	test('--probe: the delivery pays the order, the bad signature and the stale timestamp get 401', () => {
		const w = wallet('vodafone_cash');
		const order = createOrder({ product: state().products['10000'], w, payer: KIT.payer, backdate: 60 });
		const { lines, out } = kitSend(w, ['--probe', '--amount', '100']);
		const main = lines.find((l) => l.label === 'transfer.received');
		expect(main && main.status, out).toBe(200);
		expect(main.body, out).toContain('"status":"matched"');
		expect((lines.find((l) => l.label === 'bad signature') || {}).status, out).toBe(401);
		expect((lines.find((l) => l.label === 'stale timestamp') || {}).status, out).toBe(401);
		const after = orderState(order.id, true);
		expect(paid(after), show(after)).toBe(true);
	});

	for (const [option, value, back] of [
		['--late', '72', 72 * 3600],
		['--held', '15', 15 * 60],
	]) {
		test(`${option} ${value}: the order open when the money arrived is paid, a newer order of the payer is not`, () => {
			const w = wallet('vodafone_cash');
			const product = state().products['10000'];
			const open = createOrder({ product, w, payer: KIT.payer, shift: back + MARGIN });
			const newer = createOrder({ product, w, payer: KIT.payer });
			const { lines, out } = kitSend(w, [option, value, '--amount', '100']);
			const main = lines.find((l) => l.label === 'transfer.received');
			expect(main && main.status, out).toBe(200);
			expect(main.body, out).toContain('"status":"matched"');
			expect(main.body, out).toContain(`"reference":"${open.id}"`);

			const a = orderState(open.id, true);
			expect(paid(a), show(a)).toBe(true);
			const b = orderState(newer.id);
			expect(b.status, show(b)).toBe('pending');
			expect(b.row.paid_minor, show(b)).toBe(0);
		});
	}

	test('--forgery: the paid order is stopped and flagged', () => {
		const w = wallet('vodafone_cash');
		const order = createOrder({ product: state().products['10000'], w, payer: KIT.payer, backdate: 60 });
		const { lines, out } = kitSend(w, ['--forgery', '--amount', '100']);
		const main = lines.find((l) => l.label === 'transfer.received');
		const update = lines.find((l) => l.label === 'transfer.updated');
		expect(main && main.status, out).toBe(200);
		expect(main.body, out).toContain('"status":"matched"');
		expect(update && update.status, out).toBe(200);
		expect(update.body, out).toContain('"status":"ok"');

		const after = orderState(order.id, true);
		expect(after.meta._qersh_flag, show(after)).toBeTruthy();
		expect(['processing', 'completed'], show(after)).not.toContain(after.status);
	});
});

test.describe(`our sender (${REST_OFF ? 'fallback address' : 'REST'})`, () => {
	test('one delivery sent six times at once, then again, gets one reply and completes the order once', () => {
		const w = wallet('vodafone_cash');
		const payer = randPayer();
		// The 1,500 product: no other scenario completes an order of it, so its stock shows this order's completion.
		const product = state().products['150000'];
		const order = createOrder({ product, w, payer, backdate: 60 });
		const before = orderState(order.id);
		const same = ['--payer', payer, '--amount', '1500', '--delivery-id', randomUUID(), '--transfer-id', 'trf_' + randomBytes(12).toString('hex'), '--txn', String(Date.now()).slice(-12)];

		const copies = deliver(w, [...same, '--repeat', '6', '--concurrent']);
		const ok = copies.filter((r) => r.status === 200);
		expect(copies.length, show(copies)).toBe(6);
		expect(ok.length, show(copies)).toBeGreaterThanOrEqual(1);
		expect(copies.every((r) => r.status === 200 || r.status === 429), show(copies)).toBe(true);
		for (const r of ok) {
			expect(r.body.status, show(r)).toBe('matched');
			expect(String(r.body.reference), show(r)).toBe(String(ok[0].body.reference));
		}
		const again = deliver(w, same)[0];
		expect(again.status, show(again)).toBe(200);
		expect(again.body.status, show(again)).toBe(ok[0].body.status);
		expect(String(again.body.reference), show(again)).toBe(String(ok[0].body.reference));

		const after = orderState(order.id, true);
		expect(paid(after), show(after)).toBe(true);
		const key = String(product);
		expect(before.stock[key] - after.stock[key], 'stock reduced exactly once').toBe(1);
		expect(after.transfers.length, show(after)).toBe(1);
	});

	test('two transfers of one payer at the same time pay its two orders', async () => {
		const w = wallet('vodafone_cash');
		const payer = randPayer();
		const product = state().products['10000'];
		const first = createOrder({ product, w, payer, backdate: 120 });
		const second = createOrder({ product, w, payer, backdate: 60 });
		const args = ['--payer', payer, '--amount', '100'];
		const [r1, r2] = await Promise.all([deliverRetried(w, args), deliverRetried(w, args)]);
		for (const r of [r1, r2]) {
			expect(r.status, show(r)).toBe(200);
			expect(r.body.status, show(r)).toBe('matched');
		}
		expect([String(r1.body.reference), String(r2.body.reference)].sort()).toEqual([String(first.id), String(second.id)].sort());
		for (const o of [first, second]) {
			const s = orderState(o.id, true);
			expect(paid(s), show(s)).toBe(true);
			expect(s.transfers.length, show(s)).toBe(1);
		}
	});

	test('Orange Cash: a transfer that arrives before its transaction id is typed pays the order once it is', async () => {
		const w = wallet('orange_cash');
		const payer = randPayer();
		const txn = randTxn();
		const order = createOrder({ product: state().products['14000'], w, payer, backdate: 60 });
		const [reply] = deliver(w, ['--no-payer', '--txn', txn, '--amount', '140', '--reasons', '', '--auto-ok', 'true', '--risk', 'low']);
		expect(reply.status, show(reply)).toBe(200);
		expect(reply.body.status, show(reply)).toBe('review');

		const claim = await postTxn(order, txn);
		expect(claim.http, show(claim)).toBe(200);
		expect(claim.body && claim.body.status, show(claim)).toBe('saved');
		const after = await settled(order.id);
		expect(paid(after), show(after)).toBe(true);
		expect(after.meta._qersh_claim_txn).toBe(txn);
	});
});

test.describe(`the reply does not wait for the mail (${REST_OFF ? 'fallback address' : 'REST'}${FINISH ? ', fastcgi_finish_request' : ''})`, () => {
	test.afterEach(() => {
		if (mail().active) mail({ slow: false });
	});

	// The addresses the phone may use: the REST route (pretty permalinks, and ?rest_route= as on plain permalinks), or
	// the fallback when REST is blocked.
	const forms = REST_OFF ? ['fallback'] : ['rest', 'rest_route'];
	for (const form of forms) {
		test(`a delivery is answered within 3 seconds while each email takes 5 (${form})`, async ({ page }) => {
			test.skip(!mail().active, 'needs tests/e2e/mu/qersh-test-mail.php (wp-env-override.mjs --mu-mail)');
			const w = wallet('vodafone_cash');
			const payer = randPayer();
			const order = await placeOrder(page, { product: state().products['14000'], w, payer });

			// Mail queued by earlier tests runs now, while it is quick; then every email takes 5 seconds.
			const since = mail({ drain: true, clear: true, slow: true }).now;
			await page.waitForTimeout(2000); // the money must be newer than the order (skew 0)
			const url = form === 'fallback' ? w.fallback : form === 'rest_route' ? `${BASE}/?rest_route=/qersh/v1/webhook/${w.id}` : w.url;
			const [reply] = deliver(w, ['--payer', payer, '--amount', '140'], url);
			expect(reply.status, show(reply)).toBe(200);
			expect(reply.body.status, show(reply)).toBe('matched');
			if (FINISH) {
				// The stand-in fastcgi_finish_request() only flushes: Apache sends the reply, then keeps the connection
				// until the completion and its emails are done. The phone has its answer when the headers arrive.
				expect(reply.head_ms, `the reply arrived after ${reply.head_ms} ms`).toBeLessThanOrEqual(3000 * SLOW);
			} else {
				expect(reply.ms, `the reply took ${reply.ms} ms`).toBeLessThanOrEqual(3000 * SLOW);
			}

			const after = orderState(order.id, true);
			expect(paid(after), show(after)).toBe(true);
			const sent = mail({ since }).log.filter((m) => m.order === order.id);
			const ids = sent.map((m) => m.id);
			expect(ids, show(sent)).toContain('customer_processing_order');
			expect(ids, show(sent)).toContain('new_order');
			for (const m of sent) expect(m.slow, show(m)).toBe(true);
		});
	}
});
