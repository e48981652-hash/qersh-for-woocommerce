#!/usr/bin/env node
// Parity test: the compiled Qersh core (tests/parity/core/*.js, vendored from packages/qersh-webhook and pinned by
// fixtures.lock.json) against the PHP port in src/Core, on deterministic pseudo-random inputs.
//
// Usage: node tests/parity/run.mjs [--seed=<text>] [--scale=<x>] [--suite=<substring>] [--show=<n>] [--php=<binary>] [--all]
//   --seed   seeds every generator (default "qersh-parity-1"); each suite has its own stream, so suites are independent
//   --scale  multiplies the number of cases (default 1, about 85 000 cases, half a minute)
//   --suite  runs only the suites whose name contains this text
//   --show   how many mismatches to print in full (default 12)
//   --php    the PHP binary (default $PHP_BINARY or "php")
//   --all    also prints every mismatch as one JSON line
// Exit code 0 when every case agrees, 1 on any mismatch (or a PHP error inside a case), 2 when PHP cannot run.
// The summary lists, per suite, what the core answered ("core outcomes"), so a generator that stops reaching a
// branch shows up, and the mismatches by input category.
//
// How it works. Each suite generates inputs with a seeded PRNG (sfc32, never Math.random), runs the core in this
// process and the PHP port through `php tests/parity/php-runner.php` (JSON on stdin and stdout, in batches), and
// compares the two results after a canonical form: object keys sorted, a list and an object with the keys 0..n-1
// count as the same value (PHP arrays cannot tell them apart), -0 is 0.
//
// Differences the contract fixes are handled by construction, never by ignoring a mismatch:
// - Times: the core reads numbers as epoch milliseconds, the PHP port as seconds (docs-dev/CONTRACTS.md §6). Each
//   generated time is given to the core in milliseconds (or as an ISO string) and to PHP in whole seconds; `now` is
//   `options.now` in ms for the core and `$now` in seconds for PHP. Inputs use whole seconds only.
// - The matcher runs with the core's default options (late_window_min 120, skew_min 10), plus one suite with random
//   options; the store's own rules around it (MatchPolicy) are tested elsewhere (docs/17 §1).
// - ISO date-times without an offset are not generated: Date.parse reads them as local time, Time::iso_to_sec()
//   refuses them on purpose.
// - SetupLink::build() never writes `events` (docs/17 §4.1): the core builds the same parameters without them.
// - Config: PHP validates the whole plugin configuration; only the receiver issues are compared, without the
//   plugin's extra rules (endpoint path, allowed events, transfer.received), and the defaults the plugin changes
//   (endpoint.path, skew_min 0) are applied to the core's withDefaults() for the comparison.
// - Verifier: `v1_index` exists only in PHP and is not compared. Header names for deliveryHeaders() use dashes
//   (PHP also accepts WordPress's underscores, the core does not).
// - JSON cannot tell PHP an empty list from an empty object, so `[]` is not generated where the core tells them
//   apart; a lone surrogate in a core string is compared as U+FFFD, its UTF-8 form. Bytes that are not valid UTF-8
//   reach the core decoded by TextDecoder, as the core would receive them.
import { spawnSync } from 'node:child_process';
import { createHmac } from 'node:crypto';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import * as core from './core/index.js';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(HERE, '..', '..');
const RUNNER = path.join(HERE, 'php-runner.php');
const VECTORS = JSON.parse(readFileSync(path.join(ROOT, 'fixtures', 'test-vectors.json'), 'utf8'));

// ---------------------------------------------------------------------------------------------------------------
// Options

const opts = { seed: 'qersh-parity-1', scale: 1, suite: '', show: 12, php: process.env.PHP_BINARY || 'php', batch: 2000, all: false };
for (const arg of process.argv.slice(2)) {
	if (arg === '--all') {
		opts.all = true; // also print every mismatch, one JSON line each (for diagnosis)
		continue;
	}
	const m = /^--(seed|scale|suite|show|php|batch)=(.*)$/.exec(arg);
	if (!m) {
		console.error(`parity: unknown argument ${arg}`);
		process.exit(2);
	}
	opts[m[1]] = ['scale', 'show', 'batch'].includes(m[1]) ? Number(m[2]) : m[2];
}

// ---------------------------------------------------------------------------------------------------------------
// Seeded PRNG

function cyrb128(text) {
	let h1 = 1779033703, h2 = 3144134277, h3 = 1013904242, h4 = 2773480762;
	for (let i = 0; i < text.length; i++) {
		const k = text.charCodeAt(i);
		h1 = h2 ^ Math.imul(h1 ^ k, 597399067);
		h2 = h3 ^ Math.imul(h2 ^ k, 2869860233);
		h3 = h4 ^ Math.imul(h3 ^ k, 951274213);
		h4 = h1 ^ Math.imul(h4 ^ k, 2716044179);
	}
	h1 = Math.imul(h3 ^ (h1 >>> 18), 597399067);
	h2 = Math.imul(h4 ^ (h2 >>> 22), 2869860233);
	h3 = Math.imul(h1 ^ (h3 >>> 17), 951274213);
	h4 = Math.imul(h2 ^ (h4 >>> 19), 2716044179);
	return [(h1 ^ h2 ^ h3 ^ h4) >>> 0, (h2 ^ h1) >>> 0, (h3 ^ h1) >>> 0, (h4 ^ h1) >>> 0];
}

class Rng {
	constructor(seedText) {
		let [a, b, c, d] = cyrb128(seedText);
		this.next = () => {
			a >>>= 0; b >>>= 0; c >>>= 0; d >>>= 0;
			let t = (a + b) | 0;
			a = b ^ (b >>> 9);
			b = (c + (c << 3)) | 0;
			c = (c << 21) | (c >>> 11);
			d = (d + 1) | 0;
			t = (t + d) | 0;
			c = (c + t) | 0;
			return (t >>> 0) / 4294967296;
		};
		for (let i = 0; i < 16; i++) this.next();
	}
	int(lo, hi) {
		return lo + Math.floor(this.next() * (hi - lo + 1));
	}
	chance(p) {
		return this.next() < p;
	}
	pick(list) {
		return list[Math.floor(this.next() * list.length)];
	}
	/** pairs: [[weight, value or () => value], ...] */
	weighted(pairs) {
		let total = 0;
		for (const [w] of pairs) total += w;
		let x = this.next() * total;
		for (const [w, v] of pairs) {
			x -= w;
			if (x < 0) return typeof v === 'function' ? v() : v;
		}
		const v = pairs[pairs.length - 1][1];
		return typeof v === 'function' ? v() : v;
	}
	shuffle(list) {
		const a = [...list];
		for (let i = a.length - 1; i > 0; i--) {
			const j = Math.floor(this.next() * (i + 1));
			[a[i], a[j]] = [a[j], a[i]];
		}
		return a;
	}
	str(alphabet, length) {
		const chars = [...alphabet];
		let s = '';
		for (let i = 0; i < length; i++) s += this.pick(chars);
		return s;
	}
	digits(n) {
		let s = '';
		for (let i = 0; i < n; i++) s += String(this.int(0, 9));
		return s;
	}
	bytes(n) {
		const out = new Uint8Array(n);
		for (let i = 0; i < n; i++) out[i] = this.int(0, 255);
		return out;
	}
}

// ---------------------------------------------------------------------------------------------------------------
// Transport markers (see php-runner.php) and the canonical form used for comparing

const F = (x) => ({ $f: x }); // a float in PHP; the core sees the number
const B = (bytes) => ({ $b: Buffer.from(bytes).toString('base64') }); // bytes: Uint8Array for the core, a binary string in PHP
const S = (bytes) => ({ $s: Buffer.from(bytes).toString('base64') }); // bytes: decoded text for the core (U+FFFD for invalid), a binary string in PHP
const D = (js, php) => ({ $dual: [js, php] }); // differs by design: the core's value, then PHP's (ms vs seconds)
const UTF8 = new TextDecoder('utf-8', { ignoreBOM: true });
const utf8 = (text) => new Uint8Array(Buffer.from(text, 'utf8'));
const isMarker = (v, k) => v !== null && typeof v === 'object' && !Array.isArray(v) && !(v instanceof Uint8Array) && Object.keys(v).length === 1 && k in v;

function forJs(v) {
	if (Array.isArray(v)) return v.map(forJs);
	if (v !== null && typeof v === 'object') {
		if (isMarker(v, '$f')) return typeof v.$f === 'string' ? Number(v.$f) : v.$f;
		if (isMarker(v, '$b')) return new Uint8Array(Buffer.from(v.$b, 'base64'));
		if (isMarker(v, '$s')) return UTF8.decode(Buffer.from(v.$s, 'base64'));
		if (isMarker(v, '$dual')) return forJs(v.$dual[0]);
		const o = {};
		for (const [k, x] of Object.entries(v)) if (x !== undefined) o[k] = forJs(x);
		return o;
	}
	return v;
}

function forPhp(v) {
	if (Array.isArray(v)) return v.map((x) => (x === undefined ? null : forPhp(x)));
	if (v !== null && typeof v === 'object') {
		if (isMarker(v, '$dual')) return forPhp(v.$dual[1]);
		if (isMarker(v, '$f') || isMarker(v, '$b') || isMarker(v, '$s')) return v;
		const o = {};
		for (const [k, x] of Object.entries(v)) if (x !== undefined) o[k] = forPhp(x);
		return o;
	}
	return v;
}

function canon(v) {
	if (v === undefined) return { $undefined: true };
	if (typeof v === 'string') return v.toWellFormed(); // a lone surrogate is U+FFFD once encoded, the only form PHP has
	if (v === null || typeof v === 'boolean') return v;
	if (typeof v === 'number') return Number.isFinite(v) ? (Object.is(v, -0) ? 0 : v) : { $nonfinite: String(v) };
	if (v instanceof Uint8Array) return { $bytes: Buffer.from(v).toString('hex') };
	if (typeof v !== 'object') return { $type: typeof v };
	const entries = Array.isArray(v) ? v.map((x, i) => [String(i), x === undefined ? null : x]) : Object.entries(v).filter(([, x]) => x !== undefined);
	entries.sort(([a], [b]) => (a < b ? -1 : a > b ? 1 : 0));
	const o = {};
	for (const [k, x] of entries) o[k] = canon(x);
	return o;
}

const same = (a, b) => JSON.stringify(a) === JSON.stringify(b);

/** Code points the report spells out (\u{85}): controls, invisible marks and white space other than the plain space. */
const SPELLED = [[0x7f, 0xa0], [0xad, 0xad], [0x61c, 0x61c], [0x1680, 0x1680], [0x180e, 0x180e], [0x2000, 0x200f], [0x2028, 0x202f], [0x205f, 0x206f], [0x3000, 0x3000], [0xfeff, 0xfeff]];
const spelled = (c) => SPELLED.some(([a, b]) => c >= a && c <= b);

/** JSON for the report, with invisible and white-space characters spelled out, cut to `max`. */
function short(v, max = 160) {
	const json = v === undefined ? 'undefined' : JSON.stringify(v);
	const s = [...json].map((ch) => (spelled(ch.codePointAt(0)) ? '\\u{' + ch.codePointAt(0).toString(16) + '}' : ch)).join('');
	return s.length > max ? s.slice(0, max) + '…' : s;
}

function diffPaths(a, b, at = '', out = []) {
	if (out.length >= 6) return out;
	const ao = a !== null && typeof a === 'object';
	const bo = b !== null && typeof b === 'object';
	if (ao && bo) {
		for (const k of new Set([...Object.keys(a), ...Object.keys(b)])) diffPaths(a[k], b[k], at ? `${at}.${k}` : k, out);
		return out;
	}
	if (!same(a, b)) out.push(`${at || '(value)'}: core=${short(a, 90)} php=${short(b, 90)}`);
	return out;
}

// ---------------------------------------------------------------------------------------------------------------
// PHP

function runPhp(cases) {
	const r = spawnSync(opts.php, [RUNNER], { input: JSON.stringify({ cases }), encoding: 'utf8', maxBuffer: 1 << 30, cwd: ROOT, windowsHide: true });
	if (r.error) return cases.map(() => ({ e: `cannot start ${opts.php}: ${r.error.message}` }));
	let out = null;
	try {
		out = JSON.parse(r.stdout);
	} catch {
		out = null;
	}
	if (out && Array.isArray(out.results) && out.results.length === cases.length) return out.results;
	if (cases.length === 1) return [{ e: `php-runner failed (exit ${r.status}): ${(r.stderr || r.stdout || '').trim().slice(0, 300)}` }];
	const mid = cases.length >> 1; // a fatal error in one case: split until it is found
	return [...runPhp(cases.slice(0, mid)), ...runPhp(cases.slice(mid))];
}

const hmac = { hmacSha256Hex: (key, payload) => createHmac('sha256', key).update(payload).digest('hex') };
const hex = (key, t, body) => hmac.hmacSha256Hex(key, core.signedPayload(t, body));

// ---------------------------------------------------------------------------------------------------------------
// Shared generators

const BASE_NOW = Math.floor(VECTORS.generated_from_epoch_ms / 1000); // 2026-10-11, inside 32-bit seconds
// Set from the PHP build before generating. Playground runs 32-bit PHP (docs/17 §4.6), where an integer above
// 2^31 - 1 decodes as a float: the few generated integers beyond that range are kept inside it there.
let INT32 = false;
const bigInt = (value64, value32 = 2 ** 31 - 1) => (INT32 ? value32 : value64);
const cp = (n) => String.fromCodePoint(n);
const range = (a, b) => Array.from({ length: b - a + 1 }, (_, i) => cp(a + i));
const POOL = {
	ascii: range(0x21, 0x7e),
	alnum: [...'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'],
	space: [' ', '\t', '\n', '\v', '\f', '\r', '\u00A0', '\u1680', ...range(0x2000, 0x200a), '\u2028', '\u2029', '\u202F', '\u205F', '\u3000', '\uFEFF'],
	invisible: ['\u180E', '\u0085', '\u200B', '\u200C', '\u200D', '\u200E', '\u200F', '\u061C', '\u2060', '\u202A', '\u202B', '\u202C', '\u202D', '\u202E', '\u2066', '\u2067', '\u2068', '\u2069'],
	arabic: range(0x0621, 0x064a),
	arabicDigits: range(0x0660, 0x0669),
	persianDigits: range(0x06f0, 0x06f9),
	otherDigits: [...range(0x0966, 0x096f), ...range(0xff10, 0xff19), ...range(0x1d7ce, 0x1d7d7)],
	latin1: range(0xc0, 0xff),
	special: ['\u0130', '\u0131', '\u017F', '\u212A', '\u212B', '\u1E9E', '\u03A3', '\u00DF', '\uFB00', '\u01C5'],
	emoji: range(0x1f600, 0x1f64f),
	punct: [...'*•-._@+()/#,:;?!\'"%&=~`<>[]{}|\\^'],
};
const ALL_POOLS = Object.keys(POOL);

function randomText(rng, min, max, pools = ALL_POOLS) {
	const n = rng.int(min, max);
	let s = '';
	for (let i = 0; i < n; i++) s += rng.pick(POOL[rng.pick(pools)]);
	return s;
}

function wrapSpace(rng, s, p = 0.3) {
	if (!rng.chance(p)) return s;
	const pool = rng.chance(0.7) ? POOL.space : POOL.invisible; // invisible: not white space for JavaScript
	const a = rng.chance(0.6) ? rng.str(pool, rng.int(1, 3)) : '';
	const b = rng.chance(0.6) ? rng.str(pool, rng.int(1, 3)) : '';
	return a + s + b;
}

const set = (o, k, v) => {
	if (v !== undefined) o[k] = v;
	return o;
};
const pad2 = (n) => String(n).padStart(2, '0');

/** An ISO 8601 time both sides read the same way: whole seconds, Z or an offset with a colon. */
function isoOf(rng, sec) {
	if (rng.chance(0.6)) {
		const s = new Date(sec * 1000).toISOString();
		return rng.chance(0.4) ? s : s.replace('.000Z', 'Z');
	}
	const off = rng.pick([180, 120, 0, -300, 330, -60, 345, -720, 840, -570]);
	const local = new Date((sec + off * 60) * 1000).toISOString().slice(0, 19);
	const a = Math.abs(off);
	return local + (rng.chance(0.2) ? '.000' : '') + (off < 0 ? '-' : '+') + pad2(Math.floor(a / 60)) + ':' + pad2(a % 60);
}

// Payer spellings ------------------------------------------------------------------------------------------------

const EG_PREFIXES = ['010', '011', '012', '015'];
const egNumber = (rng) => rng.pick(EG_PREFIXES) + rng.digits(8);

function spellDigits(rng, text) {
	const mode = rng.weighted([[6, 'ascii'], [2, 'arabic'], [1, 'persian'], [1, 'mixed']]);
	return [...text]
		.map((ch) => {
			if (!/[0-9]/.test(ch)) return ch;
			const m = mode === 'mixed' ? rng.pick(['ascii', 'arabic', 'persian']) : mode;
			return m === 'arabic' ? cp(0x660 + Number(ch)) : m === 'persian' ? cp(0x6f0 + Number(ch)) : ch;
		})
		.join('');
}

function groupDigits(rng, digits) {
	if (rng.chance(0.45)) return digits;
	const sep = rng.pick([' ', '-', '.', '\u00A0', ' - ', '/', '\u200F', '\u202F', '_', '\u200B', '  ', '\t', '*']);
	let out = '';
	for (let i = 0; i < digits.length; ) {
		const n = rng.int(1, 4);
		out += (out ? sep : '') + digits.slice(i, i + n);
		i += n;
	}
	return out;
}

function spellNumber(rng, n11) {
	const local = rng.chance(0.7) ? n11 : n11.slice(1);
	const prefix = rng.weighted([[7, ''], [2, '+20'], [1, '+20 '], [2, '0020'], [1, '00 20 '], [1, '20'], [1, '(+20) '], [1, '+2'], [1, '+'], [1, '00']]);
	return wrapSpace(rng, spellDigits(rng, prefix + groupDigits(rng, local)));
}

function maskNumber(rng, n11) {
	const chars = [...n11];
	const mask = rng.pick(['*', 'x', 'X', '•', '#', '٭']);
	const k = rng.int(1, 6);
	for (let i = 0; i < k; i++) chars[rng.int(2, 10)] = mask;
	return spellDigits(rng, chars.join(''));
}

function badNumber(rng) {
	return rng.weighted([
		[1, () => egNumber(rng).slice(0, rng.int(5, 10))],
		[1, () => egNumber(rng) + rng.digits(rng.int(1, 3))],
		[1, () => '02' + rng.digits(9)],
		[1, () => '00' + rng.digits(rng.int(9, 13))],
		[1, () => '20' + rng.digits(rng.int(9, 12))],
		[1, () => '2' + egNumber(rng)],
		[1, () => '0020' + egNumber(rng).slice(1, 9)],
		[1, () => '+20 ' + egNumber(rng).slice(2)],
		[1, () => rng.digits(rng.int(0, 16))],
		[1, () => '1' + rng.digits(9)],
		[1, () => '0' + rng.digits(10)],
	]);
}

const HANDLE_ALPHABET = 'abcdefghijklmnopqrstuvwxyz0123456789._-';
function genHandle(rng) {
	const len = rng.weighted([[1, 1], [12, () => rng.int(2, 24)], [1, () => rng.int(60, 70)]]);
	let h = rng.str(HANDLE_ALPHABET, len);
	if (rng.chance(0.9) && !/[a-z]/.test(h)) h = 'a' + h.slice(1);
	return h;
}

function spellHandle(rng, h) {
	let s = h;
	if (rng.chance(0.25)) s = [...s].map((c) => (rng.chance(0.5) ? c.toUpperCase() : c)).join('');
	if (rng.chance(0.03)) s = s.replace(/k/g, '\u212A');
	const suffix = rng.weighted([
		[5, '@instapay'],
		[3, ''],
		[1, '@InstaPay'],
		[1, '@INSTAPAY'],
		[1, () => rng.pick(['@instapay.com', '@', '@@instapay', ' @instapay', '@insta', '@instapay ', 'instapay', '@instapay\u200F', '@ instapay'])],
	]);
	return wrapSpace(rng, s + suffix);
}

/** Anything a payer field, a counterparty number or a handle could hold. Returns [category, value]. */
function genPayerRaw(rng) {
	return rng.weighted([
		[30, () => ['number', spellNumber(rng, egNumber(rng))]],
		[8, () => ['masked', maskNumber(rng, egNumber(rng))]],
		[8, () => ['number-bad', wrapSpace(rng, spellDigits(rng, rng.chance(0.5) ? groupDigits(rng, badNumber(rng)) : badNumber(rng)))]],
		[20, () => ['handle', spellHandle(rng, genHandle(rng))]],
		[10, () => ['junk', randomText(rng, 0, 30)]],
		[5, () => ['numeric', rng.pick([Number(egNumber(rng)), Number('20' + egNumber(rng)), Number('20' + egNumber(rng).slice(1)), F(Number(egNumber(rng))), F(1.5), F(1e21), F(2e15), F(Number('20' + egNumber(rng))), 0, -1001234567, 1001234567])]],
		[3, () => ['scalar', rng.pick([true, false, null, '', ' ', '@', '+20', '0'])]],
		[3, () => ['non-scalar', rng.pick([[], {}, [egNumber(rng)], [[egNumber(rng)]], { number: egNumber(rng) }, ['ab', 'cd']])]],
		[5, () => ['unicode', rng.pick([
			rng.str(POOL.otherDigits, 11),
			'٠' + rng.str(POOL.arabicDigits, 10),
			'۰۱' + rng.str(POOL.persianDigits, 9),
			'\u212Aarim@instapay',
			'\u212Aarim',
			'\u0130stanbul',
			'ahmed\u0131',
			rng.str(POOL.arabic, rng.int(2, 10)),
			rng.str(POOL.arabic, 3) + ' ' + egNumber(rng),
			'\u{1D7CE}' + egNumber(rng).slice(1),
		])]],
		[4, () => ['mixed', rng.pick([
			'رقمي ' + spellNumber(rng, egNumber(rng)),
			'tel:+2' + egNumber(rng),
			egNumber(rng) + ' / ' + egNumber(rng),
			egNumber(rng) + 'abc',
			'abc' + egNumber(rng),
			genHandle(rng) + egNumber(rng),
			egNumber(rng) + '@instapay',
			'ext. ' + egNumber(rng),
		])]],
	]);
}

// ---------------------------------------------------------------------------------------------------------------
// Payer

function genCounterparty(rng) {
	if (rng.chance(0.06)) return ['junk', rng.pick([null, 'x', 5, [], [egNumber(rng)], true])];
	const cp1 = {};
	const [c1, number] = genPayerRaw(rng);
	const [c2, handle] = rng.chance(0.5) ? genPayerRaw(rng) : ['handle', spellHandle(rng, genHandle(rng))];
	set(cp1, 'number', rng.weighted([[6, number], [2, null], [2, undefined]]));
	set(cp1, 'handle', rng.weighted([[4, handle], [3, null], [3, undefined]]));
	set(cp1, 'name', rng.weighted([[5, 'Ahmed'], [3, 'أحمد'], [2, undefined]]));
	return [`${c1}/${c2}`, cp1];
}

// ---------------------------------------------------------------------------------------------------------------
// Time

/** True when V8 reads the text with its ECMAScript Date Time String Format parser and every field is in range. */
function esFormatAccepted(s) {
	const m = /^(?:([+-])([0-9]{6})|([0-9]{4}))(?:-([0-9]{2})(?:-([0-9]{2}))?)?(?:[Tt]([0-9]{2}):([0-9]{2})(?::([0-9]{2})(?:\.([0-9]+))?)?(?:([Zz])|([+-])([0-9]{2}):?([0-9]{2}))?)?$/.exec(s);
	if (!m) return false;
	const num = (v, d) => (v === undefined ? d : Number(v));
	const mo = num(m[4], 1);
	const d = num(m[5], 1);
	if (mo < 1 || mo > 12 || d < 1 || d > 31) return false;
	if (m[6] !== undefined) {
		if (num(m[6], 0) > 24 || num(m[7], 0) > 59 || num(m[8], 0) > 59) return false;
		if (m[10] === undefined && m[11] === undefined) return false; // no zone: local time, refused on purpose
		if (m[11] !== undefined && (num(m[12], 0) > 23 || num(m[13], 0) > 59)) return false;
	}
	return true;
}

function genIsoText(rng) {
	const y = rng.weighted([[8, () => rng.int(1971, 2037)], [1, () => rng.int(0, 100)], [1, () => rng.pick([1000, 1900, 1969, 1970, 2000, 2100, 9999])]]);
	const mo = rng.weighted([[12, () => rng.int(1, 12)], [1, () => rng.pick([0, 13])]]);
	const d = rng.weighted([[9, () => rng.int(1, 28)], [3, () => rng.int(29, 31)], [1, () => rng.pick([0, 32])]]);
	const h = rng.weighted([[12, () => rng.int(0, 23)], [1, () => rng.pick([24, 25])]]);
	const mi = rng.weighted([[12, () => rng.int(0, 59)], [1, 60]]);
	const s = rng.weighted([[12, () => rng.int(0, 59)], [1, 60]]);
	const yy = String(y).padStart(4, '0');
	const date = `${yy}-${pad2(mo)}-${pad2(d)}`;
	const zone = rng.weighted([
		[6, 'Z'],
		[4, () => (rng.chance(0.5) ? '+' : '-') + pad2(rng.int(0, 14)) + ':' + rng.pick(['00', '30', '45'])],
		[1, () => rng.pick(['z', '+0300', '-0500', '+03', '+24:00', '+23:59', '-23:59', '+00:60', '+99:00'])],
	]);
	const frac = rng.weighted([[6, ''], [2, '.000'], [1, () => '.' + rng.digits(rng.int(1, 9))]]);
	const sep = rng.weighted([[10, 'T'], [1, () => rng.pick(['t', ' '])]]);
	return rng.weighted([
		[6, () => `${date}${sep}${pad2(h)}:${pad2(mi)}:${pad2(s)}${frac}${zone}`],
		[1, () => `${date}${sep}${pad2(h)}:${pad2(mi)}${zone}`],
		[2, () => date],
		[1, () => rng.pick([`${yy}-${pad2(mo)}`, yy, `+00${yy}-${pad2(mo)}-${pad2(d)}T00:00:00Z`, ` ${date}T${pad2(h)}:00:00Z`, `${date}T${pad2(h)}:00:00Z `, `${date}T${pad2(h)}:00:00.Z`, `${date}T${pad2(h)}:00:00,5Z`, `${date}T${pad2(h)}Z`, ''])],
		[1, () => new Date((BASE_NOW + rng.int(-1e7, 1e7)) * 1000).toISOString()],
	]);
}

// ---------------------------------------------------------------------------------------------------------------
// Matcher

/** Minutes as the core reads them (finite number >= 0, else the default), for steering the generated times. */
function effMinutes(v, fallback) {
	const x = isMarker(v, '$f') ? Number(v.$f) : v;
	return typeof x === 'number' && Number.isFinite(x) && x >= 0 ? x : fallback;
}

function genOptions(rng) {
	const value = () => rng.weighted([
		[10, () => rng.pick([0, 1, 5, 10, 30, 60, 120, 240, 1440])],
		[3, () => rng.int(0, 600)],
		[1, -1],
		[1, '10'],
		[1, null],
		[1, () => F(1.5)],
		[1, () => F('Infinity')],
		[1, true],
		[2, undefined],
	]);
	const o = {};
	set(o, 'late_window_min', value());
	set(o, 'skew_min', value());
	return o;
}

function timeValue(rng, sec) {
	return rng.weighted([
		[60, () => D(sec * 1000, sec)],
		[22, () => {
			const iso = isoOf(rng, sec);
			return D(iso, rng.chance(0.7) ? sec : iso);
		}],
		[2, null],
		[1, 'garbage'],
		[1, ''],
		[2, undefined],
	]);
}

function genAmount(rng) {
	return rng.weighted([
		[70, () => rng.int(1, 5000) * 100],
		[10, () => rng.int(1, 999999)],
		[3, 0],
		[2, () => -rng.int(1, 1000)],
		[3, null],
		[3, undefined],
		[2, () => F(rng.int(1, 5000) * 100)],
		[2, () => F(150.5)],
		[2, () => String(rng.int(1, 9999) * 100)],
		[1, true],
		[1, () => F('Infinity')],
		[1, () => bigInt(2147483647, 2 ** 29)], // a price can be twice the amount: still inside 2^31 - 1
	]);
}

const intOf = (v) => {
	const x = isMarker(v, '$f') ? Number(v.$f) : v;
	return typeof x === 'number' && Number.isInteger(x) ? x : null;
};

function genMatchCase(rng, randomOptions) {
	const now = BASE_NOW + rng.int(-2000000, 2000000);
	const options = randomOptions ? genOptions(rng) : { late_window_min: 120, skew_min: 10 };
	const late = Math.round(effMinutes(options.late_window_min, 120) * 60);
	const skew = Math.round(effMinutes(options.skew_min, 10) * 60);

	const handlePayer = rng.chance(0.2);
	const number = egNumber(rng);
	const handle = genHandle(rng);
	const key = handlePayer ? core.normalizePayer(handle + '@instapay') : number;
	const spellKey = () => {
		if (!handlePayer) return rng.chance(0.4) ? number : spellNumber(rng, number);
		return rng.weighted([[5, () => key ?? handle], [2, () => handle], [1, () => (key ?? handle).toUpperCase()], [1, () => wrapSpace(rng, handle + '@instapay', 1)]]);
	};

	// When the money arrived, relative to now; boundaries of the late window are hit on purpose.
	const lag = rng.weighted([
		[4, () => rng.int(0, 120)],
		[3, () => rng.int(0, Math.max(0, late))],
		[2, () => late + rng.pick([-1, 0, 1])],
		[2, () => rng.int(late, late * 3 + 60)],
		[1, () => -rng.int(1, 900)],
		[1, 0],
	]);
	const money = now - lag;
	const at = lag >= 0 ? money : now;

	const transfer = {};
	set(transfer, 'transfer_id', rng.weighted([[95, () => 'trf_' + rng.str(POOL.alnum, 26)], [3, null], [2, 5]]));
	const amount = genAmount(rng);
	set(transfer, 'amount_minor', amount);
	set(transfer, 'received_at', rng.weighted([
		[86, () => isoOf(rng, money)],
		[4, () => D(money * 1000, money)],
		[1, () => D(money * 1000, F(money))],
		[3, undefined],
		[2, null],
		[2, () => rng.pick(['', 'garbage', 'yesterday', '2026-13-01T00:00:00Z'])],
		[1, true],
	]));
	set(transfer, 'auto_confirm_ok', rng.weighted([[88, true], [5, false], [2, 'true'], [2, 1], [3, undefined]]));
	set(transfer, 'provider_txn_id', rng.weighted([[6, () => rng.digits(12)], [1, null], [3, undefined], [1, 99]]));

	let cat = handlePayer ? 'handle' : 'number';
	const shape = rng.weighted([[75, 'raw'], [20, 'summary'], [5, 'junk']]);
	let transferValue = transfer;
	if (shape === 'raw') {
		const cpv = {};
		if (handlePayer) {
			set(cpv, 'number', rng.weighted([[5, () => maskNumber(rng, egNumber(rng))], [3, null], [2, undefined], [1, () => egNumber(rng)]]));
			set(cpv, 'handle', rng.weighted([[7, () => spellHandle(rng, handle)], [2, null], [1, undefined]]));
		} else {
			set(cpv, 'number', rng.weighted([[7, () => spellNumber(rng, number)], [1, () => maskNumber(rng, number)], [1, null], [1, undefined]]));
			set(cpv, 'handle', rng.weighted([[7, null], [2, undefined], [1, () => spellHandle(rng, genHandle(rng))]]));
		}
		set(cpv, 'name', rng.chance(0.8) ? 'Assaad K Mansouri' : undefined);
		transfer.counterparty = rng.chance(0.97) ? cpv : rng.pick([null, 'x', [], [number]]);
	} else if (shape === 'summary') {
		const payer = {};
		set(payer, 'key', rng.weighted([[6, key], [2, () => spellKey()], [1, null], [1, 'garbage'], [1, undefined]]));
		set(payer, 'number', handlePayer ? null : rng.weighted([[1, number], [1, null], [1, undefined]]));
		set(payer, 'handle', handlePayer ? rng.weighted([[1, () => spellHandle(rng, handle)], [1, null]]) : null);
		transfer.payer = payer;
		if (rng.chance(0.2)) transfer.counterparty = { number: handlePayer ? null : number, handle: handlePayer ? handle : null };
	} else {
		transferValue = rng.pick([null, 'transfer', 5, [1, 2], {}, true]); // not []: PHP reads it as {}
		cat = 'junk-transfer';
	}

	const amountNum = intOf(amount) ?? rng.int(100, 10000);
	const count = rng.weighted([[1, 0], [4, 1], [3, 2], [2, 3], [1, 4], [1, () => rng.int(5, 8)]]);
	const orders = [];
	let prevCreated = null;
	for (let k = 0; k < count; k++) {
		if (rng.chance(0.03)) {
			orders.push(rng.pick([null, 'order', 5, [1], []]));
			continue;
		}
		const o = {};
		set(o, 'order_number', rng.weighted([[96, () => String(rng.int(100, 99999))], [2, 1042], [1, null], [1, undefined]]));
		const paid = rng.weighted([[40, undefined], [20, 0], [20, () => rng.int(0, amountNum)], [4, null], [2, -100], [2, () => F(150.5)], [2, '100'], [2, () => F(100)]]);
		const paidNum = intOf(paid) ?? 0;
		const due = amountNum + paidNum;
		set(o, 'price_minor', rng.weighted([
			[35, due],
			[10, () => due + rng.pick([1, 100, 50, -1, -100])],
			[15, () => rng.int(100, 500000)],
			[10, () => due + rng.int(1, 5000)],
			[5, () => due - rng.int(1, 5000)],
			[3, null],
			[2, -1],
			[2, () => F(1.5)],
			[2, () => F(due)],
			[2, '100'],
			[2, undefined],
			[2, 0],
		]));
		set(o, 'paid_minor', paid);
		set(o, 'payer_key', rng.weighted([[70, spellKey], [12, () => egNumber(rng)], [4, () => rng.pick([5, null, true])], [6, undefined], [4, () => maskNumber(rng, number)], [4, () => key ?? 'x']]));
		let created = at + rng.weighted([
			[5, () => -rng.int(0, late + 3600)],
			[2, () => skew + rng.pick([-1, 0, 1])],
			[1, () => rng.pick([0, -1, 1])],
			[2, () => rng.int(-skew, skew)],
			[2, () => rng.int(skew, skew + 7200)],
			[1, () => -rng.int(late, late * 4 + 1)],
		]);
		if (prevCreated !== null && rng.chance(0.2)) created = prevCreated; // ties: the order of the input decides
		prevCreated = created;
		const expires = rng.weighted([
			[5, () => created + rng.int(600, 10800)],
			[3, () => at - late + rng.pick([-1, 0, 1])],
			[2, () => at + rng.pick([-1, 0, 1])],
			[1, () => created - rng.int(0, 600)],
			[2, () => at - rng.int(0, late * 2 + 1)],
		]);
		set(o, 'created_at', timeValue(rng, created));
		set(o, 'expires_at', timeValue(rng, expires));
		set(o, 'status', rng.weighted([[60, undefined], [15, 'open'], [10, 'partial'], [3, 'paid'], [2, 'expired'], [2, 'review'], [2, 'closed'], [2, null], [2, 'OPEN'], [2, 1]]));
		orders.push(o);
	}
	return {
		cat: `${cat}/${shape}`,
		args: [transferValue, orders, D(now * 1000, now), options],
	};
}

function genUpdateData(rng) {
	if (rng.chance(0.05)) return ['junk', rng.pick([null, 'x', 5, [], ['suspected_forgery'], true, {}])];
	const d = {};
	set(d, 'transfer_id', rng.weighted([[8, () => 'trf_' + rng.str(POOL.alnum, 26)], [1, null], [1, 5], [2, undefined]]));
	const reasons = ['suspected_forgery', 'new_counterparty', 'no_baseline', 'amount_unusual', 'balance_unavailable', 'duplicate_txn'];
	set(d, 'risk_reasons', rng.weighted([
		[5, () => rng.shuffle(reasons).slice(0, rng.int(0, 3))],
		[1, 'suspected_forgery'],
		[1, null],
		[1, () => [5, null, 'SUSPECTED_FORGERY', ['suspected_forgery']]],
		[1, () => ({})],
		[3, undefined],
	]));
	set(d, 'checks', rng.weighted([[5, () => set({}, 'balance_chain', rng.pick(['refuted', 'consistent', 'unknown', null, 'REFUTED', undefined]))], [1, () => []], [1, null], [1, 'refuted'], [3, undefined]]));
	set(d, 'suspected_forgery', rng.weighted([[1, true], [1, false], [1, 'true'], [1, 1], [1, null], [5, undefined]]));
	set(d, 'balance_chain', rng.weighted([[1, 'refuted'], [1, 'consistent'], [1, null], [6, undefined]]));
	return ['update', d];
}

// ---------------------------------------------------------------------------------------------------------------
// Verifier

const base64url = (bytes) => Buffer.from(bytes).toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
const qskKey = (rng) => 'qsk_' + base64url(rng.bytes(32));
const merchantKey = (rng) => rng.str(POOL.ascii, rng.int(24, 64));
const unicodeKey = (rng) => randomText(rng, 12, 30, ['arabic', 'emoji', 'alnum', 'latin1', 'arabicDigits']);

function genKeys(rng) {
	const n = rng.weighted([[1, 0], [6, 1], [3, 2], [1, 3], [1, 4]]);
	const keys = [];
	for (let i = 0; i < n; i++) {
		keys.push(rng.weighted([
			[40, () => qskKey(rng)],
			[15, () => merchantKey(rng)],
			[8, () => unicodeKey(rng)],
			[10, () => wrapSpace(rng, qskKey(rng), 1)],
			[4, ''],
			[3, () => rng.str(POOL.space, rng.int(1, 3))],
			[3, () => rng.pick([null, 5, true, {}, []])],
			[5, () => rng.str(POOL.alnum, rng.int(1, 10))],
		]));
	}
	return keys;
}

const VECTOR_BODIES = [...new Set(VECTORS.cases.map((c) => c.body))];
function genBody(rng) {
	return rng.weighted([
		[40, () => utf8(rng.pick(VECTOR_BODIES))],
		[15, () => rng.bytes(rng.int(0, 200))],
		[15, () => utf8(randomText(rng, 0, 80))],
		[5, () => new Uint8Array(0)],
		[5, () => utf8('\uFEFF' + rng.pick(VECTOR_BODIES))],
		[5, () => utf8(rng.pick(VECTOR_BODIES).replace('15007', '15008'))],
	]);
}

function v1Text(rng, h) {
	return rng.weighted([
		[80, () => 'v1=' + h],
		[4, () => 'v1=' + h.toUpperCase()],
		[3, () => 'v1=' + [...h].map((c) => (rng.chance(0.5) ? c.toUpperCase() : c)).join('')],
		[3, () => ' v1 = ' + h + ' '],
		[2, () => 'v1=' + h.slice(0, 63)],
		[2, () => 'v1=' + h + rng.pick(['0', 'a', 'g'])],
		[2, () => 'v1=' + h.slice(0, 30) + 'g' + h.slice(31)],
		[1, () => 'v1=' + h.slice(0, 32) + ' ' + h.slice(32)],
		[1, () => 'v1=\u00A0' + h],
		[1, () => 'v1=' + h + '\u3000'],
		[1, () => 'V1=' + h],
	]);
}

/** A header for a body and keys, mostly well-formed: signed (or not) by some keys, t near now, junk parts. */
function genSignatureHeader(rng, { body, keys, now, tolerance }) {
	if (rng.chance(0.03)) return rng.pick([null, 5, true, ['t=1'], {}, '', '   ', '\u00A0\n', '\uFEFF']);
	const tol = typeof tolerance === 'number' && tolerance >= 0 ? tolerance : 300;
	const tNum = now + rng.weighted([
		[5, () => rng.int(-60, 60)],
		[2, () => rng.int(-tol, tol)],
		[2, () => rng.pick([tol, -tol, tol + 1, -tol - 1])],
		[1, () => rng.int(-100000, 100000)],
		[1, () => -now + rng.int(0, 5)],
	]);
	let tText = rng.weighted([
		[85, String(tNum)],
		[4, () => '000' + tNum],
		[2, () => ' ' + tNum + ' '],
		[1, () => '\u00A0' + tNum],
		[3, () => rng.pick(['', 'abc', '1.5', '+' + tNum, '1e9', tNum + 'x', '12345678901234', String(bigInt(9999999999999)), '0', '-' + tNum])],
	]);
	const tSigned = /^\s*\d{1,13}\s*$/.test(tText) ? Number(tText.trim()) : tNum;
	const usable = keys.filter((k) => typeof k === 'string' && k.trim() !== '').map((k) => k.trim());
	const hexes = [];
	for (const k of usable) if (rng.chance(0.7)) hexes.push(hex(rng.chance(0.95) ? k : k + 'x', rng.chance(0.93) ? tSigned : tSigned + 1, body));
	if (rng.chance(0.2)) hexes.push(hex(qskKey(rng), tSigned, body));
	if (rng.chance(0.1)) hexes.push(hex(usable[0] ?? 'k', tSigned, utf8('tampered')));
	if (rng.chance(0.05)) hexes.push(rng.str('0123456789abcdef', 64));
	const parts = hexes.map((h) => v1Text(rng, h));
	if (rng.chance(0.93)) parts.push(rng.chance(0.9) ? 't=' + tText : rng.pick([' t = ' + tText, 't =' + tText, 'T=' + tText]));
	if (rng.chance(0.04)) parts.push('t=' + rng.pick([String(tNum + 1), 'x', '']));
	const extras = rng.int(0, 2);
	for (let i = 0; i < extras; i++) parts.push(rng.pick(['v0=abc', 'v2=' + rng.str('0123456789abcdef', 64), 'foo', '', '=', 't', 'v1', 'v1=', ' ', 'x=y=z', 'v1==' + rng.str('0123456789abcdef', 64)]));
	let header = rng.shuffle(parts).join(rng.chance(0.9) ? ',' : ', ');
	if (rng.chance(0.1)) header = wrapSpace(rng, header, 1);
	if (rng.chance(0.03)) {
		// around the 8192-unit limit, counted in UTF-16 units after trimming
		const target = 8192 + rng.pick([-2, -1, 0, 1, 2, 100]);
		const fill = rng.pick(['a', 'ع', '😀']);
		header = header.trim() + ',';
		while (header.length + fill.length <= target) header += fill;
		while (header.length < target) header += 'b';
	}
	return header;
}

// ---------------------------------------------------------------------------------------------------------------
// Setup link

function localIpv4(rng) {
	const o = () => String(rng.weighted([[12, () => rng.int(0, 255)], [1, () => rng.int(256, 999)]]));
	return rng.weighted([
		[1, () => `127.${o()}.${o()}.${o()}`],
		[1, () => `10.${o()}.${o()}.${o()}`],
		[1, () => `192.168.${o()}.${o()}`],
		[1, () => `172.${rng.int(10, 40)}.${o()}.${o()}`],
		[1, () => `${o()}.${o()}.${o()}.${o()}`],
	]);
}

const WEIRD_IPV4 = ['127.1', '127.0.1', '0x7f.0.0.1', '0x7F000001', '2130706433', '0177.0.0.1', '017700000001', '192.168.010.1', '192.168.0x1.1', '10.1', '172.16.256', '172.16.65535', '1.2.3.4.5', '1.2.3.09', '1.2.3.08', '09.1', '0x', '0x.0x.0', '4294967295', '4294967296', '127.0.0.1.', '127.0.0.1..', '1.2.3', '0', '00', '0x100.1.1.1', '256.256.256.256', '10.0.0.0x', '1.2.3.4x', '1.2.3.0x4', '192.168.1.1.1', '0x0a.0.0.1', '012.0.0.1', '10.0.0.0377', '10.0.0.0400', '0xffffffff', '0x1ffffffff', '172.0x10.0.1', '0x7f.1', '127.0.0.1 ', '127.0.0.01'];
const IPV6 = ['[::1]', '[::]', '[::ffff:127.0.0.1]', '[1:2:3:4:5:6:7:8]', '[1::2::3]', '[1:2:3:4:5:6:7:8:9]', '[fe80::1%25en0]', '[::1.2.3]', '[::1.2.3.4.5]', '[1:2:3:4:5:6:1.2.3.4]', '[1:2:3:4:5:6:7:1.2.3.4]', '[::0001]', '[::00001]', '[g::1]', '[:1]', '[1:]', '[::1.2.3.256]', '[::1.02.3.4]', '[::ffff:7f00:1]', '[2001:db8::1]', '[0:0:0:0:0:0:0:1]', '[::1:]', '[1:2:3:4:5:6:7::]', '[::1:2:3:4:5:6:7]', '[1:2:3:4:5:6:7:8::]', '[::ffff:1.2.3.4]', '[::1.2.3.4]', '[1::1.2.3.4]'];
// Hosts made only of Arabic-Indic digits (١٢.com, ١٢٣) are left out: PHP's intl applies the IDNA bidi rule and
// refuses them, Node's URL accepts them. A store URL comes from rest_url(), never from such a host.
// Hosts with an `xn--` label that is not valid Punycode (xn--a.com, xn--.com, xn--zz-zz.com, xn--ab--cd.com, xn--)
// are left out too: Node's URL parser (Ada) answers them differently from one Node 24 release to the next
// (24.17 refuses them, the runner's later 24.x accepts them), while ICU always refuses them. The plugin's answer,
// url_invalid, is pinned by SetupLinkTest::test_invalid_punycode_hosts_are_refused().
const IDN = ['مثال.إختبار', 'متجر.مصر', 'shop.مصر', 'xn--wgbh1c.xn--wgbl6a', 'bücher.de', 'Bücher.DE', 'faß.de', 'ＥＸＡＭＰＬＥ.com', 'ex\u00ADample.com', 'لا\u200D.com', 'a\u200Cb.com', 'xn--mgbh0fb.xn--ngbrx', '日本.jp', 'ελληνικά.gr', 'İstanbul.tr', 'a١b.com', 'א١.com', '💩.la', 'xn--ls8h.la', 'ⓛⓞⓒⓐⓛⓗⓞⓢⓣ', 'ｌｏｃａｌｈｏｓｔ', 'LOCALHOST\u3002', '127。0。0。1', '１２７.０.０.１', '１０.０.０.１', 'printer。local', 'Ｐｒｉｎｔｅｒ.local', 'a.xn--nxa', 'ex\uFE0Fample.com', 'ex\u200Bample.com', 'x\u0300.com', '\u0300x.com', 'ab--cd.com'];
const ODD_HOSTS = ['exa mple.com', 'exa<mple.com', 'a..b', '.', '..', '', '%', 'ex%41mple.com', '%E0%A4%A.com', 'a_b.com', 'a^b', 'a|b', 'a.b.c.d.e', '-a-.com', 'a-.com', 'x'.repeat(64) + '.com', 'a%00b', '%00', '[::1', '::1', 'ab]', '[]', '[::1]x', 'ex*mple.com', "ex'mple.com", 'ex"mple.com', 'ex%2Emple.com', '%2e', 'ex%25mple.com', 'a#b', 'a?b', 'a\\b', 'a{b}', 'a`b', 'a$b', 'a&b', 'a;b', 'a,b', 'a=b', 'a+b', 'a!b', 'a~b', 'a(b)', 'ex%2fample.com', 'ex%3Aample.com', 'a\u007Fb', 'a%7Fb', 'ex%C3%A4mple.com', 'ex%FFample.com'];
const DOT_LOCAL = ['printer.local', 'my-n8n.local', 'PRINTER.LOCAL', 'a.b.local', 'under_score.local', '-x.local', 'x-.local', '.local', 'local', 'x.local.', 'x.locale', 'x.localhost', '123.local', 'n8n.Local', 'x--y.local'];

function randomLabels(rng) {
	const n = rng.int(1, 4);
	const labels = [];
	for (let i = 0; i < n; i++) labels.push(rng.chance(0.2) ? String(rng.int(0, 999)) : rng.str('abcdefghijklmnopqrstuvwxyz0123456789-', rng.int(1, 12)));
	return labels.join('.') + (rng.chance(0.05) ? '.' : '');
}

function genHost(rng) {
	return rng.weighted([
		[20, () => rng.pick(['shop.example', 'n8n.example.com', 'example.com', 'my-shop.com.eg', 'a.b', 'x', 'shop.example.'])],
		[15, () => localIpv4(rng)],
		[8, () => rng.pick(['localhost', 'LOCALHOST', 'LocalHost', 'localhost.', 'localhost.localdomain', 'local', '.localhost'])],
		[8, () => rng.pick(DOT_LOCAL) + (rng.chance(0.1) ? 'x' : '')],
		[8, () => rng.pick(WEIRD_IPV4)],
		[6, () => rng.pick(IPV6)],
		[8, () => rng.pick(IDN)],
		[6, () => randomLabels(rng)],
		[5, () => rng.pick(ODD_HOSTS)],
	]);
}

function genUrl(rng) {
	if (rng.chance(0.03)) return rng.pick([null, 5, '', '   ', true, [], '\u00A0']);
	const scheme = rng.weighted([[40, 'https'], [25, 'http'], [3, 'HTTPS'], [2, 'HtTp'], [2, 'ftp'], [2, 'ws'], [2, 'wss'], [2, 'file'], [2, 'qersh'], [1, 'mailto'], [1, 'javascript'], [1, 'h ttp'], [1, '1http'], [1, 'http+x'], [1, ''], [1, 'ht\ttp'], [1, 'ﬀ']]);
	const colon = rng.chance(0.97) ? ':' : rng.pick(['', '::', ';']);
	const slashes = rng.weighted([[85, '//'], [3, '/'], [3, ''], [2, '///'], [2, '\\\\'], [2, '/\\'], [1, '\\/'], [1, ' //']]);
	const userinfo = rng.weighted([[90, ''], [2, 'user@'], [2, 'user:pw@'], [1, '@'], [1, ':@'], [1, 'a@b@'], [1, 'us%40er@'], [1, ':pw@'], [1, 'u ser@']]);
	const host = genHost(rng);
	const port = rng.weighted([[80, ''], [5, () => ':' + rng.pick([80, 443, 5678, 8080])], [1, ':'], [1, ':0'], [1, ':65535'], [1, ':65536'], [1, ':99999'], [1, ':abc'], [1, ':0080'], [1, ':000000080'], [1, ':-1'], [1, ': 80'], [1, ':８０']]);
	const tail = rng.weighted([[40, '/webhook/qersh'], [20, ''], [10, '/'], [5, '/wp-json/qersh/v1/webhook/abc123def456'], [3, '?x=1'], [3, '#frag'], [2, '/a b'], [2, '/%zz'], [2, '/عربي'], [2, '?rest_route=/qersh/v1/webhook/abc'], [1, '\\path'], [1, '/x@y'], [1, '?a@b'], [1, '#@']]);
	let url = scheme + colon + slashes + userinfo + host + port + tail;
	if (rng.chance(0.06)) url = wrapSpace(rng, url, 1);
	if (rng.chance(0.03)) url = rng.pick(['\x01', '\x1F', '\x00']) + url + rng.pick(['', '\x02']);
	if (rng.chance(0.03)) {
		const i = rng.int(0, url.length);
		url = url.slice(0, i) + rng.pick(['\t', '\n', '\r']) + url.slice(i);
	}
	if (rng.chance(0.02)) url = 'https://a.b/' + 'a'.repeat(Math.max(0, rng.int(2030, 2060) - 12));
	if (rng.chance(0.01)) url = 'https://a.b/' + '😀'.repeat(rng.int(1015, 1020));
	return url;
}

function genSecret(rng) {
	return rng.weighted([
		[25, () => qskKey(rng)],
		[5, () => VECTORS.secret],
		[10, () => merchantKey(rng)],
		[6, () => wrapSpace(rng, qskKey(rng), 1)],
		[5, () => 'qsk_' + rng.str(POOL.alnum, rng.int(1, 30))],
		[5, () => 'qsk_' + rng.str([...POOL.alnum, ...'*+/=.~!'], 40)],
		[4, 'https://looks.like/a/url/not/a/key'],
		[4, () => 'abc://' + 'x'.repeat(30)],
		[5, 'has a space inside it which is long enough'],
		[5, () => unicodeKey(rng)],
		[5, ''],
		[3, '   '],
		[3, null],
		[2, 42],
		[2, false],
		[5, () => rng.str(POOL.ascii, rng.int(20, 26))],
		[3, () => 'QSK_' + base64url(rng.bytes(32))],
		[3, () => 'x'.repeat(23) + rng.pick(['\u00A0', '\u180E', '\u200B', 'y', '😀', '\u2028'])],
		[2, () => '😀'.repeat(rng.int(11, 13))],
	]);
}

function genLabel(rng) {
	return rng.weighted([
		[20, () => rng.pick(['My shop', 'متجري', 'محفظة المتجر', 'Shop (Cairo)', 'Shop Inc.', "Ahmed's", 'a&b=c+d#e', '100% cotton', 'x!y*z', 'تجربة ١٢٣', 'a/b?c', 'tab\there'])],
		[10, () => randomText(rng, 1, 40)],
		[6, () => 'x'.repeat(rng.int(55, 70))],
		[5, () => 'y'.repeat(rng.int(57, 60)) + '😀' + rng.pick(['', 'z', '😀'])],
		[4, () => 'ع'.repeat(rng.int(58, 62))],
		[5, () => wrapSpace(rng, 'Shop', 1)],
		[4, ''],
		[3, '   '],
		[4, null],
		[2, 5],
		[6, undefined],
		[3, () => rng.pick(['.', 'a.', 'a)', 'x?', 'end;', 'q:', 'a,', 'a.)', 'x' + '.'.repeat(3)])],
	]);
}

function genProvider(rng) {
	return rng.weighted([
		[30, () => rng.pick(core.SETUP_LINK_PROVIDERS)],
		[8, () => rng.pick(['Vodafone_Cash', 'ORANGE_CASH', ' bank_nbe ', 'Instapay_App', '\u00A0bank_cib'])],
		[5, () => rng.pick(['we_pay', 'bank_xyz', 'paypal', 'vodafone cash', 'فودافون', 'bank_\u212Afh', 'BANK_\u212AFH'])],
		[5, ''],
		[3, '  '],
		[5, null],
		[2, 5],
		[20, undefined],
	]);
}

function genEvents(rng) {
	return rng.weighted([
		[40, undefined],
		[20, () => rng.shuffle(core.SETUP_LINK_EVENTS).slice(0, rng.int(1, 3))],
		[5, () => []],
		[5, () => [' transfer.received ', 'transfer.updated']],
		[5, () => ['webhook.test']],
		[5, () => ['transfer.reversed']],
		[3, () => ['transfer.received', '', '  ']],
		[3, () => ['transfer.received', 5, null]],
		[3, () => ['Transfer.Received']],
		[2, 'transfer.received'],
		[2, null],
	]);
}

function genLinkParams(rng, plausible = false) {
	const p = {};
	set(p, 'url', plausible && rng.chance(0.8) ? rng.pick(['https://shop.example/wp-json/qersh/v1/webhook/abc123def456', 'https://n8n.example.com/webhook/qersh', 'http://192.168.1.20:5678/webhook/qersh', 'https://متجر.مصر/?wc-api=qersh&qw=abc123def456']) : genUrl(rng));
	set(p, 'secret', rng.chance(plausible ? 0.6 : 0.5) ? genSecret(rng) : undefined);
	set(p, 'label', genLabel(rng));
	set(p, 'provider', genProvider(rng));
	set(p, 'events', genEvents(rng));
	return p;
}

const STOP_CHARS = ['`', '<', '>', '|', '\\', '^', '{', '}', '[', ']', '\u060C', '\u061B', '\u061F', '\u2026', '\u00A0', ' ', '\u200E', '"', '\u201C', '\u00AB', '\u2066', '\uFEFF', '\n', '\u200B'];

function mutateLink(rng, link) {
	if (!rng.chance(0.35)) return link;
	const q = link.indexOf('?') + 1;
	const at = () => rng.int(q, link.length);
	const insert = (s) => {
		const i = at();
		return link.slice(0, i) + s + link.slice(i);
	};
	return rng.weighted([
		[3, () => insert(rng.pick(['%zz', '%E0%A4%A', '%F0%9F%98', '%C3%28', '+', '%2B', '%', '%ED%A0%80', '%C0%AF', '%25']))],
		[2, () => link + rng.pick(['&v=2', '&v=1', '&v=', '&url=https%3A%2F%2Fother.example%2Fx'])],
		[1, () => link.replace('?v=1&', '?v=2&')],
		[1, () => link.replace('?v=1&', '?')],
		[1, () => link.replace('?v=1&', '??v=1&')],
		[1, () => link.replace(/&/g, '&&')],
		[1, () => link.replace('&url=', '&URL=')],
		[3, () => insert(rng.pick(STOP_CHARS))],
		[2, () => link.replace('qersh://setup?', rng.pick(['QERSH://SETUP?', 'Qersh://Setup?', 'qersh://SETUP?', 'qerſh://setup?', 'qersh://ſetup?']))],
		[2, () => link + '&secret=' + core.encodeLinkParam(String(genSecret(rng) ?? ''))],
		[1, () => link + '&events=' + rng.pick(['transfer.received', 'transfer.received,%20transfer.updated', 'webhook.test', ',,', 'x', '%20'])],
		[1, () => link + '&label=' + rng.pick(['Shop+Cairo', 'متجر', '%D9%85%D8%AA%D8%AC%D8%B1', 'a%2Bb', '+++'])],
		[1, () => link + '#frag'],
		[1, () => link + '&provider=' + rng.pick(['BANK_NBE', '%20we_pay%20', ''])],
	]);
}

function handMadeLink(rng) {
	const pairs = [];
	const n = rng.int(0, 6);
	for (let i = 0; i < n; i++) {
		pairs.push(rng.weighted([
			[3, () => 'v=' + rng.pick(['1', '1', '2', '', '01', '1.0'])],
			[5, () => 'url=' + (rng.chance(0.7) ? core.encodeLinkParam(String(genUrl(rng) ?? '')) : rng.pick(['https://a.b/c', 'https://a.b/c?x=1', 'http://localhost:5678/x', 'https%3A%2F%2Fa.b%2Fc']))],
			[2, () => 'secret=' + core.encodeLinkParam(String(genSecret(rng) ?? ''))],
			[2, () => 'label=' + core.encodeLinkParam(String(genLabel(rng) ?? ''))],
			[2, () => 'provider=' + core.encodeLinkParam(String(genProvider(rng) ?? ''))],
			[2, () => 'events=' + core.encodeLinkParam((genEvents(rng) ?? []).toString())],
			[1, () => rng.pick(['x=y', '', '=', 'url', 'v', '=1', 'url=%', 'a=b=c'])],
		]));
	}
	return 'qersh://setup?' + pairs.join(rng.chance(0.9) ? '&' : '&&');
}

function genLinkText(rng) {
	if (rng.chance(0.04)) return ['junk', rng.pick([null, 5, '', 'nothing here', 'qersh://', 'qersh://setup', 'qersh://setup?', 'QERSH://SETUP?V=1', 'qersh:/setup?v=1&url=x', 'qersh://setupv=1', true, []])];
	const built = rng.chance(0.6);
	let link = built ? core.buildSetupLink(genLinkParams(rng, true)) : handMadeLink(rng);
	link = mutateLink(rng, link);
	const before = rng.weighted([[30, ''], [10, 'Here is your link: '], [10, 'رابط مطوّرك: '], [3, '*'], [3, '_'], [3, '~'], [3, "'"], [2, '«'], [2, '"'], [2, '('], [2, '\u200F'], [2, 'x'], [1, '\n'], [1, 'qersh://setup?v=1 '], [1, '`'], [2, '\u2067'], [1, '<'], [1, '\uFEFF']]);
	const after = rng.weighted([[30, ''], [5, '.'], [3, ')?:;,.'], [3, '!'], [3, '*'], [3, '_ thanks'], [2, '\u200B…'], [2, '»'], [2, '،'], [3, ' (open it on the phone)'], [2, '\n'], [3, "'"], [2, '؟'], [2, '`'], [2, '>'], [2, '...'], [2, '.)'], [2, '~'], [1, '\u2069'], [2, ' and qersh://setup?v=1&url=https%3A%2F%2Fother.example'], [1, '\uFEFF'], [1, '_'], [1, "'."]]);
	let text = before + link + after;
	if (rng.chance(0.04)) text = text.toUpperCase();
	if (rng.chance(0.03)) {
		const bytes = Buffer.from(text, 'utf8');
		const i = rng.int(0, bytes.length);
		return ['bytes', S(Buffer.concat([bytes.subarray(0, i), Buffer.from(rng.pick([[0xff], [0xc3], [0xed, 0xa0, 0x80], [0xf0, 0x9f, 0x98], [0xe2, 0x82]])), bytes.subarray(i)]))];
	}
	return [built ? 'built' : 'hand-made', text];
}

// ---------------------------------------------------------------------------------------------------------------
// Config

const VALID_KEY = 'qsk_' + 'A'.repeat(43);

function genConfigValue(rng, kind) {
	return rng.weighted([
		[10, () => (kind === 'tolerance' ? rng.pick([300, 1, 3600, 60]) : kind === 'keep' ? rng.pick([180, 1, 365]) : rng.pick([0, 10, 120, 1440]))],
		[3, () => rng.int(-5, 4000)],
		[1, 0],
		[1, -1],
		[1, () => F(1.5)],
		[1, () => F(kind === 'tolerance' ? 300 : 10)],
		[1, '300'],
		[1, null],
		[1, true],
		[1, () => F(1e300)],
		[1, () => bigInt(2 ** 53)],
		[1, () => ({})],
		[1, () => []],
		[2, undefined],
	]);
}

function genReceiver(rng) {
	if (rng.chance(0.05)) return ['not-object', rng.pick([null, 'x', 5, [], [1, 2], true])];
	const r = {};
	set(r, 'endpoint', rng.weighted([
		[7, () => ({ path: rng.pick(['qersh/v1/webhook', 'qersh', '/qersh', 'qersh hook', '', 'q\u00A0x', 'q\u180Ex', 'a\u2028b', 'qersh/v1/webhook/', 'QERSH/V1/WEBHOOK', 'q\tx', 'q\u200Bx', 'ﾠ']) })],
		[1, () => ({ path: rng.pick([5, null, ['x'], true]) })],
		[1, () => ({})],
		[1, () => rng.pick([null, 'qersh', 5, []])],
		[1, undefined],
	]));
	set(r, 'tolerance_sec', genConfigValue(rng, 'tolerance'));
	set(r, 'events', rng.weighted([
		[6, () => rng.shuffle(core.QERSH_EVENTS).slice(0, rng.int(1, 4))],
		[2, () => ['transfer.received', 'transfer.updated', 'webhook.test']],
		[1, () => ['transfer.received', rng.pick(['foo', 5, null, 'Transfer.Received', ' webhook.test', ''])]],
		[1, () => []],
		[1, () => rng.pick(['transfer.received', {}, { a: 'transfer.received' }, null, 5])],
		[1, undefined],
	]));
	set(r, 'matching', rng.weighted([
		[7, () => set(set({}, 'late_window_min', genConfigValue(rng, 'minutes')), 'skew_min', genConfigValue(rng, 'minutes'))],
		[1, () => rng.pick([null, 'x', [], [5], 5])],
		[1, undefined],
	]));
	set(r, 'dedup', rng.weighted([[7, () => set({}, 'keep_days', genConfigValue(rng, 'keep'))], [1, () => rng.pick([null, 'x', [], 5])], [1, undefined]]));
	return ['object', r];
}

/** The receiver issues of the plugin's validation, without the plugin's own rules (compared with validateConfig). */
// The plugin also refuses numbers this PHP cannot hold as int (1e300): the core accepts them as JS numbers, but no
// configuration needs them, and an int overflow there would silently change a window.
const PLUGIN_ONLY = [/^must be qersh\/v1\/webhook /, /^not handled by this plugin/, /^must include transfer\.received$/, /too large for this server/];
function receiverIssues(issues) {
	if (!Array.isArray(issues)) return issues;
	return issues.filter((i) => i && (i.path === 'receiver' || /^receiver[.[]/.test(i.path)) && !PLUGIN_ONLY.some((rx) => rx.test(i.message)));
}

function coreReceiverIssues(r) {
	const plain = r !== null && typeof r === 'object' && !Array.isArray(r);
	const issues = core.validateConfig(plain ? { ...r, keys: [VALID_KEY] } : r);
	return issues.filter((i) => !/^keys(\[|$)/.test(i.path)).map((i) => ({ path: i.path === '' ? 'receiver' : 'receiver.' + i.path, message: i.message }));
}

function coreReceiverDefaults(r) {
	// The plugin keeps the core's withDefaults() except two defaults (docs/17 §4.2): run the core with them.
	const d = core.defaultConfig;
	const saved = [d.endpoint.path, d.matching.skew_min];
	d.endpoint.path = 'qersh/v1/webhook';
	d.matching.skew_min = 0;
	try {
		const out = core.withDefaults(r);
		delete out.keys;
		return out;
	} finally {
		[d.endpoint.path, d.matching.skew_min] = saved;
	}
}

// ---------------------------------------------------------------------------------------------------------------
// Envelope

const TEMPLATES = VECTOR_BODIES.map((b) => JSON.parse(b));
const isRecord = (v) => typeof v === 'object' && v !== null && !Array.isArray(v);
const RAW = (text) => ({ $raw: text }); // a JSON number token written as is (15007.0, 1.5e4)
const BAD_TOKEN = 'QxBADxQ';
const LONE_TOKEN = 'QxLONExQ';

function randomJsonValue(rng, depth) {
	return rng.weighted([
		[3, () => randomText(rng, 0, 12)],
		[2, () => rng.int(-1000, 100000)],
		[1, () => rng.pick([true, false, null])],
		[1, () => RAW(rng.pick(['1.0', '1e2', '-0', '0.1', '12345678901234567890', '1E400', '-1e-400', '2.50']))],
		[depth > 0 ? 1 : 0, () => Array.from({ length: rng.int(0, 3) }, () => randomJsonValue(rng, depth - 1))],
		[depth > 0 ? 1 : 0, () => {
			const o = {};
			for (let i = rng.int(0, 3); i > 0; i--) o[rng.pick(['a', 'b', 'x_y', 'عربي', '', '0', 'transfer_id', 'risk'])] = randomJsonValue(rng, depth - 1);
			return o;
		}],
	]);
}

function mutateTransferData(rng, d, cats) {
	const pick = (pairs) => rng.weighted(pairs);
	if (rng.chance(0.5)) {
		d.counterparty = pick([
			[3, () => ({ number: spellNumber(rng, egNumber(rng)), name: 'Ahmed', handle: null })],
			[2, () => ({ number: maskNumber(rng, egNumber(rng)), handle: spellHandle(rng, genHandle(rng)), name: 'أحمد' })],
			[1, () => ({ number: genPayerRaw(rng)[1], handle: genPayerRaw(rng)[1] })],
			[1, null],
			[1, 'x'],
			[1, () => []],
			[1, () => ({})],
			[1, () => ({ number: 5 })],
		]);
	}
	if (rng.chance(0.4)) {
		const v = pick([[4, () => rng.int(0, 1e6)], [1, () => RAW('15007.0')], [1, () => RAW('1.5e4')], [1, () => RAW('150.5')], [1, '15007'], [1, null], [1, undefined], [1, () => RAW('-0')], [1, () => RAW(String(bigInt('9007199254740993')))]]);
		if (v === undefined) delete d.amount_minor;
		else d.amount_minor = v;
	}
	if (rng.chance(0.3)) d.received_at = pick([[3, () => isoOf(rng, BASE_NOW + rng.int(-1e5, 1e5))], [1, 5], [1, null]]);
	if (rng.chance(0.4)) {
		const v = pick([
			[4, () => rng.shuffle(['suspected_forgery', 'new_counterparty', 'no_baseline', 'amount_unusual', 'sim_mismatch']).slice(0, rng.int(0, 3))],
			[1, () => ['suspected_forgery', 5, null, ['x']]],
			[1, 'suspected_forgery'],
			[1, null],
			[1, () => ({})],
			[1, undefined],
		]);
		if (v === undefined) delete d.risk_reasons;
		else d.risk_reasons = v;
	}
	if (rng.chance(0.3)) d.checks = pick([[4, () => ({ ...(isRecord(d.checks) ? d.checks : {}), balance_chain: rng.pick(['refuted', 'consistent', 'unavailable', null, 5]) })], [1, () => []], [1, null], [1, 'x'], [1, () => ({})]]);
	if (rng.chance(0.3)) d.auto_confirm_ok = rng.pick([true, false, 'true', null, 1]);
	if (rng.chance(0.2)) d.risk = rng.pick(['low', 'high', 'medium', 5, null]);
	if (rng.chance(0.2)) d.provider_txn_id = rng.pick([rng.digits(12), 99409626954, null]);
	if (rng.chance(0.2)) d.balance_after = rng.pick(['3122.42', RAW('3122.42'), null]);
	for (const field of ['transfer_id', 'direction', 'status', 'amount', 'currency']) if (rng.chance(0.08)) d[field] = randomJsonValue(rng, 1);
	if (rng.chance(0.15)) d.extra = randomJsonValue(rng, 2);
	if (rng.chance(0.03)) {
		d.counterparty = { ...(isRecord(d.counterparty) ? d.counterparty : {}), name: 'Ahmed ' + BAD_TOKEN };
		cats.push('invalid-utf8');
	}
	if (rng.chance(0.02)) {
		d.counterparty = { ...(isRecord(d.counterparty) ? d.counterparty : {}), name: 'Ahmed ' + LONE_TOKEN };
		cats.push('lone-surrogate');
	}
}

function genEnvelopeObject(rng, cats) {
	const env = structuredClone(rng.pick(TEMPLATES));
	if (rng.chance(0.4)) env.event = rng.weighted([[5, 'transfer.received'], [3, 'transfer.updated'], [2, 'webhook.test'], [1, 'transfer.sent'], [1, 'transfer.failed'], [1, 'device.heartbeat'], [1, 'message.unparsed'], [1, () => rng.pick(['transfer.', 'TRANSFER.received', '', 'transfer', 'webhook.test.x', 'Webhook.test'])]]);
	if (env.event === 'webhook.test' && !isRecord(env.data.sample) && rng.chance(0.7)) env.data = { test: true, message: 'اختبار من قرش', sample: structuredClone(TEMPLATES[0].data) };
	if (env.event === 'transfer.updated' && rng.chance(0.5)) env.data = genUpdateData(rng)[1];
	if (isRecord(env.data) && rng.chance(0.6)) mutateTransferData(rng, isRecord(env.data.sample) && rng.chance(0.7) ? env.data.sample : env.data, cats);
	if (rng.chance(0.15)) env.test = rng.pick([true, false, 'true', 1, null]);
	if (rng.chance(0.1)) env.extra_field = randomJsonValue(rng, 2);
	if (rng.chance(0.15)) {
		cats.push('envelope-shape');
		const field = rng.pick(['schema', 'event', 'delivery_id', 'created_at', 'wallet', 'data']);
		const bad = {
			schema: () => rng.pick([undefined, 'qersh.v2', 'QERSH.V1', null, 1, 'qersh.v1 ']),
			event: () => rng.pick([undefined, 5, null, true, ['transfer.received']]),
			delivery_id: () => rng.pick([undefined, 5, null]),
			created_at: () => rng.pick([undefined, 1791725802000, null]),
			wallet: () => rng.pick([undefined, [], 'w', null, { id: 5, provider: 'x' }, { id: 'x' }, { provider: 'x' }, { id: 'x', provider: null }, ['x', 'y']]),
			data: () => rng.pick([undefined, [], null, 'x', 5, [1], {}]),
		}[field]();
		if (bad === undefined) delete env[field];
		else env[field] = bad;
	}
	if (rng.chance(0.02)) {
		// PHP decodes JSON objects into arrays: an object whose keys are 0..n-1 reads like a list there
		cats.push('json-shape');
		const where = isRecord(env.data) ? env.data : env;
		// risk_reasons as an object {"0": …} is left out: PHP reads it as the list it looks like, so it errs toward
		// flagging forgery where the core ignores a malformed field. The phone always sends a list.
		rng.pick([
			() => { where.counterparty = { 0: egNumber(rng) }; },
			() => { env.data = { 0: 'x' }; },
			() => { where.checks = { 0: 'refuted' }; },
		])();
	}
	if (rng.chance(0.01)) {
		cats.push('deep');
		let deep = 1;
		for (let i = rng.int(505, 515); i > 0; i--) deep = [deep];
		if (isRecord(env.data)) env.data.deep = deep;
	}
	return env;
}

function jsonString(rng, s, escape) {
	let out = '"';
	for (const ch of s) {
		const c = ch.codePointAt(0);
		if (ch === '"' || ch === '\\' || c < 0x20) out += JSON.stringify(ch).slice(1, -1);
		else if (escape && (c > 0x7e || rng.chance(0.03))) {
			for (let i = 0; i < ch.length; i++) out += '\\u' + ch.charCodeAt(i).toString(16).padStart(4, '0');
		} else out += ch;
	}
	return out + '"';
}

function jsonText(rng, v, style) {
	if (isMarker(v, '$raw')) return v.$raw;
	if (v === null || typeof v === 'boolean' || typeof v === 'number') return JSON.stringify(v);
	if (typeof v === 'string') return jsonString(rng, v, style.escape);
	const nl = style.pretty ? '\n' : '';
	const sp = style.pretty ? ' ' : '';
	if (Array.isArray(v)) return '[' + v.map((x) => jsonText(rng, x === undefined ? null : x, style)).join(',' + nl) + ']';
	return '{' + nl + Object.entries(v).filter(([, x]) => x !== undefined).map(([k, x]) => jsonString(rng, k, style.escape) + ':' + sp + jsonText(rng, x, style)).join(',' + nl) + nl + '}';
}

function genBodyBytes(rng) {
	const cats = [];
	const kind = rng.weighted([[85, 'envelope'], [5, 'non-object'], [4, 'broken'], [3, 'empty'], [3, 'bom']]);
	if (kind === 'non-object') return ['non-object', utf8(rng.pick(['[]', '1', '"x"', 'null', 'true', '[{"schema":"qersh.v1"}]', '{}', '{"schema":"qersh.v1"}']))];
	if (kind === 'empty') return ['empty', utf8(rng.pick(['', ' ', '\n', '\uFEFF', '\uFEFF\uFEFF']))];
	const env = genEnvelopeObject(rng, cats);
	const style = { pretty: rng.chance(0.2), escape: rng.chance(0.15) };
	let text = jsonText(rng, env, style);
	if (kind === 'broken') {
		cats.push('broken');
		text = rng.weighted([
			[3, () => text.slice(0, rng.int(0, text.length - 1))],
			[1, () => text + rng.pick(['x', ',', '}', ' 1'])],
			[1, () => text.replace(/,"/, ',,"')],
			[1, () => text.replace(/:/, ': NaN, "x":')],
			[1, () => '// comment\n' + text],
			[1, () => text.replace(/"/, "'")],
		]);
	}
	let bytes = Buffer.from(text, 'utf8');
	if (text.includes(BAD_TOKEN)) {
		const [a, b] = text.split(BAD_TOKEN);
		bytes = Buffer.concat([Buffer.from(a, 'utf8'), Buffer.from(rng.pick([[0xff], [0xc0, 0x80], [0xed, 0xa0, 0x80], [0xf0, 0x9f, 0x98], [0xe2, 0x82], [0xc3], [0xf8, 0x88, 0x80, 0x80, 0x80], [0xef, 0xbf]])), Buffer.from(b, 'utf8')]);
	}
	if (text.includes(LONE_TOKEN)) bytes = Buffer.from(text.replace(LONE_TOKEN, rng.pick(['\\ud800', '\\udfff', '\\ud83d', '\\ude00\\ud83d'])), 'utf8');
	if (kind === 'bom' || rng.chance(0.03)) {
		cats.push('bom');
		bytes = Buffer.concat([Buffer.from('\uFEFF'.repeat(rng.int(1, 3)), 'utf8'), bytes]);
	}
	if (rng.chance(0.05)) bytes = Buffer.concat([Buffer.from(rng.pick([' ', '\n', '\t', '\r\n']), 'utf8'), bytes, Buffer.from(rng.pick(['', ' ', '\n']), 'utf8')]);
	return [cats.length ? cats.join('+') : 'envelope', new Uint8Array(bytes)];
}

function genHeaderValue(rng, kind) {
	if (kind === 'attempt') return rng.pick(['1', '0', '2', '123456789', '1234567890', '01', ' 1', '1 ', '١', '-1', '+1', '1.0', '', '1\n', '999999999', '7']);
	if (kind === 'redelivery') return rng.pick(['manual', 'backfill', 'Manual', '', 'other', ' manual', 'backfill ']);
	if (kind === 'signature') {
		return rng.weighted([
			[3, () => genSignatureHeader(rng, { body: utf8('{}'), keys: [qskKey(rng)], now: BASE_NOW, tolerance: undefined })],
			[2, () => rng.pick(['t=123', ' t=123 ,v1=ab', 'x,t=123', 'v1=ab,t=0123', 't=12345678901234', 't=,', 'tt=5', 't=5x', '\u00A0t=5', 't=5\u3000,', ',t=7', 't=7,', 't = 7', 't=7 ,', 't=\u06607', 't=5x,t=6', 't=1234567890123', '\uFEFFt=9', 't=9\uFEFF', 't=9\n,v1=a', 't=\t9'])],
		]);
	}
	return rng.weighted([[5, () => uuidOf(rng)], [2, () => 'wlt_' + rng.str(POOL.alnum, 26)], [1, ''], [1, () => randomText(rng, 1, 20)]]);
}

function uuidOf(rng) {
	const h = rng.str('0123456789abcdef', 32);
	return `${h.slice(0, 8)}-${h.slice(8, 12)}-4${h.slice(13, 16)}-a${h.slice(17, 20)}-${h.slice(20)}`;
}

function genDeliveryHeaders(rng) {
	const h = {};
	const names = { delivery: 'x-qersh-delivery', attempt: 'x-qersh-attempt', redelivery: 'x-qersh-redelivery', wallet: 'x-qersh-wallet', signature: 'x-qersh-signature' };
	const title = (n) => n.replace(/(^|-)([a-z])/g, (_, a, b) => a + b.toUpperCase());
	for (const [kind, name] of Object.entries(names)) {
		if (rng.chance(0.2)) continue;
		const key = rng.weighted([[7, name], [2, () => title(name)], [1, () => name.toUpperCase()]]);
		let value = genHeaderValue(rng, kind);
		if (rng.chance(0.08)) value = [value, 'second'];
		if (rng.chance(0.03)) value = rng.pick([5, null, true, [], [5, 'x']]);
		h[key] = value;
		if (rng.chance(0.05)) h[rng.pick([title(name), name.toUpperCase(), name])] = genHeaderValue(rng, kind);
	}
	if (rng.chance(0.3)) h['content-type'] = 'application/json';
	return h;
}

// ---------------------------------------------------------------------------------------------------------------
// Suites

/** A short label of what the core answered, so the summary shows which branches the generated inputs reach. */
function outcomeOf(v) {
	if (v === null || v === undefined) return String(v);
	if (typeof v !== 'object') return typeof v === 'boolean' ? String(v) : typeof v;
	if (Array.isArray(v)) return v.length === 0 ? 'empty list' : 'list';
	if (typeof v.status === 'string') {
		const note = typeof v.note === 'string' ? ': ' + v.note.replace(/^\d+/, 'n').split(' ').slice(0, 3).join(' ') : '';
		return v.status + note + (v.stop_order ? ' (stop)' : '');
	}
	if (v.ok === true) return 'ok';
	if (v.ok === false) return String(v.reason ?? v.problem ?? v.error ?? 'refused');
	if (typeof v.event === 'string' && 'transfer' in v) return v.transfer ? 'summary with transfer' : 'summary';
	if ('t' in v && 'v1' in v) return 'parsed';
	return 'object';
}

const verifyResult = (r) => (r.ok ? { ok: true, t: r.t, key_index: r.keyIndex } : { ok: false, reason: r.reason, t: r.t });

const suites = [
	{
		name: 'payer.normalize',
		owner: 'skeleton',
		count: 8000,
		gen: (rng) => {
			const [cat, value] = genPayerRaw(rng);
			return { cat, args: [value] };
		},
		js: ([raw]) => core.normalizePayer(raw),
	},
	{
		name: 'payer.key_of',
		owner: 'skeleton',
		count: 3000,
		gen: (rng) => {
			const [cat, value] = genCounterparty(rng);
			return { cat, args: [value] };
		},
		js: ([cpv]) => core.payerKeyOf(cpv),
	},
	{
		name: 'time.iso_to_sec',
		owner: 'skeleton',
		count: 3000,
		gen: (rng) => ({ cat: 'iso', args: [genIsoText(rng)] }),
		js: ([s]) => {
			// Time::iso_to_sec() reads only the ECMAScript Date Time String Format with an explicit zone, and refuses on
			// purpose what V8 hands to its implementation-defined legacy parser (a space for T, day 00, month 13, …):
			// no sender of these values writes them, and the app writes toISOString(). Expect null there.
			if (!esFormatAccepted(s)) return null;
			const ms = Date.parse(s);
			return Number.isFinite(ms) ? Math.floor(ms / 1000) : null;
		},
	},
	{
		name: 'matcher.match',
		owner: 'A2',
		count: 12000,
		gen: (rng) => genMatchCase(rng, false),
		js: ([transfer, orders, nowMs, options]) => core.matchTransfer(transfer, orders, { ...options, now: nowMs }),
	},
	{
		name: 'matcher.match:options',
		op: 'matcher.match',
		owner: 'A2',
		count: 3000,
		gen: (rng) => genMatchCase(rng, true),
		js: ([transfer, orders, nowMs, options]) => core.matchTransfer(transfer, orders, { ...options, now: nowMs }),
	},
	{
		name: 'matcher.judge_update',
		owner: 'A2',
		count: 2000,
		gen: (rng) => {
			const [cat, data] = genUpdateData(rng);
			return { cat, args: [data] };
		},
		js: ([data]) => core.judgeUpdate(data),
	},
	{
		name: 'verifier.parse_header',
		owner: 'A1',
		count: 4000,
		gen: (rng) => {
			const body = genBody(rng);
			const header = genSignatureHeader(rng, { body, keys: genKeys(rng), now: BASE_NOW, tolerance: undefined });
			return { cat: typeof header === 'string' ? (header.length > 8000 ? 'long' : 'string') : 'non-string', args: [header] };
		},
		js: ([header]) => core.parseSignatureHeader(header),
	},
	{
		name: 'verifier.verify',
		owner: 'A1',
		count: 6000,
		fixed: () =>
			VECTORS.cases.map((c) => ({
				cat: `kit-vector:${c.name}`,
				args: [B(utf8(c.body)), c.headers['X-Qersh-Signature'] ?? null, c.secrets, c.now, VECTORS.tolerance_sec],
				expect: c.expect,
			})),
		gen: (rng) => {
			const now = BASE_NOW + rng.int(-1000000, 1000000);
			const keys = genKeys(rng);
			const body = genBody(rng);
			const tolerance = rng.chance(0.6) ? undefined : rng.pick([0, 1, 60, 300, 301, 3600, -1, -300, rng.int(0, 1000)]);
			const header = genSignatureHeader(rng, { body, keys, now, tolerance });
			const unavailable = rng.chance(0.04);
			const args = [unavailable ? null : B(body), header, keys, now];
			if (tolerance !== undefined) args.push(tolerance);
			return { cat: keys.length === 0 ? 'no-keys' : typeof header === 'string' ? 'header' : 'non-string-header', args };
		},
		js: ([body, header, keys, now, tolerance]) => verifyResult(core.verifySignature({ rawBody: body === null ? undefined : body, header, keys, now, toleranceSec: tolerance }, hmac)),
		fromPhp: (v) => (v && v.ok === true ? { ok: true, t: v.t, key_index: v.key_index } : v),
	},
	{
		name: 'verifier.sign_header',
		owner: 'A1',
		count: 800,
		gen: (rng) => ({ cat: 'sign', args: [BASE_NOW + rng.int(-1e6, 1e6), B(genBody(rng)), genKeys(rng)] }),
		js: ([t, body, keys]) => core.signatureHeader(t, body, keys, hmac),
	},
	{
		name: 'setup.validate_url',
		owner: 'A3',
		count: 6000,
		gen: (rng) => ({ cat: 'url', args: [genUrl(rng)] }),
		js: ([url]) => core.validateReceiverUrl(url),
	},
	{
		name: 'setup.is_local_http_host',
		owner: 'A3',
		count: 3000,
		gen: (rng) => ({ cat: 'host', args: [rng.chance(0.85) ? genHost(rng) : randomText(rng, 0, 20)] }),
		js: ([host]) => core.isLocalHttpHost(host),
	},
	{
		name: 'setup.is_usable_secret',
		owner: 'A3',
		count: 2000,
		gen: (rng) => ({ cat: 'secret', args: [genSecret(rng)] }),
		js: ([s]) => core.isUsableSecret(s),
	},
	{
		name: 'setup.validate',
		owner: 'A3',
		count: 3000,
		gen: (rng) => ({ cat: 'params', args: [genLinkParams(rng, rng.chance(0.5))] }),
		js: ([params]) => core.validateSetupLink(params),
	},
	{
		name: 'setup.build',
		owner: 'A3',
		count: 3000,
		gen: (rng) => ({ cat: 'params', args: [genLinkParams(rng, rng.chance(0.5))] }),
		js: ([params]) => {
			const { events, ...rest } = params; // PHP never writes events (docs/17 §4.1)
			return core.buildSetupLink(rest);
		},
	},
	{
		name: 'setup.parse',
		owner: 'A3',
		count: 4000,
		gen: (rng) => {
			const [cat, text] = genLinkText(rng);
			return { cat, args: [text] };
		},
		js: ([text]) => core.parseSetupLink(text),
	},
	{
		name: 'setup.extract',
		owner: 'A3',
		count: 4000,
		gen: (rng) => {
			const [cat, text] = genLinkText(rng);
			return { cat, args: [text] };
		},
		js: ([text]) => core.extractSetupLink(text),
	},
	{
		name: 'config.validate_receiver',
		owner: 'A3',
		count: 3000,
		gen: (rng) => {
			const [cat, r] = genReceiver(rng);
			return { cat, args: [r] };
		},
		js: ([r]) => coreReceiverIssues(r),
		fromPhp: (v) => receiverIssues(v),
	},
	{
		name: 'config.receiver_defaults',
		owner: 'A3',
		count: 2000,
		gen: (rng) => {
			const [cat, r] = genReceiver(rng);
			return { cat, args: [r] };
		},
		js: ([r]) => coreReceiverDefaults(r),
	},
	{
		name: 'config.is_acceptable_key',
		owner: 'A3',
		count: 2000,
		gen: (rng) => ({ cat: 'key', args: [rng.chance(0.5) ? genSecret(rng) : genKeys(rng)[0] ?? ''] }),
		js: ([k]) => core.isAcceptableKey(k),
	},
	{
		name: 'envelope.parse',
		owner: 'A1',
		count: 4000,
		gen: (rng) => {
			const [cat, bytes] = genBodyBytes(rng);
			return { cat, args: [rng.chance(0.01) ? null : B(bytes)] };
		},
		js: ([bytes]) => core.parseEnvelope(bytes),
	},
	{
		name: 'envelope.headers',
		owner: 'A1',
		count: 3000,
		gen: (rng) => ({ cat: 'headers', args: [genDeliveryHeaders(rng)] }),
		js: ([h]) => core.deliveryHeaders(h),
	},
	{
		name: 'envelope.summarize',
		owner: 'A1',
		count: 4000,
		gen: (rng) => {
			const [cat, bytes] = genBodyBytes(rng);
			const headers = rng.chance(0.85) ? genDeliveryHeaders(rng) : null;
			const url = rng.weighted([[5, 'https://shop.example/wp-json/qersh/v1/webhook/abc123def456'], [2, ''], [3, null]]);
			return { cat, args: [B(bytes), headers, url] };
		},
		js: ([bytes, headers, url]) => {
			const parsed = core.parseEnvelope(bytes);
			if (!parsed.ok) return parsed;
			return core.summarize(parsed.envelope, headers === null ? undefined : core.deliveryHeaders(headers), url ?? undefined);
		},
	},
];

// ---------------------------------------------------------------------------------------------------------------
// Run

const info = runPhp([{ op: 'info', args: [] }])[0];
if (!info || !('v' in info)) {
	console.error(`parity: cannot run PHP (${opts.php}): ${info && info.e ? info.e : 'no result'}`);
	process.exit(2);
}
const php = info.v;
INT32 = php.int_size === 4;
console.log(`parity: seed "${opts.seed}", scale ${opts.scale}; Node ${process.version}; PHP ${php.php} (${php.int_size * 8}-bit, mbstring ${php.mbstring ? 'on' : 'off'}, intl ${php.intl ? 'on, ICU ' + php.icu : 'off'})`);

const selected = suites.filter((s) => !opts.suite || s.name.includes(opts.suite));
if (selected.length === 0) {
	console.error(`parity: no suite matches "${opts.suite}"; suites: ${suites.map((s) => s.name).join(', ')}`);
	process.exit(2);
}

const mismatches = [];
const rows = [];
const started = Date.now();
for (const suite of selected) {
	const rng = new Rng(`${opts.seed}/${suite.name}`);
	const cases = suite.fixed ? suite.fixed() : [];
	const n = Math.max(1, Math.round(suite.count * opts.scale));
	for (let i = 0; i < n; i++) cases.push(suite.gen(rng, i));
	const op = suite.op || suite.name;
	const results = [];
	for (let i = 0; i < cases.length; i += opts.batch) {
		results.push(...runPhp(cases.slice(i, i + opts.batch).map((c) => ({ op, args: forPhp(c.args) }))));
	}
	let bad = 0;
	let phpErrors = 0;
	const cats = new Map();
	const outcomes = new Map();
	cases.forEach((c, i) => {
		const jsArgs = forJs(c.args);
		let jsValue;
		try {
			jsValue = suite.js(jsArgs, c);
		} catch (e) {
			jsValue = { $coreThrew: String(e && e.message ? e.message : e) };
		}
		const outcome = outcomeOf(jsValue);
		outcomes.set(outcome, (outcomes.get(outcome) || 0) + 1);
		if (c.expect !== undefined && (jsValue.ok === true) !== (c.expect === 'accept')) console.warn(`parity: the core disagrees with the kit on ${c.cat} (expected ${c.expect})`);
		const r = results[i];
		const left = canon(jsValue);
		let right;
		let error = null;
		if (r && 'v' in r) right = canon(suite.fromPhp ? suite.fromPhp(r.v, jsArgs, c) : r.v);
		else error = r && r.e ? r.e : 'no result';
		const ok = error === null && same(left, right);
		const cat = cats.get(c.cat) || { n: 0, bad: 0 };
		cat.n++;
		if (!ok) {
			bad++;
			cat.bad++;
			if (error !== null) phpErrors++;
			mismatches.push({ suite: suite.name, owner: suite.owner, index: i, cat: c.cat, args: forPhp(c.args), core: left, php: error === null ? right : { $phpError: error }, diff: error === null ? diffPaths(left, right) : [`php error: ${error}`] });
		}
		cats.set(c.cat, cat);
	});
	rows.push({ suite: suite.name, owner: suite.owner, n: cases.length, bad, phpErrors, cats, outcomes });
}

// ---------------------------------------------------------------------------------------------------------------
// Report

const total = rows.reduce((a, r) => a + r.n, 0);
const totalBad = rows.reduce((a, r) => a + r.bad, 0);
const width = Math.max(...rows.map((r) => r.suite.length));
console.log('');
for (const r of rows) {
	const status = r.bad === 0 ? 'ok' : `${r.bad} mismatch(es)${r.phpErrors ? `, ${r.phpErrors} PHP error(s)` : ''}`;
	console.log(`  ${r.suite.padEnd(width)}  ${String(r.n).padStart(6)} cases  ${status}${r.bad ? `  [owner ${r.owner}]` : ''}`);
	const reached = [...r.outcomes.entries()].sort((a, b) => b[1] - a[1]).map(([k, n]) => `${k} ${n}`);
	console.log(`  ${''.padEnd(width)}  core outcomes: ${reached.slice(0, 12).join(', ')}${reached.length > 12 ? `, … (${reached.length} kinds)` : ''}`);
	if (r.bad) {
		const parts = [...r.cats.entries()].filter(([, c]) => c.bad).sort((a, b) => b[1].bad - a[1].bad).map(([k, c]) => `${k} ${c.bad}/${c.n}`);
		console.log(`  ${''.padEnd(width)}  by category: ${parts.slice(0, 8).join(', ')}${parts.length > 8 ? ', …' : ''}`);
	}
}

if (opts.all && mismatches.length) {
	console.log('');
	for (const m of mismatches) console.log(JSON.stringify({ suite: m.suite, cat: m.cat, index: m.index, input: m.args, core: m.core, php: m.php }));
}

if (mismatches.length) {
	console.log('');
	// One per suite in turn, and inside a suite one per input category in turn, so every kind shows up early.
	const bySuite = new Map();
	for (const m of mismatches) {
		if (!bySuite.has(m.suite)) bySuite.set(m.suite, new Map());
		const cats = bySuite.get(m.suite);
		if (!cats.has(m.cat)) cats.set(m.cat, []);
		cats.get(m.cat).push(m);
	}
	const queues = [...bySuite.values()].map((cats) => {
		const lists = [...cats.values()];
		const out = [];
		for (let i = 0, more = true; more; i++) {
			more = false;
			for (const list of lists) {
				if (i < list.length) {
					out.push(list[i]);
					more = true;
				}
			}
		}
		return out;
	});
	const picked = [];
	while (picked.length < opts.show && queues.some((q) => q.length)) {
		for (const q of queues) if (q.length && picked.length < opts.show) picked.push(q.shift());
	}
	console.log(`First mismatches (${picked.length} of ${mismatches.length}, taken in turn from each suite and input category):`);
	let printed = 0;
	for (const m of picked) {
		printed++;
		console.log('');
		console.log(`#${printed} ${m.suite} [${m.cat}] case ${m.index} (owner ${m.owner})`);
		console.log(`   input: ${short(m.args, 700)}`);
		console.log(`   core:  ${short(m.core, 400)}`);
		console.log(`   php:   ${short(m.php, 400)}`);
		for (const d of m.diff) console.log(`   diff   ${d}`);
	}
}

console.log('');
const seconds = ((Date.now() - started) / 1000).toFixed(1);
if (totalBad === 0) {
	console.log(`parity: ok, ${total} cases in ${rows.length} suite(s) agree (${seconds} s)`);
	process.exit(0);
}
console.log(`parity: FAILED, ${totalBad} of ${total} cases differ in ${rows.filter((r) => r.bad).length} suite(s) (${seconds} s); rerun one with --suite=<name> --seed="${opts.seed}"`);
process.exit(1);
