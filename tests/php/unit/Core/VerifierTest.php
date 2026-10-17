<?php
/**
 * Unit tests of Core\Verifier: the kit vectors through the port and through the kit's own verify.php, the cases of
 * vectors.test.ts in packages/qersh-webhook, and the parsing limits of parseSignatureHeader.
 *
 * @package QershPay\WooCommerce\Tests
 */

declare( strict_types=1 );

namespace QershPay\WooCommerce\Tests\Unit\Core;

defined( 'ABSPATH' ) || exit;

use QershPay\WooCommerce\Core\Verifier;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Verifier tests. Keys come from fixtures/test-vectors.json or are derived at run time, never written here.
 */
final class VerifierTest extends TestCase {

	/**
	 * The decoded kit vectors.
	 *
	 * @var array|null
	 */
	private static $vectors = null;

	/**
	 * The kit's own PHP verifier (tests/php/fixtures/verify.php, which defines qersh_verify), loaded on first use.
	 *
	 * @param string $header  Header.
	 * @param string $body    Raw body.
	 * @param array  $secrets Keys.
	 * @param int    $now     Time.
	 * @return bool
	 */
	private static function kit_verify( string $header, string $body, array $secrets, int $now ): bool {
		require_once QERSH_WC_TESTS_ROOT . '/tests/php/fixtures/verify.php';
		return \qersh_verify( $header, $body, $secrets, $now );
	}

	/**
	 * The kit vectors.
	 *
	 * @return array
	 */
	private static function vectors(): array {
		if ( null === self::$vectors ) {
			self::$vectors = json_decode( qersh_wc_test_fixture( 'fixtures/test-vectors.json' ), true );
		}
		return self::$vectors;
	}

	/**
	 * One vector case by name.
	 *
	 * @param string $name Case name.
	 * @return array
	 */
	private static function vector( string $name ): array {
		foreach ( self::vectors()['cases'] as $case ) {
			if ( $name === $case['name'] ) {
				return $case;
			}
		}
		throw new \RuntimeException( 'no vector ' . $name ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- unit test without WordPress (no esc_html()): the message only reaches the PHPUnit report.
	}

	/**
	 * The X-Qersh-Signature of a case ('' when the case has none).
	 *
	 * @param array $case Vector case.
	 * @return string
	 */
	private static function header_of( array $case ): string {
		return isset( $case['headers']['X-Qersh-Signature'] ) ? (string) $case['headers']['X-Qersh-Signature'] : '';
	}

	/**
	 * A key that is neither of the vector keys, derived at run time.
	 *
	 * @param int $n Which one.
	 * @return string
	 */
	private static function other_key( int $n ): string {
		return 'qsk_' . substr( hash( 'sha256', 'qersh-wc-test-other-key-' . $n ), 0, 43 );
	}

	/**
	 * Runs a vector case through the port, optionally at another time.
	 *
	 * @param string   $name Case name.
	 * @param int|null $now  Time, or the case's own.
	 * @return array
	 */
	private static function run_case( string $name, ?int $now = null ): array {
		$case = self::vector( $name );
		return Verifier::verify( $case['body'], self::header_of( $case ), $case['secrets'], null === $now ? $case['now'] : $now );
	}

	/**
	 * Docs/17 §1: the nine vectors pass the original verify.php and Verifier alike, accept and reject exactly.
	 */
	public function test_every_kit_vector_gives_the_expected_verdict_in_the_port_and_in_verify_php(): void {
		$cases = self::vectors()['cases'];
		$this->assertCount( 9, $cases );
		foreach ( $cases as $case ) {
			$accept = 'accept' === $case['expect'];
			$result = Verifier::verify( $case['body'], self::header_of( $case ), $case['secrets'], $case['now'] );
			$this->assertSame( $accept, $result['ok'], $case['name'] . ': ' . (string) json_encode( $result ) );
			$this->assertSame( $accept, self::kit_verify( self::header_of( $case ), $case['body'], $case['secrets'], $case['now'] ), $case['name'] . ' (verify.php)' );
		}
	}

	/**
	 * Vectors.test.ts: failure reasons name the cause.
	 */
	public function test_failure_reasons_name_the_cause(): void {
		$this->assertSame(
			array(
				'ok'     => false,
				'reason' => 'bad_signature',
				't'      => 1791725802,
			),
			self::run_case( 'wrong_secret' )
		);
		$this->assertSame(
			array(
				'ok'     => false,
				'reason' => 'bad_signature',
				't'      => 1791725802,
			),
			self::run_case( 'tampered_body' )
		);
		$this->assertSame(
			array(
				'ok'     => false,
				'reason' => 'stale_timestamp',
				't'      => 1791725802,
			),
			self::run_case( 'stale_timestamp' )
		);
		$this->assertSame(
			array(
				'ok'     => false,
				'reason' => 'stale_timestamp',
				't'      => 1791725802,
			),
			self::run_case( 'future_timestamp' )
		);
		$this->assertSame(
			array(
				'ok'     => false,
				'reason' => 'missing_header',
				't'      => null,
			),
			self::run_case( 'missing_signature_header' )
		);
		$this->assertSame(
			array(
				'ok'     => false,
				'reason' => 'malformed_header',
				't'      => null,
			),
			self::run_case( 'garbage_signature_header' )
		);
		// The previous key signed the second v1 of the header.
		$this->assertSame(
			array(
				'ok'        => true,
				't'         => 1791725802,
				'key_index' => 0,
				'v1_index'  => 1,
			),
			self::run_case( 'rotation_previous_secret_still_valid' )
		);
		$this->assertSame(
			array(
				'ok'        => true,
				't'         => 1791725802,
				'key_index' => 0,
				'v1_index'  => 0,
			),
			self::run_case( 'valid_transfer_received' )
		);
	}

	/**
	 * Vectors.test.ts: the valid case verifies at the window edges and fails just outside.
	 */
	public function test_the_window_is_inclusive_at_300_seconds(): void {
		$this->assertTrue( self::run_case( 'valid_transfer_received', 1791725802 + 300 )['ok'] );
		$this->assertTrue( self::run_case( 'valid_transfer_received', 1791725802 - 300 )['ok'] );
		$this->assertFalse( self::run_case( 'valid_transfer_received', 1791725802 + 301 )['ok'] );
		$this->assertFalse( self::run_case( 'valid_transfer_received', 1791725802 - 301 )['ok'] );
		$this->assertSame( 'stale_timestamp', self::run_case( 'valid_transfer_received', 1791725802 + 301 )['reason'] );
	}

	/**
	 * Vectors.test.ts: a header with several v1 values matches whichever key the receiver holds.
	 */
	public function test_rotation_header_matches_whichever_key_the_receiver_holds(): void {
		$case    = self::vector( 'valid_transfer_received' );
		$vectors = self::vectors();
		$header  = Verifier::sign_header( $case['now'], $case['body'], array( $vectors['secret'], $vectors['previous_secret'] ) );
		$this->assertCount( 2, Verifier::parse_header( $header )['v1'] );
		$this->assertSame(
			array(
				'ok'        => true,
				't'         => $case['now'],
				'key_index' => 0,
				'v1_index'  => 1,
			),
			Verifier::verify( $case['body'], $header, array( $vectors['previous_secret'] ), $case['now'] )
		);
		$this->assertSame(
			array(
				'ok'     => false,
				'reason' => 'bad_signature',
				't'      => $case['now'],
			),
			Verifier::verify( $case['body'], $header, array( self::other_key( 1 ) ), $case['now'] )
		);
		$this->assertFalse( Verifier::verify( $case['body'], $header, array(), $case['now'] )['ok'] );
		$this->assertSame(
			array(
				'ok'     => false,
				'reason' => 'raw_body_unavailable',
				't'      => null,
			),
			Verifier::verify( null, $header, array( $vectors['secret'] ), $case['now'] )
		);
	}

	/**
	 * Vectors.test.ts: keys are trimmed like the phone trims them, blanks and non-strings mean no_key, and key_index
	 * is the caller's index.
	 */
	public function test_keys_are_trimmed_and_key_index_is_the_callers_index(): void {
		$case   = self::vector( 'valid_transfer_received' );
		$secret = $case['secrets'][0];
		$t      = Verifier::parse_header( self::header_of( $case ) )['t'];
		$run    = function ( array $keys ) use ( $case ) {
			return Verifier::verify( $case['body'], self::header_of( $case ), $keys, $case['now'] );
		};
		$ok     = function ( int $key_index ) use ( $t ) {
			return array(
				'ok'        => true,
				't'         => $t,
				'key_index' => $key_index,
				'v1_index'  => 0,
			);
		};
		$no_key = array(
			'ok'     => false,
			'reason' => 'no_key',
			't'      => null,
		);
		$this->assertSame( $ok( 0 ), $run( array( $secret . "\n" ) ) );
		$this->assertSame( $ok( 0 ), $run( array( '  ' . $secret . "\r\n" ) ) );
		$this->assertSame( $ok( 2 ), $run( array( '', '   ', $secret ) ) );
		$this->assertSame( $ok( 1 ), $run( array( self::other_key( 1 ), $secret ) ) );
		$this->assertSame( $no_key, $run( array( '   ' ) ) );
		$this->assertSame( $no_key, $run( array( 42, null ) ) );
		$this->assertSame( array(), Verifier::usable_keys( 'not-an-array' ) );
		$this->assertSame(
			array(
				array(
					'key'   => 'a',
					'index' => 1,
				),
			),
			Verifier::usable_keys( array( 7, " a\t", "\xC2\xA0" ) )
		);
		// Associative arrays count by position, like Array.prototype.forEach.
		$this->assertSame(
			$ok( 1 ),
			$run(
				array(
					'x' => self::other_key( 2 ),
					'y' => $secret,
				)
			)
		);
	}

	/**
	 * Vectors.test.ts: a window that is not a finite non-negative number falls back to 300.
	 */
	public function test_tolerance_falls_back_to_300(): void {
		$this->assertSame( 300, Verifier::tolerance_of( INF ) );
		$this->assertSame( 300, Verifier::tolerance_of( NAN ) );
		$this->assertSame( 300, Verifier::tolerance_of( -1 ) );
		$this->assertSame( 300, Verifier::tolerance_of( '600' ) );
		$this->assertSame( 300, Verifier::tolerance_of( null ) );
		$this->assertSame( 0, Verifier::tolerance_of( 0 ) );
		$this->assertSame( 10.5, Verifier::tolerance_of( 10.5 ) );
		$case = self::vector( 'valid_transfer_received' );
		$t    = 1791725802;
		$this->assertFalse( Verifier::verify( $case['body'], self::header_of( $case ), $case['secrets'], $t + 400 )['ok'] );
		$this->assertTrue( Verifier::verify( $case['body'], self::header_of( $case ), $case['secrets'], $t + 400, 400 )['ok'] );
		$this->assertTrue( Verifier::verify( $case['body'], self::header_of( $case ), $case['secrets'], $t + 200, -5 )['ok'] );
		$this->assertFalse( Verifier::verify( $case['body'], self::header_of( $case ), $case['secrets'], $t + 301, -5 )['ok'] );
		$this->assertSame( 'stale_timestamp', Verifier::verify( $case['body'], self::header_of( $case ), $case['secrets'], $t + 1, 0 )['reason'] );
		$this->assertTrue( Verifier::verify( $case['body'], self::header_of( $case ), $case['secrets'], $t, 0 )['ok'] );
	}

	/**
	 * Sign_header builds the header the phone sends, byte for byte.
	 */
	public function test_sign_header_reproduces_the_vector_headers(): void {
		$vectors  = self::vectors();
		$valid    = self::vector( 'valid_transfer_received' );
		$rotation = self::vector( 'rotation_previous_secret_still_valid' );
		$this->assertSame( self::header_of( $valid ), Verifier::sign_header( 1791725802, $valid['body'], array( $vectors['secret'] ) ) );
		$this->assertSame( self::header_of( $rotation ), Verifier::sign_header( 1791725802, $rotation['body'], array( $vectors['secret'], $vectors['previous_secret'] ) ) );
		$this->assertSame( self::header_of( $valid ), Verifier::sign_header( 1791725802, $valid['body'], array( '', ' ' . $vectors['secret'] . "\n" ) ) );
		$this->assertSame( '1791725802.' . $valid['body'], Verifier::signed_payload( 1791725802, $valid['body'] ) );
		$this->assertSame( 't=5,', Verifier::sign_header( 5, 'x', array() ) );
	}

	/**
	 * Docs/17 §13 item 5: a space after the comma.
	 */
	public function test_a_space_after_the_comma_still_verifies(): void {
		$case   = self::vector( 'valid_transfer_received' );
		$header = str_replace( ',', ', ', self::header_of( $case ) );
		$this->assertStringContainsString( ', v1=', $header );
		$this->assertTrue( Verifier::verify( $case['body'], $header, $case['secrets'], $case['now'] )['ok'] );
		$this->assertTrue( self::kit_verify( $header, $case['body'], $case['secrets'], $case['now'] ) );
		// Spaces and tabs around keys and values are trimmed too (the kit does not trim inside a pair).
		$spaced = str_replace( array( 't=', 'v1=' ), array( " t =\t", "\tv1 = " ), self::header_of( $case ) );
		$this->assertTrue( Verifier::verify( $case['body'], $spaced, $case['secrets'], $case['now'] )['ok'] );
	}

	/**
	 * Docs/17 §13 item 5 and §4.8: four v1 values (the current key and three previous ones) with the match last.
	 */
	public function test_four_v1_values_with_the_match_last(): void {
		$case   = self::vector( 'valid_transfer_received' );
		$secret = $case['secrets'][0];
		$keys   = array( self::other_key( 1 ), self::other_key( 2 ), self::other_key( 3 ), $secret );
		$header = Verifier::sign_header( $case['now'], $case['body'], $keys );
		$this->assertCount( 4, Verifier::parse_header( $header )['v1'] );
		$this->assertSame(
			array(
				'ok'        => true,
				't'         => $case['now'],
				'key_index' => 0,
				'v1_index'  => 3,
			),
			Verifier::verify( $case['body'], $header, array( $secret ), $case['now'] )
		);
		$this->assertTrue( self::kit_verify( $header, $case['body'], array( $secret ), $case['now'] ) );
		// Keys are tried in the caller's order, each against every value.
		$this->assertSame(
			array(
				'ok'        => true,
				't'         => $case['now'],
				'key_index' => 1,
				'v1_index'  => 3,
			),
			Verifier::verify( $case['body'], $header, array( self::other_key( 9 ), $secret, self::other_key( 2 ) ), $case['now'] )
		);
		$this->assertSame( 1, Verifier::verify( $case['body'], $header, array( self::other_key( 2 ) ), $case['now'] )['v1_index'] );
		$this->assertSame( 0, Verifier::verify( $case['body'], $header, array( self::other_key( 1 ) ), $case['now'] )['v1_index'] );
	}

	/**
	 * ParseSignatureHeader: at most 8192 characters after trimming, counted as JavaScript counts them.
	 */
	public function test_header_length_limit(): void {
		$case   = self::vector( 'valid_transfer_received' );
		$header = self::header_of( $case );
		$verify = function ( string $h ) use ( $case ) {
			return Verifier::verify( $case['body'], $h, $case['secrets'], $case['now'] );
		};
		$exact  = $header . ',' . str_repeat( 'x', 8192 - strlen( $header ) - 1 );
		$this->assertSame( 8192, strlen( $exact ) );
		$this->assertTrue( $verify( $exact )['ok'] );
		$this->assertSame(
			array(
				'ok'     => false,
				'reason' => 'malformed_header',
				't'      => null,
			),
			$verify( $exact . 'x' )
		);
		$this->assertSame( 'malformed_header', $verify( $header . ',' . str_repeat( 'x', 8192 ) )['reason'] );
		$this->assertSame( 'malformed_header', $verify( $header . ',' . str_repeat( 'x', 100000 ) )['reason'] );
		// White space around the header does not count.
		$this->assertTrue( $verify( str_repeat( ' ', 5000 ) . $exact . str_repeat( "\n", 5000 ) )['ok'] );
		// A three-byte character is one UTF-16 unit, a four-byte character two.
		$room = 8192 - strlen( $header ) - 1;
		$this->assertTrue( $verify( $header . ',' . str_repeat( "\xE2\x82\xAC", $room ) )['ok'] );
		$this->assertSame( 'malformed_header', $verify( $header . ',' . str_repeat( "\xE2\x82\xAC", $room + 1 ) )['reason'] );
		$this->assertTrue( $verify( $header . ',' . str_repeat( "\xF0\x9F\x98\x80", intdiv( $room, 2 ) ) )['ok'] );
		$this->assertSame( 'malformed_header', $verify( $header . ',' . str_repeat( "\xF0\x9F\x98\x80", intdiv( $room, 2 ) + 1 ) )['reason'] );
	}

	/**
	 * ParseSignatureHeader: `t` is 1 to 13 digits, and a bad `t` anywhere refuses the header.
	 */
	public function test_t_must_be_one_to_thirteen_digits(): void {
		$case   = self::vector( 'valid_transfer_received' );
		$header = self::header_of( $case );
		$v1     = substr( $header, strpos( $header, ',' ) );
		$verify = function ( string $h ) use ( $case ) {
			return Verifier::verify( $case['body'], $h, $case['secrets'], $case['now'] );
		};
		$this->assertSame(
			array(
				'ok'     => false,
				'reason' => 'malformed_header',
				't'      => null,
			),
			$verify( 't=' . str_pad( '1791725802', 14, '0', STR_PAD_LEFT ) . $v1 )
		);
		// Thirteen digits with leading zeros are the same number, and the signed payload uses its plain decimal form.
		$padded = 't=' . str_pad( '1791725802', 13, '0', STR_PAD_LEFT ) . $v1;
		$this->assertSame( 1791725802, Verifier::parse_header( $padded )['t'] );
		$this->assertTrue( $verify( $padded )['ok'] );
		$this->assertTrue( self::kit_verify( $padded, $case['body'], $case['secrets'], $case['now'] ) );
		$this->assertSame( 'malformed_header', $verify( 't=' . $v1 )['reason'] );
		$this->assertSame( 'malformed_header', $verify( 't=-1791725802' . $v1 )['reason'] );
		$this->assertSame( 'malformed_header', $verify( 't=1791725802.5' . $v1 )['reason'] );
		$this->assertSame( 'malformed_header', $verify( ltrim( $v1, ',' ) )['reason'] );
		$this->assertSame( 'malformed_header', $verify( $header . ',t=abc' )['reason'] );
		$this->assertSame( 'malformed_header', $verify( $header . ",t=1\n2" )['reason'] );
		$this->assertTrue( $verify( "t=1791725802\n" . $v1 )['ok'] ); // trimmed before the digit check
		// The last good `t` wins.
		$this->assertTrue( $verify( 't=5,' . $header )['ok'] );
		$this->assertSame( 'stale_timestamp', $verify( $header . ',t=5' )['reason'] );
		$this->assertSame( 5, $verify( $header . ',t=5' )['t'] );
	}

	/**
	 * ParseSignatureHeader: a v1 value that is not 64 hex characters is ignored, others are lower-cased.
	 */
	public function test_malformed_v1_values_are_ignored(): void {
		$case   = self::vector( 'valid_transfer_received' );
		$header = self::header_of( $case );
		$t      = substr( $header, 0, strpos( $header, ',' ) );
		$hex    = substr( $header, strpos( $header, 'v1=' ) + 3 );
		$verify = function ( string $h ) use ( $case ) {
			return Verifier::verify( $case['body'], $h, $case['secrets'], $case['now'] );
		};
		$result = $verify( $t . ',v1=zz,v1=' . substr( $hex, 1 ) . ',v1=' . $hex . 'a,v1=' . $hex );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 0, $result['v1_index'] ); // the position among the well-formed values
		$this->assertSame( array( $hex ), Verifier::parse_header( $t . ',v1=zz,v1=' . $hex )['v1'] );
		$this->assertTrue( $verify( $t . ',v1=' . strtoupper( $hex ) )['ok'] );
		$this->assertSame( array( $hex ), Verifier::parse_header( $t . ',v1=' . strtoupper( $hex ) )['v1'] );
		$this->assertSame( 'malformed_header', $verify( $t . ',v1=' . substr( $hex, 1 ) )['reason'] );
		$this->assertSame( 'malformed_header', $verify( $t . ',v1=' . $hex . '0' )['reason'] );
		$this->assertSame( 'malformed_header', $verify( $t . ',v2=' . $hex )['reason'] );
		$this->assertTrue( $verify( 'v0=1,' . $header . ',junk,v2=' . $hex . ',=x' )['ok'] );
		$wrong = $verify( $t . ',v1=' . str_repeat( 'a', 64 ) );
		$this->assertSame( 'bad_signature', $wrong['reason'] );
	}

	/**
	 * The reasons come in the core's order: no_key, raw_body_unavailable, missing_header, malformed_header,
	 * stale_timestamp, bad_signature.
	 */
	public function test_reason_order(): void {
		$case = self::vector( 'valid_transfer_received' );
		$now  = $case['now'];
		$this->assertSame( 'no_key', Verifier::verify( null, null, array(), $now )['reason'] );
		$this->assertSame( 'no_key', Verifier::verify( null, 'garbage', array( '', ' ' ), $now )['reason'] );
		$this->assertSame( 'raw_body_unavailable', Verifier::verify( null, null, $case['secrets'], $now )['reason'] );
		$this->assertSame( 'raw_body_unavailable', Verifier::verify( null, self::header_of( $case ), $case['secrets'], $now )['reason'] );
		$this->assertSame( 'missing_header', Verifier::verify( $case['body'], null, $case['secrets'], $now )['reason'] );
		$this->assertSame( 'missing_header', Verifier::verify( $case['body'], array( self::header_of( $case ) ), $case['secrets'], $now )['reason'] );
		$this->assertSame( 'missing_header', Verifier::verify( $case['body'], " \t\r\n", $case['secrets'], $now )['reason'] );
		$this->assertSame( 'missing_header', Verifier::verify( $case['body'], "\xC2\xA0\xE3\x80\x80\xEF\xBB\xBF", $case['secrets'], $now )['reason'] );
		$this->assertSame( 'malformed_header', Verifier::verify( $case['body'], 'garbage', $case['secrets'], $now + 100000 )['reason'] );
		$this->assertSame( 'stale_timestamp', Verifier::verify( 'tampered', self::header_of( $case ), array( self::other_key( 1 ) ), $now + 100000 )['reason'] );
		$this->assertSame( 'bad_signature', Verifier::verify( 'tampered', self::header_of( $case ), $case['secrets'], $now )['reason'] );
		foreach ( array( 'no_key', 'raw_body_unavailable', 'missing_header', 'malformed_header', 'stale_timestamp', 'bad_signature' ) as $i => $reason ) {
			$this->assertSame( $reason, Verifier::REASONS[ $i ] );
		}
	}

	/**
	 * An empty body is a body: it verifies when it was signed.
	 */
	public function test_an_empty_body_is_not_a_missing_body(): void {
		$secret = self::vectors()['secret'];
		$header = Verifier::sign_header( 1791725802, '', array( $secret ) );
		$this->assertTrue( Verifier::verify( '', $header, array( $secret ), 1791725802 )['ok'] );
		$this->assertTrue( self::kit_verify( $header, '', array( $secret ), 1791725802 ) );
	}

	/**
	 * Parse_header returns the core's shape, and trims as JavaScript does.
	 */
	public function test_parse_header_shape_and_trimming(): void {
		$case   = self::vector( 'valid_transfer_received' );
		$header = self::header_of( $case );
		$parsed = Verifier::parse_header( $header );
		$this->assertSame( array( 't', 'v1' ), array_keys( $parsed ) );
		$this->assertSame( 1791725802, $parsed['t'] );
		$this->assertSame( array( substr( $header, strpos( $header, 'v1=' ) + 3 ) ), $parsed['v1'] );
		$this->assertNull( Verifier::parse_header( null ) );
		$this->assertNull( Verifier::parse_header( 1791725802 ) );
		$this->assertNull( Verifier::parse_header( '' ) );
		$this->assertNull( Verifier::parse_header( '   ' ) );
		$this->assertSame( $parsed, Verifier::parse_header( "\xEF\xBB\xBF\xC2\xA0" . $header . "\xE2\x80\xA8\xE3\x80\x80" ) );
		$this->assertSame( $parsed, Verifier::parse_header( str_replace( ',', "\xE2\x80\x80,\xE2\x80\x8A", $header ) ) );
		$this->assertSame( 'x', Verifier::trim_js( "\t\n\x0B\x0C\r \xC2\xA0\xE1\x9A\x80\xE2\x80\x80x\xE2\x80\xAF\xE2\x81\x9F\xEF\xBB\xBF" ) );
		$this->assertSame( "\0x\0", Verifier::trim_js( "\0x\0" ) );
		$this->assertSame( "\xE2\x80\x8Bx", Verifier::trim_js( "\xE2\x80\x8Bx" ) ); // U+200B is not white space
		$this->assertSame( '', Verifier::trim_js( '' ) );
		$this->assertSame( "\xFF", Verifier::trim_js( " \xFF " ) );
	}
}
