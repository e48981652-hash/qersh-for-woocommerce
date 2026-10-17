<?php
/**
 * Completing an order once the store decided it is paid (docs/17 §4.7).
 *
 * @package QershPay\WooCommerce
 */

namespace QershPay\WooCommerce;

use QershPay\WooCommerce\Store\Db;
use QershPay\WooCommerce\Store\LockTimeout;

defined( 'ABSPATH' ) || exit;

/**
 * Every path to `payment_complete()` goes through here:
 * - request() picks the first path the request allows: (a) after the reply, on `shutdown` at priority 5, where the
 *   host can finish the response early; (b) inline, inside a webhook request that is younger than six seconds; or
 *   (c) the `qersh_wc_complete` Action Scheduler action, unique per order.
 * - run() is the claimed path and the action handler. It claims the row (completion = running), then:
 *   1. under the payer lock, reads the order and its row again and decides. A completion left without the call
 *      corrects the row and the paying transfers right there ("Needs decision", their phone status to be
 *      corrected), so the order-status route never tells a customer "your order is confirmed" for a cancelled
 *      order. Then COMMIT.
 *   2. outside any transaction, calls payment_complete() once. The claim keeps every other path out, and neither our
 *      five-second lock wait nor the payer lock reaches WooCommerce's stock updates, its emails or other plugins'
 *      hooks (docs/17 §4.4 step 8).
 *   3. under the payer lock again, reads what WooCommerce wrote and settles the row: done when the order is paid
 *      (also when a hook failed after WooCommerce saved it), due for another try when it is still unpaid, and left
 *      to the merchant after three tries. A forgery flag that arrived while payment_complete() ran puts the paid
 *      order on hold (docs/17 §4.4 step 9). What WooCommerce wrote is never rolled back, and payment_complete() is
 *      never called twice in one run.
 * - sweep() picks up decisions whose completion never ran (every 15 minutes, for rows paid two minutes ago or more).
 *
 * The claim exists because payment_complete() is idempotent only in sequence: two concurrent calls on a pending
 * order reduce stock twice and send every email twice (docs/16 §6.4).
 */
final class Completion {

	/**
	 * The Action Scheduler hook of path (c).
	 */
	const ACTION = 'qersh_wc_complete';

	/**
	 * The Action Scheduler group of the plugin.
	 */
	const GROUP = 'qersh';

	/**
	 * Priority of the after-reply callback: after wp_ob_end_flush_all (1), before _wp_cron (10) and before the
	 * deferred email sender of WooCommerce (100), which would otherwise leave "Processing" and "New order" queued
	 * for good (docs/17 §4.7).
	 */
	const SHUTDOWN_PRIORITY = 5;

	/**
	 * Path (b) runs inline only while the webhook request is younger than this.
	 */
	const INLINE_SEC = 6;

	/**
	 * The sweep takes rows decided paid at least this long ago.
	 */
	const SWEEP_AGE_SEC = 120;

	/**
	 * The sweep stops starting new completions after this many seconds; the rest wait for the next sweep.
	 */
	const SWEEP_BUDGET_SEC = 20;

	/**
	 * WooCommerce statuses that payment_complete() is called from.
	 */
	const COMPLETABLE = array( 'pending', 'failed' );

	/**
	 * WooCommerce statuses of an order that is already paid.
	 */
	const PAID_STATUSES = array( 'processing', 'completed' );

	/**
	 * Transfer outcomes that paid an order (and go to review when its completion is left).
	 */
	const PAYING_OUTCOMES = array( 'matched', 'overpaid', 'partial', 'attached' );

	/**
	 * payment_complete() is called at most this many times for an order that stays unpaid after it (an error before
	 * WooCommerce saved the order, or a request that died in it); then the completion is left to the merchant, so a
	 * broken hook of another plugin can never repeat its side effects every 15 minutes.
	 */
	const MAX_ATTEMPTS = 3;

	/**
	 * Transient, per order, counting the payment_complete() calls of its paid decision (removed once settled; the
	 * uninstaller removes every qersh_wc_ transient).
	 */
	const ATTEMPTS_TRANSIENT = 'qersh_wc_completion_tries_';

	/**
	 * Seconds before the retry of a completion whose payer lock was busy inside the completion action itself.
	 */
	const RETRY_SEC = 60;

	/**
	 * Reason of a completion left on an on-hold order without the forgery flag.
	 */
	const REASON_ON_HOLD = 'order_on_hold';

	/**
	 * Reason of a completion left to the merchant after MAX_ATTEMPTS calls of payment_complete().
	 */
	const REASON_FAILED = 'completion_failed';

	/**
	 * The plugin.
	 *
	 * @var Plugin|null
	 */
	private $plugin;

	/**
	 * Orders waiting for the after-reply callback, by id.
	 *
	 * @var array<int, true>
	 */
	private static $queued = array();

	/**
	 * True once the after-reply callback is hooked.
	 *
	 * @var bool
	 */
	private static $hooked = false;

	/**
	 * Orders whose completion runs right now in this request, by id (for the order hooks, is_completing()).
	 *
	 * @var array<int, true>
	 */
	private static $running = array();

	/**
	 * Builds the service.
	 *
	 * @param Plugin|null $plugin The plugin.
	 */
	public function __construct( $plugin = null ) {
		$this->plugin = $plugin instanceof Plugin ? $plugin : Plugin::instance();
	}

	/**
	 * Asks for the completion of an order whose `paid` decision is committed (completion = due). Call it after
	 * COMMIT. Never throws.
	 *
	 * @param int $order_id Order id.
	 */
	public function request( int $order_id ) {
		if ( $order_id <= 0 ) {
			return;
		}
		try {
			$path = self::choose_path(
				array(
					'in_txn'      => $this->plugin->db()->in_transaction(),
					'in_shutdown' => doing_action( 'shutdown' ),
					'background'  => self::in_background(),
					'finish'      => Plugin::finish_available(),
					'webhook'     => Plugin::is_webhook_request(),
					'elapsed'     => self::elapsed(),
				)
			);
			if ( 'inline' === $path ) {
				$this->run( $order_id );
			} elseif ( 'shutdown' === $path ) {
				self::queue_after_reply( $order_id );
			} else {
				$this->enqueue( $order_id );
			}
		} catch ( \Throwable $e ) {
			$this->log_failure( 'request', $order_id, $e );
		}
	}

	/**
	 * The claimed path, and the handler of `qersh_wc_complete`: claims the completion, decides under the payer lock,
	 * calls payment_complete() after COMMIT, then settles the row from what WooCommerce wrote. A lock timeout or an
	 * error before the call puts the claim back to `due`, so another path (the action, or the sweep) takes it later.
	 * Never throws.
	 *
	 * @param int $order_id Order id.
	 */
	public function run( int $order_id ) {
		if ( $order_id <= 0 || ! $this->plugin->wc_ready() || ! $this->plugin->tables_ready() ) {
			return;
		}
		if ( $this->plugin->db()->in_transaction() ) {
			$this->retry_later( $order_id ); // Never inside someone else's transaction: that would commit or block it.
			return;
		}
		$now = time();
		try {
			if ( ! $this->plugin->orders_index()->claim_completion( $order_id, $now ) ) {
				return; // Another path has it, it is done, or the decision is no longer paid.
			}
		} catch ( \Throwable $e ) {
			$this->log_failure( 'claim', $order_id, $e );
			return;
		}
		self::$running[ $order_id ] = true;
		try {
			$plan = $this->decide_phase( $order_id, $now );
			if ( null !== $plan ) {
				$this->call_phase( $order_id, $plan, $now );
			}
		} catch ( \Throwable $e ) {
			$this->log_failure( 'run', $order_id, $e ); // Each phase catches its own errors; this is the last net.
		} finally {
			unset( self::$running[ $order_id ] );
		}
	}

	/**
	 * The sweep (every 15 minutes): completes decisions paid two minutes ago or more whose completion is still due,
	 * or running for longer than the claim allows, whatever the WooCommerce status; run() never completes an on-hold
	 * order. Never throws.
	 */
	public function sweep() {
		if ( ! $this->plugin->wc_ready() || ! $this->plugin->tables_ready() ) {
			return;
		}
		$start = microtime( true );
		try {
			$ids = $this->plugin->orders_index()->due_completions( time() - self::SWEEP_AGE_SEC );
		} catch ( \Throwable $e ) {
			$this->log_failure( 'sweep', 0, $e );
			return;
		}
		foreach ( $ids as $order_id ) {
			if ( microtime( true ) - $start > self::SWEEP_BUDGET_SEC ) {
				break;
			}
			$this->run( (int) $order_id );
		}
	}

	/**
	 * The after-reply callback (shutdown, priority 5): flushes the output buffers, finishes the response so the
	 * phone has its answer, then runs the claimed path for each queued order. Never throws.
	 */
	public static function on_shutdown() {
		$ids          = array_keys( self::$queued );
		self::$queued = array();
		if ( empty( $ids ) ) {
			return;
		}
		try {
			if ( function_exists( 'ignore_user_abort' ) ) {
				ignore_user_abort( true );
			}
			self::finish_response();
			$completion = Plugin::instance()->completion();
			foreach ( $ids as $order_id ) {
				$completion->run( (int) $order_id );
			}
		} catch ( \Throwable $e ) {
			unset( $e ); // run() logs its own failures; the sweep finishes what is left.
		}
	}

	/**
	 * True while this request completes the order (for the order hooks: a status change that comes from our own
	 * payment_complete() is not "someone else left pending").
	 *
	 * @param int $order_id Order id.
	 * @return bool
	 */
	public static function is_completing( int $order_id ): bool {
		return isset( self::$running[ $order_id ] );
	}

	/**
	 * The path of a completion request, in the order of docs/17 §4.7. Pure.
	 *
	 * Context keys: `in_txn` (a database transaction is open: never run inside it), `in_shutdown` (we are already in
	 * a shutdown callback, where a new callback at the running priority would never run), `background` (WP-Cron,
	 * Action Scheduler or WP-CLI: nobody waits for a reply), `finish` (fastcgi_finish_request() or
	 * litespeed_finish_request() exists), `webhook` (a webhook request) and `elapsed` (seconds since the request
	 * started).
	 *
	 * @param array $ctx Context.
	 * @return string `inline`, `shutdown` or `async`.
	 */
	public static function choose_path( array $ctx ): string {
		$c     = array_merge(
			array(
				'in_txn'      => false,
				'in_shutdown' => false,
				'background'  => false,
				'finish'      => false,
				'webhook'     => false,
				'elapsed'     => 0.0,
			),
			$ctx
		);
		$quick = ! empty( $c['webhook'] ) && (float) $c['elapsed'] < self::INLINE_SEC;
		if ( ! empty( $c['in_txn'] ) ) {
			return ! empty( $c['finish'] ) && empty( $c['in_shutdown'] ) ? 'shutdown' : 'async';
		}
		if ( ! empty( $c['in_shutdown'] ) ) {
			return ! empty( $c['finish'] ) || ! empty( $c['background'] ) || $quick ? 'inline' : 'async';
		}
		if ( ! empty( $c['background'] ) ) {
			return 'inline';
		}
		if ( ! empty( $c['finish'] ) ) {
			return 'shutdown';
		}
		return $quick ? 'inline' : 'async';
	}

	/**
	 * What the claimed path does with an order, read under the payer lock (docs/17 §4.7). Pure.
	 *
	 * Fact keys: `exists` (the order was found), `method` (payment method id), `status` (WooCommerce status without
	 * `wc-`), `flagged` (`_qersh_flag` is set, or a transfer that paid the order is flagged as forged), `row_state`
	 * (qersh_orders.state now), `expired` (`_qersh_expired_at` is set), `stock_ok` (only read for an order our expiry
	 * cancelled: true when its stock still suffices) and `attempts` (payment_complete() calls already made for this
	 * decision).
	 *
	 * @param array $facts Facts.
	 * @return array `[ 'action' => 'complete'|'already'|'leave', 'row_state' => 'review'|'closed'|null,
	 *               'reason' => string|null ]`. `already`: the order is paid already, nothing more to do. `leave`:
	 *               completion done without the call, the paying transfers to review with `reason`, and the row to
	 *               `row_state` when it is not null.
	 */
	public static function decide( array $facts ): array {
		$f = array_merge(
			array(
				'exists'    => false,
				'method'    => '',
				'status'    => '',
				'flagged'   => false,
				'row_state' => null,
				'expired'   => false,
				'stock_ok'  => null,
				'attempts'  => 0,
			),
			$facts
		);
		if ( empty( $f['exists'] ) || 'qersh' !== $f['method'] ) {
			return self::verdict( 'leave', 'closed', 'closed_order' );
		}
		if ( in_array( $f['status'], self::PAID_STATUSES, true ) ) {
			return self::verdict( 'already', null, null );
		}
		if ( ! empty( $f['flagged'] ) ) {
			return self::verdict( 'leave', 'review', 'forgery' );
		}
		if ( 'paid' !== $f['row_state'] ) {
			return self::verdict( 'leave', null, 'closed_order' ); // The row says why already (closed or review).
		}
		$completable = in_array( $f['status'], self::COMPLETABLE, true );
		if ( 'cancelled' === $f['status'] && ! empty( $f['expired'] ) ) {
			if ( true !== $f['stock_ok'] ) {
				return self::verdict( 'leave', 'review', 'stock_short' );
			}
			$completable = true;
		}
		if ( $completable ) {
			if ( (int) $f['attempts'] >= self::MAX_ATTEMPTS ) {
				return self::verdict( 'leave', 'review', self::REASON_FAILED ); // Earlier calls left it unpaid.
			}
			return self::verdict( 'complete', null, null );
		}
		if ( 'on-hold' === $f['status'] ) {
			return self::verdict( 'leave', 'review', self::REASON_ON_HOLD );
		}
		return self::verdict( 'leave', 'closed', 'closed_order' ); // Cancelled by someone else, trashed, refunded.
	}

	/**
	 * How the row is settled after payment_complete() ran, from what WooCommerce wrote (read again under the payer
	 * lock). Nothing is rolled back and the call is never repeated in the same run. Pure.
	 *
	 * - A forgery flag (set while the call ran): done, row to review, and a paid order goes on hold.
	 * - The call succeeded, the order is paid (also when a hook failed after WooCommerce saved it: WooCommerce
	 *   catches that and returns false), or the order is gone: done.
	 * - Still unpaid after a failed call: due, so the sweep tries again with the decision read afresh; after
	 *   MAX_ATTEMPTS calls, done with the row to review and the paying transfers to "Needs decision".
	 *
	 * @param bool   $succeeded payment_complete() returned true without throwing.
	 * @param string $status    WooCommerce status read after the call ('' when the order is gone).
	 * @param int    $attempts  payment_complete() calls made for this decision, this one included.
	 * @param bool   $flagged   The forgery flag, read after the call.
	 * @return array `[ 'completion' => 'done'|'due', 'row_state' => 'review'|null, 'reason' => string|null,
	 *               'stop' => bool ]`: `reason` for the paying transfers, `stop` puts the order on hold.
	 */
	public static function settle_plan( bool $succeeded, string $status, int $attempts, bool $flagged ): array {
		$paid = in_array( $status, self::PAID_STATUSES, true );
		if ( $flagged ) {
			return self::settled( 'done', 'review', null, $paid );
		}
		if ( $succeeded || $paid || '' === $status ) {
			return self::settled( 'done', null, null, false );
		}
		if ( $attempts >= self::MAX_ATTEMPTS ) {
			return self::settled( 'done', 'review', self::REASON_FAILED, false );
		}
		return self::settled( 'due', null, null, false );
	}

	/**
	 * True when a transfer that paid the order is flagged as forged (docs/17 §4.4 step 9 writes the flag on the
	 * transfer and `_qersh_flag` on the order in one transaction; the transfer row is read without any order cache).
	 * Pure.
	 *
	 * @param array[] $transfers The order's qersh_transfers rows.
	 * @return bool
	 */
	public static function flagged( array $transfers ): bool {
		foreach ( $transfers as $transfer ) {
			if ( isset( $transfer['flag'], $transfer['outcome'] ) && 'forgery' === $transfer['flag'] && in_array( $transfer['outcome'], self::PAYING_OUTCOMES, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether the stock still covers an order that our expiry cancelled, so reviving it never sells what is gone.
	 * Pure.
	 *
	 * Each line: `exists` (the product is still there), `managed` (it manages stock), `in_stock` (its stock status
	 * is not out of stock), `backorders` (backorders allowed), `stock_id` (the product that holds the stock, for
	 * variations managed by their parent), `stock` (quantity now), `held` (quantity held for other pending orders)
	 * and `qty` (quantity of the line).
	 *
	 * @param array[] $lines Lines.
	 * @return bool
	 */
	public static function stock_enough( array $lines ): bool {
		$need = array();
		$have = array();
		foreach ( $lines as $line ) {
			if ( empty( $line['exists'] ) ) {
				return false;
			}
			if ( empty( $line['managed'] ) ) {
				if ( empty( $line['in_stock'] ) ) {
					return false;
				}
				continue;
			}
			if ( ! empty( $line['backorders'] ) ) {
				continue;
			}
			$id          = isset( $line['stock_id'] ) ? (int) $line['stock_id'] : 0;
			$need[ $id ] = ( isset( $need[ $id ] ) ? $need[ $id ] : 0 ) + ( isset( $line['qty'] ) ? (float) $line['qty'] : 0 );
			$have[ $id ] = ( isset( $line['stock'] ) ? (float) $line['stock'] : 0 ) - ( isset( $line['held'] ) ? (float) $line['held'] : 0 );
		}
		foreach ( $need as $id => $qty ) {
			if ( $have[ $id ] < $qty ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * The `payer:` lock of a payer key, or none for an empty key (Store\Db refuses an empty lock name). Pure.
	 *
	 * @param string $payer_key Payer key.
	 * @return string[]
	 */
	public static function payer_locks( string $payer_key ): array {
		return '' === $payer_key ? array() : array( 'payer:' . $payer_key );
	}

	/**
	 * The merchant's line for the reasons this class writes that the order alone does not tell (docs/17 §4.7 names
	 * no text for them): stored in the transfer's note, which the transfer lists and the phone's status update show.
	 * Null for every other reason, whose text is built from the order.
	 *
	 * @param string $reason   Reason code.
	 * @param int    $order_id Order id.
	 * @return string|null
	 */
	public static function reason_note( string $reason, int $order_id ): ?string {
		if ( self::REASON_ON_HOLD === $reason ) {
			/* translators: %s: order number. */
			return sprintf( __( 'Order #%s is on hold, so it was not completed: check the order before you complete it by hand.', 'qersh-for-woocommerce' ), self::order_number( $order_id ) );
		}
		if ( self::REASON_FAILED === $reason ) {
			/* translators: %s: order number. */
			return sprintf( __( 'Order #%s could not be completed: WooCommerce reported an error each time. Check the order and complete it by hand.', 'qersh-for-woocommerce' ), self::order_number( $order_id ) );
		}
		return null;
	}

	/**
	 * Step 1: the locked decision, with its own error handling. Returns the plan of the call (step 2) after COMMIT,
	 * or null when the completion ended here: left without the call, already paid, or put back to `due` after a lock
	 * timeout or an error (nothing of WooCommerce ran yet, so a retry repeats nothing).
	 *
	 * @param int $order_id   Order id.
	 * @param int $claimed_at The claim's time (completion_at of our `running` row).
	 * @return array|null decide_locked() result with `do` = `call` and the order to complete, or null.
	 */
	private function decide_phase( int $order_id, int $claimed_at ): ?array {
		$db = $this->plugin->db();
		try {
			$plan = $this->decide_locked( $order_id, $claimed_at );
		} catch ( LockTimeout $e ) {
			$db->rollback();
			$this->back_to_due( $order_id, $claimed_at );
			$this->retry_later( $order_id );
			$this->log_line( 'warning', 'qersh: completion postponed, the payer lock was busy', $order_id );
			return null;
		} catch ( \Throwable $e ) {
			$db->rollback();
			$this->back_to_due( $order_id, $claimed_at );
			$this->log_failure( 'decision', $order_id, $e );
			return null;
		}
		if ( 'call' === $plan['do'] && $plan['order'] instanceof \WC_Order ) {
			return $plan;
		}
		if ( $plan['attempts'] > 0 ) {
			$this->forget_attempts( $order_id );
		}
		if ( 'gave_up' === $plan['do'] ) {
			$this->gave_up( $order_id );
		}
		return null;
	}

	/**
	 * The locked decision itself: payer lock, the row and the order read again, then decide_and_apply(), then
	 * COMMIT. A lock timeout (also at COMMIT, which is how Store\Db reports a Galera certification conflict) is tried
	 * once more, since nothing was called yet.
	 *
	 * @param int $order_id Order id.
	 * @param int $now      UTC seconds (the claim's time).
	 * @return array `[ 'do' => 'call'|'none'|'gave_up', 'order' => \WC_Order|null, 'payer' => string, 'attempts' => int ]`
	 * @throws LockTimeout When the payer lock could not be had, twice.
	 */
	private function decide_locked( int $order_id, int $now ): array {
		$db    = $this->plugin->db();
		$index = $this->plugin->orders_index();
		$none  = array(
			'do'       => 'none',
			'order'    => null,
			'payer'    => '',
			'attempts' => 0,
		);
		$row   = $index->get( $order_id );
		if ( null === $row ) {
			return $none; // The order was deleted with its row.
		}
		for ( $attempt = 0; ; $attempt++ ) {
			$payer = (string) $row['payer_key'];
			try {
				$db->begin();
				$db->lock( self::payer_locks( $payer ) );
				$row = $index->get( $order_id );
				if ( null === $row ) {
					$db->commit();
					return $none;
				}
				if ( (string) $row['payer_key'] !== $payer && $attempt < 1 ) {
					$db->rollback(); // A new process_payment() changed the payer: take that payer's lock instead.
					continue;
				}
				$plan          = $this->decide_and_apply( $order_id, $row, $now );
				$plan['payer'] = $payer;
				$db->commit();
				return $plan;
			} catch ( LockTimeout $e ) {
				$db->rollback();
				if ( $attempt >= 1 ) {
					throw $e;
				}
			}
		}
	}

	/**
	 * Inside the locked transaction: reads the order and its transfers, decides, and either hands the call to step 2
	 * or ends the completion here (row and transfers corrected). Writes only our own tables.
	 *
	 * @param int   $order_id Order id.
	 * @param array $row      The qersh_orders row, read under the lock.
	 * @param int   $now      UTC seconds.
	 * @return array `[ 'do' => 'call'|'none'|'gave_up', 'order' => \WC_Order|null, 'attempts' => int ]`
	 */
	private function decide_and_apply( int $order_id, array $row, int $now ): array {
		$index     = $this->plugin->orders_index();
		$order     = wc_get_order( $order_id );
		$transfers = $this->order_transfers( $order_id );
		$facts     = array(
			'exists'    => $order instanceof \WC_Order,
			'row_state' => isset( $row['state'] ) ? (string) $row['state'] : null,
			'attempts'  => $this->attempts( $order_id ),
		);
		if ( $order instanceof \WC_Order ) {
			$facts['method']  = (string) $order->get_payment_method();
			$facts['status']  = (string) $order->get_status();
			$facts['flagged'] = '' !== (string) $order->get_meta( '_qersh_flag', true ) || self::flagged( $transfers );
			$facts['expired'] = '' !== (string) $order->get_meta( '_qersh_expired_at', true );
			if ( 'cancelled' === $facts['status'] && $facts['expired'] ) {
				$facts['stock_ok'] = self::stock_enough( self::stock_lines( $order ) );
			}
		}
		$verdict = self::decide( $facts );

		if ( 'complete' === $verdict['action'] ) {
			return array(
				'do'       => 'call',
				'order'    => $order,
				'attempts' => $facts['attempts'],
			);
		}

		$fields = array(
			'completion'    => 'done',
			'completion_at' => time(),
		);
		if ( null !== $verdict['row_state'] ) {
			$fields['state'] = $verdict['row_state'];
		}
		$index->update( $order_id, $fields );
		if ( 'leave' === $verdict['action'] ) {
			$this->review_transfers( $order_id, $transfers, (string) $verdict['reason'], $now );
			$this->log_line(
				'info',
				'qersh: completion left without payment_complete()',
				$order_id,
				array(
					'status' => isset( $facts['status'] ) ? $facts['status'] : 'missing',
					'reason' => $verdict['reason'],
				)
			);
		}
		return array(
			'do'       => self::REASON_FAILED === $verdict['reason'] ? 'gave_up' : 'none',
			'order'    => null,
			'attempts' => $facts['attempts'],
		);
	}

	/**
	 * Steps 2 and 3: payment_complete() once, outside any transaction, then the row settled from what WooCommerce
	 * wrote. Never throws, never rolls back what WooCommerce wrote.
	 *
	 * @param int   $order_id   Order id.
	 * @param array $plan       decide_phase() result.
	 * @param int   $claimed_at The claim's time.
	 */
	private function call_phase( int $order_id, array $plan, int $claimed_at ) {
		$order     = $plan['order'];
		$attempts  = $this->count_attempt( $order_id );
		$succeeded = false;
		try {
			$succeeded = (bool) $order->payment_complete();
		} catch ( \Throwable $e ) {
			$this->log_failure( 'payment_complete()', $order_id, $e ); // A hook's Error: WooCommerce catches only Exception.
		}
		// From here on, what WooCommerce wrote (status, stock, notes, emails) stays, and nothing calls it again.
		$settled = $this->settle_phase( $order_id, (string) $plan['payer'], $succeeded, $attempts, $claimed_at );
		if ( null === $settled ) {
			return; // Still `running`: the sweep reads the order again once the claim is stale, and counts this call.
		}
		if ( 'due' !== $settled['completion'] ) {
			$this->forget_attempts( $order_id );
		}
		if ( $settled['stop'] ) {
			$this->stop_paid_order( $order_id );
		}
		if ( ! $settled['mine'] ) {
			return; // The forgery stop (or a stale-claim takeover) settled the row already.
		}
		if ( self::REASON_FAILED === $settled['reason'] ) {
			$this->gave_up( $order_id );
		} elseif ( 'due' === $settled['completion'] ) {
			$this->log_line( 'warning', 'qersh: payment_complete() failed and the order is still unpaid; the sweep tries again', $order_id, array( 'attempt' => $attempts ) );
		} elseif ( $succeeded ) {
			$this->log_line( 'info', 'qersh: order completed', $order_id );
		} else {
			$this->log_line( 'warning', 'qersh: payment_complete() reported an error; the order stays as WooCommerce wrote it, nothing is rolled back', $order_id, array( 'status' => $settled['status'] ) );
		}
	}

	/**
	 * Step 3 with its own error handling: under the payer lock (so a forgery stop running now has committed, or
	 * starts after us and sees the paid order), read and settle. When the lock stays busy or the transaction fails,
	 * the same reads and conditional writes run without it.
	 *
	 * @param int    $order_id   Order id.
	 * @param string $payer      Payer key of the decision.
	 * @param bool   $succeeded  payment_complete() returned true.
	 * @param int    $attempts   Calls made, this one included.
	 * @param int    $claimed_at The claim's time.
	 * @return array|null settle() result, or null when nothing could be written.
	 */
	private function settle_phase( int $order_id, string $payer, bool $succeeded, int $attempts, int $claimed_at ): ?array {
		$db = $this->plugin->db();
		for ( $try = 0; $try < 2; $try++ ) {
			try {
				$db->begin();
				$db->lock( self::payer_locks( $payer ) );
				$settled = $this->settle( $order_id, $succeeded, $attempts, $claimed_at );
				$db->commit();
				return $settled;
			} catch ( LockTimeout $e ) {
				$db->rollback();
			} catch ( \Throwable $e ) {
				$db->rollback();
				$this->log_failure( 'settle', $order_id, $e );
				break;
			}
		}
		try {
			return $this->settle( $order_id, $succeeded, $attempts, $claimed_at );
		} catch ( \Throwable $e ) {
			$this->log_failure( 'settle', $order_id, $e );
			return null;
		}
	}

	/**
	 * Reads the order and its transfers after the call and writes the row by settle_plan(), only while the claim is
	 * still ours (state paid, completion running since our claim).
	 *
	 * @param int  $order_id   Order id.
	 * @param bool $succeeded  payment_complete() returned true.
	 * @param int  $attempts   Calls made, this one included.
	 * @param int  $claimed_at The claim's time.
	 * @return array settle_plan() with `mine` (the row was ours and is written) and `status` (read after the call).
	 */
	private function settle( int $order_id, bool $succeeded, int $attempts, int $claimed_at ): array {
		$order     = wc_get_order( $order_id );
		$status    = $order instanceof \WC_Order ? (string) $order->get_status() : '';
		$transfers = $this->order_transfers( $order_id );
		$flagged   = self::flagged( $transfers ) || ( $order instanceof \WC_Order && '' !== (string) $order->get_meta( '_qersh_flag', true ) );
		$plan      = self::settle_plan( $succeeded, $status, $attempts, $flagged );
		$fields    = array( 'completion' => $plan['completion'] );
		if ( 'done' === $plan['completion'] ) {
			$fields['completion_at'] = time();
		}
		if ( null !== $plan['row_state'] ) {
			$fields['state'] = $plan['row_state'];
		}
		$plan['mine']   = $this->settle_row( $order_id, $claimed_at, $fields );
		$plan['status'] = $status;
		if ( $plan['mine'] && null !== $plan['reason'] ) {
			$this->review_transfers( $order_id, $transfers, $plan['reason'], time() );
		}
		return $plan;
	}

	/**
	 * The forgery stop of an order paid while its transfer was flagged (docs/17 §4.4 step 9): processing or completed
	 * goes on hold with the note; that change sends no email and reduces no stock. The flag, the transfers and the
	 * alert are written by the transfer.updated that set it. Outside any transaction. Never throws.
	 *
	 * @param int $order_id Order id.
	 */
	private function stop_paid_order( int $order_id ) {
		try {
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof \WC_Order || ! in_array( $order->get_status(), self::PAID_STATUSES, true ) ) {
				return;
			}
			$order->update_status( 'on-hold', __( 'This transfer’s message is suspected to be forged. Do not deliver the order until you have checked that the money arrived in the wallet app.', 'qersh-for-woocommerce' ) );
			$this->log_line( 'warning', 'qersh: the order was put on hold after its completion: its transfer was flagged as forged meanwhile', $order_id );
		} catch ( \Throwable $e ) {
			$this->log_failure( 'forgery stop', $order_id, $e );
		}
	}

	/**
	 * The completion was given up after MAX_ATTEMPTS calls: a note on the order for the merchant (the transfers are
	 * in "Needs decision" already) and an error line. Outside any transaction. Never throws.
	 *
	 * @param int $order_id Order id.
	 */
	private function gave_up( int $order_id ) {
		try {
			$order = wc_get_order( $order_id );
			if ( $order instanceof \WC_Order ) {
				$order->add_order_note( (string) self::reason_note( self::REASON_FAILED, $order_id ) );
			}
		} catch ( \Throwable $e ) {
			$this->log_failure( 'note', $order_id, $e );
		}
		$this->log_line( 'error', 'qersh: payment_complete() failed ' . self::MAX_ATTEMPTS . ' times; the completion is left to the merchant', $order_id );
	}

	/**
	 * The transfers that paid an order go to "Needs decision", and the phone hears their new status in the next
	 * replies (phone_sync = pending).
	 *
	 * @param int     $order_id  Order id.
	 * @param array[] $transfers The order's transfers.
	 * @param string  $reason    Reason code.
	 * @param int     $now       UTC seconds.
	 */
	private function review_transfers( int $order_id, array $transfers, string $reason, int $now ) {
		$store = $this->plugin->transfers();
		$note  = self::reason_note( $reason, $order_id );
		foreach ( $transfers as $transfer ) {
			if ( ! in_array( isset( $transfer['outcome'] ) ? $transfer['outcome'] : null, self::PAYING_OUTCOMES, true ) ) {
				continue;
			}
			$id     = (string) $transfer['transfer_id'];
			$fields = array(
				'outcome' => 'review',
				'reason'  => $reason,
			);
			if ( null !== $note ) {
				$fields['note'] = $note;
			}
			$store->update( $id, $fields );
			$store->request_sync( $id, $now );
		}
	}

	/**
	 * The transfers linked to an order.
	 *
	 * @param int $order_id Order id.
	 * @return array[]
	 */
	private function order_transfers( int $order_id ): array {
		$page = $this->plugin->transfers()->list_page( array( 'order_id' => $order_id ), 1, 200 );
		return isset( $page['rows'] ) && is_array( $page['rows'] ) ? $page['rows'] : array();
	}

	/**
	 * Writes the row only while the claim is ours: state paid and completion running since our claim. A forgery stop
	 * (completion done, row review) or a later claim of a stale one is never overwritten.
	 *
	 * @param int   $order_id   Order id.
	 * @param int   $claimed_at The claim's time.
	 * @param array $fields     Columns to write.
	 * @return bool True when the row was ours and is written.
	 * @throws \RuntimeException When the write failed.
	 */
	private function settle_row( int $order_id, int $claimed_at, array $fields ): bool {
		$db = $this->plugin->db();

		list( $set, $args ) = Db::set_sql( 'orders', $fields );

		$n = $db->query(
			'UPDATE %i SET ' . $set . " WHERE order_id = %d AND state = 'paid' AND completion = 'running' AND completion_at = %d",
			array_merge( array( $db->table( 'orders' ) ), $args, array( $order_id, $claimed_at ) )
		);
		if ( false === $n ) {
			throw new \RuntimeException( 'qersh: the completion could not be written' );
		}
		return $n > 0;
	}

	/**
	 * Puts our claim back to `due` after a failure before the call (outside any transaction). Never throws.
	 *
	 * @param int $order_id   Order id.
	 * @param int $claimed_at The claim's time.
	 */
	private function back_to_due( int $order_id, int $claimed_at ) {
		try {
			$this->settle_row( $order_id, $claimed_at, array( 'completion' => 'due' ) );
		} catch ( \Throwable $e ) {
			unset( $e ); // The claim expires after 900 seconds; the sweep takes it then.
		}
	}

	/**
	 * payment_complete() calls already made for the order's paid decision.
	 *
	 * @param int $order_id Order id.
	 * @return int
	 */
	private function attempts( int $order_id ): int {
		$value = get_transient( self::ATTEMPTS_TRANSIENT . $order_id );
		return is_numeric( $value ) ? max( 0, (int) $value ) : 0;
	}

	/**
	 * Counts one more call, before it is made (so a request that dies inside payment_complete() counts too).
	 *
	 * @param int $order_id Order id.
	 * @return int The calls, this one included.
	 */
	private function count_attempt( int $order_id ): int {
		$attempts = $this->attempts( $order_id ) + 1;
		try {
			set_transient( self::ATTEMPTS_TRANSIENT . $order_id, $attempts, DAY_IN_SECONDS );
		} catch ( \Throwable $e ) {
			$this->log_failure( 'attempt count', $order_id, $e );
		}
		return $attempts;
	}

	/**
	 * Forgets the call count once the completion is settled.
	 *
	 * @param int $order_id Order id.
	 */
	private function forget_attempts( int $order_id ) {
		try {
			delete_transient( self::ATTEMPTS_TRANSIENT . $order_id );
		} catch ( \Throwable $e ) {
			unset( $e ); // It expires after a day.
		}
	}

	/**
	 * Reads the stock facts of an order's lines for stock_enough().
	 *
	 * @param \WC_Order $order Order.
	 * @return array[]
	 */
	private static function stock_lines( \WC_Order $order ): array {
		if ( 'yes' !== get_option( 'woocommerce_manage_stock' ) ) {
			return array();
		}
		$lines = array();
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$product = $item->get_product();
			if ( ! $product instanceof \WC_Product ) {
				$lines[] = array( 'exists' => false );
				continue;
			}
			$line = array(
				'exists'     => true,
				'managed'    => $product->managing_stock(),
				'in_stock'   => 'outofstock' !== $product->get_stock_status(),
				'backorders' => $product->backorders_allowed(),
				'stock_id'   => $product->get_id(),
				'stock'      => 0,
				'held'       => 0,
				'qty'        => (float) $item->get_quantity(),
			);
			if ( $line['managed'] ) {
				$holder = wc_get_product( $product->get_stock_managed_by_id() );
				$holder = $holder instanceof \WC_Product ? $holder : $product;

				$line['stock_id'] = $holder->get_id();
				$line['stock']    = (float) $holder->get_stock_quantity();
				$line['held']     = function_exists( 'wc_get_held_stock_quantity' ) ? (float) wc_get_held_stock_quantity( $holder, $order->get_id() ) : 0;
			}
			$lines[] = $line;
		}
		return $lines;
	}

	/**
	 * Path (c): one Action Scheduler action per order (unique by its arguments). Without Action Scheduler the sweep
	 * finishes the order.
	 *
	 * @param int $order_id Order id.
	 */
	private function enqueue( int $order_id ) {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return;
		}
		try {
			as_enqueue_async_action( self::ACTION, self::action_args( $order_id ), self::GROUP, true );
		} catch ( \Throwable $e ) {
			$this->log_failure( 'enqueue', $order_id, $e );
		}
	}

	/**
	 * A completion to try again soon. Inside the completion action itself, Action Scheduler refuses a unique action
	 * with the same arguments (the running one counts), so a plain one is scheduled a minute later instead; a
	 * duplicate is harmless, since only one run wins the claim. Otherwise, path (c).
	 *
	 * @param int $order_id Order id.
	 */
	private function retry_later( int $order_id ) {
		if ( ! doing_action( self::ACTION ) ) {
			$this->enqueue( $order_id );
			return;
		}
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}
		try {
			as_schedule_single_action( time() + self::RETRY_SEC, self::ACTION, self::action_args( $order_id ), self::GROUP, false );
		} catch ( \Throwable $e ) {
			$this->log_failure( 'retry', $order_id, $e );
		}
	}

	/**
	 * The arguments of the completion action (the shape of the other per-order actions).
	 *
	 * @param int $order_id Order id.
	 * @return array
	 */
	private static function action_args( int $order_id ): array {
		return array( 'order_id' => $order_id );
	}

	/**
	 * Path (a): queues the order for the after-reply callback, hooked once.
	 *
	 * @param int $order_id Order id.
	 */
	private static function queue_after_reply( int $order_id ) {
		self::$queued[ $order_id ] = true;
		if ( ! self::$hooked ) {
			self::$hooked = true;
			add_action( 'shutdown', array( __CLASS__, 'on_shutdown' ), self::SHUTDOWN_PRIORITY );
		}
	}

	/**
	 * Sends what is buffered and ends the response, so the phone has its reply before the order is completed.
	 */
	private static function finish_response() {
		while ( ob_get_level() > 0 ) {
			$status = ob_get_status();
			if ( empty( $status ) || ! ( (int) $status['flags'] & PHP_OUTPUT_HANDLER_REMOVABLE ) ) {
				break;
			}
			ob_end_flush();
		}
		flush();
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		} elseif ( function_exists( 'litespeed_finish_request' ) ) {
			litespeed_finish_request();
		}
	}

	/**
	 * True in WP-Cron, Action Scheduler and WP-CLI runs: nobody waits for a reply there.
	 *
	 * @return bool
	 */
	private static function in_background(): bool {
		return doing_action( 'action_scheduler_run_queue' ) || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI );
	}

	/**
	 * Seconds since the request started.
	 *
	 * @return float
	 */
	private static function elapsed(): float {
		return function_exists( 'timer_float' ) ? (float) timer_float() : 0.0;
	}

	/**
	 * The order number the merchant and the customer see, isolated for Arabic text (docs/17 §7).
	 *
	 * @param int $order_id Order id.
	 * @return string
	 */
	private static function order_number( int $order_id ): string {
		$number = (string) $order_id;
		$order  = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		if ( $order instanceof \WC_Order ) {
			$number = (string) $order->get_order_number();
		}
		return "\u{2066}" . $number . "\u{2069}";
	}

	/**
	 * A verdict of decide().
	 *
	 * @param string      $action    Action.
	 * @param string|null $row_state New row state, or null.
	 * @param string|null $reason    Reason code, or null.
	 * @return array
	 */
	private static function verdict( string $action, ?string $row_state, ?string $reason ): array {
		return array(
			'action'    => $action,
			'row_state' => $row_state,
			'reason'    => $reason,
		);
	}

	/**
	 * A plan of settle_plan().
	 *
	 * @param string      $completion `done` or `due`.
	 * @param string|null $row_state  New row state, or null.
	 * @param string|null $reason     Reason for the paying transfers, or null.
	 * @param bool        $stop       Put the order on hold.
	 * @return array
	 */
	private static function settled( string $completion, ?string $row_state, ?string $reason, bool $stop ): array {
		return array(
			'completion' => $completion,
			'row_state'  => $row_state,
			'reason'     => $reason,
			'stop'       => $stop,
		);
	}

	/**
	 * Logs one line about an order, without personal data. Never throws.
	 *
	 * @param string $level    `info`, `warning` or `error`.
	 * @param string $message  Message.
	 * @param int    $order_id Order id.
	 * @param array  $context  More context (statuses, reason codes, counts only).
	 */
	private function log_line( string $level, string $message, int $order_id, array $context = array() ) {
		try {
			$logger  = $this->plugin->logger();
			$context = array_merge( array( 'order' => $order_id ), $context );
			if ( 'error' === $level ) {
				$logger->error( $message, $context );
			} elseif ( 'warning' === $level ) {
				$logger->warning( $message, $context );
			} else {
				$logger->info( $message, $context );
			}
		} catch ( \Throwable $ignored ) {
			unset( $ignored );
		}
	}

	/**
	 * Logs a failure without data: the class and place of the error.
	 *
	 * @param string     $where    Step.
	 * @param int        $order_id Order id.
	 * @param \Throwable $e        Error.
	 */
	private function log_failure( string $where, int $order_id, \Throwable $e ) {
		$this->log_line( 'error', 'qersh: completion ' . $where . ' failed: ' . get_class( $e ) . ' at ' . basename( $e->getFile() ) . ':' . $e->getLine(), $order_id );
	}
}
