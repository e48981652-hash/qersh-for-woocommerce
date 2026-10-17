<?php
/**
 * The delete confirmation of a wallet (docs/17 §5 step 10).
 *
 * @package QershPay\WooCommerce
 *
 * @var array $args `id`, `label`, `until` (escaped HTML), `orders` (escaped HTML), `last_one`, `back_url`.
 */

use QershPay\WooCommerce\Admin\View;

defined( 'ABSPATH' ) || exit;
?>
<section class="qersh-wc-card qersh-wc-dialog" role="dialog" aria-labelledby="qersh-delete-title">
	<h2 id="qersh-delete-title">
		<?php
		/* translators: %s: wallet name. */
		echo esc_html( sprintf( __( 'Delete wallet “%s”?', 'qersh-for-woocommerce' ), $args['label'] ) );
		?>
	</h2>
	<p>
		<?php
		echo wp_kses(
			sprintf(
				/* translators: 1: date and time, 2: how many orders wait for a transfer to this wallet. */
				esc_html__( 'This wallet disappears from checkout in your store, and the store refuses what the phone sends for it. For 72 hours (until %1$s Cairo time) the phone resends every transfer and says “Your store refuses transfers”; if you click “Restore wallet” within that time, every transfer arrives on its own, and sooner if you tap “Test connection” on the phone. After 72 hours the phone stops retrying: every new transfer for this wallet is sent once, then stays in “Not delivered to your store” on the phone for 90 days, and can be sent again after a restore. %2$s If you want the phone to stop sending, pause the wallet or delete it in the Qersh app too. If you only want to hide it from checkout, turn it off instead of deleting it.', 'qersh-for-woocommerce' ),
				$args['until'],
				$args['orders']
			),
			View::allowed_html()
		);
		?>
	</p>
	<?php if ( $args['last_one'] ) : ?>
		<div class="notice notice-warning inline"><p><?php esc_html_e( 'This is the last enabled wallet, so the payment method disappears from your store.', 'qersh-for-woocommerce' ); ?></p></div>
	<?php endif; ?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="qersh_wc_wallet_delete">
		<input type="hidden" name="wallet" value="<?php echo esc_attr( $args['id'] ); ?>">
		<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'qersh_wc_wallet_delete' ) ); ?>">
		<p><label><input type="checkbox" name="erase_key" value="1"> <?php esc_html_e( 'Erase the connection key now (restoring it later needs a new key)', 'qersh-for-woocommerce' ); ?></label></p>
		<p class="submit">
			<button type="submit" class="button button-primary qersh-wc-danger"><?php esc_html_e( 'Delete wallet', 'qersh-for-woocommerce' ); ?></button>
			<a class="button" href="<?php echo esc_url( $args['back_url'] ); ?>"><?php esc_html_e( 'Cancel', 'qersh-for-woocommerce' ); ?></a>
		</p>
	</form>
</section>
