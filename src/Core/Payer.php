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

	/** The white space and line terminators of JavaScript's String.prototype.trim() that take one byte in UTF-8. */
	const JS_SPACE_1 = "\t\n\x0B\f\r ";

	/** The same for two bytes: U+00A0. */
	const JS_SPACE_2 = array( "\xC2\xA0" );

	/** The same for three bytes: U+1680, U+2000 to U+200A, U+2028, U+2029, U+202F, U+205F, U+3000, U+FEFF. */
	const JS_SPACE_3 = array(
		"\xE1\x9A\x80",
		"\xE2\x80\x80",
		"\xE2\x80\x81",
		"\xE2\x80\x82",
		"\xE2\x80\x83",
		"\xE2\x80\x84",
		"\xE2\x80\x85",
		"\xE2\x80\x86",
		"\xE2\x80\x87",
		"\xE2\x80\x88",
		"\xE2\x80\x89",
		"\xE2\x80\x8A",
		"\xE2\x80\xA8",
		"\xE2\x80\xA9",
		"\xE2\x80\xAF",
		"\xE2\x81\x9F",
		"\xE3\x80\x80",
		"\xEF\xBB\xBF",
	);

	/** How deep the String() emulation follows nested arrays (json_decode's own default depth). */
	const MAX_DEPTH = 512;

	/**
	 * `01xxxxxxxxx` from any spelling (+20 1…, +20 01…, 0020…, spaces, Arabic-Indic or Persian digits), or an InstaPay
	 * handle as the app delivers it: `name@instapay` lower-case (a bare `name` gets the suffix). Null for anything
	 * else, including a masked number such as `0111***3445`.
	 *
	 * The value is read as the core's `String( raw ?? '' )` reads it (see js_string()). Bytes that are not valid
	 * UTF-8 are read as the core receives them: decoded with U+FFFD in place of each invalid sequence, as
	 * TextDecoder and json_decode( …, JSON_INVALID_UTF8_SUBSTITUTE ) do.
	 *
	 * @param mixed $raw Any value.
	 * @return string|null
	 */
	public static function normalize( $raw ): ?string {
		$s = self::lower( self::trim_js( self::to_valid_utf8( self::js_string( $raw, 0 ) ) ) );
		if ( '' === $s ) {
			return null;
		}
		if ( false !== strpos( $s, '@' ) ) {
			return preg_match( '/^[a-z0-9._-]{2,64}@instapay$/', $s ) ? $s : null;
		}
		$d     = '';
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
	 * JavaScript's `String( value ?? '' )`, for the values json_decode() gives:
	 * - null is '' (`?? ''` at the top, and Array.prototype.join() inside an array);
	 * - booleans are `true` and `false`, strings stay as they are, numbers take PHP's string form;
	 * - a list (keys 0 to n-1 in order, which is what json_decode() gives for a JSON array) is its elements, read
	 *   the same way, joined with commas, as Array.prototype.join() does; so `['01012345678']` and
	 *   `[['01012345678']]` both read as `01012345678`, in both languages;
	 * - any other array, and any object, is `[object Object]`, which never normalises.
	 * One shape cannot be told apart: json_decode( …, true ) also turns a JSON object whose keys are exactly "0" to
	 * "n-1", in that order, into a list, which JavaScript would read as `[object Object]`. The schema types the
	 * number and the handle as strings, so the phone sends neither shape.
	 *
	 * @param mixed $value Value.
	 * @param int   $depth How many arrays enclose the value.
	 * @return string
	 */
	private static function js_string( $value, int $depth ): string {
		if ( null === $value ) {
			return '';
		}
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		if ( is_string( $value ) ) {
			return $value;
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return (string) $value;
		}
		if ( is_array( $value ) && self::is_list( $value ) ) {
			if ( $depth >= self::MAX_DEPTH ) {
				return ''; // only an array that holds a reference to itself gets here; join() reads a cycle as ''
			}
			$parts = array();
			foreach ( $value as $item ) {
				$parts[] = self::js_string( $item, $depth + 1 );
			}
			return implode( ',', $parts );
		}
		return '[object Object]';
	}

	/**
	 * Array_is_list() for PHP 7.4: keys 0 to n-1 in order, including the empty array.
	 *
	 * @param array $value Array.
	 * @return bool
	 */
	private static function is_list( array $value ): bool {
		$i = 0;
		foreach ( $value as $k => $unused ) {
			if ( $k !== $i ) {
				return false;
			}
			++$i;
		}
		return true;
	}

	/**
	 * The text with each invalid UTF-8 sequence replaced by U+FFFD, as the WHATWG decoder (TextDecoder) does it: a
	 * maximal invalid subpart becomes one U+FFFD, and the byte that ended it is read again. Valid UTF-8 comes back
	 * unchanged.
	 *
	 * @param string $s Bytes.
	 * @return string
	 */
	private static function to_valid_utf8( string $s ): string {
		if ( 1 === preg_match( '//u', $s ) ) {
			return $s;
		}
		$out = '';
		$n   = strlen( $s );
		$i   = 0;
		while ( $i < $n ) {
			$len = self::utf8_sequence( $s, $i, $n );
			if ( $len > 0 ) {
				$out .= substr( $s, $i, $len );
				$i   += $len;
			} else {
				$out .= "\xEF\xBF\xBD";
				$i   -= $len;
			}
		}
		return $out;
	}

	/**
	 * The length of the well-formed UTF-8 sequence at a byte offset (Unicode table 3-7), or minus the length of the
	 * maximal invalid subpart there (at least one byte).
	 *
	 * @param string $s Bytes.
	 * @param int    $i Offset.
	 * @param int    $n Length of $s.
	 * @return int
	 */
	private static function utf8_sequence( string $s, int $i, int $n ): int {
		$b0 = ord( $s[ $i ] );
		if ( $b0 < 0x80 ) {
			return 1;
		}
		$lo = 0x80;
		$hi = 0xBF;
		if ( $b0 >= 0xC2 && $b0 <= 0xDF ) {
			$need = 1;
		} elseif ( 0xE0 === $b0 ) {
			$need = 2;
			$lo   = 0xA0;
		} elseif ( 0xED === $b0 ) {
			$need = 2;
			$hi   = 0x9F;
		} elseif ( $b0 >= 0xE1 && $b0 <= 0xEF ) {
			$need = 2;
		} elseif ( 0xF0 === $b0 ) {
			$need = 3;
			$lo   = 0x90;
		} elseif ( 0xF4 === $b0 ) {
			$need = 3;
			$hi   = 0x8F;
		} elseif ( $b0 >= 0xF1 && $b0 <= 0xF3 ) {
			$need = 3;
		} else {
			return -1;
		}
		for ( $k = 1; $k <= $need; $k++ ) {
			if ( $i + $k >= $n ) {
				return -$k;
			}
			$b = ord( $s[ $i + $k ] );
			if ( 1 === $k ? ( $b < $lo || $b > $hi ) : ( $b < 0x80 || $b > 0xBF ) ) {
				return -$k;
			}
		}
		return $need + 1;
	}

	/**
	 * JavaScript String.prototype.trim(): removes its white space and line terminators (U+0009 to U+000D, U+0020,
	 * U+00A0, U+1680, U+2000 to U+200A, U+2028, U+2029, U+202F, U+205F, U+3000, U+FEFF) from both ends, and
	 * nothing else: U+0085, U+180E and NUL stay. It works on bytes, so it cannot fail and stays linear; the text is
	 * valid UTF-8 here, so what it removes at either end is always a whole character.
	 *
	 * @param string $s Valid UTF-8.
	 * @return string
	 */
	private static function trim_js( string $s ): string {
		$start = 0;
		$end   = strlen( $s );
		while ( $start < $end ) {
			$n = self::js_space_length( $s, $start, $end, true );
			if ( 0 === $n ) {
				break;
			}
			$start += $n;
		}
		while ( $end > $start ) {
			$n = self::js_space_length( $s, $start, $end, false );
			if ( 0 === $n ) {
				break;
			}
			$end -= $n;
		}
		return (string) substr( $s, $start, $end - $start );
	}

	/**
	 * The byte length of the JavaScript white space character that starts at $start (or ends at $end), or 0.
	 *
	 * @param string $s       Valid UTF-8.
	 * @param int    $start   First byte of the remaining text.
	 * @param int    $end     One past its last byte.
	 * @param bool   $leading True to look at the start, false at the end.
	 * @return int
	 */
	private static function js_space_length( string $s, int $start, int $end, bool $leading ): int {
		if ( false !== strpos( self::JS_SPACE_1, $s[ $leading ? $start : $end - 1 ] ) ) {
			return 1;
		}
		foreach ( array( 2 => self::JS_SPACE_2, 3 => self::JS_SPACE_3 ) as $len => $set ) {
			if ( $end - $start < $len ) {
				break;
			}
			if ( in_array( substr( $s, $leading ? $start : $end - $len, $len ), $set, true ) ) {
				return $len;
			}
		}
		return 0;
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
