<?php
/**
 * The delivery claim: one answer per delivery id (docs/17 §4.4 step 6).
 *
 * @package QershPay\WooCommerce
 */

namespace QershPay\WooCommerce\Store;

defined( 'ABSPATH' ) || exit;

/**
 * Rows of `{prefix}qersh_deliveries`. No personal data: a stored reply holds a status and an order number, never
 * `status_updates[]`.
 */
final class Deliveries {

	/**
	 * A delivery in processing for this long or less is "busy"; an older one may be taken over.
	 */
	const BUSY_SEC = 60;

	/**
	 * The plugin, if any.
	 *
	 * @var \QershPay\WooCommerce\Plugin|null
	 */
	private $plugin;

	/**
	 * Database helper.
	 *
	 * @var Db|null
	 */
	private $db = null;

	/**
	 * Builds the service.
	 *
	 * @param \QershPay\WooCommerce\Plugin|null $plugin The plugin.
	 */
	public function __construct( $plugin = null ) {
		$this->plugin = $plugin;
	}

	/**
	 * Claims a delivery before any work, outside a transaction (docs/17 §4.4 step 6):
	 * - `INSERT IGNORE` of a `processing` row with a random claim token; one row inserted means it is ours.
	 * - Otherwise a `done` row gives back its stored reply and status, a `processing` row claimed within the last
	 *   60 seconds is busy (429 with Retry-After: 30), and an older one is taken over with
	 *   `UPDATE … WHERE claimed_at < now − 60`, which only one request can win.
	 *
	 * @param string      $delivery_id Envelope delivery_id (a UUID; anything longer than 36 characters is refused,
	 *                                 never cut, because a cut id would collide with another delivery).
	 * @param string      $wallet      Wallet id.
	 * @param string      $event       Event name.
	 * @param string|null $redelivery  X-Qersh-Redelivery value, if any.
	 * @param int         $now         UTC seconds.
	 * @return array `[ 'state' => 'claimed', 'token' => string ]`, `[ 'state' => 'done', 'http_status' => int,
	 *               'reply' => array ]` or `[ 'state' => 'busy' ]`.
	 * @throws LockTimeout When the row stays locked by a request that is writing its result.
	 */
	public function claim( string $delivery_id, string $wallet, string $event, ?string $redelivery, int $now ): array {
		if ( ! self::valid_id( $delivery_id ) ) {
			throw new \InvalidArgumentException( 'qersh: invalid delivery id' );
		}
		if ( function_exists( 'ignore_user_abort' ) ) {
			ignore_user_abort( true ); // From here a dropped connection must not leave the claim half done (step 6).
		}
		$db    = $this->db();
		$table = $db->table( 'deliveries' );
		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			$token = self::new_token();

			list( $sql, $args ) = $db->insert_sql(
				'deliveries',
				array(
					'delivery_id' => $delivery_id,
					'wallet'      => $wallet,
					'event'       => $event,
					'redelivery'  => null === $redelivery || '' === $redelivery ? null : $redelivery,
					'state'       => 'processing',
					'claim_token' => $token,
					'claimed_at'  => $now,
				),
				'INSERT IGNORE'
			);
			if ( 1 === $db->exec( $sql, $args ) ) {
				return array(
					'state' => 'claimed',
					'token' => $token,
				);
			}
			$row = $this->read( $delivery_id );
			if ( null === $row ) {
				continue; // Released between our insert and our read: the slot is free, try once more.
			}
			$verdict = self::verdict( $row, $now );
			if ( 'done' === $verdict ) {
				return self::done( $row );
			}
			if ( 'busy' === $verdict ) {
				return array( 'state' => 'busy' );
			}
			$taken = $db->exec(
				'UPDATE %i SET claim_token = %s, claimed_at = %d WHERE delivery_id = %s AND state = %s AND claimed_at < %d',
				array( $table, $token, $now, $delivery_id, 'processing', $now - self::BUSY_SEC )
			);
			if ( 1 === $taken ) {
				return array(
					'state' => 'claimed',
					'token' => $token,
				);
			}
			$row = $this->read( $delivery_id );
			if ( null !== $row && 'done' === self::verdict( $row, $now ) ) {
				return self::done( $row );
			}
			return array( 'state' => 'busy' );
		}
		return array( 'state' => 'busy' );
	}

	/**
	 * The fence: the first write of the result transaction, after the locks (docs/17 §4.4 step 6). Marks the row
	 * `done` only while it is still ours and still processing. False means another request took the delivery
	 * over: roll back and answer as claim() would.
	 *
	 * The reply is usually not known yet when the fence runs; pass what you have (or an empty array) and write the
	 * final reply with store_reply() before COMMIT.
	 *
	 * @param string $delivery_id Delivery id.
	 * @param string $token       Our claim token.
	 * @param int    $http_status HTTP status of the reply.
	 * @param array  $reply       Reply body (`status_updates` is never stored).
	 * @param int    $now         UTC seconds.
	 * @return bool
	 */
	public function fence( string $delivery_id, string $token, int $http_status, array $reply, int $now ): bool {
		unset( $reply['status_updates'] );
		$db = $this->db();
		$n  = $db->exec(
			'UPDATE %i SET state = %s, http_status = %d, reply_json = %s, answered_at = %d WHERE delivery_id = %s AND claim_token = %s AND state = %s',
			array( $db->table( 'deliveries' ), 'done', $http_status, Db::json( $reply ), $now, $delivery_id, $token, 'processing' )
		);
		return 1 === $n;
	}

	/**
	 * Writes the final reply of a delivery we fenced, inside the same transaction (`status_updates` is never stored;
	 * they are added to every reply when it is sent, docs/17 §4.4 step 10).
	 *
	 * @param string $delivery_id Delivery id.
	 * @param string $token       Our claim token.
	 * @param int    $http_status HTTP status.
	 * @param array  $reply       Reply body.
	 * @param int    $now         UTC seconds.
	 */
	public function store_reply( string $delivery_id, string $token, int $http_status, array $reply, int $now ) {
		unset( $reply['status_updates'] );
		$db = $this->db();
		$db->exec(
			'UPDATE %i SET http_status = %d, reply_json = %s, answered_at = %d WHERE delivery_id = %s AND claim_token = %s AND state = %s',
			array( $db->table( 'deliveries' ), $http_status, Db::json( $reply ), $now, $delivery_id, $token, 'done' )
		);
	}

	/**
	 * Gives the delivery back after a 500 or 429 of our own, so the phone's next attempt is not "busy". Deletes our
	 * row only, and only while it is still processing. Never throws.
	 *
	 * @param string $delivery_id Delivery id.
	 * @param string $token       Our claim token.
	 */
	public function release( string $delivery_id, string $token ) {
		try {
			$db = $this->db();
			$db->query(
				'DELETE FROM %i WHERE delivery_id = %s AND claim_token = %s AND state = %s',
				array( $db->table( 'deliveries' ), $delivery_id, $token, 'processing' )
			);
		} catch ( \Throwable $e ) {
			unset( $e ); // The row expires after 60 seconds anyway.
		}
	}

	/**
	 * Daily cleanup: forgets deliveries claimed before a time (now − dedup.keep_days, 180 days by default).
	 *
	 * @param int $before UTC seconds.
	 * @return int Rows deleted.
	 */
	public function purge( int $before ): int {
		$db = $this->db();
		return $db->exec( 'DELETE FROM %i WHERE claimed_at < %d', array( $db->table( 'deliveries' ), $before ) );
	}

	/**
	 * What an existing row means for a new claim. Pure.
	 *
	 * @param array $row Row with `state` and `claimed_at`.
	 * @param int   $now UTC seconds.
	 * @return string `done`, `busy` (claimed within the last 60 seconds) or `stale` (may be taken over).
	 */
	public static function verdict( array $row, int $now ): string {
		if ( isset( $row['state'] ) && 'done' === $row['state'] ) {
			return 'done';
		}
		$claimed_at = isset( $row['claimed_at'] ) ? (int) $row['claimed_at'] : 0;
		return $claimed_at >= $now - self::BUSY_SEC ? 'busy' : 'stale';
	}

	/**
	 * True for an id the table can hold without cutting it. Pure.
	 *
	 * @param string $delivery_id Delivery id.
	 * @return bool
	 */
	public static function valid_id( string $delivery_id ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9-]{1,36}$/', $delivery_id );
	}

	/**
	 * A fresh claim token: 32 hex characters.
	 *
	 * @return string
	 */
	private static function new_token(): string {
		return bin2hex( random_bytes( 16 ) );
	}

	/**
	 * The stored answer of a finished delivery.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	private static function done( array $row ): array {
		$reply = isset( $row['reply_json'] ) && is_string( $row['reply_json'] ) ? json_decode( $row['reply_json'], true ) : null;
		return array(
			'state'       => 'done',
			'http_status' => isset( $row['http_status'] ) && null !== $row['http_status'] ? (int) $row['http_status'] : 200,
			'reply'       => is_array( $reply ) ? $reply : array(),
		);
	}

	/**
	 * Reads the claim columns of a delivery.
	 *
	 * @param string $delivery_id Delivery id.
	 * @return array|null
	 */
	private function read( string $delivery_id ): ?array {
		$db = $this->db();
		return $db->row(
			'SELECT state, http_status, reply_json, claimed_at FROM %i WHERE delivery_id = %s',
			array( $db->table( 'deliveries' ), $delivery_id )
		);
	}

	/**
	 * The database helper.
	 *
	 * @return Db
	 */
	private function db(): Db {
		if ( null === $this->db ) {
			$this->db = $this->plugin instanceof \QershPay\WooCommerce\Plugin ? $this->plugin->db() : new Db();
		}
		return $this->db;
	}
}
