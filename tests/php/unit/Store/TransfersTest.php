<?php
/**
 * Tests of Store\Transfers: insert checks, the first fence of rule 4a and the phone sync counter.
 *
 * @package QershPay\WooCommerce\Tests
 */

declare( strict_types=1 );

namespace QershPay\WooCommerce\Tests\Unit\Store;

use QershPay\WooCommerce\Store\Transfers;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/FakeWpdb.php';

/**
 * Transfers on a scripted $wpdb.
 */
final class TransfersTest extends TestCase {

	const NOW = 1760000000;
	const TRF = 'trf_01J9Z8Y7X6W5V4T3S2R1Q0P9N8';

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

	/**
	 * A transfer row.
	 *
	 * @return array
	 */
	private function row(): array {
		return array(
			'transfer_id'     => self::TRF,
			'wallet'          => 'k7q2m9x4p1rz',
			'provider'        => 'orange_cash',
			'delivery_id'     => '0b9a4c1e-7d3f-4c2a-9e1b-5f6a7b8c9d0e',
			'amount_minor'    => 15000,
			'currency'        => 'EGP',
			'payer_key'       => null,
			'provider_txn_id' => '12345678901234',
			'money_at'        => self::NOW - 30,
			'risk_reasons'    => array(),
			'auto_confirm_ok' => true,
			'created_at'      => self::NOW,
		);
	}

	/** INSERT IGNORE: true for a new row, false for one that exists. */
	public function test_insert() {
		$this->wpdb->on( '/^INSERT IGNORE INTO `wp_qersh_transfers`/', 1 );
		$this->wpdb->on( '/^INSERT IGNORE INTO `wp_qersh_transfers`/', 0 );
		$store = new Transfers();
		$this->assertTrue( $store->insert( $this->row() ) );
		$this->assertFalse( $store->insert( $this->row() ) );
		$this->assertStringContainsString( "payer_key, provider_txn_id, money_at, risk_reasons, auto_confirm_ok, created_at, updated_at) VALUES ('" . self::TRF . "', 'k7q2m9x4p1rz', 'orange_cash', '0b9a4c1e-7d3f-4c2a-9e1b-5f6a7b8c9d0e', 15000, 'EGP', NULL, '12345678901234', " . ( self::NOW - 30 ) . ", '[]', 1, " . self::NOW . ', ' . self::NOW . ')', $this->wpdb->queries[0] );
	}

	/** A database error is never read as "already there". */
	public function test_insert_error_throws() {
		$this->wpdb->on( '/^INSERT IGNORE/', false, "Table 'wp_qersh_transfers' doesn't exist" );
		$this->expectException( \RuntimeException::class );
		( new Transfers() )->insert( $this->row() );
	}

	/** A transfer id the column would cut is refused. */
	public function test_insert_refuses_long_id() {
		$row                = $this->row();
		$row['transfer_id'] = str_repeat( 'x', 41 );
		$this->expectException( \InvalidArgumentException::class );
		( new Transfers() )->insert( $row );
	}

	/** The first fence of rule 4a, with its exact conditions. */
	public function test_fence_match() {
		$this->wpdb->on( '/^UPDATE/', 1 );
		$this->assertTrue( ( new Transfers() )->fence_match( self::TRF, 42 ) );
		$this->assertMatchesRegularExpression(
			"/^UPDATE `wp_qersh_transfers` SET outcome = 'matched', order_id = 42, updated_at = \\d+ WHERE transfer_id = '" . self::TRF . "' AND order_id IS NULL AND \\( outcome IS NULL OR \\( outcome = 'review' AND reason = 'no_payer' \\) \\)$/",
			$this->wpdb->queries[0]
		);
	}

	/** Three replies or seven days; the CASE reads the counter before it is raised. */
	public function test_note_synced() {
		( new Transfers() )->note_synced( array( self::TRF, self::TRF, '' ), self::NOW );
		$this->assertSame(
			"UPDATE `wp_qersh_transfers` SET phone_sync = CASE WHEN phone_sync_count >= 2 OR phone_sync_since IS NULL OR phone_sync_since < " . ( self::NOW - 604800 ) . " THEN 'none' ELSE 'pending' END, phone_sync_count = phone_sync_count + 1 WHERE phone_sync = 'pending' AND transfer_id IN ('" . self::TRF . "')",
			$this->wpdb->queries[0]
		);
		( new Transfers() )->note_synced( array(), self::NOW );
		$this->assertCount( 1, $this->wpdb->queries );
	}

	/** An over-long raw transaction id is stored as NULL (never cut), so it never equals another row. */
	public function test_long_txn_never_matches() {
		$row                    = $this->row();
		$row['provider']        = 'vodafone_cash';
		$row['provider_txn_id'] = str_repeat( '1', 40 ) . '23456';
		$this->wpdb->on( '/^INSERT IGNORE/', 1 );
		$this->assertTrue( ( new Transfers() )->insert( $row ) );
		$this->assertStringContainsString( "'EGP', NULL, NULL, ", $this->wpdb->queries[0] );
		$this->assertStringNotContainsString( str_repeat( '1', 40 ), $this->wpdb->queries[0] );

		$this->assertSame( array(), ( new Transfers() )->find_by_txn( 'vodafone_cash', str_repeat( '1', 40 ) . '99999' ) );
		$this->assertSame( array(), ( new Transfers() )->find_by_txn( 'vodafone_cash', '' ) );
		$this->assertCount( 1, $this->wpdb->queries, 'no query for an id that is never stored' );

		( new Transfers() )->find_by_txn( 'orange_cash', '12345678901234' );
		$this->assertSame( "SELECT * FROM `wp_qersh_transfers` WHERE provider = 'orange_cash' AND provider_txn_id = '12345678901234' ORDER BY id ASC", $this->wpdb->queries[1] );
	}

	/**
	 * Retention (docs/17 §4.3): after the retention time, unlinked transfers and those whose order is gone lose the
	 * payer number and name; forget_order() does it at once when the order is deleted.
	 */
	public function test_personal_data_follows_the_order() {
		$this->wpdb->on( '/^UPDATE/', 4 );
		$this->assertSame( 4, ( new Transfers() )->purge_personal( self::NOW - 90 * 86400 ) );
		$this->assertSame(
			'UPDATE `wp_qersh_transfers` SET payer_key = NULL, payer_name = NULL WHERE created_at < ' . ( self::NOW - 90 * 86400 ) . ' AND ( payer_key IS NOT NULL OR payer_name IS NOT NULL ) AND ( order_id IS NULL OR order_id NOT IN ( SELECT order_id FROM `wp_qersh_orders` ) )',
			$this->wpdb->queries[0]
		);

		$this->wpdb->on( '/^UPDATE/', 2 );
		$this->assertSame( 2, ( new Transfers() )->forget_order( 42 ) );
		$this->assertMatchesRegularExpression(
			'/^UPDATE `wp_qersh_transfers` SET payer_key = NULL, payer_name = NULL, updated_at = \d+ WHERE order_id = 42 AND \( payer_key IS NOT NULL OR payer_name IS NOT NULL \)$/',
			$this->wpdb->queries[1]
		);
		$this->assertSame( 0, ( new Transfers() )->forget_order( 0 ) );
		$this->assertCount( 2, $this->wpdb->queries );
	}

	/** The admin list: filter, count and page. */
	public function test_list_page() {
		$this->wpdb->on( '/^SELECT COUNT/', '3' );
		$this->wpdb->on( '/^SELECT \*/', array( array( 'id' => '9', 'transfer_id' => self::TRF ) ) );
		$page = ( new Transfers() )->list_page(
			array(
				'view'   => 'personal',
				'wallet' => 'k7q2m9x4p1rz',
				'search' => '+20 100 123 4567',
			),
			2,
			20
		);
		$this->assertSame( 3, $page['total'] );
		$this->assertSame( 9, $page['rows'][0]['id'] );
		$this->assertStringContainsString( "WHERE outcome = 'unmatched' AND reason = 'personal' AND wallet = 'k7q2m9x4p1rz' AND ( transfer_id = '+20 100 123 4567' OR provider_txn_id = '+20 100 123 4567' OR payer_key = '01001234567' OR payer_name LIKE '%+20 100 123 4567%' )", $this->wpdb->queries[0] );
		$this->assertStringEndsWith( 'ORDER BY created_at DESC, id DESC LIMIT 20 OFFSET 20', $this->wpdb->queries[1] );
	}
}
