<?php
/**
 * A global stand-in for esc_html(), which a few pure helpers call. Loaded only by tests that run in their own
 * process, so the function never leaks into other tests.
 *
 * @package QershPay\WooCommerce\Tests
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- a stand-in for a WordPress core function.

if ( ! function_exists( 'esc_html' ) ) {
	/**
	 * Like esc_html() for valid UTF-8: & < > " and ' encoded, existing entities kept.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8', false );
	}
}
