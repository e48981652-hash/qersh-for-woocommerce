<?php
/**
 * The two storefront routes (docs/17 §4.1, §4.10): POST qersh/v1/order-status and POST qersh/v1/order-txn. POST
 * only (GET is WordPress's 404 rest_no_route), one 404 body for every refusal, never cached.
 *
 * @package QershPay\WooCommerce\Tests
 */

namespace QershPay\WooCommerce\Tests\Integration;

defined( 'ABSPATH' ) || exit;

/**
 * Status and transaction-id routes.
 */
class StorefrontRoutesTest extends TestCase {

	/**
	 * GET on either route: 404 rest_no_route.
	 */
	public function test_routes_are_post_only() {
		foreach ( array( '/qersh/v1/order-status', '/qersh/v1/order-txn' ) as $route ) {
			$response = $this->server->dispatch( new \WP_REST_Request( 'GET', $route ) );
			$this->assertSame( 404, $response->get_status(), $route );
			$this->assertSame( 'rest_no_route', $response->get_data()['code'], $route );
		}
	}

	/**
	 * The status route: a wrong key, an unknown order and an order of another method share one 404 body; the right
	 * key answers the state with no-store, and LiteSpeed Cache is told not to keep it.
	 */
	public function test_status_route_answers_and_refuses_the_same_way() {
		$wallet = $this->vodafone_wallet();
		$order  = $this->order( $this->product( '100' ) );
		$this->assertSame( 'success', $this->pay( $order, '01122334455', $wallet['id'] )['result'] );

		$other = $this->order( $this->product( '100' ) );
		$other->set_payment_method( 'cod' );
		$other->save();

		$refusals = array(
			array(
				'order_id' => $order->get_id(),
				'key'      => 'wc_order_wrongkey0000',
			),
			array(
				'order_id' => 999999999,
				'key'      => $order->get_order_key(),
			),
			array(
				'order_id' => $other->get_id(),
				'key'      => $other->get_order_key(),
			),
			array(),
		);
		$bodies   = array();
		foreach ( $refusals as $body ) {
			$response = $this->post_json( '/qersh/v1/order-status', $body );
			$this->assertSame( 404, $response->get_status() );
			$this->assertSame( 'qersh_not_found', $response->get_data()['code'] );
			$bodies[] = wp_json_encode( $response->get_data() );
		}
		$this->assertCount( 1, array_unique( $bodies ), 'one refusal body' );

		$before   = did_action( 'litespeed_control_set_nocache' );
		$response = $this->post_json(
			'/qersh/v1/order-status',
			array(
				'order_id' => $order->get_id(),
				'key'      => $order->get_order_key(),
			)
		);
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'waiting', $response->get_data()['status'] );
		$this->assertArrayNotHasKey( 'claim', $response->get_data(), 'claim is for Orange Cash orders only' );
		$this->assertStringContainsString( 'no-store', self::header( $response, 'Cache-Control' ) );
		$this->assertGreaterThan( $before, did_action( 'litespeed_control_set_nocache' ) );
	}

	/**
	 * The status follows the row: paid after the matched transfer.
	 */
	public function test_status_route_says_paid_after_the_match() {
		$wallet = $this->vodafone_wallet();
		$order  = $this->order( $this->product( '100' ) );
		$this->assertSame( 'success', $this->pay( $order, '01122334456', $wallet['id'] )['result'] );
		$this->deliver(
			'transfer.received',
			$wallet,
			array(
				'payer'        => '01122334456',
				'amount_minor' => 10000,
			)
		);
		$response = $this->post_json(
			'/qersh/v1/order-status',
			array(
				'order_id' => (string) $order->get_id(),
				'key'      => $order->get_order_key(),
			)
		);
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'paid', $response->get_data()['status'] );
	}

	/**
	 * The transaction-id route: refusals share the status route's 404 body; a body above 2 KB is 400; a wrong
	 * shape is 400 with its code and writes nothing; a good id is 200 saved with no-store and claim true after.
	 */
	public function test_txn_route_refuses_checks_size_and_saves() {
		$wallet = $this->orange_wallet();
		$order  = $this->order( $this->product( '140' ), self::ORANGE_PAYER );
		$this->assertSame( 'success', $this->pay( $order, self::ORANGE_PAYER, $wallet['id'] )['result'] );

		$wrong = $this->post_json(
			'/qersh/v1/order-txn',
			array(
				'order_id' => $order->get_id(),
				'key'      => 'wc_order_wrongkey0000',
				'txn'      => '25007294067405',
			)
		);
		$this->assertSame( 404, $wrong->get_status() );
		$status_refusal = $this->post_json( '/qersh/v1/order-status', array( 'order_id' => $order->get_id() ) );
		$this->assertSame( wp_json_encode( $status_refusal->get_data() ), wp_json_encode( $wrong->get_data() ), 'the same 404 body on both routes' );

		$big = $this->post_json(
			'/qersh/v1/order-txn',
			array(
				'order_id' => $order->get_id(),
				'key'      => $order->get_order_key(),
				'txn'      => str_repeat( '1', 2100 ),
			)
		);
		$this->assertSame( 400, $big->get_status() );
		$this->assertSame( 'qersh_txn_too_long', $big->get_data()['code'] );

		$shape = $this->post_json(
			'/qersh/v1/order-txn',
			array(
				'order_id' => $order->get_id(),
				'key'      => $order->get_order_key(),
				'txn'      => '2500729406114',
			)
		);
		$this->assertSame( 400, $shape->get_status() );
		$this->assertSame( 'qersh_txn_format', $shape->get_data()['code'] );
		$this->assertSame( 0, (int) $this->row( $order->get_id() )['claim_tries'], 'a wrong shape costs no try' );

		$saved = $this->post_json(
			'/qersh/v1/order-txn',
			array(
				'order_id' => $order->get_id(),
				'key'      => $order->get_order_key(),
				'txn'      => '25007294067405',
			)
		);
		$this->assertSame( 200, $saved->get_status() );
		$this->assertSame( 'saved', $saved->get_data()['status'] );
		$this->assertSame( '25007294067405', $saved->get_data()['txn'] );
		$this->assertStringContainsString( 'no-store', self::header( $saved, 'Cache-Control' ) );

		$status = $this->post_json(
			'/qersh/v1/order-status',
			array(
				'order_id' => $order->get_id(),
				'key'      => $order->get_order_key(),
			)
		);
		$this->assertSame( 'waiting', $status->get_data()['status'] );
		$this->assertTrue( $status->get_data()['claim'] );
	}
}
