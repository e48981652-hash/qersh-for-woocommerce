<?php
/**
 * Tests of PayerField::check() and its helpers (docs/17 §4.1, §6.1). WordPress-free.
 *
 * @package QershPay\WooCommerce\Tests
 */

declare( strict_types=1 );

namespace QershPay\WooCommerce\Tests\Unit;

use QershPay\WooCommerce\PayerField;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

defined( 'ABSPATH' ) || exit;

/**
 * The payer field and wallet choice checks.
 */
final class PayerFieldTest extends TestCase {

	/**
	 * Wallet entries.
	 *
	 * @param bool $instapay Include an enabled InstaPay wallet.
	 * @return array
	 */
	private function wallets( bool $instapay = false ): array {
		$wallets = array(
			array(
				'id'              => 'aaaaaaaaaaa1',
				'label'           => 'Vodafone',
				'provider'        => 'vodafone_cash',
				'receive_address' => '01001234567',
				'enabled'         => true,
			),
			array(
				'id'              => 'bbbbbbbbbbb2',
				'label'           => 'Old line',
				'provider'        => 'orange_cash',
				'receive_address' => '01212345678',
				'enabled'         => false,
			),
		);
		if ( $instapay ) {
			$wallets[] = array(
				'id'              => 'ccccccccccc3',
				'label'           => 'InstaPay',
				'provider'        => 'instapay_app',
				'receive_address' => 'shop@instapay',
				'enabled'         => true,
			);
		}
		return $wallets;
	}

	/** One enabled wallet: no choice needed; numbers in any spelling normalise. */
	public function test_single_wallet_accepts_any_spelling(): void {
		$wallets = $this->wallets();
		foreach ( array( '01112233445', '+20 111 223 3445', '00201112233445', '1112233445', '٠١١١٢٢٣٣٤٤٥', '0111-223-3445' ) as $raw ) {
			$this->assertSame(
				array(
					'ok'     => true,
					'payer'  => '01112233445',
					'wallet' => 'aaaaaaaaaaa1',
				),
				PayerField::check( $raw, '', $wallets ),
				$raw
			);
		}
	}

	/** Invalid values. */
	public function test_invalid_numbers(): void {
		$wallets = $this->wallets();
		foreach ( array( '', '   ', '0111223344', '0111***3445', '12345', 'ahmed@instapay', 'ahmed' ) as $raw ) {
			$this->assertSame(
				array(
					'ok'    => false,
					'error' => 'payer_invalid',
				),
				PayerField::check( $raw, '', $wallets ),
				$raw
			);
		}
		$this->assertSame( 'payer_invalid', PayerField::check( null, '', $wallets )['error'] );
		$this->assertSame( 'payer_invalid', PayerField::check( array( '01112233445' ), '', $wallets )['error'] );
	}

	/** The store's own numbers are refused, enabled or not, in any spelling. */
	public function test_store_numbers_are_refused(): void {
		$wallets = $this->wallets( true );
		foreach ( array( '01001234567', '+20 100 123 4567', '01212345678', '1212345678', 'shop@instapay', 'SHOP@InstaPay', 'shop' ) as $raw ) {
			$this->assertSame( 'payer_store', PayerField::check( $raw, 'aaaaaaaaaaa1', $wallets )['error'], $raw );
		}
	}

	/** InstaPay addresses only while an InstaPay wallet is enabled. */
	public function test_instapay_addresses(): void {
		$this->assertSame( 'ahmed@instapay', PayerField::check( 'Ahmed@InstaPay', 'ccccccccccc3', $this->wallets( true ) )['payer'] );
		$this->assertSame( 'ahmed@instapay', PayerField::check( 'ahmed', 'aaaaaaaaaaa1', $this->wallets( true ) )['payer'] );
		$this->assertSame( '01112233445', PayerField::check( '01112233445', 'ccccccccccc3', $this->wallets( true ) )['payer'] );
	}

	/** With several enabled wallets the choice is required and must be an enabled wallet. */
	public function test_wallet_choice(): void {
		$wallets = $this->wallets( true );
		$this->assertSame( 'wallet_choice', PayerField::check( '01112233445', '', $wallets )['error'] );
		$this->assertSame( 'wallet_choice', PayerField::check( '01112233445', 'zzzzzzzzzzzz', $wallets )['error'] );
		$this->assertSame( 'wallet_choice', PayerField::check( '01112233445', 'bbbbbbbbbbb2', $wallets )['error'], 'disabled wallet' );
		$this->assertSame( 'ccccccccccc3', PayerField::check( '01112233445', 'ccccccccccc3', $wallets )['wallet'] );
	}

	/** No enabled wallet at all. */
	public function test_no_wallet(): void {
		$this->assertSame( 'no_wallet', PayerField::check( '01112233445', '', array() )['error'] );
		$wallets               = $this->wallets();
		$wallets[0]['enabled'] = false;
		$this->assertSame( 'no_wallet', PayerField::check( '01112233445', '', $wallets )['error'] );
	}

	/** Helpers. */
	public function test_helpers(): void {
		$wallets = $this->wallets( true );
		$this->assertSame( array( 'aaaaaaaaaaa1', 'ccccccccccc3' ), array_column( PayerField::enabled_wallets( $wallets ), 'id' ) );
		$this->assertTrue( PayerField::has_instapay( $wallets ) );
		$this->assertFalse( PayerField::has_instapay( $this->wallets() ) );
		$this->assertSame( array( '01001234567', '01212345678', 'shop@instapay' ), PayerField::store_numbers( $wallets ) );
		$this->assertTrue( PayerField::is_store_number( '01001234567', $wallets ) );
		$this->assertFalse( PayerField::is_store_number( '01001234568', $wallets ) );
		$this->assertFalse( PayerField::is_store_number( 'other@instapay', $wallets ) );
	}

	/** The page source carries the enabled wallets' numbers only, plus the order's own wallet on the order page. */
	public function test_browser_numbers(): void {
		$wallets = $this->wallets( true );
		$this->assertSame( array( '01001234567', 'shop@instapay' ), PayerField::browser_numbers( $wallets ), 'the turned-off line stays on the server' );
		$this->assertSame( array( '01001234567', '01212345678', 'shop@instapay' ), PayerField::browser_numbers( $wallets, 'bbbbbbbbbbb2' ), 'the order page shows its own wallet anyway' );
		$this->assertSame( array( '01001234567', 'shop@instapay' ), PayerField::browser_numbers( $wallets, 'aaaaaaaaaaa1' ) );
		$this->assertSame( array( '01001234567', 'shop@instapay' ), PayerField::browser_numbers( $wallets, 'zzzzzzzzzzz9' ), 'an unknown id adds nothing' );
		$this->assertSame( array(), PayerField::browser_numbers( array() ) );
		$this->assertSame(
			array(),
			PayerField::browser_numbers(
				array(
					'x',
					array(
						'receive_address' => '01001234567',
						'enabled'         => true,
					),
				)
			),
			'entries without an id'
		);
		$this->assertSame( 'payer_store', PayerField::check( '01212345678', '', $this->wallets() )['error'], 'the server still refuses the turned-off number' );
	}

	/** A missing wallet is the wallet choice's error; every other refusal is the payer field's. */
	public function test_notice_id(): void {
		$this->assertSame( PayerField::WALLETS, PayerField::notice_id( 'wallet_choice' ) );
		foreach ( array( 'payer_invalid', 'payer_store', 'no_wallet', '' ) as $code ) {
			$this->assertSame( PayerField::PAYER, PayerField::notice_id( $code ), $code );
		}
	}

	/** "Pay for order" fills the payer the order already has, else its billing phone (docs/17 §6.4). */
	public function test_order_prefill_keeps_the_stored_payer(): void {
		$plain = $this->wallets();
		$insta = $this->wallets( true );

		$this->assertSame(
			array(
				'value' => '01112223334',
				'kind'  => 'number',
				'kept'  => true,
			),
			PayerField::order_prefill( '01112223334', '0100 999 8887', $plain, false ),
			'the stored number, not the billing phone'
		);
		$this->assertSame(
			array(
				'value' => '01112223334',
				'kind'  => 'number',
				'kept'  => true,
			),
			PayerField::order_prefill( '01112223334', '01009998887', $insta, true ),
			'a stored number stays a number on an InstaPay wallet'
		);
		$this->assertSame(
			array(
				'value' => 'ahmed@instapay',
				'kind'  => 'instapay',
				'kept'  => true,
			),
			PayerField::order_prefill( 'ahmed@instapay', '01009998887', $insta, false ),
			'a stored address is shown as an address'
		);
	}

	/** Without a usable stored payer: the billing phone, or an empty address field on an InstaPay wallet. */
	public function test_order_prefill_falls_back(): void {
		$plain = $this->wallets();
		$insta = $this->wallets( true );
		$phone = array(
			'value' => '01009998887',
			'kind'  => 'number',
			'kept'  => false,
		);
		$this->assertSame( $phone, PayerField::order_prefill( '', '0100 999 8887', $plain, false ), 'no stored payer' );
		$this->assertSame( $phone, PayerField::order_prefill( 'ahmed@instapay', '01009998887', $plain, false ), 'an address without an InstaPay wallet' );
		$this->assertSame( $phone, PayerField::order_prefill( '01001234567', '01009998887', $plain, false ), 'a stored payer that is now a store number' );
		$this->assertSame( $phone, PayerField::order_prefill( '01212345678', '01009998887', $plain, false ), 'the turned-off line is a store number too' );
		$this->assertSame( $phone, PayerField::order_prefill( 'garbage', '01009998887', $plain, false ) );
		$this->assertSame(
			array(
				'value' => '',
				'kind'  => 'instapay',
				'kept'  => false,
			),
			PayerField::order_prefill( '', '01009998887', $insta, true ),
			'an InstaPay wallet starts the address empty'
		);
		$this->assertSame(
			array(
				'value' => '',
				'kind'  => 'number',
				'kept'  => false,
			),
			PayerField::order_prefill( '', '01001234567', $plain, false ),
			'the billing phone is a store number'
		);
		$this->assertSame( '', PayerField::order_prefill( '', '', $plain, false )['value'] );
	}
}
