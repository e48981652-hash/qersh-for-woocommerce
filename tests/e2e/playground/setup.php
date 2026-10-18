<?php
/**
 * WordPress Playground (SQLite, 32-bit PHP-WASM) setup of the nightly job, run by blueprint.json after WooCommerce
 * and the plugin are active: EGP, the method on, and one test e-wallet `nightly0001a` (a number and a label: a
 * wallet has no type) whose key is the vectors' own test secret (fixtures/test-vectors.json), so deliver.mjs can sign
 * for it from the runner.
 *
 * @package QershPay\WooCommerce\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	require '/wordpress/wp-load.php'; // @phpstan-ignore require.fileNotFound (WordPress Playground's own path, which exists only inside the Playground.)
}
defined( 'ABSPATH' ) || exit;

update_option( 'woocommerce_currency', 'EGP' );
update_option( 'woocommerce_default_country', 'EG:C' );
update_option( 'timezone_string', 'Africa/Cairo' );
update_option( 'woocommerce_coming_soon', 'no' );

$qersh_pg_plugin  = \QershPay\WooCommerce\Plugin::instance();
$qersh_pg_vectors = json_decode( (string) file_get_contents( dirname( __DIR__, 3 ) . '/fixtures/test-vectors.json' ), true );
$qersh_pg_config  = $qersh_pg_plugin->config_store()->get();

$qersh_pg_config['wallets'] = array(
	array(
		'id'              => 'nightly0001a',
		'label'           => 'Nightly',
		'receive_address' => '01001234567',
		'enabled'         => true,
	),
);
$qersh_pg_saved             = $qersh_pg_plugin->config_store()->save( $qersh_pg_config );
if ( is_wp_error( $qersh_pg_saved ) ) {
	echo 'config: ' . esc_html( $qersh_pg_saved->get_error_message() ) . "\n";
	return;
}
$qersh_pg_plugin->forget_config();
$qersh_pg_plugin->secrets()->set_current( 'nightly0001a', (string) $qersh_pg_vectors['secret'], time() );
update_option( 'woocommerce_qersh_settings', array( 'enabled' => 'yes' ) );
echo 'qersh playground setup: PHP_INT_SIZE=' . (int) PHP_INT_SIZE . ', db=' . ( defined( 'DB_ENGINE' ) ? esc_html( DB_ENGINE ) : 'mysql' ) . ', tables=' . ( $qersh_pg_plugin->tables_ready() ? 'ready' : 'missing' ) . "\n";
