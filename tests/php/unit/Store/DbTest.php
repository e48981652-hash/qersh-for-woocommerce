<?php
/**
 * Tests of Store\Db: the lock order, lock errors, the MySQL transaction sequence and the column checks.
 *
 * @package QershPay\WooCommerce\Tests
 */

declare( strict_types=1 );

namespace QershPay\WooCommerce\Tests\Unit\Store;

use QershPay\WooCommerce\Store\Db;
use QershPay\WooCommerce\Store\LockTimeout;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/FakeWpdb.php';

/**
 * Db without WordPress, on a scripted $wpdb.
 */
final class DbTest extends TestCase {

	/**
	 * The fake database.
	 *
	 * @var FakeWpdb
	 */
	private $wpdb;

	/**
	 * Installs a fresh fake $wpdb.
	 */
	protected function set_up() {
		parent::set_up();
		$this->wpdb       = new FakeWpdb();
		$GLOBALS['wpdb'] = $this->wpdb;
	}

	/**
	 * Removes the fake.
	 */
	protected function tear_down() {
		unset( $GLOBALS['wpdb'] );
		parent::tear_down();
	}

	/** One txn:/trf: name first, then payer: names sorted with strcmp, each once (docs/17 §4.4 step 8). */
	public function test_order_lock_names() {
		$this->assertSame(
			array( 'txn:orange_cash:12345678901234', 'payer:01001234567', 'payer:01112223334', 'payer:ali@instapay' ),
			Db::order_lock_names( array( 'payer:ali@instapay', 'payer:01112223334', 'txn:orange_cash:12345678901234', 'payer:01001234567', 'payer:01112223334' ) )
		);
		$this->assertSame( array( 'trf:trf_01J9ZZZZZZZZZZZZZZZZZZZZZZ' ), Db::order_lock_names( array( 'trf:trf_01J9ZZZZZZZZZZZZZZZZZZZZZZ', 'trf:trf_01J9ZZZZZZZZZZZZZZZZZZZZZZ' ) ) );
		$this->assertSame( array(), Db::order_lock_names( array() ) );
		// strcmp order is byte order: digits before upper case before lower case.
		$this->assertSame( array( 'payer:0', 'payer:A', 'payer:a' ), Db::order_lock_names( array( 'payer:a', 'payer:A', 'payer:0' ) ) );
	}

	/** Two different head locks, unknown kinds, empty names and non-strings are programming errors. */
	public function test_order_lock_names_refuses() {
		$bad = array(
			array( 'txn:orange_cash:1', 'trf:trf_x' ),
			array( 'order:12' ),
			array( 'payer:' ),
			array( 12 ),
		);
		foreach ( $bad as $names ) {
			try {
				Db::order_lock_names( $names );
				$this->fail( 'accepted ' . json_encode( $names ) );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertStringStartsWith( 'qersh:', $e->getMessage() );
			}
		}
	}

	/** A lock name longer than the column becomes a hash that keeps its kind. */
	public function test_lock_row_name() {
		$this->assertSame( 'payer:01001234567', Db::lock_row_name( 'payer:01001234567' ) );
		$long = 'payer:' . str_repeat( 'a', 120 ) . '@instapay';
		$row  = Db::lock_row_name( $long );
		$this->assertSame( 'payer:#' . hash( 'sha256', $long ), $row );
		$this->assertLessThanOrEqual( 100, strlen( $row ) );
	}

	/** MySQL 1205/1213 (by number or text) and SQLite busy are lock errors; nothing else is. */
	public function test_is_lock_error() {
		$this->assertTrue( Db::is_lock_error( 'Lock wait timeout exceeded; try restarting transaction' ) );
		$this->assertTrue( Db::is_lock_error( 'Deadlock found when trying to get lock; try restarting transaction' ) );
		$this->assertTrue( Db::is_lock_error( 'SQLSTATE[HY000]: General error: 5 database is locked' ) );
		$this->assertTrue( Db::is_lock_error( 'Zeitüberschreitung beim Warten auf Sperre', 1205 ) );
		$this->assertTrue( Db::is_lock_error( 'WSREP detected deadlock/conflict and aborted the transaction.', 1213 ) );
		$this->assertFalse( Db::is_lock_error( "Duplicate entry '1205' for key 'PRIMARY'", 1062 ) );
		$this->assertFalse( Db::is_lock_error( "Table 'wp_qersh_locks' doesn't exist", 1146 ) );
		$this->assertFalse( Db::is_lock_error( '' ) );
	}

	/** begin() saves and sets the lock wait timeout after START TRANSACTION; commit() puts it back. */
	public function test_transaction_sequence_on_mysql() {
		$this->wpdb->on( '/^SELECT @@innodb_lock_wait_timeout$/', '50' );
		$db = new Db();
		$db->begin();
		$this->assertTrue( $db->in_transaction() );
		$db->lock( array( 'payer:01112223334', 'txn:orange_cash:12345678901234', 'payer:01001234567' ) );
		$db->commit();
		$this->assertFalse( $db->in_transaction() );

		$q = $this->wpdb->queries;
		$this->assertSame( 'START TRANSACTION', $q[0] );
		$this->assertSame( 'SELECT @@innodb_lock_wait_timeout', $q[1] );
		$this->assertSame( 'SET SESSION innodb_lock_wait_timeout = 5', $q[2] );
		$this->assertMatchesRegularExpression( "/^INSERT INTO `wp_qersh_locks` \\(name, created_at\\) VALUES \\('txn:orange_cash:12345678901234', \\d+\\) ON DUPLICATE KEY UPDATE created_at = \\d+$/", $q[3] );
		$this->assertStringContainsString( "'payer:01001234567'", $q[4] );
		$this->assertStringContainsString( "'payer:01112223334'", $q[5] );
		$this->assertSame( 'COMMIT', $q[6] );
		$this->assertSame( 'SET SESSION innodb_lock_wait_timeout = 50', $q[7] );
		$this->assertCount( 8, $q );
		$this->assertFalse( $this->wpdb->suppressed, 'error suppression is put back' );
	}

	/** rollback() also restores the timeout, never throws, and does nothing without a transaction. */
	public function test_rollback() {
		$this->wpdb->on( '/^SELECT @@innodb_lock_wait_timeout$/', '30' );
		$db = new Db();
		$db->rollback();
		$this->assertSame( array(), $this->wpdb->queries );
		$db->begin();
		$db->rollback();
		$this->assertSame( array( 'START TRANSACTION', 'SELECT @@innodb_lock_wait_timeout', 'SET SESSION innodb_lock_wait_timeout = 5', 'ROLLBACK', 'SET SESSION innodb_lock_wait_timeout = 30' ), $this->wpdb->queries );
	}

	/**
	 * On the SQLite drop-in (which turns START TRANSACTION into BEGIN IMMEDIATE) the MySQL session variables are
	 * skipped; the lock statements are the same.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_sqlite_skips_session_variables() {
		require_once __DIR__ . '/FakeSqliteDb.php';
		$db = new Db();
		$this->assertTrue( $db->is_sqlite() );
		$db->begin();
		$db->lock( array( 'payer:01001234567', 'trf:trf_01J9Z8Y7X6W5V4T3S2R1Q0P9N8' ) );
		$db->commit();
		$q = $this->wpdb->queries;
		$this->assertSame( 'START TRANSACTION', $q[0] );
		$this->assertStringContainsString( "VALUES ('trf:trf_01J9Z8Y7X6W5V4T3S2R1Q0P9N8', ", $q[1] );
		$this->assertStringContainsString( "VALUES ('payer:01001234567', ", $q[2] );
		$this->assertSame( 'COMMIT', $q[3] );
		$this->assertCount( 4, $q );
		$this->assertSame( array(), $this->wpdb->matching( '/innodb/' ) );
	}

	/**
	 * On SQLite the second writer waits at BEGIN IMMEDIATE (START TRANSACTION) and fails with "database is locked":
	 * that is a LockTimeout (retry, then 429), not a RuntimeException (500), and no transaction is left open.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_sqlite_begin_lock_maps() {
		require_once __DIR__ . '/FakeSqliteDb.php';
		$this->wpdb->on( '/^START TRANSACTION$/', false, 'SQLSTATE[HY000]: General error: 5 database is locked' );
		$db = new Db();
		try {
			$db->begin();
			$this->fail( 'no LockTimeout' );
		} catch ( LockTimeout $e ) {
			$this->assertFalse( $db->in_transaction() );
		}
		$db->begin(); // The retry gets the lock.
		$this->assertTrue( $db->in_transaction() );
		$db->rollback();
		$this->assertSame( array( 'START TRANSACTION', 'START TRANSACTION', 'ROLLBACK' ), $this->wpdb->queries );
	}

	/** START TRANSACTION failing for another reason stays a RuntimeException. */
	public function test_begin_other_error() {
		$this->wpdb->on( '/^START TRANSACTION$/', false, 'MySQL server has gone away' );
		$db = new Db();
		try {
			$db->begin();
			$this->fail( 'no exception' );
		} catch ( \RuntimeException $e ) {
			$this->assertNotInstanceOf( LockTimeout::class, $e );
			$this->assertFalse( $db->in_transaction() );
		}
	}

	/**
	 * The total lock order holds across lock() calls of one transaction: a later call may continue it (names already
	 * held are skipped), never go back; rollback() and commit() start afresh.
	 */
	public function test_lock_order_across_calls() {
		$this->wpdb->on( '/^SELECT @@innodb_lock_wait_timeout$/', '5', '', true );
		$db = new Db();
		$db->begin();
		$db->lock( array( 'txn:orange_cash:12345678901234' ) );
		$db->lock( array( 'payer:01112223334' ) );
		$db->lock( array( 'payer:01112223334', 'payer:01223334445', 'txn:orange_cash:12345678901234' ) );
		$this->assertSame( array( 'txn:orange_cash:12345678901234', 'payer:01112223334', 'payer:01223334445' ), $db->held_locks() );
		$this->assertCount( 3, $this->wpdb->matching( '/^INSERT INTO `wp_qersh_locks`/' ) );

		$refused = array(
			array( 'payer:01001234567' ),
			array( 'payer:01001234567', 'payer:01999999999' ),
			array( 'trf:trf_01J9Z8Y7X6W5V4T3S2R1Q0P9N8' ),
			array( 'txn:orange_cash:99999999999999' ),
		);
		foreach ( $refused as $names ) {
			try {
				$db->lock( $names );
				$this->fail( 'accepted ' . implode( ', ', $names ) );
			} catch ( \LogicException $e ) {
				$this->assertStringStartsWith( 'qersh:', $e->getMessage() );
			}
		}
		$this->assertCount( 3, $this->wpdb->matching( '/^INSERT INTO `wp_qersh_locks`/' ), 'nothing taken by a refused call' );

		$db->rollback();
		$this->assertSame( array(), $db->held_locks() );
		$db->begin();
		$db->lock( array( 'payer:01001234567' ) );
		try {
			$db->lock( array( 'txn:orange_cash:12345678901234' ) );
			$this->fail( 'a head lock after a payer lock' );
		} catch ( \LogicException $e ) {
			$this->assertSame( array( 'payer:01001234567' ), $db->held_locks() );
		}
		$db->commit();
		$this->assertSame( array(), $db->held_locks() );
	}

	/** continue_lock_order() is pure: what is left to take, or LogicException. */
	public function test_continue_lock_order() {
		$this->assertSame( array( 'trf:trf_x', 'payer:a' ), Db::continue_lock_order( array(), array( 'trf:trf_x', 'payer:a' ) ) );
		$this->assertSame( array( 'payer:c' ), Db::continue_lock_order( array( 'trf:trf_x', 'payer:a' ), array( 'trf:trf_x', 'payer:a', 'payer:c' ) ) );
		$this->assertSame( array(), Db::continue_lock_order( array( 'payer:a' ), array( 'payer:a' ) ) );
		$this->expectException( \LogicException::class );
		Db::continue_lock_order( array( 'payer:b' ), array( 'payer:a' ) );
	}

	/** When the session already waits 5 seconds nothing is set or restored. */
	public function test_timeout_already_five() {
		$this->wpdb->on( '/^SELECT @@innodb_lock_wait_timeout$/', '5' );
		$db = new Db();
		$db->begin();
		$db->commit();
		$this->assertSame( array( 'START TRANSACTION', 'SELECT @@innodb_lock_wait_timeout', 'COMMIT' ), $this->wpdb->queries );
	}

	/** A lock wait timeout on a lock row becomes LockTimeout. */
	public function test_lock_timeout_maps() {
		$this->wpdb->on( '/^INSERT INTO `wp_qersh_locks`/', false, 'Lock wait timeout exceeded; try restarting transaction' );
		$db = new Db();
		$db->begin();
		$this->expectException( LockTimeout::class );
		$db->lock( array( 'payer:01001234567' ) );
	}

	/** A deadlock reported at COMMIT (Galera) becomes LockTimeout, and the timeout is still restored. */
	public function test_commit_deadlock_maps() {
		$this->wpdb->on( '/^SELECT @@innodb_lock_wait_timeout$/', '50' );
		$this->wpdb->on( '/^COMMIT$/', false, 'Deadlock found when trying to get lock; try restarting transaction' );
		$db = new Db();
		$db->begin();
		try {
			$db->commit();
			$this->fail( 'no LockTimeout' );
		} catch ( LockTimeout $e ) {
			$this->assertFalse( $db->in_transaction() );
			$this->assertSame( 'SET SESSION innodb_lock_wait_timeout = 50', end( $this->wpdb->queries ) );
		}
	}

	/** Locks need a transaction, and transactions do not nest. */
	public function test_misuse() {
		$db = new Db();
		try {
			$db->lock( array( 'payer:01001234567' ) );
			$this->fail( 'lock() outside a transaction' );
		} catch ( \LogicException $e ) {
			$this->assertSame( array(), $this->wpdb->queries );
		}
		$db->begin();
		$this->expectException( \LogicException::class );
		$db->begin();
	}

	/** A failed read throws (an empty answer would read as "no order"); a failed query() returns false. */
	public function test_read_errors() {
		$db = new Db();
		$this->wpdb->on( '/^SELECT/', null, "Table 'wp_qersh_orders' doesn't exist" );
		try {
			$db->row( 'SELECT * FROM %i WHERE order_id = %d', array( $db->table( 'orders' ), 5 ) );
			$this->fail( 'no exception' );
		} catch ( \RuntimeException $e ) {
			$this->assertNotInstanceOf( LockTimeout::class, $e );
		}
		$this->wpdb->on( '/^DELETE/', false, 'some error' );
		$this->assertFalse( $db->query( 'DELETE FROM %i WHERE order_id = %d', array( $db->table( 'orders' ), 5 ) ) );
	}

	/** table() knows the five tables only. */
	public function test_table() {
		$db = new Db();
		$this->assertSame( 'wp_qersh_deliveries', $db->table( 'deliveries' ) );
		$this->assertSame( 'wp_qersh_locks', $db->table( 'qersh_locks' ) );
		$this->expectException( \InvalidArgumentException::class );
		$db->table( 'posts' );
	}

	/** Values are checked like MySQL would otherwise do silently. */
	public function test_values() {
		$this->assertSame( array( '%d', 7 ), Db::value( 'orders', 'order_id', '7' ) );
		$this->assertSame( array( '%d', 1 ), Db::value( 'transfers', 'auto_confirm_ok', true ) );
		$this->assertSame( array( 'NULL', null ), Db::value( 'transfers', 'payer_key', '' ) );
		$this->assertSame( array( 'NULL', null ), Db::value( 'transfers', 'order_id', null ) );
		$this->assertSame( array( '%s', '["no_baseline","balance_stale"]' ), Db::value( 'transfers', 'risk_reasons', array( 'no_baseline', 'balance_stale' ) ) );
		$this->assertSame( array( '%s', str_repeat( 'ب', 255 ) ), Db::value( 'transfers', 'note', str_repeat( 'ب', 300 ) ), 'display strings are cut by characters' );
		$this->assertSame( array( 'NULL', null ), Db::value( 'wallets', 'last_device_state', array( 'x' => str_repeat( 'y', 300 ) ) ), 'too long JSON is dropped' );

		// A transaction id is compared for equality (duplicate rule, customer's id): never cut, NULL when too long.
		$this->assertSame( array( '%s', '12345678901234' ), Db::value( 'transfers', 'provider_txn_id', '12345678901234' ) );
		$this->assertSame( array( '%s', str_repeat( '1', 40 ) ), Db::value( 'transfers', 'provider_txn_id', str_repeat( '1', 40 ) ) );
		$this->assertSame( array( 'NULL', null ), Db::value( 'transfers', 'provider_txn_id', str_repeat( '1', 45 ) ) );
		$this->assertSame( array( 'NULL', null ), Db::value( 'transfers', 'provider_txn_id', str_repeat( '1', 40 ) . '2' ), 'two long ids never become equal' );
		$this->assertSame( array( 'NULL', null ), Db::value( 'transfers', 'provider_txn_id', '' ) );
		$this->assertSame( array( 'NULL', null ), Db::value( 'transfers', 'provider_txn_id', null ) );
		$this->assertSame( array( '%s', str_repeat( 'ب', 40 ) ), Db::value( 'transfers', 'provider_txn_id', str_repeat( 'ب', 40 ) ), 'characters, not bytes' );

		$refused = array(
			array( 'transfers', 'transfer_id', str_repeat( 'a', 41 ) ),
			array( 'deliveries', 'delivery_id', '' ),
			array( 'orders', 'payer_key', null ),
			array( 'orders', 'price_minor', '12.5' ),
			array( 'orders', 'price_minor', array( 1 ) ),
			array( 'orders', 'no_such_column', 1 ),
		);
		foreach ( $refused as $case ) {
			try {
				Db::value( $case[0], $case[1], $case[2] );
				$this->fail( 'accepted ' . $case[0] . '.' . $case[1] );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertStringNotContainsString( 'aaaa', $e->getMessage(), 'the message never carries the value' );
			}
		}
	}

	/** insert_sql() and set_sql() write NULL literally and keep the arguments in placeholder order. */
	public function test_sql_builders() {
		$db = new Db();
		list( $sql, $args ) = $db->insert_sql(
			'deliveries',
			array(
				'delivery_id' => '0b9a4c1e-7d3f-4c2a-9e1b-5f6a7b8c9d0e',
				'redelivery'  => null,
				'claimed_at'  => 1760000000,
			),
			'INSERT IGNORE'
		);
		$this->assertSame( 'INSERT IGNORE INTO %i (delivery_id, redelivery, claimed_at) VALUES (%s, NULL, %d)', $sql );
		$this->assertSame( array( 'wp_qersh_deliveries', '0b9a4c1e-7d3f-4c2a-9e1b-5f6a7b8c9d0e', 1760000000 ), $args );

		list( $set, $set_args ) = Db::set_sql(
			'orders',
			array(
				'state'   => 'paid',
				'paid_at' => null,
			)
		);
		$this->assertSame( 'state = %s, paid_at = NULL', $set );
		$this->assertSame( array( 'paid' ), $set_args );
	}

	/** Rows read back are typed. */
	public function test_typed() {
		$row = Db::typed(
			'transfers',
			array(
				'id'           => '3',
				'amount_minor' => '15000',
				'order_id'     => null,
				'risk_reasons' => '["no_baseline"]',
				'payer_key'    => '01001234567',
				'extra'        => 'x',
			)
		);
		$this->assertSame( 3, $row['id'] );
		$this->assertSame( 15000, $row['amount_minor'] );
		$this->assertNull( $row['order_id'] );
		$this->assertSame( array( 'no_baseline' ), $row['risk_reasons'] );
		$this->assertSame( '01001234567', $row['payer_key'] );
		$this->assertSame( 'x', $row['extra'] );
	}
}
