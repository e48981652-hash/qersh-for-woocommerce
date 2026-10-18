<?php
/**
 * The deadline of an order (docs/17 §4.7): the cancellation at the deadline plus the late window, and the filters
 * that keep WooCommerce's own cancellation, stock hold and "Cancel" button in step with it.
 *
 * @package QershPay\WooCommerce
 */

namespace QershPay\WooCommerce;

use QershPay\WooCommerce\Core\TxnId;
use QershPay\WooCommerce\Store\LockTimeout;

defined( 'ABSPATH' ) || exit;

/**
 * Expiry::run() is the `qersh_wc_expire` action of one order and the 15-minute sweep's step, under the payer lock
 * and in its transaction:
 * - a row still `open` whose customer typed a transaction id that a transfer waiting for the merchant carries
 *   (review or personal, no order, no flag: holds_for_claim()) is not cancelled: the order stays pending, the
 *   transfer gets `claim_overdue` in "Needs a decision", the row `review`, the customer a note and the admin an alert;
 * - every customer note is added after COMMIT, so its email never goes out while the locks are held, and so is the
 *   admin's "Cancelled order" email when the store wants it (filter_admin_cancel_email());
 * - otherwise `UPDATE … SET state = 'expired' WHERE state = 'open'` and, only when it hit the row, the order is
 *   cancelled if it is still pending with our method and not in the trash (else the row is corrected alone):
 *   `_qersh_expired_at`, an internal note, and a customer note by the kind of wallet;
 * - a partial order (money received) is never cancelled: its transfers get `partial_overdue`, the row `review`,
 *   the customer a note and the admin an alert;
 * - an order flagged as forged is never cancelled;
 * - an `expired` row whose order is still pending finishes a cancellation that was cut short.
 */
final class Expiry {

	/**
	 * After our expiry cancelled an Orange order, its customer may still type the id this long.
	 */
	const REVIVE_SEC = 259200;

	/**
	 * The sweep stops starting new expiries after this many seconds.
	 */
	const SWEEP_BUDGET_SEC = 20;

	/**
	 * The admin's "Cancelled order" email of an expiry may be sent this long after it (when emails are deferred).
	 */
	const EMAIL_WINDOW_SEC = 3600;

	/**
	 * The plugin.
	 *
	 * @var Plugin
	 */
	private $plugin;

	/**
	 * The order our expiry is cancelling right now (for the admin email filter).
	 *
	 * @var int
	 */
	private static $cancelling = 0;

	/**
	 * The order whose admin "Cancelled order" email WooCommerce tried to send inside our expiry's transaction while
	 * store.admin_cancel_email_on_expiry is false: held back there, and sent after COMMIT (send_held_email()).
	 *
	 * @var int
	 */
	private static $held_email = 0;

	/**
	 * Builds the service.
	 *
	 * @param Plugin|null $plugin The plugin.
	 */
	public function __construct( $plugin = null ) {
		$this->plugin = $plugin instanceof Plugin ? $plugin : Plugin::instance();
	}

	/**
	 * Hooks the filters. Called by Webhook\Module when WooCommerce is ready.
	 *
	 * @param Plugin $plugin The plugin.
	 */
	public static function register_hooks( Plugin $plugin ): void {
		unset( $plugin );
		add_filter( 'woocommerce_cancel_unpaid_order', array( __CLASS__, 'filter_cancel_unpaid' ), 10, 2 );
		add_filter( 'woocommerce_order_hold_stock_minutes', array( __CLASS__, 'filter_hold_minutes' ), 10, 2 );
		add_filter( 'woocommerce_valid_order_statuses_for_cancel', array( __CLASS__, 'filter_cancel_statuses' ), 10, 2 );
		add_filter( 'woocommerce_available_payment_gateways', array( __CLASS__, 'filter_gateways' ), 20, 1 );
		add_filter( 'woocommerce_email_enabled_cancelled_order', array( __CLASS__, 'filter_admin_cancel_email' ), 10, 2 );
	}

	/**
	 * The expiry of one order (the `qersh_wc_expire` action, and the sweep). Never throws for a busy lock: the sweep
	 * tries again.
	 *
	 * @param int $order_id Order id.
	 * @throws \Throwable Any other error of the locked transaction, after its rollback.
	 */
	public function run( int $order_id ): void {
		if ( $order_id <= 0 || ! $this->plugin->wc_ready() || ! $this->plugin->tables_ready() ) {
			return;
		}
		$db = $this->plugin->db();
		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			$alert            = null;
			$note             = null;
			self::$held_email = 0; // A rolled-back try sends nothing: its cancellation is undone.
			try {
				$done = $this->run_once( $order_id, $alert, $note );
			} catch ( LockTimeout $e ) {
				$db->rollback();
				continue;
			} catch ( \Throwable $e ) {
				$db->rollback();
				self::$held_email = 0;
				throw $e;
			}
			if ( ! $done ) {
				continue;
			}
			if ( null !== $note ) {
				$this->customer_note_after_commit( $order_id, $note );
			}
			$this->send_held_email( $order_id );
			if ( null !== $alert ) {
				$this->plugin->orders()->alert( $alert[0], $alert[1] );
			}
			return;
		}
		$this->plugin->logger()->warning( 'qersh: expiry postponed, the payer lock was busy', array( 'order' => $order_id ) );
	}

	/**
	 * The sweep (every 15 minutes): open and partial rows past the deadline plus the late window, and expired rows
	 * whose cancellation may have been cut short.
	 */
	public function sweep(): void {
		if ( ! $this->plugin->wc_ready() || ! $this->plugin->tables_ready() ) {
			return;
		}
		$opts  = MatchPolicy::options( $this->plugin->config() );
		$start = microtime( true );
		foreach ( $this->plugin->orders_index()->due_expiries( time(), $opts['late_window_min'] * 60 ) as $order_id ) {
			if ( microtime( true ) - $start > self::SWEEP_BUDGET_SEC ) {
				break;
			}
			try {
				$this->run( (int) $order_id );
			} catch ( \Throwable $e ) {
				$this->plugin->logger()->error( 'qersh: expiry failed', array( 'error' => get_class( $e ) ) );
			}
		}
	}

	/**
	 * Filters woocommerce_cancel_unpaid_order: WooCommerce's own cancellation of unpaid orders skips a `qersh` order
	 * that has our deadline; anything else (a Blocks order the server refused, still pending without a deadline) is
	 * left to WooCommerce.
	 *
	 * @param bool           $cancel Whether WooCommerce would cancel it.
	 * @param \WC_Order|null $order  Order.
	 * @return bool
	 */
	public static function filter_cancel_unpaid( $cancel, $order = null ) {
		if ( $order instanceof \WC_Order && Orders::METHOD === $order->get_payment_method() && '' !== (string) $order->get_meta( '_qersh_deadline', true ) ) {
			return false;
		}
		return $cancel;
	}

	/**
	 * Filters woocommerce_order_hold_stock_minutes: the hold lasts until the deadline plus the late window, from the
	 * configuration, because WooCommerce holds before process_payment(). A store that switched the hold off (0)
	 * keeps it off.
	 *
	 * @param int            $minutes Minutes.
	 * @param \WC_Order|null $order   Order.
	 * @return int
	 */
	public static function filter_hold_minutes( $minutes, $order = null ) {
		if ( ! $order instanceof \WC_Order || Orders::METHOD !== $order->get_payment_method() ) {
			return $minutes;
		}
		return self::hold_minutes( (int) $minutes, MatchPolicy::options( Plugin::instance()->config() ) );
	}

	/**
	 * The hold of a `qersh` order. Pure.
	 *
	 * @param int   $minutes WooCommerce's minutes.
	 * @param array $opts    MatchPolicy::options().
	 * @return int
	 */
	public static function hold_minutes( int $minutes, array $opts ): int {
		if ( 0 === $minutes ) {
			return 0;
		}
		return max( $minutes, (int) $opts['order_ttl_min'] + (int) $opts['late_window_min'] );
	}

	/**
	 * Filters woocommerce_valid_order_statuses_for_cancel: the customer cannot cancel an order whose money the
	 * merchant already holds, or whose customer typed a live transaction id.
	 *
	 * @param array          $statuses Statuses the customer may cancel from.
	 * @param \WC_Order|null $order    Order.
	 * @return array
	 */
	public static function filter_cancel_statuses( $statuses, $order = null ) {
		if ( ! is_array( $statuses ) || ! $order instanceof \WC_Order || Orders::METHOD !== $order->get_payment_method() ) {
			return $statuses;
		}
		if ( ! self::cancel_locked( (int) $order->get_id() ) ) {
			return $statuses;
		}
		return array_values( array_diff( $statuses, array( 'pending' ) ) );
	}

	/**
	 * Filters woocommerce_available_payment_gateways: on "Pay for order" of such an order, only our method is
	 * offered (payment_fields() says why, package C).
	 *
	 * @param array $gateways Gateways by id.
	 * @return array
	 */
	public static function filter_gateways( $gateways ) {
		if ( ! is_array( $gateways ) || ! isset( $gateways[ Orders::METHOD ] ) || ! function_exists( 'is_wc_endpoint_url' ) || ! is_wc_endpoint_url( 'order-pay' ) ) {
			return $gateways;
		}
		$order_id = absint( get_query_var( 'order-pay' ) );
		$order    = $order_id > 0 ? wc_get_order( $order_id ) : false;
		if ( ! $order instanceof \WC_Order || Orders::METHOD !== $order->get_payment_method() || ! self::cancel_locked( $order_id ) ) {
			return $gateways;
		}
		return array( Orders::METHOD => $gateways[ Orders::METHOD ] );
	}

	/**
	 * Filters woocommerce_email_enabled_cancelled_order: store.admin_cancel_email_on_expiry, on by default, turns off
	 * the admin's "Cancelled order" email when our expiry alone cancelled the order (docs/17 §4.2, §4.7); off, the
	 * email goes, though never from inside our transaction (cancel_email_now()): WooCommerce sends it there when it
	 * does not defer emails (Action Scheduler, cron), so it is held back and sent after COMMIT, and SMTP never runs
	 * while the payer lock is held. Every other cancellation keeps it.
	 *
	 * @param bool           $enabled Whether the email is enabled.
	 * @param \WC_Order|null $order   Order.
	 * @return bool
	 */
	public static function filter_admin_cancel_email( $enabled, $order = null ) {
		if ( ! $enabled || ! $order instanceof \WC_Order ) {
			return $enabled;
		}
		$plugin = Plugin::instance();
		$mutes  = self::mutes_admin_cancel_email( $plugin->config() );
		$id     = (int) $order->get_id();
		if ( self::$cancelling === $id ) {
			if ( 'after_commit' === self::cancel_email_now( $mutes ) ) {
				self::$held_email = $id;
			}
			return false;
		}
		if ( ! $mutes ) {
			return $enabled;
		}
		$expired_at = (int) $order->get_meta( '_qersh_expired_at', true );
		if ( 'cancelled' !== $order->get_status() || $expired_at <= 0 || time() - $expired_at > self::EMAIL_WINDOW_SEC || ! $plugin->tables_ready() ) {
			return $enabled;
		}
		try {
			$row = $plugin->orders_index()->get( $id );
		} catch ( \Throwable $e ) {
			return $enabled;
		}
		return null !== $row && 'expired' === $row['state'] ? false : $enabled; // A deferred email of our expiry.
	}

	/**
	 * Whether store.admin_cancel_email_on_expiry turns off the admin's "Cancelled order" email of our expiry: on by
	 * default (a missing value), off only when it is false. Pure.
	 *
	 * @param array $config The effective configuration (Plugin::config()).
	 * @return bool
	 */
	public static function mutes_admin_cancel_email( array $config ): bool {
		return ! isset( $config['store']['admin_cancel_email_on_expiry'] ) || false !== $config['store']['admin_cancel_email_on_expiry'];
	}

	/**
	 * What becomes of the admin's "Cancelled order" email that WooCommerce sends while our expiry cancels the order,
	 * inside its transaction: `mute` when mutes_admin_cancel_email() (never sent), else `after_commit` (held back,
	 * then sent once the transaction is committed). A deferred email is not sent there at all: it goes later, through
	 * the filter's other rules. Pure.
	 *
	 * @param bool $mutes mutes_admin_cancel_email().
	 * @return string `mute` or `after_commit`.
	 */
	public static function cancel_email_now( bool $mutes ): string {
		return $mutes ? 'mute' : 'after_commit';
	}

	/**
	 * After COMMIT: the admin's "Cancelled order" email that the filter held back inside the transaction, sent once
	 * through WooCommerce's own email (its other listeners of the status change ran already). Never throws.
	 *
	 * @param int $order_id Order id.
	 */
	private function send_held_email( int $order_id ): void {
		if ( self::$held_email !== $order_id ) {
			return;
		}
		self::$held_email = 0;
		try {
			$emails = function_exists( 'WC' ) ? WC()->mailer()->get_emails() : array();
			$order  = wc_get_order( $order_id );
			$email  = isset( $emails['WC_Email_Cancelled_Order'] ) ? $emails['WC_Email_Cancelled_Order'] : null;
			if ( $email instanceof \WC_Email_Cancelled_Order && $order instanceof \WC_Order ) {
				$email->trigger( $order_id, $order );
			}
		} catch ( \Throwable $e ) {
			$this->plugin->logger()->error(
				'qersh: the admin email of an expiry failed',
				array(
					'order' => $order_id,
					'error' => get_class( $e ),
				)
			);
		}
	}

	/**
	 * Whether the customer may not cancel the order or pay it another way: money already received, or a live
	 * transaction id typed by the customer.
	 *
	 * @param int $order_id Order id.
	 * @return bool
	 */
	public static function cancel_locked( int $order_id ): bool {
		$plugin = Plugin::instance();
		if ( $order_id <= 0 || ! $plugin->tables_ready() ) {
			return false;
		}
		try {
			$row = $plugin->orders_index()->get( $order_id );
		} catch ( \Throwable $e ) {
			return false;
		}
		return null !== $row && self::locked_row( $row );
	}

	/**
	 * The rule of cancel_locked() on a row: money received, or a transaction id typed on a row that still waits —
	 * live (open, partial, expired), or held at the deadline for the merchant's decision (`review`, claim_overdue:
	 * the order stays pending until the merchant decides, docs/17 §4.7). Pure.
	 *
	 * @param array $row Typed qersh_orders row.
	 * @return bool
	 */
	public static function locked_row( array $row ): bool {
		if ( (int) $row['paid_minor'] > 0 ) {
			return true;
		}
		$waiting = in_array( $row['state'], MatchPolicy::LIVE_STATES, true ) || 'review' === $row['state'];
		return isset( $row['claim_txn'] ) && '' !== $row['claim_txn'] && $waiting;
	}

	/**
	 * Which customer note a cancelled order gets (docs/17 §4.7). Pure.
	 *
	 * @param string $provider  Provider of the order's wallet.
	 * @param bool   $has_txn   The customer typed a transaction id.
	 * @param bool   $txn_entry store.txn_entry.
	 * @return string `payer`, `orange`, `orange_txn` or `decision`.
	 */
	public static function note_kind( string $provider, bool $has_txn, bool $txn_entry ): string {
		if ( in_array( $provider, MatchPolicy::PAYER_PROVIDERS, true ) ) {
			return 'payer';
		}
		if ( in_array( $provider, TxnId::CLAIM_PROVIDERS, true ) && ( $txn_entry || $has_txn ) ) {
			return $has_txn ? 'orange_txn' : 'orange';
		}
		return 'decision';
	}

	/**
	 * Whether a transfer holds an order at its deadline when its transaction id is the one the customer typed
	 * (docs/17 §4.7): it counts toward no order, is not flagged, and waits for the merchant, either in "Needs a
	 * decision" (`review`: another amount, a contested id, no automatic confirmation) or under "Personal transfers"
	 * (`unmatched`/`personal`: it arrived before the id was typed, and no live order of the wallet fits its amount),
	 * so the result never depends on which of the two came first. Pure.
	 *
	 * @param array $tr Typed qersh_transfers row.
	 * @return bool
	 */
	public static function holds_for_claim( array $tr ): bool {
		if ( isset( $tr['order_id'] ) || ( isset( $tr['flag'] ) && '' !== (string) $tr['flag'] ) ) {
			return false;
		}
		$outcome = isset( $tr['outcome'] ) ? (string) $tr['outcome'] : '';
		return 'review' === $outcome || ( 'unmatched' === $outcome && isset( $tr['reason'] ) && 'personal' === $tr['reason'] );
	}

	/**
	 * One try under the payer lock: false to try again (rolled back already). The customer's note is handed out for
	 * after COMMIT (customer_note_after_commit()).
	 *
	 * @param int         $order_id Order id.
	 * @param array|null  $alert    Set to `[ kind, args ]` for an admin alert.
	 * @param string|null $note     Set to the customer's note of the order.
	 * @return bool
	 * @throws LockTimeout On a lock wait or a deadlock.
	 */
	private function run_once( int $order_id, &$alert, &$note ): bool {
		$db    = $this->plugin->db();
		$index = $this->plugin->orders_index();
		$opts  = MatchPolicy::options( $this->plugin->config() );
		$now   = time();
		$hint  = $index->get( $order_id );
		if ( null === $hint ) {
			return true;
		}
		$db->begin();
		$db->lock( Orders::payer_lock( (string) $hint['payer_key'] ) );
		$row = $index->get( $order_id );
		if ( null === $row ) {
			$db->commit();
			return true;
		}
		if ( (string) $row['payer_key'] !== (string) $hint['payer_key'] ) {
			$db->rollback();
			return false;
		}
		if ( (int) $row['expires_at'] + $opts['late_window_min'] * 60 > $now ) {
			$db->commit();
			return true; // The deadline moved (a new process_payment()); its own action follows.
		}
		$order   = wc_get_order( $order_id );
		$flagged = $order instanceof \WC_Order && '' !== (string) $order->get_meta( '_qersh_flag', true );
		$pending = $order instanceof \WC_Order && 'pending' === $order->get_status() && Orders::METHOD === $order->get_payment_method();

		if ( 'open' === $row['state'] ) {
			if ( $flagged ) {
				$db->commit();
				return true; // Never cancelled: the merchant decides.
			}
			$waiting = $this->waiting_transfer( $row );
			if ( null !== $waiting && $pending ) {
				$text = $this->hold_for_claim( $order, $row, $waiting, $now );
				$db->commit();
				$note  = $text;
				$alert = array(
					'claim_overdue',
					array(
						'order_id'    => $order_id,
						'transfer_id' => (string) $waiting['transfer_id'],
						'wallet'      => (string) $row['wallet'],
					),
				);
				return true;
			}
			if ( ! $index->set_expired_if_open( $order_id ) ) {
				$db->commit();
				return true;
			}
			$text = null;
			if ( $pending ) {
				$text = $this->cancel( $order, $row, $opts, $now );
			} else {
				$index->update( $order_id, array( 'state' => 'closed' ) ); // The order left pending by another hand.
			}
			$db->commit();
			$note = $text;
			return true;
		}
		if ( 'partial' === $row['state'] ) {
			$text = null;
			$ids  = $this->hold_partial( $order, $row, $text );
			$db->commit();
			$note  = $text;
			$alert = array(
				'partial_overdue',
				array(
					'order_id'    => $order_id,
					'transfer_id' => isset( $ids[0] ) ? $ids[0] : '',
					'wallet'      => (string) $row['wallet'],
				),
			);
			return true;
		}
		$text = null;
		if ( 'expired' === $row['state'] && $pending && ! $flagged ) {
			$text = $this->cancel( $order, $row, $opts, $now ); // A cancellation that was cut short.
		}
		$db->commit();
		$note = $text;
		return true;
	}

	/**
	 * Adds the customer's note of an expiry after COMMIT. A customer note is emailed at once outside a webhook
	 * request (Action Scheduler, cron: WooCommerce defers no email there), and SMTP must never run while the payer
	 * lock and the row locks are held (on SQLite, the whole database is write-locked). A rolled-back try hands out no
	 * note, so a retried expiry never emails twice. Never throws.
	 *
	 * @param int    $order_id Order id.
	 * @param string $text     The note.
	 */
	private function customer_note_after_commit( int $order_id, string $text ): void {
		try {
			$order = wc_get_order( $order_id );
			if ( $order instanceof \WC_Order ) {
				$order->add_order_note( $text, 1 );
			}
		} catch ( \Throwable $e ) {
			$this->plugin->logger()->error(
				'qersh: the customer note of an expiry failed',
				array(
					'order' => $order_id,
					'error' => get_class( $e ),
				)
			);
		}
	}

	/**
	 * The transfer that waits for the merchant with the order's typed id: same canonical id on Orange, and
	 * holds_for_claim().
	 *
	 * @param array $row Row.
	 * @return array|null
	 */
	private function waiting_transfer( array $row ): ?array {
		$txn = isset( $row['claim_txn'] ) ? (string) $row['claim_txn'] : '';
		if ( '' === $txn ) {
			return null;
		}
		$providers = $this->plugin->orders()->wallet_providers();
		$provider  = isset( $providers[ $row['wallet'] ] ) ? $providers[ $row['wallet'] ] : '';
		if ( ! in_array( $provider, TxnId::CLAIM_PROVIDERS, true ) ) {
			return null;
		}
		foreach ( $this->plugin->transfers()->find_by_txn( $provider, $txn ) as $tr ) {
			if ( self::holds_for_claim( $tr ) ) {
				return $tr;
			}
		}
		return null;
	}

	/**
	 * A typed id matches a transfer waiting for the merchant: the order stays pending, its stock held, and the
	 * transfer gets `claim_overdue` in "Needs a decision". A personal one moves there from "Personal transfers"
	 * (`review`), and the phone, which was told "unmatched", hears it in the next replies.
	 *
	 * @param \WC_Order $order Order.
	 * @param array     $row   Row.
	 * @param array     $tr    The waiting transfer.
	 * @param int       $now   UTC seconds.
	 * @return string The customer's note, for after COMMIT.
	 */
	private function hold_for_claim( \WC_Order $order, array $row, array $tr, int $now ): string {
		$id        = (int) $row['order_id'];
		$tid       = (string) $tr['transfer_id'];
		$transfers = $this->plugin->transfers();
		$fields    = array(
			'reason' => 'claim_overdue',
			'note'   => MatchPolicy::reason_text( 'claim_overdue', array( 'N' => $id ) ),
		);
		if ( 'review' !== $tr['outcome'] ) {
			$fields['outcome'] = 'review';
		}
		$this->plugin->orders_index()->update( $id, array( 'state' => 'review' ) );
		$transfers->update( $tid, $fields );
		if ( isset( $fields['outcome'] ) ) {
			$transfers->request_sync( $tid, $now );
		}
		return sprintf(
			/* translators: %s: order number. */
			__( 'The time for your order #%s has ended. The store will contact you to complete the order or refund what arrived.', 'qersh-for-woocommerce' ),
			Orders::ltr( (string) $order->get_order_number() )
		);
	}

	/**
	 * A partial order at its deadline: never cancelled. Its transfers go to "Needs decision" (partial_overdue), the
	 * row to review, and the customer hears that the store will contact them.
	 *
	 * @param \WC_Order|false|null $order Order.
	 * @param array                $row   Row.
	 * @param string|null          $note  Set to the customer's note, for after COMMIT (none without the order).
	 * @return string[] Transfer ids marked.
	 */
	private function hold_partial( $order, array $row, &$note ) {
		$id        = (int) $row['order_id'];
		$transfers = $this->plugin->transfers();
		$this->plugin->orders_index()->update( $id, array( 'state' => 'review' ) );
		$ids  = array();
		$page = $transfers->list_page( array( 'order_id' => $id ), 1, 200 );
		foreach ( $page['rows'] as $tr ) {
			if ( ! in_array( $tr['outcome'], array( 'partial', 'attached' ), true ) ) {
				continue;
			}
			$transfers->update(
				(string) $tr['transfer_id'],
				array(
					'reason' => 'partial_overdue',
					'note'   => MatchPolicy::reason_text( 'partial_overdue' ),
				)
			);
			$ids[] = (string) $tr['transfer_id'];
		}
		if ( $order instanceof \WC_Order ) {
			$orders = $this->plugin->orders();
			$note   = sprintf(
				/* translators: 1: order number, 2: amount received, 3: order total. */
				__( 'The time for your order #%1$s has ended, and we received %2$s of %3$s. The store will contact you to complete the order or refund what arrived.', 'qersh-for-woocommerce' ),
				Orders::ltr( (string) $order->get_order_number() ),
				$orders->money( (int) $row['paid_minor'], $order ),
				$orders->money( (int) $row['price_minor'], $order )
			);
		}
		return $ids;
	}

	/**
	 * Cancels the order inside the transaction: `_qersh_expired_at` and the internal note with the status change.
	 * The customer's note by the kind of wallet (WooCommerce tells only the admin about pending → cancelled) is
	 * returned for after COMMIT.
	 *
	 * @param \WC_Order $order Order.
	 * @param array     $row   Row.
	 * @param array     $opts  MatchPolicy::options().
	 * @param int       $now   UTC seconds.
	 * @return string The customer's note.
	 */
	private function cancel( \WC_Order $order, array $row, array $opts, int $now ): string {
		$id = (int) $order->get_id();
		$order->update_meta_data( '_qersh_expired_at', (string) $now );
		self::$cancelling = $id;
		try {
			$order->update_status( 'cancelled', __( 'The transfer did not arrive before the deadline.', 'qersh-for-woocommerce' ) );
		} finally {
			self::$cancelling = 0;
		}
		return $this->customer_note( $order, $row, $opts );
	}

	/**
	 * The customer's note of a cancelled order (docs/17 §4.7).
	 *
	 * @param \WC_Order $order Order.
	 * @param array     $row   Row.
	 * @param array     $opts  MatchPolicy::options().
	 * @return string
	 */
	private function customer_note( \WC_Order $order, array $row, array $opts ): string {
		$providers = $this->plugin->orders()->wallet_providers();
		$provider  = isset( $providers[ $row['wallet'] ] ) ? $providers[ $row['wallet'] ] : '';
		$txn       = isset( $row['claim_txn'] ) ? (string) $row['claim_txn'] : '';
		$number    = Orders::ltr( (string) $order->get_order_number() );
		switch ( self::note_kind( $provider, '' !== $txn, ! empty( $opts['txn_entry'] ) ) ) {
			case 'payer':
				/* translators: %s: order number. */
				return sprintf( __( 'The transfer window for order #%s has ended and the order was cancelled. Do not transfer for it now. If you transferred before the deadline, your order will be confirmed when the transfer arrives; otherwise contact the store with your order number.', 'qersh-for-woocommerce' ), $number );
			case 'orange':
				$url  = Checkout::txn_page_url( $order );
				$link = Format::isolate( '<a href="' . esc_url( $url ) . '">' . esc_html( $url ) . '</a>', Format::NOTE );
				/* translators: 1: order number, 2: link to the order page. */
				return sprintf( __( 'The transfer window for order #%1$s has ended and the order was cancelled. Do not transfer for it now. If you transferred before the deadline, enter the transaction id from your transfer message on your order page within 72 hours: %2$s. Your order comes back when your transfer matches it, as long as the products are available; otherwise contact the store with your order number.', 'qersh-for-woocommerce' ), $number, $link );
			case 'orange_txn':
				/* translators: 1: order number, 2: transaction id. */
				return sprintf( __( 'The transfer window for order #%1$s has ended and the order was cancelled. We kept the transaction id %2$s, so do not transfer again. If you transferred before the deadline, your order comes back when your transfer arrives and the store checks it, as long as the products are available; otherwise contact the store with your order number.', 'qersh-for-woocommerce' ), $number, Orders::ltr( $txn ) );
			default:
				/* translators: %s: order number. */
				return sprintf( __( 'The transfer window for order #%s has ended and the order was cancelled. Do not transfer for it now. If you transferred before the deadline, the store brings your order back after it checks your transfer, as long as the products are available; otherwise contact the store with your order number.', 'qersh-for-woocommerce' ), $number );
		}
	}
}
