<?php
/**
 * Completion (docs/17 §4.7): a matched transfer completes its order once, whatever calls the claimed path, so the
 * stock goes down once and WooCommerce's payment note is written once.
 *
 * @package QershPay\WooCommerce\Tests
 */

namespace QershPay\WooCommerce\Tests\Integration;

defined( 'ABSPATH' ) || exit;

/**
 * One completion per paid decision.
 */
class CompletionTest extends TestCase {

	/**
	 * Completion::run() twice (the after-reply path and the action, or the sweep): one payment_complete(), one
	 * stock reduction.
	 */
	public function test_a_matched_transfer_completes_the_order_once() {
		$wallet  = $this->vodafone_wallet();
		$product = $this->product( '140', 10 );
		$order   = $this->order( $product );
		$this->assertSame( 'success', $this->pay( $order, '01122334466', $wallet['id'] )['result'] );

		$response = $this->deliver(
			'transfer.received',
			$wallet,
			array(
				'payer'        => '01122334466',
				'amount_minor' => 14000,
			)
		)['response'];
		$this->assertSame( 'matched', $response->get_data()['status'] );
		$this->assertContains( (string) $this->row( $order->get_id() )['completion'], array( 'due', 'running', 'done' ) );

		$completion = $this->plugin->completion();
		$completion->run( $order->get_id() );
		$completion->run( $order->get_id() );
		$completion->sweep();

		$fresh = $this->fresh( $order->get_id() );
		$this->assertContains( $fresh->get_status(), array( 'processing', 'completed' ) );
		$this->assertTrue( $fresh->is_paid() );
		$this->assertSame( 9, wc_get_product( $product->get_id() )->get_stock_quantity(), 'stock reduced once' );
		$this->assertSame( 'done', (string) $this->row( $order->get_id() )['completion'] );

		$reduced = array_filter(
			$this->notes( $order->get_id() ),
			function ( $note ) {
				return false !== stripos( $note, 'Stock levels reduced' );
			}
		);
		$this->assertCount( 1, $reduced, 'one stock note' );
	}

	/**
	 * A repeat of the same delivery after the completion changes nothing.
	 */
	public function test_a_repeated_delivery_after_completion_changes_nothing() {
		$wallet  = $this->vodafone_wallet();
		$product = $this->product( '100', 5 );
		$order   = $this->order( $product );
		$this->assertSame( 'success', $this->pay( $order, '01122334477', $wallet['id'] )['result'] );

		$sent = $this->deliver(
			'transfer.received',
			$wallet,
			array(
				'payer'        => '01122334477',
				'amount_minor' => 10000,
			)
		);
		$this->plugin->completion()->run( $order->get_id() );
		$this->assertSame( 4, wc_get_product( $product->get_id() )->get_stock_quantity() );

		$again = $this->post_webhook( $wallet['id'], $sent['body'], $this->sign( $sent['body'], $wallet['id'] ) );
		$this->assertSame( 200, $again->get_status() );
		$this->plugin->completion()->run( $order->get_id() );
		$this->assertSame( 4, wc_get_product( $product->get_id() )->get_stock_quantity(), 'still reduced once' );
	}
}
