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
	 * Parses the ISO 8601 forms the app and the core use into whole UTC seconds (milliseconds dropped toward
	 * negative infinity, like Math.floor(Date.parse(x) / 1000)). Accepts a date alone (UTC midnight, as Date.parse
	 * does) or a date-time with `Z` or an offset. A date-time without an offset is refused (Date.parse would read
	 * it as local time, which differs between machines). Null for anything else.
	 *
	 * @param mixed $iso Value.
	 * @return int|null
	 */
	public static function iso_to_sec( $iso ): ?int {
		if ( ! is_string( $iso ) ) {
			return null;
		}
		$s = trim( $iso );
		if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m ) ) {
			return self::from_parts( (int) $m[1], (int) $m[2], (int) $m[3], 0, 0, 0, 0 );
		}
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})(?::(\d{2})(?:\.(\d{1,9}))?)?(Z|[+-]\d{2}:?\d{2})$/i', $s, $m ) ) {
			return null;
		}
		$offset = 0;
		$zone   = strtoupper( $m[8] );
		if ( 'Z' !== $zone ) {
			$sign   = '-' === $zone[0] ? -1 : 1;
			$digits = str_replace( ':', '', substr( $zone, 1 ) );
			$hours  = (int) substr( $digits, 0, 2 );
			$mins   = (int) substr( $digits, 2, 2 );
			if ( $hours > 23 || $mins > 59 ) {
				return null;
			}
			$offset = $sign * ( $hours * 3600 + $mins * 60 );
		}
		$sec = isset( $m[6] ) && '' !== $m[6] ? (int) $m[6] : 0;
		$utc = self::from_parts( (int) $m[1], (int) $m[2], (int) $m[3], (int) $m[4], (int) $m[5], $sec, $offset );
		return $utc;
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
	 * Builds UTC seconds from calendar parts, refusing impossible dates (2026-02-30) like Date.parse returns NaN.
	 *
	 * @param int $y Year.
	 * @param int $mo Month.
	 * @param int $d Day.
	 * @param int $h Hour.
	 * @param int $mi Minute.
	 * @param int $s Second.
	 * @param int $offset Offset of the written time from UTC, in seconds.
	 * @return int|null
	 */
	private static function from_parts( int $y, int $mo, int $d, int $h, int $mi, int $s, int $offset ): ?int {
		if ( $mo < 1 || $mo > 12 || $d < 1 || $d > 31 || $h > 24 || $mi > 59 || $s > 59 ) {
			return null;
		}
		if ( 24 === $h && ( 0 !== $mi || 0 !== $s ) ) {
			return null;
		}
		if ( ! checkdate( $mo, $d, $y ) ) {
			return null;
		}
		$t = gmmktime( $h, $mi, $s, $mo, $d, $y );
		return false === $t ? null : $t - $offset;
	}
}
