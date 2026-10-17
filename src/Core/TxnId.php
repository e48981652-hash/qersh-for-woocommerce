<?php
/**
 * The provider transaction id a customer types or pastes on the order page (docs/17 §4.10), and its canonical form.
 *
 * @package QershPay\WooCommerce
 */

declare( strict_types=1 );

namespace QershPay\WooCommerce\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Two pure functions: canonical_typed() reads the id out of what a customer wrote (the id alone, or the whole
 * wallet SMS pasted), canonical_stored() checks what the phone delivered. The shapes equal
 * fixtures/txn-patterns.json (scripts/check-fixtures.mjs compares the text). The JavaScript normaliser of the order
 * page is a copy of canonical_typed(); both read tests/vectors/txn-normalize.json.
 */
final class TxnId {

	/**
	 * Providers whose customers may type a transaction id (0.1.0: Orange Cash only).
	 */
	const CLAIM_PROVIDERS = array( 'orange_cash' );

	/**
	 * The shape of a transaction id, per provider. Orange Cash: 14 digits today, 10 in the older wording.
	 */
	const PATTERNS = array( 'orange_cash' => '^(?:[0-9]{14}|[0-9]{10})$' );

	/**
	 * The lengths a run of the shape's characters must have to count as a candidate id.
	 */
	const LENGTHS = array( 'orange_cash' => array( 14, 10 ) );

	/**
	 * The character class of the shape, per provider (the `class` of fixtures/txn-patterns.json).
	 */
	const CLASSES = array( 'orange_cash' => '[0-9]' );

	/**
	 * The longest input accepted, in UTF-16 code units (the input's maxlength).
	 */
	const MAX_LENGTH = 400;

	/**
	 * Characters removed before reading, as ArabicNormalizer.kt drops them: zero-width, LRM/RLM, bidi embeddings,
	 * overrides and isolates, BOM, soft hyphen, tatweel, the Arabic letter mark, tashkeel and the superscript alef.
	 */
	const DROPPED = '/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}\x{00AD}\x{0640}\x{061C}\x{064B}-\x{0652}\x{0670}]/u';

	/**
	 * Characters ArabicNormalizer.kt reads as a space (Java isWhitespace or isSpaceChar), except the line breaks
	 * below: they become a space.
	 */
	const SPACES = '/[\x{0009}\x{0020}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{202F}\x{205F}\x{3000}]/u';

	/**
	 * The rest of ArabicNormalizer.kt's white space: line and paragraph breaks (LF, VT, FF, CR, U+001C to U+001F,
	 * U+2028, U+2029). They become "\n", so that step 5 never joins digits across lines.
	 */
	const LINE_BREAKS = '/[\x{000A}-\x{000D}\x{001C}-\x{001F}\x{2028}\x{2029}]/u';

	/**
	 * Separators removed by the compact attempt (step 4), after the mappings below. The same characters, one at a
	 * time, join digit runs on one line in step 5.
	 */
	const SEPARATORS = array( ' ', '-', '.', '#', ':' );

	/**
	 * Read the id out of what a customer typed or pasted (docs/17 §4.10 steps 1-7):
	 * 1. more than 400 characters: qersh_txn_too_long;
	 * 2. Arabic-Indic and Persian digits become Latin;
	 * 3. bidi, invisible marks and tatweel are removed (ArabicNormalizer.kt), white space becomes a space (a line
	 *    break becomes "\n"), and the Arabic and full-width separators become their Latin forms;
	 * 4. the compact value (without white space, `-`, `.`, `#`, `:`) is the id when it has the shape;
	 * 5. otherwise the numbers of the text are read. A number is a group of runs of the shape's characters, each
	 *    run separated from the next by exactly one of ` `, `-`, `.`, `#`, `:` on the same line. When the group has
	 *    a separator and its runs joined have one of LENGTHS, the joined value is the one candidate of the group
	 *    (an id split 10 + 4, «رقم المعاملة 2500517294 4183», is never read as its first 10 digits, docs/17 §4.10);
	 *    otherwise each run of the group is a candidate. A candidate that Payer::normalize() reads as the order's
	 *    payer key or a store wallet number, or that is a mobile number written as 0020 1xxxxxxxxx, is left out
	 *    before counting; a candidate counts only when its length is one of LENGTHS (equal ones count once);
	 * 6. no run: qersh_txn_format (qersh_txn_wallet_number when only wallet numbers were left out); more than one:
	 *    qersh_txn_several. A compact value that normalises to the payer key or a store number, or that is a
	 *    mobile number in a form a transaction id never has, is qersh_txn_wallet_number;
	 * 7. leading zeros are kept: the id is text.
	 *
	 * @param string      $provider      Provider id of the order's wallet.
	 * @param mixed       $raw           What the customer sent.
	 * @param string|null $payer_key     The order's payer key.
	 * @param array       $store_numbers The store's wallet numbers (receive addresses).
	 * @return array `[ 'ok' => true, 'txn' => string ]` or `[ 'ok' => false, 'error' => string ]`.
	 */
	public static function canonical_typed( string $provider, $raw, ?string $payer_key, array $store_numbers ): array {
		if ( null === $raw ) {
			return self::error( 'qersh_txn_empty' );
		}
		if ( ! is_string( $raw ) ) {
			return self::error( 'qersh_txn_format' ); // A JSON number would lose leading zeros, and digits on 32-bit PHP.
		}
		if ( strlen( $raw ) > 4 * self::MAX_LENGTH ) {
			return self::error( 'qersh_txn_too_long' );
		}
		$text = Config::to_valid_utf8( $raw );
		if ( Config::utf16_length( $text ) > self::MAX_LENGTH ) {
			return self::error( 'qersh_txn_too_long' );
		}

		$text    = self::normalize_text( $text );
		$compact = str_replace( array_merge( self::SEPARATORS, array( "\n" ) ), '', $text );
		if ( '' === $compact ) {
			return self::error( 'qersh_txn_empty' );
		}
		// LENGTHS and CLASSES share the keys of PATTERNS (TxnIdTest checks all three against the fixture).
		if ( ! array_key_exists( $provider, self::PATTERNS ) ) {
			return self::error( 'qersh_txn_format' );
		}

		$own   = self::own_numbers( $payer_key, $store_numbers );
		$shape = self::matches( $provider, $compact );
		if ( self::is_own( $compact, $own ) || self::is_phone( $compact, $shape ) ) {
			return self::error( 'qersh_txn_wallet_number' );
		}
		if ( $shape ) {
			return array(
				'ok'  => true,
				'txn' => $compact,
			);
		}

		$candidates = array();
		$excluded   = 0;
		foreach ( self::numbers( $provider, $text ) as $value ) {
			if ( ! in_array( strlen( $value ), self::LENGTHS[ $provider ], true ) ) {
				continue;
			}
			if ( self::is_own( $value, $own ) || self::is_phone( $value, true ) ) {
				++$excluded;
				continue;
			}
			if ( ! in_array( $value, $candidates, true ) ) {
				$candidates[] = $value;
			}
		}
		if ( 1 === count( $candidates ) ) {
			return array(
				'ok'  => true,
				'txn' => $candidates[0],
			);
		}
		if ( count( $candidates ) > 1 ) {
			return self::error( 'qersh_txn_several' );
		}
		return self::error( $excluded > 0 ? 'qersh_txn_wallet_number' : 'qersh_txn_format' );
	}

	/**
	 * The canonical form of an id delivered by the phone (`provider_txn_id`): trimmed, or null when it does not
	 * have the provider's shape (it is then stored as it came and never equals an id a customer typed).
	 *
	 * @param string $provider Provider id.
	 * @param mixed  $value    Delivered value.
	 * @return string|null
	 */
	public static function canonical_stored( string $provider, $value ): ?string {
		if ( ! is_string( $value ) || ! array_key_exists( $provider, self::PATTERNS ) ) {
			return null;
		}
		$v = Config::js_trim( $value );
		return self::matches( $provider, $v ) ? $v : null;
	}

	/**
	 * Steps 2 and 3: digits to Latin, marks removed, line breaks to "\n", other white space to a space, and
	 * separators mapped.
	 *
	 * @param string $text Valid UTF-8.
	 * @return string
	 */
	private static function normalize_text( string $text ): string {
		$map = array(
			"\u{066B}" => '.', // Arabic decimal separator.
			"\u{066C}" => ',', // Arabic thousands separator.
			"\u{060C}" => ',', // Arabic comma.
			"\u{061B}" => ';', // Arabic semicolon.
			"\u{061F}" => '?', // Arabic question mark.
			"\u{066A}" => '%', // Arabic percent sign.
			"\u{FF1A}" => ':', // Full-width colon.
			"\u{FF0C}" => ',', // Full-width comma.
			"\u{FF0E}" => '.', // Full-width full stop.
			"\u{FF03}" => '#', // Full-width number sign.
			"\u{FF0D}" => '-', // Full-width hyphen-minus.
			"\u{2010}" => '-', // Hyphen.
			"\u{2011}" => '-', // Non-breaking hyphen.
			"\u{2012}" => '-', // Figure dash.
			"\u{2013}" => '-', // En dash.
			"\u{2014}" => '-', // Em dash.
			"\u{2212}" => '-', // Minus sign.
		);
		for ( $d = 0; $d <= 9; $d++ ) {
			$map[ self::utf8( 0x0660 + $d ) ] = (string) $d; // Arabic-Indic.
			$map[ self::utf8( 0x06F0 + $d ) ] = (string) $d; // Extended Arabic-Indic (Persian).
		}
		$text   = strtr( $text, $map );
		$text   = (string) preg_replace( self::DROPPED, '', $text );
		$lined  = preg_replace( self::LINE_BREAKS, "\n", $text );
		$text   = null === $lined ? $text : $lined;
		$spaced = preg_replace( self::SPACES, ' ', $text );
		return null === $spaced ? $text : $spaced;
	}

	/**
	 * Step 5: the candidate values of the text, in order. Each group of runs of the shape's characters joined by
	 * one separator on the same line gives its joined value when that has one of LENGTHS, else each of its runs.
	 *
	 * @param string $provider Provider id (with an entry in CLASSES and LENGTHS).
	 * @param string $text     Text from normalize_text().
	 * @return string[]
	 */
	private static function numbers( string $provider, string $text ): array {
		$class     = self::CLASSES[ $provider ];
		$separator = '[' . preg_quote( implode( '', self::SEPARATORS ), '/' ) . ']';
		$values    = array();
		preg_match_all( '/' . $class . '+(?:' . $separator . $class . '+)*/u', $text, $groups );
		foreach ( $groups[0] as $group ) {
			$group  = (string) $group;
			$joined = str_replace( self::SEPARATORS, '', $group );
			if ( $joined !== $group && in_array( strlen( $joined ), self::LENGTHS[ $provider ], true ) ) {
				$values[] = $joined;
				continue;
			}
			preg_match_all( '/' . $class . '+/u', $group, $runs );
			foreach ( $runs[0] as $run ) {
				$values[] = (string) $run;
			}
		}
		return $values;
	}

	/**
	 * Whether a value has the provider's shape.
	 *
	 * @param string $provider Provider id.
	 * @param string $value    Value.
	 * @return bool
	 */
	private static function matches( string $provider, string $value ): bool {
		return 1 === preg_match( '/' . self::PATTERNS[ $provider ] . '/D', $value );
	}

	/**
	 * The normalised payer key and store numbers, as a set.
	 *
	 * @param string|null $payer_key     Payer key.
	 * @param array       $store_numbers Store numbers.
	 * @return array<string, bool>
	 */
	private static function own_numbers( ?string $payer_key, array $store_numbers ): array {
		$own = array();
		foreach ( array_merge( array( $payer_key ), array_values( $store_numbers ) ) as $number ) {
			$normalized = is_string( $number ) ? Payer::normalize( $number ) : null;
			if ( null !== $normalized ) {
				$own[ $normalized ] = true;
			}
		}
		return $own;
	}

	/**
	 * Whether a value normalises to the payer key or a store number.
	 *
	 * @param string $value Value.
	 * @param array  $own   Set from own_numbers().
	 * @return bool
	 */
	private static function is_own( string $value, array $own ): bool {
		$normalized = Payer::normalize( $value );
		return null !== $normalized && isset( $own[ $normalized ] );
	}

	/**
	 * Whether digits are a mobile number in a form a transaction id never has: written with the international
	 * prefix 0020 (the sender in Orange's «من 0020…» wording), or, when the value lacks the id's shape, any number
	 * Payer::normalize() reads as a mobile number (01xxxxxxxxx, +20 1xxxxxxxxx).
	 *
	 * @param string $value      Value.
	 * @param bool   $has_shape  Whether the value has the provider's shape.
	 * @return bool
	 */
	private static function is_phone( string $value, bool $has_shape ): bool {
		if ( 1 !== preg_match( '/^\+?[0-9]+$/D', $value ) ) {
			return false;
		}
		$normalized = Payer::normalize( $value );
		if ( null === $normalized || false !== strpos( $normalized, '@' ) ) {
			return false;
		}
		return ! $has_shape || 1 === preg_match( '/^(?:\+|00)/', $value );
	}

	/**
	 * One code point of the Basic Multilingual Plane (from U+0080) as UTF-8.
	 *
	 * @param int $code Code point, 0x80 to 0xFFFF.
	 * @return string
	 */
	private static function utf8( int $code ): string {
		if ( $code < 0x800 ) {
			return chr( 0xC0 | ( $code >> 6 ) ) . chr( 0x80 | ( $code & 0x3F ) );
		}
		return chr( 0xE0 | ( $code >> 12 ) ) . chr( 0x80 | ( ( $code >> 6 ) & 0x3F ) ) . chr( 0x80 | ( $code & 0x3F ) );
	}

	/**
	 * A refusal.
	 *
	 * @param string $code Error code.
	 * @return array
	 */
	private static function error( string $code ): array {
		return array(
			'ok'    => false,
			'error' => $code,
		);
	}
}
