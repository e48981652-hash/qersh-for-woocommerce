// Checks what this repository copies or mirrors from elsewhere:
//  1. every file in fixtures.lock.json still has its recorded SHA-256 (the vendored kit and core copies);
//  2. src/Core/TxnId.php carries exactly the patterns of fixtures/txn-patterns.json;
//  3. src/Core/Risk.php knows exactly the risk reasons of fixtures/webhook.v1.schema.json ($defs.riskReason);
//  4. assets/vendor/qrcode-generator/qrcode.js has the hash recorded in assets/vendor/qrcode-generator/LOCK.json.
// Parts whose source file does not exist yet are reported as skipped, not passed.
import { createHash } from 'node:crypto';
import { existsSync, readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const read = (rel) => readFileSync(path.join(root, rel));
const problems = [];
const skipped = [];

// 1. vendored copies
const lock = JSON.parse(read('fixtures.lock.json').toString('utf8'));
for (const f of lock.files) {
	if (!existsSync(path.join(root, f.path))) {
		problems.push(`${f.path}: missing (vendored from ${f.source})`);
		continue;
	}
	const sha = createHash('sha256').update(read(f.path)).digest('hex');
	if (sha !== f.sha256) problems.push(`${f.path}: changed since it was copied from ${f.source}; re-run scripts/import-from-qersh.mjs`);
}

// 2. transaction-id patterns
const patterns = JSON.parse(read('fixtures/txn-patterns.json').toString('utf8')).providers;
if (existsSync(path.join(root, 'src/Core/TxnId.php'))) {
	const php = read('src/Core/TxnId.php').toString('utf8');
	for (const [provider, spec] of Object.entries(patterns)) {
		if (!php.includes(`'${provider}' => '${spec.pattern}'`)) problems.push(`src/Core/TxnId.php: PATTERNS['${provider}'] must be '${spec.pattern}'`);
		const lengths = `'${provider}' => array( ${spec.lengths.join(', ')} )`;
		if (!php.includes(lengths)) problems.push(`src/Core/TxnId.php: LENGTHS must contain ${lengths}`);
	}
	const providers = Object.keys(patterns).map((p) => `'${p}'`).join(', ');
	if (!php.includes(`CLAIM_PROVIDERS = array( ${providers} )`)) problems.push(`src/Core/TxnId.php: CLAIM_PROVIDERS must be array( ${providers} )`);
} else {
	skipped.push('src/Core/TxnId.php (not written yet)');
}

// 3. risk reasons
const schema = JSON.parse(read('fixtures/webhook.v1.schema.json').toString('utf8'));
const reasons = (schema.$defs && schema.$defs.riskReason && (schema.$defs.riskReason.enum || [])) || [];
if (!reasons.length) problems.push('fixtures/webhook.v1.schema.json: $defs.riskReason.enum not found');
if (existsSync(path.join(root, 'src/Core/Risk.php'))) {
	const php = read('src/Core/Risk.php').toString('utf8');
	const block = php.match(/KNOWN_REASONS\s*=\s*array\(([\s\S]*?)\);/);
	const listed = block ? [...block[1].matchAll(/'([a-z0-9_]+)'/g)].map((m) => m[1]) : [];
	const missing = reasons.filter((r) => !listed.includes(r));
	const extra = listed.filter((r) => !reasons.includes(r));
	if (missing.length || extra.length) problems.push(`src/Core/Risk.php: KNOWN_REASONS differs from the schema (missing ${missing.join(',') || '-'}; extra ${extra.join(',') || '-'})`);
} else {
	skipped.push('src/Core/Risk.php (not written yet)');
}

// 4. bundled QR library
const qrLock = 'assets/vendor/qrcode-generator/LOCK.json';
if (existsSync(path.join(root, qrLock))) {
	const q = JSON.parse(read(qrLock).toString('utf8'));
	for (const f of q.files) {
		const sha = createHash('sha256').update(read(f.path)).digest('hex');
		if (sha !== f.sha256) problems.push(`${f.path}: differs from ${q.source}`);
	}
} else {
	skipped.push('assets/vendor/qrcode-generator (not bundled yet)');
}

if (skipped.length) console.log('check-fixtures: skipped ' + skipped.join('; '));
if (problems.length) {
	console.error(`check-fixtures: ${problems.length} problem(s)\n` + problems.join('\n'));
	process.exit(1);
}
console.log(`check-fixtures: ok (${lock.files.length} vendored files, ${reasons.length} risk reasons, ${Object.keys(patterns).length} txn pattern(s))`);
