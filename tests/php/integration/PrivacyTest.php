<?php
/**
 * Personal data (docs/17 §4.1 Privacy, §4.3, §9): the exporter lists the transfers that paid no order from the
 * numbers proven to be the customer's (a transfer from that number paid one of their orders), WooCommerce's order
 * export carries the payer number and the typed transaction id, the eraser removes the payer fields of those
 * transfers except one the merchant still has to decide on, and the order eraser removes the meta and the index row.
 * A number that a customer only typed, at checkout or as their billing phone, selects nothing of another payer.
 *
 * @package QershPay\WooCommerce\Tests
 */

namespace QershPay\WooCommerce\Tests\Integration;

use QershPay\WooCommerce\Privacy;

defined( 'ABSPATH' ) || exit;

/**
 * Export and erase.
 */
class PrivacyTest extends TestCase {

	/** The customer's email in these tests. */
	const EMAIL = 'privacy-customer@example.test';

	/** Someone who names another payer's number on their own order. */
	const CLAIMANT = 'privacy-claimant@example.test';

	/**
	 * A personal transfer from the payer (before any order), then an order of the same payer, paid by a transfer
	 * from that number: the number is then proven to be the customer's.
	 *
	 * @param string $payer Payer.
	 * @return array `[ 'order' => \WC_Order, 'transfer_id' => string, 'paid_by' => string, 'wallet' => array ]`
	 */
	private function customer_with_a_loose_transfer( $payer ) {
		$wallet = $this->vodafone_wallet();
		$sent   = $this->deliver(
			'transfer.received',
			$wallet,
			array(
				'payer'        => $payer,
				'amount_minor' => 7700,
			)
		);
		$this->assertSame( 'unmatched', $sent['response']->get_data()['status'] );
		$order = $this->order( $this->product( '100' ), $payer, self::EMAIL );
		$this->assertSame( 'success', $this->pay( $order, $payer, $wallet['id'] )['result'] );
		$paid = $this->deliver(
			'transfer.received',
			$wallet,
			array(
				'payer'        => $payer,
				'amount_minor' => 10000,
			)
		);
		$this->assertSame( 'matched', $paid['response']->get_data()['status'] );
		return array(
			'order'       => $this->fresh( $order->get_id() ),
			'transfer_id' => $sent['envelope']['data']['transfer_id'],
			'paid_by'     => $paid['envelope']['data']['transfer_id'],
			'wallet'      => $wallet,
		);
	}

	/**
	 * Another payer's personal transfer, and an unpaid order of the claimant that names that payer's number only:
	 * typed in the payer field, or as the billing phone.
	 *
	 * @param bool $as_billing_phone True to name the number as the billing phone, false as the payer typed.
	 * @return string The other payer's transfer id.
	 */
	private function claimant_naming_another_payer( $as_billing_phone ) {
		$victim = '01155566600';
		$wallet = $this->vodafone_wallet();
		$sent   = $this->deliver(
			'transfer.received',
			$wallet,
			array(
				'payer'        => $victim,
				'amount_minor' => 25000,
			)
		);
		$this->assertSame( 'unmatched', $sent['response']->get_data()['status'] );
		$order = $this->order( $this->product( '50' ), $as_billing_phone ? $victim : '01299988877', self::CLAIMANT );
		$this->assertSame( 'success', $this->pay( $order, $as_billing_phone ? '01299988877' : $victim, $wallet['id'] )['result'] );
		$order = $this->fresh( $order->get_id() );
		$order->update_status( 'cancelled' );
		return $sent['envelope']['data']['transfer_id'];
	}

	/**
	 * Our exporter's items for an email, every page.
	 *
	 * @param string $email Email.
	 * @return array[] Items.
	 */
	private function exported( $email ) {
		$items = array();
		$page  = 1;
		do {
			$out   = Privacy::export( $email, $page );
			$items = array_merge( $items, $out['data'] );
			++$page;
		} while ( empty( $out['done'] ) && $page < 20 );
		return $items;
	}

	/**
	 * Runs every registered eraser for an email, page by page, in the order WordPress runs them.
	 *
	 * @param string $email Email.
	 * @return array Last answer of each eraser, by id.
	 */
	private function erase_all( $email ) {
		$out = array();
		foreach ( apply_filters( 'wp_privacy_personal_data_erasers', array() ) as $id => $eraser ) {
			$page = 1;
			do {
				$answer = call_user_func( $eraser['callback'], $email, $page );
				++$page;
			} while ( empty( $answer['done'] ) && $page < 20 );
			$out[ $id ] = $answer;
		}
		return $out;
	}

	/**
	 * Our exporter and eraser are registered with WordPress, the eraser before WooCommerce's.
	 */
	public function test_exporter_and_eraser_are_registered(): void {
		$this->assertArrayHasKey( 'qersh-for-woocommerce', apply_filters( 'wp_privacy_personal_data_exporters', array() ) );
		$erasers = array_keys( apply_filters( 'wp_privacy_personal_data_erasers', array() ) );
		$this->assertContains( 'qersh-for-woocommerce', $erasers );
		$this->assertContains( 'woocommerce-customer-orders', $erasers );
		$this->assertLessThan( array_search( 'woocommerce-customer-orders', $erasers, true ), array_search( 'qersh-for-woocommerce', $erasers, true ), 'ours runs before WooCommerce anonymises the orders' );
	}

	/**
	 * The export: the loose transfer under our group, the payer number and the paying transfer in the order export.
	 */
	public function test_export_lists_loose_transfers_and_the_order_data(): void {
		$setup = $this->customer_with_a_loose_transfer( '01155566677' );

		$export = Privacy::export( self::EMAIL, 1 );
		$this->assertTrue( $export['done'] );
		$items = array_values(
			array_filter(
				$export['data'],
				function ( $item ) use ( $setup ) {
					return 'qersh-transfers' === $item['group_id'] && false !== strpos( wp_json_encode( $item['data'] ), $setup['transfer_id'] );
				}
			)
		);
		$this->assertCount( 1, $items, 'the personal transfer is exported once' );
		$this->assertStringContainsString( '01155566677', wp_json_encode( $items[0]['data'] ) );

		$order_data = apply_filters( 'woocommerce_privacy_export_order_personal_data', array(), $setup['order'] );
		$this->assertStringContainsString( '01155566677', wp_json_encode( $order_data ) );
		$this->assertStringContainsString( $setup['paid_by'], wp_json_encode( $order_data ), 'the transfer that paid the order goes with the order' );

		$nobody = Privacy::export( 'nobody@example.test', 1 );
		$this->assertSame( array(), $nobody['data'] );
	}

	/**
	 * The erase: the loose transfer loses its payer fields; the order eraser removes our meta, the index row and
	 * the payer fields of the transfer that paid the order.
	 */
	public function test_erase_removes_the_payer_data(): void {
		$setup = $this->customer_with_a_loose_transfer( '01155566688' );

		$erased = Privacy::erase( self::EMAIL, 1 );
		$this->assertTrue( $erased['items_removed'] );
		$this->assertFalse( $erased['items_retained'] );
		$this->assertTrue( $erased['done'] );
		$transfer = $this->plugin->transfers()->get( $setup['transfer_id'] );
		$this->assertNull( $transfer['payer_key'] );
		$this->assertNull( $transfer['payer_name'] );
		$this->assertSame( '01155566688', (string) $this->plugin->transfers()->get( $setup['paid_by'] )['payer_key'], 'the order\'s own transfer stays with the order' );

		do_action( 'woocommerce_privacy_remove_order_personal_data', $setup['order'] );
		$fresh = $this->fresh( $setup['order']->get_id() );
		$this->assertSame( '', (string) $fresh->get_meta( '_qersh_payer_key' ) );
		$this->assertNull( $this->row( $setup['order']->get_id() ) );
		$this->assertNull( $this->plugin->transfers()->get( $setup['paid_by'] )['payer_key'] );
	}

	/**
	 * A transfer from the customer's number that still waits for the merchant's decision keeps its payer data, and
	 * the eraser says so.
	 */
	public function test_erase_keeps_a_transfer_that_needs_a_decision(): void {
		$setup  = $this->customer_with_a_loose_transfer( '01155566699' );
		$review = $this->deliver(
			'transfer.received',
			$setup['wallet'],
			array(
				'payer'        => '01155566699',
				'amount_minor' => 5000,
				'auto_ok'      => false,
			)
		);
		$this->assertSame( 'review', $review['response']->get_data()['status'] );
		$review_id = $review['envelope']['data']['transfer_id'];
		$this->assertEmpty( $this->plugin->transfers()->get( $review_id )['order_id'] );

		$erased = Privacy::erase( self::EMAIL, 1 );
		$this->assertTrue( $erased['items_removed'] );
		$this->assertTrue( $erased['items_retained'] );
		$this->assertCount( 1, $erased['messages'] );
		$this->assertNull( $this->plugin->transfers()->get( $setup['transfer_id'] )['payer_key'], 'the personal transfer is erased' );
		$kept = $this->plugin->transfers()->get( $review_id );
		$this->assertSame( '01155566699', (string) $kept['payer_key'] );
		$this->assertNotEmpty( $kept['payer_name'] );
	}

	/**
	 * A number typed in the payer field of an unpaid order proves nothing: the other payer's transfers are neither
	 * exported nor erased.
	 */
	public function test_a_typed_payer_number_selects_nothing(): void {
		$victim_transfer = $this->claimant_naming_another_payer( false );

		$items = array_filter(
			$this->exported( self::CLAIMANT ),
			function ( $item ) {
				return 'qersh-transfers' === $item['group_id'];
			}
		);
		$this->assertSame( array(), $items );

		$erased = Privacy::erase( self::CLAIMANT, 1 );
		$this->assertFalse( $erased['items_removed'] );
		$kept = $this->plugin->transfers()->get( $victim_transfer );
		$this->assertSame( '01155566600', (string) $kept['payer_key'] );
		$this->assertNotEmpty( $kept['payer_name'] );
	}

	/**
	 * Nor does a billing phone, whichever erasers run.
	 */
	public function test_a_billing_phone_selects_nothing(): void {
		$victim_transfer = $this->claimant_naming_another_payer( true );

		$items = array_filter(
			$this->exported( self::CLAIMANT ),
			function ( $item ) {
				return 'qersh-transfers' === $item['group_id'];
			}
		);
		$this->assertSame( array(), $items );

		$this->erase_all( self::CLAIMANT );
		$kept = $this->plugin->transfers()->get( $victim_transfer );
		$this->assertSame( '01155566600', (string) $kept['payer_key'] );
		$this->assertNotEmpty( $kept['payer_name'] );
	}

	/**
	 * With "Remove personal data from orders on request" on, WooCommerce's order eraser anonymises the orders and
	 * blanks the transfers that paid them; ours ran first, so the loose transfer is erased too.
	 */
	public function test_erase_with_woocommerce_order_erasure_on(): void {
		update_option( 'woocommerce_erasure_request_removes_order_data', 'yes' );
		$setup = $this->customer_with_a_loose_transfer( '01155566611' );

		$answers = $this->erase_all( self::EMAIL );
		$this->assertTrue( $answers['qersh-for-woocommerce']['items_removed'] );
		$this->assertNull( $this->plugin->transfers()->get( $setup['transfer_id'] )['payer_key'] );
		$this->assertNull( $this->plugin->transfers()->get( $setup['transfer_id'] )['payer_name'] );
		$this->assertNull( $this->plugin->transfers()->get( $setup['paid_by'] )['payer_key'] );
		$this->assertNull( $this->row( $setup['order']->get_id() ) );
	}
}
