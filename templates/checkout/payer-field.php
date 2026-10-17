<?php
/**
 * The wallet choice and the payer field under the wallet transfer method (classic checkout and "Pay for order").
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
 * @var bool   $instapay      Whether an InstaPay wallet is enabled (the switch is shown).
 * @var string $kind          `number` or `instapay`.
 * @var array  $store_numbers The enabled wallets' receive addresses (PayerField::browser_numbers()).
 * @var array  $messages      Error messages: invalid, store, choice.
 */

defined( 'ABSPATH' ) || exit;

$qersh_wc_allowed      = \QershPay\WooCommerce\Format::allowed_html();
$qersh_wc_single       = 1 === count( $views );
$qersh_wc_all_decision = true;
foreach ( $views as $qersh_wc_view ) {
	if ( ! $qersh_wc_view['decision'] ) {
		$qersh_wc_all_decision = false;
	}
}
$qersh_wc_label_number   = __( 'Wallet number you will transfer from', 'qersh-for-woocommerce' );
$qersh_wc_label_instapay = __( 'InstaPay address you will transfer from', 'qersh-for-woocommerce' );
$qersh_wc_help_auto      = __( 'We recognize your transfer by this number, so send it from this number only.', 'qersh-for-woocommerce' );
$qersh_wc_help_decision  = __( 'Enter the number you will transfer from, so the store can check your transfer more easily.', 'qersh-for-woocommerce' );
$qersh_wc_decision_now   = $qersh_wc_all_decision;
foreach ( $views as $qersh_wc_view ) {
	if ( $qersh_wc_view['id'] === $checked ) {
		$qersh_wc_decision_now = $qersh_wc_view['decision'];
	}
}
?>
<div class="qersh-payer"
	data-qersh-payer
	data-instapay="<?php echo $instapay ? '1' : '0'; ?>"
	data-all-decision="<?php echo $qersh_wc_all_decision ? '1' : '0'; ?>"
	data-store-numbers="<?php echo esc_attr( (string) wp_json_encode( array_values( $store_numbers ) ) ); ?>"
	data-msg-invalid="<?php echo esc_attr( $messages['invalid'] ); ?>"
	data-msg-store="<?php echo esc_attr( $messages['store'] ); ?>"
	data-msg-choice="<?php echo esc_attr( $messages['choice'] ); ?>"
	data-label-number="<?php echo esc_attr( $qersh_wc_label_number ); ?>"
	data-label-instapay="<?php echo esc_attr( $qersh_wc_label_instapay ); ?>"
	data-help-auto="<?php echo esc_attr( $qersh_wc_help_auto ); ?>"
	data-help-decision="<?php echo esc_attr( $qersh_wc_help_decision ); ?>">

	<?php if ( empty( $views ) ) : ?>
		<p class="qersh-confirm"><?php esc_html_e( 'This store has no wallet to transfer to right now. Choose another way to pay.', 'qersh-for-woocommerce' ); ?></p>
	<?php else : ?>

		<?php if ( $qersh_wc_single ) : ?>
			<?php $qersh_wc_view = $views[0]; ?>
			<input type="hidden" name="qersh_wallet" id="<?php echo esc_attr( 'qersh_wallet_' . $qersh_wc_view['id'] ); ?>" value="<?php echo esc_attr( $qersh_wc_view['id'] ); ?>" data-decision="<?php echo $qersh_wc_view['decision'] ? '1' : '0'; ?>" data-instapay="<?php echo $qersh_wc_view['instapay'] ? '1' : '0'; ?>" />
			<p class="qersh-confirm"><?php echo esc_html( $qersh_wc_view['confirm'] ); ?></p>
		<?php else : ?>
			<fieldset class="qersh-wallets">
				<legend class="qersh-wallets__legend"><?php esc_html_e( 'Which wallet will you transfer to?', 'qersh-for-woocommerce' ); ?> <span class="required" aria-hidden="true">*</span></legend>
				<?php foreach ( $views as $qersh_wc_view ) : ?>
					<?php $qersh_wc_id = 'qersh_wallet_' . $qersh_wc_view['id']; ?>
					<div class="qersh-wallet">
						<input type="radio" class="input-radio" name="qersh_wallet" id="<?php echo esc_attr( $qersh_wc_id ); ?>" value="<?php echo esc_attr( $qersh_wc_view['id'] ); ?>" data-decision="<?php echo $qersh_wc_view['decision'] ? '1' : '0'; ?>" data-instapay="<?php echo $qersh_wc_view['instapay'] ? '1' : '0'; ?>" aria-describedby="<?php echo esc_attr( $qersh_wc_id . '_confirm' ); ?>" <?php checked( $checked, $qersh_wc_view['id'] ); ?> />
						<label for="<?php echo esc_attr( $qersh_wc_id ); ?>">
							<span class="qersh-wallet__name"><?php echo esc_html( '' !== $qersh_wc_view['label'] ? $qersh_wc_view['label'] : $qersh_wc_view['provider_name'] ); ?></span>
							<?php echo wp_kses( \QershPay\WooCommerce\Format::ltr( $qersh_wc_view['address_display'] ), $qersh_wc_allowed ); ?>
							<?php if ( '' !== $qersh_wc_view['label'] && '' !== $qersh_wc_view['provider_name'] ) : ?>
								<span class="qersh-wallet__type">(<?php echo esc_html( $qersh_wc_view['provider_name'] ); ?>)</span>
							<?php endif; ?>
						</label>
						<p class="qersh-confirm" id="<?php echo esc_attr( $qersh_wc_id . '_confirm' ); ?>"><?php echo esc_html( $qersh_wc_view['confirm'] ); ?></p>
					</div>
				<?php endforeach; ?>
			</fieldset>
		<?php endif; ?>

		<?php if ( $instapay ) : ?>
			<fieldset class="qersh-kind">
				<legend class="qersh-kind__legend"><?php esc_html_e( 'Wallet number / InstaPay address', 'qersh-for-woocommerce' ); ?></legend>
				<label for="qersh_payer_kind_number">
					<input type="radio" name="qersh_payer_kind" id="qersh_payer_kind_number" value="number" <?php checked( $kind, 'number' ); ?> />
					<?php esc_html_e( 'Wallet number', 'qersh-for-woocommerce' ); ?>
				</label>
				<label for="qersh_payer_kind_instapay">
					<input type="radio" name="qersh_payer_kind" id="qersh_payer_kind_instapay" value="instapay" <?php checked( $kind, 'instapay' ); ?> />
					<?php esc_html_e( 'InstaPay address', 'qersh-for-woocommerce' ); ?>
				</label>
			</fieldset>
		<?php endif; ?>

		<p class="form-row form-row-wide qersh-payer__row">
			<label for="qersh_payer">
				<span data-qersh-payer-label><?php echo esc_html( 'instapay' === $kind ? $qersh_wc_label_instapay : $qersh_wc_label_number ); ?></span>
				<span class="required" aria-hidden="true">*</span>
			</label>
			<input type="text" class="input-text" id="qersh_payer" name="qersh_payer" dir="ltr" inputmode="<?php echo 'instapay' === $kind ? 'email' : 'numeric'; ?>" autocomplete="<?php echo 'instapay' === $kind ? 'off' : 'tel'; ?>" maxlength="80" aria-required="true" aria-describedby="qersh_payer_help qersh_payer_error"<?php echo '' !== $value ? ' value="' . esc_attr( $value ) . '"' : ''; ?> />
			<span class="qersh-help" id="qersh_payer_help"><?php echo esc_html( $qersh_wc_decision_now ? $qersh_wc_help_decision : $qersh_wc_help_auto ); ?></span>
			<span class="qersh-error" id="qersh_payer_error" role="alert" hidden></span>
		</p>

	<?php endif; ?>
</div>
