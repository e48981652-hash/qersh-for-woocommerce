<?php
/**
 * Tests of Core\SetupLink: every test of packages/qersh-webhook/test/setup-link.test.ts, the WHATWG URL edges the
 * PHP port has to reproduce without new URL(), and extract_secret() (docs/17 §4.1 and §4.8).
 *
 * @package QershPay\WooCommerce\Tests
 */

declare( strict_types=1 );

namespace QershPay\WooCommerce\Tests\Unit\Core;

use QershPay\WooCommerce\Core\SetupLink;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

defined( 'ABSPATH' ) || exit;

/**
 * Setup links and pasted keys.
 */
final class SetupLinkTest extends TestCase {

	/**
	 * The kit's published test vector keys (no key literal lives in this file).
	 *
	 * @param string $which `secret` or `previous_secret`.
	 * @return string
	 */
	private static function vector( string $which = 'secret' ): string {
		$vectors = json_decode( qersh_wc_test_fixture( 'fixtures/test-vectors.json' ), true );
		return (string) $vectors[ $which ];
	}

	/**
	 * The parsed link with every part.
	 *
	 * @param string      $url      URL.
	 * @param string|null $secret   Secret.
	 * @param string|null $label    Label.
	 * @param string|null $provider Provider.
	 * @param array|null  $events   Events.
	 * @return array
	 */
	private static function parsed( string $url, ?string $secret = null, ?string $label = null, ?string $provider = null, ?array $events = null ): array {
		return array(
			'ok'   => true,
			'link' => array(
				'url'      => $url,
				'secret'   => $secret,
				'label'    => $label,
				'provider' => $provider,
				'events'   => $events,
			),
		);
	}

	public function test_builds_exactly_what_send_mjs_prints_for_ordinary_values(): void {
		$url      = 'https://shop.example/qersh';
		$vector   = self::vector();
		$expected = 'qersh://setup?v=1&url=' . rawurlencode( $url ) . '&secret=' . rawurlencode( $vector ) . '&label=' . rawurlencode( 'My shop' ) . '&provider=' . rawurlencode( 'vodafone_cash' );
		$this->assertSame(
			$expected,
			SetupLink::build(
				array(
					'url'      => $url,
					'secret'   => $vector,
					'label'    => 'My shop',
					'provider' => 'vodafone_cash',
				)
			)
		);
		$this->assertSame( 'qersh://setup?v=1&url=https%3A%2F%2Fshop.example%2Fqersh&secret=' . $vector . '&label=My%20shop&provider=vodafone_cash', $expected );
	}

	public function test_a_key_less_link_carries_only_url_label_and_provider(): void {
		$base = 'qersh://setup?v=1&url=' . rawurlencode( 'https://n8n.example.com/webhook/qersh' );
		$this->assertSame( $base, SetupLink::build( array( 'url' => 'https://n8n.example.com/webhook/qersh' ) ) );
		$this->assertSame(
			$base,
			SetupLink::build(
				array(
					'url'      => 'https://n8n.example.com/webhook/qersh',
					'label'    => '  ',
					'provider' => '',
					'secret'   => null,
					'events'   => array(),
				)
			)
		);
	}

	public function test_label_cut_to_60_units_without_a_dangling_surrogate_and_provider_lower_cased(): void {
		$long = str_repeat( 'x', 70 );
		$link = SetupLink::build(
			array(
				'url'      => 'https://a.b/c',
				'label'    => $long,
				'provider' => 'Vodafone_Cash',
			)
		);
		$this->assertStringContainsString( '&label=' . str_repeat( 'x', 60 ) . '&provider=vodafone_cash', $link );
		$this->assertSame(
			array(
				'ok'       => true,
				'warnings' => array( 'label_truncated', 'provider_unknown' ),
			),
			SetupLink::validate(
				array(
					'url'      => 'https://a.b/c',
					'label'    => $long,
					'provider' => 'we_pay',
				)
			)
		);
		$emoji_cut = str_repeat( 'y', 59 ) . "\u{1F600}"; // the 60th unit is a high surrogate: not kept half
		$this->assertStringEndsWith(
			'&label=' . str_repeat( 'y', 59 ),
			SetupLink::build(
				array(
					'url'   => 'https://a.b/c',
					'label' => $emoji_cut,
				)
			)
		);
		$this->assertStringEndsWith(
			'&label=' . str_repeat( 'y', 58 ) . '%F0%9F%98%80',
			SetupLink::build(
				array(
					'url'   => 'https://a.b/c',
					'label' => str_repeat( 'y', 58 ) . "\u{1F600}",
				)
			)
		);
		// Bytes that are not UTF-8 (PHP's lone surrogate) never throw and become U+FFFD.
		$this->assertStringEndsWith(
			'&label=%EF%BF%BD',
			SetupLink::build(
				array(
					'url'   => 'https://a.b/c',
					'label' => "\xF0\x9F",
				)
			)
		);
	}

	public function test_characters_the_reader_would_eat_are_percent_encoded(): void {
		$built = SetupLink::build(
			array(
				'url'   => 'https://a.b/c',
				'label' => 'Shop (Cairo)',
			)
		);
		$this->assertStringEndsWith( '&label=Shop%20%28Cairo%29', $built );
		$this->assertSame( self::parsed( 'https://a.b/c', null, 'Shop (Cairo)' ), SetupLink::parse( $built . '.' ) );
		$dot = SetupLink::build(
			array(
				'url'   => 'https://a.b/c',
				'label' => 'Shop Inc.',
			)
		);
		$this->assertStringEndsWith( 'Inc%2E', $dot );
		$this->assertSame( 'Shop Inc.', SetupLink::parse( $dot )['link']['label'] );
		$quoted = SetupLink::build(
			array(
				'url'   => 'https://a.b/c',
				'label' => "Ahmed's",
			)
		);
		$this->assertStringEndsWith( 'Ahmed%27s', $quoted );
		$this->assertTrue( SetupLink::parse( "'" . $quoted . "'" )['ok'] );
		$this->assertSame(
			'qersh://setup?v=1&url=https%3A%2F%2Fa.b%2Fc&label=a%21%2A%28%29~',
			SetupLink::build(
				array(
					'url'   => 'https://a.b/c',
					'label' => 'a!*()~',
				)
			)
		);
		$this->assertSame( 'qersh://setup?v=1&url=https%3A%2F%2Fa.b%2Fc%2E', SetupLink::build( array( 'url' => 'https://a.b/c.' ) ) );
	}

	public function test_events_only_the_seven_names_and_never_written_by_build(): void {
		$this->assertCount( 7, SetupLink::EVENTS );
		$this->assertTrue(
			SetupLink::validate(
				array(
					'url'    => 'https://a.b/c',
					'events' => array( 'transfer.received', ' transfer.updated ' ),
				)
			)['ok']
		);
		$this->assertSame(
			array(
				'ok'      => false,
				'problem' => 'event_unknown',
				'detail'  => 'webhook.test',
			),
			SetupLink::validate(
				array(
					'url'    => 'https://a.b/c',
					'events' => array( 'webhook.test' ),
				)
			)
		);
		$this->assertSame(
			array(
				'ok'      => false,
				'problem' => 'event_unknown',
				'detail'  => 'transfer.reversed',
			),
			SetupLink::validate(
				array(
					'url'    => 'https://a.b/c',
					'events' => array( 'transfer.reversed' ),
				)
			)
		);
		// The TypeScript writes &events=…; the plugin never does (docs/17 §4.1).
		$this->assertSame(
			'qersh://setup?v=1&url=https%3A%2F%2Fa.b%2Fc',
			SetupLink::build(
				array(
					'url'    => 'https://a.b/c',
					'events' => array( 'transfer.received', 'device.heartbeat' ),
				)
			)
		);
	}

	public function test_url_rules_follow_webhook_url_is_valid(): void {
		$this->assertTrue( SetupLink::validate( array( 'url' => 'https://n8n.example.com/webhook/qersh' ) )['ok'] );
		$this->assertTrue( SetupLink::validate( array( 'url' => 'http://192.168.1.20:5678/webhook/qersh' ) )['ok'] );
		$this->assertTrue( SetupLink::validate( array( 'url' => 'http://localhost:5678/webhook/qersh' ) )['ok'] );
		$this->assertTrue( SetupLink::validate( array( 'url' => 'http://my-n8n.local/webhook/qersh' ) )['ok'] );
		$problems = array(
			'http://n8n.example.com/webhook/qersh'    => 'url_insecure_remote',
			'http://[::1]:5678/webhook'               => 'url_insecure_remote',
			'http://a.b.local/webhook'                => 'url_insecure_remote',
			'https://user:pw@n8n.example.com/webhook' => 'url_userinfo',
			'https://@n8n.example.com/webhook'        => 'url_userinfo',
			'ftp://n8n.example.com/webhook'           => 'url_scheme',
			'https://' . str_repeat( 'a', 2050 )      => 'url_too_long',
			''                                        => 'url_missing',
			'not a url'                               => 'url_invalid',
		);
		foreach ( $problems as $url => $problem ) {
			$this->assertSame(
				array(
					'ok'      => false,
					'problem' => $problem,
				),
				SetupLink::validate( array( 'url' => (string) $url ) ),
				(string) $url
			);
		}
		$this->assertSame( 'url_missing', SetupLink::validate( array() )['problem'] );
		foreach ( array( '10.0.0.5', '172.16.0.1', '172.31.255.255', '127.0.0.1', 'LOCALHOST', 'printer.local' ) as $h ) {
			$this->assertTrue( SetupLink::is_local_http_host( $h ), $h );
		}
		foreach ( array( '172.32.0.1', '11.0.0.1', '8.8.8.8', 'example.com', '::1', 'a.b.local', 'under_score.local', "localhost\n", '169.254.1.1', '0.0.0.0' ) as $h ) {
			$this->assertFalse( SetupLink::is_local_http_host( $h ), $h );
		}
	}

	public function test_url_parsing_matches_the_whatwg_parser(): void {
		// Each expectation is what new URL() in Node gives (checked against tests/parity/core/setup-link.js).
		$cases = array(
			'https:example.com'          => true,
			'HTTPS://EXAMPLE.COM'        => true,
			'http://LOCALHOST/'          => true,
			'http://127.1/'              => true, // IPv4 shorthand: 127.0.0.1
			'http://0x7f.0.0.1/'         => true,
			'http://0177.0.0.1/'         => true,
			'http://3232235777/'         => true, // 192.168.1.1
			'http://169.254.1.1/'        => 'url_insecure_remote',
			'http://256.0.0.1/'          => 'url_invalid',
			'http://a.1/'                => 'url_invalid',
			'http://4294967296/'         => 'url_invalid',
			'https://[::ffff:1.2.3.4]/'  => true,
			'https://[::ffff:1.02.3.4]/' => 'url_invalid',
			'https://[1::2::3]/'         => 'url_invalid',
			'https://[::1'               => 'url_invalid',
			'https://host:65535/'        => true,
			'https://host:65536/'        => 'url_invalid',
			'https://host:abc/'          => 'url_invalid',
			'https://user@/'             => 'url_invalid',
			'https:user@host/'           => 'url_userinfo',
			'https://a.b\\@x'            => 'url_userinfo',
			'mailto:foo@example.com'     => 'url_invalid',
			'foo://bar/'                 => 'url_scheme',
			'file://server/share'        => 'url_scheme',
			'file:///C:/x'               => 'url_invalid',
			'file://localhost/x'         => 'url_invalid',
			'https://a%2Fb/'             => 'url_invalid',
			'https://a b/'               => 'url_invalid',
			'https://a_b.com/'           => true,
			"ht\ttps://a.b/"             => true,
			"\u{0000}https://a.b/"       => true,
		);
		foreach ( $cases as $url => $expected ) {
			$result = SetupLink::validate_receiver_url( (string) $url );
			$this->assertSame(
				true === $expected ? array( 'ok' => true ) : array(
					'ok'      => false,
					'problem' => $expected,
				),
				$result,
				(string) $url
			);
		}
		$this->assertSame( array( 'ok' => true ), SetupLink::validate_receiver_url( "https://\u{0645}\u{062A}\u{062C}\u{0631}.\u{0645}\u{0635}\u{0631}/wp-json" ) );
		$this->assertSame( 'url_missing', SetupLink::validate_receiver_url( 42 )['problem'] );
	}

	public function test_key_rules_match_the_app(): void {
		$vector = self::vector();
		$this->assertTrue( SetupLink::is_usable_secret( $vector ) );
		$this->assertTrue( SetupLink::is_usable_secret( 'a-merchant-chosen-secret-of-24' ) );
		$this->assertFalse( SetupLink::is_usable_secret( 'qsk_tooshort' ) );
		$this->assertFalse( SetupLink::is_usable_secret( 'qsk_' . str_repeat( 'x', 40 ) . '*' ) );
		$this->assertFalse( SetupLink::is_usable_secret( 'has a space inside it which is long enough' ) );
		$this->assertFalse( SetupLink::is_usable_secret( 'https://looks.like/a/url/not/a/key' ) );
		$this->assertSame(
			array(
				'ok'      => false,
				'problem' => 'secret_invalid',
			),
			SetupLink::validate(
				array(
					'url'    => 'https://a.b/c',
					'secret' => 'short',
				)
			)
		);
		$this->assertSame(
			array(
				'ok'       => true,
				'warnings' => array( 'secret_included' ),
			),
			SetupLink::validate(
				array(
					'url'    => 'https://a.b/c',
					'secret' => $vector,
				)
			)
		);
		$this->assertSame(
			'secret_invalid',
			SetupLink::validate(
				array(
					'url'    => 'https://a.b/c',
					'secret' => 42,
				)
			)['problem']
		);
		$this->assertSame(
			'secret_invalid',
			SetupLink::validate(
				array(
					'url'    => 'https://a.b/c',
					'secret' => '   ',
				)
			)['problem']
		); // as the TypeScript
		$this->assertTrue(
			SetupLink::validate(
				array(
					'url'    => 'https://a.b/c',
					'secret' => '',
				)
			)['ok']
		);
	}

	public function test_extraction_follows_the_app(): void {
		$link     = SetupLink::build(
			array(
				'url'      => 'https://n8n.example.com/webhook/qersh',
				'label'    => 'Shop',
				'provider' => 'instapay_app',
			)
		) . '&events=transfer.received'; // build() never writes events; the reader still reads them
		$expected = self::parsed( 'https://n8n.example.com/webhook/qersh', null, 'Shop', 'instapay_app', array( 'transfer.received' ) );
		$this->assertSame( $expected, SetupLink::parse( 'Here is your link: ' . $link . ' (open it on the phone)' ) );
		$this->assertSame( $expected, SetupLink::parse( 'Link: ' . $link . '.' ) );
		$this->assertSame( $expected, SetupLink::parse( 'Link: ' . $link . ')?:;,.' ) );
		$this->assertFalse( SetupLink::parse( 'Link: ' . $link . '!' )['ok'] ); // the app keeps a final "!" too
		$this->assertSame( $link, SetupLink::extract( '*' . $link . '*' ) );
		$this->assertSame( $link, SetupLink::extract( '_' . $link . '_ thanks' ) );
		$this->assertSame( $link, SetupLink::extract( $link . "\u{200B}\u{2026}" ) );
		$this->assertSame( $link, SetupLink::extract( "\u{00AB}" . $link . "\u{00BB}" ) );
		$this->assertSame( $link, SetupLink::extract( $link . "\u{060C}" ) );
		$this->assertSame( strtoupper( $link ), SetupLink::extract( strtoupper( $link ) ) );
		$this->assertNull( SetupLink::extract( "qer\u{017F}h://setup?v=1" ) ); // JavaScript /i does not fold the long s
		$this->assertSame(
			array(
				'ok'      => false,
				'problem' => 'not_a_setup_link',
			),
			SetupLink::parse( 'nothing here' )
		);
		$this->assertSame(
			array(
				'ok'      => false,
				'problem' => 'not_a_setup_link',
			),
			SetupLink::parse( null )
		);
		$this->assertSame(
			array(
				'ok'      => false,
				'problem' => 'bad_version',
			),
			SetupLink::parse( 'qersh://setup?v=2&url=' . rawurlencode( 'https://a.b/c' ) )
		);
		$this->assertSame(
			array(
				'ok'      => false,
				'problem' => 'event_unknown',
			),
			SetupLink::parse( 'qersh://setup?v=1&url=' . rawurlencode( 'https://a.b/c' ) . '&events=webhook.test' )
		);
		$malformed = SetupLink::parse( 'qersh://setup?v=1&url=' . rawurlencode( 'https://a.b/c' ) . '&label=%E0%A4%A' ); // malformed percent-encoding
		$this->assertTrue( $malformed['ok'] );
		$this->assertSame( "\u{FFFD}%A", $malformed['link']['label'] );
		$this->assertSame( 'a b', SetupLink::parse( 'qersh://setup?v=1&url=' . rawurlencode( 'https://a.b/c' ) . '&label=a+b' )['link']['label'] );
		$this->assertSame( 'url_missing', SetupLink::parse( 'qersh://setup?.' )['problem'] );
	}

	/**
	 * A successful extract_secret() result.
	 *
	 * @param string      $secret Key.
	 * @param string      $source Source.
	 * @param string|null $url    The link's receiver URL.
	 * @return array
	 */
	private static function secret( string $secret, string $source, ?string $url = null ): array {
		return array(
			'ok'     => true,
			'secret' => $secret,
			'source' => $source,
			'url'    => $url,
		);
	}

	public function test_extract_secret_reads_the_share_message(): void {
		$vector = self::vector();
		$url    = 'https://shop.example/wp-json/qersh/v1/webhook/k7q2m9x4p1rz';
		$link   = SetupLink::build(
			array(
				'url'      => $url,
				'secret'   => $vector,
				'label'    => 'فودافون كاش المتجر',
				'provider' => 'vodafone_cash',
			)
		);
		// The app's share_text and share_text_guide (strings_wallet.xml), Arabic and English.
		$arabic  = "رابط إعداد قرش لمحفظة «فودافون كاش المتجر»:\n" . $link . "\nيحوي مفتاح الربط (secret) وعنوان المتجر (url): استخدمهما في خادم المتجر للتحقق من التوقيع.\nالدليل: https://example.invalid/docs";
		$english = "Qersh setup link for wallet \u{201C}Shop\u{201D}:\n" . $link . "\nIt holds the connection key (secret) and the store URL (url): use them on the store server to verify the signature.\nGuide: https://example.invalid/en/docs";
		$this->assertSame( self::secret( $vector, 'message', $url ), SetupLink::extract_secret( $arabic ) );
		$this->assertSame( self::secret( $vector, 'message', $url ), SetupLink::extract_secret( $english ) );
		$this->assertSame( self::secret( $vector, 'link', $url ), SetupLink::extract_secret( "  \n" . $link . "\n" ) );
		$this->assertSame( self::secret( $vector, 'link', $url ), SetupLink::extract_secret( "\u{200F}" . $link . "\u{200E}" ) );
	}

	public function test_extract_secret_names_the_wallet_the_link_was_made_for(): void {
		// Two wallets on one phone: the share message of one must be told apart from the other's (package E refuses it).
		$vector = self::vector();
		$orange = SetupLink::build(
			array(
				'url'    => 'https://shop.example/wp-json/qersh/v1/webhook/aaaaaaaaaaaa',
				'secret' => $vector,
			)
		);
		$result = SetupLink::extract_secret( "رابط إعداد قرش لمحفظة «أورنج»:\n" . $orange );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'https://shop.example/wp-json/qersh/v1/webhook/aaaaaaaaaaaa', $result['url'] );
		$fallback = SetupLink::build(
			array(
				'url'    => 'https://shop.example/?wc-api=qersh&qw=bbbbbbbbbbbb',
				'secret' => $vector,
			)
		);
		$this->assertSame( 'https://shop.example/?wc-api=qersh&qw=bbbbbbbbbbbb', SetupLink::extract_secret( $fallback )['url'] );
		$this->assertNull( SetupLink::extract_secret( 'QERSH_WEBHOOK_SECRET=' . $vector )['url'] );
		$this->assertNull( SetupLink::extract_secret( $vector )['url'] );
	}

	public function test_extract_secret_refuses_a_link_without_a_key(): void {
		$keyless = SetupLink::build( array( 'url' => 'https://shop.example/wp-json/qersh/v1/webhook/k7q2m9x4p1rz' ) );
		$this->assertSame(
			array(
				'ok'    => false,
				'error' => 'keyless_link',
			),
			SetupLink::extract_secret( $keyless )
		);
		$this->assertSame(
			array(
				'ok'    => false,
				'error' => 'keyless_link',
			),
			SetupLink::extract_secret( 'افتح هذا الرابط: ' . $keyless )
		);
		$this->assertSame(
			array(
				'ok'    => false,
				'error' => 'invalid',
			),
			SetupLink::extract_secret( $keyless . '&secret=short' )
		);
		$this->assertSame(
			array(
				'ok'    => false,
				'error' => 'invalid',
			),
			SetupLink::extract_secret( 'qersh://setup?v=1&url=' . rawurlencode( 'http://shop.example/' ) . '&secret=' . self::vector() )
		);
	}

	public function test_extract_secret_reads_the_env_line(): void {
		$vector   = self::vector();
		$previous = self::vector( 'previous_secret' );
		$line     = self::secret( $vector, 'line' );
		$this->assertSame( $line, SetupLink::extract_secret( 'QERSH_WEBHOOK_SECRET=' . $vector ) );
		$this->assertSame( $line, SetupLink::extract_secret( 'export QERSH_WEBHOOK_SECRET = "' . $vector . '"' ) );
		$this->assertSame( $line, SetupLink::extract_secret( "QERSH_WEBHOOK_SECRET='" . $vector . "'\r\n" ) );
		$this->assertSame( $line, SetupLink::extract_secret( "# .env\nPORT=8787\nQERSH_WEBHOOK_SECRET=" . $vector . "\nQERSH_WEBHOOK_SECRET_PREVIOUS=" . $previous ) );
		$this->assertSame( $line, SetupLink::extract_secret( 'QERSH_WEBHOOK_SECRET_PREVIOUS=' . $previous . "\nQERSH_WEBHOOK_SECRET=" . $vector ) );
		// The previous key stops working on the phone within 24 hours: never take it as the current one.
		$this->assertSame(
			array(
				'ok'    => false,
				'error' => 'invalid',
			),
			SetupLink::extract_secret( 'QERSH_WEBHOOK_SECRET_PREVIOUS=' . $previous )
		);
		$this->assertSame(
			array(
				'ok'    => false,
				'error' => 'invalid',
			),
			SetupLink::extract_secret( 'QERSH_WEBHOOK_SECRET=short' )
		);
		$this->assertSame(
			array(
				'ok'    => false,
				'error' => 'invalid',
			),
			SetupLink::extract_secret( 'QERSH_WEBHOOK_SECRET=https://shop.example/a/long/enough/address' )
		);
	}

	public function test_extract_secret_reads_the_key_itself(): void {
		$vector = self::vector();
		$key    = self::secret( $vector, 'key' );
		$this->assertSame( $key, SetupLink::extract_secret( $vector ) );
		$this->assertSame( $key, SetupLink::extract_secret( "  \u{FEFF}" . $vector . "\n" ) );
		$this->assertSame( $key, SetupLink::extract_secret( "\u{200F}" . $vector . "\u{202C}" ) ); // marks a chat copy leaves
		// Quotes around the key alone are removed, as the app's readPastedKey() does: the phone signs without them.
		$this->assertSame( $key, SetupLink::extract_secret( '"' . $vector . '"' ) );
		$this->assertSame( $key, SetupLink::extract_secret( "'" . $vector . "'" ) );
		$this->assertSame( $key, SetupLink::extract_secret( ' "' . $vector . '" ' ) );
		$this->assertSame( $key, SetupLink::extract_secret( "\u{200F}\"" . $vector . "\"\u{200E}" ) );
		$this->assertSame( $key, SetupLink::extract_secret( "\"' " . $vector . " '\"" ) ); // removeSurrounding("\"") then ("'"), then trim
		$this->assertSame( self::secret( 'a-merchant-chosen-secret-of-24', 'key' ), SetupLink::extract_secret( 'a-merchant-chosen-secret-of-24' ) );
		$this->assertSame(
			array(
				'ok'    => false,
				'error' => 'invalid',
			),
			SetupLink::extract_secret( '"qsk_short"' )
		);
		$this->assertSame(
			array(
				'ok'    => false,
				'error' => 'invalid',
			),
			SetupLink::extract_secret( '"https://shop.example/wp-json/qersh/v1/webhook/k7q2m9x4p1rz"' )
		);
		$this->assertSame(
			array(
				'ok'    => false,
				'error' => 'invalid',
			),
			SetupLink::extract_secret( 'qsk_short' )
		);
		$this->assertSame(
			array(
				'ok'    => false,
				'error' => 'invalid',
			),
			SetupLink::extract_secret( 'https://shop.example/wp-json/qersh/v1/webhook/k7q2m9x4p1rz' )
		);
		$this->assertSame(
			array(
				'ok'    => false,
				'error' => 'not_found',
			),
			SetupLink::extract_secret( 'please send me the key again' )
		);
		$this->assertSame(
			array(
				'ok'    => false,
				'error' => 'not_found',
			),
			SetupLink::extract_secret( "  \u{200F} " )
		);
		$this->assertSame(
			array(
				'ok'    => false,
				'error' => 'not_found',
			),
			SetupLink::extract_secret( '' )
		);
		$this->assertSame(
			array(
				'ok'    => false,
				'error' => 'not_found',
			),
			SetupLink::extract_secret( null )
		);
		$this->assertSame(
			array(
				'ok'    => false,
				'error' => 'not_found',
			),
			SetupLink::extract_secret( array( $vector ) )
		);
	}
}
