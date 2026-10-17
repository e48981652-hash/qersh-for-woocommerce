<?php
/**
 * The mail log of an end-to-end job (tests/e2e/mu/qersh-test-mail.php), for tests/e2e/utils.mjs mail():
 *
 *   wp eval-file wp-content/plugins/qersh-for-woocommerce/tests/e2e/setup/mail.php [slow=1|0] [clear=1] [drain=1] [since=<epoch>]
 *   php tests/e2e/setup/mail.php …   (a local test site; without the must-use plugin `active` is false)
 *
 * drain=1 first runs Action Scheduler batches until nothing due is left (so mail queued by earlier tests does not
 * run while the mail is slow), clear=1 empties the log, slow=1 or slow=0 turns the 5-second mail on or off. Prints
 * one line `QERSH_JSON={…}`: whether the must-use plugin is loaded, whether the mail is slow, the batches run, and
 * the log entries (those written at `since` or later).
 *
 * @package QershPay\WooCommerce\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	require dirname( __DIR__, 2 ) . '/local/lib/bootstrap.php';
	$args = $qersh_local_args; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
}
defined( 'ABSPATH' ) || exit;

$qersh_e2e_opts = array(
	'slow'  => '',
	'clear' => '0',
	'drain' => '0',
	'since' => '0',
);
foreach ( isset( $args ) && is_array( $args ) ? $args : array() as $qersh_e2e_arg ) {
	$qersh_e2e_pair = explode( '=', (string) $qersh_e2e_arg, 2 );
	if ( 2 === count( $qersh_e2e_pair ) ) {
		$qersh_e2e_opts[ $qersh_e2e_pair[0] ] = $qersh_e2e_pair[1];
	}
}

$qersh_e2e_batches = 0;
if ( '1' === $qersh_e2e_opts['drain'] && class_exists( 'ActionScheduler_QueueRunner' ) ) {
	for ( $qersh_e2e_i = 0; $qersh_e2e_i < 20; $qersh_e2e_i++ ) {
		$qersh_e2e_done = (int) ActionScheduler_QueueRunner::instance()->run( 'qersh-e2e' );
		if ( $qersh_e2e_done <= 0 ) {
			break;
		}
		++$qersh_e2e_batches;
	}
}
if ( '1' === $qersh_e2e_opts['slow'] || '0' === $qersh_e2e_opts['slow'] ) {
	update_option( 'qersh_e2e_slow_mail', '1' === $qersh_e2e_opts['slow'] ? 'yes' : 'no', false );
}

$qersh_e2e_file = defined( 'QERSH_E2E_MAIL_LOG' ) ? QERSH_E2E_MAIL_LOG : '';
if ( '' !== $qersh_e2e_file && '1' === $qersh_e2e_opts['clear'] ) {
	file_put_contents( $qersh_e2e_file, '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
}
$qersh_e2e_log = array();
if ( '' !== $qersh_e2e_file && is_file( $qersh_e2e_file ) ) {
	foreach ( file( $qersh_e2e_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $qersh_e2e_line ) {
		$qersh_e2e_entry = json_decode( $qersh_e2e_line, true );
		if ( is_array( $qersh_e2e_entry ) && (int) $qersh_e2e_entry['at'] >= (int) $qersh_e2e_opts['since'] ) {
			$qersh_e2e_log[] = $qersh_e2e_entry;
		}
	}
}

echo 'QERSH_JSON=' . wp_json_encode(
	array(
		'active'  => '' !== $qersh_e2e_file,
		'slow'    => 'yes' === get_option( 'qersh_e2e_slow_mail', 'no' ),
		'batches' => $qersh_e2e_batches,
		'now'     => time(),
		'log'     => $qersh_e2e_log,
	),
	JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
) . "\n";
