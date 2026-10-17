<?php
/**
 * Time helpers. The plugin keeps every time as integer UTC seconds (Playground runs 32-bit PHP).
 *
 * @package QershPay\WooCommerce
 */

declare( strict_types=1 );

namespace QershPay\WooCommerce\Core;

defined( 'ABSPATH' ) || exit;

/**
 * ISO 8601 to seconds and back. Pure PHP.
 */
final class Time {

	/**
	 * The ECMAScript Date Time String Format, as V8 (Node, where the core runs) reads it in Date.parse():
	 * `YYYY`, `±YYYYYY`, then `-MM` and `-DD`, then `THH:mm`, `:ss` and a fraction of any length, then `Z` or
	 * `±HH:mm` or `±HHmm`. `T` and `Z` in either case.
	 */
	const ES_FORMAT = '/^(?:([+-])([0-9]{6})|([0-9]{4}))(?:-([0-9]{2})(?:-([0-9]{2}))?)?(?:[Tt]([0-9]{2}):([0-9]{2})(?::([0-9]{2})(?:\.([0-9]+))?)?(?:([Zz])|([+-])([0-9]{2}):?([0-9]{2}))?)?\z/';

	/** The largest time a JavaScript Date holds, in seconds: 8.64e15 ms either side of 1970 (TimeClip). */
	const MAX_SEC = 8640000000000;

	/**
	 * Parses an ISO 8601 text into whole UTC seconds: Math.floor( Date.parse( x ) / 1000 ) as Node computes it, so
	 * milliseconds are dropped toward negative infinity. Accepts what V8 reads as the ECMAScript Date Time String
	 * Format (see ES_FORMAT), with V8's own rules:
	 * - a date alone (`2026`, `2026-10`, `2026-10-11`) is UTC midnight, and missing month and day are 01;
	 * - years 0000 to 9999 are taken as written (no two-digit years), `±YYYYYY` widens them (`-000000` is refused),
	 *   and the result must stay within a JavaScript Date (±8.64e15 ms);
	 * - months 01 to 12 and days 01 to 31; a day past the end of its month rolls over, as V8 does (2026-02-30 is
	 *   2026-03-02, where the specification would ask for NaN);
	 * - hours 00 to 23, or 24 when minutes, seconds and fraction are all zero (the end of that day);
	 * - offset hours 00 to 23 and minutes 00 to 59.
	 * Refused on purpose, although Date.parse can return a number:
	 * - a date-time without `Z` or an offset: Date.parse reads it as local time, which differs between machines;
	 * - anything outside this format, which V8 hands to its legacy parser (white space around or inside the text,
	 *   `2026-10-11 13:36:41Z`, `2026-1-1`, `2026-10-11Z`, month names, …): implementation-defined heuristics
	 *   (two-digit years, month-day-year order, local time) that no sender of these values uses.
	 * Null as well when the result does not fit PHP's int (on 32-bit PHP, outside 1901–2038).
	 *
	 * @param mixed $iso Value.
	 * @return int|null
	 */
	public static function iso_to_sec( $iso ): ?int {
		if ( ! is_string( $iso ) || 1 !== preg_match( self::ES_FORMAT, $iso, $m ) ) {
			return null;
		}
		$part = static function ( int $i ) use ( $m ): string {
			return isset( $m[ $i ] ) ? $m[ $i ] : '';
		};
		if ( '' !== $part( 2 ) ) {
			if ( '-' === $part( 1 ) && '000000' === $part( 2 ) ) {
				return null; // year zero is written +000000.
			}
			$year = ( '-' === $part( 1 ) ? -1 : 1 ) * (int) $part( 2 );
		} else {
			$year = (int) $part( 3 );
		}
		$month = '' === $part( 4 ) ? 1 : (int) $part( 4 );
		$day   = '' === $part( 5 ) ? 1 : (int) $part( 5 );
		if ( $month < 1 || $month > 12 || $day < 1 || $day > 31 ) {
			return null;
		}
		$clock  = 0;
		$offset = 0;
		$millis = 0;
		if ( '' !== $part( 6 ) ) {
			$hour     = (int) $part( 6 );
			$minute   = (int) $part( 7 );
			$second   = '' === $part( 8 ) ? 0 : (int) $part( 8 );
			$fraction = $part( 9 );
			if ( $hour > 24 || $minute > 59 || $second > 59 ) {
				return null;
			}
			if ( 24 === $hour && ( 0 !== $minute || 0 !== $second || '' !== trim( $fraction, '0' ) ) ) {
				return null;
			}
			if ( '' !== $part( 11 ) ) {
				$offset_hour   = (int) $part( 12 );
				$offset_minute = (int) $part( 13 );
				if ( $offset_hour > 23 || $offset_minute > 59 ) {
					return null;
				}
				$offset = ( '-' === $part( 11 ) ? -1 : 1 ) * ( $offset_hour * 3600 + $offset_minute * 60 );
			} elseif ( '' === $part( 10 ) ) {
				return null; // neither Z nor an offset: Date.parse would read local time.
			}
			$clock  = $hour * 3600 + $minute * 60 + $second;
			$millis = self::read_milliseconds( $fraction );
		}
		$sec = ( self::days_from_civil( $year, $month ) + $day - 1 ) * 86400 + $clock - $offset;
		if ( $sec < -self::MAX_SEC || $sec > self::MAX_SEC || ( self::MAX_SEC === $sec && $millis > 0 ) ) {
			return null;
		}
		if ( is_float( $sec ) && $sec >= PHP_INT_MIN && $sec <= PHP_INT_MAX ) {
			$sec = (int) $sec; // 32-bit PHP: a part overflowed into a float, but the result fits (exact below 2^53)
		}
		return is_int( $sec ) ? $sec : null;
	}

	/**
	 * Formats seconds as `YYYY-MM-DDTHH:MM:SSZ`.
	 *
	 * @param int $sec UTC seconds.
	 * @return string
	 */
	public static function sec_to_iso( int $sec ): string {
		return gmdate( 'Y-m-d\TH:i:s\Z', $sec );
	}

	/**
	 * The days from 1970-01-01 to the first day of a month, in the proleptic Gregorian calendar (year 0 exists and
	 * is a leap year), as MakeDay() computes them. Exact for every year a JavaScript Date can hold, also on 32-bit
	 * PHP (at most about 1e8 days).
	 *
	 * @param int $year  Year.
	 * @param int $month Month, 1 to 12.
	 * @return int
	 */
	private static function days_from_civil( int $year, int $month ): int {
		$y   = $month <= 2 ? $year - 1 : $year;
		$era = intdiv( $y >= 0 ? $y : $y - 399, 400 );
		$yoe = $y - $era * 400;
		$doy = intdiv( 153 * ( $month > 2 ? $month - 3 : $month + 9 ) + 2, 5 );
		$doe = $yoe * 365 + intdiv( $yoe, 4 ) - intdiv( $yoe, 100 ) + $doy;
		return $era * 146097 + $doe - 719468;
	}

	/**
	 * The milliseconds V8 reads from a fraction (DateParser::ReadMilliseconds): the first three digits, from at
	 * most nine significant ones. Only the upper limit of a Date depends on it; the seconds never do.
	 *
	 * @param string $fraction The digits after the point, or ''.
	 * @return int 0 to 999.
	 */
	private static function read_milliseconds( string $fraction ): int {
		$length = strlen( $fraction );
		if ( 0 === $length ) {
			return 0;
		}
		$value = (int) substr( ltrim( $fraction, '0' ), 0, 9 );
		if ( $length < 3 ) {
			return $value * ( 1 === $length ? 100 : 10 );
		}
		for ( $length = min( $length, 9 ); $length > 3; $length-- ) {
			$value = intdiv( $value, 10 );
		}
		return $value;
	}
}
