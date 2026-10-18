<?php
/**
 * End-to-end store setup, run once per job before Playwright (tests/e2e/global-setup.mjs):
 *
 *   wp eval-file wp-content/plugins/qersh-for-woocommerce/tests/e2e/setup/store.php checkout=blocks|classic
 *   php tests/e2e/setup/store.php checkout=blocks   (a local test site, through tests/local/lib/bootstrap.php)
 *
 * EGP, Cairo time, guest checkout, stock management, pretty permalinks, the checkout page as Checkout Blocks or the
 * classic shortcode, and four virtual products with stock (100, 139.50, 140 and 1,500 EGP; virtual, so no shipping
 * method is needed). HPOS and the site language are set by the workflow with WP-CLI, as docs/17 §11 asks. Prints
 * one line `QERSH_JSON={…}` with the product ids and the page addresses.
 *
 * @package QershPay\WooCommerce\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	require dirname( __DIR__, 2 ) . '/local/lib/bootstrap.php';
	/** @var string[] $qersh_local_args Set by tests/local/lib/bootstrap.php. */
	$args = $qersh_local_args; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the name wp eval-file gives the arguments.
}
defined( 'ABSPATH' ) || exit;

$qersh_e2e_opts = array( 'checkout' => 'blocks' );
foreach ( isset( $args ) && is_array( $args ) ? $args : array() as $qersh_e2e_arg ) {
	$qersh_e2e_pair = explode( '=', (string) $qersh_e2e_arg, 2 );
	if ( 2 === count( $qersh_e2e_pair ) ) {
		$qersh_e2e_opts[ $qersh_e2e_pair[0] ] = $qersh_e2e_pair[1];
	}
}

update_option( 'woocommerce_currency', 'EGP' );
update_option( 'woocommerce_default_country', 'EG:C' );
update_option( 'woocommerce_allowed_countries', 'all' );
update_option( 'timezone_string', 'Africa/Cairo' );
update_option( 'woocommerce_coming_soon', 'no' );
update_option( 'woocommerce_store_pages_only', 'no' );
update_option( 'woocommerce_manage_stock', 'yes' );
update_option( 'woocommerce_hold_stock_minutes', defined( 'DB_ENGINE' ) && 'sqlite' === DB_ENGINE ? '' : '60' );
update_option( 'woocommerce_price_num_decimals', '2' );
update_option( 'woocommerce_enable_guest_checkout', 'yes' );
update_option( 'woocommerce_enable_checkout_login_reminder', 'no' );
update_option( 'woocommerce_enable_signup_and_login_from_checkout', 'no' );
update_option( 'woocommerce_onboarding_profile', array( 'skipped' => true ) );
update_option( 'woocommerce_task_list_hidden', 'yes' );

global $wp_rewrite;
$wp_rewrite->set_permalink_structure( '/%postname%/' );
// set_permalink_structure() re-initialises WP_Rewrite when the structure changes, which drops every endpoint
// registered on init (WooCommerce's order-received, order-pay and My Account pages). A flush here would save the
// rules without them, and the order-received page would answer 404. Dropping the stored rules lets the next
// request rebuild them with every endpoint.
delete_option( 'rewrite_rules' );

/**
 * WooCommerce's own block content of a page (what its installer writes: the blocks with all their inner blocks).
 *
 * @param string $which `checkout` or `cart`.
 * @return string
 */
function qersh_e2e_block_content( $which ) {
	$method = new ReflectionMethod( 'WC_Install', 'get_' . $which . '_block_content' );
	$method->setAccessible( true );
	return (string) $method->invoke( null );
}

// The checkout and cart pages: Checkout Blocks (WooCommerce's default content), or the classic shortcodes.
$qersh_e2e_classic = 'classic' === $qersh_e2e_opts['checkout'];
$qersh_e2e_pages   = array(
	'checkout' => array( wc_get_page_id( 'checkout' ), $qersh_e2e_classic ? '<!-- wp:shortcode -->[woocommerce_checkout]<!-- /wp:shortcode -->' : qersh_e2e_block_content( 'checkout' ) ),
	'cart'     => array( wc_get_page_id( 'cart' ), $qersh_e2e_classic ? '<!-- wp:shortcode -->[woocommerce_cart]<!-- /wp:shortcode -->' : qersh_e2e_block_content( 'cart' ) ),
);
foreach ( $qersh_e2e_pages as $qersh_e2e_name => $qersh_e2e_page ) {
	if ( $qersh_e2e_page[0] > 0 ) {
		wp_update_post(
			array(
				'ID'           => $qersh_e2e_page[0],
				'post_content' => $qersh_e2e_page[1],
				'post_status'  => 'publish',
			)
		);
	}
}

$qersh_e2e_products = array();
foreach ( array( '100', '139.50', '140', '1500' ) as $qersh_e2e_price ) {
	$qersh_e2e_name    = 'Qersh e2e ' . $qersh_e2e_price;
	$qersh_e2e_found   = wc_get_products(
		array(
			'name'   => $qersh_e2e_name,
			'limit'  => 1,
			'return' => 'ids',
		)
	);
	$qersh_e2e_product = ! empty( $qersh_e2e_found ) ? wc_get_product( $qersh_e2e_found[0] ) : new WC_Product_Simple();
	$qersh_e2e_product->set_name( $qersh_e2e_name );
	$qersh_e2e_product->set_regular_price( $qersh_e2e_price );
	$qersh_e2e_product->set_virtual( true );
	$qersh_e2e_product->set_manage_stock( true );
	$qersh_e2e_product->set_stock_quantity( 500 );
	$qersh_e2e_product->set_status( 'publish' );
	$qersh_e2e_product->save();
	$qersh_e2e_products[ (string) (int) round( (float) $qersh_e2e_price * 100 ) ] = $qersh_e2e_product->get_id();
}

echo 'QERSH_JSON=' . wp_json_encode(
	array(
		'checkout' => $qersh_e2e_classic ? 'classic' : 'blocks',
		'products' => $qersh_e2e_products,
		'pages'    => array(
			'checkout' => wc_get_checkout_url(),
			'cart'     => wc_get_cart_url(),
		),
		'locale'   => get_locale(),
		'hpos'     => \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled(),
		'versions' => array(
			'wp'  => get_bloginfo( 'version' ),
			'wc'  => defined( 'WC_VERSION' ) ? WC_VERSION : '',
			'php' => PHP_VERSION,
		),
	),
	JSON_UNESCAPED_SLASHES
) . "\n";
