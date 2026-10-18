// The JavaScript normaliser of the order page (assets/js/txn-normalize.js) against the vectors Core\TxnId reads
// (tests/vectors/txn-normalize.json: canonicalTyped() and longRun() against each vector's `expect` and `long_run`), the
// shapes of fixtures/txn-patterns.json, and the core's normalizePayer.
// Then hasRtl() (Format::has_rtl(); FormatTest checks that both tables hold the same code points), and the two places
// of the order page (assets/js/thankyou.js) that use the normaliser for display, in a small stand-in for the DOM: the
// deadline's direction, and the "We will send: …" preview of the transaction id. Then the live box of thankyou.js
// against a fake clock and status route, and the classic payer field (assets/js/payer-field.js), in a second
// stand-in with parents, siblings and the few selectors those scripts use.
// Run: node --test tests/js/txn-normalize.test.mjs (Node 24 refuses a directory argument).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import { normalizePayer as coreNormalizePayer } from '../parity/core/payer.js';

const require = createRequire(import.meta.url);
const N = require('../../assets/js/txn-normalize.js');
const read = (rel) => JSON.parse(readFileSync(new URL(rel, import.meta.url), 'utf8'));

const vectors = read('../vectors/txn-normalize.json');

test('the vector file is not empty', () => {
	assert.ok(Array.isArray(vectors) && vectors.length > 0);
});

for (const v of vectors) {
	test(`canonicalTyped: ${v.name}`, () => {
		assert.deepEqual(N.canonicalTyped(v.provider, v.raw, v.payer_key, v.store_numbers), v.expect);
	});
	test(`longRun: ${v.name}`, () => {
		assert.equal(N.longRun(v.provider, v.raw), v.long_run);
	});
}

test('canonicalTyped and longRun give the same answers with the shapes the page passes from the server', () => {
	const shapes = {};
	for (const [provider, spec] of Object.entries(read('../../fixtures/txn-patterns.json').providers)) {
		shapes[provider] = { pattern: spec.pattern, lengths: spec.lengths, cls: spec.class };
	}
	for (const v of vectors) {
		assert.deepEqual(N.canonicalTyped(v.provider, v.raw, v.payer_key, v.store_numbers, shapes), v.expect, v.name);
		assert.equal(N.longRun(v.provider, v.raw, shapes), v.long_run, v.name);
	}
});

test('longRun outside the vectors, as TxnIdTest reads them', () => {
	assert.equal(N.longRun('orange_cash', null), null);
	assert.equal(N.longRun('orange_cash', 250072940674051), null, 'a JSON number is never read');
	assert.equal(N.longRun('orange_cash', ''), null);
	assert.equal(N.longRun('orange_cash', ' '.repeat(386) + '250072940674051'), null, '401 characters');
	assert.equal(N.longRun('orange_cash', ' '.repeat(385) + '250072940674051'), '250072940674051');
	assert.equal(N.longRun('orange_cash', '25007294067405'), null, 'the shape itself');
	assert.equal(N.longRun('orange_cash', '250072940674051 و 2500729406740512'), '250072940674051');
	assert.equal(N.longRun('orange_cash', 'رقم 2500517294-418312'), '2500517294418312');
	assert.equal(N.longRun('orange_cash', '100\n250072940674051'), '250072940674051');
	assert.equal(N.longRun('orange_cash', '250072940674051-25007294067405123'), '250072940674051');
});

test('the built-in shapes equal fixtures/txn-patterns.json', () => {
	const fixture = read('../../fixtures/txn-patterns.json').providers;
	assert.deepEqual(Object.keys(N.SHAPES).sort(), Object.keys(fixture).sort());
	for (const [provider, spec] of Object.entries(fixture)) {
		assert.equal(N.SHAPES[provider].pattern, spec.pattern, provider);
		assert.deepEqual(N.SHAPES[provider].lengths, spec.lengths, provider);
		assert.equal(N.SHAPES[provider].cls, spec.class, provider);
	}
});

test('normalizePayer equals the core normalizePayer', () => {
	const inputs = [
		'01001234567', '+20 100 123 4567', '00201001234567', '201001234567', '1001234567', ' 0100 123 4567 ',
		'٠١٠٠١٢٣٤٥٦٧', '۰۱۰۰۱۲۳۴۵۶۷', '0111***3445', 'Ahmed@InstaPay', 'ahmed', 'a', 'ahmed@gmail.com', '',
		null, undefined, '   ', '0100-123-4567', '+2001001234567', '12345', 'name.with_dots-1', '0020 01001234567',
		'01001234567x', 'abc01001234567', ' 01001234567 ', '﻿01001234567',
	];
	for (const raw of inputs) {
		assert.equal(N.normalizePayer(raw), coreNormalizePayer(raw), JSON.stringify(raw));
	}
});

test('checkPayer: numbers, InstaPay addresses and the store number by its last nine digits', () => {
	const store = ['01212345678', 'shop@instapay'];
	assert.deepEqual(N.checkPayer('٠١٠٠١٢٣٤٥٦٧', false, store), { ok: true, payer: '01001234567' });
	assert.deepEqual(N.checkPayer('+20 100 123 4567', false, store), { ok: true, payer: '01001234567' });
	assert.deepEqual(N.checkPayer('ahmed@instapay', false, store), { ok: false, error: 'payer_invalid' });
	assert.deepEqual(N.checkPayer('ahmed@instapay', true, store), { ok: true, payer: 'ahmed@instapay' });
	assert.deepEqual(N.checkPayer('shop@instapay', true, store), { ok: false, error: 'payer_store' });
	assert.deepEqual(N.checkPayer('01212345678', false, store), { ok: false, error: 'payer_store' });
	assert.deepEqual(N.checkPayer('+20 121 234 5678', false, store), { ok: false, error: 'payer_store' });
	assert.deepEqual(N.checkPayer('01512345678', false, store), { ok: true, payer: '01512345678' });
	assert.deepEqual(N.checkPayer('01001234567', false, ['1001234567']), { ok: false, error: 'payer_store' });
	assert.deepEqual(N.checkPayer('0101234567', false, store), { ok: false, error: 'payer_invalid' });
	assert.deepEqual(N.checkPayer('', false, store), { ok: false, error: 'payer_invalid' });
});

// The file as a browser runs it: a plain script in a window.
function runInWindow(extra) {
	const ctx = vm.createContext(Object.assign({}, extra));
	ctx.window = ctx;
	ctx.self = ctx;
	vm.runInContext(readFileSync(new URL('../../assets/js/txn-normalize.js', import.meta.url), 'utf8'), ctx);
	return ctx;
}

test('in a browser the global is set', () => {
	const ctx = runInWindow({});
	assert.equal(typeof ctx.qershWcNormalize, 'object');
	assert.equal(typeof ctx.qershWcNormalize.canonicalTyped, 'function');
});

test('a global `module` leaked by another script neither hides the global nor is overwritten', () => {
	const leaked = { exports: { other: 1 } };
	const ctx = runInWindow({ module: leaked });
	assert.equal(typeof ctx.qershWcNormalize, 'object');
	assert.equal(typeof ctx.qershWcNormalize.normalizePayer, 'function');
	assert.deepEqual(ctx.module.exports, { other: 1 });
});

test('hasRtl: Arabic and Hebrew letters count; digits of any script, Latin text and symbols do not', () => {
	for (const text of ['م', '4:35 ص', '18 أكتوبر في 4:35 م', 'October 18 بتوقيت القاهرة', 'שלום', 'ﺍ', 'ﭐ', 'ج.م']) {
		assert.equal(N.hasRtl(text), true, text);
	}
	for (const text of ['', '0100 123 4567', '٠١٠٠١٢٣٤٥٦٧', '۰۱۲', '؀', '٫', '،', 'ً', '﻿', '0111•••445', 'name@instapay', 'October 18, 2026 12:38 am', 'EGP', null, undefined, 42]) {
		assert.equal(N.hasRtl(text), false, JSON.stringify(text));
	}
	// The pattern keeps no state between calls (a global RegExp's test() would).
	assert.deepEqual([N.hasRtl('م'), N.hasRtl('م'), N.hasRtl('م')], [true, true, true]);
});

// A small stand-in for the DOM of the thank-you box: elements with attributes, children, listeners, and the
// attribute selectors thankyou.js uses.
function element(tag, attrs = {}) {
	return {
		tagName: tag.toUpperCase(),
		attrs: { ...attrs },
		children: [],
		listeners: {},
		hidden: false,
		className: '',
		disabled: false,
		value: '',
		selectionStart: 0,
		selectionEnd: 0,
		text: '',
		getAttribute(name) { return name in this.attrs ? this.attrs[name] : null; },
		setAttribute(name, value) { this.attrs[name] = String(value); },
		removeAttribute(name) { delete this.attrs[name]; },
		appendChild(child) { this.children.push(child); return child; },
		addEventListener(type, fn) { (this.listeners[type] ||= []).push(fn); },
		setSelectionRange() {},
		get textContent() { return this.text + this.children.map((c) => c.textContent).join(''); },
		set textContent(value) { this.text = String(value); this.children = []; },
		querySelectorAll(selector) {
			const names = selector.split(',').map((s) => s.trim().replace(/^\[|\]$/g, ''));
			const out = [];
			const walk = (node) => {
				for (const child of node.children || []) {
					if (child.attrs && names.some((n) => n in child.attrs)) out.push(child);
					walk(child);
				}
			};
			walk(this);
			return out;
		},
		querySelector(selector) { return this.querySelectorAll(selector)[0] || null; },
	};
}

const DEADLINE = 1792330500; // 2026-10-18 13:35 UTC, an afternoon time in Cairo.

function thankyouPage(lang) {
	const box = element('section', { 'data-qersh-box': '' });
	const time = box.appendChild(element('bdi', { 'data-qersh-time': String(DEADLINE), dir: 'ltr' }));
	box.appendChild(element('p', { 'data-qersh-status': '' }));
	box.appendChild(element('p', { 'data-qersh-nudge': '' }));
	const form = box.appendChild(element('div', { 'data-qersh-txn-form': '' }));
	const input = form.appendChild(element('input', { id: 'qersh_txn' }));
	form.appendChild(element('button', { 'data-qersh-txn-send': '' }));
	const msg = form.appendChild(element('span', { 'data-qersh-txn-msg': '' }));
	box.attrs['data-qersh'] = JSON.stringify({
		order_id: 7,
		key: 'wc_order_x',
		now: DEADLINE - 3600,
		placed_at: DEADLINE - 3600,
		nudge_at: DEADLINE + 86400,
		stop_at: DEADLINE + 7200,
		fast: 15,
		slow: 60,
		fast_for: 1200,
		status: 'waiting',
		orange: true,
		txn_open: true,
		claim: false,
		urls: {},
		lines: { waiting: 'Waiting for your transfer…' },
		strings: { will_send: 'We will send: %s', txn_errors: {} },
		txn: { provider: 'orange_cash', payer_key: '01001234567', store_numbers: ['01212345678'] },
	});
	const doc = {
		hidden: false,
		documentElement: element('html', { lang }),
		querySelector: (selector) => (selector === '[data-qersh-box]' ? box : null),
		getElementById: (id) => (id === 'qersh_txn' ? input : null),
		createElement: (tag) => element(tag),
		createTextNode: (text) => ({ textContent: String(text) }),
		addEventListener() {},
	};
	const ctx = vm.createContext({
		document: doc,
		Intl,
		qershWcNormalize: N,
		setTimeout: () => 0,
		clearTimeout() {},
		sessionStorage: { getItem: () => null, setItem() {} },
		navigator: {},
	});
	ctx.window = ctx;
	vm.runInContext(readFileSync(new URL('../../assets/js/thankyou.js', import.meta.url), 'utf8'), ctx);
	return { time, input, msg };
}

test('thankyou.js: a deadline written with Arabic words is isolated by its first strong letter, an English one left to right', () => {
	const ar = thankyouPage('ar').time;
	assert.match(ar.textContent, /[صم]/, ar.textContent);
	assert.equal(ar.getAttribute('dir'), 'auto', ar.textContent);
	const en = thankyouPage('en-US').time;
	assert.match(en.textContent, /PM/, en.textContent);
	assert.equal(en.getAttribute('dir'), 'ltr', en.textContent);
});

test('thankyou.js: a pasted message shows the id that will be sent, isolated left to right; the id as typed shows nothing', () => {
	const message = vectors.find((v) => v.name === 'advert block before C1');
	assert.ok(message && message.expect.ok, 'the vector of a whole message');
	const page = thankyouPage('ar');
	page.input.listeners.paste[0]({ clipboardData: { getData: () => message.raw }, preventDefault() {} });
	assert.equal(page.msg.textContent, `We will send: ${message.expect.txn}`);
	const id = page.msg.children.find((c) => c.tagName === 'BDI');
	assert.ok(id, 'the id is in its own element');
	assert.equal(id.getAttribute('dir'), 'ltr');
	assert.equal(id.textContent, message.expect.txn);

	page.input.value = message.expect.txn;
	page.input.listeners.input[0]();
	assert.equal(page.msg.textContent, '', 'nothing to preview for the id itself');

	page.input.value = '2500729406';
	page.input.listeners.input[0]();
	assert.equal(page.msg.textContent, '', 'nothing while it is typed');

	page.input.value = `  ${message.expect.txn.slice(0, 4)} ${message.expect.txn.slice(4)}`;
	page.input.listeners.input[0]();
	assert.equal(page.msg.textContent, `We will send: ${message.expect.txn}`);

	page.input.value = 'abc';
	page.input.listeners.input[0]();
	assert.equal(page.msg.textContent, '', 'no error before sending');
});

// ---- A second stand-in for the DOM: parents, siblings, text nodes, focus, and the compound selectors thankyou.js and
// payer-field.js use (tag, .class, [attr], [attr="value"], :checked, and lists of them).

function dom() {
	const byId = new Map();
	const doc = {
		hidden: false,
		readyState: 'complete',
		activeElement: null,
		listeners: {},
		addEventListener(type, fn) { (doc.listeners[type] ||= []).push(fn); },
		fire(type, event) { for (const fn of doc.listeners[type] || []) fn(event); },
		getElementById: (id) => byId.get(id) || null,
		createElement: (tag) => el(tag),
		createTextNode: (s) => text(s),
		querySelector: (sel) => doc.body.querySelector(sel),
		querySelectorAll: (sel) => doc.body.querySelectorAll(sel),
		execCommand: () => true,
	};
	function text(s) {
		return { nodeType: 3, parentNode: null, text: String(s), get textContent() { return this.text; } };
	}
	function matches(n, sel) {
		const m = /^([a-z]+)?((?:\.[\w-]+)*)((?:\[[\w-]+(?:="[^"]*")?\])*)(:checked)?$/.exec(sel.trim());
		if (!m) throw new Error(`selector not supported here: ${sel}`);
		if (m[1] && n.tagName !== m[1].toUpperCase()) return false;
		for (const c of m[2].split('.').filter(Boolean)) if (!n.classList.contains(c)) return false;
		for (const a of m[3].match(/\[[^\]]+\]/g) || []) {
			const [, name, value] = /^\[([\w-]+)(?:="([^"]*)")?\]$/.exec(a);
			if (!(name in n.attrs) || (value !== undefined && n.attrs[name] !== value)) return false;
		}
		return !m[4] || n.checked;
	}
	function el(tag, attrs = {}, ...kids) {
		const n = {
			nodeType: 1,
			tagName: tag.toUpperCase(),
			attrs: {},
			children: [],
			parentNode: null,
			listeners: {},
			text: '',
			writes: 0,
			hidden: false,
			checked: false,
			disabled: false,
			value: '',
			type: '',
			className: '',
			style: {},
			classList: {
				contains: (c) => n.className.split(/\s+/).includes(c),
				toggle(c, on) {
					const list = n.className.split(/\s+/).filter((x) => x && x !== c);
					if (on) list.push(c);
					n.className = list.join(' ');
				},
			},
			getAttribute: (k) => (k in n.attrs ? n.attrs[k] : null),
			setAttribute(k, v) {
				n.attrs[k] = String(v);
				if (k === 'id') byId.set(String(v), n);
				if (k === 'id' || k === 'name') n[k] = String(v);
			},
			removeAttribute(k) { delete n.attrs[k]; },
			appendChild: (c) => n.insertBefore(c, null),
			insertBefore(c, ref) {
				if (c.parentNode) c.parentNode.removeChild(c);
				c.parentNode = n;
				const i = ref ? n.children.indexOf(ref) : -1;
				if (i < 0) n.children.push(c);
				else n.children.splice(i, 0, c);
				return c;
			},
			removeChild(c) {
				n.children.splice(n.children.indexOf(c), 1);
				c.parentNode = null;
				return c;
			},
			get nextSibling() {
				const p = n.parentNode;
				return p ? p.children[p.children.indexOf(n) + 1] || null : null;
			},
			get textContent() { return n.text + n.children.map((c) => c.textContent).join(''); },
			set textContent(v) {
				n.writes++;
				for (const c of n.children) c.parentNode = null;
				n.children = [];
				n.text = String(v);
			},
			addEventListener(type, fn) { (n.listeners[type] ||= []).push(fn); },
			querySelectorAll(sel) {
				const parts = sel.split(',');
				const out = [];
				const walk = (x) => {
					for (const c of x.children) {
						if (c.nodeType !== 1) continue;
						if (parts.some((p) => matches(c, p))) out.push(c);
						walk(c);
					}
				};
				walk(n);
				return out;
			},
			querySelector: (sel) => n.querySelectorAll(sel)[0] || null,
			focus() { doc.activeElement = n; },
			select() {},
		};
		for (const [k, v] of Object.entries(attrs)) {
			if (k === 'class') n.className = v;
			else if (k === 'hidden') n.hidden = !!v;
			else if (k === 'checked') n.checked = !!v;
			else if (k === 'value') n.value = v;
			else {
				n.setAttribute(k, v);
				if (k === 'type') n.type = v;
			}
		}
		for (const k of kids) n.appendChild(typeof k === 'string' ? text(k) : k);
		return n;
	}
	doc.body = el('body');
	doc.documentElement = el('html', { lang: 'en-US' });
	return { doc, el };
}

// ---- thankyou.js: the live box against a fake clock, fake timers and a fake status route.

const T0 = 1792330500 - 7200;
const LINES = {
	waiting: 'Waiting for your transfer…',
	waiting_decision: 'Waiting for the store to confirm that your transfer arrived…',
	saved: 'We have your transaction id. Your order is confirmed once the store matches your transfer; do not transfer again.',
	paid: 'Your transfer arrived and your order is confirmed.',
	partial: 'Part of the amount arrived. Send the rest from the same number before the deadline.',
	review: 'We are checking your transfer and will let you know.',
	expired: 'The transfer window has ended.',
	cancelled: 'This order was cancelled.',
};
const STRINGS = {
	copy: 'Copy',
	copied: 'Copied',
	reload_rest: 'Reload this page to see the amount that remains.',
	before: 'Before: %s Cairo time',
	nudge_other: 'Transferred from another number? Contact the store with your order number so it can attach your transfer.',
	nudge_txn: 'Transferred? Enter the transaction id above so the store finds your transfer.',
	txn_errors: {},
	will_send: 'We will send: %s',
};

// What Checkout::thankyou_script_data() prints: TTL 120 min, late window 120 min, polling 20 minutes past it.
function cfgOf(extra = {}) {
	return {
		order_id: 9,
		key: 'wc_order_k',
		now: T0,
		placed_at: T0,
		deadline: T0 + 7200,
		window_end: T0 + 14400,
		stop_at: T0 + 14400 + 1200,
		fast: 15,
		slow: 60,
		fast_for: 1200,
		nudge_at: T0 + 900,
		status: 'waiting',
		decision: false,
		orange: false,
		txn_open: false,
		claim: false,
		urls: { status: '/status', txn: '/txn', status_fallback: '/status-fb', txn_fallback: '/txn-fb' },
		lines: LINES,
		strings: STRINGS,
		txn: { provider: 'vodafone_cash', payer_key: '01112223334', store_numbers: [] },
		...extra,
	};
}

// The box as templates/order/thankyou-box.php draws it for a waiting order, and the script running on it.
function livePage({ cfg, answers, stored = {} }) {
	const { doc, el } = dom();
	let clock = cfg.now;
	let seq = 0;
	const timers = [];
	const calls = [];

	const amount = el('li', { class: 'qersh-line qersh-line--amount', 'data-qersh-amount': '' }, 'Amount: EGP 300 ', el('button', { class: 'qersh-copy', 'data-qersh-copy': '300', 'aria-label': 'Copy the amount', hidden: true }, 'Copy'));
	const to = el('li', { class: 'qersh-line qersh-line--to' }, 'Send to: ', el('strong', {}, el('bdi', { dir: 'ltr' }, '0100 123 4567')), el('button', { class: 'qersh-copy', 'data-qersh-copy': '01001234567', hidden: true }, 'Copy'));
	const time = el('bdi', { dir: 'ltr', 'data-qersh-time': String(cfg.deadline) }, '4:35 PM');
	const before = el('li', { class: 'qersh-line qersh-line--before', 'data-qersh-left': '' }, 'Before: ', el('strong', {}, time), ' Cairo time (within 2 hours)');
	const list = el('ul', { 'data-qersh-pending': '' }, amount, to, before);
	const confirm = el('p', { class: 'qersh-confirm', 'data-qersh-pending': '' }, 'Your order is confirmed on its own when your transfer arrives, usually within minutes.');
	const input = el('input', { id: 'qersh_txn', type: 'text' });
	const txn = cfg.orange ? el('section', { 'data-qersh-txn': '' }, el('div', { 'data-qersh-txn-form': '', hidden: true }, input, el('button', { 'data-qersh-txn-send': '' }), el('span', { 'data-qersh-txn-msg': '' }))) : null;
	const status = el('p', { 'data-qersh-status': '' }, LINES.waiting);
	const nudge = el('p', { 'data-qersh-nudge': '', hidden: true });
	const said = el('span', { class: 'qersh-sr', 'data-qersh-copy-msg': '' });
	const box = el('section', { 'data-qersh-box': '', 'data-qersh': JSON.stringify(cfg) }, list, confirm, ...(txn ? [txn] : []), status, nudge, said);
	doc.body.appendChild(box);
	status.writes = 0;

	const location = { reloads: 0, reload() { this.reloads++; } };
	const ctx = vm.createContext({
		document: doc,
		Intl,
		Date: class extends Date {
			constructor(...a) {
				if (a.length) super(...a);
				else super(clock * 1000);
			}
			static now() { return Math.floor(clock * 1000); }
		},
		qershWcNormalize: N,
		setTimeout: (fn, ms) => {
			timers.push({ id: ++seq, at: clock + (ms || 0) / 1000, fn });
			return seq;
		},
		clearTimeout: (id) => {
			const i = timers.findIndex((t) => t.id === id);
			if (i >= 0) timers.splice(i, 1);
		},
		sessionStorage: { getItem: (k) => (k in stored ? stored[k] : null), setItem: (k, v) => { stored[k] = String(v); } },
		location,
		navigator: {},
		isSecureContext: false,
		fetch: (url, init) => {
			calls.push({ url, body: JSON.parse(init.body) });
			const json = answers(clock, calls.length);
			return Promise.resolve({
				status: 200,
				headers: { get: (h) => (h.toLowerCase() === 'content-type' ? 'application/json' : null) },
				json: () => Promise.resolve(json),
			});
		},
	});
	ctx.window = ctx;
	vm.runInContext(readFileSync(new URL('../../assets/js/thankyou.js', import.meta.url), 'utf8'), ctx);

	return {
		amount, to, before, time, list, confirm, txn, input, status, nudge, said, location, calls, stored,
		// Runs the timers due within `sec` seconds, letting each fetch answer before the next one.
		async advance(sec) {
			const end = clock + sec;
			for (;;) {
				timers.sort((a, b) => a.at - b.at || a.id - b.id);
				const t = timers[0];
				if (!t || t.at > end) break;
				timers.shift();
				clock = Math.max(clock, t.at);
				t.fn();
				for (let i = 0; i < 6; i++) await new Promise((r) => setImmediate(r));
			}
			clock = end;
		},
		async visible() {
			doc.hidden = false;
			doc.fire('visibilitychange');
			for (let i = 0; i < 6; i++) await new Promise((r) => setImmediate(r));
		},
	};
}

test('thankyou.js: a change to partial reloads the page once, so the server shows the amount that remains', async () => {
	const p = livePage({ cfg: cfgOf(), answers: () => ({ status: 'partial' }) });
	await p.advance(16);
	assert.equal(p.location.reloads, 1);
	assert.equal(p.stored.qersh_wc_reloaded_9, 'partial', 'remembered, so a cached page cannot loop');
	const polls = p.calls.length;
	await p.advance(300);
	assert.equal(p.calls.length, polls, 'no polling while the page reloads');
});

test('thankyou.js: partial without a reload: the whole-order amount and its copy button give way to a line to reload', async () => {
	const p = livePage({ cfg: cfgOf(), answers: () => ({ status: 'partial' }), stored: { qersh_wc_reloaded_9: 'partial' } });
	await p.advance(16);
	assert.equal(p.location.reloads, 0, 'reloaded once already');
	assert.equal(p.amount.hidden, true);
	assert.equal(p.amount.nextSibling.textContent, STRINGS.reload_rest);
	assert.equal(p.list.hidden, false, 'the number to send to and the deadline stay');
	assert.equal(p.status.textContent, LINES.partial);
	await p.advance(120);
	assert.equal(p.list.children.filter((c) => c.textContent === STRINGS.reload_rest).length, 1, 'one line, not one per poll');
});

test('thankyou.js: no reload while the customer has an unsent transaction id in the field', async () => {
	const p = livePage({ cfg: cfgOf({ orange: true, txn_open: true }), answers: () => ({ status: 'partial', claim: false }) });
	p.input.value = '2500023';
	await p.advance(16);
	assert.equal(p.location.reloads, 0);
	assert.equal(p.amount.hidden, true);
});

test('thankyou.js: review and expired hide the transfer lines; review also hides the transaction-id section', async () => {
	for (const [next, txnHidden] of [['review', true], ['expired', false], ['cancelled', true], ['paid', true]]) {
		const p = livePage({ cfg: cfgOf({ orange: true, txn_open: true }), answers: () => ({ status: next, claim: false }) });
		await p.advance(16);
		assert.equal(p.status.textContent.replace(/ ✓$/, ''), LINES[next], next);
		assert.equal(p.list.hidden, true, `${next}: no amount or number to send to`);
		assert.equal(p.confirm.hidden, true, next);
		assert.equal(p.txn.hidden, txnHidden, `${next}: the transaction-id section (Claims::accepts())`);
	}
	const p = livePage({ cfg: cfgOf(), answers: () => ({ status: 'waiting' }) });
	await p.advance(16);
	assert.equal(p.list.hidden, false);
});

test('thankyou.js: the live region is written only when the status or the claim changes (Orange answers carry claim)', async () => {
	let answer = { status: 'waiting', claim: false };
	const p = livePage({ cfg: cfgOf({ orange: true, txn_open: true }), answers: () => answer });
	await p.advance(15 * 5 + 1);
	assert.ok(p.calls.length >= 4, `polled ${p.calls.length} times`);
	assert.equal(p.status.writes, 0, 'the same answer again writes nothing');
	answer = { status: 'waiting', claim: true };
	await p.advance(15);
	assert.equal(p.status.writes, 1);
	assert.equal(p.status.textContent, LINES.saved);
	await p.advance(45);
	assert.equal(p.status.writes, 1);
	answer = { status: 'paid', claim: true };
	await p.advance(15);
	assert.equal(p.status.writes, 2);
	const polls = p.calls.length;
	await p.advance(600);
	assert.equal(p.calls.length, polls, 'paid stops the polling');
});

test('thankyou.js: the polling goes on past the window and shows the expiry that runs a little after it', async () => {
	const c = cfgOf();
	const p = livePage({ cfg: c, answers: (now) => ({ status: now > c.window_end + 90 ? 'expired' : 'waiting' }) });
	await p.advance(c.window_end - c.now + 300);
	assert.equal(p.status.textContent, LINES.expired);
	assert.equal(p.list.hidden, true, 'no number to send to after the window');
});

test('thankyou.js: still waiting when the polling ends: the window has ended; a return from the wallet app looks once more', async () => {
	const c = cfgOf();
	let answer = { status: 'waiting' };
	const p = livePage({ cfg: c, answers: () => answer });
	await p.advance(c.stop_at - c.now + 120);
	assert.equal(p.status.textContent, LINES.expired);
	assert.equal(p.list.hidden, true);
	assert.equal(p.nudge.hidden, true, 'no "transferred from another number?" after the window');
	const polls = p.calls.length;
	await p.advance(600);
	assert.equal(p.calls.length, polls, 'the polling ended');
	answer = { status: 'paid' };
	await p.visible();
	assert.equal(p.calls.length, polls + 1, 'one look on return');
	assert.equal(p.status.textContent, `${LINES.paid} ✓`, 'with its mark');
	await p.advance(600);
	assert.equal(p.calls.length, polls + 1);
});

test('thankyou.js: "(within …)" goes once that duration reads differently; the time element stays', async () => {
	const p = livePage({ cfg: cfgOf(), answers: () => ({ status: 'waiting' }) });
	await p.advance(60);
	assert.match(p.before.textContent, /\(within 2 hours\)$/);
	await p.advance(30 * 60);
	assert.doesNotMatch(p.before.textContent, /within/);
	assert.match(p.before.textContent, /^Before: \S.* Cairo time$/);
	assert.equal(p.before.querySelector('[data-qersh-time]'), p.time, 'the same element, still drawn in Cairo time');
	assert.equal(p.before.getAttribute('data-qersh-left'), null);
});

test('thankyou.js: a copy is said in the live region, and said again on the next copy', async () => {
	const p = livePage({ cfg: cfgOf(), answers: () => ({ status: 'waiting' }) });
	const button = p.amount.querySelector('[data-qersh-copy]');
	assert.equal(button.hidden, false, 'shown with JavaScript');
	button.listeners.click[0]();
	assert.equal(button.textContent, 'Copied');
	assert.equal(p.said.textContent, '');
	await p.advance(0.1);
	assert.equal(p.said.textContent, 'Copied');
	await p.advance(2);
	assert.equal(p.said.textContent, '');
	assert.equal(button.textContent, 'Copy');
});

test('thankyou.js: a refused id one or two digits longer than the shape is sent alone, so the server counts it; other refusals stay on the page', async () => {
	const errors = { qersh_txn_format: 'Not a transaction id', qersh_txn_wallet_number: 'A wallet number', other: 'Try again' };
	const flush = async () => {
		for (let i = 0; i < 6; i++) await new Promise((r) => setImmediate(r));
	};
	let p = null;
	const answers = () => (p && p.calls.at(-1).url === '/txn' ? { code: 'qersh_txn_format', message: 'm' } : { status: 'waiting', claim: false });
	p = livePage({
		cfg: cfgOf({
			orange: true,
			txn_open: true,
			txn: { provider: 'orange_cash', payer_key: '01001234567', store_numbers: ['01212345678'] },
			strings: { ...STRINGS, txn_errors: errors },
		}),
		answers,
	});
	const send = p.txn.querySelector('[data-qersh-txn-send]');
	const msg = p.txn.querySelector('[data-qersh-txn-msg]');
	const sent = () => p.calls.filter((c) => c.url === '/txn').map((c) => c.body);

	// A pasted message whose id is 15 digits (format), and one with the sender's number (wallet number): only the
	// long number leaves the page, and the server's refusal is shown.
	for (const name of ['longer id: C1 with a 15-digit id', "longer id: C2 with a 15-digit id (the sender's 0020 number left out, so a wallet number)"]) {
		const v = vectors.find((x) => x.name === name);
		assert.ok(v && v.long_run, name);
		p.input.value = v.raw;
		send.listeners.click[0]();
		await flush();
		assert.deepEqual(sent().at(-1), { order_id: 9, key: 'wc_order_k', txn: v.long_run }, name);
		assert.equal(msg.textContent, errors.qersh_txn_format, name);
		assert.equal(msg.getAttribute('role'), 'alert');
		assert.equal(send.disabled, false);
	}
	assert.equal(sent().length, 2);

	// Refusals without such a number are not sent.
	for (const [raw, code] of [['2500729406114', 'qersh_txn_format'], ['25007294067405123', 'qersh_txn_format'], ['002001001234567', 'qersh_txn_wallet_number'], ['', 'qersh_txn_empty']]) {
		p.input.value = raw;
		send.listeners.click[0]();
		await flush();
		assert.equal(sent().length, 2, raw);
		assert.equal(msg.textContent, errors[code] || errors.other, raw);
	}
});

// ---- payer-field.js: the classic checkout's field, as templates/checkout/payer-field.php draws it.

const MSG = {
	invalid: 'Enter an Egyptian wallet number of 11 digits starting with 01.',
	store: "This is the store's wallet number. Enter the wallet number you will transfer from.",
	choice: 'Choose the wallet you will transfer to.',
};
const HELP = {
	auto: 'We recognize your transfer by this number, so send it from this number only.',
	decision: 'Enter the number you will transfer from, so the store can check your transfer more easily.',
	instapay: 'Enter the InstaPay address you will transfer from, so the store can check your transfer more easily.',
};

function classicField({ value = '01112223334', instapay = false, walletError = true, kept = false, checked = '' } = {}) {
	const { doc, el } = dom();
	const handlers = {};
	const $ = (target) => ({
		on(events, fn) {
			for (const ev of events.split(' ')) handlers[`${typeof target === 'string' ? target : 'body'}|${ev}`] = fn;
			return this;
		},
	});
	const wallets = [['aaaaaaaaaaa1', '0', '0'], ['bbbbbbbbbbb2', '1', '0']];
	if (instapay) wallets.push(['ccccccccccc3', '1', '1']);
	const radios = wallets.map(([id, decision, insta]) => el('input', { type: 'radio', class: 'input-radio', name: 'qersh_wallet', id: `qersh_wallet_${id}`, value: id, 'data-decision': decision, 'data-instapay': insta, checked: id === checked }));
	const group = el('fieldset', { class: 'qersh-wallets', id: 'qersh_wallets', role: 'radiogroup', 'aria-required': 'true' }, ...radios.map((r) => el('div', { class: 'qersh-wallet' }, r)));
	if (walletError) group.appendChild(el('span', { class: 'qersh-error', id: 'qersh_wallet_error', role: 'alert', hidden: true }));
	const kindNumber = el('input', { type: 'radio', name: 'qersh_payer_kind', id: 'qersh_payer_kind_number', value: 'number', checked: true });
	const kindInsta = el('input', { type: 'radio', name: 'qersh_payer_kind', id: 'qersh_payer_kind_instapay', value: 'instapay' });
	const payer = el('input', { type: 'text', class: 'input-text', id: 'qersh_payer', name: 'qersh_payer', value });
	const help = el('span', { class: 'qersh-help', id: 'qersh_payer_help' }, HELP.auto);
	const error = el('span', { class: 'qersh-error', id: 'qersh_payer_error', role: 'alert', hidden: true });
	const row = el('p', { class: 'form-row form-row-wide qersh-payer__row' }, el('span', { 'data-qersh-payer-label': '' }, 'Wallet number you will transfer from'), payer, help, error);
	const rootAttrs = {
		'data-qersh-payer': '',
		'data-instapay': instapay ? '1' : '0',
		'data-all-decision': '0',
		'data-store-numbers': JSON.stringify(['01001234567', '01201234567']),
		'data-msg-invalid': MSG.invalid,
		'data-msg-store': MSG.store,
		'data-msg-choice': MSG.choice,
		'data-label-number': 'Wallet number you will transfer from',
		'data-label-instapay': 'InstaPay address you will transfer from',
		'data-help-auto': HELP.auto,
		'data-help-decision': HELP.decision,
		'data-help-instapay': HELP.instapay,
	};
	if (kept) rootAttrs['data-kind-chosen'] = '1';
	const root = el('div', { class: 'qersh-payer', ...rootAttrs }, group, ...(instapay ? [el('fieldset', { class: 'qersh-kind' }, kindNumber, kindInsta)] : []), row);
	doc.body.appendChild(root);

	const ctx = vm.createContext({ document: doc, qershWcNormalize: N, jQuery: $ });
	ctx.window = ctx;
	vm.runInContext(readFileSync(new URL('../../assets/js/payer-field.js', import.meta.url), 'utf8'), ctx);
	return {
		doc, group, radios, payer, help, error, row, kindNumber, kindInsta,
		walletError: doc.getElementById('qersh_wallet_error'),
		place: () => handlers['form.checkout|checkout_place_order_qersh'](),
		choose(i) {
			for (const r of radios) r.checked = false;
			radios[i].checked = true;
			doc.fire('change', { target: radios[i] });
		},
	};
}

test('payer-field.js: no wallet chosen: the error is on the wallet group and the focus on a wallet, not on the number', () => {
	const f = classicField();
	assert.equal(f.place(), false, 'the order is not sent');
	assert.equal(f.walletError.hidden, false);
	assert.equal(f.walletError.textContent, MSG.choice);
	assert.equal(f.group.getAttribute('aria-invalid'), 'true');
	assert.equal(f.payer.getAttribute('aria-invalid'), null, 'the valid number is not marked invalid');
	assert.equal(f.error.hidden, true);
	assert.equal(f.row.classList.contains('woocommerce-invalid'), false);
	assert.equal(f.doc.activeElement, f.radios[0]);

	f.choose(0);
	assert.equal(f.walletError.hidden, true, 'choosing a wallet clears it');
	assert.equal(f.group.getAttribute('aria-invalid'), null);
	assert.equal(f.place(), true);
});

test('payer-field.js: a missing wallet and a wrong number show both errors, each in its place', () => {
	const f = classicField({ value: '0123' });
	assert.equal(f.place(), false);
	assert.equal(f.walletError.textContent, MSG.choice);
	assert.equal(f.error.textContent, MSG.invalid);
	assert.equal(f.payer.getAttribute('aria-invalid'), 'true');
	assert.equal(f.doc.activeElement, f.radios[0], 'the first error in the form takes the focus');
	f.choose(1);
	assert.equal(f.place(), false);
	assert.equal(f.doc.activeElement, f.payer);
});

test('payer-field.js: a theme copy without the wallet error place shows it under the number, without marking the number', () => {
	const f = classicField({ walletError: false });
	assert.equal(f.place(), false);
	assert.equal(f.error.textContent, MSG.choice);
	assert.equal(f.error.hidden, false);
	assert.equal(f.payer.getAttribute('aria-invalid'), null);
	f.choose(0);
	assert.equal(f.error.hidden, true);
});

test('payer-field.js: the help line follows the InstaPay switch', () => {
	const f = classicField({ value: '', instapay: true });
	assert.equal(f.help.textContent, HELP.auto, 'no wallet chosen: the line of all the wallets (one confirms on its own)');
	f.choose(0);
	assert.equal(f.help.textContent, HELP.auto);
	f.choose(2);
	assert.equal(f.kindInsta.checked, true, 'the switch follows the InstaPay wallet');
	assert.equal(f.help.textContent, HELP.instapay);
	f.kindNumber.checked = true;
	f.kindInsta.checked = false;
	f.doc.fire('change', { target: f.kindNumber });
	assert.equal(f.help.textContent, HELP.decision, 'a number to an InstaPay wallet waits for the store');
});

test('payer-field.js: "Pay for order" with the payer the order has keeps the switch where the server put it', () => {
	const f = classicField({ value: '01112223334', instapay: true, kept: true, checked: 'ccccccccccc3' });
	assert.equal(f.kindNumber.checked, true, 'a kept number on an InstaPay wallet stays a number');
	assert.equal(f.payer.value, '01112223334');
	const g = classicField({ value: '', instapay: true, checked: 'ccccccccccc3' });
	assert.equal(g.kindInsta.checked, true, 'without a kept payer the switch follows the wallet');
});
