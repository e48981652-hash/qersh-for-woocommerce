<?php
/**
 * The fallback address `?wc-api=qersh`, for stores whose REST API is blocked or rewritten (docs/17 §4.1, §4.4
 * step 1, §4.10).
 *
 * @package QershPay\WooCommerce
 */

namespace QershPay\WooCommerce\Webhook;

use QershPay\WooCommerce\ClaimRoute;
use QershPay\WooCommerce\OrderStatusRoute;
use QershPay\WooCommerce\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Hooked on parse_request at priority -1 from the main plugin file, before the WooCommerce check, so the promise of
 * a 503 JSON answer holds when WooCommerce is off (never through woocommerce_api_qersh, which needs WooCommerce):
 * - `?wc-api=qersh&qw=<wallet>`: the webhook, through Controller with the form `wc-api`;
 * - `?wc-api=qersh&qo=status` and `&qo=txn`: the two storefront routes (package C) with the JSON body.
 * POST only (405 otherwise). Always JSON with no-cache headers, and always ends the request.
 */
final class Fallback {

	/**
	 * Request headers the webhook reads, as $_SERVER names them.
	 */
	const SERVER_HEADERS = array(
		'HTTP_X_QERSH_SIGNATURE'  => 'x-qersh-signature',
		'HTTP_X_QERSH_DELIVERY'   => 'x-qersh-delivery',
		'HTTP_X_QERSH_ATTEMPT'    => 'x-qersh-attempt',
		'HTTP_X_QERSH_REDELIVERY' => 'x-qersh-redelivery',
		'HTTP_X_QERSH_WALLET'     => 'x-qersh-wallet',
	);

	/**
	 * On parse_request (priority -1): answers our fallback address and exits; leaves every other request alone.
	 *
	 * @param \WP $wp The request.
	 */
	public static function maybe_handle( \WP $wp ): void {
		unset( $wp );
		// The webhook is signed (HMAC) and the storefront routes check the order key; there is no session for a nonce.
		$wc_api = isset( $_GET['wc-api'] ) ? sanitize_key( wp_unslash( $_GET['wc-api'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- signed webhook or order-key route, see above.
		if ( 'qersh' !== $wc_api ) {
			return;
		}
		$qw = isset( $_GET['qw'] ) ? sanitize_key( wp_unslash( $_GET['qw'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- signed webhook or order-key route, see above.
		$qo = isset( $_GET['qo'] ) ? sanitize_key( wp_unslash( $_GET['qo'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- signed webhook or order-key route, see above.

		try {
			$result = self::answer( self::target( $qw, $qo ) );
		} catch ( \Throwable $e ) {
			Controller::log_throwable( 'fallback', $e );
			$result = Controller::after_throwable( ! Controller::found_unready() );
		}
		self::send( $result );
	}

	/**
	 * What the address asks for. Pure.
	 *
	 * @param string $qw Wallet id from `qw` (sanitized).
	 * @param string $qo Route from `qo` (sanitized).
	 * @return array `[ 'kind' => 'webhook', 'wallet' => string ]` (an empty wallet when the id does not match the
	 *               pattern, which answers 404 qersh_unknown_wallet), `[ 'kind' => 'status' ]`, `[ 'kind' => 'txn' ]`
	 *               or `[ 'kind' => 'none' ]`.
	 */
	public static function target( string $qw, string $qo ): array {
		if ( '' !== $qw ) {
			return array(
				'kind'   => 'webhook',
				'wallet' => 1 === preg_match( '/^[a-z0-9]{12}$/', $qw ) ? $qw : '',
			);
		}
		if ( 'status' === $qo || 'txn' === $qo ) {
			return array( 'kind' => $qo );
		}
		return array( 'kind' => 'none' );
	}

	/**
	 * The X-Qersh-* request headers from server variables (as server_headers() read them), with lower-case names.
	 * Only string values are kept, as they are. Pure.
	 *
	 * @param array $server Server variables.
	 * @return array<string, string>
	 */
	public static function qersh_headers( array $server ): array {
		$out = array();
		foreach ( self::SERVER_HEADERS as $variable => $name ) {
			if ( isset( $server[ $variable ] ) && is_string( $server[ $variable ] ) ) {
				$out[ $name ] = $server[ $variable ];
			}
		}
		return $out;
	}

	/**
	 * The server variables of SERVER_HEADERS, and only those, unslashed. X-Qersh-Signature is kept as it came: it is
	 * the input of the HMAC check (Verifier parses it strictly and compares each v1 value with hash_equals()), never
	 * stored or output, and sanitizing could turn a malformed header into a well-formed one. The others go through
	 * sanitize_text_field() and are validated again where they are read (Envelope::delivery_headers()).
	 *
	 * @return array<string, string>
	 */
	private static function server_headers(): array {
		$out = array();
		foreach ( array_keys( self::SERVER_HEADERS ) as $variable ) {
			if ( ! isset( $_SERVER[ $variable ] ) || ! is_string( $_SERVER[ $variable ] ) ) {
				continue;
			}
			if ( 'HTTP_X_QERSH_SIGNATURE' === $variable ) {
				$out[ $variable ] = (string) wp_unslash( $_SERVER[ $variable ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- HMAC check input, compared with hash_equals(), never stored or output (see above).
				continue;
			}
			$out[ $variable ] = sanitize_text_field( wp_unslash( $_SERVER[ $variable ] ) );
		}
		return $out;
	}

	/**
	 * The answer for a target.
	 *
	 * @param array $target target() result.
	 * @return array A result: http, body, headers.
	 */
	private static function answer( array $target ): array {
		if ( 'none' === $target['kind'] ) {
			return Controller::failure( 404, 'qersh_no_route' );
		}
		if ( 'POST' !== self::method() ) {
			$result                     = Controller::failure( 405, 'qersh_method_not_allowed' );
			$result['headers']['Allow'] = 'POST';
			return $result;
		}
		$plugin = Plugin::instance();
		if ( ! $plugin->wc_ready() || ! $plugin->tables_ready() ) {
			return Controller::unavailable();
		}
		if ( Module::too_large( self::content_length() ) ) {
			return self::too_large( $target['kind'] );
		}
		$raw = (string) \WP_REST_Server::get_raw_data();
		if ( Module::too_large( strlen( $raw ) ) ) {
			return self::too_large( $target['kind'] );
		}
		if ( 'webhook' === $target['kind'] ) {
			return Controller::handle_fallback( $target['wallet'], $raw, self::qersh_headers( self::server_headers() ) );
		}
		return self::storefront( $target['kind'], $raw );
	}

	/**
	 * For qo=status and qo=txn: the cores of the two storefront routes (package C), with the JSON body. They check
	 * the order key first, then the 2 KB limit of their body, and ask page caches not to store the answer, exactly as
	 * their REST callbacks do; a body that is not a JSON object is an empty one (their one 404).
	 *
	 * @param string $kind `status` or `txn`.
	 * @param string $raw  Raw body.
	 * @return array A result.
	 */
	private static function storefront( string $kind, string $raw ): array {
		$class = 'status' === $kind ? OrderStatusRoute::class : ClaimRoute::class;
		if ( ! class_exists( $class ) || ! method_exists( $class, 'handle_body' ) ) {
			return Controller::unavailable();
		}
		$body = json_decode( $raw, true );
		$body = is_array( $body ) ? $body : array();
		$out  = self::call_handle_body( $class, $body );
		if ( ! is_array( $out ) || ! isset( $out['http'] ) || ! is_int( $out['http'] ) || ! isset( $out['body'] ) || ! is_array( $out['body'] ) ) {
			return Controller::failure( 500, 'qersh_internal_error' );
		}
		return array(
			'http'    => $out['http'],
			'body'    => $out['body'],
			'headers' => isset( $out['headers'] ) && is_array( $out['headers'] ) ? $out['headers'] : array(),
		);
	}

	/**
	 * Calls handle_body() as the contract names it (static), or on an instance when package C made it one.
	 *
	 * @param string $class_name Class.
	 * @param array  $body       JSON body.
	 * @return mixed
	 */
	private static function call_handle_body( string $class_name, array $body ) {
		$method = new \ReflectionMethod( $class_name, 'handle_body' );
		if ( $method->isStatic() ) {
			return $class_name::handle_body( $body );
		}
		$route = new $class_name( Plugin::instance() );
		return $route->handle_body( $body );
	}

	/**
	 * A body above 64 KB: 413 for the webhook (docs/17 §4.4 step 2); for a storefront route it is not read at all,
	 * and the route answers as for an empty body (its one 404).
	 *
	 * @param string $kind Target kind.
	 * @return array A result.
	 */
	private static function too_large( string $kind ): array {
		if ( 'webhook' === $kind ) {
			return Controller::failure( 413, 'qersh_too_large', 'The body is larger than 64 KB.' );
		}
		return self::storefront( $kind, '' );
	}

	/**
	 * The request method.
	 *
	 * @return string
	 */
	private static function method(): string {
		return isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
	}

	/**
	 * The declared body length, 0 when unknown.
	 *
	 * @return int
	 */
	private static function content_length(): int {
		return isset( $_SERVER['CONTENT_LENGTH'] ) ? absint( wp_unslash( $_SERVER['CONTENT_LENGTH'] ) ) : 0;
	}

	/**
	 * Sends a result as JSON with no-cache headers, and ends the request.
	 *
	 * @param array $result A result.
	 */
	private static function send( array $result ): void {
		if ( ! headers_sent() ) {
			nocache_headers();
			foreach ( $result['headers'] as $name => $value ) {
				$name  = preg_replace( '/[^A-Za-z0-9-]/', '', (string) $name );
				$value = str_replace( array( "\r", "\n" ), '', (string) $value );
				if ( '' !== $name ) {
					header( $name . ': ' . $value );
				}
			}
		}
		wp_send_json( $result['body'], $result['http'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		exit; // wp_send_json() ends the request already; this keeps the promise if a filter changes that.
	}
}
