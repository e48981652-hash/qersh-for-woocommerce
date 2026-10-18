<?php
/**
 * CLI: php tests/local/setup-wallets.php
 *
 * Local test store only. Configures Qersh for WooCommerce with two test e-wallets told apart by their label, `main`
 * and `second` (a number and a name: a wallet has no type, the plugin never asks which operator is behind a number),
 * turns the transaction-id box on (store.txn_entry), generates a test connection key for each with the plugin's own
 * Config::new_key(), stores it through Secrets, enables the payment method, and writes the wallets file for the
 * end-to-end runs (ids, labels, numbers, URLs and keys; see qersh_local_wallets_file(): QERSH_WALLETS, or
 * qersh-wallets.local.json next to the site directory, never inside this repository or the web root). Nothing here
 * is a real key.
 *
 * @package QershPay\WooCommerce\Tests
 */

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- a command-line script: its report goes to a terminal (STDOUT and STDERR), not a page.

require __DIR__ . '/lib/bootstrap.php';

use QershPay\WooCommerce\Core\Config;
use QershPay\WooCommerce\Plugin;

$qersh_plugin                       = Plugin::instance();
$qersh_config                       = $qersh_plugin->config_store()->get();
$qersh_out                          = array(
	'site'    => home_url(),
	'wallets' => array(),
);
$qersh_want                         = array(
	array(
		'label'           => 'main',
		'receive_address' => '01000000101',
	),
	array(
		'label'           => 'second',
		'receive_address' => '01200000102',
	),
);
$qersh_config['wallets']            = array();
$qersh_config['store']['txn_entry'] = true;
foreach ( $qersh_want as $qersh_w ) {
	$qersh_id                  = Config::new_wallet_id();
	$qersh_config['wallets'][] = array(
		'id'              => $qersh_id,
		'label'           => $qersh_w['label'],
		'receive_address' => $qersh_w['receive_address'],
		'enabled'         => true,
	);
	$qersh_key                 = Config::new_key();
	$qersh_plugin->secrets()->set_current( $qersh_id, $qersh_key, time() );
	$qersh_out['wallets'][] = array(
		'id'              => $qersh_id,
		'label'           => $qersh_w['label'],
		'receive_address' => $qersh_w['receive_address'],
		'key'             => $qersh_key,
		'url'             => rest_url( 'qersh/v1/webhook/' . $qersh_id ),
		'fallback'        => add_query_arg(
			array(
				'wc-api' => 'qersh',
				'qw'     => $qersh_id,
			),
			home_url( '/' )
		),
	);
}
$qersh_saved = $qersh_plugin->config_store()->save( $qersh_config );
if ( is_wp_error( $qersh_saved ) ) {
	fwrite( STDERR, 'config save failed: ' . $qersh_saved->get_error_message() . "\n" );
	exit( 1 );
}
$qersh_settings            = get_option( 'woocommerce_qersh_settings', array() );
$qersh_settings            = is_array( $qersh_settings ) ? $qersh_settings : array();
$qersh_settings['enabled'] = 'yes';
update_option( 'woocommerce_qersh_settings', $qersh_settings );

/** @var string $qersh_local_site Set by lib/bootstrap.php. */
$qersh_file = qersh_local_wallets_file( $qersh_local_site );
if ( false === file_put_contents( $qersh_file, wp_json_encode( $qersh_out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" ) ) {
	fwrite( STDERR, "could not write {$qersh_file}\n" );
	exit( 1 );
}
echo 'configured ' . count( $qersh_out['wallets'] ) . " wallets; wrote {$qersh_file}\n";
foreach ( $qersh_out['wallets'] as $qersh_w ) {
	echo '  ' . $qersh_w['label'] . ' ' . $qersh_w['receive_address'] . ' ' . $qersh_w['id'] . ' ' . $qersh_w['url'] . "\n";
}
