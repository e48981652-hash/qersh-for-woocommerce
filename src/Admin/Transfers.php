<?php
/**
 * The Transfers tab: Needs a decision / Unmatched / Personal transfers / Matched / All, suggestions computed when
 * shown, manual attach and dismiss (docs/17 §4.6, §5 step 9).
 *
 * @package QershPay\WooCommerce
 */

namespace QershPay\WooCommerce\Admin;

use QershPay\WooCommerce\Core\Payer;
use QershPay\WooCommerce\Core\TxnId;
use QershPay\WooCommerce\Orders;

defined( 'ABSPATH' ) || exit;

/**
 * Attach and dismiss are decided by Orders (package D2) under the transfer and payer locks; this screen only checks
 * the request, shows the two warnings of docs/17 §4.6 before an attach, and reports the result.
 */
final class Transfers {

	/**
	 * The tabs of the list, in order.
	 */
	const VIEWS = array( 'needs_decision', 'unmatched', 'personal', 'matched', 'all' );

	/**
	 * Outcomes a merchant can still attach or dismiss.
	 */
	const OPEN_OUTCOMES = array( 'review', 'overpaid', 'unmatched' );

	/**
	 * Renders the tab.
	 */
	public static function render() {
		$plugin = Module::plugin();
		if ( ! $plugin->tables_ready() ) {
			echo '<p>' . esc_html__( 'The Qersh tables are missing, so there is nothing to show.', 'qersh-for-woocommerce' ) . '</p>';
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- list filters and a confirmation view.
		$view   = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'needs_decision';
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$attach = isset( $_GET['attach'] ) ? self::clean_transfer_id( sanitize_text_field( wp_unslash( $_GET['attach'] ) ) ) : '';
		$target = isset( $_GET['order'] ) ? absint( wp_unslash( $_GET['order'] ) ) : 0;
		// phpcs:enable
		$view = in_array( $view, self::VIEWS, true ) ? $view : 'needs_decision';

		$confirm = null;
		if ( '' !== $attach && $target > 0 ) {
			$confirm = self::confirm_args( $attach, $target );
		}

		$views = array();
		$names = self::view_labels();
		foreach ( self::VIEWS as $key ) {
			$count = 0;
			try {
				$count = (int) $plugin->transfers()->list_page( array( 'view' => $key ), 1, 1 )['total'];
			} catch ( \Throwable $e ) {
				$count = 0;
			}
			$views[ $key ] = array(
				'label'   => $names[ $key ],
				'count'   => $count,
				'url'     => Module::page_url( 'transfers', array( 'view' => $key ) ),
				'current' => $key === $view,
			);
		}

		$table = new TransfersTable( $view, $search );
		$table->prepare_items();

		Module::template(
			'transfers',
			array(
				'views'   => $views,
				'view'    => $view,
				'search'  => $search,
				'table'   => $table,
				'confirm' => $confirm,
			)
		);
	}

	/**
	 * Names of the list's tabs.
	 *
	 * @return array<string, string>
	 */
	public static function view_labels(): array {
		return array(
			'needs_decision' => __( 'Needs a decision', 'qersh-for-woocommerce' ),
			'unmatched'      => __( 'Unmatched', 'qersh-for-woocommerce' ),
			'personal'       => __( 'Personal transfers', 'qersh-for-woocommerce' ),
			'matched'        => __( 'Matched', 'qersh-for-woocommerce' ),
			'all'            => __( 'All', 'qersh-for-woocommerce' ),
		);
	}

	/**
	 * The name of a transfer's outcome.
	 *
	 * @param array $row Transfer row.
	 * @return string Plain text.
	 */
	public static function outcome_label( array $row ): string {
		$outcome = isset( $row['outcome'] ) ? (string) $row['outcome'] : '';
		if ( 'unmatched' === $outcome && isset( $row['reason'] ) && 'personal' === $row['reason'] ) {
			return __( 'Personal transfer', 'qersh-for-woocommerce' );
		}
		switch ( $outcome ) {
			case 'matched':
				return __( 'Matched', 'qersh-for-woocommerce' );
			case 'partial':
				return __( 'Part of the amount', 'qersh-for-woocommerce' );
			case 'overpaid':
				return __( 'Overpaid', 'qersh-for-woocommerce' );
			case 'unmatched':
				return __( 'Unmatched', 'qersh-for-woocommerce' );
			case 'review':
				return __( 'Needs a decision', 'qersh-for-woocommerce' );
			case 'attached':
				return __( 'Attached by you', 'qersh-for-woocommerce' );
			case 'dismissed':
				return __( 'Dismissed', 'qersh-for-woocommerce' );
		}
		return __( 'Being processed', 'qersh-for-woocommerce' );
	}

	/**
	 * Whether the merchant can still attach or dismiss this transfer. A transfer linked to an order never can
	 * (Orders::apply_attach() and Orders::dismiss() refuse it): an overpaid one, a forged one that paid its order,
	 * one whose paid order was cancelled, a partial payment past its deadline. Pure.
	 *
	 * @param array $row Transfer row.
	 * @return bool
	 */
	public static function is_open( array $row ): bool {
		if ( ! empty( $row['order_id'] ) ) {
			return false;
		}
		$outcome = isset( $row['outcome'] ) ? (string) $row['outcome'] : '';
		if ( in_array( $outcome, self::OPEN_OUTCOMES, true ) ) {
			return true;
		}
		return isset( $row['reason'] ) && in_array( $row['reason'], array( 'partial_overdue', 'claim_overdue' ), true ) && ! in_array( $outcome, array( 'attached', 'dismissed' ), true );
	}

	/**
	 * Whether the transfer needs a decision but is already linked to an order, so the merchant decides on the order
	 * and then marks the transfer as decided (admin-post qersh_wc_transfer_resolve). Pure.
	 *
	 * @param array $row Transfer row.
	 * @return bool
	 */
	public static function is_resolvable( array $row ): bool {
		return ! empty( $row['order_id'] ) && Reasons::needs_decision( $row );
	}

	/**
	 * Whether this copy of the plugin can mark a linked transfer as decided (Orders::resolve() of package D2).
	 *
	 * @return bool
	 */
	public static function can_resolve(): bool {
		return class_exists( Orders::class ) && method_exists( Orders::class, 'resolve' );
	}

	/**
	 * Whether the transfer was matched by the transaction id its customer entered (the "By transaction id" badge).
	 *
	 * @param array $row Transfer row.
	 * @return bool
	 */
	public static function matched_by_txn( array $row ): bool {
		if ( empty( $row['order_id'] ) || empty( $row['provider_txn_id'] ) || ! in_array( isset( $row['outcome'] ) ? $row['outcome'] : '', array( 'matched', 'partial', 'overpaid' ), true ) ) {
			return false;
		}
		if ( ! empty( $row['payer_key'] ) ) {
			return false;
		}
		try {
			$order = Module::plugin()->orders_index()->get( (int) $row['order_id'] );
		} catch ( \Throwable $e ) {
			return false;
		}
		return is_array( $order ) && isset( $order['claim_txn'] ) && $order['claim_txn'] === $row['provider_txn_id'];
	}

	/**
	 * The suggestions for a transfer, computed now (docs/17 §4.6): Orders::suggestions() of package D2, each item
	 * normalised to `[ 'order_id' => int, 'ready' => bool, 'claimant' => bool, 'reason' => string, 'dead' => bool ]`.
	 * Without D2 the customers who entered its transaction id and the payer's open orders are listed, never ready.
	 *
	 * @param array $row Transfer row.
	 * @return array[]
	 */
	public static function suggestions( array $row ): array {
		$plugin = Module::plugin();
		$items  = array();
		if ( class_exists( Orders::class ) && method_exists( Orders::class, 'suggestions' ) ) {
			try {
				$items = $plugin->orders()->suggestions( $row );
			} catch ( \Throwable $e ) {
				$items = array();
			}
		} else {
			$items = self::fallback_suggestions( $row );
		}
		$out  = array();
		$seen = array();
		foreach ( is_array( $items ) ? $items : array() as $item ) {
			$norm = self::normalize_suggestion( $item );
			if ( null === $norm || isset( $seen[ $norm['order_id'] ] ) ) {
				continue;
			}
			$seen[ $norm['order_id'] ] = true;
			$out[]                     = $norm;
		}
		// A pinned button only when exactly one claimant passed every gate.
		$ready = 0;
		foreach ( $out as $item ) {
			$ready += ( $item['ready'] && $item['claimant'] ) ? 1 : 0;
		}
		if ( $ready > 1 ) {
			foreach ( $out as $i => $item ) {
				$out[ $i ]['ready'] = false;
			}
		}
		return $out;
	}

	/**
	 * One suggestion in our shape, or null. Pure.
	 *
	 * @param mixed $item Item of Orders::suggestions().
	 * @return array|null
	 */
	public static function normalize_suggestion( $item ): ?array {
		if ( is_int( $item ) || ( is_string( $item ) && 1 === preg_match( '/^[0-9]+$/', $item ) ) ) {
			$item = array( 'order_id' => (int) $item );
		}
		if ( ! is_array( $item ) ) {
			return null;
		}
		$id = 0;
		foreach ( array( 'order_id', 'id', 'order' ) as $key ) {
			if ( isset( $item[ $key ] ) && is_numeric( $item[ $key ] ) ) {
				$id = (int) $item[ $key ];
				break;
			}
		}
		if ( $id <= 0 ) {
			return null;
		}
		$source   = isset( $item['source'] ) ? (string) $item['source'] : ( isset( $item['kind'] ) ? (string) $item['kind'] : '' );
		$claimant = ! empty( $item['claimant'] ) || in_array( $source, array( 'claim', 'claimant', 'txn' ), true );
		$dead     = ! empty( $item['dead'] ) || ( array_key_exists( 'live', $item ) && false === $item['live'] );
		$ready    = ( ! empty( $item['ready'] ) || ! empty( $item['pinned'] ) ) && ! $dead;
		$reason   = '';
		foreach ( array( 'reason', 'gate', 'fail' ) as $key ) {
			if ( isset( $item[ $key ] ) && is_string( $item[ $key ] ) && '' !== $item[ $key ] ) {
				$reason = $item[ $key ];
				break;
			}
		}
		$args = isset( $item['args'] ) && is_array( $item['args'] ) ? $item['args'] : array();
		return array(
			'order_id'  => $id,
			'ready'     => $ready && '' === $reason,
			'claimant'  => $claimant,
			'reason'    => $reason,
			'dead'      => $dead,
			'preselect' => ! empty( $item['preselect'] ) && ! $dead,
			'after'     => ! empty( $item['after'] ),
			'params'    => array(
				'n'       => isset( $args['N'] ) && is_numeric( $args['N'] ) ? (int) $args['N'] : $id,
				'm'       => isset( $args['M'] ) && is_numeric( $args['M'] ) ? (int) $args['M'] : null,
				't'       => isset( $args['T'] ) && is_string( $args['T'] ) ? $args['T'] : null,
				'z'       => isset( $args['Z'] ) && is_numeric( $args['Z'] ) ? (int) $args['Z'] : null,
				'w'       => isset( $args['W'] ) && is_string( $args['W'] ) ? $args['W'] : null,
				'variant' => isset( $args['form'] ) && is_string( $args['form'] ) ? $args['form'] : '',
			),
		);
	}

	/**
	 * Suggestions without package D2: the customers who typed this transaction id, then the payer's orders that are
	 * still waiting. Never ready: the gates are D2's.
	 *
	 * @param array $row Transfer row.
	 * @return array[]
	 */
	private static function fallback_suggestions( array $row ): array {
		$plugin = Module::plugin();
		$out    = array();
		try {
			if ( ! empty( $row['provider_txn_id'] ) && in_array( (string) $row['provider'], TxnId::CLAIM_PROVIDERS, true ) ) {
				foreach ( $plugin->orders_index()->claimants( (string) $row['provider_txn_id'] ) as $order ) {
					$out[] = array(
						'order_id' => (int) $order['order_id'],
						'claimant' => true,
						'dead'     => ! in_array( $order['state'], array( 'open', 'partial', 'expired' ), true ),
					);
				}
			}
			if ( ! empty( $row['payer_key'] ) ) {
				$money = (int) $row['money_at'];
				foreach ( $plugin->orders_index()->for_payer( (string) $row['payer_key'], $money - 86400, time() ) as $order ) {
					if ( in_array( $order['state'], array( 'open', 'partial', 'expired' ), true ) ) {
						$out[] = array( 'order_id' => (int) $order['order_id'] );
					}
				}
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}
		return $out;
	}

	/*
	 * ---------------------------------------------------------------------------------------------------------------
	 * Actions.
	 * ---------------------------------------------------------------------------------------------------------------
	 */

	/**
	 * Manual attach (admin-post qersh_wc_transfer_attach). The order is chosen by its id or displayed number
	 * (wc_order_search()), by the payer's number, or by a suggestion's button. A transaction id that already paid an
	 * order, or a transfer that arrived before the order was created, needs an explicit confirmation first.
	 */
	public static function handle_attach() {
		Module::check_action( 'qersh_wc_transfer_attach' );
		check_admin_referer( 'qersh_wc_transfer_attach' );
		$plugin      = Module::plugin();
		$transfer_id = isset( $_POST['transfer'] ) ? self::clean_transfer_id( sanitize_text_field( wp_unslash( $_POST['transfer'] ) ) ) : '';
		$order_id    = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		$term        = isset( $_POST['order'] ) ? sanitize_text_field( wp_unslash( $_POST['order'] ) ) : '';
		$confirmed   = ! empty( $_POST['confirm'] );
		$view        = isset( $_POST['view'] ) ? sanitize_key( wp_unslash( $_POST['view'] ) ) : 'needs_decision';
		$back        = array( 'view' => in_array( $view, self::VIEWS, true ) ? $view : 'needs_decision' );

		$row = '' === $transfer_id ? null : $plugin->transfers()->get( $transfer_id );
		if ( null === $row ) {
			Module::flash( 'error', esc_html__( 'This transfer was not found.', 'qersh-for-woocommerce' ) );
			Module::redirect( 'transfers', $back );
		}
		if ( $order_id <= 0 ) {
			$order_id = self::resolve_order( $term );
		}
		$order = $order_id > 0 ? wc_get_order( $order_id ) : null;
		if ( ! $order instanceof \WC_Order ) {
			// Nothing found, or a refund's id (a WC_Order_Refund has no payment method).
			/* translators: %s: what the merchant typed. */
			Module::flash( 'error', sprintf( esc_html__( 'No order of this store was found for “%s”. Enter the order number, or the number the customer transferred from.', 'qersh-for-woocommerce' ), esc_html( '' !== $term ? $term : '#' . $order_id ) ) );
			Module::redirect( 'transfers', $back );
		}
		if ( 'qersh' !== $order->get_payment_method() ) {
			/* translators: %s: order number. */
			Module::flash( 'error', sprintf( esc_html__( 'Order %s was not placed with the wallet transfer method, so a transfer cannot be attached to it.', 'qersh-for-woocommerce' ), View::ltr( '#' . $order->get_order_number() ) ) );
			Module::redirect( 'transfers', $back );
		}
		if ( ! $confirmed && ! empty( self::attach_warnings( $row, $order ) ) ) {
			Module::redirect(
				'transfers',
				array_merge(
					$back,
					array(
						'attach' => $transfer_id,
						'order'  => $order->get_id(),
					)
				)
			);
		}
		if ( ! class_exists( Orders::class ) || ! method_exists( Orders::class, 'apply_attach' ) ) {
			Module::flash( 'error', esc_html__( 'Attaching transfers is not available in this copy of the plugin.', 'qersh-for-woocommerce' ) );
			Module::redirect( 'transfers', $back );
		}
		try {
			$result = $plugin->orders()->apply_attach( $transfer_id, (int) $order->get_id(), get_current_user_id() );
		} catch ( \Throwable $e ) {
			$plugin->logger()->error( 'Qersh could not attach a transfer to an order.', array( 'error' => get_class( $e ) ) );
			$result = new \WP_Error( 'qersh_attach_failed', __( 'The transfer could not be attached. Try again in a moment.', 'qersh-for-woocommerce' ) );
		}
		if ( is_wp_error( $result ) ) {
			Module::flash( 'error', esc_html( $result->get_error_message() ) );
		} else {
			/* translators: %s: order number. */
			Module::flash( 'success', sprintf( esc_html__( 'The transfer was attached to order %s. The phone hears about it with its next delivery.', 'qersh-for-woocommerce' ), View::order_link( (int) $order->get_id() ) ) );
		}
		Module::redirect( 'transfers', $back );
	}

	/**
	 * "Dismiss" (admin-post qersh_wc_transfer_dismiss): a transfer that is not for the store (a gift, a personal
	 * transfer). The phone hears `ignored` with its next delivery.
	 */
	public static function handle_dismiss() {
		Module::check_action( 'qersh_wc_transfer_dismiss' );
		check_admin_referer( 'qersh_wc_transfer_dismiss' );
		$plugin      = Module::plugin();
		$transfer_id = isset( $_POST['transfer'] ) ? self::clean_transfer_id( sanitize_text_field( wp_unslash( $_POST['transfer'] ) ) ) : '';
		$view        = isset( $_POST['view'] ) ? sanitize_key( wp_unslash( $_POST['view'] ) ) : 'needs_decision';
		$back        = array( 'view' => in_array( $view, self::VIEWS, true ) ? $view : 'needs_decision' );
		if ( '' === $transfer_id || null === $plugin->transfers()->get( $transfer_id ) ) {
			Module::flash( 'error', esc_html__( 'This transfer was not found.', 'qersh-for-woocommerce' ) );
			Module::redirect( 'transfers', $back );
		}
		if ( ! class_exists( Orders::class ) || ! method_exists( Orders::class, 'dismiss' ) ) {
			Module::flash( 'error', esc_html__( 'Dismissing transfers is not available in this copy of the plugin.', 'qersh-for-woocommerce' ) );
			Module::redirect( 'transfers', $back );
		}
		try {
			$result = $plugin->orders()->dismiss( $transfer_id, get_current_user_id() );
		} catch ( \Throwable $e ) {
			$plugin->logger()->error( 'Qersh could not dismiss a transfer.', array( 'error' => get_class( $e ) ) );
			$result = new \WP_Error( 'qersh_dismiss_failed', __( 'The transfer could not be dismissed. Try again in a moment.', 'qersh-for-woocommerce' ) );
		}
		if ( is_wp_error( $result ) ) {
			Module::flash( 'error', esc_html( $result->get_error_message() ) );
		} else {
			Module::flash( 'success', esc_html__( 'The transfer was dismissed. The phone hears about it with its next delivery.', 'qersh-for-woocommerce' ) );
		}
		Module::redirect( 'transfers', $back );
	}

	/**
	 * "Mark as decided" (admin-post qersh_wc_transfer_resolve): a transfer that needs a decision but is already
	 * linked to an order (overpaid, flagged as forged after it paid its order, a paid order cancelled afterwards, a
	 * partial payment past its deadline) leaves "Needs a decision" once the merchant has dealt with it on the order.
	 * The link to the order stays. Orders::resolve() of package D2 decides under the transfer lock.
	 */
	public static function handle_resolve() {
		Module::check_action( 'qersh_wc_transfer_resolve' );
		check_admin_referer( 'qersh_wc_transfer_resolve' );
		$plugin      = Module::plugin();
		$transfer_id = isset( $_POST['transfer'] ) ? self::clean_transfer_id( sanitize_text_field( wp_unslash( $_POST['transfer'] ) ) ) : '';
		$view        = isset( $_POST['view'] ) ? sanitize_key( wp_unslash( $_POST['view'] ) ) : 'needs_decision';
		$back        = array( 'view' => in_array( $view, self::VIEWS, true ) ? $view : 'needs_decision' );
		$row         = '' === $transfer_id ? null : $plugin->transfers()->get( $transfer_id );
		if ( null === $row ) {
			Module::flash( 'error', esc_html__( 'This transfer was not found.', 'qersh-for-woocommerce' ) );
			Module::redirect( 'transfers', $back );
		}
		if ( ! self::is_resolvable( $row ) ) {
			Module::flash( 'info', esc_html__( 'This transfer does not need a decision any more.', 'qersh-for-woocommerce' ) );
			Module::redirect( 'transfers', $back );
		}
		if ( ! self::can_resolve() ) {
			Module::flash( 'error', esc_html__( 'Marking transfers as decided is not available in this copy of the plugin.', 'qersh-for-woocommerce' ) );
			Module::redirect( 'transfers', $back );
		}
		try {
			$result = $plugin->orders()->resolve( $transfer_id, get_current_user_id() );
		} catch ( \Throwable $e ) {
			$plugin->logger()->error( 'Qersh could not mark a transfer as decided.', array( 'error' => get_class( $e ) ) );
			$result = new \WP_Error( 'qersh_resolve_failed', __( 'The transfer could not be marked as decided. Try again in a moment.', 'qersh-for-woocommerce' ) );
		}
		if ( is_wp_error( $result ) ) {
			Module::flash( 'error', esc_html( $result->get_error_message() ) );
		} else {
			/* translators: %s: order number. */
			Module::flash( 'success', sprintf( esc_html__( 'The transfer was marked as decided. It stays linked to order %s.', 'qersh-for-woocommerce' ), View::order_link( (int) $row['order_id'] ) ) );
		}
		Module::redirect( 'transfers', $back );
	}

	/**
	 * The warnings shown before an attach (docs/17 §4.6): the transaction id already paid another order, or the
	 * transfer arrived before the order was created.
	 *
	 * @param array     $row   Transfer row.
	 * @param \WC_Order $order Target order.
	 * @return string[] Escaped HTML.
	 */
	public static function attach_warnings( array $row, $order ): array {
		$plugin   = Module::plugin();
		$out      = array();
		$order_id = (int) $order->get_id();
		$number   = View::ltr( '#' . $order->get_order_number() );
		try {
			if ( ! empty( $row['provider_txn_id'] ) ) {
				foreach ( $plugin->transfers()->find_by_txn( (string) $row['provider'], (string) $row['provider_txn_id'] ) as $other ) {
					if ( $other['transfer_id'] !== $row['transfer_id'] && ! empty( $other['order_id'] ) && (int) $other['order_id'] !== $order_id && in_array( $other['outcome'], array( 'matched', 'partial', 'overpaid', 'attached' ), true ) ) {
						/* translators: %s: order number. */
						$out[] = sprintf( esc_html__( 'This transaction id already paid order %s. Attach it only if you are sure this is a different transfer.', 'qersh-for-woocommerce' ), View::order_link( (int) $other['order_id'] ) );
						break;
					}
				}
			}
			$index   = $plugin->orders_index()->get( $order_id );
			$created = is_array( $index ) ? (int) $index['created_at'] : 0;
			if ( $created <= 0 ) {
				$placed  = (int) $order->get_meta( '_qersh_placed_at' );
				$date    = $order->get_date_created();
				$created = $placed > 0 ? $placed : ( $date ? (int) $date->getTimestamp() : 0 );
			}
			if ( $created > 0 && isset( $row['money_at'] ) && (int) $row['money_at'] < $created ) {
				/* translators: %s: order number. */
				$out[] = sprintf( esc_html__( 'This transfer arrived before order %s was created, so attach it only if you are sure it is for that order.', 'qersh-for-woocommerce' ), $number );
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}
		return $out;
	}

	/**
	 * The confirmation box of an attach that has warnings.
	 *
	 * @param string $transfer_id Transfer id.
	 * @param int    $order_id    Order id.
	 * @return array|null
	 */
	private static function confirm_args( string $transfer_id, int $order_id ): ?array {
		$plugin = Module::plugin();
		try {
			$row = $plugin->transfers()->get( $transfer_id );
		} catch ( \Throwable $e ) {
			$row = null;
		}
		$order = wc_get_order( $order_id );
		if ( null === $row || ! $order instanceof \WC_Order ) {
			return null;
		}
		$warnings = self::attach_warnings( $row, $order );
		if ( empty( $warnings ) ) {
			$warnings[] = esc_html__( 'Check the payer\'s name and the amount in the wallet app before you attach it.', 'qersh-for-woocommerce' );
		}
		return array(
			'transfer' => $transfer_id,
			'order_id' => $order_id,
			'order'    => View::order_link( $order_id ),
			'amount'   => View::money( isset( $row['amount_minor'] ) ? $row['amount_minor'] : null ),
			'warnings' => $warnings,
		);
	}

	/**
	 * The order a merchant typed (docs/17 §4.6): the number customers see (via wc_order_search(), which sequential
	 * numbering plugins hook), an order id, or the payer's number (their latest order still waiting). Never the
	 * first hit of wc_order_search()'s full-text search (names, addresses, emails, items): a typo would then pick
	 * an unrelated order and attach the money to it.
	 *
	 * @param string $term What the merchant typed.
	 * @return int Order id, or 0.
	 */
	public static function resolve_order( string $term ): int {
		$term = trim( $term );
		if ( '' === $term ) {
			return 0;
		}
		$clean = ltrim( $term, '#' );
		if ( '' === $clean ) {
			return 0;
		}
		if ( function_exists( 'wc_order_search' ) ) {
			foreach ( array_map( 'intval', (array) wc_order_search( $clean ) ) as $id ) {
				$order = wc_get_order( $id );
				if ( $order instanceof \WC_Order && (string) $order->get_order_number() === $clean ) {
					return (int) $id;
				}
			}
		}
		if ( 1 === preg_match( '/^[0-9]+$/', $clean ) ) {
			$order = wc_get_order( (int) $clean );
			if ( $order instanceof \WC_Order ) {
				return (int) $order->get_id();
			}
		}
		$payer = Payer::normalize( $term );
		if ( null !== $payer ) {
			try {
				$rows = Module::plugin()->orders_index()->for_payer( $payer, 0, time() + YEAR_IN_SECONDS );
			} catch ( \Throwable $e ) {
				$rows = array();
			}
			foreach ( array_reverse( $rows ) as $row ) {
				if ( in_array( $row['state'], array( 'open', 'partial', 'expired', 'review' ), true ) ) {
					return (int) $row['order_id'];
				}
			}
		}
		return 0;
	}

	/**
	 * A transfer id as the store keeps it, or ''. Pure.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	public static function clean_transfer_id( string $value ): string {
		return 1 === preg_match( '/^[A-Za-z0-9_-]{1,40}$/', $value ) ? $value : '';
	}
}
