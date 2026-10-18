<?php
/**
 * One wallet's card: its state, the store URL, the developer's links and the connection key (docs/17 §5 step 2).
 * The link with the connection key is never printed here: admin.js asks for it and shows it in its own window.
 *
 * @package QershPay\WooCommerce
 *
 * @var array $args See Admin\Wallets::card().
 */

use QershPay\WooCommerce\Admin\View;

defined( 'ABSPATH' ) || exit;

$qersh_wc_id = $args['id'];
?>
<section class="qersh-wc-card" id="qersh-wallet-<?php echo esc_attr( $qersh_wc_id ); ?>" data-qersh-wallet="<?php echo esc_attr( $qersh_wc_id ); ?>">
	<header class="qersh-wc-card-head">
		<h2><?php echo esc_html( $args['label'] ); ?></h2>
		<?php // The name and the number customers transfer to; the plugin knows no wallet type. A wallet without a name is called by its number, shown once. ?>
		<?php if ( $args['named'] ) : ?>
			<span class="qersh-wc-muted"><?php echo wp_kses( View::ltr( $args['address'] ), View::allowed_html() ); ?></span>
		<?php endif; ?>
		<?php if ( ! $args['enabled'] ) : ?>
			<mark class="qersh-wc-badge"><?php esc_html_e( 'Off at checkout', 'qersh-for-woocommerce' ); ?></mark>
		<?php endif; ?>
		<?php if ( $args['editable'] ) : ?>
			<span class="qersh-wc-card-links">
				<a href="<?php echo esc_url( $args['edit_url'] ); ?>"><?php esc_html_e( 'Edit', 'qersh-for-woocommerce' ); ?></a>
				<a class="qersh-wc-danger-link" href="<?php echo esc_url( $args['delete_url'] ); ?>"><?php esc_html_e( 'Delete', 'qersh-for-woocommerce' ); ?></a>
			</span>
		<?php endif; ?>
	</header>

	<?php foreach ( $args['alerts'] as $qersh_wc_alert ) : ?>
		<div class="notice notice-<?php echo esc_attr( 'error' === $qersh_wc_alert['level'] ? 'error' : ( 'warning' === $qersh_wc_alert['level'] ? 'warning' : 'info' ) ); ?> inline"><p><?php echo wp_kses( $qersh_wc_alert['message'], View::allowed_html() ); ?></p></div>
	<?php endforeach; ?>
	<?php foreach ( $args['warnings'] as $qersh_wc_warning ) : ?>
		<div class="notice notice-warning inline"><p><?php echo wp_kses( $qersh_wc_warning, View::allowed_html() ); ?></p></div>
	<?php endforeach; ?>

	<ul class="qersh-wc-lines">
		<?php foreach ( $args['lines'] as $qersh_wc_line ) : ?>
			<li><?php echo wp_kses( $qersh_wc_line, View::allowed_html() ); ?></li>
		<?php endforeach; ?>
	</ul>

	<?php if ( ! empty( $args['advice'] ) ) : ?>
		<div class="qersh-wc-advice">
			<?php foreach ( $args['advice'] as $qersh_wc_advice ) : ?>
				<p><?php echo wp_kses( $qersh_wc_advice, View::allowed_html() ); ?></p>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<div class="qersh-wc-field">
		<h3><?php esc_html_e( 'Your store URL', 'qersh-for-woocommerce' ); ?></h3>
		<p><code dir="ltr" class="qersh-wc-url"><?php echo esc_html( $args['store_url'] ); ?></code>
			<button type="button" class="button button-small qersh-wc-copy" data-clipboard-text="<?php echo esc_attr( $args['store_url'] ); ?>"><?php esc_html_e( 'Copy', 'qersh-for-woocommerce' ); ?></button></p>
	</div>

	<?php if ( $args['key_link'] ) : ?>
		<div class="qersh-wc-field">
			<p><button type="button" class="button button-primary qersh-wc-keylink" data-qersh-keylink="<?php echo esc_attr( $qersh_wc_id ); ?>"><?php esc_html_e( 'Generate your developer\'s link with the connection key', 'qersh-for-woocommerce' ); ?></button></p>
			<p class="qersh-wc-muted"><?php esc_html_e( 'The connection key is made in your store and shown once.', 'qersh-for-woocommerce' ); ?></p>
			<p class="qersh-wc-keylink-error" role="alert" hidden></p>
		</div>
	<?php endif; ?>

	<?php if ( null !== $args['pending'] ) : ?>
		<div class="qersh-wc-pending">
			<p>
				<?php
				echo wp_kses(
					sprintf(
						/* translators: 1: date and time, 2: key fingerprint. */
						esc_html__( 'Pending key since %1$s (fingerprint %2$s): it becomes the connection key when the phone first delivers with it, usually at “Test connection”.', 'qersh-for-woocommerce' ),
						View::ltr( View::time( $args['pending']['at'] ) ),
						View::ltr( $args['pending']['fp'] )
					),
					View::allowed_html()
				);
				?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="qersh_wc_key_pending_cancel">
				<input type="hidden" name="wallet" value="<?php echo esc_attr( $qersh_wc_id ); ?>">
				<input type="hidden" name="fp" value="<?php echo esc_attr( $args['pending']['fp'] ); ?>">
				<input type="hidden" name="created_at" value="<?php echo esc_attr( (string) $args['pending']['at'] ); ?>">
				<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'qersh_wc_key_pending_cancel' ) ); ?>">
				<button type="submit" class="button"><?php esc_html_e( 'Cancel this link', 'qersh-for-woocommerce' ); ?></button>
			</form>
		</div>
	<?php endif; ?>

	<details class="qersh-wc-another"<?php echo $args['key_link'] ? '' : ' open'; ?>>
		<summary><?php esc_html_e( 'Another way: your developer\'s link (paste it in the Qersh app: “Add wallet” → “Have a link from your developer?”)', 'qersh-for-woocommerce' ); ?></summary>
		<?php if ( null !== $args['keyless'] ) : ?>
			<p><code dir="ltr" class="qersh-wc-link"><?php echo esc_html( $args['keyless'] ); ?></code></p>
			<p>
				<button type="button" class="button button-small qersh-wc-copy" data-clipboard-text="<?php echo esc_attr( $args['keyless'] ); ?>"><?php esc_html_e( 'Copy link', 'qersh-for-woocommerce' ); ?></button>
				<button type="button" class="button button-small qersh-wc-qr-toggle" aria-controls="qersh-qr-<?php echo esc_attr( $qersh_wc_id ); ?>" aria-expanded="false"><?php esc_html_e( 'Show QR code', 'qersh-for-woocommerce' ); ?></button>
			</p>
			<canvas class="qersh-wc-qr" id="qersh-qr-<?php echo esc_attr( $qersh_wc_id ); ?>" data-qersh-qr="<?php echo esc_attr( $args['keyless'] ); ?>" hidden></canvas>
		<?php endif; ?>

		<?php if ( $args['paste'] ) : ?>
			<form method="post" class="qersh-wc-paste" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-qersh-pending="<?php echo null !== $args['pending'] ? '1' : '0'; ?>">
				<input type="hidden" name="action" value="qersh_wc_key_paste">
				<input type="hidden" name="wallet" value="<?php echo esc_attr( $qersh_wc_id ); ?>">
				<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'qersh_wc_key_paste' ) ); ?>">
				<p><label for="qersh-paste-<?php echo esc_attr( $qersh_wc_id ); ?>"><strong><?php esc_html_e( 'Connection key or the wallet link from the phone', 'qersh-for-woocommerce' ); ?></strong></label></p>
				<?php // A write-only field (docs/17 §10 #6). admin.js keeps a pasted multi-line message out of the page and adds it to the form only when it is sent. ?>
				<input type="password" id="qersh-paste-<?php echo esc_attr( $qersh_wc_id ); ?>" name="qersh_key" dir="ltr" class="qersh-wc-secret-field large-text" autocomplete="off" spellcheck="false" autocapitalize="off" data-lpignore="true" data-1p-ignore="true" data-bwignore="true" required>
				<p class="qersh-wc-paste-status" role="status" aria-live="polite" hidden></p>
				<p class="description"><?php esc_html_e( 'Paste the whole message the phone showed after saving, the link alone, the line QERSH_WEBHOOK_SECRET=…, or the key itself. Only its fingerprint is shown after saving.', 'qersh-for-woocommerce' ); ?></p>
				<?php if ( null !== $args['pending'] ) : ?>
					<p><label><input type="checkbox" name="confirm_replace" value="1" class="qersh-wc-confirm-replace"> <?php esc_html_e( 'You have a link with the connection key that nothing has arrived with yet. Pasting this message cancels it and makes the pasted key the connection key. Continue?', 'qersh-for-woocommerce' ); ?></label></p>
				<?php endif; ?>
				<p><button type="submit" class="button"><?php esc_html_e( 'Save the connection key', 'qersh-for-woocommerce' ); ?></button></p>
			</form>
		<?php endif; ?>
	</details>

	<form method="post" class="qersh-wc-selftest" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="qersh_wc_self_test">
		<input type="hidden" name="wallet" value="<?php echo esc_attr( $qersh_wc_id ); ?>">
		<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'qersh_wc_self_test' ) ); ?>">
		<button type="submit" class="button-link"><?php esc_html_e( 'Check your store URL', 'qersh-for-woocommerce' ); ?></button>
	</form>
</section>
