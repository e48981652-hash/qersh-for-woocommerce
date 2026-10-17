<?php
/**
 * Tests of the pure parts of Webhook\Controller: the wallet answers, the event dispatch, the status updates within
 * 16 KB and the answer shapes (docs/17 §4.4, §4.5).
 *
 * @package QershPay\WooCommerce\Tests
 */

declare( strict_types=1 );

namespace QershPay\WooCommerce\Tests\Unit\Webhook;

use QershPay\WooCommerce\Webhook\Controller;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

defined( 'ABSPATH' ) || exit;

/**
 * Controller's static helpers.
 */
final class ControllerTest extends TestCase {

	/** 404 then 410 for a deleted wallet, 404 for an unknown one, with the Arabic bodies of docs/17 §4.4 step 3. */
	public function test_wallet_refusals(): void {
		$recent = Controller::wallet_refusal( 'deleted_recent' );
		$this->assertSame( 404, $recent['http'] );
		$this->assertSame( 'qersh_wallet_deleted', $recent['body']['code'] );
		$this->assertSame( 'حُذفت هذه المحفظة في المتجر. استعِدها من WooCommerce ← قرش ← المحافظ.', $recent['body']['message'] );
		$this->assertSame( 404, $recent['body']['data']['status'] );

		$gone = Controller::wallet_refusal( 'gone' );
		$this->assertSame( 410, $gone['http'] );
		$this->assertSame( 'qersh_wallet_gone', $gone['body']['code'] );
		$this->assertSame( 'حُذفت هذه المحفظة في المتجر منذ أكثر من 72 ساعة.', $gone['body']['message'] );

		foreach ( array( 'unknown', 'active', '' ) as $state ) {
			$unknown = Controller::wallet_refusal( $state );
			$this->assertSame( 404, $unknown['http'], $state );
			$this->assertSame( 'qersh_unknown_wallet', $unknown['body']['code'], $state );
		}
	}

	/** The note of the webhook.test reply, as the phone shows it (docs/17 §4.4 step 7). */
	public function test_test_note(): void {
		$this->assertSame( 'متجر WooCommerce متصل', Controller::TEST_NOTE );
	}

	/** webhook.test, transfer.received and transfer.updated when configured; everything else is ignored. */
	public function test_event_kind(): void {
		$events = array( 'transfer.received', 'transfer.updated', 'webhook.test' );
		$this->assertSame( 'test', Controller::event_kind( 'webhook.test', $events ) );
		$this->assertSame( 'received', Controller::event_kind( 'transfer.received', $events ) );
		$this->assertSame( 'updated', Controller::event_kind( 'transfer.updated', $events ) );
		$this->assertSame( 'ignored', Controller::event_kind( 'device.heartbeat', $events ) );
		$this->assertSame( 'ignored', Controller::event_kind( 'transfer.sent', $events ) );
		$this->assertSame( 'ignored', Controller::event_kind( 'device.heartbeat', array_merge( $events, array( 'device.heartbeat' ) ) ) );
		$this->assertSame( 'ignored', Controller::event_kind( 'transfer.updated', array( 'transfer.received' ) ) );
		$this->assertSame( 'ignored', Controller::event_kind( 'webhook.test', array( 'transfer.received' ) ) );
		$this->assertSame( 'ignored', Controller::event_kind( 'transfer.received', null ) );
	}

	/** A status update keeps transfer_id, a known status, and reference and note cut to 64 and 200 characters. */
	public function test_clean_update(): void {
		$this->assertNull( Controller::clean_update( null ) );
		$this->assertNull( Controller::clean_update( array( 'status' => 'matched' ) ) );
		$this->assertNull(
			Controller::clean_update(
				array(
					'transfer_id' => 'trf_1',
					'status'      => 'attached',
				)
			)
		);
		$this->assertNull(
			Controller::clean_update(
				array(
					'transfer_id' => '',
					'status'      => 'matched',
				)
			)
		);

		$item = Controller::clean_update(
			array(
				'transfer_id' => 'trf_1',
				'status'      => 'matched',
				'reference'   => str_repeat( 'R', 70 ),
				'note'        => str_repeat( 'ط', 250 ),
				'extra'       => 'dropped',
			)
		);
		$this->assertSame( array( 'transfer_id', 'status', 'reference', 'note' ), array_keys( $item ) );
		$this->assertSame( 64, strlen( $item['reference'] ) );
		$this->assertSame( 200, mb_strlen( $item['note'], 'UTF-8' ) );

		$ignored = Controller::clean_update(
			array(
				'transfer_id' => 'trf_2',
				'status'      => 'ignored',
				'reference'   => '',
			)
		);
		$this->assertSame(
			array(
				'transfer_id' => 'trf_2',
				'status'      => 'ignored',
			),
			$ignored
		);

		$numeric = Controller::clean_update(
			array(
				'transfer_id' => 'trf_3',
				'status'      => 'partial',
				'reference'   => 1042,
			)
		);
		$this->assertSame( '1042', $numeric['reference'] );
	}

	/** Updates ride on the reply in order, each transfer once, and the JSON stays within the limit. */
	public function test_cap_updates_within_limit(): void {
		$reply = array(
			'status'         => 'matched',
			'reference'      => '1042',
			'status_updates' => array(
				array(
					'transfer_id' => 'old',
					'status'      => 'review',
				),
			),
		);
		$items = array();
		for ( $i = 0; $i < 50; $i++ ) {
			$items[] = array(
				'transfer_id' => sprintf( 'trf_%026d', $i ),
				'status'      => 'matched',
				'reference'   => (string) ( 1000 + $i ),
				'note'        => str_repeat( 'ألحقه التاجر بالطلب ', 9 ),
			);
		}
		$items[] = $items[0];
		$out     = Controller::cap_updates( $reply, $items, Controller::REPLY_MAX );
		$json    = json_encode( $out['reply'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		$this->assertLessThanOrEqual( Controller::REPLY_MAX, strlen( (string) $json ) );
		$this->assertSame( 'matched', $out['reply']['status'] );
		$this->assertNotEmpty( $out['ids'] );
		$this->assertLessThan( 50, count( $out['ids'] ) );
		$this->assertSame( $out['ids'], array_column( $out['reply']['status_updates'], 'transfer_id' ) );
		$this->assertSame( sprintf( 'trf_%026d', 0 ), $out['ids'][0] );
		$this->assertNotContains( 'old', $out['ids'] );
	}

	/** No updates: no status_updates key at all (and a stored one is not repeated). */
	public function test_cap_updates_without_items(): void {
		$out = Controller::cap_updates(
			array(
				'status'         => 'ok',
				'status_updates' => array(),
			),
			array( array( 'status' => 'bad' ) )
		);
		$this->assertSame( array( 'status' => 'ok' ), $out['reply'] );
		$this->assertSame( array(), $out['ids'] );
	}

	/** Small sets fit whole, in order. */
	public function test_cap_updates_small(): void {
		$items = array(
			array(
				'transfer_id' => 'trf_a',
				'status'      => 'matched',
				'reference'   => '7',
				'note'        => 'طابق برقم العملية الذي كتبه العميل',
			),
			array(
				'transfer_id' => 'trf_b',
				'status'      => 'ignored',
			),
		);
		$out   = Controller::cap_updates( array( 'status' => 'ignored' ), $items );
		$this->assertSame( array( 'trf_a', 'trf_b' ), $out['ids'] );
		$this->assertCount( 2, $out['reply']['status_updates'] );
	}

	/** Error bodies have the WP_Error shape of the REST API; the plain ones just a code. */
	public function test_answer_shapes(): void {
		$this->assertSame(
			array(
				'http'    => 401,
				'body'    => array(
					'code'    => 'qersh_invalid_signature',
					'message' => 'm',
					'data'    => array(
						'status' => 401,
						'reason' => 'no_key',
					),
				),
				'headers' => array(),
			),
			Controller::failure( 401, 'qersh_invalid_signature', 'm', array( 'reason' => 'no_key' ) )
		);
		$this->assertSame( array( 'code' => 'qersh_internal_error' ), Controller::failure( 500, 'qersh_internal_error' )['body'] );

		$busy = Controller::busy( 30 );
		$this->assertSame( 429, $busy['http'] );
		$this->assertSame( array( 'Retry-After' => '30' ), $busy['headers'] );
		$this->assertSame( array( 'code' => 'qersh_busy' ), $busy['body'] );

		$off = Controller::unavailable();
		$this->assertSame( 503, $off['http'] );
		$this->assertSame( array( 'code' => 'qersh_unavailable' ), $off['body'] );
	}

	/** A fence that hits no row (the stale claim was taken over) answers busy with Retry-After 30, never our reply. */
	public function test_fenced_out_is_busy(): void {
		$this->assertSame(
			array(
				'http'        => 429,
				'reply'       => array( 'code' => 'qersh_busy' ),
				'retry_after' => 30,
			),
			Controller::fenced_out()
		);
		$this->assertSame( Controller::RETRY_AFTER, Controller::fenced_out()['retry_after'] );
	}

	/** The log line of an error names the step, the class and the place, never the message (it may carry data). */
	public function test_error_line_has_no_message(): void {
		$e    = new \RuntimeException( 'SELECT * FROM wp_qersh_transfers WHERE payer_key = 01001234567' );
		$line = Controller::error_line( 'permission check', $e );
		$this->assertStringStartsWith( 'qersh: webhook permission check failed: RuntimeException at ControllerTest.php:', $line );
		$this->assertStringNotContainsString( '01001234567', $line );
		$this->assertStringNotContainsString( 'SELECT', $line );
	}

	/** The dead "newer key" option is gone: Diagnostics reads the v1 position from record_delivery(). */
	public function test_no_newer_key_option(): void {
		$this->assertFalse( defined( Controller::class . '::NEWER_KEY_OPTION' ) );
	}
}
