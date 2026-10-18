# End-to-end tests

Playwright tests of the storefront and the admin (docs/17 §11 and §14.1 in the Qersh repository), and `deliver.mjs`, the sender that signs deliveries the way the phone does. CI runs them in `.github/workflows/e2e.yml` on wp-env (`.wp-env.json`: WordPress 7.1.3, WooCommerce 11.2.1, PHP 8.3) in four jobs, {Checkout Blocks, classic} × {HPOS on, off}: one with the site in Arabic, one with the "Disable REST API" plugin, and one with `mu/qersh-test-finish-request.php` (a `fastcgi_finish_request()` that only flushes). Every job also has `mu/qersh-test-mail.php`: each email goes to `wp-content/qersh-e2e-mail.log` instead of being sent, and takes 5 seconds while a test turns that on.

| Spec | What it checks |
|---|---|
| `checkout.spec.mjs` | The payer field reads Arabic-Indic digits and `+20`, takes an e-wallet number only (it refuses the store's own numbers, an address and a name); a placed order shows the transfer box. |
| `thankyou.spec.mjs` | The live status turns paid by itself after `deliver.mjs` sends the matching transfer (through `?wc-api=qersh` when REST is off). |
| `txn-box.spec.mjs` | The transaction-id box, the same on an order of either wallet: a whole transfer message (an invented wording, on several lines) pasted into it sends the id alone (with REST off: either the REST attempt is refused first, or the page already uses the fallback, which answers 200), the "saved" line appears, the delivery without a payer number then pays the order; the page's data names no wallet type; a pasted message shows the "We will send: …" preview of the id before anything is sent. |
| `deliveries.spec.mjs` | docs/17 §1 criteria 2 and 3. The kit's `fixtures/sink/send.mjs` on a store reset before each scenario: `--probe` (both probes 401), `--late 72` and `--held 15` (the order open when the money arrived is paid, a newer one of the same payer is not), `--forgery` (the paid order is stopped and flagged). `deliver.mjs`: one delivery id six times at once and once more (one reply, stock reduced once), two transfers of one payer at once (each pays one of its orders), and, on each wallet, a transaction id typed after the delivery of a transfer without a payer number (a name only, a masked number). The reply within 3 seconds while each email takes 5 (REST, `?rest_route=`, or the fallback), then "Processing order" and "New order" after one Action Scheduler run. |
| `admin-key-link.spec.mjs` | While the key link window is open, no attribute, text node or field of the page carries the key; Escape does not close it; "Cancel this link" drops the pending key. |
| `rtl.spec.mjs` | Site in Arabic only: the plugin's Arabic (read from `languages/qersh-for-woocommerce-ar.po`) on the settings screen, the payer field, the thank-you and transaction-id boxes and the instructions email subject; the store number's groups sit left to right; the deadline reads right to left (the meridiem left of the digits); the rounding note is the plugin's Arabic, names no wallet code, and its amounts read left to right; the copy button copies `01001234567`. |

`global-setup.mjs` prepares the store (`setup/store.php`) and two test e-wallets with fresh test keys (`setup/wallets.php`: `main`, 01001234567, and `second`; a wallet has no type, so the specs pick one by its label with `wallet('main')`), writes them to `.auth/state.json` (never committed) and logs the administrator in. The other setup scripts, each run through WP-CLI (or plain PHP on a local site) and printing one `QERSH_JSON=` line:

- `setup/order-state.php` reads an order back (status, stock, meta, row, transfers, notes); `cron=1` runs one Action Scheduler batch first, `shift=`/`backdate=` move its row back in time.
- `setup/order.php` makes a waiting order on the server through the method's `process_payment()`.
- `setup/reset.php` deletes the orders of the kit's payer and its transfer (a store reset for one `send.mjs` scenario).
- `setup/mail.php` reads the mail log and turns the slow mail on or off.
- `setup/attach.php` makes a manual attach (`Orders::apply_attach()`), for `concurrency.mjs`.

`wp-env-override.mjs` writes the override file of one CI job. `concurrency.mjs` is the nightly concurrency run on wp-env's MariaDB (no browser). `playground/` and `fpm/` belong to the other nightly jobs (32-bit PHP-WASM with SQLite, and PHP-FPM with nginx).

## Running

With Docker (as CI does):

```sh
npm ci
node tests/e2e/wp-env-override.mjs --mu-mail
npx wp-env start
npx wp-env run cli wp plugin activate qersh-for-woocommerce
npx playwright install chromium
QERSH_E2E_CHECKOUT=blocks npx playwright test -c tests/e2e/playwright.config.mjs
```

Without Docker, against a copy of the local SQLite test store (see `tests/local/README.md`), with an installed browser and no download: `QERSH_E2E_RUNNER=php`, `QERSH_E2E_BASE` (the copy's address), `QERSH_E2E_CHANNEL=msedge` (or `chrome`), `QERSH_E2E_ADMIN_USER` and `QERSH_E2E_ADMIN_PASS` (the test site's own administrator), and `QERSH_E2E_SLOW=3` because `php -S` serves one request at a time. Without `mu/qersh-test-mail.php` in that site the mail checks are skipped.

Settings: `QERSH_E2E_CHECKOUT` (`blocks` or `classic`), `QERSH_E2E_LOCALE` (the site language the workflow set), `QERSH_E2E_REST` (`off` when REST is blocked), `QERSH_E2E_FINISH` (`on` in the job with the `fastcgi_finish_request()` stand-in: the reply time is then measured to the headers, because Apache keeps the connection open until the after-reply work ends), `QERSH_E2E_WP_ENV_CONFIG` (another wp-env configuration, e.g. `.wp-env.test.json`).
