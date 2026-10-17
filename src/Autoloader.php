<?php
/**
 * Class autoloader: QershPay\WooCommerce\Foo\Bar lives in src/Foo/Bar.php.
 *
 * @package QershPay\WooCommerce
 */

namespace QershPay\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Loads the plugin's own classes. The plugin has no runtime dependencies, so nothing else is loaded here.
 */
final class Autoloader {

	const PREFIX = 'QershPay\\WooCommerce\\';

	/**
	 * Registers the loader once.
	 */
	public static function register(): void {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;
		spl_autoload_register( array( __CLASS__, 'load' ) );
	}

	/**
	 * Loads one class of this plugin, if the file exists.
	 *
	 * @param string $class_name Fully qualified class name.
	 */
	public static function load( $class_name ): void {
		if ( 0 !== strpos( $class_name, self::PREFIX ) ) {
			return;
		}
		$relative = substr( $class_name, strlen( self::PREFIX ) );
		if ( ! preg_match( '/^[A-Za-z0-9_\\\\]+$/', $relative ) ) {
			return;
		}
		$file = __DIR__ . '/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
}
