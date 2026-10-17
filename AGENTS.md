# Notes for coding agents working in this repository

- **What this is:** the WordPress plugin "Qersh for WooCommerce" (slug `qersh-for-woocommerce`), built for the wordpress.org directory.
  - **The design** is `docs/17-woocommerce-plugin-design.md` in the Qersh repository (`D:\qersh pay`), with its evidence in `docs/16-woocommerce-research.md`.
  - **The names** every module shares, and who owns which file, are in `docs-dev/CONTRACTS.md` here. Read it before you write code.
- **Legal wording:** Qersh is a "wallet notification relay" and a notification tool, never a payment gateway, payment service provider or collection service. `node scripts/check-wording.mjs` enforces this on everything a user reads.
- **Vendored copies:** `fixtures/*`, `tests/php/fixtures/verify.php` and `tests/parity/{core,kit}/*` are copies from the Qersh repository, pinned by `fixtures.lock.json`.
  - Never edit them here. Change the source there, then run `node scripts/import-from-qersh.mjs "D:/qersh pay"`.
  - `fixtures/test-vectors.json` contains Arabic text. Copy the file, never retype it.
  - Test keys live only there, never as literals in PHP.
- **PHP 7.4 syntax, WordPress coding standards, no runtime dependencies.** Composer is for development tools only. `src/Core` is pure PHP without WordPress.
- **Checks:**
  - `composer test:unit` runs the unit tests (no WordPress).
  - `node scripts/check-fixtures.mjs` checks the vendored copies and the patterns.
  - `node scripts/check-wording.mjs` is the wording guard.
  - `php scripts/lint-php.php` runs `php -l` on every file.
- **Releases:** bare version tags (`0.1.0`, never `v0.1.0`) from a clean `main`. The zip is built from an allow-list by `scripts/build-zip.mjs` and never contains dotfiles, `*.dist`, `*.sh`, tests, fixtures, scripts or vendor.
- **Owner's machine:** Windows, no Docker, WSL broken. Install nothing system-wide.
  - **In CI on Linux:** the integration tests on MySQL 8.0 through `tests/php/integration/install-wp-tests.sh` (`e2e.yml`), and on MariaDB through `wp-env --config=.wp-env.test.json` (`nightly.yml`); the end-to-end tests on wp-env (`e2e.yml`).
  - **Locally:** `tests/local/` is the harness: the installed PHP 8.3 with the SQLite plugin and PHP's built-in server (see `tests/local/README.md`), or WordPress Playground through npx.
