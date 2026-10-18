// After a failed Playwright run in CI: the page snapshot of each failed test (Playwright's error-context.md, first
// attempt only) and the end of WordPress's debug.log, as error annotations. On a public repository anyone can read
// annotations without signing in; the job logs and the artifacts need an account.
//   node tests/e2e/print-failures.mjs [debug.log]
import { existsSync, readdirSync, readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const RESULTS = path.join(HERE, 'test-results');
const LINES = 60;
const MAX = 6;

const escape = (s) => s.replace(/%/g, '%25').replace(/\r/g, '%0D').replace(/\n/g, '%0A');
const prop = (s) => escape(s).replace(/:/g, '%3A').replace(/,/g, '%2C');
const annotate = (title, text) => console.log(`::error title=${prop(title)}::${escape(text)}`);

let shown = 0;
if (existsSync(RESULTS)) {
	for (const dir of readdirSync(RESULTS).sort()) {
		if (dir.endsWith('-retry1') || shown >= MAX) continue;
		const file = path.join(RESULTS, dir, 'error-context.md');
		if (!existsSync(file)) continue;
		const text = readFileSync(file, 'utf8').split('\n').slice(0, LINES).map((l) => l.slice(0, 300)).join('\n');
		annotate(`page of ${dir}`, text);
		shown++;
	}
}
console.log(`print-failures: ${shown} page snapshot(s)`);

const log = process.argv[2];
if (log && existsSync(log)) {
	const lines = readFileSync(log, 'utf8').split('\n').filter((l) => l.trim() !== '' && !/^(ℹ|✔)/.test(l));
	annotate(`debug.log, last ${Math.min(lines.length, 50)} of ${lines.length} line(s)`, lines.slice(-50).map((l) => l.slice(0, 300)).join('\n') || '(empty)');
}
