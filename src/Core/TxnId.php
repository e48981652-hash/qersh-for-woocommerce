<?php
/**
 * The transaction id a customer types or pastes on the order page (docs/17 §4.10), and its canonical form.
 *
 * @package QershPay\WooCommerce
 */

declare( strict_types=1 );

namespace QershPay\WooCommerce\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Pure functions, the same for every e-wallet: the plugin never knows a wallet's type, so it never knows the shape
 * of its ids either (the Qersh app checks them against its template pack and leaves `provider_txn_id` null when one
 * fails). An id is compared by exact equality of one canonical form: canonical_typed() reads it out of what a
 * customer wrote (the id alone, or the whole wallet message pasted), canonical_stored() reads what the phone
 * delivered. The JavaScript normaliser of the order page (assets/js/txn-normalize.js canonicalTyped()) is a copy
 * of canonical_typed(); both read tests/vectors/txn-normalize.json.
 */
final class TxnId {

	/**
	 * The shortest canonical id.
	 */
	const MIN_ID = 6;

	/**
	 * The longest canonical id.
	 */
	const MAX_ID = 40;

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
	 * U+2028, U+2029). They become "\n", so that a message's numbers are never joined across lines.
	 */
	const LINE_BREAKS = '/[\x{000A}-\x{000D}\x{001C}-\x{001F}\x{2028}\x{2029}]/u';

	/**
	 * Separators, after the mappings of normalize_text(): an id typed alone may be split by any of them, and one of
	 * them, alone between two runs on a line of a message, joins the runs into one number.
	 */
	const SEPARATORS = array( ' ', '-', '.', '#', ':' );

	/**
	 * What a message holds that is never an id, replaced by a line break before its numbers are read, in this order:
	 * links (their short codes mix letters and digits), dates (dd-mm-yy, dd/mm/yyyy, mm/dd/yyyy, yyyy-mm-dd), times
	 * (hh:mm and hh:mm:ss; a following AM, PM, ص or م is a word, which never counts), amounts with decimals (150.00,
	 * 150,5) and numbers followed by a currency (100 جنيه, 100 جنية, 100 ج.م, 150 EGP, 150 LE). A date has a day of 1
	 * to 31 and a month of 1 to 12, a time an hour of 0 to 23 and minutes and seconds of 0 to 59: digits that are no
	 * date or time («91-33-72 004518», an id typed with mixed separators) are never cut from the id. Each pattern
	 * keeps the character before the match (group 1), so that it is never the middle of a longer number: the date
	 * and time patterns never start after a digit, the amount pattern never after a digit or a dot (an id split by
	 * dots stays whole). ASCII and the Arabic letters of the currency words only, without look-behind (older Safari
	 * has none), so that the JavaScript twin has the same patterns.
	 */
	const BREAKS = array(
		'/()(?:[Hh][Tt][Tt][Pp][Ss]?:\/\/|[Ww][Ww][Ww]\.)[^ \n]*/u',
		'/(^|[^0-9])(?:[0-9]{4}-(?:0?[1-9]|1[0-2])-(?:0?[1-9]|[12][0-9]|3[01])|(?:(?:0?[1-9]|[12][0-9]|3[01])[\/-](?:0?[1-9]|1[0-2])|(?:0?[1-9]|1[0-2])[\/-](?:0?[1-9]|[12][0-9]|3[01]))[\/-][0-9]{2,4})(?![0-9])/u',
		'/(^|[^0-9])(?:[01]?[0-9]|2[0-3]):[0-5][0-9](?::[0-5][0-9])?(?![0-9])/u',
		'/(^|[^0-9.])[0-9]+[.,][0-9]{1,2}(?![0-9])/u',
		'/(^|[^0-9])[0-9]+(?= ?(?:\x{062C}\x{0646}\x{064A}[\x{0647}\x{0629}]|\x{062C}\.\x{0645}|[Ee][Gg][Pp]|[Ll]\.?[Ee])(?![A-Za-z]))/u',
	);

	/**
	 * A mobile number in a form an id is never read as: 01xxxxxxxxx, or 1xxxxxxxxx or 01xxxxxxxxx after +20, 0020 or
	 * 20 (Payer::normalize() reads all of them), with the prefix of an Egyptian mobile line (010, 011, 012 or 015):
	 * other digits of that length («01334567890») can be an id. A bare 1xxxxxxxxx can be a 10-digit id: it counts as
	 * a number only when it is the order's payer key or a store number (is_number()), as does any spelling of them.
	 */
	const PHONE = '/^(?:01[0125][0-9]{8}|(?:\+|00)?200?1[0125][0-9]{8})$/D';

	/**
	 * Read the id out of what a customer typed or pasted (docs/17 §4.10):
	 * 1. null, or nothing but white space, marks and separators (after steps 2 and 3): qersh_txn_empty; another
	 *    value that is not a string: qersh_txn_format; more than 400 characters: qersh_txn_too_long;
	 * 2. Arabic-Indic and Persian digits become Latin;
	 * 3. bidi, invisible marks and tatweel are removed (ArabicNormalizer.kt), white space becomes a space (a line
	 *    break becomes "\n"), and the Arabic and full-width separators become their Latin forms;
	 * 4. the links, dates, times and amounts of BREAKS become line breaks, before step 5, so that an amount, a date
	 *    or a time typed alone (or a date after the id) is never read as an id;
	 * 5. the id alone: when what is left is one line of runs of [0-9A-Za-z], each with a digit, split by any number
	 *    of separators (and an optional leading `+`), its compact value (the runs joined) is the id when it has the
	 *    canonical form (canonical()). A compact value that is a mobile number, the payer key or a store number is
	 *    qersh_txn_wallet_number. Two or more runs one of which is such a number by itself are read as a message;
	 * 6. a message: a number is a group of runs of [0-9A-Za-z] that hold a digit, each separated from the next by
	 *    exactly one separator on the same line; a run without a digit (a word) ends the group. The group's
	 *    candidate is its runs joined, so an id split «2500517294 4183» is read whole, never as its first 10 digits;
	 *    a group whose joined value is longer than MAX_ID gives each run as a candidate. A mobile number, the payer
	 *    key or a store number (a run of a group by itself, or a group joined) is left out; a candidate counts only
	 *    when it has the canonical form, and equal candidates count once;
	 * 7. exactly one candidate: the id; more: qersh_txn_several; none: qersh_txn_format, or
	 *    qersh_txn_wallet_number when numbers were left out.
	 * The id is text in upper case: leading zeros are kept.
	 *
	 * @param mixed  $raw           What the customer sent.
	 * @param string $payer_key     The order's payer key ('' when it has none).
	 * @param array  $store_numbers The store's wallet numbers (receive addresses).
	 * @return array `[ 'ok' => true, 'txn' => string ]` or `[ 'ok' => false, 'error' => string ]`.
	 */
	public static function canonical_typed( $raw, string $payer_key = '', array $store_numbers = array() ): array {
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

		$text = self::normalize_text( $text );
		if ( '' === str_replace( array_merge( self::SEPARATORS, array( "\n" ) ), '', $text ) ) {
			return self::error( 'qersh_txn_empty' );
		}
		$text = self::hard_breaks( $text );
		$own  = self::own_numbers( $payer_key, $store_numbers );

		$alone = self::id_alone( $text, $own );
		if ( null !== $alone ) {
			return $alone;
		}

		$candidates = array();
		$excluded   = 0;
		foreach ( self::numbers( $text, $own ) as $value ) {
			if ( self::is_number( $value, $own ) ) {
				++$excluded;
				continue;
			}
			$id = self::canonical( $value );
			if ( null !== $id && ! in_array( $id, $candidates, true ) ) {
				$candidates[] = $id;
			}
		}
		if ( 1 === count( $candidates ) ) {
			return self::found( $candidates[0] );
		}
		if ( count( $candidates ) > 1 ) {
			return self::error( 'qersh_txn_several' );
		}
		return self::error( $excluded > 0 ? 'qersh_txn_wallet_number' : 'qersh_txn_format' );
	}

	/**
	 * The canonical form of an id delivered by the phone (`provider_txn_id`), whatever the wallet: normalised as
	 * canonical_typed() normalises (steps 2 and 3), white space and separators removed, in upper case. Null when it
	 * is empty, not a string, without a digit, or shorter than MIN_ID or longer than MAX_ID after that: it then
	 * never equals an id a customer typed.
	 *
	 * @param mixed $value Delivered value.
	 * @return string|null
	 */
	public static function canonical_stored( $value ): ?string {
		if ( ! is_string( $value ) || '' === $value || strlen( $value ) > 4 * self::MAX_LENGTH ) {
			return null;
		}
		$text = self::normalize_text( Config::to_valid_utf8( $value ) );
		return self::canonical( str_replace( array_merge( self::SEPARATORS, array( "\n" ) ), '', $text ) );
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
	 * Step 4: the links, dates, times and amounts of BREAKS replaced by "\n".
	 *
	 * @param string $text Text from normalize_text().
	 * @return string
	 */
	private static function hard_breaks( string $text ): string {
		foreach ( self::BREAKS as $pattern ) {
			$broken = preg_replace( $pattern, "\$1\n", $text );
			$text   = null === $broken ? $text : $broken;
		}
		return $text;
	}

	/**
	 * Step 5: the id typed alone, its refusal as a wallet number, or null when the text is to be read as a message
	 * (it holds a word, a line break, another character, or a number next to other runs), or when its compact value
	 * is not an id (step 6 then refuses it, or reads a run of it).
	 *
	 * @param string $text Text from hard_breaks().
	 * @param array  $own  Set from own_numbers().
	 * @return array|null
	 */
	private static function id_alone( string $text, array $own ): ?array {
		$line = trim( $text, implode( '', self::SEPARATORS ) . "\n" );
		if ( false !== strpos( $line, "\n" ) ) {
			return null;
		}
		$compact = str_replace( self::SEPARATORS, '', $line );
		if ( 1 !== preg_match( '/^\+?[0-9A-Za-z]+$/D', $compact ) ) {
			return null;
		}
		preg_match_all( '/[0-9A-Za-z]+/', $line, $runs );
		foreach ( $runs[0] as $run ) {
			$run = (string) $run;
			if ( 1 !== preg_match( '/[0-9]/', $run ) || ( count( $runs[0] ) > 1 && self::is_number( $run, $own ) ) ) {
				return null;
			}
		}
		if ( self::is_number( $compact, $own ) ) {
			return self::error( 'qersh_txn_wallet_number' );
		}
		$id = self::canonical( $compact );
		return null === $id ? null : self::found( $id );
	}

	/**
	 * Step 6: the values of a message, in order. Runs of [0-9A-Za-z] with a digit, each separated from the previous
	 * one by exactly one separator, form a group; a word or a number (is_number()) ends it, and the number is a
	 * value of its own. A group gives its runs joined, or each run when they join to more than MAX_ID characters.
	 *
	 * @param string $text Text from hard_breaks().
	 * @param array  $own  Set from own_numbers().
	 * @return string[]
	 */
	private static function numbers( string $text, array $own ): array {
		preg_match_all( '/[0-9A-Za-z]+/', $text, $matches, PREG_OFFSET_CAPTURE );
		$groups = array();
		$group  = array();
		$end    = -1;
		foreach ( $matches[0] as $match ) {
			$run    = (string) $match[0];
			$offset = (int) $match[1];
			if ( 1 !== preg_match( '/[0-9]/', $run ) || self::is_number( $run, $own ) ) {
				$groups[] = $group;
				$group    = array();
				if ( 1 === preg_match( '/[0-9]/', $run ) ) {
					$groups[] = array( $run );
				}
				continue;
			}
			// Byte offsets: one byte between two runs is one character, and the separators are ASCII.
			if ( array() === $group || $offset !== $end + 1 || ! in_array( $text[ $end ], self::SEPARATORS, true ) ) {
				$groups[] = $group;
				$group    = array();
			}
			$group[] = $run;
			$end     = $offset + strlen( $run );
		}
		$groups[] = $group;

		$values = array();
		foreach ( $groups as $runs ) {
			$joined = implode( '', $runs );
			if ( '' === $joined ) {
				continue;
			}
			if ( strlen( $joined ) > self::MAX_ID ) {
				$values = array_merge( $values, $runs );
				continue;
			}
			$values[] = $joined;
		}
		return $values;
	}

	/**
	 * The canonical form: [0-9A-Za-z] only, at least one digit, MIN_ID to MAX_ID characters, in upper case.
	 *
	 * @param string $value Value.
	 * @return string|null Null when the value does not have it.
	 */
	private static function canonical( string $value ): ?string {
		$length = strlen( $value );
		if ( $length < self::MIN_ID || $length > self::MAX_ID || 1 !== preg_match( '/^[0-9A-Za-z]+$/D', $value ) || 1 !== preg_match( '/[0-9]/', $value ) ) {
			return null;
		}
		return strtoupper( $value );
	}

	/**
	 * The payer key and store numbers that normalise to a mobile number (Payer::normalize()), as a set. A handle is
	 * no wallet number of an e-wallet store, and is ignored.
	 *
	 * @param string $payer_key     Payer key.
	 * @param array  $store_numbers Store numbers.
	 * @return array<string, bool>
	 */
	private static function own_numbers( string $payer_key, array $store_numbers ): array {
		$own = array();
		foreach ( array_merge( array( $payer_key ), array_values( $store_numbers ) ) as $number ) {
			$normalized = is_string( $number ) ? Payer::normalize( $number ) : null;
			if ( null !== $normalized && 1 === preg_match( '/^01[0-9]{9}$/D', $normalized ) ) {
				$own[ $normalized ] = true;
			}
		}
		return $own;
	}

	/**
	 * Whether digits (with an optional leading `+`) are a number an id is never read as: a mobile number in one of
	 * the forms of PHONE, or any spelling Payer::normalize() reads as the payer key or a store number.
	 *
	 * @param string $value Value.
	 * @param array  $own   Set from own_numbers().
	 * @return bool
	 */
	private static function is_number( string $value, array $own ): bool {
		if ( 1 !== preg_match( '/^\+?[0-9]+$/D', $value ) ) {
			return false;
		}
		if ( 1 === preg_match( self::PHONE, $value ) ) {
			return true;
		}
		$normalized = Payer::normalize( $value );
		return null !== $normalized && isset( $own[ $normalized ] );
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
	 * An id read.
	 *
	 * @param string $txn Canonical id.
	 * @return array
	 */
	private static function found( string $txn ): array {
		return array(
			'ok'  => true,
			'txn' => $txn,
		);
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
