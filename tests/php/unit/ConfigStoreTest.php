<?php
/**
 * Tests of ConfigStore::clean(): what every configuration (saved, imported or from QERSH_WC_CONFIG) goes through
 * before Core\Config validates it.
 *
 * @package QershPay\WooCommerce\Tests
 */

declare( strict_types=1 );

namespace QershPay\WooCommerce\Tests\Unit;

use QershPay\WooCommerce\ConfigStore;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

defined( 'ABSPATH' ) || exit;

/**
 * ConfigStore::clean() and ConfigStore::clean_label(), in their own process with a stand-in for WordPress's
 * sanitize_text_field() that records its calls (FakeWpSanitize.php).
 */
final class ConfigStoreTest extends TestCase {

	/**
	 * Every string label is sanitized as the wallet form does and cut to 60 characters; a label that is not a string
	 * stays for Core\Config::validate() to report; the other fields are left to Core\Config.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_clean_sanitizes_labels(): void {
		require_once __DIR__ . '/FakeWpSanitize.php';
		$long   = str_repeat( 'محفظة ', 15 );
		$config = array(
			'receiver' => array(
				'keys'     => array( 'not-a-real-key' ),
				'endpoint' => array( 'path' => '/qersh' ),
			),
			'wallets'  => array(
				array(
					'id'              => 'k7q2m9x4p1rz',
					'label'           => "<b>Shop</b>\n",
					'provider'        => 'vodafone_cash',
					'receive_address' => ' 010-0123-4567 ',
					'enabled'         => true,
				),
				array(
					'id'    => 'a1b2c3d4e5f6',
					'label' => $long,
				),
				array(
					'id'    => 'z9y8x7w6v5u4',
					'label' => 123,
				),
				array( 'id' => 'm1n2b3v4c5x6' ),
				'not a wallet',
			),
			'store'    => array( 'order_ttl_min' => 60 ),
			'extra'    => true,
		);
		$out    = ConfigStore::clean( $config );

		$this->assertSame( array( 'receiver', 'wallets', 'store' ), array_keys( $out ) );
		$this->assertSame( array( 'endpoint' => array( 'path' => '/qersh' ) ), $out['receiver'], 'receiver.keys dropped' );
		$this->assertSame( 'Shop', $out['wallets'][0]['label'] );
		$this->assertSame( ' 010-0123-4567 ', $out['wallets'][0]['receive_address'], 'normalised by Core\Config::with_defaults(), not here' );
		$this->assertSame( trim( str_repeat( 'محفظة ', 10 ) ), $out['wallets'][1]['label'], '60 characters, without the space the cut leaves' );
		$this->assertSame( 123, $out['wallets'][2]['label'], 'left for validate() to refuse' );
		$this->assertSame( array( 'id' => 'm1n2b3v4c5x6' ), $out['wallets'][3] );
		$this->assertSame( 'not a wallet', $out['wallets'][4] );
		$this->assertSame( array( "<b>Shop</b>\n", $long ), $GLOBALS['qersh_wc_fake_sanitized'] );
		$this->assertSame( $config['store'], $out['store'] );

		$this->assertSame( array( 'wallets' => 'none' ), ConfigStore::clean( array( 'wallets' => 'none' ) ), 'not a list: left for validate()' );
	}

	/**
	 * clean_label() keeps a clean label as it is, Arabic included.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_clean_label(): void {
		require_once __DIR__ . '/FakeWpSanitize.php';
		$this->assertSame( 'فودافون كاش المتجر', ConfigStore::clean_label( 'فودافون كاش المتجر' ) );
		$this->assertSame( '', ConfigStore::clean_label( '' ) );
		$this->assertSame( str_repeat( 'a', ConfigStore::LABEL_MAX ), ConfigStore::clean_label( str_repeat( 'a', 61 ) ) );
		$this->assertSame( str_repeat( 'ب', ConfigStore::LABEL_MAX ), ConfigStore::clean_label( str_repeat( 'ب', 70 ) ), 'characters, not bytes' );
	}
}
