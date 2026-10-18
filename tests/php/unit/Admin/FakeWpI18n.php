<?php
/**
 * A global stand-in for __(), which View::wallet_name() calls for its fallback name: the English source as it is.
 * Loaded only by tests that run in their own process, so the function never leaks into other tests.
 *
 * @package QershPay\WooCommerce\Tests
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- a stand-in for a WordPress core function.

if ( ! function_exists( '__' ) ) {
	/**
	 * Like __() without a translation loaded.
	 *
	 * @param string $text   Text.
	 * @param string $domain Text domain.
	 * @return string
	 */
	function __( $text, $domain = 'default' ) {
		unset( $domain );
		return (string) $text;
	}
}
