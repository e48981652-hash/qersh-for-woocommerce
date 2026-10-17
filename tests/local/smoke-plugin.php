<?php
/**
 * CLI: php tests/local/smoke-plugin.php
 *
 * Activates Qersh for WooCommerce on the local test site and reports what a reviewer would look at first:
 * activation errors, tables, REST routes, the method, Blocks, emails, scheduled hooks, and new PHP notices of the
 * plugin in wp-content/debug.log (WP_DEBUG is on there). Works on SQLite and MySQL.
 *
 * @package QershPay\WooCommerce\Tests
 */

$qersh_log_path = '';
$qersh_log_size = 0;
// The log is measured before WordPress loads, so the notices of this very load are counted too.
foreach ( array_slice( $argv, 1 ) as $qersh_arg ) {
	if ( is_file( rtrim( str_replace( '\\', '/', $qersh_arg ), '/' ) . '/wp-load.php' ) ) {
		$qersh_log_path = rtrim( str_replace( '\\', '/', $qersh_arg ), '/' ) . '/wp-content/debug.log';
	}
}
if ( '' === $qersh_log_path ) {
	$qersh_env_site = getenv( 'QERSH_SITE' );
	$qersh_log_path = ( false !== $qersh_env_site && '' !== $qersh_env_site ? rtrim( str_replace( '\\', '/', (string) $qersh_env_site ), '/' ) : str_replace( '\\', '/', dirname( __DIR__, 3 ) ) . '/local-site' ) . '/wp-content/debug.log';
}
$qersh_log_size = is_file( $qersh_log_path ) ? filesize( $qersh_log_path ) : 0;

require __DIR__ . '/lib/bootstrap.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

/**
 * Prints one line of the report.
 *
 * @param string $label Label.
 * @param mixed  $value Value.
 */
function qersh_smoke_line( $label, $value ) {
	echo str_pad( $label, 34 ) . ( is_string( $value ) ? $value : var_export( $value, true ) ) . "\n"; // phpcs:ignore WordPress.PHP.DevelopmentFunctions
}

$qersh_slug = 'qersh-for-woocommerce/qersh-for-woocommerce.php';
if ( ! is_plugin_active( $qersh_slug ) ) {
	$qersh_r = activate_plugin( $qersh_slug );
	qersh_smoke_line( 'activate', is_wp_error( $qersh_r ) ? 'ERROR ' . $qersh_r->get_error_message() : 'ok' );
} else {
	qersh_smoke_line( 'activate', 'already active' );
}
qersh_smoke_line( 'QERSH_WC_VERSION', defined( 'QERSH_WC_VERSION' ) ? QERSH_WC_VERSION : 'missing' );
qersh_smoke_line( 'db version option', get_option( 'qersh_wc_db_version' ) );

global $wpdb;
$qersh_sqlite = defined( 'DB_ENGINE' ) && 'sqlite' === DB_ENGINE;
foreach ( array( 'deliveries', 'transfers', 'orders', 'wallets', 'locks' ) as $qersh_t ) {
	$qersh_name = $wpdb->prefix . 'qersh_' . $qersh_t;
	if ( $qersh_sqlite ) {
		$qersh_ok = $wpdb->get_var( $wpdb->prepare( 'SELECT name FROM sqlite_master WHERE type = %s AND name = %s', 'table', $qersh_name ) );
	} else {
		$qersh_ok = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $qersh_name ) ) );
	}
	qersh_smoke_line( 'table ' . $qersh_t, $qersh_ok ? 'ok' : 'MISSING' );
}

do_action( 'rest_api_init', rest_get_server() );
$qersh_routes = array_keys( rest_get_server()->get_routes() );
$qersh_ours   = array_values(
	array_filter(
		$qersh_routes,
		function ( $r ) {
			return 0 === strpos( $r, '/qersh/v1' );
		}
	)
);
qersh_smoke_line( 'REST routes', $qersh_ours ? implode( ' ', $qersh_ours ) : 'NONE' );

if ( function_exists( 'WC' ) ) {
	$qersh_gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
	qersh_smoke_line( 'gateway qersh', isset( $qersh_gateways['qersh'] ) ? get_class( $qersh_gateways['qersh'] ) : 'MISSING' );
	$qersh_emails = array_keys( WC()->mailer()->get_emails() );
	$qersh_emails = array_filter(
		$qersh_emails,
		function ( $e ) {
			return false !== stripos( $e, 'qersh' );
		}
	);
	qersh_smoke_line( 'emails', $qersh_emails ? implode( ' ', $qersh_emails ) : 'none' );
	$qersh_fc       = wc_get_container()->get( \Automattic\WooCommerce\Internal\Features\FeaturesController::class );
	$qersh_compat   = $qersh_fc->get_compatible_plugins_for_feature( 'custom_order_tables', true );
	$qersh_compat_b = $qersh_fc->get_compatible_plugins_for_feature( 'cart_checkout_blocks', true );
	qersh_smoke_line( 'HPOS compatible list has us', in_array( $qersh_slug, isset( $qersh_compat['compatible'] ) ? $qersh_compat['compatible'] : array(), true ) );
	qersh_smoke_line( 'Blocks compatible list has us', in_array( $qersh_slug, isset( $qersh_compat_b['compatible'] ) ? $qersh_compat_b['compatible'] : array(), true ) );
}
foreach ( array( 'qersh_wc_complete', 'qersh_wc_expire', 'qersh_wc_claim_eval', 'qersh_wc_sweep', 'qersh_wc_daily' ) as $qersh_hook ) {
	qersh_smoke_line( 'hook ' . $qersh_hook, has_action( $qersh_hook ) ? 'handled' : 'no handler' );
}
qersh_smoke_line( 'defer filter registered', (bool) has_filter( 'woocommerce_defer_transactional_emails' ) );
qersh_smoke_line( 'parse_request fallback', (bool) has_action( 'parse_request' ) );

clearstatcache();
$qersh_new      = is_file( $qersh_log_path ) ? (string) file_get_contents( $qersh_log_path, false, null, $qersh_log_size ) : '';
$qersh_ours_log = array_filter(
	explode( "\n", $qersh_new ),
	function ( $l ) {
		return false !== stripos( $l, 'qersh' );
	}
);
qersh_smoke_line( 'new debug.log lines (ours)', (string) count( $qersh_ours_log ) );
foreach ( array_slice( $qersh_ours_log, 0, 20 ) as $qersh_l ) {
	echo '   ' . substr( $qersh_l, 0, 400 ) . "\n";
}
