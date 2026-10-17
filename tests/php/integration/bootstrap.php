<?php
/**
 * Bootstrap of the integration tests: the WordPress test library (WP_TESTS_DIR), WooCommerce, then this plugin, on
 * a real database. HPOS is on unless DISABLE_HPOS=1. CI installs the library, WordPress and WooCommerce first
 * (tests/php/integration/install-wp-tests.sh, or wp-env with .wp-env.test.json); there is no local run without a
 * MySQL or MariaDB server.
 *
 * Environment:
 * - WP_TESTS_DIR: the test library (`includes/functions.php` inside), default <tmp>/wordpress-tests-lib.
 * - WC_PLUGIN_FILE: woocommerce.php, default WP_PLUGIN_DIR/woocommerce/woocommerce.php.
 * - DISABLE_HPOS: `1` runs every test on the posts tables.
 *
 * @package QershPay\WooCommerce\Tests
 */

$qersh_wc_root      = dirname( __DIR__, 3 );
$qersh_wc_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( false === $qersh_wc_tests_dir || '' === $qersh_wc_tests_dir ) {
	$qersh_wc_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}
if ( ! is_file( $qersh_wc_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "The WordPress test library is not at {$qersh_wc_tests_dir}. Set WP_TESTS_DIR (see tests/php/integration/install-wp-tests.sh).\n" );
	exit( 1 );
}
if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $qersh_wc_root . '/vendor/yoast/phpunit-polyfills' );
}
define( 'QERSH_WC_TESTS_ROOT', $qersh_wc_root );
define( 'QERSH_WC_TESTS_HPOS', '1' !== getenv( 'DISABLE_HPOS' ) );

require_once $qersh_wc_root . '/vendor/autoload.php';
require_once $qersh_wc_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	function () use ( $qersh_wc_root ) {
		$wc = getenv( 'WC_PLUGIN_FILE' );
		if ( false === $wc || '' === $wc ) {
			$wc = WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
		}
		require_once $wc;
		require_once $qersh_wc_root . '/qersh-for-woocommerce.php';
	}
);

tests_add_filter(
	'setup_theme',
	function () {
		WC_Install::install();

		// The roles were read before WooCommerce added its capabilities (core ticket 28374).
		$GLOBALS['wp_roles'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		wp_roles();

		$sync = wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer::class );
		if ( ! $sync->check_orders_table_exists() ) {
			$sync->create_database_tables();
		}
		update_option( 'woocommerce_custom_orders_table_enabled', QERSH_WC_TESTS_HPOS ? 'yes' : 'no' );
		update_option( 'woocommerce_custom_orders_table_data_sync_enabled', 'no' );
		update_option( 'woocommerce_currency', 'EGP' );
		update_option( 'woocommerce_default_country', 'EG:C' );
		update_option( 'woocommerce_manage_stock', 'yes' );

		\QershPay\WooCommerce\Store\Schema::install();
	}
);

require $qersh_wc_tests_dir . '/includes/bootstrap.php';
require_once __DIR__ . '/TestCase.php';

$qersh_wc_hpos = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
fwrite( STDOUT, 'Qersh integration tests: WordPress ' . get_bloginfo( 'version' ) . ', WooCommerce ' . ( defined( 'WC_VERSION' ) ? WC_VERSION : '?' ) . ', PHP ' . PHP_VERSION . ', HPOS ' . ( $qersh_wc_hpos ? 'on' : 'off' ) . "\n" );

// WooCommerce may refuse the option silently (CustomOrdersTableController::process_pre_update_option() keeps "no"
// while woocommerce_custom_orders_table_created is not "yes"): a row of the matrix must test the storage it names.
if ( QERSH_WC_TESTS_HPOS !== $qersh_wc_hpos ) {
	fwrite(
		STDERR,
		sprintf(
			"HPOS is %s, but DISABLE_HPOS asked for it %s (woocommerce_custom_orders_table_enabled = %s, woocommerce_custom_orders_table_created = %s).\n",
			$qersh_wc_hpos ? 'on' : 'off',
			QERSH_WC_TESTS_HPOS ? 'on' : 'off',
			var_export( get_option( 'woocommerce_custom_orders_table_enabled' ), true ), // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
			var_export( get_option( 'woocommerce_custom_orders_table_created' ), true ) // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
		)
	);
	exit( 1 );
}
