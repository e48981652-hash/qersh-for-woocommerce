// Supply-chain guard for .github/workflows (docs/17 §11 in the Qersh repository). An action that can read a secret
// runs with that secret (the deploy's SVN_USERNAME and SVN_PASSWORD let it publish a release that auto-updates onto
// every store), so it must be pinned to a full commit SHA: a tag or a branch can be moved to other code, as happened
// with tj-actions/changed-files in March 2025. A step "can read a secret" when its own `with:` or `env:` names one,
// or when the job's or the workflow's `env:` does; a reusable workflow when its job passes `secrets:`. GitHub's own
// actions (actions/*, github/*) and local ones (./…) are exempt, and a third-party action that sees no secret may keep
// its tag. A Docker action (docker://…) needs an @sha256: digest instead.
// No dependency: the workflows are read by indentation, which is all the layout of these files needs. The rules are
// checked against the built-in cases below on every run, so a broken reader fails instead of passing everything.
// Usage: node scripts/check-workflows.mjs   Exit 1 on any problem.
import { readdirSync, readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const WORKFLOWS = path.join(root, '.github', 'workflows');

const SHA = /^[0-9a-f]{40}$/;
const FIRST_PARTY = /^(?:actions|github)\//;
const SECRET = /\$\{\{[^}]*\bsecrets\b|^\s*secrets\s*:/m;
const USES = /^(\s*)(-\s+)?uses\s*:\s*(['"]?)([^\s'"#]+)\3/;

const indentOf = (line) => line.length - line.trimStart().length;
const skip = (line) => !line.trim() || line.trim().startsWith('#');

// The end (exclusive) of the YAML node that starts at line `start` and whose marker (the "-" of a list item, or the
// key) is at column `indent`: the node holds the lines after it that are indented deeper.
function blockEnd(lines, start, indent) {
	let i = start + 1;
	while (i < lines.length && (skip(lines[i]) || indentOf(lines[i]) > indent)) i++;
	return i;
}
// The nearest line above `from` that is indented less than `indent` (the parent node), or -1.
function parentOf(lines, from, indent) {
	for (let j = from - 1; j >= 0; j--) if (!skip(lines[j]) && indentOf(lines[j]) < indent) return j;
	return -1;
}
// The `env:` blocks directly under the node at line `start` (column `indent`), as text; start -1 is the top level.
function envOf(lines, start, indent) {
	const end = start < 0 ? lines.length : blockEnd(lines, start, indent);
	let out = '';
	let childIndent = start < 0 ? 0 : null;
	for (let i = start + 1; i < end; i++) {
		if (skip(lines[i])) continue;
		if (childIndent === null) childIndent = indentOf(lines[i]);
		if (indentOf(lines[i]) !== childIndent || !/^\s*env\s*:/.test(lines[i])) continue;
		out += lines.slice(i, blockEnd(lines, i, childIndent)).join('\n') + '\n';
	}
	return out;
}

/**
 * The actions of one workflow file that can read a secret and are not pinned to a commit.
 *
 * @param {string} text The workflow, as YAML.
 * @returns {{line: number, action: string, ref: string}[]}
 */
export function unpinned(text) {
	const lines = text.replace(/\r\n?/g, '\n').split('\n');
	const workflowEnv = envOf(lines, -1, -1);
	const problems = [];
	lines.forEach((line, i) => {
		const m = USES.exec(line);
		if (!m) return;
		const spec = m[4];
		if (spec.startsWith('./') || spec.startsWith('../')) return;
		const keyIndent = m[1].length + (m[2] ? m[2].length : 0);
		// The node the `uses:` belongs to: a step ("- uses:", or a "- name:" item above it), or a job (a reusable
		// workflow, "jobs.<id>.uses").
		let start = i;
		let indent = m[1].length;
		let isStep = Boolean(m[2]);
		if (!isStep) {
			const p = parentOf(lines, i, keyIndent);
			if (p < 0) return;
			const dash = /^(\s*)-\s+/.exec(lines[p]);
			start = p;
			indent = indentOf(lines[p]);
			isStep = Boolean(dash) && dash[0].length === keyIndent;
		}
		let seen = lines.slice(start, blockEnd(lines, start, indent)).join('\n');
		if (isStep) {
			const steps = parentOf(lines, start, indent);
			const job = steps < 0 ? -1 : parentOf(lines, steps, indentOf(lines[steps]));
			if (job >= 0) seen += '\n' + envOf(lines, job, indentOf(lines[job]));
		}
		seen += '\n' + workflowEnv;
		if (!SECRET.test(seen)) return;
		const at = spec.lastIndexOf('@');
		const action = at < 0 ? spec : spec.slice(0, at);
		const ref = at < 0 ? '' : spec.slice(at + 1);
		if (spec.startsWith('docker://')) {
			if (!/^sha256:[0-9a-f]{64}$/.test(ref)) problems.push({ line: i + 1, action, ref });
			return;
		}
		if (FIRST_PARTY.test(action) || SHA.test(ref)) return;
		problems.push({ line: i + 1, action, ref });
	});
	return problems;
}

// --- the rules, on cases whose verdict is known ---------------------------------------------------------------------
const PIN = '54bd289b8525fd23a5c365ec369185f2966529c2';
const CASES = [
	['a third-party step with a secret in env, by tag', `jobs:
  deploy:
    runs-on: ubuntu-24.04
    steps:
      - uses: actions/checkout@v7
      - name: Deploy
        uses: someone/deploy@2.3.0
        with:
          dry-run: true
        env:
          SVN_PASSWORD: \${{ secrets.SVN_PASSWORD }}
`, [7]],
	['the same step pinned to a commit, with the version as a comment', `jobs:
  deploy:
    steps:
      - name: Deploy
        uses: someone/deploy@${PIN} # 2.3.0
        env:
          SVN_PASSWORD: \${{ secrets.SVN_PASSWORD }}
`, []],
	['a third-party step that sees no secret keeps its tag; the next step has the secret', `jobs:
  e2e:
    steps:
      - uses: nick-fields/retry@v4
        with:
          command: npm test
      - name: Report
        run: echo "\${{ secrets.TOKEN }}"
`, []],
	['a first-party action with a secret', `jobs:
  build:
    steps:
      - uses: actions/checkout@v7
        with:
          token: \${{ secrets.PAT }}
`, []],
	['a secret in the job env reaches every step', `jobs:
  deploy:
    env:
      KEY: \${{ secrets.KEY }}
    steps:
      - run: echo hi
      - uses: someone/tool@main
`, [7]],
	['a secret in another job env does not', `jobs:
  a:
    env:
      KEY: \${{ secrets.KEY }}
    steps:
      - run: echo hi
  b:
    steps:
      - uses: someone/tool@v1
`, []],
	['a secret in the workflow env reaches every job', `env:
  KEY: \${{ secrets.KEY }}
jobs:
  a:
    steps:
      - uses: 'someone/tool@v1'
`, [6]],
	['a reusable workflow that is passed the secrets', `jobs:
  call:
    uses: someone/repo/.github/workflows/release.yml@v1
    secrets: inherit
`, [3]],
	['a local action and a Docker action pinned by digest', `jobs:
  a:
    steps:
      - uses: ./.github/actions/deploy
        env:
          KEY: \${{ secrets.KEY }}
      - uses: docker://alpine@sha256:${'a'.repeat(64)}
        env:
          KEY: \${{ secrets.KEY }}
      - uses: docker://alpine:3
        env:
          KEY: \${{ secrets.KEY }}
`, [10]],
];
const failed = [];
for (const [name, yaml, lines] of CASES) {
	const got = unpinned(yaml).map((p) => p.line);
	if (JSON.stringify(got) !== JSON.stringify(lines)) failed.push(`${name}: lines ${JSON.stringify(got)}, expected ${JSON.stringify(lines)}`);
}
if (failed.length) {
	console.error('check-workflows: the rules fail their own cases\n' + failed.join('\n'));
	process.exit(1);
}

// --- the repository's workflows --------------------------------------------------------------------------------------
const problems = [];
let files = 0;
let checked = 0;
for (const name of readdirSync(WORKFLOWS).sort()) {
	if (!/\.ya?ml$/i.test(name)) continue;
	files++;
	const text = readFileSync(path.join(WORKFLOWS, name), 'utf8');
	checked += text.split('\n').filter((l) => USES.test(l)).length;
	for (const p of unpinned(text)) {
		problems.push(`.github/workflows/${name}:${p.line}: ${p.action}@${p.ref || '(no ref)'} can read a secret; pin it to the full commit SHA of that release ("${p.action}@<40 hex> # <version>", from \`gh api repos/<owner>/<repo>/git/ref/tags/<version>\`)`);
	}
}
if (problems.length) {
	console.error(`check-workflows: ${problems.length} problem(s)\n` + problems.join('\n'));
	process.exit(1);
}
console.log(`check-workflows: ok (${files} workflows, ${checked} actions, ${CASES.length} rule cases)`);
