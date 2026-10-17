<?php
/**
 * A manual attach, as the Transfers tab's "Attach" makes it (Orders::apply_attach()), for the concurrency run of the
 * nightly MariaDB job (tests/e2e/concurrency.mjs, docs/17 §11 and §14.1 "two crossing attaches between two payers:
 * no deadlock"):
 *
 *   wp eval-file wp-content/plugins/qersh-for-woocommerce/tests/e2e/setup/attach.php transfer=<transfer id> order=<id> [at=<epoch ms>]
 *
 * at=<epoch ms> waits, after WordPress has loaded, until that moment, so two processes started together call
 * apply_attach() at the same time. Prints one line `QERSH_JSON={…}`: ok, the error code and message when refused,
 * and how long the call took.
 *
 * @package QershPay\WooCommerce\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	require dirname( __DIR__, 2 ) . '/local/lib/bootstrap.php';
	/** @var string[] $qersh_local_args Set by tests/local/lib/bootstrap.php. */
	$args = $qersh_local_args; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the name wp eval-file gives the arguments.
}
defined( 'ABSPATH' ) || exit;

$qersh_e2e_opts = array(
	'transfer' => '',
	'order'    => '0',
	'at'       => '0',
);
foreach ( isset( $args ) && is_array( $args ) ? $args : array() as $qersh_e2e_arg ) {
	$qersh_e2e_pair = explode( '=', (string) $qersh_e2e_arg, 2 );
	if ( 2 === count( $qersh_e2e_pair ) ) {
		$qersh_e2e_opts[ $qersh_e2e_pair[0] ] = $qersh_e2e_pair[1];
	}
}

$qersh_e2e_admins = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	)
);
$qersh_e2e_user   = empty( $qersh_e2e_admins ) ? 1 : (int) $qersh_e2e_admins[0];
wp_set_current_user( $qersh_e2e_user );

$qersh_e2e_at = (int) $qersh_e2e_opts['at'] / 1000;
if ( $qersh_e2e_at > microtime( true ) ) {
	time_sleep_until( $qersh_e2e_at );
}
$qersh_e2e_started = microtime( true );
$qersh_e2e_result  = \QershPay\WooCommerce\Plugin::instance()->orders()->apply_attach( (string) $qersh_e2e_opts['transfer'], (int) $qersh_e2e_opts['order'], $qersh_e2e_user );
$qersh_e2e_ms      = (int) round( ( microtime( true ) - $qersh_e2e_started ) * 1000 );

global $wpdb;
echo 'QERSH_JSON=' . wp_json_encode(
	array(
		'ok'       => true === $qersh_e2e_result,
		'code'     => is_wp_error( $qersh_e2e_result ) ? $qersh_e2e_result->get_error_code() : null,
		'message'  => is_wp_error( $qersh_e2e_result ) ? $qersh_e2e_result->get_error_message() : null,
		'db_error' => (string) $wpdb->last_error,
		'ms'       => $qersh_e2e_ms,
	),
	JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
) . "\n";
