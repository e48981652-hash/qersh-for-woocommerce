<?php
/**
 * The qersh_orders row of each order, the matching transaction of a delivery, and applying its result to the
 * WooCommerce order (docs/17 §4.3, §4.4 steps 8–10, §4.6).
 *
 * @package QershPay\WooCommerce
 */

namespace QershPay\WooCommerce;

use QershPay\WooCommerce\Admin\Reasons;
use QershPay\WooCommerce\Core\Matcher;
use QershPay\WooCommerce\Core\Money;
use QershPay\WooCommerce\Core\Payer;
use QershPay\WooCommerce\Core\TxnId;
use QershPay\WooCommerce\Store\Db;
use QershPay\WooCommerce\Store\LockTimeout;

defined( 'ABSPATH' ) || exit;

/**
 * The WooCommerce order is the source of truth, and qersh_orders its index:
 * - the row is written at every process_payment() (on_process_payment()), and the order hooks keep it in step
 *   (register_hooks()); they clean the index only, the decision always reads the order again under the lock;
 * - a transfer.received runs one transaction (handle_received()): the locks in their total order, the delivery
 *   fence as the first write, the decision of MatchPolicy, its writes, the stored reply, COMMIT; then the order
 *   notes, the completion request and the expiry action;
 * - a transfer.updated updates the risk fields and stops an order its transfer paid when the message is suspected
 *   to be forged (handle_updated());
 * - the merchant's attach and dismiss run under the transfer lock, then the payer locks (apply_attach(), dismiss()),
 *   including transfers that wait for a decision while they count toward an order (attach_kind(), dismiss_kind()).
 */
final class Orders {

	/**
	 * The plugin's payment method id.
	 */
	const METHOD = 'qersh';

	/**
	 * Action Scheduler group and the expiry hook.
	 */
	const GROUP = 'qersh';

	/**
	 * The expiry action, one per order (Expiry::run()).
	 */
	const EXPIRE_ACTION = 'qersh_wc_expire';

	/**
	 * Seconds a rejected lock waits before the phone tries again.
	 */
	const RETRY_AFTER = 30;

	/**
	 * Status updates ride on a reply of at most 16 KB; this many bytes are left to them.
	 */
	const SYNC_BYTES = 14000;

	/**
	 * The plugin.
	 *
	 * @var Plugin
	 */
	private $plugin;

	/**
	 * Builds the service.
	 *
	 * @param Plugin|null $plugin The plugin.
	 */
	public function __construct( $plugin = null ) {
		$this->plugin = $plugin instanceof Plugin ? $plugin : Plugin::instance();
	}

	/*
	 * ------------------------------------------------------------------------------------------------------------
	 * Order hooks (docs/17 §4.3): they keep the index in step and never decide a match.
	 * ------------------------------------------------------------------------------------------------------------
	 */

	/**
	 * Hooks the index cleanup. Called by Webhook\Module when WooCommerce is ready.
	 *
	 * @param Plugin $plugin The plugin.
	 */
	public static function register_hooks( Plugin $plugin ): void {
		unset( $plugin );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_status_changed' ), 10, 4 );
		add_action( 'woocommerce_trash_order', array( __CLASS__, 'on_trash' ), 10, 1 );
		add_action( 'wp_trash_post', array( __CLASS__, 'on_trash_post' ), 10, 1 );
		add_action( 'woocommerce_untrash_order', array( __CLASS__, 'on_untrash' ), 10, 2 );
		add_action( 'untrashed_post', array( __CLASS__, 'on_untrash_post' ), 10, 2 );
		add_action( 'woocommerce_delete_order', array( __CLASS__, 'on_delete' ), 10, 1 );
		add_action( 'woocommerce_before_order_object_save', array( __CLASS__, 'on_before_save' ), 10, 1 );
		add_action( 'woocommerce_order_after_calculate_totals', array( __CLASS__, 'on_totals' ), 10, 2 );
	}

	/**
	 * Leaving `pending` is neither our payment nor our expiry: the row is closed (a cancellation, a refund, or the
	 * merchant moving the order by hand). Back to `pending` with our method: the row opens again. A cancellation of an
	 * order with money already received adds a note and puts its transfers in "Needs decision".
	 *
	 * @param int            $order_id Order id.
	 * @param string         $from     Old status.
	 * @param string         $to       New status.
	 * @param \WC_Order|null $order    Order.
	 */
	public static function on_status_changed( $order_id, $from = '', $to = '', $order = null ): void {
		$self = self::ready();
		if ( null === $self ) {
			return;
		}
		try {
			$order = $order instanceof \WC_Order ? $order : wc_get_order( $order_id );
			if ( ! $order instanceof \WC_Order || self::METHOD !== $order->get_payment_method() ) {
				return;
			}
			$id = (int) $order->get_id();
			if ( class_exists( Completion::class ) && Completion::is_completing( $id ) ) {
				return;
			}
			$row = $self->plugin->orders_index()->get( $id );
			if ( null === $row ) {
				return;
			}
			if ( 'pending' === $to ) {
				$self->reopen( $order, $row );
				return;
			}
			if ( 'pending' !== $from || 'paid' === $row['state'] ) {
				return; // A paid decision belongs to Completion, which reads the order again under the lock.
			}
			if ( 'expired' === $row['state'] && 'cancelled' === $to ) {
				return; // Our expiry.
			}
			$self->close( $order, $row, in_array( $to, array( 'cancelled', 'refunded' ), true ), (string) $to );
		} catch ( \Throwable $e ) {
			$self->log( 'status hook', $e );
		}
	}

	/**
	 * On woocommerce_trash_order: HPOS fires no status change when an order goes to the trash.
	 *
	 * @param int $order_id Order id.
	 */
	public static function on_trash( $order_id ): void {
		$self = self::ready();
		if ( null === $self ) {
			return;
		}
		try {
			$row = $self->plugin->orders_index()->get( (int) $order_id );
			if ( null === $row || self::completion_pending( $row ) || 'closed' === $row['state'] ) {
				return;
			}
			$self->plugin->orders_index()->update( (int) $order_id, array( 'state' => 'closed' ) );
		} catch ( \Throwable $e ) {
			$self->log( 'trash hook', $e );
		}
	}

	/**
	 * On wp_trash_post: the same for the posts storage.
	 *
	 * @param int $post_id Post id.
	 */
	public static function on_trash_post( $post_id ): void {
		if ( function_exists( 'get_post_type' ) && in_array( get_post_type( (int) $post_id ), array( 'shop_order', 'shop_order_placehold' ), true ) ) {
			self::on_trash( (int) $post_id );
		}
	}

	/**
	 * On woocommerce_untrash_order: read the order again; pending with our method opens the row again.
	 *
	 * @param int    $order_id Order id.
	 * @param string $previous Previous status.
	 */
	public static function on_untrash( $order_id, $previous = '' ): void {
		unset( $previous );
		$self = self::ready();
		if ( null === $self ) {
			return;
		}
		try {
			$order = wc_get_order( (int) $order_id );
			if ( ! $order instanceof \WC_Order || self::METHOD !== $order->get_payment_method() || 'pending' !== $order->get_status() ) {
				return;
			}
			$row = $self->plugin->orders_index()->get( (int) $order_id );
			if ( null !== $row ) {
				$self->reopen( $order, $row );
			}
		} catch ( \Throwable $e ) {
			$self->log( 'untrash hook', $e );
		}
	}

	/**
	 * On untrashed_post: the same for the posts storage.
	 *
	 * @param int    $post_id  Post id.
	 * @param string $previous Previous status.
	 */
	public static function on_untrash_post( $post_id, $previous = '' ): void {
		if ( function_exists( 'get_post_type' ) && in_array( get_post_type( (int) $post_id ), array( 'shop_order', 'shop_order_placehold' ), true ) ) {
			self::on_untrash( (int) $post_id, $previous );
		}
	}

	/**
	 * On woocommerce_delete_order: the row goes, and the order's transfers lose the payer number and name.
	 *
	 * @param int $order_id Order id.
	 */
	public static function on_delete( $order_id ): void {
		$self = self::ready();
		if ( null === $self ) {
			return;
		}
		try {
			$self->plugin->orders_index()->delete( (int) $order_id );
			$self->plugin->transfers()->forget_order( (int) $order_id );
		} catch ( \Throwable $e ) {
			$self->log( 'delete hook', $e );
		}
	}

	/**
	 * On woocommerce_before_order_object_save: the payment method leaves `qersh` ("Pay for order" saves the new
	 * method before its gateway runs): the row is closed.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function on_before_save( $order ): void {
		if ( ! $order instanceof \WC_Order || ! $order->get_id() ) {
			return;
		}
		$changes = $order->get_changes();
		if ( ! array_key_exists( 'payment_method', $changes ) || self::METHOD === $changes['payment_method'] ) {
			return;
		}
		$data = $order->get_data();
		if ( ! isset( $data['payment_method'] ) || self::METHOD !== $data['payment_method'] ) {
			return;
		}
		$self = self::ready();
		if ( null === $self ) {
			return;
		}
		try {
			$row = $self->plugin->orders_index()->get( (int) $order->get_id() );
			if ( null === $row || 'closed' === $row['state'] || self::completion_pending( $row ) ) {
				return;
			}
			$self->close( $order, $row, true );
		} catch ( \Throwable $e ) {
			$self->log( 'method hook', $e );
		}
	}

	/**
	 * On woocommerce_order_after_calculate_totals: the price follows the order while it waits.
	 *
	 * @param bool      $and_taxes Whether taxes were calculated.
	 * @param \WC_Order $order     Order.
	 */
	public static function on_totals( $and_taxes, $order = null ): void {
		unset( $and_taxes );
		if ( ! $order instanceof \WC_Order || ! $order->get_id() || self::METHOD !== $order->get_payment_method() ) {
			return;
		}
		$self = self::ready();
		if ( null === $self ) {
			return;
		}
		try {
			$row = $self->plugin->orders_index()->get( (int) $order->get_id() );
			if ( null === $row || ! in_array( $row['state'], array( 'open', 'partial' ), true ) ) {
				return;
			}
			$price = self::total_minor( $order );
			if ( $price !== (int) $row['price_minor'] ) {
				$self->plugin->orders_index()->update( (int) $order->get_id(), array( 'price_minor' => $price ) );
			}
		} catch ( \Throwable $e ) {
			$self->log( 'totals hook', $e );
		}
	}

	/*
	 * ------------------------------------------------------------------------------------------------------------
	 * process_payment() (docs/17 §4.3, §4.7).
	 * ------------------------------------------------------------------------------------------------------------
	 */

	/**
	 * Starts or restarts the wait for a transfer: the qersh_orders row (created_at and the claim columns kept),
	 * the meta, the deadline (kept while it has not passed, else now + order_ttl_min), the expiry action at the
	 * deadline plus the late window, then the instructions email. The stock hold of "Pay for order" (docs/17 §4.7)
	 * is the gateway's (Gateway::process_payment(), package C): it runs before this call and, when the stock ran out,
	 * answers the customer with the old deadline intact.
	 *
	 * @param \WC_Order $order     Order.
	 * @param string    $payer_key Normalised payer key the customer entered.
	 * @param string    $wallet    Wallet id the customer chose.
	 * @param int       $now       UTC seconds.
	 * @throws \RuntimeException When the row could not be written (the gateway shows an error).
	 * @throws \Throwable        Any other error of the transaction, after its rollback.
	 */
	public function on_process_payment( \WC_Order $order, string $payer_key, string $wallet, int $now ): void {
		$id    = (int) $order->get_id();
		$opts  = MatchPolicy::options( $this->plugin->config() );
		$index = $this->plugin->orders_index();
		$db    = $this->plugin->db();
		$old   = $index->get( $id );

		$deadline = null !== $old ? (int) $old['expires_at'] : (int) $order->get_meta( '_qersh_deadline', true );
		if ( $deadline <= $now ) {
			$deadline = $now + $opts['order_ttl_min'] * 60;
		}

		$written = false;
		for ( $attempt = 0; $attempt < 2 && ! $written; $attempt++ ) {
			$names = self::payer_lock( $payer_key );
			if ( null !== $old ) {
				$names = array_merge( $names, self::payer_lock( (string) $old['payer_key'] ) );
			}
			try {
				$db->begin();
				$db->lock( $names );
				$row = $index->get( $id );
				if ( null !== $row && '' !== (string) $row['payer_key'] && ! in_array( 'payer:' . $row['payer_key'], $names, true ) ) {
					$db->rollback();
					$old = $row; // Another request changed the payer meanwhile: take its lock too.
					continue;
				}
				$index->upsert(
					array(
						'order_id'    => $id,
						'wallet'      => $wallet,
						'payer_key'   => $payer_key,
						'price_minor' => self::total_minor( $order ),
						'state'       => self::state_on_payment( $row ),
						'created_at'  => $now,
						'expires_at'  => $deadline,
					)
				);
				$db->commit();
				$written = true;
			} catch ( LockTimeout $e ) {
				$db->rollback();
			} catch ( \Throwable $e ) {
				$db->rollback();
				throw $e;
			}
		}
		if ( ! $written ) {
			throw new \RuntimeException( 'qersh: the order row could not be written' );
		}

		// Order meta holds strings; WooCommerce stores these times as their decimal text either way.
		$order->update_meta_data( '_qersh_payer_key', $payer_key );
		$order->update_meta_data( '_qersh_wallet', $wallet );
		if ( '' === (string) $order->get_meta( '_qersh_placed_at', true ) ) {
			$order->update_meta_data( '_qersh_placed_at', (string) $now );
		}
		$order->update_meta_data( '_qersh_deadline', (string) $deadline );
		$order->save();

		$this->schedule_expiry( $id, $deadline + $opts['late_window_min'] * 60 );

		/**
		 * Asks for the customer's payment instructions email of an order that now waits for a transfer (the
		 * `qersh_instructions` email listens, docs/17 §4.7).
		 *
		 * @since 0.1.0
		 *
		 * @param int $id Order id.
		 */
		do_action( 'qersh_wc_send_instructions', $id );
	}

	/**
	 * The row's state at a process_payment(): a new row is open, a row closed (the method changed, the order was
	 * cancelled and reopened) or expired opens again (partial when money already arrived); any other state stays.
	 * Pure.
	 *
	 * @param array|null $row Current row.
	 * @return string
	 */
	public static function state_on_payment( ?array $row ): string {
		if ( null === $row ) {
			return 'open';
		}
		if ( in_array( $row['state'], array( 'closed', 'expired' ), true ) ) {
			return (int) $row['paid_minor'] > 0 ? 'partial' : 'open';
		}
		return (string) $row['state'];
	}

	/**
	 * Replaces the expiry action of an order (Action Scheduler keys uniqueness on the arguments, so a second
	 * schedule of the same order would be dropped silently).
	 *
	 * @param int $order_id Order id.
	 * @param int $at       UTC seconds.
	 */
	public function schedule_expiry( int $order_id, int $at ): void {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}
		try {
			self::unschedule_expiry( $order_id );
			as_schedule_single_action( $at, self::EXPIRE_ACTION, self::action_args( $order_id ), self::GROUP, true );
		} catch ( \Throwable $e ) {
			$this->log( 'expiry schedule', $e ); // The expiry sweep cancels it anyway.
		}
	}

	/**
	 * Removes the pending expiry action of an order.
	 *
	 * @param int $order_id Order id.
	 */
	public static function unschedule_expiry( int $order_id ): void {
		if ( function_exists( 'as_unschedule_action' ) ) {
			as_unschedule_action( self::EXPIRE_ACTION, self::action_args( $order_id ), self::GROUP );
		}
	}

	/**
	 * The arguments of our per-order actions (the shape Completion uses too).
	 *
	 * @param int $order_id Order id.
	 * @return array
	 */
	public static function action_args( int $order_id ): array {
		return array( 'order_id' => $order_id );
	}

	/*
	 * ------------------------------------------------------------------------------------------------------------
	 * Deliveries (docs/17 §4.4 steps 8–10).
	 * ------------------------------------------------------------------------------------------------------------
	 */

	/**
	 * A transfer.received after the claim: the locked transaction, the decision, the stored reply.
	 *
	 * @param array  $envelope    The parsed envelope.
	 * @param array  $wallet_cfg  The wallet's configuration entry.
	 * @param string $delivery_id Delivery id.
	 * @param string $token       Our claim token.
	 * @param int    $now         UTC seconds.
	 * @return array `[ 'http' => int, 'reply' => array, 'retry_after' => int|null ]`
	 * @throws \Throwable Any error of the transaction other than a busy lock, after its rollback (the controller
	 *                    releases the claim and answers 500).
	 */
	public function handle_received( array $envelope, array $wallet_cfg, string $delivery_id, string $token, int $now ): array {
		$data     = isset( $envelope['data'] ) && is_array( $envelope['data'] ) ? $envelope['data'] : array();
		$wallet   = isset( $wallet_cfg['id'] ) ? (string) $wallet_cfg['id'] : '';
		$provider = isset( $wallet_cfg['provider'] ) ? (string) $wallet_cfg['provider'] : '';
		$t        = MatchPolicy::read_transfer( $data, $wallet, $provider, $now );
		$opts     = MatchPolicy::options( $this->plugin->config() );
		$db       = $this->plugin->db();
		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			$effects = null;
			try {
				$out = null === $t
					? $this->received_unreadable( $delivery_id, $token, $now )
					: $this->received_once( $t, $opts, $delivery_id, $token, $now, $effects );
			} catch ( LockTimeout $e ) {
				$db->rollback();
				continue;
			} catch ( \Throwable $e ) {
				$db->rollback();
				throw $e;
			}
			if ( null === $out ) {
				continue; // The hint changed under the locks, or a fence missed: once more.
			}
			if ( null !== $effects ) {
				$this->after_commit( $effects, $now );
			}
			return $out;
		}
		return self::busy();
	}

	/**
	 * A transfer.updated after the claim (docs/17 §4.4 step 9): the risk fields, and the forgery stop. Always `ok`.
	 *
	 * @param array  $envelope    The parsed envelope.
	 * @param array  $wallet_cfg  The wallet's configuration entry.
	 * @param string $delivery_id Delivery id.
	 * @param string $token       Our claim token.
	 * @param int    $now         UTC seconds.
	 * @return array `[ 'http' => int, 'reply' => array, 'retry_after' => int|null ]`
	 * @throws \Throwable Any error of the transaction other than a busy lock, after its rollback (the controller
	 *                    releases the claim and answers 500).
	 */
	public function handle_updated( array $envelope, array $wallet_cfg, string $delivery_id, string $token, int $now ): array {
		unset( $wallet_cfg );
		$data  = isset( $envelope['data'] ) && is_array( $envelope['data'] ) ? $envelope['data'] : array();
		$tid   = isset( $data['transfer_id'] ) && is_string( $data['transfer_id'] ) && '' !== $data['transfer_id'] && strlen( $data['transfer_id'] ) <= 40 ? $data['transfer_id'] : '';
		$judge = Matcher::judge_update( $data );
		$db    = $this->plugin->db();
		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			$alert = null;
			try {
				$out = $this->updated_once( $tid, $data, $judge, $delivery_id, $token, $now, $alert );
			} catch ( LockTimeout $e ) {
				$db->rollback();
				continue;
			} catch ( \Throwable $e ) {
				$db->rollback();
				throw $e;
			}
			if ( null === $out ) {
				continue;
			}
			if ( null !== $alert ) {
				$this->alert( 'forgery', $alert );
			}
			return $out;
		}
		return self::busy();
	}

	/**
	 * The status updates for a wallet's next 2xx reply (docs/17 §4.4 step 10): at most 50, and at most about 14 KB.
	 * The controller sends them and counts them with Transfers::note_synced().
	 *
	 * @param string $wallet Wallet id.
	 * @param int    $now    UTC seconds.
	 * @return array[] Each `{ transfer_id, status, reference?, note? }`.
	 */
	public function status_updates( string $wallet, int $now ): array {
		$items = array();
		$bytes = 0;
		foreach ( $this->plugin->transfers()->pending_sync( $wallet, $now, 50 ) as $row ) {
			$item = $this->status_update_for( $row, $now );
			if ( null === $item ) {
				continue;
			}
			$size = strlen( Db::json( $item ) ) + 1;
			if ( $bytes + $size > self::SYNC_BYTES ) {
				break;
			}
			$bytes  += $size;
			$items[] = $item;
		}
		return $items;
	}

	/**
	 * The status update of one transfer as it stands now.
	 *
	 * @param array $row Typed qersh_transfers row.
	 * @param int   $now UTC seconds (unused, for the controller's signature).
	 * @return array|null `{ transfer_id, status, reference?, note? }`, or null for a transfer without a verdict.
	 */
	public function status_update_for( array $row, int $now = 0 ): ?array {
		unset( $now );
		if ( ! isset( $row['transfer_id'] ) || null === $row['outcome'] ) {
			return null;
		}
		$reply = $this->reply_for_row( $row );
		return array_merge( array( 'transfer_id' => (string) $row['transfer_id'] ), $reply );
	}

	/**
	 * The reply of a transfer that already has a verdict: its own verdict, never matched again (step 8).
	 *
	 * @param array $row Typed qersh_transfers row.
	 * @return array `{ status, reference?, note? }`
	 */
	public function reply_for_row( array $row ): array {
		$outcome  = (string) $row['outcome'];
		$order_id = isset( $row['order_id'] ) ? (int) $row['order_id'] : 0;
		$reason   = isset( $row['reason'] ) ? (string) $row['reason'] : '';
		$status   = $outcome;
		$note     = null;
		if ( 'dismissed' === $outcome ) {
			return array( 'status' => 'ignored' );
		}
		if ( 'attached' === $outcome ) {
			$status = $this->attached_status( $order_id );
			$note   = MatchPolicy::reply_note( 'attached' );
		} elseif ( 'matched' === $outcome && isset( $row['claim_eval'] ) && 'matched' === $row['claim_eval'] ) {
			$note = MatchPolicy::reply_note( 'by_claim' );
		} elseif ( 'unmatched' === $outcome && 'personal' === $reason ) {
			$note = MatchPolicy::reply_note( 'personal' );
		} elseif ( 'review' === $outcome ) {
			$stored = isset( $row['note'] ) ? trim( (string) $row['note'] ) : '';
			if ( self::reason_of_order( $reason, $order_id ) || '' === $stored ) {
				$note = MatchPolicy::reply_note( '' === $reason ? 'not_confirmed' : $reason, array( 'N' => $order_id > 0 ? $order_id : null ) );
			} else {
				$note = MatchPolicy::cut( $stored, 200 );
			}
		}
		$out = array( 'status' => in_array( $status, array( 'matched', 'partial', 'overpaid', 'unmatched', 'review' ), true ) ? $status : 'review' );
		if ( $order_id > 0 && in_array( $out['status'], array( 'matched', 'partial', 'overpaid' ), true ) ) {
			$reference = $this->reference( $order_id );
			if ( '' !== $reference ) {
				$out['reference'] = $reference;
			}
		}
		if ( null !== $note && '' !== $note ) {
			$out['note'] = $note;
		}
		return $out;
	}

	/*
	 * ------------------------------------------------------------------------------------------------------------
	 * Admin (docs/17 §4.6): attach, dismiss, suggestions.
	 * ------------------------------------------------------------------------------------------------------------
	 */

	/**
	 * The merchant attaches a transfer to an order (docs/17 §4.6, §4.7), under the transfer lock then the payer locks
	 * (the target's, and for a move the old order's), by attach_kind():
	 * - `new` and `move`: the link, the amount added to the target's paid_minor (and for a move taken from the old
	 *   order's), a note with the admin's name and the masked number;
	 * - `own`: a transfer of this order that waited for the merchant (left by the completion or a cancellation, or
	 *   part of the amount at the deadline) is settled with the other transfers that waited with it; its amount is
	 *   counted already, and part of the amount at the deadline completes the order with what arrived;
	 * - `confirm`: the overpaid transfer of a paid order is confirmed, nothing else changes;
	 * then the result as for an automatic match: partial without completion (the customer's note, and the expiry
	 * action of a row written here), else paid and the completion, which checks the stock again for an order our
	 * expiry cancelled. A cancelled order is never brought back with part of its amount. The phone hears it in the
	 * next replies. Check the warnings (attach_warnings()) with the merchant first.
	 *
	 * @param string $transfer_id Transfer id.
	 * @param int    $order_id    Order id.
	 * @param int    $user_id     The admin.
	 * @return true|\WP_Error
	 */
	public function apply_attach( string $transfer_id, int $order_id, int $user_id ) {
		$transfers = $this->plugin->transfers();
		$index     = $this->plugin->orders_index();
		$db        = $this->plugin->db();
		$tr        = $transfers->get( $transfer_id );
		$mode      = $this->attachable( $tr, $order_id );
		if ( is_wp_error( $mode ) ) {
			return $mode;
		}
		$order = wc_get_order( $order_id );
		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			$now     = time();
			$row     = $index->get( $order_id );
			$payer   = $this->target_payer( $order, $row, $tr );
			$old_id  = 'move' === $mode ? self::linked_order( $tr ) : 0;
			$old_row = $old_id > 0 ? $index->get( $old_id ) : null;
			$names   = array_merge( array( MatchPolicy::transfer_lock( $tr ) ), self::payer_lock( $payer ) );
			if ( null !== $old_row ) {
				$names = array_merge( $names, self::payer_lock( (string) $old_row['payer_key'] ) );
			}
			try {
				$db->begin();
				$db->lock( $names );
				$tr       = $transfers->get( $transfer_id );
				$now_mode = $this->attachable( $tr, $order_id );
				if ( is_wp_error( $now_mode ) ) {
					$db->rollback();
					return $now_mode;
				}
				$now_row = $index->get( $order_id );
				$now_old = 'move' === $now_mode ? $index->get( self::linked_order( $tr ) ) : null;
				if ( $now_mode !== $mode || ( null !== $now_row && (string) $now_row['payer_key'] !== $payer )
					|| ( 'move' === $mode && ( self::linked_order( $tr ) !== $old_id || ! self::same_payer( $old_row, $now_old ) ) ) ) {
					$db->rollback();
					$mode = $now_mode; // Someone changed the transfer or a payer meanwhile: take the right locks.
					continue;
				}
				$order = wc_get_order( $order_id ); // As attachable() read it under the locks.
				if ( ! $order instanceof \WC_Order ) {
					$db->rollback();
					return new \WP_Error( 'qersh_order_unknown', __( 'This order was not found.', 'qersh-for-woocommerce' ) );
				}
				$effects = $this->attach_locked( $mode, $tr, $order, $now_row, $payer, $user_id, $now );
				if ( is_wp_error( $effects ) ) {
					$db->rollback();
					return $effects;
				}
				$db->commit();
			} catch ( LockTimeout $e ) {
				$db->rollback();
				continue;
			} catch ( \Throwable $e ) {
				$db->rollback();
				$this->log( 'attach', $e );
				return new \WP_Error( 'qersh_attach_failed', __( 'The transfer could not be attached. Try again.', 'qersh-for-woocommerce' ) );
			}
			$this->after_attach( $order_id, $effects, $now );
			return true;
		}
		return new \WP_Error( 'qersh_attach_busy', __( 'The transfer is being processed right now. Try again in a moment.', 'qersh-for-woocommerce' ) );
	}

	/**
	 * The merchant dismisses a transfer that is not the store's (a gift, a personal transfer), or a transfer that
	 * waited for a decision while it counted toward an order (the merchant refunded it): the phone hears `ignored`,
	 * without an order number, and its amount no longer counts toward that order. A transfer that paid its order is
	 * refused with what to do instead (dismiss_kind()).
	 *
	 * @param string $transfer_id Transfer id.
	 * @param int    $user_id     The admin.
	 * @return true|\WP_Error
	 */
	public function dismiss( string $transfer_id, int $user_id ) {
		$transfers = $this->plugin->transfers();
		$index     = $this->plugin->orders_index();
		$db        = $this->plugin->db();
		$tr        = $transfers->get( $transfer_id );
		if ( null === $tr ) {
			return new \WP_Error( 'qersh_transfer_unknown', __( 'This transfer was not found.', 'qersh-for-woocommerce' ) );
		}
		if ( 'dismissed' === $tr['outcome'] ) {
			return true;
		}
		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			$linked = self::linked_order( $tr );
			$row    = $linked > 0 ? $index->get( $linked ) : null;
			$names  = array( MatchPolicy::transfer_lock( $tr ) );
			if ( null !== $row ) {
				$names = array_merge( $names, self::payer_lock( (string) $row['payer_key'] ) );
			}
			try {
				$db->begin();
				$db->lock( $names );
				$now_tr = $transfers->get( $transfer_id );
				if ( null === $now_tr ) {
					$db->rollback();
					return new \WP_Error( 'qersh_transfer_unknown', __( 'This transfer was not found.', 'qersh-for-woocommerce' ) );
				}
				if ( 'dismissed' === $now_tr['outcome'] ) {
					$db->commit();
					return true;
				}
				$now_row = $linked > 0 ? $index->get( $linked ) : null;
				if ( self::linked_order( $now_tr ) !== $linked || ! self::same_payer( $row, $now_row ) ) {
					$db->rollback();
					$tr = $now_tr; // Linked to another order meanwhile: take its payer lock.
					continue;
				}
				$tr   = $now_tr;
				$kind = self::dismiss_kind( $tr );
				if ( 'release' === $kind && null !== $now_row && 'paid' === $now_row['state'] ) {
					$kind = 'linked'; // It counts toward an order that is paid now.
				}
				if ( 'free' !== $kind && 'release' !== $kind ) {
					$db->rollback();
					return self::dismiss_refusal( $kind, $linked );
				}
				$now  = time();
				$user = get_userdata( $user_id );
				$name = $user ? $user->display_name : '#' . $user_id;
				if ( 'release' === $kind ) {
					if ( null !== $now_row ) {
						$index->update( $linked, self::released_fields( $now_row, (int) $tr['amount_minor'] ) );
					}
					$order = wc_get_order( $linked );
					if ( $order instanceof \WC_Order ) {
						$order->add_order_note(
							sprintf(
								/* translators: 1: name of the admin, 2: amount. */
								__( '%1$s dismissed a wallet transfer of %2$s; it no longer counts toward this order.', 'qersh-for-woocommerce' ),
								esc_html( $name ),
								$this->money( (int) $tr['amount_minor'], $order )
							)
						);
					}
				}
				$transfers->update(
					$transfer_id,
					array(
						'outcome' => 'dismissed',
						/* translators: %s: name of the admin. */
						'note'    => sprintf( __( 'Dismissed by %s.', 'qersh-for-woocommerce' ), $name ),
					)
				);
				$transfers->request_sync( $transfer_id, $now );
				$db->commit();
				return true;
			} catch ( LockTimeout $e ) {
				$db->rollback();
			} catch ( \Throwable $e ) {
				$db->rollback();
				$this->log( 'dismiss', $e );
				return new \WP_Error( 'qersh_dismiss_failed', __( 'The transfer could not be dismissed. Try again.', 'qersh-for-woocommerce' ) );
			}
		}
		return new \WP_Error( 'qersh_attach_busy', __( 'The transfer is being processed right now. Try again in a moment.', 'qersh-for-woocommerce' ) );
	}

	/**
	 * "Mark as decided": a transfer already linked to an order that still needs a decision (overpaid, a forgery
	 * flag, short stock, an overdue partial payment or claim). The merchant decides on the order itself (refund the
	 * excess, check the money in the wallet app, complete or cancel it); this only takes the transfer out of
	 * "Needs a decision". It never changes the order's status or the money counted toward it, and sends nothing to
	 * the phone (the phone already has the transfer's outcome). Under the transfer lock, then the payer lock.
	 *
	 * @param string $transfer_id Transfer id.
	 * @param int    $user_id     The admin.
	 * @return true|\WP_Error
	 */
	public function resolve( string $transfer_id, int $user_id ) {
		$transfers = $this->plugin->transfers();
		$index     = $this->plugin->orders_index();
		$db        = $this->plugin->db();
		$tr        = $transfers->get( $transfer_id );
		if ( null === $tr ) {
			return new \WP_Error( 'qersh_transfer_unknown', __( 'This transfer was not found.', 'qersh-for-woocommerce' ) );
		}
		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			$linked = self::linked_order( $tr );
			if ( $linked <= 0 ) {
				return new \WP_Error( 'qersh_resolve_unlinked', __( 'This transfer is not linked to an order. Attach it to its order or dismiss it.', 'qersh-for-woocommerce' ) );
			}
			$row   = $index->get( $linked );
			$names = array( MatchPolicy::transfer_lock( $tr ) );
			if ( null !== $row ) {
				$names = array_merge( $names, self::payer_lock( (string) $row['payer_key'] ) );
			}
			try {
				$db->begin();
				$db->lock( $names );
				$now_tr = $transfers->get( $transfer_id );
				if ( null === $now_tr ) {
					$db->rollback();
					return new \WP_Error( 'qersh_transfer_unknown', __( 'This transfer was not found.', 'qersh-for-woocommerce' ) );
				}
				$now_row = $index->get( $linked );
				if ( self::linked_order( $now_tr ) !== $linked || ! self::same_payer( $row, $now_row ) ) {
					$db->rollback();
					$tr = $now_tr;
					continue;
				}
				$fields = self::resolved_fields( $now_tr );
				if ( empty( $fields ) ) {
					$db->commit();
					return true; // Nothing waits for a decision any more.
				}
				$user = get_userdata( $user_id );
				$name = $user ? $user->display_name : '#' . $user_id;
				/* translators: %s: name of the admin. */
				$fields['note'] = sprintf( __( 'Marked as decided by %s.', 'qersh-for-woocommerce' ), $name );
				$transfers->update( $transfer_id, $fields );
				$order = wc_get_order( $linked );
				if ( $order instanceof \WC_Order ) {
					$order->add_order_note(
						sprintf(
							/* translators: 1: name of the admin, 2: amount. */
							__( '%1$s marked the wallet transfer of %2$s as decided.', 'qersh-for-woocommerce' ),
							esc_html( $name ),
							$this->money( (int) $now_tr['amount_minor'], $order )
						)
					);
				}
				$db->commit();
				return true;
			} catch ( LockTimeout $e ) {
				$db->rollback();
			} catch ( \Throwable $e ) {
				$db->rollback();
				$this->log( 'resolve', $e );
				return new \WP_Error( 'qersh_resolve_failed', __( 'The transfer could not be marked as decided. Try again in a moment.', 'qersh-for-woocommerce' ) );
			}
		}
		return new \WP_Error( 'qersh_attach_busy', __( 'The transfer is being processed right now. Try again in a moment.', 'qersh-for-woocommerce' ) );
	}

	/**
	 * The column changes that take a linked transfer out of "Needs a decision" (Store\Transfers::NEEDS_DECISION_SQL)
	 * without touching the money: overpaid → matched (the excess is the merchant's to return), review → attached, a
	 * forgery flag → forgery_decided, an overdue reason → decided. Empty when nothing waits. Pure.
	 *
	 * @param array $tr Transfer row.
	 * @return array
	 */
	public static function resolved_fields( array $tr ): array {
		$fields  = array();
		$outcome = isset( $tr['outcome'] ) ? (string) $tr['outcome'] : '';
		if ( 'overpaid' === $outcome ) {
			$fields['outcome'] = 'matched';
		} elseif ( 'review' === $outcome ) {
			$fields['outcome'] = 'attached';
		}
		if ( isset( $tr['flag'] ) && 'forgery' === $tr['flag'] && 'dismissed' !== $outcome ) {
			$fields['flag'] = 'forgery_decided';
		}
		$reason = isset( $tr['reason'] ) ? (string) $tr['reason'] : '';
		if ( in_array( $reason, array( 'partial_overdue', 'claim_overdue' ), true ) && 'dismissed' !== $outcome ) {
			$fields['reason'] = 'decided';
		}
		return $fields;
	}

	/**
	 * The suggestions of a transfer, computed when read (docs/17 §4.6; MatchPolicy::advice() decides).
	 *
	 * @param array $transfer_row Typed qersh_transfers row.
	 * @return array[] Each MatchPolicy::advice() item plus `order_number` (string), `text` (plain text: the ready
	 *                 line «Its customer entered this transaction id · Attach to order #N», the owner's reason, or
	 *                 what remains of the order) and `warnings` (string[]: check the payer in the wallet app for a
	 *                 transfer not confirmed on its own; the order was created after the money), in the words of
	 *                 the admin screens (Admin\Reasons, Admin\Transfers).
	 */
	public function suggestions( array $transfer_row ): array {
		$now  = time();
		$opts = MatchPolicy::options( $this->plugin->config() );
		$t    = MatchPolicy::transfer_from_row( $transfer_row );
		$ctx  = $this->context( $t, $opts, $now, false, true );
		$out  = array();
		foreach ( MatchPolicy::advice( $t, $ctx ) as $item ) {
			$id                   = (int) $item['order_id'];
			$item['order_number'] = (string) $this->reference( $id );
			if ( $item['ready'] ) {
				/* translators: %s: order number. */
				$item['text'] = sprintf( __( 'Its customer entered this transaction id · Attach to order %s', 'qersh-for-woocommerce' ), MatchPolicy::order_tag( $id ) );
			} elseif ( null !== $item['reason'] ) {
				$item['text'] = MatchPolicy::reason_text( (string) $item['reason'], $item['args'] );
			} else {
				$row          = $this->plugin->orders_index()->get( $id );
				$left         = null === $row ? 0 : max( 0, (int) $row['price_minor'] - (int) $row['paid_minor'] );
				$item['text'] = sprintf(
					/* translators: 1: order number, 2: amount that remains. */
					__( 'Order %1$s: %2$s remains.', 'qersh-for-woocommerce' ),
					MatchPolicy::order_tag( $id ),
					MatchPolicy::plain_price( $opts['round_up_to_pound'] ? Money::round_up_pound( $left ) : $left )
				);
			}
			$item['warnings'] = array();
			if ( empty( $t['auto_confirm_ok'] ) ) {
				$item['warnings'][] = __( 'Check the payer\'s name and the amount in the wallet app before you attach it.', 'qersh-for-woocommerce' );
			}
			if ( $item['after'] ) {
				$item['warnings'][] = self::created_after_text( $id );
			}
			$out[] = $item;
		}
		return $out;
	}

	/**
	 * The warnings the merchant must confirm before attaching (docs/17 §4.6): a transaction id that already paid
	 * another order, and a transfer that arrived before the order was created.
	 *
	 * @param array $transfer_row Typed qersh_transfers row.
	 * @param int   $order_id     The target order.
	 * @return string[] Plain text.
	 */
	public function attach_warnings( array $transfer_row, int $order_id ): array {
		$out    = array();
		$stored = isset( $transfer_row['provider_txn_id'] ) ? (string) $transfer_row['provider_txn_id'] : '';
		if ( '' !== $stored ) {
			foreach ( $this->plugin->transfers()->find_by_txn( (string) $transfer_row['provider'], $stored ) as $other ) {
				if ( (string) $other['transfer_id'] === (string) $transfer_row['transfer_id'] || null === $other['order_id'] || ! in_array( $other['outcome'], MatchPolicy::PAYING_OUTCOMES, true ) ) {
					continue;
				}
				$out[] = sprintf(
					/* translators: %s: order number. */
					__( 'This transaction id already paid order %s. Attach it only if you are sure this is a different transfer.', 'qersh-for-woocommerce' ),
					MatchPolicy::order_tag( (int) $other['order_id'] )
				);
				break;
			}
		}
		$row     = $this->plugin->orders_index()->get( $order_id );
		$order   = wc_get_order( $order_id );
		$created = null !== $row ? (int) $row['created_at'] : ( $order instanceof \WC_Order && $order->get_date_created() ? (int) $order->get_date_created()->getTimestamp() : 0 );
		if ( $created > (int) $transfer_row['money_at'] ) {
			$out[] = self::created_after_text( $order_id );
		}
		return $out;
	}

	/**
	 * The one line of a transfer's reason in "Needs decision" and the other lists (plain text).
	 *
	 * @param array $transfer_row Typed qersh_transfers row.
	 * @return string
	 */
	public function reason_line( array $transfer_row ): string {
		if ( isset( $transfer_row['flag'] ) && 'forgery' === $transfer_row['flag'] ) {
			return MatchPolicy::reason_text( 'forgery' );
		}
		if ( 'review' === $transfer_row['outcome'] && isset( $transfer_row['balance_chain'] ) && 'merchant_confirmed' === $transfer_row['balance_chain'] ) {
			return MatchPolicy::reason_text( 'merchant_confirmed' );
		}
		$note     = isset( $transfer_row['note'] ) ? trim( (string) $transfer_row['note'] ) : '';
		$reason   = isset( $transfer_row['reason'] ) ? (string) $transfer_row['reason'] : '';
		$order_id = isset( $transfer_row['order_id'] ) ? (int) $transfer_row['order_id'] : 0;
		if ( '' !== $note && ! self::reason_of_order( $reason, $order_id ) ) {
			return $note;
		}
		if ( '' === $reason ) {
			return $note;
		}
		return MatchPolicy::reason_text(
			$reason,
			array(
				'N'      => isset( $transfer_row['order_id'] ) ? $transfer_row['order_id'] : null,
				'orange' => in_array( (string) $transfer_row['provider'], TxnId::CLAIM_PROVIDERS, true ),
			)
		);
	}

	/*
	 * ------------------------------------------------------------------------------------------------------------
	 * Shared with Claims and Expiry.
	 * ------------------------------------------------------------------------------------------------------------
	 */

	/**
	 * Rule 3 on one row: classifies it from the WooCommerce order and, when `$write`, corrects the row with what was
	 * found (the live price, a row still open whose order left pending). Call it under the row's payer lock.
	 *
	 * @param array $row   Typed qersh_orders row.
	 * @param bool  $write Whether to correct the row.
	 * @return array The row with `kind` (and the live `price_minor` for normal and expired rows).
	 */
	public function shape( array $row, bool $write = true ) {
		$id    = (int) $row['order_id'];
		$order = wc_get_order( $id );
		$facts = array( 'exists' => $order instanceof \WC_Order );
		if ( $order instanceof \WC_Order ) {
			$facts['method']     = (string) $order->get_payment_method();
			$facts['flagged']    = '' !== (string) $order->get_meta( '_qersh_flag', true );
			$facts['status']     = (string) $order->get_status();
			$facts['currency']   = (string) $order->get_currency();
			$facts['expired_at'] = (int) $order->get_meta( '_qersh_expired_at', true );
		}
		$row['kind'] = MatchPolicy::classify( $row, $facts );
		$index       = $this->plugin->orders_index();
		if ( 'normal' === $row['kind'] || 'expired' === $row['kind'] ) {
			$live = self::total_minor( $order );
			if ( $live !== (int) $row['price_minor'] ) {
				if ( $write ) {
					$index->update( $id, array( 'price_minor' => $live ) );
				}
				$row['price_minor'] = $live;
			}
		} elseif ( $write && $order instanceof \WC_Order && in_array( $row['state'], array( 'open', 'partial' ), true ) && empty( $facts['flagged'] )
			&& ( self::METHOD !== $facts['method'] || 'pending' !== $facts['status'] ) ) {
			$index->update( $id, array( 'state' => 'closed' ) ); // A hook that did not run: the order left pending.
			$row['state'] = 'closed';
		}
		return $row;
	}

	/**
	 * The claimants of a transaction id, each with `provider` (of its wallet; deleted wallets from their snapshot).
	 *
	 * @param string $txn Canonical id.
	 * @return array[]
	 */
	public function claimants( string $txn ): array {
		$providers = $this->wallet_providers();
		$out       = array();
		foreach ( $this->plugin->orders_index()->claimants( $txn ) as $row ) {
			$row['provider'] = isset( $providers[ $row['wallet'] ] ) ? $providers[ $row['wallet'] ] : '';
			$out[]           = $row;
		}
		return $out;
	}

	/**
	 * Wallet id => provider, from the configuration, and the snapshots of deleted wallets.
	 *
	 * @return array<string, string>
	 */
	public function wallet_providers(): array {
		$out = array();
		try {
			foreach ( $this->plugin->wallets()->all() as $id => $row ) {
				if ( isset( $row['snapshot']['provider'] ) && is_string( $row['snapshot']['provider'] ) ) {
					$out[ (string) $id ] = $row['snapshot']['provider'];
				}
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}
		$config = $this->plugin->config();
		foreach ( isset( $config['wallets'] ) && is_array( $config['wallets'] ) ? $config['wallets'] : array() as $wallet ) {
			if ( isset( $wallet['id'], $wallet['provider'] ) ) {
				$out[ (string) $wallet['id'] ] = (string) $wallet['provider'];
			}
		}
		return $out;
	}

	/**
	 * The context MatchPolicy reads, from the store (call it under the locks for a decision).
	 *
	 * @param array      $t         Normalised transfer.
	 * @param array      $opts      MatchPolicy::options().
	 * @param int        $now       UTC seconds.
	 * @param array|bool $claimants The claimants read under the locks, or false to read them here.
	 * @param bool       $advice    True for the suggestions (no writes; the payer's later orders too).
	 * @return array
	 */
	public function context( array $t, array $opts, int $now, $claimants = false, bool $advice = false ): array {
		$index = $this->plugin->orders_index();
		$at    = (int) $t['money_at'];
		$late  = (int) $opts['late_window_min'] * 60;
		$skew  = (int) $opts['skew_min'] * 60;
		$ctx   = array(
			'now'         => $now,
			'config'      => $opts,
			'duplicates'  => array(),
			'payer_rows'  => array(),
			'wallet_rows' => array(),
			'claimants'   => array(),
		);
		if ( ! $advice && null !== $t['txn_stored'] && '' !== $t['txn_stored'] ) {
			$ctx['duplicates'] = $this->plugin->transfers()->find_by_txn( (string) $t['provider'], (string) $t['txn_stored'] );
		}
		if ( null !== $t['payer_key'] && '' !== $t['payer_key'] ) {
			foreach ( $index->for_payer( (string) $t['payer_key'], $at - 2 * $late, ( $advice ? max( $now, $at ) : $at ) + $skew ) as $row ) {
				$ctx['payer_rows'][] = $this->shape( $row, ! $advice );
			}
			return $ctx;
		}
		$ctx['wallet_rows'] = $index->for_wallet( (string) $t['wallet'], $at - 2 * $late, $at + $skew );
		if ( null !== $t['txn'] && ( $advice || ! empty( $opts['txn_entry'] ) ) ) {
			$rows = false === $claimants ? $this->claimants( (string) $t['txn'] ) : $claimants;
			$live = 0;
			foreach ( $rows as $row ) {
				if ( in_array( $row['state'], MatchPolicy::LIVE_STATES, true ) ) {
					++$live;
				}
			}
			foreach ( $rows as $row ) {
				if ( 1 === $live && in_array( $row['state'], MatchPolicy::LIVE_STATES, true ) ) {
					$row = $this->shape( $row, ! $advice ); // Gate 2, under this payer's lock (taken from the hint).
				}
				$ctx['claimants'][] = $row;
			}
		}
		return $ctx;
	}

	/**
	 * Writes a decision inside the open transaction: the transfer row, and for a paid or partial decision the order
	 * row (rule 4a through its two fences). Returns what to do after COMMIT, or null when a fence missed (roll back).
	 *
	 * @param array $t   Normalised transfer.
	 * @param array $d   MatchPolicy decision.
	 * @param int   $now UTC seconds.
	 * @return array|null Effects for after_commit().
	 */
	public function write_decision( array $t, array $d, int $now ): ?array {
		$transfers = $this->plugin->transfers();
		$index     = $this->plugin->orders_index();
		$fields    = array(
			'reason' => $d['reason'],
			'note'   => self::note_of( $t, $d ),
		);
		if ( null !== $d['flag'] ) {
			$fields['flag'] = $d['flag'];
		}
		$effects = array(
			't'        => $t,
			'd'        => $d,
			'paid_now' => 0,
			'price'    => 0,
		);
		if ( null !== $d['order_id'] && in_array( $d['outcome'], array( 'matched', 'partial', 'overpaid' ), true ) ) {
			$id  = (int) $d['order_id'];
			$row = $index->get( $id );
			if ( null === $row ) {
				return null;
			}
			$total = (int) $row['paid_minor'] + (int) $d['paid_add'];
			$set   = array(
				'paid_minor'  => $total,
				'price_minor' => (int) $d['price'],
			);
			if ( 'paid' === $d['state'] ) {
				$set['paid_at']    = $now;
				$set['completion'] = 'due';
			}
			if ( $d['by_claim'] ) {
				if ( ! $transfers->fence_match( (string) $t['transfer_id'], $id ) ) {
					return null;
				}
				if ( ! $index->fence_claimant( $id, (string) $t['txn'], (string) $t['wallet'], (string) $row['payer_key'], $set ) ) {
					return null;
				}
				$fields['claim_eval']    = 'matched';
				$fields['claim_eval_at'] = $now;
			} else {
				$set['state'] = $d['state'];
				$index->update( $id, $set );
				$fields['outcome']  = $d['outcome'];
				$fields['order_id'] = $id;
			}
			$effects['paid_now'] = $total;
			$effects['price']    = (int) $d['price'];
		} else {
			$fields['outcome'] = $d['outcome'];
		}
		$transfers->update( (string) $t['transfer_id'], $fields );
		return $effects;
	}

	/**
	 * After COMMIT of a decision: the Orange wallet's `auto_seen`, the order notes, the expiry action removed and the
	 * completion requested for a paid decision. Never throws.
	 *
	 * @param array $effects From write_decision().
	 * @param int   $now     UTC seconds.
	 */
	public function after_commit( array $effects, int $now ): void {
		$t = $effects['t'];
		$d = $effects['d'];
		try {
			if ( ! empty( $t['auto_confirm_ok'] ) && in_array( $t['provider'], TxnId::CLAIM_PROVIDERS, true ) ) {
				$state = $this->plugin->wallets()->get( (string) $t['wallet'] );
				if ( null !== $state && 1 !== (int) $state['auto_seen'] ) {
					$this->plugin->wallets()->touch( (string) $t['wallet'], array( 'auto_seen' => 1 ) );
				}
			}
		} catch ( \Throwable $e ) {
			$this->log( 'auto_seen', $e );
		}
		$id = null !== $d['order_id'] ? (int) $d['order_id'] : 0;
		try {
			if ( $id > 0 && in_array( $d['outcome'], array( 'matched', 'overpaid', 'partial' ), true ) ) {
				$order = wc_get_order( $id );
				if ( $order instanceof \WC_Order ) {
					$this->paid_notes( $order, $t, $d, (int) $effects['paid_now'], (int) $effects['price'], $now );
				}
			} elseif ( 'review' === $d['outcome'] && ! empty( $d['pinned'] ) ) {
				$order = wc_get_order( (int) $d['pinned'][0] );
				if ( $order instanceof \WC_Order && self::METHOD === $order->get_payment_method() ) {
					if ( null === $t['amount_minor'] ) {
						$text = sprintf(
							/* translators: 1: the payer, 2: why the transfer needs a decision. */
							__( 'A wallet transfer from %1$s may be for this order and needs your decision: %2$s', 'qersh-for-woocommerce' ),
							$this->payer_text( $t ),
							esc_html( (string) self::note_of( $t, $d ) )
						);
					} else {
						$text = sprintf(
							/* translators: 1: amount, 2: the payer, 3: why the transfer needs a decision. */
							__( 'A wallet transfer of %1$s from %2$s may be for this order and needs your decision: %3$s', 'qersh-for-woocommerce' ),
							$this->money( (int) $t['amount_minor'], $order ),
							$this->payer_text( $t ),
							esc_html( (string) self::note_of( $t, $d ) )
						);
					}
					$order->add_order_note( $text );
				}
			}
		} catch ( \Throwable $e ) {
			$this->log( 'order notes', $e );
		}
		if ( $id > 0 && 'paid' === $d['state'] ) {
			try {
				self::unschedule_expiry( $id );
			} catch ( \Throwable $e ) {
				$this->log( 'expiry unschedule', $e );
			}
			$this->request_completion( $id );
		}
	}

	/**
	 * The reply of a decision: status, the order number for a paid or partial one, and the note.
	 *
	 * @param array $d MatchPolicy decision.
	 * @return array
	 */
	public function reply_of( array $d ): array {
		$reply = array( 'status' => (string) $d['reply']['status'] );
		if ( ! empty( $d['reply']['order'] ) ) {
			$reference = $this->reference( (int) $d['reply']['order'] );
			if ( '' !== $reference ) {
				$reply['reference'] = $reference;
			}
		}
		$args = isset( $d['reply']['args'] ) && is_array( $d['reply']['args'] ) ? $d['reply']['args'] : $d['args'];
		$note = MatchPolicy::reply_note( isset( $d['reply']['note'] ) ? $d['reply']['note'] : null, $args );
		if ( null !== $note && '' !== $note ) {
			$reply['note'] = $note;
		}
		return $reply;
	}

	/**
	 * The paid or partial notes of an order (docs/17 §4.6 "applying the result").
	 *
	 * @param \WC_Order $order  Order.
	 * @param array     $t      Normalised transfer.
	 * @param array     $d      Decision.
	 * @param int       $paid   paid_minor now.
	 * @param int       $price  Order total.
	 * @param int       $now    UTC seconds.
	 */
	public function paid_notes( \WC_Order $order, array $t, array $d, int $paid, int $price, int $now ): void {
		$amount = $this->money( (int) $t['amount_minor'], $order );
		$txn    = null !== $t['txn_stored'] && '' !== $t['txn_stored'] ? self::ltr( (string) $t['txn_stored'] ) : '—';
		$time   = self::ltr( self::time_text( (int) $t['money_at'], $now ) );
		if ( 'partial' === $d['outcome'] ) {
			$this->customer_partial_note( $order, (string) $t['provider'], $paid, $price, $now );
			return;
		}
		if ( $d['by_claim'] ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: wallet type, 2: amount, 3: the payer, 4: transaction id, 5: when the money arrived. */
					__( 'A %1$s transfer of %2$s from %3$s matched this order by the transaction id the customer entered (%4$s, money arrived %5$s).', 'qersh-for-woocommerce' ),
					esc_html( self::provider_name( (string) $t['provider'] ) ),
					$amount,
					$this->payer_text( $t ),
					$txn,
					$time
				)
			);
		} else {
			$order->add_order_note(
				sprintf(
					/* translators: 1: amount, 2: the payer, 3: transaction id, 4: when the money arrived. */
					__( 'A wallet transfer of %1$s from %2$s matched this order (transaction %3$s, money arrived %4$s).', 'qersh-for-woocommerce' ),
					$amount,
					$this->payer_text( $t ),
					$txn,
					$time
				)
			);
		}
		if ( $d['late'] ) {
			$order->add_order_note( __( 'Paid after the deadline.', 'qersh-for-woocommerce' ) );
		}
		if ( (int) $d['rounded'] > 0 ) {
			/* translators: %s: the piasters added, as an amount. */
			$order->add_order_note( sprintf( __( 'Rounded to the pound (+%s).', 'qersh-for-woocommerce' ), $this->money( (int) $d['rounded'], $order ) ) );
		}
		if ( 'overpaid' === $d['outcome'] ) {
			/* translators: %s: amount. */
			$order->add_order_note( sprintf( __( 'Overpaid by %s', 'qersh-for-woocommerce' ), $this->money( (int) $d['over'], $order ) ) );
		}
	}

	/**
	 * The customer's note (an email) of an order that received part of its amount (docs/17 §4.6 "partial"): what
	 * arrived, the total, the rest rounded up to the pound and the deadline; for an Orange order, the line about the
	 * new transaction id.
	 *
	 * @param \WC_Order $order    Order.
	 * @param string    $provider Provider of the transfer.
	 * @param int       $paid     paid_minor now.
	 * @param int       $price    Order total.
	 * @param int       $now      UTC seconds.
	 */
	public function customer_partial_note( \WC_Order $order, string $provider, int $paid, int $price, int $now ): void {
		$opts     = MatchPolicy::options( $this->plugin->config() );
		$left     = max( 0, $price - $paid );
		$left     = $opts['round_up_to_pound'] ? Money::round_up_pound( $left ) : $left;
		$deadline = (int) $order->get_meta( '_qersh_deadline', true );
		$note     = sprintf(
			/* translators: 1: amount received so far, 2: order total, 3: amount that remains, 4: deadline. */
			__( 'We received %1$s of %2$s; %3$s remains. Send the rest from the same number before %4$s.', 'qersh-for-woocommerce' ),
			$this->money( $paid, $order ),
			$this->money( $price, $order ),
			$this->money( $left, $order ),
			self::ltr( self::time_text( $deadline > 0 ? $deadline : $now, $now ) )
		);
		if ( in_array( $provider, TxnId::CLAIM_PROVIDERS, true ) || $this->order_is_orange( $order ) ) {
			$note .= ' ' . __( 'Then enter the transaction id of the new transfer on your order page.', 'qersh-for-woocommerce' );
		}
		$order->add_order_note( $note, 1 );
	}

	/**
	 * Asks Completion (package D1) to complete an order whose paid decision is committed.
	 *
	 * @param int $order_id Order id.
	 */
	public function request_completion( int $order_id ): void {
		if ( ! class_exists( Completion::class ) ) {
			return; // Without the completion module the sweep has nothing to run; the decision stays due.
		}
		try {
			$this->plugin->completion()->request( $order_id );
		} catch ( \Throwable $e ) {
			$this->log( 'completion request', $e );
		}
	}

	/**
	 * The order number the merchant and the customer see (get_order_number()), cut to 64 characters, for the
	 * reply's `reference`; '' when the order is gone.
	 *
	 * @param int $order_id Order id.
	 * @return string
	 */
	public function reference( int $order_id ): string {
		$order = $order_id > 0 ? wc_get_order( $order_id ) : false;
		if ( ! $order instanceof \WC_Order ) {
			return '';
		}
		$number = (string) $order->get_order_number();
		return function_exists( 'mb_substr' ) ? (string) mb_substr( $number, 0, 64, 'UTF-8' ) : substr( $number, 0, 64 );
	}

	/**
	 * An alert for the admin (Emails\AdminAlert listens; package E).
	 *
	 * @param string $kind `forgery`, `claim_overdue` or `partial_overdue`.
	 * @param array  $args `order_id`, `transfer_id`, `wallet`.
	 */
	public function alert( string $kind, array $args ): void {
		try {
			/**
			 * Asks for the administrator's alert email about a transfer that needs a decision (the
			 * `qersh_admin_alert` email listens).
			 *
			 * @since 0.1.0
			 *
			 * @param string $kind `forgery`, `claim_overdue` or `partial_overdue`.
			 * @param array  $args `order_id`, `transfer_id`, `wallet`.
			 */
			do_action( 'qersh_wc_admin_alert', $kind, $args );
		} catch ( \Throwable $e ) {
			$this->log( 'admin alert', $e );
		}
	}

	/**
	 * The order total in piasters: round( get_total() × 100 ).
	 *
	 * @param \WC_Order|false|null $order Order.
	 * @return int
	 */
	public static function total_minor( $order ): int {
		return $order instanceof \WC_Order ? (int) round( (float) $order->get_total() * 100 ) : 0;
	}

	/**
	 * Whether a reason of a transfer linked to an order is told from that order alone (Completion leaving a paid
	 * decision, a cancellation with money received, the deadline of a partial order): its text is then built from
	 * the order, never from a note written for an earlier decision. Pure.
	 *
	 * @param string $reason   Reason code.
	 * @param int    $order_id The transfer's order, 0 for none.
	 * @return bool
	 */
	public static function reason_of_order( string $reason, int $order_id ): bool {
		return $order_id > 0 && in_array( $reason, array( 'closed_order', 'stock_short', 'forgery', 'cancelled_paid', 'partial_overdue' ), true );
	}

	/**
	 * The payer lock of a key, or none for an empty key.
	 *
	 * @param string $payer_key Payer key.
	 * @return string[]
	 */
	public static function payer_lock( string $payer_key ): array {
		return '' === $payer_key ? array() : array( 'payer:' . $payer_key );
	}

	/**
	 * A row whose paid decision still waits for Completion: the hooks leave it alone, Completion reads the order
	 * again under the lock and corrects it. Pure.
	 *
	 * @param array $row Typed qersh_orders row.
	 * @return bool
	 */
	public static function completion_pending( array $row ): bool {
		return 'paid' === $row['state'] && in_array( $row['completion'], array( 'due', 'running' ), true );
	}

	/**
	 * Whether a status the merchant set by hand decides an order that waited with money received (its overdue
	 * reasons end): processing or completed. On hold and failed decide nothing. Pure.
	 *
	 * @param string $to New WooCommerce status, without `wc-`.
	 * @return bool
	 */
	public static function merchant_decided( string $to ): bool {
		return in_array( $to, array( 'processing', 'completed' ), true );
	}

	/**
	 * The risk_reasons column of a new transfer: the list as it came; nothing for a missing or null field (which has
	 * no unknown reason, Risk::unknown_reasons()); any other value as one unknown value, its JSON text, so the late
	 * path reads the same verdict as the arrival. Pure.
	 *
	 * @param mixed $reasons The normalised transfer's risk_reasons.
	 * @return array
	 */
	public static function stored_reasons( $reasons ): array {
		if ( is_array( $reasons ) ) {
			return array_values( $reasons );
		}
		return null === $reasons ? array() : array( Db::json( $reasons ) );
	}

	/**
	 * What attaching a transfer to an order means (docs/17 §4.6, §4.7). Pure.
	 *
	 * - `new`: the transfer counts toward no order (needs a decision, without a match, personal, or dismissed, even
	 *   when a dismissed one still names the order it left): its amount is added to the order.
	 * - `own`: the transfer is linked to this order and waits for the merchant, its amount counted already: left by
	 *   the completion or by a cancellation (`review`: stock short, closed, flagged, cancelled with money), or part of
	 *   the amount at the deadline (`partial_overdue`, "attach and complete with what arrived").
	 * - `confirm`: the overpaid transfer of this order, not flagged: the merchant confirms it, nothing else changes.
	 * - `move`: the transfer is linked to another order and waits for the merchant (`review`): its amount leaves that
	 *   order for this one.
	 * - `linked`: anything else linked to an order (it paid it): refused.
	 *
	 * @param array $tr       Typed qersh_transfers row.
	 * @param int   $order_id The target order.
	 * @return string
	 */
	public static function attach_kind( array $tr, int $order_id ): string {
		$linked  = self::linked_order( $tr );
		$outcome = isset( $tr['outcome'] ) ? (string) $tr['outcome'] : '';
		$reason  = isset( $tr['reason'] ) ? (string) $tr['reason'] : '';
		if ( 'dismissed' === $outcome ) {
			return 'new';
		}
		if ( 0 === $linked ) {
			return in_array( $outcome, MatchPolicy::PAYING_OUTCOMES, true ) ? 'linked' : 'new';
		}
		if ( $linked !== $order_id ) {
			return 'review' === $outcome ? 'move' : 'linked';
		}
		if ( 'review' === $outcome || 'partial_overdue' === $reason ) {
			return 'own';
		}
		$flagged = isset( $tr['flag'] ) && '' !== $tr['flag'];
		return 'overpaid' === $outcome && ! $flagged ? 'confirm' : 'linked';
	}

	/**
	 * What dismissing a transfer means. Pure.
	 *
	 * - `free`: it counts toward no order: dismissed.
	 * - `release`: it is linked to an order and waits for the merchant (`review`, the merchant refunded it or it is
	 *   not the store's): dismissed, and its amount no longer counts toward that order.
	 * - `partial`: part of the amount of an order at its deadline: refused (attach it to complete the order with what
	 *   arrived, or cancel the order, which refunds it, then dismiss it).
	 * - `overpaid`: it paid its order: refused (refund the extra amount, then attach it to that order to confirm it).
	 * - `linked`: it paid its order: refused.
	 *
	 * @param array $tr Typed qersh_transfers row.
	 * @return string
	 */
	public static function dismiss_kind( array $tr ): string {
		if ( 0 === self::linked_order( $tr ) ) {
			return 'free';
		}
		$outcome = isset( $tr['outcome'] ) ? (string) $tr['outcome'] : '';
		if ( 'review' === $outcome ) {
			return 'release';
		}
		if ( isset( $tr['reason'] ) && 'partial_overdue' === $tr['reason'] ) {
			return 'partial';
		}
		return 'overpaid' === $outcome ? 'overpaid' : 'linked';
	}

	/**
	 * Whether a transfer of an order waits for the merchant with it, so attaching one of them settles all of them
	 * (they all count in paid_minor): left in review, or part of the amount at the deadline. Pure.
	 *
	 * @param array $tr Typed qersh_transfers row.
	 * @return bool
	 */
	public static function waits_with_order( array $tr ): bool {
		$outcome = isset( $tr['outcome'] ) ? (string) $tr['outcome'] : '';
		if ( 'dismissed' === $outcome ) {
			return false;
		}
		return 'review' === $outcome || ( isset( $tr['reason'] ) && 'partial_overdue' === $tr['reason'] );
	}

	/**
	 * The fields of an order row when a transfer's amount stops counting toward it (moved to another order, or
	 * dismissed): paid_minor goes down, and a partial row without money opens again. Pure.
	 *
	 * @param array $row    Typed qersh_orders row.
	 * @param int   $amount The transfer's amount.
	 * @return array
	 */
	public static function released_fields( array $row, int $amount ): array {
		$left   = max( 0, (int) $row['paid_minor'] - max( 0, $amount ) );
		$fields = array( 'paid_minor' => $left );
		if ( 0 === $left && 'partial' === $row['state'] ) {
			$fields['state'] = 'open';
		}
		return $fields;
	}

	/**
	 * The order a transfer is linked to, 0 for none. Pure.
	 *
	 * @param array $tr Typed qersh_transfers row.
	 * @return int
	 */
	public static function linked_order( array $tr ): int {
		return isset( $tr['order_id'] ) ? (int) $tr['order_id'] : 0;
	}

	/**
	 * Isolates a value inside an order note (Format::ltr(), package C).
	 *
	 * @param string $value Value.
	 * @return string Safe HTML.
	 */
	public static function ltr( string $value ): string {
		if ( class_exists( Format::class ) ) {
			return Format::ltr( $value, Format::NOTE );
		}
		return '<span dir="ltr" style="unicode-bidi:isolate">' . esc_html( $value ) . '</span>';
	}

	/**
	 * An amount inside an order note, through wc_price().
	 *
	 * @param int       $minor Piasters.
	 * @param \WC_Order $order Order (its currency).
	 * @return string
	 */
	public function money( int $minor, \WC_Order $order ): string {
		if ( class_exists( Format::class ) ) {
			return Format::price( $minor, Format::NOTE, (string) $order->get_currency() );
		}
		return wc_price( $minor / 100, array( 'currency' => $order->get_currency() ) );
	}

	/**
	 * A time in Cairo for notes, with the date.
	 *
	 * @param int $timestamp UTC seconds.
	 * @param int $now       UTC seconds.
	 * @return string Not isolated.
	 */
	public static function time_text( int $timestamp, int $now ): string {
		if ( class_exists( Format::class ) ) {
			/* translators: %s: date and time. */
			return sprintf( __( '%s Cairo time', 'qersh-for-woocommerce' ), Format::cairo_time( $timestamp, $now, true ) );
		}
		/* translators: %s: date and time. */
		return sprintf( __( '%s Cairo time', 'qersh-for-woocommerce' ), (string) wp_date( 'Y-m-d H:i', $timestamp, new \DateTimeZone( 'Africa/Cairo' ) ) );
	}

	/**
	 * The name of a wallet type (Checkout::provider_name(), package C).
	 *
	 * @param string $provider Provider id.
	 * @return string
	 */
	public static function provider_name( string $provider ): string {
		$name = Checkout::provider_name( $provider );
		return '' !== $name ? $name : $provider;
	}

	/**
	 * Whether an order's wallet is an Orange Cash wallet (a claim provider).
	 *
	 * @param \WC_Order $order Order.
	 * @return bool
	 */
	public function order_is_orange( \WC_Order $order ): bool {
		$wallet    = (string) $order->get_meta( '_qersh_wallet', true );
		$providers = $this->wallet_providers();
		return isset( $providers[ $wallet ] ) && in_array( $providers[ $wallet ], TxnId::CLAIM_PROVIDERS, true );
	}

	/*
	 * ------------------------------------------------------------------------------------------------------------
	 * Internals.
	 * ------------------------------------------------------------------------------------------------------------
	 */

	/**
	 * One try of a transfer.received: null to try again (rolled back already).
	 *
	 * @param array      $t           Normalised transfer.
	 * @param array      $opts        Options.
	 * @param string     $delivery_id Delivery id.
	 * @param string     $token       Claim token.
	 * @param int        $now         UTC seconds.
	 * @param array|null $effects     Set to the effects after COMMIT.
	 * @return array|null
	 * @throws LockTimeout On a lock wait or a deadlock.
	 */
	private function received_once( array $t, array $opts, string $delivery_id, string $token, int $now, &$effects ): ?array {
		$db        = $this->plugin->db();
		$transfers = $this->plugin->transfers();
		$claim     = ( null === $t['payer_key'] || '' === $t['payer_key'] ) && null !== $t['txn'] && ! empty( $opts['txn_entry'] );
		$hint      = $claim ? $this->claimants( (string) $t['txn'] ) : array();
		$names     = MatchPolicy::lock_names( $t, $hint, ! empty( $opts['txn_entry'] ) );

		$db->begin();
		$db->lock( $names );
		if ( ! $this->plugin->deliveries()->fence( $delivery_id, $token, 200, array(), $now ) ) {
			$db->rollback();
			return self::busy(); // Another request took the delivery over; the phone hears its answer next time.
		}
		$existing = $transfers->get( (string) $t['transfer_id'] );
		if ( null !== $existing && null !== $existing['outcome'] ) {
			$reply = $this->reply_for_row( $existing ); // A verdict exists: it is the answer, never matched again.
			$this->plugin->deliveries()->store_reply( $delivery_id, $token, 200, $reply, $now );
			$db->commit();
			return self::ok( $reply );
		}
		$claimants = false;
		if ( $claim ) {
			$claimants = $this->claimants( (string) $t['txn'] );
			if ( MatchPolicy::claimants_print( $claimants ) !== MatchPolicy::claimants_print( $hint ) ) {
				$db->rollback();
				return null; // Someone typed or changed this id meanwhile: take the right locks.
			}
		}
		if ( null === $existing ) {
			$transfers->insert( $this->transfer_row( $t, $delivery_id, $now ) );
		}
		$ctx      = $this->context( $t, $opts, $now, $claimants );
		$decision = MatchPolicy::decide( $t, $ctx );
		$applied  = $this->write_decision( $t, $decision, $now );
		if ( null === $applied ) {
			$db->rollback();
			return null;
		}
		$reply = $this->reply_of( $decision );
		$this->plugin->deliveries()->store_reply( $delivery_id, $token, 200, $reply, $now );
		$db->commit();
		$effects = $applied;
		$this->plugin->logger()->info(
			'qersh: transfer decided',
			array(
				'outcome' => $decision['outcome'],
				'reason'  => $decision['reason'],
				'order'   => $decision['order_id'],
			)
		);
		return self::ok( $reply );
	}

	/**
	 * A transfer.received without a usable transfer_id: nothing can be stored or matched, so the reply is review.
	 *
	 * @param string $delivery_id Delivery id.
	 * @param string $token       Claim token.
	 * @param int    $now         UTC seconds.
	 * @return array
	 */
	private function received_unreadable( string $delivery_id, string $token, int $now ): array {
		$reply = array(
			'status' => 'review',
			'note'   => MatchPolicy::cut( __( 'This transfer could not be read: check it in the wallet app.', 'qersh-for-woocommerce' ), 200 ),
		);

		$db = $this->plugin->db();
		$db->begin();
		if ( ! $this->plugin->deliveries()->fence( $delivery_id, $token, 200, $reply, $now ) ) {
			$db->rollback();
			return self::busy();
		}
		$db->commit();
		return self::ok( $reply );
	}

	/**
	 * One try of a transfer.updated: null to try again.
	 *
	 * @param string     $tid         Transfer id ('' when missing).
	 * @param array      $data        Envelope data.
	 * @param array      $judge       Matcher::judge_update().
	 * @param string     $delivery_id Delivery id.
	 * @param string     $token       Claim token.
	 * @param int        $now         UTC seconds.
	 * @param array|null $alert       Set to the alert arguments when an order was stopped.
	 * @return array|null
	 * @throws LockTimeout On a lock wait or a deadlock.
	 */
	private function updated_once( string $tid, array $data, array $judge, string $delivery_id, string $token, int $now, &$alert ): ?array {
		$db        = $this->plugin->db();
		$transfers = $this->plugin->transfers();
		$index     = $this->plugin->orders_index();
		$hint      = '' === $tid ? null : $transfers->get( $tid );
		$hint_row  = null !== $hint && null !== $hint['order_id'] ? $index->get( (int) $hint['order_id'] ) : null;
		$names     = array();
		if ( null !== $hint ) {
			$names[] = MatchPolicy::transfer_lock( $hint );
		} elseif ( '' !== $tid ) {
			$names[] = 'trf:' . $tid;
		}
		if ( null !== $hint_row && '' !== (string) $hint_row['payer_key'] ) {
			$names[] = 'payer:' . $hint_row['payer_key'];
		}
		$reply = array( 'status' => 'ok' );

		$db->begin();
		$db->lock( $names );
		if ( ! $this->plugin->deliveries()->fence( $delivery_id, $token, 200, $reply, $now ) ) {
			$db->rollback();
			return self::busy();
		}
		$row = '' === $tid ? null : $transfers->get( $tid );
		if ( ( null === $row ) !== ( null === $hint ) || ( null !== $row && $row['order_id'] !== $hint['order_id'] ) ) {
			$db->rollback();
			return null;
		}
		if ( null !== $row && null !== $row['order_id'] ) {
			$now_row = $index->get( (int) $row['order_id'] );
			if ( null !== $now_row && ( null === $hint_row || (string) $now_row['payer_key'] !== (string) $hint_row['payer_key'] ) ) {
				$db->rollback();
				return null;
			}
		}
		if ( null !== $row ) {
			$fields = self::risk_fields( $data );
			if ( ! empty( $judge['stop_order'] ) ) {
				$fields['flag'] = 'forgery';
				if ( 'dismissed' !== $row['outcome'] ) {
					$fields['reason'] = 'forgery';
				}
				if ( null !== $row['order_id'] && in_array( $row['outcome'], MatchPolicy::PAYING_OUTCOMES, true ) ) {
					$this->stop_order( (int) $row['order_id'], $now );
					$alert = array(
						'order_id'    => (int) $row['order_id'],
						'transfer_id' => $tid,
						'wallet'      => (string) $row['wallet'],
					);
				}
			}
			if ( ! empty( $fields ) ) {
				$transfers->update( $tid, $fields );
			}
		}
		$db->commit();
		return self::ok( $reply );
	}

	/**
	 * The forgery stop of an order its transfer paid (docs/17 §4.4 step 9), inside the locked transaction: the flag,
	 * a completion that has not run is done without the call, the row goes to review; processing or completed goes
	 * on-hold with the note (no email, no second stock reduction); pending or cancelled keeps its status with the
	 * note, privately.
	 *
	 * @param int $order_id Order id.
	 * @param int $now      UTC seconds.
	 */
	private function stop_order( int $order_id, int $now ): void {
		$index  = $this->plugin->orders_index();
		$row    = $index->get( $order_id );
		$fields = array( 'state' => 'review' );
		if ( null !== $row && in_array( $row['completion'], array( 'due', 'running' ), true ) ) {
			$fields['completion']    = 'done';
			$fields['completion_at'] = $now;
		}
		if ( null !== $row ) {
			$index->update( $order_id, $fields );
		}
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$text = Reasons::forgery_warning(); // The sentence of the order box and the admin alert too.
		$order->update_meta_data( '_qersh_flag', 'forgery' );
		if ( in_array( $order->get_status(), array( 'processing', 'completed' ), true ) ) {
			$order->update_status( 'on-hold', $text );
			return;
		}
		$order->save();
		$order->add_order_note( $text );
	}

	/**
	 * The risk fields a transfer.updated carries.
	 *
	 * @param array $data Envelope data.
	 * @return array
	 */
	private static function risk_fields( array $data ): array {
		$fields = array();
		if ( isset( $data['risk'] ) && is_string( $data['risk'] ) ) {
			$fields['risk'] = $data['risk'];
		}
		if ( isset( $data['risk_reasons'] ) && is_array( $data['risk_reasons'] ) ) {
			$fields['risk_reasons'] = array_values( $data['risk_reasons'] );
		}
		if ( isset( $data['checks']['balance_chain'] ) && is_string( $data['checks']['balance_chain'] ) ) {
			$fields['balance_chain'] = $data['checks']['balance_chain'];
		}
		if ( isset( $data['auto_confirm_ok'] ) && is_bool( $data['auto_confirm_ok'] ) ) {
			$fields['auto_confirm_ok'] = $data['auto_confirm_ok'] ? 1 : 0;
		}
		return $fields;
	}

	/**
	 * The qersh_transfers row of a new transfer (no verdict yet).
	 *
	 * @param array  $t           Normalised transfer.
	 * @param string $delivery_id Delivery id.
	 * @param int    $now         UTC seconds.
	 * @return array
	 */
	private function transfer_row( array $t, string $delivery_id, int $now ): array {
		$reasons = self::stored_reasons( $t['risk_reasons'] );
		return array(
			'transfer_id'     => (string) $t['transfer_id'],
			'wallet'          => (string) $t['wallet'],
			'provider'        => '' === (string) $t['provider'] ? 'unknown' : (string) $t['provider'],
			'delivery_id'     => $delivery_id,
			'amount_minor'    => $t['amount_minor'],
			'currency'        => (string) $t['currency'],
			'payer_key'       => $t['payer_key'],
			'payer_name'      => $t['payer_name'],
			'provider_txn_id' => $t['txn_stored'],
			'money_at'        => (int) $t['money_at'],
			'sms_at'          => $t['sms_at'],
			'risk'            => $t['risk'],
			'risk_reasons'    => $reasons,
			'auto_confirm_ok' => ! empty( $t['auto_confirm_ok'] ) ? 1 : 0,
			'balance_chain'   => $t['balance_chain'],
			'flag'            => ! empty( $t['forgery'] ) ? 'forgery' : null,
			'created_at'      => $now,
			'updated_at'      => $now,
		);
	}

	/**
	 * The note column of a decision: the reason line of a review, then its hints (plain text, at most 255).
	 *
	 * @param array $t Normalised transfer.
	 * @param array $d Decision.
	 * @return string|null
	 */
	private static function note_of( array $t, array $d ): ?string {
		$lines = array();
		if ( 'review' === $d['outcome'] && null !== $d['reason'] ) {
			$args = $d['args'];
			if ( 'no_payer' === $d['reason'] && ! isset( $args['orange'] ) ) {
				$args['orange'] = in_array( $t['provider'], TxnId::CLAIM_PROVIDERS, true );
			}
			$lines[] = MatchPolicy::reason_text( (string) $d['reason'], $args );
		}
		foreach ( $d['hints'] as $hint ) {
			$lines[] = MatchPolicy::hint_text( (string) $hint[0], (array) $hint[1] );
		}
		$text = trim( wp_strip_all_tags( implode( ' ', $lines ) ) );
		return '' === $text ? null : MatchPolicy::cut( $text, 255 );
	}

	/**
	 * The payer as an order note shows it: the number (or InstaPay address) isolated, or the name the message gives.
	 *
	 * @param array $t Normalised transfer.
	 * @return string Safe HTML.
	 */
	private function payer_text( array $t ): string {
		if ( null !== $t['payer_key'] && '' !== $t['payer_key'] ) {
			$number = class_exists( Format::class ) ? Format::number( (string) $t['payer_key'] ) : (string) $t['payer_key'];
			return self::ltr( $number );
		}
		if ( null !== $t['payer_name'] && '' !== (string) $t['payer_name'] ) {
			return esc_html( (string) $t['payer_name'] );
		}
		return '—';
	}

	/**
	 * The attach note: the admin's name and the payer's number masked.
	 *
	 * @param \WC_Order $order   Order.
	 * @param array     $tr      Typed transfer row.
	 * @param int       $user_id Admin.
	 * @return string
	 */
	private function attach_note( \WC_Order $order, array $tr, int $user_id ): string {
		$user  = get_userdata( $user_id );
		$payer = isset( $tr['payer_key'] ) ? (string) $tr['payer_key'] : '';
		if ( '' !== $payer ) {
			$who = self::ltr( class_exists( Format::class ) ? Format::masked( $payer ) : substr( $payer, 0, 4 ) . '•••' . substr( $payer, -3 ) );
		} else {
			$who = isset( $tr['payer_name'] ) && '' !== $tr['payer_name'] ? esc_html( (string) $tr['payer_name'] ) : '—';
		}
		return sprintf(
			/* translators: 1: name of the admin, 2: amount, 3: the payer, masked, 4: transaction id. */
			__( '%1$s attached a wallet transfer of %2$s from %3$s to this order (transaction %4$s).', 'qersh-for-woocommerce' ),
			esc_html( $user ? $user->display_name : '#' . $user_id ),
			$this->money( (int) $tr['amount_minor'], $order ),
			$who,
			isset( $tr['provider_txn_id'] ) ? self::ltr( (string) $tr['provider_txn_id'] ) : '—'
		);
	}

	/**
	 * The confirmation note of a transfer that already counted toward this order: the admin's name and the payer's
	 * number masked.
	 *
	 * @param \WC_Order $order   Order.
	 * @param array     $tr      Typed transfer row.
	 * @param int       $user_id Admin.
	 * @return string
	 */
	private function confirm_note( \WC_Order $order, array $tr, int $user_id ): string {
		$user  = get_userdata( $user_id );
		$payer = isset( $tr['payer_key'] ) ? (string) $tr['payer_key'] : '';
		if ( '' !== $payer ) {
			$who = self::ltr( class_exists( Format::class ) ? Format::masked( $payer ) : substr( $payer, 0, 4 ) . '•••' . substr( $payer, -3 ) );
		} else {
			$who = isset( $tr['payer_name'] ) && '' !== $tr['payer_name'] ? esc_html( (string) $tr['payer_name'] ) : '—';
		}
		return sprintf(
			/* translators: 1: name of the admin, 2: amount, 3: the payer, masked, 4: transaction id. */
			__( '%1$s confirmed the wallet transfer of %2$s from %3$s for this order (transaction %4$s).', 'qersh-for-woocommerce' ),
			esc_html( $user ? $user->display_name : '#' . $user_id ),
			$this->money( (int) $tr['amount_minor'], $order ),
			$who,
			isset( $tr['provider_txn_id'] ) ? self::ltr( (string) $tr['provider_txn_id'] ) : '—'
		);
	}

	/**
	 * Whether a transfer may be attached to an order, and how (attach_kind()): a transfer with an amount; an order
	 * with our method (or none yet), not flagged as a suspected forgery (the completion never completes it), that is
	 * pending, on-hold or failed, or cancelled by our expiry (Completion checks the stock); for `confirm`, the order's
	 * row is paid; for `move`, the old order's row is not.
	 *
	 * @param array|null $tr       Typed transfer row.
	 * @param int        $order_id Order id.
	 * @return string|\WP_Error `new`, `own`, `confirm` or `move`.
	 */
	private function attachable( ?array $tr, int $order_id ) {
		if ( null === $tr ) {
			return new \WP_Error( 'qersh_transfer_unknown', __( 'This transfer was not found.', 'qersh-for-woocommerce' ) );
		}
		$linked = new \WP_Error( 'qersh_transfer_linked', __( 'This transfer is already linked to an order.', 'qersh-for-woocommerce' ) );
		$mode   = self::attach_kind( $tr, $order_id );
		if ( 'linked' === $mode ) {
			return $linked;
		}
		if ( null === $tr['amount_minor'] || (int) $tr['amount_minor'] <= 0 ) {
			return new \WP_Error( 'qersh_transfer_no_amount', __( 'This transfer has no amount, so it cannot be attached.', 'qersh-for-woocommerce' ) );
		}
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return new \WP_Error( 'qersh_order_unknown', __( 'This order was not found.', 'qersh-for-woocommerce' ) );
		}
		$method = (string) $order->get_payment_method();
		if ( '' !== $method && self::METHOD !== $method ) {
			return new \WP_Error( 'qersh_order_method', __( 'This order is not paid by wallet transfer.', 'qersh-for-woocommerce' ) );
		}
		if ( '' !== (string) $order->get_meta( '_qersh_flag', true ) ) {
			return new \WP_Error( 'qersh_order_flagged', __( 'This order is flagged as a suspected forgery. Check that the money arrived in the wallet app, then change the order\'s status yourself.', 'qersh-for-woocommerce' ) );
		}
		$index = $this->plugin->orders_index();
		if ( 'confirm' === $mode ) {
			$row = $index->get( $order_id );
			return null !== $row && 'paid' === $row['state'] ? $mode : $linked;
		}
		if ( 'move' === $mode ) {
			$old = $index->get( self::linked_order( $tr ) );
			if ( null !== $old && 'paid' === $old['state'] ) {
				return $linked;
			}
		}
		$status = (string) $order->get_status();
		$ours   = 'cancelled' === $status && (int) $order->get_meta( '_qersh_expired_at', true ) > 0;
		if ( ! in_array( $status, array( 'pending', 'on-hold', 'failed' ), true ) && ! $ours ) {
			return new \WP_Error( 'qersh_order_status', __( 'Only an order that is pending payment, on hold or failed, or one that the transfer window cancelled, can take a transfer.', 'qersh-for-woocommerce' ) );
		}
		return $mode;
	}

	/**
	 * The writes of an attach, inside the locked transaction (apply_attach()). Returns what to do after COMMIT, or a
	 * refusal (roll back).
	 *
	 * @param string     $mode    attach_kind() as attachable() refined it.
	 * @param array      $tr      Typed transfer row, read under the locks.
	 * @param \WC_Order  $order   The target order.
	 * @param array|null $row     Its row, read under the locks.
	 * @param string     $payer   Its payer key (target_payer()).
	 * @param int        $user_id The admin.
	 * @param int        $now     UTC seconds.
	 * @return array|\WP_Error `[ 'paid' => bool, 'partial' => bool, 'new_row' => bool, 'deadline' => int, 'total' => int,
	 *                         'price' => int, 'provider' => string ]`
	 */
	private function attach_locked( string $mode, array $tr, \WC_Order $order, ?array $row, string $payer, int $user_id, int $now ) {
		$transfers = $this->plugin->transfers();
		$index     = $this->plugin->orders_index();
		$order_id  = (int) $order->get_id();
		$tid       = (string) $tr['transfer_id'];
		$amount    = (int) $tr['amount_minor'];
		$price     = self::total_minor( $order );
		$effects   = array(
			'paid'     => false,
			'partial'  => false,
			'new_row'  => null === $row,
			'deadline' => 0,
			'total'    => 0,
			'price'    => $price,
			'provider' => (string) $tr['provider'],
		);
		if ( 'confirm' === $mode ) {
			$transfers->update(
				$tid,
				array(
					'outcome' => 'attached',
					'reason'  => null,
					'note'    => MatchPolicy::reply_note( 'attached' ),
				)
			);
			$transfers->request_sync( $tid, $now );
			$order->add_order_note( $this->confirm_note( $order, $tr, $user_id ) );
			return $effects;
		}

		$counted = 'own' === $mode && null !== $row; // Its amount is in paid_minor already.
		$total   = ( null === $row ? 0 : (int) $row['paid_minor'] ) + ( $counted ? 0 : $amount );
		$accept  = 'own' === $mode && isset( $tr['reason'] ) && 'partial_overdue' === $tr['reason'];
		$paid    = $total >= $price || $accept;
		if ( ! $paid && 'cancelled' === $order->get_status() ) {
			return new \WP_Error( 'qersh_attach_cancelled_partial', __( 'This order was cancelled when its transfer window ended, and what arrived does not cover it, so it cannot be brought back with part of the amount.', 'qersh-for-woocommerce' ) );
		}

		if ( 'move' === $mode ) {
			$old_id = self::linked_order( $tr );
			$old    = $index->get( $old_id );
			if ( null !== $old ) {
				$index->update( $old_id, self::released_fields( $old, $amount ) );
			}
			$old_order = wc_get_order( $old_id );
			if ( $old_order instanceof \WC_Order ) {
				$user = get_userdata( $user_id );
				$old_order->add_order_note(
					sprintf(
						/* translators: 1: name of the admin, 2: amount, 3: order number. */
						__( '%1$s moved a wallet transfer of %2$s from this order to order #%3$s; it no longer counts toward this order.', 'qersh-for-woocommerce' ),
						esc_html( $user ? $user->display_name : '#' . $user_id ),
						$this->money( $amount, $old_order ),
						self::ltr( (string) $order->get_order_number() )
					)
				);
			}
		}

		if ( null === $row ) {
			$opts     = MatchPolicy::options( $this->plugin->config() );
			$deadline = (int) $order->get_meta( '_qersh_deadline', true );
			$deadline = $deadline > 0 ? $deadline : $now + $opts['order_ttl_min'] * 60;
			$index->upsert(
				array(
					'order_id'    => $order_id,
					'wallet'      => (string) $tr['wallet'],
					'payer_key'   => $payer,
					'price_minor' => $price,
					'state'       => 'open',
					'created_at'  => $now,
					'expires_at'  => $deadline,
				)
			);
			$effects['deadline'] = $deadline;
		}
		$fields  = array(
			'paid_minor'  => $total,
			'price_minor' => $price,
		);
		$fields += $paid ? array(
			'state'      => 'paid',
			'paid_at'    => $now,
			'completion' => 'due',
		) : array( 'state' => 'partial' );
		$index->update( $order_id, $fields );

		// The transfer, and for `own` the order's other transfers that waited with it: they all count in paid_minor.
		$ids = array( $tid );
		if ( 'own' === $mode ) {
			$page = $transfers->list_page( array( 'order_id' => $order_id ), 1, 200 );
			foreach ( $page['rows'] as $other ) {
				if ( (string) $other['transfer_id'] !== $tid && self::waits_with_order( $other ) ) {
					$ids[] = (string) $other['transfer_id'];
				}
			}
		}
		foreach ( $ids as $id ) {
			$transfers->update(
				$id,
				array(
					'outcome'  => 'attached',
					'order_id' => $order_id,
					'reason'   => null,
					'note'     => MatchPolicy::reply_note( 'attached' ),
				)
			);
			$transfers->request_sync( $id, $now );
		}

		$save = false;
		if ( '' === (string) $order->get_payment_method() ) {
			$order->set_payment_method( self::METHOD );
			$save = true;
		}
		if ( null === $row ) {
			// The meta of process_payment() for an order created in the admin (docs/17 §4.3): display, emails, privacy.
			if ( '' === (string) $order->get_meta( '_qersh_payer_key', true ) && 0 !== strpos( $payer, 'order:' ) ) {
				$order->update_meta_data( '_qersh_payer_key', $payer );
			}
			if ( '' === (string) $order->get_meta( '_qersh_wallet', true ) ) {
				$order->update_meta_data( '_qersh_wallet', (string) $tr['wallet'] );
			}
			if ( '' === (string) $order->get_meta( '_qersh_placed_at', true ) ) {
				$order->update_meta_data( '_qersh_placed_at', (string) $now );
			}
			$order->update_meta_data( '_qersh_deadline', (string) $effects['deadline'] );
			$save = true;
		}
		if ( $save ) {
			$order->save();
		}

		$order->add_order_note( 'own' === $mode ? $this->confirm_note( $order, $tr, $user_id ) : $this->attach_note( $order, $tr, $user_id ) );
		if ( $paid && $total > $price && ! $counted ) {
			/* translators: %s: amount. */
			$order->add_order_note( sprintf( __( 'Overpaid by %s', 'qersh-for-woocommerce' ), $this->money( $total - $price, $order ) ) );
		}
		if ( $paid && $total < $price ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: amount received, 2: order total. */
					__( 'The order is completed with what arrived: %1$s of %2$s.', 'qersh-for-woocommerce' ),
					$this->money( $total, $order ),
					$this->money( $price, $order )
				)
			);
		}
		$effects['paid']    = $paid;
		$effects['partial'] = ! $paid;
		$effects['total']   = $total;
		return $effects;
	}

	/**
	 * After COMMIT of an attach: a paid order loses its expiry action and goes to the completion; a partial one gets
	 * the customer's note, as an automatic partial match does, and the expiry action when its row was written here.
	 * Never throws.
	 *
	 * @param int   $order_id Order id.
	 * @param array $effects  From attach_locked().
	 * @param int   $now      UTC seconds.
	 */
	private function after_attach( int $order_id, array $effects, int $now ): void {
		if ( $effects['paid'] ) {
			try {
				self::unschedule_expiry( $order_id );
			} catch ( \Throwable $e ) {
				$this->log( 'expiry unschedule', $e );
			}
			$this->request_completion( $order_id );
			return;
		}
		if ( ! $effects['partial'] ) {
			return;
		}
		try {
			$order = wc_get_order( $order_id );
			if ( $order instanceof \WC_Order ) {
				$this->customer_partial_note( $order, (string) $effects['provider'], (int) $effects['total'], (int) $effects['price'], $now );
			}
		} catch ( \Throwable $e ) {
			$this->log( 'attach notes', $e );
		}
		if ( $effects['new_row'] ) {
			$opts = MatchPolicy::options( $this->plugin->config() );
			$this->schedule_expiry( $order_id, (int) $effects['deadline'] + $opts['late_window_min'] * 60 );
		}
	}

	/**
	 * The refusal of a dismiss, with what to do instead.
	 *
	 * @param string $kind     dismiss_kind().
	 * @param int    $order_id The order the transfer counts toward.
	 * @return \WP_Error
	 */
	private static function dismiss_refusal( string $kind, int $order_id ): \WP_Error {
		if ( 'partial' === $kind ) {
			return new \WP_Error(
				'qersh_transfer_partial',
				/* translators: %s: order number. */
				sprintf( __( 'This transfer paid part of order #%s. Attach it to that order to complete it with what arrived, or cancel the order and refund the customer before you dismiss it.', 'qersh-for-woocommerce' ), MatchPolicy::order_ref( $order_id ) )
			);
		}
		if ( 'overpaid' === $kind ) {
			return new \WP_Error(
				'qersh_transfer_overpaid',
				/* translators: %s: order number. */
				sprintf( __( 'This transfer paid order #%s. Refund the extra amount from the wallet app, then attach it to that order to confirm it.', 'qersh-for-woocommerce' ), MatchPolicy::order_ref( $order_id ) )
			);
		}
		return new \WP_Error( 'qersh_transfer_linked', __( 'This transfer is linked to an order and cannot be dismissed.', 'qersh-for-woocommerce' ) );
	}

	/**
	 * Whether two reads of an order row name the same payer (both missing counts as the same). Pure.
	 *
	 * @param array|null $a Row.
	 * @param array|null $b Row.
	 * @return bool
	 */
	private static function same_payer( ?array $a, ?array $b ): bool {
		if ( null === $a || null === $b ) {
			return null === $a && null === $b;
		}
		return (string) $a['payer_key'] === (string) $b['payer_key'];
	}

	/**
	 * The payer key of the target order's row: the row's, the order meta's, the transfer's, the billing phone's, or
	 * a key that never matches a transfer.
	 *
	 * @param \WC_Order  $order Order.
	 * @param array|null $row   Row.
	 * @param array      $tr    Transfer row.
	 * @return string
	 */
	private function target_payer( \WC_Order $order, ?array $row, array $tr ): string {
		if ( null !== $row && '' !== (string) $row['payer_key'] ) {
			return (string) $row['payer_key'];
		}
		$meta = (string) $order->get_meta( '_qersh_payer_key', true );
		if ( '' !== $meta ) {
			return $meta;
		}
		if ( null !== $tr['payer_key'] && '' !== (string) $tr['payer_key'] ) {
			return (string) $tr['payer_key'];
		}
		$phone = Payer::normalize( (string) $order->get_billing_phone() );
		return null !== $phone ? $phone : 'order:' . (int) $order->get_id();
	}

	/**
	 * The status an attached transfer reports to the phone: from its order's row now.
	 *
	 * @param int $order_id Order id.
	 * @return string
	 */
	private function attached_status( int $order_id ): string {
		$row = $order_id > 0 ? $this->plugin->orders_index()->get( $order_id ) : null;
		if ( null === $row ) {
			return 'matched';
		}
		if ( 'partial' === $row['state'] ) {
			return 'partial';
		}
		return (int) $row['paid_minor'] > (int) $row['price_minor'] ? 'overpaid' : 'matched';
	}

	/**
	 * A row leaves pending by someone else's hand: closed. With money already received and a cancellation or a
	 * change of method, a note and its transfers to "Needs decision"; with money received and a status the merchant
	 * set by hand to processing or completed, the overdue reasons are cleared, since the merchant decided. On hold
	 * or failed is no decision: the money is still held, so those transfers stay in "Needs decision".
	 *
	 * @param \WC_Order $order  Order.
	 * @param array     $row    Row.
	 * @param bool      $cancel True for a cancellation, a refund or a change of method.
	 * @param string    $to     The new WooCommerce status ('' for a change of method).
	 */
	private function close( \WC_Order $order, array $row, bool $cancel, string $to = '' ): void {
		$id   = (int) $order->get_id();
		$paid = (int) $row['paid_minor'];
		if ( $paid > 0 ) {
			$transfers = $this->plugin->transfers();
			$page      = $transfers->list_page( array( 'order_id' => $id ), 1, 200 );
			foreach ( $page['rows'] as $transfer ) {
				$tid = (string) $transfer['transfer_id'];
				if ( $cancel && in_array( $transfer['outcome'], MatchPolicy::PAYING_OUTCOMES, true ) ) {
					$transfers->update(
						$tid,
						array(
							'outcome' => 'review',
							'reason'  => 'cancelled_paid',
							'note'    => MatchPolicy::reason_text( 'cancelled_paid', array( 'N' => $id ) ),
						)
					);
					$transfers->request_sync( $tid, time() );
				} elseif ( ! $cancel && self::merchant_decided( $to ) && in_array( $transfer['reason'], array( 'partial_overdue', 'claim_overdue' ), true ) ) {
					$transfers->update(
						$tid,
						array(
							'reason' => null,
							'note'   => null,
						)
					);
				}
			}
			if ( $cancel ) {
				$order->add_order_note(
					sprintf(
						/* translators: %s: amount received. */
						__( 'This order has %s paid: refund it to the customer from the wallet app.', 'qersh-for-woocommerce' ),
						$this->money( $paid, $order )
					)
				);
			}
		}
		$this->plugin->orders_index()->update( $id, array( 'state' => 'closed' ) );
	}

	/**
	 * Back to pending with our method: a closed or expired row opens again (partial when money arrived), with a new
	 * deadline and expiry action when the old deadline has passed.
	 *
	 * @param \WC_Order $order Order.
	 * @param array     $row   Row.
	 */
	private function reopen( \WC_Order $order, array $row ): void {
		if ( ! in_array( $row['state'], array( 'closed', 'expired' ), true ) ) {
			return;
		}
		$id       = (int) $order->get_id();
		$now      = time();
		$opts     = MatchPolicy::options( $this->plugin->config() );
		$deadline = (int) $row['expires_at'];
		$fields   = array( 'state' => (int) $row['paid_minor'] > 0 ? 'partial' : 'open' );
		if ( $deadline + $opts['late_window_min'] * 60 <= $now ) {
			$deadline             = $now + $opts['order_ttl_min'] * 60;
			$fields['expires_at'] = $deadline;
			$order->update_meta_data( '_qersh_deadline', (string) $deadline );
			$order->save_meta_data();
		}
		$this->plugin->orders_index()->update( $id, $fields );
		$this->schedule_expiry( $id, $deadline + $opts['late_window_min'] * 60 );
	}

	/**
	 * The warning of a transfer that arrived before the order was created (rule 5).
	 *
	 * @param int $order_id Order id.
	 * @return string
	 */
	private static function created_after_text( int $order_id ): string {
		return sprintf(
			/* translators: %s: order number. */
			__( 'This transfer arrived before order %s was created, so attach it only if you are sure it is for that order.', 'qersh-for-woocommerce' ),
			MatchPolicy::order_tag( $order_id )
		);
	}

	/**
	 * The 200 answer.
	 *
	 * @param array $reply Reply.
	 * @return array
	 */
	private static function ok( array $reply ): array {
		return array(
			'http'        => 200,
			'reply'       => $reply,
			'retry_after' => null,
		);
	}

	/**
	 * The 429 answer after a lock wait twice (docs/17 §4.4 step 8).
	 *
	 * @return array
	 */
	private static function busy(): array {
		return array(
			'http'        => 429,
			'reply'       => array( 'code' => 'qersh_busy' ),
			'retry_after' => self::RETRY_AFTER,
		);
	}

	/**
	 * The service when the hooks may run: WooCommerce and our tables ready.
	 *
	 * @return Orders|null
	 */
	private static function ready(): ?Orders {
		$plugin = Plugin::instance();
		if ( ! $plugin->wc_ready() || ! $plugin->tables_ready() ) {
			return null;
		}
		return $plugin->orders();
	}

	/**
	 * Logs a failure without personal data.
	 *
	 * @param string     $what Where.
	 * @param \Throwable $e    Error.
	 */
	private function log( string $what, \Throwable $e ): void {
		$this->plugin->logger()->error(
			'qersh: ' . $what . ' failed',
			array(
				'error' => get_class( $e ),
				'where' => basename( $e->getFile() ) . ':' . $e->getLine(),
			)
		);
	}
}
