<?php
/**
 * Tests of the pure parts of Completion: the path choice, the decision under the payer lock and the stock check
 * (docs/17 §4.7).
 *
 * @package QershPay\WooCommerce\Tests
 */

declare( strict_types=1 );

namespace QershPay\WooCommerce\Tests\Unit;

use QershPay\WooCommerce\Completion;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

defined( 'ABSPATH' ) || exit;

/**
 * Completion::choose_path(), Completion::decide() and Completion::stock_enough().
 */
final class CompletionTest extends TestCase {

	/** Path (a) wherever the host can finish the response early, in any request. */
	public function test_finish_function_means_after_reply() {
		$this->assertSame( 'shutdown', Completion::choose_path( array( 'finish' => true ) ) );
		$this->assertSame( 'shutdown', Completion::choose_path( array( 'finish' => true, 'webhook' => true, 'elapsed' => 0.2 ) ) );
		$this->assertSame( 'shutdown', Completion::choose_path( array( 'finish' => true, 'webhook' => true, 'elapsed' => 9.0 ) ) );
	}

	/** Path (b) only inside a webhook request younger than six seconds. */
	public function test_inline_in_young_webhook_request() {
		$this->assertSame( 'inline', Completion::choose_path( array( 'webhook' => true, 'elapsed' => 0.0 ) ) );
		$this->assertSame( 'inline', Completion::choose_path( array( 'webhook' => true, 'elapsed' => 5.99 ) ) );
		$this->assertSame( 'async', Completion::choose_path( array( 'webhook' => true, 'elapsed' => 6.0 ) ) );
		$this->assertSame( 'async', Completion::choose_path( array( 'webhook' => true, 'elapsed' => 30.0 ) ) );
	}

	/** Path (c) otherwise: the customer's transaction-id request never completes inside itself without a finish function. */
	public function test_async_for_other_requests_without_finish() {
		$this->assertSame( 'async', Completion::choose_path( array() ) );
		$this->assertSame( 'async', Completion::choose_path( array( 'webhook' => false, 'elapsed' => 0.1 ) ) );
	}

	/** Already in a shutdown callback: run now (a new callback at the running priority would never run). */
	public function test_in_shutdown_runs_inline() {
		$this->assertSame( 'inline', Completion::choose_path( array( 'in_shutdown' => true, 'finish' => true ) ) );
		$this->assertSame( 'inline', Completion::choose_path( array( 'in_shutdown' => true, 'webhook' => true, 'elapsed' => 1.0 ) ) );
		$this->assertSame( 'async', Completion::choose_path( array( 'in_shutdown' => true ) ) );
		$this->assertSame( 'async', Completion::choose_path( array( 'in_shutdown' => true, 'webhook' => true, 'elapsed' => 7.0 ) ) );
	}

	/** Cron, Action Scheduler and WP-CLI: nobody waits, so the claimed path runs at once. */
	public function test_background_runs_inline() {
		$this->assertSame( 'inline', Completion::choose_path( array( 'background' => true ) ) );
		$this->assertSame( 'inline', Completion::choose_path( array( 'background' => true, 'finish' => true ) ) );
	}

	/** Never inside an open transaction. */
	public function test_never_inside_a_transaction() {
		$this->assertSame( 'shutdown', Completion::choose_path( array( 'in_txn' => true, 'finish' => true ) ) );
		$this->assertSame( 'async', Completion::choose_path( array( 'in_txn' => true, 'webhook' => true, 'elapsed' => 0.0 ) ) );
		$this->assertSame( 'async', Completion::choose_path( array( 'in_txn' => true, 'background' => true ) ) );
		$this->assertSame( 'async', Completion::choose_path( array( 'in_txn' => true, 'finish' => true, 'in_shutdown' => true ) ) );
	}

	/** The after-reply callback runs before _wp_cron (10) and the deferred email sender (100), after the flush (1). */
	public function test_shutdown_priority() {
		$this->assertGreaterThan( 1, Completion::SHUTDOWN_PRIORITY );
		$this->assertLessThan( 10, Completion::SHUTDOWN_PRIORITY );
	}

	/** A pending or failed order of ours, still paid in the row and not flagged, is completed. */
	public function test_complete_pending_and_failed() {
		foreach ( array( 'pending', 'failed' ) as $status ) {
			$verdict = Completion::decide( $this->facts( array( 'status' => $status ) ) );
			$this->assertSame( 'complete', $verdict['action'], $status );
			$this->assertNull( $verdict['row_state'] );
		}
	}

	/** An order our expiry cancelled is revived only when its stock still suffices. */
	public function test_expired_order_needs_stock() {
		$ok = Completion::decide( $this->facts( array( 'status' => 'cancelled', 'expired' => true, 'stock_ok' => true ) ) );
		$this->assertSame( 'complete', $ok['action'] );

		$short = Completion::decide( $this->facts( array( 'status' => 'cancelled', 'expired' => true, 'stock_ok' => false ) ) );
		$this->assertSame( array( 'action' => 'leave', 'row_state' => 'review', 'reason' => 'stock_short' ), $short );

		$unknown = Completion::decide( $this->facts( array( 'status' => 'cancelled', 'expired' => true ) ) );
		$this->assertSame( 'leave', $unknown['action'] );
	}

	/** Cancelled by the customer or the merchant, or trashed: closed, never revived. */
	public function test_cancelled_by_someone_else_is_closed() {
		foreach ( array( 'cancelled', 'trash', 'refunded', 'checkout-draft' ) as $status ) {
			$verdict = Completion::decide( $this->facts( array( 'status' => $status ) ) );
			$this->assertSame( array( 'action' => 'leave', 'row_state' => 'closed', 'reason' => 'closed_order' ), $verdict, $status );
		}
	}

	/** On-hold is never completed (the forgery stop, and WooCommerce would accept it), and its reason says on hold. */
	public function test_on_hold_is_never_completed() {
		$verdict = Completion::decide( $this->facts( array( 'status' => 'on-hold' ) ) );
		$this->assertSame( array( 'action' => 'leave', 'row_state' => 'review', 'reason' => 'order_on_hold' ), $verdict );
		$this->assertSame( Completion::REASON_ON_HOLD, $verdict['reason'] );
	}

	/** After three calls that left the order unpaid, the decision goes to the merchant instead of a fourth call. */
	public function test_attempts_are_capped() {
		$this->assertSame( 3, Completion::MAX_ATTEMPTS );
		foreach ( array( 0, 1, 2 ) as $attempts ) {
			$this->assertSame( 'complete', Completion::decide( $this->facts( array( 'attempts' => $attempts ) ) )['action'], (string) $attempts );
		}
		$given_up = array( 'action' => 'leave', 'row_state' => 'review', 'reason' => 'completion_failed' );
		$this->assertSame( $given_up, Completion::decide( $this->facts( array( 'attempts' => 3 ) ) ) );
		$this->assertSame( $given_up, Completion::decide( $this->facts( array( 'status' => 'failed', 'attempts' => 7 ) ) ) );
		$this->assertSame( $given_up, Completion::decide( $this->facts( array( 'status' => 'cancelled', 'expired' => true, 'stock_ok' => true, 'attempts' => 3 ) ) ) );

		// The cap never hides what the order already says.
		$this->assertSame( 'already', Completion::decide( $this->facts( array( 'status' => 'processing', 'attempts' => 3 ) ) )['action'] );
		$this->assertSame( 'forgery', Completion::decide( $this->facts( array( 'flagged' => true, 'attempts' => 3 ) ) )['reason'] );
		$this->assertSame( 'order_on_hold', Completion::decide( $this->facts( array( 'status' => 'on-hold', 'attempts' => 3 ) ) )['reason'] );
		$this->assertSame( 'stock_short', Completion::decide( $this->facts( array( 'status' => 'cancelled', 'expired' => true, 'stock_ok' => false, 'attempts' => 3 ) ) )['reason'] );
	}

	/** payment_complete() succeeded, or the order is paid even though it reported an error: done, never rolled back or repeated. */
	public function test_settle_paid_is_done() {
		$done = array( 'completion' => 'done', 'row_state' => null, 'reason' => null, 'stop' => false );
		$this->assertSame( $done, Completion::settle_plan( true, 'processing', 1, false ) );
		$this->assertSame( $done, Completion::settle_plan( true, 'completed', 1, false ) );
		// WooCommerce caught an exception after save() (emails sent, stock reduced) and returned false.
		$this->assertSame( $done, Completion::settle_plan( false, 'processing', 1, false ) );
		$this->assertSame( $done, Completion::settle_plan( false, 'completed', 3, false ) );
		// The order disappeared, or a filter kept WooCommerce from changing the status: nothing to try again.
		$this->assertSame( $done, Completion::settle_plan( false, '', 1, false ) );
		$this->assertSame( $done, Completion::settle_plan( true, 'pending', 1, false ) );
	}

	/** Still unpaid after a failed call: due for the sweep, until the third call; then the merchant decides. */
	public function test_settle_unpaid_is_due_then_given_up() {
		$due = array( 'completion' => 'due', 'row_state' => null, 'reason' => null, 'stop' => false );
		$this->assertSame( $due, Completion::settle_plan( false, 'pending', 1, false ) );
		$this->assertSame( $due, Completion::settle_plan( false, 'failed', 2, false ) );
		$this->assertSame( $due, Completion::settle_plan( false, 'cancelled', 2, false ) );

		$given_up = array( 'completion' => 'done', 'row_state' => 'review', 'reason' => 'completion_failed', 'stop' => false );
		$this->assertSame( $given_up, Completion::settle_plan( false, 'pending', 3, false ) );
		$this->assertSame( $given_up, Completion::settle_plan( false, 'failed', 4, false ) );
	}

	/** A forgery flag that arrived while payment_complete() ran: done, row to review, and a paid order goes on hold. */
	public function test_settle_flagged_stops_a_paid_order() {
		$this->assertSame(
			array( 'completion' => 'done', 'row_state' => 'review', 'reason' => null, 'stop' => true ),
			Completion::settle_plan( true, 'processing', 1, true )
		);
		$this->assertTrue( Completion::settle_plan( false, 'completed', 1, true )['stop'] );
		// Already on hold (the stop ran after our save), or never paid: nothing to stop, no retry.
		foreach ( array( 'on-hold', 'pending', 'cancelled', '' ) as $status ) {
			$plan = Completion::settle_plan( false, $status, 1, true );
			$this->assertFalse( $plan['stop'], $status );
			$this->assertSame( 'done', $plan['completion'], $status );
			$this->assertSame( 'review', $plan['row_state'], $status );
		}
	}

	/** A flagged transfer counts only while it pays the order; a dismissed or reviewed one does not. */
	public function test_flagged_transfers() {
		$this->assertFalse( Completion::flagged( array() ) );
		$this->assertFalse( Completion::flagged( array( array( 'flag' => null, 'outcome' => 'matched' ) ) ) );
		foreach ( array( 'matched', 'overpaid', 'partial', 'attached' ) as $outcome ) {
			$rows = array(
				array( 'flag' => null, 'outcome' => 'matched' ),
				array( 'flag' => 'forgery', 'outcome' => $outcome ),
			);
			$this->assertTrue( Completion::flagged( $rows ), $outcome );
		}
		foreach ( array( 'dismissed', 'review', 'unmatched', null ) as $outcome ) {
			$this->assertFalse( Completion::flagged( array( array( 'flag' => 'forgery', 'outcome' => $outcome ) ) ), (string) $outcome );
		}
	}

	/** An empty payer key takes no lock (Store\Db refuses an empty name, which would fail every sweep). */
	public function test_payer_locks() {
		$this->assertSame( array(), Completion::payer_locks( '' ) );
		$this->assertSame( array( 'payer:01001234567' ), Completion::payer_locks( '01001234567' ) );
	}

	/** A flag wins over every status that could be completed. */
	public function test_flag_blocks() {
		foreach ( array( 'pending', 'failed', 'cancelled', 'on-hold' ) as $status ) {
			$verdict = Completion::decide( $this->facts( array( 'status' => $status, 'flagged' => true, 'expired' => true, 'stock_ok' => true ) ) );
			$this->assertSame( array( 'action' => 'leave', 'row_state' => 'review', 'reason' => 'forgery' ), $verdict, $status );
		}
	}

	/** A row that is no longer paid is left as it says (closed or review), without the call. */
	public function test_row_no_longer_paid() {
		foreach ( array( 'closed', 'review', 'open', null ) as $state ) {
			$verdict = Completion::decide( $this->facts( array( 'row_state' => $state ) ) );
			$this->assertSame( array( 'action' => 'leave', 'row_state' => null, 'reason' => 'closed_order' ), $verdict, (string) $state );
		}
	}

	/** Another method, or a missing order: closed. */
	public function test_not_ours() {
		$this->assertSame( 'closed', Completion::decide( $this->facts( array( 'method' => 'cod' ) ) )['row_state'] );
		$this->assertSame( 'closed', Completion::decide( array( 'exists' => false, 'row_state' => 'paid' ) )['row_state'] );
	}

	/** Processing or completed already: nothing to do, the transfer stays as it is. */
	public function test_already_paid() {
		foreach ( array( 'processing', 'completed' ) as $status ) {
			$verdict = Completion::decide( $this->facts( array( 'status' => $status ) ) );
			$this->assertSame( array( 'action' => 'already', 'row_state' => null, 'reason' => null ), $verdict, $status );
		}
	}

	/** Stock: managed stock minus what other pending orders hold must cover the lines, per stock holder. */
	public function test_stock_enough() {
		$this->assertTrue( Completion::stock_enough( array() ) );
		$this->assertTrue( Completion::stock_enough( array( $this->line( 5, 0, 5 ) ) ) );
		$this->assertFalse( Completion::stock_enough( array( $this->line( 5, 1, 5 ) ) ) );
		$this->assertFalse( Completion::stock_enough( array( $this->line( 4, 0, 5 ) ) ) );

		// Two variations managed by their parent (stock_id 7) share the parent's stock.
		$a = $this->line( 3, 0, 2, 7 );
		$b = $this->line( 3, 0, 2, 7 );
		$this->assertFalse( Completion::stock_enough( array( $a, $b ) ) );
		$b['qty'] = 1;
		$this->assertTrue( Completion::stock_enough( array( $a, $b ) ) );
	}

	/** Backorders allowed, unmanaged stock in stock: fine; out of stock or a deleted product: not. */
	public function test_stock_other_cases() {
		$backorder               = $this->line( 0, 0, 5 );
		$backorder['backorders'] = true;
		$this->assertTrue( Completion::stock_enough( array( $backorder ) ) );

		$unmanaged = array( 'exists' => true, 'managed' => false, 'in_stock' => true );
		$this->assertTrue( Completion::stock_enough( array( $unmanaged ) ) );
		$unmanaged['in_stock'] = false;
		$this->assertFalse( Completion::stock_enough( array( $unmanaged ) ) );

		$this->assertFalse( Completion::stock_enough( array( array( 'exists' => false ) ) ) );
	}

	/**
	 * Facts of a completable order, with changes.
	 *
	 * @param array $changes Changes.
	 * @return array
	 */
	private function facts( array $changes ): array {
		return array_merge(
			array(
				'exists'    => true,
				'method'    => 'qersh',
				'status'    => 'pending',
				'flagged'   => false,
				'row_state' => 'paid',
				'expired'   => false,
				'stock_ok'  => null,
			),
			$changes
		);
	}

	/**
	 * One stock line.
	 *
	 * @param float $stock    Stock.
	 * @param float $held     Held by other orders.
	 * @param float $qty      Quantity.
	 * @param int   $stock_id Stock holder.
	 * @return array
	 */
	private function line( float $stock, float $held, float $qty, int $stock_id = 1 ): array {
		return array(
			'exists'     => true,
			'managed'    => true,
			'in_stock'   => true,
			'backorders' => false,
			'stock_id'   => $stock_id,
			'stock'      => $stock,
			'held'       => $held,
			'qty'        => $qty,
		);
	}
}
