<?php
/**
 * Bootstrap of the unit tests: pure PHP, no WordPress. Only src/Core and other WordPress-free classes are tested here.
 *
 * @package QershPay\WooCommerce\Tests
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'QERSH_WC_TESTS_ROOT', dirname( __DIR__, 3 ) );

require_once QERSH_WC_TESTS_ROOT . '/vendor/autoload.php';
require_once QERSH_WC_TESTS_ROOT . '/src/Autoloader.php';
\QershPay\WooCommerce\Autoloader::register();

/**
 * Reads a fixture file of this repository.
 *
 * @param string $relative Path from the repository root.
 * @return string
 */
function qersh_wc_test_fixture( $relative ) {
	$path = QERSH_WC_TESTS_ROOT . '/' . $relative;
	if ( ! is_readable( $path ) ) {
		throw new RuntimeException( 'missing fixture ' . $relative );
	}
	return (string) file_get_contents( $path );
}
