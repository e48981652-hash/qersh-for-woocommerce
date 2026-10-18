<?php
/**
 * "After you transfer": the transaction-id field on the order page (docs/17 §4.10), for an order of any wallet while
 * the store takes transaction ids (Checkout::box() `txn_open`). The id only helps the store find a transfer whose
 * message does not show the payer's number, so the texts say nothing about the wallet. The form needs JavaScript
 * (thankyou.js sends it); without it a fixed line says what to do instead. The keyboard is a text one: an id may
 * hold letters as well as digits (Core\TxnId), and a whole message may be pasted.
 *
 * Override it by copying it to yourtheme/qersh-for-woocommerce/order/txn-box.php. Keep the id `qersh-txn` (the
 * instructions email links to it), the input attributes and the data- attributes.
 *
 * @package QershPay\WooCommerce
 *
 * @var WC_Order $order Order.
 * @var array    $box   Checkout::box().
 */

defined( 'ABSPATH' ) || exit;

// The theme's button look on a block theme (`wp-element-button`, as WooCommerce's own templates do); empty on a
// classic theme, which styles `.button`.
$qersh_wc_button = function_exists( 'wc_wp_theme_get_element_class_name' ) ? (string) wc_wp_theme_get_element_class_name( 'button' ) : '';
?>
<section class="qersh-txn" id="qersh-txn" data-qersh-txn aria-labelledby="qersh-txn-title">
	<h3 class="qersh-txn__title" id="qersh-txn-title"><?php esc_html_e( 'After you transfer', 'qersh-for-woocommerce' ); ?></h3>
	<div class="qersh-txn__form" data-qersh-txn-form hidden>
		<label class="qersh-txn__label" for="qersh_txn"><?php esc_html_e( 'Transaction id', 'qersh-for-woocommerce' ); ?></label>
		<input type="text" class="input-text qersh-txn__input" id="qersh_txn" name="qersh_txn" dir="ltr" inputmode="text" autocapitalize="characters" autocomplete="off" spellcheck="false" maxlength="400" aria-describedby="qersh_txn_help qersh_txn_hint qersh_txn_msg" />
		<span class="qersh-help" id="qersh_txn_help"><?php esc_html_e( 'Enter the transaction id from your transfer message, or paste the whole message.', 'qersh-for-woocommerce' ); ?></span>
		<span class="qersh-help" id="qersh_txn_hint"><?php esc_html_e( 'It is in the message your wallet sent you about this transfer, usually after "transaction number".', 'qersh-for-woocommerce' ); ?></span>
		<button type="button" class="<?php echo esc_attr( '' === $qersh_wc_button ? 'button qersh-txn__send' : 'button ' . $qersh_wc_button . ' qersh-txn__send' ); ?>" data-qersh-txn-send><?php esc_html_e( 'Send transaction id', 'qersh-for-woocommerce' ); ?></button>
		<span class="qersh-txn__msg" id="qersh_txn_msg" data-qersh-txn-msg role="status" aria-live="polite"></span>
	</div>
	<noscript>
		<p class="qersh-txn__nojs"><?php esc_html_e( 'After you transfer, you can send the store the transaction id with your order number: it helps when the store cannot see the number you transferred from.', 'qersh-for-woocommerce' ); ?></p>
	</noscript>
</section>
