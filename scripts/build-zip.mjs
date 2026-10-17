// Builds dist/qersh-for-woocommerce.zip, the package uploaded to wordpress.org and attached to GitHub releases
// (docs/17 §9 and §11 in the Qersh repository), from an allow-list, under one top folder qersh-for-woocommerce/.
// Nothing outside the list is ever packed: no dotfile (.distignore and .gitignore included), no LOCK.json, *.dist,
// *.sh, tests, fixtures, scripts, vendor, node_modules, docs-dev, composer.*, package*.json, AGENTS.md or README.md.
// The build fails when the finished zip holds a path with a part starting with a dot, a forbidden extension, a file
// type that does not belong to its folder, or a PHP file without its ABSPATH guard; it then re-reads the zip and
// checks every entry's size and CRC-32.
// No dependency: a minimal ZIP writer on node:zlib deflateRawSync.
// Usage: node scripts/build-zip.mjs [--release] [--out <path>]
//   --release  also fail when languages/ (the Domain Path folder) is missing or has no compiled translation.
// Entry times come from SOURCE_DATE_EPOCH, else the last commit, else now, so a build of one commit is reproducible.
import { execFileSync } from 'node:child_process';
import { existsSync, mkdirSync, readdirSync, readFileSync, statSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { deflateRawSync, inflateRawSync } from 'node:zlib';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const SLUG = 'qersh-for-woocommerce';
const args = process.argv.slice(2);
const release = args.includes('--release');
const outIndex = args.indexOf('--out');
const outFile = outIndex >= 0 && args[outIndex + 1] ? path.resolve(args[outIndex + 1]) : path.join(root, 'dist', `${SLUG}.zip`);
const MAX_BYTES = 10 * 1024 * 1024; // wordpress.org refuses larger uploads.

// The allow-list: single files, and folders with the file types each may hold.
const FILES = ['qersh-for-woocommerce.php', 'uninstall.php', 'readme.txt', 'LICENSE', 'assets/vendor/qrcode-generator/qrcode.js', 'assets/vendor/qrcode-generator/LICENSE'];
const FOLDERS = [
	{ dir: 'src', ext: ['.php'] },
	{ dir: 'templates', ext: ['.php'] },
	{ dir: 'assets/js', ext: ['.js'] },
	{ dir: 'assets/css', ext: ['.css'] },
	// Compiled translations (.mo, .l10n.php, the JSON of JavaScript strings) and their sources (.po, .pot).
	{ dir: 'languages', ext: ['.mo', '.po', '.pot', '.json', '.php'] },
];
const FORBIDDEN_NAMES = new Set(['LOCK.json', 'AGENTS.md', 'CLAUDE.md', 'README.md', 'composer.json', 'composer.lock', 'package.json', 'package-lock.json', 'phpcs.xml.dist', 'phpstan.neon', 'phpunit.xml.dist', 'fixtures.lock.json']);
const FORBIDDEN_EXT = ['.dist', '.sh', '.bash', '.ps1', '.bat', '.cmd', '.exe', '.phar', '.zip', '.gz', '.tar', '.lock', '.log', '.bak', '.orig', '.swp', '.tmp', '.env', '.map', '.md', '.neon', '.yml', '.yaml', '.mjs', '.ts', '.inc'];
const FORBIDDEN_DIRS = ['tests', 'e2e', 'fixtures', 'scripts', 'vendor', 'node_modules', 'docs-dev', 'dist', 'build'];

const problems = [];
const warnings = [];
const posix = (p) => p.split(path.sep).join('/');

function walk(rel) {
	const abs = path.join(root, rel);
	const out = [];
	for (const name of readdirSync(abs).sort()) {
		const child = rel ? `${rel}/${name}` : name;
		const st = statSync(path.join(root, child));
		if (st.isDirectory()) out.push(...walk(child));
		else if (st.isFile()) out.push(child);
	}
	return out;
}

const selected = [];
for (const f of FILES) {
	if (!existsSync(path.join(root, f))) problems.push(`${f}: missing`);
	else selected.push(f);
}
for (const { dir, ext } of FOLDERS) {
	if (!existsSync(path.join(root, dir))) {
		if (dir === 'languages') (release ? problems : warnings).push('languages/: missing (the Domain Path folder must be in the package)');
		else problems.push(`${dir}/: missing`);
		continue;
	}
	const files = walk(dir);
	if (dir === 'languages' && !files.some((f) => f.endsWith('.mo') || f.endsWith('.l10n.php'))) {
		(release ? problems : warnings).push('languages/: no compiled translation (.mo or .l10n.php)');
	}
	for (const f of files) {
		const base = f.split('/').pop();
		if (f.split('/').some((part) => part.startsWith('.'))) continue; // never packed; reported by the final check if it got in
		if (!ext.some((e) => base.toLowerCase().endsWith(e))) {
			problems.push(`${f}: a ${path.extname(base) || 'file without extension'} does not belong in ${dir}/ (allowed: ${ext.join(' ')}); remove it or extend the allow-list`);
			continue;
		}
		selected.push(f);
	}
}

// The final check, on the entry names exactly as they go into the zip.
function checkEntry(name) {
	const parts = name.split('/');
	if (parts[0] !== SLUG) problems.push(`${name}: outside the top folder ${SLUG}/`);
	const inner = parts.slice(1);
	if (inner.some((p) => p.startsWith('.'))) problems.push(`${name}: a path part starts with a dot`);
	const base = inner[inner.length - 1] || '';
	if (FORBIDDEN_NAMES.has(base)) problems.push(`${name}: ${base} is a development file`);
	if (FORBIDDEN_EXT.some((e) => base.toLowerCase().endsWith(e))) problems.push(`${name}: forbidden extension`);
	if (inner.length > 1 && FORBIDDEN_DIRS.includes(inner[0])) problems.push(`${name}: ${inner[0]}/ is a development folder`);
}

// Plugin Check's direct_file_access rule: every PHP file of the plugin stops when loaded outside WordPress.
for (const f of selected) {
	if (!f.endsWith('.php') || f.endsWith('.l10n.php')) continue;
	const src = readFileSync(path.join(root, f), 'utf8');
	if (!src.startsWith('<?php')) problems.push(`${f}: does not start with <?php`);
	if (!/defined\(\s*'ABSPATH'\s*\)\s*\|\|\s*exit|if\s*\(\s*!\s*defined\(\s*'ABSPATH'\s*\)\s*\)/.test(src)) problems.push(`${f}: no ABSPATH guard`);
}

// Two Plugin Check sniffs that have no PHPCS equivalent in vendor/ (phpcs-plugin-check.xml says so), applied to
// every line of the PHP and JavaScript files packed: PluginCheck.CodeAnalysis.Localhost and ShortURL, same patterns.
const LOCALHOST = /(https?:)?\/\/(localhost|127.0.0.1|(.*\.local(host)?))\//i;
const SHORT_URL = /https?:\/\/(?:[^/\s]+\.)?(adf\.ly|bit\.do|bit\.ly|clck\.ru|cutt\.ly|df\.ly|goo\.gl|is\.gd|lc\.chat|ow\.ly|polr\.me|rb\.gy|s2r\.co|short\.link|shorturl\.at|soo\.gd|tiny\.cc|tinyurl\.com|v\.gd)/;
for (const f of selected) {
	if (!/\.(php|js)$/.test(f)) continue;
	readFileSync(path.join(root, f), 'utf8').split('\n').forEach((line, i) => {
		if (LOCALHOST.test(line)) problems.push(`${f}:${i + 1}: a localhost or *.local URL (Plugin Check: PluginCheck.CodeAnalysis.Localhost)`);
		if (SHORT_URL.test(line)) problems.push(`${f}:${i + 1}: a short URL (Plugin Check: PluginCheck.CodeAnalysis.ShortURL)`);
	});
}

// --- a minimal ZIP writer (PKWARE APPNOTE 6.3: local headers, central directory, end record; UTF-8 names) -----------
const CRC_TABLE = new Uint32Array(256).map((_, n) => {
	let c = n;
	for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
	return c >>> 0;
});
function crc32(buf) {
	let c = 0xffffffff;
	for (let i = 0; i < buf.length; i++) c = CRC_TABLE[(c ^ buf[i]) & 0xff] ^ (c >>> 8);
	return (c ^ 0xffffffff) >>> 0;
}
function epoch() {
	if (process.env.SOURCE_DATE_EPOCH && /^\d+$/.test(process.env.SOURCE_DATE_EPOCH)) return Number(process.env.SOURCE_DATE_EPOCH);
	try {
		return Number(execFileSync('git', ['log', '-1', '--format=%ct'], { cwd: root, encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] }).trim()) || Math.floor(Date.now() / 1000);
	} catch {
		return Math.floor(Date.now() / 1000);
	}
}
function dosTime(sec) {
	const d = new Date(sec * 1000);
	const year = Math.max(1980, d.getUTCFullYear());
	return {
		time: (d.getUTCHours() << 11) | (d.getUTCMinutes() << 5) | Math.floor(d.getUTCSeconds() / 2),
		date: ((year - 1980) << 9) | ((d.getUTCMonth() + 1) << 5) | d.getUTCDate(),
	};
}

const when = dosTime(epoch());
const entries = [];
const dirs = new Set([`${SLUG}/`]);
for (const f of [...new Set(selected)].sort()) {
	const parts = f.split('/');
	for (let i = 1; i < parts.length; i++) dirs.add(`${SLUG}/${parts.slice(0, i).join('/')}/`);
	entries.push({ name: `${SLUG}/${f}`, data: readFileSync(path.join(root, f)) });
}
const all = [...[...dirs].sort().map((name) => ({ name, data: null })), ...entries];
for (const e of all) if (e.data) checkEntry(e.name);

// The deploy to wordpress.org SVN (10up/action-wordpress-plugin-deploy, docs/17 §11) does not use this script: it
// copies the repository minus .distignore. So the two must ship the same files: every file git knows (tracked, or
// new and not ignored) is either in the zip or excluded by .distignore, and nothing in the zip is excluded there.
function distignoreMatchers() {
	const file = path.join(root, '.distignore');
	if (!existsSync(file)) return null;
	return readFileSync(file, 'utf8').split(/\r?\n/).map((l) => l.trim()).filter((l) => l && !l.startsWith('#')).map((pattern) => {
		const anchored = pattern.startsWith('/');
		const body = pattern.replace(/^\//, '').replace(/\/$/, '');
		const glob = body.split('/').map((seg) => seg.replace(/[.+^${}()|[\]\\]/g, '\\$&').replace(/\*/g, '[^/]*').replace(/\?/g, '[^/]')).join('/');
		// rsync: a pattern with a slash, or a leading one, is matched from the root; otherwise against any path part.
		const re = anchored || body.includes('/') ? new RegExp(`^${glob}(/|$)`) : new RegExp(`(^|/)${glob}(/|$)`);
		return { pattern, re };
	});
}
const matchers = distignoreMatchers();
let gitFiles = null;
try {
	const ls = (extra) => execFileSync('git', ['ls-files', '-z', ...extra], { cwd: root, encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'], maxBuffer: 64 * 1024 * 1024 }).split('\0').filter(Boolean);
	gitFiles = [...new Set([...ls([]), ...ls(['--others', '--exclude-standard'])])].filter((f) => existsSync(path.join(root, f)));
} catch {
	warnings.push('git is not available: the .distignore parity check was skipped');
}
if (!matchers) problems.push('.distignore: missing (the SVN deploy would ship the whole repository)');
else if (gitFiles) {
	const packed = new Set(selected);
	const excluded = (f) => matchers.some((m) => m.re.test(f));
	for (const f of gitFiles.sort()) {
		if (packed.has(f) && excluded(f)) problems.push(`${f}: packed in the zip but excluded by .distignore`);
		if (!packed.has(f) && !excluded(f)) problems.push(`${f}: not in the zip, but the SVN deploy would ship it; add it to .distignore or to the allow-list`);
	}
}

if (problems.length) {
	for (const w of warnings) console.log(`warning: ${w}`);
	console.error(`build-zip: ${problems.length} problem(s), nothing written\n` + problems.join('\n'));
	process.exit(1);
}

const chunks = [];
const central = [];
let offset = 0;
for (const e of all) {
	const name = Buffer.from(e.name, 'utf8');
	const isDir = e.data === null;
	const raw = isDir ? Buffer.alloc(0) : e.data;
	const deflated = isDir ? raw : deflateRawSync(raw, { level: 9 });
	const stored = isDir || deflated.length >= raw.length;
	const body = stored ? raw : deflated;
	const crc = isDir ? 0 : crc32(raw);
	const method = stored ? 0 : 8;
	const local = Buffer.alloc(30);
	local.writeUInt32LE(0x04034b50, 0);
	local.writeUInt16LE(20, 4); // version needed: 2.0
	local.writeUInt16LE(0x0800, 6); // flags: names are UTF-8
	local.writeUInt16LE(method, 8);
	local.writeUInt16LE(when.time, 10);
	local.writeUInt16LE(when.date, 12);
	local.writeUInt32LE(crc, 14);
	local.writeUInt32LE(body.length, 18);
	local.writeUInt32LE(raw.length, 22);
	local.writeUInt16LE(name.length, 26);
	local.writeUInt16LE(0, 28);
	chunks.push(local, name, body);

	const cd = Buffer.alloc(46);
	cd.writeUInt32LE(0x02014b50, 0);
	cd.writeUInt16LE((3 << 8) | 20, 4); // made by: Unix, 2.0 (so the permission bits below are read)
	cd.writeUInt16LE(20, 6);
	cd.writeUInt16LE(0x0800, 8);
	cd.writeUInt16LE(method, 10);
	cd.writeUInt16LE(when.time, 12);
	cd.writeUInt16LE(when.date, 14);
	cd.writeUInt32LE(crc, 16);
	cd.writeUInt32LE(body.length, 20);
	cd.writeUInt32LE(raw.length, 24);
	cd.writeUInt16LE(name.length, 28);
	cd.writeUInt16LE(0, 30); // extra
	cd.writeUInt16LE(0, 32); // comment
	cd.writeUInt16LE(0, 34); // disk
	cd.writeUInt16LE(0, 36); // internal attributes
	cd.writeUInt32LE((((isDir ? 0o040755 : 0o100644) << 16) | (isDir ? 0x10 : 0)) >>> 0, 38);
	cd.writeUInt32LE(offset, 42);
	central.push(cd, name);
	offset += local.length + name.length + body.length;
}
const cdSize = central.reduce((n, b) => n + b.length, 0);
const end = Buffer.alloc(22);
end.writeUInt32LE(0x06054b50, 0);
end.writeUInt16LE(0, 4);
end.writeUInt16LE(0, 6);
end.writeUInt16LE(all.length, 8);
end.writeUInt16LE(all.length, 10);
end.writeUInt32LE(cdSize, 12);
end.writeUInt32LE(offset, 16);
end.writeUInt16LE(0, 20);
const zip = Buffer.concat([...chunks, ...central, end]);
if (all.length > 0xffff || zip.length > 0xffffffff) {
	console.error('build-zip: too large for a ZIP without ZIP64');
	process.exit(1);
}

// --- read it back: the central directory, every name, size and CRC ---------------------------------------------
function readBack(buf) {
	const eocd = buf.lastIndexOf(Buffer.from([0x50, 0x4b, 0x05, 0x06]));
	if (eocd < 0) throw new Error('no end of central directory record');
	const count = buf.readUInt16LE(eocd + 10);
	let p = buf.readUInt32LE(eocd + 16);
	const names = [];
	for (let i = 0; i < count; i++) {
		if (buf.readUInt32LE(p) !== 0x02014b50) throw new Error(`bad central directory entry ${i}`);
		const method = buf.readUInt16LE(p + 10);
		const crc = buf.readUInt32LE(p + 16);
		const csize = buf.readUInt32LE(p + 20);
		const usize = buf.readUInt32LE(p + 24);
		const nlen = buf.readUInt16LE(p + 28);
		const xlen = buf.readUInt16LE(p + 30);
		const clen = buf.readUInt16LE(p + 32);
		const lho = buf.readUInt32LE(p + 42);
		const name = buf.toString('utf8', p + 46, p + 46 + nlen);
		const lnlen = buf.readUInt16LE(lho + 26);
		const lxlen = buf.readUInt16LE(lho + 28);
		const data = buf.subarray(lho + 30 + lnlen + lxlen, lho + 30 + lnlen + lxlen + csize);
		const raw = method === 8 ? inflateRawSync(data) : data;
		if (raw.length !== usize || crc32(raw) !== crc) throw new Error(`${name}: size or CRC-32 does not match`);
		names.push(name);
		p += 46 + nlen + xlen + clen;
	}
	return names;
}

let names;
try {
	names = readBack(zip);
} catch (e) {
	console.error(`build-zip: the written zip does not read back: ${e.message}`);
	process.exit(1);
}
for (const n of names) if (!n.endsWith('/')) checkEntry(n);
if (zip.length > MAX_BYTES) problems.push(`the zip is ${zip.length} bytes; wordpress.org accepts at most ${MAX_BYTES}`);
if (problems.length) {
	console.error(`build-zip: ${problems.length} problem(s), nothing written\n` + problems.join('\n'));
	process.exit(1);
}

mkdirSync(path.dirname(outFile), { recursive: true });
writeFileSync(outFile, zip);
for (const w of warnings) console.log(`warning: ${w}`);
const files = names.filter((n) => !n.endsWith('/'));
const unpacked = entries.reduce((n, e) => n + e.data.length, 0);
console.log(`build-zip: ${posix(path.relative(root, outFile)) || outFile}: ${files.length} files, ${zip.length} bytes (${(zip.length / 1024).toFixed(1)} KB; ${(unpacked / 1024).toFixed(1)} KB unpacked)`);
