<?php
/**
 * The administrator's Qersh alert (HTML).
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/emails/admin-alert.php.
 *
 * @package QershPay\WooCommerce
 *
 * @var string                                    $email_heading      Heading.
 * @var string[]                                  $lines              Lines, escaped HTML.
 * @var string                                    $action_url         Where to act.
 * @var string                                    $action_label       Its label.
 * @var string                                    $additional_content Merchant's extra text.
 * @var \QershPay\WooCommerce\Emails\AdminAlert $email              The email.
 */

defined( 'ABSPATH' ) || exit;

/** This action is documented in woocommerce/includes/class-wc-emails.php (WC_Emails::email_header() prints it). */
do_action( 'woocommerce_email_header', $email_heading, $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's email hook.

// wp_kses_post() alone drops the <bdi> that wc_price() puts around each amount, and the amounts rely on it to keep
// the number and the currency in order inside an Arabic sentence (docs/17 §7).
$qersh_wc_allowed        = wp_kses_allowed_html( 'post' );
$qersh_wc_allowed['bdi'] = array( 'dir' => true );
?>

<?php foreach ( $lines as $qersh_wc_line ) : ?>
	<p><?php echo wp_kses( $qersh_wc_line, $qersh_wc_allowed ); ?></p>
<?php endforeach; ?>

<p><a href="<?php echo esc_url( $action_url ); ?>"><?php echo esc_html( $action_label ); ?></a></p>

<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

/** This action is documented in woocommerce/includes/class-wc-emails.php (WC_Emails::email_footer() prints it). */
do_action( 'woocommerce_email_footer', $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's email hook.
