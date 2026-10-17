<?php
/**
 * The administrator's Qersh alert (plain text). Values inside the lines are isolated with U+2066 … U+2069.
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/emails/plain/admin-alert.php.
 *
 * @package QershPay\WooCommerce
 *
 * @var string   $email_heading      Heading.
 * @var string[] $lines              Lines, plain text.
 * @var string   $action_url         Where to act.
 * @var string   $action_label       Its label.
 * @var string   $additional_content Merchant's extra text.
 */

defined( 'ABSPATH' ) || exit;

echo '= ' . esc_html( wp_strip_all_tags( $email_heading ) ) . " =\n\n";

foreach ( $lines as $qersh_wc_line ) {
	echo esc_html( wp_strip_all_tags( $qersh_wc_line ) ) . "\n\n";
}

echo esc_html( wp_strip_all_tags( $action_label ) ) . ': ' . esc_url_raw( $action_url ) . "\n\n";

echo "\n----------------------------------------\n\n";

if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) );
	echo "\n\n----------------------------------------\n\n";
}

/** This filter is documented in woocommerce/templates/emails/email-footer.php */
echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's email hook.
