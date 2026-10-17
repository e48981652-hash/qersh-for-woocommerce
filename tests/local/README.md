# Local end-to-end harness

Scripts to run Qersh for WooCommerce against a real WordPress + WooCommerce test store on this machine, without Docker or MySQL: PHP's built-in web server and the SQLite Database Integration plugin. CI runs the same kind of scenarios on Linux with wp-env (`tests/e2e/`, `.github/workflows/e2e.yml`). None of this is part of the plugin package.

## Settings

| Variable | Default | Meaning |
|---|---|---|
| `QERSH_SITE` | `../local-site` next to this repository | The test site's WordPress directory (the one with `wp-load.php`). |
| `QERSH_BASE` | `http://127.0.0.1:8899` | The address the site answers on; it must match `WP_HOME` in its `wp-config.php`. |
| `QERSH_WALLETS` | `qersh-wallets.local.json` next to the site directory | Where `setup-wallets.php` writes the test wallets and their test keys, outside the web root and outside this repository. |
| `QERSH_PHP` | `php` | The PHP binary `e2e.mjs` calls. |
| `QERSH_DB_PATH` | (the site's own database) | A SQLite file to use instead, for a run on a copy of the store. |
| `QERSH_HOME_OVERRIDE` | (off) | `1` makes `WP_HOME`/`WP_SITEURL` equal `QERSH_BASE`, so a copy can answer on another port. |

The PHP scripts also accept the site directory as their first argument (`php tests/local/run-cron.php D:/path/to/site`), as the earlier copies in `local-tools` did.

## The test site

The site needs WordPress, WooCommerce, the SQLite Database Integration plugin with its `db.php` drop-in, and this repository linked (or copied) as `wp-content/plugins/qersh-for-woocommerce`. Its `wp-config.php` should have `WP_DEBUG` and `WP_DEBUG_LOG` on, `WP_DEBUG_DISPLAY` off, `DISABLE_WP_CRON` on (one request at a time: a loopback request would wait for itself), and `WP_HOME`/`WP_SITEURL` set to `QERSH_BASE`. Then, once:

```sh
php tests/local/setup-store.php on pretty   # EGP, Cairo time, stock on, HPOS on, pretty permalinks, four test products
php tests/local/smoke-plugin.php            # activates the plugin, reports tables, routes, method, emails, hooks, notices
php tests/local/setup-wallets.php           # three test wallets (Vodafone Cash, Orange Cash, InstaPay) with fresh test keys
```

`setup-wallets.php` replaces the plugin's wallets each time it runs, with new ids and keys.

## Running

Start the server in its own terminal and leave it running:

```sh
php -S 127.0.0.1:8899 -t "$QERSH_SITE" tests/local/router.php
```

Then:

```sh
node tests/local/e2e.mjs                       # every scenario, several minutes
node tests/local/e2e.mjs --only vodafone-match,orange-claim-first
```

Each scenario prints `PASS` or `FAIL` with the reply or order state that did not match; the exit code is 1 when one fails. The scenarios: a Vodafone Cash match, six concurrent copies of one delivery, a bad signature and an unknown wallet, a partial transfer then the rest, a personal transfer, 140 for a 139.50 order, the Orange Cash transaction id before and after the delivery, a forgery update after the match, the `?wc-api=qersh` fallback address, and the expiry.

## A copy of the store

To run without touching the store itself (the browser tests of `tests/e2e` change its wallets and pages), copy its SQLite file and serve the copy on another port:

```sh
cp "$QERSH_SITE/wp-content/database/<secret folder>/.ht.sqlite" /tmp/qersh-copy.sqlite
export QERSH_DB_PATH=/tmp/qersh-copy.sqlite QERSH_BASE=http://127.0.0.1:8898 QERSH_HOME_OVERRIDE=1
php -S 127.0.0.1:8898 -t "$QERSH_SITE" tests/local/router.php
```

The scripts here read the same variables. The Playwright tests run against such a copy with an installed browser and no download: `QERSH_E2E_RUNNER=php QERSH_E2E_BASE=http://127.0.0.1:8898 QERSH_E2E_CHANNEL=msedge QERSH_E2E_ADMIN_USER=<test admin> QERSH_E2E_ADMIN_PASS=<its password> npx playwright test -c tests/e2e/playwright.config.mjs`.

## The helpers

- `run-cron.php`: runs the due WP-Cron events and one Action Scheduler batch.
- `order-state.php <order id> [--expire-now] [--backdate-created <seconds>] [--run-expiry]`: one JSON line with the order's status, stock, notes, Qersh meta, `qersh_orders` row and transfers; the flags move the deadline or the creation time back, or run the expiry, first.
- `lib/bootstrap.php`: how each script finds the site and loads WordPress; `lib/overrides.php`: `QERSH_DB_PATH` and `QERSH_HOME_OVERRIDE`.

On SQLite, WooCommerce's stock reservation query does not run, so `setup-store.php` leaves `woocommerce_hold_stock_minutes` empty there; CI on MySQL covers the reservation.
