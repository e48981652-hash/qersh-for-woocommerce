<?php
/**
 * Privacy: the policy text, the personal data exporter and eraser, and WooCommerce's order privacy hooks
 * (docs/17 §4.1, §4.3, §4.10).
 *
 * @package QershPay\WooCommerce
 */

namespace QershPay\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * What the plugin keeps about a customer:
 * - on the order (meta `_qersh_payer_key`, `_qersh_claim_txn`, and its qersh_orders row): the wallet number they
 *   transfer from and the transaction id they entered, in its canonical form only;
 * - in qersh_transfers: the payer's number and name as the wallet's message printed them.
 *
 * WooCommerce's own exporter and eraser handle orders; our hooks add the order's Qersh data to them, the transfers
 * that paid the order included. Our exporter and eraser cover the transfers that paid none of the customer's orders,
 * but only from a number proven to be theirs: one that a transfer linked to one of their orders came from (the phone
 * saw it pay the order, or the merchant attached it). The number a customer types at checkout and their billing
 * phone prove nothing (anyone can type anyone's number), so they never select another payer's transfers.
 */
final class Privacy {

	/**
	 * Orders read per page when the customer's payer numbers are collected.
	 */
	const PAGE = 10;

	/**
	 * Payer keys covered per exporter or eraser page.
	 */
	const KEYS_PER_PAGE = 5;

	/**
	 * At most this many pages of orders are read to collect a customer's payer keys (5,000 orders).
	 */
	const MAX_ORDER_PAGES = 500;

	/**
	 * Outcomes of a transfer linked to an order that it paid: matched by the phone's message, or attached by the
	 * merchant. Its payer number is then the customer's.
	 */
	const PAYING_OUTCOMES = array( 'matched', 'partial', 'overpaid', 'attached' );

	/**
	 * Priority of our eraser on wp_privacy_personal_data_erasers. WooCommerce adds its erasers at 10, and its order
	 * eraser anonymises the billing email and customer id that orders_of() finds orders by, and fires erase_order(),
	 * which blanks the payer number of the transfers that prove the customer's numbers; so ours must run first.
	 */
	const ERASER_PRIORITY = 5;

	/**
	 * The plugin.
	 *
	 * @var Plugin|null
	 */
	private static $plugin = null;

	/**
	 * Registers the hooks.
	 *
	 * @param Plugin $plugin The plugin.
	 */
	public static function register( Plugin $plugin ): void {
		self::$plugin = $plugin;
		add_action( 'admin_init', array( __CLASS__, 'policy' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'erasers' ), self::ERASER_PRIORITY );
		add_filter( 'woocommerce_privacy_export_order_personal_data', array( __CLASS__, 'export_order' ), 10, 2 );
		add_action( 'woocommerce_privacy_remove_order_personal_data', array( __CLASS__, 'erase_order' ) );
	}

	/**
	 * The plugin.
	 *
	 * @return Plugin
	 */
	private static function plugin(): Plugin {
		if ( null === self::$plugin ) {
			self::$plugin = Plugin::instance();
		}
		return self::$plugin;
	}

	/**
	 * The suggested privacy policy text.
	 */
	public static function policy(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$days       = self::retention_days();
		$paragraphs = array(
			__( 'When you pay by wallet transfer, we ask for the wallet number you transfer from, so we can recognize your transfer. We store it with your order.', 'qersh-for-woocommerce' ),
			__( 'Our store\'s phone, running the Qersh app, reads the confirmation message of each transfer it receives and sends us directly the amount, the payer\'s number and name as the message prints them, the operator\'s transaction id and the time. If you enter the transaction id of your transfer on your order page, we keep that id only, never the text you pasted. All of this is stored in our own database only: it does not pass through Qersh or any other outside service.', 'qersh-for-woocommerce' ),
			sprintf(
				/* translators: %d: number of days. */
				_n(
					'Transfer data that belongs to an order is kept as long as the order. The payer\'s number and name of a transfer that paid no order are erased after %d day. Records of the phone\'s deliveries hold no personal data and are kept for 180 days.',
					'Transfer data that belongs to an order is kept as long as the order. The payer\'s number and name of a transfer that paid no order are erased after %d days. Records of the phone\'s deliveries hold no personal data and are kept for 180 days.',
					$days,
					'qersh-for-woocommerce'
				),
				$days
			),
			__( 'When you ask for your personal data, it includes the wallet data of your orders, and the transfers that paid none of your orders but came from a wallet number that paid one of them. When you ask us to erase your personal data, we erase the payer\'s number and name of those transfers, except a transfer we still have to settle, such as money we may need to return, whose data is erased at the end of the period above. The wallet data of an order is erased with the order\'s personal data.', 'qersh-for-woocommerce' ),
		);

		wp_add_privacy_policy_content( 'Qersh for WooCommerce', wp_kses_post( self::policy_html( __( 'Suggested text for the wallet transfer part of your privacy policy. The retention period shows the plugin\'s setting at the time you copy it, so edit the text if you change the setting later.', 'qersh-for-woocommerce' ), $paragraphs ) ) );
	}

	/**
	 * The policy section as the Policy Guide expects it (wp-admin/includes/class-wp-privacy-policy-content.php): one
	 * note for the site owner in `privacy-policy-tutorial`, which "Copy suggested policy text" leaves out, and the
	 * text to copy in plain paragraphs inside `wp-suggested-text`. Pure apart from esc_html().
	 *
	 * @param string   $note       Note for the site owner, plain text.
	 * @param string[] $paragraphs Policy paragraphs, plain text.
	 * @return string HTML.
	 */
	public static function policy_html( string $note, array $paragraphs ): string {
		$html = '<div class="wp-suggested-text">';
		if ( '' !== $note ) {
			$html .= '<p class="privacy-policy-tutorial">' . esc_html( $note ) . '</p>';
		}
		foreach ( $paragraphs as $paragraph ) {
			$html .= '<p>' . esc_html( (string) $paragraph ) . '</p>';
		}
		return $html . '</div>';
	}

	/**
	 * Adds our exporter.
	 *
	 * @param array $exporters Exporters.
	 * @return array
	 */
	public static function exporters( $exporters ) {
		$exporters                          = is_array( $exporters ) ? $exporters : array();
		$exporters['qersh-for-woocommerce'] = array(
			'exporter_friendly_name' => __( 'Wallet transfers (Qersh)', 'qersh-for-woocommerce' ),
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $exporters;
	}

	/**
	 * Adds our eraser.
	 *
	 * @param array $erasers Erasers.
	 * @return array
	 */
	public static function erasers( $erasers ) {
		$erasers                          = is_array( $erasers ) ? $erasers : array();
		$erasers['qersh-for-woocommerce'] = array(
			'eraser_friendly_name' => __( 'Wallet transfers (Qersh)', 'qersh-for-woocommerce' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * The customer's orders paid by wallet transfer, one page.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page from 1.
	 * @return \WC_Order[]
	 */
	private static function orders_of( string $email, int $page ): array {
		if ( ! function_exists( 'wc_get_orders' ) || '' === $email ) {
			return array();
		}
		$args = array(
			'limit'          => self::PAGE,
			'page'           => max( 1, $page ),
			'payment_method' => 'qersh',
			'type'           => 'shop_order',
			'orderby'        => 'ID',
			'order'          => 'ASC',
		);
		$user = get_user_by( 'email', $email );
		if ( $user ) {
			$args['customer'] = array( (int) $user->ID, $email );
		} else {
			$args['billing_email'] = $email;
		}
		$orders = wc_get_orders( $args );
		return is_array( $orders ) ? $orders : array();
	}

	/**
	 * The payer keys proven to be the customer's by one order: those of the transfers that paid it. What the customer
	 * typed at checkout and their billing phone are not used: they are only claimed.
	 *
	 * @param \WC_Order $order Order.
	 * @return string[]
	 */
	private static function proven_keys( $order ): array {
		try {
			$rows = self::plugin()->transfers()->list_page(
				array(
					'order_id' => (int) $order->get_id(),
					'outcome'  => self::PAYING_OUTCOMES,
				),
				1,
				200
			)['rows'];
		} catch ( \Throwable $e ) {
			return array();
		}
		return self::paying_keys( $rows, (int) $order->get_id() );
	}

	/**
	 * The payer keys of the transfers that paid an order, each once: linked to it, with a paying outcome, and not
	 * flagged as a suspected forgery (whoever forged the message chose the number in it). Pure.
	 *
	 * @param array[] $rows     Transfer rows.
	 * @param int     $order_id The order.
	 * @return string[]
	 */
	public static function paying_keys( array $rows, int $order_id ): array {
		$keys = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || $order_id <= 0 || ! isset( $row['order_id'] ) || (int) $row['order_id'] !== $order_id ) {
				continue;
			}
			if ( ! isset( $row['outcome'] ) || ! in_array( $row['outcome'], self::PAYING_OUTCOMES, true ) ) {
				continue;
			}
			if ( isset( $row['flag'] ) && 'forgery' === $row['flag'] ) {
				continue;
			}
			$key = isset( $row['payer_key'] ) && is_scalar( $row['payer_key'] ) ? (string) $row['payer_key'] : '';
			if ( '' !== $key ) {
				$keys[ $key ] = true;
			}
		}
		return array_map( 'strval', array_keys( $keys ) );
	}

	/**
	 * Whether the eraser may blank the payer number and name of a transfer that paid no order (erase()): a personal
	 * transfer, or one the merchant dismissed. A transfer the merchant still has to decide on keeps them, since they
	 * tell who to return the money to or which order to attach it to: one that needs a decision, a suspected forgery,
	 * an unmatched one waiting to be attached, or one still being processed. Retention erases those later
	 * (Transfers::purge_personal()). Pure.
	 *
	 * @param array $row Transfer row.
	 * @return bool
	 */
	public static function erasable( array $row ): bool {
		if ( ! empty( $row['order_id'] ) ) {
			return false;
		}
		$outcome = isset( $row['outcome'] ) ? (string) $row['outcome'] : '';
		if ( 'dismissed' === $outcome ) {
			return true;
		}
		$reason = isset( $row['reason'] ) ? (string) $row['reason'] : '';
		$flag   = isset( $row['flag'] ) ? (string) $row['flag'] : '';
		return 'unmatched' === $outcome && 'personal' === $reason && 'forgery' !== $flag;
	}

	/**
	 * Transfers of a payer key that are not tied to an order (those tied to an order go with the order's export).
	 *
	 * @param string $payer_key Payer key.
	 * @return array[]
	 */
	private static function loose_transfers( string $payer_key ): array {
		$out = array();
		try {
			$page = self::plugin()->transfers()->list_page( array( 'payer_key' => $payer_key ), 1, 200 );
			foreach ( $page['rows'] as $row ) {
				if ( empty( $row['order_id'] ) ) {
					$out[] = $row;
				}
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}
		return $out;
	}

	/**
	 * Every payer key proven to be the customer's (proven_keys()) by their orders paid by wallet transfer, each once,
	 * sorted.
	 *
	 * @param string $email Email address.
	 * @return string[]
	 */
	private static function all_payer_keys( string $email ): array {
		$keys = array();
		for ( $page = 1; $page <= self::MAX_ORDER_PAGES; $page++ ) {
			$orders = self::orders_of( $email, $page );
			foreach ( $orders as $order ) {
				foreach ( self::proven_keys( $order ) as $key ) {
					$keys[ $key ] = true;
				}
			}
			if ( count( $orders ) < self::PAGE ) {
				break;
			}
		}
		$keys = array_map( 'strval', array_keys( $keys ) );
		sort( $keys, SORT_STRING );
		return $keys;
	}

	/**
	 * The payer keys one exporter page covers, and whether it is the last page. Pure.
	 *
	 * @param string[] $keys     Every payer key, each once.
	 * @param int      $page     Page from 1.
	 * @param int      $per_page Keys per page.
	 * @return array `[ 'keys' => string[], 'done' => bool ]`
	 */
	public static function key_page( array $keys, int $page, int $per_page ): array {
		$keys     = array_values( array_unique( array_map( 'strval', $keys ) ) );
		$per_page = max( 1, $per_page );
		$start    = ( max( 1, $page ) - 1 ) * $per_page;
		return array(
			'keys' => array_slice( $keys, $start, $per_page ),
			'done' => $start + $per_page >= count( $keys ),
		);
	}

	/**
	 * Our exporter: the transfers that paid no order, from the payer numbers proven to be the customer's
	 * (proven_keys()). The pages go through those payer keys, not the orders, so a number that paid many orders is
	 * exported once. The transfers that paid an order go with WooCommerce's order export (export_order()).
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page from 1.
	 * @return array
	 */
	public static function export( $email, $page = 1 ) {
		$items = array();
		if ( ! self::plugin()->tables_ready() ) {
			return array(
				'data' => $items,
				'done' => true,
			);
		}
		$slice = self::key_page( self::all_payer_keys( (string) $email ), (int) $page, self::KEYS_PER_PAGE );
		foreach ( $slice['keys'] as $key ) {
			foreach ( self::loose_transfers( $key ) as $row ) {
				$items[] = array(
					'group_id'          => 'qersh-transfers',
					'group_label'       => __( 'Wallet transfers', 'qersh-for-woocommerce' ),
					'group_description' => __( 'Transfers the store\'s phone received that paid none of your orders, from a wallet number that paid one of them.', 'qersh-for-woocommerce' ),
					'item_id'           => 'qersh-transfer-' . (string) $row['id'],
					'data'              => self::transfer_data( $row ),
				);
			}
		}
		return array(
			'data' => $items,
			'done' => $slice['done'],
		);
	}

	/**
	 * Our eraser: the payer number and name of the transfers our exporter lists, page by page through the same payer
	 * keys, except those the merchant still has to decide on (erasable()), which are reported as retained. It runs
	 * before WooCommerce's erasers (ERASER_PRIORITY), while the transfers that prove the keys still carry them.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page from 1.
	 * @return array
	 */
	public static function erase( $email, $page = 1 ) {
		$removed  = false;
		$retained = 0;
		$done     = true;
		if ( self::plugin()->tables_ready() ) {
			$slice = self::key_page( self::all_payer_keys( (string) $email ), (int) $page, self::KEYS_PER_PAGE );
			$done  = $slice['done'];
			foreach ( $slice['keys'] as $key ) {
				foreach ( self::loose_transfers( $key ) as $row ) {
					try {
						// Read again just before the write: the merchant may have attached it meanwhile.
						$fresh = self::plugin()->transfers()->get( (string) $row['transfer_id'] );
						if ( null === $fresh ) {
							continue;
						}
						if ( ! self::erasable( $fresh ) ) {
							++$retained;
							continue;
						}
						self::plugin()->transfers()->update(
							(string) $row['transfer_id'],
							array(
								'payer_key'  => null,
								'payer_name' => null,
							)
						);
						$removed = true;
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
			}
		}
		return array(
			'items_removed'  => $removed,
			'items_retained' => $retained > 0,
			'messages'       => $retained > 0 ? array( esc_html( self::retained_message( $retained, self::retention_days() ) ) ) : array(),
			'done'           => $done,
		);
	}

	/**
	 * The eraser's message for the transfers it kept, for the administrator. Plain text.
	 *
	 * @param int $count Transfers kept.
	 * @param int $days  unmatched_retention_days.
	 * @return string
	 */
	private static function retained_message( int $count, int $days ): string {
		return sprintf(
			/* translators: %d: number of wallet transfers. */
			_n(
				'The payer number and name of %d wallet transfer were kept, because it still waits for your decision in WooCommerce → Qersh → Transfers.',
				'The payer number and name of %d wallet transfers were kept, because they still wait for your decision in WooCommerce → Qersh → Transfers.',
				$count,
				'qersh-for-woocommerce'
			),
			$count
		) . ' ' . sprintf(
			/* translators: %d: number of days. */
			_n(
				'Qersh erases them automatically %d day after the transfer arrived.',
				'Qersh erases them automatically %d days after the transfer arrived.',
				$days,
				'qersh-for-woocommerce'
			),
			$days
		);
	}

	/**
	 * The unmatched_retention_days setting (90 when it cannot be read).
	 *
	 * @return int
	 */
	private static function retention_days(): int {
		try {
			$days = (int) self::plugin()->config()['store']['unmatched_retention_days'];
		} catch ( \Throwable $e ) {
			$days = 90;
		}
		return $days > 0 ? $days : 90;
	}

	/**
	 * Adds the order's Qersh data to WooCommerce's order export.
	 *
	 * @param array     $data  Name and value pairs.
	 * @param \WC_Order $order Order.
	 * @return array
	 */
	public static function export_order( $data, $order ) {
		$data = is_array( $data ) ? $data : array();
		if ( ! is_object( $order ) || ! is_a( $order, 'WC_Order' ) || 'qersh' !== $order->get_payment_method() ) {
			return $data;
		}
		$payer = (string) $order->get_meta( '_qersh_payer_key' );
		$claim = (string) $order->get_meta( '_qersh_claim_txn' );
		try {
			$row = self::plugin()->tables_ready() ? self::plugin()->orders_index()->get( (int) $order->get_id() ) : null;
			if ( is_array( $row ) ) {
				$payer = '' !== $payer ? $payer : (string) $row['payer_key'];
				$claim = '' !== $claim ? $claim : (string) $row['claim_txn'];
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}
		if ( '' !== $payer ) {
			$data[] = array(
				'name'  => __( 'Wallet number you transfer from', 'qersh-for-woocommerce' ),
				'value' => $payer,
			);
		}
		if ( '' !== $claim ) {
			$data[] = array(
				'name'  => __( 'Transaction id you entered', 'qersh-for-woocommerce' ),
				'value' => $claim,
			);
		}
		try {
			if ( self::plugin()->tables_ready() ) {
				foreach ( self::plugin()->transfers()->list_page( array( 'order_id' => (int) $order->get_id() ), 1, 50 )['rows'] as $row ) {
					foreach ( self::transfer_data( $row ) as $pair ) {
						$data[] = $pair;
					}
				}
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}
		return $data;
	}

	/**
	 * Erases the order's Qersh data when WooCommerce erases the order's personal data: the meta, the index row
	 * (payer number and transaction id) and the payer fields of the transfers that paid it.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function erase_order( $order ): void {
		if ( ! is_object( $order ) || ! is_a( $order, 'WC_Order' ) ) {
			return;
		}
		$changed = false;
		foreach ( array( '_qersh_payer_key', '_qersh_claim_txn' ) as $key ) {
			if ( '' !== (string) $order->get_meta( $key ) ) {
				$order->delete_meta_data( $key );
				$changed = true;
			}
		}
		if ( $changed ) {
			$order->save();
		}
		try {
			if ( self::plugin()->tables_ready() ) {
				self::plugin()->orders_index()->delete( (int) $order->get_id() );
				self::plugin()->transfers()->forget_order( (int) $order->get_id() );
			}
		} catch ( \Throwable $e ) {
			self::plugin()->logger()->warning( 'Qersh could not erase the transfer data of an order.', array( 'error' => get_class( $e ) ) );
		}
	}

	/**
	 * Name and value pairs of one transfer.
	 *
	 * @param array $row Transfer row.
	 * @return array[]
	 */
	private static function transfer_data( array $row ): array {
		$out = array(
			array(
				'name'  => __( 'Transfer', 'qersh-for-woocommerce' ),
				'value' => (string) $row['transfer_id'],
			),
			array(
				'name'  => __( 'Money arrived', 'qersh-for-woocommerce' ),
				'value' => gmdate( 'Y-m-d H:i:s', (int) $row['money_at'] ) . ' UTC',
			),
		);
		if ( null !== $row['amount_minor'] ) {
			$out[] = array(
				'name'  => __( 'Amount', 'qersh-for-woocommerce' ),
				'value' => number_format( (int) $row['amount_minor'] / 100, 2, '.', '' ) . ' ' . (string) $row['currency'],
			);
		}
		if ( ! empty( $row['payer_key'] ) ) {
			$out[] = array(
				'name'  => __( 'Payer number', 'qersh-for-woocommerce' ),
				'value' => (string) $row['payer_key'],
			);
		}
		if ( ! empty( $row['payer_name'] ) ) {
			$out[] = array(
				'name'  => __( 'Payer name', 'qersh-for-woocommerce' ),
				'value' => (string) $row['payer_name'],
			);
		}
		if ( ! empty( $row['provider_txn_id'] ) ) {
			$out[] = array(
				'name'  => __( 'Operator transaction id', 'qersh-for-woocommerce' ),
				'value' => (string) $row['provider_txn_id'],
			);
		}
		return $out;
	}
}
