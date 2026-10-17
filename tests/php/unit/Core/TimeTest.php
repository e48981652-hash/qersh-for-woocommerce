<?php
/**
 * Tests of Core\Time (given): ISO 8601 to whole UTC seconds and back.
 *
 * @package QershPay\WooCommerce\Tests
 */

declare( strict_types=1 );

namespace QershPay\WooCommerce\Tests\Unit\Core;

use QershPay\WooCommerce\Core\Time;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

defined( 'ABSPATH' ) || exit;

/**
 * Time::iso_to_sec() and Time::sec_to_iso().
 */
final class TimeTest extends TestCase {

	/** 2026-10-11T13:36:41Z, the time of the kit's valid_transfer_received vector. */
	const VECTOR = 1791725801;

	/** `Z` in either case, with or without seconds. */
	public function test_utc() {
		$this->assertSame( self::VECTOR, Time::iso_to_sec( '2026-10-11T13:36:41Z' ) );
		$this->assertSame( self::VECTOR, Time::iso_to_sec( '2026-10-11t13:36:41z' ) );
		$this->assertSame( self::VECTOR - 41, Time::iso_to_sec( '2026-10-11T13:36Z' ) );
		$this->assertSame( 0, Time::iso_to_sec( '1970-01-01T00:00:00Z' ) );
	}

	/** Offsets, with or without the colon, east and west of UTC. */
	public function test_offsets() {
		$this->assertSame( self::VECTOR, Time::iso_to_sec( '2026-10-11T16:36:41+03:00' ) );
		$this->assertSame( self::VECTOR, Time::iso_to_sec( '2026-10-11T16:36:41+0300' ) );
		$this->assertSame( self::VECTOR, Time::iso_to_sec( '2026-10-11T08:06:41-05:30' ) );
		$this->assertSame( self::VECTOR, Time::iso_to_sec( '2026-10-11T13:36:41+00:00' ) );
		$this->assertSame( self::VECTOR, Time::iso_to_sec( '2026-10-11T13:36:41-00:00' ) );
		$this->assertSame( self::VECTOR + 3600, Time::iso_to_sec( '2026-10-11T13:36:41-01:00' ) );
		$this->assertSame( Time::iso_to_sec( '2026-10-11T00:00:00Z' ) - ( 23 * 3600 + 59 * 60 ), Time::iso_to_sec( '2026-10-11T00:00:00+23:59' ) );
		$this->assertNull( Time::iso_to_sec( '2026-10-11T16:36:41+24:00' ) );
		$this->assertNull( Time::iso_to_sec( '2026-10-11T16:36:41+03:60' ) );
		$this->assertNull( Time::iso_to_sec( '2026-10-11T16:36:41+03' ) );
	}

	/** Fractions of a second are dropped toward negative infinity, like Math.floor( Date.parse( x ) / 1000 ). */
	public function test_milliseconds_are_floored() {
		$this->assertSame( self::VECTOR, Time::iso_to_sec( '2026-10-11T13:36:41.000Z' ) );
		$this->assertSame( self::VECTOR, Time::iso_to_sec( '2026-10-11T13:36:41.999Z' ) );
		$this->assertSame( self::VECTOR, Time::iso_to_sec( '2026-10-11T16:36:41.5+03:00' ) );
		$this->assertSame( self::VECTOR, Time::iso_to_sec( '2026-10-11T13:36:41.123456789Z' ) );
		$this->assertSame( -1, Time::iso_to_sec( '1969-12-31T23:59:59.5Z' ) );
		$this->assertNull( Time::iso_to_sec( '2026-10-11T13:36.5Z' ) );
	}

	/** A date alone is UTC midnight, as Date.parse reads it. */
	public function test_date_only() {
		$this->assertSame( self::VECTOR - ( 13 * 3600 + 36 * 60 + 41 ), Time::iso_to_sec( '2026-10-11' ) );
		$this->assertSame( Time::iso_to_sec( '2026-10-11T00:00:00Z' ), Time::iso_to_sec( '2026-10-11' ) );
		$this->assertSame( Time::iso_to_sec( '2028-02-29T00:00:00Z' ), Time::iso_to_sec( '2028-02-29' ) );
		$this->assertNull( Time::iso_to_sec( '2026-1-1' ) );
	}

	/** The other date-only forms: a year alone, a year and a month, and a time after either. */
	public function test_short_dates() {
		$this->assertSame( 1767225600, Time::iso_to_sec( '2026' ) );
		$this->assertSame( Time::iso_to_sec( '2026-01-01T00:00:00Z' ), Time::iso_to_sec( '2026' ) );
		$this->assertSame( 1790812800, Time::iso_to_sec( '2026-10' ) );
		$this->assertSame( Time::iso_to_sec( '2026-10-01' ), Time::iso_to_sec( '2026-10' ) );
		$this->assertSame( 1790861760, Time::iso_to_sec( '2026-10T13:36Z' ) );
		$this->assertSame( 1767263801, Time::iso_to_sec( '2026T13:36:41.5+03:00' ) );
		$this->assertNull( Time::iso_to_sec( '2026-10T13:36' ) ); // a date-time without an offset
	}

	/** Years 0000 to 0100 are taken as written, never as two-digit years; year 0 is a leap year, as in MakeDay. */
	public function test_early_years() {
		$this->assertSame( -61078152486, Time::iso_to_sec( '0034-07-05T22:31:54Z' ) );
		$this->assertSame( -62167219200, Time::iso_to_sec( '0000-01-01' ) );
		$this->assertSame( -62135596800, Time::iso_to_sec( '0001-01-01' ) );
		$this->assertSame( -59958144001, Time::iso_to_sec( '0069-12-31T23:59:59Z' ) );
		$this->assertSame( -59958144000, Time::iso_to_sec( '0070-01-01' ) );
		$this->assertSame( -59011459201, Time::iso_to_sec( '0099-12-31T23:59:59Z' ) );
		$this->assertSame( -59006361600, Time::iso_to_sec( '0100-03-01' ) );
		$this->assertSame( -2203891200, Time::iso_to_sec( '1900-03-01' ) );
		$this->assertSame( Time::iso_to_sec( '0000-03-01' ) - 86400, Time::iso_to_sec( '0000-02-29' ) );
	}

	/** Expanded years ±YYYYYY: before year 0 too, but never -000000. */
	public function test_expanded_years() {
		$this->assertSame( self::VECTOR, Time::iso_to_sec( '+002026-10-11T13:36:41Z' ) );
		$this->assertSame( 1767225600, Time::iso_to_sec( '+002026' ) );
		$this->assertSame( -126077077399, Time::iso_to_sec( '-002026-10-11T13:36:41Z' ) );
		$this->assertSame( -62167219200, Time::iso_to_sec( '+000000-01-01T00:00:00Z' ) );
		$this->assertSame( -62167219201, Time::iso_to_sec( '-000001-12-31T23:59:59Z' ) );
		$this->assertSame( -62288352000, Time::iso_to_sec( '-000004-02-29' ) ); // a leap year
		$this->assertSame( -65317795200, Time::iso_to_sec( '-000100-02-29' ) ); // not one: 1 March
		$this->assertSame( -74784902400, Time::iso_to_sec( '-000400-02-29' ) ); // a leap year
		foreach ( array( '-000000-01-01T00:00:00Z', '-000000-01-01', '+2026-10-11T00:00:00Z', '+02026-10-11T00:00:00Z', '20261-10-11T00:00:00Z', '+0020260-10-11' ) as $iso ) {
			$this->assertNull( Time::iso_to_sec( $iso ), $iso );
		}
	}

	/** A JavaScript Date holds 8.64e15 ms either side of 1970; past that Date.parse returns NaN. */
	public function test_date_limits() {
		$this->assertSame( 8640000000000, Time::iso_to_sec( '+275760-09-13T00:00:00Z' ) );
		$this->assertSame( 8640000000000, Time::iso_to_sec( '+275760-09-13' ) );
		$this->assertSame( 8640000000000, Time::iso_to_sec( '+275760-09-13T00:00:00.0001Z' ) ); // V8 reads 0 ms
		$this->assertSame( 8639999999999, Time::iso_to_sec( '+275760-09-12T23:59:59.999Z' ) );
		$this->assertSame( -8640000000000, Time::iso_to_sec( '-271821-04-20T00:00:00Z' ) );
		$this->assertSame( -8639999999940, Time::iso_to_sec( '-271821-04-20T00:00:00-00:01' ) );
		foreach ( array( '+275760-09-13T00:00:00.001Z', '+275760-09-14', '-271821-04-19T23:59:59.999Z', '-271821-04-20T00:00:00+00:01', '+999999-12-31' ) as $iso ) {
			$this->assertNull( Time::iso_to_sec( $iso ), $iso );
		}
	}

	/** A day 29 to 31 past the end of its month rolls over, as V8 reads it; other impossible parts are refused. */
	public function test_impossible_dates() {
		$this->assertSame( Time::iso_to_sec( '2026-03-01' ), Time::iso_to_sec( '2026-02-29' ) ); // 2026 is not a leap year
		$this->assertSame( 1772409600, Time::iso_to_sec( '2026-02-30' ) );
		$this->assertSame( Time::iso_to_sec( '2026-03-02' ), Time::iso_to_sec( '2026-02-30' ) );
		$this->assertSame( 1772496000, Time::iso_to_sec( '2026-02-31T00:00:00Z' ) );
		$this->assertSame( 1777593600, Time::iso_to_sec( '2026-04-31' ) );
		$this->assertSame( Time::iso_to_sec( '2028-03-01' ), Time::iso_to_sec( '2028-02-30' ) );
		$cases = array(
			'2026-13-01',
			'2026-00-10',
			'2026-10-00',
			'2026-10-32',
			'2026-13',
			'2026-00',
			'2026-10-11T25:00:00Z',
			'2026-10-11T24:00:01Z',
			'2026-10-11T24:01Z',
			'2026-10-11T24:00:00.0001Z',
			'2026-10-11T13:60:00Z',
			'2026-10-11T23:59:60Z',
		);
		foreach ( $cases as $iso ) {
			$this->assertNull( Time::iso_to_sec( $iso ), $iso );
		}
		$this->assertSame( Time::iso_to_sec( '2026-10-12T00:00:00Z' ), Time::iso_to_sec( '2026-10-11T24:00:00Z' ) );
		$this->assertSame( Time::iso_to_sec( '2026-10-12T00:00:00Z' ), Time::iso_to_sec( '2026-10-11T24:00Z' ) );
		$this->assertSame( Time::iso_to_sec( '2026-10-12T00:00:00Z' ), Time::iso_to_sec( '2026-10-11T24:00:00.000000Z' ) );
		$this->assertSame( 1791752400, Time::iso_to_sec( '2026-10-11T24:00:00+03:00' ) );
		$this->assertSame( 1798761600, Time::iso_to_sec( '2026-12-31T24:00Z' ) );
		$this->assertSame( Time::iso_to_sec( '2028-03-01T00:00:00Z' ) - 86400, Time::iso_to_sec( '2028-02-29T00:00:00Z' ) );
	}

	/** A fraction of any length is read; only its first digits could matter, and never to the seconds. */
	public function test_long_fractions() {
		$this->assertSame( self::VECTOR, Time::iso_to_sec( '2026-10-11T13:36:41.1234567890123Z' ) );
		$this->assertSame( self::VECTOR, Time::iso_to_sec( '2026-10-11T13:36:41.0001234567891Z' ) );
		$this->assertSame( self::VECTOR, Time::iso_to_sec( '2026-10-11T13:36:41.' . str_repeat( '9', 40 ) . 'Z' ) );
		$this->assertNull( Time::iso_to_sec( '2026-10-11T13:36:41.Z' ) );
		$this->assertNull( Time::iso_to_sec( '2026-10-11T13:36:41,5Z' ) );
	}

	/** A date-time without an offset is refused: Date.parse would read it as local time, which differs between machines. */
	public function test_offset_less_date_time_is_refused() {
		$this->assertNull( Time::iso_to_sec( '2026-10-11T16:36:41' ) );
		$this->assertNull( Time::iso_to_sec( '2026-10-11T16:36' ) );
		$this->assertNull( Time::iso_to_sec( '2026-10-11T16:36:41.123' ) );
		$this->assertNull( Time::iso_to_sec( '2026-10-11 13:36:41' ) );
	}

	/**
	 * Nothing is trimmed: white space around the text is refused (Date.parse returns NaN, or reads local time when
	 * the text is a date alone).
	 */
	public function test_white_space_is_not_trimmed() {
		$cases = array(
			' 2026-10-11T00:00:00Z',
			'2026-10-11T00:00:00Z ',
			"2026-10-11T13:36:41Z\n",
			"\t2026-10-11",
			"2026-10-11\n",
			"2026\n",
			'2026-10-11T13:36:41 Z',
			"2026-10-11T13:36:41\u{00A0}Z",
		);
		foreach ( $cases as $iso ) {
			$this->assertNull( Time::iso_to_sec( $iso ), $iso );
		}
	}

	/**
	 * Texts outside the format are refused on purpose, also where V8's legacy parser would return a number: its
	 * heuristics (two-digit years, month-day-year order, local time) belong to no sender of these values.
	 */
	public function test_legacy_forms_are_refused() {
		$cases = array(
			'2026-10-11 13:36:41Z', // V8: UTC
			'2032-04-26 03:00:24Z',
			'0012-07-05 22:31:54Z', // V8: 2005-12-07
			'2026-10-11Z', // V8: UTC midnight
			'2026-1-1Z',
			'2026-10-11T13:36:41+03:00:00',
			'2026-10-11T13:36:41+3:00',
			'2026-10-11T13:36:41+030',
			'2026-10-11T13:36:41UTC',
			'Sun, 11 Oct 2026 13:36:41 GMT',
			'Oct 11 2026',
			'2026/10/11',
		);
		foreach ( $cases as $iso ) {
			$this->assertNull( Time::iso_to_sec( $iso ), $iso );
		}
	}

	/** Anything else is null. */
	public function test_other_values() {
		foreach ( array( 'not a date', '', ' ', 'T13:36:41Z', '1791725801' ) as $iso ) {
			$this->assertNull( Time::iso_to_sec( $iso ), $iso );
		}
		foreach ( array( null, self::VECTOR, 1.5, true, array( '2026-10-11' ) ) as $value ) {
			$this->assertNull( Time::iso_to_sec( $value ) );
		}
	}

	/** Seconds back to ISO: `YYYY-MM-DDTHH:MM:SSZ`, and the round trip. */
	public function test_sec_to_iso() {
		$this->assertSame( '2026-10-11T13:36:41Z', Time::sec_to_iso( self::VECTOR ) );
		$this->assertSame( '1970-01-01T00:00:00Z', Time::sec_to_iso( 0 ) );
		$this->assertSame( '1969-12-31T23:59:59Z', Time::sec_to_iso( -1 ) );
		foreach ( array( 0, 1, self::VECTOR, 1800000000, 2147483647 ) as $sec ) {
			$this->assertSame( $sec, Time::iso_to_sec( Time::sec_to_iso( $sec ) ) );
		}
	}
}
