<?php
/**
 * The transfer box on the order-received page (classic and Blocks): labelled lines whose values are isolated
 * (docs/17 §6.2, §7), copy buttons for the number and the amount, the transaction-id section when the order page
 * takes one (the box's `txn_open`, the same gate for every wallet), and the live status line that thankyou.js keeps
 * up to date.
 *
 * Override it by copying it to yourtheme/qersh-for-woocommerce/order/thankyou-box.php. Keep the data- attributes.
 *
 * @package QershPay\WooCommerce
 *
 * @var WC_Order $order  Order.
 * @var array    $box    Checkout::box().
 * @var array    $script Checkout::thankyou_script_data().
 */

defined( 'ABSPATH' ) || exit;

use QershPay\WooCommerce\Checkout;
use QershPay\WooCommerce\Format;

$qersh_wc_allowed = Format::allowed_html();
$qersh_wc_now     = (int) $script['now'];
$qersh_wc_lines   = Checkout::status_lines();
$qersh_wc_key     = Checkout::status_line_key( $box['status'], $box['claim'] );
$qersh_wc_nudge   = Checkout::nudge_key( $box['status'], $qersh_wc_now >= (int) $script['nudge_at'], $box['txn_open'], $box['claim'] );
$qersh_wc_nudge   = '' === $qersh_wc_nudge ? '' : $script['strings'][ $qersh_wc_nudge ];
$qersh_wc_copy    = $script['strings']['copy'];
?>
<section class="qersh-box" id="qersh-box" data-qersh-box data-qersh="<?php echo esc_attr( (string) wp_json_encode( $script ) ); ?>">
	<h2 class="qersh-box__title"><?php esc_html_e( 'Your transfer', 'qersh-for-woocommerce' ); ?></h2>

	<?php if ( $box['pending'] ) : ?>
		<ul class="qersh-box__lines" data-qersh-pending>
			<li class="qersh-line qersh-line--amount" data-qersh-amount>
				<?php
				echo wp_kses(
					sprintf(
						/* translators: %s: amount to transfer. */
						esc_html__( 'Amount: %s', 'qersh-for-woocommerce' ),
						'<strong>' . Format::price( $box['due_minor'], Format::HTML, $box['currency'] ) . '</strong>'
					),
					$qersh_wc_allowed
				);
				?>
				<button type="button" class="qersh-copy" data-qersh-copy="<?php echo esc_attr( Format::amount_raw( $box['due_minor'] ) ); ?>" aria-label="<?php esc_attr_e( 'Copy the amount', 'qersh-for-woocommerce' ); ?>" hidden><?php echo esc_html( $qersh_wc_copy ); ?></button>
				<?php if ( $box['rounded'] ) : ?>
					<span class="qersh-note">
						<?php
						if ( $box['paid_minor'] > 0 ) {
							/* translators: 1: what remains of the order, with piasters, 2: the same rounded up to the pound. */
							$qersh_wc_text = esc_html__( 'The rest of your order is %1$s, rounded up to %2$s because wallet transfers made by code accept whole pounds only.', 'qersh-for-woocommerce' );
						} else {
							/* translators: 1: order total with piasters, 2: the total rounded up to the pound. */
							$qersh_wc_text = esc_html__( 'Your order total is %1$s, rounded up to %2$s because wallet transfers made by code accept whole pounds only.', 'qersh-for-woocommerce' );
						}
						echo wp_kses( sprintf( $qersh_wc_text, Format::price( $box['exact_minor'], Format::HTML, $box['currency'] ), Format::price( $box['due_minor'], Format::HTML, $box['currency'] ) ), $qersh_wc_allowed );
						?>
					</span>
				<?php endif; ?>
			</li>

			<?php if ( '' !== $box['address'] ) : ?>
				<li class="qersh-line qersh-line--to">
					<?php
					$qersh_wc_to = '<strong>' . Format::ltr( Format::number( $box['address'] ) ) . '</strong>';
					if ( '' !== $box['wallet_label'] ) {
						/* translators: 1: the store's wallet number, 2: the name the store gave that wallet. */
						$qersh_wc_line = sprintf( esc_html__( 'Send to: %1$s (%2$s)', 'qersh-for-woocommerce' ), $qersh_wc_to, esc_html( $box['wallet_label'] ) );
					} else {
						/* translators: %s: the store's wallet number. */
						$qersh_wc_line = sprintf( esc_html__( 'Send to: %s', 'qersh-for-woocommerce' ), $qersh_wc_to );
					}
					echo wp_kses( $qersh_wc_line, $qersh_wc_allowed );
					?>
					<button type="button" class="qersh-copy" data-qersh-copy="<?php echo esc_attr( $box['address'] ); ?>" aria-label="<?php esc_attr_e( 'Copy the number to send to', 'qersh-for-woocommerce' ); ?>" hidden><?php echo esc_html( $qersh_wc_copy ); ?></button>
				</li>
			<?php endif; ?>

			<?php if ( '' !== $box['payer'] ) : ?>
				<li class="qersh-line qersh-line--from">
					<?php
					/* translators: %s: the customer's wallet number. */
					$qersh_wc_line = sprintf( esc_html__( 'From your number: %s', 'qersh-for-woocommerce' ), '<strong>' . Format::ltr( Format::number( $box['payer'] ) ) . '</strong>' );
					echo wp_kses( $qersh_wc_line, $qersh_wc_allowed );
					?>
				</li>
			<?php endif; ?>

			<?php if ( null !== $box['deadline'] ) : ?>
				<?php // data-qersh-left: thankyou.js drops "(within …)" once that duration would read differently. ?>
				<li class="qersh-line qersh-line--before"<?php echo $box['deadline'] > $qersh_wc_now ? ' data-qersh-left' : ''; ?>>
					<?php
					$qersh_wc_time = '<strong>' . Format::isolate( esc_html( Format::cairo_time( $box['deadline'], $qersh_wc_now ) ), Format::HTML, ' data-qersh-time="' . esc_attr( (string) $box['deadline'] ) . '"' ) . '</strong>';
					if ( $box['deadline'] > $qersh_wc_now ) {
						/* translators: 1: deadline time in Cairo, 2: how long until then, e.g. "2 hours". */
						$qersh_wc_line = sprintf( esc_html__( 'Before: %1$s Cairo time (within %2$s)', 'qersh-for-woocommerce' ), $qersh_wc_time, esc_html( human_time_diff( $qersh_wc_now, $box['deadline'] ) ) );
					} else {
						/* translators: %s: deadline time in Cairo. */
						$qersh_wc_line = sprintf( esc_html__( 'Before: %s Cairo time', 'qersh-for-woocommerce' ), $qersh_wc_time );
					}
					echo wp_kses( $qersh_wc_line, $qersh_wc_allowed );
					?>
				</li>
			<?php endif; ?>
		</ul>

		<p class="qersh-confirm" data-qersh-pending><?php echo esc_html( $box['confirm'] ); ?></p>

		<?php if ( '' !== trim( $box['instructions'] ) ) : ?>
			<div class="qersh-instructions" data-qersh-pending><?php echo wp_kses_post( wpautop( wptexturize( $box['instructions'] ) ) ); ?></div>
		<?php endif; ?>
	<?php endif; ?>

	<?php
	if ( $box['txn_open'] ) {
		wc_get_template(
			'order/txn-box.php',
			array(
				'order' => $order,
				'box'   => $box,
			),
			'qersh-for-woocommerce/',
			QERSH_WC_DIR . 'templates/'
		);
	}
	?>

	<p class="qersh-status qersh-status--<?php echo esc_attr( $box['status'] ); ?>" data-qersh-status role="status" aria-live="polite">
		<?php echo esc_html( $qersh_wc_lines[ $qersh_wc_key ] ); ?>
		<?php if ( 'paid' === $box['status'] ) : ?>
			<span class="qersh-status__mark" aria-hidden="true">&#10003;</span>
		<?php endif; ?>
	</p>
	<p class="qersh-nudge" data-qersh-nudge<?php echo '' === $qersh_wc_nudge ? ' hidden' : ''; ?>><?php echo esc_html( $qersh_wc_nudge ); ?></p>
	<span class="qersh-sr" data-qersh-copy-msg role="status" aria-live="polite"></span>
</section>
