<?php
/**
 * The Status tab: "Check your store URL" and what the plugin knows about the site (docs/17 §4.9).
 *
 * @package QershPay\WooCommerce
 *
 * @var array $args `wallets` (id => name), `selected`, `result` (null or Status::result_view()), `alerts`, `facts`.
 */

use QershPay\WooCommerce\Admin\View;

defined( 'ABSPATH' ) || exit;
?>
<h2><?php esc_html_e( 'Check your store URL', 'qersh-for-woocommerce' ); ?></h2>
<p><?php esc_html_e( 'Sends empty test requests, with no order or customer data, to your own store\'s addresses (through your CDN or firewall, such as Cloudflare, if you use one) and names what blocks them. It runs only when you click, and sends nothing anywhere else.', 'qersh-for-woocommerce' ); ?></p>
<?php if ( empty( $args['wallets'] ) ) : ?>
	<p><?php esc_html_e( 'Add a wallet first.', 'qersh-for-woocommerce' ); ?></p>
<?php else : ?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="qersh-wc-selftest-form">
		<input type="hidden" name="action" value="qersh_wc_self_test">
		<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'qersh_wc_self_test' ) ); ?>">
		<label for="qersh-selftest-wallet" class="screen-reader-text"><?php esc_html_e( 'Wallet', 'qersh-for-woocommerce' ); ?></label>
		<select id="qersh-selftest-wallet" name="wallet">
			<?php foreach ( $args['wallets'] as $qersh_wc_id => $qersh_wc_name ) : ?>
				<option value="<?php echo esc_attr( $qersh_wc_id ); ?>"<?php selected( $qersh_wc_id, $args['selected'] ); ?>><?php echo esc_html( $qersh_wc_name ); ?></option>
			<?php endforeach; ?>
		</select>
		<button type="submit" class="button button-primary"><?php esc_html_e( 'Check your store URL', 'qersh-for-woocommerce' ); ?></button>
	</form>
<?php endif; ?>

<?php if ( null !== $args['result'] ) : ?>
	<section class="qersh-wc-card">
		<h3>
			<?php
			echo wp_kses(
				sprintf(
					/* translators: 1: wallet name, 2: date and time. */
					esc_html__( 'Result for %1$s, %2$s', 'qersh-for-woocommerce' ),
					esc_html( $args['result']['wallet'] ),
					$args['result']['at']
				),
				View::allowed_html()
			);
			?>
		</h3>
		<table class="widefat striped qersh-wc-probes">
			<tbody>
				<?php foreach ( $args['result']['probes'] as $qersh_wc_probe ) : ?>
					<tr>
						<th scope="row"><?php echo esc_html( $qersh_wc_probe['name'] ); ?><br><code dir="ltr" class="qersh-wc-url"><?php echo esc_html( $qersh_wc_probe['url'] ); ?></code></th>
						<td class="<?php echo $qersh_wc_probe['ok'] ? 'qersh-wc-ok' : 'qersh-wc-bad'; ?>"><?php echo $qersh_wc_probe['ok'] ? '✓ ' : '✗ '; ?><?php echo wp_kses( $qersh_wc_probe['text'], View::allowed_html() ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php foreach ( $args['result']['extra'] as $qersh_wc_extra ) : ?>
			<p><?php echo wp_kses( $qersh_wc_extra, View::allowed_html() ); ?></p>
		<?php endforeach; ?>
		<?php if ( null !== $args['result']['link'] ) : ?>
			<p><code dir="ltr" class="qersh-wc-link"><?php echo esc_html( $args['result']['link'] ); ?></code></p>
			<p>
				<button type="button" class="button button-small qersh-wc-copy" data-clipboard-text="<?php echo esc_attr( $args['result']['link'] ); ?>"><?php esc_html_e( 'Copy link', 'qersh-for-woocommerce' ); ?></button>
				<button type="button" class="button button-small qersh-wc-qr-toggle" aria-controls="qersh-qr-fallback" aria-expanded="false"><?php esc_html_e( 'Show QR code', 'qersh-for-woocommerce' ); ?></button>
			</p>
			<canvas class="qersh-wc-qr" id="qersh-qr-fallback" data-qersh-qr="<?php echo esc_attr( $args['result']['link'] ); ?>" hidden></canvas>
		<?php endif; ?>
	</section>
<?php endif; ?>

<h2><?php esc_html_e( 'Alerts', 'qersh-for-woocommerce' ); ?></h2>
<?php if ( empty( $args['alerts'] ) ) : ?>
	<p><?php esc_html_e( 'Nothing to report about the site.', 'qersh-for-woocommerce' ); ?></p>
<?php else : ?>
	<?php foreach ( $args['alerts'] as $qersh_wc_alert ) : ?>
		<div class="notice notice-<?php echo esc_attr( 'error' === $qersh_wc_alert['level'] ? 'error' : ( 'warning' === $qersh_wc_alert['level'] ? 'warning' : 'info' ) ); ?> inline"><p><?php echo wp_kses( $qersh_wc_alert['message'], View::allowed_html() ); ?></p></div>
	<?php endforeach; ?>
<?php endif; ?>

<h2><?php esc_html_e( 'This site', 'qersh-for-woocommerce' ); ?></h2>
<table class="widefat striped qersh-wc-facts">
	<tbody>
		<?php foreach ( $args['facts'] as $qersh_wc_fact ) : ?>
			<tr<?php echo $qersh_wc_fact['warn'] ? ' class="qersh-wc-warn"' : ''; ?>>
				<th scope="row"><?php echo esc_html( $qersh_wc_fact['label'] ); ?></th>
				<td><?php echo wp_kses( $qersh_wc_fact['value'], View::allowed_html() ); ?></td>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>
