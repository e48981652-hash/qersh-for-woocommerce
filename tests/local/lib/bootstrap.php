<?php
/**
 * Shared start of the local test scripts (tests/local/*.php): finds the test site, fakes the request a web server
 * would make and loads WordPress. Test store only; never part of the plugin package.
 *
 * The site is, in this order: the first command-line argument when it is a WordPress directory (the old calling
 * form `php script.php <site dir> …`, kept so existing notes still work), the QERSH_SITE environment variable, or
 * `local-site` next to this repository. QERSH_BASE (default http://127.0.0.1:8899) gives the host WordPress sees.
 *
 * Before including this file a script may set `$qersh_local_cron = true` (DOING_CRON) or
 * `$qersh_local_installing = true` (WP_INSTALLING). After it, `$qersh_local_args` holds the remaining arguments
 * and `$qersh_local_site` the site directory.
 *
 * @package QershPay\WooCommerce\Tests
 */

$qersh_local_repo = dirname( __DIR__, 3 );
$qersh_local_args = array_slice( $argv, 1 );
$qersh_local_site = '';
if ( isset( $qersh_local_args[0] ) && is_file( rtrim( str_replace( '\\', '/', $qersh_local_args[0] ), '/' ) . '/wp-load.php' ) ) {
	$qersh_local_site = rtrim( str_replace( '\\', '/', array_shift( $qersh_local_args ) ), '/' );
} elseif ( false !== getenv( 'QERSH_SITE' ) && '' !== getenv( 'QERSH_SITE' ) ) {
	$qersh_local_site = rtrim( str_replace( '\\', '/', (string) getenv( 'QERSH_SITE' ) ), '/' );
} else {
	$qersh_local_site = str_replace( '\\', '/', dirname( $qersh_local_repo ) ) . '/local-site';
}
if ( ! is_file( $qersh_local_site . '/wp-load.php' ) ) {
	fwrite( STDERR, "No WordPress at {$qersh_local_site}. Set QERSH_SITE to the test site directory.\n" );
	exit( 2 );
}

$qersh_local_base = false !== getenv( 'QERSH_BASE' ) && '' !== getenv( 'QERSH_BASE' ) ? (string) getenv( 'QERSH_BASE' ) : 'http://127.0.0.1:8899';
$qersh_local_host = (string) preg_replace( '#^https?://#', '', rtrim( $qersh_local_base, '/' ) );
$qersh_local_host = (string) preg_replace( '#/.*$#', '', $qersh_local_host );

$_SERVER['HTTP_HOST']       = $qersh_local_host;
$_SERVER['SERVER_NAME']     = (string) preg_replace( '/:\d+$/', '', $qersh_local_host );
$_SERVER['REQUEST_URI']     = '/';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
if ( 0 === strpos( $qersh_local_base, 'https://' ) ) {
	$_SERVER['HTTPS'] = 'on';
}
if ( ! empty( $qersh_local_cron ) ) {
	define( 'DOING_CRON', true );
}
if ( ! empty( $qersh_local_installing ) ) {
	define( 'WP_INSTALLING', true );
}

/**
 * Where the end-to-end runs keep the test wallets (ids, addresses and test keys): QERSH_WALLETS, or
 * `qersh-wallets.local.json` next to the site directory, outside the web root and outside this repository.
 *
 * @param string $site Site directory.
 * @return string
 */
function qersh_local_wallets_file( $site ) {
	$env = getenv( 'QERSH_WALLETS' );
	if ( false !== $env && '' !== $env ) {
		return (string) $env;
	}
	return str_replace( '\\', '/', dirname( $site ) ) . '/qersh-wallets.local.json';
}

require_once __DIR__ . '/overrides.php';
require $qersh_local_site . '/wp-load.php';
