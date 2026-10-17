<?php
/**
 * The webhook: one delivery from the Qersh phone app, from the wallet check to the reply (docs/17 §4.4, §4.5).
 *
 * @package QershPay\WooCommerce
 */

namespace QershPay\WooCommerce\Webhook;

use QershPay\WooCommerce\Core\Envelope;
use QershPay\WooCommerce\Core\Verifier;
use QershPay\WooCommerce\Diagnostics;
use QershPay\WooCommerce\Orders;
use QershPay\WooCommerce\Plugin;
use QershPay\WooCommerce\Store\Db;
use QershPay\WooCommerce\Store\Deliveries;
use QershPay\WooCommerce\Store\LockTimeout;

defined( 'ABSPATH' ) || exit;

/**
 * Serves `POST qersh/v1/webhook/<wallet>` and its fallback address `?wc-api=qersh&qw=<wallet>` with the same
 * steps:
 * 1. the wallet, before the signature, without a database write or a key: 404 qersh_unknown_wallet, 404
 *    qersh_wallet_deleted (deleted less than 72 hours ago) or 410 qersh_wallet_gone;
 * 2. the signature, on the raw bytes, with the wallet's keys (pending, current, previous): 401
 *    qersh_invalid_signature with the reason, `no_key` for a wallet without a usable key; on REST this runs in
 *    permission_callback, and the result is kept for the request because the callback runs again for `Allow`;
 * 3. the envelope (400 when it is not qersh.v1), the key bookkeeping (a pending key that signed becomes current),
 *    the wallet's state (Diagnostics, which also reads the position of the matching v1 value: a later one means the
 *    phone has a newer key, docs/17 §4.8);
 * 4. the delivery claim: 429 Retry-After 30 while another request works on it, the stored reply for a done one;
 * 5. the event: webhook.test and ignored events here, transfer.received and transfer.updated in Orders; a fence
 *    that hits no row (another request took the stale claim over) answers 429, as step 4 would;
 * 6. every 2xx reply carries the pending status_updates of the wallet, within 16 KB.
 * Every Throwable is logged without data and answers 500 JSON; after the claim it also releases it.
 */
final class Controller {

	/**
	 * A reply, with its status updates, stays within this many bytes (docs/17 §4.4 step 10).
	 */
	const REPLY_MAX = 16384;

	/**
	 * Retry-After of a busy answer, in seconds.
	 */
	const RETRY_AFTER = 30;

	/**
	 * The note of the webhook.test reply, as the phone shows it.
	 */
	const TEST_NOTE = 'متجر WooCommerce متصل';

	/**
	 * Body of the 404 for a wallet deleted less than 72 hours ago (docs/17 §4.4 step 3).
	 */
	const DELETED_MESSAGE = 'حُذفت هذه المحفظة في المتجر. استعِدها من WooCommerce ← قرش ← المحافظ.';

	/**
	 * Body of the 410 for a wallet deleted 72 hours ago or more.
	 */
	const GONE_MESSAGE = 'حُذفت هذه المحفظة في المتجر منذ أكثر من 72 ساعة.';

	/**
	 * Statuses a status update may carry (merchantResponse in webhook.v1.schema.json).
	 */
	const UPDATE_STATUSES = array( 'matched', 'partial', 'overpaid', 'unmatched', 'review', 'duplicate', 'ignored' );

	/**
	 * Longest `reference` of a status update, in characters.
	 */
	const REFERENCE_MAX = 64;

	/**
	 * Longest `note` of a status update, in characters.
	 */
	const NOTE_MAX = 200;

	/**
	 * The one instance.
	 *
	 * @var Controller|null
	 */
	private static $instance = null;

	/**
	 * The plugin.
	 *
	 * @var Plugin
	 */
	private $plugin;

	/**
	 * Gate results of this request, by a hash of the wallet, the signature header and the body.
	 *
	 * @var array<string, array>
	 */
	private $verdicts = array();

	/**
	 * Builds the controller.
	 *
	 * @param Plugin $plugin The plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * The controller of this request.
	 *
	 * @return Controller
	 */
	public static function instance(): Controller {
		if ( null === self::$instance ) {
			self::$instance = new self( Plugin::instance() );
		}
		return self::$instance;
	}

	/**
	 * permission_callback of the REST route: the wallet, then the signature. True when the store cannot answer at
	 * all (WooCommerce or the tables missing): the callback then answers 503.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public static function rest_permission( $request ) {
		try {
			$controller = self::instance();
			if ( ! $controller->ready() ) {
				return true;
			}
			$gate = $controller->gate( self::route_wallet( $request ), (string) $request->get_body(), $request->get_header( 'x_qersh_signature' ), time() );
			return $gate['ok'] ? true : self::to_wp_error( $gate['error'] );
		} catch ( \Throwable $e ) {
			self::log_throwable( 'permission check', $e );
			return new \WP_Error( 'qersh_internal_error', 'Unexpected error.', array( 'status' => 500 ) );
		}
	}

	/**
	 * Callback of the REST route.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function rest_handle( $request ) {
		$controller = self::instance();
		if ( ! $controller->ready() ) {
			return Plugin::unavailable_response();
		}
		$wallet = self::route_wallet( $request );
		$raw    = (string) $request->get_body();
		$now    = time();
		try {
			$gate = $controller->gate( $wallet, $raw, $request->get_header( 'x_qersh_signature' ), $now );
			if ( ! $gate['ok'] ) {
				return self::to_wp_error( $gate['error'] );
			}
			$result = $controller->process( $wallet, $raw, $request->get_headers(), self::rest_form(), $gate, $now );
		} catch ( \Throwable $e ) {
			$controller->log_error( 'webhook', $e );
			$result = self::failure( 500, 'qersh_internal_error' );
		}
		$controller->as_health();
		$response = new \WP_REST_Response( $result['body'], $result['http'] );
		foreach ( $result['headers'] as $name => $value ) {
			$response->header( $name, $value );
		}
		return $response;
	}

	/**
	 * The same delivery through the fallback address (`?wc-api=qersh&qw=<wallet>`). Answers 503 when WooCommerce
	 * or the tables are missing.
	 *
	 * @param string      $wallet  Wallet id from the address.
	 * @param string|null $raw     Raw body.
	 * @param array       $headers Request headers, lower-case names (`x-qersh-signature`, …).
	 * @return array `[ 'http' => int, 'body' => array, 'headers' => array ]`
	 */
	public static function handle_fallback( string $wallet, ?string $raw, array $headers ): array {
		$controller = self::instance();
		if ( ! $controller->ready() ) {
			return self::unavailable();
		}
		$now = time();
		try {
			$gate = $controller->gate( $wallet, $raw, isset( $headers['x-qersh-signature'] ) ? $headers['x-qersh-signature'] : null, $now );
			if ( ! $gate['ok'] ) {
				return $gate['error'];
			}
			$result = $controller->process( $wallet, (string) $raw, $headers, 'wc-api', $gate, $now );
		} catch ( \Throwable $e ) {
			$controller->log_error( 'webhook', $e );
			$result = self::failure( 500, 'qersh_internal_error' );
		}
		$controller->as_health();
		return $result;
	}

	/**
	 * Steps 3 and 4: the wallet, then the signature. Reads only; the one write is the throttled log flag of a
	 * refused signature. The result is kept for the rest of the request.
	 *
	 * @param string      $wallet    Wallet id.
	 * @param string|null $raw       Raw body.
	 * @param mixed       $signature X-Qersh-Signature value.
	 * @param int         $now       UTC seconds.
	 * @return array `[ 'ok' => true, 'wallet_cfg' => array, 'slot' => string, 'v1_index' => int ]` or
	 *               `[ 'ok' => false, 'error' => array ]` (a result: http, body, headers).
	 */
	public function gate( string $wallet, ?string $raw, $signature, int $now ): array {
		$cache_key = hash( 'sha256', $wallet . "\0" . ( is_string( $signature ) ? $signature : '' ) . "\0" . (string) $raw );
		if ( isset( $this->verdicts[ $cache_key ] ) ) {
			return $this->verdicts[ $cache_key ];
		}
		$cfg = $this->plugin->wallet( $wallet );
		if ( null === $cfg ) {
			$state = 1 === preg_match( '/^[a-z0-9]{12}$/', $wallet ) ? $this->plugin->wallets()->gone( $wallet, $now ) : 'unknown';
			$out   = array(
				'ok'    => false,
				'error' => self::wallet_refusal( $state ),
			);
			return $this->remember( $cache_key, $out );
		}
		$slots = $this->plugin->secrets()->slots_for( $wallet, $now );
		$keys  = array();
		foreach ( $slots as $slot ) {
			$keys[] = $slot['key'];
		}
		$config    = $this->plugin->config();
		$tolerance = isset( $config['receiver']['tolerance_sec'] ) && is_numeric( $config['receiver']['tolerance_sec'] ) ? (int) $config['receiver']['tolerance_sec'] : Verifier::DEFAULT_TOLERANCE_SEC;
		$result    = Verifier::verify( $raw, $signature, $keys, $now, $tolerance );
		unset( $keys );
		if ( empty( $result['ok'] ) ) {
			$reason = isset( $result['reason'] ) ? (string) $result['reason'] : 'bad_signature';
			$this->plugin->logger()->throttled( 'sig:' . $wallet, 'qersh: a delivery for wallet ' . $wallet . ' was refused: ' . $reason );
			$out = array(
				'ok'    => false,
				'error' => self::failure( 401, 'qersh_invalid_signature', 'The signature of this delivery is not valid.', array( 'reason' => $reason ) ),
			);
			return $this->remember( $cache_key, $out );
		}
		$index = (int) $result['key_index'];
		$out   = array(
			'ok'         => true,
			'wallet_cfg' => $cfg,
			'slot'       => isset( $slots[ $index ]['slot'] ) ? (string) $slots[ $index ]['slot'] : 'current',
			'v1_index'   => (int) $result['v1_index'],
		);
		return $this->remember( $cache_key, $out );
	}

	/**
	 * Keeps a gate result for the rest of the request.
	 *
	 * @param string $key Cache key.
	 * @param array  $out Result.
	 * @return array The result.
	 */
	private function remember( string $key, array $out ): array {
		$this->verdicts[ $key ] = $out;
		return $out;
	}

	/**
	 * Steps 5 to 12 for a verified delivery.
	 *
	 * @param string $wallet  Wallet id.
	 * @param string $raw     Raw body (verified).
	 * @param array  $headers Request headers.
	 * @param string $form    `wp-json`, `rest_route` or `wc-api`.
	 * @param array  $gate    The gate() result (ok).
	 * @param int    $now     UTC seconds.
	 * @return array `[ 'http' => int, 'body' => array, 'headers' => array ]`
	 */
	public function process( string $wallet, string $raw, array $headers, string $form, array $gate, int $now ): array {
		$parsed = Envelope::parse( $raw );
		if ( empty( $parsed['ok'] ) ) {
			return self::failure( 400, 'qersh_invalid_envelope', 'The body is not a qersh.v1 envelope.', array( 'reason' => (string) $parsed['reason'] ) );
		}
		$envelope = $parsed['envelope'];
		$this->after_verified( $wallet, $gate, $now );
		$this->record_delivery( $wallet, $envelope, $form, $now, (int) $gate['v1_index'] );

		$delivery_id = (string) $envelope['delivery_id'];
		if ( ! Deliveries::valid_id( $delivery_id ) ) {
			return self::failure( 400, 'qersh_invalid_envelope', 'The body is not a qersh.v1 envelope.', array( 'reason' => 'delivery_id' ) );
		}
		$event = (string) $envelope['event'];
		$meta  = Envelope::delivery_headers( $headers );
		try {
			$claim = $this->plugin->deliveries()->claim( $delivery_id, $wallet, $event, $meta['redelivery'], $now );
		} catch ( LockTimeout $e ) {
			$claim = array( 'state' => 'busy' );
		}
		if ( 'busy' === $claim['state'] ) {
			return self::busy( self::RETRY_AFTER );
		}
		if ( 'done' === $claim['state'] ) {
			$http  = (int) $claim['http_status'];
			$reply = is_array( $claim['reply'] ) ? $claim['reply'] : array();
			return self::result( $http, $this->with_updates( $wallet, $http, $reply, 'transfer.received' === $event ? $envelope : null, $now ) );
		}

		$token = (string) $claim['token'];
		try {
			$out = $this->dispatch( $event, $envelope, $wallet, $gate['wallet_cfg'], $delivery_id, $token, $now );
		} catch ( LockTimeout $e ) {
			$this->plugin->deliveries()->release( $delivery_id, $token );
			return self::busy( self::RETRY_AFTER ); // A lock wait or deadlock is "busy", never "not answering".
		} catch ( \Throwable $e ) {
			$this->plugin->deliveries()->release( $delivery_id, $token );
			$this->log_error( 'dispatch of ' . $event, $e );
			return self::failure( 500, 'qersh_internal_error' );
		}
		$http = $out['http'];
		if ( $http < 200 || $http >= 300 ) {
			$this->plugin->deliveries()->release( $delivery_id, $token ); // Only a row still processing and ours.
			if ( 429 === $http ) {
				$retry = isset( $out['retry_after'] ) && (int) $out['retry_after'] > 0 ? (int) $out['retry_after'] : self::RETRY_AFTER;
				return self::result( 429, $out['reply'], array( 'Retry-After' => (string) $retry ) );
			}
			return self::result( $http, $out['reply'] );
		}
		return self::result( $http, $this->with_updates( $wallet, $http, $out['reply'], null, $now ) );
	}

	/**
	 * Diagnostics::as_health() (package E) at the end of each verified webhook request. Never throws.
	 */
	public function as_health() {
		if ( ! $this->ready() || ! class_exists( Diagnostics::class ) || ! method_exists( Diagnostics::class, 'as_health' ) ) {
			return;
		}
		try {
			$this->plugin->diagnostics()->as_health();
		} catch ( \Throwable $e ) {
			$this->log_error( 'as_health', $e );
		}
	}

	/**
	 * The answer for a wallet that is not in the configuration (docs/17 §4.4 step 3). Pure.
	 *
	 * @param string $gone_state Store\Wallets::gone(): `active`, `deleted_recent`, `gone` or `unknown`.
	 * @return array A result (http, body, headers).
	 */
	public static function wallet_refusal( string $gone_state ): array {
		if ( 'deleted_recent' === $gone_state ) {
			return self::failure( 404, 'qersh_wallet_deleted', self::DELETED_MESSAGE );
		}
		if ( 'gone' === $gone_state ) {
			return self::failure( 410, 'qersh_wallet_gone', self::GONE_MESSAGE );
		}
		return self::failure( 404, 'qersh_unknown_wallet', 'This wallet is not set up in this store.' );
	}

	/**
	 * How the controller treats an event. Pure.
	 *
	 * @param string $event  Event name.
	 * @param mixed  $events receiver.events of the configuration.
	 * @return string `test`, `received`, `updated` or `ignored`.
	 */
	public static function event_kind( string $event, $events ): string {
		if ( ! is_array( $events ) || ! in_array( $event, $events, true ) ) {
			return 'ignored';
		}
		switch ( $event ) {
			case 'webhook.test':
				return 'test';
			case 'transfer.received':
				return 'received';
			case 'transfer.updated':
				return 'updated';
			default:
				return 'ignored';
		}
	}

	/**
	 * A status update item as the phone accepts it, or null: transfer_id and a known status required, reference and
	 * note cut to 64 and 200 characters. Pure.
	 *
	 * @param mixed $item Item.
	 * @return array|null
	 */
	public static function clean_update( $item ): ?array {
		if ( ! is_array( $item ) || ! isset( $item['transfer_id'], $item['status'] ) || ! is_string( $item['transfer_id'] ) || '' === $item['transfer_id'] ) {
			return null;
		}
		if ( ! in_array( $item['status'], self::UPDATE_STATUSES, true ) ) {
			return null;
		}
		$out = array(
			'transfer_id' => $item['transfer_id'],
			'status'      => $item['status'],
		);
		if ( isset( $item['reference'] ) && ( is_string( $item['reference'] ) || is_int( $item['reference'] ) ) && '' !== (string) $item['reference'] ) {
			$out['reference'] = self::cut( (string) $item['reference'], self::REFERENCE_MAX );
		}
		if ( isset( $item['note'] ) && is_string( $item['note'] ) && '' !== $item['note'] ) {
			$out['note'] = self::cut( $item['note'], self::NOTE_MAX );
		}
		return $out;
	}

	/**
	 * Adds status updates to a reply while the JSON stays within the limit, in the given order, each transfer once.
	 * Pure.
	 *
	 * @param array $reply Reply (any `status_updates` in it is replaced).
	 * @param array $items Status update items.
	 * @param int   $max   Byte limit of the JSON.
	 * @return array `[ 'reply' => array, 'ids' => string[] ]`, the ids of the items included.
	 */
	public static function cap_updates( array $reply, array $items, int $max = self::REPLY_MAX ): array {
		unset( $reply['status_updates'] );
		$kept = array();
		$ids  = array();
		foreach ( $items as $item ) {
			$clean = self::clean_update( $item );
			if ( null === $clean || in_array( $clean['transfer_id'], $ids, true ) ) {
				continue;
			}
			$candidate                   = $reply;
			$candidate['status_updates'] = array_merge( $kept, array( $clean ) );
			if ( strlen( Db::json( $candidate ) ) > $max ) {
				break;
			}
			$kept[] = $clean;
			$ids[]  = $clean['transfer_id'];
		}
		if ( ! empty( $kept ) ) {
			$reply['status_updates'] = $kept;
		}
		return array(
			'reply' => $reply,
			'ids'   => $ids,
		);
	}

	/**
	 * A result with a JSON error body in the shape of a WP_Error answer of the REST API. Pure.
	 *
	 * @param int    $status  HTTP status.
	 * @param string $code    Error code.
	 * @param string $message Message (optional).
	 * @param array  $data    More data (optional).
	 * @return array
	 */
	public static function failure( int $status, string $code, string $message = '', array $data = array() ): array {
		if ( '' === $message ) {
			return self::result( $status, array( 'code' => $code ) );
		}
		return self::result(
			$status,
			array(
				'code'    => $code,
				'message' => $message,
				'data'    => array_merge( array( 'status' => $status ), $data ),
			)
		);
	}

	/**
	 * The 503 answer while WooCommerce or the tables are missing (the body of Plugin::unavailable_response()). Pure.
	 *
	 * @return array
	 */
	public static function unavailable(): array {
		return self::result( 503, array( 'code' => 'qersh_unavailable' ), array( 'Cache-Control' => 'no-store, private' ) );
	}

	/**
	 * The 429 answer of a busy delivery. Pure.
	 *
	 * @param int $retry_after Seconds.
	 * @return array
	 */
	public static function busy( int $retry_after ): array {
		return self::result( 429, array( 'code' => 'qersh_busy' ), array( 'Retry-After' => (string) $retry_after ) );
	}

	/**
	 * The dispatch result when our fence hit no row: another request took the stale claim over (docs/17 §4.4 step
	 * 6), so this one answers as that step does for a delivery in progress, 429 with Retry-After 30; the phone's next
	 * try gets the stored reply. Pure.
	 *
	 * @return array `[ 'http' => 429, 'reply' => array, 'retry_after' => int ]`
	 */
	public static function fenced_out(): array {
		return array(
			'http'        => 429,
			'reply'       => array( 'code' => 'qersh_busy' ),
			'retry_after' => self::RETRY_AFTER,
		);
	}

	/**
	 * Step 7: the event.
	 *
	 * @param string $event       Event.
	 * @param array  $envelope    Envelope.
	 * @param string $wallet      Wallet id.
	 * @param array  $wallet_cfg  The wallet's configuration entry.
	 * @param string $delivery_id Delivery id.
	 * @param string $token       Our claim token.
	 * @param int    $now         UTC seconds.
	 * @return array `[ 'http' => int, 'reply' => array, 'retry_after' => int|null ]`
	 * @throws \RuntimeException When the matching module is missing or answers in a wrong shape.
	 */
	private function dispatch( string $event, array $envelope, string $wallet, array $wallet_cfg, string $delivery_id, string $token, int $now ): array {
		$config = $this->plugin->config();
		$kind   = self::event_kind( $event, isset( $config['receiver']['events'] ) ? $config['receiver']['events'] : array() );
		if ( 'ignored' === $kind || 'test' === $kind ) {
			$reply = array( 'status' => 'ignored' );
			if ( 'test' === $kind ) {
				$this->touch_test( $wallet, $envelope, $now );
				$reply = array(
					'status' => 'ok',
					'note'   => self::TEST_NOTE,
				);
			}
			if ( ! $this->plugin->deliveries()->fence( $delivery_id, $token, 200, $reply, $now ) ) {
				return self::fenced_out(); // Another request took our stale claim over: it answers this delivery.
			}
			return array(
				'http'        => 200,
				'reply'       => $reply,
				'retry_after' => null,
			);
		}
		$method = 'received' === $kind ? 'handle_received' : 'handle_updated';
		if ( ! class_exists( Orders::class ) || ! method_exists( Orders::class, $method ) ) {
			throw new \RuntimeException( 'qersh: the matching module is missing' );
		}
		$out = $this->plugin->orders()->$method( $envelope, $wallet_cfg, $delivery_id, $token, $now );
		if ( ! is_array( $out ) || ! isset( $out['http'] ) || ! is_int( $out['http'] ) || ! isset( $out['reply'] ) || ! is_array( $out['reply'] ) ) {
			throw new \RuntimeException( 'qersh: the matching module answered in a wrong shape' );
		}
		return array(
			'http'        => $out['http'],
			'reply'       => $out['reply'],
			'retry_after' => isset( $out['retry_after'] ) ? (int) $out['retry_after'] : null,
		);
	}

	/**
	 * Step 10: a 2xx reply carries the wallet's pending status updates (Orders::status_updates()), and the
	 * transfers sent are counted (Transfers::note_synced()). For the repeat of a transfer.received whose transfer
	 * now has another verdict than the stored reply, that transfer's own item comes first, outside the three-reply
	 * limit. Never throws.
	 *
	 * @param string     $wallet   Wallet id.
	 * @param int        $http     HTTP status.
	 * @param array      $reply    Reply.
	 * @param array|null $envelope Envelope of a repeated transfer.received, or null.
	 * @param int        $now      UTC seconds.
	 * @return array The reply to send.
	 */
	private function with_updates( string $wallet, int $http, array $reply, ?array $envelope, int $now ): array {
		unset( $reply['status_updates'] );
		if ( $http < 200 || $http >= 300 || ! class_exists( Orders::class ) ) {
			return $reply;
		}
		try {
			$orders  = $this->plugin->orders();
			$pending = method_exists( $orders, 'status_updates' ) ? $orders->status_updates( $wallet, $now ) : array();
			$pending = is_array( $pending ) ? array_values( $pending ) : array();
			$items   = $pending;
			$extra   = null === $envelope ? null : $this->repeat_item( $orders, $envelope, $reply, $now );
			if ( null !== $extra ) {
				array_unshift( $items, $extra );
			}
			$capped = self::cap_updates( $reply, $items );
			$synced = array();
			foreach ( $pending as $item ) {
				if ( is_array( $item ) && isset( $item['transfer_id'] ) && in_array( $item['transfer_id'], $capped['ids'], true ) ) {
					$synced[] = (string) $item['transfer_id'];
				}
			}
			if ( ! empty( $synced ) ) {
				$this->plugin->transfers()->note_synced( $synced, $now );
			}
			return $capped['reply'];
		} catch ( \Throwable $e ) {
			$this->log_error( 'status updates', $e );
			return $reply;
		}
	}

	/**
	 * For a repeated transfer.received: the transfer's current item when it says something else than the stored
	 * reply (Orders::status_update_for(), when package D2 provides it).
	 *
	 * @param Orders $orders   Orders.
	 * @param array  $envelope Envelope.
	 * @param array  $reply    Stored reply.
	 * @param int    $now      UTC seconds.
	 * @return array|null
	 */
	private function repeat_item( $orders, array $envelope, array $reply, int $now ): ?array {
		if ( ! method_exists( $orders, 'status_update_for' ) ) {
			return null;
		}
		$transfer_id = isset( $envelope['data']['transfer_id'] ) && is_string( $envelope['data']['transfer_id'] ) ? $envelope['data']['transfer_id'] : '';
		if ( '' === $transfer_id || strlen( $transfer_id ) > 40 ) {
			return null;
		}
		$row = $this->plugin->transfers()->get( $transfer_id );
		if ( null === $row ) {
			return null;
		}
		$item = self::clean_update( $orders->status_update_for( $row, $now ) );
		if ( null === $item ) {
			return null;
		}
		$same_status    = isset( $reply['status'] ) && $reply['status'] === $item['status'];
		$same_reference = ( isset( $reply['reference'] ) ? (string) $reply['reference'] : '' ) === ( isset( $item['reference'] ) ? $item['reference'] : '' );
		return $same_status && $same_reference ? null : $item;
	}

	/**
	 * After a valid signature: a pending key that signed becomes current, and the wallet has its state row. The
	 * "phone has a newer key" card comes from the v1 position that record_delivery() passes to Diagnostics. Never
	 * throws.
	 *
	 * @param string $wallet Wallet id.
	 * @param array  $gate   The gate() result.
	 * @param int    $now    UTC seconds.
	 */
	private function after_verified( string $wallet, array $gate, int $now ) {
		try {
			if ( 'pending' === $gate['slot'] ) {
				$this->plugin->secrets()->promote_pending( $wallet, $now );
			}
			$this->plugin->wallets()->ensure( $wallet );
		} catch ( \Throwable $e ) {
			$this->log_error( 'key bookkeeping', $e );
		}
	}

	/**
	 * Diagnostics::record_delivery() (package E): the wallet's state from every verified delivery, with the position
	 * of the v1 value that matched (its optional fifth argument). Never throws.
	 *
	 * @param string $wallet   Wallet id.
	 * @param array  $envelope Envelope.
	 * @param string $form     Address form.
	 * @param int    $now      UTC seconds.
	 * @param int    $v1_index Position of the matching v1 value in the signature header.
	 */
	private function record_delivery( string $wallet, array $envelope, string $form, int $now, int $v1_index ) {
		if ( ! class_exists( Diagnostics::class ) || ! method_exists( Diagnostics::class, 'record_delivery' ) ) {
			return;
		}
		try {
			$this->plugin->diagnostics()->record_delivery( $wallet, $envelope, $form, $now, array( 'v1_index' => $v1_index ) );
		} catch ( \Throwable $e ) {
			$this->log_error( 'record_delivery', $e );
		}
	}

	/**
	 * webhook.test: the last_test_* columns, without touching any order.
	 *
	 * @param string $wallet   Wallet id.
	 * @param array  $envelope Envelope.
	 * @param int    $now      UTC seconds.
	 */
	private function touch_test( string $wallet, array $envelope, int $now ) {
		$device = isset( $envelope['device'] ) && is_array( $envelope['device'] ) ? $envelope['device'] : array();
		$phone  = isset( $envelope['wallet'] ) && is_array( $envelope['wallet'] ) ? $envelope['wallet'] : array();
		$this->plugin->wallets()->touch(
			$wallet,
			array(
				'last_test_at'            => $now,
				'last_test_app_version'   => isset( $device['app_version'] ) && is_string( $device['app_version'] ) ? $device['app_version'] : null,
				'last_test_provider'      => isset( $phone['provider'] ) && is_string( $phone['provider'] ) ? $phone['provider'] : null,
				'last_test_number_masked' => isset( $phone['number_masked'] ) && is_string( $phone['number_masked'] ) ? $phone['number_masked'] : null,
			)
		);
	}

	/**
	 * True when WooCommerce and the tables are there.
	 *
	 * @return bool
	 */
	private function ready(): bool {
		return $this->plugin->wc_ready() && $this->plugin->tables_ready();
	}

	/**
	 * The wallet id of the REST route, from the URL only (the envelope has a `wallet` field too).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return string
	 */
	private static function route_wallet( $request ): string {
		$params = $request->get_url_params();
		return isset( $params['wallet'] ) && is_string( $params['wallet'] ) ? $params['wallet'] : '';
	}

	/**
	 * The address form of a REST delivery: `rest_route` (plain permalinks) or `wp-json`.
	 *
	 * @return string
	 */
	private static function rest_form(): string {
		return isset( $_GET['rest_route'] ) ? 'rest_route' : 'wp-json'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- signed webhook; only the presence of the parameter is read.
	}

	/**
	 * A WP_Error from a failure() result.
	 *
	 * @param array $result Result.
	 * @return \WP_Error
	 */
	private static function to_wp_error( array $result ): \WP_Error {
		$body = $result['body'];
		$data = isset( $body['data'] ) && is_array( $body['data'] ) ? $body['data'] : array( 'status' => $result['http'] );
		return new \WP_Error( (string) $body['code'], isset( $body['message'] ) ? (string) $body['message'] : '', $data );
	}

	/**
	 * A result. Pure.
	 *
	 * @param int   $http    HTTP status.
	 * @param array $body    Body.
	 * @param array $headers Headers.
	 * @return array
	 */
	private static function result( int $http, array $body, array $headers = array() ): array {
		return array(
			'http'    => $http,
			'body'    => $body,
			'headers' => $headers,
		);
	}

	/**
	 * Cuts a text to a number of characters.
	 *
	 * @param string $text Text.
	 * @param int    $max  Characters.
	 * @return string
	 */
	private static function cut( string $text, int $max ): string {
		if ( function_exists( 'mb_strlen' ) ) {
			return mb_strlen( $text, 'UTF-8' ) > $max ? (string) mb_substr( $text, 0, $max, 'UTF-8' ) : $text;
		}
		return strlen( $text ) > $max ? substr( $text, 0, $max ) : $text;
	}

	/**
	 * Logs an error without data: the class and place.
	 *
	 * @param string     $where Step.
	 * @param \Throwable $e     Error.
	 */
	private function log_error( string $where, \Throwable $e ) {
		try {
			$this->plugin->logger()->error( self::error_line( $where, $e ) );
		} catch ( \Throwable $ignored ) {
			unset( $ignored );
		}
	}

	/**
	 * Logs an error without data where no controller may exist (the permission check, the fallback address): the
	 * class and place of the error, never its message. Never throws.
	 *
	 * @param string     $where Step.
	 * @param \Throwable $e     Error.
	 */
	public static function log_throwable( string $where, \Throwable $e ) {
		try {
			Plugin::instance()->logger()->error( self::error_line( $where, $e ) );
		} catch ( \Throwable $ignored ) {
			unset( $ignored );
		}
	}

	/**
	 * The log line of an error: the step, the class, the file name and the line, without the message (which may
	 * carry a query or a value). Pure.
	 *
	 * @param string     $where Step.
	 * @param \Throwable $e     Error.
	 * @return string
	 */
	public static function error_line( string $where, \Throwable $e ): string {
		return 'qersh: webhook ' . $where . ' failed: ' . get_class( $e ) . ' at ' . basename( $e->getFile() ) . ':' . $e->getLine();
	}
}
