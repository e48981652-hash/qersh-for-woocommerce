<?php
/**
 * The plugin's one configuration (docs/17 §4.2): its defaults, its validation, and the ids and keys it hands out.
 *
 * @package QershPay\WooCommerce
 */

declare( strict_types=1 );

namespace QershPay\WooCommerce\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Pure PHP. The receiver part is a literal port of config.ts in packages/qersh-webhook (withDefaults, validateConfig,
 * isAcceptableKey) without `keys`, which never live in the configuration; wallets and store are the plugin's own.
 *
 * The public text helpers at the end (js_trim, utf16_length, to_valid_utf8) are shared by the Core ports of A3
 * (SetupLink, TxnId): they give PHP strings the JavaScript semantics the TypeScript core relies on.
 *
 * One deliberate addition to the receiver rules: a whole number that passes Number.isInteger() but does not fit in
 * a PHP int (a JSON number of 2^63 or more on 64-bit PHP, 2^31 or more on 32-bit PHP such as Playground, where
 * json_decode() returns it as a float) is reported with the message of too_large() under the field's own path, as
 * are such store numbers. The TypeScript accepts it (1e300 minutes, for example), but the plugin stores integers
 * (docs-dev/CONTRACTS.md §1) and would otherwise hand a float to code that takes an int.
 */
final class Config {

	/**
	 * Every event name the phone can send (kit/webhook.v1.schema.json `eventName`, QERSH_EVENTS in config.ts).
	 */
	const QERSH_EVENTS = array(
		'transfer.received',
		'transfer.sent',
		'transfer.failed',
		'transfer.reversed',
		'transfer.updated',
		'message.unparsed',
		'device.heartbeat',
		'webhook.test',
		'subscription.limit_reached',
	);

	/**
	 * The events this plugin handles. Any other event with a valid signature is answered 2xx `ignored`.
	 */
	const EVENTS_ALLOWED = array( 'transfer.received', 'transfer.updated', 'webhook.test' );

	/**
	 * The REST path of the webhook, relative to the REST root; the wallet id is appended.
	 */
	const ENDPOINT_PATH = 'qersh/v1/webhook';

	/**
	 * The defaults, exactly the JSON of docs/17 §4.2 (with no wallet).
	 */
	const DEFAULTS = array(
		'receiver' => array(
			'endpoint'      => array( 'path' => 'qersh/v1/webhook' ),
			'tolerance_sec' => 300,
			'events'        => array( 'transfer.received', 'transfer.updated', 'webhook.test' ),
			'matching'      => array(
				'late_window_min' => 120,
				'skew_min'        => 0,
			),
			'dedup'         => array( 'keep_days' => 180 ),
		),
		'wallets'  => array(),
		'store'    => array(
			'order_ttl_min'                => 120,
			'min_total_minor'              => null,
			'max_total_minor'              => null,
			'round_up_to_pound'            => true,
			'allow_key_link'               => true,
			'txn_entry'                    => true,
			'admin_cancel_email_on_expiry' => true,
			'unmatched_retention_days'     => 90,
			'remove_data_on_uninstall'     => false,
		),
	);

	/**
	 * The fields of a wallet entry. A wallet has no type: every store wallet is an e-wallet number, and the plugin
	 * never branches on the operator behind it (the Qersh app reads the SMS and knows it). The `provider` of a
	 * configuration saved before wallets lost their type is dropped by with_defaults(), never refused.
	 */
	const WALLET_FIELDS = array( 'id', 'label', 'receive_address', 'enabled' );

	/**
	 * Boolean store values.
	 */
	const STORE_BOOLEANS = array( 'round_up_to_pound', 'allow_key_link', 'txn_entry', 'admin_cancel_email_on_expiry', 'remove_data_on_uninstall' );

	/**
	 * JavaScript white space (`\s` and String.prototype.trim): WhiteSpace and LineTerminator, for a character class.
	 */
	const JS_SPACE = '\x{0009}-\x{000D}\x{0020}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}';

	/**
	 * The alphabet of wallet ids.
	 */
	const ID_ALPHABET = 'abcdefghijklmnopqrstuvwxyz0123456789';

	/**
	 * A configuration completed with the defaults: withDefaults of config.ts for the receiver (keys dropped), plus
	 * the plugin's parts. Only known fields are kept, so nothing else (a key above all, or the legacy `provider` of a
	 * wallet) reaches the stored option. Values of the wrong type are kept for validate() to report, a `wallets` or
	 * `store` that is present but not an array included (so an import of `"wallets": null` is refused, never read as
	 * "no wallets"); a missing `wallets` is an empty list and a missing `store` takes the defaults. A non-object
	 * `receiver` takes the defaults, as in withDefaults() of config.ts. A whole-number float within the int range
	 * becomes an int (JSON `300.0`), and a receive_address that normalises to a wallet number is stored normalised.
	 *
	 * @param array $c A partial or complete configuration.
	 * @return array
	 */
	public static function with_defaults( array $c ): array {
		$d = self::DEFAULTS;
		$r = isset( $c['receiver'] ) && is_array( $c['receiver'] ) ? $c['receiver'] : array();

		$endpoint = isset( $r['endpoint'] ) && is_array( $r['endpoint'] ) ? $r['endpoint'] : array();
		$path     = isset( $endpoint['path'] ) && is_string( $endpoint['path'] ) ? $endpoint['path'] : $d['receiver']['endpoint']['path'];
		$events   = array_key_exists( 'events', $r ) ? self::string_list( $r['events'] ) : $d['receiver']['events'];
		$matching = isset( $r['matching'] ) && is_array( $r['matching'] ) ? $r['matching'] : array();
		$dedup    = isset( $r['dedup'] ) && is_array( $r['dedup'] ) ? $r['dedup'] : array();

		$receiver = array(
			'endpoint'      => array( 'path' => $path ),
			'tolerance_sec' => self::value_or( $r, 'tolerance_sec', $d['receiver']['tolerance_sec'] ),
			'events'        => $events,
			'matching'      => array(
				'late_window_min' => self::value_or( $matching, 'late_window_min', $d['receiver']['matching']['late_window_min'] ),
				'skew_min'        => self::value_or( $matching, 'skew_min', $d['receiver']['matching']['skew_min'] ),
			),
			'dedup'         => array( 'keep_days' => self::value_or( $dedup, 'keep_days', $d['receiver']['dedup']['keep_days'] ) ),
		);

		$wallets = array();
		if ( array_key_exists( 'wallets', $c ) && ! is_array( $c['wallets'] ) ) {
			$wallets = $c['wallets']; // Kept for validate(): `wallets` must be an array of wallets.
		} elseif ( array_key_exists( 'wallets', $c ) ) {
			foreach ( $c['wallets'] as $index => $wallet ) {
				$wallets[ $index ] = is_array( $wallet ) ? self::wallet_with_defaults( $wallet ) : $wallet;
			}
		}

		if ( array_key_exists( 'store', $c ) && ! is_array( $c['store'] ) ) {
			$store = $c['store']; // Kept for validate(): `store` must be an object.
		} else {
			$s     = array_key_exists( 'store', $c ) ? $c['store'] : array();
			$store = array();
			foreach ( $d['store'] as $key => $default ) {
				$store[ $key ] = self::value_or( $s, $key, $default );
			}
		}

		return array(
			'receiver' => $receiver,
			'wallets'  => $wallets,
			'store'    => $store,
		);
	}

	/**
	 * Everything wrong with a configuration, as a list of `[ 'path' => .., 'message' => .. ]`. Empty means usable.
	 * Call it on with_defaults() output; missing parts are reported. The receiver rules and their messages are
	 * validateConfig of config.ts (paths prefixed with `receiver.`, no `keys`), followed by the plugin's own rules
	 * of docs/17 §4.2. Never throws.
	 *
	 * @param array $c Configuration.
	 * @return array<int, array{path: string, message: string}>
	 */
	public static function validate( array $c ): array {
		$issues = array();

		if ( ! isset( $c['receiver'] ) || ! is_array( $c['receiver'] ) ) {
			$issues[] = self::issue( 'receiver', 'config must be an object' );
		} else {
			$issues = array_merge( $issues, self::validate_receiver( $c['receiver'] ) );
		}

		$issues = array_merge( $issues, self::validate_wallets( array_key_exists( 'wallets', $c ) ? $c['wallets'] : null ) );

		if ( ! isset( $c['store'] ) || ! is_array( $c['store'] ) ) {
			$issues[] = self::issue( 'store', 'an object' );
		} else {
			$issues = array_merge( $issues, self::validate_store( $c['store'] ) );
		}

		return $issues;
	}

	/**
	 * The rule the Qersh app applies to a connection key typed or pasted into it (isAcceptableKey of config.ts,
	 * `WebhookSigner.isAcceptableSecret`): at least 24 characters once trimmed and no white space. A string that is
	 * not valid UTF-8 is refused (no JavaScript string can hold it, so no phone can sign with it).
	 *
	 * @param mixed $k Value.
	 * @return bool
	 */
	public static function is_acceptable_key( $k ): bool {
		if ( ! is_string( $k ) || 1 !== preg_match( '//u', $k ) ) {
			return false;
		}
		$s = self::js_trim( $k );
		return self::utf16_length( $s ) >= 24 && 1 !== preg_match( '/[' . self::JS_SPACE . ']/u', $s );
	}

	/**
	 * Whether a value is a wallet id: twelve characters a-z and 0-9 (it is part of the webhook path).
	 *
	 * @param mixed $id Value.
	 * @return bool
	 */
	public static function wallet_id_ok( $id ): bool {
		return is_string( $id ) && 1 === preg_match( '/^[a-z0-9]{12}$/D', $id );
	}

	/**
	 * A store wallet number in its stored form: the `01XXXXXXXXX` that Payer::normalize() reads from any spelling
	 * (+20…, spaces, Arabic-Indic digits). Null for anything else: a value that is not a string, a masked number, a
	 * payment address or any other handle (a store wallet is an e-wallet number, whatever its operator).
	 *
	 * @param mixed $address Value.
	 * @return string|null
	 */
	public static function wallet_number( $address ): ?string {
		$normalized = is_string( $address ) ? Payer::normalize( $address ) : null;
		return null !== $normalized && 1 === preg_match( '/^01[0-9]{9}$/D', $normalized ) ? $normalized : null;
	}

	/**
	 * A new wallet id: twelve characters a-z and 0-9 from random_int().
	 *
	 * @return string
	 */
	public static function new_wallet_id(): string {
		$id  = '';
		$max = strlen( self::ID_ALPHABET ) - 1;
		for ( $i = 0; $i < 12; $i++ ) {
			$id .= self::ID_ALPHABET[ random_int( 0, $max ) ];
		}
		return $id;
	}

	/**
	 * A new connection key in the app's format: `qsk_` and the base64url form, without padding, of 32 random bytes
	 * (43 characters).
	 *
	 * @return string
	 */
	public static function new_key(): string {
		return 'qsk_' . rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- base64url text of 32 random bytes (the app's key format), no code.
	}

	/**
	 * The fingerprint shown for a key: the first 8 hex characters of sha256( 'qersh-key:' . key ).
	 *
	 * @param string $key Connection key.
	 * @return string
	 */
	public static function fingerprint( string $key ): string {
		return substr( hash( 'sha256', 'qersh-key:' . $key ), 0, 8 );
	}

	/**
	 * JavaScript String.prototype.trim(): white space and line terminators at both ends, NBSP and BOM included.
	 * Invalid UTF-8 is first repaired with to_valid_utf8().
	 *
	 * @param string $s Text.
	 * @return string
	 */
	public static function js_trim( string $s ): string {
		$s   = self::to_valid_utf8( $s );
		$out = preg_replace( '/^[' . self::JS_SPACE . ']+|[' . self::JS_SPACE . ']+$/uD', '', $s );
		return null === $out ? $s : $out;
	}

	/**
	 * The JavaScript length of a UTF-8 string: its UTF-16 code units (a character outside the BMP counts 2).
	 *
	 * @param string $s Valid UTF-8.
	 * @return int
	 */
	public static function utf16_length( string $s ): int {
		$continuation = (int) preg_match_all( '/[\x80-\xBF]/', $s );
		$astral       = (int) preg_match_all( '/[\xF0-\xF7]/', $s );
		return strlen( $s ) - $continuation + $astral;
	}

	/**
	 * The text a JavaScript string would hold after decoding these bytes: valid UTF-8 is returned as it is, and each
	 * invalid sequence becomes one U+FFFD, as the WHATWG UTF-8 decoder does (maximal subparts).
	 *
	 * @param string $s Bytes.
	 * @return string
	 */
	public static function to_valid_utf8( string $s ): string {
		if ( 1 === preg_match( '//u', $s ) ) {
			return $s;
		}
		$out = '';
		$n   = strlen( $s );
		$i   = 0;
		while ( $i < $n ) {
			$b = ord( $s[ $i ] );
			if ( $b < 0x80 ) {
				$out .= $s[ $i ];
				++$i;
				continue;
			}
			if ( $b >= 0xC2 && $b <= 0xDF ) {
				$need = 1;
				$lo   = 0x80;
				$hi   = 0xBF;
			} elseif ( 0xE0 === $b ) {
				$need = 2;
				$lo   = 0xA0;
				$hi   = 0xBF;
			} elseif ( 0xED === $b ) {
				$need = 2;
				$lo   = 0x80;
				$hi   = 0x9F;
			} elseif ( $b >= 0xE1 && $b <= 0xEF ) {
				$need = 2;
				$lo   = 0x80;
				$hi   = 0xBF;
			} elseif ( 0xF0 === $b ) {
				$need = 3;
				$lo   = 0x90;
				$hi   = 0xBF;
			} elseif ( 0xF4 === $b ) {
				$need = 3;
				$lo   = 0x80;
				$hi   = 0x8F;
			} elseif ( $b >= 0xF1 && $b <= 0xF3 ) {
				$need = 3;
				$lo   = 0x80;
				$hi   = 0xBF;
			} else {
				$out .= "\xEF\xBF\xBD";
				++$i;
				continue;
			}
			$j  = $i + 1;
			$ok = true;
			for ( $k = 0; $k < $need; $k++ ) {
				if ( $j >= $n ) {
					$ok = false;
					break;
				}
				$c   = ord( $s[ $j ] );
				$min = 0 === $k ? $lo : 0x80;
				$max = 0 === $k ? $hi : 0xBF;
				if ( $c < $min || $c > $max ) {
					$ok = false;
					break;
				}
				++$j;
			}
			$out .= $ok ? substr( $s, $i, $j - $i ) : "\xEF\xBF\xBD";
			$i    = $j;
		}
		return $out;
	}

	/**
	 * The receiver rules: validateConfig of config.ts without keys, then the plugin's endpoint and event rules.
	 *
	 * @param array $r The receiver part.
	 * @return array
	 */
	private static function validate_receiver( array $r ): array {
		$issues = array();

		$endpoint = isset( $r['endpoint'] ) && is_array( $r['endpoint'] ) ? $r['endpoint'] : null;
		$path     = null !== $endpoint && array_key_exists( 'path', $endpoint ) ? $endpoint['path'] : null;
		if ( ! is_string( $path ) || '' === $path || 0 === strpos( $path, '/' ) || 1 === preg_match( '/[' . self::JS_SPACE . ']/u', self::to_valid_utf8( $path ) ) ) {
			$issues[] = self::issue( 'receiver.endpoint.path', 'a non-empty path without a leading slash or white space' );
		} elseif ( self::ENDPOINT_PATH !== $path ) {
			$issues[] = self::issue( 'receiver.endpoint.path', 'must be ' . self::ENDPOINT_PATH . ' (the route is registered at that path)' );
		}

		$tolerance = array_key_exists( 'tolerance_sec', $r ) ? $r['tolerance_sec'] : null;
		if ( ! self::is_integer( $tolerance ) || $tolerance < 1 || $tolerance > 3600 ) {
			$issues[] = self::issue( 'receiver.tolerance_sec', 'an integer number of seconds between 1 and 3600 (the contract uses 300)' );
		}

		$events = array_key_exists( 'events', $r ) ? $r['events'] : null;
		if ( ! self::is_list( $events ) || array() === $events ) {
			$issues[] = self::issue( 'receiver.events', 'a non-empty array of event names' );
		} else {
			foreach ( $events as $i => $e ) {
				if ( ! is_string( $e ) || ! in_array( $e, self::QERSH_EVENTS, true ) ) {
					$issues[] = self::issue( 'receiver.events[' . $i . ']', 'unknown event; one of ' . implode( ', ', self::QERSH_EVENTS ) );
				} elseif ( ! in_array( $e, self::EVENTS_ALLOWED, true ) ) {
					$issues[] = self::issue( 'receiver.events[' . $i . ']', 'not handled by this plugin; one of ' . implode( ', ', self::EVENTS_ALLOWED ) );
				}
			}
			if ( ! in_array( 'transfer.received', $events, true ) ) {
				$issues[] = self::issue( 'receiver.events', 'must include transfer.received' );
			}
		}

		$matching = isset( $r['matching'] ) && is_array( $r['matching'] ) ? $r['matching'] : array();
		$late     = array_key_exists( 'late_window_min', $matching ) ? $matching['late_window_min'] : null;
		$skew     = array_key_exists( 'skew_min', $matching ) ? $matching['skew_min'] : null;
		if ( ! self::is_integer( $late ) || $late < 0 ) {
			$issues[] = self::issue( 'receiver.matching.late_window_min', 'a non-negative integer number of minutes' );
		} elseif ( ! self::fits_int( $late ) ) {
			$issues[] = self::issue( 'receiver.matching.late_window_min', self::too_large() );
		}
		if ( ! self::is_integer( $skew ) || $skew < 0 ) {
			$issues[] = self::issue( 'receiver.matching.skew_min', 'a non-negative integer number of minutes' );
		} elseif ( ! self::fits_int( $skew ) ) {
			$issues[] = self::issue( 'receiver.matching.skew_min', self::too_large() );
		}

		$dedup = isset( $r['dedup'] ) && is_array( $r['dedup'] ) ? $r['dedup'] : array();
		$keep  = array_key_exists( 'keep_days', $dedup ) ? $dedup['keep_days'] : null;
		if ( ! self::is_integer( $keep ) || $keep < 1 ) {
			$issues[] = self::issue( 'receiver.dedup.keep_days', 'a positive integer number of days (the contract asks for at least 180)' );
		} elseif ( ! self::fits_int( $keep ) ) {
			$issues[] = self::issue( 'receiver.dedup.keep_days', self::too_large() );
		}

		return $issues;
	}

	/**
	 * The wallet rules of docs/17 §4.2. No rule looks at a wallet type: a legacy `provider` in the entry is ignored,
	 * and receive_address is a wallet number for every wallet.
	 *
	 * @param mixed $wallets The wallets part.
	 * @return array
	 */
	private static function validate_wallets( $wallets ): array {
		if ( ! self::is_list( $wallets ) ) {
			return array( self::issue( 'wallets', 'an array of wallets' ) );
		}
		$issues = array();
		$seen   = array();
		foreach ( $wallets as $i => $w ) {
			$at = 'wallets[' . $i . ']';
			if ( ! is_array( $w ) ) {
				$issues[] = self::issue( $at, 'a wallet object' );
				continue;
			}
			$id = array_key_exists( 'id', $w ) ? $w['id'] : null;
			if ( ! self::wallet_id_ok( $id ) ) {
				$issues[] = self::issue( $at . '.id', 'twelve characters, a to z and 0 to 9' );
			} elseif ( isset( $seen[ $id ] ) ) {
				$issues[] = self::issue( $at . '.id', 'the same id as wallets[' . $seen[ $id ] . ']' );
			} else {
				$seen[ $id ] = $i;
			}
			if ( ! array_key_exists( 'label', $w ) || ! is_string( $w['label'] ) ) {
				$issues[] = self::issue( $at . '.label', 'text' );
			}
			if ( null === self::wallet_number( array_key_exists( 'receive_address', $w ) ? $w['receive_address'] : null ) ) {
				$issues[] = self::issue( $at . '.receive_address', 'an 11-digit wallet number such as 01001234567' );
			}
			if ( ! array_key_exists( 'enabled', $w ) || ! is_bool( $w['enabled'] ) ) {
				$issues[] = self::issue( $at . '.enabled', 'true or false' );
			}
		}
		return $issues;
	}

	/**
	 * The store rules of docs/17 §4.2.
	 *
	 * @param array $s The store part.
	 * @return array
	 */
	private static function validate_store( array $s ): array {
		$issues = array();

		$ttl = array_key_exists( 'order_ttl_min', $s ) ? $s['order_ttl_min'] : null;
		if ( ! self::is_integer( $ttl ) || $ttl < 15 || $ttl > 1440 ) {
			$issues[] = self::issue( 'store.order_ttl_min', 'an integer number of minutes between 15 and 1440' );
		}

		$bounds_ok = true;
		foreach ( array( 'min_total_minor', 'max_total_minor' ) as $key ) {
			$v = array_key_exists( $key, $s ) ? $s[ $key ] : null;
			if ( null !== $v && ( ! self::is_integer( $v ) || $v < 0 ) ) {
				$issues[]  = self::issue( 'store.' . $key, 'empty, or a non-negative integer number of piasters' );
				$bounds_ok = false;
			} elseif ( null !== $v && ! self::fits_int( $v ) ) {
				$issues[]  = self::issue( 'store.' . $key, self::too_large() );
				$bounds_ok = false;
			}
		}
		$min = array_key_exists( 'min_total_minor', $s ) ? $s['min_total_minor'] : null;
		$max = array_key_exists( 'max_total_minor', $s ) ? $s['max_total_minor'] : null;
		if ( $bounds_ok && null !== $min && null !== $max && $min > $max ) {
			$issues[] = self::issue( 'store.min_total_minor', 'not more than store.max_total_minor' );
		}

		foreach ( self::STORE_BOOLEANS as $key ) {
			if ( ! array_key_exists( $key, $s ) || ! is_bool( $s[ $key ] ) ) {
				$issues[] = self::issue( 'store.' . $key, 'true or false' );
			}
		}

		$days = array_key_exists( 'unmatched_retention_days', $s ) ? $s['unmatched_retention_days'] : null;
		if ( ! self::is_integer( $days ) || $days < 1 || $days > 3650 ) {
			$issues[] = self::issue( 'store.unmatched_retention_days', 'an integer number of days between 1 and 3650' );
		}

		return $issues;
	}

	/**
	 * A wallet entry with the known fields only (WALLET_FIELDS, so a legacy `provider` is dropped): a missing label
	 * becomes '' and a missing enabled true, and a receive_address that normalises to a wallet number
	 * (wallet_number()) is kept normalised. Everything else is kept as given, for validate() to report.
	 *
	 * @param array $w Wallet entry.
	 * @return array
	 */
	private static function wallet_with_defaults( array $w ): array {
		$out = array();
		foreach ( self::WALLET_FIELDS as $field ) {
			$out[ $field ] = array_key_exists( $field, $w ) ? $w[ $field ] : null;
		}
		if ( null === $out['label'] ) {
			$out['label'] = '';
		}
		if ( null === $out['enabled'] ) {
			$out['enabled'] = true;
		}
		$number = self::wallet_number( $out['receive_address'] );
		if ( null !== $number ) {
			$out['receive_address'] = $number;
		}
		return $out;
	}

	/**
	 * `value ?? default` of the TypeScript, with a whole-number float that fits in an int turned into an int. A
	 * float outside the int range is kept as it is (a cast would wrap it), for validate() to report.
	 *
	 * @param array  $a        Array.
	 * @param string $key      Key.
	 * @param mixed  $fallback Default.
	 * @return mixed
	 */
	private static function value_or( array $a, string $key, $fallback ) {
		if ( ! array_key_exists( $key, $a ) || null === $a[ $key ] ) {
			return $fallback;
		}
		$v = $a[ $key ];
		return is_float( $v ) && self::is_integer( $v ) && self::fits_int( $v ) ? (int) $v : $v;
	}

	/**
	 * Number.isInteger(): an int, or a finite float with no fraction (1e300 included).
	 *
	 * @param mixed $v Value.
	 * @return bool
	 */
	private static function is_integer( $v ): bool {
		if ( is_int( $v ) ) {
			return true;
		}
		return is_float( $v ) && is_finite( $v ) && floor( $v ) === $v;
	}

	/**
	 * Whether a number is an int, or a float that (int) converts exactly: from PHP_INT_MIN up to, but not
	 * including, 2^63 (2^31 on 32-bit PHP). Both bounds are exact as floats.
	 *
	 * @param mixed $v Value.
	 * @return bool
	 */
	private static function fits_int( $v ): bool {
		if ( is_int( $v ) ) {
			return true;
		}
		return is_float( $v ) && $v >= (float) PHP_INT_MIN && $v < -( (float) PHP_INT_MIN );
	}

	/**
	 * The issue message for a whole number that does not fit in a PHP int on this server.
	 *
	 * @return string
	 */
	private static function too_large(): string {
		return 'too large for this server (at most ' . PHP_INT_MAX . ')';
	}

	/**
	 * Array.isArray() for decoded JSON: a PHP list.
	 *
	 * @param mixed $v Value.
	 * @return bool
	 */
	private static function is_list( $v ): bool {
		return is_array( $v ) && ( array() === $v || array_keys( $v ) === range( 0, count( $v ) - 1 ) );
	}

	/**
	 * The strings of a list, in order (stringList of config.ts); anything that is not a list gives an empty list.
	 *
	 * @param mixed $v Value.
	 * @return array
	 */
	private static function string_list( $v ): array {
		if ( ! self::is_list( $v ) ) {
			return array();
		}
		$out = array();
		foreach ( $v as $item ) {
			if ( is_string( $item ) ) {
				$out[] = $item;
			}
		}
		return $out;
	}

	/**
	 * One issue.
	 *
	 * @param string $path    Field path.
	 * @param string $message What the field must be.
	 * @return array
	 */
	private static function issue( string $path, string $message ): array {
		return array(
			'path'    => $path,
			'message' => $message,
		);
	}
}
