<?php
/**
 * Customer email "Transfer instructions for your order #N" (HTML).
 *
 * Override it by copying it to yourtheme/woocommerce/emails/instructions.php.
 *
 * @package QershPay\WooCommerce
 *
 * @var WC_Order   $order              Order.
 * @var array|null $box                Checkout::box(), or null (a preview with another order).
 * @var int        $now                UTC seconds.
 * @var string     $email_heading      Heading.
 * @var string     $additional_content Content below.
 * @var bool       $sent_to_admin      False.
 * @var bool       $plain_text         False.
 * @var WC_Email   $email              Email object.
 */

defined( 'ABSPATH' ) || exit;

use QershPay\WooCommerce\Checkout;
use QershPay\WooCommerce\Format;

$qersh_wc_allowed = Format::allowed_html();
$qersh_wc_ctx     = Format::EMAIL_HTML;

/**
 * WooCommerce's email header (WC_Emails::email_header()).
 * This action is documented in woocommerce/templates/emails/customer-processing-order.php.
 */
do_action( 'woocommerce_email_header', $email_heading, $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's email hook.
?>

<p>
	<?php
	/* translators: %s: order number. */
	echo esc_html( sprintf( __( 'Your order #%s is waiting for your transfer. Send the amount below from your wallet before the deadline.', 'qersh-for-woocommerce' ), $order->get_order_number() ) );
	?>
</p>

<?php if ( is_array( $box ) ) : ?>
	<ul>
		<li>
			<?php
			echo wp_kses(
				sprintf(
					/* translators: %s: amount to transfer. */
					esc_html__( 'Amount: %s', 'qersh-for-woocommerce' ),
					'<strong>' . Format::price( $box['due_minor'], $qersh_wc_ctx, $box['currency'] ) . '</strong>'
				),
				$qersh_wc_allowed
			);
			if ( $box['rounded'] ) {
				echo '<br />';
				if ( $box['paid_minor'] > 0 ) {
					/* translators: 1: what remains of the order, with piasters, 2: the same rounded up to the pound. */
					$qersh_wc_text = esc_html__( 'The rest of your order is %1$s, rounded up to %2$s because wallet transfers made by code accept whole pounds only.', 'qersh-for-woocommerce' );
				} else {
					/* translators: 1: order total with piasters, 2: the total rounded up to the pound. */
					$qersh_wc_text = esc_html__( 'Your order total is %1$s, rounded up to %2$s because wallet transfers made by code accept whole pounds only.', 'qersh-for-woocommerce' );
				}
				echo wp_kses( sprintf( $qersh_wc_text, Format::price( $box['exact_minor'], $qersh_wc_ctx, $box['currency'] ), Format::price( $box['due_minor'], $qersh_wc_ctx, $box['currency'] ) ), $qersh_wc_allowed );
			}
			?>
		</li>
		<?php if ( '' !== $box['address'] ) : ?>
			<li>
				<?php
				$qersh_wc_to = '<strong>' . Format::ltr( Format::number( $box['address'] ), $qersh_wc_ctx ) . '</strong>';
				if ( '' !== $box['wallet_label'] ) {
					/* translators: 1: the store's wallet number, 2: the name the store gave that wallet. */
					$qersh_wc_line = sprintf( esc_html__( 'Send to: %1$s (%2$s)', 'qersh-for-woocommerce' ), $qersh_wc_to, esc_html( $box['wallet_label'] ) );
				} else {
					/* translators: %s: the store's wallet number. */
					$qersh_wc_line = sprintf( esc_html__( 'Send to: %s', 'qersh-for-woocommerce' ), $qersh_wc_to );
				}
				echo wp_kses( $qersh_wc_line, $qersh_wc_allowed );
				?>
			</li>
		<?php endif; ?>
		<?php if ( '' !== $box['payer'] ) : ?>
			<li>
				<?php
				/* translators: %s: the customer's wallet number. */
				$qersh_wc_line = sprintf( esc_html__( 'From your number: %s', 'qersh-for-woocommerce' ), '<strong>' . Format::ltr( Format::number( $box['payer'] ), $qersh_wc_ctx ) . '</strong>' );
				echo wp_kses( $qersh_wc_line, $qersh_wc_allowed );
				?>
			</li>
		<?php endif; ?>
		<?php if ( null !== $box['deadline'] ) : ?>
			<li>
				<?php
				/* translators: %s: deadline date and time in Cairo. */
				$qersh_wc_line = sprintf( esc_html__( 'Before: %s Cairo time', 'qersh-for-woocommerce' ), '<strong>' . Format::ltr( Format::cairo_time( $box['deadline'], $now, true ), $qersh_wc_ctx ) . '</strong>' );
				echo wp_kses( $qersh_wc_line, $qersh_wc_allowed );
				?>
			</li>
		<?php endif; ?>
	</ul>

	<p><?php echo esc_html( $box['confirm'] ); ?></p>

	<?php if ( $box['txn_open'] ) : ?>
		<?php // The link to the id box: the id is optional (the confirm line above says when it helps), never an order. ?>
		<p>
			<?php
			$qersh_wc_url = Checkout::txn_page_url( $order );
			echo wp_kses(
				sprintf(
					/* translators: %s: link to the order page. */
					esc_html__( 'If the store cannot see the number you transferred from, you can enter the transaction id on your order page: %s', 'qersh-for-woocommerce' ),
					'<a href="' . esc_url( $qersh_wc_url ) . '">' . esc_html__( 'open your order page', 'qersh-for-woocommerce' ) . '</a>'
				),
				$qersh_wc_allowed
			);
			?>
		</p>
	<?php endif; ?>

	<?php if ( '' !== trim( $box['instructions'] ) ) : ?>
		<?php echo wp_kses_post( wpautop( wptexturize( $box['instructions'] ) ) ); ?>
	<?php endif; ?>
<?php endif; ?>

<?php
/**
 * WooCommerce's order details table (WC_Emails::order_details()).
 * This action is documented in woocommerce/templates/emails/customer-processing-order.php.
 */
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's email hook.

/**
 * WooCommerce's order meta (WC_Emails::order_meta()).
 * This action is documented in woocommerce/templates/emails/customer-processing-order.php.
 */
do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's email hook.

/**
 * WooCommerce's customer details (WC_Emails::customer_details(), WC_Emails::email_address()).
 * This action is documented in woocommerce/templates/emails/customer-processing-order.php.
 */
do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's email hook.

if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

/**
 * WooCommerce's email footer (WC_Emails::email_footer()).
 * This action is documented in woocommerce/templates/emails/customer-processing-order.php.
 */
do_action( 'woocommerce_email_footer', $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's email hook.
