<?php
/**
 * Resets the store for one delivery scenario of the kit's sender (docs/17 §1 criterion 2: "against a store reset
 * before each scenario"). fixtures/sink/send.mjs always sends the same payer (01112233445) and the same transfer id
 * (trf_01JA2Y4X5M7Q9R0S1T2U3V4W5Z), so a scenario that follows another would otherwise meet the earlier one's
 * orders and transfer:
 *
 *   wp eval-file wp-content/plugins/qersh-for-woocommerce/tests/e2e/setup/reset.php payer=01112233445 transfer=trf_…
 *   php tests/e2e/setup/reset.php …   (a local test site)
 *
 * Deletes (for good) every order of that payer, through WooCommerce, so the plugin's own delete hook removes its
 * row, and every stored transfer with that transfer id. Test store only. Prints one line `QERSH_JSON={…}` with what
 * it deleted.
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
	'payer'    => '',
	'transfer' => '',
);
foreach ( isset( $args ) && is_array( $args ) ? $args : array() as $qersh_e2e_arg ) {
	$qersh_e2e_pair = explode( '=', (string) $qersh_e2e_arg, 2 );
	if ( 2 === count( $qersh_e2e_pair ) ) {
		$qersh_e2e_opts[ $qersh_e2e_pair[0] ] = $qersh_e2e_pair[1];
	}
}

global $wpdb;
$qersh_e2e_orders = array();
if ( '' !== $qersh_e2e_opts['payer'] ) {
	$qersh_e2e_ids = $wpdb->get_col( $wpdb->prepare( 'SELECT order_id FROM %i WHERE payer_key = %s', $wpdb->prefix . 'qersh_orders', $qersh_e2e_opts['payer'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a test reads or sets up the plugin's tables directly.
	$qersh_e2e_ids = array_merge(
		array_map( 'intval', $qersh_e2e_ids ),
		wc_get_orders(
			array(
				'limit'      => -1,
				'return'     => 'ids',
				'status'     => 'any',
				'meta_key'   => '_qersh_payer_key', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_key -- test cleanup, a handful of orders.
				'meta_value' => $qersh_e2e_opts['payer'], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_value -- test cleanup, a handful of orders.
			)
		)
	);
	foreach ( array_unique( array_map( 'intval', $qersh_e2e_ids ) ) as $qersh_e2e_id ) {
		$qersh_e2e_order = wc_get_order( $qersh_e2e_id );
		if ( $qersh_e2e_order ) {
			$qersh_e2e_order->delete( true );
			$qersh_e2e_orders[] = $qersh_e2e_id;
		} else {
			$wpdb->delete( $wpdb->prefix . 'qersh_orders', array( 'order_id' => $qersh_e2e_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a test reads or sets up the plugin's tables directly.
		}
	}
}
$qersh_e2e_transfers = 0;
if ( '' !== $qersh_e2e_opts['transfer'] ) {
	$qersh_e2e_transfers = (int) $wpdb->delete( $wpdb->prefix . 'qersh_transfers', array( 'transfer_id' => $qersh_e2e_opts['transfer'] ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a test reads or sets up the plugin's tables directly.
}

echo 'QERSH_JSON=' . wp_json_encode(
	array(
		'orders'    => $qersh_e2e_orders,
		'transfers' => $qersh_e2e_transfers,
	),
	JSON_UNESCAPED_SLASHES
) . "\n";
