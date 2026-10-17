// Playwright for Qersh for WooCommerce (docs/17 §11, §14.1). CI runs it on wp-env in four jobs of
// .github/workflows/e2e.yml: {Blocks, classic} × {HPOS on, off}, one with the site in Arabic and one with the
// "Disable REST API" plugin. Run: npx playwright test -c tests/e2e/playwright.config.mjs
// Locally without Docker: QERSH_E2E_RUNNER=php QERSH_E2E_BASE=http://127.0.0.1:8899 QERSH_E2E_CHANNEL=msedge
// (an installed browser, no download) against the test site of tests/local.
import { defineConfig, devices } from '@playwright/test';

const CI = Boolean(process.env.CI);
// A slow local server (php -S serves one request at a time) multiplies the timeouts: QERSH_E2E_SLOW=3.
const SLOW = Math.max(1, Number(process.env.QERSH_E2E_SLOW) || 1);

export default defineConfig({
	testDir: '.',
	testMatch: /.*\.spec\.mjs$/,
	globalSetup: './global-setup.mjs',
	timeout: 180_000 * SLOW,
	expect: { timeout: 20_000 * SLOW },
	fullyParallel: false,
	workers: 1,
	retries: CI ? 1 : 0,
	forbidOnly: CI,
	outputDir: './test-results',
	reporter: CI ? [['list'], ['html', { open: 'never', outputFolder: './playwright-report' }], ['github']] : [['list']],
	use: {
		baseURL: (process.env.QERSH_E2E_BASE || 'http://localhost:8888').replace(/\/+$/, ''),
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
		video: 'off',
		actionTimeout: 30_000 * SLOW,
		navigationTimeout: 60_000 * SLOW,
	},
	projects: [
		{
			name: 'chromium',
			use: { ...devices['Desktop Chrome'], channel: process.env.QERSH_E2E_CHANNEL || undefined },
		},
	],
});
