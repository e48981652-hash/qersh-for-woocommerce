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
	 * Wallet entries: an enabled one, a turned-off one, and optionally a second enabled one.
	 *
	 * @param bool $second Include a second enabled wallet.
	 * @return array
	 */
	private function wallets( bool $second = false ): array {
		$wallets = array(
			array(
				'id'              => 'aaaaaaaaaaa1',
				'label'           => 'Main',
				'receive_address' => '01001234567',
				'enabled'         => true,
			),
			array(
				'id'              => 'bbbbbbbbbbb2',
				'label'           => 'Old line',
				'receive_address' => '01212345678',
				'enabled'         => false,
			),
		);
		if ( $second ) {
			$wallets[] = array(
				'id'              => 'ccccccccccc3',
				'label'           => '',
				'receive_address' => '01112223344',
				'enabled'         => true,
			);
		}
		return $wallets;
	}

	/**
	 * The same entries with the `provider` key an older configuration may still carry (Config drops it; PayerField
	 * must not care either way).
	 *
	 * @param string $provider Any type id, known or not.
	 * @return array
	 */
	private function legacy_wallets( string $provider ): array {
		$wallets = $this->wallets( true );
		foreach ( $wallets as $i => $wallet ) {
			$wallets[ $i ]['provider'] = $provider;
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

	/** Invalid values, addresses and bare names included: the field takes an e-wallet number only. */
	public function test_invalid_numbers(): void {
		$wallets = $this->wallets();
		foreach ( array( '', '   ', '0111223344', '0111***3445', '12345', 'ahmed@instapay', 'Ahmed@InstaPay', 'ahmed', 'shop.eg', 'a@b' ) as $raw ) {
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
		$this->assertSame( 'payer_invalid', PayerField::check( str_repeat( '0', 801 ), '', $wallets )['error'], 'over the length read' );
	}

	/** The type an older wallet entry still carries changes nothing: the same number passes, an address never does. */
	public function test_wallet_type_changes_nothing(): void {
		$plain = $this->wallets( true );
		foreach ( array( 'vodafone_cash', 'etisalat_cash', 'orange_cash', 'instapay_app', 'bank_cib', 'a_wallet_type_not_known_yet', '' ) as $provider ) {
			$legacy = $this->legacy_wallets( $provider );
			foreach ( array( '01112233445', '+20 100 123 4567', 'ahmed@instapay', 'ahmed', '' ) as $raw ) {
				foreach ( array( 'aaaaaaaaaaa1', 'ccccccccccc3', '' ) as $wallet ) {
					$this->assertSame( PayerField::check( $raw, $wallet, $plain ), PayerField::check( $raw, $wallet, $legacy ), "$provider / $raw / $wallet" );
				}
			}
			$this->assertSame( 'payer_invalid', PayerField::check( 'Ahmed@InstaPay', 'ccccccccccc3', $legacy )['error'], $provider );
		}
	}

	/** The store's own numbers are refused, enabled or not, in any spelling. */
	public function test_store_numbers_are_refused(): void {
		$wallets = $this->wallets( true );
		foreach ( array( '01001234567', '+20 100 123 4567', '01212345678', '1212345678', '٠١١١٢٢٢٣٣٤٤', '00201112223344' ) as $raw ) {
			$this->assertSame( 'payer_store', PayerField::check( $raw, 'aaaaaaaaaaa1', $wallets )['error'], $raw );
		}
	}

	/** With several enabled wallets the choice is required and must be an enabled wallet. */
	public function test_wallet_choice(): void {
		$wallets = $this->wallets( true );
		$this->assertSame( 'wallet_choice', PayerField::check( '01112233445', '', $wallets )['error'] );
		$this->assertSame( 'wallet_choice', PayerField::check( '01112233445', 'zzzzzzzzzzzz', $wallets )['error'] );
		$this->assertSame( 'wallet_choice', PayerField::check( '01112233445', 'bbbbbbbbbbb2', $wallets )['error'], 'disabled wallet' );
		$this->assertSame( 'ccccccccccc3', PayerField::check( '01112233445', 'ccccccccccc3', $wallets )['wallet'], 'a wallet without a label' );
		$this->assertSame( 'aaaaaaaaaaa1', PayerField::check( '01112233445', ' aaaaaaaaaaa1 ', $wallets )['wallet'] );
	}

	/** No enabled wallet at all. */
	public function test_no_wallet(): void {
		$this->assertSame( 'no_wallet', PayerField::check( '01112233445', '', array() )['error'] );
		$wallets               = $this->wallets();
		$wallets[0]['enabled'] = false;
		$this->assertSame( 'no_wallet', PayerField::check( '01112233445', '', $wallets )['error'] );
	}

	/** number_of(): the normalised number, or null for anything that is not one (an address, a bare name, a mask). */
	public function test_number_of(): void {
		$this->assertSame( '01112233445', PayerField::number_of( '+20 111 223 3445' ) );
		$this->assertSame( '01112233445', PayerField::number_of( '٠١١١٢٢٣٣٤٤٥' ) );
		foreach ( array( '', 'ahmed@instapay', 'ahmed', '0111***3445', '0111223344', null, array( 'x' ) ) as $raw ) {
			$this->assertNull( PayerField::number_of( $raw ), var_export( $raw, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- the failure message of a test.
		}
	}

	/** Helpers. */
	public function test_helpers(): void {
		$wallets = $this->wallets( true );
		$this->assertSame( array( 'aaaaaaaaaaa1', 'ccccccccccc3' ), array_column( PayerField::enabled_wallets( $wallets ), 'id' ) );
		$this->assertSame( array( '01001234567', '01212345678', '01112223344' ), PayerField::store_numbers( $wallets ) );
		$this->assertTrue( PayerField::is_store_number( '01001234567', $wallets ) );
		$this->assertTrue( PayerField::is_store_number( '01212345678', $wallets ), 'a turned-off wallet is still the store\'s' );
		$this->assertFalse( PayerField::is_store_number( '01001234568', $wallets ) );
		$this->assertFalse( PayerField::is_store_number( 'shop@instapay', $wallets ), 'an address is never a store number' );

		// A stored entry whose address is not a number (an older configuration) is no store number either.
		$odd   = $wallets;
		$odd[] = array(
			'id'              => 'ddddddddddd4',
			'receive_address' => 'shop@instapay',
			'enabled'         => true,
		);
		$this->assertFalse( PayerField::is_store_number( 'shop@instapay', $odd ) );
		$this->assertSame( array( '01001234567', '01212345678', '01112223344', 'shop@instapay' ), PayerField::store_numbers( $odd ), 'listed as configured' );
		$this->assertSame( 'payer_invalid', PayerField::check( 'shop@instapay', 'ddddddddddd4', $odd )['error'], 'refused as no number, not as the store\'s' );
	}

	/** The page source carries the enabled wallets' numbers only, plus the order's own wallet on the order page. */
	public function test_browser_numbers(): void {
		$wallets = $this->wallets( true );
		$this->assertSame( array( '01001234567', '01112223344' ), PayerField::browser_numbers( $wallets ), 'the turned-off line stays on the server' );
		$this->assertSame( array( '01001234567', '01212345678', '01112223344' ), PayerField::browser_numbers( $wallets, 'bbbbbbbbbbb2' ), 'the order page shows its own wallet anyway' );
		$this->assertSame( array( '01001234567', '01112223344' ), PayerField::browser_numbers( $wallets, 'aaaaaaaaaaa1' ) );
		$this->assertSame( array( '01001234567', '01112223344' ), PayerField::browser_numbers( $wallets, 'zzzzzzzzzzz9' ), 'an unknown id adds nothing' );
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
		$this->assertSame(
			array(
				'value' => '01112223334',
				'kept'  => true,
			),
			PayerField::order_prefill( '01112223334', '0100 999 8887', $this->wallets() ),
			'the stored number, not the billing phone'
		);
		$this->assertSame(
			array(
				'value' => '01112223334',
				'kept'  => true,
			),
			PayerField::order_prefill( '+20 111 222 3334', '01009998887', $this->wallets( true ) ),
			'a stored number in another spelling, normalised'
		);
	}

	/** Without a usable stored payer: the billing phone, when it is a number and none of the store's. */
	public function test_order_prefill_falls_back(): void {
		$wallets = $this->wallets();
		$phone   = array(
			'value' => '01009998887',
			'kept'  => false,
		);
		$this->assertSame( $phone, PayerField::order_prefill( '', '0100 999 8887', $wallets ), 'no stored payer' );
		$this->assertSame( $phone, PayerField::order_prefill( 'ahmed@instapay', '01009998887', $wallets ), 'an address an older order holds is not kept' );
		$this->assertSame( $phone, PayerField::order_prefill( 'ahmed@instapay', '01009998887', $this->legacy_wallets( 'instapay_app' ) ), 'whatever type an older wallet entry names' );
		$this->assertSame( $phone, PayerField::order_prefill( '01001234567', '01009998887', $wallets ), 'a stored payer that is now a store number' );
		$this->assertSame( $phone, PayerField::order_prefill( '01212345678', '01009998887', $wallets ), 'the turned-off line is a store number too' );
		$this->assertSame( $phone, PayerField::order_prefill( 'garbage', '01009998887', $wallets ) );
		$this->assertSame(
			array(
				'value' => '',
				'kept'  => false,
			),
			PayerField::order_prefill( '', '01001234567', $wallets ),
			'the billing phone is a store number'
		);
		$this->assertSame( '', PayerField::order_prefill( '', 'ahmed', $wallets )['value'], 'a billing phone that is no number' );
		$this->assertSame( '', PayerField::order_prefill( '', '', $wallets )['value'] );
	}
}
