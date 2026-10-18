<?php
/**
 * Transfers the phone delivered, with the store's verdict on each (docs/17 §4.3, §4.4 steps 8–10, §4.6).
 *
 * @package QershPay\WooCommerce
 */

namespace QershPay\WooCommerce\Store;

use QershPay\WooCommerce\Core\Payer;

defined( 'ABSPATH' ) || exit;

/**
 * Rows of `{prefix}qersh_transfers`. Rows come back typed: integer columns as int, `risk_reasons` as an array.
 * Queries that carry a payer number go through Db, which suppresses errors so the number never reaches a log.
 */
final class Transfers {

	/**
	 * A status update rides on this many replies at most (docs/17 §4.4 step 10).
	 */
	const SYNC_REPLIES = 3;

	/**
	 * …or for this many seconds after phone_sync_since, whichever ends first.
	 */
	const SYNC_SEC = 604800;

	/**
	 * What "needs a decision" means (docs/17 §4.6): review and overpaid, transfers flagged as forged, a partial
	 * payment past its deadline, and a customer's transaction id waiting for the merchant at the deadline.
	 *
	 * An overdue reason counts whatever the outcome, until the merchant decides: an attach clears the reason, a
	 * status set by hand clears it, a cancellation turns the row to review, and only a dismissed row leaves with the
	 * reason kept. `partial_overdue` marks every transfer that paid the partial order (docs/17 §4.7), `partial` or
	 * `attached` alike (Expiry::hold_partial()), so an order paid in part only by a merchant's attach shows too.
	 */
	const NEEDS_DECISION_SQL = "( outcome IN ('review','overpaid') OR ( flag = 'forgery' AND ( outcome IS NULL OR outcome <> 'dismissed' ) ) OR ( reason IN ('partial_overdue','claim_overdue') AND ( outcome IS NULL OR outcome <> 'dismissed' ) ) )";

	/**
	 * Columns insert() requires. `provider` is not one: it is the envelope's `wallet.provider`, kept as information
	 * only, and '' when the row leaves it out.
	 */
	const REQUIRED = array( 'transfer_id', 'wallet', 'delivery_id', 'currency', 'money_at' );

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
	 * One transfer.
	 *
	 * @param string $transfer_id Transfer id (`trf_…`).
	 * @return array|null
	 */
	public function get( string $transfer_id ): ?array {
		$db  = $this->db();
		$row = $db->row( 'SELECT * FROM %i WHERE transfer_id = %s', array( $db->table( 'transfers' ), $transfer_id ) );
		return null === $row ? null : Db::typed( 'transfers', $row );
	}

	/**
	 * Every transfer with this canonical transaction id, on any wallet, oldest first (the duplicate-id rule and the
	 * customer's transaction id, docs/17 §4.6 rule 2 and §4.10). The id is compared whole and exactly, never by the
	 * kind of wallet; a caller that needs one wallet's transfers keeps the rows whose `wallet` is that wallet.
	 *
	 * @param string $txn Canonical transaction id.
	 * @return array[]
	 */
	public function find_by_txn( string $txn ): array {
		if ( 'NULL' === Db::value( 'transfers', 'provider_txn_id', $txn )[0] ) {
			return array(); // Empty or longer than the column: such an id is never stored, so it equals no row.
		}
		$db = $this->db();
		return $this->typed_rows(
			$db->rows(
				'SELECT * FROM %i WHERE provider_txn_id = %s ORDER BY id ASC',
				array( $db->table( 'transfers' ), $txn )
			)
		);
	}

	/**
	 * Inserts a transfer with `INSERT IGNORE`. False when a row with this transfer_id already exists (its stored
	 * outcome is then the answer, docs/17 §4.4 step 8). `created_at` and `updated_at` default to now; arrays (such as
	 * `risk_reasons`) are stored as JSON; every value is checked against the column first, because IGNORE would turn
	 * a value that does not fit into a silent cut.
	 *
	 * @param array $row Column => value.
	 * @return bool True when the row was inserted.
	 * @throws \InvalidArgumentException When a required column is missing, or a value does not fit its column.
	 */
	public function insert( array $row ): bool {
		foreach ( self::REQUIRED as $column ) {
			if ( ! isset( $row[ $column ] ) ) {
				throw new \InvalidArgumentException( 'qersh: transfers.' . rawurlencode( $column ) . ' is required' );
			}
		}
		unset( $row['id'] );
		// Written, never left to the column's default: a store upgraded from schema 1 may still have the column
		// without one (the SQLite drop-in does not apply dbDelta's SET DEFAULT), and there INSERT IGNORE would skip
		// a row missing a NOT NULL value instead of failing.
		if ( ! isset( $row['provider'] ) ) {
			$row['provider'] = '';
		}
		$now = time();
		if ( ! isset( $row['created_at'] ) ) {
			$row['created_at'] = $now;
		}
		if ( ! isset( $row['updated_at'] ) ) {
			$row['updated_at'] = $row['created_at'];
		}
		$db = $this->db();

		list( $sql, $args ) = $db->insert_sql( 'transfers', $row, 'INSERT IGNORE' );
		return 1 === $db->exec( $sql, $args );
	}

	/**
	 * Updates fields of one transfer; `updated_at` is set to now unless given.
	 *
	 * @param string $transfer_id Transfer id.
	 * @param array  $fields      Column => value (not `id` or `transfer_id`).
	 */
	public function update( string $transfer_id, array $fields ): void {
		unset( $fields['id'], $fields['transfer_id'] );
		if ( empty( $fields ) ) {
			return;
		}
		if ( ! array_key_exists( 'updated_at', $fields ) ) {
			$fields['updated_at'] = time();
		}
		$db = $this->db();

		list( $set, $args ) = Db::set_sql( 'transfers', $fields );
		$db->exec(
			'UPDATE %i SET ' . $set . ' WHERE transfer_id = %s',
			array_merge( array( $db->table( 'transfers' ) ), $args, array( $transfer_id ) )
		);
	}

	/**
	 * The first fence of rule 4a (docs/17 §4.6): the transfer becomes `matched` to the order only while it has no
	 * order and no verdict yet, or is still the `review` row of a payerless transfer (`no_payer`, or `masked` when
	 * the payer's number arrived hidden) that waits for a customer's transaction id. False means someone else decided
	 * it first: roll back.
	 *
	 * @param string $transfer_id Transfer id.
	 * @param int    $order_id    Order id.
	 * @return bool
	 */
	public function fence_match( string $transfer_id, int $order_id ): bool {
		$db = $this->db();
		$n  = $db->exec(
			"UPDATE %i SET outcome = 'matched', order_id = %d, updated_at = %d WHERE transfer_id = %s AND order_id IS NULL AND ( outcome IS NULL OR ( outcome = 'review' AND reason IN ('no_payer','masked') ) )",
			array( $db->table( 'transfers' ), $order_id, time(), $transfer_id )
		);
		return 1 === $n;
	}

	/**
	 * Marks a transfer for the phone: the next replies to its wallet carry its new status (attach, dismiss, a match
	 * by the customer's transaction id), docs/17 §4.4 step 10.
	 *
	 * @param string $transfer_id Transfer id.
	 * @param int    $now         UTC seconds.
	 */
	public function request_sync( string $transfer_id, int $now ): void {
		$this->update(
			$transfer_id,
			array(
				'phone_sync'       => 'pending',
				'phone_sync_since' => $now,
				'phone_sync_count' => 0,
			)
		);
	}

	/**
	 * Transfers of a wallet whose new status the phone still has to hear: pending, sent in fewer than three replies,
	 * and marked less than seven days ago. Oldest first.
	 *
	 * @param string $wallet Wallet id.
	 * @param int    $now    UTC seconds.
	 * @param int    $limit  At most this many (50 by default).
	 * @return array[]
	 */
	public function pending_sync( string $wallet, int $now, int $limit = 50 ): array {
		$db = $this->db();
		return $this->typed_rows(
			$db->rows(
				"SELECT * FROM %i WHERE wallet = %s AND phone_sync = 'pending' AND phone_sync_count < %d AND phone_sync_since >= %d ORDER BY phone_sync_since ASC, id ASC LIMIT %d",
				array( $db->table( 'transfers' ), $wallet, self::SYNC_REPLIES, $now - self::SYNC_SEC, max( 1, min( 200, $limit ) ) )
			)
		);
	}

	/**
	 * Counts one more reply that carried these transfers' status updates. After the third reply, or seven days
	 * after phone_sync_since, a transfer goes back to `none`. The CASE comes before the counter on purpose: MySQL
	 * evaluates SET from left to right, SQLite on the old row, and both then read the same old counter.
	 *
	 * @param string[] $transfer_ids Transfer ids included in the reply.
	 * @param int      $now          UTC seconds.
	 */
	public function note_synced( array $transfer_ids, int $now ): void {
		$ids = array();
		foreach ( $transfer_ids as $id ) {
			if ( is_string( $id ) && '' !== $id && ! in_array( $id, $ids, true ) ) {
				$ids[] = $id;
			}
		}
		if ( empty( $ids ) ) {
			return;
		}
		$db = $this->db();
		foreach ( array_chunk( $ids, 100 ) as $chunk ) {
			$in = implode( ', ', array_fill( 0, count( $chunk ), '%s' ) );
			$db->exec(
				"UPDATE %i SET phone_sync = CASE WHEN phone_sync_count >= %d OR phone_sync_since IS NULL OR phone_sync_since < %d THEN 'none' ELSE 'pending' END, phone_sync_count = phone_sync_count + 1 WHERE phone_sync = 'pending' AND transfer_id IN (" . $in . ')',
				array_merge( array( $db->table( 'transfers' ), self::SYNC_REPLIES - 1, $now - self::SYNC_SEC ), $chunk )
			);
		}
	}

	/**
	 * One page of transfers for the admin list.
	 *
	 * Filter keys (all optional): `view` (`needs_decision`, `unmatched` without personal ones, `personal`,
	 * `matched` = matched/partial/attached, `dismissed`, or `all`), `wallet`, `outcome` (string or list),
	 * `order_id`, `payer_key`, `from` and `to` (created_at, UTC seconds), `search` (transfer id, transaction id,
	 * payer number, or part of the payer name), `orderby` (`created_at`, `money_at`, `amount_minor`, `updated_at`)
	 * and `order` (`ASC` or `DESC`, newest first by default). There is no filter by kind of wallet: a `provider` key
	 * is ignored.
	 *
	 * @param array $filter   Filter.
	 * @param int   $page     Page, from 1.
	 * @param int   $per_page Rows per page (1–200).
	 * @return array `[ 'rows' => array[], 'total' => int, 'page' => int, 'per_page' => int ]`
	 */
	public function list_page( array $filter, int $page, int $per_page ): array {
		$db       = $this->db();
		$page     = max( 1, $page );
		$per_page = max( 1, min( 200, $per_page ) );
		$where    = self::where( $filter, $db->wpdb() );
		$table    = $db->table( 'transfers' );

		$orderby = isset( $filter['orderby'] ) && in_array( $filter['orderby'], array( 'created_at', 'money_at', 'amount_minor', 'updated_at' ), true ) ? $filter['orderby'] : 'created_at';
		$order   = isset( $filter['order'] ) && 'ASC' === strtoupper( (string) $filter['order'] ) ? 'ASC' : 'DESC';

		$total = (int) $db->var( 'SELECT COUNT(*) FROM %i WHERE ' . $where[0], array_merge( array( $table ), $where[1] ) );
		$rows  = $db->rows(
			'SELECT * FROM %i WHERE ' . $where[0] . ' ORDER BY ' . $orderby . ' ' . $order . ', id ' . $order . ' LIMIT %d OFFSET %d',
			array_merge( array( $table ), $where[1], array( $per_page, ( $page - 1 ) * $per_page ) )
		);
		return array(
			'rows'     => $this->typed_rows( $rows ),
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
		);
	}

	/**
	 * How many transfers need a decision (the menu badge). Personal transfers are not counted.
	 *
	 * @return int
	 */
	public function count_needs_decision(): int {
		$db = $this->db();
		return (int) $db->var( 'SELECT COUNT(*) FROM %i WHERE ' . self::NEEDS_DECISION_SQL, array( $db->table( 'transfers' ) ) );
	}

	/**
	 * Retention (docs/17 §4.3): erases the payer number and name of transfers that arrived before a time (now −
	 * unmatched_retention_days) and paid no order, or whose order is gone (its qersh_orders row was deleted with the
	 * order; forget_order() does that at once, this catches what it missed). Transfers linked to an order keep them
	 * while the order exists; the WooCommerce eraser handles those.
	 *
	 * @param int $before UTC seconds.
	 * @return int Rows changed.
	 */
	public function purge_personal( int $before ): int {
		$db = $this->db();
		return $db->exec(
			'UPDATE %i SET payer_key = NULL, payer_name = NULL WHERE created_at < %d AND ( payer_key IS NOT NULL OR payer_name IS NOT NULL ) AND ( order_id IS NULL OR order_id NOT IN ( SELECT order_id FROM %i ) )',
			array( $db->table( 'transfers' ), $before, $db->table( 'orders' ) )
		);
	}

	/**
	 * The order is deleted (woocommerce_delete_order): its transfers lose the payer number and name now, since they
	 * were kept only while the order remained (docs/17 §4.3). The rest of the row (amount, outcome, order id) stays
	 * for the merchant's records.
	 *
	 * @param int $order_id Order id.
	 * @return int Rows changed.
	 */
	public function forget_order( int $order_id ): int {
		if ( $order_id <= 0 ) {
			return 0;
		}
		$db = $this->db();
		return $db->exec(
			'UPDATE %i SET payer_key = NULL, payer_name = NULL, updated_at = %d WHERE order_id = %d AND ( payer_key IS NOT NULL OR payer_name IS NOT NULL )',
			array( $db->table( 'transfers' ), time(), $order_id )
		);
	}

	/**
	 * The WHERE clause of list_page(). Pure apart from esc_like().
	 *
	 * @param array $filter Filter.
	 * @param mixed $wpdb   Database object (for esc_like).
	 * @return array{0: string, 1: array} SQL and arguments.
	 */
	private static function where( array $filter, $wpdb ): array {
		$sql  = array();
		$args = array();
		$view = isset( $filter['view'] ) ? (string) $filter['view'] : 'all';
		if ( 'needs_decision' === $view ) {
			$sql[] = self::NEEDS_DECISION_SQL;
		} elseif ( 'unmatched' === $view ) {
			$sql[] = "outcome = 'unmatched' AND ( reason IS NULL OR reason <> 'personal' )";
		} elseif ( 'personal' === $view ) {
			$sql[] = "outcome = 'unmatched' AND reason = 'personal'";
		} elseif ( 'matched' === $view ) {
			$sql[] = "outcome IN ('matched','partial','attached')";
		} elseif ( 'dismissed' === $view ) {
			$sql[] = "outcome = 'dismissed'";
		}
		foreach ( array( 'wallet', 'payer_key' ) as $column ) {
			if ( isset( $filter[ $column ] ) && is_string( $filter[ $column ] ) && '' !== $filter[ $column ] ) {
				$sql[]  = $column . ' = %s';
				$args[] = $filter[ $column ];
			}
		}
		if ( isset( $filter['outcome'] ) ) {
			$outcomes = array_values( array_filter( (array) $filter['outcome'], 'is_string' ) );
			if ( ! empty( $outcomes ) ) {
				$sql[] = 'outcome IN (' . implode( ', ', array_fill( 0, count( $outcomes ), '%s' ) ) . ')';
				$args  = array_merge( $args, $outcomes );
			}
		}
		if ( isset( $filter['order_id'] ) && (int) $filter['order_id'] > 0 ) {
			$sql[]  = 'order_id = %d';
			$args[] = (int) $filter['order_id'];
		}
		if ( isset( $filter['from'] ) && is_int( $filter['from'] ) ) {
			$sql[]  = 'created_at >= %d';
			$args[] = $filter['from'];
		}
		if ( isset( $filter['to'] ) && is_int( $filter['to'] ) ) {
			$sql[]  = 'created_at <= %d';
			$args[] = $filter['to'];
		}
		if ( isset( $filter['search'] ) && is_string( $filter['search'] ) && '' !== trim( $filter['search'] ) ) {
			$term   = trim( $filter['search'] );
			$payer  = Payer::normalize( $term );
			$sql[]  = '( transfer_id = %s OR provider_txn_id = %s OR payer_key = %s OR payer_name LIKE %s )';
			$args[] = $term;
			$args[] = $term;
			$args[] = null === $payer ? $term : $payer;
			$args[] = '%' . $wpdb->esc_like( $term ) . '%';
		}
		return array( empty( $sql ) ? '1 = 1' : implode( ' AND ', $sql ), $args );
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
			$out[] = Db::typed( 'transfers', $row );
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
