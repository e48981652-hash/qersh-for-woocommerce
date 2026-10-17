<?php
/**
 * CLI: php tests/local/run-cron.php
 *
 * Runs the due WP-Cron events and one Action Scheduler batch on the local test site (DISABLE_WP_CRON is on there,
 * because `php -S` serves one request at a time and a loopback request would wait for itself).
 *
 * @package QershPay\WooCommerce\Tests
 */

$qersh_local_cron = true;
require __DIR__ . '/lib/bootstrap.php';

$qersh_ran   = 0;
$qersh_crons = _get_cron_array();
foreach ( is_array( $qersh_crons ) ? $qersh_crons : array() as $qersh_timestamp => $qersh_hooks ) {
	if ( $qersh_timestamp > time() ) {
		continue;
	}
	foreach ( $qersh_hooks as $qersh_hook => $qersh_events ) {
		foreach ( $qersh_events as $qersh_event ) {
			wp_unschedule_event( $qersh_timestamp, $qersh_hook, $qersh_event['args'] );
			do_action_ref_array( $qersh_hook, $qersh_event['args'] );
			$qersh_ran++;
		}
	}
}
$qersh_processed = 0;
if ( class_exists( 'ActionScheduler_QueueRunner' ) ) {
	$qersh_processed = ActionScheduler_QueueRunner::instance()->run( 'qersh-local' );
}
echo "cron events run: {$qersh_ran}; action scheduler actions processed: {$qersh_processed}\n";
