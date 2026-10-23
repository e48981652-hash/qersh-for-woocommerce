# Qersh for WooCommerce: implementation contracts

The design is `docs/17-woocommerce-plugin-design.md` (v3, Arabic) in the Qersh repository (not public). The evidence behind it is `docs/16-woocommerce-research.md` in the same repository. This file fixes the names every module shares, so parts written in parallel fit together. Where this file and docs/17 disagree, docs/17 wins on behaviour and this file wins on names; report the conflict.

Reference copies of the Qersh core live in this repository; never edit them (see `fixtures.lock.json`):
- `tests/parity/core/*.js` is the compiled core (`packages/qersh-webhook`).
- `tests/parity/kit/match.mjs` is the kit's reference matcher.
- `fixtures/test-vectors.json`, `fixtures/webhook.v1.schema.json`, `fixtures/sink/send.mjs`, `fixtures/qersh.config.schema.json`.
- `tests/php/fixtures/verify.php` is the kit's PHP verifier.

The TypeScript sources and their tests are in `packages/qersh-webhook/src/core` and `packages/qersh-webhook/test` of the Qersh repository, and the kit tests in its `kit/tools/test-match.mjs`. The same core is public in [n8n-nodes-qersh](https://github.com/e48981652-hash/n8n-nodes-qersh/tree/main/nodes/Qersh/lib/core). Read them when you port.

## 1. Rules for all code
- PHP 7.4 syntax only. No union types, `match`, nullsafe `?->`, named arguments, enums, `readonly`, constructor promotion, `str_contains`, `str_starts_with`, `str_ends_with`, `array_is_list`, or first-class callable syntax.
- WordPress Coding Standards style: tabs, `array()` or `[]` (pick `array()`), spaces inside parentheses, Yoda conditions, snake_case methods and variables. Class names StudlyCaps.
- Namespace `QershPay\WooCommerce`. `QershPay\WooCommerce\Foo\Bar` lives in `src/Foo/Bar.php`; the autoloader is `src/Autoloader.php`.
- Every PHP file starts with `<?php`, a file docblock, then `defined( 'ABSPATH' ) || exit;`.
- `src/Core/*` is pure PHP: no WordPress or WooCommerce function, constant or class, and no I/O. It may use `declare( strict_types=1 );`. Unit tests load it without WordPress.
- **Prefixes:** functions, options, hooks, transients and meta keys start with `qersh_wc_`, or `_qersh_` for order meta. Tables are `{$wpdb->prefix}qersh_*`. REST namespace `qersh/v1`. Text domain `qersh-for-woocommerce`.
- **Forbidden:** `parse_url` (use `wp_parse_url`), `strip_tags` (`wp_strip_all_tags`), `rand`/`mt_rand` (`random_int`/`random_bytes`), `curl_*`, `file_put_contents` into the plugin folder, `ini_set`, `date_default_timezone_set`, heredoc/nowdoc, `error_log` of anything personal, `eval`, `extract`, `serialize` of untrusted data.
- **Escaping:** escape late (`esc_html`, `esc_attr`, `esc_url`, `wp_kses_post`). Sanitize early (`sanitize_text_field`, `sanitize_key`, `absint`, `wp_unslash` first). Never sanitize keys or webhook bodies.
- **Admin actions:** every one checks `current_user_can( 'manage_woocommerce' )` and a nonce.
- **SQL:** every query uses `$wpdb->prepare()`, with `%i` for table names (WP 6.2+). Add `// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table` where needed. Wrap queries that carry personal data in `$wpdb->suppress_errors()`.
- **Strings:** wrap with `__()`/`esc_html__()` and the text domain, and put `/* translators: */` above any string with placeholders. No translation call before `init`.
- **Numbers and times:**
  - Money is always integer minor units (piasters): `int $amount_minor`.
  - Times are always integer UTC seconds: `int $now = time()`. Never milliseconds (Playground PHP is 32-bit).
  - ISO strings from envelopes are parsed with `Core\Time::iso_to_sec()`.
- **Bidi isolation:** every number, address, transaction id or time shown inside Arabic text goes through `Format::ltr( $value, $context )` (docs/17 §7).
- **Logs:** never a key, signature, body or raw customer input. Phone numbers and transaction ids show their last three characters only (`Logger` does this).

## 2. Layout and ownership
| Path | Module group |
|---|---|
| `qersh-for-woocommerce.php`, `src/Autoloader.php`, `src/Plugin.php` | Skeleton (given), then B extends `Plugin` |
| `src/Core/{Payer,Time,Money}.php` | Skeleton (given; tests by A2) |
| `src/Core/{Verifier,Envelope}.php` + tests | A1 |
| `src/Core/{Matcher,Risk}.php` + tests, and tests for Payer, Time, Money | A2 |
| `src/Core/{SetupLink,Config,TxnId}.php` + tests | A3 |
| `tests/parity/*` (runner + generators) | A4 (after A1–A3) |
| `src/Store/*`, `src/ConfigStore.php`, `src/Secrets.php`, `src/Logger.php` + tests | B |
| `src/Gateway.php`, `src/BlocksIntegration.php`, `src/PayerField.php`, `src/Format.php`, `src/OrderStatusRoute.php`, `src/ClaimRoute.php`, `src/Emails/Instructions.php`, `templates/checkout/*`, `templates/order/*`, `templates/emails/instructions*.php`, `assets/js/{checkout-blocks,payer-field,thankyou}.js`, `assets/css/front.css` | C |
| `src/Webhook/*`, `src/MatchPolicy.php`, `src/Orders.php`, `src/Claims.php`, `src/Completion.php`, `src/Expiry.php` | D |
| `src/Admin/*`, `src/Diagnostics.php`, `src/Privacy.php`, `src/Emails/AdminAlert.php`, `templates/emails/admin-alert*.php`, `templates/admin/*`, `assets/js/admin.js`, `assets/css/admin.css`, `assets/vendor/qrcode-generator/*`, `uninstall.php` | E |
| `languages/*`, `readme.txt`, `README.md`, `CHANGELOG.md`, `.github/*`, `.wp-env*.json`, `scripts/*` (except import-from-qersh), `phpcs.xml.dist`, `phpstan.neon` | F |

A module calls another module only through the public methods listed here. A module that needs a method not listed here adds it to its own files, never to another module's.

## 3. Constants and bootstrap (`qersh-for-woocommerce.php`)
- **Constants:**
  - `QERSH_WC_VERSION = '0.1.1'`, `QERSH_WC_FILE = __FILE__`, `QERSH_WC_DIR`, `QERSH_WC_URL`. Our scripts and styles are versioned by `Plugin::asset_version( string $path )` (`QERSH_WC_VERSION` and the file's modification time), never by `QERSH_WC_VERSION` alone.
  - `QERSH_WC_DB_VERSION = 2` (equal to `Store\Schema::VERSION`; see §7 for what schema 2 changed), `QERSH_WC_MIN_WC = '11.0'`.
  - Optional in wp-config:
    - `QERSH_WC_CONFIG`: a JSON string; makes the settings read-only.
    - `QERSH_WC_REMOVE_ALL_DATA`: bool.
    - `QERSH_WC_KEY_<WALLET ID IN UPPER CASE>`: a connection key for that wallet, which beats every stored key.
- **At include time:**
  - `Autoloader::register()`.
  - `add_action( 'parse_request', [ Webhook\Fallback::class, 'maybe_handle' ], -1 )`. This runs before the WooCommerce check, so it answers 503 when WooCommerce is off.
  - `add_filter( 'woocommerce_defer_transactional_emails', [ Plugin::class, 'filter_defer_emails' ] )`.
  - `add_filter( 'lang_dir_for_domain', [ Plugin::class, 'lang_dir' ], 10, 3 )`: the bundled `languages/` folder when the plugin is network-activated (WordPress registers the Domain Path folder only for plugins active on the site). A folder WordPress found, such as a language pack, passes through.
  - `add_action( 'before_woocommerce_init', [ Plugin::class, 'declare_compatibility' ] )`.
  - `register_activation_hook( QERSH_WC_FILE, [ Plugin::class, 'activate' ] )`.
  - `register_deactivation_hook( QERSH_WC_FILE, [ Plugin::class, 'deactivate' ] )`: cancels the two recurring actions; `Webhook\Module::schedule_recurring()` schedules them again after reactivation. No data is deleted.
  - `add_action( 'plugins_loaded', [ Plugin::class, 'boot' ], 20 )`.
- **`Plugin::boot()`:**
  - Always: runs the schema check, registers the REST routes (`rest_api_init`, routes answer 503 JSON when WooCommerce or the tables are missing) and the cron/Action Scheduler hooks.
  - Only when WooCommerce ≥ `QERSH_WC_MIN_WC` is active: the gateway (`woocommerce_payment_gateways`), Blocks (`woocommerce_blocks_payment_method_type_registration`), emails (`woocommerce_email_classes`, `woocommerce_email_actions`), admin, privacy and the order hooks.
- **Module entry points.** `Plugin::boot()` calls each package's static `register( Plugin $plugin, bool $wc_ready ): void`, guarded by `class_exists`. So `Plugin.php` is never edited by C, D or E.
  - `Checkout` (C, `src/Checkout.php`): the gateway, Blocks, the two storefront routes and the thank-you hooks.
  - `Webhook\Module` (D, `src/Webhook/Module.php`): the webhook route, the Action Scheduler handlers, the order hooks that keep `qersh_orders` in step, and the expiry and stock filters.
  - `Admin\Module` (E, `src/Admin/Module.php`): menus, admin-post, AJAX, the order box, privacy, emails and diagnostics hooks. It calls `Admin\Notices::register( bool $wc_ready )` (E, `src/Admin/Notices.php`) in both states: the admin notices hook themselves from `current_screen` on the screens they concern (our page, WooCommerce → Settings → Payments, the order screens) and from `load-plugins.php` for the WooCommerce-missing line, print through `wp_admin_notice()`, and remember their dismissals in `Notices::OPTION`.
- **Routes and WooCommerce off.** Each module registers its own REST routes on `rest_api_init` even when `$wc_ready` is false. Routes then answer 503 JSON `{ "code": "qersh_unavailable" }` through `Plugin::unavailable_response()`.

## 4. Service container (`src/Plugin.php`)
`Plugin::instance()` returns the singleton. Services are created lazily, and each is built with `new X( $plugin )`; take what you need from `$plugin` inside.
| Getter | Class |
|---|---|
| `config_store()` | `ConfigStore` |
| `config()` | array: the effective config with defaults, from `ConfigStore::get()` |
| `db()` | `Store\Db` |
| `deliveries()` | `Store\Deliveries` |
| `transfers()` | `Store\Transfers` |
| `orders_index()` | `Store\OrdersIndex` |
| `wallets()` | `Store\Wallets` (runtime state rows) |
| `secrets()` | `Secrets` |
| `logger()` | `Logger` |
| `policy()` | `MatchPolicy` |
| `orders()` | `Orders` |
| `claims()` | `Claims` |
| `completion()` | `Completion` |
| `expiry()` | `Expiry` |
| `diagnostics()` | `Diagnostics` |

Also: `Plugin::wc_ready(): bool`, `Plugin::tables_ready(): bool`, `Plugin::wallet( string $id ): ?array` (the config entry), and `Plugin::is_webhook_request(): bool` (docs/17 §4.1 Plugin row; reads REQUEST_URI, `rest_route` and `wc-api`).

## 5. Config (`ConfigStore`, `Core\Config`)
The shape is exactly the JSON in docs/17 §4.2.
- **Defaults:** `matching.skew_min = 0` and `late_window_min = 120`; `store.allow_key_link = true` and `store.txn_entry = true`.
- **Wallet entry** (no wallet type, §13):
  - Fields: `{ id, label, receive_address, enabled }` (`Core\Config::WALLET_FIELDS`).
  - `id` matches `^[a-z0-9]{12}$`.
  - `label` may be `''`: screens then show "E-wallet" and the number (`Admin\View::wallet_name()`).
  - `receive_address` holds the 11-digit e-wallet number `01XXXXXXXXX`, normalised by `Core\Payer::normalize()` (`Core\Config::wallet_number()`); anything else is refused with the path `wallets[n].receive_address`.
  - A legacy `provider` key in any input (the saved option, an import, the `QERSH_WC_CONFIG` constant, a deleted wallet's snapshot) is accepted and dropped by `Config::with_defaults()`, never an error. `ConfigStore::snapshot( array $wallet ): array` keeps only `WALLET_FIELDS` in the snapshot of a deleted wallet. The companion schema keeps `wallets[].provider` as a deprecated, ignored string.
- **`ConfigStore`:**
  - Read: `get(): array` (with defaults), `is_constant(): bool`. A wallet of the stored option whose `receive_address` is no e-wallet number (an InstaPay address a test build saved) is left out of `get()` (`ConfigStore::split_legacy( array $wallets ): array`, pure), named by `dropped(): array` (`[ id, label, address ]` each, for the Wallets screen's notice), and removed by the next save, which marks its row deleted with its stored entry; a restore of it is refused by `Config::validate()`. `QERSH_WC_CONFIG` holding such a wallet is refused as a whole (`issues()`).
  - Write: `save( array $config ): true|\WP_Error`, refused when constant.
  - Transfer: `export(): array` (no runtime state) and `import( array $config ): true|\WP_Error`.
- **Wallet labels:** `ConfigStore::clean_label( string $label ): string` runs `sanitize_text_field()` and cuts to `ConfigStore::LABEL_MAX` (60) characters, the same limit as `Core\SetupLink::LABEL_MAX`.
- **Storage:** option `qersh_wc_config`, autoload off. Gateway form fields stay in `woocommerce_qersh_settings` (enabled, title, description, instructions only).

## 6. Core API (pure PHP, `src/Core`)
Arrays mirror the TypeScript objects of the core, with the same keys. Where the core uses milliseconds, PHP uses seconds.
- **`Verifier`:**
  - `parse_header( $header ): ?array` returns `[ 't' => int, 'v1' => string[] ]`, by the rules of `parseSignatureHeader`: header ≤ 8192 characters, `t` of 1–13 digits, well-formed v1 of 64 hex, keys trimmed.
  - `verify( ?string $raw_body, $header, array $keys, int $now, int $tolerance_sec = 300 ): array`.
    - Success: `[ 'ok' => true, 't' => int, 'key_index' => int, 'v1_index' => int ]`.
    - Failure: `[ 'ok' => false, 'reason' => 'no_key'|'raw_body_unavailable'|'missing_header'|'malformed_header'|'stale_timestamp'|'bad_signature', 't' => ?int ]`.
    - Reason order as in `verifySignature`.
    - HMAC once per key, then `hash_equals` against every v1.
    - `v1_index` is the position, in the header, of the v1 that matched.
  - `sign_header( int $t, string $raw_body, array $keys ): string` (for tests and the self-test).
- **`Envelope`:**
  - `parse( ?string $raw ): array` returns `[ 'ok' => true, 'envelope' => array ]` or `[ 'ok' => false, 'reason' => 'invalid_json'|'not_an_object'|'not_qersh_v1' ]`.
  - `delivery_headers( array $headers ): array` takes lower-case header names.
  - `summarize( array $envelope ): array`: the core `summarize` output, including `transfer.payer.key` and `suspected_forgery`.
- **`Payer`:** `normalize( $raw ): ?string` (= `normalizePayer`), `key_of( ?array $counterparty ): ?string` (= `payerKeyOf`).
- **`Matcher`:**
  - `match( $transfer, array $candidates, int $now, array $options = array() ): array` is `matchTransfer`, with these differences:
    - Candidates `{ order_number: string, price_minor: ?int, paid_minor: int, payer_key: string, created_at: int, expires_at: int, status: string }`, where a null or non-integer `price_minor` means unreadable.
    - Options `{ late_window_min: int, skew_min: int }`.
    - The transfer is the envelope `data` array (`received_at` is ISO and is converted inside).
  - The result has the same keys as `MatchResult`.
  - `judge_update( $data ): array` is `judgeUpdate`.
- **`Risk`** (A2): `KNOWN_REASONS`, the 22 values of `$defs.riskReason` in `fixtures/webhook.v1.schema.json` (checked by `scripts/check-fixtures.mjs`), and `unknown_reasons( $reasons ): array`.
- **`Time`:** `iso_to_sec( $iso ): ?int`, `sec_to_iso( int $sec ): string`.
- **`Money`:** `round_up_pound( int $minor ): int` (= `ceil(minor/100)*100`), `to_minor( string $decimal ): ?int`.
- **`SetupLink`:**
  - Build and check: `build( array $params ): string`, `validate( array $params ): array`.
  - Read: `parse( $text ): array`, `extract( $text ): ?string`.
  - Helpers: `is_usable_secret( $s ): bool`, `is_local_http_host( string $host ): bool` (the literal `PRIVATE_HOST` regex), `validate_receiver_url( $url ): array`.
  - All as in `setup-link.ts`.
  - Plus `extract_secret( $text ): array`, returning `[ 'ok' => true, 'secret' => string, 'source' => 'message'|'link'|'line'|'key' ]` or `[ 'ok' => false, 'error' => 'keyless_link'|'not_found'|'invalid' ]` (docs/17 §4.1 SetupLink row).
- **`Config`:**
  - Constants: `DEFAULTS`, `WALLET_FIELDS = array( 'id', 'label', 'receive_address', 'enabled' )`, `EVENTS_ALLOWED = [ 'transfer.received', 'transfer.updated', 'webhook.test' ]`. There is no `PROVIDERS` (§13).
  - Validation: `with_defaults( array $c ): array`, `validate( array $c ): array` (a list of `[ 'path' => .., 'message' => .. ]`; receiver rules are literal from `validateConfig` minus `keys`, plus the plugin rules of docs/17 §4.2). No rule looks at a wallet type.
  - `wallet_number( $address ): ?string`: the stored form `01XXXXXXXXX` of a store wallet number, or null.
  - Keys and ids: `is_acceptable_key( $k ): bool`, `wallet_id_ok( $id ): bool`, `new_wallet_id(): string`, `new_key(): string` (`qsk_` + base64url without padding of `random_bytes(32)`), `fingerprint( string $key ): string` (the first 8 hex characters of `sha256( 'qersh-key:' . $key )`).
- **`TxnId`** (not a port: plugin code, the same for every wallet, §13):
  - Constants: `MIN_ID = 6`, `MAX_ID = 40`, `MAX_LENGTH = 400`. The canonical form is ASCII `[0-9A-Z]` only, at least one digit, `MIN_ID` to `MAX_ID` characters, upper case, leading zeros kept (text, never a number). No per-wallet patterns, lengths or classes exist.
  - `canonical_typed( $raw, string $payer_key = '', array $store_numbers = array() ): array` returns `[ 'ok' => true, 'txn' => string ]` or `[ 'ok' => false, 'error' => 'qersh_txn_empty'|'qersh_txn_too_long'|'qersh_txn_format'|'qersh_txn_several'|'qersh_txn_wallet_number' ]` (docs/17 §4.10). The reading rules:
    1. Normalise: Arabic-Indic and Persian digits to Latin; bidi, invisible marks and tatweel removed; white space and the Arabic and full-width separators normalised. More than 400 characters is `qersh_txn_too_long`.
    2. Hard breaks, before anything is read: links, dates, times and amounts become line breaks, so that an amount, a date or a time typed alone (or a date after the id) is never read as an id. A date needs a day of 1 to 31 and a month of 1 to 12 (day or month first, or `yyyy-mm-dd`), a time an hour of 0 to 23 and minutes and seconds of 0 to 59, so that digits that are no date or time (`91-33-72 004518`) are never cut from an id.
    3. The id alone: one line of runs of `[0-9A-Za-z]` split by spaces or `- . # :` gives its compact value (separators removed, upper case) when that has the canonical form. A compact value that is a mobile number (`01XXXXXXXXX`, `+201…`, `00201…`, `201…`, with an Egyptian mobile prefix `010`, `011`, `012` or `015`), the payer key or a store number (any spelling) is `qersh_txn_wallet_number`.
    4. Otherwise a pasted message: a group is a sequence of runs on one line separated by exactly one separator, and its candidate is its runs joined (an id split by single separators is read whole, never as its first part); a group longer than `MAX_ID` gives each run. Mobile numbers, the payer key and store numbers are left out; equal candidates count once. Exactly one: ok; more: `qersh_txn_several`; none: `qersh_txn_format`, or `qersh_txn_wallet_number` when numbers were left out.
  - `canonical_stored( $value ): ?string`: the canonical form of the webhook's `provider_txn_id` (same normalisation, separators removed, upper case), or null when it is empty, not a string, without a digit, or outside `MIN_ID`..`MAX_ID`. A typed id decides a transfer only by exact equality of the two canonical forms.
  - The JavaScript twin `assets/js/txn-normalize.js` (`window.qershWcNormalize`) has `canonicalTyped( raw, payerKey, storeNumbers )` with the same results, plus `normalizeText`, `normalizePayer`, `numberOf`, `isStoreNumber`, `checkPayer( raw, storeNumbers )` and `hasRtl`. Both read the shared vectors `tests/vectors/txn-normalize.json` (`tests/php/unit/Core/TxnIdTest.php`, `tests/js/txn-normalize.test.mjs`).

## 7. Database tables (`Store\Schema`, dbDelta, `qersh_wc_db_version`)
Exact columns from docs/17 §4.3. Times are `BIGINT UNSIGNED` UTC seconds, money `BIGINT`. Text uses `$wpdb->get_charset_collate()`.

`{prefix}qersh_deliveries`:
```
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, delivery_id CHAR(36) NOT NULL, wallet VARCHAR(32) NOT NULL, event VARCHAR(40) NOT NULL,
redelivery VARCHAR(16) NULL, state VARCHAR(12) NOT NULL, claim_token CHAR(32) NOT NULL, http_status SMALLINT NULL,
reply_json TEXT NULL, claimed_at BIGINT UNSIGNED NOT NULL, answered_at BIGINT UNSIGNED NULL,
PRIMARY KEY  (id), UNIQUE KEY delivery_id (delivery_id), KEY claimed_at (claimed_at)
```
`{prefix}qersh_transfers`:
```
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, transfer_id VARCHAR(40) NOT NULL, wallet VARCHAR(32) NOT NULL, provider VARCHAR(32) NOT NULL DEFAULT '',
delivery_id CHAR(36) NOT NULL, amount_minor BIGINT NULL, currency VARCHAR(8) NOT NULL, payer_key VARCHAR(80) NULL, payer_name VARCHAR(120) NULL,
provider_txn_id VARCHAR(40) NULL, money_at BIGINT UNSIGNED NOT NULL, sms_at BIGINT UNSIGNED NULL, risk VARCHAR(16) NULL, risk_reasons TEXT NULL,
auto_confirm_ok TINYINT NOT NULL DEFAULT 0, balance_chain VARCHAR(24) NULL, outcome VARCHAR(16) NULL, reason VARCHAR(40) NULL,
claim_eval VARCHAR(40) NULL, claim_eval_at BIGINT UNSIGNED NULL, order_id BIGINT UNSIGNED NULL, flag VARCHAR(32) NULL,
phone_sync VARCHAR(8) NOT NULL DEFAULT 'none', phone_sync_since BIGINT UNSIGNED NULL, phone_sync_count TINYINT NOT NULL DEFAULT 0,
note VARCHAR(255) NULL, created_at BIGINT UNSIGNED NOT NULL, updated_at BIGINT UNSIGNED NOT NULL,
PRIMARY KEY  (id), UNIQUE KEY transfer_id (transfer_id), KEY provider_txn_id (provider_txn_id), KEY outcome_created (outcome,created_at),
KEY order_id (order_id), KEY payer_key (payer_key), KEY wallet_sync (wallet,phone_sync)
```
`provider` is the envelope's `wallet.provider` as the phone sent it (`Orders::provider_of()`: printable ASCII without spaces, at most 32 characters, `''` when absent), information only: no rule reads it, and it is not in `Transfers::REQUIRED`. `provider_txn_id` holds the canonical form (`TxnId::canonical_stored()`) when the delivered id has one, else the trimmed value when it fits 40 characters, else NULL.
`{prefix}qersh_orders`:
```
order_id BIGINT UNSIGNED NOT NULL, wallet VARCHAR(32) NOT NULL, payer_key VARCHAR(80) NOT NULL, price_minor BIGINT NOT NULL, paid_minor BIGINT NOT NULL DEFAULT 0,
state VARCHAR(12) NOT NULL, created_at BIGINT UNSIGNED NOT NULL, expires_at BIGINT UNSIGNED NOT NULL, paid_at BIGINT UNSIGNED NULL,
completion VARCHAR(10) NOT NULL DEFAULT 'none', completion_at BIGINT UNSIGNED NULL, claim_txn VARCHAR(40) NULL, claim_at BIGINT UNSIGNED NULL,
claim_tries TINYINT UNSIGNED NOT NULL DEFAULT 0,
PRIMARY KEY  (order_id), KEY payer_state (payer_key,state,created_at), KEY wallet_state (wallet,state,created_at), KEY claim_txn (claim_txn)
```
`{prefix}qersh_wallets`:
```
wallet VARCHAR(32) NOT NULL, state VARCHAR(10) NOT NULL DEFAULT 'active', deleted_at BIGINT UNSIGNED NULL, gone_at BIGINT UNSIGNED NULL,
key_erased_at BIGINT UNSIGNED NULL, snapshot TEXT NULL, last_delivery_at BIGINT UNSIGNED NULL, last_event VARCHAR(40) NULL,
last_heartbeat_at BIGINT UNSIGNED NULL, last_device_state VARCHAR(255) NULL, grace_until BIGINT UNSIGNED NULL, sender_masked TINYINT NOT NULL DEFAULT 0,
auto_seen TINYINT NOT NULL DEFAULT 0, seen_host VARCHAR(190) NULL, seen_form VARCHAR(16) NULL, last_test_at BIGINT UNSIGNED NULL,
last_test_app_version VARCHAR(20) NULL, last_test_provider VARCHAR(32) NULL, last_test_number_masked VARCHAR(16) NULL,
silent_since BIGINT UNSIGNED NULL, silent_notified TINYINT NOT NULL DEFAULT 0,
PRIMARY KEY  (wallet), KEY state_deleted (state,deleted_at)
```
`last_test_provider` is information only, like `qersh_transfers.provider`. `sender_masked` and `auto_seen` are learned from the deliveries of every wallet (Diagnostics).
`{prefix}qersh_locks`: `name VARCHAR(100) NOT NULL, created_at BIGINT UNSIGNED NOT NULL, PRIMARY KEY  (name)`.

Value sets:
- `qersh_deliveries.state`: `processing|done`.
- `qersh_transfers.outcome`: `matched|partial|overpaid|unmatched|review|attached|dismissed`.
- `qersh_orders.state`: `open|partial|paid|review|expired|closed`.
- `qersh_orders.completion`: `none|due|running|done`.
- `qersh_transfers.reason` codes: as in docs/17 §4.6 and §4.3.

**Schema versions.** `Schema::VERSION = 2` (the fallback when `QERSH_WC_DB_VERSION` is not defined). `Schema::install()` runs dbDelta, then the private `Schema::upgrade( int $from )` for a store recorded below version 2 (or with no version), then records the version. Schema 2 gave `qersh_transfers.provider` its default `''` and the key `provider_txn_id` (dbDelta), dropped the schema-1 key `provider_txn (provider,provider_txn_id)` (`Schema::OBSOLETE_INDEXES`, dbDelta never drops an index), deleted the options `qersh_wc_txn_drift` and `qersh_wc_txn_long` (`Schema::OBSOLETE_OPTIONS`), and rewrote each stored `transfers.provider_txn_id` in the form of `Core\TxnId::canonical_stored()` (batches of `Schema::TXN_BATCH` by row id; a value without a canonical form stays). `Admin\Uninstall::OPTIONS` still lists both option names, for a store whose upgrade never ran. The SQLite drop-in does not apply dbDelta's `SET DEFAULT`, so an upgraded SQLite store keeps `provider` without a default: `Transfers::insert()` writes `''` when a row has no `provider`, and nothing relies on the column's default.

**Lost tables.** `Schema::table_missing( ?string $prefix = null )` is called by `Db` when a query reports one of our tables missing. `Schema::lost(): bool` is then true for the rest of the request, so `Plugin::tables_ready()` is false. `Schema::forget_version()` deletes `qersh_wc_db_version` (at once, or right after the open transaction ends) so the next request's `maybe_upgrade()` creates the tables again. A second loss within ten minutes pauses automatic installs for ten minutes.

## 8. Store API (`src/Store`, package B; others call only these)
- **`Db`:**
  - Basics: `table( string $short ): string`, `wpdb(): \wpdb`, `is_sqlite(): bool`.
  - Transactions: `begin(): void` (START TRANSACTION, then `innodb_lock_wait_timeout = 5` saved and restored on MySQL), `commit(): void`, `rollback(): void`.
  - Locks: `lock( array $names ): void` takes names in the total order and throws `Store\LockTimeout`. That order is at most one `txn:`/`trf:` name first, then `payer:` names sorted; the method sorts and dedupes, and runs one `INSERT … ON DUPLICATE KEY UPDATE` per name. A transaction-id lock is `txn:<canonical id>` (`MatchPolicy::txn_lock()`), the same for a payerless delivery of the id and for the customer who types it, on any wallet.
  - Queries: `query( string $sql, array $args = array() ): int|false`, `row(...)`, `rows(...)`, `var(...)`; each prepares, suppresses errors and maps lock-timeout and deadlock errors to `LockTimeout`.
- **`Deliveries`:**
  - `claim( string $delivery_id, string $wallet, string $event, ?string $redelivery, int $now ): array` returns `[ 'state' => 'claimed', 'token' => string ]`, `[ 'state' => 'done', 'http_status' => int, 'reply' => array ]` or `[ 'state' => 'busy' ]` (docs/17 §4.4 step 6, including the 60 s reclaim).
  - `fence( string $delivery_id, string $token, int $http_status, array $reply, int $now ): bool`.
  - `release( string $delivery_id, string $token ): void`; `purge( int $before ): int`.
- **`Transfers`:**
  - Read: `get( string $transfer_id ): ?array`, `find_by_txn( string $txn ): array` (every transfer with this canonical id, on any wallet, oldest first; a caller that needs one wallet keeps the rows of that wallet).
  - Write: `insert( array $row ): bool` (INSERT IGNORE), `update( string $transfer_id, array $fields ): void`.
  - `fence_match( string $transfer_id, int $order_id ): bool` (docs/17 §4.6 rule 4a, the first fence): a row with no order and no verdict, or still `review` with the reason `no_payer` or `masked`.
  - Phone sync: `pending_sync( string $wallet, int $now, int $limit = 50 ): array`, `note_synced( array $transfer_ids, int $now ): void`.
  - Lists and privacy: `list_page( array $filter, int $page, int $per_page ): array` (with total; there is no filter by wallet type, a `provider` key is ignored), `count_needs_decision(): int`, `purge_personal( int $before ): int`.
- **`OrdersIndex`:**
  - Read and write: `get( int $order_id ): ?array`, `upsert( array $row ): void` (keeps `created_at`, `claim_txn` and `claim_tries` on update), `update( int $order_id, array $fields ): void`, `delete( int $order_id ): void`.
  - Candidates: `for_payer( string $payer_key, int $from, int $to ): array`, `for_wallet( string $wallet, int $from, int $to ): array`.
  - Claims: `claimants( string $txn ): array`, `bump_claim( int $order_id, string $txn, int $now ): string` (`ok|locked|wait:<seconds>`), `fence_claimant( int $order_id, string $txn, string $wallet, string $payer_key ): bool` (the second fence).
  - Completion and expiry: `claim_completion( int $order_id, int $now ): bool`, `set_expired_if_open( int $order_id ): bool`, `due_completions( int $before ): array`, `due_expiries( int $now, int $late ): array`.
- **`Wallets`:**
  - Read: `get( string $wallet ): ?array`, `all(): array`.
  - On delivery: `ensure( string $wallet ): void`, `touch( string $wallet, array $fields ): void` (one UPDATE).
  - Delete and restore: `mark_deleted( string $wallet, int $now, array $snapshot ): void`, `restore( string $wallet ): ?array`, `gone( string $wallet, int $now ): string` (`active|deleted_recent|gone|unknown`).
- **`Locks`:** `purge( int $before ): int`.
- **`Secrets`:**
  - Read and verify: `keys_for( string $wallet, int $now ): array` (pending, current, then previous while valid; the constant beats all), `fingerprint( string $wallet ): ?array` (`[ 'fp' => .., 'set_at' => .., 'pending_fp' => .., 'pending_at' => .. ]`), `has_key( string $wallet ): bool`.
  - Write: `set_current( string $wallet, string $key, int $now ): void` (the old current becomes previous for 24 h), `set_pending( string $wallet, string $key, int $now ): void`, `promote_pending( string $wallet, int $now ): void`, `cancel_pending( string $wallet ): void`.
  - Erase: `seal( string $wallet, int $now ): void` (on delete), `erase( string $wallet ): void`.
  - Storage order: the `QERSH_WC_KEY_*` constant, then `wp_set_secret`/`wp_get_secret` when they exist (each behind a literal `function_exists`, in this file only), then a sodium envelope keyed from the `LOGGED_IN_KEY` and `LOGGED_IN_SALT` constants (never `wp_salt()`) in option `qersh_wc_secret_<wallet>`, autoload off.
- **`Logger`:** `info|warning|error( string $message, array $context = array() ): void` through `wc_get_logger()`, source `qersh`, with redaction. Plus `throttled( string $key, string $message ): void`, which writes one line per minute.

## 9. Hooks, actions and names
- **REST routes** (POST only, `show_in_index => false`):
  - `qersh/v1/webhook/(?P<wallet>[a-z0-9]{12})`.
  - `qersh/v1/order-status`.
  - `qersh/v1/order-txn`.
- **Fallback:** `?wc-api=qersh&qw=<wallet>`, or `&qo=status|txn`, on `parse_request` priority -1. It always ends with `exit`.
- **Action Scheduler** (group `qersh`, unique per args):
  - `qersh_wc_complete` ( order_id ), `qersh_wc_expire` ( order_id ), `qersh_wc_claim_eval` ( order_id ).
  - Recurring: `qersh_wc_sweep` (every 900 s), `qersh_wc_daily` (every 86400 s, cleanup).
- **Emails:** class ids `qersh_instructions` and `qersh_admin_alert`. Trigger actions `qersh_wc_send_instructions` ( order_id ) and `qersh_wc_admin_alert` ( kind, args ); both are added to `woocommerce_email_actions`.
- **AJAX:** `wp_ajax_qersh_wc_key_link`, `wp_ajax_qersh_wc_notice_dismiss` (`Admin\Notices::DISMISS_ACTION`, also its nonce action).
- **admin-post actions:** `qersh_wc_wallet_save`, `qersh_wc_wallet_delete`, `qersh_wc_wallet_restore`, `qersh_wc_key_paste`, `qersh_wc_key_pending_cancel`, `qersh_wc_transfer_attach`, `qersh_wc_transfer_dismiss`, `qersh_wc_transfer_resolve`, `qersh_wc_settings_save`, `qersh_wc_config_import`, `qersh_wc_config_export`, `qersh_wc_self_test`.
- **Admin page:** a submenu of WooCommerce, slug `qersh`, with tabs `wallets|transfers|settings|status`.
- **Order meta:** `_qersh_payer_key`, `_qersh_wallet`, `_qersh_placed_at`, `_qersh_deadline`, `_qersh_expired_at`, `_qersh_flag`, `_qersh_claim_txn`, `_qersh_attach_hold` (`Completion::ATTACH_HOLD_META`: set by an admin attach that pays an on-hold order, removed once its completion is settled).
- **Options:** `woocommerce_qersh_settings`, `qersh_wc_config`, `qersh_wc_db_version`, `qersh_wc_as_ok`, `qersh_wc_secret_<wallet>`, `qersh_wc_notices` (`Admin\Notices::OPTION`, dismissed notice ids, autoload off). Obsolete since schema 2, deleted by its upgrade and still removed on uninstall: `qersh_wc_txn_drift` and `qersh_wc_txn_long` (the transaction-id drift diagnostics, removed).
- **Gateway id:** `qersh`, default title `Gateway::default_title()` ("E-wallet transfer", one for every wallet). Blocks script data key: `qersh_data`, whose `wallets[]` are `{ id, label, address, display, confirm }` (no type, brand or InstaPay flag). The thank-you script data has `txn_box` (bool, the wallet's gate) and `txn_open`, and its `txn` part is `{ payer_key, store_numbers }`. The `$_POST` key for the payer field is `qersh_payer`, and for the chosen wallet `qersh_wallet`.

## 10. Cross-module calls (package D implements, C and E call)
- **`Orders`:**
  - `on_process_payment( \WC_Order $order, string $payer_key, string $wallet, int $now ): void`: the `qersh_orders` row, meta, deadline, expiry action and instructions email (docs/17 §4.3, §4.7).
  - `apply_attach( string $transfer_id, int $order_id, int $user_id ): true|\WP_Error`: manual attach.
  - `dismiss( string $transfer_id, int $user_id ): true|\WP_Error`.
  - `resolve( string $transfer_id, int $user_id ): true|\WP_Error`: "Mark as decided". It takes a linked transfer out of "Needs a decision" without touching the order or the money, through the pure `Orders::resolved_fields( array $tr ): array` (overpaid → matched, review → attached, a forgery flag → forgery_decided, an overdue reason → decided).
  - `suggestions( array $transfer_row ): array`, computed when read (docs/17 §4.6).
- **`Claims`:**
  - `submit( \WC_Order $order, string $raw, int $now ): array`, returning `[ 'http' => int, 'body' => array, 'retry_after' => ?int ]` (docs/17 §4.10 route steps 1–8).
  - `evaluate( int $order_id ): void`.
  - `Claims::ELIGIBLE_REASONS = array( 'no_payer', 'masked' )`: the payerless reviews a typed id may decide later (`Claims::eligible()`, the sweep, `Transfers::fence_match()`). A refused text is never sent, counted or stored: it stays on the order page.
- **`Completion`:** `request( int $order_id ): void` (paths a/b/c), `run( int $order_id ): void` (the claimed path), `sweep(): void`.
  - The decision facts include `merchant_hold`, from the pure `Completion::merchant_hold( $meta, array $row ): bool`. An on-hold order completes only when `_qersh_attach_hold` names the row's current paid decision.
- **`Expiry`:** `run( int $order_id ): void`, `sweep(): void`, plus the filters on `woocommerce_cancel_unpaid_order` and `woocommerce_order_hold_stock_minutes`.
  - `Expiry::cancel_email_now( bool $mutes ): string` (pure) returns `mute` or `after_commit`. WooCommerce's admin "Cancelled order" email from our cancellation is held back inside the transaction and sent after COMMIT.
- **`Diagnostics`** (package E): `wallet_alerts( string $wallet ): array`, `global_alerts(): array`, `self_test( string $wallet ): array`, `record_delivery( string $wallet, array $envelope, string $form, int $now = 0, array $verified = array() ): void` (called by the webhook controller), `as_health(): void`, `txn_entry_open( string $wallet_id ): bool` (the pure rule `Diagnostics::txn_entry_allowed( array $config, string $wallet_id ): bool`: `store.txn_entry` on and the wallet in the configuration, enabled or not). Nothing in Diagnostics takes a wallet type (§13).

## 11. Tests
- **Unit:** `tests/php/unit/**Test.php`, PHPUnit 9.6 with yoast/phpunit-polyfills 2. No WordPress; the bootstrap defines `ABSPATH` and loads the autoloader. Run `composer test:unit`, or `vendor/bin/phpunit -c phpunit.xml.dist --testsuite unit`.
- **Integration:** `tests/php/integration/**Test.php`, WP_UnitTestCase via the WordPress test library. Runs in CI on MySQL 8.0 through `tests/php/integration/install-wp-tests.sh` and setup-php (`e2e.yml`, six rows), and nightly on MariaDB through `wp-env --config=.wp-env.test.json` (`nightly.yml`, HPOS on and off). The bootstrap stops when HPOS is not in the state `DISABLE_HPOS` asked for. Locally only when a test library and a MySQL or MariaDB server are available.
- **Parity:** `node tests/parity/run.mjs` runs the compiled core and the PHP port (through `php tests/parity/php-runner.php`) on generated inputs and compares.
- **E2E:** `tests/e2e/` (Playwright + `deliver.mjs`, the kit's `fixtures/sink/send.mjs`, the setup scripts in `tests/e2e/setup/` and the test must-use plugins in `tests/e2e/mu/`), in CI on wp-env (`e2e.yml`, four jobs). `tests/e2e/concurrency.mjs` runs nightly on wp-env's MariaDB. `tests/local/` is the local harness (PHP's built-in server and SQLite, no Docker): its own scenarios, and the Playwright tests against a copy of the local store.

## 12. Interfaces between C, D1, D2 and E (second wave)
Package D is split in two: **D1** owns `src/Webhook/{Module,Fallback,Controller}.php` and `src/Completion.php`; **D2** owns `src/MatchPolicy.php`, `src/Orders.php`, `src/Claims.php` and `src/Expiry.php`.

- **D1 to D2, called by `Webhook\Controller` after verifying and claiming a delivery.**
  - `Orders::handle_received( array $envelope, array $wallet_cfg, string $delivery_id, string $token, int $now ): array`, returning `[ 'http' => int, 'reply' => array ]`.
  - `Orders::handle_updated( ... same ... ): array`, with the same return.
  - Both run the locked transaction whose first write is `Deliveries::fence()`.
  - On `Store\LockTimeout` after one retry they return `[ 'http' => 429, 'reply' => [ 'code' => 'qersh_busy' ], 'retry_after' => 30 ]`.
  - `Orders::status_updates( string $wallet, int $now ): array` returns the `status_updates[]` items to append to any 2xx reply (docs/17 §4.4 step 10). The controller appends them, and calls `Transfers::note_synced()` for the ids it sent.
  - The controller handles `webhook.test`, `device.heartbeat` and ignored events itself.
  - After a caught `Throwable` the controller answers `Controller::after_throwable( bool $ready ): array`: 500 `qersh_internal_error`, or 503 `qersh_unavailable` once the store is no longer ready (`Schema::lost()`). `Controller::found_unready(): bool` gives the same answer where no controller exists (the permission check, the fallback address).
  - `Webhook\Fallback` reads each header it needs (signature, content length) one key at a time.
- **D2 to D1:** after a paid decision commits, `Completion::request( int $order_id )`.
  - Path (a) registers one shutdown callback at priority 5.
  - Path (b) runs inline under the 6 s guard, webhook requests only.
  - Path (c) uses the `qersh_wc_complete` Action Scheduler action.
  - `Completion::run( int $order_id )` is the claimed path, and also the action handler.
- **C to D2:**
  - `Orders::on_process_payment( \WC_Order $order, string $payer_key, string $wallet_id, int $now ): void`.
  - `Claims::submit( \WC_Order $order, $raw_txn, int $now ): array`, returning `[ 'http' => int, 'body' => array, 'retry_after' => ?int ]`.
- **D1 to C, the fallback for storefront routes:**
  - `Webhook\Fallback` calls `OrderStatusRoute::handle_body( array $body ): array` and `ClaimRoute::handle_body( array $body ): array`.
  - Both return `[ 'http' => int, 'body' => array, 'headers' => array ]`, and each is also the core of its REST callback.
- **E to D2, for admin screens:** `Orders::apply_attach( string $transfer_id, int $order_id, int $user_id )`, `Orders::dismiss( string $transfer_id, int $user_id )` and `Orders::suggestions( array $transfer_row ): array`.
- **D1 to E:** `Diagnostics::record_delivery( string $wallet, array $envelope, string $form, int $now = 0, array $verified = array() ): void`, called for every verified delivery before dispatch. `$form` is `wp-json`, `rest_route` or `wc-api`.
- **Plain emails:** `Checkout::plain_apostrophes( string $text ): string` (pure) turns `'` into U+2019 in plain-text lines that go into WooCommerce's own emails. Their catch-all for unknown entities would delete `&#039;`.
- **Customer-facing status values** (`OrderStatusRoute`): `waiting|partial|paid|review|expired|cancelled`, read from `qersh_orders.state` (docs/17 §4.1), plus `claim` (bool: a transaction id is saved) whenever `Checkout::txn_entry_open()` is true for the order's wallet, whatever the wallet.

## 13. No wallet type (the owner's decision of 2026-10-18)
The plugin is for e-wallets (Vodafone Cash, Orange Cash, e& money and any other), and they are all just an e-wallet: the Qersh app reads the message and sends the transfer webhook, and the plugin only handles the webhook it receives.

- **The plugin never branches on a wallet type.** `wallet.provider` stays in every qersh.v1 envelope (`Core\Envelope` still requires a string: a shape check, not wallet logic) and is stored as opaque information only, in `qersh_transfers.provider` and `qersh_wallets.last_test_provider`. No rule, gate, text, alert, lock or lookup reads either column, and the same delivery gets the same verdict and reply whatever `wallet.provider` says. An envelope without a string `wallet.provider` is refused (400 `not_qersh_v1`), so a transfer row gets `''` only when the phone sent a value with no printable ASCII in it, the empty string above all (`Orders::provider_of()` keeps `\x21-\x7E`, cut to 32 characters); `WalletTypeInvarianceTest` checks the refusal and the empty string. `Core\SetupLink` keeps its optional provider parameter; the plugin never passes one.
- **What decides** is what the webhook carries: a payer key (`Core\Payer::key_of()`, an e-wallet number `01XXXXXXXXX`: `MatchPolicy::PAYER_NUMBER`) or none, `provider_txn_id` compared by exact equality of its canonical form (`Core\TxnId`), the amount, the time the money arrived, and `auto_confirm_ok`. A notification without an SMS is told by `data.channel` or `notification_only` in the risk reasons (`transfer_from_row()` reads the stored reasons). Every wallet is read from its text messages, so a phone without the permission to read messages raises `device_sms` whatever `wallet.slot` says (it is null for a wallet bound to no SIM too, `Diagnostics::device_alerts()`).
- **Payerless** means no payer key: a name or a handle only, a masked number, or nothing (`Orders::payerless()`). Rule 4a and the late claim path apply to every payerless transfer with a canonical `provider_txn_id` while `store.txn_entry` is on, masked ones included (`Claims::ELIGIBLE_REASONS`). A typed id never decides a transfer that names a payer.
- **Store wallets are e-wallet numbers**, and so is the customer's payer field (`PayerField::number_of()`); there are no payment addresses or bank accounts in the plugin (a handle in a counterparty only makes the transfer payerless).
- **Texts** for customers and merchants never name a wallet brand or a USSD code: one title, "E-wallet transfer"; one confirmation line (`Checkout::confirm_text()`); one advice paragraph on the wallet screen; one expiry note.

Removed names:

| Removed | Instead |
|---|---|
| `Core\Config::PROVIDERS` | none; `Config::WALLET_FIELDS = array( 'id', 'label', 'receive_address', 'enabled' )`, `Config::wallet_number()` |
| `Core\TxnId::CLAIM_PROVIDERS`, `PATTERNS`, `LENGTHS`, `CLASSES`, `long_run()`; JS `longRun()` and the `shapes` argument | `TxnId::MIN_ID = 6`, `TxnId::MAX_ID = 40`, one canonical form (§6) |
| `fixtures/txn-patterns.json` and its part of `scripts/check-fixtures.mjs` | the shared vectors `tests/vectors/txn-normalize.json` |
| `MatchPolicy::PAYER_PROVIDERS`, `orange_claimants()` | every claimant counts; gate 1 (same wallet) refuses a claimant on another wallet |
| `Orders::wallet_providers()`, `Orders::provider_name()`, `Orders::order_is_orange()`, the Orange-only `auto_seen` write in `Orders::after_commit` | `Diagnostics` writes `auto_seen` for every wallet |
| `Expiry::REVIVE_SEC` (never read) | `Claims::REVIVE_SEC` |
| `Claims::PROVIDER`, `Claims::counts_long_run()`, `count_long_run()` | `Claims::ELIGIBLE_REASONS = array( 'no_payer', 'masked' )` |
| `Diagnostics::DRIFT_OPTION`, `TXN_LONG_OPTION`, `TXN_LONG_ORDERS`, `TXN_LONG_WINDOW`, `TXN_LONG_KEEP`, `NOTIFICATION_PROVIDERS`, `NUMBER_PROVIDERS`, `write_drift()`, `record_txn_refusal()`, `long_run_ok()`, `txn_long_tally()`, `drift_of()`, `drift_tail()`, `has_provider()`, the `txn_drift` alert | none (the app checks each operator's id format); the competition alert counts `txn_contested` on every wallet |
| `Admin\View::PROVIDER_ORDER`, `DECISION_PROVIDERS`, `provider_label()`, `provider_options()` | `View::wallet_name()`: the label, else `E-wallet %s` with the formatted number |
| `Checkout::AUTO_PROVIDERS`, `Checkout::provider_name()`, `Checkout::auto_seen()`, `Checkout::txn_shapes()` | none |
| `PayerField::KIND` (`qersh_payer_kind`), `PayerField::has_instapay()`, `Gateway::has_instapay()` | `PayerField::NUMBER`, `PayerField::number_of( $raw ): ?string` |
| the `provider` filter key of `Transfers::list_page()` | ignored |

Changed signatures and values:

| Before | Now |
|---|---|
| `TxnId::canonical_typed( string $provider, $raw, ?string $payer_key, array $store_numbers )` | `canonical_typed( $raw, string $payer_key = '', array $store_numbers = array() ): array` |
| `TxnId::canonical_stored( string $provider, $value )` | `canonical_stored( $value ): ?string` |
| JS `canonicalTyped( provider, raw, payerKey, storeNumbers, shapes )`, `checkPayer( raw, instapay, storeNumbers )` | `canonicalTyped( raw, payerKey, storeNumbers )`, `checkPayer( raw, storeNumbers )`, plus `numberOf( raw )` |
| `MatchPolicy::read_transfer( $data, string $wallet, string $provider, int $now )` | `read_transfer( $data, string $wallet, int $now ): ?array`; `txn` is the canonical form for every transfer |
| the lock `txn:<provider>:<id>`, built in `MatchPolicy::transfer_lock()` and again in `Claims` | `MatchPolicy::txn_lock( string $txn ): string` = `txn:<id>`, used by both |
| `Store\Transfers::find_by_txn( string $provider, string $txn )` | `find_by_txn( string $txn ): array`, store-wide; callers keep the rows of the wallet they need (`Expiry::same_wallet( array $tr, array $row ): bool`) |
| rule 2 (`duplicate_txn`): same provider and id | same canonical id on any wallet of the store |
| `Expiry::note_kind( string $provider, bool $has_txn, bool $txn_entry )` | `note_kind( bool $has_txn, bool $txn_entry ): string`, returning `txn_saved`, `txn_entry` or `plain` |
| `Orders::customer_partial_note( \WC_Order $order, string $provider, int $paid, int $price, int $now )` | `customer_partial_note( \WC_Order $order, bool $payerless, int $paid, int $price, int $now ): void` |
| `Orders::handle_received()` stored the configured wallet's provider | it stores `Orders::provider_of( array $envelope ): string`, the envelope's `wallet.provider` |
| `Diagnostics::sender_masked_value( string $provider, $counterparty )` | `sender_masked_value( $counterparty ): ?int` |
| `Diagnostics::device_alerts( string $provider, $state )` | `device_alerts( $state ): array` |
| `Diagnostics::delivery_fields( array $envelope, string $provider, string $form, string $host, int $now, array $verified = array() )` | `delivery_fields( array $envelope, string $form, string $host, int $now, array $verified = array() ): array` |
| `Diagnostics::txn_entry_open( string $provider )` | `txn_entry_open( string $wallet_id ): bool`, with the pure `txn_entry_allowed( array $config, string $wallet_id ): bool` |
| `Checkout::txn_wallet()`: Orange Cash wallets only | any wallet entry with an id; `Checkout::txn_entry_open( Plugin $plugin, ?array $wallet ): bool` keeps its signature and asks `Diagnostics::txn_entry_open()` |
| `Checkout::confirm_kind( string $provider, bool $auto_seen, bool $txn_open )`: `auto`, `orange_auto`, `orange`, `decision` | `confirm_kind( bool $txn_open ): string`: `txn` or `plain` |
| `Checkout::status_line_key( string $status, bool $decision, bool $claim )` | `status_line_key( string $status, bool $claim ): string` |
| `Checkout::nudge_key( string $status, bool $due, bool $orange, bool $txn_open, bool $claim )` | `nudge_key( string $status, bool $due, bool $txn_open, bool $claim ): string` |
| `Checkout::box()` with `provider`, `provider_name`, `payer_handle`, `kind`, `decision`, `orange` | without them, with `txn_box` (the wallet's gate) beside `txn_open` |
| `Checkout::wallet_views()` with `provider`, `provider_name`, `instapay`, `kind`, `decision` | `{ id, label, address, address_display, confirm }` |
| thank-you script data `orange`, and `txn.provider`, `txn.shapes` | `txn_box`; `txn` is `{ payer_key, store_numbers }` |
| `Gateway::default_title( bool $instapay )` | `default_title(): string` |
| `PayerField::message( string $code, bool $instapay )`, `PayerField::order_prefill( string $stored, string $billing_phone, array $wallets, bool $instapay_checked )` | `message( string $code ): string`, `order_prefill( string $stored, string $billing_phone, array $wallets ): array` |
| `Admin\Wallets::setup_link( string $url, string $label, string $provider, ?string $secret )` | `setup_link( string $url, string $label, ?string $secret ): ?string` |
| `Admin\Wallets::address_problem( string $provider, string $address )`: `''`, `instapay` or `number` | `address_problem( string $address ): string`: `''` or `number` |
| the `no_payer` variant `orange` (MatchPolicy, Orders, Admin\Reasons) | `txn` (`Admin\Reasons::no_payer_variant( string $variant, bool $txn_entry, int $claimants ): string`) |
| the note code `no_payer_orange` | `no_payer_txn` |
| `OrderStatusRoute`: `claim` for an Orange Cash order | `claim` whenever `Checkout::txn_entry_open()` is true |
| `Transfers::fence_match()` and the claim sweep: reason `no_payer` | reason `no_payer` or `masked` |
| `QERSH_WC_DB_VERSION = 1` | `2` (§7) |
