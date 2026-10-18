<?php
/**
 * The Settings tab: the store values of qersh_wc_config (docs/17 §4.2), export and import, and how Qersh is used
 * from day to day (docs/17 §5 steps 6–9).
 *
 * @package QershPay\WooCommerce
 *
 * @var array $args `constant`, `issues`, `store`, `min_total`, `max_total`, `late_min`, `piaster_hint`,
 *                  `payments_url`, `remove_forced`.
 */

use QershPay\WooCommerce\Admin\View;

defined( 'ABSPATH' ) || exit;

$qersh_wc_store = $args['store'];
?>
<?php if ( $args['constant'] ) : ?>
	<div class="notice notice-info inline"><p><?php esc_html_e( 'Set in wp-config.php (QERSH_WC_CONFIG): these settings are read-only here.', 'qersh-for-woocommerce' ); ?></p></div>
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

<?php if ( $args['piaster_hint'] ) : ?>
	<div class="notice notice-warning inline"><p><?php esc_html_e( 'Your prices have piasters, but a wallet transfer made by code carries whole pounds only. Turn on “Round up to the next pound” so such transfers match.', 'qersh-for-woocommerce' ); ?></p></div>
<?php endif; ?>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<input type="hidden" name="action" value="qersh_wc_settings_save">
	<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'qersh_wc_settings_save' ) ); ?>">
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="qersh-ttl"><?php esc_html_e( 'Time to transfer (minutes)', 'qersh-for-woocommerce' ); ?></label></th>
			<td>
				<input type="number" id="qersh-ttl" name="order_ttl_min" min="15" max="1440" step="1" class="small-text" dir="ltr" value="<?php echo esc_attr( (string) $qersh_wc_store['order_ttl_min'] ); ?>"<?php disabled( $args['constant'] ); ?>>
				<p class="description">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: minutes. */
							_n(
								'The deadline the customer sees, from 15 to 1440 minutes. An order with no transfer is cancelled %d minute after its deadline, so a transfer the phone reported late still finds it.',
								'The deadline the customer sees, from 15 to 1440 minutes. An order with no transfer is cancelled %d minutes after its deadline, so a transfer the phone reported late still finds it.',
								(int) $args['late_min'],
								'qersh-for-woocommerce'
							),
							(int) $args['late_min']
						)
					);
					?>
				</p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="qersh-min"><?php esc_html_e( 'Order total limits (EGP)', 'qersh-for-woocommerce' ); ?></label></th>
			<td>
				<input type="text" id="qersh-min" name="min_total" inputmode="decimal" class="small-text" dir="ltr" value="<?php echo esc_attr( $args['min_total'] ); ?>" placeholder="<?php esc_attr_e( 'Minimum', 'qersh-for-woocommerce' ); ?>"<?php disabled( $args['constant'] ); ?>>
				–
				<label class="screen-reader-text" for="qersh-max"><?php esc_html_e( 'Maximum order total (EGP)', 'qersh-for-woocommerce' ); ?></label>
				<input type="text" id="qersh-max" name="max_total" inputmode="decimal" class="small-text" dir="ltr" value="<?php echo esc_attr( $args['max_total'] ); ?>" placeholder="<?php esc_attr_e( 'Maximum', 'qersh-for-woocommerce' ); ?>"<?php disabled( $args['constant'] ); ?>>
				<p class="description"><?php esc_html_e( 'Leave empty for no limit. Outside these limits the wallet transfer method is not offered at checkout.', 'qersh-for-woocommerce' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Whole pounds', 'qersh-for-woocommerce' ); ?></th>
			<td><label><input type="checkbox" name="round_up_to_pound" value="1"<?php checked( ! empty( $qersh_wc_store['round_up_to_pound'] ) ); ?><?php disabled( $args['constant'] ); ?>> <?php esc_html_e( 'Round up to the next pound: accept a transfer of the amount left rounded up to a whole pound (wallet transfers made by code carry no piasters)', 'qersh-for-woocommerce' ); ?></label></td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Link with the connection key', 'qersh-for-woocommerce' ); ?></th>
			<td><label><input type="checkbox" name="allow_key_link" value="1"<?php checked( ! empty( $qersh_wc_store['allow_key_link'] ) ); ?><?php disabled( $args['constant'] ); ?>> <?php esc_html_e( 'Offer “Generate your developer\'s link with the connection key” on the wallet cards', 'qersh-for-woocommerce' ); ?></label></td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Transaction id', 'qersh-for-woocommerce' ); ?></th>
			<td>
				<label><input type="checkbox" name="txn_entry" value="1"<?php checked( ! empty( $qersh_wc_store['txn_entry'] ) ); ?><?php disabled( $args['constant'] ); ?>> <?php esc_html_e( 'Ask the customer for the transaction id', 'qersh-for-woocommerce' ); ?></label>
				<p class="description"><?php esc_html_e( 'After transferring, the customer can enter the transaction id from the transfer message on their order page, on every wallet. It confirms the order only for a transfer whose message does not show the sender\'s number, and only when the id is exactly the one in the message. When this is off, such transfers wait for your decision, and the ids customers already entered stay visible in the order\'s Qersh box.', 'qersh-for-woocommerce' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Cancelled orders', 'qersh-for-woocommerce' ); ?></th>
			<td><label><input type="checkbox" name="admin_cancel_email_on_expiry" value="1"<?php checked( ! empty( $qersh_wc_store['admin_cancel_email_on_expiry'] ) ); ?><?php disabled( $args['constant'] ); ?>> <?php esc_html_e( 'Do not send the administrator WooCommerce\'s “Cancelled order” email when the deadline alone cancels an order', 'qersh-for-woocommerce' ); ?></label></td>
		</tr>
		<tr>
			<th scope="row"><label for="qersh-retention"><?php esc_html_e( 'Unmatched transfers (days)', 'qersh-for-woocommerce' ); ?></label></th>
			<td>
				<input type="number" id="qersh-retention" name="unmatched_retention_days" min="1" max="3650" step="1" class="small-text" dir="ltr" value="<?php echo esc_attr( (string) $qersh_wc_store['unmatched_retention_days'] ); ?>"<?php disabled( $args['constant'] ); ?>>
				<p class="description"><?php esc_html_e( 'The payer\'s number and name of a transfer that paid no order are erased after this many days.', 'qersh-for-woocommerce' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Uninstall', 'qersh-for-woocommerce' ); ?></th>
			<td>
				<label><input type="checkbox" name="remove_data_on_uninstall" value="1"<?php checked( ! empty( $qersh_wc_store['remove_data_on_uninstall'] ) ); ?><?php disabled( $args['constant'] ); ?>> <?php esc_html_e( 'Delete the plugin\'s tables, settings and connection keys when the plugin is deleted', 'qersh-for-woocommerce' ); ?></label>
				<?php if ( $args['remove_forced'] ) : ?>
					<p class="description"><?php esc_html_e( 'QERSH_WC_REMOVE_ALL_DATA in wp-config.php decides this instead.', 'qersh-for-woocommerce' ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
	</table>
	<p class="description">
		<?php
		echo wp_kses(
			sprintf(
				/* translators: %s: link to the payment method settings. */
				esc_html__( 'The method\'s title, description and instructions are in %s.', 'qersh-for-woocommerce' ),
				'<a href="' . esc_url( $args['payments_url'] ) . '">' . esc_html__( 'WooCommerce → Settings → Payments → “Wallet transfer (Qersh)”', 'qersh-for-woocommerce' ) . '</a>'
			),
			View::allowed_html()
		);
		?>
	</p>
	<?php if ( ! $args['constant'] ) : ?>
		<p class="submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Save settings', 'qersh-for-woocommerce' ); ?></button></p>
	<?php endif; ?>
</form>

<h2><?php esc_html_e( 'Export and import', 'qersh-for-woocommerce' ); ?></h2>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<input type="hidden" name="action" value="qersh_wc_config_export">
	<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'qersh_wc_config_export' ) ); ?>">
	<p><button type="submit" class="button"><?php esc_html_e( 'Export settings', 'qersh-for-woocommerce' ); ?></button></p>
	<p class="description"><?php esc_html_e( 'A JSON file with the wallets and these settings, without connection keys and without the wallets\' state.', 'qersh-for-woocommerce' ); ?></p>
</form>
<?php if ( ! $args['constant'] ) : ?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="qersh_wc_config_import">
		<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'qersh_wc_config_import' ) ); ?>">
		<p><label for="qersh-import"><strong><?php esc_html_e( 'Import settings', 'qersh-for-woocommerce' ); ?></strong></label></p>
		<textarea id="qersh-import" name="config_json" rows="6" class="large-text code" dir="ltr" spellcheck="false" required></textarea>
		<p class="description"><?php esc_html_e( 'Paste an exported settings file. The imported wallets replace the current ones: a wallet missing from the file is deleted (you can restore it for 72 hours). Connection keys are never in the file.', 'qersh-for-woocommerce' ); ?></p>
		<p><button type="submit" class="button"><?php esc_html_e( 'Import settings', 'qersh-for-woocommerce' ); ?></button></p>
	</form>
<?php endif; ?>

<h2><?php esc_html_e( 'Before the first order', 'qersh-for-woocommerce' ); ?></h2>
<ul class="qersh-wc-list">
	<li><?php esc_html_e( '“Test connection” arrives even before Qersh is activated, but the phone sends no transfer before activation: it keeps them for up to 30 days and sends them after.', 'qersh-for-woocommerce' ); ?></li>
	<li><?php esc_html_e( 'For a wallet whose messages state the balance: on the wallet page open the ⋮ menu → “Sync balance” and enter your real balance from the wallet app or its balance code. Without it, Qersh waits up to 15 minutes before it reports the first transfers of the wallet to your store, and asks you to check the balance; answer in “Confirm your balance” within that time and the order is confirmed on its own, otherwise the transfer goes to “Needs a decision”.', 'qersh-for-woocommerce' ); ?></li>
	<li><?php esc_html_e( 'A transfer larger than the “Auto-confirm limit (EGP)” in the wallet settings on the phone (5,000 by default) always goes to “Needs a decision”: raise it there if your orders are larger.', 'qersh-for-woocommerce' ); ?></li>
	<li><?php esc_html_e( 'Turn the method on in WooCommerce → Settings → Payments → “Wallet transfer (Qersh)”.', 'qersh-for-woocommerce' ); ?></li>
	<li><?php esc_html_e( 'Place a small test order and send a real transfer: the order turns “Processing” within seconds (or after your answer in “Confirm your balance”), and the phone shows the order number next to the transfer.', 'qersh-for-woocommerce' ); ?></li>
</ul>

<h2><?php esc_html_e( 'Every day', 'qersh-for-woocommerce' ); ?></h2>
<ul class="qersh-wc-list">
	<li><?php esc_html_e( 'The Transfers tab lists Needs a decision, Unmatched, Personal transfers, Matched and All, and the menu shows how many need a decision. Transfers matched by the transaction id the customer entered carry the badge “By transaction id”.', 'qersh-for-woocommerce' ); ?></li>
	<li><?php esc_html_e( 'Each order paid by wallet transfer has a Qersh box: the payer number, the wallet, the deadline, the transfers applied, the forgery flag, the last delivery from the phone, and the transaction id the customer entered, if any.', 'qersh-for-woocommerce' ); ?></li>
	<li><?php esc_html_e( '“Generate a new key” and “Send this wallet\'s link to your developer” on the phone each make a new key, and the phone signs with the new and the old key for 24 hours: paste the latest message within that time, or generate your developer\'s link with the connection key. If the store still matches the old key, the wallet card tells you.', 'qersh-for-woocommerce' ); ?></li>
	<li><?php esc_html_e( 'If you change the store\'s domain, www or permalinks, open your new developer\'s link on the phone once; the alert says when.', 'qersh-for-woocommerce' ); ?></li>
</ul>
