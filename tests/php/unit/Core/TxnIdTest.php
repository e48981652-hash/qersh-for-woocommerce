<?php
/**
 * Tests of Core\TxnId (docs/17 §4.10): the shared vectors in tests/vectors/txn-normalize.json, which the order
 * page's JavaScript normaliser reads too, the shapes of fixtures/txn-patterns.json, and canonical_stored().
 *
 * @package QershPay\WooCommerce\Tests
 */

declare( strict_types=1 );

namespace QershPay\WooCommerce\Tests\Unit\Core;

use QershPay\WooCommerce\Core\TxnId;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

defined( 'ABSPATH' ) || exit;

/**
 * Transaction ids typed by customers and delivered by the phone.
 */
final class TxnIdTest extends TestCase {

	/**
	 * The shared vectors, by name.
	 *
	 * @return array
	 */
	public function vectors(): array {
		$cases = json_decode( qersh_wc_test_fixture( 'tests/vectors/txn-normalize.json' ), true );
		$out   = array();
		foreach ( is_array( $cases ) ? $cases : array() as $case ) {
			$out[ $case['name'] ] = array( $case );
		}
		return $out;
	}

	/**
	 * Every vector.
	 *
	 * @dataProvider vectors
	 *
	 * @param array $case One vector.
	 */
	public function test_vector( array $case ): void {
		$result = TxnId::canonical_typed( $case['provider'], $case['raw'], $case['payer_key'], $case['store_numbers'] );
		$this->assertSame( $case['expect'], $result );
		$this->assertSame( $case['long_run'], TxnId::long_run( $case['provider'], $case['raw'] ), 'long_run' );
	}

	public function test_the_vectors_cover_long_runs(): void {
		$cases = json_decode( qersh_wc_test_fixture( 'tests/vectors/txn-normalize.json' ), true );
		$found = array();
		foreach ( $cases as $case ) {
			if ( null !== $case['long_run'] ) {
				$found[ strlen( $case['long_run'] ) ][ $case['expect']['ok'] ? 'read' : $case['expect']['error'] ] = true;
			}
		}
		ksort( $found );
		// 15 and 16 digits (the 14-digit shape plus one or two), refused as a format or as a wallet number (an
		// Orange message with the sender's number), and next to an id that is read.
		$this->assertSame( array( 15, 16 ), array_keys( $found ) );
		$this->assertArrayHasKey( 'qersh_txn_format', $found[15] );
		$this->assertArrayHasKey( 'qersh_txn_wallet_number', $found[15] );
		$this->assertArrayHasKey( 'read', $found[15] );
	}

	public function test_long_run_outside_the_vectors(): void {
		$this->assertNull( TxnId::long_run( 'orange_cash', null ) );
		$this->assertNull( TxnId::long_run( 'orange_cash', 250072940674051 ), 'a JSON number is never read' );
		$this->assertNull( TxnId::long_run( 'orange_cash', '' ) );
		$this->assertNull( TxnId::long_run( 'orange_cash', str_repeat( ' ', 386 ) . '250072940674051' ), '401 characters' );
		$this->assertSame( '250072940674051', TxnId::long_run( 'orange_cash', str_repeat( ' ', 385 ) . '250072940674051' ) );
		$this->assertNull( TxnId::long_run( 'orange_cash', '25007294067405' ), 'the shape itself' );
		// The first one counts; a group joined to 15 or 16 digits is read whole, as step 5 reads ids.
		$this->assertSame( '250072940674051', TxnId::long_run( 'orange_cash', '250072940674051 و 2500729406740512' ) );
		$this->assertSame( '2500517294418312', TxnId::long_run( 'orange_cash', 'رقم 2500517294-418312' ) );
		$this->assertSame( '250072940674051', TxnId::long_run( 'orange_cash', "100\n250072940674051" ) );
		// Each run of a group whose joined length is not one of them.
		$this->assertSame( '250072940674051', TxnId::long_run( 'orange_cash', '250072940674051-25007294067405123' ) );
	}

	public function test_the_vectors_cover_what_docs_17_names(): void {
		$cases  = json_decode( qersh_wc_test_fixture( 'tests/vectors/txn-normalize.json' ), true );
		$errors = array();
		$ids    = array();
		foreach ( $cases as $case ) {
			$this->assertSame( array( 'name', 'provider', 'raw', 'payer_key', 'store_numbers', 'expect', 'long_run' ), array_keys( $case ) );
			if ( $case['expect']['ok'] ) {
				$ids[ $case['expect']['txn'] ] = true;
			} else {
				$errors[ $case['expect']['error'] ] = true;
			}
		}
		ksort( $errors );
		$this->assertSame( array( 'qersh_txn_empty', 'qersh_txn_format', 'qersh_txn_several', 'qersh_txn_too_long', 'qersh_txn_wallet_number' ), array_keys( $errors ) );
		foreach ( array( '25007294067405', '25005172944183', '25004617383051', '25006831596394', '7700031948', '2995831338', '0012345678' ) as $id ) {
			$this->assertArrayHasKey( $id, $ids, $id );
		}
	}

	public function test_constants_equal_fixtures_txn_patterns(): void {
		$fixture = json_decode( qersh_wc_test_fixture( 'fixtures/txn-patterns.json' ), true );
		$this->assertSame( array_keys( $fixture['providers'] ), TxnId::CLAIM_PROVIDERS );
		foreach ( $fixture['providers'] as $provider => $spec ) {
			$this->assertSame( $spec['pattern'], TxnId::PATTERNS[ $provider ] );
			$this->assertSame( $spec['lengths'], TxnId::LENGTHS[ $provider ] );
			$this->assertSame( $spec['class'], TxnId::CLASSES[ $provider ] );
		}
		$this->assertSame( array( 'orange_cash' ), TxnId::CLAIM_PROVIDERS );
	}

	public function test_canonical_typed_never_takes_ten_digits_out_of_fourteen(): void {
		// The truncated pack id (first 10 of 14) must never be read out of a 14-digit id (docs/17 §4.10), however the
		// customer split it: the old pack cuts 25005172944183 and 25005172945283 alike to 2500517294.
		$whole = array(
			'ok'  => true,
			'txn' => '25005172944183',
		);
		$texts = array(
			'25005172944183',
			'رقم المعاملة 25005172944183',
			'2500517294 4183',
			'رقم المعاملة 2500517294 4183',
			'رقم المعاملة 2500517294-4183',
			'رقم المعاملة 2500517294.4183',
			'رقم المعاملة #2500517294#4183',
			'رقم المعاملة 2500517294:4183',
			"رقم المعاملة 2500517294\u{00A0}4183",
			"رقم المعاملة 2500517294\t4183",
			"رقم المعاملة 2500517294\u{2013}4183",
			"رقم المعاملة \u{200F}2500517294 4183\u{200F}.",
			'رقم المعاملة ٢٥٠٠٥١٧٢٩٤ ٤١٨٣',
			'رقم المعاملة 2500 5172 9441 83',
			'رقم المعاملة 2500-5172944183',
			'رقم المعاملة 2500517 2944183',
		);
		foreach ( $texts as $text ) {
			$this->assertSame( $whole, TxnId::canonical_typed( 'orange_cash', $text, '01001234567', array( '01212345678' ) ), $text );
		}
	}

	public function test_canonical_typed_joins_only_one_separator_on_one_line(): void {
		$args = array( '01001234567', array( '01212345678' ) );
		// Joined runs whose length is not an id leave each run to be read alone.
		$this->assertSame(
			array(
				'ok'  => true,
				'txn' => '7700031948',
			),
			TxnId::canonical_typed( 'orange_cash', 'رقم المعاملة 7700031948 100', ...$args )
		);
		$this->assertSame(
			array(
				'ok'  => true,
				'txn' => '25007294067405',
			),
			TxnId::canonical_typed( 'orange_cash', 'رقم المعاملة 25007294067405 7770', ...$args )
		);
		$this->assertSame(
			array(
				'ok'  => true,
				'txn' => '25007294067405',
			),
			TxnId::canonical_typed( 'orange_cash', 'بمبلغ 11.00 جنيه رقم المعاملة 25007294067405', ...$args )
		);
		// A line break of any kind keeps numbers apart.
		foreach ( array( "\n", "\r\n", "\r", "\u{2028}", "\u{2029}", "\u{000B}", "\u{000C}", "\u{001E}" ) as $break ) {
			$this->assertSame(
				array(
					'ok'  => true,
					'txn' => '7700031948',
				),
				TxnId::canonical_typed( 'orange_cash', 'رقم المعاملة 7700031948' . $break . '1000 جنيه', ...$args ),
				bin2hex( $break )
			);
		}
		// Two 14-digit ids on one line stay two.
		$this->assertSame(
			array(
				'ok'    => false,
				'error' => 'qersh_txn_several',
			),
			TxnId::canonical_typed( 'orange_cash', 'رقم 25007294067405 25005172944183', ...$args )
		);
		// A joined phone number is left out like a whole one.
		$this->assertSame(
			array(
				'ok'    => false,
				'error' => 'qersh_txn_wallet_number',
			),
			TxnId::canonical_typed( 'orange_cash', 'من 0020 1001234567 فقط', ...$args )
		);
		$this->assertSame(
			array(
				'ok'  => true,
				'txn' => '25007294067405',
			),
			TxnId::canonical_typed( 'orange_cash', 'من 121 234 5678 رقم المعاملة 25007294067405', ...$args )
		);
		// The compact attempt still reads the id alone, with any white space inside.
		$this->assertSame(
			array(
				'ok'  => true,
				'txn' => '25007294067405',
			),
			TxnId::canonical_typed( 'orange_cash', "2500\n7294067405", ...$args )
		);
	}

	public function test_canonical_typed_handles_odd_input(): void {
		$this->assertSame(
			array(
				'ok'    => false,
				'error' => 'qersh_txn_format',
			),
			TxnId::canonical_typed( 'orange_cash', array( '25007294067405' ), null, array() )
		);
		$this->assertSame(
			array(
				'ok'    => false,
				'error' => 'qersh_txn_format',
			),
			TxnId::canonical_typed( 'orange_cash', 2.5007294067405E+13, null, array() )
		);
		$this->assertSame(
			array(
				'ok'    => false,
				'error' => 'qersh_txn_too_long',
			),
			TxnId::canonical_typed( 'orange_cash', str_repeat( "\xFF", 401 ), null, array() )
		);
		$this->assertSame(
			array(
				'ok'  => true,
				'txn' => '25007294067405',
			),
			TxnId::canonical_typed( 'orange_cash', "\xFF25007294067405", null, array() )
		); // not UTF-8
		$this->assertSame(
			array(
				'ok'  => true,
				'txn' => '25007294067405',
			),
			TxnId::canonical_typed( 'orange_cash', '25007294067405', null, array( null, 7, '', 'shop@instapay' ) )
		);
		$this->assertSame(
			array(
				'ok'    => false,
				'error' => 'qersh_txn_wallet_number',
			),
			TxnId::canonical_typed( 'orange_cash', 'shop', null, array( 'shop@instapay' ) )
		);
	}

	public function test_canonical_stored(): void {
		$this->assertSame( '25007294067405', TxnId::canonical_stored( 'orange_cash', '25007294067405' ) );
		$this->assertSame( '25007294067405', TxnId::canonical_stored( 'orange_cash', " 25007294067405\n" ) );
		$this->assertSame( '2500461738', TxnId::canonical_stored( 'orange_cash', '2500461738' ) ); // the pack's cut id still has a shape
		$this->assertSame( '0012345678', TxnId::canonical_stored( 'orange_cash', '0012345678' ) );
		$this->assertNull( TxnId::canonical_stored( 'orange_cash', '2500729406114' ) );
		$this->assertNull( TxnId::canonical_stored( 'orange_cash', '25007294067405X' ) );
		$this->assertNull( TxnId::canonical_stored( 'orange_cash', "25007294067405\n0" ) );
		$this->assertNull( TxnId::canonical_stored( 'orange_cash', '٢٥٠٠٧٢٩٤٠٦٧٤٠٥' ) ); // the phone sends Latin digits
		$this->assertNull( TxnId::canonical_stored( 'orange_cash', '' ) );
		$this->assertNull( TxnId::canonical_stored( 'orange_cash', null ) );
		$this->assertNull( TxnId::canonical_stored( 'orange_cash', 25007294067405 ) );
		$this->assertNull( TxnId::canonical_stored( 'vodafone_cash', '25007294067405' ) );
	}

	public function test_a_typed_id_equals_the_stored_id_of_the_same_transfer(): void {
		foreach ( array( '25007294067405', '7700031948', '0012345678' ) as $id ) {
			$typed = TxnId::canonical_typed( 'orange_cash', ' #' . $id . '. ', '01001234567', array( '01212345678' ) );
			$this->assertSame( TxnId::canonical_stored( 'orange_cash', $id ), $typed['txn'] );
		}
	}
}
