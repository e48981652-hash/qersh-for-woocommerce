// Legal wording guard, the same FORBIDDEN and DENIAL rules as scripts/check-wording.mjs in the Qersh repository and
// in the n8n node repository: Qersh is a "wallet notification relay", never a payment gateway, PSP or collection
// service, in anything a user reads here. A line that denies the role ("not a payment gateway") is the legal
// positioning itself and passes. The domain rule of the Qersh repository is left out on purpose: qersh.cfd must
// appear in readme.txt and the plugin header.
import { readdirSync, readFileSync, statSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const ROOTS = ['qersh-for-woocommerce.php', 'uninstall.php', 'readme.txt', 'README.md', 'CHANGELOG.md', 'llms-section.md', 'languages', 'src', 'templates', 'assets/js'];
const TEXT = /\.(php|po|pot|txt|md|js|json)$/i;
const SKIP = new Set(['node_modules', 'vendor', '.git', 'vendor-js']);

const FORBIDDEN = [
	/بوابة\s+دفع/u, /بوابة\s+تحصيل/u, /مقدّ?م\s+خدمات?\s+دفع\b(?!.*(ليس|لا |not))/u, /قبول\s+إلكتروني/u, /خدمة\s+تحصيل/u,
	/payment\s+gateway/i, /\bPSP\b/, /payment\s+service\s+provider(?!.*\bnot\b)/i, /collection\s+service/i, /e-?payment\s+acceptance/i,
];
const DENIAL = /(ليس|ليست|لا\s|not\s+a|never\s+a|no\s+)/u;
// WooCommerce's own API names contain the forbidden words; they are code, not wording (WC_Payment_Gateway, …).
const CODE_NAMES = /WC_Payment_Gateway|woocommerce_payment_gateways|payment_gateways|PaymentGateway|available_payment_gateways/g;

function* walk(p) {
	const abs = path.join(root, p);
	let st;
	try {
		st = statSync(abs);
	} catch {
		return;
	}
	if (st.isFile()) {
		yield p;
		return;
	}
	for (const name of readdirSync(abs)) {
		if (SKIP.has(name)) continue;
		yield* walk(path.posix.join(p, name));
	}
}

const hits = [];
let poFiles = 0;
for (const r of ROOTS) {
	for (const rel of walk(r)) {
		if (!TEXT.test(rel)) continue;
		if (/\.po$/i.test(rel)) poFiles++;
		readFileSync(path.join(root, rel), 'utf8').split('\n').forEach((raw, i) => {
			const line = raw.replace(CODE_NAMES, '');
			for (const re of FORBIDDEN) {
				if (re.test(line) && !DENIAL.test(line)) hits.push(`${rel}:${i + 1}: ${raw.trim().slice(0, 120)}`);
			}
		});
	}
}
if (process.argv.includes('--require-po') && poFiles === 0) {
	hits.push('languages/: no .po file found, so the Arabic texts were not checked');
}
if (hits.length) {
	console.error(`check-wording: ${hits.length} problem(s)\n` + hits.join('\n'));
	process.exit(1);
}
console.log('check-wording: ok');
