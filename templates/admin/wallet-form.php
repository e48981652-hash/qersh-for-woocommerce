<?php
/**
 * Add a wallet, or edit one (docs/17 §5 step 2): its number and name only. There is no wallet type: the Qersh app
 * asks for it on the phone when the developer's link is opened.
 *
 * @package QershPay\WooCommerce
 *
 * @var array $args `wallet` (null for a new one).
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
			<th scope="row"><label for="qersh-address"><?php esc_html_e( 'Wallet number customers transfer to', 'qersh-for-woocommerce' ); ?></label></th>
			<td>
				<input type="text" id="qersh-address" name="receive_address" class="regular-text" dir="ltr" inputmode="numeric" autocomplete="off" required value="<?php echo esc_attr( $qersh_wc_new ? '' : (string) $qersh_wc_wallet['receive_address'] ); ?>" placeholder="01001234567">
				<p class="description"><?php esc_html_e( 'The 11-digit e-wallet number, starting with 01, such as 01001234567.', 'qersh-for-woocommerce' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="qersh-label"><?php esc_html_e( 'Name', 'qersh-for-woocommerce' ); ?></label></th>
			<td>
				<input type="text" id="qersh-label" name="label" class="regular-text" maxlength="60" value="<?php echo esc_attr( $qersh_wc_new || ! isset( $qersh_wc_wallet['label'] ) ? '' : (string) $qersh_wc_wallet['label'] ); ?>">
				<p class="description"><?php esc_html_e( 'Customers see this name when they choose the wallet to transfer to, and the phone shows it for the wallet. Without a name, the wallet number is shown.', 'qersh-for-woocommerce' ); ?></p>
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
