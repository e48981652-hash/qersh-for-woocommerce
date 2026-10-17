<?php
/**
 * The qersh.v1 envelope, as the core (envelope.ts in packages/qersh-webhook) parses and summarises it.
 *
 * @package QershPay\WooCommerce
 */

declare( strict_types=1 );

namespace QershPay\WooCommerce\Core;

defined( 'ABSPATH' ) || exit;

/**
 * A literal port of envelope.ts. Pure PHP.
 *
 * Parsing works on the raw body (never on a re-serialised object). Field names follow kit/webhook.v1.schema.json;
 * readers ignore unknown fields. The parsed envelope is a PHP array (JSON objects become associative arrays), so a
 * "record" here is an array that is not a non-empty list: after decoding, PHP cannot tell `{}` from `[]`, and both
 * count as an empty record. parse() checks the JSON types of the top level, `wallet` and `data` before that
 * information is lost, so summarize() takes any array `data` as the object it was.
 *
 * Accepted differences from the core, none of which the phone sends (its lists are JSON arrays, its records JSON
 * objects, its bodies about 4 levels deep):
 * - Deeper inside `data`, a JSON object whose names are "0".."n-1" reads as a list, and `[]` as an empty record. So
 *   `risk_reasons` sent as `{"0":"suspected_forgery"}` counts as a forgery verdict (the core ignores it: PHP errs on
 *   the side that stops the order), and a webhook.test `sample` sent as `[]` gives a summary of nulls (the core null).
 * - A JSON.parse string can hold a lone surrogate; a PHP string cannot, so it holds U+FFFD there, the UTF-8 form of
 *   that string. Names of one object that become equal this way are one name here (the last wins).
 * - PHP's JSON parser has a stack of its own and stops near 5000 nested arrays or 2500 nested objects (measured on
 *   PHP 8.3, with a syntax error). A body nested deeper is invalid_json here, where the core parses it.
 */
final class Envelope {

	/** The only schema this receiver reads. */
	const SCHEMA = 'qersh.v1';

	/**
	 * The nesting json_decode is allowed: above what PHP's parser can reach, so its own stack is the only bound
	 * (JSON.parse has none). Code that re-encodes part of a parsed envelope must not assume 512 levels at most.
	 */
	const MAX_DEPTH = 10000;

	/**
	 * One escape of a JSON text, matched left to right so an escaped backslash is never read as the start of an
	 * escape: a surrogate pair (kept), a lone surrogate (group 1), `\u0000` (group 2) or any other escape (kept).
	 * Outside strings a backslash is a syntax error, and stays one.
	 */
	const ESCAPE = '/\\\\(?:u[dD][89abAB][0-9a-fA-F]{2}\\\\u[dD][c-fC-F][0-9a-fA-F]{2}|(u[dD][89a-fA-F][0-9a-fA-F]{2})|(u0000)|[\s\S])/';

	/** U+FFFD, the replacement character, in UTF-8. */
	const REPLACEMENT = "\xEF\xBF\xBD";

	/** The UTF-8 byte order mark. */
	const BOM = "\xEF\xBB\xBF";

	/** JavaScript's `\s` as a PCRE class for UTF-8 subjects. */
	const WS_CLASS_UTF8 = '[\t\n\x0B\f\r \x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]';

	/** The ASCII part of it, for subjects that are not valid UTF-8. */
	const WS_CLASS_BYTES = '[\t\n\x0B\f\r ]';

	/**
	 * Parses the raw body into an envelope (parseEnvelope, on bytes). Call it only after the signature verified.
	 * Requires the fields every qersh.v1 delivery carries (`schema`, `event`, `delivery_id`, `created_at`, `wallet`
	 * with string `id` and `provider`, and `data`), with their JSON types. Never throws.
	 *
	 * Like the core on bytes:
	 * - a leading byte order mark is dropped by the UTF-8 decoding (TextDecoder) and one more U+FEFF by parseEnvelope;
	 * - the bytes are then decoded as TextDecoder does, each maximal invalid subpart becoming one U+FFFD, so invalid
	 *   UTF-8 inside a string becomes U+FFFD and outside strings stays a syntax error;
	 * - a lone surrogate escape such as `\ud800`, which JSON.parse accepts, is read as U+FFFD.
	 *
	 * @param string|null $raw The body exactly as received; null when unavailable.
	 * @return array `[ 'ok' => true, 'envelope' => array ]` or `[ 'ok' => false, 'reason' => 'invalid_json'|'not_an_object'|'not_qersh_v1' ]`.
	 */
	public static function parse( ?string $raw ): array {
		if ( null === $raw ) {
			return self::refusal( 'invalid_json' );
		}
		$text = $raw;
		for ( $i = 0; $i < 2; $i++ ) {
			if ( 0 === strncmp( $text, self::BOM, 3 ) ) {
				$text = (string) substr( $text, 3 );
			}
		}
		$text = self::rewrite_escapes( self::well_formed_utf8( $text ), false );
		// Decoded once as objects, so JSON objects and arrays stay apart for the shape checks.
		$value = json_decode( $text, false, self::MAX_DEPTH );
		if ( JSON_ERROR_INVALID_PROPERTY_NAME === json_last_error() ) {
			// A PHP object cannot have a property name that starts with NUL; JSON.parse allows one. The shape checks
			// read no such name, so this decoding reads `\u0000` as U+0001. The envelope keeps the real names.
			$value = json_decode( self::rewrite_escapes( $text, true ), false, self::MAX_DEPTH );
		}
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return self::refusal( 'invalid_json' );
		}
		if ( ! ( $value instanceof \stdClass ) ) {
			return self::refusal( 'not_an_object' );
		}
		$fields = get_object_vars( $value );
		$wallet = self::field( $fields, 'wallet' );
		$wallet = $wallet instanceof \stdClass ? get_object_vars( $wallet ) : null;
		if (
			self::SCHEMA !== self::field( $fields, 'schema' ) ||
			! is_string( self::field( $fields, 'event' ) ) ||
			! is_string( self::field( $fields, 'delivery_id' ) ) ||
			! is_string( self::field( $fields, 'created_at' ) ) ||
			null === $wallet ||
			! is_string( self::field( $wallet, 'id' ) ) ||
			! is_string( self::field( $wallet, 'provider' ) ) ||
			! ( self::field( $fields, 'data' ) instanceof \stdClass )
		) {
			return self::refusal( 'not_qersh_v1' );
		}
		$envelope = json_decode( $text, true, self::MAX_DEPTH );
		if ( ! is_array( $envelope ) ) {
			return self::refusal( 'invalid_json' ); // Cannot happen: the same text decoded above.
		}
		return array(
			'ok'       => true,
			'envelope' => $envelope,
		);
	}

	/**
	 * Reads the `X-Qersh-*` headers (deliveryHeaders). Names are matched case-insensitively, and `-` and `_` count as
	 * the same character, so both `x-qersh-delivery` and WordPress's `x_qersh_delivery` are found. A value may be a
	 * string or a list whose first element is a string (WP_REST_Request::get_headers()). Never throws.
	 *
	 * @param array $headers Header names to values.
	 * @return array{delivery: string|null, attempt: int|null, redelivery: string|null, wallet: string|null, signature_t: int|null}
	 */
	public static function delivery_headers( array $headers ): array {
		$attempt_text = self::header( $headers, 'x-qersh-attempt' );
		$redelivery   = self::header( $headers, 'x-qersh-redelivery' );
		$signature    = self::header( $headers, 'x-qersh-signature' );
		$t_text       = null;
		if ( null !== $signature && '' !== $signature ) {
			$found = preg_match( '/(?:^|,)' . self::WS_CLASS_UTF8 . '*t=([0-9]+)' . self::WS_CLASS_UTF8 . '*(?:,|\z)/u', $signature, $m );
			if ( false === $found ) {
				$found = preg_match( '/(?:^|,)' . self::WS_CLASS_BYTES . '*t=([0-9]+)' . self::WS_CLASS_BYTES . '*(?:,|\z)/', $signature, $m );
			}
			if ( 1 === $found ) {
				$t_text = $m[1];
			}
		}
		return array(
			'delivery'    => self::header( $headers, 'x-qersh-delivery' ),
			'attempt'     => null !== $attempt_text && 1 === preg_match( '/^[0-9]{1,9}\z/', $attempt_text ) ? (int) $attempt_text : null,
			'redelivery'  => 'manual' === $redelivery || 'backfill' === $redelivery ? $redelivery : null,
			'wallet'      => self::header( $headers, 'x-qersh-wallet' ),
			'signature_t' => null !== $t_text && strlen( $t_text ) <= 13 ? (int) $t_text : null,
		);
	}

	/**
	 * True when a transfer or an update carries the forgery verdict of the balance check (isSuspectedForgery).
	 *
	 * @param mixed $data `data` of the envelope.
	 * @return bool
	 */
	public static function is_suspected_forgery( $data ): bool {
		return self::is_record( $data ) && self::forgery_verdict( $data );
	}

	/**
	 * Summarises `data` of a transfer event, of webhook.test's sample, or of transfer.updated, where most fields are
	 * then null (summarizeTransfer). Null when `data` is not a record.
	 *
	 * @param mixed $data The data.
	 * @return array|null The contract's names plus `payer.key` and `suspected_forgery`.
	 */
	public static function summarize_transfer( $data ): ?array {
		return self::is_record( $data ) ? self::transfer_summary( $data ) : null;
	}

	/**
	 * Builds the normalised item from a parsed envelope (summarize): one item per delivery, as the n8n trigger emits
	 * it. `transfer` is present for transfer.* events and for webhook.test (from `data.sample`), null otherwise.
	 *
	 * As parse() has checked that `data` is a JSON object, any array `data` is taken as one, even with the names
	 * "0".."n-1"; anything else (in an array built by hand) becomes an empty record, as in the core.
	 *
	 * @param array       $envelope    A parsed envelope (parse()['envelope']).
	 * @param array|null  $headers     The result of delivery_headers(), or null.
	 * @param string|null $webhook_url The receiver's production URL (never with a key), or null.
	 * @return array Keys: event, delivery_id, attempt, redelivery, test, created_at, wallet, transfer, data, envelope, webhook_url.
	 */
	public static function summarize( array $envelope, ?array $headers = null, ?string $webhook_url = null ): array {
		$event    = self::field( $envelope, 'event' );
		$event    = is_string( $event ) ? $event : '';
		$data     = self::field( $envelope, 'data' );
		$data     = is_array( $data ) ? $data : array();
		$transfer = null;
		if ( 0 === strpos( $event, 'transfer.' ) ) {
			$transfer = self::transfer_summary( $data );
		} elseif ( 'webhook.test' === $event ) {
			$sample   = self::field( $data, 'sample' );
			$transfer = self::is_record( $sample ) ? self::transfer_summary( $sample ) : null;
		}
		return array(
			'event'       => $event,
			'delivery_id' => self::field( $envelope, 'delivery_id' ),
			'attempt'     => null === $headers ? null : self::field( $headers, 'attempt' ),
			'redelivery'  => null === $headers ? null : self::field( $headers, 'redelivery' ),
			'test'        => true === self::field( $envelope, 'test' ) || 'webhook.test' === $event,
			'created_at'  => self::field( $envelope, 'created_at' ),
			'wallet'      => self::field( $envelope, 'wallet' ),
			'transfer'    => $transfer,
			'data'        => $data,
			'envelope'    => $envelope,
			'webhook_url' => null !== $webhook_url && '' !== $webhook_url ? $webhook_url : null,
		);
	}

	/**
	 * A JSON object after decoding: an array that is not a non-empty list.
	 *
	 * @param mixed $v Value.
	 * @return bool
	 */
	public static function is_record( $v ): bool {
		return is_array( $v ) && ( array() === $v || ! self::is_list( $v ) );
	}

	/**
	 * A JSON array after decoding: an array with the keys 0..n-1 in order (the empty array included).
	 *
	 * @param mixed $v Value.
	 * @return bool
	 */
	public static function is_list( $v ): bool {
		if ( ! is_array( $v ) ) {
			return false;
		}
		$expected = 0;
		foreach ( $v as $key => $unused ) {
			if ( $key !== $expected ) {
				return false;
			}
			++$expected;
		}
		return true;
	}

	/**
	 * The transfer summary of a decoded JSON object (the body of summarizeTransfer).
	 *
	 * @param array $data The object.
	 * @return array
	 */
	private static function transfer_summary( array $data ): array {
		$cp     = self::field( $data, 'counterparty' );
		$cp     = self::is_record( $cp ) ? $cp : null;
		$checks = self::field( $data, 'checks' );
		$checks = self::is_record( $checks ) ? $checks : array();
		$risk   = self::field( $data, 'risk_reasons' );
		return array(
			'transfer_id'       => self::str_of( self::field( $data, 'transfer_id' ) ),
			'direction'         => self::str_of( self::field( $data, 'direction' ) ),
			'status'            => self::str_of( self::field( $data, 'status' ) ),
			'amount'            => self::str_of( self::field( $data, 'amount' ) ),
			'amount_minor'      => self::int_of( self::field( $data, 'amount_minor' ) ),
			'currency'          => self::str_of( self::field( $data, 'currency' ) ),
			'received_at'       => self::str_of( self::field( $data, 'received_at' ) ),
			'payer'             => array(
				'number' => null === $cp ? null : self::str_of( self::field( $cp, 'number' ) ),
				'handle' => null === $cp ? null : self::str_of( self::field( $cp, 'handle' ) ),
				'name'   => null === $cp ? null : self::str_of( self::field( $cp, 'name' ) ),
				'key'    => Payer::key_of( $cp ),
			),
			'provider_txn_id'   => self::str_of( self::field( $data, 'provider_txn_id' ) ),
			'balance_after'     => self::str_of( self::field( $data, 'balance_after' ) ),
			'risk'              => self::str_of( self::field( $data, 'risk' ) ),
			'risk_reasons'      => self::is_list( $risk ) ? array_values( array_filter( $risk, 'is_string' ) ) : array(),
			'auto_confirm_ok'   => self::bool_of( self::field( $data, 'auto_confirm_ok' ) ),
			'balance_chain'     => self::str_of( self::field( $checks, 'balance_chain' ) ),
			'suspected_forgery' => self::forgery_verdict( $data ),
		);
	}

	/**
	 * The forgery verdict of a decoded JSON object (the body of isSuspectedForgery).
	 *
	 * @param array $data The object.
	 * @return bool
	 */
	private static function forgery_verdict( array $data ): bool {
		$reasons = self::field( $data, 'risk_reasons' );
		$reasons = self::is_list( $reasons ) ? $reasons : array();
		$checks  = self::field( $data, 'checks' );
		$checks  = self::is_record( $checks ) ? $checks : array();
		return in_array( 'suspected_forgery', $reasons, true ) || 'refuted' === self::field( $checks, 'balance_chain' );
	}

	/**
	 * The text TextDecoder (WHATWG UTF-8, not fatal) makes of these bytes: valid UTF-8 stays as it is, and each
	 * maximal subpart of an invalid sequence becomes one U+FFFD. So `F0 9F 98` is one U+FFFD, `C0 80` two.
	 *
	 * @param string $bytes Bytes.
	 * @return string Valid UTF-8.
	 */
	private static function well_formed_utf8( string $bytes ): string {
		if ( 1 === preg_match( '//u', $bytes ) ) {
			return $bytes;
		}
		$out = '';
		$n   = strlen( $bytes );
		$i   = 0;
		while ( $i < $n ) {
			$b = ord( $bytes[ $i ] );
			if ( $b < 0x80 ) {
				$out .= $bytes[ $i ];
				++$i;
				continue;
			}
			// How many bytes follow the lead byte, and the range of the first of them (the others are 0x80..0xBF).
			$need = 0;
			$lo   = 0x80;
			$hi   = 0xBF;
			if ( $b >= 0xC2 && $b <= 0xDF ) {
				$need = 1;
			} elseif ( 0xE0 === $b ) {
				$need = 2;
				$lo   = 0xA0;
			} elseif ( 0xED === $b ) {
				$need = 2;
				$hi   = 0x9F;
			} elseif ( $b >= 0xE1 && $b <= 0xEF ) {
				$need = 2;
			} elseif ( 0xF0 === $b ) {
				$need = 3;
				$lo   = 0x90;
			} elseif ( 0xF4 === $b ) {
				$need = 3;
				$hi   = 0x8F;
			} elseif ( $b >= 0xF1 && $b <= 0xF3 ) {
				$need = 3;
			}
			$j = $i + 1;
			for ( $k = 0; $k < $need && $j < $n; $k++ ) {
				$c = ord( $bytes[ $j ] );
				if ( $c < ( 0 === $k ? $lo : 0x80 ) || $c > ( 0 === $k ? $hi : 0xBF ) ) {
					break;
				}
				++$j;
			}
			// A complete sequence is kept; anything else (a bad lead byte, a cut sequence) is one U+FFFD, and the
			// byte that broke the sequence is read again as the start of the next one.
			$out .= $need > 0 && $j - $i === $need + 1 ? substr( $bytes, $i, $j - $i ) : self::REPLACEMENT;
			$i    = $j;
		}
		return $out;
	}

	/**
	 * Rewrites the escapes of a JSON text that PHP's decoder refuses and JSON.parse accepts: a lone surrogate
	 * becomes `�`, and with `$nul` also `\u0000` becomes `\u0001`. Only hexadecimal digits change, so a text
	 * that is not JSON stays one.
	 *
	 * @param string $text Valid UTF-8.
	 * @param bool   $nul  Whether to rewrite `\u0000` too (for the decoding into objects that only the shape checks read).
	 * @return string
	 */
	private static function rewrite_escapes( string $text, bool $nul ): string {
		if ( false === strpos( $text, '\\u' ) ) {
			return $text;
		}
		$out = preg_replace_callback(
			self::ESCAPE,
			static function ( array $m ) use ( $nul ): string {
				if ( isset( $m[1] ) && '' !== $m[1] ) {
					return '\\ufffd';
				}
				if ( $nul && isset( $m[2] ) ) { // The last group is in $m only when it matched.
					return '\\u0001';
				}
				return $m[0];
			},
			$text
		);
		return null === $out ? $text : $out;
	}

	/**
	 * One header value (the `get` of deliveryHeaders): the exact name or its `_` form first, then any name that is
	 * the same ignoring case and `-`/`_`. A list gives its first element when that is a string.
	 *
	 * @param array  $source Header names to values.
	 * @param string $name   Lower-case name with dashes.
	 * @return string|null
	 */
	private static function header( array $source, string $name ): ?string {
		$wanted = str_replace( '-', '_', $name );
		$raw    = null;
		foreach ( array( $name, $wanted ) as $candidate ) {
			if ( isset( $source[ $candidate ] ) ) {
				$raw = $source[ $candidate ];
				break;
			}
		}
		if ( null === $raw ) {
			foreach ( $source as $key => $value ) {
				if ( str_replace( '-', '_', strtolower( (string) $key ) ) === $wanted ) {
					$raw = $value;
					break;
				}
			}
		}
		if ( is_array( $raw ) ) {
			return array_key_exists( 0, $raw ) && is_string( $raw[0] ) ? $raw[0] : null;
		}
		return is_string( $raw ) ? $raw : null;
	}

	/**
	 * A field of a decoded object, or null when absent.
	 *
	 * @param array  $record The object.
	 * @param string $name   Field name.
	 * @return mixed
	 */
	private static function field( array $record, string $name ) {
		return array_key_exists( $name, $record ) ? $record[ $name ] : null;
	}

	/**
	 * The value when it is a string, else null.
	 *
	 * @param mixed $v Value.
	 * @return string|null
	 */
	private static function str_of( $v ): ?string {
		return is_string( $v ) ? $v : null;
	}

	/**
	 * The value when it is an integer (Number.isInteger: a whole float such as 15007.0 counts), else null. A whole
	 * float outside PHP's integer range gives null.
	 *
	 * @param mixed $v Value.
	 * @return int|null
	 */
	private static function int_of( $v ): ?int {
		if ( is_int( $v ) ) {
			return $v;
		}
		$limit = -( (float) PHP_INT_MIN );
		if ( is_float( $v ) && is_finite( $v ) && floor( $v ) === $v && $v >= -$limit && $v < $limit ) {
			return (int) $v;
		}
		return null;
	}

	/**
	 * The value when it is a boolean, else null.
	 *
	 * @param mixed $v Value.
	 * @return bool|null
	 */
	private static function bool_of( $v ): ?bool {
		return is_bool( $v ) ? $v : null;
	}

	/**
	 * A parse refusal.
	 *
	 * @param string $reason Reason.
	 * @return array
	 */
	private static function refusal( string $reason ): array {
		return array(
			'ok'     => false,
			'reason' => $reason,
		);
	}
}
