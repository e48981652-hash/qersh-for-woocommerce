<?php
/**
 * Tests of the pure rules behind the review fixes of the admin area: which payer numbers the personal data tools
 * may follow and which transfers the eraser may blank, the policy text's markup, the self-test's response headers
 * and the address the phone last used, the options of the old transaction-id drift, attaching to an order created
 * in the admin, and apostrophes in plain-text emails.
 *
 * @package QershPay\WooCommerce\Tests
 */

declare( strict_types=1 );

namespace QershPay\WooCommerce\Tests\Unit\Admin;

use QershPay\WooCommerce\Admin\OrderBox;
use QershPay\WooCommerce\Admin\Transfers;
use QershPay\WooCommerce\Admin\Uninstall;
use QershPay\WooCommerce\Admin\View;
use QershPay\WooCommerce\Diagnostics;
use QershPay\WooCommerce\Privacy;
use QershPay\WooCommerce\Store\Schema;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

defined( 'ABSPATH' ) || exit;

/**
 * Review fixes of the admin area, without WordPress.
 */
final class ReviewFindingsFixesTest extends TestCase {

	const WALLET = 'k7q2m9x4p1rz';

	/**
	 * A transfer row.
	 *
	 * @param array $fields Fields.
	 * @return array
	 */
	private function row( array $fields ): array {
		return array_merge(
			array(
				'transfer_id' => 'trf_01JA2Y4X5M7Q9R0S1T2U3V4W5Z',
				'payer_key'   => '01112233445',
				'payer_name'  => 'Payer Name',
				'outcome'     => 'unmatched',
				'reason'      => 'personal',
				'order_id'    => null,
				'flag'        => null,
			),
			$fields
		);
	}

	/*
	 * Privacy.
	 */

	/** Only a transfer that paid the order proves its number: never a claim, a review row or a forged message. */
	public function test_privacy_paying_keys(): void {
		$rows = array(
			$this->row(
				array(
					'order_id' => 7,
					'outcome'  => 'matched',
				)
			),
			$this->row(
				array(
					'order_id'  => 7,
					'outcome'   => 'partial',
					'payer_key' => '01000000002',
				)
			),
			$this->row(
				array(
					'order_id'  => 7,
					'outcome'   => 'attached',
					'payer_key' => 'shop@instapay',
				)
			),
			$this->row(
				array(
					'order_id'  => 7,
					'outcome'   => 'overpaid',
					'payer_key' => '01112233445',
				)
			),
			$this->row(
				array(
					'order_id'  => 7,
					'outcome'   => 'review',
					'payer_key' => '01000000003',
				)
			),
			$this->row(
				array(
					'order_id'  => 7,
					'outcome'   => 'matched',
					'flag'      => 'forgery',
					'payer_key' => '01000000004',
				)
			),
			$this->row(
				array(
					'order_id'  => 8,
					'outcome'   => 'matched',
					'payer_key' => '01000000005',
				)
			),
			$this->row(
				array(
					'order_id'  => 7,
					'outcome'   => 'matched',
					'payer_key' => null,
				)
			),
			$this->row(
				array(
					'outcome'   => 'unmatched',
					'payer_key' => '01000000006',
				)
			),
		);
		$this->assertSame( array( '01112233445', '01000000002', 'shop@instapay' ), Privacy::paying_keys( $rows, 7 ) );
		$this->assertSame( array(), Privacy::paying_keys( $rows, 0 ) );
		$this->assertSame( array(), Privacy::paying_keys( array( 'x' ), 7 ) );
		$this->assertSame( array( '01000000005' ), Privacy::paying_keys( $rows, 8 ) );
	}

	/** The eraser blanks personal and dismissed transfers, never one the merchant still has to decide on. */
	public function test_privacy_erasable(): void {
		$this->assertTrue( Privacy::erasable( $this->row( array() ) ), 'personal' );
		$this->assertTrue( Privacy::erasable( $this->row( array( 'outcome' => 'dismissed' ) ) ), 'dismissed' );
		$this->assertTrue(
			Privacy::erasable(
				$this->row(
					array(
						'outcome' => 'dismissed',
						'flag'    => 'forgery',
						'reason'  => 'claim_overdue',
					)
				)
			),
			'a dismissed row left "Needs a decision"'
		);
		foreach ( array(
			'review'           => array(
				'outcome' => 'review',
				'reason'  => 'closed_order',
			),
			'duplicate'        => array(
				'outcome' => 'review',
				'reason'  => 'duplicate_txn',
			),
			'overpaid'         => array(
				'outcome' => 'overpaid',
				'reason'  => null,
			),
			'unmatched'        => array( 'reason' => null ),
			'unknown risk'     => array( 'reason' => 'unknown_risk' ),
			'claim overdue'    => array( 'reason' => 'claim_overdue' ),
			'personal forgery' => array( 'flag' => 'forgery' ),
			'processing'       => array(
				'outcome' => null,
				'reason'  => null,
			),
			'linked'           => array(
				'order_id' => 12,
				'outcome'  => 'dismissed',
			),
		) as $label => $fields ) {
			$this->assertFalse( Privacy::erasable( $this->row( $fields ) ), $label );
		}
	}

	/**
	 * The Policy Guide copies the plain paragraphs of `wp-suggested-text` and leaves the tutorial note out.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_privacy_policy_html(): void {
		require_once dirname( __DIR__ ) . '/FakeWpEscaping.php';
		$html = Privacy::policy_html( 'Note for the owner.', array( 'First <paragraph>.', 'Our store\'s phone.' ) );
		$this->assertSame(
			'<div class="wp-suggested-text"><p class="privacy-policy-tutorial">Note for the owner.</p><p>First &lt;paragraph&gt;.</p><p>Our store&#039;s phone.</p></div>',
			$html
		);
		$this->assertSame( 1, substr_count( $html, 'privacy-policy-tutorial' ) );
		$this->assertSame( '<div class="wp-suggested-text"><p>Only text.</p></div>', Privacy::policy_html( '', array( 'Only text.' ) ) );
	}

	/*
	 * Diagnostics: the self-test's headers.
	 */

	/**
	 * A stand-in shaped like WpOrg\Requests\Utility\CaseInsensitiveDictionary: the headers in a protected property,
	 * read through getAll().
	 *
	 * @param array $data Lower-case name => value or list of values.
	 * @return object
	 */
	private function dictionary( array $data ) {
		return new class( $data ) {
			/**
			 * Headers.
			 *
			 * @var array
			 */
			protected $data;

			/**
			 * Builds it.
			 *
			 * @param array $data Headers.
			 */
			public function __construct( array $data ) {
				$this->data = $data;
			}

			/**
			 * All headers.
			 *
			 * @return array
			 */
			public function getAll() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- the name of the Requests method.
				return $this->data;
			}
		};
	}

	/** Headers come from getAll(), never from casting the object (its protected property). */
	public function test_header_list(): void {
		$raw     = $this->dictionary(
			array(
				'content-type' => 'text/html; charset=UTF-8',
				'cf-mitigated' => 'challenge',
				'server'       => 'cloudflare',
				'location'     => 'https://shop.example/login',
				'set-cookie'   => array( 'a=1', 'b=2' ),
			)
		);
		$headers = Diagnostics::header_list( $raw );
		$this->assertSame( array( 'content-type', 'cf-mitigated', 'server', 'location', 'set-cookie' ), array_keys( $headers ) );
		$this->assertSame( 'a=1, b=2', $headers['set-cookie'] );
		$this->assertSame(
			array(
				'verdict' => 'cloudflare',
				'detail'  => 'challenge',
			),
			Diagnostics::classify(
				array(
					'error'   => null,
					'code'    => 403,
					'headers' => $headers,
					'body'    => '<html>Just a moment...</html>',
				),
				'webhook'
			)
		);

		$redirect = Diagnostics::header_list( $this->dictionary( array( 'location' => 'https://www.shop.example/wp-json/qersh/v1/webhook/' . self::WALLET ) ) );
		$this->assertSame(
			array(
				'verdict' => 'redirect',
				'detail'  => 'https://www.shop.example/wp-json/qersh/v1/webhook/' . self::WALLET,
			),
			Diagnostics::classify(
				array(
					'error'   => null,
					'code'    => 301,
					'headers' => $redirect,
					'body'    => '',
				),
				'webhook'
			)
		);

		$this->assertSame(
			array(
				'content-type' => 'application/json',
				'x-sucuri-id'  => '1',
			),
			Diagnostics::header_list(
				array(
					'Content-Type' => 'application/json',
					'X-Sucuri-ID'  => 1,
				)
			),
			'a plain array from a pre_http_request filter'
		);
		$this->assertSame(
			array( 'vary' => 'Accept, Origin' ),
			Diagnostics::header_list(
				array(
					'Vary' => 'Accept',
					'vary' => 'Origin',
				)
			)
		);
		$this->assertSame( array(), Diagnostics::header_list( null ) );
		$this->assertSame( array(), Diagnostics::header_list( 'x' ) );
		$this->assertSame( array(), Diagnostics::header_list( new \stdClass() ) );
		$this->assertSame(
			array( 'a' => '1' ),
			Diagnostics::header_list(
				array(
					'a' => '1',
					'b' => new \stdClass(),
				)
			)
		);
	}

	/*
	 * Diagnostics: the address the phone last used.
	 */

	/**
	 * A qersh_wallets row.
	 *
	 * @param string      $host Host.
	 * @param string|null $form Form.
	 * @return array
	 */
	private function seen( string $host, ?string $form ): array {
		return array(
			'seen_host' => $host,
			'seen_form' => $form,
		);
	}

	/** With PATHINFO permalinks the rebuilt address keeps index.php: on the same host it is the current one. */
	public function test_rebuild_seen_url(): void {
		$route    = '/qersh/v1/webhook/' . self::WALLET;
		$pathinfo = 'https://shop.example/index.php/wp-json' . $route;
		$this->assertSame( $pathinfo, Diagnostics::rebuild_seen_url( self::WALLET, $this->seen( 'shop.example', 'wp-json' ), $pathinfo, '/', 'wp-json' ) );
		$this->assertSame( 'https://old.example/index.php/wp-json' . $route, Diagnostics::rebuild_seen_url( self::WALLET, $this->seen( 'old.example', 'wp-json' ), $pathinfo, '/', 'wp-json' ) );

		$sub = 'https://shop.example/shop/api' . $route;
		$this->assertSame( 'https://old.example/shop/api' . $route, Diagnostics::rebuild_seen_url( self::WALLET, $this->seen( 'old.example', null ), $sub, '/shop/', 'api' ), 'subdirectory, filtered prefix, form null' );

		$plain = 'https://shop.example/index.php?rest_route=' . $route;
		$this->assertSame( 'https://old.example/index.php?rest_route=' . $route, Diagnostics::rebuild_seen_url( self::WALLET, $this->seen( 'old.example', 'rest_route' ), $plain, '/', 'wp-json' ) );
		$this->assertSame( 'https://shop.example/wp-json' . $route, Diagnostics::rebuild_seen_url( self::WALLET, $this->seen( 'shop.example', 'wp-json' ), $plain, '/', 'wp-json' ), 'permalinks changed to plain since' );
		$this->assertSame(
			'https://shop.example/shop/index.php?rest_route=' . rawurlencode( $route ),
			Diagnostics::rebuild_seen_url( self::WALLET, $this->seen( 'shop.example', 'rest_route' ), 'https://shop.example/shop/wp-json' . $route, '/shop/', 'wp-json' ),
			'permalinks changed to pretty since'
		);
		$this->assertSame( 'https://shop.example/?wc-api=qersh&qw=' . self::WALLET, Diagnostics::rebuild_seen_url( self::WALLET, $this->seen( 'shop.example', 'wc-api' ), $pathinfo, '/', 'wp-json' ) );

		$local = 'http://127.0.0.1:8899/wp-json' . $route;
		$this->assertSame( $local, Diagnostics::rebuild_seen_url( self::WALLET, $this->seen( '127.0.0.1:8899', 'wp-json' ), $local, '/', 'wp-json' ), 'local http' );
		$this->assertSame( 'https://shop.example:8443/wp-json' . $route, Diagnostics::rebuild_seen_url( self::WALLET, $this->seen( 'shop.example:8443', 'odd' ), $local, '/', 'wp-json' ), 'a public host is https' );
		$this->assertNull( Diagnostics::rebuild_seen_url( self::WALLET, $this->seen( '', 'wp-json' ), $pathinfo, '/', 'wp-json' ) );
	}

	/*
	 * Diagnostics: the transaction-id drift of 0.1.0 is gone.
	 */

	/**
	 * Nothing counts refused transaction ids any more (the reading no longer depends on a wallet type), but the two
	 * options 0.1.0 wrote stay in the uninstall list, so an uninstall still removes them from an older store, and the
	 * upgrade to schema 2 deletes the same two.
	 */
	public function test_uninstall_removes_the_old_drift_options(): void {
		foreach ( array( 'qersh_wc_txn_long', 'qersh_wc_txn_drift' ) as $option ) {
			$this->assertContains( $option, Uninstall::OPTIONS );
			$this->assertContains( $option, Schema::OBSOLETE_OPTIONS );
		}
		foreach ( Schema::OBSOLETE_OPTIONS as $option ) {
			$this->assertContains( $option, Uninstall::OPTIONS, 'an option the upgrade deletes is still removed by an uninstall' );
		}
		foreach ( array( 'TXN_LONG_OPTION', 'DRIFT_OPTION' ) as $constant ) {
			$this->assertFalse( defined( Diagnostics::class . '::' . $constant ), $constant );
		}
		foreach ( array( 'long_run_ok', 'txn_long_tally', 'record_txn_refusal' ) as $method ) {
			$this->assertFalse( method_exists( Diagnostics::class, $method ), $method );
		}
	}

	/*
	 * Orders created in the admin.
	 */

	/** An order from WooCommerce's "Add order" screen has no method yet and can take a transfer. */
	public function test_method_attachable(): void {
		$this->assertTrue( Transfers::method_attachable( 'qersh' ) );
		$this->assertTrue( Transfers::method_attachable( '' ) );
		$this->assertFalse( Transfers::method_attachable( 'cod' ) );
		$this->assertFalse( Transfers::method_attachable( 'bacs' ) );
	}

	/** The order box shows on our orders and on a pending admin order without a method. */
	public function test_order_box_shows(): void {
		$this->assertTrue( OrderBox::shows( 'qersh', 'processing', 'checkout' ) );
		$this->assertTrue( OrderBox::shows( '', 'pending', 'admin' ) );
		$this->assertFalse( OrderBox::shows( '', 'processing', 'admin' ) );
		$this->assertFalse( OrderBox::shows( '', 'pending', 'checkout' ) );
		$this->assertFalse( OrderBox::shows( 'cod', 'pending', 'admin' ) );
	}

	/*
	 * Plain-text emails.
	 */

	/**
	 * WC_Email's plain-text rules of WooCommerce 11.2.1 (includes/emails/class-wc-email.php).
	 *
	 * @return array `[ search, replace ]`
	 */
	private function woocommerce_plain_rules(): array {
		return array(
			array(
				"/\r/",
				'/&(nbsp|#0*160);/i',
				'/&(quot|rdquo|ldquo|#0*8220|#0*8221|#0*147|#0*148);/i',
				'/&(apos|rsquo|lsquo|#0*8216|#0*8217);/i',
				'/&gt;/i',
				'/&lt;/i',
				'/&#0*38;/i',
				'/&amp;/i',
				'/&(copy|#0*169);/i',
				'/&(trade|#0*8482|#0*153);/i',
				'/&(reg|#0*174);/i',
				'/&(mdash|#0*151|#0*8212);/i',
				'/&(ndash|minus|#0*8211|#0*8722);/i',
				'/&(bull|#0*149|#0*8226);/i',
				'/&(pound|#0*163);/i',
				'/&(euro|#0*8364);/i',
				'/&(dollar|#0*36);/i',
				'/&[^&\s;]+;/i',
				'/[ ]{2,}/',
			),
			array( '', ' ', '"', "'", '>', '<', '&', '&', '(c)', '(tm)', '(R)', '--', '-', '*', '£', 'EUR', '$', '', ' ' ),
		);
	}

	/** The apostrophe esc_html() writes survives WooCommerce's plain-text processing. */
	public function test_plain_text_rules(): void {
		list( $search, $replace ) = $this->woocommerce_plain_rules();
		$escaped                  = 'This transfer&#039;s message is suspected to be forged. A wallet&#x27;s phone &amp; O&#39;Brien &quot;x&quot;.';
		$this->assertSame( 'This transfers message is suspected to be forged. A wallets phone & OBrien "x".', preg_replace( $search, $replace, $escaped ), 'WooCommerce alone drops it' );

		list( $ours_search, $ours_replace ) = View::plain_text_rules( $search, $replace );
		$this->assertCount( count( $ours_search ), $ours_replace );
		$this->assertSame( "This transfer's message is suspected to be forged. A wallet's phone & O'Brien \"x\".", preg_replace( $ours_search, $ours_replace, $escaped ) );
		$this->assertSame( preg_replace( $search, $replace, 'An unknown &foo; entity, &#8217; and &#3900;' ), preg_replace( $ours_search, $ours_replace, 'An unknown &foo; entity, &#8217; and &#3900;' ), 'other entities as before' );
		$this->assertSame( 'An unknown entity', preg_replace( $ours_search, $ours_replace, 'An unknown &foo; entity' ) );

		$twice = View::plain_text_rules( $ours_search, $ours_replace );
		$this->assertSame( array( $ours_search, $ours_replace ), $twice, 'added once' );

		$odd = View::plain_text_rules( array( '/a/' ), array() );
		$this->assertSame( array( array( '/a/' ), array() ), $odd, 'lists of different lengths are left alone' );
	}
}
