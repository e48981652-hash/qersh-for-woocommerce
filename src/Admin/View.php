<?php
/**
 * Small display helpers shared by the admin screens, the order box, the alert email and Diagnostics.
 *
 * @package QershPay\WooCommerce
 */

namespace QershPay\WooCommerce\Admin;

use QershPay\WooCommerce\Format;

defined( 'ABSPATH' ) || exit;

/**
 * Every helper returns HTML that is already escaped, so templates print it through View::kses() (which keeps
 * `<bdi>`, which wp_kses_post() drops). Times are shown in Cairo time, as the customer sees them (docs/17 §6).
 */
final class View {

	/**
	 * The time zone of every time the plugin shows.
	 */
	const TIME_ZONE = 'Africa/Cairo';

	/**
	 * Wallet types in the order of the "Add wallet" list (docs/17 §5 step 2).
	 */
	const PROVIDER_ORDER = array( 'vodafone_cash', 'etisalat_cash', 'orange_cash', 'instapay_app', 'bank_nbe', 'bank_cib', 'bank_egbank' );

	/**
	 * Wallet types whose transfers wait for the merchant's decision (no payer number, or no balance to check).
	 */
	const DECISION_PROVIDERS = array( 'instapay_app', 'bank_nbe', 'bank_cib', 'bank_egbank' );

	/**
	 * A value isolated left to right inside admin HTML (docs/17 §7): numbers, addresses, transaction ids, times.
	 *
	 * @param string|int $value Value (plain text).
	 * @return string Escaped HTML.
	 */
	public static function ltr( $value ): string {
		$value = (string) $value;
		if ( class_exists( Format::class ) && method_exists( Format::class, 'ltr' ) ) {
			try {
				$out = Format::ltr( $value, 'html' );
			} catch ( \Throwable $e ) {
				$out = null;
			}
			if ( is_string( $out ) && false !== strpos( $out, 'dir="ltr"' ) ) {
				return $out;
			}
		}
		return '<bdi dir="ltr">' . esc_html( $value ) . '</bdi>';
	}

	/**
	 * A value isolated for an email: a span with unicode-bidi isolate in HTML (wp_kses_post() and mail clients drop
	 * `<bdi>`), U+2066 … U+2069 in plain text.
	 *
	 * @param string|int $value Value.
	 * @param bool       $plain True for the plain-text email.
	 * @return string Escaped HTML, or plain text.
	 */
	public static function email_ltr( $value, bool $plain ): string {
		$value = (string) $value;
		if ( $plain ) {
			return "\u{2066}" . $value . "\u{2069}";
		}
		return '<span dir="ltr" style="unicode-bidi:isolate">' . esc_html( $value ) . '</span>';
	}

	/**
	 * The tags our escaped lines may carry.
	 *
	 * @return array
	 */
	public static function allowed_html(): array {
		return array(
			'bdi'    => array( 'dir' => true ),
			'span'   => array(
				'dir'   => true,
				'class' => true,
				'style' => true,
			),
			'code'   => array(
				'dir'   => true,
				'class' => true,
			),
			'strong' => array(),
			'em'     => array(),
			'br'     => array(),
			'a'      => array(
				'href'   => true,
				'class'  => true,
				'target' => true,
				'rel'    => true,
			),
			'mark'   => array( 'class' => true ),
		);
	}

	/**
	 * Late escaping for a line built by these helpers.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public static function kses( $html ): string {
		return wp_kses( (string) $html, self::allowed_html() );
	}

	/**
	 * A time in Cairo time with the site's date and time formats.
	 *
	 * @param int|null $ts UTC seconds.
	 * @return string Plain text ('' for null).
	 */
	public static function time( $ts ): string {
		if ( null === $ts || (int) $ts <= 0 ) {
			return '';
		}
		$format = trim( (string) get_option( 'date_format', 'Y-m-d' ) . ' ' . (string) get_option( 'time_format', 'H:i' ) );
		$out    = wp_date( '' === $format ? 'Y-m-d H:i' : $format, (int) $ts, new \DateTimeZone( self::TIME_ZONE ) );
		return is_string( $out ) ? $out : '';
	}

	/**
	 * An amount in piasters as WooCommerce prints prices (wc_price() isolates it with `<bdi>` itself).
	 *
	 * @param int|null $minor Piasters.
	 * @return string Escaped HTML.
	 */
	public static function money( $minor ): string {
		if ( null === $minor ) {
			return esc_html__( 'unknown amount', 'qersh-for-woocommerce' );
		}
		if ( function_exists( 'wc_price' ) ) {
			return (string) wc_price( (int) $minor / 100, array( 'currency' => 'EGP' ) );
		}
		return self::ltr( number_format( (int) $minor / 100, 2, '.', ',' ) );
	}

	/**
	 * An amount for the plain-text email.
	 *
	 * @param int|null $minor Piasters.
	 * @return string
	 */
	public static function money_plain( $minor ): string {
		if ( null === $minor ) {
			return __( 'unknown amount', 'qersh-for-woocommerce' );
		}
		if ( function_exists( 'wc_price' ) ) {
			return html_entity_decode( wp_strip_all_tags( (string) wc_price( (int) $minor / 100, array( 'currency' => 'EGP' ) ) ), ENT_QUOTES, 'UTF-8' );
		}
		return number_format( (int) $minor / 100, 2, '.', ',' );
	}

	/**
	 * The name of a wallet type.
	 *
	 * @param string $provider Provider id.
	 * @return string Plain text.
	 */
	public static function provider_label( $provider ): string {
		switch ( (string) $provider ) {
			case 'vodafone_cash':
				return __( 'Vodafone Cash', 'qersh-for-woocommerce' );
			case 'etisalat_cash':
				return __( 'e& money', 'qersh-for-woocommerce' );
			case 'orange_cash':
				return __( 'Orange Cash', 'qersh-for-woocommerce' );
			case 'instapay_app':
				return __( 'InstaPay', 'qersh-for-woocommerce' );
			case 'bank_nbe':
				return __( 'National Bank of Egypt', 'qersh-for-woocommerce' );
			case 'bank_cib':
				return __( 'CIB', 'qersh-for-woocommerce' );
			case 'bank_egbank':
				return __( 'Egyptian Gulf Bank (EGBANK)', 'qersh-for-woocommerce' );
		}
		return (string) $provider;
	}

	/**
	 * The display name of a configured wallet: its label, or its type.
	 *
	 * @param array|null $wallet Wallet entry.
	 * @return string Plain text.
	 */
	public static function wallet_name( $wallet ): string {
		if ( ! is_array( $wallet ) ) {
			return '';
		}
		$label = isset( $wallet['label'] ) && is_string( $wallet['label'] ) ? trim( $wallet['label'] ) : '';
		if ( '' !== $label ) {
			return $label;
		}
		return self::provider_label( isset( $wallet['provider'] ) ? $wallet['provider'] : '' );
	}

	/**
	 * `#1042`, linked to the order's edit screen when the order exists.
	 *
	 * @param int $order_id Order id.
	 * @return string Escaped HTML.
	 */
	public static function order_link( $order_id ): string {
		$order_id = (int) $order_id;
		if ( $order_id <= 0 ) {
			return '';
		}
		$order  = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		$number = $order ? (string) $order->get_order_number() : (string) $order_id;
		$text   = self::ltr( '#' . $number );
		if ( $order && method_exists( $order, 'get_edit_order_url' ) ) {
			return '<a href="' . esc_url( $order->get_edit_order_url() ) . '">' . $text . '</a>';
		}
		return $text;
	}

	/**
	 * The order number customers and the merchant see (plain text), or the id.
	 *
	 * @param int $order_id Order id.
	 * @return string
	 */
	public static function order_number( $order_id ): string {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $order_id ) : null;
		return $order ? (string) $order->get_order_number() : (string) (int) $order_id;
	}
}
