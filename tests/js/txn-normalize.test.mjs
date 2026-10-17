// The JavaScript normaliser of the order page (assets/js/txn-normalize.js) against the vectors Core\TxnId reads
// (tests/vectors/txn-normalize.json), the shapes of fixtures/txn-patterns.json, and the core's normalizePayer.
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
