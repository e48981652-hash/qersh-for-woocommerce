<?php
/**
 * Declares the class name of the SQLite drop-in's database object, so Store\Db::is_sqlite() sees the drop-in.
 * Loaded only by a test that runs in its own process.
 *
 * @package QershPay\WooCommerce\Tests
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_SQLite_DB', false ) ) {
	/**
	 * Stand-in for the drop-in's wpdb subclass.
	 */
	class WP_SQLite_DB {} // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound, Squiz.Classes.ClassFileName.NoMatch -- test stand-in.
}
