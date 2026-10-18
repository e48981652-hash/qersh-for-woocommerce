<?php
/**
 * process_payment(): the qersh_orders row, the order meta, the deadline, the expiry action and the instructions
 * email (docs/17 §4.3, §4.7); the payer field's normalisation and the store-number refusal (§4.1).
 *
 * @package QershPay\WooCommerce\Tests
 */

namespace QershPay\WooCommerce\Tests\Integration;

defined( 'ABSPATH' ) || exit;

/**
 * Checkout through the method.
 */
class ProcessPaymentTest extends TestCase {

	/**
	 * A successful process_payment() writes the row, the meta, the deadline and the expiry action, and sends one
	 * instructions email; the order stays pending.
	 */
	public function test_process_payment_writes_the_row_meta_and_deadline(): void {
		$wallet = $this->wallet();
		$order  = $this->order( $this->product( '140' ) );
		$before = time();
		$result = $this->pay( $order, '01123456789', $wallet['id'], false );
		$after  = time();

		$this->assertSame( 'success', $result['result'] );
		$this->assertNotEmpty( $result['redirect'] );

		$row = $this->row( $order->get_id() );
		$this->assertNotNull( $row, 'qersh_orders row written' );
		$this->assertSame( $wallet['id'], (string) $row['wallet'] );
		$this->assertSame( '01123456789', (string) $row['payer_key'] );
		$this->assertSame( 14000, (int) $row['price_minor'] );
		$this->assertSame( 0, (int) $row['paid_minor'] );
		$this->assertSame( 'open', (string) $row['state'] );
		$this->assertGreaterThanOrEqual( $before, (int) $row['created_at'] );
		$this->assertLessThanOrEqual( $after, (int) $row['created_at'] );

		$ttl = (int) $this->plugin->config()['store']['order_ttl_min'] * 60;
		$this->assertGreaterThanOrEqual( $before + $ttl, (int) $row['expires_at'] );
		$this->assertLessThanOrEqual( $after + $ttl, (int) $row['expires_at'] );

		$fresh = $this->fresh( $order->get_id() );
		$this->assertSame( 'pending', $fresh->get_status() );
		$this->assertSame( '01123456789', (string) $fresh->get_meta( '_qersh_payer_key' ) );
		$this->assertSame( $wallet['id'], (string) $fresh->get_meta( '_qersh_wallet' ) );
		$this->assertSame( (int) $row['expires_at'], (int) $fresh->get_meta( '_qersh_deadline' ) );
		$this->assertGreaterThanOrEqual( $before, (int) $fresh->get_meta( '_qersh_placed_at' ) );

		$late = (int) $this->plugin->config()['receiver']['matching']['late_window_min'] * 60;
		$next = as_next_scheduled_action( 'qersh_wc_expire', array( 'order_id' => $order->get_id() ), 'qersh' );
		$this->assertSame( (int) $row['expires_at'] + $late, (int) $next, 'one expiry action at the deadline plus the late window' );

		$to_customer = array_filter(
			$this->mails,
			function ( $mail ) {
				return false !== strpos( is_array( $mail['to'] ) ? implode( ',', $mail['to'] ) : (string) $mail['to'], 'customer@example.test' );
			}
		);
		$this->assertCount( 1, $to_customer, 'one instructions email to the customer: ' . implode( ' | ', $this->mail_subjects() ) );
	}

	/**
	 * Arabic-Indic digits and +20 are stored as the normalised 11-digit number.
	 */
	public function test_arabic_digits_and_country_code_are_normalised(): void {
		$wallet = $this->wallet();

		$first = $this->order( $this->product( '100' ) );
		$this->assertSame( 'success', $this->pay( $first, "\u{0660}\u{0661}\u{0661}\u{0662}\u{0663}\u{0664}\u{0665}\u{0666}\u{0667}\u{0668}\u{0669}", $wallet['id'] )['result'] );
		$this->assertSame( '01123456789', (string) $this->row( $first->get_id() )['payer_key'] );

		$second = $this->order( $this->product( '100' ) );
		$this->assertSame( 'success', $this->pay( $second, '+20 112 345 6780', $wallet['id'] )['result'] );
		$this->assertSame( '01123456780', (string) $this->row( $second->get_id() )['payer_key'] );
	}

	/**
	 * The store's own wallet number is refused: no row, no meta, the order stays as it was.
	 */
	public function test_the_store_number_is_refused(): void {
		$wallet = $this->wallet();
		$order  = $this->order( $this->product( '100' ) );

		$this->with_session(
			function () use ( $order, $wallet ) {
				$result = $this->pay( $order, $wallet['receive_address'], $wallet['id'] );
				$this->assertSame( 'failure', $result['result'] );
				$this->assertNotEmpty( wc_get_notices( 'error' ), 'an error notice for the customer' );
			}
		);

		$this->assertNull( $this->row( $order->get_id() ) );
		$fresh = $this->fresh( $order->get_id() );
		$this->assertSame( '', (string) $fresh->get_meta( '_qersh_payer_key' ) );
		$this->assertSame( 'pending', $fresh->get_status() );
	}

	/**
	 * The classic checkout resumes the session's failed order (a declined card) with our method and no status
	 * change: process_payment() moves it back to pending, so the exact transfer matches it.
	 */
	public function test_a_resumed_failed_order_goes_back_to_pending_and_matches(): void {
		$wallet = $this->wallet();
		$order  = $this->order( $this->product( '140' ) );
		$order->set_payment_method( 'cod' );
		$order->save();
		$order->update_status( 'failed', 'Card declined.' );
		$order = $this->fresh( $order->get_id() );
		$order->set_payment_method( 'qersh' ); // What WC_Checkout::create_order() does when it resumes the order.
		$order->save();

		$this->assertSame( 'success', $this->pay( $order, '01122334488', $wallet['id'] )['result'] );
		$this->assertSame( 'pending', $this->fresh( $order->get_id() )->get_status() );
		$this->assertSame( 'open', (string) $this->row( $order->get_id() )['state'] );

		$response = $this->deliver(
			'transfer.received',
			$wallet,
			array(
				'payer'        => '01122334488',
				'amount_minor' => 14000,
			)
		)['response'];
		$this->assertSame( 'matched', $response->get_data()['status'] );
		$this->plugin->completion()->run( $order->get_id() );
		$this->assertContains( $this->fresh( $order->get_id() )->get_status(), array( 'processing', 'completed' ) );
	}

	/**
	 * "Pay for order" of one of our orders that went to failed: its closed row opens again, and the transfer
	 * matches instead of going to review as "arrived after the order was closed".
	 */
	public function test_pay_for_order_of_a_failed_order_reopens_its_row(): void {
		$wallet = $this->wallet();
		$order  = $this->order( $this->product( '140' ) );
		$this->assertSame( 'success', $this->pay( $order, '01122334499', $wallet['id'] )['result'] );
		$this->fresh( $order->get_id() )->update_status( 'failed' );
		$this->assertSame( 'closed', (string) $this->row( $order->get_id() )['state'] );

		set_query_var( 'order-pay', $order->get_id() );
		try {
			$this->assertSame( 'success', $this->pay( $this->fresh( $order->get_id() ), '01122334499', $wallet['id'] )['result'] );
		} finally {
			set_query_var( 'order-pay', '' );
		}
		$this->assertSame( 'pending', $this->fresh( $order->get_id() )->get_status() );
		$this->assertSame( 'open', (string) $this->row( $order->get_id() )['state'] );

		$response = $this->deliver(
			'transfer.received',
			$wallet,
			array(
				'payer'        => '01122334499',
				'amount_minor' => 14000,
			)
		)['response'];
		$this->assertSame( 'matched', $response->get_data()['status'] );
	}

	/**
	 * A refused field leaves a failed order failed (nothing else is touched).
	 */
	public function test_a_refused_field_leaves_a_failed_order_failed(): void {
		$wallet = $this->wallet();
		$order  = $this->order( $this->product( '100' ) );
		$order->update_status( 'failed' );

		$this->with_session(
			function () use ( $order, $wallet ) {
				$this->assertSame( 'failure', $this->pay( $this->fresh( $order->get_id() ), $wallet['receive_address'], $wallet['id'] )['result'] );
			}
		);
		$this->assertSame( 'failed', $this->fresh( $order->get_id() )->get_status() );
		$this->assertNull( $this->row( $order->get_id() ) );
	}

	/**
	 * Runs a callback with an in-memory WooCommerce session (wc_add_notice() needs one; a real session handler would
	 * try to set a cookie after PHPUnit's output).
	 *
	 * @param callable $callback Callback.
	 */
	private function with_session( callable $callback ): void {
		$previous     = WC()->session;
		WC()->session = new class() extends \WC_Session {
		};
		try {
			$callback();
		} finally {
			wc_clear_notices();
			WC()->session = $previous;
		}
	}
}
