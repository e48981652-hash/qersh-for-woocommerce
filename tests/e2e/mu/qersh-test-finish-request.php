<?php
/**
 * Plugin Name: Qersh e2e: fastcgi_finish_request() stand-in
 * Description: Test only (one end-to-end job, docs/17 §11). wp-env serves PHP through Apache mod_php, which has no
 *              fastcgi_finish_request(); this defines one that only flushes, so the plugin takes its after-reply
 *              path (completion and the late transaction-id evaluation on shutdown) as it does on PHP-FPM.
 *
 * @package QershPay\WooCommerce\Tests
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'fastcgi_finish_request' ) ) {
	/**
	 * Flushes the output; the request keeps running, as on PHP-FPM.
	 *
	 * @return bool
	 */
	function fastcgi_finish_request() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- the PHP-FPM function this stands in for.
		flush();
		return true;
	}
}
