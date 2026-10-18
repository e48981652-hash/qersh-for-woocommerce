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
function harness(wallets, opts = {}) {
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
		wcSettings: { getSetting: () => ({ title: 'E-wallet transfer', ready: true, wallets, store_numbers: wallets.map((w) => w.address), instapay: !!opts.instapay }) },
	};
	ctx.qershWcNormalize = N;
	vm.runInContext(SOURCE, ctx);
	assert.ok(registered, 'the method is registered');
	const Content = registered.content.type;
	// What Blocks hands the content: the payment setup observer (the last one registered is the live one).
	let setup = null;
	const eventRegistration = { onPaymentSetup: (cb) => { setup = cb; return () => {}; } };

	return {
		// Renders until the effects settle, and returns the tree.
		render(phone) {
			const props = { billing: { billingAddress: { phone } }, eventRegistration, emitResponse: {} };
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
		// Place order: Blocks calls the payment setup observer.
		place() {
			assert.ok(setup, 'onPaymentSetup was registered');
			return setup();
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

test('no wallet chosen: the error is on the wallet group, not on the payer field', () => {
	const h = harness(WALLETS);
	let tree = h.render('01009998887');
	const answer = h.place();
	assert.equal(answer.type, 'error');
	assert.equal(answer.message, 'Choose the wallet you will transfer to.');
	tree = h.render('01009998887');
	assert.equal(field(tree).props['aria-invalid'], undefined, 'the valid number is not marked invalid');
	assert.equal(find(tree, 'qersh_payer_blocks_error').props.hidden, true);
	const group = find(tree, 'qersh_wallets_blocks');
	assert.equal(group.props.role, 'radiogroup');
	assert.equal(group.props['aria-required'], 'true');
	assert.equal(group.props['aria-invalid'], 'true');
	const error = find(tree, 'qersh_wallet_blocks_error');
	assert.equal(error.props.hidden, false);
	assert.deepEqual(error.children, ['Choose the wallet you will transfer to.']);
	assert.match(find(tree, 'qersh_wallet_blocks_aaaaaaaaaaa1').props['aria-describedby'], /qersh_wallet_blocks_error/);

	find(tree, 'qersh_wallet_blocks_aaaaaaaaaaa1').props.onChange();
	tree = h.render('01009998887');
	assert.equal(find(tree, 'qersh_wallet_blocks_error').props.hidden, true, 'choosing a wallet clears it');
	assert.equal(find(tree, 'qersh_wallets_blocks').props['aria-invalid'], undefined);
	const ok = h.place();
	assert.equal(ok.type, 'success');
	assert.equal(JSON.stringify(ok.meta.paymentMethodData), JSON.stringify({ qersh_payer: '01009998887', qersh_wallet: 'aaaaaaaaaaa1' }));
});

test('a wrong number with a wallet chosen stays the payer field\'s error', () => {
	const h = harness(WALLETS);
	let tree = h.render('');
	find(tree, 'qersh_wallet_blocks_aaaaaaaaaaa1').props.onChange();
	field(h.render('')).props.onChange({ target: { value: '0123' } });
	h.render('');
	const answer = h.place();
	assert.equal(answer.type, 'error');
	assert.match(answer.message, /11 digits/);
	tree = h.render('');
	assert.equal(field(tree).props['aria-invalid'], 'true');
	assert.equal(find(tree, 'qersh_wallet_blocks_error').props.hidden, true);
});

test('the help line follows the InstaPay switch', () => {
	const wallets = [
		WALLETS[0],
		{ id: 'ccccccccccc3', label: 'InstaPay', provider: 'instapay_app', name: 'InstaPay', address: 'shop@instapay', display: 'shop@instapay', instapay: true, confirm: 'decision', decision: true },
	];
	const h = harness(wallets, { instapay: true });
	let tree = h.render('01009998887');
	assert.deepEqual(find(tree, 'qersh_payer_blocks_help').children, ['We recognize your transfer by this number, so send it from this number only.']);
	find(tree, 'qersh_wallet_blocks_ccccccccccc3').props.onChange();
	tree = h.render('01009998887');
	assert.deepEqual(find(tree, 'qersh_payer_blocks_help').children, ['Enter the InstaPay address you will transfer from, so the store can check your transfer more easily.']);
	find(tree, 'qersh_payer_kind_blocks_number').props.onChange();
	tree = h.render('01009998887');
	assert.deepEqual(find(tree, 'qersh_payer_blocks_help').children, ['Enter the number you will transfer from, so the store can check your transfer more easily.'], 'a number to an InstaPay wallet needs the store\'s decision');
});
