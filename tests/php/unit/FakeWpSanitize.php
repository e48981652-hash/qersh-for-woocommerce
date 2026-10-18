<?php
/**
 * A global stand-in for WordPress's sanitize_text_field() that records its calls: tags stripped, white space and
 * line breaks collapsed, trimmed (the parts of the real function the tests rely on). Loaded only by tests that run in
 * their own process, so the function never leaks into other tests.
 *
 * @package QershPay\WooCommerce\Tests
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- stand-in for a WordPress core function.

$GLOBALS['qersh_wc_fake_sanitized'] = array();

if ( ! function_exists( 'sanitize_text_field' ) ) {
	/**
	 * Like sanitize_text_field().
	 *
	 * @param string $str Text.
	 * @return string
	 */
	function sanitize_text_field( $str ) {
		$GLOBALS['qersh_wc_fake_sanitized'][] = $str;
		return trim( (string) preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $str ) ) );
	}
}
