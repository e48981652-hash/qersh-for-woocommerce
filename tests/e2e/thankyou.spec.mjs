// The thank-you box's live status (docs/17 §6.2, §14.1): it polls POST qersh/v1/order-status (or ?wc-api=qersh&qo=
// status when REST answers 401/403, the "Disable REST API" job), and turns paid by itself after the phone's delivery,
// without a reload. The order is then processing on the server.
import { expect, test } from '@playwright/test';
import { CHECKOUT, deliver, orderState, placeOrder, randPayer, REST_OFF, SLOW, state, wallet } from './utils.mjs';

test(`the live status turns paid after the delivery (${CHECKOUT}${REST_OFF ? ', REST off' : ''})`, async ({ page }) => {
	const payer = randPayer();
	const w = wallet('main');
	const order = await placeOrder(page, { product: state().products['14000'], w, payer });
	const status = page.locator('[data-qersh-box] [data-qersh-status]');
	await expect(status).toHaveClass(/qersh-status--waiting/);

	// The money arrives one second before the delivery is built; give the order a moment so it is older (skew 0).
	await page.waitForTimeout(2000);
	const [reply] = deliver(w, ['--payer', payer, '--amount', '140']);
	expect(reply.status, JSON.stringify(reply)).toBe(200);
	expect(reply.body.status).toBe('matched');
	expect(String(reply.body.reference)).toContain(String(order.id));
	expect(reply.ms, 'the phone gets its answer quickly').toBeLessThan(5000 * SLOW);

	await expect(status).toHaveClass(/qersh-status--paid/, { timeout: 90_000 });
	await expect(page.locator('[data-qersh-box] [data-qersh-pending]').first()).toBeHidden();

	const server = orderState(order.id, true);
	expect(['processing', 'completed']).toContain(server.status);
	expect(server.row.state).toBe('paid');
});
