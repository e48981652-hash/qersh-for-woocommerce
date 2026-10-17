<?php
/**
 * Tests of the pure parts of Webhook\Module and Webhook\Fallback: routes, the size limit, action arguments and the
 * fallback address (docs/17 §4.4 steps 1 and 2).
 *
 * @package QershPay\WooCommerce\Tests
 */

declare( strict_types=1 );

namespace QershPay\WooCommerce\Tests\Unit\Webhook;

use QershPay\WooCommerce\Webhook\Fallback;
use QershPay\WooCommerce\Webhook\Module;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

defined( 'ABSPATH' ) || exit;

/**
 * Module and Fallback helpers.
 */
final class ModuleTest extends TestCase {

	/** The webhook route and the plugin's routes, as WP_REST_Request::get_route() gives them. */
	public function test_routes(): void {
		$this->assertTrue( Module::is_webhook_route( '/qersh/v1/webhook/k7q2m9x4p1rz' ) );
		$this->assertFalse( Module::is_webhook_route( '/qersh/v1/order-status' ) );
		$this->assertFalse( Module::is_webhook_route( '/wc/v3/orders' ) );
		$this->assertFalse( Module::is_webhook_route( 'qersh/v1/webhook/k7q2m9x4p1rz' ) );

		$this->assertTrue( Module::is_our_route( '/qersh/v1/order-txn' ) );
		$this->assertTrue( Module::is_our_route( '/qersh/v1' ) );
		$this->assertFalse( Module::is_our_route( '/qersh/v10/x' ) );
		$this->assertFalse( Module::is_our_route( '/wp/v2/posts' ) );

		$this->assertSame( 1, preg_match( '#^' . Module::ROUTE . '$#', '/webhook/k7q2m9x4p1rz' ) );
		$this->assertSame( 0, preg_match( '#^' . Module::ROUTE . '$#', '/webhook/K7Q2M9X4P1RZ' ) );
		$this->assertSame( 0, preg_match( '#^' . Module::ROUTE . '$#', '/webhook/k7q2m9x4p1r' ) );
	}

	/** 64 KB is accepted, one byte more is 413. */
	public function test_size_limit(): void {
		$this->assertFalse( Module::too_large( 65536 ) );
		$this->assertTrue( Module::too_large( 65537 ) );
		$this->assertFalse( Module::too_large( 0 ) );
	}

	/** The intervals of the recurring actions (CONTRACTS §9). */
	public function test_recurring(): void {
		$this->assertSame(
			array(
				'qersh_wc_sweep' => 900,
				'qersh_wc_daily' => 86400,
			),
			Module::RECURRING
		);
		$this->assertSame( 'qersh', Module::GROUP );
	}

	/** Action Scheduler passes arguments by position; both shapes of arguments give the order id. */
	public function test_order_id_of(): void {
		$this->assertSame( 42, Module::order_id_of( 42 ) );
		$this->assertSame( 42, Module::order_id_of( '42' ) );
		$this->assertSame( 42, Module::order_id_of( array( 'order_id' => 42 ) ) );
		$this->assertSame( 42, Module::order_id_of( array( 42 ) ) );
		$this->assertSame( 0, Module::order_id_of( -3 ) );
		$this->assertSame( 0, Module::order_id_of( '4x' ) );
		$this->assertSame( 0, Module::order_id_of( null ) );
		$this->assertSame( 0, Module::order_id_of( array() ) );
	}

	/** Retention days from the configuration, with the defaults for anything else. */
	public function test_days(): void {
		$this->assertSame( 30, Module::days( 30, 180 ) );
		$this->assertSame( 30, Module::days( 30.0, 180 ) );
		$this->assertSame( 180, Module::days( 0, 180 ) );
		$this->assertSame( 180, Module::days( '30', 180 ) );
		$this->assertSame( 90, Module::days( null, 90 ) );
		$this->assertSame( 90, Module::days( 1.5, 90 ) );
	}

	/** qw wins; an id outside the pattern becomes the unknown wallet; qo=status|txn; anything else no route. */
	public function test_fallback_target(): void {
		$this->assertSame(
			array(
				'kind'   => 'webhook',
				'wallet' => 'k7q2m9x4p1rz',
			),
			Fallback::target( 'k7q2m9x4p1rz', '' )
		);
		$this->assertSame(
			array(
				'kind'   => 'webhook',
				'wallet' => 'k7q2m9x4p1rz',
			),
			Fallback::target( 'k7q2m9x4p1rz', 'status' )
		);
		$this->assertSame(
			array(
				'kind'   => 'webhook',
				'wallet' => '',
			),
			Fallback::target( 'k7q2m9x4p1', '' )
		);
		$this->assertSame(
			array(
				'kind'   => 'webhook',
				'wallet' => '',
			),
			Fallback::target( 'k7q2m9x4p1rz-', '' )
		);
		$this->assertSame( array( 'kind' => 'status' ), Fallback::target( '', 'status' ) );
		$this->assertSame( array( 'kind' => 'txn' ), Fallback::target( '', 'txn' ) );
		$this->assertSame( array( 'kind' => 'none' ), Fallback::target( '', 'other' ) );
		$this->assertSame( array( 'kind' => 'none' ), Fallback::target( '', '' ) );
	}

	/** Only the X-Qersh-* headers, with lower-case names, values untouched. */
	public function test_fallback_headers(): void {
		$headers = Fallback::qersh_headers(
			array(
				'HTTP_X_QERSH_SIGNATURE'  => 't=1760000000, v1=abc',
				'HTTP_X_QERSH_DELIVERY'   => 'd-1',
				'HTTP_X_QERSH_REDELIVERY' => 'manual',
				'HTTP_X_QERSH_ATTEMPT'    => array( '1' ),
				'HTTP_COOKIE'             => 'secret',
				'REQUEST_METHOD'          => 'POST',
			)
		);
		$this->assertSame(
			array(
				'x-qersh-signature'  => 't=1760000000, v1=abc',
				'x-qersh-delivery'   => 'd-1',
				'x-qersh-redelivery' => 'manual',
			),
			$headers
		);
	}
}
