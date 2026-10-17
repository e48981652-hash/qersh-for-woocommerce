<?php
/**
 * The Wallets tab: one card per wallet, the deleted wallets, and how to link the phone.
 *
 * @package QershPay\WooCommerce
 *
 * @var array $args `constant`, `issues`, `cards`, `deleted`, `add_url`.
 */

use QershPay\WooCommerce\Admin\Module;

defined( 'ABSPATH' ) || exit;
?>
<?php if ( $args['constant'] ) : ?>
	<div class="notice notice-info inline"><p><?php esc_html_e( 'These settings are set in wp-config.php (QERSH_WC_CONFIG), so wallets are added, changed and removed there. Connection keys are still linked from each card.', 'qersh-for-woocommerce' ); ?></p></div>
	<?php if ( ! empty( $args['issues'] ) ) : ?>
		<div class="notice notice-error inline">
			<p><?php esc_html_e( 'QERSH_WC_CONFIG in wp-config.php has problems:', 'qersh-for-woocommerce' ); ?></p>
			<ul class="qersh-wc-list">
				<?php foreach ( $args['issues'] as $qersh_wc_issue ) : ?>
					<li><code dir="ltr"><?php echo esc_html( $qersh_wc_issue['path'] ); ?></code> <?php echo esc_html( $qersh_wc_issue['message'] ); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
	<?php endif; ?>
<?php endif; ?>

<?php if ( empty( $args['cards'] ) ) : ?>
	<section class="qersh-wc-card qersh-wc-empty">
		<h2><?php esc_html_e( 'Add your first wallet', 'qersh-for-woocommerce' ); ?></h2>
		<p><?php esc_html_e( 'Add the wallet or InstaPay address your customers transfer to, then link it to the Qersh app on the phone that receives its messages.', 'qersh-for-woocommerce' ); ?></p>
		<?php if ( ! $args['constant'] ) : ?>
			<p><a class="button button-primary" href="<?php echo esc_url( $args['add_url'] ); ?>"><?php esc_html_e( 'Add wallet', 'qersh-for-woocommerce' ); ?></a></p>
		<?php endif; ?>
	</section>
<?php else : ?>
	<?php foreach ( $args['cards'] as $qersh_wc_card ) : ?>
		<?php Module::template( 'wallet-card', $qersh_wc_card ); ?>
	<?php endforeach; ?>
	<?php if ( ! $args['constant'] ) : ?>
		<p><a class="button" href="<?php echo esc_url( $args['add_url'] ); ?>"><?php esc_html_e( 'Add wallet', 'qersh-for-woocommerce' ); ?></a></p>
	<?php endif; ?>
<?php endif; ?>

<?php if ( ! empty( $args['deleted'] ) ) : ?>
	<h2><?php esc_html_e( 'Deleted wallets', 'qersh-for-woocommerce' ); ?></h2>
	<table class="widefat striped qersh-wc-deleted">
		<tbody>
			<?php foreach ( $args['deleted'] as $qersh_wc_row ) : ?>
				<tr>
					<th scope="row"><?php echo esc_html( $qersh_wc_row['label'] ); ?></th>
					<td><?php echo wp_kses( $qersh_wc_row['text'], \QershPay\WooCommerce\Admin\View::allowed_html() ); ?></td>
					<td class="qersh-wc-actions">
						<?php if ( $qersh_wc_row['restorable'] ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<input type="hidden" name="action" value="qersh_wc_wallet_restore">
								<input type="hidden" name="wallet" value="<?php echo esc_attr( $qersh_wc_row['id'] ); ?>">
								<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'qersh_wc_wallet_restore' ) ); ?>">
								<button type="submit" class="button"><?php echo esc_html( $qersh_wc_row['button'] ); ?></button>
							</form>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
<?php endif; ?>

<details class="qersh-wc-help">
	<summary><?php esc_html_e( 'How to link the phone', 'qersh-for-woocommerce' ); ?></summary>
	<ol>
		<li><?php esc_html_e( 'In the Qersh app, first complete the “Set up Qersh” list on the home screen (“Activate Qersh”, then “Set up the phone”).', 'qersh-for-woocommerce' ); ?></li>
		<li><?php esc_html_e( 'With the link with the connection key: open it on the wallet\'s phone (scan its code, tap “Open in Qersh” on the phone itself, or copy it into “Have a link from your developer?”), and save the wallet, or tap “Update wallet” if it is on the phone already. Nothing to paste in the store.', 'qersh-for-woocommerce' ); ?></li>
		<li><?php esc_html_e( 'With the link without a key: in the app open “More → Wallets → Add wallet”, paste it in “Have a link from your developer?” and save. Qersh makes the connection key and opens “Send this link to your developer”, the only time it shows the key: share or copy the message to yourself (WhatsApp Web, email), then paste it, as it arrived, in the box of the wallet card here.', 'qersh-for-woocommerce' ); ?></li>
		<li><?php esc_html_e( 'If the wallet was on the phone before (linked to n8n or another server), the app offers to update it so it sends to this store: each wallet on the phone sends to one address.', 'qersh-for-woocommerce' ); ?></li>
		<li><?php esc_html_e( 'Keep “Send sender details” (wallet page → “Developer settings”) on “Full name and number”: any other choice stops automatic matching for Vodafone Cash and e& money.', 'qersh-for-woocommerce' ); ?></li>
		<li><?php esc_html_e( 'Then tap “Test connection” on the phone: the card says when it arrived.', 'qersh-for-woocommerce' ); ?></li>
		<li><?php esc_html_e( '“Send this wallet\'s link to your developer” on the wallet page is for later only (a key that was lost or never pasted): each tap makes a new key and the old one stays accepted on the phone for 24 hours, so paste only the latest message.', 'qersh-for-woocommerce' ); ?></li>
	</ol>
</details>
