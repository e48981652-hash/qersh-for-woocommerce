// "Generate your developer's link with the connection key" (docs/17 §4.8 rule 5, §10 item 7, §14.1): the key arrives
// in the AJAX answer and stays in a JavaScript variable. While the window is open no attribute, text node or field
// value of the page carries it (the QR canvas alone does), Escape does not close the window, and closing it asks
// whether the link was used; "Cancel this link" drops the pending key.
import { expect, test } from '@playwright/test';
import { ADMIN_STATE, wallet } from './utils.mjs';

test.use({ storageState: ADMIN_STATE });

/** Every place of the page that could carry the key, with what it holds when it matches. */
async function leaks(page, secret) {
	return page.evaluate((key) => {
		const found = [];
		const looksLikeKey = /qsk_[A-Za-z0-9_-]{6,}/;
		const bad = (s) => typeof s === 'string' && (s.includes(key) || looksLikeKey.test(s));
		if (bad(document.documentElement.outerHTML)) found.push('outerHTML');
		const walker = document.createTreeWalker(document, NodeFilter.SHOW_ELEMENT | NodeFilter.SHOW_TEXT | NodeFilter.SHOW_COMMENT);
		for (let node = walker.currentNode; node; node = walker.nextNode()) {
			if (node.nodeType === Node.ELEMENT_NODE) {
				for (const attr of node.attributes) if (bad(attr.value)) found.push(`<${node.tagName.toLowerCase()} ${attr.name}>`);
				if ('value' in node && bad(node.value)) found.push(`<${node.tagName.toLowerCase()}>.value`);
				if (node.shadowRoot && bad(node.shadowRoot.innerHTML)) found.push(`<${node.tagName.toLowerCase()}> shadow root`);
			} else if (bad(node.nodeValue)) {
				found.push(`text: ${node.nodeValue.slice(0, 40)}`);
			}
		}
		if (bad(document.title) || bad(window.location.href) || bad(window.status)) found.push('title, address or status bar');
		return found;
	}, secret);
}

test('the key link window never puts the key into the page', async ({ page }) => {
	const w = wallet('main');
	await page.goto('/wp-admin/admin.php?page=qersh&tab=wallets');
	const button = page.locator(`[data-qersh-keylink="${w.id}"]`);
	await expect(button).toBeVisible();
	expect(await leaks(page, w.key), 'the stored key is not in the page before').toEqual([]);

	const answer = page.waitForResponse((r) => r.url().includes('admin-ajax.php') && (r.request().postData() || '').includes('qersh_wc_key_link'));
	await button.click();
	const response = await answer;
	expect(response.status()).toBe(200);
	expect(response.headers()['cache-control'] || '').toMatch(/no-store/);
	const data = await response.json();
	expect(data.link).toMatch(/^qersh:\/\/setup\?/);
	const secret = decodeURIComponent(/[?&]secret=([^&]+)/.exec(data.link)[1]);
	expect(secret).toMatch(/^qsk_[A-Za-z0-9_-]{43}$/);

	const modal = page.locator('.qersh-wc-modal');
	await expect(modal).toBeVisible();
	await expect(modal.locator('[role="dialog"]')).toHaveAttribute('aria-modal', 'true');
	expect(await leaks(page, secret), 'no key in the page while the window is open').toEqual([]);

	await page.keyboard.press('Escape');
	await expect(modal).toBeVisible();

	// "Show QR code" is the first button: the key is drawn into the canvas only.
	await modal.locator('.qersh-wc-modal-buttons button').first().click();
	await expect(modal.locator('canvas.qersh-wc-qr')).toBeVisible();
	expect(await leaks(page, secret), 'no key in the page with the QR code shown').toEqual([]);

	// "Close" (the primary button) wipes the link and asks; "Cancel this link" drops the pending key.
	await modal.locator('.qersh-wc-modal-main .qersh-wc-modal-buttons .button-primary').click();
	await expect(modal.locator('.qersh-wc-modal-ask')).toBeVisible();
	expect(await leaks(page, secret)).toEqual([]);
	await Promise.all([page.waitForNavigation(), modal.locator('.qersh-wc-danger').click()]);
	await expect(page.locator('.qersh-wc-modal')).toHaveCount(0);
	expect(await leaks(page, secret), 'nothing after the cancel either').toEqual([]);
});
