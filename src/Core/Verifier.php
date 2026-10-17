<?php
/**
 * The qersh.v1 signature check (X-Qersh-Signature), as the core (signature.ts in packages/qersh-webhook) does it.
 *
 * @package QershPay\WooCommerce
 */

declare( strict_types=1 );

namespace QershPay\WooCommerce\Core;

defined( 'ABSPATH' ) || exit;

/**
 * A literal port of signature.ts. Pure PHP.
 *
 * The header is `t=<unix seconds>,v1=<hex HMAC-SHA256>` over the bytes of `t + "." + rawBody`, keyed by the bytes of
 * the connection key. During a key rotation the header carries one `v1=` per live key; any matching value is accepted.
 * The window is |now - t| <= tolerance (300 s). The algorithm is the kit's verify.php (one HMAC per key, then
 * `hash_equals` against every `v1`), with the stricter parsing rules of parseSignatureHeader.
 *
 * Strings are handled as JavaScript would handle the same text: trimming removes JavaScript white space (ASCII white
 * space, NBSP, U+1680, U+2000-U+200A, U+2028, U+2029, U+202F, U+205F, U+3000 and U+FEFF), and the 8192 limit counts
 * UTF-16 code units (bytes when the text is not valid UTF-8).
 */
final class Verifier {

	/** Longest accepted header, after trimming, in UTF-16 code units (as `text.length` counts). */
	const MAX_HEADER_LENGTH = 8192;

	/** The contract's window in seconds. */
	const DEFAULT_TOLERANCE_SEC = 300;

	/** Why a delivery was refused, in the order the checks run. A receiver answers 401 for every one of these. */
	const REASONS = array( 'no_key', 'raw_body_unavailable', 'missing_header', 'malformed_header', 'stale_timestamp', 'bad_signature' );

	/** JavaScript white space outside ASCII, as UTF-8 byte sequences (two or three bytes each). */
	const WS_MULTIBYTE = array(
		"\xC2\xA0",
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

	/**
	 * Parses the header (parseSignatureHeader). Splits on commas and reads `t=` and `v1=` pairs, keys and values
	 * trimmed. `t` must be 1 to 13 digits, or the whole header is refused; a `v1` value that is not 64 hex characters
	 * is ignored. Returns null when there is no usable `t` or no well-formed `v1`.
	 *
	 * @param mixed $header The header value; anything but a string gives null.
	 * @return array{t: int, v1: string[]}|null `v1` holds every well-formed value, lower-cased, in header order.
	 */
	public static function parse_header( $header ): ?array {
		if ( ! is_string( $header ) ) {
			return null;
		}
		$text = self::trim_js( $header );
		if ( '' === $text || self::too_long( $text ) ) {
			return null;
		}
		$t  = null;
		$v1 = array();
		foreach ( explode( ',', $text ) as $part ) {
			$eq = strpos( $part, '=' );
			if ( false === $eq ) {
				continue;
			}
			$key   = self::trim_js( (string) substr( $part, 0, $eq ) );
			$value = self::trim_js( (string) substr( $part, $eq + 1 ) );
			if ( 't' === $key ) {
				if ( 1 !== preg_match( '/^[0-9]{1,13}\z/', $value ) ) {
					return null;
				}
				$t = (int) $value;
			} elseif ( 'v1' === $key ) {
				if ( 1 === preg_match( '/^[0-9a-fA-F]{64}\z/', $value ) ) {
					$v1[] = strtolower( $value );
				}
			}
		}
		if ( null === $t || 0 === count( $v1 ) ) {
			return null;
		}
		return array(
			't'  => $t,
			'v1' => $v1,
		);
	}

	/**
	 * The exact bytes that are signed (signedPayload): `t` in decimal, a dot, then the raw request body.
	 *
	 * @param int    $t        Unix seconds.
	 * @param string $raw_body The body exactly as received.
	 * @return string
	 */
	public static function signed_payload( int $t, string $raw_body ): string {
		return $t . '.' . $raw_body;
	}

	/**
	 * The keys a receiver can check with (usableKeys): strings only, trimmed (the phone trims a key before it stores
	 * and signs with it), empty ones dropped. Each entry keeps its position in the caller's array, so `index` means
	 * something to the caller.
	 *
	 * @param mixed $keys The caller's keys; anything but an array gives none.
	 * @return array<int, array{key: string, index: int}>
	 */
	public static function usable_keys( $keys ): array {
		if ( ! is_array( $keys ) ) {
			return array();
		}
		$out   = array();
		$index = 0;
		foreach ( $keys as $value ) {
			if ( is_string( $value ) ) {
				$key = self::trim_js( $value );
				if ( '' !== $key ) {
					$out[] = array(
						'key'   => $key,
						'index' => $index,
					);
				}
			}
			++$index;
		}
		return $out;
	}

	/**
	 * A window in seconds (toleranceOf): anything that is not a finite non-negative number falls back to 300.
	 *
	 * @param mixed $value Value.
	 * @return int|float
	 */
	public static function tolerance_of( $value ) {
		if ( ( is_int( $value ) || is_float( $value ) ) && is_finite( (float) $value ) && $value >= 0 ) {
			return $value;
		}
		return self::DEFAULT_TOLERANCE_SEC;
	}

	/**
	 * Verifies a delivery (verifySignature). Never throws. The checks run in the core's order: key presence, raw
	 * body, header presence, header shape, time window, then the HMAC (once per key, compared with `hash_equals`
	 * against every well-formed `v1`, keys in the caller's order first, then `v1` values in header order).
	 *
	 * @param string|null $raw_body      The body exactly as received, before any JSON parsing; null when unavailable.
	 * @param mixed       $header        The X-Qersh-Signature value; anything but a non-blank string counts as missing.
	 * @param array       $keys          Connection keys to try, current first. Trimmed before use.
	 * @param int         $now           Receiver clock in unix seconds.
	 * @param int         $tolerance_sec Window in seconds; a negative value falls back to 300.
	 * @return array `[ 'ok' => true, 't' => int, 'key_index' => int, 'v1_index' => int ]`, where `key_index` is the
	 *               matching key's position in `$keys` and `v1_index` the matching value's position among the
	 *               well-formed `v1` values of the header (the `v1` list of parse_header()); or
	 *               `[ 'ok' => false, 'reason' => string, 't' => int|null ]` with a reason from REASONS.
	 */
	public static function verify( ?string $raw_body, $header, array $keys, int $now, int $tolerance_sec = self::DEFAULT_TOLERANCE_SEC ): array {
		$usable = self::usable_keys( $keys );
		if ( 0 === count( $usable ) ) {
			return self::failure( 'no_key', null );
		}
		if ( null === $raw_body ) {
			return self::failure( 'raw_body_unavailable', null );
		}
		if ( ! is_string( $header ) || '' === self::trim_js( $header ) ) {
			return self::failure( 'missing_header', null );
		}
		$parsed = self::parse_header( $header );
		if ( null === $parsed ) {
			return self::failure( 'malformed_header', null );
		}
		$tolerance = self::tolerance_of( $tolerance_sec );
		if ( abs( $now - $parsed['t'] ) > $tolerance ) {
			return self::failure( 'stale_timestamp', $parsed['t'] );
		}
		$payload  = self::signed_payload( $parsed['t'], $raw_body );
		$expected = array();
		foreach ( $usable as $entry ) {
			$expected[] = array(
				'hex'   => hash_hmac( 'sha256', $payload, $entry['key'] ),
				'index' => $entry['index'],
			);
		}
		foreach ( $expected as $entry ) {
			foreach ( $parsed['v1'] as $position => $candidate ) {
				if ( hash_equals( $entry['hex'], $candidate ) ) {
					return array(
						'ok'        => true,
						't'         => $parsed['t'],
						'key_index' => $entry['index'],
						'v1_index'  => $position,
					);
				}
			}
		}
		return self::failure( 'bad_signature', $parsed['t'] );
	}

	/**
	 * Builds the header value for a body, as the phone does (signatureHeader): one `v1=` per usable key, in the
	 * order given (current first). For tests and the self-test.
	 *
	 * @param int    $t        Unix seconds.
	 * @param string $raw_body The body.
	 * @param array  $keys     Connection keys.
	 * @return string
	 */
	public static function sign_header( int $t, string $raw_body, array $keys ): string {
		$payload = self::signed_payload( $t, $raw_body );
		$values  = array();
		foreach ( self::usable_keys( $keys ) as $entry ) {
			$values[] = 'v1=' . hash_hmac( 'sha256', $payload, $entry['key'] );
		}
		return 't=' . $t . ',' . implode( ',', $values );
	}

	/**
	 * JavaScript String.prototype.trim() on UTF-8 text, without regular expressions (linear on any input).
	 *
	 * @param string $s Text.
	 * @return string
	 */
	public static function trim_js( string $s ): string {
		$start = 0;
		$end   = strlen( $s );
		while ( $start < $end ) {
			$n = self::white_space_length( $s, $start, $end, true );
			if ( 0 === $n ) {
				break;
			}
			$start += $n;
		}
		while ( $end > $start ) {
			$n = self::white_space_length( $s, $start, $end, false );
			if ( 0 === $n ) {
				break;
			}
			$end -= $n;
		}
		return (string) substr( $s, $start, $end - $start );
	}

	/**
	 * True when the text is longer than MAX_HEADER_LENGTH as JavaScript's `text.length` counts it.
	 *
	 * @param string $s Text.
	 * @return bool
	 */
	private static function too_long( string $s ): bool {
		$bytes = strlen( $s );
		if ( $bytes <= self::MAX_HEADER_LENGTH ) {
			return false; // a UTF-16 code unit takes at least one byte
		}
		if ( $bytes > 3 * self::MAX_HEADER_LENGTH ) {
			return true; // and at most three
		}
		return self::js_length( $s ) > self::MAX_HEADER_LENGTH;
	}

	/**
	 * The length JavaScript's `text.length` gives: UTF-16 code units for valid UTF-8, bytes otherwise.
	 *
	 * @param string $s Text.
	 * @return int
	 */
	private static function js_length( string $s ): int {
		$bytes = strlen( $s );
		if ( 1 !== preg_match( '//u', $s ) ) {
			return $bytes;
		}
		$units = $bytes;
		foreach ( count_chars( $s, 1 ) as $byte => $count ) {
			if ( $byte >= 0x80 && $byte <= 0xBF ) {
				$units -= $count; // continuation bytes add no unit
			} elseif ( $byte >= 0xF0 ) {
				$units += $count; // four-byte sequences are a surrogate pair
			}
		}
		return $units;
	}

	/**
	 * The number of bytes of one JavaScript white-space character at the start or at the end of `$s[$start, $end)`.
	 *
	 * @param string $s       Text.
	 * @param int    $start   First byte of the range.
	 * @param int    $end     One past the last byte of the range.
	 * @param bool   $leading True for the start of the range, false for its end.
	 * @return int 0 when the range does not start (or end) with white space.
	 */
	private static function white_space_length( string $s, int $start, int $end, bool $leading ): int {
		$c = ord( $s[ $leading ? $start : $end - 1 ] );
		if ( 0x20 === $c || ( $c >= 0x09 && $c <= 0x0D ) ) {
			return 1;
		}
		if ( $c < 0x80 ) {
			return 0;
		}
		foreach ( array( 2, 3 ) as $n ) {
			if ( $end - $start < $n ) {
				break;
			}
			$piece = substr( $s, $leading ? $start : $end - $n, $n );
			if ( in_array( $piece, self::WS_MULTIBYTE, true ) ) {
				return $n;
			}
		}
		return 0;
	}

	/**
	 * A refusal.
	 *
	 * @param string   $reason One of REASONS.
	 * @param int|null $t      The header's time, once it parsed.
	 * @return array
	 */
	private static function failure( string $reason, ?int $t ): array {
		return array(
			'ok'     => false,
			'reason' => $reason,
			't'      => $t,
		);
	}
}
