# Security policy

Qersh for WooCommerce verifies signed webhooks and stores the connection key of each wallet.

## Reporting a vulnerability

Please do not open a public issue. Email support@qersh.cfd with "security" in the subject. Include the plugin version, what an attacker can do, and the steps to reproduce. Please give us time to release a fix before you disclose the details.

## Supported versions

Only the latest release gets security fixes. The plugin is in its 0.x series, so update to the newest 0.x version.

## Scope

In scope: this plugin's code, its REST routes (`qersh/v1/webhook/<wallet>`, `qersh/v1/order-status`, `qersh/v1/order-txn`) and the `?wc-api=qersh` fallback, signature checks, key storage, and the data it keeps in WordPress.

Out of scope here: the Qersh Android app and qersh.cfd (report those to the same address), and WordPress, WooCommerce or other plugins (report those to their authors).
