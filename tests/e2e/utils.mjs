// Shared helpers of the Playwright tests: the job's settings, the store and wallet state written by global-setup.mjs,
// WP-CLI (through wp-env, or plain PHP on a local test site), signed deliveries through deliver.mjs, and checkout.
//
// Job settings (environment):
//   QERSH_E2E_BASE      site address (default http://localhost:8888, wp-env)
//   QERSH_E2E_CHECKOUT  blocks | classic
//   QERSH_E2E_LOCALE    the site language set by the workflow (ar for the RTL job)
//   QERSH_E2E_REST      on | off (off: the "Disable REST API" plugin is active, deliveries go to ?wc-api=qersh)
//   QERSH_E2E_FINISH    on in the job whose tests/e2e/mu defines fastcgi_finish_request() (a flush only)
//   QERSH_E2E_RUNNER    wp-env (default) | php (local: php tests/e2e/setup/*.php with QERSH_SITE, see tests/local)
import { execFile, execFileSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { existsSync, readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { promisify } from 'node:util';

const require = createRequire(import.meta.url);
// The CommonJS build: the ES module build of 0.5.1 imports './cart' without an extension, which Node's own ES module
// loader refuses. Its address helpers look fields up by labels that WooCommerce 11.2 no longer uses ("Address",
// "City", "ZIP Code"), so the checkout is filled by the fields' ids below.
const { getOrderIdFromUrl } = require('@woocommerce/e2e-utils-playwright');

export const HERE = path.dirname(fileURLToPath(import.meta.url));
export const ROOT = path.resolve(HERE, '..', '..');
export const STATE_DIR = path.join(HERE, '.auth');
export const STATE_FILE = path.join(STATE_DIR, 'state.json');
export const ADMIN_STATE = path.join(STATE_DIR, 'admin.json');

export const BASE = (process.env.QERSH_E2E_BASE || 'http://localhost:8888').replace(/\/+$/, '');
export const CHECKOUT = process.env.QERSH_E2E_CHECKOUT === 'classic' ? 'classic' : 'blocks';
export const LOCALE = process.env.QERSH_E2E_LOCALE || 'en_US';
export const REST_OFF = process.env.QERSH_E2E_REST === 'off';
export const FINISH = process.env.QERSH_E2E_FINISH === 'on';
/** Timeout multiplier for a slow local server (playwright.config.mjs reads the same variable). */
export const SLOW = Math.max(1, Number(process.env.QERSH_E2E_SLOW) || 1);

/** The plugin's own normaliser (assets/js/txn-normalize.js), the one the order page runs. */
export const N = require('../../assets/js/txn-normalize.js');

/**
 * Runs one of tests/e2e/setup/*.php and returns its QERSH_JSON line, decoded.
 */
export function wp(script, ...args) {
	const clean = args.filter(Boolean).map(String);
	const [cmd, argv, opts] = wpCommand(script, clean);
	return wpJson(script, execFileSync(cmd, argv, { ...opts, encoding: 'utf8', timeout: 180_000 }));
}

/** wp() without blocking: for setup scripts that must run at the same time (tests/e2e/concurrency.mjs). */
export async function wpAsync(script, ...args) {
	const [cmd, argv, opts] = wpCommand(script, args.filter(Boolean).map(String));
	const { stdout } = await promisify(execFile)(cmd, argv, { ...opts, encoding: 'utf8', timeout: 180_000 });
	return wpJson(script, stdout);
}

/**
 * The command that runs a setup script: plain PHP on a local test site, or WP-CLI in wp-env's cli container
 * (QERSH_E2E_WP_ENV_CONFIG names another wp-env configuration, e.g. .wp-env.test.json in the nightly MariaDB job).
 */
function wpCommand(script, clean) {
	if (process.env.QERSH_E2E_RUNNER === 'php') {
		return [process.env.QERSH_PHP || 'php', [path.join(HERE, 'setup', script), ...clean], { env: { ...process.env, QERSH_BASE: BASE } }];
	}
	const config = process.env.QERSH_E2E_WP_ENV_CONFIG ? [`--config=${process.env.QERSH_E2E_WP_ENV_CONFIG}`] : [];
	return ['npx', ['wp-env', 'run', 'cli', ...config, '--env-cwd=wp-content/plugins/qersh-for-woocommerce', 'wp', 'eval-file', `tests/e2e/setup/${script}`, ...clean], { cwd: ROOT }];
}

function wpJson(script, out) {
	const line = out.split(/\r?\n/).find((l) => l.startsWith('QERSH_JSON='));
	if (!line) throw new Error(`${script} printed no QERSH_JSON line:\n${out.slice(-2000)}`);
	return JSON.parse(line.slice('QERSH_JSON='.length));
}

/** The store and wallets of this job (global-setup.mjs). */
export function state() {
	if (!existsSync(STATE_FILE)) throw new Error(`${STATE_FILE} is missing: global-setup.mjs did not run`);
	return JSON.parse(readFileSync(STATE_FILE, 'utf8'));
}

export function wallet(provider) {
	const w = state().wallets.find((x) => x.provider === provider);
	if (!w) throw new Error(`no ${provider} wallet in the e2e state`);
	return w;
}

export const storeNumbers = () => state().wallets.map((w) => w.receive_address);

/** A fresh Egyptian payer number that is no store number. */
export function randPayer() {
	for (;;) {
		const n = '011' + String(Number('0x' + randomBytes(4).toString('hex')) % 100000000).padStart(8, '0');
		if (!storeNumbers().includes(n)) return n;
	}
}

/** A fresh 14-digit Orange Cash transaction id. */
export const randTxn = () => '2500' + String(Number('0x' + randomBytes(5).toString('hex')) % 1e10).padStart(10, '0');

/**
 * Sends a signed delivery with tests/e2e/deliver.mjs (REST, or the fallback address when REST is off, unless `url`
 * names another address of the wallet). Returns the reply lines.
 */
export function deliver(w, args, url = REST_OFF ? w.fallback : w.url) {
	const out = execFileSync('node', [path.join(HERE, 'deliver.mjs'), url, '--secret', w.key, '--wallet', w.id, '--provider', w.provider, ...args.map(String)], { encoding: 'utf8', timeout: 120_000 });
	return out.trim().split('\n').filter(Boolean).map((l) => JSON.parse(l));
}

/** deliver() without blocking, for transfers that must reach the store together. */
export async function deliverAsync(w, args) {
	const url = REST_OFF ? w.fallback : w.url;
	const { stdout } = await promisify(execFile)('node', [path.join(HERE, 'deliver.mjs'), url, '--secret', w.key, '--wallet', w.id, '--provider', w.provider, ...args.map(String)], { encoding: 'utf8', timeout: 120_000 });
	return stdout.trim().split('\n').filter(Boolean).map((l) => JSON.parse(l));
}

/** What the kit's sender (fixtures/sink/send.mjs) always sends: the payer and transfer id of its vector. */
export const KIT = (() => {
	const vectors = JSON.parse(readFileSync(path.join(ROOT, 'fixtures', 'test-vectors.json'), 'utf8'));
	const body = JSON.parse(vectors.cases.find((c) => c.name === 'valid_transfer_received').body);
	return { payer: body.data.counterparty.number, transfer: body.data.transfer_id };
})();

/**
 * Runs the kit's own sender, fixtures/sink/send.mjs (a byte copy from the Qersh repository), against a wallet (REST,
 * or the fallback address when REST is off). Returns its reply lines, [{label, status, ms, body}] (body: the first 200
 * characters it prints), and its whole output.
 */
export function kitSend(w, args) {
	const url = REST_OFF ? w.fallback : w.url;
	const out = execFileSync('node', [path.join(ROOT, 'fixtures', 'sink', 'send.mjs'), url, '--secret', w.key, ...args.map(String)], { encoding: 'utf8', timeout: 120_000 });
	const lines = [];
	for (const line of out.split(/\r?\n/)) {
		const m = /^(.+?)\s+HTTP (\d{3})\s+(\d+) ms\s*(.*)$/.exec(line);
		if (m) lines.push({ label: m[1].trim(), status: Number(m[2]), ms: Number(m[3]), body: m[4] });
	}
	return { lines, out };
}

/** Deletes every order of the kit's payer and its stored transfer (tests/e2e/setup/reset.php): a store reset for one send.mjs scenario. */
export const resetKit = () => wp('reset.php', `payer=${KIT.payer}`, `transfer=${KIT.transfer}`);

/**
 * A waiting order made on the server through the method's process_payment() (tests/e2e/setup/order.php). backdate
 * moves the row's created_at back, shift moves created_at and expires_at back (seconds). Returns {id, key, total,
 * row, urls: {txn, txn_fallback}}.
 */
export const createOrder = ({ product, w, payer, backdate = 0, shift = 0 }) =>
	wp('order.php', `product=${product}`, `payer=${payer}`, `wallet=${w.id}`, backdate ? `backdate=${backdate}` : '', shift ? `shift=${shift}` : '');

/** POSTs a transaction id for an order (REST, or ?wc-api=qersh&qo=txn when REST is off), as the order page does. */
export async function postTxn(order, txn) {
	const started = Date.now();
	const res = await fetch(REST_OFF ? order.urls.txn_fallback : order.urls.txn, {
		method: 'POST',
		headers: { 'content-type': 'application/json', accept: 'application/json' },
		body: JSON.stringify({ order_id: order.id, key: order.key, txn }),
	});
	const body = await res.json().catch(() => null);
	return { http: res.status, body, ms: Date.now() - started };
}

/**
 * The mail log of tests/e2e/mu/qersh-test-mail.php (tests/e2e/setup/mail.php): {active, slow, now, log: [{at, id,
 * order, to, subject, slow, ms}]}. Options: { slow: true|false, clear: true, drain: true, since: <epoch> }.
 */
export function mail({ slow, clear = false, drain = false, since = 0 } = {}) {
	return wp('mail.php', slow === undefined ? '' : `slow=${slow ? 1 : 0}`, clear ? 'clear=1' : '', drain ? 'drain=1' : '', since ? `since=${since}` : '');
}

let PO = null;
/** One string of a .po file, its quoted lines joined. */
function poString(block, name) {
	const m = new RegExp(`^${name} ((?:"(?:[^"\\\\]|\\\\.)*"[ \\t]*\\r?\\n?)+)`, 'm').exec(block);
	if (!m) return null;
	return (m[1].match(/"(?:[^"\\]|\\.)*"/g) || []).map((q) => JSON.parse(q)).join('');
}
/** The plugin's own Arabic for a source string (languages/qersh-for-woocommerce-ar.po), or throws. */
export function arabic(msgid) {
	if (!PO) {
		PO = new Map();
		const text = readFileSync(path.join(ROOT, 'languages', 'qersh-for-woocommerce-ar.po'), 'utf8');
		for (const block of text.split(/\r?\n\s*\r?\n/)) {
			if (/^#,.*fuzzy/m.test(block) || /^msgctxt /m.test(block)) continue;
			const id = poString(block, 'msgid');
			const str = poString(block, 'msgstr');
			if (id && str) PO.set(id, str);
		}
	}
	if (!PO.has(msgid)) throw new Error(`no Arabic for "${msgid}" in languages/qersh-for-woocommerce-ar.po`);
	return PO.get(msgid);
}

/** The order's server-side state (cron = run one Action Scheduler batch first). */
export const orderState = (id, cron = false) => wp('order-state.php', `order=${id}`, cron ? 'cron=1' : '');

/** Order id and key from an order-received address (pretty or plain permalinks). */
export function orderFromUrl(url) {
	const u = new URL(url);
	const m = /order-received\/(\d+)/.exec(u.pathname);
	const id = m ? Number(m[1]) : Number(u.searchParams.get('order-received'));
	return { id, key: u.searchParams.get('key') };
}

/** An Orange Cash message wording with invented values (tests/vectors/txn-normalize.json, one with line breaks) and a fresh id. */
export function orangeMessage(payer) {
	const vectors = JSON.parse(readFileSync(path.join(ROOT, 'tests/vectors/txn-normalize.json'), 'utf8'));
	const txn = randTxn();
	for (const v of vectors) {
		if (v.provider !== 'orange_cash' || !v.expect || !v.expect.ok || String(v.expect.txn).length !== 14 || !/\n/.test(v.raw) || !v.raw.includes(v.expect.txn)) continue;
		const raw = v.raw.split(v.expect.txn).join(txn);
		const res = N.canonicalTyped('orange_cash', raw, payer, storeNumbers());
		if (res.ok && res.txn === txn) return { raw, txn, vector: v.name };
	}
	throw new Error('no usable Orange Cash message in tests/vectors/txn-normalize.json');
}

/** Adds one product to the cart (a classic add-to-cart link, so it works with the REST API turned off too). */
export async function addToCart(page, productId) {
	await page.goto(`/?add-to-cart=${productId}`);
}

async function fillIfVisible(locator, value) {
	if ((await locator.count()) && (await locator.first().isVisible())) {
		const tag = await locator.first().evaluate((el) => el.tagName.toLowerCase());
		if (tag === 'select') await locator.first().selectOption(value.select || value.text);
		else await locator.first().fill(value.text);
	}
}

/** Fills the customer details of the checkout (no payment details). */
export async function fillCustomer(page, { email, phone }) {
	if (CHECKOUT === 'blocks') {
		await page.locator('#email').fill(email);
		// The cart holds virtual products: Blocks asks for the billing address only.
		const edit = page.locator('.wc-block-components-address-card__edit');
		if ((await edit.count()) && (await edit.first().isVisible())) await edit.first().click();
		await page.locator('#billing-first_name').fill('Test');
		await page.locator('#billing-last_name').fill('Customer');
		await fillIfVisible(page.locator('#billing-country'), { select: 'EG', text: 'Egypt' });
		await fillIfVisible(page.locator('#billing-address_1'), { text: '1 Test Street' });
		await fillIfVisible(page.locator('#billing-city'), { text: 'Cairo' });
		await fillIfVisible(page.locator('#billing-state'), { select: 'EGC', text: 'Cairo' });
		await fillIfVisible(page.locator('#billing-postcode'), { text: '11511' });
		await fillIfVisible(page.locator('#billing-phone'), { text: phone });
		return;
	}
	await page.locator('#billing_first_name').fill('Test');
	await page.locator('#billing_last_name').fill('Customer');
	await fillIfVisible(page.locator('#billing_address_1'), { text: '1 Test Street' });
	await fillIfVisible(page.locator('#billing_city'), { text: 'Cairo' });
	const state = page.locator('select#billing_state');
	if (await state.count()) await state.selectOption('EGC', { force: true });
	await fillIfVisible(page.locator('#billing_postcode'), { text: '11511' });
	await page.locator('#billing_phone').fill(phone);
	await page.locator('#billing_email').fill(email);
	await waitForClassicRefresh(page);
}

/** Classic checkout reloads the payment box after each change; wait until its overlay is gone. */
export async function waitForClassicRefresh(page) {
	if (CHECKOUT !== 'classic') return;
	await page.waitForTimeout(300);
	await page.locator('.blockUI.blockOverlay').first().waitFor({ state: 'detached', timeout: 20_000 }).catch(() => {});
}

/** The payer field of the method, in Blocks or classic. */
export const payerField = (page) => page.locator(CHECKOUT === 'blocks' ? '#qersh_payer_blocks' : '#qersh_payer');
export const payerError = (page) => page.locator(CHECKOUT === 'blocks' ? '#qersh_payer_blocks_error' : '#qersh_payer_error');

/** Selects the method and the wallet. */
export async function chooseMethod(page, w) {
	if (CHECKOUT === 'blocks') {
		const radio = page.locator('#radio-control-wc-payment-method-options-qersh');
		if ((await radio.count()) && !(await radio.isChecked())) await radio.check();
		const choice = page.locator(`#qersh_wallet_blocks_${w.id}`);
		if (await choice.count()) await choice.check();
		return;
	}
	const radio = page.locator('#payment_method_qersh');
	if ((await radio.count()) && !(await radio.isChecked())) {
		await radio.check({ force: true });
		await waitForClassicRefresh(page);
	}
	const choice = page.locator(`#qersh_wallet_${w.id}`);
	if ((await choice.count()) && (await choice.getAttribute('type')) === 'radio') await choice.check();
}

export async function placeOrderButton(page) {
	return CHECKOUT === 'blocks' ? page.locator('.wc-block-components-checkout-place-order-button') : page.locator('#place_order');
}

/** The whole checkout: cart, customer, method, wallet, payer; returns the order id and key. */
export async function placeOrder(page, { product, w, payer, email = 'customer@example.test' }) {
	await addToCart(page, product);
	await page.goto(state().pages.checkout);
	await fillCustomer(page, { email, phone: payer });
	await chooseMethod(page, w);
	await payerField(page).fill(payer);
	await payerField(page).blur();
	await (await placeOrderButton(page)).click();
	await page.waitForURL(/order-received/);
	const order = orderFromUrl(page.url());
	const fromUtils = Number(getOrderIdFromUrl(page));
	if (fromUtils && fromUtils !== order.id) throw new Error(`order id ${order.id} vs ${fromUtils} in ${page.url()}`);
	return order;
}

/** The configuration the thank-you box hands its script (data-qersh). */
export async function boxConfig(page) {
	return JSON.parse((await page.locator('[data-qersh-box]').getAttribute('data-qersh')) || '{}');
}
