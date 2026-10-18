<?php
/**
 * POST qersh/v1/order-status: the live status line of the thank-you page.
 *
 * @package QershPay\WooCommerce
 */

namespace QershPay\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Body `{ order_id, key }`. POST, never GET: LiteSpeed Cache stores every visitor GET to REST for 7 days by default
 * (docs/16 §7.3). The order key must be valid and the method ours; every refusal is the same 404 body. The answer is
 * `{ "status": waiting|partial|paid|review|expired|cancelled }` from qersh_orders.state, or from the WooCommerce
 * status where the merchant settled the order by hand (Checkout::status_of()), plus `claim` (whether a
 * transaction id is saved) for an Orange Cash order: a fact the customer knows, which says nothing about transfers.
 * Never cached (wc_nocache_headers(), litespeed_control_set_nocache).
 *
 * The method handle_body() is the whole route, used by the REST callback and by Webhook\Fallback
 * (`?wc-api=qersh&qo=status`).
 */
final class OrderStatusRoute {

	/** Code of the one refusal. */
	const NOT_FOUND = 'qersh_not_found';

	/** Longest order key read (WooCommerce keys are `wc_order_` and 13 characters). */
	const KEY_MAX = 64;

	/**
	 * Permission: the order key and the method. A WP_Error with the same 404 body handle_body() gives. While
	 * WooCommerce or the tables are missing it lets the callback answer 503.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public static function permission( $request ) {
		if ( ! self::ready() ) {
			return true;
		}
		self::nocache( 'qersh order status' );
		return null === self::find_order( self::body_of( $request ) ) ? self::not_found_error() : true;
	}

	/**
	 * The REST callback.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function rest( $request ) {
		if ( ! self::ready() ) {
			return Plugin::unavailable_response();
		}
		return self::response( self::handle_body( self::body_of( $request ) ) );
	}

	/**
	 * The route.
	 *
	 * @param array $body Decoded body.
	 * @return array `[ 'http' => int, 'body' => array, 'headers' => array ]`
	 */
	public static function handle_body( array $body ): array {
		if ( ! self::ready() ) {
			return self::result( 503, array( 'code' => 'qersh_unavailable' ) );
		}
		self::nocache( 'qersh order status' );
		$order = self::find_order( $body );
		if ( null === $order ) {
			return self::result( 404, self::not_found_body() );
		}
		$plugin = Plugin::instance();
		$row    = Checkout::order_row( $plugin, $order->get_id() );
		$state  = null !== $row && isset( $row['state'] ) ? (string) $row['state'] : null;
		$out    = array( 'status' => Checkout::status_of( $state, (string) $order->get_status(), Checkout::completing( $row ) ) );

		$wallet = $plugin->wallet( Checkout::order_wallet_id( $order, $row ) );
		if ( null !== $wallet && isset( $wallet['provider'] ) && 'orange_cash' === $wallet['provider'] ) {
			$out['claim'] = null !== $row && ! empty( $row['claim_txn'] );
		}
		return self::result( 200, $out );
	}

	/**
	 * The order a body names, when its key is valid and its method is ours; null otherwise.
	 *
	 * @param array $body Decoded body.
	 * @return \WC_Order|null
	 */
	public static function find_order( array $body ) {
		$order_id = self::order_id_of( $body );
		$key      = isset( $body['key'] ) && is_string( $body['key'] ) ? $body['key'] : '';
		if ( $order_id <= 0 || '' === $key || strlen( $key ) > self::KEY_MAX ) {
			return null;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order || ! $order->key_is_valid( $key ) ) {
			return null;
		}
		return Checkout::METHOD === $order->get_payment_method() ? $order : null;
	}

	/**
	 * The order id of a body: a positive integer, or digits. Pure.
	 *
	 * @param array $body Decoded body.
	 * @return int
	 */
	public static function order_id_of( array $body ): int {
		if ( ! isset( $body['order_id'] ) ) {
			return 0;
		}
		$id = $body['order_id'];
		if ( is_int( $id ) ) {
			return max( 0, $id );
		}
		if ( is_string( $id ) && 1 === preg_match( '/^[0-9]{1,19}$/D', $id ) ) {
			return (int) $id;
		}
		return 0;
	}

	/**
	 * Whether WooCommerce and our tables are in place.
	 *
	 * @return bool
	 */
	public static function ready(): bool {
		$plugin = Plugin::instance();
		return $plugin->wc_ready() && $plugin->tables_ready();
	}

	/**
	 * The decoded body of a REST request: JSON (whatever its content type says), else form fields.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 */
	public static function body_of( $request ): array {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$decoded = json_decode( (string) $request->get_body(), true );
			$params  = is_array( $decoded ) ? $decoded : $request->get_body_params();
		}
		return is_array( $params ) ? $params : array();
	}

	/**
	 * The one refusal body: what WordPress prints for not_found_error(), so the REST and the fallback answers are
	 * the same bytes.
	 *
	 * @return array
	 */
	public static function not_found_body(): array {
		return array(
			'code'    => self::NOT_FOUND,
			'message' => self::not_found_message(),
			'data'    => array( 'status' => 404 ),
		);
	}

	/**
	 * The one refusal as a WP_Error.
	 *
	 * @return \WP_Error
	 */
	public static function not_found_error() {
		return new \WP_Error( self::NOT_FOUND, self::not_found_message(), array( 'status' => 404 ) );
	}

	/**
	 * The refusal text.
	 *
	 * @return string
	 */
	private static function not_found_message(): string {
		return __( 'Order not found.', 'qersh-for-woocommerce' );
	}

	/**
	 * A route result with the no-cache headers.
	 *
	 * @param int   $http    Status.
	 * @param array $body    Body.
	 * @param array $headers More headers.
	 * @return array
	 */
	public static function result( int $http, array $body, array $headers = array() ): array {
		return array(
			'http'    => $http,
			'body'    => $body,
			'headers' => array_merge( self::nocache_headers(), $headers ),
		);
	}

	/**
	 * The headers that keep a page cache or a CDN from storing the answer.
	 *
	 * @return array<string, string>
	 */
	public static function nocache_headers(): array {
		$headers = function_exists( 'wp_get_nocache_headers' ) ? wp_get_nocache_headers() : array();
		unset( $headers['Last-Modified'] );
		$headers['Cache-Control']             = 'no-cache, must-revalidate, max-age=0, no-store, private';
		$headers['X-LiteSpeed-Cache-Control'] = 'no-cache';
		return $headers;
	}

	/**
	 * Tells WooCommerce, page caches and LiteSpeed Cache not to store this request.
	 *
	 * @param string $reason Reason passed to LiteSpeed Cache.
	 */
	public static function nocache( string $reason ): void {
		if ( function_exists( 'wc_nocache_headers' ) ) {
			wc_nocache_headers();
		}
		/**
		 * LiteSpeed Cache's own action: this response is not to be cached. Nothing listens without LiteSpeed Cache.
		 *
		 * @since 0.1.0
		 *
		 * @param string $reason Reason, shown in LiteSpeed Cache's debug log.
		 */
		do_action( 'litespeed_control_set_nocache', $reason ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache's own hook.
	}

	/**
	 * A REST response from a route result.
	 *
	 * @param array $out From handle_body().
	 * @return \WP_REST_Response
	 */
	public static function response( array $out ): \WP_REST_Response {
		$response = new \WP_REST_Response( $out['body'], (int) $out['http'] );
		foreach ( $out['headers'] as $name => $value ) {
			$response->header( (string) $name, (string) $value );
		}
		return $response;
	}
}
