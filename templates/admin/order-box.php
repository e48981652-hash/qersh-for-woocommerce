<?php
/**
 * The "Qersh" box on an order paid by wallet transfer (docs/17 §5 step 9).
 *
 * @package QershPay\WooCommerce
 *
 * @var array $args See Admin\OrderBox::args().
 */

use QershPay\WooCommerce\Admin\View;

defined( 'ABSPATH' ) || exit;
?>
<div class="qersh-wc-order-box">
	<?php if ( null !== $args['flag'] ) : ?>
		<p class="qersh-wc-flag"><strong><?php echo wp_kses( $args['flag'], View::allowed_html() ); ?></strong></p>
	<?php endif; ?>
	<ul class="qersh-wc-lines">
		<?php foreach ( $args['lines'] as $qersh_wc_line ) : ?>
			<li><?php echo wp_kses( $qersh_wc_line, View::allowed_html() ); ?></li>
		<?php endforeach; ?>
	</ul>
	<?php if ( ! empty( $args['transfers'] ) ) : ?>
		<h4><?php esc_html_e( 'Transfers applied', 'qersh-for-woocommerce' ); ?></h4>
		<ul class="qersh-wc-lines">
			<?php foreach ( $args['transfers'] as $qersh_wc_transfer ) : ?>
				<li>
					<?php echo wp_kses( $qersh_wc_transfer['amount'] . ' · ' . $qersh_wc_transfer['time'] . ' · ' . $qersh_wc_transfer['status'], View::allowed_html() ); ?>
					<br><span class="qersh-wc-muted"><?php echo wp_kses( $qersh_wc_transfer['id'] . ( '' !== $qersh_wc_transfer['txn'] ? ' · ' . $qersh_wc_transfer['txn'] : '' ), View::allowed_html() ); ?></span>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
	<p><a href="<?php echo esc_url( $args['list_url'] ); ?>"><?php esc_html_e( 'Open in Transfers', 'qersh-for-woocommerce' ); ?></a></p>
</div>
