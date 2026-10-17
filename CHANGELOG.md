# Changelog

All notable changes to Qersh for WooCommerce. Versions are bare tags (`0.1.0`, never `v0.1.0`), and each one matches `Version` in `qersh-for-woocommerce.php` and `Stable tag` in `readme.txt`. The wordpress.org changelog in `readme.txt` is the short form of this file, for the newest versions that fit in its 10 KB; this file keeps every version.

## 0.1.0 (unreleased)

First release, for submission to wordpress.org.

### Checkout

- "E-wallet transfer" (gateway id `qersh`) for the classic checkout, the Checkout block and the "Pay for order" page, offered for EGP stores with at least one enabled wallet, within the optional minimum and maximum totals.
- One required field: the wallet number or InstaPay address the customer will transfer from, normalised when the field is left (Arabic-Indic digits, `+20`, spaces), and refused when it is the store's own wallet number. A choice of wallet when the store has more than one.
- The order waits as `pending` until a deadline (120 minutes by default, 15 to 1440), with the transfer instructions on the thank-you page and by email; totals with piasters are rounded up to the pound for whole-pound `*9#` transfers.
- A live status on the thank-you page through `POST qersh/v1/order-status`, never cached.
- Numbers, addresses, transaction ids and times inside Arabic text are isolated so they never show reversed; deadlines are shown in Cairo time.

### Deliveries from the Qersh app

- `POST qersh/v1/webhook/<wallet id>` on the REST API, in both permalink forms, and the fallback `?wc-api=qersh&qw=<wallet id>` on `parse_request` for stores whose REST API is blocked.
- Signature verified on the raw body (HMAC-SHA256, every `v1` value, 300-second window, header at most 8 KB), with the current, pending and previous key of each wallet; 401 with a reason otherwise.
- Every delivery is claimed once (unique index, 60-second reclaim, stored reply for duplicates); 429 with `Retry-After: 30` while the same delivery is being processed; 503 JSON when WooCommerce is off.
- Deleted wallets answer 404 with a one-click restore for 72 hours, then 410.
- `status_updates` in later replies tell the phone about manual attaches, dismissals and late matches.

### Matching

- A port of the Qersh core's `matchTransfer` in whole seconds (parity-tested against the compiled core), by payer, then the time the money arrived, then the amount.
- Store rules around it: unknown risk reasons and repeated operator transaction ids go to review; closed orders block a match; rounding to the pound; overpaid after the deadline; possible second payment; partial orders past their deadline; personal transfers for transfers with no open order; an order created after the money arrived is never matched automatically.
- Orange Cash: the customer enters the operator's transaction id on the order page after transferring (`POST qersh/v1/order-txn`, typed or pasted with the whole message). It confirms an order only for the exact remaining amount, in the chosen wallet, for an order created before the money arrived, with no competing unpaid order, and never bypasses the phone's checks.
- Completion claimed once per order before `payment_complete()`, after the reply on `shutdown` where the server can finish the response early, otherwise under a time guard or in Action Scheduler; a sweep picks up anything left.
- Deadline expiry under the payer lock: cancels orders that received nothing, keeps partial and disputed ones for the merchant, revives a cancelled order when money that arrived in time is delivered late and stock allows.
- Suspected forgery (`transfer.updated`) flags the transfer and holds the order it paid.

### Admin

- WooCommerce, Qersh: Wallets (store URL, "your developer's link with the connection key" shown once with a QR code, the link without the key, pasting a key or the phone's message, key rotation, delete and restore), Transfers (Needs a decision, Unmatched, Personal transfers, attach, dismiss, suggestions), Settings (with export and import, or read-only under `QERSH_WC_CONFIG`), Status ("Check your store URL", cron and Action Scheduler health, time zone, REST blockers).
- An order box with the payer, the deadline, the matched transfer and the customer's transaction id.
- Admin alert emails for suspected forgery, a silent phone and decisions due at a deadline.
- Privacy policy text, personal data exporter and eraser, retention of unmatched transfers (90 days by default), optional data removal on uninstall.

### Languages

- English source strings and an Arabic translation written in the app's own terms.
