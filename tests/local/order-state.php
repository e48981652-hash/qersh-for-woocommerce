<?php
/**
 * CLI: php tests/local/order-state.php <order id> [--expire-now] [--backdate-created <seconds>] [--run-expiry]
 *
 * Prints one JSON line with what the end-to-end runs assert on: the WooCommerce order status, the stock of its
 * items, its notes, the Qersh meta, the qersh_orders row and the transfers attached to it (with the envelope's
 * wallet.provider, which the plugin only keeps as information). Test store only.
 * --expire-now moves the deadline seven hours into the past (to test the expiry); --backdate-created moves the
 * row's created_at back by that many seconds; --run-expiry runs the plugin's expiry of the order before printing.
 *
 * @package QershPay\WooCommerce\Tests
 */

require __DIR__ . '/lib/bootstrap.php';

global $wpdb;
/** @var string[] $qersh_local_args Set by lib/bootstrap.php. */
$qersh_order_id  = isset( $qersh_local_args[0] ) ? (int) $qersh_local_args[0] : 0;
$qersh_args      = array_slice( $qersh_local_args, 1 );
$qersh_t_orders  = $wpdb->prefix . 'qersh_orders';
$qersh_t_trf     = $wpdb->prefix . 'qersh_transfers';
$qersh_meta_keys = array( '_qersh_payer_key', '_qersh_wallet', '_qersh_placed_at', '_qersh_deadline', '_qersh_expired_at', '_qersh_flag', '_qersh_claim_txn' );

if ( in_array( '--expire-now', $qersh_args, true ) ) {
	$wpdb->query( $wpdb->prepare( "UPDATE {$qersh_t_orders} SET expires_at = %d WHERE order_id = %d", time() - 7 * 3600, $qersh_order_id ) ); // phpcs:ignore WordPress.DB -- the test store's own tables, in a test script.
	$qersh_order = wc_get_order( $qersh_order_id );
	if ( $qersh_order ) {
		$qersh_order->update_meta_data( '_qersh_deadline', (string) ( time() - 7 * 3600 ) );
		$qersh_order->save();
	}
}
$qersh_i = array_search( '--backdate-created', $qersh_args, true );
if ( false !== $qersh_i && isset( $qersh_args[ $qersh_i + 1 ] ) ) {
	$wpdb->query( $wpdb->prepare( "UPDATE {$qersh_t_orders} SET created_at = created_at - %d WHERE order_id = %d", (int) $qersh_args[ $qersh_i + 1 ], $qersh_order_id ) ); // phpcs:ignore WordPress.DB -- the test store's own tables, in a test script.
}
if ( in_array( '--run-expiry', $qersh_args, true ) ) {
	\QershPay\WooCommerce\Plugin::instance()->expiry()->run( $qersh_order_id );
}

$qersh_order = wc_get_order( $qersh_order_id );
if ( ! $qersh_order ) {
	echo wp_json_encode( array( 'error' => 'no such order' ) ) . "\n";
	exit( 0 );
}
$qersh_stock = array();
foreach ( $qersh_order->get_items() as $qersh_item ) {
	$qersh_product = $qersh_item instanceof WC_Order_Item_Product ? $qersh_item->get_product() : false;
	if ( $qersh_product ) {
		$qersh_stock[ $qersh_product->get_id() ] = $qersh_product->get_stock_quantity();
	}
}
$qersh_notes = array_map(
	function ( $n ) {
		return ( $n->customer_note ? '[customer] ' : '' ) . wp_strip_all_tags( $n->content );
	},
	wc_get_order_notes( array( 'order_id' => $qersh_order_id ) )
);
$qersh_meta  = array();
foreach ( $qersh_meta_keys as $qersh_key ) {
	$qersh_meta[ $qersh_key ] = $qersh_order->get_meta( $qersh_key );
}
echo wp_json_encode(
	array(
		'order_id'  => $qersh_order_id,
		'status'    => $qersh_order->get_status(),
		'total'     => $qersh_order->get_total(),
		'paid'      => $qersh_order->is_paid(),
		'stock'     => $qersh_stock,
		'meta'      => $qersh_meta,
		'row'       => $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$qersh_t_orders} WHERE order_id = %d", $qersh_order_id ), ARRAY_A ), // phpcs:ignore WordPress.DB -- the test store's own tables, in a test script.
		'transfers' => $wpdb->get_results( $wpdb->prepare( "SELECT transfer_id, wallet, provider, outcome, reason, amount_minor, payer_key, provider_txn_id, flag, phone_sync FROM {$qersh_t_trf} WHERE order_id = %d", $qersh_order_id ), ARRAY_A ), // phpcs:ignore WordPress.DB -- the test store's own tables, in a test script.
		'notes'     => array_reverse( $qersh_notes ),
	),
	JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
) . "\n";
