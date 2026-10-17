<?php
/**
 * The index of orders that wait for a wallet transfer (docs/17 §4.3, §4.6, §4.7, §4.10).
 *
 * @package QershPay\WooCommerce
 */

namespace QershPay\WooCommerce\Store;

defined( 'ABSPATH' ) || exit;

/**
 * Rows of `{prefix}qersh_orders`. The WooCommerce order is the source of truth; this row is an index that the
 * matching reads under the payer lock. Rows come back typed (integer columns as int).
 */
final class OrdersIndex {

	/**
	 * Accepted transaction ids per order, never reset (docs/17 §4.10).
	 */
	const CLAIM_MAX = 5;

	/**
	 * The first three are accepted at once…
	 */
	const CLAIM_FREE = 3;

	/**
	 * …then one every ten minutes.
	 */
	const CLAIM_WAIT_SEC = 600;

	/**
	 * A `running` completion older than this may be claimed again (longer than PHPMailer's SMTP timeout of 300 s).
	 */
	const COMPLETION_STALE_SEC = 900;

	/**
	 * How far back the expiry sweep looks for `expired` rows whose cancellation may have been cut short.
	 */
	const EXPIRED_RECHECK_SEC = 86400;

	/**
	 * Sweeps take at most this many orders per run.
	 */
	const SWEEP_LIMIT = 200;

	/**
	 * Columns an upsert never changes on an existing row: the first process_payment() time, everything the
	 * customer's transaction id and its rate limit depend on, and the money already received.
	 */
	const UPSERT_KEEP = array( 'order_id', 'created_at', 'claim_txn', 'claim_at', 'claim_tries', 'paid_minor' );

	/**
	 * Columns a new row needs.
	 */
	const REQUIRED = array( 'order_id', 'wallet', 'payer_key', 'price_minor', 'state', 'expires_at' );

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
	 * One row.
	 *
	 * @param int $order_id Order id.
	 * @return array|null
	 */
	public function get( int $order_id ): ?array {
		$db  = $this->db();
		$row = $db->row( 'SELECT * FROM %i WHERE order_id = %d', array( $db->table( 'orders' ), $order_id ) );
		return null === $row ? null : Db::typed( 'orders', $row );
	}

	/**
	 * Writes the row of an order (`INSERT … ON DUPLICATE KEY UPDATE` by order_id, docs/17 §4.3). A new row needs
	 * order_id, wallet, payer_key, price_minor, state and expires_at; created_at defaults to now. On an existing row
	 * every given column is updated except order_id, created_at (the first process_payment()), claim_txn, claim_at,
	 * claim_tries (docs/17 §4.7: kept on a second process_payment(); claim_at is what the ten-minute limit counts
	 * from) and paid_minor (money already received never goes back to zero by a retry). Use update() to change
	 * those. The update half repeats the values instead of using VALUES(), which MySQL 8 deprecates.
	 *
	 * @param array $row Column => value.
	 * @throws \InvalidArgumentException When a new row lacks a required column, or a value does not fit its column.
	 */
	public function upsert( array $row ): void {
		foreach ( self::REQUIRED as $column ) {
			if ( ! isset( $row[ $column ] ) ) {
				throw new \InvalidArgumentException( 'qersh: orders.' . rawurlencode( $column ) . ' is required' );
			}
		}
		if ( ! isset( $row['created_at'] ) ) {
			$row['created_at'] = time();
		}
		$db = $this->db();

		list( $sql, $args ) = $db->insert_sql( 'orders', $row, 'INSERT' );

		$changes = array_diff_key( $row, array_flip( self::UPSERT_KEEP ) );

		list( $set, $set_args ) = Db::set_sql( 'orders', $changes );
		if ( '' === $set ) {
			$set = 'order_id = order_id';
		}
		$db->exec( $sql . ' ON DUPLICATE KEY UPDATE ' . $set, array_merge( $args, $set_args ) );
	}

	/**
	 * Updates fields of one row.
	 *
	 * @param int   $order_id Order id.
	 * @param array $fields   Column => value (not order_id).
	 */
	public function update( int $order_id, array $fields ): void {
		unset( $fields['order_id'] );
		if ( empty( $fields ) ) {
			return;
		}
		$db = $this->db();

		list( $set, $args ) = Db::set_sql( 'orders', $fields );
		$db->exec(
			'UPDATE %i SET ' . $set . ' WHERE order_id = %d',
			array_merge( array( $db->table( 'orders' ) ), $args, array( $order_id ) )
		);
	}

	/**
	 * Deletes the row of an order (woocommerce_delete_order).
	 *
	 * @param int $order_id Order id.
	 */
	public function delete( int $order_id ): void {
		$db = $this->db();
		$db->exec( 'DELETE FROM %i WHERE order_id = %d', array( $db->table( 'orders' ), $order_id ) );
	}

	/**
	 * Every row of a payer whose life overlaps a time window: created at or before `$to` and expiring at or after
	 * `$from` (the kit's candidate query is `expires_at >= received_at − late window AND created_at <=
	 * received_at + skew`). All states come back; the caller sorts candidates from blockers (docs/17 §4.4 step 8).
	 * Oldest first.
	 *
	 * @param string $payer_key Payer key.
	 * @param int    $from      UTC seconds.
	 * @param int    $to        UTC seconds.
	 * @return array[]
	 */
	public function for_payer( string $payer_key, int $from, int $to ): array {
		$db = $this->db();
		return $this->typed_rows(
			$db->rows(
				'SELECT * FROM %i WHERE payer_key = %s AND created_at <= %d AND expires_at >= %d ORDER BY created_at ASC, order_id ASC',
				array( $db->table( 'orders' ), $payer_key, $to, $from )
			)
		);
	}

	/**
	 * Every row of a wallet whose life overlaps a time window (same rule as for_payer()), for transfers without a
	 * payer (docs/17 §4.6 rules 4a gate 6 and 5). Oldest first.
	 *
	 * @param string $wallet Wallet id.
	 * @param int    $from   UTC seconds.
	 * @param int    $to     UTC seconds.
	 * @return array[]
	 */
	public function for_wallet( string $wallet, int $from, int $to ): array {
		$db = $this->db();
		return $this->typed_rows(
			$db->rows(
				'SELECT * FROM %i WHERE wallet = %s AND created_at <= %d AND expires_at >= %d ORDER BY created_at ASC, order_id ASC',
				array( $db->table( 'orders' ), $wallet, $to, $from )
			)
		);
	}

	/**
	 * Every order whose customer typed this canonical transaction id, in any state (live and dead owners, docs/17
	 * §4.6 rule 4a). Earliest claim first.
	 *
	 * @param string $txn Canonical transaction id.
	 * @return array[]
	 */
	public function claimants( string $txn ): array {
		$db = $this->db();
		return $this->typed_rows(
			$db->rows(
				'SELECT * FROM %i WHERE claim_txn = %s ORDER BY claim_at ASC, order_id ASC',
				array( $db->table( 'orders' ), $txn )
			)
		);
	}

	/**
	 * The atomic counter of the transaction-id route (docs/17 §4.10 step 5): stores the id and counts one try, if
	 * fewer than five were used and either fewer than three or the last one is ten minutes old.
	 *
	 * @param int    $order_id Order id.
	 * @param string $txn      Canonical transaction id.
	 * @param int    $now      UTC seconds.
	 * @return string `ok`, `locked` (five used, 409 qersh_txn_locked) or `wait:<seconds>` (429 qersh_txn_wait).
	 */
	public function bump_claim( int $order_id, string $txn, int $now ): string {
		$db = $this->db();
		Db::value( 'orders', 'claim_txn', $txn );
		$n = $db->exec(
			'UPDATE %i SET claim_tries = claim_tries + 1, claim_at = %d, claim_txn = %s WHERE order_id = %d AND claim_tries < %d AND ( claim_tries < %d OR claim_at IS NULL OR claim_at < %d )',
			array( $db->table( 'orders' ), $now, $txn, $order_id, self::CLAIM_MAX, self::CLAIM_FREE, $now - self::CLAIM_WAIT_SEC )
		);
		if ( 1 === $n ) {
			return 'ok';
		}
		return self::claim_verdict( $this->get( $order_id ), $now );
	}

	/**
	 * Why bump_claim() changed nothing. Pure.
	 *
	 * @param array|null $row Row of the order.
	 * @param int        $now UTC seconds.
	 * @return string `locked` or `wait:<seconds>`.
	 */
	public static function claim_verdict( ?array $row, int $now ): string {
		if ( null === $row ) {
			return 'locked';
		}
		$tries = isset( $row['claim_tries'] ) ? (int) $row['claim_tries'] : 0;
		$at    = isset( $row['claim_at'] ) ? (int) $row['claim_at'] : null;
		if ( $tries >= self::CLAIM_MAX ) {
			return 'locked';
		}
		if ( $tries >= self::CLAIM_FREE && null !== $at && $at >= $now - self::CLAIM_WAIT_SEC ) {
			return 'wait:' . max( 1, $at + self::CLAIM_WAIT_SEC - $now );
		}
		return 'locked';
	}

	/**
	 * The second fence of rule 4a (docs/17 §4.6): the order is still this transaction id's live owner, on this
	 * wallet, for this payer. Rule 4a always ends in `matched` (gate 5 asks for the exact remainder), so the fence
	 * writes state = 'paid'; MySQL counts only changed rows, and every allowed state differs from 'paid'. Pass the
	 * other columns of the decision (paid_minor, paid_at, completion…) in `$set` to make it the one write. False:
	 * roll back.
	 *
	 * @param int    $order_id  Order id.
	 * @param string $txn       Canonical transaction id.
	 * @param string $wallet    Wallet the transfer arrived on.
	 * @param string $payer_key The order's payer key.
	 * @param array  $set       Optional more columns to write (not state, order_id or the claim columns).
	 * @return bool
	 */
	public function fence_claimant( int $order_id, string $txn, string $wallet, string $payer_key, array $set = array() ): bool {
		unset( $set['state'], $set['order_id'], $set['claim_txn'], $set['claim_at'], $set['claim_tries'] );
		$db = $this->db();

		list( $more, $more_args ) = Db::set_sql( 'orders', $set );

		$n = $db->exec(
			"UPDATE %i SET state = 'paid'" . ( '' === $more ? '' : ', ' . $more ) . " WHERE order_id = %d AND claim_txn = %s AND wallet = %s AND payer_key = %s AND state IN ('open','partial','expired')",
			array_merge( array( $db->table( 'orders' ) ), $more_args, array( $order_id, $txn, $wallet, $payer_key ) )
		);
		return 1 === $n;
	}

	/**
	 * The completion claim (docs/17 §4.7): every path to payment_complete() takes the row first, and only one wins.
	 *
	 * @param int $order_id Order id.
	 * @param int $now      UTC seconds.
	 * @return bool
	 */
	public function claim_completion( int $order_id, int $now ): bool {
		$db = $this->db();
		$n  = $db->exec(
			"UPDATE %i SET completion = 'running', completion_at = %d WHERE order_id = %d AND state = 'paid' AND ( completion = 'due' OR ( completion = 'running' AND ( completion_at IS NULL OR completion_at < %d ) ) )",
			array( $db->table( 'orders' ), $now, $order_id, $now - self::COMPLETION_STALE_SEC )
		);
		return 1 === $n;
	}

	/**
	 * The expiry write (docs/17 §4.7), under the payer lock: true only when the row was still `open`, and only then
	 * may the WooCommerce order be cancelled.
	 *
	 * @param int $order_id Order id.
	 * @return bool
	 */
	public function set_expired_if_open( int $order_id ): bool {
		$db = $this->db();
		$n  = $db->exec(
			"UPDATE %i SET state = 'expired' WHERE order_id = %d AND state = 'open'",
			array( $db->table( 'orders' ), $order_id )
		);
		return 1 === $n;
	}

	/**
	 * Orders the completion sweep should finish: decided `paid` before a time (now − 120 s) and still `due` or
	 * `running`, whatever the WooCommerce status. Oldest first, at most 200.
	 *
	 * @param int $before UTC seconds.
	 * @return int[] Order ids.
	 */
	public function due_completions( int $before ): array {
		$db = $this->db();
		return $this->ids(
			$db->rows(
				"SELECT order_id FROM %i WHERE state = 'paid' AND completion IN ('due','running') AND ( paid_at IS NULL OR paid_at < %d ) ORDER BY paid_at ASC, order_id ASC LIMIT %d",
				array( $db->table( 'orders' ), $before, self::SWEEP_LIMIT )
			)
		);
	}

	/**
	 * Orders the expiry sweep should look at, at most 200:
	 * 1. first, `open` or `partial` rows whose deadline plus the late window has passed, oldest deadline first;
	 * 2. then, with what is left of the 200, `expired` rows of the last day (a cancellation that was cut short),
	 *    newest deadline first, since a cut-short cancellation is most likely the latest one.
	 * Every normal expiry leaves its row `expired`, so on a busy store the second group can be large; it never
	 * pushes a due order out of the first. The handler re-reads each one under the payer lock; it must move a partial
	 * row out of `partial` (to `review`) once handled, or it comes back every sweep.
	 *
	 * @param int $now  UTC seconds.
	 * @param int $late The late window in SECONDS (late_window_min × 60).
	 * @return int[] Order ids.
	 */
	public function due_expiries( int $now, int $late ): array {
		$db     = $this->db();
		$table  = $db->table( 'orders' );
		$cutoff = $now - $late;
		$due    = $this->ids(
			$db->rows(
				"SELECT order_id FROM %i WHERE state IN ('open','partial') AND expires_at <= %d ORDER BY expires_at ASC, order_id ASC LIMIT %d",
				array( $table, $cutoff, self::SWEEP_LIMIT )
			)
		);
		$left   = self::SWEEP_LIMIT - count( $due );
		if ( $left <= 0 ) {
			return $due;
		}
		$recheck = $this->ids(
			$db->rows(
				"SELECT order_id FROM %i WHERE state = 'expired' AND expires_at > %d AND expires_at <= %d ORDER BY expires_at DESC, order_id DESC LIMIT %d",
				array( $table, $cutoff - self::EXPIRED_RECHECK_SEC, $cutoff, $left )
			)
		);
		return array_values( array_unique( array_merge( $due, $recheck ) ) ); // A row may expire between the two reads.
	}

	/**
	 * Order ids of rows.
	 *
	 * @param array[] $rows Rows with order_id.
	 * @return int[]
	 */
	private function ids( array $rows ): array {
		$ids = array();
		foreach ( $rows as $row ) {
			if ( isset( $row['order_id'] ) ) {
				$ids[] = (int) $row['order_id'];
			}
		}
		return $ids;
	}

	/**
	 * Types every row.
	 *
	 * @param array[] $rows Rows.
	 * @return array[]
	 */
	private function typed_rows( array $rows ): array {
		$out = array();
		foreach ( $rows as $row ) {
			$out[] = Db::typed( 'orders', $row );
		}
		return $out;
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
