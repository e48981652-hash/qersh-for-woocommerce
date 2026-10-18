<?php
/**
 * The wallet choice and the payer field under the wallet transfer method (classic checkout and "Pay for order").
 * Every wallet is shown the same way: the merchant's label, its number and the one confirmation line.
 *
 * Override it by copying it to yourtheme/qersh-for-woocommerce/checkout/payer-field.php. Keep the ids and the
 * data- attributes: WooCommerce restores the payment box inputs by id after each checkout refresh, and
 * payer-field.js reads the attributes.
 *
 * @package QershPay\WooCommerce
 *
 * @var array  $views         Enabled wallets (Checkout::wallet_views()).
 * @var string $checked       Wallet id to check, or ''.
 * @var string $value         Value of the field ("Pay for order" only), or ''.
 * @var array  $store_numbers The enabled wallets' numbers (PayerField::browser_numbers()).
 * @var array  $messages      Error messages: invalid, store, choice.
 */

defined( 'ABSPATH' ) || exit;

$qersh_wc_allowed = \QershPay\WooCommerce\Format::allowed_html();
$qersh_wc_single  = 1 === count( $views );
?>
<div class="qersh-payer"
	data-qersh-payer
	data-store-numbers="<?php echo esc_attr( (string) wp_json_encode( array_values( $store_numbers ) ) ); ?>"
	data-msg-invalid="<?php echo esc_attr( $messages['invalid'] ); ?>"
	data-msg-store="<?php echo esc_attr( $messages['store'] ); ?>"
	data-msg-choice="<?php echo esc_attr( $messages['choice'] ); ?>">

	<?php if ( empty( $views ) ) : ?>
		<p class="qersh-confirm"><?php esc_html_e( 'This store has no wallet to transfer to right now. Choose another way to pay.', 'qersh-for-woocommerce' ); ?></p>
	<?php else : ?>

		<?php if ( $qersh_wc_single ) : ?>
			<?php $qersh_wc_view = $views[0]; ?>
			<input type="hidden" name="qersh_wallet" id="<?php echo esc_attr( 'qersh_wallet_' . $qersh_wc_view['id'] ); ?>" value="<?php echo esc_attr( $qersh_wc_view['id'] ); ?>" />
			<p class="qersh-confirm"><?php echo esc_html( $qersh_wc_view['confirm'] ); ?></p>
		<?php else : ?>
			<?php // No `required` on the radios: WooCommerce hides an unselected method's box, and a hidden required input would block the other methods. ?>
			<fieldset class="qersh-wallets" id="qersh_wallets" role="radiogroup" aria-required="true">
				<legend class="qersh-wallets__legend"><?php esc_html_e( 'Which wallet will you transfer to?', 'qersh-for-woocommerce' ); ?> <span class="required" aria-hidden="true">*</span></legend>
				<?php foreach ( $views as $qersh_wc_view ) : ?>
					<?php $qersh_wc_id = 'qersh_wallet_' . $qersh_wc_view['id']; ?>
					<div class="qersh-wallet">
						<input type="radio" class="input-radio" name="qersh_wallet" id="<?php echo esc_attr( $qersh_wc_id ); ?>" value="<?php echo esc_attr( $qersh_wc_view['id'] ); ?>" aria-describedby="<?php echo esc_attr( $qersh_wc_id . '_confirm qersh_wallet_error' ); ?>" <?php checked( $checked, $qersh_wc_view['id'] ); ?> />
						<label for="<?php echo esc_attr( $qersh_wc_id ); ?>">
							<?php if ( '' !== $qersh_wc_view['label'] ) : ?>
								<span class="qersh-wallet__name"><?php echo esc_html( $qersh_wc_view['label'] ); ?></span>
							<?php endif; ?>
							<?php echo wp_kses( \QershPay\WooCommerce\Format::ltr( $qersh_wc_view['address_display'] ), $qersh_wc_allowed ); ?>
						</label>
						<p class="qersh-confirm" id="<?php echo esc_attr( $qersh_wc_id . '_confirm' ); ?>"><?php echo esc_html( $qersh_wc_view['confirm'] ); ?></p>
					</div>
				<?php endforeach; ?>
				<span class="qersh-error" id="qersh_wallet_error" role="alert" hidden></span>
			</fieldset>
		<?php endif; ?>

		<p class="form-row form-row-wide qersh-payer__row">
			<label for="qersh_payer">
				<?php esc_html_e( 'Wallet number you will transfer from', 'qersh-for-woocommerce' ); ?>
				<span class="required" aria-hidden="true">*</span>
			</label>
			<input type="text" class="input-text" id="qersh_payer" name="qersh_payer" dir="ltr" inputmode="numeric" autocomplete="tel" maxlength="80" aria-required="true" aria-describedby="qersh_payer_help qersh_payer_error"<?php echo '' !== $value ? ' value="' . esc_attr( $value ) . '"' : ''; ?> />
			<span class="qersh-help" id="qersh_payer_help"><?php esc_html_e( 'Transfer from this number, so the store can match your transfer to your order.', 'qersh-for-woocommerce' ); ?></span>
			<span class="qersh-error" id="qersh_payer_error" role="alert" hidden></span>
		</p>

	<?php endif; ?>
</div>
