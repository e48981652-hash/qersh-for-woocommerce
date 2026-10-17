#!/usr/bin/env bash
# Installs the PHP-FPM test site of the nightly job: WordPress, WooCommerce 11.2.1, this plugin, the store and two
# test wallets (tests/e2e/setup/*.php). Writes tests/e2e/.auth/fpm.json (ids, test keys, addresses; never committed).
# The administrator account is a test account of this throwaway container only.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"
cd "$ROOT"
wp() { docker compose -f tests/e2e/fpm/compose.yml --profile cli run --rm -T cli "$@"; }

wp core install --url=http://localhost:8080 --title='Qersh FPM' --admin_user=admin --admin_password=password --admin_email=admin@example.org --skip-email
wp rewrite structure '/%postname%/'
wp plugin install woocommerce --version=11.2.1 --activate
wp plugin activate qersh-for-woocommerce
wp option update woocommerce_coming_soon no

mkdir -p tests/e2e/.auth
wp eval-file wp-content/plugins/qersh-for-woocommerce/tests/e2e/setup/store.php checkout=blocks | grep '^QERSH_JSON=' | sed 's/^QERSH_JSON=//' > tests/e2e/.auth/fpm-store.json
wp eval-file wp-content/plugins/qersh-for-woocommerce/tests/e2e/setup/wallets.php | grep '^QERSH_JSON=' | sed 's/^QERSH_JSON=//' > tests/e2e/.auth/fpm-wallets.json
node -e "
const fs = require('fs');
const store = JSON.parse(fs.readFileSync('tests/e2e/.auth/fpm-store.json', 'utf8'));
const wallets = JSON.parse(fs.readFileSync('tests/e2e/.auth/fpm-wallets.json', 'utf8'));
fs.writeFileSync('tests/e2e/.auth/fpm.json', JSON.stringify({ ...store, ...wallets }, null, '\t'));
console.log('fpm store:', store.versions, 'products', store.products, 'wallets', wallets.wallets.map((w) => w.provider + ' ' + w.id).join(', '));
"
wp eval 'echo "fastcgi_finish_request: " . ( function_exists( "fastcgi_finish_request" ) ? "CLI has it" : "CLI has none (expected; the FPM container has it)" ) . "\n";'
