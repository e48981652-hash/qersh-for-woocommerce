<?php
/**
 * PHPStan bootstrap: the constants the plugin's main file defines at load time, and the ones a site may define in
 * wp-config.php. Values are placeholders for analysis only; phpstan.neon marks the optional ones dynamic so no
 * branch on them is judged dead. The WordPress and WooCommerce stubs come from phpstan.neon.
 *
 * @package QershPay\WooCommerce\Tests
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', '/' );

// qersh-for-woocommerce.php.
define( 'QERSH_WC_VERSION', '0.1.1' );
define( 'QERSH_WC_FILE', '/wp-content/plugins/qersh-for-woocommerce/qersh-for-woocommerce.php' );
define( 'QERSH_WC_DIR', '/wp-content/plugins/qersh-for-woocommerce/' );
define( 'QERSH_WC_URL', 'https://example.org/wp-content/plugins/qersh-for-woocommerce/' );
define( 'QERSH_WC_DB_VERSION', 2 );
define( 'QERSH_WC_MIN_WC', '11.0' );

// wp-config.php, optional (dynamic in phpstan.neon).
define( 'QERSH_WC_CONFIG', '{}' );
define( 'QERSH_WC_REMOVE_ALL_DATA', false );
defined( 'LOGGED_IN_KEY' ) || define( 'LOGGED_IN_KEY', 'phpstan-logged-in-key' );
defined( 'LOGGED_IN_SALT' ) || define( 'LOGGED_IN_SALT', 'phpstan-logged-in-salt' );
defined( 'WC_VERSION' ) || define( 'WC_VERSION', '11.2.1' );
