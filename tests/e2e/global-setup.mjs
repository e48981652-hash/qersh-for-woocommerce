// Playwright global setup: waits for the site, prepares the store and the test wallets (tests/e2e/setup/*.php),
// writes tests/e2e/.auth/state.json (product ids, pages, wallet ids, test keys and webhook addresses; the folder is in
// .gitignore) and logs the administrator in once (tests/e2e/.auth/admin.json).
// The administrator is wp-env's own test account (admin / password) unless QERSH_E2E_ADMIN_USER and
// QERSH_E2E_ADMIN_PASS name another test account of a local test site.
import { chromium } from '@playwright/test';
import { mkdirSync, writeFileSync } from 'node:fs';
import { ADMIN_STATE, BASE, CHECKOUT, STATE_DIR, STATE_FILE, wp } from './utils.mjs';

async function waitForSite() {
	const until = Date.now() + 180_000;
	let last = '';
	while (Date.now() < until) {
		try {
			const r = await fetch(`${BASE}/`, { redirect: 'manual' });
			if (r.status < 500) return;
			last = `HTTP ${r.status}`;
		} catch (e) {
			last = e.message;
		}
		await new Promise((resolve) => setTimeout(resolve, 2000));
	}
	throw new Error(`${BASE} did not answer: ${last}`);
}

export default async function globalSetup(config) {
	await waitForSite();
	const store = wp('store.php', `checkout=${CHECKOUT}`);
	const wallets = wp('wallets.php');
	mkdirSync(STATE_DIR, { recursive: true });
	writeFileSync(STATE_FILE, JSON.stringify({ ...store, ...wallets, base: BASE }, null, '\t'));
	console.log(`e2e store: ${CHECKOUT} checkout, locale ${store.locale}, HPOS ${store.hpos ? 'on' : 'off'}, WordPress ${store.versions.wp}, WooCommerce ${store.versions.wc}, PHP ${store.versions.php}`);

	const project = config.projects[0] || { use: {} };
	const browser = await chromium.launch({ channel: project.use.channel });
	const page = await browser.newPage({ baseURL: BASE });
	await page.goto('/wp-login.php');
	await page.locator('#user_login').fill(process.env.QERSH_E2E_ADMIN_USER || 'admin');
	await page.locator('#user_pass').fill(process.env.QERSH_E2E_ADMIN_PASS || 'password');
	await page.locator('#wp-submit').click();
	await page.waitForURL(/wp-admin/, { timeout: 60_000 });
	await page.context().storageState({ path: ADMIN_STATE });
	await browser.close();
}
