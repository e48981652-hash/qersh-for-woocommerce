<?php
/**
 * Expiry (docs/17 §4.7): an open order past its deadline and the late window is cancelled by our expiry; a partly
 * paid one is never cancelled and stays pending for the merchant. The customer's note of a cancelled order is one
 * text for every wallet, chosen only by store.txn_entry and a typed transaction id.
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
	public function test_expiry_cancels_an_open_order(): void {
		$wallet = $this->wallet();
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
	public function test_expiry_before_the_deadline_does_nothing(): void {
		$wallet = $this->wallet();
		$order  = $this->order( $this->product( '100' ) );
		$this->assertSame( 'success', $this->pay( $order, '01122334499', $wallet['id'] )['result'] );

		$this->plugin->expiry()->run( $order->get_id() );

		$this->assertSame( 'pending', $this->fresh( $order->get_id() )->get_status() );
		$this->assertSame( 'open', (string) $this->row( $order->get_id() )['state'] );
	}

	/**
	 * Partly paid and overdue: never cancelled, the order stays pending.
	 */
	public function test_expiry_leaves_a_partly_paid_order_pending(): void {
		$wallet = $this->wallet();
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

	/**
	 * The settings of the customer's note: store.txn_entry, and whether the customer typed an id.
	 *
	 * @return array[]
	 */
	public static function note_cases() {
		return array(
			'the order page still takes an id' => array( true, false ),
			'an id typed before the deadline'  => array( true, true ),
			'the store takes no id'            => array( false, false ),
		);
	}

	/**
	 * The customer's note of an order our expiry cancelled: the link to the order page to type the id within 72
	 * hours while the store takes ids, the id the store kept when one was typed, else the one plain sentence.
	 *
	 * @dataProvider note_cases
	 *
	 * @param bool $txn_entry store.txn_entry.
	 * @param bool $typed     Whether the customer typed an id before the deadline.
	 */
	public function test_the_customer_note_of_a_cancelled_order( $txn_entry, $typed ): void {
		$entries = $this->configure( array( array( 'label' => 'Main wallet' ) ), array( 'txn_entry' => $txn_entry ) );
		$order   = $this->order( $this->product( '100' ) );
		$this->assertSame( 'success', $this->pay( $order, '01122334412', $entries[0]['id'] )['result'] );
		$txn = self::txn_id();
		if ( $typed ) {
			$this->assertSame( 200, $this->plugin->claims()->submit( $this->fresh( $order->get_id() ), $txn, time() )['http'] );
		}

		$this->expire_now( $order->get_id() );
		$this->plugin->expiry()->run( $order->get_id() );
		$this->assertSame( 'cancelled', $this->fresh( $order->get_id() )->get_status() );

		$notes = array();
		foreach ( wc_get_order_notes(
			array(
				'order_id' => $order->get_id(),
				'type'     => 'customer',
			)
		) as $note ) {
			$notes[] = (string) $note->content;
		}
		$this->assertCount( 1, $notes, 'one note to the customer' );
		$has_link = false !== strpos( $notes[0], '#qersh-txn' );
		$has_txn  = false !== strpos( $notes[0], $txn );
		$this->assertSame( $txn_entry && ! $typed, $has_link, 'the link to type the id' );
		$this->assertSame( $typed, $has_txn, 'the id the store kept' );
		if ( ! $txn_entry ) {
			$this->assertStringNotContainsString( 'transaction id', $notes[0] );
		}
	}
}
