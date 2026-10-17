<?php
/**
 * Unit tests of Core\Envelope: the envelope cases of vectors.test.ts and the cases of summarize.test.ts in
 * packages/qersh-webhook, plus the PHP-specific edges (byte order marks, WordPress header names, JSON types).
 *
 * @package QershPay\WooCommerce\Tests
 */

declare( strict_types=1 );

namespace QershPay\WooCommerce\Tests\Unit\Core;

defined( 'ABSPATH' ) || exit;

use QershPay\WooCommerce\Core\Envelope;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Envelope tests. Arabic text is read from fixtures/test-vectors.json, never retyped.
 */
final class EnvelopeTest extends TestCase {

	/**
	 * The decoded kit vectors.
	 *
	 * @var array|null
	 */
	private static $vectors = null;

	/**
	 * One vector case by name.
	 *
	 * @param string $name Case name.
	 * @return array
	 */
	private static function vector( string $name ): array {
		if ( null === self::$vectors ) {
			self::$vectors = json_decode( qersh_wc_test_fixture( 'fixtures/test-vectors.json' ), true );
		}
		foreach ( self::$vectors['cases'] as $case ) {
			if ( $name === $case['name'] ) {
				return $case;
			}
		}
		throw new \RuntimeException( 'no vector ' . $name ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- unit test without WordPress (no esc_html()): the message only reaches the PHPUnit report.
	}

	/**
	 * Parses and asserts success.
	 *
	 * @param string $raw Body.
	 * @return array The envelope.
	 */
	private function parsed( string $raw ): array {
		$result = Envelope::parse( $raw );
		$this->assertTrue( $result['ok'], (string) json_encode( $result ) );
		$this->assertSame( array( 'ok', 'envelope' ), array_keys( $result ) );
		return $result['envelope'];
	}

	/**
	 * A minimal valid envelope with the given data, as JSON.
	 *
	 * @param string $event     Event.
	 * @param string $data_json `data` as JSON text.
	 * @return string
	 */
	private static function body( string $event, string $data_json ): string {
		return '{"schema":"qersh.v1","event":"' . $event . '","delivery_id":"d1","created_at":"2026-10-11T16:40:00+03:00","wallet":{"id":"wlt_x","provider":"vodafone_cash","number_masked":"0100****776"},"data":' . $data_json . '}';
	}

	/**
	 * Vectors.test.ts: the valid bodies parse as qersh.v1 envelopes; the webhook.test sample sits under data.sample.
	 */
	public function test_the_valid_bodies_parse(): void {
		$received = $this->parsed( self::vector( 'valid_transfer_received' )['body'] );
		$this->assertSame( 'transfer.received', $received['event'] );
		$this->assertSame( json_decode( self::vector( 'valid_transfer_received' )['body'], true ), $received );
		$test = $this->parsed( self::vector( 'valid_webhook_test' )['body'] );
		$this->assertTrue( $test['test'] );
		$this->assertTrue( Envelope::is_record( $test['data']['sample'] ) );
		$this->assertSame(
			array(
				'ok'     => false,
				'reason' => 'invalid_json',
			),
			Envelope::parse( 'not json' )
		);
		$this->assertSame(
			array(
				'ok'     => false,
				'reason' => 'not_an_object',
			),
			Envelope::parse( '[]' )
		);
		$this->assertSame(
			array(
				'ok'     => false,
				'reason' => 'not_qersh_v1',
			),
			Envelope::parse( '{"schema":"other"}' )
		);
	}

	/**
	 * ParseEnvelope: what counts as invalid JSON, as not an object, and as not qersh.v1.
	 */
	public function test_refusals_follow_the_json_types(): void {
		$invalid = array(
			'ok'     => false,
			'reason' => 'invalid_json',
		);
		$object  = array(
			'ok'     => false,
			'reason' => 'not_an_object',
		);
		$schema  = array(
			'ok'     => false,
			'reason' => 'not_qersh_v1',
		);
		$this->assertSame( $invalid, Envelope::parse( null ) );
		$this->assertSame( $invalid, Envelope::parse( '' ) );
		$this->assertSame( $invalid, Envelope::parse( '   ' ) );
		$this->assertSame( $invalid, Envelope::parse( '{"schema":"qersh.v1",}' ) );
		$this->assertSame( $invalid, Envelope::parse( "{'schema':'qersh.v1'}" ) );
		$this->assertSame( $object, Envelope::parse( 'null' ) );
		$this->assertSame( $object, Envelope::parse( '5' ) );
		$this->assertSame( $object, Envelope::parse( '"qersh.v1"' ) );
		$this->assertSame( $object, Envelope::parse( 'true' ) );
		$this->assertSame( $object, Envelope::parse( ' [ {"schema":"qersh.v1"} ] ' ) );
		$this->assertSame( $schema, Envelope::parse( '{}' ) );
		$this->assertTrue( Envelope::parse( self::body( 'transfer.received', '{}' ) )['ok'] );
		$this->assertTrue( Envelope::parse( " \r\n\t" . self::body( 'transfer.received', '{"a":1}' ) . "\n" )['ok'] );
		// `data` and `wallet` must be JSON objects, not arrays, even empty ones.
		$this->assertSame( $schema, Envelope::parse( self::body( 'transfer.received', '[]' ) ) );
		$this->assertSame( $schema, Envelope::parse( self::body( 'transfer.received', '[1]' ) ) );
		$this->assertSame( $schema, Envelope::parse( self::body( 'transfer.received', 'null' ) ) );
		$this->assertSame( $schema, Envelope::parse( self::body( 'transfer.received', '"x"' ) ) );
		$this->assertSame( $schema, Envelope::parse( '{"schema":"qersh.v1","event":"e","delivery_id":"d","created_at":"c","wallet":[],"data":{}}' ) );
		$this->assertSame( $schema, Envelope::parse( '{"schema":"qersh.v1","event":"e","delivery_id":"d","created_at":"c","wallet":{"id":"w"},"data":{}}' ) );
		$this->assertSame( $schema, Envelope::parse( '{"schema":"qersh.v1","event":"e","delivery_id":"d","created_at":"c","wallet":{"id":5,"provider":"p"},"data":{}}' ) );
		$this->assertSame( $schema, Envelope::parse( '{"schema":"qersh.v1","event":5,"delivery_id":"d","created_at":"c","wallet":{"id":"w","provider":"p"},"data":{}}' ) );
		$this->assertSame( $schema, Envelope::parse( '{"schema":"qersh.v1","event":"e","delivery_id":null,"created_at":"c","wallet":{"id":"w","provider":"p"},"data":{}}' ) );
		$this->assertSame( $schema, Envelope::parse( '{"schema":"qersh.v1","event":"e","delivery_id":"d","wallet":{"id":"w","provider":"p"},"data":{}}' ) );
		$this->assertSame( $schema, Envelope::parse( '{"schema":"QERSH.V1","event":"e","delivery_id":"d","created_at":"c","wallet":{"id":"w","provider":"p"},"data":{}}' ) );
		$this->assertTrue( Envelope::parse( '{"schema":"qersh.v1","event":"e","delivery_id":"d","created_at":"c","wallet":{"id":"w","provider":"p"},"data":{}}' )['ok'] );
		// Duplicate names: the last one wins, as in JSON.parse.
		$this->assertTrue( Envelope::parse( '{"schema":"other","schema":"qersh.v1","event":"e","delivery_id":"d","created_at":"c","wallet":{"id":"w","provider":"p"},"data":{}}' )['ok'] );
		$this->assertSame( $schema, Envelope::parse( '{"schema":"qersh.v1","schema":"other","event":"e","delivery_id":"d","created_at":"c","wallet":{"id":"w","provider":"p"},"data":{}}' ) );
	}

	/**
	 * ParseEnvelope on bytes: the UTF-8 decoder drops one byte order mark, parseEnvelope one more U+FEFF.
	 */
	public function test_byte_order_marks(): void {
		$body = self::vector( 'valid_transfer_received' )['body'];
		$bom  = "\xEF\xBB\xBF";
		$this->assertSame( $this->parsed( $body ), $this->parsed( $bom . $body ) );
		$this->assertSame( $this->parsed( $body ), $this->parsed( $bom . $bom . $body ) );
		$this->assertSame(
			array(
				'ok'     => false,
				'reason' => 'invalid_json',
			),
			Envelope::parse( $bom . $bom . $bom . $body )
		);
		$this->assertSame(
			array(
				'ok'     => false,
				'reason' => 'invalid_json',
			),
			Envelope::parse( ' ' . $bom . $body )
		);
	}

	/**
	 * Invalid UTF-8 inside a string becomes U+FFFD, as the core's TextDecoder makes it: one U+FFFD per maximal
	 * subpart (WHATWG), not one per byte. Outside strings it stays a syntax error.
	 */
	public function test_invalid_utf8_inside_a_string_is_replaced(): void {
		$fffd = "\xEF\xBF\xBD";
		$note = function ( string $bytes ): string {
			return $this->parsed( self::body( 'transfer.received', '{"note":"a' . $bytes . 'b"}' ) )['data']['note'];
		};
		$this->assertSame( 'a' . $fffd . 'b', $note( "\xFF" ) );
		$this->assertSame( 'a' . $fffd . 'b', $note( "\xF0\x9F\x98" ) ); // a cut 4-byte sequence: one U+FFFD
		$this->assertSame( 'a' . $fffd . 'b', $note( "\xE2\x82" ) );
		$this->assertSame( 'a' . $fffd . 'b', $note( "\xEF\xBF" ) );
		$this->assertSame( 'a' . $fffd . 'b', $note( "\xC3" ) );
		$this->assertSame( 'a' . $fffd . $fffd . 'b', $note( "\xC0\x80" ) ); // an overlong form: two
		$this->assertSame( 'a' . $fffd . $fffd . $fffd . 'b', $note( "\xED\xA0\x80" ) ); // an encoded surrogate: three
		$this->assertSame( 'a' . $fffd . $fffd . $fffd . $fffd . 'b', $note( "\xF4\x90\x80\x80" ) ); // above U+10FFFF
		$this->assertSame( 'a' . str_repeat( $fffd, 5 ) . 'b', $note( "\xF8\x88\x80\x80\x80" ) );
		$this->assertSame( 'a' . $fffd . "\xC3\xA9b", $note( "\xE2\xC3\xA9" ) ); // the byte that broke a sequence starts the next one
		$this->assertSame( "a\xF0\x9F\x98\x80" . $fffd . 'b', $note( "\xF0\x9F\x98\x80\xF0\x9F\x98" ) );
		// The name of a field is repaired the same way.
		$this->assertSame( array( 'n' . $fffd => 1 ), $this->parsed( self::body( 'transfer.received', "{\"n\xF0\x9F\x98\":1}" ) )['data'] );
		// Outside strings, a U+FFFD is no JSON token.
		$this->assertSame(
			array(
				'ok'     => false,
				'reason' => 'invalid_json',
			),
			Envelope::parse( "\xFF" . self::body( 'transfer.received', '{}' ) )
		);
		$this->assertSame(
			array(
				'ok'     => false,
				'reason' => 'invalid_json',
			),
			Envelope::parse( self::body( 'transfer.received', "{\"a\":1\xF0\x9F\x98}" ) )
		);
		// A byte order mark, then invalid UTF-8.
		$this->assertSame( 'a' . $fffd . 'b', $this->parsed( "\xEF\xBB\xBF" . self::body( 'transfer.received', "{\"note\":\"a\xE2\x82b\"}" ) )['data']['note'] );
		$this->assertSame(
			array(
				'ok'     => false,
				'reason' => 'invalid_json',
			),
			Envelope::parse( "\xEF\xBB" . self::body( 'transfer.received', '{}' ) )
		);
	}

	/**
	 * JSON.parse accepts a lone surrogate escape; PHP's decoder refuses it (JSON_ERROR_UTF16). It reads as U+FFFD,
	 * the UTF-8 form of the core's string, and a pair stays one character.
	 */
	public function test_lone_surrogate_escapes(): void {
		$fffd = "\xEF\xBF\xBD";
		$note = function ( string $escaped ): string {
			return $this->parsed( self::body( 'transfer.received', '{"note":"a' . $escaped . 'b"}' ) )['data']['note'];
		};
		$this->assertSame( 'a' . $fffd . 'b', $note( '\ud800' ) );
		$this->assertSame( 'a' . $fffd . 'b', $note( '\udfff' ) );
		$this->assertSame( 'a' . $fffd . 'b', $note( '\uD83D' ) );
		$this->assertSame( 'a' . $fffd . $fffd . 'b', $note( '\ude00\ud83d' ) ); // low then high: two lone surrogates
		$this->assertSame( "a\xF0\x9F\x98\x80b", $note( '\ud83d\ude00' ) ); // an escaped pair: one character
		$this->assertSame( "a\xF0\x9F\x98\x80b", $note( '😀' ) );
		$this->assertSame( 'a' . $fffd . "\xF0\x9F\x98\x80b", $note( '\ud83d\ud83d\ude00' ) );
		$this->assertSame( 'a' . $fffd . "\xF0\x9F\x98\x80b", $note( '\ud83d😀' ) );
		$this->assertSame( 'a' . $fffd . 'xb', $note( '\ud83dx' ) );
		$this->assertSame( 'a' . $fffd . '\\b', $note( '\ud83d\\\\' ) );
		// An escaped backslash is not the start of an escape.
		$this->assertSame( 'a\\ud800b', $note( '\\\\ud800' ) );
		$this->assertSame( 'a\\' . $fffd . 'b', $note( '\\\\\ud800' ) );
		$this->assertSame( 'a"' . $fffd . 'b', $note( '\"\ud800' ) );
		// In a name, in the whole summary, and with a refused shape.
		$this->assertSame( array( 'n' . $fffd => 1 ), $this->parsed( self::body( 'transfer.received', '{"n\udc00":1}' ) )['data'] );
		$summary = Envelope::summarize( $this->parsed( self::body( 'transfer.received', '{"counterparty":{"name":"Ahmed \ud800","number":"01012345678"}}' ) ) );
		$this->assertSame( 'Ahmed ' . $fffd, $summary['transfer']['payer']['name'] );
		$this->assertSame( '01012345678', $summary['transfer']['payer']['key'] );
		$this->assertSame(
			array(
				'ok'     => false,
				'reason' => 'not_qersh_v1',
			),
			Envelope::parse( '{"schema":"qersh.v1","event":"e\ud800","delivery_id":"d","created_at":"c","wallet":{"id":"w","provider":"p"},"data":[]}' )
		);
		$this->assertSame(
			array(
				'ok'     => false,
				'reason' => 'not_an_object',
			),
			Envelope::parse( '["\ud800"]' )
		);
		// Outside strings an escape stays a syntax error.
		$this->assertSame(
			array(
				'ok'     => false,
				'reason' => 'invalid_json',
			),
			Envelope::parse( '{"a":\ud800}' )
		);
		$this->assertSame(
			array(
				'ok'     => false,
				'reason' => 'invalid_json',
			),
			Envelope::parse( self::body( 'transfer.received', '{"a":"\ud80"}' ) )
		);
	}

	/**
	 * JSON.parse accepts a name that starts with NUL; a PHP object cannot hold one (JSON_ERROR_INVALID_PROPERTY_NAME),
	 * so the shape checks read it differently, and the envelope keeps the real name.
	 */
	public function test_names_that_start_with_nul(): void {
		$envelope = $this->parsed( self::body( 'transfer.received', '{"\u0000a":1,"\u0001a":2,"x":{"\u0000":"\u0000"}}' ) );
		$this->assertSame(
			array(
				"\0a" => 1,
				"\1a" => 2,
				'x'   => array( "\0" => "\0" ),
			),
			$envelope['data']
		);
		$top = $this->parsed( '{"\u0000":5,' . substr( self::body( 'e', '{}' ), 1 ) );
		$this->assertSame( 5, $top["\0"] );
		$this->assertSame( 'qersh.v1', $top['schema'] );
		$this->assertSame(
			array(
				'ok'     => false,
				'reason' => 'not_qersh_v1',
			),
			Envelope::parse( '{"\u0000":1,"schema":"qersh.v1","event":"e","delivery_id":"d","created_at":"c","wallet":{"id":"w","provider":"p"},"data":[]}' )
		);
		$this->assertSame(
			array(
				'ok'     => false,
				'reason' => 'not_qersh_v1',
			),
			Envelope::parse( '{"schema":"qersh.v1\u0000","event":"e","delivery_id":"d","created_at":"c","wallet":{"id":"w","provider":"p","\u0000":1},"data":{}}' )
		);
		$this->assertSame(
			array(
				'ok'     => false,
				'reason' => 'not_an_object',
			),
			Envelope::parse( '[{"\u0000":1}]' )
		);
		$this->assertSame(
			array(
				'ok'     => false,
				'reason' => 'invalid_json',
			),
			Envelope::parse( '{"\u0000":1,}' )
		);
		// An escaped backslash before "u0000" is text.
		$this->assertSame( array( '\\u0000' => 1 ), $this->parsed( self::body( 'transfer.received', '{"\\\\u0000":1}' ) )['data'] );
	}

	/**
	 * JSON.parse has no nesting limit; json_decode is allowed far more than its default 512 levels.
	 */
	public function test_deep_nesting(): void {
		$deep     = str_repeat( '[', 600 ) . '1' . str_repeat( ']', 600 );
		$envelope = $this->parsed( self::body( 'transfer.received', '{"deep":' . $deep . ',"amount_minor":5}' ) );
		$this->assertSame( json_decode( $deep, true, 1000 ), $envelope['data']['deep'] );
		$this->assertSame( 5, Envelope::summarize( $envelope )['transfer']['amount_minor'] );
		$objects = str_repeat( '{"a":', 1000 ) . '1' . str_repeat( '}', 1000 );
		$this->assertTrue( Envelope::parse( self::body( 'transfer.received', '{"deep":' . $objects . '}' ) )['ok'] );
		$this->assertSame(
			array(
				'ok'     => false,
				'reason' => 'not_qersh_v1',
			),
			Envelope::parse( self::body( 'transfer.received', '[' . $deep . ']' ) )
		);
	}

	/**
	 * Summarize.test.ts: transfer.received becomes one item with the contract names plus payer.key.
	 */
	public function test_summarize_transfer_received(): void {
		$case     = self::vector( 'valid_transfer_received' );
		$envelope = $this->parsed( $case['body'] );
		$lower    = array();
		foreach ( $case['headers'] as $name => $value ) {
			$lower[ strtolower( $name ) ] = $value;
		}
		$headers = Envelope::delivery_headers( $lower );
		$item    = Envelope::summarize( $envelope, $headers, 'https://n8n.example.com/webhook/qersh' );
		$this->assertSame( array( 'event', 'delivery_id', 'attempt', 'redelivery', 'test', 'created_at', 'wallet', 'transfer', 'data', 'envelope', 'webhook_url' ), array_keys( $item ) );
		$this->assertSame( 'transfer.received', $item['event'] );
		$this->assertSame( '3f2a9c6e-1b7d-4e8a-9f0c-5d6e7a8b9c0d', $item['delivery_id'] );
		$this->assertSame( 1, $item['attempt'] );
		$this->assertNull( $item['redelivery'] );
		$this->assertFalse( $item['test'] );
		$this->assertSame( 'vodafone_cash', $item['wallet']['provider'] );
		$this->assertSame( json_decode( $case['body'], true )['wallet']['label'], $item['wallet']['label'] );
		$this->assertSame( '2026-10-11T16:36:42+03:00', $item['created_at'] );
		$transfer = $item['transfer'];
		$this->assertSame(
			array( 'transfer_id', 'direction', 'status', 'amount', 'amount_minor', 'currency', 'received_at', 'payer', 'provider_txn_id', 'balance_after', 'risk', 'risk_reasons', 'auto_confirm_ok', 'balance_chain', 'suspected_forgery' ),
			array_keys( $transfer )
		);
		$this->assertSame( 15007, $transfer['amount_minor'] );
		$this->assertSame( '150.07', $transfer['amount'] );
		$this->assertSame( '2026-10-11T16:36:41+03:00', $transfer['received_at'] );
		$this->assertSame(
			array(
				'number' => '01112233445',
				'handle' => null,
				'name'   => 'Assaad K Mansouri',
				'key'    => '01112233445',
			),
			$transfer['payer']
		);
		$this->assertSame( '099409626954', $transfer['provider_txn_id'] );
		$this->assertSame( 'consistent', $transfer['balance_chain'] );
		$this->assertTrue( $transfer['auto_confirm_ok'] );
		$this->assertFalse( $transfer['suspected_forgery'] );
		$this->assertSame( array(), $transfer['risk_reasons'] );
		$this->assertSame( 'low', $transfer['risk'] );
		$this->assertSame( 'https://n8n.example.com/webhook/qersh', $item['webhook_url'] );
		$this->assertSame( $envelope['data'], $item['data'] );
		$this->assertSame( $envelope, $item['envelope'] );
		$this->assertSame( 1791725802, $headers['signature_t'] );
		$this->assertSame( 'wlt_01JA2Y4X5M7Q9R0S1T2U3V4W5X', $headers['wallet'] );
		$this->assertSame( '3f2a9c6e-1b7d-4e8a-9f0c-5d6e7a8b9c0d', $headers['delivery'] );
	}

	/**
	 * Summarize.test.ts: webhook.test takes its transfer from data.sample and is flagged as a test.
	 */
	public function test_summarize_webhook_test(): void {
		$item = Envelope::summarize( $this->parsed( self::vector( 'valid_webhook_test' )['body'] ), null, null );
		$this->assertTrue( $item['test'] );
		$this->assertSame( 100, $item['transfer']['amount_minor'] );
		$this->assertSame( '01000000000', $item['transfer']['payer']['key'] );
		$this->assertSame( 'test', $item['transfer']['risk'] );
		$this->assertNull( $item['webhook_url'] );
		$this->assertNull( $item['attempt'] );
		$this->assertSame( $item, Envelope::summarize( $this->parsed( self::vector( 'valid_webhook_test' )['body'] ) ) );
	}

	/**
	 * Summarize.test.ts: transfer.updated keeps what arrived and raises suspected_forgery.
	 */
	public function test_summarize_transfer_updated(): void {
		$envelope = $this->parsed( self::body( 'transfer.updated', '{"transfer_id":"trf_1","updated_fields":["checks.balance_chain"],"checks":{"balance_chain":"refuted"},"risk":"high","risk_reasons":["balance_inconsistent","suspected_forgery"],"auto_confirm_ok":false}' ) );
		$item     = Envelope::summarize(
			$envelope,
			Envelope::delivery_headers(
				array(
					'x-qersh-attempt'    => '3',
					'x-qersh-redelivery' => 'backfill',
				)
			)
		);
		$this->assertSame( 'trf_1', $item['transfer']['transfer_id'] );
		$this->assertNull( $item['transfer']['amount_minor'] );
		$this->assertTrue( $item['transfer']['suspected_forgery'] );
		$this->assertSame( 'refuted', $item['transfer']['balance_chain'] );
		$this->assertSame( array( 'balance_inconsistent', 'suspected_forgery' ), $item['transfer']['risk_reasons'] );
		$this->assertFalse( $item['transfer']['auto_confirm_ok'] );
		$this->assertSame(
			array(
				'number' => null,
				'handle' => null,
				'name'   => null,
				'key'    => null,
			),
			$item['transfer']['payer']
		);
		$this->assertSame( 3, $item['attempt'] );
		$this->assertSame( 'backfill', $item['redelivery'] );
		$this->assertFalse( $item['test'] );
		$this->assertTrue( Envelope::is_suspected_forgery( array( 'checks' => array( 'balance_chain' => 'refuted' ) ) ) );
		$this->assertTrue( Envelope::is_suspected_forgery( array( 'risk_reasons' => array( 'suspected_forgery' ) ) ) );
		$this->assertFalse( Envelope::is_suspected_forgery( array( 'risk_reasons' => array( 'balance_stale' ) ) ) );
		$this->assertFalse( Envelope::is_suspected_forgery( array( 'risk_reasons' => 'suspected_forgery' ) ) );
		$this->assertFalse( Envelope::is_suspected_forgery( array( 'risk_reasons' => array( 'reason' => 'suspected_forgery' ) ) ) );
		$this->assertFalse( Envelope::is_suspected_forgery( array( 'checks' => array( 'balance_chain' => 'consistent' ) ) ) );
		$this->assertFalse( Envelope::is_suspected_forgery( array( 'checks' => array( 'refuted' ) ) ) );
		$this->assertFalse( Envelope::is_suspected_forgery( null ) );
		$this->assertFalse( Envelope::is_suspected_forgery( 'suspected_forgery' ) );
	}

	/**
	 * Summarize.test.ts: other events have no transfer; headers are read case-insensitively and bad values become null.
	 */
	public function test_summarize_other_events_and_bad_header_values(): void {
		$envelope = $this->parsed( '{"schema":"qersh.v1","event":"device.heartbeat","delivery_id":"d2","created_at":"2026-10-11T16:40:00+03:00","wallet":{"id":"wlt_x","provider":"instapay_app","number_masked":"0100****776"},"data":{"seq":4}}' );
		$item     = Envelope::summarize(
			$envelope,
			Envelope::delivery_headers(
				array(
					'X-Qersh-Attempt'    => 'many',
					'X-Qersh-Redelivery' => 'later',
				)
			)
		);
		$this->assertNull( $item['transfer'] );
		$this->assertNull( $item['attempt'] );
		$this->assertNull( $item['redelivery'] );
		$this->assertSame( array( 'seq' => 4 ), $item['data'] );
		$this->assertSame( 'd2', $item['delivery_id'] );
	}

	/**
	 * Summarize.test.ts: the kit spellings of a payer number or handle reach `payer.key` (normalizePayer).
	 */
	public function test_payer_key_spellings(): void {
		$key_of = function ( $number, $handle = null ) {
			$cp = array( 'number' => $number );
			if ( null !== $handle ) {
				$cp['handle'] = $handle;
			}
			return Envelope::summarize_transfer( array( 'counterparty' => $cp ) )['payer']['key'];
		};
		$this->assertSame( '01012345678', $key_of( '+20 101 234 5678' ) );
		$this->assertSame( '01012345678', $key_of( '00201012345678' ) );
		$this->assertSame( '01012345678', $key_of( '201012345678' ) );
		$this->assertSame( '01012345678', $key_of( '1012345678' ) );
		$this->assertSame( '01012345678', $key_of( "\xD9\xA0\xD9\xA1\xD9\xA0\xD9\xA1\xD9\xA2\xD9\xA3\xD9\xA4\xD9\xA5\xD9\xA6\xD9\xA7\xD9\xA8" ) ); // Arabic-Indic digits
		$this->assertSame( 'shop@instapay', $key_of( 'Shop@InstaPay' ) );
		$this->assertSame( 'shop@instapay', $key_of( 'shop' ) );
		$this->assertNull( $key_of( '0111***3445' ) );
		$this->assertNull( $key_of( '' ) );
		$this->assertNull( $key_of( null ) );
		// A masked number falls through to the handle.
		$this->assertSame( 'shop@instapay', $key_of( '0111***3445', 'shop@instapay' ) );
		$this->assertNull( Envelope::summarize_transfer( array( 'counterparty' => array( '01012345678' ) ) )['payer']['key'] );
		$this->assertNull( Envelope::summarize_transfer( array( 'counterparty' => '01012345678' ) )['payer']['key'] );
	}

	/**
	 * DeliveryHeaders: names in any case, with dashes or WordPress's underscores, values as strings or lists.
	 */
	public function test_delivery_headers(): void {
		$empty = array(
			'delivery'    => null,
			'attempt'     => null,
			'redelivery'  => null,
			'wallet'      => null,
			'signature_t' => null,
		);
		$this->assertSame( $empty, Envelope::delivery_headers( array() ) );
		$this->assertSame(
			array(
				'delivery'    => 'abc',
				'attempt'     => 2,
				'redelivery'  => 'manual',
				'wallet'      => 'w1',
				'signature_t' => 1791725802,
			),
			Envelope::delivery_headers(
				array(
					'x_qersh_delivery'   => array( 'abc' ),
					'x_qersh_attempt'    => array( '2' ),
					'x_qersh_redelivery' => array( 'manual' ),
					'x_qersh_wallet'     => array( 'w1' ),
					'x_qersh_signature'  => array( 't=1791725802,v1=00' ),
				)
			)
		);
		$this->assertSame( 'abc', Envelope::delivery_headers( array( 'X_Qersh_Delivery' => 'abc' ) )['delivery'] );
		$this->assertSame( 'abc', Envelope::delivery_headers( array( 'X-QERSH-DELIVERY' => 'abc' ) )['delivery'] );
		// A null value falls through to the scan, where the first name that matches decides (as in the core).
		$this->assertNull(
			Envelope::delivery_headers(
				array(
					'x-qersh-delivery' => null,
					'X-Qersh-Delivery' => 'abc',
				)
			)['delivery']
		);
		$this->assertNull(
			Envelope::delivery_headers(
				array(
					'X-Qersh-Delivery' => null,
					'x-Qersh-Delivery' => 'abc',
				)
			)['delivery']
		);
		$this->assertSame(
			'abc',
			Envelope::delivery_headers(
				array(
					'X-Qersh-Delivery' => 'x',
					'x-qersh-delivery' => 'abc',
				)
			)['delivery']
		);
		$this->assertSame(
			'abc',
			Envelope::delivery_headers(
				array(
					'x_qersh_delivery' => 'abc',
					'X-Qersh-Delivery' => 'x',
				)
			)['delivery']
		);
		$this->assertNull( Envelope::delivery_headers( array( 'x-qersh-delivery' => array( 5 ) ) )['delivery'] );
		$this->assertNull( Envelope::delivery_headers( array( 'x-qersh-delivery' => array() ) )['delivery'] );
		$this->assertNull( Envelope::delivery_headers( array( 'x-qersh-delivery' => 5 ) )['delivery'] );
		$this->assertSame( '', Envelope::delivery_headers( array( 'x-qersh-delivery' => '' ) )['delivery'] );
		// Attempt: 1 to 9 digits.
		$this->assertSame( 0, Envelope::delivery_headers( array( 'x-qersh-attempt' => '0' ) )['attempt'] );
		$this->assertSame( 999999999, Envelope::delivery_headers( array( 'x-qersh-attempt' => '999999999' ) )['attempt'] );
		$this->assertNull( Envelope::delivery_headers( array( 'x-qersh-attempt' => '1000000000' ) )['attempt'] );
		$this->assertNull( Envelope::delivery_headers( array( 'x-qersh-attempt' => ' 1' ) )['attempt'] );
		$this->assertNull( Envelope::delivery_headers( array( 'x-qersh-attempt' => "1\n" ) )['attempt'] );
		$this->assertNull( Envelope::delivery_headers( array( 'x-qersh-attempt' => '-1' ) )['attempt'] );
		$this->assertNull( Envelope::delivery_headers( array( 'x-qersh-redelivery' => 'Manual' ) )['redelivery'] );
		// signature_t: the first `t=<digits>` pair, 13 digits at most.
		$t = function ( string $signature ) {
			return Envelope::delivery_headers( array( 'x-qersh-signature' => $signature ) )['signature_t'];
		};
		$this->assertSame( 1791725802, $t( 'v1=ab, t=1791725802 ,v1=cd' ) );
		$this->assertSame( 1791725802, $t( "t=1791725802\xC2\xA0,v1=cd" ) );
		$this->assertSame( 5, $t( 'v1=ab,t=5' ) );
		$this->assertSame( 5, $t( 't=5,t=6' ) );
		$this->assertSame( 6, $t( 't=x5,t=6' ) );
		$this->assertSame( 1, $t( 't=0000000000001' ) );
		$this->assertNull( $t( 't=17917258020000' ) );
		$this->assertNull( $t( 't=abc' ) );
		$this->assertNull( $t( 't= 5x' ) );
		$this->assertNull( $t( 'xt=5' ) );
		$this->assertNull( $t( '' ) );
		$this->assertSame( 5, $t( "t=5,\xFF" ) ); // not UTF-8: ASCII white space only
	}

	/**
	 * SummarizeTransfer: each field keeps its JSON type or becomes null.
	 */
	public function test_summarize_transfer_types(): void {
		$this->assertNull( Envelope::summarize_transfer( null ) );
		$this->assertNull( Envelope::summarize_transfer( 'x' ) );
		$this->assertNull( Envelope::summarize_transfer( array( 1, 2 ) ) );
		$all_null = Envelope::summarize_transfer( array() );
		$this->assertNull( $all_null['transfer_id'] );
		$this->assertSame( array(), $all_null['risk_reasons'] );
		$this->assertFalse( $all_null['suspected_forgery'] );
		$envelope = $this->parsed( self::body( 'transfer.received', '{"transfer_id":5,"amount":150.07,"amount_minor":15007.0,"currency":"EGP","risk_reasons":["a",5,null,"b",{"c":1}],"auto_confirm_ok":"true","checks":[],"counterparty":{"number":1012345678,"name":7}}' ) );
		$summary  = Envelope::summarize( $envelope )['transfer'];
		$this->assertNull( $summary['transfer_id'] );
		$this->assertNull( $summary['amount'] );
		$this->assertSame( 15007, $summary['amount_minor'] );
		$this->assertSame( 'EGP', $summary['currency'] );
		$this->assertSame( array( 'a', 'b' ), $summary['risk_reasons'] );
		$this->assertNull( $summary['auto_confirm_ok'] );
		$this->assertNull( $summary['balance_chain'] );
		$this->assertNull( $summary['payer']['number'] );
		$this->assertNull( $summary['payer']['name'] );
		$this->assertSame( '01012345678', $summary['payer']['key'] ); // normalizePayer reads numbers too
		$amount = function ( string $json ) {
			return Envelope::summarize( $this->parsed( self::body( 'transfer.received', '{"amount_minor":' . $json . '}' ) ) )['transfer']['amount_minor'];
		};
		$this->assertSame( 0, $amount( '0' ) );
		$this->assertSame( -5, $amount( '-5' ) );
		$this->assertSame( 100, $amount( '1e2' ) );
		$this->assertNull( $amount( '150.5' ) );
		$this->assertNull( $amount( '"15007"' ) );
		$this->assertNull( $amount( 'true' ) );
		$this->assertNull( $amount( 'null' ) );
		$this->assertNull( $amount( '1e400' ) );
		$this->assertSame( array(), Envelope::summarize_transfer( array( 'risk_reasons' => array( 'x' => 'a' ) ) )['risk_reasons'] );
	}

	/**
	 * Summarize: the event decides whether there is a transfer; test, webhook_url and missing fields.
	 */
	public function test_summarize_event_rules(): void {
		$sent = Envelope::summarize( $this->parsed( self::body( 'transfer.sent', '{"transfer_id":"trf_2","direction":"out"}' ) ) );
		$this->assertSame( 'out', $sent['transfer']['direction'] );
		$sample_string = Envelope::summarize( $this->parsed( self::body( 'webhook.test', '{"sample":"x"}' ) ) );
		$this->assertTrue( $sample_string['test'] );
		$this->assertNull( $sample_string['transfer'] );
		$no_sample = Envelope::summarize( $this->parsed( self::body( 'webhook.test', '{}' ) ) );
		$this->assertNull( $no_sample['transfer'] );
		$this->assertSame( array(), $no_sample['data'] );
		$this->assertNull( Envelope::summarize( $this->parsed( self::body( 'transferX', '{"transfer_id":"t"}' ) ) )['transfer'] );
		$flagged         = $this->parsed( self::body( 'device.heartbeat', '{}' ) );
		$flagged['test'] = true;
		$this->assertTrue( Envelope::summarize( $flagged )['test'] );
		$flagged['test'] = 'true';
		$this->assertFalse( Envelope::summarize( $flagged )['test'] );
		$this->assertNull( Envelope::summarize( $flagged, null, '' )['webhook_url'] );
		$this->assertSame( 'https://shop.example/x', Envelope::summarize( $flagged, null, 'https://shop.example/x' )['webhook_url'] );
		// A hand-built array without the required fields still summarises, with nulls.
		$bare = Envelope::summarize(
			array(
				'event' => 7,
				'data'  => 'x',
			)
		);
		$this->assertSame( '', $bare['event'] );
		$this->assertSame( array(), $bare['data'] );
		$this->assertNull( $bare['delivery_id'] );
		$this->assertNull( $bare['created_at'] );
		$this->assertNull( $bare['wallet'] );
		$this->assertNull( $bare['transfer'] );
		$this->assertFalse( $bare['test'] );
	}

	/**
	 * Summarize after parse(): `data` is a JSON object even when its names are "0".."n-1", which PHP decodes like a
	 * list. Deeper down that cannot be told, and the accepted difference errs on the side that stops the order.
	 */
	public function test_summarize_objects_that_look_like_lists(): void {
		$received = Envelope::summarize( $this->parsed( self::body( 'transfer.received', '{"0":"x"}' ) ) );
		$this->assertSame( array( 'x' ), $received['data'] );
		$this->assertNotNull( $received['transfer'] );
		$this->assertNull( $received['transfer']['transfer_id'] );
		$this->assertSame( array(), $received['transfer']['risk_reasons'] );
		$this->assertFalse( $received['transfer']['suspected_forgery'] );
		$this->assertSame( Envelope::summarize_transfer( array() ), $received['transfer'] );
		$heartbeat = Envelope::summarize( $this->parsed( self::body( 'device.heartbeat', '{"0":"x","1":2}' ) ) );
		$this->assertSame( array( 'x', 2 ), $heartbeat['data'] );
		$this->assertNull( $heartbeat['transfer'] );
		$this->assertNull( Envelope::summarize( $this->parsed( self::body( 'webhook.test', '{"0":{"transfer_id":"t"}}' ) ) )['transfer'] );
		// A counterparty or checks object with such names has no number, handle or verdict in either language.
		$odd = Envelope::summarize( $this->parsed( self::body( 'transfer.received', '{"counterparty":{"0":"01012345678"},"checks":{"0":"refuted"}}' ) ) )['transfer'];
		$this->assertNull( $odd['payer']['key'] );
		$this->assertNull( $odd['balance_chain'] );
		$this->assertFalse( $odd['suspected_forgery'] );
		// Accepted difference: risk_reasons sent as {"0":"suspected_forgery"} reads as the list (the core ignores it).
		$listish = Envelope::summarize( $this->parsed( self::body( 'transfer.received', '{"risk_reasons":{"0":"suspected_forgery"}}' ) ) )['transfer'];
		$this->assertTrue( $listish['suspected_forgery'] );
		$this->assertSame( array( 'suspected_forgery' ), $listish['risk_reasons'] );
	}

	/**
	 * Is_record and is_list: how decoded JSON objects and arrays are told apart.
	 */
	public function test_records_and_lists(): void {
		$this->assertTrue( Envelope::is_record( array() ) );
		$this->assertTrue( Envelope::is_record( array( 'a' => 1 ) ) );
		$this->assertTrue( Envelope::is_record( array( 1 => 'a' ) ) );
		$this->assertFalse( Envelope::is_record( array( 'a' ) ) );
		$this->assertFalse( Envelope::is_record( 'a' ) );
		$this->assertFalse( Envelope::is_record( null ) );
		$this->assertTrue( Envelope::is_list( array() ) );
		$this->assertTrue( Envelope::is_list( array( 'a', 'b' ) ) );
		$this->assertFalse( Envelope::is_list( array( 1 => 'a' ) ) );
		$this->assertFalse(
			Envelope::is_list(
				array(
					1 => 'b',
					0 => 'a',
				)
			)
		);
		$this->assertFalse( Envelope::is_list( 'a' ) );
	}
}
