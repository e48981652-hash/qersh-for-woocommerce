<?php
/**
 * Customer email "Transfer instructions for your order #N" (plain text). Numbers sit between U+2066 and U+2069 so
 * they keep their order inside Arabic text, and a time written with Arabic words between U+2068 and U+2069
 * (Format::isolate(), docs/17 §7).
 *
 * Override it by copying it to yourtheme/woocommerce/emails/plain/instructions.php.
 *
 * @package QershPay\WooCommerce
 *
 * @var WC_Order   $order              Order.
 * @var array|null $box                Checkout::box(), or null (a preview with another order).
 * @var int        $now                UTC seconds.
 * @var string     $email_heading      Heading.
 * @var string     $additional_content Content below.
 * @var bool       $sent_to_admin      False.
 * @var bool       $plain_text         True.
 * @var WC_Email   $email              Email object.
 */

defined( 'ABSPATH' ) || exit;

use QershPay\WooCommerce\Checkout;
use QershPay\WooCommerce\Format;

$qersh_wc_ctx = Format::PLAIN;

echo "=============================================================\n";
echo esc_html( wp_strip_all_tags( $email_heading ) );
echo "\n=============================================================\n\n";

/* translators: %s: order number. */
echo esc_html( sprintf( __( 'Your order #%s is waiting for your transfer. Send the amount below from your wallet before the deadline.', 'qersh-for-woocommerce' ), $order->get_order_number() ) ) . "\n\n";

if ( is_array( $box ) ) {
	/* translators: %s: amount to transfer. */
	echo esc_html( sprintf( __( 'Amount: %s', 'qersh-for-woocommerce' ), Format::price( $box['due_minor'], $qersh_wc_ctx, $box['currency'] ) ) ) . "\n";
	if ( $box['rounded'] ) {
		if ( $box['paid_minor'] > 0 ) {
			/* translators: 1: what remains of the order, with piasters, 2: the same rounded up to the pound. */
			$qersh_wc_text = __( 'The rest of your order is %1$s, rounded up to %2$s because wallet transfers made by code accept whole pounds only.', 'qersh-for-woocommerce' );
		} else {
			/* translators: 1: order total with piasters, 2: the total rounded up to the pound. */
			$qersh_wc_text = __( 'Your order total is %1$s, rounded up to %2$s because wallet transfers made by code accept whole pounds only.', 'qersh-for-woocommerce' );
		}
		echo esc_html( sprintf( $qersh_wc_text, Format::price( $box['exact_minor'], $qersh_wc_ctx, $box['currency'] ), Format::price( $box['due_minor'], $qersh_wc_ctx, $box['currency'] ) ) ) . "\n";
	}
	if ( '' !== $box['address'] ) {
		$qersh_wc_to = Format::ltr( $box['address'], $qersh_wc_ctx );
		if ( '' !== $box['wallet_label'] ) {
			/* translators: 1: the store's wallet number, 2: the name the store gave that wallet. */
			echo esc_html( sprintf( __( 'Send to: %1$s (%2$s)', 'qersh-for-woocommerce' ), $qersh_wc_to, $box['wallet_label'] ) ) . "\n";
		} else {
			/* translators: %s: the store's wallet number. */
			echo esc_html( sprintf( __( 'Send to: %s', 'qersh-for-woocommerce' ), $qersh_wc_to ) ) . "\n";
		}
	}
	if ( '' !== $box['payer'] ) {
		/* translators: %s: the customer's wallet number. */
		echo esc_html( sprintf( __( 'From your number: %s', 'qersh-for-woocommerce' ), Format::ltr( $box['payer'], $qersh_wc_ctx ) ) ) . "\n";
	}
	if ( null !== $box['deadline'] ) {
		/* translators: %s: deadline date and time in Cairo. */
		echo esc_html( sprintf( __( 'Before: %s Cairo time', 'qersh-for-woocommerce' ), Format::ltr( Format::cairo_time( $box['deadline'], $now, true ), $qersh_wc_ctx ) ) ) . "\n";
	}
	echo "\n" . esc_html( $box['confirm'] ) . "\n\n";

	if ( $box['txn_open'] ) {
		// The link to the id box: the id is optional (the confirm line above says when it helps), never an order.
		// Printed as WooCommerce's emails/plain/email-order-details.php prints "View order": the sentence through
		// esc_html__(), the address through esc_url() in its display context, whose `&#038;` WC_Email::get_content()
		// maps back to `&` with its plain-text rules, so the link works in a text client.
		/* translators: %s: link to the order page. */
		echo sprintf( esc_html__( 'If the store cannot see the number you transferred from, you can enter the transaction id on your order page: %s', 'qersh-for-woocommerce' ), esc_url( Checkout::txn_page_url( $order ) ) ) . "\n\n";
	}
	if ( '' !== trim( $box['instructions'] ) ) {
		echo esc_html( wp_strip_all_tags( wptexturize( $box['instructions'] ) ) ) . "\n\n";
	}
}

echo "\n----------------------------------------\n\n";

/**
 * WooCommerce's order details table (WC_Emails::order_details()).
 * This action is documented in woocommerce/templates/emails/customer-processing-order.php.
 */
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's email hook.

echo "\n----------------------------------------\n\n";

/**
 * WooCommerce's order meta (WC_Emails::order_meta()).
 * This action is documented in woocommerce/templates/emails/customer-processing-order.php.
 */
do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's email hook.

/**
 * WooCommerce's customer details (WC_Emails::customer_details(), WC_Emails::email_address()).
 * This action is documented in woocommerce/templates/emails/customer-processing-order.php.
 */
do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's email hook.

echo "\n\n----------------------------------------\n\n";

if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) );
	echo "\n\n----------------------------------------\n\n";
}

/** This filter is documented in woocommerce/templates/emails/email-footer.php. */
echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own filter, as its plain templates use it.
