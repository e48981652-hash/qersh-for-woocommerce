<?php
/**
 * The Transfers tab (docs/17 §4.6, §5 step 9).
 *
 * @package QershPay\WooCommerce
 *
 * @var array $args `views`, `view`, `search`, `table` (TransfersTable, prepared), `confirm` (null or the attach
 *                  confirmation: `transfer`, `order_id`, `order`, `amount`, `warnings`).
 */

use QershPay\WooCommerce\Admin\Module;
use QershPay\WooCommerce\Admin\View;

defined( 'ABSPATH' ) || exit;
?>
<?php if ( null !== $args['confirm'] ) : ?>
	<section class="qersh-wc-card qersh-wc-dialog" role="dialog" aria-labelledby="qersh-attach-title">
		<h2 id="qersh-attach-title">
			<?php
			echo wp_kses(
				sprintf(
					/* translators: 1: amount, 2: order number. */
					esc_html__( 'Attach the transfer of %1$s to order %2$s?', 'qersh-for-woocommerce' ),
					$args['confirm']['amount'],
					$args['confirm']['order']
				),
				View::allowed_html()
			);
			?>
		</h2>
		<?php foreach ( $args['confirm']['warnings'] as $qersh_wc_warning ) : ?>
			<div class="notice notice-warning inline"><p><?php echo wp_kses( $qersh_wc_warning, View::allowed_html() ); ?></p></div>
		<?php endforeach; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="qersh_wc_transfer_attach">
			<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'qersh_wc_transfer_attach' ) ); ?>">
			<input type="hidden" name="transfer" value="<?php echo esc_attr( $args['confirm']['transfer'] ); ?>">
			<input type="hidden" name="order_id" value="<?php echo esc_attr( (string) $args['confirm']['order_id'] ); ?>">
			<input type="hidden" name="view" value="<?php echo esc_attr( $args['view'] ); ?>">
			<input type="hidden" name="confirm" value="1">
			<p class="submit">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Attach anyway', 'qersh-for-woocommerce' ); ?></button>
				<a class="button" href="<?php echo esc_url( Module::page_url( 'transfers', array( 'view' => $args['view'] ) ) ); ?>"><?php esc_html_e( 'Cancel', 'qersh-for-woocommerce' ); ?></a>
			</p>
		</form>
	</section>
<?php endif; ?>

<ul class="subsubsub">
	<?php
	$qersh_wc_links = array();
	foreach ( $args['views'] as $qersh_wc_key => $qersh_wc_view ) {
		$qersh_wc_links[] = '<li><a href="' . esc_url( $qersh_wc_view['url'] ) . '"' . ( $qersh_wc_view['current'] ? ' class="current" aria-current="page"' : '' ) . '>' . esc_html( $qersh_wc_view['label'] ) . ' <span class="count">(' . esc_html( number_format_i18n( $qersh_wc_view['count'] ) ) . ')</span></a>';
	}
	echo wp_kses_post( implode( ' |</li>', $qersh_wc_links ) . '</li>' );
	?>
</ul>

<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="qersh-wc-search">
	<input type="hidden" name="page" value="<?php echo esc_attr( Module::SLUG ); ?>">
	<input type="hidden" name="tab" value="transfers">
	<input type="hidden" name="view" value="<?php echo esc_attr( $args['view'] ); ?>">
	<p class="search-box">
		<label class="screen-reader-text" for="qersh-transfer-search"><?php esc_html_e( 'Search transfers', 'qersh-for-woocommerce' ); ?></label>
		<input type="search" id="qersh-transfer-search" name="s" dir="auto" value="<?php echo esc_attr( $args['search'] ); ?>" placeholder="<?php esc_attr_e( 'Payer number, name, transaction id', 'qersh-for-woocommerce' ); ?>">
		<button type="submit" class="button"><?php esc_html_e( 'Search transfers', 'qersh-for-woocommerce' ); ?></button>
	</p>
</form>

<div class="qersh-wc-transfers">
	<?php $args['table']->display(); ?>
</div>
