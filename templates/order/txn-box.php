<?php
/**
 * "After you transfer": the transaction-id field of an Orange Cash order on the order page (docs/17 §4.10). The
 * form needs JavaScript (thankyou.js sends it); without it a fixed line says what to do instead.
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
?>
<section class="qersh-txn" id="qersh-txn" data-qersh-txn aria-labelledby="qersh-txn-title">
	<h3 class="qersh-txn__title" id="qersh-txn-title"><?php esc_html_e( 'After you transfer', 'qersh-for-woocommerce' ); ?></h3>
	<div class="qersh-txn__form" data-qersh-txn-form hidden>
		<label class="qersh-txn__label" for="qersh_txn"><?php esc_html_e( 'Transaction id', 'qersh-for-woocommerce' ); ?></label>
		<input type="text" class="input-text qersh-txn__input" id="qersh_txn" name="qersh_txn" dir="ltr" inputmode="numeric" autocomplete="off" spellcheck="false" maxlength="400" aria-describedby="qersh_txn_help qersh_txn_hint qersh_txn_msg" />
		<span class="qersh-help" id="qersh_txn_help"><?php esc_html_e( 'Enter the transaction id from your transfer message, or paste the whole message.', 'qersh-for-woocommerce' ); ?></span>
		<span class="qersh-help" id="qersh_txn_hint"><?php esc_html_e( 'In the Orange Cash message, it is the number after "رقم المعاملة".', 'qersh-for-woocommerce' ); ?></span>
		<button type="button" class="button qersh-txn__send" data-qersh-txn-send><?php esc_html_e( 'Send transaction id', 'qersh-for-woocommerce' ); ?></button>
		<span class="qersh-txn__msg" id="qersh_txn_msg" data-qersh-txn-msg role="status" aria-live="polite"></span>
	</div>
	<noscript>
		<p class="qersh-txn__nojs"><?php esc_html_e( 'After you transfer, send the transaction id to the store with your order number.', 'qersh-for-woocommerce' ); ?></p>
	</noscript>
</section>
