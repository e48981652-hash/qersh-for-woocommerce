<?php
/**
 * Add a wallet, or edit one (docs/17 §5 step 2).
 *
 * @package QershPay\WooCommerce
 *
 * @var array $args `wallet` (null for a new one), `providers` (type => name).
 */

use QershPay\WooCommerce\Admin\Module;
use QershPay\WooCommerce\Admin\View;

defined( 'ABSPATH' ) || exit;

$qersh_wc_wallet = $args['wallet'];
$qersh_wc_new    = null === $qersh_wc_wallet;
?>
<h2>
	<?php
	if ( $qersh_wc_new ) {
		esc_html_e( 'Add wallet', 'qersh-for-woocommerce' );
	} else {
		/* translators: %s: wallet name. */
		echo esc_html( sprintf( __( 'Edit wallet “%s”', 'qersh-for-woocommerce' ), View::wallet_name( $qersh_wc_wallet ) ) );
	}
	?>
</h2>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="qersh-wc-wallet-form">
	<input type="hidden" name="action" value="qersh_wc_wallet_save">
	<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'qersh_wc_wallet_save' ) ); ?>">
	<?php if ( ! $qersh_wc_new ) : ?>
		<input type="hidden" name="wallet" value="<?php echo esc_attr( $qersh_wc_wallet['id'] ); ?>">
	<?php endif; ?>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="qersh-provider"><?php esc_html_e( 'Wallet type', 'qersh-for-woocommerce' ); ?></label></th>
			<td>
				<?php if ( $qersh_wc_new ) : ?>
					<select id="qersh-provider" name="provider" required>
						<option value=""><?php esc_html_e( 'Choose…', 'qersh-for-woocommerce' ); ?></option>
						<?php foreach ( $args['providers'] as $qersh_wc_value => $qersh_wc_name ) : ?>
							<option value="<?php echo esc_attr( $qersh_wc_value ); ?>"><?php echo esc_html( $qersh_wc_name ); ?></option>
						<?php endforeach; ?>
					</select>
				<?php else : ?>
					<strong><?php echo esc_html( View::provider_label( $qersh_wc_wallet['provider'] ) ); ?></strong>
					<p class="description"><?php esc_html_e( 'The type cannot change: it is part of the link on the phone. To use another type, add a new wallet.', 'qersh-for-woocommerce' ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="qersh-address"><?php esc_html_e( 'Number or InstaPay address customers transfer to', 'qersh-for-woocommerce' ); ?></label></th>
			<td>
				<input type="text" id="qersh-address" name="receive_address" class="regular-text" dir="ltr" autocomplete="off" required value="<?php echo esc_attr( $qersh_wc_new ? '' : (string) $qersh_wc_wallet['receive_address'] ); ?>" placeholder="01001234567">
				<p class="description"><?php esc_html_e( 'An 11-digit wallet number such as 01001234567, or for InstaPay an address such as name@instapay.', 'qersh-for-woocommerce' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="qersh-label"><?php esc_html_e( 'Name', 'qersh-for-woocommerce' ); ?></label></th>
			<td>
				<input type="text" id="qersh-label" name="label" class="regular-text" maxlength="60" value="<?php echo esc_attr( $qersh_wc_new ? '' : (string) $qersh_wc_wallet['label'] ); ?>">
				<p class="description"><?php esc_html_e( 'Customers see this name when they choose the wallet to transfer to, and the phone shows it for the wallet.', 'qersh-for-woocommerce' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Checkout', 'qersh-for-woocommerce' ); ?></th>
			<td><label><input type="checkbox" name="enabled" value="1"<?php checked( $qersh_wc_new || ! empty( $qersh_wc_wallet['enabled'] ) ); ?>> <?php esc_html_e( 'Show this wallet at checkout', 'qersh-for-woocommerce' ); ?></label></td>
		</tr>
	</table>
	<p class="submit">
		<button type="submit" class="button button-primary"><?php echo $qersh_wc_new ? esc_html__( 'Add wallet', 'qersh-for-woocommerce' ) : esc_html__( 'Save wallet', 'qersh-for-woocommerce' ); ?></button>
		<a class="button" href="<?php echo esc_url( Module::page_url( 'wallets' ) ); ?>"><?php esc_html_e( 'Cancel', 'qersh-for-woocommerce' ); ?></a>
	</p>
</form>
