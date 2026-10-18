<?php
/**
 * End-to-end wallets, run once per job before Playwright (tests/e2e/global-setup.mjs):
 *
 *   wp eval-file wp-content/plugins/qersh-for-woocommerce/tests/e2e/setup/wallets.php
 *   php tests/e2e/setup/wallets.php   (a local test site, through tests/local/lib/bootstrap.php)
 *
 * Replaces the plugin's wallets with three test e-wallets told apart by their label: `main` (the store number
 * 01001234567 that the RTL test reads), `second` (01201234567) and `txn` (01501234567). `txn` is used only by the
 * scenarios where a typed transaction id must confirm an order: an open order of the same amount on the same wallet
 * whose customer typed no id rightly blocks that (owner decision 20), and the other scenarios leave such orders on
 * `main` and `second`. A wallet has no type: the plugin never asks which
 * operator is behind a number. Each gets a fresh test key from the plugin's own Config::new_key(); the script turns
 * the transaction-id box on and enables the method. Prints one line `QERSH_JSON={…}` with the ids, labels, numbers,
 * test keys and both webhook addresses (REST and the ?wc-api= fallback). The keys exist only in this test site and
 * the Playwright state file of the job (tests/e2e/.auth/, never committed).
 *
 * @package QershPay\WooCommerce\Tests
 */

// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- a command-line script: its errors go to STDERR, which WP_Filesystem cannot write.

if ( ! defined( 'ABSPATH' ) ) {
	require dirname( __DIR__, 2 ) . '/local/lib/bootstrap.php';
}
defined( 'ABSPATH' ) || exit;

$qersh_e2e_plugin = \QershPay\WooCommerce\Plugin::instance();
$qersh_e2e_config = $qersh_e2e_plugin->config_store()->get();
$qersh_e2e_want   = array(
	array(
		'label'           => 'main',
		'receive_address' => '01001234567',
	),
	array(
		'label'           => 'second',
		'receive_address' => '01201234567',
	),
	array(
		'label'           => 'txn',
		'receive_address' => '01501234567',
	),
);

$qersh_e2e_config['wallets']                 = array();
$qersh_e2e_config['store']['txn_entry']      = true;
$qersh_e2e_config['store']['allow_key_link'] = true;
$qersh_e2e_out                               = array( 'wallets' => array() );
foreach ( $qersh_e2e_want as $qersh_e2e_w ) {
	$qersh_e2e_id                  = \QershPay\WooCommerce\Core\Config::new_wallet_id();
	$qersh_e2e_config['wallets'][] = array(
		'id'              => $qersh_e2e_id,
		'label'           => $qersh_e2e_w['label'],
		'receive_address' => $qersh_e2e_w['receive_address'],
		'enabled'         => true,
	);
	$qersh_e2e_key                 = \QershPay\WooCommerce\Core\Config::new_key();
	$qersh_e2e_out['wallets'][]    = array(
		'id'              => $qersh_e2e_id,
		'label'           => $qersh_e2e_w['label'],
		'receive_address' => $qersh_e2e_w['receive_address'],
		'key'             => $qersh_e2e_key,
		'url'             => rest_url( 'qersh/v1/webhook/' . $qersh_e2e_id ),
		'fallback'        => add_query_arg(
			array(
				'wc-api' => 'qersh',
				'qw'     => $qersh_e2e_id,
			),
			home_url( '/' )
		),
	);
}
$qersh_e2e_saved = $qersh_e2e_plugin->config_store()->save( $qersh_e2e_config );
if ( is_wp_error( $qersh_e2e_saved ) ) {
	fwrite( STDERR, 'config save failed: ' . $qersh_e2e_saved->get_error_message() . "\n" );
	exit( 1 );
}
$qersh_e2e_plugin->forget_config();
foreach ( $qersh_e2e_out['wallets'] as $qersh_e2e_w ) {
	$qersh_e2e_plugin->secrets()->set_current( $qersh_e2e_w['id'], $qersh_e2e_w['key'], time() );
}

$qersh_e2e_settings            = get_option( 'woocommerce_qersh_settings', array() );
$qersh_e2e_settings            = is_array( $qersh_e2e_settings ) ? $qersh_e2e_settings : array();
$qersh_e2e_settings['enabled'] = 'yes';
update_option( 'woocommerce_qersh_settings', $qersh_e2e_settings );

echo 'QERSH_JSON=' . wp_json_encode( $qersh_e2e_out, JSON_UNESCAPED_SLASHES ) . "\n";
