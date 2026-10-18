# Qersh for WooCommerce: implementation contracts

The design is `docs/17-woocommerce-plugin-design.md` (v3, Arabic) in the Qersh repository, at `D:\qersh pay`. The evidence behind it is `docs/16-woocommerce-research.md` in the same repository. This file fixes the names every module shares, so parts written in parallel fit together. Where this file and docs/17 disagree, docs/17 wins on behaviour and this file wins on names; report the conflict.

Reference copies of the Qersh core live in this repository; never edit them (see `fixtures.lock.json`):
- `tests/parity/core/*.js` is the compiled core (`packages/qersh-webhook`).
- `tests/parity/kit/match.mjs` is the kit's reference matcher.
- `fixtures/test-vectors.json`, `fixtures/webhook.v1.schema.json`, `fixtures/sink/send.mjs`, `fixtures/qersh.config.schema.json`.
- `tests/php/fixtures/verify.php` is the kit's PHP verifier.

The TypeScript sources and their tests are in `D:\qersh pay\packages\qersh-webhook\src\core` and `...\test`, and the kit tests in `D:\qersh pay\kit\tools\test-match.mjs`. Read them when you port.

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
| Path | Owner (work package) |
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

A module may call another module's public methods as listed here. If you need a method that is not listed, add it to your own module, or write it down in your report. Do not edit another package's files.

## 3. Constants and bootstrap (`qersh-for-woocommerce.php`)
- **Constants:**
  - `QERSH_WC_VERSION = '0.1.0'`, `QERSH_WC_FILE = __FILE__`, `QERSH_WC_DIR`, `QERSH_WC_URL`.
  - `QERSH_WC_DB_VERSION = 1`, `QERSH_WC_MIN_WC = '11.0'`.
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
  - `Admin\Module` (E, `src/Admin/Module.php`): menus, admin-post, AJAX, notices, the order box, privacy, emails and diagnostics hooks.
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
- **Wallet entry:**
  - Fields: `{ id, label, provider, receive_address, enabled }`.
  - `id` matches `^[a-z0-9]{12}$`.
  - `provider` is one of `vodafone_cash, etisalat_cash, orange_cash, instapay_app, bank_nbe, bank_cib, bank_egbank`.
  - `receive_address` holds a normalised 11-digit number, or `name@instapay` for `instapay_app`.
- **`ConfigStore`:**
  - Read: `get(): array` (with defaults), `is_constant(): bool`.
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
  - Constants: `DEFAULTS`, `PROVIDERS`, `EVENTS_ALLOWED = [ 'transfer.received', 'transfer.updated', 'webhook.test' ]`.
  - Validation: `with_defaults( array $c ): array`, `validate( array $c ): array` (a list of `[ 'path' => .., 'message' => .. ]`; receiver rules are literal from `validateConfig` minus `keys`, plus the plugin rules of docs/17 §4.2).
  - Keys and ids: `is_acceptable_key( $k ): bool`, `wallet_id_ok( $id ): bool`, `new_wallet_id(): string`, `new_key(): string` (`qsk_` + base64url without padding of `random_bytes(32)`), `fingerprint( string $key ): string` (the first 8 hex characters of `sha256( 'qersh-key:' . $key )`).
- **`TxnId`:**
  - Constants:
    - `CLAIM_PROVIDERS = array( 'orange_cash' )`.
    - `PATTERNS = array( 'orange_cash' => '^(?:[0-9]{14}|[0-9]{10})$' )`, which must equal `fixtures/txn-patterns.json` (checked by `scripts/check-fixtures.mjs`).
    - `LENGTHS = array( 'orange_cash' => array( 14, 10 ) )`.
  - `canonical_typed( string $provider, $raw, ?string $payer_key, array $store_numbers ): array` returns `[ 'ok' => true, 'txn' => string ]` or `[ 'ok' => false, 'error' => 'qersh_txn_empty'|'qersh_txn_too_long'|'qersh_txn_format'|'qersh_txn_several'|'qersh_txn_wallet_number' ]` (docs/17 §4.10 steps 1–7).
  - `canonical_stored( string $provider, $value ): ?string`.

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
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, transfer_id VARCHAR(40) NOT NULL, wallet VARCHAR(32) NOT NULL, provider VARCHAR(32) NOT NULL,
delivery_id CHAR(36) NOT NULL, amount_minor BIGINT NULL, currency VARCHAR(8) NOT NULL, payer_key VARCHAR(80) NULL, payer_name VARCHAR(120) NULL,
provider_txn_id VARCHAR(40) NULL, money_at BIGINT UNSIGNED NOT NULL, sms_at BIGINT UNSIGNED NULL, risk VARCHAR(16) NULL, risk_reasons TEXT NULL,
auto_confirm_ok TINYINT NOT NULL DEFAULT 0, balance_chain VARCHAR(24) NULL, outcome VARCHAR(16) NULL, reason VARCHAR(40) NULL,
claim_eval VARCHAR(40) NULL, claim_eval_at BIGINT UNSIGNED NULL, order_id BIGINT UNSIGNED NULL, flag VARCHAR(32) NULL,
phone_sync VARCHAR(8) NOT NULL DEFAULT 'none', phone_sync_since BIGINT UNSIGNED NULL, phone_sync_count TINYINT NOT NULL DEFAULT 0,
note VARCHAR(255) NULL, created_at BIGINT UNSIGNED NOT NULL, updated_at BIGINT UNSIGNED NOT NULL,
PRIMARY KEY  (id), UNIQUE KEY transfer_id (transfer_id), KEY provider_txn (provider,provider_txn_id), KEY outcome_created (outcome,created_at),
KEY order_id (order_id), KEY payer_key (payer_key), KEY wallet_sync (wallet,phone_sync)
```
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
`{prefix}qersh_locks`: `name VARCHAR(100) NOT NULL, created_at BIGINT UNSIGNED NOT NULL, PRIMARY KEY  (name)`.

Value sets:
- `qersh_deliveries.state`: `processing|done`.
- `qersh_transfers.outcome`: `matched|partial|overpaid|unmatched|review|attached|dismissed`.
- `qersh_orders.state`: `open|partial|paid|review|expired|closed`.
- `qersh_orders.completion`: `none|due|running|done`.
- `qersh_transfers.reason` codes: as in docs/17 §4.6 and §4.3.

**Lost tables.** `Schema::table_missing( ?string $prefix = null )` is called by `Db` when a query reports one of our tables missing. `Schema::lost(): bool` is then true for the rest of the request, so `Plugin::tables_ready()` is false. `Schema::forget_version()` deletes `qersh_wc_db_version` (at once, or right after the open transaction ends) so the next request's `maybe_upgrade()` creates the tables again. A second loss within ten minutes pauses automatic installs for ten minutes.

## 8. Store API (`src/Store`, package B; others call only these)
- **`Db`:**
  - Basics: `table( string $short ): string`, `wpdb(): \wpdb`, `is_sqlite(): bool`.
  - Transactions: `begin(): void` (START TRANSACTION, then `innodb_lock_wait_timeout = 5` saved and restored on MySQL), `commit(): void`, `rollback(): void`.
  - Locks: `lock( array $names ): void` takes names in the total order and throws `Store\LockTimeout`. That order is at most one `txn:`/`trf:` name first, then `payer:` names sorted; the method sorts and dedupes, and runs one `INSERT … ON DUPLICATE KEY UPDATE` per name.
  - Queries: `query( string $sql, array $args = array() ): int|false`, `row(...)`, `rows(...)`, `var(...)`; each prepares, suppresses errors and maps lock-timeout and deadlock errors to `LockTimeout`.
- **`Deliveries`:**
  - `claim( string $delivery_id, string $wallet, string $event, ?string $redelivery, int $now ): array` returns `[ 'state' => 'claimed', 'token' => string ]`, `[ 'state' => 'done', 'http_status' => int, 'reply' => array ]` or `[ 'state' => 'busy' ]` (docs/17 §4.4 step 6, including the 60 s reclaim).
  - `fence( string $delivery_id, string $token, int $http_status, array $reply, int $now ): bool`.
  - `release( string $delivery_id, string $token ): void`; `purge( int $before ): int`.
- **`Transfers`:**
  - Read: `get( string $transfer_id ): ?array`, `find_by_txn( string $provider, string $txn ): array`.
  - Write: `insert( array $row ): bool` (INSERT IGNORE), `update( string $transfer_id, array $fields ): void`.
  - `fence_match( string $transfer_id, int $order_id ): bool` (docs/17 §4.6 rule 4a, the first fence).
  - Phone sync: `pending_sync( string $wallet, int $now, int $limit = 50 ): array`, `note_synced( array $transfer_ids, int $now ): void`.
  - Lists and privacy: `list_page( array $filter, int $page, int $per_page ): array` (with total), `count_needs_decision(): int`, `purge_personal( int $before ): int`.
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
- **AJAX:** `wp_ajax_qersh_wc_key_link`.
- **admin-post actions:** `qersh_wc_wallet_save`, `qersh_wc_wallet_delete`, `qersh_wc_wallet_restore`, `qersh_wc_key_paste`, `qersh_wc_key_pending_cancel`, `qersh_wc_transfer_attach`, `qersh_wc_transfer_dismiss`, `qersh_wc_transfer_resolve`, `qersh_wc_settings_save`, `qersh_wc_config_import`, `qersh_wc_config_export`, `qersh_wc_self_test`.
- **Admin page:** a submenu of WooCommerce, slug `qersh`, with tabs `wallets|transfers|settings|status`.
- **Order meta:** `_qersh_payer_key`, `_qersh_wallet`, `_qersh_placed_at`, `_qersh_deadline`, `_qersh_expired_at`, `_qersh_flag`, `_qersh_claim_txn`, `_qersh_attach_hold` (`Completion::ATTACH_HOLD_META`: set by an admin attach that pays an on-hold order, removed once its completion is settled).
- **Options:** `woocommerce_qersh_settings`, `qersh_wc_config`, `qersh_wc_db_version`, `qersh_wc_as_ok`, `qersh_wc_txn_drift`, `qersh_wc_txn_long` (`Diagnostics::TXN_LONG_OPTION`, the second drift trigger), `qersh_wc_secret_<wallet>`, `qersh_wc_notices` (dismissed notice ids).
- **Gateway id:** `qersh`. Blocks script data key: `qersh_data`. The `$_POST` key for the payer field is `qersh_payer`, and for the chosen wallet `qersh_wallet`.

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
  - `Claims::counts_long_run( string $code ): bool` (pure): true for `qersh_txn_format` and `qersh_txn_wallet_number`. Only those refusals pass `Core\TxnId::long_run( string $provider, $raw ): ?string` (JS twin `longRun()` in `assets/js/txn-normalize.js`) to `Diagnostics::record_txn_refusal()`. The order page then sends only that long run, never the rest of what the customer pasted.
- **`Completion`:** `request( int $order_id ): void` (paths a/b/c), `run( int $order_id ): void` (the claimed path), `sweep(): void`.
  - The decision facts include `merchant_hold`, from the pure `Completion::merchant_hold( $meta, array $row ): bool`. An on-hold order completes only when `_qersh_attach_hold` names the row's current paid decision.
- **`Expiry`:** `run( int $order_id ): void`, `sweep(): void`, plus the filters on `woocommerce_cancel_unpaid_order` and `woocommerce_order_hold_stock_minutes`.
  - `Expiry::cancel_email_now( bool $mutes ): string` (pure) returns `mute` or `after_commit`. WooCommerce's admin "Cancelled order" email from our cancellation is held back inside the transaction and sent after COMMIT.
- **`Diagnostics`** (package E): `wallet_alerts( string $wallet ): array`, `global_alerts(): array`, `self_test( string $wallet ): array`, `record_delivery( string $wallet, array $envelope, string $form ): void` (called by the webhook controller), `as_health(): void`, `record_txn_refusal( string $provider, string $long_run, int $order_id, string $payer_key, int $now = 0 ): bool` (the second drift trigger, with the pure `Diagnostics::long_run_ok( string $provider, string $run ): bool`).

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
- **D1 to E:** `Diagnostics::record_delivery( string $wallet, array $envelope, string $form, int $now ): void`, called for every verified delivery before dispatch. `$form` is `wp-json`, `rest_route` or `wc-api`.
- **Plain emails:** `Checkout::plain_apostrophes( string $text ): string` (pure) turns `'` into U+2019 in plain-text lines that go into WooCommerce's own emails. Their catch-all for unknown entities would delete `&#039;`.
- **Customer-facing status values** (`OrderStatusRoute`): `waiting|partial|paid|review|expired|cancelled`, read from `qersh_orders.state` (docs/17 §4.1), plus `claim` (bool) for Orange orders.
