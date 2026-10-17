<?php
/**
 * Personal data (docs/17 §4.1 Privacy, §9): the exporter lists the transfers from the customer's numbers that paid
 * no order, WooCommerce's order export carries the payer number and the typed transaction id, the eraser removes
 * the payer fields of those transfers, and the order eraser removes the meta and the index row.
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

	/**
	 * A personal transfer from the payer (before any order), then an order of the same payer.
	 *
	 * @param string $payer Payer.
	 * @return array `[ 'order' => \WC_Order, 'transfer_id' => string, 'wallet' => array ]`
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
		return array(
			'order'       => $this->fresh( $order->get_id() ),
			'transfer_id' => $sent['envelope']['data']['transfer_id'],
			'wallet'      => $wallet,
		);
	}

	/**
	 * Our exporter and eraser are registered with WordPress.
	 */
	public function test_exporter_and_eraser_are_registered() {
		$this->assertArrayHasKey( 'qersh-for-woocommerce', apply_filters( 'wp_privacy_personal_data_exporters', array() ) );
		$this->assertArrayHasKey( 'qersh-for-woocommerce', apply_filters( 'wp_privacy_personal_data_erasers', array() ) );
	}

	/**
	 * The export: the loose transfer under our group, the payer number in the order export.
	 */
	public function test_export_lists_loose_transfers_and_the_order_data() {
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

		$nobody = Privacy::export( 'nobody@example.test', 1 );
		$this->assertSame( array(), $nobody['data'] );
	}

	/**
	 * The erase: the loose transfer loses its payer fields; the order eraser removes our meta and index row.
	 */
	public function test_erase_removes_the_payer_data() {
		$setup = $this->customer_with_a_loose_transfer( '01155566688' );

		$erased = Privacy::erase( self::EMAIL, 1 );
		$this->assertTrue( $erased['items_removed'] );
		$this->assertTrue( $erased['done'] );
		$transfer = $this->plugin->transfers()->get( $setup['transfer_id'] );
		$this->assertNull( $transfer['payer_key'] );
		$this->assertNull( $transfer['payer_name'] );

		do_action( 'woocommerce_privacy_remove_order_personal_data', $setup['order'] );
		$fresh = $this->fresh( $setup['order']->get_id() );
		$this->assertSame( '', (string) $fresh->get_meta( '_qersh_payer_key' ) );
		$this->assertNull( $this->row( $setup['order']->get_id() ) );
	}
}
