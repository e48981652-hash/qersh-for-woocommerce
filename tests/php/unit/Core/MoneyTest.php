<?php
/**
 * Tests of Core\Money (given): rounding up to the pound and decimal strings to piasters.
 *
 * @package QershPay\WooCommerce\Tests
 */

declare( strict_types=1 );

namespace QershPay\WooCommerce\Tests\Unit\Core;

use QershPay\WooCommerce\Core\Money;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

defined( 'ABSPATH' ) || exit;

/**
 * Money::round_up_pound() and Money::to_minor().
 */
final class MoneyTest extends TestCase {

	/** The smallest whole-pound amount at or above the value (docs/17 §4.6: 139.50 → 140). */
	public function test_round_up_pound(): void {
		$cases = array(
			13950 => 14000,
			14000 => 14000,
			14001 => 14100,
			13901 => 14000,
			1     => 100,
			99    => 100,
			100   => 100,
			101   => 200,
			0     => 0,
		);
		foreach ( $cases as $minor => $expected ) {
			$this->assertSame( $expected, Money::round_up_pound( $minor ), (string) $minor );
		}
	}

	/** It equals ceil( minor / 100 ) * 100 for every positive amount (CONTRACTS.md §6). */
	public function test_round_up_pound_is_ceil_for_positive_amounts(): void {
		foreach ( array( 1, 50, 99, 100, 101, 199, 200, 12345, 15007, 99999999, 2147483547 ) as $minor ) {
			$this->assertSame( (int) ( ceil( $minor / 100 ) * 100 ), Money::round_up_pound( $minor ), (string) $minor );
		}
	}

	/** Decimal strings with up to two places, and whole numbers. */
	public function test_to_minor_strings(): void {
		$cases = array(
			'139.50'           => 13950,
			'139.5'            => 13950,
			'140'              => 14000,
			'140.00'           => 14000,
			'0.05'             => 5,
			'0.5'              => 50,
			'0'                => 0,
			'0012'             => 1200,
			'150.07'           => 15007,
			' 12.30 '          => 1230,
			'9999999999999.99' => 999999999999999,
		);
		foreach ( $cases as $decimal => $expected ) {
			$this->assertSame( $expected, Money::to_minor( (string) $decimal ), (string) $decimal );
		}
	}

	/** An int is a whole number of pounds. */
	public function test_to_minor_int(): void {
		$this->assertSame( 14000, Money::to_minor( 140 ) );
		$this->assertSame( 0, Money::to_minor( 0 ) );
	}

	/** Anything that is not a plain amount is null, floats included (never float arithmetic). */
	public function test_to_minor_refused(): void {
		// phpcs:ignore PHPCompatibility.Miscellaneous.ValidIntegers.HexNumericStringFound -- '0x10' is an input to_minor() must refuse, never used as a number.
		foreach ( array( '1.234', '.5', '5.', '-5', '+5', '1,000', '1 000', '1e3', '', ' ', 'abc', '١٢', '0x10', '12.3.4', '99999999999999' ) as $decimal ) {
			$this->assertNull( Money::to_minor( $decimal ), $decimal );
		}
		foreach ( array( 139.5, 140.0, null, true, array( '140' ) ) as $value ) {
			$this->assertNull( Money::to_minor( $value ) );
		}
	}
}
