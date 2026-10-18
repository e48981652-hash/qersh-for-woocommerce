#!/usr/bin/env bash
# Installs WordPress, its PHPUnit test library and WooCommerce for the integration tests (CI on Linux, MySQL or
# MariaDB reachable over TCP).
#
#   tests/php/integration/install-wp-tests.sh <db-name> <db-user> <db-pass> <db-host> <wp> <wc>
#
#   <wp>: latest | nightly | x.y (the newest x.y.z) | x.y.z
#   <wc>: latest | rc (the newest beta or release candidate above the stable release, else the stable) | x.y | x.y.z
#
# Environment: WP_CORE_DIR (default /tmp/wordpress), WP_TESTS_DIR (default /tmp/wordpress-tests-lib).
# Prints the versions it installed, and adds them to the job summary on GitHub Actions.
set -euo pipefail

DB_NAME=${1:?db name}
DB_USER=${2:?db user}
DB_PASS=${3-}
DB_HOST=${4:-127.0.0.1}
WP_WANT=${5:-latest}
WC_WANT=${6:-latest}
TMP=${TMPDIR:-/tmp}
WP_CORE_DIR=${WP_CORE_DIR:-$TMP/wordpress}
WP_TESTS_DIR=${WP_TESTS_DIR:-$TMP/wordpress-tests-lib}

fetch() { curl -fsSL --retry 3 --retry-delay 5 "$@"; }

# --- WordPress version -------------------------------------------------------------------------------------------
if [ "$WP_WANT" = "nightly" ]; then
	WP_VERSION=nightly
	WP_ZIP=https://wordpress.org/nightly-builds/wordpress-latest.zip
	WP_REF=trunk
else
	OFFERS=$(fetch https://api.wordpress.org/core/version-check/1.7/ | jq -r '.offers[].version' | sort -Vr | uniq)
	if [ "$WP_WANT" = "latest" ]; then
		WP_VERSION=$(echo "$OFFERS" | head -n 1)
	elif [[ "$WP_WANT" =~ ^[0-9]+\.[0-9]+$ ]]; then
		WP_VERSION=$(echo "$OFFERS" | grep -E "^${WP_WANT//./\\.}(\.[0-9]+)?$" | head -n 1 || true)
		WP_VERSION=${WP_VERSION:-$WP_WANT}
	else
		WP_VERSION=$WP_WANT
	fi
	WP_ZIP=https://wordpress.org/wordpress-${WP_VERSION}.zip
	WP_REF=$WP_VERSION
fi

# --- WooCommerce version -----------------------------------------------------------------------------------------
WC_INFO=$(fetch https://api.wordpress.org/plugins/info/1.0/woocommerce.json)
WC_STABLE=$(echo "$WC_INFO" | jq -r '.version')
WC_ALL=$(echo "$WC_INFO" | jq -r '.versions | keys[]' | grep -v '^trunk$' | sort -V)
if [ "$WC_WANT" = "latest" ]; then
	WC_VERSION=$WC_STABLE
elif [ "$WC_WANT" = "rc" ]; then
	WC_PRE=$(echo "$WC_ALL" | grep -E -- '-(rc|beta)\.[0-9]+$' | tail -n 1 || true)
	# A pre-release counts only when its release is newer than the stable one.
	if [ -n "$WC_PRE" ] && [ "$(printf '%s\n%s\n' "${WC_PRE%%-*}" "$WC_STABLE" | sort -V | tail -n 1)" = "${WC_PRE%%-*}" ] && [ "${WC_PRE%%-*}" != "$WC_STABLE" ]; then
		WC_VERSION=$WC_PRE
	else
		echo "No WooCommerce pre-release above $WC_STABLE; testing the stable release."
		WC_VERSION=$WC_STABLE
	fi
elif [[ "$WC_WANT" =~ ^[0-9]+\.[0-9]+$ ]]; then
	WC_VERSION=$(echo "$WC_ALL" | grep -E "^${WC_WANT//./\\.}\.[0-9]+$" | tail -n 1 || true)
	if [ -z "$WC_VERSION" ]; then
		echo "No WooCommerce release ${WC_WANT}.x on wordpress.org" >&2
		exit 1
	fi
else
	WC_VERSION=$WC_WANT
fi

echo "WordPress $WP_VERSION ($WP_ZIP), tests from wordpress-develop $WP_REF; WooCommerce $WC_VERSION"

# --- WordPress core ----------------------------------------------------------------------------------------------
rm -rf "$WP_CORE_DIR" "$TMP/wordpress-zip"
mkdir -p "$TMP/wordpress-zip"
fetch -o "$TMP/wordpress-zip/wordpress.zip" "$WP_ZIP"
unzip -q "$TMP/wordpress-zip/wordpress.zip" -d "$TMP/wordpress-zip"
mv "$TMP/wordpress-zip/wordpress" "$WP_CORE_DIR"
rm -rf "$TMP/wordpress-zip"

# --- The PHPUnit test library, only tests/phpunit/{includes,data} of the same version ---------------------------
rm -rf "$WP_TESTS_DIR" "$TMP/wordpress-develop"
git clone --quiet --depth=1 --branch "$WP_REF" --filter=blob:none --sparse https://github.com/WordPress/wordpress-develop.git "$TMP/wordpress-develop"
git -C "$TMP/wordpress-develop" sparse-checkout set tests/phpunit/includes tests/phpunit/data
mkdir -p "$WP_TESTS_DIR"
mv "$TMP/wordpress-develop/tests/phpunit/includes" "$WP_TESTS_DIR/includes"
mv "$TMP/wordpress-develop/tests/phpunit/data" "$WP_TESTS_DIR/data"
rm -rf "$TMP/wordpress-develop"

cat > "$WP_TESTS_DIR/wp-tests-config.php" <<PHP
<?php
define( 'ABSPATH', '${WP_CORE_DIR}/' );
define( 'WP_DEFAULT_THEME', 'default' );
define( 'WP_DEBUG', true );
define( 'DB_NAME', '${DB_NAME}' );
define( 'DB_USER', '${DB_USER}' );
define( 'DB_PASSWORD', '${DB_PASS}' );
define( 'DB_HOST', '${DB_HOST}' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );
// Secrets seals the connection keys with a key derived from these two (src/Secrets.php); the test library's
// bootstrap defines neither. Fixed test-only values.
define( 'LOGGED_IN_KEY', 'qersh-integration-tests-logged-in-key-0001' );
define( 'LOGGED_IN_SALT', 'qersh-integration-tests-logged-in-salt-0001' );
\$table_prefix = 'wptests_';
define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Test Blog' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );
PHP

# --- WooCommerce -------------------------------------------------------------------------------------------------
mkdir -p "$WP_CORE_DIR/wp-content/plugins" "$TMP/wc-zip"
fetch -o "$TMP/wc-zip/woocommerce.zip" "https://downloads.wordpress.org/plugin/woocommerce.${WC_VERSION}.zip"
unzip -q "$TMP/wc-zip/woocommerce.zip" -d "$WP_CORE_DIR/wp-content/plugins"
rm -rf "$TMP/wc-zip"

# --- The database ------------------------------------------------------------------------------------------------
DB_HOSTNAME=${DB_HOST%%:*}
DB_PORT=3306
if [[ "$DB_HOST" == *:* ]]; then DB_PORT=${DB_HOST##*:}; fi
MYSQL_PWD="$DB_PASS" mysqladmin --user="$DB_USER" --host="$DB_HOSTNAME" --port="$DB_PORT" --protocol=tcp create "$DB_NAME" 2>/dev/null || echo "Database $DB_NAME exists already."

if [ -n "${GITHUB_STEP_SUMMARY:-}" ]; then
	echo "Integration environment: WordPress **$WP_VERSION**, WooCommerce **$WC_VERSION**, PHP **$(php -r 'echo PHP_VERSION;')**" >> "$GITHUB_STEP_SUMMARY"
fi
echo "Installed: WordPress $WP_VERSION in $WP_CORE_DIR, test library in $WP_TESTS_DIR, WooCommerce $WC_VERSION"
