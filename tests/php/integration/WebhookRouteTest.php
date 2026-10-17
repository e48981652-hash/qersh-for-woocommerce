<?php
/**
 * The webhook route's answers (docs/17 §4.4, §4.5): 404 for an unknown wallet, 404 then 410 for a deleted one
 * around the 72 hours, 401 for a bad signature and for a wallet without a key, 413 for a body above 64 KB, 400 for
 * a body that is not a qersh.v1 envelope, 429 while the same delivery is being processed, 200 matched, and the
 * stored reply for a repeated delivery.
 *
 * @package QershPay\WooCommerce\Tests
 */

namespace QershPay\WooCommerce\Tests\Integration;

defined( 'ABSPATH' ) || exit;

/**
 * Webhook route codes.
 */
class WebhookRouteTest extends TestCase {

	/**
	 * A wallet the store never had: 404 qersh_unknown_wallet, before any signature check.
	 */
	public function test_unknown_wallet_is_404() {
		$this->vodafone_wallet();
		$response = $this->post_webhook( 'aaaaaaaaaaaa', '{}', null );
		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'qersh_unknown_wallet', $response->get_data()['code'] );
	}

	/**
	 * A deleted wallet answers 404 qersh_wallet_deleted until gone_at, then 410 qersh_wallet_gone, without a write.
	 */
	public function test_deleted_wallet_is_404_then_410() {
		$kept    = $this->vodafone_wallet();
		$deleted = $this->orange_wallet();

		$config            = $this->plugin->config_store()->get();
		$config['wallets'] = array_values(
			array_filter(
				$config['wallets'],
				function ( $w ) use ( $deleted ) {
					return $w['id'] !== $deleted['id'];
				}
			)
		);
		$this->save_config( $config );
		$this->assertNotNull( $this->plugin->wallet( $kept['id'] ) );
		$this->assertNull( $this->plugin->wallet( $deleted['id'] ) );

		global $wpdb;
		$table = $wpdb->prefix . 'qersh_wallets';
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET gone_at = %d WHERE wallet = %s', $table, time() + 60, $deleted['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$deliveries_before = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $wpdb->prefix . 'qersh_deliveries' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$recent = $this->deliver( 'transfer.received', $deleted, array( 'amount_minor' => 14000 ) )['response'];
		$this->assertSame( 404, $recent->get_status() );
		$this->assertSame( 'qersh_wallet_deleted', $recent->get_data()['code'] );

		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET gone_at = %d WHERE wallet = %s', $table, time(), $deleted['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$gone = $this->deliver( 'transfer.received', $deleted, array( 'amount_minor' => 14000 ) )['response'];
		$this->assertSame( 410, $gone->get_status() );
		$this->assertSame( 'qersh_wallet_gone', $gone->get_data()['code'] );

		$deliveries_after = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $wpdb->prefix . 'qersh_deliveries' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertSame( $deliveries_before, $deliveries_after, 'no delivery row for a deleted wallet' );
	}

	/**
	 * A signature made with another key: 401 qersh_invalid_signature, reason bad_signature.
	 */
	public function test_bad_signature_is_401() {
		$wallet = $this->vodafone_wallet();
		$env    = $this->envelope( 'transfer.received', $wallet, array( 'amount_minor' => 100 ) );
		$body   = (string) wp_json_encode( $env );
		$other  = \QershPay\WooCommerce\Core\Config::new_key();

		$response = $this->post_webhook( $wallet['id'], $body, $this->sign( $body, $wallet['id'], $other ) );
		$this->assertSame( 401, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'qersh_invalid_signature', $data['code'] );
		$this->assertSame( 'bad_signature', $data['data']['reason'] );
	}

	/**
	 * A wallet with no usable key: 401 with the reason no_key.
	 */
	public function test_wallet_without_a_key_is_401_no_key() {
		$wallet = $this->vodafone_wallet();
		$key    = $this->keys[ $wallet['id'] ];
		$this->plugin->secrets()->erase( $wallet['id'] );

		$env      = $this->envelope( 'transfer.received', $wallet, array( 'amount_minor' => 100 ) );
		$body     = (string) wp_json_encode( $env );
		$response = $this->post_webhook( $wallet['id'], $body, $this->sign( $body, $wallet['id'], $key ) );
		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'no_key', $response->get_data()['data']['reason'] );
	}

	/**
	 * A body above 64 KB: 413 qersh_too_large before the signature is read.
	 */
	public function test_body_above_64_kb_is_413() {
		$wallet   = $this->vodafone_wallet();
		$body     = '{"pad":"' . str_repeat( 'x', 66000 ) . '"}';
		$response = $this->post_webhook( $wallet['id'], $body, $this->sign( $body, $wallet['id'] ) );
		$this->assertSame( 413, $response->get_status() );
		$this->assertSame( 'qersh_too_large', $response->get_data()['code'] );
	}

	/**
	 * A signed body that is not JSON: 400. With `Content-Type: application/json` WordPress refuses it itself
	 * (rest_invalid_json) before the route runs; the status is the same 400 the phone treats as final.
	 */
	public function test_signed_body_that_is_not_json_is_400() {
		$wallet   = $this->vodafone_wallet();
		$body     = 'this is not json {';
		$response = $this->post_webhook( $wallet['id'], $body, $this->sign( $body, $wallet['id'] ) );
		$this->assertSame( 400, $response->get_status() );
		$this->assertContains( $response->get_data()['code'], array( 'qersh_invalid_envelope', 'rest_invalid_json' ) );
	}

	/**
	 * Signed JSON that is not a qersh.v1 envelope: 400 qersh_invalid_envelope with the reason.
	 */
	public function test_signed_json_that_is_not_an_envelope_is_400() {
		$wallet   = $this->vodafone_wallet();
		$body     = '{"schema":"other.v9","event":"transfer.received"}';
		$response = $this->post_webhook( $wallet['id'], $body, $this->sign( $body, $wallet['id'] ) );
		$this->assertSame( 400, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'qersh_invalid_envelope', $data['code'] );
		$this->assertSame( 'not_qersh_v1', $data['data']['reason'] );
	}

	/**
	 * The same delivery while another request still processes it: 429 qersh_busy with Retry-After 30.
	 */
	public function test_same_delivery_in_progress_is_429() {
		$wallet = $this->vodafone_wallet();
		$id     = wp_generate_uuid4();
		$claim  = $this->plugin->deliveries()->claim( $id, $wallet['id'], 'transfer.received', null, time() );
		$this->assertSame( 'claimed', $claim['state'] );

		$response = $this->deliver(
			'transfer.received',
			$wallet,
			array(
				'delivery_id'  => $id,
				'amount_minor' => 100,
			)
		)['response'];
		$this->assertSame( 429, $response->get_status() );
		$this->assertSame( 'qersh_busy', $response->get_data()['code'] );
		$this->assertSame( '30', self::header( $response, 'Retry-After' ) );
	}

	/**
	 * A transfer from the order's payer for its total: 200 matched with the order number; the same delivery again
	 * gets the stored reply and changes nothing.
	 */
	public function test_matching_transfer_is_200_matched_and_a_repeat_gets_the_stored_reply() {
		$wallet = $this->vodafone_wallet();
		$order  = $this->order( $this->product( '140' ) );
		$this->assertSame( 'success', $this->pay( $order, '01122334455', $wallet['id'] )['result'] );

		$sent     = $this->deliver(
			'transfer.received',
			$wallet,
			array(
				'payer'        => '01122334455',
				'amount_minor' => 14000,
			)
		);
		$response = $sent['response'];
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'matched', $data['status'] );
		$this->assertSame( (string) $order->get_order_number(), (string) $data['reference'] );

		$row = $this->row( $order->get_id() );
		$this->assertSame( 'paid', (string) $row['state'] );
		$this->assertSame( 14000, (int) $row['paid_minor'] );
		$transfer = $this->plugin->transfers()->get( $sent['envelope']['data']['transfer_id'] );
		$this->assertSame( 'matched', (string) $transfer['outcome'] );
		$this->assertSame( $order->get_id(), (int) $transfer['order_id'] );

		$again = $this->post_webhook( $wallet['id'], $sent['body'], $this->sign( $sent['body'], $wallet['id'] ) );
		$this->assertSame( 200, $again->get_status() );
		$this->assertSame( 'matched', $again->get_data()['status'] );
		$this->assertSame( 14000, (int) $this->row( $order->get_id() )['paid_minor'], 'the repeat paid nothing more' );
	}

	/**
	 * GET is not a route: WordPress's own 404 rest_no_route.
	 */
	public function test_get_is_not_a_route() {
		$wallet   = $this->vodafone_wallet();
		$response = $this->server->dispatch( new \WP_REST_Request( 'GET', '/qersh/v1/webhook/' . $wallet['id'] ) );
		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'rest_no_route', $response->get_data()['code'] );
	}
}
