<?php
/**
 * uninstall.php (docs/17 §4.1): the scheduled actions always go; the tables, options and connection keys go only
 * when the merchant chose "remove the data on uninstall" (store.remove_data_on_uninstall). QERSH_WC_REMOVE_ALL_DATA
 * cannot be undefined inside one PHP process, so Uninstall::should_remove() covers it in the unit tests.
 *
 * @package QershPay\WooCommerce\Tests
 */

namespace QershPay\WooCommerce\Tests\Integration;

use QershPay\WooCommerce\Store\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Uninstall with and without the setting.
 */
class UninstallTest extends TestCase {

	/**
	 * Runs uninstall.php as WordPress does.
	 */
	private function uninstall() {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'qersh-for-woocommerce/qersh-for-woocommerce.php' );
		}
		require QERSH_WC_TESTS_ROOT . '/uninstall.php';
	}

	/**
	 * Whether each of our tables exists.
	 *
	 * @return array<string, bool>
	 */
	private function tables() {
		global $wpdb;
		$out = array();
		foreach ( Schema::table_names( $wpdb->prefix ) as $name ) {
			$out[ $name ] = $name === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $name ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		return $out;
	}

	/**
	 * An order waiting for a transfer, so an expiry action is scheduled.
	 *
	 * @param array $wallet Wallet entry.
	 * @return int Order id.
	 */
	private function waiting_order( array $wallet ) {
		$order = $this->order( $this->product( '100' ) );
		$this->assertSame( 'success', $this->pay( $order, '01122334499', $wallet['id'] )['result'] );
		$this->assertNotFalse( as_next_scheduled_action( 'qersh_wc_expire', array( 'order_id' => $order->get_id() ), 'qersh' ) );
		return $order->get_id();
	}

	/**
	 * Without the setting: the actions go, everything else stays.
	 */
	public function test_uninstall_keeps_the_data_by_default() {
		$wallet   = $this->vodafone_wallet();
		$order_id = $this->waiting_order( $wallet );

		$this->uninstall();

		$this->assertFalse( as_next_scheduled_action( 'qersh_wc_expire', array( 'order_id' => $order_id ), 'qersh' ), 'scheduled actions removed' );
		$this->assertNotContains( false, $this->tables(), 'tables kept' );
		$this->assertNotFalse( get_option( 'qersh_wc_config', false ), 'settings kept' );
		$this->assertTrue( $this->plugin->secrets()->has_key( $wallet['id'] ), 'key kept' );
		$this->assertNotNull( $this->row( $order_id ), 'rows kept' );
	}

	/**
	 * The newest Action Scheduler action id, so a test can remove every action created after it.
	 *
	 * @return int
	 */
	private static function last_action_id() {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE( MAX( action_id ), 0 ) FROM %i', $wpdb->prefix . 'actionscheduler_actions' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * What test_uninstall_removes_the_data_when_asked() wrote outside the test transaction: on MySQL and MariaDB its
	 * first DROP TABLE commits that transaction, so the order (wc_orders and its placeholder post, or the post), its
	 * product and the actions scheduled since $after would stay for the tests after it, and in the database of a
	 * second run (DISABLE_HPOS=1). Deletes them; the caller then commits.
	 *
	 * @param int $order_id Order id.
	 * @param int $after    last_action_id() before the test wrote anything.
	 */
	private function remove_committed( $order_id, $after ) {
		global $wpdb;
		$order = wc_get_order( $order_id );
		if ( $order instanceof \WC_Order ) {
			$products = array();
			foreach ( $order->get_items() as $item ) {
				if ( $item instanceof \WC_Order_Item_Product ) {
					$products[] = (int) $item->get_product_id();
				}
			}
			$order->delete( true );
			foreach ( array_unique( $products ) as $product_id ) {
				$product = wc_get_product( $product_id );
				if ( $product ) {
					$product->delete( true );
				}
			}
		}
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT action_id FROM %i WHERE action_id > %d', $wpdb->prefix . 'actionscheduler_actions', $after ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( $ids as $action_id ) {
			try {
				\ActionScheduler::store()->delete_action( (int) $action_id );
			} catch ( \Exception $e ) {
				unset( $e ); // Already gone.
			}
		}
	}

	/**
	 * With the setting: tables, options and keys go too. Orders and their meta stay (they are the store's records).
	 */
	public function test_uninstall_removes_the_data_when_asked() {
		$after    = self::last_action_id();
		$entries  = $this->configure(
			array(
				array(
					'provider'        => 'vodafone_cash',
					'receive_address' => '01000000101',
				),
			),
			array( 'remove_data_on_uninstall' => true )
		);
		$wallet   = $entries[0];
		$order_id = $this->waiting_order( $wallet );

		// DROP TABLE must reach the real tables (the test library turns it into DROP TEMPORARY TABLE), and it ends the
		// test transaction; the tables are created again below, and committed, so later tests have them. What the test
		// wrote before the DROP was committed with it, so it is deleted (remove_committed()) before that commit.
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		try {
			$this->uninstall();

			$this->assertFalse( as_next_scheduled_action( 'qersh_wc_expire', array( 'order_id' => $order_id ), 'qersh' ) );
			$this->assertNotContains( true, $this->tables(), 'tables dropped' );
			foreach ( array( 'qersh_wc_config', 'qersh_wc_db_version', 'woocommerce_qersh_settings', 'qersh_wc_secret_' . $wallet['id'] ) as $option ) {
				$this->assertFalse( get_option( $option, false ), $option . ' deleted' );
			}
			$this->plugin->forget_config();
			$this->assertFalse( $this->plugin->secrets()->has_key( $wallet['id'] ), 'key erased' );
			$order = $this->fresh( $order_id );
			$this->assertSame( 'qersh', $order->get_payment_method(), 'the order stays' );
		} finally {
			global $wpdb;
			remove_filter( 'query', array( $this, 'savepoints' ), 0 );
			Schema::install();
			$this->remove_committed( $order_id, $after );
			$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			add_filter( 'query', array( $this, 'savepoints' ), 0 );
			$this->plugin->forget_config();
		}
		$this->assertNotContains( false, $this->tables(), 'tables back for the next tests' );
	}
}
