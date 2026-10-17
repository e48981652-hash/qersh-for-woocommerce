<?php
/**
 * Thrown when the database gives up waiting for a lock, or breaks a deadlock (docs/17 §4.4 step 8).
 *
 * @package QershPay\WooCommerce
 */

namespace QershPay\WooCommerce\Store;

defined( 'ABSPATH' ) || exit;

/**
 * MySQL errors 1205 ("Lock wait timeout exceeded") and 1213 ("Deadlock found", also how Galera reports a
 * certification conflict), and SQLite "database is locked". The caller rolls back, retries once, then answers 429.
 * The message never carries SQL or values.
 */
final class LockTimeout extends \RuntimeException {

	/**
	 * Builds the exception with a fixed message.
	 *
	 * @param string $message Optional fixed text (never SQL or data).
	 */
	public function __construct( string $message = 'qersh: lock wait timeout or deadlock' ) {
		parent::__construct( $message );
	}
}
