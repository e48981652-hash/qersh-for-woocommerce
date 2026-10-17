<?php
/**
 * Plugin Name: Qersh e2e: slow mail
 * Description: Test only (the nightly PHP-FPM job, docs/17 §14.1). Every email takes 5 seconds and is written to
 *              wp-content/uploads/qersh-mail.log instead of being sent, so the job can check that the phone's
 *              answer does not wait for the mail and that the mail still goes out after it.
 *
 * @package QershPay\WooCommerce\Tests
 */

defined( 'ABSPATH' ) || exit;

add_filter(
	'pre_wp_mail',
	function ( $short, $atts ) {
		unset( $short );
		sleep( 5 );
		$uploads = wp_upload_dir( null, false );
		$line    = gmdate( 'c' ) . "\t" . ( isset( $atts['subject'] ) ? wp_strip_all_tags( (string) $atts['subject'] ) : '' ) . "\n";
		file_put_contents( trailingslashit( $uploads['basedir'] ) . 'qersh-mail.log', $line, FILE_APPEND | LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- the test mail log, outside the plugin folder.
		return true;
	},
	10,
	2
);
