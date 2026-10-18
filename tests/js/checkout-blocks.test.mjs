// The Blocks content of the method (assets/js/checkout-blocks.js) with a small stand-in for wp.element: Blocks draws
// a method's content only while the method is selected, so choosing another method and coming back mounts it again.
// What the customer chose and typed must survive that, and the billing phone fills the field only while it is empty
// and untouched (docs/17 §4.1, §6.1). Every wallet is drawn the same way (label and number, one help line, a number
// only): the script never knows a wallet's type.
// Run: node --test tests/js/checkout-blocks.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const require = createRequire(import.meta.url);
const N = require('../../assets/js/txn-normalize.js');
const SOURCE = readFileSync(new URL('../../assets/js/checkout-blocks.js', import.meta.url), 'utf8');

// What BlocksIntegration::get_payment_method_data() sends for each wallet: { id, label, address, display, confirm }.
const CONFIRM = 'Your order is confirmed once your transfer reaches the store.';
const WALLETS = [
	{ id: 'aaaaaaaaaaa1', label: 'Main', address: '01001234567', display: '0100 123 4567', confirm: CONFIRM },
	{ id: 'bbbbbbbbbbb2', label: 'Second', address: '01201234567', display: '0120 123 4567', confirm: CONFIRM },
];
const HELP = 'Transfer from this number, so the store can match your transfer to your order.';
const INVALID = 'Enter an Egyptian wallet number of 11 digits starting with 01.';

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
		wcSettings: { getSetting: () => ({ title: 'E-wallet transfer', ready: true, wallets, store_numbers: wallets.map((w) => w.address), ...(opts.extra || {}) }) },
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

// Every node of a tree whose props match.
function all(node, match, out = []) {
	if (!node || typeof node !== 'object') {
		return out;
	}
	if (Array.isArray(node)) {
		node.forEach((child) => all(child, match, out));
		return out;
	}
	if (node.props && match(node)) {
		out.push(node);
	}
	all(node.children, match, out);
	return out;
}

// The text of a tree, as a reader would see it.
function text(node) {
	if (node === null || node === undefined || node === false) {
		return '';
	}
	if (typeof node === 'string' || typeof node === 'number') {
		return String(node);
	}
	if (Array.isArray(node)) {
		return node.map(text).join('');
	}
	return text(node.children);
}

test('one help line and one number field for every wallet, whichever is chosen', () => {
	const h = harness(WALLETS);
	let tree = h.render('01009998887');
	assert.deepEqual(find(tree, 'qersh_payer_blocks_help').children, [HELP]);
	for (const w of WALLETS) {
		find(tree, `qersh_wallet_blocks_${w.id}`).props.onChange();
		tree = h.render('01009998887');
		assert.deepEqual(find(tree, 'qersh_payer_blocks_help').children, [HELP], w.id);
		assert.equal(field(tree).props.inputMode, 'numeric', w.id);
		assert.equal(field(tree).props.autoComplete, 'tel', w.id);
		assert.equal(field(tree).props.value, '01009998887', `${w.id}: the prefill stays`);
	}
	assert.equal(all(tree, (n) => n.type === 'fieldset').length, 1, 'the wallet choice only: no number/address switch');
	assert.equal(all(tree, (n) => n.props.type === 'radio').length, WALLETS.length, 'one radio per wallet, nothing else');
});

test('a wallet is shown by its label and number; without a label, by its number alone', () => {
	const wallets = [WALLETS[0], { id: 'ccccccccccc3', label: '', address: '01112223344', display: '0111 222 3344', confirm: CONFIRM }];
	const tree = harness(wallets).render('');
	const labels = all(tree, (n) => n.type === 'label' && typeof n.props.htmlFor === 'string' && n.props.htmlFor.startsWith('qersh_wallet_blocks_'));
	assert.equal(labels.length, 2);
	assert.equal(text(labels[0]), 'Main 0100 123 4567');
	assert.equal(text(labels[1]), '0111 222 3344');
	assert.equal(all(labels[1], (n) => n.props.className === 'qersh-wallet__name').length, 0, 'no empty name');
	for (const w of wallets) {
		assert.deepEqual(find(tree, `qersh_wallet_blocks_${w.id}_confirm`).children, [CONFIRM], 'the same line for every wallet');
	}
});

test('an address or a bare name is refused like any other wrong number', () => {
	for (const typed of ['ahmed@instapay', 'Ahmed@InstaPay', 'ahmed', '0111***3445']) {
		const h = harness([WALLETS[0]]);
		field(h.render('')).props.onChange({ target: { value: typed } });
		let tree = h.render('');
		field(tree).props.onBlur();
		tree = h.render('');
		assert.equal(find(tree, 'qersh_payer_blocks_error').props.hidden, false, typed);
		assert.deepEqual(find(tree, 'qersh_payer_blocks_error').children, [INVALID], typed);
		const answer = h.place();
		assert.equal(answer.type, 'error', typed);
		assert.equal(answer.message, INVALID, typed);
	}
});

test('a type the page data may still carry changes nothing (the script never reads it)', () => {
	const typed = WALLETS.map((w, i) => ({ ...w, provider: ['instapay_app', 'orange_cash'][i], instapay: i === 0, decision: true, name: 'Brand' }));
	const plain = harness(WALLETS);
	const legacy = harness(typed, { extra: { instapay: true } });
	for (const h of [plain, legacy]) {
		let tree = h.render('01009998887');
		find(tree, 'qersh_wallet_blocks_aaaaaaaaaaa1').props.onChange();
		tree = h.render('01009998887');
		field(tree).props.onChange({ target: { value: 'ahmed@instapay' } });
		h.render('01009998887');
		assert.equal(h.place().message, INVALID, 'no address, even with an InstaPay flag');
		field(h.render('01009998887')).props.onChange({ target: { value: '+20 111 222 3334' } });
		tree = h.render('01009998887');
		assert.doesNotMatch(text(tree), /Brand|InstaPay/);
		const ok = h.place();
		assert.equal(JSON.stringify(ok.meta.paymentMethodData), JSON.stringify({ qersh_payer: '01112223334', qersh_wallet: 'aaaaaaaaaaa1' }));
	}
});

test('a billing phone that is no number (a name) never fills the field', () => {
	const h = harness([WALLETS[0]]);
	assert.equal(field(h.render('ahmed')).props.value, '');
	assert.equal(field(h.render('01001234567')).props.value, '', "the store's own number");
	assert.equal(field(h.render('+20 100 999 8887')).props.value, '01009998887');
});
