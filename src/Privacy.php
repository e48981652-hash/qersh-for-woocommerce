<?php
/**
 * Privacy: the policy text, the personal data exporter and eraser, and WooCommerce's order privacy hooks
 * (docs/17 §4.1, §4.3, §4.10).
 *
 * @package QershPay\WooCommerce
 */

namespace QershPay\WooCommerce;

use QershPay\WooCommerce\Core\Payer;

defined( 'ABSPATH' ) || exit;

/**
 * What the plugin keeps about a customer:
 * - on the order (meta `_qersh_payer_key`, `_qersh_claim_txn`, and its qersh_orders row): the number or InstaPay
 *   address they transfer from and the transaction id they entered, in its canonical form only;
 * - in qersh_transfers: the payer's number or address and name as the wallet's message printed them.
 *
 * WooCommerce's own exporter and eraser handle orders; our hooks add the order's Qersh data to them. Our exporter and
 * eraser cover the transfers of the customer's payer numbers that are not tied to one of their orders.
 */
final class Privacy {

	/**
	 * Orders read per exporter or eraser page.
	 */
	const PAGE = 10;

	/**
	 * Payer keys exported per exporter page.
	 */
	const KEYS_PER_PAGE = 5;

	/**
	 * At most this many pages of orders are read to collect a customer's payer keys (5,000 orders).
	 */
	const MAX_ORDER_PAGES = 500;

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
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'erasers' ) );
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
		$days = 90;
		try {
			$days = (int) self::plugin()->config()['store']['unmatched_retention_days'];
		} catch ( \Throwable $e ) {
			$days = 90;
		}
		$paragraphs = array(
			__( 'When you pay by wallet transfer, we ask for the wallet number or InstaPay address you transfer from, so we can recognize your transfer. We store it with your order.', 'qersh-for-woocommerce' ),
			__( 'Our store\'s phone, running the Qersh app, reads the confirmation message of each transfer it receives and sends us directly the amount, the payer\'s number or InstaPay address and name as the message prints them, the operator\'s transaction id and the time. If you enter the transaction id of your transfer on your order page, we keep that id only, never the text you pasted. All of this is stored in our own database only: it does not pass through Qersh or any other outside service.', 'qersh-for-woocommerce' ),
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
			__( 'This data is included when you ask for your personal data, and erased when you ask us to erase it.', 'qersh-for-woocommerce' ),
		);

		$html = '';
		foreach ( $paragraphs as $paragraph ) {
			$html .= '<p class="privacy-policy-tutorial">' . esc_html( $paragraph ) . '</p>';
		}
		wp_add_privacy_policy_content( 'Qersh for WooCommerce', wp_kses_post( $html ) );
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
	 * Payer keys of an order: what the customer entered, and their billing phone normalised.
	 *
	 * @param \WC_Order $order Order.
	 * @return string[]
	 */
	private static function payer_keys( $order ): array {
		$keys = array();
		$meta = (string) $order->get_meta( '_qersh_payer_key' );
		if ( '' !== $meta ) {
			$keys[] = $meta;
		}
		try {
			$row = self::plugin()->tables_ready() ? self::plugin()->orders_index()->get( (int) $order->get_id() ) : null;
			if ( is_array( $row ) && '' !== (string) $row['payer_key'] ) {
				$keys[] = (string) $row['payer_key'];
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}
		$phone = Payer::normalize( (string) $order->get_billing_phone() );
		if ( null !== $phone ) {
			$keys[] = $phone;
		}
		return array_values( array_unique( $keys ) );
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
	 * Every payer key of the customer's orders paid by wallet transfer, each once, sorted.
	 *
	 * @param string $email Email address.
	 * @return string[]
	 */
	private static function all_payer_keys( string $email ): array {
		$keys = array();
		for ( $page = 1; $page <= self::MAX_ORDER_PAGES; $page++ ) {
			$orders = self::orders_of( $email, $page );
			foreach ( $orders as $order ) {
				foreach ( self::payer_keys( $order ) as $key ) {
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
	 * Our exporter: transfers from the customer's payer numbers that paid no order of theirs. The pages go through
	 * the customer's payer keys, not their orders, so a number used on many orders is exported once.
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
					'group_description' => __( 'Transfers the store\'s phone received from your wallet number or InstaPay address that paid no order.', 'qersh-for-woocommerce' ),
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
	 * Our eraser: payer number and name of those transfers.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page from 1.
	 * @return array
	 */
	public static function erase( $email, $page = 1 ) {
		$removed = false;
		$orders  = self::orders_of( (string) $email, (int) $page );
		if ( self::plugin()->tables_ready() ) {
			foreach ( $orders as $order ) {
				foreach ( self::payer_keys( $order ) as $key ) {
					foreach ( self::loose_transfers( $key ) as $row ) {
						try {
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
		}
		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => count( $orders ) < self::PAGE,
		);
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
				'name'  => __( 'Wallet number or InstaPay address you transfer from', 'qersh-for-woocommerce' ),
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
				'name'  => __( 'Payer number or InstaPay address', 'qersh-for-woocommerce' ),
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
