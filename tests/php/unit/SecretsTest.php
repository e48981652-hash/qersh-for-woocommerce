<?php
/**
 * Tests of the pure parts of Secrets: the sodium envelope and the constant name (docs/17 §4.8).
 *
 * @package QershPay\WooCommerce\Tests
 */

declare( strict_types=1 );

namespace QershPay\WooCommerce\Tests\Unit;

use QershPay\WooCommerce\Core\Config;
use QershPay\WooCommerce\Secrets;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

defined( 'ABSPATH' ) || exit;

/**
 * Secrets::seal_value(), Secrets::open_value() and Secrets::constant_name(); erase() on stand-ins for the option
 * functions and the WordPress secrets API (tests in their own process).
 */
final class SecretsTest extends TestCase {

	const WALLET = 'k7q2m9x4p1rz';
	const NOW    = 1760000000;

	/**
	 * Erase with a store that works: nothing of the wallet is left anywhere.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_erase_leaves_nothing(): void {
		require_once __DIR__ . '/FakeWpSecrets.php';
		$s = new Secrets();
		$s->set_current( self::WALLET, Config::new_key(), self::NOW );
		$s->seal( self::WALLET, self::NOW );
		$s->erase( self::WALLET );
		$this->assertSame( array(), $GLOBALS['qersh_wc_fake_secrets'] );
		$this->assertSame( array(), $GLOBALS['qersh_wc_fake_options'] );
		$this->assertSame( 'none', ( new Secrets() )->state( self::WALLET ) );
	}

	/**
	 * The WordPress secrets store refuses the delete: the surviving copy is overwritten by an empty record with a
	 * higher revision, so the erased key never comes back, not even as the previous key of the next one.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_erase_overwrites_a_copy_that_survived(): void {
		require_once __DIR__ . '/FakeWpSecrets.php';
		$old = Config::new_key();
		$new = Config::new_key();
		$s   = new Secrets();
		$s->set_current( self::WALLET, $old, self::NOW );
		$s->seal( self::WALLET, self::NOW );
		$this->assertSame( array(), $s->keys_for( self::WALLET, self::NOW ), 'sealed' );

		$GLOBALS['qersh_wc_fake_refuse']['delete'] = true;
		$s->erase( self::WALLET );
		$this->assertStringNotContainsString( $old, json_encode( $GLOBALS['qersh_wc_fake_secrets'] ) . json_encode( $GLOBALS['qersh_wc_fake_options'] ) );

		$fresh = new Secrets();
		$this->assertSame( 'none', $fresh->state( self::WALLET ) );
		$this->assertNull( $fresh->fingerprint( self::WALLET ) );
		$this->assertFalse( $fresh->has_key( self::WALLET ) );

		$later = self::NOW + 300000;
		$fresh->set_current( self::WALLET, $new, $later );
		$this->assertSame( array( $new ), ( new Secrets() )->keys_for( self::WALLET, $later + 1 ), 'no erased key as the previous one' );
	}

	/**
	 * The store refuses the delete and then the overwrite too: the empty record goes to the option with the higher
	 * revision, and read() prefers it over the stale copy.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_erase_falls_back_to_the_option(): void {
		require_once __DIR__ . '/FakeWpSecrets.php';
		$old = Config::new_key();
		$new = Config::new_key();
		$s   = new Secrets();
		$s->set_current( self::WALLET, $old, self::NOW );
		$s->seal( self::WALLET, self::NOW );

		$GLOBALS['qersh_wc_fake_refuse']['delete'] = true;
		$GLOBALS['qersh_wc_fake_refuse']['set']    = true;
		$s->erase( self::WALLET );
		$option = $GLOBALS['qersh_wc_fake_options'][ Secrets::OPTION_PREFIX . self::WALLET ];
		$this->assertSame( 3, $option['rev'] );
		$this->assertNull( $option['current'] );
		$this->assertSame( 'none', ( new Secrets() )->state( self::WALLET ) );

		$GLOBALS['qersh_wc_fake_refuse']['set'] = false;
		$later                                  = self::NOW + 300000;
		( new Secrets() )->set_current( self::WALLET, $new, $later );
		$this->assertSame( array( $new ), ( new Secrets() )->keys_for( self::WALLET, $later + 1 ) );
	}

	/** The wp-config constant of a wallet. */
	public function test_constant_name(): void {
		$this->assertSame( 'QERSH_WC_KEY_K7Q2M9X4P1RZ', Secrets::constant_name( 'k7q2m9x4p1rz' ) );
	}

	/** A value kept inside the WordPress secrets store is read as it is; an empty one is no key. */
	public function test_plain_values(): void {
		$this->assertSame( 'qsk_abcdefghijklmnopqrstuvwxyz', Secrets::open_value( 'pt1:qsk_abcdefghijklmnopqrstuvwxyz', null ) );
		$this->assertNull( Secrets::open_value( 'pt1:', null ) );
		$this->assertNull( Secrets::open_value( 'qsk_plain_text_is_never_read_as_a_key', null ) );
		$this->assertNull( Secrets::open_value( 'sb1:00', null ) );
	}

	/**
	 * The secretbox round trip; another site key or a changed byte opens nothing. Without sodium (this PHP may not
	 * load it; WordPress ships sodium_compat) a sealed value is simply unreadable.
	 */
	public function test_envelope(): void {
		$box = str_repeat( "\x01", 32 );
		if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
			$this->assertNull( Secrets::open_value( 'sb1:' . str_repeat( 'ab', 60 ), $box ) );
			return;
		}
		$other = str_repeat( "\x02", 32 );
		$key   = 'qsk_' . str_repeat( 'Zz9_-', 8 ) . 'abc';

		$sealed = Secrets::seal_value( $key, $box );
		$this->assertStringStartsWith( 'sb1:', $sealed );
		$this->assertStringNotContainsString( $key, $sealed );
		$this->assertNotSame( $sealed, Secrets::seal_value( $key, $box ), 'a fresh nonce every time' );
		$this->assertSame( $key, Secrets::open_value( $sealed, $box ) );
		$this->assertNull( Secrets::open_value( $sealed, $other ), 'renewed salts: unreadable, not wrong' );
		$tampered = substr( $sealed, 0, -2 ) . ( '00' === substr( $sealed, -2 ) ? '01' : '00' );
		$this->assertNull( Secrets::open_value( $tampered, $box ) );
		$this->assertNull( Secrets::open_value( 'sb1:' . str_repeat( 'zz', 50 ), $box ) );
	}
}
