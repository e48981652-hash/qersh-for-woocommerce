// The JavaScript normaliser of the order page (assets/js/txn-normalize.js) against the vectors Core\TxnId reads
// (tests/vectors/txn-normalize.json), the shapes of fixtures/txn-patterns.json, and the core's normalizePayer.
// Then hasRtl() (Format::has_rtl(); FormatTest checks that both tables hold the same code points), and the two places
// of the order page (assets/js/thankyou.js) that use the normaliser for display, in a small stand-in for the DOM: the
// deadline's direction, and the "We will send: …" preview of the transaction id.
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
}

test('canonicalTyped gives the same answers with the shapes the page passes from the server', () => {
	const shapes = {};
	for (const [provider, spec] of Object.entries(read('../../fixtures/txn-patterns.json').providers)) {
		shapes[provider] = { pattern: spec.pattern, lengths: spec.lengths, cls: spec.class };
	}
	for (const v of vectors) {
		assert.deepEqual(N.canonicalTyped(v.provider, v.raw, v.payer_key, v.store_numbers, shapes), v.expect, v.name);
	}
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
