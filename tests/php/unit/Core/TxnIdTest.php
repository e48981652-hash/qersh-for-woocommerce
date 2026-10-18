<?php
/**
 * Tests of Core\TxnId (docs/17 §4.10): the shared vectors in tests/vectors/txn-normalize.json, which the order
 * page's JavaScript normaliser reads too, and canonical_stored(). The same for every e-wallet: no wallet type goes
 * in, no shape table comes out.
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
	 * The shared vectors.
	 *
	 * @return array
	 */
	private static function cases(): array {
		$cases = json_decode( qersh_wc_test_fixture( 'tests/vectors/txn-normalize.json' ), true );
		return is_array( $cases ) ? $cases : array();
	}

	/**
	 * The shared vectors, by name.
	 *
	 * @return array
	 */
	public function vectors(): array {
		$out = array();
		foreach ( self::cases() as $case ) {
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
		$this->assertSame( $case['expect'], TxnId::canonical_typed( $case['raw'], $case['payer_key'], $case['store_numbers'] ) );
	}

	public function test_the_vectors_carry_no_wallet_type(): void {
		$names = array();
		foreach ( self::cases() as $case ) {
			$this->assertSame( array( 'name', 'raw', 'payer_key', 'store_numbers', 'expect' ), array_keys( $case ), $case['name'] );
			$this->assertIsString( $case['payer_key'], $case['name'] );
			$names[] = $case['name'];
		}
		$this->assertSame( array_unique( $names ), $names, 'one name per vector' );
	}

	public function test_the_vectors_cover_what_docs_17_names(): void {
		$errors = array();
		$ids    = array();
		$names  = '';
		foreach ( self::cases() as $case ) {
			$names .= $case['name'] . "\n";
			if ( $case['expect']['ok'] ) {
				$ids[ $case['expect']['txn'] ] = true;
			} else {
				$errors[ $case['expect']['error'] ] = true;
			}
		}
		ksort( $errors );
		$this->assertSame( array( 'qersh_txn_empty', 'qersh_txn_format', 'qersh_txn_several', 'qersh_txn_too_long', 'qersh_txn_wallet_number' ), array_keys( $errors ) );
		// Ids of every length the wallets print, with letters, and with leading zeros.
		foreach ( array( '25007294067405', '25005172944183', '25004617383051', '25006831596394', '7700031948', '2995831338', '913372004518', '250046173830512', '0012345678', 'A1B2C3D4E5' ) as $id ) {
			$this->assertArrayHasKey( $id, $ids, $id );
		}
		// Messages of three wallets, as paste samples.
		foreach ( array( 'Orange Cash C1', 'Orange Cash C2', 'Orange Cash C3', 'Vodafone Cash style, Arabic', 'Vodafone Cash style, English', 'e& money style, Arabic', 'e& money style, English' ) as $sample ) {
			$this->assertStringContainsString( $sample, $names, $sample );
		}
	}

	public function test_no_wallet_type_and_no_shape_table(): void {
		foreach ( array( 'CLAIM_PROVIDERS', 'PATTERNS', 'LENGTHS', 'CLASSES' ) as $constant ) {
			$this->assertFalse( defined( TxnId::class . '::' . $constant ), $constant );
		}
		$this->assertFalse( method_exists( TxnId::class, 'long_run' ) );
		$this->assertSame( 6, TxnId::MIN_ID );
		$this->assertSame( 40, TxnId::MAX_ID );

		$typed = new \ReflectionMethod( TxnId::class, 'canonical_typed' );
		$this->assertSame(
			array( 'raw', 'payer_key', 'store_numbers' ),
			array_map(
				static function ( \ReflectionParameter $p ): string {
					return $p->getName();
				},
				$typed->getParameters()
			)
		);
		$this->assertSame( 1, $typed->getNumberOfRequiredParameters() );
		$stored = new \ReflectionMethod( TxnId::class, 'canonical_stored' );
		$this->assertSame( 1, $stored->getNumberOfParameters() );
	}

	public function test_canonical_typed_never_reads_a_part_of_a_split_id(): void {
		// An app template that cuts a 14-digit id to its first 10 digits makes 25005172944183 and 25005172945283
		// both 2500517294: however a customer splits an id, it is read whole (docs/17 §4.10),
		// so it never equals such a cut id, and it never equals a part of another one.
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
			'Transaction ID: 2500517294 4183.',
		);
		foreach ( $texts as $text ) {
			$this->assertSame( $whole, TxnId::canonical_typed( $text, '01001234567', array( '01212345678' ) ), $text );
		}
		$this->assertNotSame( TxnId::canonical_stored( '2500517294' ), $whole['txn'] );
	}

	public function test_canonical_typed_joins_only_one_separator_on_one_line(): void {
		$args = array( '01001234567', array( '01212345678' ) );
		// A line break of any kind keeps numbers apart.
		foreach ( array( "\n", "\r\n", "\r", "\u{2028}", "\u{2029}", "\u{000B}", "\u{000C}", "\u{001E}" ) as $break ) {
			$this->assertSame(
				array(
					'ok'    => false,
					'error' => 'qersh_txn_several',
				),
				TxnId::canonical_typed( 'رقم المعاملة 7700031948' . $break . '913372004518', ...$args ),
				bin2hex( $break )
			);
		}
		// Two separators in a row, a word or another character end a number in a message.
		foreach ( array( 'رقم 25007294067405  913372004518', 'رقم 25007294067405 و 913372004518', 'رقم 25007294067405, 913372004518', 'Ref 25007294067405 or 913372004518' ) as $text ) {
			$this->assertSame(
				array(
					'ok'    => false,
					'error' => 'qersh_txn_several',
				),
				TxnId::canonical_typed( $text, ...$args ),
				$text
			);
		}
		// A number joined by separators is left out like a whole one, and so is one standing next to the id.
		$this->assertSame(
			array(
				'ok'    => false,
				'error' => 'qersh_txn_wallet_number',
			),
			TxnId::canonical_typed( 'من 0020 1001234567 فقط', ...$args )
		);
		foreach ( array( 'من 121 234 5678 رقم المعاملة 25007294067405', '01001234567 25007294067405', '25007294067405 01212345678', 'من 01001234567-25007294067405' ) as $text ) {
			$this->assertSame(
				array(
					'ok'  => true,
					'txn' => '25007294067405',
				),
				TxnId::canonical_typed( $text, ...$args ),
				$text
			);
		}
	}

	public function test_canonical_typed_never_reads_dates_times_amounts_or_links(): void {
		$args  = array( '01001234567', array( '01212345678' ) );
		$id    = array(
			'ok'  => true,
			'txn' => '913372004518',
		);
		$noise = array(
			'18-10-26',
			'18/10/2026',
			'2026-10-18',
			'14:35',
			'09:15:42',
			'150.00 جنيه',
			'125000.00',
			'125000,50',
			'125000 جنيه',
			'125000 جنية',
			'125000 ج.م',
			'125000 EGP',
			'125000EGP',
			'125000 LE',
			'https://short.example/Ab12Cd34',
			'www.short.example/x9y8z7w6',
		);
		foreach ( $noise as $text ) {
			$this->assertSame(
				array(
					'ok'    => false,
					'error' => 'qersh_txn_format',
				),
				TxnId::canonical_typed( 'رسالة ' . $text, ...$args ),
				$text
			);
			$this->assertSame( $id, TxnId::canonical_typed( 'رقم العملية 913372004518 ' . $text, ...$args ), $text );
			$this->assertSame( $id, TxnId::canonical_typed( $text . ' رقم العملية 913372004518', ...$args ), $text );
		}
		// A currency word only ends a number as a word: «LE» inside a longer Latin word does not.
		$this->assertSame(
			array(
				'ok'  => true,
				'txn' => '913372004518',
			),
			TxnId::canonical_typed( 'Ref 913372004518 LEFT', ...$args )
		);
	}

	public function test_canonical_typed_handles_odd_input(): void {
		$this->assertSame(
			array(
				'ok'    => false,
				'error' => 'qersh_txn_format',
			),
			TxnId::canonical_typed( array( '25007294067405' ) )
		);
		$this->assertSame(
			array(
				'ok'    => false,
				'error' => 'qersh_txn_format',
			),
			TxnId::canonical_typed( 2.5007294067405E+13 )
		);
		$this->assertSame(
			array(
				'ok'    => false,
				'error' => 'qersh_txn_format',
			),
			TxnId::canonical_typed( true )
		);
		$this->assertSame(
			array(
				'ok'    => false,
				'error' => 'qersh_txn_too_long',
			),
			TxnId::canonical_typed( str_repeat( "\xFF", 401 ) )
		);
		$this->assertSame(
			array(
				'ok'  => true,
				'txn' => '25007294067405',
			),
			TxnId::canonical_typed( "\xFF25007294067405" )
		); // not UTF-8
		// The defaults: no payer key and no store numbers, so a bare 1xxxxxxxxx is an id; 01xxxxxxxxx never is.
		$this->assertSame(
			array(
				'ok'  => true,
				'txn' => '1001234567',
			),
			TxnId::canonical_typed( '1001234567' )
		);
		$this->assertSame(
			array(
				'ok'    => false,
				'error' => 'qersh_txn_wallet_number',
			),
			TxnId::canonical_typed( '01001234567' )
		);
		// Store numbers that are not mobile numbers are ignored, a handle included (the stores are e-wallets).
		$this->assertSame(
			array(
				'ok'  => true,
				'txn' => '25007294067405',
			),
			TxnId::canonical_typed( '25007294067405', '', array( null, 7, '', 'shop@instapay' ) )
		);
		$this->assertSame(
			array(
				'ok'    => false,
				'error' => 'qersh_txn_format',
			),
			TxnId::canonical_typed( 'shop', 'shop@instapay', array( 'shop@instapay' ) )
		);
		// A store number in another spelling is still the store's.
		$this->assertSame(
			array(
				'ok'    => false,
				'error' => 'qersh_txn_wallet_number',
			),
			TxnId::canonical_typed( '1212345678', '', array( '+20 121 234 5678' ) )
		);
	}

	public function test_canonical_stored(): void {
		$this->assertSame( '25007294067405', TxnId::canonical_stored( '25007294067405' ) );
		$this->assertSame( '913372004518', TxnId::canonical_stored( '913372004518' ) );
		$this->assertSame( '250046173830512', TxnId::canonical_stored( '250046173830512' ) );
		$this->assertSame( '2500461738', TxnId::canonical_stored( '2500461738' ) );
		$this->assertSame( '0012345678', TxnId::canonical_stored( '0012345678' ) );
		$this->assertSame( '25007294067405', TxnId::canonical_stored( " 25007294067405\n" ) );
		$this->assertSame( '25007294067405', TxnId::canonical_stored( '2500-7294 0674.05' ) );
		$this->assertSame( '25007294067405', TxnId::canonical_stored( '٢٥٠٠٧٢٩٤٠٦٧٤٠٥' ) );
		$this->assertSame( '25007294067405', TxnId::canonical_stored( "\u{200F}25007294067405" ) );
		$this->assertSame( 'AB12CD34EF', TxnId::canonical_stored( 'ab12cd34ef' ) );
		$this->assertSame( '25007294067405X', TxnId::canonical_stored( '25007294067405X' ) );
		$this->assertSame( '913372', TxnId::canonical_stored( '913372' ) );
		$this->assertSame( str_repeat( '1234567890', 4 ), TxnId::canonical_stored( str_repeat( '1234567890', 4 ) ) );
		$this->assertNull( TxnId::canonical_stored( '91337' ) );
		$this->assertNull( TxnId::canonical_stored( str_repeat( '1234567890', 4 ) . '1' ) );
		$this->assertNull( TxnId::canonical_stored( 'ABCDEFGH' ) );
		$this->assertNull( TxnId::canonical_stored( 'TXN/25007294067405' ) );
		$this->assertNull( TxnId::canonical_stored( '+25007294067405' ) );
		$this->assertNull( TxnId::canonical_stored( '' ) );
		$this->assertNull( TxnId::canonical_stored( ' - ' ) );
		$this->assertNull( TxnId::canonical_stored( null ) );
		$this->assertNull( TxnId::canonical_stored( 25007294067405 ) );
		$this->assertNull( TxnId::canonical_stored( array( '25007294067405' ) ) );
		$this->assertNull( TxnId::canonical_stored( str_repeat( '1', 1601 ) ) );
	}

	public function test_a_typed_id_equals_the_stored_id_of_the_same_transfer(): void {
		// Whatever the wallet: what the phone delivered and what the customer typed meet in one canonical form.
		foreach ( array( '25007294067405', '7700031948', '913372004518', '250046173830512', '0012345678', 'a1b2c3d4e5' ) as $id ) {
			$stored = TxnId::canonical_stored( $id );
			$this->assertNotNull( $stored, $id );
			$spaced = implode( ' ', str_split( $id, 4 ) );
			foreach ( array( $id, ' #' . $id . '. ', $spaced, strtoupper( $id ), 'رقم العملية ' . $id . '.', 'Transaction ID: ' . $spaced ) as $text ) {
				$typed = TxnId::canonical_typed( $text, '01001234567', array( '01212345678' ) );
				$this->assertSame(
					array(
						'ok'  => true,
						'txn' => $stored,
					),
					$typed,
					$text
				);
			}
		}
	}
}
