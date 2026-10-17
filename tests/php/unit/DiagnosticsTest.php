<?php
/**
 * Tests of the pure rules of Diagnostics (docs/17 §4.9): what a delivery writes, the phone's state, the silent
 * phone, the pending key, the store URL change, the self-test classifier and the transaction id drift.
 *
 * @package QershPay\WooCommerce\Tests
 */

declare( strict_types=1 );

namespace QershPay\WooCommerce\Tests\Unit;

use QershPay\WooCommerce\Diagnostics;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

defined( 'ABSPATH' ) || exit;

/**
 * Diagnostics, without WordPress.
 */
final class DiagnosticsTest extends TestCase {

	const NOW = 1792000000;

	/**
	 * An envelope.
	 *
	 * @param string $event Event.
	 * @param array  $data  data.
	 * @param array  $more  Other top-level fields.
	 * @return array
	 */
	private function envelope( string $event, array $data = array(), array $more = array() ): array {
		return array_merge(
			array(
				'schema'       => 'qersh.v1',
				'event'        => $event,
				'device'       => array(
					'id'          => 'dev_01JA2Y4X5M7Q9R0S1T2U3V4W5Z',
					'app_version' => '0.4.6',
					'flavor'      => 'play',
					'android_sdk' => 34,
					'state'       => array(
						'sms_permission'        => true,
						'background_restricted' => false,
					),
				),
				'subscription' => array( 'grace_until' => null ),
				'data'         => $data,
			),
			$more
		);
	}

	/** Every delivery: time, event, host and form, the device state, and the end of a silent episode. */
	public function test_delivery_fields_common() {
		$f = Diagnostics::delivery_fields( $this->envelope( 'transfer.updated' ), 'vodafone_cash', 'rest_route', 'shop.example:8443', self::NOW );
		$this->assertSame( self::NOW, $f['last_delivery_at'] );
		$this->assertSame( 'transfer.updated', $f['last_event'] );
		$this->assertSame( 'shop.example:8443', $f['seen_host'] );
		$this->assertSame( 'rest_route', $f['seen_form'] );
		$this->assertNull( $f['silent_since'] );
		$this->assertSame( 0, $f['silent_notified'] );
		$this->assertSame(
			array(
				'sms' => true,
				'bg'  => false,
				'v'   => '0.4.6',
			),
			$f['last_device_state']
		);
		$this->assertNull( $f['grace_until'] );
		$this->assertArrayNotHasKey( 'last_heartbeat_at', $f );
		$this->assertArrayNotHasKey( 'sender_masked', $f );
	}

	/** An unknown form is not stored; an empty host is not either. */
	public function test_delivery_fields_bad_form() {
		$f = Diagnostics::delivery_fields( $this->envelope( 'webhook.test' ), 'vodafone_cash', 'other', '', self::NOW );
		$this->assertArrayNotHasKey( 'seen_form', $f );
		$this->assertArrayNotHasKey( 'seen_host', $f );
	}

	/** A heartbeat writes last_heartbeat_at; grace_until comes from the subscription. */
	public function test_delivery_fields_heartbeat_and_grace() {
		$env = $this->envelope( 'device.heartbeat', array( 'seq' => 1 ), array( 'subscription' => array( 'grace_until' => '2026-10-20T10:00:00Z' ) ) );
		$f   = Diagnostics::delivery_fields( $env, 'vodafone_cash', 'wp-json', 'shop.example', self::NOW );
		$this->assertSame( self::NOW, $f['last_heartbeat_at'] );
		$this->assertSame( 1792490400, $f['grace_until'] );
	}

	/** transfer.received: masked sender and auto_seen. */
	public function test_delivery_fields_transfer() {
		$masked = Diagnostics::delivery_fields(
			$this->envelope(
				'transfer.received',
				array(
					'counterparty'    => array( 'number' => '0111***3445' ),
					'auto_confirm_ok' => true,
				)
			),
			'vodafone_cash',
			'wp-json',
			'shop.example',
			self::NOW
		);
		$this->assertSame( 1, $masked['sender_masked'] );
		$this->assertSame( 1, $masked['auto_seen'] );

		$full = Diagnostics::delivery_fields( $this->envelope( 'transfer.received', array( 'counterparty' => array( 'number' => '01112233445' ) ) ), 'vodafone_cash', 'wp-json', 'shop.example', self::NOW );
		$this->assertSame( 0, $full['sender_masked'] );
		$this->assertArrayNotHasKey( 'auto_seen', $full );

		$orange = Diagnostics::delivery_fields( $this->envelope( 'transfer.received', array( 'counterparty' => array( 'name' => 'Ali' ) ) ), 'orange_cash', 'wp-json', 'shop.example', self::NOW );
		$this->assertArrayNotHasKey( 'sender_masked', $orange );
	}

	/** The newer-key marker comes from the Verifier's v1_index. */
	public function test_device_state_newer_key() {
		$device = array(
			'app_version' => '0.4.6 beta<script>',
			'state'       => array(
				'sms_permission'    => false,
				'battery_optimized' => 'yes',
			),
		);
		$this->assertSame(
			array(
				'sms' => false,
				'v'   => '0.4.6betascript',
				'nk'  => 1,
			),
			Diagnostics::device_state( $device, array( 'v1_index' => 1 ) )
		);
		$this->assertArrayNotHasKey( 'nk', Diagnostics::device_state( $device, array( 'v1_index' => 0 ) ) );
		$this->assertNull( Diagnostics::device_state( null ) );
	}

	/** Masked sender rule. */
	public function test_sender_masked_value() {
		$this->assertSame( 1, Diagnostics::sender_masked_value( 'orange_cash', array( 'number' => '0111***445' ) ) );
		$this->assertSame( 1, Diagnostics::sender_masked_value( 'etisalat_cash', array() ) );
		$this->assertSame( 1, Diagnostics::sender_masked_value( 'vodafone_cash', array( 'number' => null, 'name' => '', 'handle' => null ) ) );
		$this->assertNull( Diagnostics::sender_masked_value( 'vodafone_cash', array( 'name' => 'Ali' ) ) );
		$this->assertNull( Diagnostics::sender_masked_value( 'bank_nbe', array() ) );
		$this->assertSame( 0, Diagnostics::sender_masked_value( 'vodafone_cash', array( 'number' => '+20 111 223 3445' ) ) );
		$this->assertSame( 0, Diagnostics::sender_masked_value( 'instapay_app', array( 'handle' => 'ali@instapay' ) ) );
	}

	/** Phone state alerts, only for keys the envelope carried; battery optimisation alone is no alert. */
	public function test_device_alerts() {
		$this->assertSame( array( 'device_sms' ), Diagnostics::device_alerts( 'vodafone_cash', array( 'sms' => false ) ) );
		$this->assertSame( array(), Diagnostics::device_alerts( 'instapay_app', array( 'sms' => false ) ) );
		$this->assertSame( array( 'device_notif' ), Diagnostics::device_alerts( 'instapay_app', array( 'notif' => false ) ) );
		$this->assertSame( array(), Diagnostics::device_alerts( 'orange_cash', array( 'notif' => false ) ) );
		$this->assertSame( array( 'device_bg' ), Diagnostics::device_alerts( 'bank_cib', array( 'bg' => true ) ) );
		$this->assertSame( array(), Diagnostics::device_alerts( 'vodafone_cash', array( 'batt' => true ) ) );
		$this->assertSame( array(), Diagnostics::device_alerts( 'vodafone_cash', null ) );
	}

	/** The silent phone: heartbeat wallets only, 35 minutes with an order waiting, two hours = down. */
	public function test_silent_state() {
		$row = array(
			'last_heartbeat_at' => self::NOW - 2160,
			'last_delivery_at'  => self::NOW - 2160,
		);
		$this->assertSame( 'suspect', Diagnostics::silent_state( $row, self::NOW, true )['state'] );
		$this->assertSame( self::NOW - 2160, Diagnostics::silent_state( $row, self::NOW, true )['since'] );
		$this->assertSame( 'none', Diagnostics::silent_state( $row, self::NOW, false )['state'] );
		$this->assertSame( 'none', Diagnostics::silent_state( $row, self::NOW - 120, true )['state'] );
		$this->assertSame( 'down', Diagnostics::silent_state( $row, self::NOW + 7200, true )['state'] );

		$basic = array(
			'last_heartbeat_at' => null,
			'last_delivery_at'  => self::NOW - 9000,
		);
		$this->assertSame( 'none', Diagnostics::silent_state( $basic, self::NOW, true )['state'] );

		$stopped = array(
			'last_heartbeat_at' => self::NOW - 200000,
			'last_delivery_at'  => self::NOW - 3000,
		);
		$this->assertSame( 'none', Diagnostics::silent_state( $stopped, self::NOW, true )['state'], 'heartbeats stopped a day before the last delivery' );
		$this->assertSame( 'none', Diagnostics::silent_state( null, self::NOW, true )['state'] );
	}

	/** The pending key: reminded after a day, the 7-day alert, cancelled only when the phone proved it lacks it. */
	public function test_pending_key() {
		$fp = array( 'pending_at' => self::NOW - 90000 );
		$this->assertSame( 'waiting', Diagnostics::pending_state( $fp, self::NOW )['state'] );
		$this->assertSame( 1, Diagnostics::pending_state( $fp, self::NOW )['day'] );
		$this->assertSame( 'none', Diagnostics::pending_state( array( 'pending_at' => self::NOW - 3600 ), self::NOW )['state'] );
		$this->assertSame( 'stale', Diagnostics::pending_state( array( 'pending_at' => self::NOW - 700000 ), self::NOW )['state'] );
		$this->assertSame( 'none', Diagnostics::pending_state( null, self::NOW )['state'] );

		$old = array( 'pending_at' => self::NOW - 700000 );
		$this->assertTrue( Diagnostics::should_cancel_pending( $old, array( 'last_delivery_at' => self::NOW - 600000 ), self::NOW ) );
		$this->assertFalse( Diagnostics::should_cancel_pending( $old, array( 'last_delivery_at' => self::NOW - 800000 ), self::NOW ), 'nothing arrived after the link' );
		$this->assertFalse( Diagnostics::should_cancel_pending( $old, array( 'last_delivery_at' => null ), self::NOW ) );
		$this->assertFalse( Diagnostics::should_cancel_pending( array( 'pending_at' => self::NOW - 8 * 3600 ), array( 'last_delivery_at' => self::NOW ), self::NOW ), 'eight hours, not eight days' );
	}

	/** The store URL changed: host, then form; a phone on the fallback is compared by host only. */
	public function test_url_changed() {
		$this->assertFalse( Diagnostics::url_changed( 'shop.example', 'wp-json', '', '' ) );
		$this->assertFalse( Diagnostics::url_changed( 'shop.example', 'wp-json', 'shop.example:443', 'wp-json' ) );
		$this->assertTrue( Diagnostics::url_changed( 'www.shop.example', 'wp-json', 'shop.example', 'wp-json' ) );
		$this->assertTrue( Diagnostics::url_changed( 'shop.example', 'rest_route', 'shop.example', 'wp-json' ) );
		$this->assertFalse( Diagnostics::url_changed( 'shop.example', 'wp-json', 'shop.example', 'wc-api' ) );
		$this->assertTrue( Diagnostics::url_changed( 'new.example', 'wp-json', 'shop.example', 'wc-api' ) );
		$this->assertFalse( Diagnostics::url_changed( 'Shop.Example', 'wp-json', 'shop.example', 'wp-json' ) );
	}

	/** Address forms from a query string. */
	public function test_form_of_query() {
		$this->assertSame( 'wp-json', Diagnostics::form_of_query( '' ) );
		$this->assertSame( 'rest_route', Diagnostics::form_of_query( 'rest_route=/qersh/v1/webhook/abc' ) );
		$this->assertSame( 'wc-api', Diagnostics::form_of_query( 'wc-api=qersh&qw=k7q2m9x4p1rz' ) );
	}

	/**
	 * One answer.
	 *
	 * @param int    $code    Status.
	 * @param string $body    Body.
	 * @param array  $headers Headers.
	 * @return array
	 */
	private function resp( int $code, string $body, array $headers = array() ): array {
		return array(
			'error'   => null,
			'code'    => $code,
			'headers' => $headers,
			'body'    => $body,
		);
	}

	/** What answered the self-test. */
	public function test_classify() {
		$json = array( 'content-type' => 'application/json; charset=UTF-8' );
		$this->assertSame( 'ok', Diagnostics::classify( $this->resp( 401, '{"code":"qersh_invalid_signature","message":"x","data":{"status":401}}', $json ), 'webhook' )['verdict'] );
		$this->assertSame( 'ok', Diagnostics::classify( $this->resp( 404, '{"code":"qersh_not_found"}', $json ), 'txn' )['verdict'] );
		$this->assertNotSame( 'ok', Diagnostics::classify( $this->resp( 404, '{"code":"qersh_not_found"}', $json ), 'webhook' )['verdict'] );

		$cf = Diagnostics::classify( $this->resp( 403, '<html>Just a moment</html>', array( 'cf-mitigated' => 'challenge', 'server' => 'cloudflare' ) ), 'webhook' );
		$this->assertSame( 'cloudflare', $cf['verdict'] );
		$this->assertSame( 'challenge', $cf['detail'] );

		$redirect = Diagnostics::classify( $this->resp( 301, '', array( 'location' => 'https://www.shop.example/wp-json/qersh/v1/webhook/k7q2m9x4p1rz' ) ), 'webhook' );
		$this->assertSame( 'redirect', $redirect['verdict'] );
		$this->assertStringContainsString( 'www.shop.example', $redirect['detail'] );

		$this->assertSame( 'html', Diagnostics::classify( $this->resp( 200, '<!doctype html><title>Shop</title>', array( 'content-type' => 'text/html' ) ), 'webhook' )['verdict'] );
		$this->assertSame( 'html', Diagnostics::classify( $this->resp( 404, '<html>Not found</html>' ), 'webhook' )['verdict'] );
		$this->assertSame( 'route_missing', Diagnostics::classify( $this->resp( 404, '{"code":"rest_no_route"}', $json ), 'webhook' )['verdict'] );

		$wordfence = Diagnostics::classify( $this->resp( 403, '<html>Generated by Wordfence</html>' ), 'webhook' );
		$this->assertSame( 'blocked', $wordfence['verdict'] );
		$this->assertSame( 'Wordfence', $wordfence['detail'] );
		$dra = Diagnostics::classify( $this->resp( 401, '{"code":"rest_cannot_access","message":"DRA: Only authenticated users can access the REST API."}', $json ), 'txn' );
		$this->assertSame( 'blocked', $dra['verdict'] );
		$this->assertSame( 'Disable REST API', $dra['detail'] );
		$this->assertSame( array( 'verdict' => 'blocked', 'detail' => '' ), Diagnostics::classify( $this->resp( 403, '<html>Forbidden</html>' ), 'webhook' ) );
		$this->assertSame( 'blocked', Diagnostics::classify( $this->resp( 403, '{"code":"rest_forbidden"}', $json ), 'webhook' )['verdict'] );

		$this->assertSame( 'maintenance', Diagnostics::classify( $this->resp( 503, '<html>Briefly unavailable for scheduled maintenance.</html>' ), 'webhook' )['verdict'] );
		$this->assertSame( 'unavailable', Diagnostics::classify( $this->resp( 503, '{"code":"qersh_unavailable"}', $json ), 'webhook' )['verdict'] );
		$this->assertSame( 'other_wallet', Diagnostics::classify( $this->resp( 404, '{"code":"qersh_unknown_wallet"}', $json ), 'webhook' )['verdict'] );
		$this->assertSame( array( 'verdict' => 'unexpected', 'detail' => '500' ), Diagnostics::classify( $this->resp( 500, '{"code":"x"}', $json ), 'webhook' ) );

		$tls = Diagnostics::classify(
			array(
				'error' => 'cURL error 60: SSL certificate problem: self-signed certificate',
				'code'  => 0,
			),
			'webhook'
		);
		$this->assertSame( 'tls', $tls['verdict'] );
		$this->assertSame(
			'inconclusive',
			Diagnostics::classify(
				array(
					'error' => 'cURL error 7: Failed to connect',
					'code'  => 0,
				),
				'webhook'
			)['verdict']
		);
	}

	/** This PHP knows Egypt's summer time. */
	public function test_tzdata_ok() {
		$this->assertTrue( Diagnostics::tzdata_ok() );
	}

	/** The drift record, in the shapes the webhook and the order page write. */
	public function test_drift_tail() {
		$this->assertNull( Diagnostics::drift_tail( null ) );
		$this->assertNull( Diagnostics::drift_tail( array() ) );
		$this->assertSame( '234', Diagnostics::drift_tail( array( 'orange_cash' => array( 'tail' => '0994001234' ) ) ) );
		$this->assertSame( '777', Diagnostics::drift_tail( array( 'last3' => '777' ) ) );
		$this->assertSame( '***', Diagnostics::drift_tail( array( 'at' => 5 ) ) );
	}

	/** An Orange transaction id that the pattern refuses is a drift; a good one, or another wallet type, is not. */
	public function test_drift_of() {
		$bad = $this->envelope( 'transfer.received', array( 'provider_txn_id' => '12345678901' ) );
		$this->assertSame( '901', Diagnostics::drift_of( $bad, 'orange_cash' ) );
		$this->assertNull( Diagnostics::drift_of( $bad, 'vodafone_cash' ) );
		$this->assertNull( Diagnostics::drift_of( $this->envelope( 'transfer.received', array( 'provider_txn_id' => '12345678901234' ) ), 'orange_cash' ) );
		$this->assertNull( Diagnostics::drift_of( $this->envelope( 'transfer.received', array( 'provider_txn_id' => '1234567890' ) ), 'orange_cash' ) );
		$this->assertNull( Diagnostics::drift_of( $this->envelope( 'transfer.received', array( 'provider_txn_id' => null ) ), 'orange_cash' ) );
		$this->assertNull( Diagnostics::drift_of( $this->envelope( 'webhook.test', array( 'provider_txn_id' => '12345678901' ) ), 'orange_cash' ) );
	}

	/** A wallet's alert codes together. */
	public function test_wallet_alert_codes() {
		$codes = Diagnostics::wallet_alert_codes(
			array(
				'provider'       => 'vodafone_cash',
				'row'            => array(
					'last_device_state' => array(
						'sms' => false,
						'nk'  => 1,
					),
					'last_heartbeat_at' => self::NOW - 3000,
					'last_delivery_at'  => self::NOW - 3000,
					'sender_masked'     => 1,
					'seen_host'         => 'old.example',
					'seen_form'         => 'wp-json',
				),
				'fp'             => array( 'pending_at' => self::NOW - 100000 ),
				'key_state'      => 'unreadable',
				'now'            => self::NOW,
				'cur_host'       => 'shop.example',
				'cur_form'       => 'wp-json',
				'orders_waiting' => true,
			)
		);
		$this->assertSame(
			array( 'key_unreadable', 'device_sms', 'silent_suspect', 'masked', 'url_changed', 'newer_key', 'pending_waiting' ),
			array_column( $codes, 'code' )
		);
		$this->assertSame( 'silent_suspect:' . ( self::NOW - 3000 ), $codes[2]['id'] );
		$this->assertSame( array(), Diagnostics::wallet_alert_codes( array( 'provider' => 'vodafone_cash', 'now' => self::NOW ) ) );
	}

	/** Hosts for comparison. */
	public function test_normal_host() {
		$this->assertSame( 'shop.example', Diagnostics::normal_host( ' Shop.Example:443 ' ) );
		$this->assertSame( 'shop.example:8080', Diagnostics::normal_host( 'shop.example:8080' ) );
	}
}
