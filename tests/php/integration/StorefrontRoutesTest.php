<?php
/**
 * The two storefront routes (docs/17 §4.1, §4.10): POST qersh/v1/order-status and POST qersh/v1/order-txn. POST
 * only (GET is WordPress's 404 rest_no_route), one 404 body for every refusal, never cached. The order page takes a
 * transaction id on every wallet while store.txn_entry is on, so the status answer carries `claim` for every order
 * then, and never when it is off.
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
	public function test_routes_are_post_only(): void {
		foreach ( array( '/qersh/v1/order-status', '/qersh/v1/order-txn' ) as $route ) {
			$response = $this->server->dispatch( new \WP_REST_Request( 'GET', $route ) );
			$this->assertSame( 404, $response->get_status(), $route );
			$this->assertSame( 'rest_no_route', $response->get_data()['code'], $route );
		}
	}

	/**
	 * The status route: a wrong key, an unknown order and an order of another method share one 404 body; the right
	 * key answers the state with no-store, and LiteSpeed Cache is told not to keep it. `claim` is there, false: the
	 * order page takes an id, and none was typed yet.
	 */
	public function test_status_route_answers_and_refuses_the_same_way(): void {
		$wallet = $this->wallet();
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
		$this->assertArrayHasKey( 'claim', $response->get_data(), 'every order of a wallet in the configuration takes an id' );
		$this->assertFalse( $response->get_data()['claim'] );
		$this->assertStringContainsString( 'no-store', self::header( $response, 'Cache-Control' ) );
		$this->assertGreaterThan( $before, did_action( 'litespeed_control_set_nocache' ) );
	}

	/**
	 * With store.txn_entry off the status answer has no `claim`, and the transaction-id route answers its 404.
	 */
	public function test_status_route_leaves_claim_out_when_the_store_takes_no_id(): void {
		$entries = $this->configure( array( array( 'label' => 'Main wallet' ) ), array( 'txn_entry' => false ) );
		$order   = $this->order( $this->product( '100' ) );
		$this->assertSame( 'success', $this->pay( $order, '01122334457', $entries[0]['id'] )['result'] );

		$response = $this->post_json(
			'/qersh/v1/order-status',
			array(
				'order_id' => $order->get_id(),
				'key'      => $order->get_order_key(),
			)
		);
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'waiting', $response->get_data()['status'] );
		$this->assertArrayNotHasKey( 'claim', $response->get_data() );

		$txn = $this->post_json(
			'/qersh/v1/order-txn',
			array(
				'order_id' => $order->get_id(),
				'key'      => $order->get_order_key(),
				'txn'      => '913372004518',
			)
		);
		$this->assertSame( 404, $txn->get_status() );
		$this->assertSame( 'qersh_not_found', $txn->get_data()['code'] );
	}

	/**
	 * The status follows the row: paid after the matched transfer.
	 */
	public function test_status_route_says_paid_after_the_match(): void {
		$wallet = $this->wallet();
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
	 * The transaction-id route, on any wallet: refusals share the status route's 404 body; a body above 2 KB is 400;
	 * a text that holds no id (too short) or only a wallet number is 400 with its code and writes nothing; a good id
	 * is 200 saved, in its canonical form, with no-store and claim true after.
	 */
	public function test_txn_route_refuses_checks_size_and_saves(): void {
		$wallet = $this->wallet();
		$order  = $this->order( $this->product( '140' ), self::VECTOR_PAYER );
		$this->assertSame( 'success', $this->pay( $order, self::VECTOR_PAYER, $wallet['id'] )['result'] );

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

		$refusals = array(
			'91337'            => 'qersh_txn_format', // Shorter than any id.
			self::STORE_NUMBER => 'qersh_txn_wallet_number',
			'+20 100 123 4567' => 'qersh_txn_wallet_number', // The order's payer.
		);
		foreach ( $refusals as $typed => $code ) {
			$refused = $this->post_json(
				'/qersh/v1/order-txn',
				array(
					'order_id' => $order->get_id(),
					'key'      => $order->get_order_key(),
					'txn'      => (string) $typed,
				)
			);
			$this->assertSame( 400, $refused->get_status(), (string) $typed );
			$this->assertSame( $code, $refused->get_data()['code'], (string) $typed );
		}
		$this->assertSame( 0, (int) $this->row( $order->get_id() )['claim_tries'], 'a refused text costs no try' );
		$this->assertSame( '', (string) $this->row( $order->get_id() )['claim_txn'] );

		$saved = $this->post_json(
			'/qersh/v1/order-txn',
			array(
				'order_id' => $order->get_id(),
				'key'      => $order->get_order_key(),
				'txn'      => 'ab12-cd34-ef56',
			)
		);
		$this->assertSame( 200, $saved->get_status() );
		$this->assertSame( 'saved', $saved->get_data()['status'] );
		$this->assertSame( 'AB12CD34EF56', $saved->get_data()['txn'], 'separators removed, upper case' );
		$this->assertSame( 'AB12CD34EF56', (string) $this->row( $order->get_id() )['claim_txn'] );
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
