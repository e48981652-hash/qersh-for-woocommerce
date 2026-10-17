<?php
/**
 * POST qersh/v1/order-txn: the Orange Cash transaction id a customer enters on the order page (docs/17 §4.10).
 *
 * @package QershPay\WooCommerce
 */

namespace QershPay\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Body `{ order_id, key, txn }`, at most 2 KB. The permission check is never __return_true: the order key, our
 * method, store.txn_entry on, and the order's chosen wallet an active Orange Cash wallet (one still in the
 * configuration, turned off or not: turning a wallet off only hides it from the checkout; a deleted wallet is gone
 * from the configuration, so its orders answer 404). Every refusal there is the
 * same 404 `qersh_not_found` body. Then Claims::submit() (package D2) runs the steps of docs/17 §4.10: the shape
 * before the database (400 by code), the same id again (200), the locks, the order's state (409), the atomic counter
 * (409 or 429), the note, and the late evaluation outside the customer's request. The answer is the same whatever is
 * behind the id, never cached, and the raw text is never stored or logged here.
 *
 * The method handle_body() is the whole route, used by the REST callback and by Webhook\Fallback
 * (`?wc-api=qersh&qo=txn`).
 */
final class ClaimRoute {

	/** Largest body accepted. */
	const MAX_BODY = 2048;

	/**
	 * Permission: the order and its wallet (see the class). While WooCommerce or the tables are missing it lets the
	 * callback answer 503.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public static function permission( $request ) {
		if ( ! OrderStatusRoute::ready() ) {
			return true;
		}
		OrderStatusRoute::nocache( 'qersh order txn' );
		return null === self::eligible_order( Plugin::instance(), OrderStatusRoute::body_of( $request ) ) ? OrderStatusRoute::not_found_error() : true;
	}

	/**
	 * The REST callback.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function rest( $request ) {
		if ( ! OrderStatusRoute::ready() ) {
			return Plugin::unavailable_response();
		}
		// The permission check ran first, so a body this large reaches here only for an eligible order.
		$body = OrderStatusRoute::body_of( $request );
		if ( strlen( (string) $request->get_body() ) > self::MAX_BODY ) {
			return OrderStatusRoute::response( OrderStatusRoute::result( 400, array( 'code' => 'qersh_txn_too_long' ) ) );
		}
		return OrderStatusRoute::response( self::handle_body( $body ) );
	}

	/**
	 * The route.
	 *
	 * @param array $body Decoded body.
	 * @return array `[ 'http' => int, 'body' => array, 'headers' => array ]`
	 */
	public static function handle_body( array $body ): array {
		if ( ! OrderStatusRoute::ready() ) {
			return OrderStatusRoute::result( 503, array( 'code' => 'qersh_unavailable' ) );
		}
		OrderStatusRoute::nocache( 'qersh order txn' );
		$plugin = Plugin::instance();
		$order  = self::eligible_order( $plugin, $body );
		if ( null === $order ) {
			return OrderStatusRoute::result( 404, OrderStatusRoute::not_found_body() );
		}
		if ( self::too_big( $body ) ) {
			return OrderStatusRoute::result( 400, array( 'code' => 'qersh_txn_too_long' ) );
		}
		if ( ! class_exists( Claims::class ) ) {
			return OrderStatusRoute::result( 503, array( 'code' => 'qersh_unavailable' ) );
		}

		try {
			$out = $plugin->claims()->submit( $order, array_key_exists( 'txn', $body ) ? $body['txn'] : null, time() );
		} catch ( \Throwable $e ) {
			$plugin->logger()->error(
				'Qersh could not save a transaction id.',
				array(
					'order' => $order->get_id(),
					'error' => get_class( $e ),
				)
			);
			return OrderStatusRoute::result( 500, array( 'code' => 'qersh_txn_failed' ) );
		}

		$http    = is_array( $out ) && isset( $out['http'] ) ? (int) $out['http'] : 500;
		$answer  = is_array( $out ) && isset( $out['body'] ) && is_array( $out['body'] ) ? $out['body'] : array( 'code' => 'qersh_txn_failed' );
		$headers = array();
		if ( is_array( $out ) && isset( $out['retry_after'] ) && is_numeric( $out['retry_after'] ) && (int) $out['retry_after'] > 0 ) {
			$headers['Retry-After'] = (string) (int) $out['retry_after'];
		}
		return OrderStatusRoute::result( $http, $answer, $headers );
	}

	/**
	 * The order of a body when a transaction id may be entered for it; null otherwise (every case is one 404).
	 *
	 * @param Plugin $plugin The plugin.
	 * @param array  $body   Decoded body.
	 * @return \WC_Order|null
	 */
	public static function eligible_order( Plugin $plugin, array $body ) {
		$order = OrderStatusRoute::find_order( $body );
		if ( null === $order ) {
			return null;
		}
		$row    = Checkout::order_row( $plugin, $order->get_id() );
		$wallet = $plugin->wallet( Checkout::order_wallet_id( $order, $row ) );
		return Checkout::txn_entry_open( $plugin, $wallet ) ? $order : null;
	}

	/**
	 * Whether a decoded body is over the size limit (for callers that only have the decoded body). Pure.
	 *
	 * @param array $body Decoded body.
	 * @return bool
	 */
	public static function too_big( array $body ): bool {
		$json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) : json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- unit tests run without WordPress.
		return ! is_string( $json ) || strlen( $json ) > self::MAX_BODY;
	}
}
