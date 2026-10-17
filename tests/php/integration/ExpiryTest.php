<?php
/**
 * Expiry (docs/17 §4.7): an open order past its deadline and the late window is cancelled by our expiry; a partly
 * paid one is never cancelled and stays pending for the merchant.
 *
 * @package QershPay\WooCommerce\Tests
 */

namespace QershPay\WooCommerce\Tests\Integration;

defined( 'ABSPATH' ) || exit;

/**
 * The deadline.
 */
class ExpiryTest extends TestCase {

	/**
	 * Open and overdue: cancelled, row expired, _qersh_expired_at set.
	 */
	public function test_expiry_cancels_an_open_order() {
		$wallet = $this->vodafone_wallet();
		$order  = $this->order( $this->product( '100' ) );
		$this->assertSame( 'success', $this->pay( $order, '01122334488', $wallet['id'] )['result'] );

		$this->expire_now( $order->get_id() );
		$this->plugin->expiry()->run( $order->get_id() );

		$fresh = $this->fresh( $order->get_id() );
		$this->assertSame( 'cancelled', $fresh->get_status() );
		$this->assertSame( 'expired', (string) $this->row( $order->get_id() )['state'] );
		$this->assertGreaterThan( 0, (int) $fresh->get_meta( '_qersh_expired_at' ) );
	}

	/**
	 * Not overdue yet (the deadline plus the late window is in the future): nothing happens.
	 */
	public function test_expiry_before_the_deadline_does_nothing() {
		$wallet = $this->vodafone_wallet();
		$order  = $this->order( $this->product( '100' ) );
		$this->assertSame( 'success', $this->pay( $order, '01122334499', $wallet['id'] )['result'] );

		$this->plugin->expiry()->run( $order->get_id() );

		$this->assertSame( 'pending', $this->fresh( $order->get_id() )->get_status() );
		$this->assertSame( 'open', (string) $this->row( $order->get_id() )['state'] );
	}

	/**
	 * Partly paid and overdue: never cancelled, the order stays pending.
	 */
	public function test_expiry_leaves_a_partly_paid_order_pending() {
		$wallet = $this->vodafone_wallet();
		$order  = $this->order( $this->product( '140' ) );
		$this->assertSame( 'success', $this->pay( $order, '01122334400', $wallet['id'] )['result'] );

		$response = $this->deliver(
			'transfer.received',
			$wallet,
			array(
				'payer'        => '01122334400',
				'amount_minor' => 10000,
			)
		)['response'];
		$this->assertSame( 'partial', $response->get_data()['status'] );
		$this->assertSame( 'partial', (string) $this->row( $order->get_id() )['state'] );

		$this->expire_now( $order->get_id() );
		$this->plugin->expiry()->run( $order->get_id() );

		$this->assertSame( 'pending', $this->fresh( $order->get_id() )->get_status() );
		$this->assertNotSame( 'expired', (string) $this->row( $order->get_id() )['state'] );
	}
}
