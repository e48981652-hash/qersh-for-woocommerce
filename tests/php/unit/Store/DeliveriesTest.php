<?php
/**
 * Tests of Store\Deliveries: the claim of docs/17 §4.4 step 6, the fence and the release.
 *
 * @package QershPay\WooCommerce\Tests
 */

declare( strict_types=1 );

namespace QershPay\WooCommerce\Tests\Unit\Store;

use QershPay\WooCommerce\Store\Deliveries;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/FakeWpdb.php';

/**
 * Deliveries on a scripted $wpdb.
 */
final class DeliveriesTest extends TestCase {

	const ID  = '0b9a4c1e-7d3f-4c2a-9e1b-5f6a7b8c9d0e';
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

	/** One inserted row: the delivery is ours, with a 32-hex token. */
	public function test_fresh_claim() {
		$this->wpdb->on( '/^INSERT IGNORE INTO `wp_qersh_deliveries`/', 1 );
		$claim = ( new Deliveries() )->claim( self::ID, 'k7q2m9x4p1rz', 'transfer.received', null, self::NOW );
		$this->assertSame( 'claimed', $claim['state'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', $claim['token'] );
		$insert = $this->wpdb->queries[0];
		$this->assertStringContainsString( "(delivery_id, wallet, event, redelivery, state, claim_token, claimed_at) VALUES ('" . self::ID . "', 'k7q2m9x4p1rz', 'transfer.received', NULL, 'processing', '" . $claim['token'] . "', " . self::NOW . ')', $insert );
		$this->assertCount( 1, $this->wpdb->queries );
	}

	/** A finished delivery gives back its stored reply and status. */
	public function test_done_replays() {
		$this->wpdb->on( '/^INSERT IGNORE/', 0 );
		$this->wpdb->on(
			'/^SELECT state, http_status, reply_json, claimed_at FROM `wp_qersh_deliveries`/',
			array(
				'state'       => 'done',
				'http_status' => '200',
				'reply_json'  => '{"status":"matched","reference":"1042","note":"طابق"}',
				'claimed_at'  => (string) ( self::NOW - 86400 ),
			)
		);
		$claim = ( new Deliveries() )->claim( self::ID, 'k7q2m9x4p1rz', 'transfer.received', 'manual', self::NOW );
		$this->assertSame(
			array(
				'state'       => 'done',
				'http_status' => 200,
				'reply'       => array(
					'status'    => 'matched',
					'reference' => '1042',
					'note'      => 'طابق',
				),
			),
			$claim
		);
	}

	/** In processing for 60 seconds or less: busy, and nothing is taken over. */
	public function test_recent_processing_is_busy() {
		$this->wpdb->on( '/^INSERT IGNORE/', 0 );
		$this->wpdb->on(
			'/^SELECT state/',
			array(
				'state'       => 'processing',
				'http_status' => null,
				'reply_json'  => null,
				'claimed_at'  => (string) ( self::NOW - 60 ),
			)
		);
		$this->assertSame( array( 'state' => 'busy' ), ( new Deliveries() )->claim( self::ID, 'k7q2m9x4p1rz', 'transfer.received', null, self::NOW ) );
		$this->assertSame( array(), $this->wpdb->matching( '/^UPDATE/' ) );
	}

	/** Older than 60 seconds: taken over by an UPDATE that only one request can win. */
	public function test_stale_processing_is_reclaimed() {
		$this->wpdb->on( '/^INSERT IGNORE/', 0 );
		$this->wpdb->on(
			'/^SELECT state/',
			array(
				'state'       => 'processing',
				'http_status' => null,
				'reply_json'  => null,
				'claimed_at'  => (string) ( self::NOW - 61 ),
			)
		);
		$this->wpdb->on( '/^UPDATE `wp_qersh_deliveries` SET claim_token/', 1 );
		$claim = ( new Deliveries() )->claim( self::ID, 'k7q2m9x4p1rz', 'transfer.received', null, self::NOW );
		$this->assertSame( 'claimed', $claim['state'] );
		$update = $this->wpdb->matching( '/^UPDATE/' );
		$this->assertCount( 1, $update );
		$this->assertSame( "UPDATE `wp_qersh_deliveries` SET claim_token = '" . $claim['token'] . "', claimed_at = " . self::NOW . " WHERE delivery_id = '" . self::ID . "' AND state = 'processing' AND claimed_at < " . ( self::NOW - 60 ), $update[0] );
	}

	/** Losing the takeover: the winner's result if it is done already, otherwise busy. */
	public function test_lost_reclaim() {
		$stale = array(
			'state'       => 'processing',
			'http_status' => null,
			'reply_json'  => null,
			'claimed_at'  => (string) ( self::NOW - 300 ),
		);
		$this->wpdb->on( '/^INSERT IGNORE/', 0 );
		$this->wpdb->on( '/^SELECT state/', $stale );
		$this->wpdb->on( '/^UPDATE/', 0 );
		$this->wpdb->on( '/^SELECT state/', $stale );
		$this->assertSame( array( 'state' => 'busy' ), ( new Deliveries() )->claim( self::ID, 'k7q2m9x4p1rz', 'transfer.received', null, self::NOW ) );
	}

	/** A row released between our insert and our read: the claim tries once more. */
	public function test_released_meanwhile() {
		$this->wpdb->on( '/^INSERT IGNORE/', 0 );
		$this->wpdb->on( '/^SELECT state/', null );
		$this->wpdb->on( '/^INSERT IGNORE/', 1 );
		$claim = ( new Deliveries() )->claim( self::ID, 'k7q2m9x4p1rz', 'transfer.received', null, self::NOW );
		$this->assertSame( 'claimed', $claim['state'] );
		$this->assertCount( 2, $this->wpdb->matching( '/^INSERT IGNORE/' ) );
	}

	/** An id the column would cut is refused before any query. */
	public function test_invalid_id() {
		$this->expectException( \InvalidArgumentException::class );
		( new Deliveries() )->claim( str_repeat( 'a', 37 ), 'k7q2m9x4p1rz', 'transfer.received', null, self::NOW );
	}

	/** The fence marks our processing row done and never stores status_updates. */
	public function test_fence() {
		$this->wpdb->on( '/^UPDATE `wp_qersh_deliveries` SET state/', 1 );
		$ok = ( new Deliveries() )->fence(
			self::ID,
			str_repeat( 'a', 32 ),
			200,
			array(
				'status'         => 'ok',
				'status_updates' => array( array( 'transfer_id' => 'trf_x' ) ),
			),
			self::NOW
		);
		$this->assertTrue( $ok );
		$this->assertSame(
			"UPDATE `wp_qersh_deliveries` SET state = 'done', http_status = 200, reply_json = '{\\\"status\\\":\\\"ok\\\"}', answered_at = " . self::NOW . " WHERE delivery_id = '" . self::ID . "' AND claim_token = '" . str_repeat( 'a', 32 ) . "' AND state = 'processing'",
			$this->wpdb->queries[0]
		);
		$this->wpdb->on( '/^UPDATE/', 0 );
		$this->assertFalse( ( new Deliveries() )->fence( self::ID, str_repeat( 'b', 32 ), 200, array(), self::NOW ) );
	}

	/** Release deletes only our own processing row and swallows errors. */
	public function test_release() {
		$this->wpdb->on( '/^DELETE/', false, 'Lock wait timeout exceeded; try restarting transaction' );
		( new Deliveries() )->release( self::ID, str_repeat( 'c', 32 ) );
		$this->assertSame( "DELETE FROM `wp_qersh_deliveries` WHERE delivery_id = '" . self::ID . "' AND claim_token = '" . str_repeat( 'c', 32 ) . "' AND state = 'processing'", $this->wpdb->queries[0] );
	}

	/** verdict(): done, busy within 60 s, stale after. */
	public function test_verdict() {
		$this->assertSame( 'done', Deliveries::verdict( array( 'state' => 'done', 'claimed_at' => self::NOW ), self::NOW ) );
		$this->assertSame( 'busy', Deliveries::verdict( array( 'state' => 'processing', 'claimed_at' => self::NOW ), self::NOW ) );
		$this->assertSame( 'busy', Deliveries::verdict( array( 'state' => 'processing', 'claimed_at' => self::NOW - 60 ), self::NOW ) );
		$this->assertSame( 'stale', Deliveries::verdict( array( 'state' => 'processing', 'claimed_at' => self::NOW - 61 ), self::NOW ) );
	}
}
