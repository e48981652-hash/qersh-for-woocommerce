<?php
/**
 * Bidi isolation and display forms of numbers, addresses, amounts and times (docs/17 §7).
 *
 * @package QershPay\WooCommerce
 */

namespace QershPay\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Every wallet number, InstaPay address, transaction id, masked number or time shown inside Arabic text is isolated
 * here first, or the bidi algorithm reverses its groups ("0100 123 4567" would read "4567 123 0100" and a customer
 * would transfer to a wrong number). One function, by context:
 * - `html` (our storefront and admin templates): `<bdi dir="ltr">`;
 * - `note` (order notes, which pass through wp_kses_post() and lose `<bdi>`) and `email_html`:
 *   `<span dir="ltr" style="unicode-bidi:isolate">`;
 * - `plain` (plain-text email): between U+2066 (LEFT-TO-RIGHT ISOLATE) and U+2069 (POP DIRECTIONAL ISOLATE).
 * Amounts go through wc_price(), which isolates with `<bdi>` already. Spaces inside a grouped number are U+00A0, and
 * a number is grouped only inside an isolated element. Copy buttons copy the raw value from a data- attribute.
 */
final class Format {

	const HTML       = 'html';
	const NOTE       = 'note';
	const EMAIL_HTML = 'email_html';
	const PLAIN      = 'plain';

	/** U+2066 LEFT-TO-RIGHT ISOLATE. */
	const LRI = "\xE2\x81\xA6";

	/** U+2069 POP DIRECTIONAL ISOLATE. */
	const PDI = "\xE2\x81\xA9";

	/** U+00A0 NO-BREAK SPACE, used between the groups of a number. */
	const NBSP = "\xC2\xA0";

	/** U+2022 BULLET, three of them stand for the hidden digits of a masked number. */
	const MASK = "\xE2\x80\xA2\xE2\x80\xA2\xE2\x80\xA2";

	/**
	 * Isolates a value for its context. The value is text: it is escaped here for the HTML contexts.
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
	 * Wraps text that is already safe for its context (escaped HTML, or plain text). Pure.
	 *
	 * @param string $safe    Escaped HTML (or plain text for `plain`).
	 * @param string $context One of html, note, email_html, plain.
	 * @param string $attrs   Extra attributes for the HTML element, already escaped (e.g. ` data-qersh-time="1"`).
	 * @return string
	 */
	public static function isolate( string $safe, string $context, string $attrs = '' ): string {
		if ( self::PLAIN === $context ) {
			return self::LRI . $safe . self::PDI;
		}
		if ( self::NOTE === $context || self::EMAIL_HTML === $context ) {
			return '<span dir="ltr" style="unicode-bidi:isolate"' . $attrs . '>' . $safe . '</span>';
		}
		return '<bdi dir="ltr"' . $attrs . '>' . $safe . '</bdi>';
	}

	/**
	 * The display form of a wallet number: `0100 123 4567` (4-3-4, with no-break spaces) for an 11-digit number;
	 * anything else (an InstaPay address, a transaction id) as it is. Isolate the result. Pure.
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
	 * A masked number: the first four and the last three digits (`0111•••445`); an InstaPay address keeps its first
	 * two characters and its domain (`ah•••@instapay`). Isolate the result. Pure.
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
	 * An amount through wc_price() (isolated with `<bdi>` in HTML), without decimals when it is whole pounds, as
	 * a *9# transfer carries none. For `plain`, the text of wc_price() between isolates.
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
		return $html;
	}

	/**
	 * A time in Cairo: the time alone when it falls on the same Cairo day as $now, otherwise the date and the time
	 * (always both when $always_date). Built with wp_date() and the Africa/Cairo zone; the site's zone is never
	 * changed (docs/17 §6.2). Not isolated: pass it to ltr().
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
