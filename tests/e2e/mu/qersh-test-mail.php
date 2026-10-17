<?php
/**
 * Plugin Name: Qersh e2e: mail log and slow mail
 * Description: Test only (the end-to-end jobs of e2e.yml, docs/17 §1 criteria 3 and 7). Every email is written to
 *              wp-content/qersh-e2e-mail.log (one JSON line: time, WooCommerce email id, order id, recipient,
 *              subject) instead of being sent. While the option qersh_e2e_slow_mail is "yes" (tests/e2e/setup/mail.php
 *              slow=1), each email first takes 5 seconds, so a test can check that the phone's answer never waits for
 *              the mail and that the mail still goes out after it.
 *
 * @package QershPay\WooCommerce\Tests
 */

defined( 'ABSPATH' ) || exit;

define( 'QERSH_E2E_MAIL_LOG', WP_CONTENT_DIR . '/qersh-e2e-mail.log' );

/**
 * The WooCommerce email being sent right now (woocommerce_mail_callback_params runs just before its wp_mail()).
 *
 * @param array|null $set New value, or null to read.
 * @param bool       $clear True to forget it.
 * @return array
 */
function qersh_e2e_mail_context( $set = null, $clear = false ) {
	static $current = array(
		'id'    => '',
		'order' => null,
	);
	if ( is_array( $set ) ) {
		$current = $set;
	}
	$out = $current;
	if ( $clear ) {
		$current = array(
			'id'    => '',
			'order' => null,
		);
	}
	return $out;
}

add_filter(
	'woocommerce_mail_callback_params',
	function ( $params, $email ) {
		$order = null;
		if ( is_object( $email ) && isset( $email->object ) && $email->object instanceof WC_Order ) {
			$order = $email->object->get_id();
		}
		qersh_e2e_mail_context(
			array(
				'id'    => is_object( $email ) && isset( $email->id ) ? (string) $email->id : '',
				'order' => $order,
			)
		);
		return $params;
	},
	10,
	2
);

add_filter(
	'pre_wp_mail',
	function ( $short, $atts ) {
		if ( null !== $short ) {
			return $short;
		}
		$slow    = 'yes' === get_option( 'qersh_e2e_slow_mail', 'no' );
		$started = microtime( true );
		if ( $slow ) {
			sleep( 5 );
		}
		$ctx  = qersh_e2e_mail_context( null, true );
		$to   = isset( $atts['to'] ) ? $atts['to'] : '';
		$line = array(
			'at'      => time(),
			'id'      => $ctx['id'],
			'order'   => $ctx['order'],
			'to'      => is_array( $to ) ? implode( ',', $to ) : (string) $to,
			'subject' => isset( $atts['subject'] ) ? wp_strip_all_tags( (string) $atts['subject'] ) : '',
			'slow'    => $slow,
			'ms'      => (int) round( ( microtime( true ) - $started ) * 1000 ),
		);
		file_put_contents( QERSH_E2E_MAIL_LOG, wp_json_encode( $line, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n", FILE_APPEND | LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		return true;
	},
	10,
	2
);
