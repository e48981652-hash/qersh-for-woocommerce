<?php
/**
 * Tests of the pure rules of Diagnostics (docs/17 §4.9): what a delivery writes, the phone's state, the silent
 * phone, the pending key, the store URL change, the self-test classifier and the transaction-id box. None of them
 * reads a wallet type.
 *
 * @package QershPay\WooCommerce\Tests
 */

declare( strict_types=1 );

namespace QershPay\WooCommerce\Tests\Unit;

use QershPay\WooCommerce\Diagnostics;
use QershPay\WooCommerce\Plugin;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

defined( 'ABSPATH' ) || exit;

/**
 * Diagnostics, without WordPress.
 */
final class DiagnosticsTest extends TestCase {

	const NOW = 1792000000;

	/**
	 * Wallet types the envelope may carry: the rules must answer the same for each.
	 */
	const WALLET_TYPES = array( 'vodafone_cash', 'orange_cash', 'etisalat_cash', 'a_type_never_heard_of', '' );

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
				'wallet'       => array(
					'id'            => 'wal_01JA2Y4X5M7Q9R0S1T2U3V4W5Z',
					'provider'      => 'vodafone_cash',
					'label'         => 'Main',
					'number_masked' => '0100****567',
					'slot'          => 0,
				),
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

	/**
	 * The same envelope with another wallet type.
	 *
	 * @param array  $envelope Envelope.
	 * @param string $provider `wallet.provider`.
	 * @return array
	 */
	private function with_type( array $envelope, string $provider ): array {
		$envelope['wallet']['provider'] = $provider;
		return $envelope;
	}

	/** Every delivery: time, event, host and form, the device state, and the end of a silent episode. */
	public function test_delivery_fields_common(): void {
		$f = Diagnostics::delivery_fields( $this->envelope( 'transfer.updated' ), 'rest_route', 'shop.example:8443', self::NOW );
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
	public function test_delivery_fields_bad_form(): void {
		$f = Diagnostics::delivery_fields( $this->envelope( 'webhook.test' ), 'other', '', self::NOW );
		$this->assertArrayNotHasKey( 'seen_form', $f );
		$this->assertArrayNotHasKey( 'seen_host', $f );
	}

	/** A heartbeat writes last_heartbeat_at; grace_until comes from the subscription. */
	public function test_delivery_fields_heartbeat_and_grace(): void {
		$env = $this->envelope( 'device.heartbeat', array( 'seq' => 1 ), array( 'subscription' => array( 'grace_until' => '2026-10-20T10:00:00Z' ) ) );
		$f   = Diagnostics::delivery_fields( $env, 'wp-json', 'shop.example', self::NOW );
		$this->assertSame( self::NOW, $f['last_heartbeat_at'] );
		$this->assertSame( 1792490400, $f['grace_until'] );
	}

	/** transfer.received: masked sender and auto_seen, from the counterparty alone. */
	public function test_delivery_fields_transfer(): void {
		$masked = Diagnostics::delivery_fields(
			$this->envelope(
				'transfer.received',
				array(
					'counterparty'    => array( 'number' => '0111***3445' ),
					'auto_confirm_ok' => true,
				)
			),
			'wp-json',
			'shop.example',
			self::NOW
		);
		$this->assertSame( 1, $masked['sender_masked'] );
		$this->assertSame( 1, $masked['auto_seen'] );

		$full = Diagnostics::delivery_fields( $this->envelope( 'transfer.received', array( 'counterparty' => array( 'number' => '01112233445' ) ) ), 'wp-json', 'shop.example', self::NOW );
		$this->assertSame( 0, $full['sender_masked'] );
		$this->assertArrayNotHasKey( 'auto_seen', $full );

		$name = Diagnostics::delivery_fields( $this->envelope( 'transfer.received', array( 'counterparty' => array( 'name' => 'Sample Payer' ) ) ), 'wp-json', 'shop.example', self::NOW );
		$this->assertArrayNotHasKey( 'sender_masked', $name, 'a name only leaves the flag as it is' );

		$nothing = Diagnostics::delivery_fields( $this->envelope( 'transfer.received', array( 'counterparty' => array() ) ), 'wp-json', 'shop.example', self::NOW );
		$this->assertSame( 1, $nothing['sender_masked'], 'no sender details at all' );

		$updated = Diagnostics::delivery_fields( $this->envelope( 'transfer.updated', array( 'counterparty' => array( 'number' => '0111***3445' ) ) ), 'wp-json', 'shop.example', self::NOW );
		$this->assertArrayNotHasKey( 'sender_masked', $updated, 'only transfer.received counts' );
	}

	/**
	 * The wallet type of the envelope changes nothing a delivery writes: the same delivery gives the same columns
	 * with every type, a type never heard of, or none.
	 */
	public function test_delivery_fields_ignore_the_wallet_type(): void {
		$deliveries = array(
			$this->envelope( 'transfer.received', array( 'counterparty' => array( 'number' => '0111***3445' ) ) ),
			$this->envelope( 'transfer.received', array( 'counterparty' => array( 'name' => 'Sample Payer' ) ) ),
			$this->envelope( 'transfer.received', array( 'counterparty' => array() ) ),
			$this->envelope(
				'transfer.received',
				array(
					'counterparty'    => array( 'number' => '01001234567' ),
					'provider_txn_id' => '250046173830512',
					'auto_confirm_ok' => true,
				)
			),
			$this->envelope( 'device.heartbeat' ),
			$this->envelope( 'webhook.test' ),
		);
		foreach ( $deliveries as $i => $envelope ) {
			$expected = Diagnostics::delivery_fields( $envelope, 'wp-json', 'shop.example', self::NOW );
			foreach ( self::WALLET_TYPES as $type ) {
				$this->assertSame( $expected, Diagnostics::delivery_fields( $this->with_type( $envelope, $type ), 'wp-json', 'shop.example', self::NOW ), $i . ': ' . $type );
			}
			$without = $envelope;
			unset( $without['wallet']['provider'] );
			$this->assertSame( $expected, Diagnostics::delivery_fields( $without, 'wp-json', 'shop.example', self::NOW ), $i . ': no type' );
		}
	}

	/**
	 * A wallet bound to no SIM (`wallet.slot` null: the merchant chose "Not bound to a SIM", or the phone lists no
	 * SIM) is still read from its text messages: the stored state is the same as for a bound wallet, and a revoked
	 * permission to read messages raises device_sms, whatever the notification access.
	 */
	public function test_unbound_wallet_needs_messages(): void {
		$state                     = array(
			'sms_permission'      => false,
			'notification_access' => false,
		);
		$bound                     = $this->envelope( 'device.heartbeat' );
		$bound['device']['state']  = $state;
		$unbound                   = $bound;
		$unbound['wallet']['slot'] = null;
		$no_slot                   = $bound;
		unset( $no_slot['wallet']['slot'] );

		$expected = Diagnostics::delivery_fields( $bound, 'wp-json', 'shop.example', self::NOW )['last_device_state'];
		$this->assertSame(
			array(
				'sms'   => false,
				'notif' => false,
				'v'     => '0.4.6',
			),
			$expected
		);
		$this->assertSame( $expected, Diagnostics::delivery_fields( $unbound, 'wp-json', 'shop.example', self::NOW )['last_device_state'], 'slot null' );
		$this->assertSame( $expected, Diagnostics::delivery_fields( $no_slot, 'wp-json', 'shop.example', self::NOW )['last_device_state'], 'no slot key' );
		$this->assertSame( array( 'device_sms' ), Diagnostics::device_alerts( $expected ) );

		$granted                                      = $unbound;
		$granted['device']['state']['sms_permission'] = true;
		$this->assertSame( array(), Diagnostics::device_alerts( Diagnostics::delivery_fields( $granted, 'wp-json', 'shop.example', self::NOW )['last_device_state'] ), 'messages granted, notification access off: no alert' );

		$no_device = $unbound;
		unset( $no_device['device'] );
		$this->assertArrayNotHasKey( 'last_device_state', Diagnostics::delivery_fields( $no_device, 'wp-json', 'shop.example', self::NOW ), 'nothing to store without a device' );
	}

	/** The newer-key marker comes from the Verifier's v1_index. */
	public function test_device_state_newer_key(): void {
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

	/** Masked sender rule: read from the counterparty alone. */
	public function test_sender_masked_value(): void {
		$this->assertSame( 1, Diagnostics::sender_masked_value( array( 'number' => '0111***445' ) ) );
		$this->assertSame( 1, Diagnostics::sender_masked_value( array( 'number' => '*' ) ) );
		$this->assertSame( 1, Diagnostics::sender_masked_value( array() ), 'no sender details at all' );
		$this->assertSame( 1, Diagnostics::sender_masked_value( null ) );
		$this->assertSame(
			1,
			Diagnostics::sender_masked_value(
				array(
					'number' => null,
					'name'   => ' ',
					'handle' => null,
				)
			)
		);
		$this->assertNull( Diagnostics::sender_masked_value( array( 'name' => 'Sample Payer' ) ), 'a name says nothing about the number' );
		$this->assertNull( Diagnostics::sender_masked_value( array( 'handle' => 'sample.payer' ) ), 'a handle is no mobile number' );
		$this->assertNull( Diagnostics::sender_masked_value( array( 'number' => '0100123' ) ), 'a number that does not read' );
		$this->assertSame( 0, Diagnostics::sender_masked_value( array( 'number' => '+20 111 223 3445' ) ) );
		$this->assertSame(
			0,
			Diagnostics::sender_masked_value(
				array(
					'number' => '٠١٠٠١٢٣٤٥٦٧',
					'name'   => 'Sample Payer',
				)
			),
			'Arabic-Indic digits'
		);
	}

	/**
	 * Phone state alerts, only for keys the envelope carried; battery optimisation alone is no alert. Every wallet
	 * needs the permission to read messages, and none the notification access.
	 */
	public function test_device_alerts(): void {
		$this->assertSame( array( 'device_sms' ), Diagnostics::device_alerts( array( 'sms' => false ) ) );
		$this->assertSame( array(), Diagnostics::device_alerts( array( 'notif' => false ) ), 'a wallet read from messages needs no notification access' );
		$this->assertSame(
			array( 'device_sms', 'device_bg' ),
			Diagnostics::device_alerts(
				array(
					'sms'   => false,
					'notif' => false,
					'bg'    => true,
				)
			)
		);
		$this->assertSame(
			array( 'device_sms' ),
			Diagnostics::device_alerts(
				array(
					'sms'   => false,
					'notif' => true,
					'ns'    => 1,
				)
			),
			'messages revoked while the notification access is granted; an `ns` of an earlier test build changes nothing'
		);
		$this->assertSame(
			array(),
			Diagnostics::device_alerts(
				array(
					'sms'   => true,
					'notif' => false,
					'ns'    => 1,
				)
			),
			'messages granted, notification access off: no alert'
		);
		$this->assertSame( array( 'device_bg' ), Diagnostics::device_alerts( array( 'bg' => true ) ) );
		$this->assertSame( array(), Diagnostics::device_alerts( array( 'batt' => true ) ) );
		$this->assertSame( array(), Diagnostics::device_alerts( array( 'sms' => true ) ) );
		$this->assertSame( array(), Diagnostics::device_alerts( null ) );
		$this->assertSame( array(), Diagnostics::device_alerts( 'sms' ) );
	}

	/** The silent phone: heartbeat wallets only, 35 minutes with an order waiting, two hours = down. */
	public function test_silent_state(): void {
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
	public function test_pending_key(): void {
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
	public function test_url_changed(): void {
		$this->assertFalse( Diagnostics::url_changed( 'shop.example', 'wp-json', '', '' ) );
		$this->assertFalse( Diagnostics::url_changed( 'shop.example', 'wp-json', 'shop.example:443', 'wp-json' ) );
		$this->assertTrue( Diagnostics::url_changed( 'www.shop.example', 'wp-json', 'shop.example', 'wp-json' ) );
		$this->assertTrue( Diagnostics::url_changed( 'shop.example', 'rest_route', 'shop.example', 'wp-json' ) );
		$this->assertFalse( Diagnostics::url_changed( 'shop.example', 'wp-json', 'shop.example', 'wc-api' ) );
		$this->assertTrue( Diagnostics::url_changed( 'new.example', 'wp-json', 'shop.example', 'wc-api' ) );
		$this->assertFalse( Diagnostics::url_changed( 'Shop.Example', 'wp-json', 'shop.example', 'wp-json' ) );
	}

	/** Address forms from a query string. */
	public function test_form_of_query(): void {
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
	public function test_classify(): void {
		$json = array( 'content-type' => 'application/json; charset=UTF-8' );
		$this->assertSame( 'ok', Diagnostics::classify( $this->resp( 401, '{"code":"qersh_invalid_signature","message":"x","data":{"status":401}}', $json ), 'webhook' )['verdict'] );
		$this->assertSame( 'ok', Diagnostics::classify( $this->resp( 404, '{"code":"qersh_not_found"}', $json ), 'txn' )['verdict'] );
		$this->assertNotSame( 'ok', Diagnostics::classify( $this->resp( 404, '{"code":"qersh_not_found"}', $json ), 'webhook' )['verdict'] );

		$cf = Diagnostics::classify(
			$this->resp(
				403,
				'<html>Just a moment</html>',
				array(
					'cf-mitigated' => 'challenge',
					'server'       => 'cloudflare',
				)
			),
			'webhook'
		);
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
		$this->assertSame(
			array(
				'verdict' => 'blocked',
				'detail'  => '',
			),
			Diagnostics::classify( $this->resp( 403, '<html>Forbidden</html>' ), 'webhook' )
		);
		$this->assertSame( 'blocked', Diagnostics::classify( $this->resp( 403, '{"code":"rest_forbidden"}', $json ), 'webhook' )['verdict'] );

		$this->assertSame( 'maintenance', Diagnostics::classify( $this->resp( 503, '<html>Briefly unavailable for scheduled maintenance.</html>' ), 'webhook' )['verdict'] );
		$this->assertSame( 'unavailable', Diagnostics::classify( $this->resp( 503, '{"code":"qersh_unavailable"}', $json ), 'webhook' )['verdict'] );
		$this->assertSame( 'other_wallet', Diagnostics::classify( $this->resp( 404, '{"code":"qersh_unknown_wallet"}', $json ), 'webhook' )['verdict'] );
		$this->assertSame(
			array(
				'verdict' => 'unexpected',
				'detail'  => '500',
			),
			Diagnostics::classify( $this->resp( 500, '{"code":"x"}', $json ), 'webhook' )
		);

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
	public function test_tzdata_ok(): void {
		$this->assertTrue( Diagnostics::tzdata_ok() );
	}

	/**
	 * The transaction-id format drift of 0.1.0 is gone (docs/17 §4.9): no option, no alert and no rule hides the
	 * transaction-id box any more; checking an operator's id shape is the Qersh app's job.
	 */
	public function test_no_transaction_id_drift(): void {
		foreach ( array( 'DRIFT_OPTION', 'TXN_LONG_OPTION', 'TXN_LONG_ORDERS', 'TXN_LONG_WINDOW', 'TXN_LONG_KEEP', 'NOTIFICATION_PROVIDERS', 'NUMBER_PROVIDERS' ) as $constant ) {
			$this->assertFalse( defined( Diagnostics::class . '::' . $constant ), $constant );
		}
		foreach ( array( 'drift_of', 'drift_tail', 'record_txn_refusal', 'long_run_ok', 'txn_long_tally', 'has_provider' ) as $method ) {
			$this->assertFalse( method_exists( Diagnostics::class, $method ), $method );
		}
	}

	/** A wallet's alert codes together. */
	public function test_wallet_alert_codes(): void {
		$codes = Diagnostics::wallet_alert_codes(
			array(
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
		$this->assertSame(
			array(),
			Diagnostics::wallet_alert_codes(
				array(
					'now' => self::NOW,
				)
			)
		);

		$unbound = Diagnostics::wallet_alert_codes(
			array(
				'provider' => 'vodafone_cash', // A context key of 0.1.0: ignored.
				'row'      => array(
					'last_device_state' => array(
						'sms'   => false,
						'notif' => false,
						'ns'    => 1,
					),
				),
				'now'      => self::NOW,
			)
		);
		$this->assertSame( array( 'device_sms' ), array_column( $unbound, 'code' ) );
	}

	/**
	 * The transaction-id box rule (docs/17 §4.10): the store setting and a wallet entry in the configuration,
	 * enabled or not, whatever the wallet; a legacy `provider` of the entry changes nothing.
	 */
	public function test_txn_entry_allowed(): void {
		$config = array(
			'store'   => array( 'txn_entry' => true ),
			'wallets' => array(
				array(
					'id'              => 'k7q2m9x4p1rz',
					'label'           => 'Main',
					'receive_address' => '01001234567',
					'enabled'         => true,
				),
				array(
					'id'              => 'a1b2c3d4e5f6',
					'label'           => '',
					'receive_address' => '01112223344',
					'enabled'         => false,
				),
				array(
					'id'              => 'z9y8x7w6v5u4',
					'provider'        => 'vodafone_cash',
					'receive_address' => '01223334455',
					'enabled'         => true,
				),
			),
		);
		$this->assertTrue( Diagnostics::txn_entry_allowed( $config, 'k7q2m9x4p1rz' ) );
		$this->assertTrue( Diagnostics::txn_entry_allowed( $config, 'a1b2c3d4e5f6' ), 'a wallet turned off keeps the box of its orders' );
		$this->assertTrue( Diagnostics::txn_entry_allowed( $config, 'z9y8x7w6v5u4' ), 'whatever type an old entry names' );
		$this->assertFalse( Diagnostics::txn_entry_allowed( $config, 'q1w2e3r4t5y6' ), 'a deleted wallet has no entry' );
		$this->assertFalse( Diagnostics::txn_entry_allowed( $config, '' ) );
		$this->assertFalse( Diagnostics::txn_entry_allowed( $config, 'orange_cash' ), 'a wallet type is no wallet id' );

		$off                       = $config;
		$off['store']['txn_entry'] = false;
		$this->assertFalse( Diagnostics::txn_entry_allowed( $off, 'k7q2m9x4p1rz' ), 'the setting is off' );
		unset( $off['store'] );
		$this->assertFalse( Diagnostics::txn_entry_allowed( $off, 'k7q2m9x4p1rz' ) );
		$this->assertFalse( Diagnostics::txn_entry_allowed( array( 'store' => array( 'txn_entry' => true ) ), 'k7q2m9x4p1rz' ) );
	}

	/**
	 * txn_entry_open() reads the effective configuration of the plugin, and is false without the plugin.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_txn_entry_open(): void {
		$this->assertFalse( ( new Diagnostics() )->txn_entry_open( 'k7q2m9x4p1rz' ) );

		$plugin = Plugin::instance();
		$cached = new \ReflectionProperty( Plugin::class, 'config' );
		$cached->setAccessible( true );
		$cached->setValue(
			$plugin,
			array(
				'store'   => array( 'txn_entry' => true ),
				'wallets' => array(
					array(
						'id'              => 'k7q2m9x4p1rz',
						'label'           => 'Main',
						'receive_address' => '01001234567',
						'enabled'         => true,
					),
				),
			)
		);
		$diagnostics = new Diagnostics( $plugin );
		$this->assertTrue( $diagnostics->txn_entry_open( 'k7q2m9x4p1rz' ) );
		$this->assertFalse( $diagnostics->txn_entry_open( 'a1b2c3d4e5f6' ) );
	}

	/** Hosts for comparison. */
	public function test_normal_host(): void {
		$this->assertSame( 'shop.example', Diagnostics::normal_host( ' Shop.Example:443 ' ) );
		$this->assertSame( 'shop.example:8080', Diagnostics::normal_host( 'shop.example:8080' ) );
	}
}
