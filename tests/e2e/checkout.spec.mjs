// Checkout with the method (docs/17 §4.1, §6.1, §14.1), in the job's checkout (Blocks or classic): the payer field
// reads Arabic-Indic digits and +20, takes an e-wallet number only (never one of the store's own wallets, never an
// address or a name), and a placed order lands on the thank-you box with the amount, the store number and the
// customer's number.
import { expect, test } from '@playwright/test';
import { addToCart, checkPayer, chooseMethod, CHECKOUT, fillCustomer, payerError, payerField, placeOrder, placeOrderButton, randPayer, state, wallet } from './utils.mjs';

test.describe(`checkout (${CHECKOUT})`, () => {
	test.beforeEach(async ({ page }) => {
		await addToCart(page, state().products['14000']);
		await page.goto(state().pages.checkout);
		await fillCustomer(page, { email: 'customer@example.test', phone: '01155500011' });
		await chooseMethod(page, wallet('main'));
	});

	test('the payer field reads Arabic-Indic digits and +20', async ({ page }) => {
		const field = payerField(page);
		await field.fill('٠١١٢٣٤٥٦٧٨٩');
		await field.blur();
		await expect(field).toHaveValue('01123456789');
		await expect(payerError(page)).toBeHidden();

		await field.fill('+20 112 345 6780');
		await field.blur();
		await expect(field).toHaveValue('01123456780');
		await expect(field).toHaveAttribute('dir', 'ltr');
	});

	test("the store's own wallet numbers are refused", async ({ page }) => {
		const field = payerField(page);
		for (const own of [wallet('second').receive_address, wallet('main').receive_address]) {
			expect(checkPayer(own).error, own).toBe('payer_store');
			await field.fill(own);
			await field.blur();
			await expect(payerError(page)).toBeVisible();
			await expect(payerError(page)).not.toHaveText('');
		}

		await (await placeOrderButton(page)).click();
		await page.waitForTimeout(3000);
		expect(page.url()).not.toMatch(/order-received/);
	});

	test('an address or a name is not a wallet number', async ({ page }) => {
		const field = payerField(page);
		for (const value of ['someone@instapay', 'Test Customer']) {
			expect(checkPayer(value).error, value).toBe('payer_invalid');
			await field.fill(value);
			await field.blur();
			await expect(payerError(page)).toBeVisible();
			await expect(payerError(page)).not.toHaveText('');
		}
	});
});

test(`an order placed with the method shows the transfer box (${CHECKOUT})`, async ({ page }) => {
	const payer = randPayer();
	const w = wallet('main');
	const order = await placeOrder(page, { product: state().products['14000'], w, payer });
	expect(order.id).toBeGreaterThan(0);
	expect(order.key).toMatch(/^wc_order_/);

	const box = page.locator('[data-qersh-box]');
	await expect(box).toBeVisible();
	await expect(box.locator('.qersh-line--amount')).toContainText('140');
	await expect(box.locator('.qersh-line--to bdi')).toHaveText(w.receive_address.replace(/^(\d{4})(\d{3})(\d{4})$/, '$1 $2 $3'));
	await expect(box.locator('.qersh-line--from bdi')).toHaveText(payer.replace(/^(\d{4})(\d{3})(\d{4})$/, '$1 $2 $3'));
	await expect(box.locator('[data-qersh-status]')).toHaveClass(/qersh-status--waiting/);
	await expect(box.locator('.qersh-line--to [data-qersh-copy]')).toHaveAttribute('data-qersh-copy', w.receive_address);
});
