// The transaction-id box on the thank-you page (docs/17 §4.10, §14.1), the same for every wallet while the store
// takes transaction ids (store.txn_entry, on in tests/e2e/setup/wallets.php): the customer pastes the whole transfer
// message (an invented wording with line breaks, utils.mjs pastedMessage()), the page sends the id alone, the status
// line becomes the neutral "saved" line, and the delivery of that transfer, which names no payer number, then turns
// the order paid without a reload. With the "Disable REST API" plugin (REST_OFF), the page's first POST to the REST
// route is refused (401/403) and the same body goes to ?wc-api=qersh&qo=txn; the test waits for the address the job
// uses.
import { expect, test } from '@playwright/test';
import { boxConfig, CHECKOUT, deliver, orderState, pastedMessage, placeOrder, randPayer, REST_OFF, state, wallet } from './utils.mjs';

/** The order-txn POSTs of the page: the REST route (/qersh/v1/order-txn) or the fallback address (qo=txn). */
const isRestTxn = (r) => r.request().method() === 'POST' && /\/qersh\/v1\/order-txn/.test(decodeURIComponent(r.url()));
const isFallbackTxn = (r) => r.request().method() === 'POST' && /[?&]qo=txn(&|$)/.test(r.url());

/** Pastes text into an input the way a browser does, through a paste event with clipboard data. */
async function paste(locator, text) {
	await locator.focus();
	await locator.evaluate((el, value) => {
		const data = new DataTransfer();
		data.setData('text', value);
		el.dispatchEvent(new ClipboardEvent('paste', { clipboardData: data, bubbles: true, cancelable: true }));
	}, text);
}

test.describe(`transaction-id box (${CHECKOUT})`, () => {
	test('a pasted message sends the id alone, then the delivery pays the order', async ({ page }) => {
		const payer = randPayer();
		const w = wallet('second');
		// Every request of the page to our routes and its console, reported if the answer does not come.
		const trail = [];
		page.on('request', (r) => {
			if (/qersh|order-txn|order-status/.test(decodeURIComponent(r.url()))) trail.push(`${r.method()} ${r.url()}`);
		});
		page.on('requestfinished', async (r) => {
			if (/qersh|order-txn|order-status/.test(decodeURIComponent(r.url()))) trail.push(`done ${(await r.response())?.status()} ${r.url()}`);
		});
		page.on('requestfailed', (r) => trail.push(`failed ${r.url()}: ${r.failure()?.errorText}`));
		page.on('console', (m) => trail.push(`console ${m.type()}: ${m.text()}`));
		const order = await placeOrder(page, { product: state().products['14000'], w, payer });
		const message = pastedMessage(payer);

		const box = page.locator('#qersh-txn');
		await expect(box).toBeVisible();
		const input = page.locator('#qersh_txn');
		await expect(input).toHaveAttribute('dir', 'ltr');
		await expect(input).toHaveAttribute('inputmode', 'text'); // an id may hold letters: a phone shows its letter keyboard
		await expect(input).toHaveAttribute('autocapitalize', 'characters');
		await expect(input).toHaveAttribute('maxlength', '400');

		await paste(input, message.raw);
		const pasted = await input.inputValue();
		expect(pasted.length, 'the whole message is in the field').toBeGreaterThan(message.txn.length);
		expect(pasted).toContain(' '); // the line breaks are kept as paragraph separators

		const seen = [];
		page.on('response', (r) => {
			if (isRestTxn(r) || isFallbackTxn(r)) seen.push({ url: r.url(), status: r.status() });
		});
		const answer = page.waitForResponse(REST_OFF ? isFallbackTxn : isRestTxn);
		await page.locator('[data-qersh-txn-send]').click();
		const response = await answer.catch(async (e) => {
			const line = await page.locator('[data-qersh-status]').textContent().catch(() => '?');
			throw new Error(`${e.message}\nstatus line: ${line}\ntrail: ${JSON.stringify(trail, null, 1)}`);
		});
		expect(response.status(), JSON.stringify(seen)).toBe(200);
		if (REST_OFF) {
			// Either the REST attempt came first and was refused, or the page's status polling had already switched
			// this tab to the fallback (thankyou.js remembers it), and the id went there directly.
			expect(seen.length, JSON.stringify(seen)).toBeGreaterThanOrEqual(1);
			for (const s of seen.filter((x) => /order-txn/.test(decodeURIComponent(x.url)))) {
				expect([401, 403], JSON.stringify(seen)).toContain(s.status);
			}
		}
		const sent = JSON.parse(response.request().postData() || '{}');
		expect(sent.txn, 'only the canonical id leaves the page').toBe(message.txn);
		await expect(input).toHaveValue(message.txn);

		const cfg = await boxConfig(page);
		await expect(page.locator('[data-qersh-status]')).toHaveText(cfg.lines.saved);
		expect(orderState(order.id).meta._qersh_claim_txn).toBe(message.txn);

		const [reply] = deliver(w, ['--no-payer', '--txn', message.txn, '--amount', '140', '--reasons', '', '--auto-ok', 'true', '--risk', 'low']);
		expect(reply.status, JSON.stringify(reply)).toBe(200);
		expect(reply.body.status).toBe('matched');
		await expect(page.locator('[data-qersh-status]')).toHaveClass(/qersh-status--paid/, { timeout: 90_000 });
	});

	test('the box is there for an order of any wallet, and the page is told nothing about the wallet', async ({ page }) => {
		const w = wallet('main');
		await placeOrder(page, { product: state().products['14000'], w, payer: randPayer() });
		await expect(page.locator('#qersh-txn')).toBeVisible();
		await expect(page.locator('[data-qersh-txn-form]')).toBeVisible();
		const cfg = await boxConfig(page);
		expect(cfg.txn_box, JSON.stringify(cfg)).toBe(true);
		expect(cfg.txn_open, JSON.stringify(cfg)).toBe(true);
		expect(cfg.claim, JSON.stringify(cfg)).toBe(false);
		expect(Object.keys(cfg.txn || {}).sort(), 'the reader gets the payer and the store numbers only').toEqual(['payer_key', 'store_numbers']);
		expect(cfg, 'no wallet type in the page').not.toHaveProperty('orange');
	});

	// docs/17 §14.1: a preview of what will be sent («سنرسل: …») as soon as a message is pasted, before anything is
	// sent. thankyou.js preview() draws it ("We will send: <id>", the id in its own left-to-right element); the unit
	// side is in tests/js/txn-normalize.test.mjs.
	test('a pasted message shows the id that will be sent before sending', async ({ page }) => {
		const payer = randPayer();
		await placeOrder(page, { product: state().products['14000'], w: wallet('second'), payer });
		const message = pastedMessage(payer);
		await expect(page.locator('#qersh-txn')).toBeVisible();
		const sent = [];
		page.on('request', (r) => {
			if (r.method() === 'POST' && /order-txn|[?&]qo=txn(&|$)/.test(decodeURIComponent(r.url()))) sent.push(r.url());
		});
		await paste(page.locator('#qersh_txn'), message.raw);
		await expect(page.locator('[data-qersh-txn-msg] bdi')).toHaveText(message.txn);
		expect(sent, 'nothing is sent before the customer asks').toEqual([]);
	});
});
