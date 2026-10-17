// Translation guard (docs/17 §7). Fails when:
// - languages/qersh-for-woocommerce.pot is stale: the strings of the code changed and `php scripts/make-pot.php` was
//   not run (the POT is extracted again with --stdout and compared);
// - a languages/*.po misses a string of the POT, keeps a string the POT no longer has, has references or comments
//   that differ from the POT (run `php scripts/make-pot.php --update-po`), or has a fuzzy or empty entry, or the
//   wrong number of plural forms;
// - a translation does not keep the placeholders of its original: printf placeholders (%s, %1$s, %d, %%), the
//   {site_title}-style placeholders of WooCommerce emails, and HTML tags. A plural form used only for 0, 1 or 2 may
//   leave out the number, as Arabic writes "طلبان" for 2;
// - a translation into a right-to-left language has a USSD code such as *9# that is not isolated: the neutral "*"
//   and "#" then land on the wrong side of the digits, and the customer sees "#9*" (docs/17 §7). The code must
//   follow U+200E (left-to-right mark) directly, or sit between U+2066 and U+2069;
// - a compiled file (.mo, .l10n.php, the JSON of a script) is missing, or older than its .po and different from what
//   the .po compiles to (`php scripts/build-translations.php --check`). The bytes decide, not only the dates, because
//   a git checkout gives every file the same arbitrary time.
// Needs PHP on the PATH (or in $PHP), like the other checks.
import { readdirSync, readFileSync, existsSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const DOMAIN = 'qersh-for-woocommerce';
const POT = path.join(root, 'languages', `${DOMAIN}.pot`);
const PHP = process.env.PHP || 'php';
const AR_PLURAL = 'nplurals=6; plural=(n==0 ? 0 : n==1 ? 1 : n==2 ? 2 : n%100>=3 && n%100<=10 ? 3 : n%100>=11 ? 4 : 5);';

const problems = [];
const warnings = [];
const short = (s) => {
	const t = s.replace(/\n/g, '\\n');
	return t.length > 70 ? `${t.slice(0, 67)}…` : t;
};

function php(args) {
	const r = spawnSync(PHP, args, { cwd: root, encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 });
	if (r.error) {
		console.error(`check-translations: cannot run ${PHP}: ${r.error.message}`);
		process.exit(2);
	}
	return r;
}

// ---- PO reading -------------------------------------------------------------------------------------------------

function unescape(s) {
	return s.replace(/\\(?:([0-7]{1,3})|x([0-9a-fA-F]{1,2})|(.))/gs, (m, oct, hex, c) => {
		if (oct) return String.fromCharCode(parseInt(oct, 8));
		if (hex) return String.fromCharCode(parseInt(hex, 16));
		return { n: '\n', t: '\t', r: '\r', a: '\x07', b: '\b', f: '\f', v: '\v' }[c] ?? c;
	});
}

function parsePo(text, label) {
	const lines = text.replace(/^﻿/, '').replace(/\r\n/g, '\n').split('\n');
	const entries = [];
	let e = null;
	let last = null;
	const fresh = () => ({ comments: [], extracted: [], references: [], flags: [], msgctxt: null, msgid: null, msgid_plural: null, msgstr: [], obsolete: false, line: 0 });
	const flush = () => {
		if (e && e.msgid !== null) entries.push(e);
		e = null;
		last = null;
	};
	lines.forEach((raw, i) => {
		let line = raw.trimEnd();
		let obsolete = false;
		if (line.startsWith('#~')) {
			obsolete = true;
			line = line.slice(2).trimStart();
			if (line === '') return;
		}
		if (line === '') {
			flush();
			return;
		}
		if (line[0] === '#' && !obsolete) {
			if (e && last !== null) flush();
			e ??= fresh();
			const kind = line.slice(0, 2);
			if (kind === '#.') e.extracted.push(line.slice(2).trim());
			else if (kind === '#:') e.references.push(...line.slice(2).trim().split(/\s+/).filter(Boolean));
			else if (kind === '#,') e.flags.push(...line.slice(2).split(',').map((f) => f.trim()).filter(Boolean));
			else if (kind !== '#|') e.comments.push(line.slice(1).trim());
			return;
		}
		let m = /^(msgctxt|msgid_plural|msgid|msgstr(?:\[(\d+)\])?)\s+"(.*)"$/s.exec(line);
		if (m) {
			const field = m[1];
			const value = unescape(m[3]);
			if ((field === 'msgctxt' || (field === 'msgid' && last !== 'msgctxt')) && e && last && last.startsWith('msgstr')) flush();
			e ??= fresh();
			if (!e.line) e.line = i + 1;
			e.obsolete = obsolete;
			if (field === 'msgctxt') e.msgctxt = value;
			else if (field === 'msgid') e.msgid = value;
			else if (field === 'msgid_plural') e.msgid_plural = value;
			else e.msgstr[m[2] === undefined ? 0 : Number(m[2])] = value;
			last = field.startsWith('msgstr') ? `msgstr:${m[2] ?? 0}` : field;
			return;
		}
		m = /^"(.*)"$/s.exec(line);
		if (m && e && last) {
			const value = unescape(m[1]);
			if (last === 'msgctxt') e.msgctxt += value;
			else if (last === 'msgid') e.msgid += value;
			else if (last === 'msgid_plural') e.msgid_plural += value;
			else e.msgstr[Number(last.slice(7))] += value;
			return;
		}
		problems.push(`${label}:${i + 1}: cannot read this line: ${line.slice(0, 80)}`);
	});
	flush();
	const headerEntry = entries.find((x) => x.msgid === '' && x.msgctxt === null && !x.obsolete);
	const header = {};
	if (headerEntry) {
		for (const h of (headerEntry.msgstr[0] ?? '').split('\n')) {
			const at = h.indexOf(':');
			if (at > 0) header[h.slice(0, at).trim()] = h.slice(at + 1).trim();
		}
	}
	return { header, entries: entries.filter((x) => x !== headerEntry) };
}

const keyOf = (x) => (x.msgctxt === null ? x.msgid : `${x.msgctxt}\u0004${x.msgid}`);

// ---- Placeholder rules ------------------------------------------------------------------------------------------

const PRINTF = /%(?:%|(?:([1-9][0-9]*)\$)?((?:[-+0]|'.)?[0-9]*(?:\.[0-9]+)?)([bcdeEfFgGosuxX]))/g;

// Normalized printf placeholders: "%s %s" and "%2$s %1$s" both give ["1:s", "2:s"]; "%%" counts as "%".
function printfOf(s) {
	const out = [];
	let seq = 0;
	for (const m of s.matchAll(PRINTF)) {
		if (m[0] === '%%') {
			out.push('%');
			continue;
		}
		out.push(`${m[1] ? Number(m[1]) : ++seq}:${m[2]}${m[3]}`);
	}
	return out.sort();
}
const bracesOf = (s) => (s.match(/\{[a-z_]+\}/g) ?? []).sort();
const tagsOf = (s) => (s.match(/<\/?[a-zA-Z][^<>]*>/g) ?? []).sort();
const same = (a, b) => a.length === b.length && a.every((v, i) => v === b[i]);

// Right-to-left languages (WordPress locales), and the USSD codes (*9#, *9*1#) whose "*" and "#" are neutral.
const RTL_LANGUAGE = /^(?:ar|ary|arz|azb|ckb|dv|fa|haz|he|ps|sd|ug|ur|yi)(?:[_-]|$)/;
const USSD = /\*[0-9][0-9*]*#/g;
const LRM = '‎';
const LRI = '⁦';
const PDI = '⁩';
function unisolatedCodes(s) {
	const out = [];
	for (const m of s.matchAll(USSD)) {
		const before = s[m.index - 1];
		const after = s[m.index + m[0].length];
		if (before !== LRM && !(before === LRI && after === PDI)) out.push(m[0]);
	}
	return out;
}
const subset = (a, b) => {
	const pool = [...b];
	for (const v of a) {
		const at = pool.indexOf(v);
		if (at < 0) return false;
		pool.splice(at, 1);
	}
	return true;
};

// Which plural forms are reached by some n >= 3 (those must keep every placeholder).
function formsForBigNumbers(pluralForms, nplurals) {
	const m = /plural\s*=\s*(.+?);?\s*$/.exec(pluralForms);
	if (!m || !/^[n0-9\s=!<>&|?:%()+\-*/]+$/.test(m[1])) return new Set(Array.from({ length: nplurals }, (_, i) => i));
	const f = new Function('n', `return Number(${m[1]});`);
	const big = new Set();
	for (let n = 3; n <= 1000; n++) big.add(f(n));
	return big;
}

// ---- 1. The POT is current --------------------------------------------------------------------------------------

if (!existsSync(POT)) {
	problems.push(`languages/${DOMAIN}.pot is missing; run php scripts/make-pot.php`);
} else {
	const r = php(['scripts/make-pot.php', '--stdout']);
	if (r.status !== 0) {
		problems.push(`make-pot failed:\n${r.stderr.trim()}`);
	} else {
		const norm = (s) => s.replace(/\r\n/g, '\n').replace(/^"POT-Creation-Date: .*"$/m, '').replace(/^# Copyright \(C\) \d{4} /m, '# Copyright (C) ');
		if (norm(r.stdout) !== norm(readFileSync(POT, 'utf8'))) {
			problems.push(`languages/${DOMAIN}.pot is stale: the strings of the code changed. Run php scripts/make-pot.php --update-po, then translate the new strings`);
		}
	}
}

// ---- 2. Every .po is complete and faithful ----------------------------------------------------------------------

const pot = existsSync(POT) ? parsePo(readFileSync(POT, 'utf8'), 'pot') : { header: {}, entries: [] };
const potByKey = new Map(pot.entries.map((x) => [keyOf(x), x]));
const poFiles = readdirSync(path.join(root, 'languages')).filter((f) => f.endsWith('.po')).sort();
if (poFiles.length === 0) problems.push('languages/: no .po file');

for (const file of poFiles) {
	const rel = `languages/${file}`;
	const po = parsePo(readFileSync(path.join(root, rel), 'utf8'), rel);
	const h = po.header;
	if (!h.Language) problems.push(`${rel}: no Language header`);
	if (!h['Plural-Forms']) problems.push(`${rel}: no Plural-Forms header`);
	if (h.Language === 'ar' && h['Plural-Forms'] !== AR_PLURAL) problems.push(`${rel}: Plural-Forms must be ${AR_PLURAL}`);
	if (h['Content-Type'] && !/charset=UTF-8/i.test(h['Content-Type'])) problems.push(`${rel}: Content-Type must be UTF-8`);
	if (h['X-Domain'] && h['X-Domain'] !== DOMAIN) problems.push(`${rel}: X-Domain is ${h['X-Domain']}`);
	const nplurals = Number((/nplurals\s*=\s*(\d+)/.exec(h['Plural-Forms'] ?? '') ?? [])[1] ?? 2);
	const big = formsForBigNumbers(h['Plural-Forms'] ?? '', nplurals);
	const rtl = RTL_LANGUAGE.test(h.Language ?? '');

	const seen = new Set();
	let translated = 0;
	for (const e of po.entries) {
		const where = `${rel}:${e.line}`;
		if (e.obsolete) {
			warnings.push(`${where}: obsolete entry "${short(e.msgid)}" (kept as #~; delete it when it is no longer useful)`);
			continue;
		}
		const key = keyOf(e);
		if (seen.has(key)) problems.push(`${where}: "${short(e.msgid)}" appears twice`);
		seen.add(key);
		const p = potByKey.get(key);
		if (!p) {
			problems.push(`${where}: "${short(e.msgid)}" is not in the POT any more; run php scripts/make-pot.php --update-po`);
			continue;
		}
		if ((p.msgid_plural ?? null) !== (e.msgid_plural ?? null)) problems.push(`${where}: "${short(e.msgid)}" has another plural than the POT`);
		if (!same(p.references, e.references) || !same(p.extracted, e.extracted)) {
			problems.push(`${where}: "${short(e.msgid)}" has other references or comments than the POT; run php scripts/make-pot.php --update-po`);
		}
		if (e.flags.includes('fuzzy')) problems.push(`${where}: "${short(e.msgid)}" is fuzzy`);
		const forms = e.msgid_plural === null ? 1 : nplurals;
		const strs = Array.from({ length: Math.max(forms, e.msgstr.length) }, (_, i) => e.msgstr[i]);
		if (strs.length !== forms) problems.push(`${where}: "${short(e.msgid)}" has ${strs.length} forms, ${forms} expected`);
		if (strs.some((s) => s === undefined || s === '')) {
			problems.push(`${where}: "${short(e.msgid)}" is not translated`);
			continue;
		}
		translated++;

		// Placeholders, WooCommerce {placeholders} and HTML.
		const origins = e.msgid_plural === null ? [e.msgid] : [e.msgid, e.msgid_plural];
		const want = printfOf(origins[origins.length - 1]);
		if (e.msgid_plural !== null && !same(printfOf(e.msgid), want) && !subset(printfOf(e.msgid), want)) {
			problems.push(`${where}: the singular and plural of "${short(e.msgid)}" have different placeholders`);
		}
		strs.forEach((s, i) => {
			const label = e.msgid_plural === null ? '' : ` (msgstr[${i}])`;
			const got = printfOf(s);
			const mayDrop = e.msgid_plural !== null && !big.has(i);
			if (mayDrop ? !subset(got, want) : !same(got, want)) {
				problems.push(`${where}${label}: placeholders differ for "${short(e.msgid)}": ${JSON.stringify(want)} vs ${JSON.stringify(got)}`);
			}
			if (!same(bracesOf(s), bracesOf(origins[0]))) problems.push(`${where}${label}: {placeholders} differ for "${short(e.msgid)}"`);
			if (!same(tagsOf(s), tagsOf(origins[0]))) problems.push(`${where}${label}: HTML tags differ for "${short(e.msgid)}": ${JSON.stringify(tagsOf(origins[0]))} vs ${JSON.stringify(tagsOf(s))}`);
			if (rtl) {
				for (const code of unisolatedCodes(s)) {
					problems.push(`${where}${label}: ${code} is not isolated in "${short(e.msgid)}", so right-to-left text moves its * and # (*9# shows as #9*); put U+200E (left-to-right mark) right before it, or wrap it in U+2066 … U+2069`);
				}
			}
		});
	}
	for (const [key, p] of potByKey) {
		if (!seen.has(key)) problems.push(`${rel}: "${short(p.msgid)}" from the POT is missing; run php scripts/make-pot.php --update-po and translate it`);
	}
	console.log(`check-translations: ${rel}: ${translated} of ${pot.entries.length} strings translated`);
}

// ---- 3. The compiled files match their .po ----------------------------------------------------------------------

// build-translations.php --check names a file "older than its .po and stale" when both hold; an older file with the
// right bytes is current (a fresh git checkout), so the date alone never fails.
{
	const r = php(['scripts/build-translations.php', '--check']);
	if (r.status !== 0) problems.push((r.stderr || r.stdout).trim());
}

for (const w of warnings) console.warn(`check-translations: warning: ${w}`);
if (problems.length) {
	console.error(`check-translations: ${problems.length} problem(s)\n${problems.join('\n')}`);
	process.exit(1);
}
console.log('check-translations: ok');
