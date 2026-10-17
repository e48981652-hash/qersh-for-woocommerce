<?php
/**
 * Tests of Logger redaction (CONTRACTS.md §1 Logs, docs/17 §4.1 Logger row).
 *
 * @package QershPay\WooCommerce\Tests
 */

declare( strict_types=1 );

namespace QershPay\WooCommerce\Tests\Unit;

use QershPay\WooCommerce\Logger;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

defined( 'ABSPATH' ) || exit;

/**
 * Logger::redact(), Logger::redact_context() and Logger::tail(); logging itself needs WooCommerce.
 */
final class LoggerTest extends TestCase {

	/** Connection keys, in any form the plugin may meet them, never reach the log. */
	public function test_keys() {
		$key = 'qsk_' . str_repeat( 'Ab9-_', 8 ) . 'xyz';
		$this->assertSame( 'pasted qsk_[redacted] here', Logger::redact( 'pasted ' . $key . ' here' ) );
		$this->assertSame( 'qersh://setup?v=1&url=x&secret=[redacted]&label=a', Logger::redact( 'qersh://setup?v=1&url=x&secret=' . rawurlencode( 'my-own-long-secret-value-123' ) . '&label=a' ) );
		$this->assertSame( 'QERSH_WEBHOOK_SECRET=[redacted]', Logger::redact( 'QERSH_WEBHOOK_SECRET=my-own-long-secret-value-123' ) );
	}

	/** Signatures, tokens and hashes (32 hex characters or more) are replaced. */
	public function test_hex() {
		$sig = str_repeat( 'a1b2c3d4', 8 );
		$this->assertSame( 't=***000,v1=[hex]', Logger::redact( 't=1760000000,v1=' . $sig ) );
		$this->assertSame( 'token [hex]', Logger::redact( 'token ' . str_repeat( 'f', 32 ) ) );
		$this->assertSame( 'short abcdef12', Logger::redact( 'short abcdef12' ) );
	}

	/** Phone numbers keep their last three digits, in every spelling. */
	public function test_phones() {
		$cases = array(
			'from 01001234567'        => 'from ***567',
			'from +201001234567.'     => 'from ***567.',
			'from +20 100 123 4567'   => 'from ***567',
			'from 0020-100-123-4567'  => 'from ***567',
			'من ٠١٠٠١٢٣٤٥٦٧'          => 'من ***٥٦٧',
			'من۰۱۰۰۱۲۳۴۵۶۷'           => 'من***۵۶۷',
			'رقم المحوِّل 01001234567' => 'رقم المحوِّل ***567',
		);
		foreach ( $cases as $in => $out ) {
			$this->assertSame( $out, Logger::redact( $in ), $in );
		}
	}

	/** Transaction ids and other long digit runs keep their last three digits; short numbers stay. */
	public function test_digit_runs() {
		$this->assertSame( 'txn ***234', Logger::redact( 'txn 12345678901234' ) );
		$this->assertSame( 'txn ***890', Logger::redact( 'txn 1234567890' ) );
		$this->assertSame( 'landline ***678', Logger::redact( 'landline 12345678' ) );
		$this->assertSame( 'order #10423 for 150000 piasters on 2026-10-17T12:30:45Z', Logger::redact( 'order #10423 for 150000 piasters on 2026-10-17T12:30:45Z' ) );
	}

	/**
	 * A number that touches a letter or an underscore is masked whole: never a shorter match that leaves a fourth
	 * digit (or the first digits) visible.
	 */
	public function test_numbers_touching_letters() {
		$cases = array(
			'01001234567abc'     => '***567abc',
			'x01001234567'       => 'x***567',
			'payer_01001234567'  => 'payer_***567',
			'txn_12345678901234' => 'txn_***234',
			'id12345678x'        => 'id***678x',
			'a+201001234567b'    => 'a***567b',
			'رقم01001234567ج'    => 'رقم***567ج',
		);
		foreach ( $cases as $in => $out ) {
			$this->assertSame( $out, Logger::redact( $in ), $in );
		}
		// Seven digits stay, whatever touches them, and so do ids that are not personal.
		$this->assertSame( 'ab1234567cd', Logger::redact( 'ab1234567cd' ) );
		$this->assertSame( 'x12345678-1234-4234-9234-123456789012', Logger::redact( 'x12345678-1234-4234-9234-123456789012' ) );
		$this->assertSame( 'id=trf_01J9Z8Y7X6W5V4T3S2R1Q0P9N8', Logger::redact( 'id=trf_01J9Z8Y7X6W5V4T3S2R1Q0P9N8' ) );
	}

	/** Ids that are not personal stay readable. */
	public function test_ids_kept() {
		$text = 'delivery 12345678-1234-4234-9234-123456789012 transfer trf_01J9Z8Y7X6W5V4T3S2R1Q0P9N8 wallet k7q2m9x4p1rz';
		$this->assertSame( $text, Logger::redact( $text ) );
	}

	/** InstaPay handles keep their last three characters, e-mail addresses only their domain. */
	public function test_handles_and_mail() {
		$this->assertSame( 'payer ***med@instapay', Logger::redact( 'payer ahmed@instapay' ) );
		$this->assertSame( 'mail ***@example.com', Logger::redact( 'mail someone.name@example.com' ) );
	}

	/** Context: secret names dropped, payer-like names reduced to the tail, the rest redacted as text. */
	public function test_context() {
		$out = Logger::redact_context(
			array(
				'key'         => 'qsk_abc',
				'body'        => '{"a":1}',
				'payer_name'  => 'أحمد علي',
				'payer_key'   => '01001234567',
				'claim_txn'   => '12345678901234',
				'handle'      => 'ahmed@instapay',
				'order_id'    => 10423,
				'reason'      => 'from 01001234567',
				'nested'      => array( 'signature' => 'v1=abc' ),
				'ok'          => true,
				'source'      => 'other',
			)
		);
		$this->assertSame( '[redacted]', $out['key'] );
		$this->assertSame( '[redacted]', $out['body'] );
		$this->assertSame( '[redacted]', $out['payer_name'] );
		$this->assertSame( '***567', $out['payer_key'] );
		$this->assertSame( '***234', $out['claim_txn'] );
		$this->assertSame( '***med@instapay', $out['handle'] );
		$this->assertSame( 10423, $out['order_id'] );
		$this->assertSame( 'from ***567', $out['reason'] );
		$this->assertSame( array( 'signature' => '[redacted]' ), $out['nested'] );
		$this->assertTrue( $out['ok'] );
	}

	/** tail() counts characters, not bytes. */
	public function test_tail() {
		$this->assertSame( '567', Logger::tail( '01001234567' ) );
		$this->assertSame( '٥٦٧', Logger::tail( '٠١٠٠١٢٣٤٥٦٧' ) );
		$this->assertSame( 'ab', Logger::tail( 'ab' ) );
	}

	/** Without WooCommerce, logging does nothing and never throws. */
	public function test_without_woocommerce() {
		$logger = new Logger();
		$logger->info( 'hello 01001234567' );
		$logger->warning( 'hello' );
		$logger->error( 'hello' );
		$this->assertFalse( function_exists( 'wc_get_logger' ) );
	}
}
