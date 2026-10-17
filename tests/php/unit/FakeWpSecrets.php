<?php
/**
 * Global stand-ins for the WordPress option functions and the WordPress 7.2 secrets API that Secrets uses, backed by
 * arrays in $GLOBALS, with switches that make the secrets store refuse writes or deletes. Loaded only by tests that
 * run in their own process, so the functions never leak into other tests.
 *
 * @package QershPay\WooCommerce\Tests
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- stand-ins for WordPress core functions.

$GLOBALS['qersh_wc_fake_options'] = array();
$GLOBALS['qersh_wc_fake_secrets'] = array();
$GLOBALS['qersh_wc_fake_refuse']  = array(
	'set'    => false,
	'delete' => false,
);

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
	 * Like delete_option().
	 *
	 * @param string $name Option.
	 * @return bool
	 */
	function delete_option( $name ) {
		if ( ! array_key_exists( $name, $GLOBALS['qersh_wc_fake_options'] ) ) {
			return false;
		}
		unset( $GLOBALS['qersh_wc_fake_options'][ $name ] );
		return true;
	}

	/**
	 * Like wp_set_secret(): false while writes are refused.
	 *
	 * @param string $name  Name.
	 * @param string $value Value.
	 * @return bool
	 */
	function wp_set_secret( $name, $value ) {
		if ( $GLOBALS['qersh_wc_fake_refuse']['set'] ) {
			return false;
		}
		$GLOBALS['qersh_wc_fake_secrets'][ $name ] = (string) $value;
		return true;
	}

	/**
	 * Like wp_get_secret().
	 *
	 * @param string $name Name.
	 * @return string|null
	 */
	function wp_get_secret( $name ) {
		return isset( $GLOBALS['qersh_wc_fake_secrets'][ $name ] ) ? $GLOBALS['qersh_wc_fake_secrets'][ $name ] : null;
	}

	/**
	 * Like wp_delete_secret(): throws while deletes are refused.
	 *
	 * @param string $name Name.
	 * @return bool
	 */
	function wp_delete_secret( $name ) {
		if ( $GLOBALS['qersh_wc_fake_refuse']['delete'] ) {
			throw new RuntimeException( 'the secrets store refused the delete' );
		}
		unset( $GLOBALS['qersh_wc_fake_secrets'][ $name ] );
		return true;
	}
}
