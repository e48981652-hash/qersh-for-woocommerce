// The nightly PHP-FPM check (docs/17 §14.1): with every email taking 5 seconds (mu/qersh-slow-mail.php), a delivery
// that pays an order is answered within 3 seconds on the REST address and on the ?wc-api=qersh fallback, the order
// is completed after the answer (fastcgi_finish_request() then the shutdown path), and the emails still go out.
// Run after start.sh and setup.sh: node tests/e2e/fpm/check.mjs
import { execFileSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..', '..');
const BASE = 'http://localhost:8080';
const state = JSON.parse(readFileSync(path.join(ROOT, 'tests/e2e/.auth/fpm.json'), 'utf8'));
const w = state.wallets.find((x) => x.provider === 'vodafone_cash');
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const wp = (...args) => execFileSync('docker', ['compose', '-f', 'tests/e2e/fpm/compose.yml', '--profile', 'cli', 'run', '--rm', '-T', 'cli', ...args], { cwd: ROOT, encoding: 'utf8', timeout: 120_000 });
const json = (out) => JSON.parse(out.split('\n').find((l) => l.startsWith('QERSH_JSON=')).slice('QERSH_JSON='.length));
const randPayer = () => '011' + String(Number('0x' + randomBytes(4).toString('hex')) % 100000000).padStart(8, '0');

async function checkout(productId, payer) {
	const start = await fetch(`${BASE}/wp-json/wc/store/v1/cart`);
	const h = { 'content-type': 'application/json', 'cart-token': start.headers.get('cart-token'), nonce: start.headers.get('nonce') };
	const add = await fetch(`${BASE}/wp-json/wc/store/v1/cart/add-item`, { method: 'POST', headers: h, body: JSON.stringify({ id: productId, quantity: 1 }) });
	if (!add.ok) throw new Error('add-item ' + add.status + ' ' + (await add.text()).slice(0, 300));
	const address = { first_name: 'Test', last_name: 'Customer', address_1: '1 Test St', city: 'Cairo', state: 'EGC', postcode: '11511', country: 'EG', email: 'customer@example.test', phone: payer };
	const res = await fetch(`${BASE}/wp-json/wc/store/v1/checkout`, {
		method: 'POST',
		headers: h,
		body: JSON.stringify({ billing_address: address, payment_method: 'qersh', payment_data: [{ key: 'qersh_payer', value: payer }, { key: 'qersh_wallet', value: w.id }] }),
	});
	const body = await res.json();
	if (!res.ok) throw new Error('checkout ' + res.status + ' ' + JSON.stringify(body).slice(0, 400));
	return body.order_id;
}

const problems = [];
for (const form of ['rest', 'fallback']) {
	const payer = randPayer();
	const id = await checkout(state.products['14000'], payer);
	await sleep(2000); // the money of the delivery must be newer than the order (skew 0)
	const url = form === 'rest' ? w.url : w.fallback;
	const out = execFileSync('node', [path.join(ROOT, 'tests/e2e/deliver.mjs'), url, '--secret', w.key, '--wallet', w.id, '--provider', w.provider, '--payer', payer, '--amount', '140'], { encoding: 'utf8' });
	const reply = JSON.parse(out.trim().split('\n')[0]);
	console.log(`${form}: order ${id}, HTTP ${reply.status} ${reply.body && reply.body.status} in ${reply.ms} ms`);
	if (reply.status !== 200 || !reply.body || reply.body.status !== 'matched') problems.push(`${form}: not matched: ${JSON.stringify(reply).slice(0, 300)}`);
	if (!(reply.ms <= 3000)) problems.push(`${form}: the answer took ${reply.ms} ms (at most 3000 with a 5-second mail)`);

	let status = '';
	for (let i = 0; i < 30 && !['processing', 'completed'].includes(status); i++) {
		await sleep(2000);
		status = json(wp('eval-file', 'wp-content/plugins/qersh-for-woocommerce/tests/e2e/setup/order-state.php', `order=${id}`)).status;
	}
	console.log(`${form}: order ${id} is ${status}`);
	if (!['processing', 'completed'].includes(status)) problems.push(`${form}: order ${id} is ${status}, not completed after the answer`);
}

await sleep(15000); // two orders, two emails each, five seconds per email
const mail = wp('eval', 'echo @file_get_contents( wp_upload_dir( null, false )["basedir"] . "/qersh-mail.log" );');
const sent = mail.split('\n').filter((l) => /\t/.test(l));
console.log(`mail log:\n${sent.join('\n')}`);
if (sent.length < 4) problems.push(`only ${sent.length} email(s) were sent; expected the customer's and the store's for each order`);

if (problems.length) {
	console.error(`\n${problems.length} problem(s):\n- ${problems.join('\n- ')}`);
	process.exit(1);
}
console.log('\nPHP-FPM: answers within 3 s, completion after the answer, mail sent.');
