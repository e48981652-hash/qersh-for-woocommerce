<?php
/**
 * Global stand-ins for the WordPress option and transient functions and dbDelta() that Store\Schema uses, backed by
 * arrays in $GLOBALS (the same option array as FakeWpSecrets.php). Loaded only by tests that run in their own
 * process, so the functions never leak into other tests.
 *
 * @package QershPay\WooCommerce\Tests
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- stand-ins for WordPress core functions.

$GLOBALS['qersh_wc_fake_options']    = array();
$GLOBALS['qersh_wc_fake_transients'] = array();
$GLOBALS['qersh_wc_fake_dbdelta']    = array();
$GLOBALS['qersh_wc_fake_refuse']     = array( 'delete_option' => false );

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * Like get_option().
	 *
	 * @param string $name    Option.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	function get_option( $name, $default = false ) {
		return array_key_exists( $name, $GLOBALS['qersh_wc_fake_options'] ) ? $GLOBALS['qersh_wc_fake_options'][ $name ] : $default;
	}

	/**
	 * Like update_option().
	 *
	 * @param string    $name     Option.
	 * @param mixed     $value    Value.
	 * @param bool|null $autoload Autoload.
	 * @return bool
	 */
	function update_option( $name, $value, $autoload = null ) {
		unset( $autoload );
		$GLOBALS['qersh_wc_fake_options'][ $name ] = $value;
		return true;
	}

	/**
	 * Like delete_option(): false when the row is not there (or, with the refuse switch, when another request
	 * deleted it a moment earlier).
	 *
	 * @param string $name Option.
	 * @return bool
	 */
	function delete_option( $name ) {
		if ( ! empty( $GLOBALS['qersh_wc_fake_refuse']['delete_option'] ) || ! array_key_exists( $name, $GLOBALS['qersh_wc_fake_options'] ) ) {
			return false;
		}
		unset( $GLOBALS['qersh_wc_fake_options'][ $name ] );
		return true;
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	/**
	 * Like get_transient() (no expiry).
	 *
	 * @param string $name Transient.
	 * @return mixed
	 */
	function get_transient( $name ) {
		return array_key_exists( $name, $GLOBALS['qersh_wc_fake_transients'] ) ? $GLOBALS['qersh_wc_fake_transients'][ $name ] : false;
	}

	/**
	 * Like set_transient().
	 *
	 * @param string $name       Transient.
	 * @param mixed  $value      Value.
	 * @param int    $expiration Seconds.
	 * @return bool
	 */
	function set_transient( $name, $value, $expiration = 0 ) {
		unset( $expiration );
		$GLOBALS['qersh_wc_fake_transients'][ $name ] = $value;
		return true;
	}

	/**
	 * Like delete_transient().
	 *
	 * @param string $name Transient.
	 * @return bool
	 */
	function delete_transient( $name ) {
		unset( $GLOBALS['qersh_wc_fake_transients'][ $name ] );
		return true;
	}
}

if ( ! function_exists( 'dbDelta' ) ) {
	/**
	 * Like dbDelta(): records the statements.
	 *
	 * @param string[] $queries Statements.
	 * @return array
	 */
	function dbDelta( $queries ) {
		$GLOBALS['qersh_wc_fake_dbdelta'][] = $queries;
		return array();
	}
}
