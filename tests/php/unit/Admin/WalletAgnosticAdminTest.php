<?php
/**
 * Tests that the admin never depends on the wallet type (docs/17 §17, 2026-10-18): a wallet is its number and its
 * name, the developer's link carries no type, and no admin screen, list or text reads, labels or filters by the
 * `provider` of a wallet entry or of a transfer row.
 *
 * @package QershPay\WooCommerce\Tests
 */

declare( strict_types=1 );

namespace QershPay\WooCommerce\Tests\Unit\Admin;

use QershPay\WooCommerce\Admin\View;
use QershPay\WooCommerce\Admin\Wallets;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

defined( 'ABSPATH' ) || exit;

/**
 * The wallet-agnostic admin, without WordPress.
 */
final class WalletAgnosticAdminTest extends TestCase {

	/** U+00A0, between the groups of a displayed number. */
	const NBSP = "\xC2\xA0";

	/** The wallet-type helpers are gone, so nothing can bring a type back to a screen. */
	public function test_no_type_helpers(): void {
		$this->assertFalse( method_exists( View::class, 'provider_label' ) );
		$this->assertFalse( defined( View::class . '::PROVIDER_ORDER' ) );
		$this->assertFalse( defined( View::class . '::DECISION_PROVIDERS' ) );
		$this->assertFalse( method_exists( Wallets::class, 'provider_options' ) );
		$names = array();
		foreach ( ( new \ReflectionMethod( Wallets::class, 'setup_link' ) )->getParameters() as $param ) {
			$names[] = $param->getName();
		}
		$this->assertSame( array( 'url', 'label', 'secret' ), $names );
		$this->assertCount( 1, ( new \ReflectionMethod( Wallets::class, 'address_problem' ) )->getParameters(), 'the number alone decides' );
	}

	/**
	 * The wallet's name: the merchant's label, otherwise "E-wallet" and the formatted number; a type an old entry
	 * still carries is never shown.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_wallet_name(): void {
		require_once __DIR__ . '/FakeWpI18n.php';
		$fallback = 'E-wallet 0100' . self::NBSP . '123' . self::NBSP . '4567';
		$this->assertSame(
			'Main wallet',
			View::wallet_name(
				array(
					'label'           => '  Main wallet ',
					'receive_address' => '01001234567',
				)
			)
		);
		$this->assertSame(
			$fallback,
			View::wallet_name(
				array(
					'label'           => '',
					'receive_address' => '01001234567',
				)
			)
		);
		$this->assertSame( $fallback, View::wallet_name( array( 'receive_address' => '01001234567' ) ), 'no label at all' );
		foreach ( array( 'vodafone_cash', 'orange_cash', 'etisalat_cash', 'some_new_wallet' ) as $provider ) {
			$this->assertSame(
				$fallback,
				View::wallet_name(
					array(
						'label'           => ' ',
						'provider'        => $provider,
						'receive_address' => '01001234567',
					)
				),
				'a legacy provider ' . $provider . ' is ignored'
			);
		}
		$this->assertSame( 'E-wallet 0111' . self::NBSP . '222' . self::NBSP . '3344', View::wallet_name( array( 'receive_address' => '01112223344' ) ) );
		$this->assertSame( '', View::wallet_name( array( 'provider' => 'orange_cash' ) ), 'a bare snapshot has no name' );
		$this->assertSame( '', View::wallet_name( null ) );
	}

	/**
	 * A name of the merchant's own decides whether a screen shows the number next to the name: without one the name
	 * is the number already (the wallet card and the order box then show it once).
	 */
	public function test_has_label(): void {
		$this->assertTrue(
			View::has_label(
				array(
					'label'           => ' Main wallet ',
					'receive_address' => '01001234567',
				)
			)
		);
		$this->assertFalse(
			View::has_label(
				array(
					'label'           => '  ',
					'receive_address' => '01001234567',
				)
			),
			'white space only'
		);
		$this->assertFalse( View::has_label( array( 'receive_address' => '01001234567' ) ), 'no label at all' );
		$this->assertFalse( View::has_label( array( 'label' => 12 ) ), 'not a string' );
		$this->assertFalse( View::has_label( null ) );

		$card = (string) file_get_contents( QERSH_WC_TESTS_ROOT . '/templates/admin/wallet-card.php' );
		$this->assertMatchesRegularExpression( '/if \( \$args\[\'named\'\] \) : \?>\s*<span class="qersh-wc-muted">/', $card, 'the card shows the number under a name of its own only' );
		$box = (string) file_get_contents( QERSH_WC_TESTS_ROOT . '/src/Admin/OrderBox.php' );
		$this->assertStringContainsString( 'null !== $wallet && View::has_label( $wallet )', $box, 'the order box names the number once for a wallet without a name' );
	}

	/**
	 * The admin's own files: classes, templates, script, the alert email and the privacy texts.
	 *
	 * @return string[] Path => source.
	 */
	private function admin_sources(): array {
		$root  = QERSH_WC_TESTS_ROOT;
		$files = array_merge(
			glob( $root . '/src/Admin/*.php' ),
			glob( $root . '/templates/admin/*.php' ),
			array( $root . '/assets/js/admin.js', $root . '/src/Emails/AdminAlert.php', $root . '/src/Privacy.php' )
		);
		$out   = array();
		foreach ( $files as $file ) {
			// Uninstall still names the options of the old transaction-id drift, so an uninstall removes them.
			if ( 'Uninstall.php' === basename( $file ) ) {
				continue;
			}
			$out[ substr( $file, strlen( $root ) + 1 ) ] = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local source file in a unit test.
		}
		return $out;
	}

	/** No admin file reads, writes, labels or filters by a wallet type, and none names a wallet brand. */
	public function test_admin_never_reads_the_type(): void {
		$sources = $this->admin_sources();
		$this->assertGreaterThan( 15, count( $sources ) );
		$rules = array(
			'reads a provider'         => '/\[\s*[\'"]provider[\'"]\s*\]/',
			'writes a provider key'    => '/[\'"]provider[\'"]\s*=>/',
			'a type helper'            => '/provider_label|provider_options|PROVIDER_ORDER|DECISION_PROVIDERS|CLAIM_PROVIDERS|qersh-provider/',
			'a provider filter'        => '/[\'"]provider[\'"]\s*,/',
			'a wallet brand or *9#'    => '/Vodafone|Orange|InstaPay|Etisalat|e&(amp;)? money|\*9#|instapay_app|orange_cash|vodafone_cash/i',
			'a type-named variant'     => '/[\'"]orange[\'"]/',
			'a provider in the script' => '/\.provider\b/',
		);
		foreach ( $sources as $path => $source ) {
			foreach ( $rules as $what => $rule ) {
				if ( 'a provider in the script' === $what && '.js' !== substr( $path, -3 ) ) {
					continue; // The PHP docblocks name the delivery's `wallet.provider`, which nothing reads.
				}
				$this->assertSame( 0, preg_match( $rule, $source, $m ), $path . ': ' . $what . ( isset( $m[0] ) ? ' (' . $m[0] . ')' : '' ) );
			}
		}
	}
}
