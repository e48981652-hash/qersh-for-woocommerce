<?php
/**
 * The "Qersh" box on the order screen (docs/17 §5 step 9), on HPOS and on the posts storage.
 *
 * @package QershPay\WooCommerce
 */

namespace QershPay\WooCommerce\Admin;

use QershPay\WooCommerce\Core\TxnId;

defined( 'ABSPATH' ) || exit;

/**
 * Payer number, wallet, deadline, the transfers applied, the forgery flag, the last delivery from the phone and, for
 * an Orange Cash order, the transaction id its customer entered and what became of it.
 */
final class OrderBox {

	/**
	 * Screen ids of the order edit screen: HPOS and the posts storage.
	 *
	 * @return string[]
	 */
	public static function screens(): array {
		$out = array( 'shop_order' );
		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$hpos = wc_get_page_screen_id( 'shop-order' );
			if ( is_string( $hpos ) && '' !== $hpos ) {
				$out[] = $hpos;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * True on an order edit screen.
	 *
	 * @return bool
	 */
	public static function is_order_screen(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return $screen && in_array( $screen->id, self::screens(), true );
	}

	/**
	 * Adds the box to the orders shows() accepts (add_meta_boxes).
	 *
	 * @param string $screen_or_type Screen id or post type.
	 * @param mixed  $post_or_order  WP_Post or WC_Order.
	 */
	public static function add( $screen_or_type = '', $post_or_order = null ): void {
		if ( ! in_array( $screen_or_type, self::screens(), true ) || ! current_user_can( Module::CAP ) ) {
			return;
		}
		$order = self::order_of( $post_or_order );
		if ( ! $order || ! self::shows( (string) $order->get_payment_method(), (string) $order->get_status(), (string) $order->get_created_via() ) ) {
			return;
		}
		add_meta_box( 'qersh-wc-order', __( 'Qersh', 'qersh-for-woocommerce' ), array( __CLASS__, 'render' ), $screen_or_type, 'side', 'default' );
	}

	/**
	 * Whether an order gets the box: one placed with the wallet transfer method, or a pending order created in the
	 * admin without a method yet, which a transfer can still be attached to (Transfers::method_attachable()); the box
	 * then says how. Pure.
	 *
	 * @param string $method      Payment method id.
	 * @param string $status      WooCommerce status, without `wc-`.
	 * @param string $created_via WC_Order::get_created_via().
	 * @return bool
	 */
	public static function shows( string $method, string $status, string $created_via ): bool {
		if ( 'qersh' === $method ) {
			return true;
		}
		return '' === $method && 'pending' === $status && 'admin' === $created_via;
	}

	/**
	 * The order of a meta box callback argument.
	 *
	 * @param mixed $post_or_order WP_Post or WC_Order.
	 * @return \WC_Order|null
	 */
	private static function order_of( $post_or_order ) {
		if ( is_object( $post_or_order ) && is_a( $post_or_order, 'WC_Order' ) ) {
			return $post_or_order;
		}
		if ( is_object( $post_or_order ) && isset( $post_or_order->ID ) && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( (int) $post_or_order->ID );
			return $order && is_a( $order, 'WC_Order' ) ? $order : null;
		}
		return null;
	}

	/**
	 * Renders the box.
	 *
	 * @param mixed $post_or_order WP_Post or WC_Order.
	 */
	public static function render( $post_or_order ): void {
		$order = self::order_of( $post_or_order );
		if ( ! $order ) {
			return;
		}
		Module::template( 'order-box', self::args( $order ) );
	}

	/**
	 * What the box shows.
	 *
	 * @param \WC_Order $order Order.
	 * @return array `[ 'lines' => escaped HTML[], 'transfers' => array[], 'flag' => ?string, 'links' => array ]`
	 */
	public static function args( $order ): array {
		$plugin   = Module::plugin();
		$order_id = (int) $order->get_id();
		$row      = null;
		$applied  = array();
		try {
			if ( $plugin->tables_ready() ) {
				$row     = $plugin->orders_index()->get( $order_id );
				$applied = $plugin->transfers()->list_page( array( 'order_id' => $order_id ), 1, 20 )['rows'];
			}
		} catch ( \Throwable $e ) {
			$row = null;
		}
		$lines     = array();
		$wallet_id = is_array( $row ) ? (string) $row['wallet'] : (string) $order->get_meta( '_qersh_wallet' );
		$wallet    = '' === $wallet_id ? null : $plugin->wallet( $wallet_id );
		$payer     = is_array( $row ) ? (string) $row['payer_key'] : (string) $order->get_meta( '_qersh_payer_key' );
		$deadline  = (int) $order->get_meta( '_qersh_deadline' );
		if ( $deadline <= 0 && is_array( $row ) ) {
			$deadline = (int) $row['expires_at'];
		}

		if ( null === $row && $order->has_status( 'pending' ) ) {
			$lines[] = esc_html__( 'The wait for the transfer has not started yet. Send the customer the payment page (Order actions → “Send order details to customer”) so they enter the number they will transfer from, or attach their transfer from “Personal transfers” if they transferred before the order was created.', 'qersh-for-woocommerce' );
		}
		if ( '' !== $payer ) {
			/* translators: %s: wallet number or InstaPay address. */
			$lines[] = sprintf( esc_html__( 'Payer number: %s', 'qersh-for-woocommerce' ), View::ltr( $payer ) );
		}
		if ( null !== $wallet ) {
			/* translators: 1: wallet name, 2: wallet number or address. */
			$lines[] = sprintf( esc_html__( 'Wallet: %1$s (%2$s)', 'qersh-for-woocommerce' ), esc_html( View::wallet_name( $wallet ) ), View::ltr( (string) $wallet['receive_address'] ) );
		} elseif ( '' !== $wallet_id ) {
			/* translators: %s: wallet id. */
			$lines[] = sprintf( esc_html__( 'Wallet: %s (deleted)', 'qersh-for-woocommerce' ), View::ltr( $wallet_id ) );
		}
		if ( $deadline > 0 ) {
			/* translators: %s: date and time. */
			$lines[] = sprintf( esc_html__( 'Deadline: %s Cairo time', 'qersh-for-woocommerce' ), View::ltr( View::time( $deadline ) ) );
		}
		if ( is_array( $row ) ) {
			$lines[] = esc_html( self::state_label( (string) $row['state'] ) );
			if ( (int) $row['paid_minor'] > 0 ) {
				/* translators: 1: amount received, 2: order amount. */
				$lines[] = sprintf( esc_html__( 'Received: %1$s of %2$s', 'qersh-for-woocommerce' ), View::money( (int) $row['paid_minor'] ), View::money( (int) $row['price_minor'] ) );
			}
		}

		$flag = 'forgery' === (string) $order->get_meta( '_qersh_flag' )
			? esc_html( Reasons::forgery_warning() )
			: null;

		if ( null !== $wallet ) {
			try {
				$wrow = $plugin->tables_ready() ? $plugin->wallets()->get( $wallet_id ) : null;
			} catch ( \Throwable $e ) {
				$wrow = null;
			}
			if ( is_array( $wrow ) && ! empty( $wrow['last_delivery_at'] ) ) {
				/* translators: %s: date and time. */
				$lines[] = sprintf( esc_html__( 'Last delivery from this wallet\'s phone: %s', 'qersh-for-woocommerce' ), View::ltr( View::time( (int) $wrow['last_delivery_at'] ) ) );
			} else {
				$lines[] = esc_html__( 'Nothing has arrived from this wallet\'s phone yet.', 'qersh-for-woocommerce' );
			}
		}

		$claim = self::claim_line( $order, $row, null !== $wallet ? (string) $wallet['provider'] : '' );
		if ( null !== $claim ) {
			$lines[] = $claim;
		}

		$transfers = array();
		foreach ( $applied as $transfer ) {
			$transfers[] = array(
				'amount' => View::money( isset( $transfer['amount_minor'] ) ? $transfer['amount_minor'] : null ),
				'time'   => View::ltr( View::time( (int) $transfer['money_at'] ) ),
				'id'     => View::ltr( (string) $transfer['transfer_id'] ),
				'status' => esc_html( Transfers::outcome_label( $transfer ) ),
				'txn'    => empty( $transfer['provider_txn_id'] ) ? '' : View::ltr( (string) $transfer['provider_txn_id'] ),
			);
		}

		return array(
			'lines'     => $lines,
			'flag'      => $flag,
			'transfers' => $transfers,
			'list_url'  => Module::page_url(
				'transfers',
				array(
					'view' => 'all',
					's'    => '' !== $payer ? $payer : (string) $order_id,
				)
			),
		);
	}

	/**
	 * "Transaction id from the customer: {T} ({time}) · {status}" for an Orange Cash order.
	 *
	 * @param \WC_Order  $order    Order.
	 * @param array|null $row      qersh_orders row.
	 * @param string     $provider The order wallet's type.
	 * @return string|null Escaped HTML.
	 */
	private static function claim_line( $order, $row, string $provider ): ?string {
		$txn = is_array( $row ) && ! empty( $row['claim_txn'] ) ? (string) $row['claim_txn'] : (string) $order->get_meta( '_qersh_claim_txn' );
		if ( '' === $txn ) {
			return null;
		}
		$at       = is_array( $row ) && ! empty( $row['claim_at'] ) ? (int) $row['claim_at'] : 0;
		$order_id = (int) $order->get_id();
		$status   = esc_html__( 'No transfer with this transaction id has arrived yet', 'qersh-for-woocommerce' );
		try {
			$provider = '' !== $provider ? $provider : TxnId::CLAIM_PROVIDERS[0];
			foreach ( Module::plugin()->transfers()->find_by_txn( $provider, $txn ) as $transfer ) {
				if ( (int) $transfer['order_id'] === $order_id && in_array( $transfer['outcome'], array( 'matched', 'partial', 'overpaid', 'attached' ), true ) ) {
					/* translators: %s: transfer id. */
					$status = sprintf( esc_html__( 'Matched transfer %s', 'qersh-for-woocommerce' ), View::ltr( (string) $transfer['transfer_id'] ) );
					break;
				}
				if ( ! empty( $transfer['order_id'] ) && in_array( $transfer['outcome'], array( 'matched', 'partial', 'overpaid', 'attached' ), true ) ) {
					/* translators: %s: order number. */
					$status = sprintf( esc_html__( 'Already paid order %s', 'qersh-for-woocommerce' ), View::order_link( (int) $transfer['order_id'] ) );
					break;
				}
				$status = esc_html__( 'Waits for your decision in “Needs a decision”', 'qersh-for-woocommerce' );
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}
		/* translators: 1: transaction id, 2: date and time, 3: what became of it. */
		return sprintf( esc_html__( 'Transaction id from the customer: %1$s (%2$s) · %3$s', 'qersh-for-woocommerce' ), View::ltr( $txn ), View::ltr( $at > 0 ? View::time( $at ) : '—' ), $status );
	}

	/**
	 * The name of a qersh_orders state.
	 *
	 * @param string $state State.
	 * @return string Plain text.
	 */
	public static function state_label( string $state ): string {
		switch ( $state ) {
			case 'open':
				return __( 'Waiting for the transfer', 'qersh-for-woocommerce' );
			case 'partial':
				return __( 'Part of the amount arrived', 'qersh-for-woocommerce' );
			case 'paid':
				return __( 'Paid by wallet transfer', 'qersh-for-woocommerce' );
			case 'review':
				return __( 'Waits for your decision in “Needs a decision”', 'qersh-for-woocommerce' );
			case 'expired':
				return __( 'The transfer window ended', 'qersh-for-woocommerce' );
			case 'closed':
				return __( 'Closed', 'qersh-for-woocommerce' );
		}
		return $state;
	}
}
