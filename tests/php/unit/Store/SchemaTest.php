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
	public function test_five_tables() {
		$sql = $this->sql();
		$this->assertSame( array( 'deliveries', 'transfers', 'orders', 'wallets', 'locks' ), array_keys( $sql ) );
		foreach ( $sql as $short => $statement ) {
			$this->assertStringStartsWith( 'CREATE TABLE wp_qersh_' . $short . " (\n", $statement );
			$this->assertStringEndsWith( "\n) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci", $statement );
		}
		$this->assertSame( array( 'wp_qersh_deliveries', 'wp_qersh_transfers', 'wp_qersh_orders', 'wp_qersh_wallets', 'wp_qersh_locks' ), Schema::table_names( 'wp_' ) );
	}

	/** One field per line, two spaces after PRIMARY KEY, KEY never INDEX, no backticks (docs/16 §8). */
	public function test_dbdelta_rules() {
		foreach ( $this->sql() as $short => $statement ) {
			$this->assertStringNotContainsString( '`', $statement, $short );
			$this->assertDoesNotMatchRegularExpression( '/\bINDEX\b/i', $statement, $short );
			$this->assertSame( 1, preg_match_all( '/^PRIMARY KEY  \([a-z_]+\),?$/m', $statement ), $short );
			$this->assertDoesNotMatchRegularExpression( '/PRIMARY KEY \(/', $statement, $short );
			foreach ( array_slice( explode( "\n", $statement ), 1, -1 ) as $line ) {
				$this->assertSame( 1, preg_match( '/^(?:[a-z_]+ [A-Z]+(?:\(\d+\))?(?: UNSIGNED)?(?: NOT NULL| NULL)(?: AUTO_INCREMENT| DEFAULT (?:\d+|\'[a-z]+\'))?|PRIMARY KEY  \([a-z_]+\)|(?:UNIQUE )?KEY [a-z_]+ \([a-z_,]+\)),?$/', $line ), $short . ': ' . $line );
			}
		}
	}

	/** Every column of CONTRACTS.md §7 is in the CREATE statement and in COLUMNS, and nothing else. */
	public function test_columns_match() {
		$contract = array(
			'deliveries' => 'id delivery_id wallet event redelivery state claim_token http_status reply_json claimed_at answered_at',
			'transfers'  => 'id transfer_id wallet provider delivery_id amount_minor currency payer_key payer_name provider_txn_id money_at sms_at risk risk_reasons auto_confirm_ok balance_chain outcome reason claim_eval claim_eval_at order_id flag phone_sync phone_sync_since phone_sync_count note created_at updated_at',
			'orders'     => 'order_id wallet payer_key price_minor paid_minor state created_at expires_at paid_at completion completion_at claim_txn claim_at claim_tries',
			'wallets'    => 'wallet state deleted_at gone_at key_erased_at snapshot last_delivery_at last_event last_heartbeat_at last_device_state grace_until sender_masked auto_seen seen_host seen_form last_test_at last_test_app_version last_test_provider last_test_number_masked silent_since silent_notified',
			'locks'      => 'name created_at',
		);
		$sql = $this->sql();
		foreach ( $contract as $short => $names ) {
			$expected = explode( ' ', $names );
			$this->assertSame( $expected, array_keys( Schema::COLUMNS[ $short ] ), $short . ' COLUMNS' );
			preg_match_all( '/^([a-z_]+) [A-Z]/m', $sql[ $short ], $m );
			$this->assertSame( $expected, $m[1], $short . ' CREATE' );
		}
	}

	/** NULL in COLUMNS agrees with NULL in the CREATE statement. */
	public function test_nullability_matches() {
		$sql = $this->sql();
		foreach ( Schema::COLUMNS as $short => $columns ) {
			foreach ( $columns as $column => $spec ) {
				$this->assertSame( 1, preg_match( '/^' . $column . ' .*$/m', $sql[ $short ], $m ), $column );
				$nullable = Schema::parse_spec( $spec )[2];
				$this->assertSame( $nullable, false === strpos( $m[0], 'NOT NULL' ), $short . '.' . $column );
			}
		}
	}

	/** Key columns are refused when too long, so their lengths must equal the column. */
	public function test_key_lengths_match() {
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
	public function test_spec_types() {
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
}
