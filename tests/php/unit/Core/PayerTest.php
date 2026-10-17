<?php
/**
 * Tests of Core\Payer (given): the cases of the core's tests (normalizePayer, payerKeyOf) and the spellings around them.
 *
 * @package QershPay\WooCommerce\Tests
 */

declare( strict_types=1 );

namespace QershPay\WooCommerce\Tests\Unit\Core;

use QershPay\WooCommerce\Core\Payer;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

defined( 'ABSPATH' ) || exit;

/**
 * Payer::normalize() and Payer::key_of().
 */
final class PayerTest extends TestCase {

	/** The kit spellings (packages/qersh-webhook/test/summarize.test.ts, normalizePayer). */
	public function test_the_kit_spellings() {
		$this->assertSame( '01012345678', Payer::normalize( '+20 101 234 5678' ) );
		$this->assertSame( '01012345678', Payer::normalize( '00201012345678' ) );
		$this->assertSame( '01012345678', Payer::normalize( '201012345678' ) );
		$this->assertSame( '01012345678', Payer::normalize( '1012345678' ) );
		$this->assertSame( '01012345678', Payer::normalize( '٠١٠١٢٣٤٥٦٧٨' ) );
		$this->assertSame( 'shop@instapay', Payer::normalize( 'Shop@InstaPay' ) );
		$this->assertSame( 'shop@instapay', Payer::normalize( 'shop' ) );
		$this->assertNull( Payer::normalize( '0111***3445' ) );
		$this->assertNull( Payer::normalize( '' ) );
		$this->assertNull( Payer::normalize( null ) );
	}

	/** More number spellings: Persian digits, +20 01…, 0020 01…, separators, white space JavaScript's trim() removes. */
	public function test_number_spellings() {
		$cases = array(
			'۰۱۰۱۲۳۴۵۶۷۸',
			'+٢٠ ١٠١ ٢٣٤ ٥٦٧٨',
			'+20 01012345678',
			'0020 01012345678',
			'2001012345678',
			'010-1234-5678',
			'(010) 1234 5678',
			'  01012345678  ',
			"\t01012345678\n",
			"\u{00A0}01012345678\u{FEFF}",
			"\u{2000}01012345678\u{3000}",
			'01012345678',
			'01012345678x', // the digits are read before the handle rule
			'x01012345678',
		);
		foreach ( $cases as $raw ) {
			$this->assertSame( '01012345678', Payer::normalize( $raw ), $raw );
		}
		$this->assertSame( '01112233445', Payer::normalize( '011 1223 3445' ) );
	}

	/** Not a number and not a handle: null. */
	public function test_refused() {
		$cases = array(
			'0101234567', // 10 digits not starting with 1
			'010123456789', // 12 digits
			'٠١٠١٢٣٤٥٦٧٨٩',
			'20101234567', // 11 digits starting with 20, not stripped
			'12345', // digits only, no letter: not a handle
			'a', // too short for a handle
			'a@instapay',
			'ab@gmail.com',
			'shop name',
			'𝟎𝟏𝟎𝟏𝟐𝟑𝟒𝟓𝟔𝟕𝟖', // mathematical digits are not digits for the core
			str_repeat( 'x', 65 ),
			str_repeat( 'x', 65 ) . '@instapay',
		);
		foreach ( $cases as $raw ) {
			$this->assertNull( Payer::normalize( $raw ), $raw );
		}
	}

	/** Handles: lower case, the suffix added to a bare name, 2 to 64 characters of a-z 0-9 . _ -. */
	public function test_handles() {
		$this->assertSame( 'ab@instapay', Payer::normalize( 'AB@INSTAPAY' ) );
		$this->assertSame( 'ab@instapay', Payer::normalize( 'ab' ) );
		$this->assertSame( 'shop.name_1-x@instapay', Payer::normalize( 'shop.name_1-x' ) );
		$this->assertSame( '1a@instapay', Payer::normalize( '1a' ) );
		$this->assertSame( str_repeat( 'x', 64 ) . '@instapay', Payer::normalize( str_repeat( 'x', 64 ) ) );
		$this->assertSame( str_repeat( 'x', 64 ) . '@instapay', Payer::normalize( str_repeat( 'x', 64 ) . '@instapay' ) );
		$this->assertNull( Payer::normalize( '01012345678@instapay@instapay' ) );
		$this->assertSame( '01012345678@instapay', Payer::normalize( '01012345678@instapay' ) );
	}

	/** Numbers and booleans are read as JavaScript's String() reads them. */
	public function test_other_types() {
		$this->assertSame( '01012345678', Payer::normalize( 1012345678 ) );
		$this->assertSame( '01012345678', Payer::normalize( 201012345678 ) );
		$this->assertSame( 'true@instapay', Payer::normalize( true ) );
		$this->assertSame( 'false@instapay', Payer::normalize( false ) );
		$this->assertNull( Payer::normalize( 0 ) );
		$this->assertNull( Payer::normalize( 1.5 ) );
	}

	/**
	 * Arrays and objects as String() reads them: an array is its elements joined with commas (null inside is ''), an
	 * object is `[object Object]`. So the core normalises a list holding a number, and payerKeyOf takes an
	 * array-valued number before the handle.
	 */
	public function test_arrays_and_objects() {
		$this->assertNull( Payer::normalize( array() ) ); // ''
		$this->assertNull( Payer::normalize( new \stdClass() ) ); // [object Object]
		$this->assertNull( Payer::normalize( array( 'number' => '01012345678' ) ) ); // a JSON object: [object Object]
		$this->assertNull( Payer::normalize( array( 1 => '01012345678' ) ) ); // not a list: [object Object]
		$this->assertNull( Payer::normalize( array( 'ab', 'cd' ) ) ); // ab,cd
		$this->assertNull( Payer::normalize( array( 'shop', 'x' ) ) ); // shop,x is no handle
		$lists = array(
			array( '01012345678' ),
			array( '0101', '2345678' ), // 0101,2345678: the digits are read across the comma
			array( 1012345678 ),
			array( array( array( '01012345678' ) ) ),
			array( null, '01012345678', null ), // ,01012345678,
			array( new \stdClass(), '01012345678' ), // [object Object],01012345678
			array( '+20 101', array( '234', '5678' ) ), // +20 101,234,5678
		);
		foreach ( $lists as $list ) {
			$this->assertSame( '01012345678', Payer::normalize( $list ) );
			$this->assertSame( '01012345678', Payer::key_of( array( 'number' => $list ) ) );
			$this->assertSame( '01012345678', Payer::key_of( array( 'handle' => $list ) ) );
			$this->assertSame( '01012345678', Payer::key_of( array( 'number' => $list, 'handle' => 'shop' ) ) );
		}
		$this->assertSame( 'shop@instapay', Payer::normalize( array( 'Shop' ) ) );
		$this->assertSame( 'true@instapay', Payer::normalize( array( true ) ) );
		$this->assertSame( 'shop@instapay', Payer::key_of( array( 'number' => array( 'number' => '01012345678' ), 'handle' => 'shop' ) ) );
	}

	/**
	 * White space is what String.prototype.trim() removes, no more: U+0085 (NEL), U+180E (Mongolian vowel separator)
	 * and NUL stay, so the value is no identifier; VT and FF go.
	 */
	public function test_trim_is_javascript_trim() {
		$this->assertNull( Payer::normalize( "shop\u{85}" ) );
		$this->assertNull( Payer::normalize( "\u{180E}shop" ) );
		$this->assertNull( Payer::normalize( "bonah@INSTAPAY\u{85}" ) );
		$this->assertNull( Payer::normalize( "shop\0" ) );
		$this->assertNull( Payer::normalize( "\0shop@instapay" ) );
		$this->assertSame( 'shop@instapay', Payer::normalize( "\x0B\fShop\f\x0B" ) );
		$this->assertSame( 'shop@instapay', Payer::normalize( "\u{1680}\u{2028}\u{2029}\u{202F}\u{205F}shop\u{200A}\u{3000}\u{FEFF}" ) );
		$this->assertSame( '01012345678', Payer::normalize( "\u{85}01012345678\u{180E}" ) ); // the digits are still read
		$this->assertSame(
			'01112233445',
			Payer::key_of(
				array(
					'number' => "\u{180E}shop",
					'handle' => '+20 111 223 3445',
				)
			)
		);
		$this->assertSame( 'shop@instapay', Payer::normalize( 'shop' . str_repeat( ' ', 100000 ) ) );
		$this->assertNull( Payer::normalize( 'a' . str_repeat( ' ', 100000 ) . 'b' ) );
	}

	/** Bytes that are not UTF-8 are read as the core receives them: U+FFFD for each invalid sequence. */
	public function test_invalid_utf8() {
		$this->assertSame( '01012345678', Payer::normalize( "\xFF01012345678" ) );
		$this->assertSame( '01012345678', Payer::normalize( "0101234\xC0\x805678\xE2\x80" ) );
		$this->assertSame( '01012345678', Payer::normalize( "\xE0\xD9\xA0\xD9\xA1\xD9\xA0\xD9\xA1\xD9\xA2\xD9\xA3\xD9\xA4\xD9\xA5\xD9\xA6\xD9\xA7\xD9\xA8" ) ); // E0 then ٠١٠١٢٣٤٥٦٧٨
		$this->assertSame( '01012345678', Payer::normalize( " \t01012345678\xED\xA0\x80 " ) );
		$this->assertNull( Payer::normalize( "shop\xFF" ) ); // U+FFFD is no handle character
		$this->assertNull( Payer::normalize( "\xFFshop@instapay" ) );
		$this->assertNull( Payer::normalize( "\xC2" ) );
		$this->assertNull( Payer::normalize( "\x00\xFF\x0C" ) );
	}

	/** The payer key of a transfer (payerKeyOf): the number when it normalises, otherwise the handle. */
	public function test_key_of() {
		$this->assertSame(
			'shop@instapay',
			Payer::key_of(
				array(
					'number' => '0111***3445',
					'name'   => 'Customer',
					'handle' => 'shop@instapay',
				)
			)
		);
		$this->assertSame(
			'01012345678',
			Payer::key_of(
				array(
					'number' => '01012345678',
					'handle' => 'x@instapay',
				)
			)
		);
		$this->assertSame(
			'01112233445',
			Payer::key_of(
				array(
					'number' => '01112233445',
					'handle' => null,
					'name'   => 'Assaad K Mansouri',
				)
			)
		);
		$this->assertSame( 'shop@instapay', Payer::key_of( array( 'handle' => 'Shop' ) ) );
		$this->assertNull(
			Payer::key_of(
				array(
					'number' => '0111***3445',
					'name'   => 'Customer',
					'handle' => null,
				)
			)
		);
		$this->assertNull( Payer::key_of( array() ) );
		$this->assertNull(
			Payer::key_of(
				array(
					'number' => null,
					'handle' => null,
				)
			)
		);
		$this->assertNull( Payer::key_of( null ) );
		$this->assertNull( Payer::key_of( '01012345678' ) );
	}
}
