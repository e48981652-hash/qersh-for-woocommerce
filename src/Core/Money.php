<?php
/**
 * Money helpers. Amounts are always integer minor units (piasters).
 *
 * @package QershPay\WooCommerce
 */

declare( strict_types=1 );

namespace QershPay\WooCommerce\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Rounding and conversion. Pure PHP.
 */
final class Money {

	/**
	 * The smallest whole-pound amount at or above the value: what a *9# transfer can carry (docs/17 §4.6).
	 *
	 * @param int $minor Piasters.
	 * @return int
	 */
	public static function round_up_pound( int $minor ): int {
		if ( $minor <= 0 ) {
			return $minor;
		}
		return intdiv( $minor + 99, 100 ) * 100;
	}

	/**
	 * Converts a decimal string such as "139.50" or "140" into piasters, or null when it is not a plain amount.
	 * Uses string arithmetic, never floats.
	 *
	 * @param mixed $decimal Value.
	 * @return int|null
	 */
	public static function to_minor( $decimal ): ?int {
		if ( is_int( $decimal ) ) {
			return $decimal * 100;
		}
		if ( ! is_string( $decimal ) || ! preg_match( '/^(\d{1,13})(?:\.(\d{1,2}))?$/', trim( $decimal ), $m ) ) {
			return null;
		}
		$fraction = isset( $m[2] ) ? str_pad( $m[2], 2, '0' ) : '00';
		return (int) $m[1] * 100 + (int) $fraction;
	}
}
