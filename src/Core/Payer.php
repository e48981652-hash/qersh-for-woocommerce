<?php
/**
 * Payer identifiers, as the core (normalizePayer, payerKeyOf in packages/qersh-webhook) normalises them.
 *
 * @package QershPay\WooCommerce
 */

declare( strict_types=1 );

namespace QershPay\WooCommerce\Core;

defined( 'ABSPATH' ) || exit;

/**
 * A literal port of payer.ts. Pure PHP.
 */
final class Payer {

	/**
	 * `01xxxxxxxxx` from any spelling (+20 1…, +20 01…, 0020…, spaces, Arabic-Indic or Persian digits), or an InstaPay
	 * handle as the app delivers it: `name@instapay` lower-case (a bare `name` gets the suffix). Null for anything
	 * else, including a masked number such as `0111***3445`.
	 *
	 * @param mixed $raw Any value.
	 * @return string|null
	 */
	public static function normalize( $raw ): ?string {
		if ( null === $raw || is_array( $raw ) || is_object( $raw ) ) {
			$s = '';
		} elseif ( is_bool( $raw ) ) {
			$s = $raw ? 'true' : 'false';
		} else {
			$s = (string) $raw;
		}
		$s = self::lower( self::trim_js( $s ) );
		if ( '' === $s ) {
			return null;
		}
		if ( false !== strpos( $s, '@' ) ) {
			return preg_match( '/^[a-z0-9._-]{2,64}@instapay$/', $s ) ? $s : null;
		}
		$d = '';
		$chars = preg_split( '//u', $s, -1, PREG_SPLIT_NO_EMPTY );
		foreach ( false === $chars ? array() : $chars as $ch ) {
			$c = self::code_unit( $ch );
			if ( $c >= 0x30 && $c <= 0x39 ) {
				$d .= $ch;
			} elseif ( $c >= 0x660 && $c <= 0x669 ) {
				$d .= chr( $c - 0x660 + 0x30 );
			} elseif ( $c >= 0x6f0 && $c <= 0x6f9 ) {
				$d .= chr( $c - 0x6f0 + 0x30 );
			}
		}
		if ( 0 === strpos( $d, '00' ) ) {
			$d = substr( $d, 2 ); // 0020…
		}
		if ( 0 === strpos( $d, '20' ) && strlen( $d ) >= 12 ) {
			$d = substr( $d, 2 ); // 201… or 2001…
		}
		if ( 10 === strlen( $d ) && 0 === strpos( $d, '1' ) ) {
			$d = '0' . $d; // 1xxxxxxxxx
		}
		if ( preg_match( '/^01[0-9]{9}$/', $d ) ) {
			return $d;
		}
		if ( preg_match( '/^[a-z0-9._-]{2,64}$/', $s ) && preg_match( '/[a-z]/', $s ) ) {
			return $s . '@instapay'; // handle typed without the suffix
		}
		return null;
	}

	/**
	 * The payer key of a transfer: the number when it normalises, otherwise the handle.
	 *
	 * @param mixed $counterparty Array with number/handle, or anything else.
	 * @return string|null
	 */
	public static function key_of( $counterparty ): ?string {
		if ( ! is_array( $counterparty ) ) {
			return null;
		}
		$number = self::normalize( array_key_exists( 'number', $counterparty ) ? $counterparty['number'] : null );
		if ( null !== $number ) {
			return $number;
		}
		return self::normalize( array_key_exists( 'handle', $counterparty ) ? $counterparty['handle'] : null );
	}

	/**
	 * JavaScript String.prototype.trim(): removes white space and line terminators, including NBSP and BOM.
	 *
	 * @param string $s Text.
	 * @return string
	 */
	private static function trim_js( string $s ): string {
		$out = preg_replace( '/^[\s\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]+|[\s\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]+$/u', '', $s );
		return null === $out ? trim( $s ) : $out;
	}

	/**
	 * JavaScript toLowerCase() for the characters that matter here (ASCII); other characters are left as they are,
	 * which only affects values that cannot normalise anyway.
	 *
	 * @param string $s Text.
	 * @return string
	 */
	private static function lower( string $s ): string {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
	}

	/**
	 * The UTF-16 code unit JavaScript's charCodeAt(0) would return for the first character (BMP characters only matter).
	 *
	 * @param string $ch One UTF-8 character.
	 * @return int
	 */
	private static function code_unit( string $ch ): int {
		$bytes = array_values( unpack( 'C*', $ch ) ?: array() );
		$n     = count( $bytes );
		if ( 1 === $n ) {
			return $bytes[0];
		}
		if ( 2 === $n ) {
			return ( ( $bytes[0] & 0x1F ) << 6 ) | ( $bytes[1] & 0x3F );
		}
		if ( 3 === $n ) {
			return ( ( $bytes[0] & 0x0F ) << 12 ) | ( ( $bytes[1] & 0x3F ) << 6 ) | ( $bytes[2] & 0x3F );
		}
		return 0xD800; // astral: JavaScript sees a surrogate, never a digit
	}
}
