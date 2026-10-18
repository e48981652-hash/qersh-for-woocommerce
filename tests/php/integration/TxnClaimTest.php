<?php
/**
 * The transaction id a customer types on the order page (docs/17 §4.10), the same on every e-wallet: typed before
 * the transfer arrives (the delivery matches by it), and after (the delivery waits in review, the late evaluation
 * matches it and the next reply to the phone carries the status update). It decides only a transfer that came
 * without the payer's number (none, a masked one, or a handle only), never one that names a payer, and only on the
 * wallet the order chose. The route answers 404 only when store.txn_entry is off or the order's wallet was deleted.
 * The pasted messages are wordings of tests/vectors/txn-normalize.json, with invented values.
 *
 * @package QershPay\WooCommerce\Tests
 */

namespace QershPay\WooCommerce\Tests\Integration;

defined( 'ABSPATH' ) || exit;

/**
 * Transaction id before and after the delivery, on any wallet.
 */
class TxnClaimTest extends TestCase {

	/** A sender number the message gives masked. */
	const MASKED = '0100****567';

	/** A sender the message names by a handle only, with no number. */
	const HANDLE = 'test.buyer';

	/**
	 * A whole wallet message pasted by the customer, from the vectors: the first one whose name contains $hint (any
	 * pasted message of the vectors' payer and store when none does), with its id replaced by a fresh one of the
	 * same length and first four digits (each real transfer has its own id; a fixed one would make a second test a
	 * duplicate, rule 2), and that id.
	 *
	 * @param string $hint Part of the vector's name.
	 * @return array `[ 'raw' => string, 'txn' => string ]`
	 */
	private static function pasted_message( $hint ) {
		$vectors  = json_decode( (string) file_get_contents( QERSH_WC_TESTS_ROOT . '/tests/vectors/txn-normalize.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a fixture file of this repository.
		$chosen   = null;
		$fallback = null;
		foreach ( is_array( $vectors ) ? $vectors : array() as $v ) {
			if ( ! self::is_pasted_message( $v ) ) {
				continue;
			}
			if ( null === $fallback ) {
				$fallback = $v;
			}
			if ( '' !== $hint && false !== strpos( (string) $v['name'], $hint ) ) {
				$chosen = $v;
				break;
			}
		}
		$v = null !== $chosen ? $chosen : $fallback;
		if ( null === $v ) {
			throw new \RuntimeException( 'no pasted message in tests/vectors/txn-normalize.json' );
		}
		$old    = (string) $v['expect']['txn'];
		$length = strlen( $old );
		$new    = substr( $old, 0, 4 );
		for ( $i = 4; $i < $length; $i++ ) {
			$new .= (string) random_int( 0, 9 );
		}
		return array(
			'raw' => str_replace( $old, $new, (string) $v['raw'] ),
			'txn' => $new,
		);
	}

	/**
	 * Whether a vector is a whole message (letters around the id) of the vectors' payer and store, read as one id of
	 * at least eight digits that it holds in Latin digits.
	 *
	 * @param mixed $v Vector.
	 * @return bool
	 */
	private static function is_pasted_message( $v ) {
		if ( ! is_array( $v ) || empty( $v['expect']['ok'] ) || ! isset( $v['raw'], $v['name'], $v['expect']['txn'] ) || ! is_string( $v['raw'] ) ) {
			return false;
		}
		$txn = (string) $v['expect']['txn'];
		return self::VECTOR_PAYER === ( isset( $v['payer_key'] ) ? $v['payer_key'] : null )
			&& array( self::STORE_NUMBER ) === array_values( isset( $v['store_numbers'] ) ? (array) $v['store_numbers'] : array() )
			&& 1 === preg_match( '/^[0-9]{8,}$/D', $txn )
			&& false !== strpos( $v['raw'], $txn )
			&& 1 === preg_match( '/\p{L}/u', $v['raw'] );
	}

	/**
	 * A delivery without the payer's number, confirmed by the phone.
	 *
	 * @param array  $wallet   Wallet entry.
	 * @param string $txn      Operator transaction id.
	 * @param int    $minor    Amount.
	 * @param string $sender   `none` (a name only), `masked` (a masked number) or `handle` (a handle only).
	 * @param string $provider The envelope's wallet.provider.
	 * @return array deliver() result.
	 */
	private function payerless_delivery( array $wallet, $txn, $minor, $sender = 'none', $provider = 'vodafone_cash' ) {
		$opts = array(
			'txn'          => $txn,
			'amount_minor' => $minor,
			'risk'         => 'low',
			'reasons'      => array(),
			'auto_ok'      => true,
			'provider'     => $provider,
		);
		if ( 'masked' === $sender ) {
			$opts['payer'] = self::MASKED;
		} elseif ( 'handle' === $sender ) {
			$opts['handle'] = self::HANDLE;
		} else {
			$opts['payer'] = null;
		}
		return $this->deliver( 'transfer.received', $wallet, $opts );
	}

	/**
	 * A pending order of 140 for the vectors' payer on a wallet, through process_payment().
	 *
	 * @param array $wallet Wallet entry.
	 * @return \WC_Order
	 */
	private function waiting_order( array $wallet ) {
		$order = $this->order( $this->product( '140' ), self::VECTOR_PAYER );
		$this->assertSame( 'success', $this->pay( $order, self::VECTOR_PAYER, $wallet['id'] )['result'] );
		return $order;
	}

	/**
	 * POSTs a transaction id to the route for an order.
	 *
	 * @param \WC_Order $order Order.
	 * @param mixed     $txn   What the customer sent.
	 * @return \WP_REST_Response
	 */
	private function post_txn( $order, $txn ) {
		return $this->post_json(
			'/qersh/v1/order-txn',
			array(
				'order_id' => $order->get_id(),
				'key'      => $order->get_order_key(),
				'txn'      => $txn,
			)
		);
	}

	/**
	 * Wordings, senders and wallet types of the "before" case: none of them changes anything.
	 *
	 * @return array[]
	 */
	public static function before_cases() {
		return array(
			'an Orange Cash wording, no sender number' => array( 'Orange Cash C1', 'none', 'orange_cash' ),
			'a Vodafone Cash wording, a masked sender number' => array( 'Vodafone Cash style', 'masked', 'vodafone_cash' ),
			'an e& money wording, a handle only'       => array( 'e& money style', 'handle', 'etisalat_cash' ),
			'no sender number, a wallet type nobody knows yet' => array( 'Orange Cash C1', 'none', 'qersh_test_wallet' ),
		);
	}

	/**
	 * The customer pastes the whole message first; the delivery then matches the order by the id, whatever the
	 * wallet's type and however the message hides the sender.
	 *
	 * @dataProvider before_cases
	 *
	 * @param string $wording  Part of the name of the pasted vector.
	 * @param string $sender   Sender of the delivery (payerless_delivery()).
	 * @param string $provider The envelope's wallet.provider.
	 */
	public function test_claim_before_the_delivery_matches_by_the_id( $wording, $sender, $provider ): void {
		$wallet  = $this->wallet();
		$message = self::pasted_message( $wording );
		$order   = $this->waiting_order( $wallet );

		$saved = $this->plugin->claims()->submit( $this->fresh( $order->get_id() ), $message['raw'], time() );
		$this->assertSame( 200, $saved['http'] );
		$this->assertSame( 'saved', $saved['body']['status'] );
		$this->assertSame( $message['txn'], $saved['body']['txn'] );
		$this->assertSame( $message['txn'], (string) $this->row( $order->get_id() )['claim_txn'] );
		$this->assertSame( $message['txn'], (string) $this->fresh( $order->get_id() )->get_meta( '_qersh_claim_txn' ) );

		$again = $this->plugin->claims()->submit( $this->fresh( $order->get_id() ), $message['txn'], time() );
		$this->assertSame( 200, $again['http'], 'the same id again is free' );
		$this->assertSame( 1, (int) $this->row( $order->get_id() )['claim_tries'] );

		$sent     = $this->payerless_delivery( $wallet, $message['txn'], 14000, $sender, $provider );
		$response = $sent['response'];
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'matched', $response->get_data()['status'] );
		$this->assertSame( (string) $order->get_order_number(), (string) $response->get_data()['reference'] );

		$transfer = $this->plugin->transfers()->get( $sent['envelope']['data']['transfer_id'] );
		$this->assertSame( 'matched', (string) $transfer['outcome'] );
		$this->assertSame( $order->get_id(), (int) $transfer['order_id'] );
		$this->assertNull( $transfer['payer_key'], 'the transfer has no payer: the typed id decided it' );
		$this->assertSame( $provider, (string) $transfer['provider'], 'the envelope\'s wallet type, kept as information' );

		$this->plugin->completion()->run( $order->get_id() );
		$this->assertContains( $this->fresh( $order->get_id() )->get_status(), array( 'processing', 'completed' ) );
	}

	/**
	 * Senders of the "after" case and the reason the transfer waits for.
	 *
	 * @return array[]
	 */
	public static function after_cases() {
		return array(
			'no sender number'       => array( 'none', 'no_payer' ),
			'a masked sender number' => array( 'masked', 'masked' ),
			'a handle only'          => array( 'handle', 'no_payer' ),
		);
	}

	/**
	 * The delivery first: review (no_payer, or masked); the id typed after it is evaluated outside the customer's
	 * request, the order is paid, and the wallet's next reply carries the matched status update.
	 *
	 * @dataProvider after_cases
	 *
	 * @param string $sender Sender of the delivery (payerless_delivery()).
	 * @param string $reason The reason the transfer waits for.
	 */
	public function test_claim_after_the_delivery_is_evaluated_and_reported_to_the_phone( $sender, $reason ): void {
		$wallet  = $this->wallet();
		$message = self::pasted_message( 'Vodafone Cash style' );
		$order   = $this->waiting_order( $wallet );

		$sent     = $this->payerless_delivery( $wallet, $message['txn'], 14000, $sender );
		$response = $sent['response'];
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'review', $response->get_data()['status'] );
		$transfer_id = $sent['envelope']['data']['transfer_id'];
		$transfer    = $this->plugin->transfers()->get( $transfer_id );
		$this->assertSame( 'review', (string) $transfer['outcome'] );
		$this->assertSame( $reason, (string) $transfer['reason'] );
		$this->assertNull( $transfer['payer_key'] );
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
	 * A typed id never decides a transfer that names a payer (docs/17 D5): a transfer from another number with the
	 * same id is that number's own transfer (here a personal one), before and after the late evaluation, and the
	 * order keeps waiting.
	 */
	public function test_a_typed_id_never_decides_a_transfer_that_names_a_payer(): void {
		$wallet = $this->wallet();
		$order  = $this->waiting_order( $wallet );
		$txn    = self::txn_id();
		$this->assertSame( 200, $this->post_txn( $order, $txn )->get_status() );

		$sent = $this->deliver(
			'transfer.received',
			$wallet,
			array(
				'payer'        => '01155566644',
				'txn'          => $txn,
				'amount_minor' => 14000,
				'provider'     => 'orange_cash',
			)
		);
		$this->assertSame( 'unmatched', $sent['response']->get_data()['status'] );
		$transfer_id = $sent['envelope']['data']['transfer_id'];
		$this->assertSame( 'personal', (string) $this->plugin->transfers()->get( $transfer_id )['reason'] );
		$this->assertSame( 'open', (string) $this->row( $order->get_id() )['state'] );

		$this->plugin->claims()->evaluate( $order->get_id() );
		$this->assertSame( 'open', (string) $this->row( $order->get_id() )['state'], 'the late evaluation leaves it too' );
		$transfer = $this->plugin->transfers()->get( $transfer_id );
		$this->assertSame( 'unmatched', (string) $transfer['outcome'] );
		$this->assertNull( $transfer['order_id'] );
		$this->assertSame( '01155566644', (string) $transfer['payer_key'] );
	}

	/**
	 * Gate 1: the id typed for an order on one wallet does not decide a payerless transfer that reached another
	 * wallet; that transfer waits for the merchant (txn_wallet) and the order keeps waiting.
	 */
	public function test_an_id_typed_for_another_wallet_fails_gate_1(): void {
		$chosen = $this->wallet( 'Main wallet' );
		$other  = $this->wallet( 'Second wallet', '01000000102' );
		$order  = $this->waiting_order( $chosen );
		$txn    = self::txn_id();
		$this->assertSame( 200, $this->post_txn( $order, $txn )->get_status() );

		$sent = $this->payerless_delivery( $other, $txn, 14000 );
		$this->assertSame( 'review', $sent['response']->get_data()['status'] );
		$transfer = $this->plugin->transfers()->get( $sent['envelope']['data']['transfer_id'] );
		$this->assertSame( 'review', (string) $transfer['outcome'] );
		$this->assertSame( 'txn_wallet', (string) $transfer['reason'] );
		$this->assertNull( $transfer['order_id'] );
		$this->assertSame( 'open', (string) $this->row( $order->get_id() )['state'] );
	}

	/**
	 * The route takes an id for an order of any wallet, also one turned off since (turning a wallet off only hides it
	 * from the checkout). Its one 404, the status route's body, comes only when store.txn_entry is off or when the
	 * order's wallet was deleted; the status route then leaves `claim` out.
	 */
	public function test_the_route_answers_404_only_when_the_setting_is_off_or_the_wallet_was_deleted(): void {
		$kept    = $this->wallet( 'Main wallet' );
		$deleted = $this->wallet( 'Second wallet', '01000000102' );
		$first   = $this->waiting_order( $kept );
		$second  = $this->waiting_order( $kept );
		$gone    = $this->waiting_order( $deleted );

		$saved = $this->post_txn( $first, self::txn_id() );
		$this->assertSame( 200, $saved->get_status() );
		$this->assertSame( 'saved', $saved->get_data()['status'] );

		$config = $this->plugin->config_store()->get();
		foreach ( $config['wallets'] as $i => $entry ) {
			if ( $entry['id'] === $kept['id'] ) {
				$config['wallets'][ $i ]['enabled'] = false;
			}
		}
		$config['wallets'] = array_values(
			array_filter(
				$config['wallets'],
				function ( $w ) use ( $deleted ) {
					return $w['id'] !== $deleted['id'];
				}
			)
		);
		$this->save_config( $config );

		$this->assertSame( 200, $this->post_txn( $second, self::txn_id() )->get_status(), 'a wallet turned off keeps the box of its orders' );

		$refusal = $this->post_json( '/qersh/v1/order-status', array( 'order_id' => $gone->get_id() ) );
		$this->assertSame( 404, $refusal->get_status() );
		$deleted_answer = $this->post_txn( $gone, self::txn_id() );
		$this->assertSame( 404, $deleted_answer->get_status(), 'the order of a deleted wallet' );
		$this->assertSame( wp_json_encode( $refusal->get_data() ), wp_json_encode( $deleted_answer->get_data() ), 'the one 404 body' );
		$this->assertSame( '', (string) $this->row( $gone->get_id() )['claim_txn'] );

		$config                       = $this->plugin->config_store()->get();
		$config['store']['txn_entry'] = false;
		$this->save_config( $config );

		$off = $this->post_txn( $second, self::txn_id() );
		$this->assertSame( 404, $off->get_status(), 'store.txn_entry off' );
		$this->assertSame( 'qersh_not_found', $off->get_data()['code'] );

		$status = $this->post_json(
			'/qersh/v1/order-status',
			array(
				'order_id' => $second->get_id(),
				'key'      => $second->get_order_key(),
			)
		);
		$this->assertSame( 200, $status->get_status() );
		$this->assertArrayNotHasKey( 'claim', $status->get_data(), 'no box, so no claim' );
	}
}
