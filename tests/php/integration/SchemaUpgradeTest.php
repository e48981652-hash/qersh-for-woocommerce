<?php
/**
 * The upgrade of a store at schema 1 to schema 2 (QERSH_WC_DB_VERSION 2): `transfers.provider` gets its default of
 * '', the key `provider_txn` (wallet type and transaction id) gives way to `provider_txn_id` (the id alone, looked up
 * on any wallet), the two options of the transaction-id drift (`qersh_wc_txn_drift`, `qersh_wc_txn_long`) are
 * deleted, the stored transaction ids take their canonical form, the version is recorded, and the rows stay.
 *
 * ALTER TABLE commits the test library's transaction in MySQL and MariaDB, so this test changes the real table. Its
 * finally block puts the table back at schema 2 from scratch, deletes what the test wrote and commits that, so the
 * next tests (and the second run of the suite with DISABLE_HPOS=1) find the store as the bootstrap left it.
 *
 * @package QershPay\WooCommerce\Tests
 */

namespace QershPay\WooCommerce\Tests\Integration;

use QershPay\WooCommerce\Store\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Schema 1 to 2.
 */
class SchemaUpgradeTest extends TestCase {

	/** The options of schema 1 that the upgrade deletes. */
	const OBSOLETE = array( 'qersh_wc_txn_drift', 'qersh_wc_txn_long' );

	/**
	 * A store recorded at schema 1, with its table, its options and a row of that time, upgrades on the next
	 * maybe_upgrade() (plugins_loaded); a second install changes nothing.
	 */
	public function test_a_schema_1_store_upgrades_to_schema_2(): void {
		global $wpdb;
		$table   = $wpdb->prefix . 'qersh_transfers';
		$old_row = 'trf_schema1_' . bin2hex( random_bytes( 6 ) );
		$spaced  = 'trf_schema1s_' . bin2hex( random_bytes( 6 ) );
		$new_row = 'trf_schema2_' . bin2hex( random_bytes( 6 ) );
		$txn     = self::txn_id( 14 );
		try {
			// The table of schema 1: provider without a default, and the key on (provider, provider_txn_id).
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ALTER COLUMN provider DROP DEFAULT', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a test reads or sets up the plugin's tables directly.
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP INDEX provider_txn_id', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a test reads or sets up the plugin's tables directly.
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD KEY provider_txn (provider,provider_txn_id)', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a test reads or sets up the plugin's tables directly.
			$this->assertSame( array( 'provider', 'provider_txn_id' ), self::indexes( $table )['provider_txn'], 'the schema 1 key is in place' );
			$this->assertArrayNotHasKey( 'provider_txn_id', self::indexes( $table ) );
			$this->assertNull( self::provider_column( $table )['Default'], 'no default, as in schema 1' );

			foreach ( self::OBSOLETE as $option ) {
				update_option( $option, array( 'at' => time() ), false );
			}
			$this->assertTrue(
				$this->plugin->transfers()->insert(
					array(
						'transfer_id'     => $old_row,
						'wallet'          => 'aaaaaaaaaaaa',
						'provider'        => 'orange_cash',
						'delivery_id'     => wp_generate_uuid4(),
						'amount_minor'    => 14000,
						'currency'        => 'EGP',
						'provider_txn_id' => $txn,
						'money_at'        => time(),
					)
				)
			);
			// Schema 1 kept the id of most wallets as the phone sent it.
			$this->assertTrue(
				$this->plugin->transfers()->insert(
					array(
						'transfer_id'     => $spaced,
						'wallet'          => 'aaaaaaaaaaaa',
						'provider'        => 'etisalat_cash',
						'delivery_id'     => wp_generate_uuid4(),
						'amount_minor'    => 2500,
						'currency'        => 'EGP',
						'provider_txn_id' => '2500517294 4183',
						'money_at'        => time(),
					)
				)
			);
			update_option( Schema::VERSION_OPTION, 1, true );
			delete_transient( Schema::RETRY_TRANSIENT );
			$this->assertFalse( $this->plugin->tables_ready(), 'a store behind its schema is not ready' );

			Schema::maybe_upgrade();

			$this->assertSame( (int) QERSH_WC_DB_VERSION, (int) get_option( Schema::VERSION_OPTION ) );
			$this->assertSame( 2, (int) QERSH_WC_DB_VERSION );
			$this->assertTrue( $this->plugin->tables_ready() );
			foreach ( self::OBSOLETE as $option ) {
				$this->assertFalse( get_option( $option, false ), $option . ' deleted' );
			}
			$indexes = self::indexes( $table );
			$this->assertSame( array( 'provider_txn_id' ), $indexes['provider_txn_id'], 'the id alone' );
			$this->assertArrayNotHasKey( 'provider_txn', $indexes, 'the key with the wallet type is gone' );
			$column = self::provider_column( $table );
			$this->assertSame( '', $column['Default'] );
			$this->assertSame( 'NO', $column['Null'] );

			$kept = $this->plugin->transfers()->get( $old_row );
			$this->assertNotNull( $kept, 'the rows stay' );
			$this->assertSame( 'orange_cash', (string) $kept['provider'], 'as information, unchanged' );
			$this->assertSame( array( $old_row ), array_column( $this->plugin->transfers()->find_by_txn( $txn ), 'transfer_id' ), 'found by the id alone' );
			$this->assertSame( $txn, (string) $kept['provider_txn_id'], 'a canonical id is kept as it is' );
			$this->assertSame( '25005172944183', (string) $this->plugin->transfers()->get( $spaced )['provider_txn_id'], 'an id stored as the phone sent it takes its canonical form' );
			$this->assertContains( $spaced, array_column( $this->plugin->transfers()->find_by_txn( '25005172944183' ), 'transfer_id' ), 'so the same id delivered again is found' );

			$this->assertTrue(
				$this->plugin->transfers()->insert(
					array(
						'transfer_id' => $new_row,
						'wallet'      => 'aaaaaaaaaaaa',
						'delivery_id' => wp_generate_uuid4(),
						'currency'    => 'EGP',
						'money_at'    => time(),
					)
				),
				'a row without a wallet type'
			);
			$this->assertSame( '', (string) $this->plugin->transfers()->get( $new_row )['provider'] );

			Schema::install();
			$this->assertSame( $indexes, self::indexes( $table ), 'a second install changes nothing' );
			$this->assertSame( (int) QERSH_WC_DB_VERSION, (int) get_option( Schema::VERSION_OPTION ) );
		} finally {
			$this->restore( $table, array( $old_row, $spaced, $new_row ) );
		}
	}

	/**
	 * Puts the store back as the bootstrap left it, and commits: the table rebuilt to schema 2 by an install from no
	 * recorded version (dbDelta, then every upgrade step), the test's rows and options deleted.
	 *
	 * @param string   $table        The transfers table.
	 * @param string[] $transfer_ids Rows the test wrote.
	 */
	private function restore( $table, array $transfer_ids ): void {
		global $wpdb;
		remove_filter( 'query', array( $this, 'savepoints' ), 0 ); // The COMMIT below must reach the database.
		try {
			delete_option( Schema::VERSION_OPTION );
			delete_transient( Schema::RETRY_TRANSIENT );
			Schema::install();
			foreach ( $transfer_ids as $id ) {
				$wpdb->delete( $table, array( 'transfer_id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a test reads or sets up the plugin's tables directly.
			}
			foreach ( self::OBSOLETE as $option ) {
				delete_option( $option );
			}
			$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a test reads or sets up the plugin's tables directly.
		} finally {
			add_filter( 'query', array( $this, 'savepoints' ), 0 );
		}
	}

	/**
	 * Runs after the upgrade test (and the restore of its finally block): the store it left behind is the one the
	 * bootstrap installed, ready, at schema 2.
	 *
	 * @depends test_a_schema_1_store_upgrades_to_schema_2
	 */
	public function test_the_store_is_left_at_schema_2(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'qersh_transfers';
		$this->assertTrue( $this->plugin->tables_ready() );
		$this->assertSame( (int) QERSH_WC_DB_VERSION, (int) get_option( Schema::VERSION_OPTION ) );
		$this->assertSame( array( 'provider_txn_id' ), self::indexes( $table )['provider_txn_id'] );
		$this->assertArrayNotHasKey( 'provider_txn', self::indexes( $table ) );
		$this->assertSame( '', self::provider_column( $table )['Default'] );
		foreach ( self::OBSOLETE as $option ) {
			$this->assertFalse( get_option( $option, false ), $option );
		}
	}

	/**
	 * The indexes of a table: key name (lower case) => its columns in order.
	 *
	 * @param string $table Table.
	 * @return array<string, string[]>
	 */
	private static function indexes( $table ) {
		global $wpdb;
		$keys = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i', $table ), ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a test reads or sets up the plugin's tables directly.
			$keys[ strtolower( (string) $row['Key_name'] ) ][ (int) $row['Seq_in_index'] ] = strtolower( (string) $row['Column_name'] );
		}
		$out = array();
		foreach ( $keys as $name => $columns ) {
			ksort( $columns );
			$out[ $name ] = array_values( $columns );
		}
		ksort( $out );
		return $out;
	}

	/**
	 * SHOW COLUMNS of transfers.provider.
	 *
	 * @param string $table Table.
	 * @return array
	 */
	private static function provider_column( $table ) {
		global $wpdb;
		$column = $wpdb->get_row( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, 'provider' ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a test reads or sets up the plugin's tables directly.
		self::assertIsArray( $column, 'transfers.provider exists' );
		return $column;
	}
}
