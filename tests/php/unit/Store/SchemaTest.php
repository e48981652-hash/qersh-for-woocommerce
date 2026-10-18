<?php
/**
 * Tests of Store\Schema: the dbDelta rules of docs/16 §8 and the columns of CONTRACTS.md §7.
 *
 * @package QershPay\WooCommerce\Tests
 */

declare( strict_types=1 );

namespace QershPay\WooCommerce\Tests\Unit\Store;

use QershPay\WooCommerce\Store\Schema;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

defined( 'ABSPATH' ) || exit;

/**
 * Schema::sql() and Schema::COLUMNS.
 */
final class SchemaTest extends TestCase {

	/**
	 * The statements for a plain prefix.
	 *
	 * @return string[]
	 */
	private function sql(): array {
		return Schema::sql( 'wp_', 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci' );
	}

	/** Five tables, each a dbDelta-ready CREATE TABLE. */
	public function test_five_tables(): void {
		$sql = $this->sql();
		$this->assertSame( array( 'deliveries', 'transfers', 'orders', 'wallets', 'locks' ), array_keys( $sql ) );
		foreach ( $sql as $short => $statement ) {
			$this->assertStringStartsWith( 'CREATE TABLE wp_qersh_' . $short . " (\n", $statement );
			$this->assertStringEndsWith( "\n) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci", $statement );
		}
		$this->assertSame( array( 'wp_qersh_deliveries', 'wp_qersh_transfers', 'wp_qersh_orders', 'wp_qersh_wallets', 'wp_qersh_locks' ), Schema::table_names( 'wp_' ) );
	}

	/** One field per line, two spaces after PRIMARY KEY, KEY never INDEX, no backticks (docs/16 §8). */
	public function test_dbdelta_rules(): void {
		foreach ( $this->sql() as $short => $statement ) {
			$this->assertStringNotContainsString( '`', $statement, $short );
			$this->assertDoesNotMatchRegularExpression( '/\bINDEX\b/i', $statement, $short );
			$this->assertSame( 1, preg_match_all( '/^PRIMARY KEY  \([a-z_]+\),?$/m', $statement ), $short );
			$this->assertDoesNotMatchRegularExpression( '/PRIMARY KEY \(/', $statement, $short );
			foreach ( array_slice( explode( "\n", $statement ), 1, -1 ) as $line ) {
				$this->assertSame( 1, preg_match( '/^(?:[a-z_]+ [A-Z]+(?:\(\d+\))?(?: UNSIGNED)?(?: NOT NULL| NULL)(?: AUTO_INCREMENT| DEFAULT (?:\d+|\'[a-z]*\'))?|PRIMARY KEY  \([a-z_]+\)|(?:UNIQUE )?KEY [a-z_]+ \([a-z_,]+\)),?$/', $line ), $short . ': ' . $line );
			}
		}
	}

	/** Every column of CONTRACTS.md §7 is in the CREATE statement and in COLUMNS, and nothing else. */
	public function test_columns_match(): void {
		$contract = array(
			'deliveries' => 'id delivery_id wallet event redelivery state claim_token http_status reply_json claimed_at answered_at',
			'transfers'  => 'id transfer_id wallet provider delivery_id amount_minor currency payer_key payer_name provider_txn_id money_at sms_at risk risk_reasons auto_confirm_ok balance_chain outcome reason claim_eval claim_eval_at order_id flag phone_sync phone_sync_since phone_sync_count note created_at updated_at',
			'orders'     => 'order_id wallet payer_key price_minor paid_minor state created_at expires_at paid_at completion completion_at claim_txn claim_at claim_tries',
			'wallets'    => 'wallet state deleted_at gone_at key_erased_at snapshot last_delivery_at last_event last_heartbeat_at last_device_state grace_until sender_masked auto_seen seen_host seen_form last_test_at last_test_app_version last_test_provider last_test_number_masked silent_since silent_notified',
			'locks'      => 'name created_at',
		);
		$sql      = $this->sql();
		foreach ( $contract as $short => $names ) {
			$expected = explode( ' ', $names );
			$this->assertSame( $expected, array_keys( Schema::COLUMNS[ $short ] ), $short . ' COLUMNS' );
			preg_match_all( '/^([a-z_]+) [A-Z]/m', $sql[ $short ], $m );
			$this->assertSame( $expected, $m[1], $short . ' CREATE' );
		}
	}

	/** NULL in COLUMNS agrees with NULL in the CREATE statement. */
	public function test_nullability_matches(): void {
		$sql = $this->sql();
		foreach ( Schema::COLUMNS as $short => $columns ) {
			foreach ( $columns as $column => $spec ) {
				$this->assertSame( 1, preg_match( '/^' . $column . ' .*$/m', $sql[ $short ], $m ), $column );
				$nullable = Schema::parse_spec( $spec )[2];
				$this->assertSame( $nullable, ( false === strpos( $m[0], 'NOT NULL' ) ), $short . '.' . $column );
			}
		}
	}

	/** Key columns are refused when too long, so their lengths must equal the column. */
	public function test_key_lengths_match(): void {
		$sql = $this->sql();
		foreach ( Schema::COLUMNS as $short => $columns ) {
			foreach ( $columns as $column => $spec ) {
				list( $type, $len ) = Schema::parse_spec( $spec );
				if ( ! in_array( $type, array( 'key', 'nul', 'str' ), true ) ) {
					continue;
				}
				preg_match( '/^' . $column . ' (?:VAR)?CHAR\((\d+)\)/m', $sql[ $short ], $m );
				$this->assertSame( (string) $len, $m[1] ?? '', $short . '.' . $column );
			}
		}
	}

	/** Every spec has a known type; a `nul` column turns a too-long value into NULL, so it must allow NULL. */
	public function test_spec_types(): void {
		foreach ( Schema::COLUMNS as $short => $columns ) {
			foreach ( $columns as $column => $spec ) {
				list( $type, , $nullable ) = Schema::parse_spec( $spec );
				$this->assertContains( $type, array( 'int', 'key', 'nul', 'str', 'text', 'json' ), $short . '.' . $column );
				if ( 'nul' === $type ) {
					$this->assertTrue( $nullable, $short . '.' . $column );
				}
			}
		}
		$this->assertSame( 'nul:40?', Schema::COLUMNS['transfers']['provider_txn_id'], 'a transaction id is never cut' );
	}

	/**
	 * Schema 2: the wallet type of a transfer is information only (a default of '', no index on it), and a
	 * transaction id is looked up by itself, on any wallet.
	 */
	public function test_transfers_never_key_on_the_wallet_type(): void {
		$sql = $this->sql()['transfers'];
		$this->assertStringContainsString( "\nprovider VARCHAR(32) NOT NULL DEFAULT '',\n", $sql );
		$this->assertStringContainsString( "\nKEY provider_txn_id (provider_txn_id),\n", $sql );
		$this->assertDoesNotMatchRegularExpression( '/KEY [a-z_]+ \((?:[a-z_]+,)*provider(?:,[a-z_]+)*\)/', $sql, 'no index holds the wallet type' );
		$this->assertStringNotContainsString( 'KEY provider_txn ', $sql, 'the index of schema 1 is gone' );
		$this->assertSame( 'str:32', Schema::COLUMNS['transfers']['provider'], 'a display string, cut and never refused' );
		$this->assertSame( array( 'transfers' => array( 'provider_txn' ) ), Schema::OBSOLETE_INDEXES );
		$this->assertSame( array( 'qersh_wc_txn_drift', 'qersh_wc_txn_long' ), Schema::OBSOLETE_OPTIONS );
	}

	/** The main plugin file defines the schema version this code installs. */
	public function test_main_file_version(): void {
		$main = (string) file_get_contents( QERSH_WC_TESTS_ROOT . '/qersh-for-woocommerce.php' );
		$this->assertSame( 1, preg_match( "/^define\\( 'QERSH_WC_DB_VERSION', (\\d+) \\);$/m", $main, $m ) );
		$this->assertSame( (string) Schema::VERSION, $m[1] );
		$this->assertSame( 2, Schema::VERSION );
	}

	/**
	 * A fake $wpdb whose SHOW TABLES answers with the matched name in lower case, as MySQL does with
	 * lower_case_table_names=1 (the default on Windows), or with nothing for the tables in $missing.
	 *
	 * @param string   $prefix  Table prefix.
	 * @param string[] $missing Short names of the tables that do not exist.
	 * @return FakeWpdb
	 */
	private function show_tables_wpdb( string $prefix, array $missing = array() ): FakeWpdb {
		require_once __DIR__ . '/FakeWpdb.php';
		$wpdb         = new FakeWpdb();
		$wpdb->prefix = $prefix;
		$wpdb->on(
			'/^SHOW TABLES LIKE /',
			function ( string $sql ) use ( $missing ) {
				preg_match( "/LIKE '(.*)'$/", $sql, $m );
				$name = str_replace( '\\', '', $m[1] );
				foreach ( $missing as $short ) {
					if ( substr( $name, -strlen( 'qersh_' . $short ) ) === 'qersh_' . $short ) {
						return null;
					}
				}
				return strtolower( $name );
			},
			'',
			true
		);
		$GLOBALS['wpdb'] = $wpdb;
		return $wpdb;
	}

	/** SHOW TABLES returns `wp_qersh_…` for the prefix `WP_` under lower_case_table_names=1: the tables exist. */
	public function test_tables_exist_ignores_case(): void {
		try {
			$wpdb = $this->show_tables_wpdb( 'WP_' );
			$this->assertTrue( Schema::tables_exist() );
			$this->assertCount( 5, $wpdb->queries );
			$this->assertSame( "SHOW TABLES LIKE 'WP\\\\_qersh\\\\_deliveries'", $wpdb->queries[0] );
			$this->assertFalse( $wpdb->suppressed, 'error suppression is put back' );

			$this->show_tables_wpdb( 'wp_' );
			$this->assertTrue( Schema::tables_exist() );

			$this->show_tables_wpdb( 'WP_', array( 'orders' ) );
			$this->assertFalse( Schema::tables_exist() );
		} finally {
			unset( $GLOBALS['wpdb'] );
		}
	}

	/**
	 * install() records the schema version once SHOW TABLES finds the five tables, in whatever case it names them,
	 * and pauses automatic installs for ten minutes while one is missing.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_install_records_the_version(): void {
		require_once __DIR__ . '/FakeWpOptions.php';
		$this->show_tables_wpdb( 'WP_', array( 'locks' ) );
		Schema::install();
		$this->assertCount( 1, $GLOBALS['qersh_wc_fake_dbdelta'] );
		$this->assertSame( array( 'deliveries', 'transfers', 'orders', 'wallets', 'locks' ), array_keys( $GLOBALS['qersh_wc_fake_dbdelta'][0] ) );
		$this->assertArrayNotHasKey( Schema::VERSION_OPTION, $GLOBALS['qersh_wc_fake_options'] );
		$this->assertSame( 1, $GLOBALS['qersh_wc_fake_transients'][ Schema::RETRY_TRANSIENT ] );

		Schema::maybe_upgrade();
		$this->assertCount( 1, $GLOBALS['qersh_wc_fake_dbdelta'], 'paused' );

		$wpdb = $this->show_tables_wpdb( 'WP_' );
		Schema::table_missing();
		Schema::install();
		$this->assertSame( Schema::VERSION, $GLOBALS['qersh_wc_fake_options'][ Schema::VERSION_OPTION ] );
		$this->assertArrayNotHasKey( Schema::RETRY_TRANSIENT, $GLOBALS['qersh_wc_fake_transients'] );
		$this->assertFalse( Schema::lost(), 'the tables are back' );
		$this->assertSame( array( 'SHOW INDEX FROM `WP_qersh_transfers`' ), $wpdb->matching( '/^SHOW INDEX/' ), 'a new store has no index of schema 1' );
		$this->assertSame( array(), $wpdb->matching( '/^ALTER/' ) );

		Schema::maybe_upgrade();
		$this->assertCount( 2, $GLOBALS['qersh_wc_fake_dbdelta'], 'nothing to do at the current version' );
	}

	/**
	 * A store of schema 1 (0.1.0) upgrades on the next request: dbDelta runs (it gives `provider` its default and
	 * adds the key `provider_txn_id`), the two transaction-id drift options are deleted while the other options stay,
	 * the index `provider_txn` is dropped, and only then is version 2 recorded. A second request does nothing.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_upgrade_from_schema_1(): void {
		require_once __DIR__ . '/FakeWpOptions.php';
		$wpdb = $this->show_tables_wpdb( 'wp_' );
		$wpdb->on(
			'/^SHOW INDEX FROM `wp_qersh_transfers`$/',
			array(
				array(
					'Key_name'    => 'PRIMARY',
					'Column_name' => 'id',
				),
				array(
					'Key_name'    => 'provider_txn',
					'Column_name' => 'provider',
				),
				array(
					'Key_name'    => 'provider_txn',
					'Column_name' => 'provider_txn_id',
				),
				array(
					'Key_name'    => 'provider_txn_id',
					'Column_name' => 'provider_txn_id',
				),
			)
		);
		$GLOBALS['qersh_wc_fake_options'] = array(
			Schema::VERSION_OPTION => 1,
			'qersh_wc_txn_drift'   => array(
				'orange_cash' => array(
					'tail'    => '512',
					'at'      => 1760000000,
					'version' => '0.1.0',
				),
			),
			'qersh_wc_txn_long'    => array(
				'version' => '0.1.0',
				'orders'  => array(),
			),
			'qersh_wc_config'      => array( 'version' => 1 ),
		);

		Schema::maybe_upgrade();
		$this->assertCount( 1, $GLOBALS['qersh_wc_fake_dbdelta'] );
		$this->assertStringContainsString( "provider VARCHAR(32) NOT NULL DEFAULT '',", $GLOBALS['qersh_wc_fake_dbdelta'][0]['transfers'] );
		$this->assertSame(
			array(
				Schema::VERSION_OPTION => 2,
				'qersh_wc_config'      => array( 'version' => 1 ),
			),
			$GLOBALS['qersh_wc_fake_options']
		);
		$this->assertSame( array( 'ALTER TABLE `wp_qersh_transfers` DROP INDEX `provider_txn`' ), $wpdb->matching( '/^ALTER/' ) );
		$this->assertFalse( $wpdb->suppressed, 'error suppression is put back' );

		$before = count( $wpdb->queries );
		Schema::maybe_upgrade();
		Schema::install(); // A reactivation at schema 2: dbDelta again, no upgrade step.
		$this->assertCount( 2, $GLOBALS['qersh_wc_fake_dbdelta'] );
		$this->assertSame( array(), preg_grep( '/^(?:SHOW INDEX|ALTER)/', array_slice( $wpdb->queries, $before ) ) );
	}

	/**
	 * The upgrade to schema 2 rewrites the transaction ids schema 1 stored as the phone sent them in the canonical
	 * form schema 2 compares by exact equality, a batch of rows at a time by row id: «2500517294 4183» becomes
	 * 25005172944183 and «ft-2412305» FT2412305; a canonical id is not written, and a value with no canonical form
	 * stays. A second request does nothing.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_upgrade_rewrites_txn_ids_in_their_canonical_form(): void {
		require_once __DIR__ . '/FakeWpOptions.php';
		$wpdb  = $this->show_tables_wpdb( 'wp_' );
		$first = array();
		for ( $id = 1; $id <= Schema::TXN_BATCH; $id++ ) {
			$first[] = array(
				'id'              => (string) $id,
				'provider_txn_id' => (string) ( 913372004000 + $id ),
			);
		}
		$first[0]['provider_txn_id'] = '2500517294 4183';
		$first[1]['provider_txn_id'] = '12 34';
		$wpdb->on( '/^SELECT id, provider_txn_id FROM `wp_qersh_transfers` WHERE id > 0 AND provider_txn_id IS NOT NULL ORDER BY id ASC LIMIT 500$/', $first );
		$wpdb->on(
			'/^SELECT id, provider_txn_id FROM `wp_qersh_transfers` WHERE id > 500 /',
			array(
				array(
					'id'              => '501',
					'provider_txn_id' => 'ft-2412305',
				),
				array(
					'id'              => '502',
					'provider_txn_id' => 'FT2412305',
				),
			)
		);
		$GLOBALS['qersh_wc_fake_options'][ Schema::VERSION_OPTION ] = 1;

		Schema::maybe_upgrade();
		$this->assertCount( 2, $wpdb->matching( '/^SELECT id, provider_txn_id /' ), 'a full batch, then the rest' );
		$this->assertSame(
			array(
				"UPDATE `wp_qersh_transfers` SET provider_txn_id = '25005172944183' WHERE id = 1",
				"UPDATE `wp_qersh_transfers` SET provider_txn_id = 'FT2412305' WHERE id = 501",
			),
			$wpdb->matching( '/^UPDATE /' )
		);
		$this->assertSame( 2, $GLOBALS['qersh_wc_fake_options'][ Schema::VERSION_OPTION ] );
		$this->assertFalse( $wpdb->suppressed, 'error suppression is put back' );

		$before = count( $wpdb->queries );
		Schema::maybe_upgrade();
		$this->assertSame( array(), preg_grep( '/^(?:SELECT id, provider_txn_id|UPDATE)/', array_slice( $wpdb->queries, $before ) ), 'nothing at schema 2' );
	}

	/**
	 * A failed index drop (another request dropped it a moment earlier) never stops the upgrade.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_upgrade_survives_a_refused_drop(): void {
		require_once __DIR__ . '/FakeWpOptions.php';
		$wpdb = $this->show_tables_wpdb( 'wp_' );
		$wpdb->on(
			'/^SHOW INDEX FROM /',
			array(
				array(
					'Key_name'    => 'PROVIDER_TXN',
					'Column_name' => 'provider',
				),
			)
		);
		$wpdb->on( '/^ALTER TABLE /', false, "Can't DROP 'provider_txn'; check that column/key exists" );
		$GLOBALS['qersh_wc_fake_options'][ Schema::VERSION_OPTION ] = 1;

		Schema::maybe_upgrade();
		$this->assertCount( 1, $wpdb->matching( '/^ALTER TABLE `wp_qersh_transfers` DROP INDEX `provider_txn`$/' ), 'the name is compared without case' );
		$this->assertSame( 2, $GLOBALS['qersh_wc_fake_options'][ Schema::VERSION_OPTION ] );
		$this->assertFalse( $wpdb->suppressed );
	}

	/**
	 * The missing tables of one site of a network never make another site's tables "not ready" in the same request
	 * (switch_to_blog() changes the prefix), and install() clears only its own site.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_lost_is_per_site(): void {
		require_once __DIR__ . '/FakeWpOptions.php';
		$wpdb = $this->show_tables_wpdb( 'wp_' );
		$this->assertFalse( Schema::lost() );
		Schema::table_missing( 'wp_2_' );
		$this->assertFalse( Schema::lost(), 'the main site' );
		$wpdb->prefix = 'wp_2_';
		$this->assertTrue( Schema::lost(), 'site 2' );
		$wpdb->prefix = 'wp_';
		Schema::table_missing();
		Schema::install();
		$this->assertFalse( Schema::lost(), 'the main site, installed again' );
		$wpdb->prefix = 'wp_2_';
		$this->assertTrue( Schema::lost(), 'site 2 is still lost' );
	}

	/**
	 * forget_version() deletes the version so the next maybe_upgrade() installs again; a second loss within ten
	 * minutes also pauses automatic installs. A version already gone, here or by a request that met the missing
	 * tables at the same moment, changes nothing.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_forget_version(): void {
		require_once __DIR__ . '/FakeWpOptions.php';
		$this->show_tables_wpdb( 'wp_' );

		Schema::forget_version();
		$this->assertSame( array(), $GLOBALS['qersh_wc_fake_transients'], 'nothing installed, nothing to forget' );

		$GLOBALS['qersh_wc_fake_options'][ Schema::VERSION_OPTION ] = 1;
		$GLOBALS['qersh_wc_fake_refuse']['delete_option']           = true;
		Schema::forget_version();
		$this->assertSame( array(), $GLOBALS['qersh_wc_fake_transients'], 'another request deleted it first' );
		$GLOBALS['qersh_wc_fake_refuse']['delete_option'] = false;

		Schema::forget_version();
		$this->assertArrayNotHasKey( Schema::VERSION_OPTION, $GLOBALS['qersh_wc_fake_options'] );
		$this->assertSame( array( Schema::LOST_TRANSIENT => 1 ), $GLOBALS['qersh_wc_fake_transients'] );

		Schema::maybe_upgrade();
		$this->assertCount( 1, $GLOBALS['qersh_wc_fake_dbdelta'], 'the next request installs again' );
		$this->assertSame( Schema::VERSION, $GLOBALS['qersh_wc_fake_options'][ Schema::VERSION_OPTION ] );

		Schema::forget_version();
		$this->assertSame( 1, $GLOBALS['qersh_wc_fake_transients'][ Schema::RETRY_TRANSIENT ], 'lost again within ten minutes' );
		Schema::maybe_upgrade();
		$this->assertCount( 1, $GLOBALS['qersh_wc_fake_dbdelta'], 'paused' );
	}
}
