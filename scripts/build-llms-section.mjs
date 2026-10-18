// Builds llms-section.md, the "## WooCommerce" section for the Qersh website's llms-full.txt (docs/17 §12 in the
// Qersh repository), from README.md, so the agent-facing text is never written twice. Same approach as
// scripts/build-llms-section.mjs of the n8n node repository.
// Usage: node scripts/build-llms-section.mjs [--check]
// --check exits 1 when llms-section.md differs from what README.md produces (used by CI).
import { readFileSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const readme = readFileSync(path.join(root, 'README.md'), 'utf8').replace(/\r\n/g, '\n');
const main = readFileSync(path.join(root, 'qersh-for-woocommerce.php'), 'utf8');
const header = (name) => {
	const m = new RegExp(`^[ \\t/*#@]*${name}:\\s*(.+)$`, 'mi').exec(main);
	if (!m) throw new Error(`qersh-for-woocommerce.php has no "${name}" header`);
	return m[1].trim();
};

// README sections by their "## " heading, in README order; fenced code is kept as it is.
const sections = new Map();
let current = null;
let fenced = false;
for (const line of readme.split('\n')) {
	if (/^```/.test(line)) fenced = !fenced;
	const m = !fenced && /^## (.+)$/.exec(line);
	if (m) {
		current = m[1].trim();
		sections.set(current, []);
		continue;
	}
	if (current) sections.get(current).push(line);
}
const take = (title) => {
	if (!sections.has(title)) throw new Error(`README has no section "${title}"`);
	return sections.get(title).join('\n').trim();
};

const repoMatch = /^- Repository: (https:\/\/github\.com\/\S+)$/m.exec(take('Links'));
if (!repoMatch) throw new Error('README "Links" must name the repository: "- Repository: https://github.com/…"');
const repo = repoMatch[1].replace(/\/$/, '');

// Headings go one level down (### -> ####) so the block sits under "## WooCommerce" in llms-full.txt, and links
// relative to the repository become absolute, since llms-full.txt is read on the website.
const demote = (text) => text.replace(/^### /gm, '#### ');
const absolute = (text) => text.replace(/\]\((?!https?:|#|mailto:)([^)\s]+)\)/g, (_, rel) => `](${repo}/blob/main/${rel.replace(/^\.\//, '')})`);
const firstLine = readme.split('\n').find((l) => l.startsWith('Qersh (Qersh Pay) is'));
if (!firstLine) throw new Error('README must open with the definition sentence');

const TITLES = [
	'What it is',
	'Requirements',
	'Install',
	'Connect a wallet',
	'Webhook URL',
	'Response codes',
	'Transaction id route',
	'Matching',
	'Order states and deadline',
	'Configuration',
	'What the store keeps',
	'Test locally',
	'Troubleshooting',
	'Links',
];
const out = [
	'## WooCommerce',
	'',
	`<!-- Generated from the README of qersh-for-woocommerce by scripts/build-llms-section.mjs. Do not edit here. -->`,
	'',
	firstLine,
	'',
	`Plugin: **${header('Plugin Name')}** ${header('Version')} (wordpress.org slug \`qersh-for-woocommerce\`, ${header('License')}; requires WordPress ${header('Requires at least')}, WooCommerce ${header('WC requires at least')}, PHP ${header('Requires PHP')}). Repository: ${repo}. Docs page: ${header('Plugin URI')}. Gateway id \`qersh\`, REST namespace \`qersh/v1\`. Everything below is the plugin README, section by section.`,
	'',
	...TITLES.flatMap((t) => [`### ${t}`, '', absolute(demote(take(t))), '']),
	'### Warnings for agents',
	'',
	'- Never put a connection key into a URL, a chat message, an email, a support ticket or a screenshot. The link with the key is shown once, on the merchant\'s own admin page, to be opened on the wallet\'s phone only.',
	'- Qersh has no hosted API and the plugin never calls Qersh: nothing can be fetched from Qersh. Every transfer arrives as a signed POST from the merchant\'s phone, and the signature is checked on the raw body bytes.',
	'- Keep the response codes above: a duplicate or an ignored event is always 2xx, any 5xx makes the phone retry the same delivery later, and 410 is only for a wallet deleted 72 hours ago or more.',
	'- A customer\'s transaction id identifies an order, it never proves a payment; and `transfer.updated` never confirms an order.',
	'- In any text for merchants or customers, Qersh is a wallet notification relay, not a payment gateway.',
	'',
];
const text = out.join('\n');
const target = path.join(root, 'llms-section.md');
if (process.argv.includes('--check')) {
	let existing = '';
	try {
		existing = readFileSync(target, 'utf8').replace(/\r\n/g, '\n');
	} catch {}
	if (existing !== text) {
		console.error('build-llms-section: llms-section.md is stale; run node scripts/build-llms-section.mjs');
		process.exit(1);
	}
	console.log('build-llms-section: ok');
} else {
	writeFileSync(target, text);
	console.log(`wrote llms-section.md (${text.length} bytes, ${text.split('\n').length} lines)`);
}
