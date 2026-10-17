// Copies the reference files this plugin must match, byte for byte, from the Qersh repository, and writes
// fixtures.lock.json with their SHA-256 hashes. scripts/check-fixtures.mjs refuses a drifted copy in CI.
//   node scripts/import-from-qersh.mjs "D:/qersh pay"
// Never edit the copies here: change the source in the Qersh repository and run this again.
import { createHash } from 'node:crypto';
import { execFileSync } from 'node:child_process';
import { copyFileSync, existsSync, mkdirSync, readdirSync, readFileSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const qersh = process.argv[2];
if (!qersh || !existsSync(path.join(qersh, 'kit', 'test-vectors.json'))) {
	console.error('usage: node scripts/import-from-qersh.mjs <path to the Qersh repository>');
	process.exit(1);
}

const coreDist = path.join('packages', 'qersh-webhook', 'dist', 'src', 'core');
const pairs = [
	['kit/test-vectors.json', 'fixtures/test-vectors.json'],
	['kit/webhook.v1.schema.json', 'fixtures/webhook.v1.schema.json'],
	['kit/sink/send.mjs', 'fixtures/sink/send.mjs'],
	['kit/snippets/php/verify.php', 'tests/php/fixtures/verify.php'],
	['kit/reference/match.mjs', 'tests/parity/kit/match.mjs'],
	['packages/qersh-webhook/qersh.config.schema.json', 'fixtures/qersh.config.schema.json'],
	...readdirSync(path.join(qersh, coreDist))
		.filter((f) => f.endsWith('.js'))
		.map((f) => [path.posix.join(coreDist.split(path.sep).join('/'), f), `tests/parity/core/${f}`]),
];

let commit = 'unknown';
try {
	commit = execFileSync('git', ['-C', qersh, 'rev-parse', 'HEAD'], { encoding: 'utf8' }).trim();
} catch {
	// not a git checkout: the hashes still pin the content
}

const files = [];
for (const [from, to] of pairs) {
	const src = path.join(qersh, from);
	const dest = path.join(root, to);
	mkdirSync(path.dirname(dest), { recursive: true });
	copyFileSync(src, dest);
	files.push({ path: to, source: from, sha256: createHash('sha256').update(readFileSync(dest)).digest('hex') });
}
// The compiled core is ESM: mark its folder as a module so Node loads it without the package.json of the source.
writeFileSync(path.join(root, 'tests/parity/core/package.json'), '{ "type": "module" }\n');
writeFileSync(path.join(root, 'tests/parity/kit/package.json'), '{ "type": "module" }\n');

writeFileSync(
	path.join(root, 'fixtures.lock.json'),
	JSON.stringify({ source: 'Qersh repository', commit, files }, null, '\t') + '\n',
);
console.log(`import-from-qersh: copied ${files.length} files from ${commit.slice(0, 7)} and wrote fixtures.lock.json`);
