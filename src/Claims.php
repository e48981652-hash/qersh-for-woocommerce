<?php
/**
 * The Orange Cash transaction id a customer types on the order page (docs/17 §4.10): saving it, and the late
 * evaluation when the transfer arrived before it.
 *
 * @package QershPay\WooCommerce
 */

namespace QershPay\WooCommerce;

use QershPay\WooCommerce\Core\Risk;
use QershPay\WooCommerce\Core\TxnId;
use QershPay\WooCommerce\Store\LockTimeout;

defined( 'ABSPATH' ) || exit;

/**
 * The id only identifies the order; it never proves the payment: the confirmation stays the phone's
 * (auto_confirm_ok of the transfer.received itself), and every rule of 4a applies.
 *
 * - submit() is the core of `POST qersh/v1/order-txn` after its permission check (ClaimRoute, package C): the shape
 *   before any database work, the same id again for free, the locks, the order's state, the atomic counter, a
 *   private note, COMMIT, `_qersh_claim_txn`, then the late evaluation after the reply. One body, whatever is
 *   behind the id: the route never tells anything about the transfers.
 * - evaluate() looks, under the locks of §4.4 step 8, for the transfer the customer means, and runs rule 4a on it
 *   when it waits as `review`/`no_payer` (the eligible row). Never inside the customer's request: after the
 *   response on `shutdown` (priority 5) where the host can finish it early, else in the `qersh_wc_claim_eval`
 *   Action Scheduler action.
 * - sweep() evaluates again the live ids an eligible row matches but whose evaluation never ran.
 *
 * What the customer wrote is never stored: only the canonical id.
 */
final class Claims {

	/**
	 * The provider whose customers type an id (0.1.0).
	 */
	const PROVIDER = 'orange_cash';

	/**
	 * After our expiry cancelled the order, an id is still accepted this long (the owner's decision).
	 */
	const REVIVE_SEC = 259200;

	/**
	 * Retry-After of a busy lock on the route.
	 */
	const BUSY_RETRY = 5;

	/**
	 * The Action Scheduler hook of the late evaluation.
	 */
	const ACTION = 'qersh_wc_claim_eval';

	/**
	 * The priority of the after-reply callback (after wp_ob_end_flush_all at 1, before _wp_cron at 10 and the
	 * deferred email sender at 100), as Completion's.
	 */
	const SHUTDOWN_PRIORITY = 5;

	/**
	 * The sweep evaluates at most this many orders per run.
	 */
	const SWEEP_LIMIT = 50;

	/**
	 * The plugin.
	 *
	 * @var Plugin
	 */
	private $plugin;

	/**
	 * Orders waiting for the after-reply evaluation.
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
	 * Builds the service.
	 *
	 * @param Plugin|null $plugin The plugin.
	 */
	public function __construct( $plugin = null ) {
		$this->plugin = $plugin instanceof Plugin ? $plugin : Plugin::instance();
	}

	/**
	 * Saves the transaction id a customer typed for an order (docs/17 §4.10, route steps 1–8).
	 *
	 * @param \WC_Order $order Order (the route checked its key).
	 * @param mixed     $raw   What the customer sent.
	 * @param int       $now   UTC seconds.
	 * @return array `[ 'http' => int, 'body' => array, 'retry_after' => int|null ]`
	 * @throws \Throwable Any error of the locked transaction other than a busy lock, after its rollback.
	 */
	public function submit( \WC_Order $order, $raw, int $now ): array {
		$id    = (int) $order->get_id();
		$index = $this->plugin->orders_index();
		$opts  = MatchPolicy::options( $this->plugin->config() );
		$row   = $index->get( $id );
		if ( Orders::METHOD !== $order->get_payment_method() || empty( $opts['txn_entry'] ) || null === $row ) {
			return self::not_found();
		}
		// The order's wallet is still in the configuration, turned off or not: turning a wallet off only hides it from
		// the checkout (docs/17 §5 step 10), so the orders already waiting on it keep their field (ClaimRoute,
		// Checkout::txn_wallet()). A deleted wallet has no entry.
		$wallet = $this->plugin->wallet( (string) $row['wallet'] );
		if ( null === $wallet || ! isset( $wallet['provider'] ) || self::PROVIDER !== $wallet['provider'] ) {
			return self::not_found();
		}

		// Step 1: the shape, before any database write.
		$read = TxnId::canonical_typed( self::PROVIDER, $raw, (string) $row['payer_key'], $this->store_numbers() );
		if ( empty( $read['ok'] ) ) {
			return self::refusal( 400, (string) $read['error'], $order );
		}
		$txn = (string) $read['txn'];

		// Step 2: the same id again costs nothing and evaluates nothing.
		if ( isset( $row['claim_txn'] ) && $txn === $row['claim_txn'] ) {
			return self::saved( $txn, (int) $row['claim_tries'] );
		}

		// Steps 3–6 under txn:orange_cash:<id> then payer:<the order's payer>.
		$db    = $this->plugin->db();
		$payer = (string) $row['payer_key'];
		$saved = null;
		for ( $attempt = 0; $attempt < 3 && null === $saved; $attempt++ ) {
			try {
				$db->begin();
				$db->lock( array_merge( array( 'txn:' . self::PROVIDER . ':' . $txn ), Orders::payer_lock( $payer ) ) );
				$row = $index->get( $id );
				if ( null === $row ) {
					$db->rollback();
					return self::not_found();
				}
				if ( (string) $row['payer_key'] !== $payer ) {
					$db->rollback();
					$payer = (string) $row['payer_key']; // A new process_payment() changed the payer: take its lock.
					continue;
				}
				if ( isset( $row['claim_txn'] ) && $txn === $row['claim_txn'] ) {
					$db->commit();
					return self::saved( $txn, (int) $row['claim_tries'] );
				}
				if ( ! self::accepts( $order, $row, $now ) ) {
					$db->rollback();
					return self::refusal( 409, 'qersh_txn_closed', $order );
				}
				$verdict = $index->bump_claim( $id, $txn, $now );
				if ( 'ok' !== $verdict ) {
					$db->rollback();
					if ( 0 === strpos( $verdict, 'wait:' ) ) {
						$out                = self::refusal( 429, 'qersh_txn_wait', $order );
						$out['retry_after'] = max( 1, (int) substr( $verdict, 5 ) );
						return $out;
					}
					return self::refusal( 409, 'qersh_txn_locked', $order );
				}
				$old = isset( $row['claim_txn'] ) ? (string) $row['claim_txn'] : '';
				if ( '' === $old ) {
					/* translators: %s: transaction id. */
					$order->add_order_note( sprintf( __( 'The customer entered the transaction id %s.', 'qersh-for-woocommerce' ), Orders::ltr( $txn ) ) );
				} else {
					/* translators: 1: new transaction id, 2: the one before. */
					$order->add_order_note( sprintf( __( 'The customer changed the transaction id to %1$s (it was %2$s).', 'qersh-for-woocommerce' ), Orders::ltr( $txn ), Orders::ltr( $old ) ) );
				}
				$db->commit();
				$saved = (int) $row['claim_tries'] + 1;
			} catch ( LockTimeout $e ) {
				$db->rollback();
				if ( $attempt >= 1 ) {
					$out                = self::refusal( 429, 'qersh_txn_busy', $order );
					$out['retry_after'] = self::BUSY_RETRY;
					return $out;
				}
			} catch ( \Throwable $e ) {
				$db->rollback();
				throw $e;
			}
		}
		if ( null === $saved ) {
			$out                = self::refusal( 429, 'qersh_txn_busy', $order );
			$out['retry_after'] = self::BUSY_RETRY;
			return $out;
		}

		$order->update_meta_data( '_qersh_claim_txn', $txn );
		$order->save_meta_data();
		$this->plugin->logger()->info(
			'qersh: transaction id saved',
			array(
				'order' => $id,
				'txn'   => $txn,
			)
		);

		// Step 7: the late evaluation, never inside this request.
		$this->schedule_evaluation( $id );
		return self::saved( $txn, $saved );
	}

	/**
	 * The late evaluation (docs/17 §4.10): under the locks, the transfer with this provider and canonical id. Rule
	 * 4a runs only for the one eligible row (`review`, `no_payer`, no order, no flag, auto_confirm_ok = 1), after
	 * rule 1 on its stored reasons. A row of this order already: nothing. A row that paid another order, or any other
	 * row: a private note. A failed evaluation is kept in claim_eval only, so the row stays eligible. Never throws
	 * for a busy lock (the sweep tries again).
	 *
	 * @param int $order_id Order id.
	 * @throws \Throwable Any other error of the locked transaction, after its rollback.
	 */
	public function evaluate( int $order_id ): void {
		if ( $order_id <= 0 || ! $this->plugin->wc_ready() || ! $this->plugin->tables_ready() ) {
			return;
		}
		$db = $this->plugin->db();
		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			try {
				if ( $this->evaluate_once( $order_id ) ) {
					return;
				}
			} catch ( LockTimeout $e ) {
				$db->rollback();
			} catch ( \Throwable $e ) {
				$db->rollback();
				throw $e;
			}
		}
		$this->plugin->logger()->warning( 'qersh: transaction id evaluation postponed, the locks were busy', array( 'order' => $order_id ) );
	}

	/**
	 * The sweep (every 15 minutes, from Webhook\Module): evaluates again the live ids that an eligible row matches
	 * and whose last evaluation is older than the claim (a callback that never ran).
	 */
	public function sweep(): void {
		if ( ! $this->plugin->wc_ready() || ! $this->plugin->tables_ready() ) {
			return;
		}
		$db   = $this->plugin->db();
		$rows = $db->rows(
			"SELECT DISTINCT o.order_id FROM %i o INNER JOIN %i t ON t.provider_txn_id = o.claim_txn WHERE o.claim_txn IS NOT NULL AND o.state IN ('open','partial','expired') AND t.provider = %s AND t.outcome = 'review' AND t.reason = 'no_payer' AND t.order_id IS NULL AND t.flag IS NULL AND t.auto_confirm_ok = 1 AND ( t.claim_eval_at IS NULL OR t.claim_eval_at < o.claim_at ) ORDER BY o.order_id ASC LIMIT %d",
			array( $db->table( 'orders' ), $db->table( 'transfers' ), self::PROVIDER, self::SWEEP_LIMIT )
		);
		foreach ( $rows as $row ) {
			try {
				$this->evaluate( (int) $row['order_id'] );
			} catch ( \Throwable $e ) {
				$this->plugin->logger()->error( 'qersh: transaction id evaluation failed', array( 'error' => get_class( $e ) ) );
			}
		}
	}

	/**
	 * The after-reply callback (shutdown, priority 5): finishes the response, then evaluates. Never throws.
	 */
	public static function on_shutdown(): void {
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
			$claims = Plugin::instance()->claims();
			foreach ( $ids as $id ) {
				$claims->evaluate( (int) $id );
			}
		} catch ( \Throwable $e ) {
			unset( $e ); // The sweep evaluates what is left.
		}
	}

	/**
	 * Whether the order still takes an id (step 4): pending with its row open or partial, or cancelled by our
	 * expiry less than 72 hours ago. Pure apart from the order's getters.
	 *
	 * @param \WC_Order $order Order.
	 * @param array     $row   Row.
	 * @param int       $now   UTC seconds.
	 * @return bool
	 */
	public static function accepts( \WC_Order $order, array $row, int $now ): bool {
		$status = (string) $order->get_status();
		if ( 'pending' === $status && in_array( $row['state'], array( 'open', 'partial' ), true ) ) {
			return true;
		}
		$expired_at = (int) $order->get_meta( '_qersh_expired_at', true );
		return 'cancelled' === $status && 'expired' === $row['state'] && $expired_at > 0 && $now - $expired_at < self::REVIVE_SEC;
	}

	/**
	 * The customer-facing message of a refusal code (docs/17 §7).
	 *
	 * @param string    $code  Code.
	 * @param \WC_Order $order Order.
	 * @return string
	 */
	public static function message( string $code, \WC_Order $order ): string {
		switch ( $code ) {
			case 'qersh_txn_empty':
				return __( 'Enter the transaction id.', 'qersh-for-woocommerce' );
			case 'qersh_txn_several':
				return __( 'We found more than one possible number in what you pasted. Enter the transaction id only.', 'qersh-for-woocommerce' );
			case 'qersh_txn_wallet_number':
				return __( 'This is a wallet number, not a transaction id.', 'qersh-for-woocommerce' );
			case 'qersh_txn_wait':
				return __( 'You have changed it several times. Try again in 10 minutes.', 'qersh-for-woocommerce' );
			case 'qersh_txn_locked':
				/* translators: %s: order number. */
				return sprintf( __( 'The transaction id cannot be changed again. Contact the store with your order number #%s.', 'qersh-for-woocommerce' ), (string) $order->get_order_number() );
			case 'qersh_txn_closed':
				return __( 'This order is no longer waiting for a transfer.', 'qersh-for-woocommerce' );
			case 'qersh_txn_busy':
				return __( 'We could not save the transaction id. Try again.', 'qersh-for-woocommerce' );
			default:
				return __( 'This does not look like an Orange Cash transaction id. Check it in your message.', 'qersh-for-woocommerce' );
		}
	}

	/**
	 * One try of the evaluation: false to try again (rolled back already).
	 *
	 * @param int $order_id Order id.
	 * @return bool
	 * @throws LockTimeout On a lock wait or a deadlock.
	 */
	private function evaluate_once( int $order_id ): bool {
		$db        = $this->plugin->db();
		$index     = $this->plugin->orders_index();
		$transfers = $this->plugin->transfers();
		$orders    = $this->plugin->orders();
		$opts      = MatchPolicy::options( $this->plugin->config() );
		$now       = time();

		$row = $index->get( $order_id );
		if ( null === $row || null === $row['claim_txn'] || '' === (string) $row['claim_txn'] ) {
			return true;
		}
		$providers = $orders->wallet_providers();
		if ( ! isset( $providers[ $row['wallet'] ] ) || self::PROVIDER !== $providers[ $row['wallet'] ] ) {
			return true;
		}
		$txn   = (string) $row['claim_txn'];
		$hint  = $orders->claimants( $txn );
		$probe = array(
			'transfer_id' => '',
			'provider'    => self::PROVIDER,
			'payer_key'   => null,
			'txn'         => $txn,
		);
		$names = MatchPolicy::lock_names( $probe, $hint, true );
		foreach ( Orders::payer_lock( (string) $row['payer_key'] ) as $own ) {
			if ( ! in_array( $own, $names, true ) && count( $names ) <= MatchPolicy::MAX_CLAIM_LOCKS ) {
				$names[] = $own;
			}
		}

		$db->begin();
		$db->lock( $names );
		$claimants = $orders->claimants( $txn );
		if ( MatchPolicy::claimants_print( $claimants ) !== MatchPolicy::claimants_print( $hint ) ) {
			$db->rollback();
			return false;
		}
		$mine = $index->get( $order_id );
		if ( null === $mine || $txn !== (string) $mine['claim_txn'] ) {
			$db->rollback();
			return true; // The customer changed the id meanwhile; its own evaluation follows.
		}
		$rows = $transfers->find_by_txn( self::PROVIDER, $txn );
		if ( empty( $rows ) ) {
			$db->commit();
			return true; // The transfer has not arrived: rule 4a runs when it does.
		}
		$notes    = array();
		$eligible = array();
		foreach ( $rows as $tr ) {
			$linked = isset( $tr['order_id'] ) ? (int) $tr['order_id'] : 0;
			if ( $linked === $order_id ) {
				$db->commit();
				return true; // The delivery got there first.
			}
			if ( $linked > 0 ) {
				$notes['paid'] = $linked;
			} elseif ( self::eligible( $tr ) ) {
				$eligible[] = $tr;
			} else {
				$notes['waiting'] = true;
			}
		}
		$effects = null;
		if ( 1 === count( $rows ) && 1 === count( $eligible ) ) {
			$tr = $eligible[0];
			if ( ! empty( Risk::unknown_reasons( $tr['risk_reasons'] ) ) ) {
				$transfers->update(
					(string) $tr['transfer_id'],
					array(
						'claim_eval'    => 'unknown_risk',
						'claim_eval_at' => $now,
					)
				);
			} else {
				$t        = MatchPolicy::transfer_from_row( $tr );
				$ctx      = $orders->context( $t, $opts, $now, $claimants );
				$decision = MatchPolicy::claim_decision( $t, $ctx );
				if ( 'matched' === $decision['outcome'] ) {
					$effects = $orders->write_decision( $t, $decision, $now );
					if ( null === $effects ) {
						$db->rollback();
						return false; // A fence missed: someone decided first.
					}
					$transfers->request_sync( (string) $tr['transfer_id'], $now );
				} else {
					$transfers->update(
						(string) $tr['transfer_id'],
						array(
							'claim_eval'    => 'none' === $decision['outcome'] ? 'none' : (string) $decision['reason'],
							'claim_eval_at' => $now,
						)
					);
				}
			}
		}
		$db->commit();

		$order = wc_get_order( $order_id );
		if ( $order instanceof \WC_Order ) {
			if ( isset( $notes['paid'] ) ) {
				$order->add_order_note(
					sprintf(
						/* translators: 1: transaction id, 2: order number. */
						__( 'The transaction id the customer entered (%1$s) belongs to a transfer that paid order #%2$s before.', 'qersh-for-woocommerce' ),
						Orders::ltr( $txn ),
						Orders::ltr( $orders->reference( (int) $notes['paid'] ) )
					)
				);
			}
			if ( isset( $notes['waiting'] ) ) {
				$order->add_order_note( __( 'The transaction id the customer entered matches a transfer waiting for your decision.', 'qersh-for-woocommerce' ) );
			}
		}
		if ( null !== $effects ) {
			$orders->after_commit( $effects, $now );
		}
		return true;
	}

	/**
	 * The one kind of row the late path may decide alone (docs/17 §4.10). Pure.
	 *
	 * @param array $tr Typed transfer row.
	 * @return bool
	 */
	public static function eligible( array $tr ): bool {
		return 'review' === $tr['outcome'] && 'no_payer' === $tr['reason'] && null === $tr['order_id']
			&& ( ! isset( $tr['flag'] ) || '' === $tr['flag'] ) && 1 === (int) $tr['auto_confirm_ok'];
	}

	/**
	 * Step 7: after the response where the host can finish it, else one Action Scheduler action for the order.
	 *
	 * @param int $order_id Order id.
	 */
	private function schedule_evaluation( int $order_id ): void {
		if ( Plugin::finish_available() ) {
			self::$queued[ $order_id ] = true;
			if ( ! self::$hooked ) {
				self::$hooked = true;
				add_action( 'shutdown', array( __CLASS__, 'on_shutdown' ), self::SHUTDOWN_PRIORITY );
			}
			return;
		}
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			try {
				as_enqueue_async_action( self::ACTION, Orders::action_args( $order_id ), Orders::GROUP, true );
			} catch ( \Throwable $e ) {
				$this->plugin->logger()->error( 'qersh: transaction id evaluation could not be queued', array( 'order' => $order_id ) );
			}
		}
	}

	/**
	 * The receive numbers of the store's wallets (an id equal to one of them is a wallet number).
	 *
	 * @return string[]
	 */
	private function store_numbers(): array {
		$config = $this->plugin->config();
		$out    = array();
		foreach ( isset( $config['wallets'] ) && is_array( $config['wallets'] ) ? $config['wallets'] : array() as $wallet ) {
			if ( isset( $wallet['receive_address'] ) && is_string( $wallet['receive_address'] ) && '' !== $wallet['receive_address'] ) {
				$out[] = $wallet['receive_address'];
			}
		}
		return $out;
	}

	/**
	 * Sends what is buffered and ends the response, so the customer has the answer before the evaluation runs.
	 */
	private static function finish_response(): void {
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
	 * `200 {"status":"saved","txn":…,"changes_left":n}`.
	 *
	 * @param string $txn   Canonical id.
	 * @param int    $tries Tries used.
	 * @return array
	 */
	private static function saved( string $txn, int $tries ): array {
		return array(
			'http'        => 200,
			'body'        => array(
				'status'       => 'saved',
				'txn'          => $txn,
				'changes_left' => max( 0, Store\OrdersIndex::CLAIM_MAX - $tries ),
			),
			'retry_after' => null,
		);
	}

	/**
	 * A refusal with its code and the customer's message.
	 *
	 * @param int       $http  HTTP status.
	 * @param string    $code  Code.
	 * @param \WC_Order $order Order.
	 * @return array
	 */
	private static function refusal( int $http, string $code, \WC_Order $order ): array {
		return array(
			'http'        => $http,
			'body'        => array(
				'code'    => $code,
				'message' => self::message( $code, $order ),
			),
			'retry_after' => null,
		);
	}

	/**
	 * The one 404 body of the route.
	 *
	 * @return array
	 */
	private static function not_found(): array {
		return array(
			'http'        => 404,
			'body'        => OrderStatusRoute::not_found_body(),
			'retry_after' => null,
		);
	}
}
