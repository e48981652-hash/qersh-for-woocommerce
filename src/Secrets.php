<?php
/**
 * Connection keys of the wallets: current, pending and previous (docs/17 §4.8).
 *
 * @package QershPay\WooCommerce
 */

namespace QershPay\WooCommerce;

use QershPay\WooCommerce\Core\Config;

defined( 'ABSPATH' ) || exit;

/**
 * Where a wallet's key lives, in this order:
 * 1. the constant QERSH_WC_KEY_<WALLET ID IN UPPER CASE> in wp-config.php, which beats every stored key;
 * 2. the secrets API of WordPress (wp_set_secret / wp_get_secret, WordPress 7.2+) when both functions exist;
 * 3. otherwise option `qersh_wc_secret_<wallet>` (autoload off), each key sealed with sodium secretbox under a key
 *    derived from the LOGGED_IN_KEY and LOGGED_IN_SALT constants (never wp_salt(), which security plugins rotate).
 *
 * One record per wallet holds the current key, a pending key (a generated link the phone has not used yet) and the
 * previous key with the end of its 24-hour bridge, plus fingerprints and times that are shown in the admin. A key
 * never appears in a log, an exception, a transient, an order note, the export or a reply.
 */
final class Secrets {

	/**
	 * Option name prefix.
	 */
	const OPTION_PREFIX = 'qersh_wc_secret_';

	/**
	 * Name prefix in the WordPress secrets API.
	 */
	const WP_SECRET_PREFIX = 'qersh-for-woocommerce/wallet-';

	/**
	 * The previous key stays valid this long after a change (the phone's own rotation window).
	 */
	const PREVIOUS_SEC = 86400;

	/**
	 * Context mixed into the box key, so this key is used for nothing else.
	 */
	const BOX_CONTEXT = 'qersh-for-woocommerce:secrets:v1|';

	/**
	 * Key slots of a record.
	 */
	const SLOTS = array( 'pending', 'current', 'previous' );

	/**
	 * The plugin, if any.
	 *
	 * @var Plugin|null
	 */
	private $plugin;

	/**
	 * Records read in this request, by wallet (false = none).
	 *
	 * @var array<string, array|false>
	 */
	private $records = array();

	/**
	 * Builds the service.
	 *
	 * @param Plugin|null $plugin The plugin.
	 */
	public function __construct( $plugin = null ) {
		$this->plugin = $plugin;
	}

	/**
	 * Name of the wp-config constant that holds a wallet's key.
	 *
	 * @param string $wallet Wallet id.
	 * @return string
	 */
	public static function constant_name( string $wallet ): string {
		return 'QERSH_WC_KEY_' . strtoupper( $wallet );
	}

	/**
	 * True when the wallet's key comes from wp-config.php (the admin then offers no key link and no paste).
	 *
	 * @param string $wallet Wallet id.
	 * @return bool
	 */
	public function from_constant( string $wallet ): bool {
		return 1 === preg_match( '/^[a-z0-9]{12}$/', $wallet ) && defined( self::constant_name( $wallet ) );
	}

	/**
	 * Keys to verify a delivery with, in order: pending, current, then previous while its 24 hours last. With the
	 * constant, only the constant. Empty when the wallet has no key, a sealed key (deleted wallet) or only keys that
	 * cannot be read (the salts changed): the webhook then answers 401 no_key.
	 *
	 * @param string $wallet Wallet id.
	 * @param int    $now    UTC seconds.
	 * @return string[]
	 */
	public function keys_for( string $wallet, int $now ): array {
		$keys = array();
		foreach ( $this->slots_for( $wallet, $now ) as $slot ) {
			$keys[] = $slot['key'];
		}
		return $keys;
	}

	/**
	 * keys_for() with the slot of each key, so the caller knows that the key at Verifier's key_index was the pending
	 * one (then promote_pending()).
	 *
	 * @param string $wallet Wallet id.
	 * @param int    $now    UTC seconds.
	 * @return array<int, array{slot: string, key: string}> Slot is `constant`, `pending`, `current` or `previous`.
	 */
	public function slots_for( string $wallet, int $now ): array {
		if ( $this->from_constant( $wallet ) ) {
			$key = $this->constant_key( $wallet );
			return null === $key ? array() : array(
				array(
					'slot' => 'constant',
					'key'  => $key,
				),
			);
		}
		$record = $this->read( $wallet );
		if ( null === $record || ! empty( $record['sealed_at'] ) ) {
			return array();
		}
		$out = array();
		foreach ( self::SLOTS as $slot ) {
			if ( 'previous' === $slot && ( empty( $record['previous_until'] ) || (int) $record['previous_until'] <= $now ) ) {
				continue;
			}
			$key = $this->reveal( $record, $slot );
			if ( null === $key ) {
				continue;
			}
			foreach ( $out as $seen ) {
				if ( $seen['key'] === $key ) {
					continue 2;
				}
			}
			$out[] = array(
				'slot' => $slot,
				'key'  => $key,
			);
		}
		return $out;
	}

	/**
	 * What the wallet card shows: the current key's fingerprint and when it was set, and the pending key's. The
	 * fingerprint is the first 8 hex characters of sha256("qersh-key:" + key); it is stored next to the sealed key, so
	 * it shows even when the key itself can no longer be read. Null without any key.
	 *
	 * @param string $wallet Wallet id.
	 * @return array|null `[ 'fp' => ?string, 'set_at' => ?int, 'pending_fp' => ?string, 'pending_at' => ?int ]`
	 */
	public function fingerprint( string $wallet ): ?array {
		if ( $this->from_constant( $wallet ) ) {
			$key = $this->constant_key( $wallet );
			return array(
				'fp'         => null === $key ? null : Config::fingerprint( $key ),
				'set_at'     => null,
				'pending_fp' => null,
				'pending_at' => null,
			);
		}
		$record = $this->read( $wallet );
		if ( null === $record || ( empty( $record['current'] ) && empty( $record['pending'] ) ) ) {
			return null;
		}
		return array(
			'fp'         => empty( $record['current'] ) ? null : (string) $record['current_fp'],
			'set_at'     => empty( $record['current'] ) ? null : (int) $record['current_at'],
			'pending_fp' => empty( $record['pending'] ) ? null : (string) $record['pending_fp'],
			'pending_at' => empty( $record['pending'] ) ? null : (int) $record['pending_at'],
		);
	}

	/**
	 * True when the wallet has a usable current key (or the constant). A generated link then becomes the pending
	 * key; without one it becomes the current key at once (docs/17 §4.8).
	 *
	 * @param string $wallet Wallet id.
	 * @return bool
	 */
	public function has_key( string $wallet ): bool {
		if ( $this->from_constant( $wallet ) ) {
			return null !== $this->constant_key( $wallet );
		}
		$record = $this->read( $wallet );
		return null !== $record && empty( $record['sealed_at'] ) && null !== $this->reveal( $record, 'current' );
	}

	/**
	 * The key state for the wallet card and Diagnostics.
	 *
	 * @param string $wallet Wallet id.
	 * @return string `constant`, `constant_invalid` (the constant is not an acceptable key), `ok`, `unreadable` (a key
	 *                is stored but cannot be opened, for example after the salts were renewed: paste or link again),
	 *                `sealed` (deleted wallet) or `none`.
	 */
	public function state( string $wallet ): string {
		if ( $this->from_constant( $wallet ) ) {
			return null === $this->constant_key( $wallet ) ? 'constant_invalid' : 'constant';
		}
		$record = $this->read( $wallet );
		if ( null === $record || empty( $record['current'] ) ) {
			return null !== $record && ! empty( $record['pending'] ) ? 'ok' : 'none';
		}
		if ( ! empty( $record['sealed_at'] ) ) {
			return 'sealed';
		}
		return null === $this->reveal( $record, 'current' ) ? 'unreadable' : 'ok';
	}

	/**
	 * Makes a key the current one at once (a pasted key, or a generated link for a wallet without a key). The old
	 * current key, when readable and different, becomes the previous key for 24 hours. A pending key is cancelled:
	 * pasting replaces it (docs/17 §4.8). The admin asks before replacing a pending key and refuses a paste that
	 * equals it; this method does not.
	 *
	 * @param string $wallet Wallet id.
	 * @param string $key    Key (trimmed; must pass Config::is_acceptable_key()).
	 * @param int    $now    UTC seconds.
	 */
	public function set_current( string $wallet, string $key, int $now ) {
		$key    = $this->checked( $wallet, $key );
		$record = $this->read( $wallet );
		$record = null === $record ? self::blank() : $record;
		$old    = $this->reveal( $record, 'current' );
		if ( null !== $old && $old !== $key ) {
			self::put( $record, 'previous', $old, $now );
		}
		self::put( $record, 'current', $key, $now );
		self::clear( $record, 'pending' );
		$record['sealed_at'] = null;
		$this->write( $wallet, $record );
	}

	/**
	 * Stores a generated key as pending: both keys are accepted until the first valid delivery signed with the
	 * pending one (promote_pending()). A new link replaces the pending key and never touches the current one. A
	 * wallet without a usable current key gets the key as current instead, as docs/17 §4.8 item 2 says.
	 *
	 * @param string $wallet Wallet id.
	 * @param string $key    Key.
	 * @param int    $now    UTC seconds.
	 */
	public function set_pending( string $wallet, string $key, int $now ) {
		$key    = $this->checked( $wallet, $key );
		$record = $this->read( $wallet );
		if ( null === $record || ! empty( $record['sealed_at'] ) || null === $this->reveal( $record, 'current' ) ) {
			$this->set_current( $wallet, $key, $now );
			return;
		}
		if ( $this->reveal( $record, 'current' ) === $key ) {
			return;
		}
		self::put( $record, 'pending', $key, $now );
		$this->write( $wallet, $record );
	}

	/**
	 * After the first valid delivery signed with the pending key: it becomes current, and the old current key the
	 * previous one for 24 hours.
	 *
	 * @param string $wallet Wallet id.
	 * @param int    $now    UTC seconds.
	 */
	public function promote_pending( string $wallet, int $now ) {
		$record = $this->read( $wallet );
		if ( null === $record ) {
			return;
		}
		$pending = $this->reveal( $record, 'pending' );
		if ( null === $pending ) {
			return;
		}
		$old = $this->reveal( $record, 'current' );
		if ( null !== $old && $old !== $pending ) {
			self::put( $record, 'previous', $old, $now );
		}
		self::put( $record, 'current', $pending, $now );
		self::clear( $record, 'pending' );
		$this->write( $wallet, $record );
	}

	/**
	 * Cancels the pending key ("Cancel this link", or a paste that replaces it).
	 *
	 * @param string $wallet Wallet id.
	 */
	public function cancel_pending( string $wallet ) {
		$record = $this->read( $wallet );
		if ( null === $record || empty( $record['pending'] ) ) {
			return;
		}
		self::clear( $record, 'pending' );
		$this->write( $wallet, $record );
	}

	/**
	 * On delete (docs/17 §4.8): the pending key is erased now, the current and previous keys are kept sealed (never
	 * used to verify) until erase() after 72 hours, or until a restore unseals them. Keeps the first seal time.
	 *
	 * @param string $wallet Wallet id.
	 * @param int    $now    UTC seconds.
	 */
	public function seal( string $wallet, int $now ) {
		$record = $this->read( $wallet );
		if ( null === $record ) {
			return;
		}
		self::clear( $record, 'pending' );
		if ( empty( $record['sealed_at'] ) ) {
			$record['sealed_at'] = $now;
		}
		$this->write( $wallet, $record );
	}

	/**
	 * Restore within 72 hours: the sealed keys verify again.
	 *
	 * @param string $wallet Wallet id.
	 */
	public function unseal( string $wallet ) {
		$record = $this->read( $wallet );
		if ( null === $record || empty( $record['sealed_at'] ) ) {
			return;
		}
		$record['sealed_at'] = null;
		$this->write( $wallet, $record );
	}

	/**
	 * Erases every stored key of the wallet, from both stores. The constant, if any, stays in wp-config.php.
	 *
	 * Then it reads both stores back. When a copy with a key survived (the WordPress secrets store refused the
	 * delete, or the option could not be deleted), it is overwritten by an empty record with a higher revision, so
	 * read() never prefers the erased key again: without that, the next write would start again at revision 1 and
	 * set_current() would reveal the erased key and keep it as the previous key for 24 hours.
	 *
	 * @param string $wallet Wallet id.
	 * @throws \RuntimeException When a surviving copy cannot be overwritten either (the caller may try again later).
	 */
	public function erase( string $wallet ) {
		unset( $this->records[ $wallet ] );
		delete_option( self::option_name( $wallet ) );
		if ( self::wp_store_available() ) {
			$this->wp_store_clear( $wallet );
		}
		unset( $this->records[ $wallet ] );
		$left = $this->read( $wallet );
		unset( $this->records[ $wallet ] );
		if ( null === $left || ! self::holds_key( $left ) ) {
			return;
		}
		$empty        = self::blank();
		$empty['rev'] = (int) $left['rev']; // write() adds one.
		$this->write( $wallet, $empty );
	}

	/**
	 * Seals one key with sodium secretbox: `sb1:` + hex( nonce . box ). Pure.
	 *
	 * @param string $plain   Key.
	 * @param string $box_key 32-byte key.
	 * @return string
	 */
	public static function seal_value( string $plain, string $box_key ): string {
		$nonce = random_bytes( 24 );
		return 'sb1:' . bin2hex( $nonce . sodium_crypto_secretbox( $plain, $nonce, $box_key ) );
	}

	/**
	 * Opens a value written by seal_value(), or a `pt1:` value from the WordPress secrets store. Null when it cannot
	 * be opened. Pure.
	 *
	 * @param string      $stored  Stored value.
	 * @param string|null $box_key 32-byte key, or null when none is available.
	 * @return string|null
	 */
	public static function open_value( string $stored, ?string $box_key ): ?string {
		if ( 0 === strpos( $stored, 'pt1:' ) ) {
			$plain = substr( $stored, 4 );
			return '' === $plain ? null : $plain;
		}
		if ( 0 !== strpos( $stored, 'sb1:' ) || null === $box_key || ! function_exists( 'sodium_crypto_secretbox_open' ) ) {
			return null;
		}
		$hex = substr( $stored, 4 );
		if ( 1 !== preg_match( '/^[0-9a-f]+$/', $hex ) || 0 !== strlen( $hex ) % 2 || strlen( $hex ) < 2 * ( 24 + 16 + 1 ) ) {
			return null;
		}
		$raw = (string) hex2bin( $hex );
		try {
			$plain = sodium_crypto_secretbox_open( substr( $raw, 24 ), substr( $raw, 0, 24 ), $box_key );
		} catch ( \Throwable $e ) {
			return null;
		}
		return is_string( $plain ) && '' !== $plain ? $plain : null;
	}

	/**
	 * The secretbox key: BLAKE2b (sodium_crypto_generichash) of a context and LOGGED_IN_KEY . LOGGED_IN_SALT. Null
	 * when the constants are missing or still the sample phrase, or sodium is not there.
	 *
	 * @return string|null
	 */
	private static function box_key(): ?string {
		if ( ! defined( 'LOGGED_IN_KEY' ) || ! defined( 'LOGGED_IN_SALT' ) ) {
			return null;
		}
		$material = (string) LOGGED_IN_KEY . (string) LOGGED_IN_SALT;
		if ( strlen( $material ) < 32 || false !== strpos( $material, 'put your unique phrase here' ) ) {
			return null;
		}
		if ( ! function_exists( 'sodium_crypto_generichash' ) || ! function_exists( 'sodium_crypto_secretbox' ) ) {
			return null;
		}
		return sodium_crypto_generichash( self::BOX_CONTEXT . $material, '', 32 );
	}

	/**
	 * The trimmed constant key, or null when it is not an acceptable key.
	 *
	 * @param string $wallet Wallet id.
	 * @return string|null
	 */
	private function constant_key( string $wallet ): ?string {
		$value = constant( self::constant_name( $wallet ) );
		if ( ! is_string( $value ) ) {
			return null;
		}
		$value = Config::js_trim( $value ); // The phone trims like JavaScript (NBSP and BOM too) before it signs.
		return Config::is_acceptable_key( $value ) ? $value : null;
	}

	/**
	 * Validates a wallet id and a key before storing.
	 *
	 * @param string $wallet Wallet id.
	 * @param string $key    Key.
	 * @return string The trimmed key.
	 */
	private function checked( string $wallet, string $key ): string {
		if ( 1 !== preg_match( '/^[a-z0-9]{12}$/', $wallet ) ) {
			throw new \InvalidArgumentException( 'qersh: invalid wallet id' );
		}
		$key = Config::js_trim( $key );
		if ( ! Config::is_acceptable_key( $key ) ) {
			throw new \InvalidArgumentException( 'qersh: the connection key is not acceptable' );
		}
		return $key;
	}

	/**
	 * The plain key of a slot, or null.
	 *
	 * @param array  $record Record.
	 * @param string $slot   Slot.
	 * @return string|null
	 */
	private function reveal( array $record, string $slot ): ?string {
		if ( empty( $record[ $slot ] ) || ! is_string( $record[ $slot ] ) ) {
			return null;
		}
		return self::open_value( $record[ $slot ], self::box_key() );
	}

	/**
	 * Puts a key into a slot (kept as `pt1:` in memory; write() seals it for the store it goes to).
	 *
	 * @param array  $record Record (by reference).
	 * @param string $slot   Slot.
	 * @param string $key    Key.
	 * @param int    $now    UTC seconds.
	 */
	private static function put( array &$record, string $slot, string $key, int $now ) {
		$record[ $slot ]         = 'pt1:' . $key;
		$record[ $slot . '_fp' ] = Config::fingerprint( $key );
		$record[ $slot . '_at' ] = $now;
		if ( 'previous' === $slot ) {
			$record['previous_until'] = $now + self::PREVIOUS_SEC;
		}
	}

	/**
	 * Empties a slot.
	 *
	 * @param array  $record Record (by reference).
	 * @param string $slot   Slot.
	 */
	private static function clear( array &$record, string $slot ) {
		$record[ $slot ]         = null;
		$record[ $slot . '_fp' ] = null;
		$record[ $slot . '_at' ] = null;
		if ( 'previous' === $slot ) {
			$record['previous_until'] = null;
		}
	}

	/**
	 * True when a record still carries a key in any slot (readable or not). Pure.
	 *
	 * @param array $record Record.
	 * @return bool
	 */
	private static function holds_key( array $record ): bool {
		foreach ( self::SLOTS as $slot ) {
			if ( ! empty( $record[ $slot ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * An empty record.
	 *
	 * @return array
	 */
	private static function blank(): array {
		$record = array(
			'v'         => 1,
			'rev'       => 0,
			'sealed_at' => null,
		);
		foreach ( self::SLOTS as $slot ) {
			self::clear( $record, $slot );
		}
		return $record;
	}

	/**
	 * Reads the wallet's record: from the WordPress secrets store and from the option, whichever has the higher
	 * revision (so a copy left behind in one store never shadows a newer write to the other).
	 *
	 * @param string $wallet Wallet id.
	 * @return array|null
	 */
	private function read( string $wallet ): ?array {
		if ( array_key_exists( $wallet, $this->records ) ) {
			return false === $this->records[ $wallet ] ? null : $this->records[ $wallet ];
		}
		$best   = null;
		$option = get_option( self::option_name( $wallet ), null );
		if ( is_array( $option ) && isset( $option['v'] ) ) {
			$best = $option;
		}
		if ( self::wp_store_available() ) {
			$stored = self::decode( $this->wp_store_get( $wallet ) );
			if ( null !== $stored && ( null === $best || (int) $stored['rev'] > (int) $best['rev'] ) ) {
				$best = $stored;
			}
		}
		$this->records[ $wallet ] = null === $best ? false : array_merge( self::blank(), $best );
		return null === $best ? null : $this->records[ $wallet ];
	}

	/**
	 * Writes the record with the next revision: to the WordPress secrets store when it is there and reads back
	 * what was written (the option copy is then deleted), otherwise to the option with every key sealed.
	 *
	 * @param string $wallet Wallet id.
	 * @param array  $record Record (keys as `pt1:` or `sb1:`).
	 * @throws \RuntimeException When no store can keep the key (no secrets API and no LOGGED_IN_* constants).
	 */
	private function write( string $wallet, array $record ) {
		$record['v']   = 1;
		$record['rev'] = (int) $record['rev'] + 1;
		$box           = self::box_key();
		unset( $this->records[ $wallet ] );

		if ( self::wp_store_available() ) {
			$plain = $record;
			foreach ( self::SLOTS as $slot ) {
				if ( ! empty( $plain[ $slot ] ) ) {
					$open           = self::open_value( (string) $plain[ $slot ], $box );
					$plain[ $slot ] = null === $open ? $plain[ $slot ] : 'pt1:' . $open;
				}
			}
			$json = self::encode( $plain );
			if ( $this->wp_store_put( $wallet, $json ) && $this->wp_store_get( $wallet ) === $json ) {
				delete_option( self::option_name( $wallet ) );
				return;
			}
		}

		foreach ( self::SLOTS as $slot ) {
			if ( empty( $record[ $slot ] ) || 0 !== strpos( (string) $record[ $slot ], 'pt1:' ) ) {
				continue; // Empty, or already sealed (an unreadable key stays as it was).
			}
			if ( null === $box ) {
				throw new \RuntimeException( 'qersh: the connection key cannot be stored on this site. Define LOGGED_IN_KEY and LOGGED_IN_SALT in wp-config.php, or put the key in its QERSH_WC_KEY_ constant.' );
			}
			$record[ $slot ] = self::seal_value( substr( (string) $record[ $slot ], 4 ), $box );
		}
		update_option( self::option_name( $wallet ), $record, false );
		$check = get_option( self::option_name( $wallet ), null );
		if ( ! is_array( $check ) || ! isset( $check['rev'] ) || (int) $check['rev'] !== $record['rev'] ) {
			throw new \RuntimeException( 'qersh: the connection key could not be saved' );
		}
		if ( self::wp_store_available() ) {
			$this->wp_store_clear( $wallet );
		}
	}

	/**
	 * Option name of a wallet's record.
	 *
	 * @param string $wallet Wallet id.
	 * @return string
	 */
	private static function option_name( string $wallet ): string {
		return self::OPTION_PREFIX . $wallet;
	}

	/**
	 * True when the WordPress secrets API is there (WordPress 7.2+).
	 *
	 * @return bool
	 */
	private static function wp_store_available(): bool {
		return function_exists( 'wp_set_secret' ) && function_exists( 'wp_get_secret' );
	}

	/**
	 * Reads our record from the WordPress secrets API. Null when absent or unreadable; never throws.
	 *
	 * @param string $wallet Wallet id.
	 * @return string|null
	 */
	private function wp_store_get( string $wallet ): ?string {
		if ( ! function_exists( 'wp_get_secret' ) ) {
			return null;
		}
		try {
			$secret = wp_get_secret( self::WP_SECRET_PREFIX . $wallet );
			if ( is_object( $secret ) && method_exists( $secret, 'reveal' ) ) {
				$secret = $secret->reveal();
			}
		} catch ( \Throwable $e ) {
			return null;
		}
		return is_string( $secret ) && '' !== $secret ? $secret : null;
	}

	/**
	 * Writes our record through the WordPress secrets API. False on any failure; never throws.
	 *
	 * @param string $wallet Wallet id.
	 * @param string $value  JSON record.
	 * @return bool
	 */
	private function wp_store_put( string $wallet, string $value ): bool {
		if ( ! function_exists( 'wp_set_secret' ) ) {
			return false;
		}
		try {
			$result = wp_set_secret( self::WP_SECRET_PREFIX . $wallet, $value );
		} catch ( \Throwable $e ) {
			return false;
		}
		return ! ( is_object( $result ) && is_a( $result, 'WP_Error' ) ) && false !== $result;
	}

	/**
	 * Removes our record from the WordPress secrets API (or blanks it when there is no delete function).
	 *
	 * @param string $wallet Wallet id.
	 */
	private function wp_store_clear( string $wallet ) {
		try {
			if ( function_exists( 'wp_delete_secret' ) ) {
				wp_delete_secret( self::WP_SECRET_PREFIX . $wallet );
			} elseif ( function_exists( 'wp_set_secret' ) ) {
				wp_set_secret( self::WP_SECRET_PREFIX . $wallet, '' );
			}
		} catch ( \Throwable $e ) {
			unset( $e ); // A stale copy has a lower revision than the option and is never used.
		}
	}

	/**
	 * JSON of a record.
	 *
	 * @param array $record Record.
	 * @return string
	 */
	private static function encode( array $record ): string {
		return Store\Db::json( $record );
	}

	/**
	 * A record from JSON, or null.
	 *
	 * @param string|null $json JSON.
	 * @return array|null
	 */
	private static function decode( ?string $json ): ?array {
		if ( null === $json ) {
			return null;
		}
		$record = json_decode( $json, true );
		return is_array( $record ) && isset( $record['v'], $record['rev'] ) ? $record : null;
	}
}
