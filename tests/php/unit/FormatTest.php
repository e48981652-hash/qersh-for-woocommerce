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
	public function test_html_context_uses_bdi() {
		$this->assertSame( '<bdi dir="ltr">0100</bdi>', Format::isolate( '0100', Format::HTML ) );
		$this->assertSame( '<bdi dir="ltr" data-qersh-time="5">x</bdi>', Format::isolate( 'x', Format::HTML, ' data-qersh-time="5"' ) );
	}

	/** Order notes and HTML email use a span that wp_kses_post() keeps. */
	public function test_note_and_email_contexts_use_an_isolating_span() {
		foreach ( array( Format::NOTE, Format::EMAIL_HTML ) as $context ) {
			$this->assertSame( '<span dir="ltr" style="unicode-bidi:isolate">0100</span>', Format::isolate( '0100', $context ), $context );
		}
	}

	/** Plain text sits between U+2066 and U+2069. */
	public function test_plain_context_uses_isolate_marks() {
		$this->assertSame( "\u{2066}0111\u{2069}", Format::isolate( '0111', Format::PLAIN ) );
		$this->assertSame( "\u{2066}a<b\u{2069}", Format::ltr( 'a<b', Format::PLAIN ) );
	}

	/** ltr() escapes the value for the HTML contexts. */
	public function test_ltr_escapes_html() {
		$this->assertSame( '<bdi dir="ltr">a&lt;b&quot;</bdi>', Format::ltr( 'a<b"', Format::HTML ) );
		$this->assertSame( '<span dir="ltr" style="unicode-bidi:isolate">42</span>', Format::ltr( 42, Format::NOTE ) );
	}

	/** An 11-digit number is grouped 4-3-4 with no-break spaces; anything else is left as it is. */
	public function test_number_groups_wallet_numbers() {
		$this->assertSame( "0100\u{00A0}123\u{00A0}4567", Format::number( '01001234567' ) );
		$this->assertSame( 'name@instapay', Format::number( 'name@instapay' ) );
		$this->assertSame( '25007294067405', Format::number( '25007294067405' ) );
		$this->assertSame( '0100123456', Format::number( '0100123456' ) );
	}

	/** Masked numbers keep the first four and last three digits. */
	public function test_masked() {
		$this->assertSame( "0111\u{2022}\u{2022}\u{2022}445", Format::masked( '01112233445' ) );
		$this->assertSame( "ah\u{2022}\u{2022}\u{2022}@instapay", Format::masked( 'ahmed@instapay' ) );
		$this->assertSame( "\u{2022}\u{2022}\u{2022}345", Format::masked( '12345' ) );
	}

	/** The raw amount a copy button copies. */
	public function test_amount_raw() {
		$this->assertSame( '140', Format::amount_raw( 14000 ) );
		$this->assertSame( '139.50', Format::amount_raw( 13950 ) );
		$this->assertSame( '0.05', Format::amount_raw( 5 ) );
		$this->assertSame( '0', Format::amount_raw( 0 ) );
		$this->assertSame( '-1.20', Format::amount_raw( -120 ) );
	}
}
