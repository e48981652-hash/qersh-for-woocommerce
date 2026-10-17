// Arabic and RTL (docs/17 §1 criterion 7, §7, §14.1), in the job whose site language is ar (the core language pack
// only): the plugin's own Arabic texts load without a language pack of their own, on the settings screen, in the payer
// field (Blocks script translations, or the classic template), in the thank-you box and the transaction-id box, and in
// the instructions email; every number sits in an isolated left-to-right element and its groups appear left to right
// on screen; the deadline time reads right to left like the Arabic around it (the meridiem ص/م to the left of the
// digits, the day and month to their right); "*9#" shows as "*9#"; and the copy button copies the bare number.
// Positions are measured with Range.getClientRects and getBoundingClientRect.
import { expect, test } from '@playwright/test';
import { ADMIN_STATE, addToCart, arabic, CHECKOUT, chooseMethod, fillCustomer, LOCALE, mail, orderFromUrl, payerField, placeOrder, placeOrderButton, randPayer, state, wallet } from './utils.mjs';

test.skip(!/^ar/.test(LOCALE), 'only in the job with the site in Arabic');

/** Left edges, in screen order of the text, of the groups of a grouped number (split at no-break spaces). */
async function groupLefts(locator) {
	return locator.evaluate((el) => {
		const node = el.firstChild;
		const text = node && node.nodeType === Node.TEXT_NODE ? node.nodeValue : '';
		const out = [];
		let start = 0;
		for (const part of text.split(' ')) {
			const range = document.createRange();
			range.setStart(node, start);
			range.setEnd(node, start + part.length);
			out.push({ part, left: range.getBoundingClientRect().left });
			start += part.length + 1;
		}
		return out;
	});
}

/** The words of an element's text in logical order, each with its box on screen and its line. */
async function wordBoxes(locator) {
	return locator.evaluate((el) => {
		const out = [];
		const walker = document.createTreeWalker(el, NodeFilter.SHOW_TEXT);
		for (let node = walker.nextNode(); node; node = walker.nextNode()) {
			const re = /[^\s‎‏⁦-⁩]+/g;
			let m;
			while ((m = re.exec(node.nodeValue))) {
				const range = document.createRange();
				range.setStart(node, m.index);
				range.setEnd(node, m.index + m[0].length);
				const rects = [...range.getClientRects()].filter((r) => r.width > 0);
				if (!rects.length) continue;
				out.push({ word: m[0], left: Math.min(...rects.map((r) => r.left)), right: Math.max(...rects.map((r) => r.right)), top: Math.round(rects[0].top) });
			}
		}
		return out;
	});
}

/** The left edge of each character of `needle` where it first occurs in the element's text, or null. */
async function charLefts(locator, needle) {
	return locator.evaluate((el, wanted) => {
		const walker = document.createTreeWalker(el, NodeFilter.SHOW_TEXT);
		for (let node = walker.nextNode(); node; node = walker.nextNode()) {
			const at = node.nodeValue.indexOf(wanted);
			if (at < 0) continue;
			return [...wanted].map((ch, i) => {
				const range = document.createRange();
				range.setStart(node, at + i);
				range.setEnd(node, at + i + 1);
				return { ch, left: range.getBoundingClientRect().left };
			});
		}
		return null;
	}, needle);
}

const escapeRe = (s) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

test.use({ permissions: ['clipboard-read', 'clipboard-write'] });

test('numbers read left to right inside Arabic text, and copy copies the bare number', async ({ page }) => {
	const w = wallet('vodafone_cash');
	const payer = randPayer();
	await placeOrder(page, { product: state().products['14000'], w, payer });

	await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
	const box = page.locator('[data-qersh-box]');
	await expect(box.locator('.qersh-box__title')).toHaveText(arabic('Your transfer'));

	const to = box.locator('.qersh-line--to bdi');
	await expect(to).toHaveAttribute('dir', 'ltr');
	const groups = await groupLefts(to);
	expect(groups.map((g) => g.part)).toEqual([w.receive_address.slice(0, 4), w.receive_address.slice(4, 7), w.receive_address.slice(7)]);
	for (let i = 1; i < groups.length; i++) {
		expect(groups[i].left, `group "${groups[i].part}" is right of "${groups[i - 1].part}"`).toBeGreaterThan(groups[i - 1].left);
	}

	const from = await groupLefts(box.locator('.qersh-line--from bdi'));
	for (let i = 1; i < from.length; i++) expect(from[i].left).toBeGreaterThan(from[i - 1].left);

	const copy = box.locator('.qersh-line--to [data-qersh-copy]');
	await expect(copy).toBeVisible();
	await copy.click();
	await expect.poll(() => page.evaluate(() => navigator.clipboard.readText())).toBe(w.receive_address);
	expect(w.receive_address).toBe('01001234567');
});

test('the deadline reads right to left; "*9#" and the amounts of the rounding note read left to right', async ({ page }) => {
	const w = wallet('vodafone_cash');
	// 139.50: the box rounds it up to 140 and says why, naming the *9# code (docs/17 §6.2, §7).
	await placeOrder(page, { product: state().products['13950'], w, payer: randPayer() });
	const box = page.locator('[data-qersh-box]');

	// The deadline as thankyou.js writes it in the page's language ("3:45 م", or "19 أكتوبر في 3:45 م" on another
	// day): its words, in logical order, sit from right to left, so the meridiem is left of the digits.
	const time = box.locator('.qersh-line--before [data-qersh-time]');
	await expect(time).toBeVisible();
	await expect(time).toHaveText(/[صم]/);
	const text = await time.textContent();
	const words = await wordBoxes(time);
	expect(words.length, `the deadline "${text}"`).toBeGreaterThanOrEqual(2);
	expect(new Set(words.map((x) => x.top)).size, `the deadline "${text}" is on one line`).toBe(1);
	for (let i = 1; i < words.length; i++) {
		expect(words[i].right, `in "${text}", "${words[i].word}" is left of "${words[i - 1].word}"`).toBeLessThanOrEqual(words[i - 1].left + 1);
	}
	const meridiem = words.find((x) => /^[صم]$/.test(x.word));
	const clock = words.find((x) => /^\d{1,2}:\d{2}$/.test(x.word));
	expect(Boolean(meridiem && clock), `the deadline "${text}" has a time and a meridiem`).toBe(true);
	expect(meridiem.right, `in "${text}" the meridiem is left of the digits`).toBeLessThanOrEqual(clock.left + 1);

	// The rounding note: the code shows as *9#, and the digits of each amount read left to right.
	const note = box.locator('.qersh-line--amount .qersh-note');
	await expect(note).toBeVisible();
	const code = await charLefts(note, '*9#');
	expect(code, 'the note names the *9# code').not.toBeNull();
	expect(code[1].left, '"9" is right of "*"').toBeGreaterThan(code[0].left);
	expect(code[2].left, '"#" is right of "9"').toBeGreaterThan(code[1].left);
	for (const amount of ['139.50', '140']) {
		const digits = await charLefts(note, amount);
		expect(digits, `the note shows ${amount}`).not.toBeNull();
		for (let i = 1; i < digits.length; i++) {
			expect(digits[i].left, `in ${amount}, "${digits[i].ch}" is right of "${digits[i - 1].ch}"`).toBeGreaterThan(digits[i - 1].left);
		}
	}
});

test("the payer field, the transaction-id box and the instructions email are in the plugin's Arabic", async ({ page }) => {
	const w = wallet('orange_cash');
	const payer = randPayer();
	await addToCart(page, state().products['14000']);
	await page.goto(state().pages.checkout);
	await fillCustomer(page, { email: 'customer@example.test', phone: payer });
	await chooseMethod(page, w);
	// Blocks: the script's translations (languages/*.json); classic: the template's.
	await expect(page.locator(CHECKOUT === 'blocks' ? 'label[for="qersh_payer_blocks"]' : 'label[for="qersh_payer"]')).toContainText(arabic('Wallet number you will transfer from'));
	await expect(page.locator('.qersh-wallets__legend').first()).toContainText(arabic('Which wallet will you transfer to?'));
	await payerField(page).fill(payer);
	await payerField(page).blur();
	await (await placeOrderButton(page)).click();
	await page.waitForURL(/order-received/);
	const order = orderFromUrl(page.url());

	const txn = page.locator('#qersh-txn');
	await expect(txn.locator('#qersh-txn-title')).toHaveText(arabic('After you transfer'));
	await expect(txn.locator('label[for="qersh_txn"]')).toHaveText(arabic('Transaction id'));
	await expect(txn.locator('#qersh_txn_help')).toHaveText(arabic('Enter the transaction id from your transfer message, or paste the whole message.'));
	await expect(txn.locator('[data-qersh-txn-send]')).toHaveText(arabic('Send transaction id'));

	const sent = mail();
	test.skip(!sent.active, 'the instructions email needs tests/e2e/mu/qersh-test-mail.php (wp-env-override.mjs --mu-mail)');
	const instructions = sent.log.filter((m) => m.order === order.id && m.id === 'qersh_instructions');
	expect(instructions.length, JSON.stringify(sent.log.slice(-5))).toBe(1);
	const subject = new RegExp('^' + escapeRe(arabic('Transfer instructions for your order #{order_number}')).replace(escapeRe('{order_number}'), '.+') + '$');
	expect(instructions[0].subject).toMatch(subject);
});

test.describe('the settings screen', () => {
	test.use({ storageState: ADMIN_STATE });

	test("is in the plugin's Arabic, right to left", async ({ page }) => {
		await page.goto('/wp-admin/admin.php?page=qersh&tab=settings');
		await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
		await expect(page.locator('.qersh-wc h1.wp-heading-inline')).toHaveText(arabic('Qersh'));
		const tabs = page.locator('.qersh-wc .nav-tab-wrapper');
		await expect(tabs).toContainText(arabic('Wallets'));
		await expect(tabs.locator('.nav-tab-active')).toContainText(arabic('Settings'));
	});
});
