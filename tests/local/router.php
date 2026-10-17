<?php
/**
 * Router for PHP's built-in web server, for the local test store:
 *
 *   php -S 127.0.0.1:8899 -t <site dir> tests/local/router.php
 *
 * Serves existing files as they are and sends everything else to WordPress, like the rewrite rules of a real
 * server. One request at a time: keep DISABLE_WP_CRON on in that site and run cron with run-cron.php.
 *
 * A copy of the store on another port (QERSH_DB_PATH and QERSH_HOME_OVERRIDE, see lib/bootstrap.php):
 *
 *   QERSH_DB_PATH=/tmp/copy.sqlite QERSH_BASE=http://127.0.0.1:8898 QERSH_HOME_OVERRIDE=1 \
 *     php -S 127.0.0.1:8898 -t <site dir> tests/local/router.php
 *
 * @package QershPay\WooCommerce\Tests
 */

require_once __DIR__ . '/lib/overrides.php';

$qersh_root = $_SERVER['DOCUMENT_ROOT'];
$qersh_path = rawurldecode( (string) parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- WordPress is not loaded yet (no wp_parse_url()).
$qersh_file = realpath( $qersh_root . $qersh_path );
if ( false !== $qersh_file && 0 === strpos( $qersh_file, realpath( $qersh_root ) ) && is_file( $qersh_file ) && ! preg_match( '/\.php$/i', $qersh_file ) ) {
	return false;
}
// A plugin asset reached through a symbolic link that leaves the web root (this repository linked as
// wp-content/plugins/qersh-for-woocommerce): the built-in server would not serve it, so it is sent from here. Only
// assets/ folders, never a dot-segment, so the link does not expose the rest of the repository.
if ( false !== $qersh_file && 1 === preg_match( '#^/wp-content/plugins/[^/.][^/]*/assets/#', $qersh_path ) && false === strpos( $qersh_path, '/.' ) && is_file( $qersh_file ) && ! preg_match( '/\.php$/i', $qersh_file ) ) {
	$qersh_types = array(
		'js'    => 'application/javascript; charset=utf-8',
		'css'   => 'text/css; charset=utf-8',
		'json'  => 'application/json; charset=utf-8',
		'svg'   => 'image/svg+xml',
		'png'   => 'image/png',
		'jpg'   => 'image/jpeg',
		'gif'   => 'image/gif',
		'webp'  => 'image/webp',
		'woff2' => 'font/woff2',
		'txt'   => 'text/plain; charset=utf-8',
	);
	$qersh_ext   = strtolower( pathinfo( $qersh_file, PATHINFO_EXTENSION ) );
	header( 'Content-Type: ' . ( isset( $qersh_types[ $qersh_ext ] ) ? $qersh_types[ $qersh_ext ] : 'application/octet-stream' ) );
	header( 'Content-Length: ' . filesize( $qersh_file ) );
	readfile( $qersh_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- the built-in server's static file, before WordPress loads.
	return true;
}
if ( false !== $qersh_file && is_file( $qersh_file ) && preg_match( '/\.php$/i', $qersh_file ) ) {
	$_SERVER['SCRIPT_NAME']     = $qersh_path;
	$_SERVER['SCRIPT_FILENAME'] = $qersh_file;
	chdir( dirname( $qersh_file ) );
	require $qersh_file;
	return true;
}
$_SERVER['SCRIPT_NAME']     = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $qersh_root . '/index.php';
chdir( $qersh_root );
require $qersh_root . '/index.php';
return true;
