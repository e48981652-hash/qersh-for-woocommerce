<?php
/**
 * What a Playwright test asserts on the server side of an order (tests/e2e/utils.mjs orderState()):
 *
 *   wp eval-file wp-content/plugins/qersh-for-woocommerce/tests/e2e/setup/order-state.php order=<id> [cron=1] [shift=<s>] [backdate=<s>]
 *   php tests/e2e/setup/order-state.php order=<id> [cron=1]   (a local test site)
 *
 * cron=1 first runs one Action Scheduler batch (the completion, the late transaction-id evaluation and the emails
 * WooCommerce deferred run there when the host cannot finish the response early). shift=<s> moves the row's
 * created_at and expires_at back together (--late and --held, docs/17 §11); backdate=<s> moves created_at alone.
 * Prints one line `QERSH_JSON={…}`: status, paid, the stock of its products, Qersh meta, the qersh_orders row, the
 * stored transfers linked to it and its notes (oldest first).
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
	'order'    => '0',
	'cron'     => '0',
	'shift'    => '0',
	'backdate' => '0',
);
foreach ( isset( $args ) && is_array( $args ) ? $args : array() as $qersh_e2e_arg ) {
	$qersh_e2e_pair = explode( '=', (string) $qersh_e2e_arg, 2 );
	if ( 2 === count( $qersh_e2e_pair ) ) {
		$qersh_e2e_opts[ $qersh_e2e_pair[0] ] = $qersh_e2e_pair[1];
	}
}
$qersh_e2e_id = (int) $qersh_e2e_opts['order'];

global $wpdb;
$qersh_e2e_rows = $wpdb->prefix . 'qersh_orders';
if ( (int) $qersh_e2e_opts['shift'] > 0 ) {
	$wpdb->query( $wpdb->prepare( 'UPDATE %i SET created_at = created_at - %d, expires_at = expires_at - %d WHERE order_id = %d', $qersh_e2e_rows, (int) $qersh_e2e_opts['shift'], (int) $qersh_e2e_opts['shift'], $qersh_e2e_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a test reads or sets up the plugin's tables directly.
}
if ( (int) $qersh_e2e_opts['backdate'] > 0 ) {
	$wpdb->query( $wpdb->prepare( 'UPDATE %i SET created_at = created_at - %d WHERE order_id = %d', $qersh_e2e_rows, (int) $qersh_e2e_opts['backdate'], $qersh_e2e_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a test reads or sets up the plugin's tables directly.
}
if ( '1' === $qersh_e2e_opts['cron'] && class_exists( 'ActionScheduler_QueueRunner' ) ) {
	ActionScheduler_QueueRunner::instance()->run( 'qersh-e2e' );
}

$qersh_e2e_order = wc_get_order( $qersh_e2e_id );
$qersh_e2e_meta  = array();
$qersh_e2e_stock = array();
$qersh_e2e_notes = array();
if ( $qersh_e2e_order ) {
	foreach ( array( '_qersh_payer_key', '_qersh_wallet', '_qersh_deadline', '_qersh_flag', '_qersh_claim_txn' ) as $qersh_e2e_key ) {
		$qersh_e2e_meta[ $qersh_e2e_key ] = $qersh_e2e_order->get_meta( $qersh_e2e_key );
	}
	foreach ( $qersh_e2e_order->get_items() as $qersh_e2e_item ) {
		$qersh_e2e_product = is_callable( array( $qersh_e2e_item, 'get_product' ) ) ? $qersh_e2e_item->get_product() : null;
		if ( $qersh_e2e_product ) {
			$qersh_e2e_stock[ (string) $qersh_e2e_product->get_id() ] = $qersh_e2e_product->get_stock_quantity();
		}
	}
	foreach ( array_reverse( wc_get_order_notes( array( 'order_id' => $qersh_e2e_id ) ) ) as $qersh_e2e_note ) {
		$qersh_e2e_notes[] = wp_strip_all_tags( (string) $qersh_e2e_note->content );
	}
}
$qersh_e2e_transfers = $wpdb->get_results( $wpdb->prepare( 'SELECT transfer_id, outcome, reason, amount_minor, payer_key, provider_txn_id, flag FROM %i WHERE order_id = %d ORDER BY id', $wpdb->prefix . 'qersh_transfers', $qersh_e2e_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a test reads or sets up the plugin's tables directly.
echo 'QERSH_JSON=' . wp_json_encode(
	array(
		'order_id'  => $qersh_e2e_id,
		'status'    => $qersh_e2e_order ? $qersh_e2e_order->get_status() : null,
		'paid'      => $qersh_e2e_order ? $qersh_e2e_order->is_paid() : false,
		'stock'     => $qersh_e2e_stock,
		'meta'      => $qersh_e2e_meta,
		'row'       => \QershPay\WooCommerce\Plugin::instance()->orders_index()->get( $qersh_e2e_id ),
		'transfers' => is_array( $qersh_e2e_transfers ) ? $qersh_e2e_transfers : array(),
		'notes'     => $qersh_e2e_notes,
	),
	JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
) . "\n";
