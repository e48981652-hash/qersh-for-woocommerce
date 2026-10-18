# Changelog

All notable changes to Qersh for WooCommerce. Versions are bare tags (`0.1.0`, never `v0.1.0`), and each one matches `Version` in `qersh-for-woocommerce.php` and `Stable tag` in `readme.txt`. The wordpress.org changelog in `readme.txt` is the short form of this file, for the newest versions that fit in its 10 KB; this file keeps every version.

## 0.1.0 (2026-10-19)

First release, for submission to wordpress.org.

The plugin works with any e-wallet the Qersh app reads (Vodafone Cash, Orange Cash, e& money and others) and never looks at the wallet type. It decides each transfer from the webhook alone: the sender's number, the transaction id, the amount, the time the money arrived and the app's `auto_confirm_ok`. The same delivery gets the same verdict whatever its `wallet.provider` says; that value is kept in `qersh_transfers.provider` as information only, and no rule reads it.

### Checkout

- "E-wallet transfer" (gateway id `qersh`), one title for every wallet, for the classic checkout, the Checkout block and the "Pay for order" page, offered for EGP stores with at least one enabled wallet, within the optional minimum and maximum totals.
- One required field: the e-wallet number (`01XXXXXXXXX`) the customer will transfer from, normalised when the field is left (Arabic-Indic digits, `+20`, spaces), and refused when it is the store's own wallet number or anything other than a number. A choice of wallet when the store has more than one, shown by its name, else its number.
- The order waits as `pending` until a deadline (120 minutes by default, 15 to 1440), with the transfer instructions on the thank-you page and by email; totals with piasters are rounded up to the pound, since wallet transfers made by code carry whole pounds only.
- One confirmation line for every wallet, which never names a wallet brand: the order is confirmed once the transfer reaches the store.
- A live status on the thank-you page through `POST qersh/v1/order-status`, never cached.
- Numbers, transaction ids and times inside Arabic text are isolated so they never show reversed; deadlines are shown in Cairo time.

### Deliveries from the Qersh app

- `POST qersh/v1/webhook/<wallet id>` on the REST API, in both permalink forms, and the fallback `?wc-api=qersh&qw=<wallet id>` on `parse_request` for stores whose REST API is blocked.
- Signature verified on the raw body (HMAC-SHA256, every `v1` value, 300-second window, header at most 8 KB), with the current, pending and previous key of each wallet; 401 with a reason otherwise.
- Every delivery is claimed once (unique index, 60-second reclaim, stored reply for duplicates); 429 with `Retry-After: 30` while the same delivery is being processed; 503 JSON when WooCommerce is off.
- Deleted wallets answer 404 with a one-click restore for 72 hours, then 410.
- `status_updates` in later replies tell the phone about manual attaches, dismissals and late matches.

### Matching

- A port of the Qersh core's `matchTransfer` in whole seconds (parity-tested against the compiled core), by payer, then the time the money arrived, then the amount.
- Store rules around it: unknown risk reasons and repeated operator transaction ids (found store-wide, on any wallet) go to review; closed orders block a match; rounding to the pound; overpaid after the deadline; possible second payment; partial orders past their deadline; personal transfers for transfers with no open order; an order created after the money arrived is never matched automatically.
- The transaction id, on every wallet while `store.txn_entry` is on (the default): the customer enters the operator's transaction id on the order page after transferring (`POST qersh/v1/order-txn`, typed or pasted with the whole message). It decides only a transfer that arrived without the sender's number (a name or a handle only, a masked number, or nothing), by exact equality with the canonical form of the webhook's `provider_txn_id`, and confirms an order only for the exact remaining amount, in the chosen wallet, for an order created before the money arrived, with no competing unpaid order, and never bypasses the phone's checks. `qersh/v1/order-status` returns `claim` whenever the order page takes a transaction id.
- One reading of transaction ids for every wallet, with no per-operator shapes: an id is 6 to 40 letters or digits with at least one digit, in upper case, leading zeros kept; an id typed alone may be split by spaces or `- . # :`; in a pasted message, links, dates, times and amounts are ignored, an id split by single separators is read whole, and mobile, payer and store numbers are left out. A text the order page cannot read stays on the page and is never sent.
- Completion claimed once per order before `payment_complete()`, after the reply on `shutdown` where the server can finish the response early, otherwise under a time guard or in Action Scheduler; a sweep picks up anything left. A delivery and the customer's transaction id lock the same `txn:<id>`.
- Deadline expiry under the payer lock: cancels orders that received nothing, keeps partial and disputed ones for the merchant, revives a cancelled order when money that arrived in time is delivered late and stock allows. The customer's note on expiry is the same for every wallet.
- Suspected forgery (`transfer.updated`) flags the transfer and holds the order it paid.

### Admin

- WooCommerce, Qersh: Wallets (a wallet is its 11-digit number and an optional name, with no type; store URL, "your developer's link with the connection key" shown once with a QR code, the link without the key, pasting a key or the phone's message, key rotation, delete and restore), Transfers (Needs a decision, Unmatched, Personal transfers, attach, dismiss, suggestions), Settings (with export and import, or read-only under `QERSH_WC_CONFIG`), Status ("Check your store URL", cron and Action Scheduler health, time zone, REST blockers).
- The developer's link carries the store URL, the label and the key, never `provider`: the Qersh app asks for the wallet type itself.
- An order box with the payer, the deadline, the matched transfer and the customer's transaction id.
- Admin alert emails for suspected forgery, a silent phone and decisions due at a deadline. The phone-permission and masked-sender alerts are the same for every wallet.
- Privacy policy text, personal data exporter and eraser, retention of unmatched transfers (90 days by default), optional data removal on uninstall.

### Database

- Schema version 2 (`QERSH_WC_DB_VERSION`): `qersh_transfers.provider` is `VARCHAR(32) NOT NULL DEFAULT ''`, and the key `provider_txn_id (provider_txn_id)` finds repeated transaction ids store-wide.

### Languages

- English source strings and an Arabic translation written in the app's own terms.

### Changes from the pre-release test builds

For test stores that ran a build from before 2026-10-18 (schema 1):

- A wallet entry has no type any more. A legacy `provider` in a saved configuration, an import, `QERSH_WC_CONFIG` or a deleted wallet's snapshot is accepted and dropped, never an error; the companion schema keeps `wallets[].provider` as a deprecated, ignored string, so older exports still validate. Store wallets and the customer's payer field take an e-wallet number only, never an address. The admin transfer list has no filter by wallet type.
- A wallet saved with an InstaPay address (anything but an 11-digit e-wallet number) is no longer offered at checkout or served: the Wallets screen names it, and the next save of the settings or of a wallet removes it from the configuration and lists it under "Deleted wallets", where it cannot be restored. Add it again with "Add wallet" and its e-wallet number. Under `QERSH_WC_CONFIG` such a wallet makes the whole constant invalid, so no wallet is served until it is fixed (the Settings and Wallets screens name the entry): remove that entry, or give it an e-wallet number, before updating.
- Every wallet is read from its text messages: a phone without the permission to read messages raises the same alert for every wallet, whatever its SIM binding, and the alert about notification access (InstaPay) is gone.
- The upgrade to schema 2 gives `qersh_transfers.provider` its default of `''`, replaces the key `provider_txn (provider, provider_txn_id)` by `provider_txn_id (provider_txn_id)`, deletes the options `qersh_wc_txn_drift` and `qersh_wc_txn_long` (deleting the plugin still removes both where the upgrade never ran), and rewrites each stored `provider_txn_id` in its canonical form (separators removed, upper case, as every new transfer stores it), so an id stored as the phone sent it, such as `2500517294 4183`, equals the same id delivered again.
- The transaction-id format drift diagnostics are removed: both triggers, the `txn_drift` alert, and the count of refused long ids (the Qersh app checks each operator's id format itself). `fixtures/txn-patterns.json` and its check are removed.
- Overridable templates changed; copies in a theme must be updated:
  - `qersh-for-woocommerce/checkout/payer-field.php`: the variables `$instapay`, `$kind` and `$kept` are gone, the `$views` entries are `{ id, label, address, address_display, confirm }`, and the field takes a wallet number only.
  - `qersh-for-woocommerce/order/thankyou-box.php`, `qersh-for-woocommerce/order/txn-box.php`, `woocommerce/emails/instructions.php` and `woocommerce/emails/plain/instructions.php`: `$box` no longer has `provider`, `provider_name`, `payer_handle`, `kind`, `decision` or `orange`, and has `txn_box`; the wallet is shown by its name and number only, and the lines for an address are gone. `Checkout::status_line_key( $status, $claim )` and `Checkout::nudge_key( $status, $due, $txn_open, $claim )` lost their `$decision` and `$orange` arguments, and the thank-you `$script` data has `txn_box` instead of `orange`.
