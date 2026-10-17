<?php
/**
 * Tests of Store\Wallets: the 404/410 rule of docs/17 §4.4 step 3 and the single-row writes.
 *
 * @package QershPay\WooCommerce\Tests
 */

declare( strict_types=1 );

namespace QershPay\WooCommerce\Tests\Unit\Store;

use QershPay\WooCommerce\Store\Wallets;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/FakeWpdb.php';

/**
 * Wallets on a scripted $wpdb.
 */
final class WalletsTest extends TestCase {

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

	/** Unknown, active, deleted for less than 72 hours (404), then gone (410). */
	public function test_gone_state() {
		$this->assertSame( 'unknown', Wallets::gone_state( null, self::NOW ) );
		$this->assertSame( 'active', Wallets::gone_state( array( 'state' => 'active' ), self::NOW ) );
		$deleted = array(
			'state'      => 'deleted',
			'deleted_at' => self::NOW - 259199,
			'gone_at'    => self::NOW + 1,
		);
		$this->assertSame( 'deleted_recent', Wallets::gone_state( $deleted, self::NOW ) );
		$deleted['gone_at'] = self::NOW;
		$this->assertSame( 'gone', Wallets::gone_state( $deleted, self::NOW ), 'at 72 hours exactly it is gone' );
		$this->assertSame( 'deleted_recent', Wallets::gone_state( array( 'state' => 'deleted', 'deleted_at' => self::NOW - 10, 'gone_at' => null ), self::NOW ) );
	}

	/** gone() reads the row. */
	public function test_gone_reads_row() {
		$this->wpdb->on(
			'/^SELECT \* FROM `wp_qersh_wallets` WHERE wallet = /',
			array(
				'wallet'     => 'k7q2m9x4p1rz',
				'state'      => 'deleted',
				'deleted_at' => (string) ( self::NOW - 300000 ),
				'gone_at'    => (string) ( self::NOW - 300000 + 259200 ),
			)
		);
		$this->assertSame( 'gone', ( new Wallets() )->gone( 'k7q2m9x4p1rz', self::NOW ) );
	}

	/** touch() writes only runtime columns, in one UPDATE, with JSON for the device state. */
	public function test_touch() {
		( new Wallets() )->touch(
			'k7q2m9x4p1rz',
			array(
				'last_delivery_at'  => self::NOW,
				'last_device_state' => array( 'sms_permission' => true ),
				'state'             => 'deleted',
				'seen_form'         => 'rest_route',
			)
		);
		$this->assertSame(
			"UPDATE `wp_qersh_wallets` SET last_delivery_at = " . self::NOW . ", last_device_state = '{\\\"sms_permission\\\":true}', seen_form = 'rest_route' WHERE wallet = 'k7q2m9x4p1rz'",
			$this->wpdb->queries[0]
		);
	}

	/** Deleting keeps the first deletion time: only an active row changes. */
	public function test_mark_deleted() {
		( new Wallets() )->mark_deleted( 'k7q2m9x4p1rz', self::NOW, array( 'id' => 'k7q2m9x4p1rz', 'label' => 'فودافون' ) );
		$this->assertSame( "INSERT IGNORE INTO `wp_qersh_wallets` (wallet, state) VALUES ('k7q2m9x4p1rz', 'active')", $this->wpdb->queries[0] );
		$this->assertSame(
			"UPDATE `wp_qersh_wallets` SET state = 'deleted', deleted_at = " . self::NOW . ', gone_at = ' . ( self::NOW + 259200 ) . ", key_erased_at = NULL, snapshot = '{\\\"id\\\":\\\"k7q2m9x4p1rz\\\",\\\"label\\\":\\\"فودافون\\\"}' WHERE wallet = 'k7q2m9x4p1rz' AND state = 'active'",
			$this->wpdb->queries[1]
		);
	}

	/**
	 * Each deletion starts a new seal period (docs/17 §4.4 step 3, §4.8): delete, the cleanup erases the key after 72
	 * hours, restore, delete again, and the cleanup erases the second sealed key 72 hours after the second deletion.
	 */
	public function test_second_deletion_is_erased_again() {
		$row = $this->install_wallet_table();
		$w   = new Wallets();
		$id  = 'k7q2m9x4p1rz';

		$w->mark_deleted( $id, self::NOW, array( 'id' => $id ) );
		$this->assertSame( 0, $w->erase_due_keys( self::NOW + 259199 ), 'sealed for 72 hours' );
		$this->assertSame( 1, $w->erase_due_keys( self::NOW + 259200 ) );
		$this->assertSame( self::NOW + 259200, (int) $row->data['key_erased_at'] );
		$this->assertSame( 0, $w->erase_due_keys( self::NOW + 400000 ), 'erased once' );

		$this->assertSame( array( 'id' => $id ), $w->restore( $id ) );
		$this->assertSame( 'active', $row->data['state'] );

		$again = self::NOW + 500000;
		$w->mark_deleted( $id, $again, array( 'id' => $id ) );
		$this->assertSame( 'deleted', $row->data['state'] );
		$this->assertNull( $row->data['key_erased_at'], 'the new deletion has a key to erase' );
		$this->assertSame( 0, $w->erase_due_keys( $again + 259199 ) );
		$this->assertSame( 1, $w->erase_due_keys( $again + 259200 ), 'the second sealed key is erased too' );
		$this->assertSame( $again + 259200, (int) $row->data['key_erased_at'] );

		$w->mark_deleted( $id, $again + 600000, array( 'id' => $id ) );
		$this->assertSame( $again + 259200, (int) $row->data['key_erased_at'], 'deleting a deleted wallet changes nothing' );
	}

	/**
	 * A one-row qersh_wallets on the fake database, enough for the statements of Wallets: INSERT IGNORE, SELECT *,
	 * the cleanup's SELECT and the UPDATEs with their WHERE conditions.
	 *
	 * @return \stdClass Holder whose `data` is the row (null until inserted).
	 */
	private function install_wallet_table(): \stdClass {
		$row       = new \stdClass();
		$row->data = null;
		$this->wpdb->on(
			'/`wp_qersh_wallets`/',
			function ( string $sql ) use ( $row ) {
				if ( 0 === strpos( $sql, 'INSERT IGNORE' ) ) {
					if ( null !== $row->data ) {
						return 0;
					}
					preg_match( "/VALUES \\('([a-z0-9]+)', '([a-z]+)'\\)$/", $sql, $m );
					$row->data = array(
						'wallet'        => $m[1],
						'state'         => $m[2],
						'deleted_at'    => null,
						'gone_at'       => null,
						'key_erased_at' => null,
						'snapshot'      => null,
					);
					return 1;
				}
				if ( 0 === strpos( $sql, 'SELECT * ' ) ) {
					return $row->data;
				}
				if ( 0 === strpos( $sql, 'SELECT wallet ' ) ) {
					preg_match( '/gone_at <= (\d+)$/', $sql, $m );
					$due = null !== $row->data && 'deleted' === $row->data['state'] && null === $row->data['key_erased_at'] && (int) $row->data['gone_at'] <= (int) $m[1];
					return $due ? array( array( 'wallet' => $row->data['wallet'] ) ) : array();
				}
				if ( 1 === preg_match( '/^UPDATE `wp_qersh_wallets` SET (.*) WHERE (.*)$/', $sql, $m ) ) {
					if ( null === $row->data ) {
						return 0;
					}
					$where = $m[2];
					if ( ( false !== strpos( $where, "state = 'active'" ) && 'active' !== $row->data['state'] )
						|| ( false !== strpos( $where, "state = 'deleted'" ) && 'deleted' !== $row->data['state'] )
						|| ( false !== strpos( $where, 'key_erased_at IS NULL' ) && null !== $row->data['key_erased_at'] ) ) {
						return 0;
					}
					preg_match_all( "/(\\w+) = (NULL|-?\\d+|'(?:[^'\\\\]|\\\\.)*')/", $m[1], $sets, PREG_SET_ORDER );
					foreach ( $sets as $set ) {
						if ( 'NULL' === $set[2] ) {
							$value = null;
						} elseif ( "'" === $set[2][0] ) {
							$value = stripslashes( substr( $set[2], 1, -1 ) );
						} else {
							$value = $set[2];
						}
						$row->data[ $set[1] ] = $value;
					}
					return 1;
				}
				throw new \LogicException( 'unexpected statement: ' . $sql );
			},
			'',
			true
		);
		return $row;
	}
}
