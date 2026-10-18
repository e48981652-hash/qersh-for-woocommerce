<?php
/**
 * Bidi isolation and display forms of numbers, addresses, amounts and times (docs/17 §7).
 *
 * @package QershPay\WooCommerce
 */

namespace QershPay\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Every wallet number, payer address, transaction id, masked number or time shown inside Arabic text is isolated
 * here first, or the bidi algorithm reverses its groups ("0100 123 4567" would read "4567 123 0100" and a customer
 * would transfer to a wrong number). One function, by context:
 * - `html` (our storefront and admin templates): `<bdi dir="ltr">`;
 * - `note` (order notes, which pass through wp_kses_post() and lose `<bdi>`) and `email_html`:
 *   `<span dir="ltr" style="unicode-bidi:isolate">`;
 * - `plain` (plain-text email): between U+2066 (LEFT-TO-RIGHT ISOLATE) and U+2069 (POP DIRECTIONAL ISOLATE).
 *
 * A value that holds right-to-left letters, which is a localized date or time in Arabic (month names, ص/م,
 * «بتوقيت القاهرة»), is isolated with its direction taken from its first strong letter instead: `<bdi>` without
 * `dir`, a span with `dir="auto"`, or U+2068 (FIRST STRONG ISOLATE). Inside a left-to-right isolate its words would
 * come out in the wrong order. Numbers, addresses, masked numbers and transaction ids have no such letter, so they
 * stay left to right.
 *
 * Amounts go through wc_price(), which isolates with `<bdi>` in our templates; order notes drop `<bdi>`, so there,
 * and in email, the amount is isolated by the same rule. Spaces inside a grouped number are U+00A0, and a number is
 * grouped only inside an isolated element. Copy buttons copy the raw value from a data- attribute.
 */
final class Format {

	const HTML       = 'html';
	const NOTE       = 'note';
	const EMAIL_HTML = 'email_html';
	const PLAIN      = 'plain';

	/** U+2066 LEFT-TO-RIGHT ISOLATE. */
	const LRI = "\xE2\x81\xA6";

	/** U+2068 FIRST STRONG ISOLATE. */
	const FSI = "\xE2\x81\xA8";

	/** U+2069 POP DIRECTIONAL ISOLATE. */
	const PDI = "\xE2\x81\xA9";

	/**
	 * The right-to-left letters that make a value follow its own direction: Hebrew and Arabic letters and their
	 * presentation forms, without the Arabic-Indic digits and the Arabic number signs (numbers, not letters, for
	 * the bidi algorithm). assets/js/txn-normalize.js hasRtl() uses the same ranges.
	 */
	const RTL_LETTERS = '/[\x{05D0}-\x{05EA}\x{05EF}-\x{05F2}\x{0620}-\x{064A}\x{066E}\x{066F}\x{0671}-\x{06D3}\x{06D5}\x{06EE}\x{06EF}\x{06FA}-\x{06FF}\x{0750}-\x{077F}\x{0870}-\x{0887}\x{0889}-\x{088E}\x{08A0}-\x{08C9}\x{FB1D}\x{FB1F}-\x{FB28}\x{FB2A}-\x{FB4F}\x{FB50}-\x{FD3D}\x{FD50}-\x{FDFF}\x{FE70}-\x{FEFC}]/u';

	/** U+00A0 NO-BREAK SPACE, used between the groups of a number. */
	const NBSP = "\xC2\xA0";

	/** U+2022 BULLET, three of them stand for the hidden digits of a masked number. */
	const MASK = "\xE2\x80\xA2\xE2\x80\xA2\xE2\x80\xA2";

	/**
	 * Isolates a value for its context: left to right, or by its first strong letter when it holds right-to-left
	 * letters (see the class). The value is text: it is escaped here for the HTML contexts.
	 *
	 * @param string|int $value   Value.
	 * @param string     $context One of html, note, email_html, plain.
	 * @return string
	 */
	public static function ltr( $value, string $context = self::HTML ): string {
		$value = (string) $value;
		if ( self::PLAIN === $context ) {
			return self::isolate( $value, $context );
		}
		return self::isolate( function_exists( 'esc_html' ) ? esc_html( $value ) : htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' ), $context );
	}

	/**
	 * Wraps text that is already safe for its context (escaped HTML, or plain text): left to right, or, when it
	 * holds right-to-left letters, by its first strong letter. Pure.
	 *
	 * @param string $safe    Escaped HTML (or plain text for `plain`).
	 * @param string $context One of html, note, email_html, plain.
	 * @param string $attrs   Extra attributes for the HTML element, already escaped (e.g. ` data-qersh-time="1"`).
	 * @return string
	 */
	public static function isolate( string $safe, string $context, string $attrs = '' ): string {
		$auto = self::has_rtl( $safe );
		if ( self::PLAIN === $context ) {
			return ( $auto ? self::FSI : self::LRI ) . $safe . self::PDI;
		}
		if ( self::NOTE === $context || self::EMAIL_HTML === $context ) {
			return '<span dir="' . ( $auto ? 'auto' : 'ltr' ) . '" style="unicode-bidi:isolate"' . $attrs . '>' . $safe . '</span>';
		}
		return ( $auto ? '<bdi' : '<bdi dir="ltr"' ) . $attrs . '>' . $safe . '</bdi>';
	}

	/**
	 * Whether a text holds a right-to-left letter (RTL_LETTERS), entities decoded first so an escaped value or the
	 * HTML of wc_price() reads as its text. Digits of any script, Latin text and symbols do not count. Pure.
	 *
	 * @param string $text Text or escaped HTML.
	 * @return bool
	 */
	public static function has_rtl( string $text ): bool {
		if ( '' === $text || 1 !== preg_match( '/[^\x00-\x7F]|&/', $text ) ) {
			return false;
		}
		return 1 === preg_match( self::RTL_LETTERS, html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}

	/**
	 * The display form of a wallet number: `0100 123 4567` (4-3-4, with no-break spaces) for an 11-digit number;
	 * anything else (a transaction id, an address an older row may hold) as it is. Isolate the result. Pure.
	 *
	 * @param string $value Normalised number or address.
	 * @return string
	 */
	public static function number( string $value ): string {
		if ( 1 !== preg_match( '/^[0-9]{11}$/D', $value ) ) {
			return $value;
		}
		return substr( $value, 0, 4 ) . self::NBSP . substr( $value, 4, 3 ) . self::NBSP . substr( $value, 7 );
	}

	/**
	 * A masked number: the first four and the last three digits (`0111•••445`); an address (`name@domain`, which an
	 * older row may hold) keeps its first two characters and its domain (`ah•••@domain`). Isolate the result. Pure.
	 *
	 * @param string $value Normalised number or address.
	 * @return string
	 */
	public static function masked( string $value ): string {
		$at = strpos( $value, '@' );
		if ( false !== $at ) {
			return substr( $value, 0, min( 2, $at ) ) . self::MASK . substr( $value, $at );
		}
		if ( strlen( $value ) <= 7 ) {
			return self::MASK . substr( $value, -min( 3, strlen( $value ) ) );
		}
		return substr( $value, 0, 4 ) . self::MASK . substr( $value, -3 );
	}

	/**
	 * The raw value a copy button copies for an amount: `140` or `139.50`. Pure.
	 *
	 * @param int $minor Piasters.
	 * @return string
	 */
	public static function amount_raw( int $minor ): string {
		$sign  = $minor < 0 ? '-' : '';
		$minor = abs( $minor );
		$whole = intdiv( $minor, 100 );
		$rest  = $minor % 100;
		return $sign . $whole . ( 0 === $rest ? '' : '.' . str_pad( (string) $rest, 2, '0', STR_PAD_LEFT ) );
	}

	/**
	 * An amount through wc_price(), without decimals when it is whole pounds, as a wallet transfer made by code
	 * carries none.
	 * - `html`: as wc_price() gives it, isolated by its own `<bdi>` (our templates allow it).
	 * - `note` and `email_html`: that HTML inside the isolating span of isolate(), because wp_kses_post() drops the
	 *   `<bdi>` of an order note, and not every mail client knows it.
	 * - `plain`: the text of wc_price() between isolates.
	 *
	 * @param int    $minor    Piasters.
	 * @param string $context  One of html, note, email_html, plain.
	 * @param string $currency Currency code (the order's), or '' for the store's.
	 * @return string
	 */
	public static function price( int $minor, string $context = self::HTML, string $currency = '' ): string {
		$args = array( 'decimals' => 0 === $minor % 100 ? 0 : 2 );
		if ( '' !== $currency ) {
			$args['currency'] = $currency;
		}
		$html = wc_price( $minor / 100, $args );
		if ( self::PLAIN === $context ) {
			return self::isolate( trim( html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' ) ), $context );
		}
		if ( self::NOTE === $context || self::EMAIL_HTML === $context ) {
			return self::isolate( $html, $context );
		}
		return $html;
	}

	/**
	 * A time in Cairo: the time alone when it falls on the same Cairo day as $now, otherwise the date and the time
	 * (always both when $always_date). Built with wp_date() and the Africa/Cairo zone; the site's zone is never
	 * changed (docs/17 §6.2). Not isolated: pass it to ltr() or isolate(), which isolate it by its first strong
	 * letter when the site's language writes it with Arabic words.
	 *
	 * @param int  $timestamp   UTC seconds.
	 * @param int  $now         UTC seconds.
	 * @param bool $always_date True for email, which may be read later.
	 * @return string
	 */
	public static function cairo_time( int $timestamp, int $now, bool $always_date = false ): string {
		$zone        = self::cairo_zone();
		$time_format = (string) get_option( 'time_format', 'g:i a' );
		$date_format = (string) get_option( 'date_format', 'F j, Y' );
		$same_day    = wp_date( 'Y-m-d', $timestamp, $zone ) === wp_date( 'Y-m-d', $now, $zone );
		$format      = ( $always_date || ! $same_day ) ? $date_format . ' ' . $time_format : $time_format;
		$out         = wp_date( $format, $timestamp, $zone );
		return is_string( $out ) ? $out : '';
	}

	/**
	 * The Africa/Cairo zone (or UTC if this PHP lacks it, which the status screen reports).
	 *
	 * @return \DateTimeZone
	 */
	public static function cairo_zone(): \DateTimeZone {
		try {
			return new \DateTimeZone( 'Africa/Cairo' );
		} catch ( \Exception $e ) {
			return new \DateTimeZone( 'UTC' );
		}
	}

	/**
	 * The tags our storefront templates print through wp_kses(): what wp_kses_post() allows, plus `<bdi dir>`
	 * (wp_kses_post() drops it) and the data- attributes our scripts read.
	 *
	 * @return array
	 */
	public static function allowed_html(): array {
		$allowed = function_exists( 'wp_kses_allowed_html' ) ? wp_kses_allowed_html( 'post' ) : array();
		$extra   = array(
			'dir'             => true,
			'class'           => true,
			'id'              => true,
			'data-qersh-time' => true,
		);

		$allowed['bdi']  = $extra;
		$allowed['span'] = array_merge( isset( $allowed['span'] ) ? $allowed['span'] : array(), $extra, array( 'style' => true ) );
		return $allowed;
	}
}
