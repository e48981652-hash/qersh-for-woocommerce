<?php
/**
 * Tests of Store\OrdersIndex: the upsert, the claim counter, the fences and the sweeps.
 *
 * @package QershPay\WooCommerce\Tests
 */

declare( strict_types=1 );

namespace QershPay\WooCommerce\Tests\Unit\Store;

use QershPay\WooCommerce\Store\OrdersIndex;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/FakeWpdb.php';

/**
 * OrdersIndex on a scripted $wpdb.
 */
final class OrdersIndexTest extends TestCase {

	const NOW = 1760000000;

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

	/** On an existing row the upsert never touches created_at, the claim columns or the money received. */
	public function test_upsert_keeps_columns() {
		( new OrdersIndex() )->upsert(
			array(
				'order_id'    => 42,
				'wallet'      => 'k7q2m9x4p1rz',
				'payer_key'   => '01001234567',
				'price_minor' => 15000,
				'paid_minor'  => 0,
				'state'       => 'open',
				'created_at'  => self::NOW,
				'expires_at'  => self::NOW + 7200,
				'claim_tries' => 0,
			)
		);
		$sql = $this->wpdb->queries[0];
		$this->assertStringStartsWith( 'INSERT INTO `wp_qersh_orders` (order_id, wallet, payer_key, price_minor, paid_minor, state, created_at, expires_at, claim_tries) VALUES (42, ', $sql );
		$update = substr( $sql, (int) strpos( $sql, ' ON DUPLICATE KEY UPDATE ' ) );
		$this->assertSame( " ON DUPLICATE KEY UPDATE wallet = 'k7q2m9x4p1rz', payer_key = '01001234567', price_minor = 15000, state = 'open', expires_at = " . ( self::NOW + 7200 ), $update );
		$this->assertStringNotContainsString( 'VALUES(', $sql, 'no VALUES() function' );
	}

	/** A new row needs its required columns. */
	public function test_upsert_requires() {
		$this->expectException( \InvalidArgumentException::class );
		( new OrdersIndex() )->upsert(
			array(
				'order_id' => 42,
				'state'    => 'open',
			)
		);
	}

	/** The counter of docs/17 §4.10 step 5, literally. */
	public function test_bump_claim_ok() {
		$this->wpdb->on( '/^UPDATE `wp_qersh_orders` SET claim_tries/', 1 );
		$this->assertSame( 'ok', ( new OrdersIndex() )->bump_claim( 42, '12345678901234', self::NOW ) );
		$this->assertSame(
			"UPDATE `wp_qersh_orders` SET claim_tries = claim_tries + 1, claim_at = " . self::NOW . ", claim_txn = '12345678901234' WHERE order_id = 42 AND claim_tries < 5 AND ( claim_tries < 3 OR claim_at IS NULL OR claim_at < " . ( self::NOW - 600 ) . ' )',
			$this->wpdb->queries[0]
		);
	}

	/** Zero rows: locked after five, otherwise the seconds left of the ten minutes. */
	public function test_bump_claim_refusals() {
		$this->wpdb->on( '/^UPDATE/', 0 );
		$this->wpdb->on(
			'/^SELECT \* FROM `wp_qersh_orders` WHERE order_id = 42$/',
			array(
				'order_id'    => '42',
				'claim_tries' => '3',
				'claim_at'    => (string) ( self::NOW - 100 ),
			)
		);
		$this->assertSame( 'wait:500', ( new OrdersIndex() )->bump_claim( 42, '12345678901234', self::NOW ) );

		$this->assertSame( 'locked', OrdersIndex::claim_verdict( array( 'claim_tries' => 5, 'claim_at' => self::NOW ), self::NOW ) );
		$this->assertSame( 'locked', OrdersIndex::claim_verdict( null, self::NOW ) );
		$this->assertSame( 'wait:1', OrdersIndex::claim_verdict( array( 'claim_tries' => 4, 'claim_at' => self::NOW - 600 ), self::NOW ) );
	}

	/** The second fence of rule 4a writes state paid and keeps all its conditions. */
	public function test_fence_claimant() {
		$this->wpdb->on( '/^UPDATE/', 1 );
		$ok = ( new OrdersIndex() )->fence_claimant(
			42,
			'12345678901234',
			'k7q2m9x4p1rz',
			'01001234567',
			array(
				'paid_minor' => 15000,
				'completion' => 'due',
				'state'      => 'review',
			)
		);
		$this->assertTrue( $ok );
		$this->assertSame(
			"UPDATE `wp_qersh_orders` SET state = 'paid', paid_minor = 15000, completion = 'due' WHERE order_id = 42 AND claim_txn = '12345678901234' AND wallet = 'k7q2m9x4p1rz' AND payer_key = '01001234567' AND state IN ('open','partial','expired')",
			$this->wpdb->queries[0]
		);
	}

	/** The completion claim of docs/17 §4.7. */
	public function test_claim_completion() {
		$this->wpdb->on( '/^UPDATE/', 0 );
		$this->assertFalse( ( new OrdersIndex() )->claim_completion( 42, self::NOW ) );
		$this->assertSame(
			"UPDATE `wp_qersh_orders` SET completion = 'running', completion_at = " . self::NOW . " WHERE order_id = 42 AND state = 'paid' AND ( completion = 'due' OR ( completion = 'running' AND ( completion_at IS NULL OR completion_at < " . ( self::NOW - 900 ) . ' ) ) )',
			$this->wpdb->queries[0]
		);
	}

	/** The candidate window is an overlap: created by the end, not expired before the start. */
	public function test_for_payer_window() {
		$this->wpdb->on( '/^SELECT/', array( array( 'order_id' => '42', 'price_minor' => '15000', 'claim_txn' => null ) ) );
		$rows = ( new OrdersIndex() )->for_payer( '01001234567', self::NOW - 7200, self::NOW );
		$this->assertSame( 42, $rows[0]['order_id'] );
		$this->assertSame( 15000, $rows[0]['price_minor'] );
		$this->assertNull( $rows[0]['claim_txn'] );
		$this->assertSame( "SELECT * FROM `wp_qersh_orders` WHERE payer_key = '01001234567' AND created_at <= " . self::NOW . ' AND expires_at >= ' . ( self::NOW - 7200 ) . ' ORDER BY created_at ASC, order_id ASC', $this->wpdb->queries[0] );
	}

	/**
	 * The expiry sweep takes the late window in seconds: due open/partial rows first, then expired rows of the last
	 * day (cut-short cancellations), newest first, with what is left of the 200.
	 */
	public function test_due_expiries() {
		$this->wpdb->on( "/^SELECT order_id FROM `wp_qersh_orders` WHERE state IN \\('open','partial'\\)/", array( array( 'order_id' => '7' ), array( 'order_id' => '9' ) ) );
		$this->wpdb->on( "/^SELECT order_id FROM `wp_qersh_orders` WHERE state = 'expired'/", array( array( 'order_id' => '30' ), array( 'order_id' => '9' ), array( 'order_id' => '4' ) ) );
		$this->assertSame( array( 7, 9, 30, 4 ), ( new OrdersIndex() )->due_expiries( self::NOW, 7200 ), 'a row that expired between the reads comes once' );
		$cut = self::NOW - 7200;
		$this->assertSame( "SELECT order_id FROM `wp_qersh_orders` WHERE state IN ('open','partial') AND expires_at <= {$cut} ORDER BY expires_at ASC, order_id ASC LIMIT 200", $this->wpdb->queries[0] );
		$this->assertSame( "SELECT order_id FROM `wp_qersh_orders` WHERE state = 'expired' AND expires_at > " . ( $cut - 86400 ) . " AND expires_at <= {$cut} ORDER BY expires_at DESC, order_id DESC LIMIT 198", $this->wpdb->queries[1] );
	}

	/** Many expired rows of the last day never push a due order out: with 200 due rows the recheck waits. */
	public function test_due_expiries_due_rows_first() {
		$due = array();
		for ( $i = 1; $i <= 200; $i++ ) {
			$due[] = array( 'order_id' => (string) $i );
		}
		$this->wpdb->on( "/WHERE state IN \\('open','partial'\\)/", $due );
		$ids = ( new OrdersIndex() )->due_expiries( self::NOW, 7200 );
		$this->assertCount( 200, $ids );
		$this->assertSame( 200, $ids[199] );
		$this->assertCount( 1, $this->wpdb->queries, 'no expired recheck this run' );
	}
}
