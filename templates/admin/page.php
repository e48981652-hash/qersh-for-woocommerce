<?php
/**
 * WooCommerce → Qersh: the page around the tabs.
 *
 * @package QershPay\WooCommerce
 *
 * @var array $args `tab`, `tabs`, `badge`, `flash`, `content` (the tab, escaped by its own template).
 */

use QershPay\WooCommerce\Admin\Module;
use QershPay\WooCommerce\Admin\View;

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap qersh-wc">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Qersh', 'qersh-for-woocommerce' ); ?></h1>
	<p class="qersh-wc-disclosure"><?php echo wp_kses( Module::disclosure(), View::allowed_html() ); ?></p>
	<hr class="wp-header-end">
	<?php foreach ( $args['flash'] as $qersh_wc_flash ) : ?>
		<div class="notice notice-<?php echo esc_attr( $qersh_wc_flash['type'] ); ?>"><p><?php echo wp_kses( $qersh_wc_flash['message'], View::allowed_html() ); ?></p></div>
	<?php endforeach; ?>
	<nav class="nav-tab-wrapper woo-nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Qersh sections', 'qersh-for-woocommerce' ); ?>">
		<?php foreach ( $args['tabs'] as $qersh_wc_tab => $qersh_wc_label ) : ?>
			<a href="<?php echo esc_url( Module::page_url( $qersh_wc_tab ) ); ?>" class="nav-tab<?php echo $qersh_wc_tab === $args['tab'] ? ' nav-tab-active' : ''; ?>"<?php echo $qersh_wc_tab === $args['tab'] ? ' aria-current="page"' : ''; ?>>
				<?php echo esc_html( $qersh_wc_label ); ?>
				<?php if ( 'transfers' === $qersh_wc_tab && $args['badge'] > 0 ) : ?>
					<span class="qersh-wc-count"><?php echo esc_html( number_format_i18n( (int) $args['badge'] ) ); ?></span>
				<?php endif; ?>
			</a>
		<?php endforeach; ?>
	</nav>
	<div class="qersh-wc-tab">
		<?php
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the tab's own template escaped every value when it was rendered just before.
		echo $args['content'];
		?>
	</div>
</div>
