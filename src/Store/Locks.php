<?php
/**
 * Cleanup of the lock rows (docs/17 §4.3).
 *
 * @package QershPay\WooCommerce
 */

namespace QershPay\WooCommerce\Store;

defined( 'ABSPATH' ) || exit;

/**
 * Rows of `{prefix}qersh_locks`: one per lock name, refreshed on every use and never deleted inside a transaction.
 * Taking locks is Db::lock().
 */
final class Locks {

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
	 * Daily cleanup, outside any transaction: deletes lock rows not used since a time (now − one day). A row a
	 * transaction holds right now carries a fresh created_at once that transaction commits, so it is not deleted.
	 *
	 * @param int $before UTC seconds.
	 * @return int Rows deleted.
	 */
	public function purge( int $before ): int {
		$db = $this->db();
		if ( $db->in_transaction() ) {
			throw new \LogicException( 'qersh: lock rows are purged outside a transaction' );
		}
		return $db->exec( 'DELETE FROM %i WHERE created_at < %d', array( $db->table( 'locks' ), $before ) );
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
