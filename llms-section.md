## WooCommerce

<!-- Generated from the README of qersh-for-woocommerce by scripts/build-llms-section.mjs. Do not edit here. -->

Qersh (Qersh Pay) is an Android app that reads the incoming-transfer notifications of e-wallets (Vodafone Cash, Orange Cash, e& money, InstaPay) on the merchant's phone and sends a signed webhook to the merchant's server, so orders are confirmed automatically with no payment gateway. Qersh is a wallet notification relay, not a payment service provider.

Plugin: **Qersh for WooCommerce** 0.1.0 (wordpress.org slug `qersh-for-woocommerce`, GPLv2 or later; requires WordPress 6.9, WooCommerce 11.0, PHP 7.4). Repository: https://github.com/e48981652-hash/qersh-for-woocommerce. Docs page: https://qersh.cfd/en/integrations/woocommerce. Gateway id `qersh`, REST namespace `qersh/v1`. Everything below is the plugin README, section by section.

### What it is

- **A checkout method** ("E-wallet transfer", gateway id `qersh`) for the classic checkout, the Checkout block and the "Pay for order" page. One required field: the wallet number (or InstaPay address) the customer will transfer from. The order waits as `pending` until a deadline the merchant sets (120 minutes by default).
- **A webhook receiver** for the `qersh.v1` contract, one URL per store wallet: `POST qersh/v1/webhook/<wallet id>` on the REST API, and a fallback on `?wc-api=qersh` for stores whose REST API is blocked. The signature is checked on the raw body bytes with the algorithm of the Qersh kit's PHP verifier, and every delivery is claimed once through a unique index.
- **A matcher**: a literal port of the core's `matchTransfer` (payer, then the time the money arrived, then the amount), wrapped in named store rules. A full transfer completes the order with `payment_complete()` and the phone gets `{"status":"matched","reference":"1042"}` within seconds. What the matcher cannot settle goes to "Needs a decision", where the merchant attaches it to an order with one click.
- **Orange Cash transaction ids**: Orange Cash messages rarely name the payer, so after transferring the customer enters the operator's transaction id on the order page (`POST qersh/v1/order-txn`). The id identifies the order; it never proves the payment (the phone's `auto_confirm_ok` still decides).
- **Admin screens** under WooCommerce, Qersh: Wallets (store URL, setup links, QR code, connection keys, delete and restore), Transfers (Needs a decision, Unmatched, Personal transfers), Settings (export and import), Status (diagnostics and "Check your store URL").

What confirms on its own: Vodafone Cash and e& money (their messages name the payer; the wallet's "Send sender details" must be "Full name and number"), and Orange Cash with a transaction id. InstaPay (every notification arrives with `auto_confirm_ok: false` today) and bank SMS (no balance in the message) wait for one click. A transfer with no open order for it is filed as a personal transfer, and an order created after the money arrived is never matched to it automatically (`skew_min = 0`).

### Requirements

- WordPress 6.9 or later, WooCommerce 11.0 or later (tested up to 7.1 and 11.2), PHP 7.4 or later (32-bit PHP included: all times are whole seconds).
- Store currency EGP, HTTPS (the app sends to `http://` only for private hosts: `localhost`, `127.x`, RFC 1918 addresses and single-label `.local` names).
- MySQL or MariaDB with InnoDB (the row locks need it), or SQLite through the SQLite Database Integration plugin.
- HPOS on or off. No runtime dependency: the plugin ships its own autoloader and one vendored library (qrcode-generator 2.0.4, MIT).
- On the merchant's side: the paid Qersh Android app (https://qersh.cfd/en/download) on the phone that receives the wallet's messages.

### Install

- From wordpress.org once it is listed: Plugins, Add New, search "Qersh for WooCommerce".
- Until then, or for a pinned version: download `qersh-for-woocommerce.zip` from the GitHub release of a tag and upload it in Plugins, Add New, Upload Plugin.
- From this repository: the plugin runs from a checkout (no build step), but install the zip from `node scripts/build-zip.mjs` on a real site, so tests and tools stay out.

Activation creates five tables (`{prefix}qersh_deliveries`, `_transfers`, `_orders`, `_wallets`, `_locks`). Deactivation removes nothing; deleting the plugin removes its data only when the setting "remove data on uninstall" or `QERSH_WC_REMOVE_ALL_DATA` says so.

### Connect a wallet

1. WooCommerce, Qersh, Wallets, add a wallet: label, type (`vodafone_cash`, `etisalat_cash`, `orange_cash`, `instapay_app`, `bank_nbe`, `bank_cib`, `bank_egbank`) and the number or InstaPay address customers transfer to. The store gives it a 12-character id such as `k7q2m9x4p1rz`, which is part of its URL.
2. **With the key (default):** "Generate your developer's link with the connection key". The store creates the connection key (`qsk_` + 43 base64url characters) and shows a `qersh://setup?…&secret=…` link once, with a QR code. Open it on the wallet's phone in the Qersh app, save the wallet (or "Update wallet"), then tap "Test connection". Nothing needs pasting back. For a wallet that already has a key, the new key is pending until the first delivery signed with it arrives; the old key stays valid meanwhile and for 24 hours after.
3. **Without the key:** "Another way: your developer's link". The link carries the URL, label and wallet type only. Paste it in the app (Add wallet, "Have a link from your developer?"); the phone generates the key, and the merchant pastes the key, or the whole message the phone shares, into "Connection key or the wallet link from the phone".
4. On the phone, "Test connection" sends a `webhook.test` delivery and two negative probes. The wallet card in the store then shows the test, the app version and what the phone reports about its permissions.

The setup link (the app calls it "your developer's link") never carries `events`: listing events switches off every event that is not listed. A store URL change (domain, `www`, https, permalinks) is fixed with a new link without the key, which updates the URL and keeps the key. The setting `store.allow_key_link` switches the key link off; it also disappears when the wallet's key comes from `wp-config.php`.

### Webhook URL

The URL the plugin shows is built with `rest_url()`, so it takes one of two forms:

| Permalinks | URL |
|---|---|
| Pretty | `https://shop.example/wp-json/qersh/v1/webhook/k7q2m9x4p1rz` |
| Plain | `https://shop.example/index.php?rest_route=/qersh/v1/webhook/k7q2m9x4p1rz` |

**Fallback:** `https://shop.example/?wc-api=qersh&qw=k7q2m9x4p1rz` reaches the same handler through `parse_request` at priority -1, before WooCommerce is checked and without the REST API, so it works where a security plugin or a firewall blocks `/wp-json/`, and still answers 503 JSON when WooCommerce is off. It always ends the request with `exit`. The status page offers it when "Check your store URL" finds the REST route blocked. The order page uses the same fallback for its two routes (`&qo=status`, `&qo=txn`) when the REST API is blocked for visitors.

Other routes, all POST only and not listed in the REST index:

- `qersh/v1/order-status`, body `{order_id, key}`: the live status of the thank-you page, one of `waiting|partial|paid|review|expired|cancelled`, plus `claim` for an Orange Cash order. Never cached (`no-store`, LiteSpeed Cache told not to store it); every refusal is the same 404 `qersh_not_found`.
- `qersh/v1/order-txn`, body `{order_id, key, txn}` (at most 2 KB): the Orange Cash transaction id, see below.

Before deactivating the plugin or WooCommerce, pause the wallet in the app: on the fallback URL the storefront would answer 200 with a page, and the phone would count transfers as delivered.

### Response codes

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

### Transaction id route (Orange Cash)

`POST qersh/v1/order-txn` (fallback `?wc-api=qersh&qo=txn`). The permission check needs a valid order key, our method, `store.txn_entry` on and an active Orange Cash wallet on the order; any failure is the same 404. The format is checked before any database access, the counter is one atomic `UPDATE`, and the body is the same whatever is behind the id (unknown, used, contested), so the route reveals nothing about transfers. The evaluation runs after the reply (or in Action Scheduler), never inside the customer's request.

| Situation | HTTP | Body |
|---|---|---|
| Saved, or the same id again | 200 | `{"status":"saved","txn":"<id>","changes_left":n}` |
| Wrong order key, unknown order, another method, not an Orange Cash wallet, setting off, wallet deleted | 404 | `qersh_not_found` (one body for all) |
| Empty, wrong shape, more than one candidate number, a wallet number, body over 2 KB | 400 | `qersh_txn_empty`, `qersh_txn_format`, `qersh_txn_several`, `qersh_txn_wallet_number`, `qersh_txn_too_long` |
| The order no longer waits for a transfer | 409 | `qersh_txn_closed` |
| All five changes used | 409 | `qersh_txn_locked` |
| Changed too often (3 at once, then one per 10 minutes) | 429 + `Retry-After` | `qersh_txn_wait` |
| Lock busy | 429 + `Retry-After: 5` | `qersh_txn_busy` |
| WooCommerce off, tables missing | 503 | `qersh_unavailable` |

The customer may type the id or paste the whole transfer message: Arabic-Indic and Persian digits become Latin, direction marks and tatweel are removed, and digit runs that normalise to the order's or the store's wallet number are dropped before exactly one run of the expected length is taken (14 digits today, 10 for the older format; `fixtures/txn-patterns.json`). Only the canonical id is stored, never the pasted text. The same id works for 72 hours after the deadline cancelled the order.

### Matching

`Core\Matcher` is the core's `matchTransfer` in whole seconds, with the core's safe differences (a transfer without a whole amount, or an unreadable candidate, gives `review`). Windows come from the configuration: `late_window_min = 120`, `skew_min = 0`. `MatchPolicy` adds named store rules, each tested outside the parity suite:

- **Before the matcher:** an unknown risk reason gives `review` even with `auto_confirm_ok` true; an operator transaction id already settled by another transfer of the same provider gives `review` (`duplicate_txn`); candidates are shaped from the orders themselves under the lock: a cancelled, refunded, manually completed or flagged order in the window is an obstacle (unreadable price, so it blocks "the only order" and turns `unmatched` into `review`, `closed_order`), and an order our deadline cancelled is still a candidate while the money arrived in time.
- **Rounding:** with `round_up_to_pound` (default on) a whole-pound transfer pays an order whose remainder rounds up to it, because `*9#` transfers carry no piasters; exact amounts win first (orders of 139.50 and 140 and a transfer of 140 pay the 140 order).
- **Rule 4a, the Orange Cash transaction id:** only when the matcher says `unmatched` for a transfer without a payer, the provider is `orange_cash`, and the id has the expected shape. Exactly one live order must hold the id, and every gate must pass: same wallet, order created before the money arrived, id entered after the operator's message, exact remaining amount after rounding, and no competing unpaid order of the same amount on that wallet. Any failure gives `review` with a reason (`txn_wallet`, `txn_window`, `txn_amount`, `txn_contested`, `closed_order`). The id never bypasses `auto_confirm_ok`, an unknown risk, a duplicate id or a forgery flag.
- **No payer** (Orange Cash without an id, bank SMS, a masked number such as `0111***3445`): `review` (`no_payer`, or `masked`), replied `{"status":"review","note":"…"}`.
- **Personal transfer:** when no order could take the transfer at arrival, `unmatched` with reason `personal`, shown in its own tab and not in the badge. A later order never takes it automatically; the merchant may attach it after a warning.
- **After the matcher:** a payment after the deadline that exceeds the remainder is `overpaid`; an `unmatched` transfer from a payer with an order paid within the late window is noted "maybe a second payment"; a partial order past its deadline is `review` (`partial_overdue`), never revived on its own.

`auto_confirm_ok: false` (every InstaPay notification today, bank SMS, the first transfers of a wallet whose balance Qersh has not confirmed) gives `review` before any payer search; the plugin still suggests the payer's order for a one-click attach. `transfer.updated` never confirms an order; with `suspected_forgery` it flags the transfer, puts a paid order `on-hold` without email, and keeps a pending one out of matching and expiry until the merchant decides.

### Order states and deadline

- `process_payment()` leaves the order `pending`, writes its `qersh_orders` row, the payer key, `_qersh_deadline` = now + `order_ttl_min` (15 to 1440, default 120), and sends the transfer instructions email.
- A full transfer writes the `paid` decision in the matching transaction; completion (`payment_complete()`) then runs after the reply on `shutdown` priority 5 when `fastcgi_finish_request()` or `litespeed_finish_request()` exists, inside the request under a 6-second guard otherwise, or in one Action Scheduler action. Every path claims the row first, so stock and emails happen once.
- At deadline + `late_window_min` an order that received nothing is cancelled (`_qersh_expired_at`, stock released, a customer note by wallet type). A partial order, an order whose transaction id matches a transfer waiting for the merchant, and a flagged order are never cancelled automatically; they go to "Needs a decision".
- A transfer that arrives late for money that arrived in time revives the cancelled order after a stock check, or goes to `review` (`stock_short`).
- Customers cannot cancel an order that has received money or holds a live transaction id. WooCommerce's own unpaid-order cancel skips our orders with a deadline, and the stock hold is stretched to deadline + late window.
- An order created in the admin has no row until the customer pays it on "Pay for order"; the wait starts then.

### Configuration

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
    { "id": "k7q2m9x4p1rz", "label": "Store Vodafone Cash", "provider": "vodafone_cash", "receive_address": "01001234567", "enabled": true }
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
- `wallets[].id` matches `^[a-z0-9]{12}$`; the id of a deleted wallet cannot be reused (restore it instead). `receive_address` is an 11-digit number (`01…`) or `name@instapay` for `instapay_app`; other spellings (`+20…`, spaces, Arabic digits) are stored normalised.
- Money is in piasters (`*_minor`), times in minutes or days as named.
- An import or a constant that breaks a rule is refused with a message naming the field.

The companion schema [`qersh-woocommerce.config.schema.json`](https://github.com/e48981652-hash/qersh-for-woocommerce/blob/main/qersh-woocommerce.config.schema.json) (JSON Schema 2020-12) describes this shape. It takes `tolerance_sec`, `matching` and `dedup` from the core's receiver schema by JSON pointer (`urn:qersh:receiver-config:1#/properties/…`, vendored as `fixtures/qersh.config.schema.json`), so they never drift; load both schemas into your validator. `node scripts/check-config-schema.mjs` validates the example above and the plugin's defaults against it.

Connection keys never live in the configuration. Per wallet they are stored by `Secrets` (the WordPress secrets API when it exists, otherwise a sodium envelope keyed from `LOGGED_IN_KEY` and `LOGGED_IN_SALT`), or given as a constant that beats every stored key:

```php
define( 'QERSH_WC_KEY_K7Q2M9X4P1RZ', 'qsk_…' ); // the wallet id in upper case
define( 'QERSH_WC_REMOVE_ALL_DATA', true );      // optional: delete everything on uninstall
```

A key is at least 24 characters without white space (the app's own rule); only its fingerprint (the first 8 hex characters of `sha256("qersh-key:" + key)`) is ever shown.

### What the store keeps

- `qersh_deliveries`: every `delivery_id` with its stored reply, for `dedup.keep_days` (180).
- `qersh_transfers`: one row per transfer: amount, payer key and name, operator transaction id, risk, outcome and reason, the order it paid. Transfers that matched nothing are deleted after `unmatched_retention_days` (90).
- `qersh_orders`: one row per order of the method: payer key, price and paid amount in piasters, state (`open|partial|paid|review|expired|closed`), deadline, completion claim, the customer's transaction id.
- `qersh_wallets`: runtime state per wallet (last delivery, last test, phone state, deletion tombstone); never exported.
- Order meta `_qersh_*`, options `qersh_wc_*`. WooCommerce logs (source `qersh`) never hold a key, a signature, a body or what a customer pasted; numbers show their last three digits.
- The personal data exporter and eraser cover the payer number, name and transaction ids.

### Test locally

The kit's sender (vendored, pinned by `fixtures.lock.json`) signs a sample delivery exactly as the phone does:

```sh
URL=https://shop.test/wp-json/qersh/v1/webhook/k7q2m9x4p1rz
node fixtures/sink/send.mjs "$URL" --secret qsk_... --event webhook.test   # 200 {"status":"ok"}
node fixtures/sink/send.mjs "$URL" --secret qsk_... --probe                # also a bad signature and a stale t: both 401
node fixtures/sink/send.mjs "$URL" --secret qsk_... --late 72              # a backfill: must not pay an order opened later
```

`send.mjs` keeps the vector's `transfer_id`, so on one store every run after the first gets the first verdict back. `tests/e2e/deliver.mjs` adds what end-to-end tests need: a fresh `transfer_id` and operator id per run, and options for the payer, the provider, the transaction id, risk and events:

```sh
node tests/e2e/deliver.mjs "$URL" --secret qsk_... --payer 01001234567 --amount 140
node tests/e2e/deliver.mjs "$URL" --secret qsk_... --provider orange_cash --no-payer --txn 12345678901234 --amount 140
node tests/e2e/deliver.mjs "$URL" --secret qsk_... --delivery-id 0b6f1a52-6a43-4e8e-9d4a-2f1c7a1e9b10 --repeat 6 --concurrent
node tests/e2e/deliver.mjs "$URL" --secret qsk_... --reasons notification_only,balance_unavailable --auto-ok false --channel notification
node tests/e2e/deliver.mjs "$URL" --secret qsk_... --event device.heartbeat
```

Each prints one JSON line per response (`{label, status, ms, body}`). Give the wallet a test key with "Connection key or the wallet link from the phone", or define `QERSH_WC_KEY_<ID>`. The same URL with `?wc-api=qersh&qw=<id>` exercises the fallback.

### Troubleshooting

- **401 `bad_signature`**: the key in the store and on the phone differ. Generate a new key link, or paste the phone's message again. The wallet card says when the phone signs with a newer key than the store's.
- **401 `no_key`**: the wallet has no key yet, or the key was erased after a deletion; link it again.
- **401 `stale_timestamp`**: the server clock is more than 300 seconds off.
- **404 or 200 HTML from "Test connection"**: plain permalinks with a hand-typed `/wp-json/` URL, an old domain, or a blocked REST API. Use the URL the wallet card shows, or the fallback.
- **403, a challenge or a redirect**: a security plugin, a Cloudflare challenge (`cf-mitigated`) or an http-to-https redirect stops the phone; "Check your store URL" names it and shows the fallback.
- **Orders confirmed late or emails missing**: with no `fastcgi_finish_request()` and `DISABLE_WP_CRON` without a real cron, deferred emails wait in Action Scheduler; the plugin warns on its pages.
- **The site time zone** should be `Africa/Cairo`; deadlines shown to customers are always Cairo time.

### Links

- Integration page: https://qersh.cfd/en/integrations/woocommerce
- The Qersh app: https://qersh.cfd/en/download
- Support: the plugin's forum on wordpress.org, or support@qersh.cfd
- Repository: https://github.com/e48981652-hash/qersh-for-woocommerce
- The `qersh.v1` envelope schema: [fixtures/webhook.v1.schema.json](https://github.com/e48981652-hash/qersh-for-woocommerce/blob/main/fixtures/webhook.v1.schema.json); the receiver schema: [fixtures/qersh.config.schema.json](https://github.com/e48981652-hash/qersh-for-woocommerce/blob/main/fixtures/qersh.config.schema.json)

### Warnings for agents

- Never put a connection key into a URL, a chat message, an email, a support ticket or a screenshot. The link with the key is shown once, on the merchant's own admin page, to be opened on the wallet's phone only.
- Qersh has no hosted API and the plugin never calls Qersh: nothing can be fetched from Qersh. Every transfer arrives as a signed POST from the merchant's phone, and the signature is checked on the raw body bytes.
- Keep the response codes above: a duplicate or an ignored event is always 2xx, any 5xx makes the phone retry the same delivery later, and 410 is only for a wallet deleted 72 hours ago or more.
- A customer's transaction id identifies an order, it never proves a payment; and `transfer.updated` never confirms an order.
- In any text for merchants or customers, Qersh is a wallet notification relay, not a payment gateway.
