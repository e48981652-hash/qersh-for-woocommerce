=== Qersh for WooCommerce ===
Contributors: qersh
Tags: woocommerce, egypt, vodafone cash, instapay, wallet
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Confirm WooCommerce orders paid by e-wallet transfer, with the Qersh app on your phone. The money goes straight to your wallet.

== Description ==

Qersh (Qersh Pay) is an Android app that reads the incoming-transfer notifications of e-wallets (Vodafone Cash, Orange Cash, e& money, InstaPay) on the merchant's phone and sends a signed webhook to the merchant's server, so orders are confirmed automatically with no payment gateway. Qersh is a wallet notification relay, not a payment service provider.

= How it works =

1. Add your wallet in WooCommerce, Qersh, Wallets: the number or InstaPay address your customers transfer to.
2. Generate your developer's link with the connection key and open it on the wallet's phone in the Qersh app, then tap "Test connection".
3. Activate Qersh and set the wallet balance once in the app ("Sync balance").
4. At checkout your customer chooses "E-wallet transfer", enters the wallet number they will transfer from, and sees the amount, your wallet and the deadline.
5. When the transfer arrives, your phone sends it to your store, signed. The plugin matches it to the order by the payer's number and the time the money arrived, and the order is paid, usually within minutes.

= What your customer does =

One field, then a transfer. Vodafone Cash and e& money: no transaction id, no screenshot, and orders are confirmed on their own. Orange Cash: after transferring, the customer enters the transaction id from the transfer message on the order page (pasting the whole message works), and the order is usually confirmed on its own. InstaPay and bank transfers wait for your one-click decision.

= Why Qersh =

* Only your phone: no hosted payment page, and no cloud copy of your transfers.
* Matching by the payer's number and the time the money arrived.
* Checkout never calls an outside service, so customers can always place an order.
* A notification tool, not a payment gateway: the money goes from your customer's wallet straight to yours.

= What you need =

The paid Qersh Android app with a subscription, on the phone that receives your wallet's messages: [get the app](https://qersh.cfd/en/download). The plugin itself is free and complete; nothing in it is locked.

= Support =

The plugin's forum on wordpress.org, and support@qersh.cfd. The same two appear at the top of the plugin's settings page.

= Wallets =

Automatic: Vodafone Cash and e& money (keep Send sender details on Full name and number), and Orange Cash when the customer enters the transaction id (set the wallet balance once in the app; needs the app's current message templates). One click, with the payer shown: the InstaPay app (a later app version will confirm InstaPay transfers itself, with no plugin change) and bank SMS (bank messages state no balance, so Qersh cannot check them). A transfer that arrives with no open order is filed as a personal transfer, and an order created after the money arrived is never confirmed by it automatically.

= Privacy =

Transfers stay on your phone and in your WordPress database (see External services); a message your customer pastes is never stored, only its transaction id. The plugin suggests privacy policy text and supports the WordPress personal data exporter and eraser. Transfers that matched no order are deleted after 90 days (adjustable). Logs show only the last three digits of numbers.

Third-party code: qrcode-generator 2.0.4 by Kazuhiko Arase, MIT licence, https://github.com/kazuhikoarase/qrcode-generator

== External services ==

This plugin never contacts Qersh or any other outside service, and sends no analytics. It receives signed HTTPS requests sent directly by your own phone running the Qersh app, and stores what it needs to match them (amount, payer number or InstaPay address, payer name, operator transaction id, time, and the transaction id your customer enters on the order page) in your WordPress database. Nothing passes through Qersh servers. The only requests the plugin sends itself come from the "Check your store URL" button (Status tab, or a wallet card): when an administrator clicks it, the plugin sends test requests with no order or customer data (an empty body or a placeholder order key, with a deliberately invalid signature) to your store's own addresses, the current one and the one your phone last used (through your CDN or firewall, such as Cloudflare, if you use one), to show what blocks them. Qersh app: https://qersh.cfd/en/ — Terms: https://qersh.cfd/en/terms — Privacy: https://qersh.cfd/en/privacy

== Frequently Asked Questions ==

= Do customers type a transaction id? =

Not for Vodafone Cash or e& money. For Orange Cash, whose messages do not name the payer's number, the order page asks for it after the transfer, never at checkout. It is optional: without it the transfer waits for your decision. A transaction id only tells the plugin which order a transfer belongs to. It never replaces Qersh's checks, and it confirms an order only when a signed transfer from your phone carries the same id and the exact amount, into the wallet the customer chose, for an order created before the money arrived, while no other unpaid order could take it.

= Can a customer use someone else's transaction id? =

Not to get an order confirmed. The id alone is never enough: the amount must match, the wallet must be the one that customer chose, the order must predate the money, the id must be entered after the operator's message, and no other unpaid order may compete. Otherwise it waits for your click. A transfer with no open order is a personal transfer, and a later order never takes it on its own.

= What if my phone is offline or my Qersh subscription has ended? =

Checkout keeps working and orders wait. The app keeps what it could not send for 30 days and sends it when the phone is back or the subscription is renewed. Every order whose money arrived in time is then confirmed, even after its deadline passed, while the products are in stock; otherwise the transfer goes to "Needs a decision". The plugin shows when your phone last sent anything.

= Does the plugin check my Qersh subscription? =

No. It never contacts Qersh and locks nothing. Your phone sends transfers only while its subscription is active.

= Orders are not confirmed automatically =

In the app, keep the wallet's Send sender details (Developer settings) on Full name and number, and set the balance once in "Sync balance". A transfer above the wallet's "Auto-confirm limit" waits for you, and so does a transfer that arrived before its order was placed. Each waiting transfer shows its reason in "Needs a decision".

= Orange Cash, InstaPay and bank accounts? =

Orange Cash messages rarely name the payer, so the customer enters the transaction id on the order page; this needs the app's current message templates. InstaPay transfers are recognized by the payer's address but wait for your click, with the order suggested, until a later app version confirms them. Bank messages (NBE, CIB, EGBANK) state no balance, so they wait for your click too.

= Orders I create in the admin (WhatsApp or phone sales)? =

Send the customer the payment link (Order actions, "Send order details to customer"). They pay on the "Pay for order" page with their wallet number, and the wait starts then. A transfer whose money arrived before you created the order is never matched on its own; attach it from the Transfers screen.

= Classic or block checkout? HPOS? =

Both checkouts and the "Pay for order" page work, with High-Performance Order Storage on or off. The store needs WooCommerce 11.0 or later, the Egyptian pound and HTTPS.

= My security plugin blocks the REST API / Cloudflare =

Click "Check your store URL": it names what blocks your phone (a security plugin, a Cloudflare challenge, a redirect) and how to allow it. If you cannot allow the REST route, use the fallback address it shows (?wc-api=qersh), which works with the REST API switched off, for deliveries and for the order page.

= Can the same wallet also feed n8n or my own server? =

No. Each wallet on the phone sends to one address. Use another wallet for another system.

= I deleted a wallet by mistake =

Restore it with one click within 72 hours. After that it can be restored with a new key: open your developer's link with the connection key on the phone, tap "Test connection", then "Resend all" in the app's "Did not reach your store".

= Before deactivating this plugin or WooCommerce =

Pause the wallet in the Qersh app or remove the store URL from it; on the ?wc-api= fallback URL the storefront would otherwise answer 200 and the phone would count transfers as delivered.

= Does money pass through Qersh? =

No. Qersh is a wallet notification relay, not a payment gateway: the money goes from your customer's wallet straight to yours, and Qersh only tells your store that it arrived.

= Fees? =

Qersh takes nothing from any transfer; the subscription is a fixed monthly fee. Your customer's wallet or InstaPay may charge its usual transfer fee.

== Screenshots ==

1. Checkout with the payer's wallet number field.
2. Thank-you page: amount, store wallet, deadline and live status.
3. Orange Cash order page: the transaction id box.
4. Wallets: store URL, developer's link and QR code.
5. Transfers: "Needs a decision" with the suggested order.
6. Order screen: the Qersh box.

== Changelog ==

= 0.1.0 =
* First release: e-wallet transfers for the classic checkout, the Checkout block and "Pay for order", matched by payer and time; Orange Cash transaction id; "Needs a decision"; diagnostics; English and Arabic.

== Upgrade Notice ==

= 0.1.0 =
First release.
