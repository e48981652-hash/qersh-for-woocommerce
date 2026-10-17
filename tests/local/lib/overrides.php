<?php
/**
 * Lets the local scripts and the router work on a copy of the test store, before WordPress loads:
 *
 * - QERSH_DB_PATH: a SQLite database file to use instead of the site's own (the SQLite Database Integration
 *   plugin reads DB_PATH), so a run can never change the real test store.
 * - QERSH_HOME_OVERRIDE=1: WP_HOME and WP_SITEURL become QERSH_BASE (the copy answers on its own port). The site's
 *   wp-config.php then defines them a second time, which PHP reports as a warning in debug.log and ignores.
 *
 * @package QershPay\WooCommerce\Tests
 */

$qersh_local_db_path = getenv( 'QERSH_DB_PATH' );
if ( false !== $qersh_local_db_path && '' !== $qersh_local_db_path && ! defined( 'DB_PATH' ) ) {
	define( 'DB_PATH', str_replace( '\\', '/', (string) $qersh_local_db_path ) );
}
$qersh_local_home = getenv( 'QERSH_BASE' );
if ( '1' === getenv( 'QERSH_HOME_OVERRIDE' ) && false !== $qersh_local_home && '' !== $qersh_local_home && ! defined( 'WP_HOME' ) ) {
	// The second define() in wp-config.php must not reach a JSON answer. WP_DEBUG_DISPLAY cannot stop it: the warning
	// comes from wp-config.php itself, before wp_debug_mode() reads that constant.
	ini_set( 'display_errors', '0' ); // phpcs:ignore WordPress.PHP.IniSet.display_errors_Disallowed -- see above; test store only.
	define( 'WP_HOME', rtrim( (string) $qersh_local_home, '/' ) );
	define( 'WP_SITEURL', rtrim( (string) $qersh_local_home, '/' ) );
}
unset( $qersh_local_db_path, $qersh_local_home );
