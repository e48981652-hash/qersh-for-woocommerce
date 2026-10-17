// Checks the companion schema qersh-woocommerce.config.schema.json (docs/17 §4.2 in the Qersh repository):
//  1. every $ref resolves: the core parts by JSON pointer into fixtures/qersh.config.schema.json
//     (urn:qersh:receiver-config:1), the rest inside the schema itself;
//  2. its defaults, providers and events equal Core\Config (through php scripts/config-verdicts.php);
//  3. the QERSH_WC_CONFIG example of README.md and the plugin's defaults are valid;
//  4. on a list of cases the schema and Config::validate() agree (valid or not), and the cases where the schema is
//     deliberately stricter (unknown fields, unnormalised numbers) are refused by the schema.
// The validator below implements the JSON Schema 2020-12 keywords the schema uses and throws on any other keyword,
// so it cannot pass a schema it does not understand. No dependency.
// Usage: node scripts/check-config-schema.mjs [--php <binary>]
import { execFileSync } from 'node:child_process';
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const readJson = (rel) => JSON.parse(readFileSync(path.join(root, rel), 'utf8'));
const schema = readJson('qersh-woocommerce.config.schema.json');
const core = readJson('fixtures/qersh.config.schema.json');
const registry = new Map([
	[schema.$id, schema],
	[core.$id, core],
]);
const problems = [];

// --- a small JSON Schema 2020-12 evaluator ----------------------------------------------------------------------
const ANNOTATIONS = new Set(['$schema', '$id', 'title', 'description', 'default', '$defs', 'examples', '$comment']);
const KNOWN = new Set([...ANNOTATIONS, '$ref', 'type', 'enum', 'const', 'pattern', 'minLength', 'maxLength', 'minimum', 'maximum',
	'minItems', 'maxItems', 'items', 'contains', 'uniqueItems', 'required', 'properties', 'additionalProperties', 'if', 'then', 'else',
	'allOf', 'anyOf', 'oneOf', 'not']);

function resolve(ref, base) {
	const [uri, fragment = ''] = ref.split('#');
	const doc = uri ? registry.get(uri) : base;
	if (!doc) throw new Error(`$ref ${ref}: no schema with $id ${uri}`);
	let node = doc;
	for (const raw of fragment.split('/').slice(1)) {
		const key = decodeURIComponent(raw).replace(/~1/g, '/').replace(/~0/g, '~');
		if (node === null || typeof node !== 'object' || !(key in node)) throw new Error(`$ref ${ref}: pointer does not resolve at "${key}"`);
		node = node[key];
	}
	return { node, doc };
}
const typeOf = (v) => (v === null ? 'null' : Array.isArray(v) ? 'array' : Number.isInteger(v) ? 'integer' : typeof v);
const typeMatches = (want, v) => want === typeOf(v) || (want === 'number' && typeof v === 'number');
const equal = (a, b) => JSON.stringify(a) === JSON.stringify(b);

function validate(s, v, at, doc, errors) {
	if (s === true) return;
	if (s === false) {
		errors.push(`${at}: not allowed`);
		return;
	}
	for (const k of Object.keys(s)) if (!KNOWN.has(k)) throw new Error(`schema keyword "${k}" at ${at} is not supported by this checker`);
	if (s.$ref) {
		const { node, doc: d } = resolve(s.$ref, doc);
		validate(node, v, at, d, errors);
	}
	if (s.type) {
		const types = [].concat(s.type);
		if (!types.some((t) => typeMatches(t, v))) {
			errors.push(`${at}: must be ${types.join(' or ')}`);
			return;
		}
	}
	if (s.enum && !s.enum.some((e) => equal(e, v))) errors.push(`${at}: must be one of ${s.enum.join(', ')}`);
	if ('const' in s && !equal(s.const, v)) errors.push(`${at}: must be ${JSON.stringify(s.const)}`);
	if (typeof v === 'string') {
		if (s.pattern && !new RegExp(s.pattern, 'u').test(v)) errors.push(`${at}: must match ${s.pattern}`);
		if (s.minLength !== undefined && [...v].length < s.minLength) errors.push(`${at}: too short`);
		if (s.maxLength !== undefined && [...v].length > s.maxLength) errors.push(`${at}: too long`);
	}
	if (typeof v === 'number') {
		if (s.minimum !== undefined && v < s.minimum) errors.push(`${at}: below ${s.minimum}`);
		if (s.maximum !== undefined && v > s.maximum) errors.push(`${at}: above ${s.maximum}`);
	}
	if (Array.isArray(v)) {
		if (s.minItems !== undefined && v.length < s.minItems) errors.push(`${at}: fewer than ${s.minItems} items`);
		if (s.maxItems !== undefined && v.length > s.maxItems) errors.push(`${at}: more than ${s.maxItems} items`);
		if (s.uniqueItems && new Set(v.map((x) => JSON.stringify(x))).size !== v.length) errors.push(`${at}: items must be unique`);
		if (s.items) v.forEach((x, i) => validate(s.items, x, `${at}[${i}]`, doc, errors));
		if (s.contains && !v.some((x) => {
			const e = [];
			validate(s.contains, x, at, doc, e);
			return e.length === 0;
		})) errors.push(`${at}: must contain ${JSON.stringify(s.contains.const ?? s.contains)}`);
	}
	if (v && typeof v === 'object' && !Array.isArray(v)) {
		for (const k of s.required || []) if (!(k in v)) errors.push(`${at}.${k}: required`);
		for (const [k, sub] of Object.entries(s.properties || {})) if (k in v) validate(sub, v[k], `${at}.${k}`, doc, errors);
		if (s.additionalProperties !== undefined) {
			for (const k of Object.keys(v)) {
				if (s.properties && k in s.properties) continue;
				validate(s.additionalProperties, v[k], `${at}.${k}`, doc, errors);
			}
		}
	}
	if (s.if) {
		const e = [];
		validate(s.if, v, at, doc, e);
		if (e.length === 0 && s.then) validate(s.then, v, at, doc, errors);
		if (e.length !== 0 && s.else) validate(s.else, v, at, doc, errors);
	}
	for (const sub of s.allOf || []) validate(sub, v, at, doc, errors);
	if (s.anyOf && !s.anyOf.some((sub) => {
		const e = [];
		validate(sub, v, at, doc, e);
		return e.length === 0;
	})) errors.push(`${at}: matches none of anyOf`);
	if (s.oneOf) {
		const n = s.oneOf.filter((sub) => {
			const e = [];
			validate(sub, v, at, doc, e);
			return e.length === 0;
		}).length;
		if (n !== 1) errors.push(`${at}: matches ${n} of oneOf, not exactly one`);
	}
	if (s.not) {
		const e = [];
		validate(s.not, v, at, doc, e);
		if (e.length === 0) errors.push(`${at}: must not match`);
	}
}
const errorsOf = (value) => {
	const errors = [];
	validate(schema, value, '$', schema, errors);
	return errors;
};

// 1. Every $ref resolves, and the core parts are the ones docs/17 §4.2 names.
const refs = [];
(function collect(node) {
	if (Array.isArray(node)) node.forEach(collect);
	else if (node && typeof node === 'object') {
		if (typeof node.$ref === 'string') refs.push(node.$ref);
		Object.values(node).forEach(collect);
	}
})(schema);
for (const ref of refs) {
	try {
		resolve(ref, schema);
	} catch (e) {
		problems.push(e.message);
	}
}
for (const want of ['urn:qersh:receiver-config:1#/properties/tolerance_sec', 'urn:qersh:receiver-config:1#/properties/matching', 'urn:qersh:receiver-config:1#/properties/dedup']) {
	if (!refs.includes(want)) problems.push(`the schema must take ${want} from the core schema`);
}

// 2-4. The plugin's own view.
const cases = [];
const add = (name, value, expect) => cases.push({ name, value, expect });
const wallet = (over = {}) => ({ id: 'k7q2m9x4p1rz', label: 'Shop', provider: 'vodafone_cash', receive_address: '01001234567', enabled: true, ...over });
const readme = readFileSync(path.join(root, 'README.md'), 'utf8').replace(/\r\n/g, '\n');
const configSection = readme.split(/^## /m).find((s) => s.startsWith('Configuration'));
const example = configSection && /```json\n([\s\S]*?)\n```/.exec(configSection);
if (!example) problems.push('README.md: no ```json example under "## Configuration"');
else add('README example', JSON.parse(example[1]), 'valid');
add('empty object (all defaults)', {}, 'valid');
add('instapay wallet', { wallets: [wallet({ provider: 'instapay_app', receive_address: 'shop.eg@instapay' })] }, 'valid');
add('two wallets', { wallets: [wallet(), wallet({ id: 'a1b2c3d4e5f6', provider: 'orange_cash', receive_address: '01201234567' })] }, 'valid');
add('limits', { store: { min_total_minor: 1000, max_total_minor: 500000, order_ttl_min: 15 } }, 'valid');
add('events without transfer.received', { receiver: { events: ['transfer.updated', 'webhook.test'] } }, 'invalid');
add('event the plugin does not handle', { receiver: { events: ['transfer.received', 'transfer.sent'] } }, 'invalid');
add('empty events', { receiver: { events: [] } }, 'invalid');
add('another endpoint path', { receiver: { endpoint: { path: 'qersh' } } }, 'invalid');
add('tolerance above the core maximum', { receiver: { tolerance_sec: 4000 } }, 'invalid');
add('tolerance not an integer', { receiver: { tolerance_sec: 1.5 } }, 'invalid');
add('negative skew', { receiver: { matching: { late_window_min: 120, skew_min: -1 } } }, 'invalid');
add('dedup of 0 days', { receiver: { dedup: { keep_days: 0 } } }, 'invalid');
add('wallet id with capitals', { wallets: [wallet({ id: 'K7Q2M9X4P1RZ' })] }, 'invalid');
add('wallet id too short', { wallets: [wallet({ id: 'abc' })] }, 'invalid');
add('unknown provider', { wallets: [wallet({ provider: 'we_pay' })] }, 'invalid');
add('instapay wallet with a number', { wallets: [wallet({ provider: 'instapay_app', receive_address: '01001234567' })] }, 'invalid');
add('number wallet with an address', { wallets: [wallet({ receive_address: 'shop@instapay' })] }, 'invalid');
add('enabled not a boolean', { wallets: [wallet({ enabled: 'yes' })] }, 'invalid');
add('wallets not an array', { wallets: null }, 'invalid');
add('deadline too short', { store: { order_ttl_min: 10 } }, 'invalid');
add('deadline too long', { store: { order_ttl_min: 2000 } }, 'invalid');
add('negative minimum', { store: { min_total_minor: -1 } }, 'invalid');
add('retention of 0 days', { store: { unmatched_retention_days: 0 } }, 'invalid');
add('a boolean as text', { store: { txn_entry: 'true' } }, 'invalid');
// Deliberately stricter than the plugin: refused by the schema, accepted (and cleaned) by Config.
const stricter = [];
const strict = (name, value) => stricter.push({ name, value });
strict('keys in the receiver', { receiver: { keys: ['qsk_aaaaaaaaaaaaaaaaaaaaaaaa'] } });
strict('an unknown store field', { store: { order_ttl: 60 } });
strict('a number in +20 form', { wallets: [wallet({ receive_address: '+20 100 123 4567' })] });

let php = 'php';
const phpIndex = process.argv.indexOf('--php');
if (phpIndex > 0 && process.argv[phpIndex + 1]) php = process.argv[phpIndex + 1];
const dir = mkdtempSync(path.join(tmpdir(), 'qersh-schema-'));
let view;
try {
	const file = path.join(dir, 'cases.json');
	writeFileSync(file, JSON.stringify([...cases, ...stricter].map((c) => c.value)));
	view = JSON.parse(execFileSync(php, [path.join(root, 'scripts', 'config-verdicts.php'), file], { encoding: 'utf8' }));
} finally {
	rmSync(dir, { recursive: true, force: true });
}

// 2. Defaults, providers and events.
const d = view.defaults;
const p = schema.properties;
const check = (label, a, b) => {
	if (!equal(a, b)) problems.push(`${label}: schema ${JSON.stringify(a)} != Config ${JSON.stringify(b)}`);
};
check('receiver.events default', p.receiver.properties.events.default, d.receiver.events);
check('receiver.events enum', p.receiver.properties.events.items.enum, view.events);
check('receiver.matching default', p.receiver.properties.matching.default, d.receiver.matching);
check('receiver.endpoint.path', p.receiver.properties.endpoint.properties.path.const, d.receiver.endpoint.path);
check('receiver.tolerance_sec default (core)', core.properties.tolerance_sec.default, d.receiver.tolerance_sec);
check('receiver.dedup.keep_days default (core)', core.properties.dedup.properties.keep_days.default, d.receiver.dedup.keep_days);
check('wallets[].provider enum', schema.$defs.wallet.properties.provider.enum, view.providers);
check('store fields', Object.keys(p.store.properties), Object.keys(d.store));
for (const [k, sub] of Object.entries(p.store.properties)) check(`store.${k} default`, sub.default, d.store[k]);
const defaultsErrors = errorsOf(d);
if (defaultsErrors.length) problems.push(`Config::DEFAULTS is not valid against the schema: ${defaultsErrors.join('; ')}`);

// 3-4. Agreement.
cases.forEach((c, i) => {
	const schemaOk = errorsOf(c.value).length === 0;
	const phpOk = view.verdicts[i].length === 0;
	const want = c.expect === 'valid';
	if (schemaOk !== want) problems.push(`case "${c.name}": the schema says ${schemaOk ? 'valid' : 'invalid'} (${errorsOf(c.value).join('; ')}), expected ${c.expect}`);
	if (phpOk !== want) problems.push(`case "${c.name}": Config::validate says ${phpOk ? 'valid' : 'invalid'} (${view.verdicts[i].map((x) => x.path + ' ' + x.message).join('; ')}), expected ${c.expect}`);
});
stricter.forEach((c, j) => {
	const verdict = view.verdicts[cases.length + j];
	if (errorsOf(c.value).length === 0) problems.push(`stricter case "${c.name}": the schema accepts it`);
	if (verdict.length !== 0) problems.push(`stricter case "${c.name}": Config refuses it too (${verdict.map((x) => x.path).join(', ')}); move it to the shared cases`);
});

if (problems.length) {
	console.error(`check-config-schema: ${problems.length} problem(s)\n` + problems.join('\n'));
	process.exit(1);
}
console.log(`check-config-schema: ok (${refs.length} $ref, ${cases.length} shared cases agree with Config::validate, ${stricter.length} stricter cases)`);
