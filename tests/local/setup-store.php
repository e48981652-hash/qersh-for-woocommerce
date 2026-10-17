<?php
/**
 * CLI: php tests/local/setup-store.php [hpos: on|off] [permalinks: pretty|plain]
 *
 * Prepares the local test store for Qersh for WooCommerce: EGP, Cairo time, coming-soon off, stock management on,
 * HPOS on or off, and four simple products named "Qersh test 100", "Qersh test 139.50", "Qersh test 140" and
 * "Qersh test 1500" with stock (e2e.mjs finds them by name). Run it once after installing the site.
 *
 * @package QershPay\WooCommerce\Tests
 */

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- a command-line script: its report goes to a terminal, not a page.

require __DIR__ . '/lib/bootstrap.php';

use Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer;
use Automattic\WooCommerce\Internal\Features\FeaturesController;
use Automattic\WooCommerce\Utilities\OrderUtil;

$qersh_hpos       = isset( $qersh_local_args[0] ) ? $qersh_local_args[0] : 'on';
$qersh_permalinks = isset( $qersh_local_args[1] ) ? $qersh_local_args[1] : 'pretty';

update_option( 'woocommerce_currency', 'EGP' );
update_option( 'woocommerce_default_country', 'EG:C' );
update_option( 'timezone_string', 'Africa/Cairo' );
update_option( 'woocommerce_coming_soon', 'no' );
update_option( 'woocommerce_store_pages_only', 'no' );
update_option( 'woocommerce_manage_stock', 'yes' );
// WooCommerce's stock reservation query does not run on SQLite, so a SQLite store holds no stock (CI on MySQL does).
update_option( 'woocommerce_hold_stock_minutes', defined( 'DB_ENGINE' ) && 'sqlite' === DB_ENGINE ? '' : '60' );
update_option( 'woocommerce_price_num_decimals', '2' );
update_option( 'woocommerce_onboarding_profile', array( 'skipped' => true ) );

global $wp_rewrite;
$wp_rewrite->set_permalink_structure( 'plain' === $qersh_permalinks ? '' : '/%postname%/' );
flush_rewrite_rules( false );

$qersh_want_hpos = 'off' !== $qersh_hpos;
$qersh_sync      = wc_get_container()->get( DataSynchronizer::class );
if ( $qersh_want_hpos && ! $qersh_sync->check_orders_table_exists() ) {
	$qersh_sync->create_database_tables();
}
wc_get_container()->get( FeaturesController::class )->change_feature_enable( 'custom_order_tables', $qersh_want_hpos );
update_option( 'woocommerce_custom_orders_table_enabled', $qersh_want_hpos ? 'yes' : 'no' );

$qersh_prices = array(
	'Qersh test 100'    => '100',
	'Qersh test 139.50' => '139.50',
	'Qersh test 140'    => '140',
	'Qersh test 1500'   => '1500',
);
foreach ( $qersh_prices as $qersh_name => $qersh_price ) {
	$qersh_found = wc_get_products(
		array(
			'name'   => $qersh_name,
			'limit'  => 1,
			'return' => 'ids',
		)
	);
	if ( ! empty( $qersh_found ) ) {
		continue;
	}
	$qersh_p = new WC_Product_Simple();
	$qersh_p->set_name( $qersh_name );
	$qersh_p->set_regular_price( $qersh_price );
	$qersh_p->set_manage_stock( true );
	$qersh_p->set_stock_quantity( 50 );
	$qersh_p->set_status( 'publish' );
	$qersh_p->save();
}
echo 'currency=' . get_woocommerce_currency() . ' tz=' . get_option( 'timezone_string' ) . ' hpos=' . ( OrderUtil::custom_orders_table_usage_is_enabled() ? 'on' : 'off' ) . "\n";
echo 'products=' . implode(
	', ',
	array_map(
		function ( $p ) {
			return $p->get_id() . ':' . $p->get_name() . ':' . $p->get_price();
		},
		wc_get_products( array( 'limit' => 20 ) )
	)
) . "\n";
echo 'rest_url=' . rest_url( 'qersh/v1/webhook' ) . "\n";
