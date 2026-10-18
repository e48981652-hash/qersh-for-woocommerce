<?php
/**
 * Tests of ConfigStore::clean(): what every configuration (saved, imported or from QERSH_WC_CONFIG) goes through
 * before Core\Config validates it; and ConfigStore::snapshot(), the entry kept for "Restore wallet".
 *
 * @package QershPay\WooCommerce\Tests
 */

declare( strict_types=1 );

namespace QershPay\WooCommerce\Tests\Unit;

use QershPay\WooCommerce\ConfigStore;
use QershPay\WooCommerce\Core\Config;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

defined( 'ABSPATH' ) || exit;

/**
 * ConfigStore::clean() and ConfigStore::clean_label(), in their own process with a stand-in for WordPress's
 * sanitize_text_field() that records its calls (FakeWpSanitize.php), and the pure ConfigStore::snapshot().
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
	 * A configuration saved while wallets had a type (the stored option, an export, QERSH_WC_CONFIG) goes through
	 * clean() and Core\Config as every configuration does: its `provider` is accepted, dropped, and never refused.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_legacy_provider_is_dropped_not_refused(): void {
		require_once __DIR__ . '/FakeWpSanitize.php';
		$legacy = array(
			'wallets' => array(
				array(
					'id'              => 'k7q2m9x4p1rz',
					'label'           => 'Shop',
					'provider'        => 'orange_cash',
					'receive_address' => '+20 100 123 4567',
					'enabled'         => true,
				),
				array(
					'id'              => 'a1b2c3d4e5f6',
					'label'           => '',
					'provider'        => 'instapay_app',
					'receive_address' => '01112223344',
					'enabled'         => false,
				),
			),
		);
		$out    = ConfigStore::clean( $legacy );
		$this->assertSame( 'orange_cash', $out['wallets'][0]['provider'], 'clean() leaves the fields to Core\Config' );
		$full = Config::with_defaults( $out );
		$this->assertSame( array(), Config::validate( $full ) );
		$this->assertSame(
			array(
				array(
					'id'              => 'k7q2m9x4p1rz',
					'label'           => 'Shop',
					'receive_address' => '01001234567',
					'enabled'         => true,
				),
				array(
					'id'              => 'a1b2c3d4e5f6',
					'label'           => '',
					'receive_address' => '01112223344',
					'enabled'         => false,
				),
			),
			$full['wallets']
		);
	}

	/**
	 * A stored wallet whose address is no e-wallet number (an InstaPay address a test build saved) is left out of
	 * the effective configuration, so the checkout never offers it and the next save, which starts from get(),
	 * removes it; the number wallets stay, and the rest of the configuration then validates.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_split_legacy_leaves_out_wallets_without_a_number(): void {
		require_once __DIR__ . '/FakeWpSanitize.php';
		$stored = array(
			'wallets' => array(
				array(
					'id'              => 'k7q2m9x4p1rz',
					'label'           => 'Shop',
					'provider'        => 'vodafone_cash',
					'receive_address' => '0100 123 4567',
					'enabled'         => true,
				),
				array(
					'id'              => 'a1b2c3d4e5f6',
					'label'           => 'InstaPay',
					'provider'        => 'instapay_app',
					'receive_address' => 'shop@instapay',
					'enabled'         => true,
				),
				array(
					'id'      => 'z9y8x7w6v5u4',
					'label'   => '',
					'enabled' => false,
				),
			),
		);
		$full   = Config::with_defaults( ConfigStore::clean( $stored ) );
		$this->assertNotSame( array(), Config::validate( $full ), 'as stored, the configuration cannot be saved again' );

		list( $kept, $dropped ) = ConfigStore::split_legacy( $full['wallets'] );
		$this->assertSame(
			array(
				array(
					'id'              => 'k7q2m9x4p1rz',
					'label'           => 'Shop',
					'receive_address' => '01001234567',
					'enabled'         => true,
				),
			),
			$kept
		);
		$this->assertSame(
			array(
				array(
					'id'      => 'a1b2c3d4e5f6',
					'label'   => 'InstaPay',
					'address' => 'shop@instapay',
				),
				array(
					'id'      => 'z9y8x7w6v5u4',
					'label'   => '',
					'address' => '',
				),
			),
			$dropped
		);
		$full['wallets'] = $kept;
		$this->assertSame( array(), Config::validate( $full ), 'without them the settings and wallet screens save again' );

		$this->assertSame( array( array(), array() ), ConfigStore::split_legacy( array() ) );
		$this->assertSame( array( array( 'not a wallet' ), array() ), ConfigStore::split_legacy( array( 'not a wallet' ) ), 'left for validate()' );
	}

	/**
	 * The snapshot of a wallet that leaves the configuration keeps its wallet fields as they were stored, and no
	 * legacy `provider` or other field.
	 */
	public function test_snapshot_keeps_the_wallet_fields_only(): void {
		$this->assertSame(
			array(
				'id'              => 'k7q2m9x4p1rz',
				'label'           => 'Shop',
				'receive_address' => '01001234567',
				'enabled'         => false,
			),
			ConfigStore::snapshot(
				array(
					'id'              => 'k7q2m9x4p1rz',
					'label'           => 'Shop',
					'provider'        => 'vodafone_cash',
					'receive_address' => '01001234567',
					'enabled'         => false,
					'secret'          => 'never kept',
				)
			)
		);
		$this->assertSame( array( 'id' => 'a1b2c3d4e5f6' ), ConfigStore::snapshot( array( 'id' => 'a1b2c3d4e5f6' ) ) );
		$this->assertSame(
			array(
				'id'              => 'a1b2c3d4e5f6',
				'receive_address' => 'shop@instapay',
			),
			ConfigStore::snapshot(
				array(
					'provider'        => 'instapay_app',
					'id'              => 'a1b2c3d4e5f6',
					'receive_address' => 'shop@instapay',
				)
			),
			'values stay as stored; "Restore wallet" validates them'
		);
		$this->assertSame( array(), ConfigStore::snapshot( array( 'provider' => 'orange_cash' ) ) );
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
