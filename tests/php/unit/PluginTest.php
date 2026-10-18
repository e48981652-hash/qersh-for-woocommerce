<?php
/**
 * Tests of Plugin: the bundled translations for a network-activated plugin, and tables_ready().
 *
 * @package QershPay\WooCommerce\Tests
 */

declare( strict_types=1 );

namespace QershPay\WooCommerce\Tests\Unit;

use QershPay\WooCommerce\Plugin;
use QershPay\WooCommerce\Store\Schema;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin::lang_dir() (the lang_dir_for_domain filter) and Plugin::tables_ready(), each in its own process (they need
 * the plugin's constants).
 */
final class PluginTest extends TestCase {

	/**
	 * The languages/ folder answers for our text domain only when WordPress found no folder and it has a file for
	 * the locale; every other answer passes through, so a language pack, or the Domain Path registered for a plugin
	 * active on the site, still wins.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_lang_dir(): void {
		define( 'QERSH_WC_DIR', QERSH_WC_TESTS_ROOT . '/' );
		$bundled = QERSH_WC_TESTS_ROOT . '/languages/';
		$this->assertFileExists( $bundled . 'qersh-for-woocommerce-ar.mo' );

		$this->assertSame( $bundled, Plugin::lang_dir( false, 'qersh-for-woocommerce', 'ar' ), 'network-activated, no language pack' );
		$this->assertSame( $bundled, Plugin::lang_dir( false, 'qersh-for-woocommerce', 'ar' ), 'the same answer from the cache' );

		$pack = '/var/www/wp-content/languages/plugins/';
		$this->assertSame( $pack, Plugin::lang_dir( $pack, 'qersh-for-woocommerce', 'ar' ), 'a language pack wins' );
		$this->assertSame( $bundled, Plugin::lang_dir( $bundled, 'qersh-for-woocommerce', 'fr_FR' ), 'the registered Domain Path passes through' );

		$this->assertFalse( Plugin::lang_dir( false, 'qersh-for-woocommerce', 'fr_FR' ), 'no file for the locale' );
		$this->assertFalse( Plugin::lang_dir( false, 'woocommerce', 'ar' ), 'another text domain' );
		$this->assertFalse( Plugin::lang_dir( false, 'qersh-for-woocommerce', '../ar' ), 'not a locale name' );
		$this->assertFalse( Plugin::lang_dir( false, 'qersh-for-woocommerce', '' ) );
		$this->assertNull( Plugin::lang_dir( null, 'qersh-for-woocommerce', 'ar' ), 'anything but false passes through' );
	}

	/**
	 * Each script and style is versioned by the plugin version and its file's time, so a cached copy from another
	 * build of the same version is never mixed with this one; a missing file gets the plugin version alone. Every
	 * enqueue of our own files goes through it.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_asset_version(): void {
		define( 'QERSH_WC_DIR', QERSH_WC_TESTS_ROOT . '/' );
		define( 'QERSH_WC_VERSION', '0.1.0' );
		$files = array( 'assets/js/txn-normalize.js', 'assets/js/payer-field.js', 'assets/js/thankyou.js', 'assets/js/checkout-blocks.js', 'assets/js/admin.js', 'assets/css/front.css', 'assets/css/admin.css' );
		foreach ( $files as $file ) {
			$this->assertSame( '0.1.0.' . filemtime( QERSH_WC_TESTS_ROOT . '/' . $file ), Plugin::asset_version( $file ), $file );
		}
		$this->assertSame( Plugin::asset_version( 'assets/js/thankyou.js' ), Plugin::asset_version( '/assets/js/thankyou.js' ) );
		$this->assertSame( '0.1.0', Plugin::asset_version( 'assets/js/missing.js' ) );
		$this->assertSame( '0.1.0', Plugin::asset_version( 'assets/js' ), 'a folder is no file' );

		$sources = '';
		foreach ( array( 'src/Checkout.php', 'src/BlocksIntegration.php', 'src/Admin/Module.php' ) as $source ) {
			$sources .= (string) file_get_contents( QERSH_WC_TESTS_ROOT . '/' . $source );
		}
		$this->assertSame( 0, preg_match( '/wp_(?:register|enqueue)_(?:script|style)\([^;]*QERSH_WC_VERSION/', $sources ), 'no asset of ours is versioned by QERSH_WC_VERSION alone' );
		foreach ( $files as $file ) {
			$this->assertStringContainsString( "Plugin::asset_version( '" . $file . "' )", $sources, $file );
		}
	}

	/**
	 * tables_ready() follows the schema version option, and turns false for the rest of the request once a query
	 * found one of the tables missing.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_tables_ready(): void {
		require_once __DIR__ . '/Store/FakeWpOptions.php';
		define( 'QERSH_WC_DB_VERSION', 1 );
		$plugin = Plugin::instance();
		$this->assertFalse( $plugin->tables_ready(), 'never installed' );
		$GLOBALS['qersh_wc_fake_options'][ Schema::VERSION_OPTION ] = '1';
		$this->assertTrue( $plugin->tables_ready() );
		Schema::table_missing();
		$this->assertFalse( $plugin->tables_ready(), 'a table went missing during this request' );
	}
}
