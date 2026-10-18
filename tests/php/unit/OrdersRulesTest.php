<?php
/**
 * Tests of the pure rules of Orders and Expiry around the merchant's decisions (docs/17 §4.6, §4.7): what attaching
 * or dismissing a transfer means, the stored risk reasons, the overdue reasons a status clears, the switch of the
 * admin's "Cancelled order" email, what the order hooks leave alone, the forgery stop, the notes of a cancellation,
 * a deleted order post, and the transfers that hold an order at its deadline for a typed transaction id.
 *
 * @package QershPay\WooCommerce\Tests
 */

namespace QershPay\WooCommerce\Tests\Unit;

use QershPay\WooCommerce\Claims;
use QershPay\WooCommerce\Core\Risk;
use QershPay\WooCommerce\Expiry;
use QershPay\WooCommerce\Orders;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

defined( 'ABSPATH' ) || exit;

/**
 * Orders::attach_kind(), dismiss_kind(), waits_with_order(), released_fields(), stored_reasons(), merchant_decided(),
 * hook_keeps(), counts_toward_order(), suspected(), cancel_amounts(), deletes_order(), Expiry::mutes_admin_cancel_email(),
 * Expiry::holds_for_claim() and Claims::personal().
 */
final class OrdersRulesTest extends TestCase {

	/**
	 * A typed qersh_transfers row.
	 *
	 * @param array $over Changes.
	 * @return array
	 */
	private function transfer( array $over = array() ): array {
		return array_merge(
			array(
				'transfer_id'  => 'trf_1',
				'amount_minor' => 14000,
				'outcome'      => 'review',
				'reason'       => 'no_payer',
				'order_id'     => null,
				'flag'         => null,
			),
			$over
		);
	}

	/** Attaching: a free transfer is new; a waiting linked one is its order's own or moves; one that paid is refused. */
	public function test_attach_kind(): void {
		$this->assertSame( 'new', Orders::attach_kind( $this->transfer(), 10 ) );
		$this->assertSame(
			'new',
			Orders::attach_kind(
				$this->transfer(
					array(
						'outcome' => 'unmatched',
						'reason'  => 'personal',
					)
				),
				10
			)
		);
		$this->assertSame(
			'new',
			Orders::attach_kind(
				$this->transfer(
					array(
						'outcome'  => 'dismissed',
						'order_id' => 7,
					)
				),
				10
			),
			'a dismissed transfer counts toward no order'
		);
		$this->assertSame(
			'new',
			Orders::attach_kind(
				$this->transfer(
					array(
						'outcome'  => 'dismissed',
						'order_id' => 10,
					)
				),
				10
			)
		);
		$this->assertSame( 'linked', Orders::attach_kind( $this->transfer( array( 'outcome' => 'matched' ) ), 10 ), 'a verdict that paid, even without the link' );

		foreach ( array( 'stock_short', 'closed_order', 'forgery', 'cancelled_paid' ) as $reason ) {
			$left = $this->transfer(
				array(
					'reason'   => $reason,
					'order_id' => 10,
				)
			);
			$this->assertSame( 'own', Orders::attach_kind( $left, 10 ), $reason . ' on its own order' );
			$this->assertSame( 'move', Orders::attach_kind( $left, 11 ), $reason . ' to another order' );
		}

		$overdue = $this->transfer(
			array(
				'outcome'  => 'partial',
				'reason'   => 'partial_overdue',
				'order_id' => 10,
			)
		);
		$this->assertSame( 'own', Orders::attach_kind( $overdue, 10 ), 'attach and complete with what arrived' );
		$this->assertSame( 'own', Orders::attach_kind( array_merge( $overdue, array( 'outcome' => 'attached' ) ), 10 ) );
		$this->assertSame( 'linked', Orders::attach_kind( $overdue, 11 ), 'part of an order at its deadline never moves' );

		$over = $this->transfer(
			array(
				'outcome'  => 'overpaid',
				'reason'   => null,
				'order_id' => 10,
			)
		);
		$this->assertSame( 'confirm', Orders::attach_kind( $over, 10 ) );
		$this->assertSame( 'linked', Orders::attach_kind( $over, 11 ) );
		$this->assertSame( 'linked', Orders::attach_kind( array_merge( $over, array( 'flag' => 'forgery' ) ), 10 ), 'a flagged one is never confirmed' );

		foreach ( array( 'matched', 'partial', 'attached' ) as $outcome ) {
			$paid = $this->transfer(
				array(
					'outcome'  => $outcome,
					'reason'   => null,
					'order_id' => 10,
				)
			);
			$this->assertSame( 'linked', Orders::attach_kind( $paid, 10 ), $outcome );
			$this->assertSame( 'linked', Orders::attach_kind( $paid, 11 ), $outcome );
		}
	}

	/** Dismissing: a free transfer goes; a waiting linked one is released; what paid its order is refused with a way. */
	public function test_dismiss_kind(): void {
		$this->assertSame( 'free', Orders::dismiss_kind( $this->transfer() ) );
		$this->assertSame(
			'free',
			Orders::dismiss_kind(
				$this->transfer(
					array(
						'outcome' => 'unmatched',
						'reason'  => 'personal',
					)
				)
			)
		);
		$this->assertSame(
			'release',
			Orders::dismiss_kind(
				$this->transfer(
					array(
						'reason'   => 'cancelled_paid',
						'order_id' => 10,
					)
				)
			)
		);
		$this->assertSame(
			'release',
			Orders::dismiss_kind(
				$this->transfer(
					array(
						'reason'   => 'stock_short',
						'order_id' => 10,
					)
				)
			)
		);
		$this->assertSame(
			'partial',
			Orders::dismiss_kind(
				$this->transfer(
					array(
						'outcome'  => 'partial',
						'reason'   => 'partial_overdue',
						'order_id' => 10,
					)
				)
			)
		);
		$this->assertSame(
			'overpaid',
			Orders::dismiss_kind(
				$this->transfer(
					array(
						'outcome'  => 'overpaid',
						'reason'   => null,
						'order_id' => 10,
					)
				)
			)
		);
		$this->assertSame(
			'linked',
			Orders::dismiss_kind(
				$this->transfer(
					array(
						'outcome'  => 'matched',
						'reason'   => null,
						'order_id' => 10,
					)
				)
			)
		);
	}

	/** The transfers of an order that wait with it, settled by one attach. */
	public function test_waits_with_order(): void {
		$this->assertTrue(
			Orders::waits_with_order(
				$this->transfer(
					array(
						'reason'   => 'stock_short',
						'order_id' => 10,
					)
				)
			)
		);
		$this->assertTrue(
			Orders::waits_with_order(
				$this->transfer(
					array(
						'outcome'  => 'partial',
						'reason'   => 'partial_overdue',
						'order_id' => 10,
					)
				)
			)
		);
		$this->assertFalse(
			Orders::waits_with_order(
				$this->transfer(
					array(
						'outcome'  => 'partial',
						'reason'   => null,
						'order_id' => 10,
					)
				)
			)
		);
		$this->assertFalse(
			Orders::waits_with_order(
				$this->transfer(
					array(
						'outcome'  => 'dismissed',
						'reason'   => 'partial_overdue',
						'order_id' => 10,
					)
				)
			)
		);
	}

	/** An amount that stops counting: paid_minor goes down, never below zero; a partial row without money opens. */
	public function test_released_fields(): void {
		$row = array(
			'paid_minor' => 14000,
			'state'      => 'closed',
		);
		$this->assertSame( array( 'paid_minor' => 4000 ), Orders::released_fields( $row, 10000 ) );
		$this->assertSame( array( 'paid_minor' => 0 ), Orders::released_fields( $row, 20000 ) );
		$this->assertSame(
			array(
				'paid_minor' => 0,
				'state'      => 'open',
			),
			Orders::released_fields( array_merge( $row, array( 'state' => 'partial' ) ), 14000 )
		);
		$this->assertSame( array( 'paid_minor' => 4000 ), Orders::released_fields( array_merge( $row, array( 'state' => 'partial' ) ), 10000 ) );
		$this->assertSame( array( 'paid_minor' => 0 ), Orders::released_fields( array_merge( $row, array( 'state' => 'review' ) ), 14000 ), 'review stays review' );
	}

	/** The stored risk reasons read the same verdict as the arrival: null has none, a non-list is one unknown value. */
	public function test_stored_reasons(): void {
		$this->assertSame( array(), Orders::stored_reasons( null ) );
		$this->assertSame( array(), Risk::unknown_reasons( Orders::stored_reasons( null ) ), 'null is no unknown reason later either' );
		$this->assertSame( array( 'no_baseline' ), Orders::stored_reasons( array( 'x' => 'no_baseline' ) ) );
		$this->assertSame( array( '"weird"' ), Orders::stored_reasons( 'weird' ) );
		$this->assertSame( Risk::unknown_reasons( 'weird' ), Risk::unknown_reasons( Orders::stored_reasons( 'weird' ) ) );
		$this->assertSame( array( '7' ), Orders::stored_reasons( 7 ) );
	}

	/** Only processing or completed by the merchant's hand decides an order that waited with money; on hold does not. */
	public function test_merchant_decided(): void {
		$this->assertTrue( Orders::merchant_decided( 'processing' ) );
		$this->assertTrue( Orders::merchant_decided( 'completed' ) );
		$this->assertFalse( Orders::merchant_decided( 'on-hold' ) );
		$this->assertFalse( Orders::merchant_decided( 'failed' ) );
		$this->assertFalse( Orders::merchant_decided( '' ) );
	}

	/** store.admin_cancel_email_on_expiry, on by default, turns the admin's email of our expiry off (docs/17 §4.2). */
	public function test_admin_cancel_email_switch(): void {
		$this->assertTrue( Expiry::mutes_admin_cancel_email( array() ), 'the default' );
		$this->assertTrue( Expiry::mutes_admin_cancel_email( array( 'store' => array( 'admin_cancel_email_on_expiry' => true ) ) ) );
		$this->assertFalse( Expiry::mutes_admin_cancel_email( array( 'store' => array( 'admin_cancel_email_on_expiry' => false ) ) ) );
	}

	/**
	 * A typed qersh_orders row.
	 *
	 * @param string $state      State.
	 * @param string $completion Completion.
	 * @return array
	 */
	private function order_row( string $state, string $completion = 'none' ): array {
		return array(
			'order_id'    => 10,
			'state'       => $state,
			'completion'  => $completion,
			'paid_minor'  => 0,
			'price_minor' => 14000,
		);
	}

	/**
	 * The hooks leave a closed row, a paid decision (any paid row for a status change, one whose completion has not
	 * run for the trash and a change of method) and our expiry's own cancellation; everything else is closed.
	 */
	public function test_hook_keeps(): void {
		foreach ( array( 'status', 'trash', 'method' ) as $hook ) {
			$this->assertTrue( Orders::hook_keeps( $this->order_row( 'closed' ), $hook, 'cancelled' ), $hook . ': closed already' );
			$this->assertTrue( Orders::hook_keeps( $this->order_row( 'paid', 'due' ), $hook, 'cancelled' ), $hook . ': paid, due' );
			$this->assertTrue( Orders::hook_keeps( $this->order_row( 'paid', 'running' ), $hook, 'cancelled' ), $hook . ': paid, running' );
			foreach ( array( 'open', 'partial', 'review' ) as $state ) {
				$this->assertFalse( Orders::hook_keeps( $this->order_row( $state ), $hook, 'cancelled' ), $hook . ': ' . $state );
			}
		}
		$this->assertTrue( Orders::hook_keeps( $this->order_row( 'paid', 'done' ), 'status', 'processing' ), 'every paid row is Completion\'s on a status change' );
		$this->assertFalse( Orders::hook_keeps( $this->order_row( 'paid', 'done' ), 'trash' ) );
		$this->assertFalse( Orders::hook_keeps( $this->order_row( 'paid', 'done' ), 'method' ) );
		$this->assertTrue( Orders::hook_keeps( $this->order_row( 'expired' ), 'status', 'cancelled' ), 'our expiry' );
		$this->assertFalse( Orders::hook_keeps( $this->order_row( 'expired' ), 'status', 'processing' ) );
		$this->assertFalse( Orders::hook_keeps( $this->order_row( 'expired' ), 'trash' ) );
	}

	/**
	 * A forgery verdict stops the order of a transfer whose amount still counts toward it: paying, or left in review
	 * by Completion or a cancellation (one case per reason); never a released or an unlinked one (docs/17 §4.4 step 9).
	 */
	public function test_counts_toward_order(): void {
		foreach ( array( 'matched', 'partial', 'overpaid', 'attached' ) as $outcome ) {
			$this->assertTrue(
				Orders::counts_toward_order(
					$this->transfer(
						array(
							'outcome'  => $outcome,
							'order_id' => 10,
						)
					)
				),
				$outcome
			);
		}
		foreach ( array( 'order_on_hold', 'completion_failed', 'stock_short', 'closed_order', 'cancelled_paid', 'forgery' ) as $reason ) {
			$this->assertTrue(
				Orders::counts_toward_order(
					$this->transfer(
						array(
							'outcome'  => 'review',
							'reason'   => $reason,
							'order_id' => 10,
						)
					)
				),
				$reason
			);
		}
		$this->assertFalse(
			Orders::counts_toward_order(
				$this->transfer(
					array(
						'outcome'  => 'dismissed',
						'order_id' => 10,
					)
				)
			),
			'a release took the amount out'
		);
		$this->assertFalse(
			Orders::counts_toward_order(
				$this->transfer(
					array(
						'outcome'  => 'unmatched',
						'order_id' => 10,
					)
				)
			)
		);
		$this->assertFalse(
			Orders::counts_toward_order(
				$this->transfer(
					array(
						'outcome'  => null,
						'order_id' => 10,
					)
				)
			)
		);
		$this->assertFalse( Orders::counts_toward_order( $this->transfer() ), 'a review decided at arrival has no order' );
		$this->assertFalse( Orders::counts_toward_order( $this->transfer( array( 'outcome' => 'matched' ) ) ) );
	}

	/** Only a forgery flag the merchant has not decided is suspected. */
	public function test_suspected(): void {
		$this->assertTrue( Orders::suspected( $this->transfer( array( 'flag' => 'forgery' ) ) ) );
		$this->assertFalse( Orders::suspected( $this->transfer( array( 'flag' => 'forgery_decided' ) ) ) );
		$this->assertFalse( Orders::suspected( $this->transfer() ) );
		$this->assertFalse( Orders::suspected( array( 'outcome' => 'matched' ) ) );
	}

	/** A cancellation tells the merchant to refund only what no forged transfer brought, and to check the rest. */
	public function test_cancel_amounts(): void {
		$this->assertSame(
			array(
				'refund'  => 14000,
				'suspect' => 0,
			),
			Orders::cancel_amounts( 14000, 0 )
		);
		$this->assertSame(
			array(
				'refund'  => 0,
				'suspect' => 5000,
			),
			Orders::cancel_amounts( 5000, 5000 ),
			'all of it suspected: no refund note'
		);
		$this->assertSame(
			array(
				'refund'  => 9000,
				'suspect' => 5000,
			),
			Orders::cancel_amounts( 14000, 5000 )
		);
		$this->assertSame(
			array(
				'refund'  => 0,
				'suspect' => 5000,
			),
			Orders::cancel_amounts( 5000, 8000 ),
			'never more suspected than received'
		);
		$this->assertSame(
			array(
				'refund'  => 0,
				'suspect' => 0,
			),
			Orders::cancel_amounts( -3, -2 )
		);
	}

	/** Only a shop_order deleted while the posts storage holds the orders deletes the row; with HPOS, never. */
	public function test_deletes_order(): void {
		$this->assertTrue( Orders::deletes_order( 'shop_order', false ) );
		$this->assertFalse( Orders::deletes_order( 'shop_order', true ), 'HPOS fires woocommerce_delete_order itself' );
		$this->assertFalse( Orders::deletes_order( 'shop_order_placehold', false ) );
		$this->assertFalse( Orders::deletes_order( 'shop_order_placehold', true ) );
		$this->assertFalse( Orders::deletes_order( 'shop_order_refund', false ) );
		$this->assertFalse( Orders::deletes_order( 'post', false ) );
	}

	/**
	 * At the deadline, a typed transaction id holds the order for a transfer that waits for the merchant, in "Needs a
	 * decision" or under "Personal transfers", so the result never depends on which arrived first (docs/17 §4.7).
	 */
	public function test_holds_for_claim(): void {
		foreach ( array( 'no_payer', 'txn_amount', 'txn_window', 'txn_wallet', 'txn_contested', 'unknown_risk' ) as $reason ) {
			$this->assertTrue( Expiry::holds_for_claim( $this->transfer( array( 'reason' => $reason ) ) ), $reason );
		}
		$personal = $this->transfer(
			array(
				'outcome' => 'unmatched',
				'reason'  => 'personal',
			)
		);
		$this->assertTrue( Expiry::holds_for_claim( $personal ), 'personal' );
		$this->assertFalse( Expiry::holds_for_claim( array_merge( $personal, array( 'reason' => null ) ) ), 'unmatched, not personal' );
		$this->assertFalse( Expiry::holds_for_claim( array_merge( $personal, array( 'flag' => 'forgery' ) ) ), 'flagged' );
		$this->assertFalse( Expiry::holds_for_claim( array_merge( $personal, array( 'order_id' => 7 ) ) ), 'linked' );
		$this->assertFalse( Expiry::holds_for_claim( $this->transfer( array( 'order_id' => 7 ) ) ), 'linked review' );
		$this->assertFalse( Expiry::holds_for_claim( $this->transfer( array( 'flag' => 'forgery' ) ) ), 'flagged review' );
		$this->assertFalse( Expiry::holds_for_claim( $this->transfer( array( 'outcome' => 'dismissed' ) ) ) );
		$this->assertFalse( Expiry::holds_for_claim( $this->transfer( array( 'outcome' => 'matched' ) ) ) );
		$this->assertTrue( Expiry::holds_for_claim( $this->transfer( array( 'flag' => '' ) ) ), 'an empty flag is none' );
	}

	/** The late path names "Personal transfers" for an unlinked, unflagged personal row. */
	public function test_claims_personal(): void {
		$personal = $this->transfer(
			array(
				'outcome' => 'unmatched',
				'reason'  => 'personal',
			)
		);
		$this->assertTrue( Claims::personal( $personal ) );
		$this->assertFalse( Claims::personal( array_merge( $personal, array( 'order_id' => 7 ) ) ) );
		$this->assertFalse( Claims::personal( array_merge( $personal, array( 'flag' => 'forgery' ) ) ) );
		$this->assertFalse( Claims::personal( array_merge( $personal, array( 'reason' => 'no_payer' ) ) ) );
		$this->assertFalse( Claims::personal( $this->transfer() ) );
	}
}
