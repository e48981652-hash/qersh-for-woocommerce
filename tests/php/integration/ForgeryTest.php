<?php
/**
 * transfer.updated with suspected_forgery (docs/17 §4.4 step 9): an order already processing goes on-hold without
 * an email to the customer; an order whose completion has not run yet stays pending with the flag, and the
 * completion never runs for it.
 *
 * @package QershPay\WooCommerce\Tests
 */

namespace QershPay\WooCommerce\Tests\Integration;

defined( 'ABSPATH' ) || exit;

/**
 * Forgery updates.
 */
class ForgeryTest extends TestCase {

	/**
	 * Pays an order by a matched transfer and returns the transfer id.
	 *
	 * @param array     $wallet Wallet entry.
	 * @param \WC_Order $order  Order.
	 * @param string    $payer  Payer.
	 * @param int       $minor  Amount.
	 * @return string
	 */
	private function match( array $wallet, $order, $payer, $minor ) {
		$this->assertSame( 'success', $this->pay( $order, $payer, $wallet['id'] )['result'] );
		$sent = $this->deliver(
			'transfer.received',
			$wallet,
			array(
				'payer'        => $payer,
				'amount_minor' => $minor,
			)
		);
		$this->assertSame( 'matched', $sent['response']->get_data()['status'] );
		return $sent['envelope']['data']['transfer_id'];
	}

	/**
	 * Processing → on-hold, with the flag and no email to the customer.
	 */
	public function test_forgery_after_completion_puts_the_order_on_hold_without_email(): void {
		$wallet   = $this->vodafone_wallet();
		$product  = $this->product( '100', 10 );
		$order    = $this->order( $product );
		$transfer = $this->match( $wallet, $order, '01122334422', 10000 );
		$this->plugin->completion()->run( $order->get_id() );
		$this->assertContains( $this->fresh( $order->get_id() )->get_status(), array( 'processing', 'completed' ) );

		$this->mails = array();
		$response    = $this->deliver_forgery( $wallet, $transfer );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'ok', $response->get_data()['status'] );

		$fresh = $this->fresh( $order->get_id() );
		$this->assertSame( 'on-hold', $fresh->get_status() );
		$this->assertSame( 'forgery', (string) $fresh->get_meta( '_qersh_flag' ) );
		$this->assertSame( 'forgery', (string) $this->plugin->transfers()->get( $transfer )['flag'] );
		$to_customer = array_filter(
			$this->mails,
			function ( $mail ) {
				return false !== strpos( is_array( $mail['to'] ) ? implode( ',', $mail['to'] ) : (string) $mail['to'], 'customer@example.test' );
			}
		);
		$this->assertCount( 0, $to_customer, 'no email to the customer: ' . implode( ' | ', $this->mail_subjects() ) );
		$this->assertSame( 9, wc_get_product( $product->get_id() )->get_stock_quantity(), 'no second stock change' );
	}

	/**
	 * Before the completion ran: the order stays pending with the flag, and a later completion does nothing.
	 */
	public function test_forgery_before_completion_flags_a_pending_order(): void {
		$wallet   = $this->vodafone_wallet();
		$product  = $this->product( '100', 10 );
		$order    = $this->order( $product );
		$transfer = $this->match( $wallet, $order, '01122334433', 10000 );
		$this->assertSame( 'pending', $this->fresh( $order->get_id() )->get_status(), 'the completion is queued, not run' );

		$response = $this->deliver_forgery( $wallet, $transfer );
		$this->assertSame( 200, $response->get_status() );

		$fresh = $this->fresh( $order->get_id() );
		$this->assertSame( 'pending', $fresh->get_status() );
		$this->assertSame( 'forgery', (string) $fresh->get_meta( '_qersh_flag' ) );
		$this->assertSame( 'review', (string) $this->row( $order->get_id() )['state'] );

		$this->plugin->completion()->run( $order->get_id() );
		$this->plugin->completion()->sweep();
		$this->assertSame( 'pending', $this->fresh( $order->get_id() )->get_status(), 'never completed' );
		$this->assertSame( 10, wc_get_product( $product->get_id() )->get_stock_quantity(), 'no stock change' );
	}
}
