<?php
/**
 * Runtime state of each wallet, and the witness row a deleted wallet leaves behind (docs/17 §4.3, §4.4 step 3).
 *
 * @package QershPay\WooCommerce
 */

namespace QershPay\WooCommerce\Store;

defined( 'ABSPATH' ) || exit;

/**
 * Rows of `{prefix}qersh_wallets`. None of this is configuration: it never goes into qersh_wc_config or the
 * export, and it works the same with QERSH_WC_CONFIG. Each write is one single-row statement, so two deliveries
 * and an admin save never overwrite each other.
 */
final class Wallets {

	/**
	 * A deleted wallet answers 404 for this long, then 410 (the phone's per-delivery reject window, 72 hours).
	 */
	const GONE_SEC = 259200;

	/**
	 * Columns touch() may write: what deliveries and tests report. State, deletion and key columns have their own
	 * methods.
	 */
	const TOUCH_COLUMNS = array(
		'last_delivery_at',
		'last_event',
		'last_heartbeat_at',
		'last_device_state',
		'grace_until',
		'sender_masked',
		'auto_seen',
		'seen_host',
		'seen_form',
		'last_test_at',
		'last_test_app_version',
		'last_test_provider',
		'last_test_number_masked',
		'silent_since',
		'silent_notified',
	);

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
	 * One row (snapshot and last_device_state decoded to arrays).
	 *
	 * @param string $wallet Wallet id.
	 * @return array|null
	 */
	public function get( string $wallet ): ?array {
		$db  = $this->db();
		$row = $db->row( 'SELECT * FROM %i WHERE wallet = %s', array( $db->table( 'wallets' ), $wallet ) );
		return null === $row ? null : Db::typed( 'wallets', $row );
	}

	/**
	 * Every row, keyed by wallet id.
	 *
	 * @return array<string, array>
	 */
	public function all(): array {
		$db  = $this->db();
		$out = array();
		foreach ( $db->rows( 'SELECT * FROM %i ORDER BY wallet ASC', array( $db->table( 'wallets' ) ) ) as $row ) {
			$row                            = Db::typed( 'wallets', $row );
			$out[ (string) $row['wallet'] ] = $row;
		}
		return $out;
	}

	/**
	 * Makes sure the wallet has a row (`active` when new). Never changes an existing row.
	 *
	 * @param string $wallet Wallet id.
	 */
	public function ensure( string $wallet ): void {
		$db = $this->db();
		$db->exec(
			'INSERT IGNORE INTO %i (wallet, state) VALUES (%s, %s)',
			array( $db->table( 'wallets' ), Db::value( 'wallets', 'wallet', $wallet )[1], 'active' )
		);
	}

	/**
	 * Writes delivery and test fields in one UPDATE (docs/17 §4.4 step 7). Arrays (last_device_state) are stored as
	 * JSON, and dropped when longer than the column; strings are cut to their column.
	 *
	 * @param string $wallet Wallet id.
	 * @param array  $fields Column => value, from TOUCH_COLUMNS.
	 */
	public function touch( string $wallet, array $fields ): void {
		$fields = array_intersect_key( $fields, array_flip( self::TOUCH_COLUMNS ) );
		if ( empty( $fields ) ) {
			return;
		}
		$db = $this->db();

		list( $set, $args ) = Db::set_sql( 'wallets', $fields );
		$db->exec(
			'UPDATE %i SET ' . $set . ' WHERE wallet = %s',
			array_merge( array( $db->table( 'wallets' ) ), $args, array( $wallet ) )
		);
	}

	/**
	 * Deletes a wallet: the row becomes the witness (`deleted`, with the config entry kept for a restore), the
	 * pending key is erased and the current key sealed for 72 hours (Secrets::seal()). Only an active (or missing)
	 * row changes, so calling it twice keeps the first deletion time. Each deletion starts a new seal period:
	 * key_erased_at goes back to NULL, so the daily cleanup erases the key sealed now even when an earlier deletion of
	 * the same wallet (before a restore) had its key erased already.
	 *
	 * ConfigStore::save() calls this for every wallet that leaves the configuration.
	 *
	 * @param string $wallet   Wallet id.
	 * @param int    $now      UTC seconds.
	 * @param array  $snapshot The wallets[] entry, for "Restore wallet".
	 */
	public function mark_deleted( string $wallet, int $now, array $snapshot ): void {
		$this->ensure( $wallet );
		$db = $this->db();
		$db->exec(
			'UPDATE %i SET state = %s, deleted_at = %d, gone_at = %d, key_erased_at = NULL, snapshot = %s WHERE wallet = %s AND state = %s',
			array( $db->table( 'wallets' ), 'deleted', $now, $now + self::GONE_SEC, Db::json( $snapshot ), $wallet, 'active' )
		);
		if ( $this->plugin instanceof \QershPay\WooCommerce\Plugin ) {
			$this->plugin->secrets()->seal( $wallet, $now );
			$this->plugin->forget_config(); // The effective configuration leaves deleted wallets out.
		}
	}

	/**
	 * "Restore wallet": the row is active again and the saved entry comes back. Within 72 hours the sealed key is
	 * usable again (same id, link and key, nothing to do on the phone); after that the key is erased, so the wallet
	 * answers 401 no_key until it gets a new one (docs/17 §4.4 step 3). Then add the entry back to the
	 * configuration with ConfigStore::save() (with QERSH_WC_CONFIG the constant's entry is used as it is).
	 *
	 * @param string $wallet Wallet id.
	 * @return array|null The saved wallets[] entry (at least `[ 'id' => … ]`), or null when the wallet is not
	 *                    deleted.
	 */
	public function restore( string $wallet ): ?array {
		$row = $this->get( $wallet );
		if ( null === $row || 'deleted' !== $row['state'] ) {
			return null;
		}
		$now = time();
		$db  = $this->db();
		$n   = $db->exec(
			'UPDATE %i SET state = %s, deleted_at = NULL, gone_at = NULL WHERE wallet = %s AND state = %s',
			array( $db->table( 'wallets' ), 'active', $wallet, 'deleted' )
		);
		if ( 1 !== $n ) {
			return null;
		}
		if ( $this->plugin instanceof \QershPay\WooCommerce\Plugin ) {
			if ( 'deleted_recent' === self::gone_state( $row, $now ) ) {
				$this->plugin->secrets()->unseal( $wallet );
			} else {
				$this->erase_key( $wallet, $now );
			}
			$this->plugin->forget_config();
		}
		$snapshot = is_array( $row['snapshot'] ) ? $row['snapshot'] : array();
		if ( ! isset( $snapshot['id'] ) ) {
			$snapshot['id'] = $wallet;
		}
		return $snapshot;
	}

	/**
	 * Where a wallet stands for the webhook's step 3.
	 *
	 * @param string $wallet Wallet id.
	 * @param int    $now    UTC seconds.
	 * @return string `active`, `deleted_recent` (404 qersh_wallet_deleted), `gone` (410) or `unknown` (no row).
	 */
	public function gone( string $wallet, int $now ): string {
		return self::gone_state( $this->get( $wallet ), $now );
	}

	/**
	 * The answer of gone() for a row already read. Pure.
	 *
	 * @param array|null $row Row, or null.
	 * @param int        $now UTC seconds.
	 * @return string
	 */
	public static function gone_state( ?array $row, int $now ): string {
		if ( null === $row ) {
			return 'unknown';
		}
		if ( ! isset( $row['state'] ) || 'deleted' !== $row['state'] ) {
			return 'active';
		}
		if ( isset( $row['gone_at'] ) ) {
			$gone_at = (int) $row['gone_at'];
		} else {
			$gone_at = ( isset( $row['deleted_at'] ) ? (int) $row['deleted_at'] : 0 ) + self::GONE_SEC;
		}
		return $now < $gone_at ? 'deleted_recent' : 'gone';
	}

	/**
	 * Erases the key of a wallet now ("Erase the connection key now", or the daily cleanup) and records when.
	 *
	 * @param string $wallet Wallet id.
	 * @param int    $now    UTC seconds.
	 */
	public function erase_key( string $wallet, int $now ): void {
		if ( $this->plugin instanceof \QershPay\WooCommerce\Plugin ) {
			$this->plugin->secrets()->erase( $wallet );
		}
		$db = $this->db();
		$db->exec(
			'UPDATE %i SET key_erased_at = %d WHERE wallet = %s AND key_erased_at IS NULL',
			array( $db->table( 'wallets' ), $now, $wallet )
		);
	}

	/**
	 * Daily cleanup: erases the sealed keys of wallets deleted 72 hours ago or more.
	 *
	 * @param int $now UTC seconds.
	 * @return int Wallets whose key was erased.
	 */
	public function erase_due_keys( int $now ): int {
		$db   = $this->db();
		$rows = $db->rows(
			'SELECT wallet FROM %i WHERE state = %s AND key_erased_at IS NULL AND gone_at <= %d',
			array( $db->table( 'wallets' ), 'deleted', $now )
		);
		foreach ( $rows as $row ) {
			$this->erase_key( (string) $row['wallet'], $now );
		}
		return count( $rows );
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
