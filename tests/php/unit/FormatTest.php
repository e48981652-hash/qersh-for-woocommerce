<?php
/**
 * Tests of Format (docs/17 §7): the isolation of each context and the display forms. WordPress-free parts only.
 *
 * @package QershPay\WooCommerce\Tests
 */

declare( strict_types=1 );

namespace QershPay\WooCommerce\Tests\Unit;

use QershPay\WooCommerce\Format;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

defined( 'ABSPATH' ) || exit;

/**
 * Format::isolate(), ltr(), number(), masked() and amount_raw().
 */
final class FormatTest extends TestCase {

	/** HTML templates use <bdi dir="ltr">. */
	public function test_html_context_uses_bdi(): void {
		$this->assertSame( '<bdi dir="ltr">0100</bdi>', Format::isolate( '0100', Format::HTML ) );
		$this->assertSame( '<bdi dir="ltr" data-qersh-time="5">x</bdi>', Format::isolate( 'x', Format::HTML, ' data-qersh-time="5"' ) );
	}

	/** Order notes and HTML email use a span that wp_kses_post() keeps. */
	public function test_note_and_email_contexts_use_an_isolating_span(): void {
		foreach ( array( Format::NOTE, Format::EMAIL_HTML ) as $context ) {
			$this->assertSame( '<span dir="ltr" style="unicode-bidi:isolate">0100</span>', Format::isolate( '0100', $context ), $context );
		}
	}

	/** Plain text sits between U+2066 and U+2069. */
	public function test_plain_context_uses_isolate_marks(): void {
		$this->assertSame( "\u{2066}0111\u{2069}", Format::isolate( '0111', Format::PLAIN ) );
		$this->assertSame( "\u{2066}a<b\u{2069}", Format::ltr( 'a<b', Format::PLAIN ) );
	}

	/** ltr() escapes the value for the HTML contexts. */
	public function test_ltr_escapes_html(): void {
		$this->assertSame( '<bdi dir="ltr">a&lt;b&quot;</bdi>', Format::ltr( 'a<b"', Format::HTML ) );
		$this->assertSame( '<span dir="ltr" style="unicode-bidi:isolate">42</span>', Format::ltr( 42, Format::NOTE ) );
	}

	/** A localized time with Arabic words takes its direction from its first strong letter, in every context. */
	public function test_values_with_arabic_letters_get_first_strong_isolation(): void {
		$time = '18 أكتوبر 2026 4:35 م';
		$this->assertSame( '<bdi>' . $time . '</bdi>', Format::ltr( $time, Format::HTML ) );
		$this->assertSame( '<bdi data-qersh-time="5">4:35 م</bdi>', Format::isolate( '4:35 م', Format::HTML, ' data-qersh-time="5"' ) );
		foreach ( array( Format::NOTE, Format::EMAIL_HTML ) as $context ) {
			$this->assertSame( '<span dir="auto" style="unicode-bidi:isolate">' . $time . '</span>', Format::ltr( $time, $context ), $context );
		}
		$this->assertSame( "\u{2068}" . $time . "\u{2069}", Format::ltr( $time, Format::PLAIN ) );
		$this->assertSame( "\u{2068}4:35 م بتوقيت القاهرة\u{2069}", Format::isolate( '4:35 م بتوقيت القاهرة', Format::PLAIN ) );

		// The same time in English (a site without the core Arabic pack) stays left to right.
		$this->assertSame( '<bdi dir="ltr">October 18, 2026 12:38 am</bdi>', Format::ltr( 'October 18, 2026 12:38 am', Format::HTML ) );
		$this->assertSame( "\u{2066}October 18, 2026 12:38 am\u{2069}", Format::ltr( 'October 18, 2026 12:38 am', Format::PLAIN ) );
	}

	/** Numbers of any script, masked numbers, addresses and transaction ids stay left to right. */
	public function test_numbers_and_ids_stay_left_to_right(): void {
		$values = array( '0100 123 4567', "0100\u{00A0}123\u{00A0}4567", '٠١٠٠١٢٣٤٥٦٧', '۰۱۰۰۱۲۳۴۵۶۷', "0111\u{2022}\u{2022}\u{2022}445", 'name@instapay', '25007294067405', '+0.50 EGP', '139٫50' );
		foreach ( $values as $value ) {
			$this->assertSame( '<bdi dir="ltr">' . $value . '</bdi>', Format::ltr( $value, Format::HTML ), $value );
			$this->assertSame( '<span dir="ltr" style="unicode-bidi:isolate">' . $value . '</span>', Format::ltr( $value, Format::NOTE ), $value );
			$this->assertSame( "\u{2066}" . $value . "\u{2069}", Format::ltr( $value, Format::PLAIN ), $value );
		}
	}

	/** has_rtl() reads letters, not digits or symbols, and decodes entities first (wc_price() HTML, escaped text). */
	public function test_has_rtl(): void {
		foreach ( array( 'م', '4:35 ص', 'October 18 بتوقيت القاهرة', 'שלום', "\u{FE8D}", "\u{FB50}", '&#x62c;.&#x645;', '&#1580;', 'ج.م' ) as $text ) {
			$this->assertTrue( Format::has_rtl( $text ), $text );
		}
		foreach ( array( '', 'a&lt;b', '0100', '٠١٢', '۰۱۲', "\u{0600}", "\u{066B}", "\u{060C}", "\u{064B}", "\u{FEFF}", "\u{2022}", 'EGP', "\xC3\x28" ) as $text ) {
			$this->assertFalse( Format::has_rtl( $text ), bin2hex( $text ) );
		}
	}

	/**
	 * An amount in an order note or an email: wc_price()'s HTML (its <bdi> is dropped by wp_kses_post()) inside an
	 * isolating span, left to right for a Latin currency sign, by its first strong letter for an Arabic one.
	 */
	public function test_price_html_isolation_by_its_currency_sign(): void {
		$latin = '<span class="woocommerce-Price-amount amount"><bdi>140&nbsp;<span class="woocommerce-Price-currencySymbol">EGP</span></bdi></span>';
		$this->assertSame( '<span dir="ltr" style="unicode-bidi:isolate">' . $latin . '</span>', Format::isolate( $latin, Format::NOTE ) );
		$arabic = '<span class="woocommerce-Price-amount amount"><bdi>140&nbsp;<span class="woocommerce-Price-currencySymbol">&#x62c;.&#x645;</span></bdi></span>';
		$this->assertSame( '<span dir="auto" style="unicode-bidi:isolate">' . $arabic . '</span>', Format::isolate( $arabic, Format::EMAIL_HTML ) );
	}

	/** Format::RTL_LETTERS and hasRtl() of assets/js/txn-normalize.js hold the same code points. */
	public function test_rtl_letters_equal_the_script_copy(): void {
		$js = qersh_wc_test_fixture( 'assets/js/txn-normalize.js' );
		$this->assertSame( 1, preg_match( '/var RTL_LETTERS = new RegExp\( charClass\( \[(.*?)\] \)\.source \);/s', $js, $block ), 'the RTL_LETTERS table of txn-normalize.js' );
		preg_match_all( '/\[\s*0x([0-9A-F]+),\s*0x([0-9A-F]+)\s*\]|0x([0-9A-F]+)/i', $block[1], $items, PREG_SET_ORDER );
		$ranges = array();
		foreach ( $items as $item ) {
			$ranges[] = isset( $item[3] ) && '' !== $item[3] ? array( hexdec( $item[3] ), hexdec( $item[3] ) ) : array( hexdec( $item[1] ), hexdec( $item[2] ) );
		}
		$this->assertNotEmpty( $ranges );
		for ( $cp = 0; $cp <= 0xFFFF; $cp++ ) {
			if ( $cp >= 0xD800 && $cp <= 0xDFFF ) {
				continue;
			}
			$in = false;
			foreach ( $ranges as $range ) {
				if ( $cp >= $range[0] && $cp <= $range[1] ) {
					$in = true;
					break;
				}
			}
			$ch  = (string) json_decode( '"\\u' . sprintf( '%04x', $cp ) . '"' );
			$php = 1 === preg_match( Format::RTL_LETTERS, $ch );
			if ( $php !== $in ) {
				$this->fail( sprintf( 'U+%04X: the script says %s', $cp, $in ? 'right to left' : 'not right to left' ) );
			}
		}
		$this->addToAssertionCount( 1 );
	}

	/** An 11-digit number is grouped 4-3-4 with no-break spaces; anything else is left as it is. */
	public function test_number_groups_wallet_numbers(): void {
		$this->assertSame( "0100\u{00A0}123\u{00A0}4567", Format::number( '01001234567' ) );
		$this->assertSame( 'name@instapay', Format::number( 'name@instapay' ) );
		$this->assertSame( '25007294067405', Format::number( '25007294067405' ) );
		$this->assertSame( '0100123456', Format::number( '0100123456' ) );
	}

	/** Every e-wallet number is grouped the same way, whatever its prefix: the display never depends on the wallet. */
	public function test_number_is_the_same_for_every_wallet(): void {
		foreach ( array( '01001234567', '01112223344', '01212345678', '01512345678' ) as $number ) {
			$this->assertSame( substr( $number, 0, 4 ) . "\u{00A0}" . substr( $number, 4, 3 ) . "\u{00A0}" . substr( $number, 7 ), Format::number( $number ), $number );
		}
		foreach ( array( '250046173830512', '913372004518', 'A1B2C3D4E5', '01001234567 ' ) as $value ) {
			$this->assertSame( $value, Format::number( $value ), 'not an 11-digit number: ' . $value );
		}
	}

	/** Masked numbers keep the first four and last three digits; an address keeps two characters and its domain. */
	public function test_masked(): void {
		$this->assertSame( "0111\u{2022}\u{2022}\u{2022}445", Format::masked( '01112233445' ) );
		$this->assertSame( "0151\u{2022}\u{2022}\u{2022}678", Format::masked( '01512345678' ) );
		$this->assertSame( "ah\u{2022}\u{2022}\u{2022}@instapay", Format::masked( 'ahmed@instapay' ) );
		$this->assertSame( "ah\u{2022}\u{2022}\u{2022}@example", Format::masked( 'ahmed@example' ) );
		$this->assertSame( "\u{2022}\u{2022}\u{2022}345", Format::masked( '12345' ) );
	}

	/** The raw amount a copy button copies. */
	public function test_amount_raw(): void {
		$this->assertSame( '140', Format::amount_raw( 14000 ) );
		$this->assertSame( '139.50', Format::amount_raw( 13950 ) );
		$this->assertSame( '0.05', Format::amount_raw( 5 ) );
		$this->assertSame( '0', Format::amount_raw( 0 ) );
		$this->assertSame( '-1.20', Format::amount_raw( -120 ) );
	}
}
