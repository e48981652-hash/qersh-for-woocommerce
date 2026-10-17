<?php
/**
 * The Orange Cash transaction id (docs/17 §4.10): typed before the transfer arrives (the delivery matches by it),
 * and after (the delivery waits as review/no_payer, the late evaluation matches it and the next reply to the
 * phone carries the status update). The pasted message is a real masked one from tests/vectors/txn-normalize.json.
 *
 * @package QershPay\WooCommerce\Tests
 */

namespace QershPay\WooCommerce\Tests\Integration;

defined( 'ABSPATH' ) || exit;

/**
 * Transaction id before and after the delivery.
 */
class OrangeClaimTest extends TestCase {

	/**
	 * The first "C1" vector: a whole received-money message, with its 14-digit id replaced by a fresh one (each
	 * real transfer has its own id; a fixed one would make a second test a duplicate, rule 2), and that id.
	 *
	 * @return array `[ 'raw' => string, 'txn' => string ]`
	 */
	private static function pasted_message() {
		$vectors = json_decode( (string) file_get_contents( QERSH_WC_TESTS_ROOT . '/tests/vectors/txn-normalize.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		foreach ( $vectors as $v ) {
			if ( 0 === strpos( $v['name'], 'C1 ' ) && ! empty( $v['expect']['ok'] ) && self::ORANGE_PAYER === $v['payer_key'] ) {
				$old = (string) $v['expect']['txn'];
				$new = '2500' . str_pad( (string) random_int( 0, 9999999999 ), 10, '0', STR_PAD_LEFT );
				$raw = (string) $v['raw'];
				if ( 14 !== strlen( $old ) || false === strpos( $raw, $old ) ) {
					return array(
						'raw' => $raw,
						'txn' => $old,
					);
				}
				return array(
					'raw' => str_replace( $old, $new, $raw ),
					'txn' => $new,
				);
			}
		}
		throw new \RuntimeException( 'no C1 vector in tests/vectors/txn-normalize.json' );
	}

	/**
	 * A delivery of the Orange wallet without a payer number, as the phone sends one today.
	 *
	 * @param array  $wallet Wallet entry.
	 * @param string $txn    Operator transaction id.
	 * @param int    $minor  Amount.
	 * @return array deliver() result.
	 */
	private function orange_delivery( array $wallet, $txn, $minor ) {
		return $this->deliver(
			'transfer.received',
			$wallet,
			array(
				'payer'        => null,
				'txn'          => $txn,
				'amount_minor' => $minor,
				'risk'         => 'low',
				'reasons'      => array(),
				'auto_ok'      => true,
			)
		);
	}

	/**
	 * The customer pastes the whole message first; the delivery then matches the order by the id.
	 */
	public function test_claim_before_the_delivery_matches_by_the_id() {
		$wallet  = $this->orange_wallet();
		$message = self::pasted_message();
		$order   = $this->order( $this->product( '140' ), self::ORANGE_PAYER );
		$this->assertSame( 'success', $this->pay( $order, self::ORANGE_PAYER, $wallet['id'] )['result'] );

		$saved = $this->plugin->claims()->submit( $this->fresh( $order->get_id() ), $message['raw'], time() );
		$this->assertSame( 200, $saved['http'] );
		$this->assertSame( 'saved', $saved['body']['status'] );
		$this->assertSame( $message['txn'], $saved['body']['txn'] );
		$this->assertSame( $message['txn'], (string) $this->row( $order->get_id() )['claim_txn'] );
		$this->assertSame( $message['txn'], (string) $this->fresh( $order->get_id() )->get_meta( '_qersh_claim_txn' ) );

		$again = $this->plugin->claims()->submit( $this->fresh( $order->get_id() ), $message['txn'], time() );
		$this->assertSame( 200, $again['http'], 'the same id again is free' );
		$this->assertSame( 1, (int) $this->row( $order->get_id() )['claim_tries'] );

		$response = $this->orange_delivery( $wallet, $message['txn'], 14000 )['response'];
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'matched', $response->get_data()['status'] );

		$this->plugin->completion()->run( $order->get_id() );
		$this->assertContains( $this->fresh( $order->get_id() )->get_status(), array( 'processing', 'completed' ) );
	}

	/**
	 * The delivery first: review/no_payer; the id typed after it is evaluated outside the customer's request, the
	 * order is paid, and the wallet's next reply carries the matched status update.
	 */
	public function test_claim_after_the_delivery_is_evaluated_and_reported_to_the_phone() {
		$wallet  = $this->orange_wallet();
		$message = self::pasted_message();
		$order   = $this->order( $this->product( '140' ), self::ORANGE_PAYER );
		$this->assertSame( 'success', $this->pay( $order, self::ORANGE_PAYER, $wallet['id'] )['result'] );

		$sent     = $this->orange_delivery( $wallet, $message['txn'], 14000 );
		$response = $sent['response'];
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'review', $response->get_data()['status'] );
		$transfer_id = $sent['envelope']['data']['transfer_id'];
		$transfer    = $this->plugin->transfers()->get( $transfer_id );
		$this->assertSame( 'review', (string) $transfer['outcome'] );
		$this->assertSame( 'no_payer', (string) $transfer['reason'] );
		$this->assertSame( 'open', (string) $this->row( $order->get_id() )['state'] );

		$saved = $this->plugin->claims()->submit( $this->fresh( $order->get_id() ), $message['raw'], time() );
		$this->assertSame( 200, $saved['http'] );
		$this->assertSame( 'saved', $saved['body']['status'] );
		$this->assertSame( 'open', (string) $this->row( $order->get_id() )['state'], 'no evaluation inside the customer request' );

		$this->plugin->claims()->evaluate( $order->get_id() );
		$this->assertSame( 'paid', (string) $this->row( $order->get_id() )['state'] );
		$this->assertSame( 'matched', (string) $this->plugin->transfers()->get( $transfer_id )['outcome'] );

		$this->plugin->completion()->run( $order->get_id() );
		$this->assertContains( $this->fresh( $order->get_id() )->get_status(), array( 'processing', 'completed' ) );

		$updates = $this->plugin->orders()->status_updates( $wallet['id'], time() );
		$mine    = array_values(
			array_filter(
				$updates,
				function ( $u ) use ( $transfer_id ) {
					return isset( $u['transfer_id'] ) && $u['transfer_id'] === $transfer_id;
				}
			)
		);
		$this->assertCount( 1, $mine, 'one status update for the transfer' );
		$this->assertSame( 'matched', $mine[0]['status'] );
		$this->assertNotEmpty( $mine[0]['note'] );
	}

	/**
	 * A Vodafone Cash order takes no transaction id: the route's one 404.
	 */
	public function test_a_vodafone_order_takes_no_id() {
		$wallet = $this->vodafone_wallet();
		$order  = $this->order( $this->product( '100' ) );
		$this->assertSame( 'success', $this->pay( $order, '01122334411', $wallet['id'] )['result'] );

		$response = $this->post_json(
			'/qersh/v1/order-txn',
			array(
				'order_id' => $order->get_id(),
				'key'      => $order->get_order_key(),
				'txn'      => '25007294067405',
			)
		);
		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'qersh_not_found', $response->get_data()['code'] );
	}
}
