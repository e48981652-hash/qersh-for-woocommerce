// The Blocks content of the method (assets/js/checkout-blocks.js) with a small stand-in for wp.element: Blocks draws
// a method's content only while the method is selected, so choosing another method and coming back mounts it again.
// What the customer chose and typed must survive that, and the billing phone fills the field only while it is empty
// and untouched (docs/17 §4.1, §6.1).
// Run: node --test tests/js/checkout-blocks.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const require = createRequire(import.meta.url);
const N = require('../../assets/js/txn-normalize.js');
const SOURCE = readFileSync(new URL('../../assets/js/checkout-blocks.js', import.meta.url), 'utf8');

const WALLETS = [
	{ id: 'aaaaaaaaaaa1', label: 'Vodafone', provider: 'vodafone_cash', name: 'Vodafone Cash', address: '01001234567', display: '0100 123 4567', instapay: false, confirm: 'auto', decision: false },
	{ id: 'bbbbbbbbbbb2', label: 'Orange', provider: 'orange_cash', name: 'Orange Cash', address: '01201234567', display: '0120 123 4567', instapay: false, confirm: 'orange', decision: true },
];

// One component instance at a time: hook slots by call order, effects after the render when their deps change.
function harness(wallets) {
	let slots = [];
	let deps = [];
	let index = 0;
	let pending = [];
	const element = {
		createElement: (type, props, ...children) => ({ type, props: props || {}, children }),
		useState(initial) {
			const k = index++;
			if (!(k in slots)) {
				slots[k] = initial;
			}
			return [slots[k], (v) => { slots[k] = v; }];
		},
		useEffect(fn, list) {
			const k = index++;
			const before = deps[k];
			if (!before || !list || list.some((d, j) => d !== before[j])) {
				pending.push(() => { deps[k] = list; fn(); });
			}
		},
	};
	let registered = null;
	const ctx = vm.createContext({});
	ctx.window = ctx;
	ctx.wp = { element, i18n: { __: (s) => s }, htmlEntities: { decodeEntities: (s) => s } };
	ctx.wc = {
		wcBlocksRegistry: { registerPaymentMethod: (config) => { registered = config; } },
		wcSettings: { getSetting: () => ({ title: 'E-wallet transfer', ready: true, wallets, store_numbers: wallets.map((w) => w.address), instapay: false }) },
	};
	ctx.qershWcNormalize = N;
	vm.runInContext(SOURCE, ctx);
	assert.ok(registered, 'the method is registered');
	const Content = registered.content.type;

	return {
		// Renders until the effects settle, and returns the tree.
		render(phone) {
			const props = { billing: { billingAddress: { phone } }, eventRegistration: {}, emitResponse: {} };
			let tree;
			for (let round = 0; round < 5; round++) {
				index = 0;
				pending = [];
				tree = Content(props);
				if (pending.length === 0) {
					return tree;
				}
				pending.forEach((f) => f());
			}
			return tree;
		},
		unmount() {
			slots = [];
			deps = [];
		},
	};
}

function find(node, id) {
	if (!node || typeof node !== 'object') {
		return null;
	}
	if (Array.isArray(node)) {
		for (const child of node) {
			const hit = find(child, id);
			if (hit) {
				return hit;
			}
		}
		return null;
	}
	if (node.props && node.props.id === id) {
		return node;
	}
	return find(node.children, id) || (node.props && node.props.children ? find(node.props.children, id) : null);
}

const field = (tree) => find(tree, 'qersh_payer_blocks');

test('the billing phone fills an empty, untouched field', () => {
	const h = harness(WALLETS);
	assert.equal(field(h.render('01009998887')).props.value, '01009998887');
});

test('a typed number survives choosing another method and coming back', () => {
	const h = harness(WALLETS);
	let tree = h.render('01009998887');
	field(tree).props.onChange({ target: { value: '01112223334' } });
	tree = h.render('01009998887');
	assert.equal(field(tree).props.value, '01112223334');

	h.unmount(); // Cash on delivery chosen: Blocks drops the content.
	tree = h.render('01009998887'); // Back to the wallet transfer.
	assert.equal(field(tree).props.value, '01112223334', 'never replaced by the billing phone');
});

test('a field the customer emptied stays empty after a remount', () => {
	const h = harness(WALLETS);
	let tree = h.render('01009998887');
	field(tree).props.onChange({ target: { value: '' } });
	h.unmount();
	tree = h.render('01009998887');
	assert.equal(field(tree).props.value, '');
});

test('the chosen wallet survives a remount', () => {
	const h = harness(WALLETS);
	let tree = h.render('01009998887');
	find(tree, 'qersh_wallet_blocks_bbbbbbbbbbb2').props.onChange();
	tree = h.render('01009998887');
	assert.equal(find(tree, 'qersh_wallet_blocks_bbbbbbbbbbb2').props.checked, true);
	h.unmount();
	tree = h.render('01009998887');
	assert.equal(find(tree, 'qersh_wallet_blocks_bbbbbbbbbbb2').props.checked, true);
	assert.equal(find(tree, 'qersh_wallet_blocks_aaaaaaaaaaa1').props.checked, false);
});

test('an untouched prefill follows a new billing phone after a remount', () => {
	const h = harness(WALLETS);
	h.render('01009998887');
	h.unmount();
	assert.equal(field(h.render('01005556667')).props.value, '01005556667');
});
