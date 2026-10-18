# Qersh for WooCommerce

Qersh (Qersh Pay) is an Android app that reads the incoming-transfer notifications of e-wallets (Vodafone Cash, Orange Cash, e& money and others) on the merchant's phone and sends a signed webhook to the merchant's server, so orders are confirmed automatically with no payment gateway. Qersh is a wallet notification relay, not a payment service provider.

This repository is the WordPress plugin that receives that webhook in a WooCommerce store. Money never passes through Qersh or through the plugin: the customer transfers from their wallet straight to the merchant's wallet, the merchant's phone tells the store that the transfer arrived (amount, payer, operator transaction id, risk verdict), and the plugin confirms the order it belongs to. The plugin never calls Qersh or any other outside service; Qersh has no hosted API.

This README is for developers. Merchants read [readme.txt](readme.txt) (the wordpress.org page) and the settings page of the plugin.

## What it is

- **A checkout method** ("E-wallet transfer", gateway id `qersh`) for the classic checkout, the Checkout block and the "Pay for order" page. One required field: the wallet number (`01…`) the customer will transfer from. The order waits as `pending` until a deadline the merchant sets (120 minutes by default).
- **A webhook receiver** for the `qersh.v1` contract, one URL per store wallet: `POST qersh/v1/webhook/<wallet id>` on the REST API, and a fallback on `?wc-api=qersh` for stores whose REST API is blocked. The signature is checked on the raw body bytes with the algorithm of the Qersh kit's PHP verifier, and every delivery is claimed once through a unique index.
- **A matcher**: a literal port of the core's `matchTransfer` (payer, then the time the money arrived, then the amount), wrapped in named store rules. A full transfer completes the order with `payment_complete()` and the phone gets `{"status":"matched","reference":"1042"}` within seconds. What the matcher cannot settle goes to "Needs a decision", where the merchant attaches it to an order with one click.
- **Transaction ids**: after transferring, the customer can enter the operator's transaction id on the order page (`POST qersh/v1/order-txn`), on any wallet, while `store.txn_entry` is on (the default). It decides only a transfer whose message does not show the sender's number, by exact equality with the webhook's `provider_txn_id`. The id identifies the order; it never proves the payment (the phone's `auto_confirm_ok` still decides).
- **Admin screens** under WooCommerce, Qersh: Wallets (store URL, setup links, QR code, connection keys, delete and restore), Transfers (Needs a decision, Unmatched, Personal transfers), Settings (export and import), Status (diagnostics and "Check your store URL").

The plugin works with any e-wallet the Qersh app reads (Vodafone Cash, Orange Cash, e& money and others) and never looks at the wallet type: the envelope's `wallet.provider` is stored as information only, and no rule reads it. It decides each transfer from the webhook alone: the sender's number, the transaction id, the amount, the time the money arrived and the app's `auto_confirm_ok`. A transfer that shows the sender's full number pays the order it matches on its own when `auto_confirm_ok` is true (in the app, keep the wallet's "Send sender details" on "Full name and number"). A transfer without the sender's number (a name or a handle only, a masked number, or nothing) waits for one click in "Needs a decision", or for the transaction id the customer enters on the order page. A transfer with no open order for it is filed as a personal transfer, and an order created after the money arrived is never matched to it automatically (`skew_min = 0`).

## Requirements

- WordPress 6.9 or later, WooCommerce 11.0 or later (tested up to 7.1 and 11.2), PHP 7.4 or later (32-bit PHP included: all times are whole seconds).
- Store currency EGP, HTTPS (the app sends to `http://` only for private hosts: `localhost`, `127.x`, RFC 1918 addresses and single-label `.local` names).
- MySQL or MariaDB with InnoDB (the row locks need it), or SQLite through the SQLite Database Integration plugin.
- HPOS on or off. No runtime dependency: the plugin ships its own autoloader and one vendored library (qrcode-generator 2.0.4, MIT).
- On the merchant's side: the paid Qersh Android app (https://qersh.cfd/en/download) on the phone that receives the wallet's messages.

## Install

- From wordpress.org once it is listed: Plugins, Add New, search "Qersh for WooCommerce".
- Until then, or for a pinned version: download `qersh-for-woocommerce.zip` from the GitHub release of a tag and upload it in Plugins, Add New, Upload Plugin.
- From this repository: the plugin runs from a checkout (no build step), but install the zip from `node scripts/build-zip.mjs` on a real site, so tests and tools stay out.

Activation creates five tables (`{prefix}qersh_deliveries`, `_transfers`, `_orders`, `_wallets`, `_locks`). Deactivation removes nothing; deleting the plugin removes its data only when the setting "remove data on uninstall" or `QERSH_WC_REMOVE_ALL_DATA` says so.

## Connect a wallet

1. WooCommerce, Qersh, Wallets, add a wallet: the 11-digit wallet number customers transfer to (`01…`) and an optional name (without one, the number is shown). There is no wallet type to choose: the Qersh app asks for it when the link is opened. The store gives the wallet a 12-character id such as `k7q2m9x4p1rz`, which is part of its URL.
2. **With the key (default):** "Generate your developer's link with the connection key". The store creates the connection key (`qsk_` + 43 base64url characters) and shows a `qersh://setup?…&secret=…` link once, with a QR code. Open it on the wallet's phone in the Qersh app, save the wallet (or "Update wallet"), then tap "Test connection". Nothing needs pasting back. For a wallet that already has a key, the new key is pending until the first delivery signed with it arrives; the old key stays valid meanwhile and for 24 hours after.
3. **Without the key:** "Another way: your developer's link". The link carries the URL and label only. Paste it in the app (Add wallet, "Have a link from your developer?"); the phone generates the key, and the merchant pastes the key, or the whole message the phone shares, into "Connection key or the wallet link from the phone".
4. On the phone, "Test connection" sends a `webhook.test` delivery and two negative probes. The wallet card in the store then shows the test, the app version and what the phone reports about its permissions.

The setup link (the app calls it "your developer's link") never carries `events` (listing events switches off every event that is not listed) and never carries `provider`: the plugin does not know the wallet type. A store URL change (domain, `www`, https, permalinks) is fixed with a new link without the key, which updates the URL and keeps the key. The setting `store.allow_key_link` switches the key link off; it also disappears when the wallet's key comes from `wp-config.php`.

## Webhook URL

The URL the plugin shows is built with `rest_url()`, so it takes one of two forms:

| Permalinks | URL |
|---|---|
| Pretty | `https://shop.example/wp-json/qersh/v1/webhook/k7q2m9x4p1rz` |
| Plain | `https://shop.example/index.php?rest_route=/qersh/v1/webhook/k7q2m9x4p1rz` |

**Fallback:** `https://shop.example/?wc-api=qersh&qw=k7q2m9x4p1rz` reaches the same handler through `parse_request` at priority -1, before WooCommerce is checked and without the REST API, so it works where a security plugin or a firewall blocks `/wp-json/`, and still answers 503 JSON when WooCommerce is off. It always ends the request with `exit`. The status page offers it when "Check your store URL" finds the REST route blocked. The order page uses the same fallback for its two routes (`&qo=status`, `&qo=txn`) when the REST API is blocked for visitors.

Other routes, all POST only and not listed in the REST index:

- `qersh/v1/order-status`, body `{order_id, key}`: the live status of the thank-you page, one of `waiting|partial|paid|review|expired|cancelled`, plus `claim` (whether a transaction id is saved) whenever the order page takes one. Never cached (`no-store`, LiteSpeed Cache told not to store it); every refusal is the same 404 `qersh_not_found`.
- `qersh/v1/order-txn`, body `{order_id, key, txn}` (at most 2 KB): the transaction id the customer enters, see below.

Before deactivating the plugin or WooCommerce, pause the wallet in the app: on the fallback URL the storefront would answer 200 with a page, and the phone would count transfers as delivered.

## Response codes

| Situation | HTTP | Body | What the phone does |
|---|---|---|---|
| Valid delivery, whatever the outcome | 200 | `{"status":"matched\|partial\|overpaid\|unmatched\|review\|ok\|ignored","reference":…,"note":…}` | Delivered |
| Duplicate (same `delivery_id`) | 200 | the stored reply, plus the pending `status_updates` | Delivered |
| Signature missing, malformed, stale or wrong; wallet without a key; unreadable key | 401 | `qersh_invalid_signature` with `reason`: `no_key`, `raw_body_unavailable`, `missing_header`, `malformed_header`, `stale_timestamp`, `bad_signature` | Slow retries for 3 days, alerts the merchant at once |
| Unknown wallet id, or a wallet deleted less than 72 hours ago | 404 | `qersh_unknown_wallet` or `qersh_wallet_deleted` | As 401 ("route not found"); a deleted wallet comes back with one click on "Restore wallet" |
| Wallet deleted 72 hours ago or more | 410 | `qersh_wallet_gone` | Final for that delivery: each transfer is tried once, then kept 90 days in "Did not reach your store"; the wallet keeps running on the phone |
| Body larger than 64 KB | 413 | `qersh_too_large` | As 401 (the app never sends it) |
| Broken JSON, or not a `qersh.v1` envelope | 400 | `rest_invalid_json` (WordPress, REST route) or `qersh_invalid_envelope` with `reason` | As 401 (the app never sends it) |
| The same delivery is being processed; lock timeout or deadlock after one retry | 429 + `Retry-After: 30` | `qersh_busy` | "Busy": nothing leaves the wallet for 30 seconds, and it does not count against the circuit breaker |
| WooCommerce off, tables missing | 503 | `qersh_unavailable` | "Not responding": retries for up to 7 days, counts against the circuit breaker (3 in a row pause the wallet and probe with one delivery); `Retry-After` is not read on 5xx |
| Unexpected error (a caught `Throwable`) | 500 | `qersh_internal_error` | As 503; the claim is released |

Never a 4xx for a duplicate or an ignored event, never a 3xx, and 410 only for a wallet deleted at least 72 hours ago. On the fallback, a method other than POST gets 405 `qersh_method_not_allowed` and an unknown `qo` 404 `qersh_no_route`. Every 2xx reply to a wallet carries up to 50 pending `status_updates` (`{transfer_id, status, reference, note}`) that correct what an earlier reply told the phone: a manual attach, a dismissal, or a match by a transaction id entered after the transfer arrived. `reference` is `$order->get_order_number()` (what the merchant and the customer see, sequential-number plugins included), never the internal id.

## Transaction id route

`POST qersh/v1/order-txn` (fallback `?wc-api=qersh&qo=txn`), the same for every wallet. The permission check needs a valid order key, our method, `store.txn_entry` on and the order's wallet still in the configuration (turned off or not; a deleted wallet has no entry). The wallet type is never read, and any failure is the same 404. The format is checked before any database access, the counter is one atomic `UPDATE`, and the body is the same whatever is behind the id (unknown, used, contested), so the route reveals nothing about transfers. The evaluation runs after the reply (or in Action Scheduler), never inside the customer's request.

| Situation | HTTP | Body |
|---|---|---|
| Saved, or the same id again | 200 | `{"status":"saved","txn":"<id>","changes_left":n}` |
| Wrong order key, unknown order, another method, setting off, wallet deleted | 404 | `qersh_not_found` (one body for all) |
| Empty, no id in it, more than one candidate id, only a wallet or phone number, text over 400 characters or body over 2 KB | 400 | `qersh_txn_empty`, `qersh_txn_format`, `qersh_txn_several`, `qersh_txn_wallet_number`, `qersh_txn_too_long` |
| The order no longer waits for a transfer | 409 | `qersh_txn_closed` |
| All five changes used | 409 | `qersh_txn_locked` |
| Changed too often (3 at once, then one per 10 minutes) | 429 + `Retry-After` | `qersh_txn_wait` |
| Lock busy | 429 + `Retry-After: 5` | `qersh_txn_busy` |
| WooCommerce off, tables missing | 503 | `qersh_unavailable` |

The customer may type the id or paste the whole transfer message. One set of generic rules reads every wallet's ids (`Core\TxnId::canonical_typed()`, and its twin `canonicalTyped()` in `assets/js/txn-normalize.js`; both run `tests/vectors/txn-normalize.json`):

- Arabic-Indic and Persian digits become Latin; direction marks, invisible characters and tatweel are removed; Arabic and full-width separators become their Latin forms.
- An id typed alone may be split by spaces or `- . # :` and is read whole (`9133 7200 4518` is `913372004518`).
- In a pasted message, links, dates, times and amounts (`150.00`, a number followed by a currency word) are ignored. Runs of letters and digits split by exactly one separator on a line are read as one number, so an id printed in two parts is read whole, never as its first part. Mobile numbers, the order's payer number and the store's wallet numbers are left out.
- An id is 6 to 40 letters or digits with at least one digit, kept as text in upper case (leading zeros stay). Exactly one must be left: several give `qersh_txn_several`; none gives `qersh_txn_format`, or `qersh_txn_wallet_number` when only such numbers were found.

The id decides a transfer only when it equals the canonical form of the webhook's `provider_txn_id` (separators removed, upper case) exactly, never a part of it, and only for a transfer that arrived without the sender's number. Only the canonical id is stored, never the pasted text; a refused text stays on the order page. The same id works for 72 hours after the deadline cancelled the order.

## Matching

`Core\Matcher` is the core's `matchTransfer` in whole seconds, with the core's safe differences (a transfer without a whole amount, or an unreadable candidate, gives `review`). Windows come from the configuration: `late_window_min = 120`, `skew_min = 0`. `MatchPolicy` adds named store rules, each tested outside the parity suite:

- **Before the matcher:** an unknown risk reason gives `review` even with `auto_confirm_ok` true; an operator transaction id that an earlier transfer of the store already carries, on any wallet, gives `review` (`duplicate_txn`); candidates are shaped from the orders themselves under the lock: a cancelled, refunded, manually completed or flagged order in the window is an obstacle (unreadable price, so it blocks "the only order" and turns `unmatched` into `review`, `closed_order`), and an order our deadline cancelled is still a candidate while the money arrived in time.
- **Rounding:** with `round_up_to_pound` (default on) a whole-pound transfer pays an order whose remainder rounds up to it, because wallet transfers made by code carry whole pounds only; exact amounts win first (orders of 139.50 and 140 and a transfer of 140 pay the 140 order).
- **Rule 4a, the customer's transaction id:** only while `store.txn_entry` is on, when the matcher says `unmatched` for a transfer without the sender's number (a name or a handle only, a masked number, or nothing) that carries a `provider_txn_id`, on any wallet. Exactly one live order must hold the same canonical id, and every gate must pass: same wallet (an order of another wallet fails here), order created before the money arrived, id entered after the operator's message, exact remaining amount after rounding, and no competing unpaid order of the same amount on that wallet. Any failure gives `review` with a reason (`txn_wallet`, `txn_window`, `txn_amount`, `txn_contested`, `closed_order`). The id never bypasses `auto_confirm_ok`, an unknown risk, a duplicate id or a forgery flag.
- **No payer** (a name or a handle only, a masked number such as `0111***3445`, or nothing) and no typed id that settles it: `review` (`no_payer`, or `masked`), replied `{"status":"review","note":"…"}`. An id the customer types later can still decide it, through the same gates.
- **Personal transfer:** when no order could take the transfer at arrival, `unmatched` with reason `personal`, shown in its own tab and not in the badge. A later order never takes it automatically; the merchant may attach it after a warning.
- **After the matcher:** a payment after the deadline that exceeds the remainder is `overpaid`; an `unmatched` transfer from a payer with an order paid within the late window is noted "maybe a second payment"; a partial order past its deadline is `review` (`partial_overdue`), never revived on its own.

`auto_confirm_ok: false` (the phone did not confirm the transfer, for example one of the first transfers of a wallet whose balance Qersh has not confirmed, or one above the wallet's auto-confirm limit) gives `review` before any payer search; the plugin still suggests the payer's order for a one-click attach. `transfer.updated` never confirms an order; with `suspected_forgery` it flags the transfer, puts a paid order `on-hold` without email, and keeps a pending one out of matching and expiry until the merchant decides.

## Order states and deadline

- `process_payment()` leaves the order `pending`, writes its `qersh_orders` row, the payer key, `_qersh_deadline` = now + `order_ttl_min` (15 to 1440, default 120), and sends the transfer instructions email.
- A full transfer writes the `paid` decision in the matching transaction; completion (`payment_complete()`) then runs after the reply on `shutdown` priority 5 when `fastcgi_finish_request()` or `litespeed_finish_request()` exists, inside the request under a 6-second guard otherwise, or in one Action Scheduler action. Every path claims the row first, so stock and emails happen once.
- At deadline + `late_window_min` an order that received nothing is cancelled (`_qersh_expired_at`, stock released, one customer note for every wallet, which says whether the order page still takes a transaction id). A partial order, an order whose transaction id matches a transfer waiting for the merchant, and a flagged order are never cancelled automatically; they go to "Needs a decision".
- A transfer that arrives late for money that arrived in time revives the cancelled order after a stock check, or goes to `review` (`stock_short`).
- Customers cannot cancel an order that has received money or holds a live transaction id. WooCommerce's own unpaid-order cancel skips our orders with a deadline, and the stock hold is stretched to deadline + late window.
- An order created in the admin has no row until the customer pays it on "Pay for order"; the wait starts then.

## Configuration

Behaviour lives in one option, `qersh_wc_config` (not autoloaded), edited on the Settings tab and exported or imported there as JSON without keys and without runtime state. The gateway's own option `woocommerce_qersh_settings` holds only `enabled`, title, description and instructions.

`QERSH_WC_CONFIG` in `wp-config.php` replaces the option with a JSON string and makes the settings page read-only:

```json
{
  "receiver": {
    "endpoint": { "path": "qersh/v1/webhook" },
    "tolerance_sec": 300,
    "events": ["transfer.received", "transfer.updated", "webhook.test"],
    "matching": { "late_window_min": 120, "skew_min": 0 },
    "dedup": { "keep_days": 180 }
  },
  "wallets": [
    { "id": "k7q2m9x4p1rz", "label": "Main wallet", "receive_address": "01001234567", "enabled": true }
  ],
  "store": {
    "order_ttl_min": 120,
    "min_total_minor": null,
    "max_total_minor": null,
    "round_up_to_pound": true,
    "allow_key_link": true,
    "txn_entry": true,
    "admin_cancel_email_on_expiry": true,
    "unmatched_retention_days": 90,
    "remove_data_on_uninstall": false
  }
}
```

- `receiver` uses the names of the core's `QershReceiverConfig` without `keys`. `endpoint.path` is fixed (`qersh/v1/webhook`, relative to the REST root; the wallet id is appended). `events` is a subset of the three shown and must include `transfer.received`; any other event with a valid signature is answered `ignored`. Omitted parts take the defaults shown.
- `wallets[].id` matches `^[a-z0-9]{12}$`; the id of a deleted wallet cannot be reused (restore it instead). `receive_address` is the wallet's 11-digit e-wallet number (`01…`); other spellings (`+20…`, spaces, Arabic digits) are stored normalised. A wallet has no type: a `provider` in an older configuration, an import or `QERSH_WC_CONFIG` is accepted and dropped, never an error (the companion schema keeps it as a deprecated, ignored field). `label` may be empty: the number is shown instead.
- Money is in piasters (`*_minor`), times in minutes or days as named.
- An import or a constant that breaks a rule is refused with a message naming the field.

The companion schema [`qersh-woocommerce.config.schema.json`](qersh-woocommerce.config.schema.json) (JSON Schema 2020-12) describes this shape. It takes `tolerance_sec`, `matching` and `dedup` from the core's receiver schema by JSON pointer (`urn:qersh:receiver-config:1#/properties/…`, vendored as `fixtures/qersh.config.schema.json`), so they never drift; load both schemas into your validator. `node scripts/check-config-schema.mjs` validates the example above and the plugin's defaults against it.

Connection keys never live in the configuration. Per wallet they are stored by `Secrets` (the WordPress secrets API when it exists, otherwise a sodium envelope keyed from `LOGGED_IN_KEY` and `LOGGED_IN_SALT`), or given as a constant that beats every stored key:

```php
define( 'QERSH_WC_KEY_K7Q2M9X4P1RZ', 'qsk_…' ); // the wallet id in upper case
define( 'QERSH_WC_REMOVE_ALL_DATA', true );      // optional: delete everything on uninstall
```

A key is at least 24 characters without white space (the app's own rule); only its fingerprint (the first 8 hex characters of `sha256("qersh-key:" + key)`) is ever shown.

## What the store keeps

- `qersh_deliveries`: every `delivery_id` with its stored reply, for `dedup.keep_days` (180).
- `qersh_transfers`: one row per transfer: amount, payer key and name, operator transaction id (in its canonical form when it has one), risk, outcome and reason, the order it paid, and the envelope's `wallet.provider` as information only (no rule reads it). A transfer that paid no order loses `payer_key` and `payer_name` (set to NULL) after `unmatched_retention_days` (90), and a transfer whose order is deleted loses them at once; the row itself (amount, times, operator transaction id, outcome) stays.
- `qersh_orders`: one row per order of the method: payer key, price and paid amount in piasters, state (`open|partial|paid|review|expired|closed`), deadline, completion claim, the customer's transaction id.
- `qersh_wallets`: runtime state per wallet (last delivery, last test, phone state, deletion tombstone); never exported.
- Order meta `_qersh_*`, options `qersh_wc_*`. WooCommerce logs (source `qersh`) never hold a key, a signature, a body or what a customer pasted; numbers show their last three digits.
- The personal data exporter and eraser cover the payer number, name and transaction ids.

## Test locally

The kit's sender (vendored, pinned by `fixtures.lock.json`) signs a sample delivery exactly as the phone does:

```sh
URL=https://shop.test/wp-json/qersh/v1/webhook/k7q2m9x4p1rz
node fixtures/sink/send.mjs "$URL" --secret qsk_... --event webhook.test   # 200 {"status":"ok"}
node fixtures/sink/send.mjs "$URL" --secret qsk_... --probe                # also a bad signature and a stale t: both 401
node fixtures/sink/send.mjs "$URL" --secret qsk_... --late 72              # a backfill: must not pay an order opened later
```

`send.mjs` keeps the vector's `transfer_id`, so on one store every run after the first gets the first verdict back. `tests/e2e/deliver.mjs` adds what end-to-end tests need: a fresh `transfer_id` and operator id per run, and options for the payer, the transaction id, risk and events (`--provider` only sets the envelope's `wallet.provider`: the verdict is the same whatever it says):

```sh
node tests/e2e/deliver.mjs "$URL" --secret qsk_... --payer 01001234567 --amount 140
node tests/e2e/deliver.mjs "$URL" --secret qsk_... --no-payer --txn 913372004518 --amount 140
node tests/e2e/deliver.mjs "$URL" --secret qsk_... --delivery-id 0b6f1a52-6a43-4e8e-9d4a-2f1c7a1e9b10 --repeat 6 --concurrent
node tests/e2e/deliver.mjs "$URL" --secret qsk_... --reasons notification_only,balance_unavailable --auto-ok false --channel notification
node tests/e2e/deliver.mjs "$URL" --secret qsk_... --event device.heartbeat
```

Each prints one JSON line per response (`{label, status, ms, body}`). Give the wallet a test key with "Connection key or the wallet link from the phone", or define `QERSH_WC_KEY_<ID>`. The same URL with `?wc-api=qersh&qw=<id>` exercises the fallback.

## Troubleshooting

- **401 `bad_signature`**: the key in the store and on the phone differ. Generate a new key link, or paste the phone's message again. The wallet card says when the phone signs with a newer key than the store's.
- **401 `no_key`**: the wallet has no key yet, or the key was erased after a deletion; link it again.
- **401 `stale_timestamp`**: the server clock is more than 300 seconds off.
- **404 or 200 HTML from "Test connection"**: plain permalinks with a hand-typed `/wp-json/` URL, an old domain, or a blocked REST API. Use the URL the wallet card shows, or the fallback.
- **403, a challenge or a redirect**: a security plugin, a Cloudflare challenge (`cf-mitigated`) or an http-to-https redirect stops the phone; "Check your store URL" names it and shows the fallback.
- **Orders confirmed late or emails missing**: with no `fastcgi_finish_request()` and `DISABLE_WP_CRON` without a real cron, deferred emails wait in Action Scheduler; the plugin warns on its pages.
- **The site time zone** should be `Africa/Cairo`; deadlines shown to customers are always Cairo time.

## Development

```sh
composer install
composer test:unit                      # PHPUnit 9.6, no WordPress
composer lint:php                       # php -l on every PHP file
composer lint:phpcs                     # phpcs.xml.dist: WordPress-Extra, WooCommerce-Core, PHPCompatibilityWP 7.4-
composer lint:plugin-check              # phpcs-plugin-check.xml: the PHPCS part of Plugin Check's plugin_repo checks
composer analyse                        # PHPStan level 6 on src/ with the WordPress and WooCommerce stubs
node scripts/check-fixtures.mjs         # vendored copies, risk reasons, qrcode-generator hash
node scripts/check-wording.mjs          # the legal wording guard
node scripts/check-readme.mjs --wp 7.1.3
node scripts/check-config-schema.mjs
node scripts/build-llms-section.mjs --check
node tests/parity/run.mjs               # the compiled core against the PHP port
node --test tests/js/txn-normalize.test.mjs tests/js/checkout-blocks.test.mjs
```

Rules for code are in [AGENTS.md](AGENTS.md) and [docs-dev/CONTRACTS.md](docs-dev/CONTRACTS.md): PHP 7.4 syntax, WordPress coding standards, `src/Core` pure PHP, every SQL statement prepared, late escaping, and the legal wording. `docs/02`, `docs/16` and `docs/17`, cited in comments, are the maintainer's design notes in the Qersh repository, which is not public. Files under `fixtures/`, `tests/php/fixtures/` and `tests/parity/{core,kit}/` are copies from the Qersh repository: change them there and run `node scripts/import-from-qersh.mjs "<path to the Qersh repository>"`.

Integration tests (WordPress test library) and the Playwright end-to-end suite run in CI on Linux (wp-env, MySQL 8.0); the nightly job adds SQLite on 32-bit PHP.

## Release

1. Update the version in `qersh-for-woocommerce.php` (header and `QERSH_WC_VERSION`), `Stable tag` in `readme.txt`, the changelog and upgrade notice there, and `CHANGELOG.md`. Raise `Tested up to` and `WC tested up to` with each WordPress and WooCommerce major release.
   `readme.txt` must stay under 10 KB (`check-readme.mjs` fails at 10,240 bytes and warns when less than 1 KB is left). Its Upgrade Notice keeps only the current version, and its Changelog the newest versions that fit (always the current one; drop the oldest first); `CHANGELOG.md` keeps every version. The first time an entry is dropped, end the readme's Changelog with a link to `CHANGELOG.md` on GitHub.
2. Run every check above on a clean `main`.
3. `node scripts/build-zip.mjs` builds `dist/qersh-for-woocommerce.zip` from an allow-list (the plugin file, `uninstall.php`, `readme.txt`, `LICENSE`, `src/`, `templates/`, `assets/js`, `assets/css`, the vendored QR library and its licence, `languages/`) and fails on any dotfile or development file. It also fails when `.distignore` (which the SVN deploy uses instead of the allow-list) would ship a different set of files.
4. Tag with the bare version, never a `v` prefix: `git tag 0.1.0 && git push origin 0.1.0`. The release workflow builds the same zip and attaches it to the GitHub release.
5. After wordpress.org accepts the plugin, the deploy workflow pushes the tag to SVN, and the owner confirms the release from the wordpress.org email. The deploy action, the only third-party code that sees the SVN credentials, is pinned to a full commit SHA (`scripts/check-workflows.mjs` fails on a tag); to move to a newer release, read its `deploy.sh` first. A confirmed tag never changes, so even raising `Tested up to` is a patch release.

## Links

- Integration page: https://qersh.cfd/en/integrations/woocommerce
- The Qersh app: https://qersh.cfd/en/download
- Support: the plugin's forum on wordpress.org, or support@qersh.cfd
- Repository: https://github.com/e48981652-hash/qersh-for-woocommerce
- Security reports: see [SECURITY.md](SECURITY.md)
- The `qersh.v1` envelope schema: [fixtures/webhook.v1.schema.json](fixtures/webhook.v1.schema.json); the receiver schema: [fixtures/qersh.config.schema.json](fixtures/qersh.config.schema.json)

## License

GPL-2.0-or-later. See [LICENSE](LICENSE). qrcode-generator 2.0.4 by Kazuhiko Arase is MIT ([assets/vendor/qrcode-generator/LICENSE](assets/vendor/qrcode-generator/LICENSE)).
