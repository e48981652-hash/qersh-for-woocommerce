<?php
/**
 * A waiting order made on the server, for the delivery scenarios that need no browser (tests/e2e/deliveries.spec.mjs,
 * tests/e2e/concurrency.mjs): one product, the billing details of a guest, our method, then the method's own
 * process_payment() with the payer and the wallet as the checkout posts them (the qersh_orders row, the deadline,
 * the expiry action and the instructions email).
 *
 *   wp eval-file wp-content/plugins/qersh-for-woocommerce/tests/e2e/setup/order.php product=<id> payer=<number> wallet=<id> [backdate=<s>] [shift=<s>]
 *   php tests/e2e/setup/order.php …   (a local test site)
 *
 * backdate=<s> moves the row's created_at back (the money of a delivery "arrives one second before now", and with
 * skew_min = 0 an order newer than the money is never a candidate, docs/17 §4.6). shift=<s> moves created_at and
 * expires_at back together, for --late and --held (docs/17 §11). Prints one line `QERSH_JSON={…}`: the order id,
 * key and total, the qersh_orders row, and the addresses of the order-txn route (REST and the ?wc-api= fallback).
 *
 * @package QershPay\WooCommerce\Tests
 */

// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- a command-line script: its errors go to STDERR, which WP_Filesystem cannot write.

if ( ! defined( 'ABSPATH' ) ) {
	require dirname( __DIR__, 2 ) . '/local/lib/bootstrap.php';
	/** @var string[] $qersh_local_args Set by tests/local/lib/bootstrap.php. */
	$args = $qersh_local_args; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the name wp eval-file gives the arguments.
}
defined( 'ABSPATH' ) || exit;

$qersh_e2e_opts = array(
	'product'  => '0',
	'payer'    => '',
	'wallet'   => '',
	'backdate' => '0',
	'shift'    => '0',
	'email'    => 'customer@example.test',
);
foreach ( isset( $args ) && is_array( $args ) ? $args : array() as $qersh_e2e_arg ) {
	$qersh_e2e_pair = explode( '=', (string) $qersh_e2e_arg, 2 );
	if ( 2 === count( $qersh_e2e_pair ) ) {
		$qersh_e2e_opts[ $qersh_e2e_pair[0] ] = $qersh_e2e_pair[1];
	}
}

$qersh_e2e_product = wc_get_product( (int) $qersh_e2e_opts['product'] );
if ( ! $qersh_e2e_product ) {
	fwrite( STDERR, 'order.php: no product ' . $qersh_e2e_opts['product'] . "\n" );
	exit( 1 );
}

$qersh_e2e_order = wc_create_order();
$qersh_e2e_order->add_product( $qersh_e2e_product, 1 );
$qersh_e2e_order->set_billing_first_name( 'Test' );
$qersh_e2e_order->set_billing_last_name( 'Customer' );
$qersh_e2e_order->set_billing_email( $qersh_e2e_opts['email'] );
$qersh_e2e_order->set_billing_phone( $qersh_e2e_opts['payer'] );
$qersh_e2e_order->set_billing_country( 'EG' );
$qersh_e2e_order->set_currency( get_woocommerce_currency() );
$qersh_e2e_order->set_payment_method( 'qersh' );
$qersh_e2e_order->set_payment_method_title( 'Wallet transfer' );
$qersh_e2e_order->set_created_via( 'checkout' );
$qersh_e2e_order->calculate_totals( false );
$qersh_e2e_order->save();

$qersh_e2e_gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
if ( ! isset( $qersh_e2e_gateways['qersh'] ) ) {
	fwrite( STDERR, "order.php: the qersh method is not registered\n" );
	exit( 1 );
}
// phpcs:disable WordPress.Security.NonceVerification.Missing -- what the checkout posts, in a test script.
$_POST['qersh_payer']  = $qersh_e2e_opts['payer'];
$_POST['qersh_wallet'] = $qersh_e2e_opts['wallet'];
$qersh_e2e_result      = $qersh_e2e_gateways['qersh']->process_payment( $qersh_e2e_order->get_id() );
unset( $_POST['qersh_payer'], $_POST['qersh_wallet'] );
// phpcs:enable WordPress.Security.NonceVerification.Missing
if ( ! is_array( $qersh_e2e_result ) || ! isset( $qersh_e2e_result['result'] ) || 'success' !== $qersh_e2e_result['result'] ) {
	fwrite( STDERR, 'order.php: process_payment() failed: ' . wp_json_encode( $qersh_e2e_result ) . "\n" );
	exit( 1 );
}

global $wpdb;
$qersh_e2e_table = $wpdb->prefix . 'qersh_orders';
if ( (int) $qersh_e2e_opts['shift'] > 0 ) {
	$wpdb->query( $wpdb->prepare( 'UPDATE %i SET created_at = created_at - %d, expires_at = expires_at - %d WHERE order_id = %d', $qersh_e2e_table, (int) $qersh_e2e_opts['shift'], (int) $qersh_e2e_opts['shift'], $qersh_e2e_order->get_id() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a test reads or sets up the plugin's tables directly.
}
if ( (int) $qersh_e2e_opts['backdate'] > 0 ) {
	$wpdb->query( $wpdb->prepare( 'UPDATE %i SET created_at = created_at - %d WHERE order_id = %d', $qersh_e2e_table, (int) $qersh_e2e_opts['backdate'], $qersh_e2e_order->get_id() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a test reads or sets up the plugin's tables directly.
}

echo 'QERSH_JSON=' . wp_json_encode(
	array(
		'id'    => $qersh_e2e_order->get_id(),
		'key'   => $qersh_e2e_order->get_order_key(),
		'total' => $qersh_e2e_order->get_total(),
		'row'   => \QershPay\WooCommerce\Plugin::instance()->orders_index()->get( $qersh_e2e_order->get_id() ),
		'urls'  => array(
			'txn'          => rest_url( 'qersh/v1/order-txn' ),
			'txn_fallback' => add_query_arg(
				array(
					'wc-api' => 'qersh',
					'qo'     => 'txn',
				),
				home_url( '/' )
			),
		),
	),
	JSON_UNESCAPED_SLASHES
) . "\n";
