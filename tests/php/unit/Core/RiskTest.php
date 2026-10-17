<?php
/**
 * Tests of Core\Risk: the known reasons equal the locked schema, and unknown reasons are found.
 *
 * @package QershPay\WooCommerce\Tests
 */

declare( strict_types=1 );

namespace QershPay\WooCommerce\Tests\Unit\Core;

use QershPay\WooCommerce\Core\Risk;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

defined( 'ABSPATH' ) || exit;

/**
 * Risk::KNOWN_REASONS, Risk::is_known() and Risk::unknown_reasons().
 */
final class RiskTest extends TestCase {

	/** KNOWN_REASONS is `$defs.riskReason` of fixtures/webhook.v1.schema.json, value for value and in order. */
	public function test_known_reasons_are_the_schema_enum(): void {
		$schema = json_decode( qersh_wc_test_fixture( 'fixtures/webhook.v1.schema.json' ), true );
		$this->assertIsArray( $schema );
		$enum = $schema['$defs']['riskReason']['enum'];
		$this->assertSame( $enum, Risk::KNOWN_REASONS );
		$this->assertCount( 22, Risk::KNOWN_REASONS );
		$this->assertSame( Risk::KNOWN_REASONS, array_values( array_unique( Risk::KNOWN_REASONS ) ) );
	}

	/** Every reason the matching rules of docs/17 §4.6 name is known. */
	public function test_reasons_named_by_the_design_are_known(): void {
		foreach ( array( 'no_baseline', 'balance_stale', 'amount_over_limit', 'notification_only', 'balance_unavailable', 'partial_template', 'unverified_template', 'suspected_forgery', 'balance_inconsistent' ) as $reason ) {
			$this->assertTrue( Risk::is_known( $reason ), $reason );
		}
	}

	/** Known means the exact string. */
	public function test_is_known_is_exact(): void {
		foreach ( array( 'SIM_MISMATCH', ' sim_mismatch', 'sim_mismatch ', '', 'unknown', 'low' ) as $reason ) {
			$this->assertFalse( Risk::is_known( $reason ), $reason );
		}
		foreach ( array( null, 1, true, array( 'sim_mismatch' ) ) as $reason ) {
			$this->assertFalse( Risk::is_known( $reason ) );
		}
	}

	/** No reasons, or only known ones: nothing unknown. */
	public function test_nothing_unknown(): void {
		$this->assertSame( array(), Risk::unknown_reasons( null ) );
		$this->assertSame( array(), Risk::unknown_reasons( array() ) );
		$this->assertSame( array(), Risk::unknown_reasons( Risk::KNOWN_REASONS ) );
		$this->assertSame( array(), Risk::unknown_reasons( array( 'balance_inconsistent', 'suspected_forgery' ) ) );
	}

	/** Unknown strings come back once each, in the order they first appear. */
	public function test_unknown_strings(): void {
		$this->assertSame(
			array( 'new_reason', 'Balance_Gap', '' ),
			Risk::unknown_reasons( array( 'no_baseline', 'new_reason', 'Balance_Gap', 'new_reason', 'balance_gap', '' ) )
		);
		$this->assertSame( array( 'سبب' ), Risk::unknown_reasons( array( 'سبب' ) ) );
	}

	/** A value that is not a string is unknown, as its JSON text; so is a field that is not an array. */
	public function test_malformed_values_are_unknown(): void {
		$this->assertSame( array( '42', 'null', 'true', '["sim_mismatch"]', '{"a":1}' ), Risk::unknown_reasons( array( 42, null, true, array( 'sim_mismatch' ), array( 'a' => 1 ), 42 ) ) );
		$this->assertSame( array( '"sim_mismatch"' ), Risk::unknown_reasons( 'sim_mismatch' ) );
		$this->assertSame( array( '0' ), Risk::unknown_reasons( 0 ) );
		$this->assertSame( array( 'false' ), Risk::unknown_reasons( false ) );
		$this->assertSame( array( 'double' ), Risk::unknown_reasons( array( NAN ) ) );
	}

	/** A JSON object in place of the list is read by its values. */
	public function test_object_values_are_read(): void {
		$this->assertSame( array(), Risk::unknown_reasons( array( 'x' => 'sim_mismatch' ) ) );
		$this->assertSame( array( 'odd' ), Risk::unknown_reasons( array( 'x' => 'odd' ) ) );
	}
}
