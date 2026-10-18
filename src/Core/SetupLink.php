<?php
/**
 * The setup link the merchant opens on the phone ("your developer's link"), and reading a pasted connection key.
 *
 * @package QershPay\WooCommerce
 */

declare( strict_types=1 );

namespace QershPay\WooCommerce\Core;

defined( 'ABSPATH' ) || exit;

/**
 * A literal port of setup-link.ts in packages/qersh-webhook (which mirrors the app's SetupLink.kt and WebhookUrl):
 * `qersh://setup?v=1&url=<urlencoded>[&secret=<key>][&label=<text>][&provider=<id>]`. Pure PHP.
 *
 * Differences from the TypeScript, all deliberate: build() never writes `events` (docs/17 §4.1: listing
 * webhook.test voids the link and listing others switches off the rest); new URL() is replaced by the part of the
 * WHATWG URL parser that decides the result (scheme, user info, host); and extract_secret() is added.
 *
 * Known differences of the URL check (validate_receiver_url(), and so validate() and parse()), with intl and
 * mbstring loaded. For ASCII hosts the result equals Node's. For hosts with non-ASCII characters, PHP's UTS 46
 * (ICU through idn_to_ascii, CheckBidi and CheckJoiners on as the WHATWG URL Standard asks) and Node's URL parser
 * (Ada) disagree in a few corners; PHP then answers url_invalid where Node may answer ok or another problem. None
 * is a real store address:
 * - labels the Bidi rule (RFC 5893) refuses that Node accepts: a label of Arabic-Indic digits only (`١٢.com`,
 *   `١٢٣`, and the same percent-encoded), a label mixing them with Latin (`a١.com`), a right-to-left label that
 *   starts with a digit (`1א.com`), and an IPv4 address written in Arabic-Indic digits. Dropping CheckBidi would
 *   not give parity either: ICU would then accept `متجرshop.com`, which Node refuses;
 * - an ASCII form of 255 bytes or more: idn_to_ascii() fails with an empty $info (a fixed buffer), while WHATWG
 *   accepts any length (VerifyDnsLength off);
 * - characters whose UTS 46 mapping differs between the two Unicode versions, such as U+180E;
 * - an `xn--` label that is not valid Punycode (`xn--a.com`, `xn--zz-zz.com`, `xn--`): ICU refuses it, and Node's
 *   answer depends on its version (Node 24.17 refuses it, later 24.x releases accept it).
 * Without intl, a non-ASCII host is only lower-cased (see domain_to_ascii()). Parity generators keep such hosts out,
 * or compare only whether the URL is accepted.
 */
final class SetupLink {

	/**
	 * Event names the app accepts in a link's `events` list. webhook.test is always sent and cannot be listed.
	 */
	const EVENTS = array(
		'transfer.received',
		'transfer.sent',
		'transfer.failed',
		'transfer.updated',
		'message.unparsed',
		'device.heartbeat',
		'subscription.limit_reached',
	);

	/**
	 * Wallet type ids (kit/webhook.v1.schema.json `wallet.provider`). The app accepts other values but flags them.
	 */
	const PROVIDERS = array(
		'vodafone_cash',
		'etisalat_cash',
		'orange_cash',
		'instapay_app',
		'bank_egbank',
		'bank_nbe',
		'bank_cib',
		'bank_kfh',
	);

	const LABEL_MAX = 60;
	const URL_MAX   = 2048;

	/**
	 * The app's `WebhookUrl.PRIVATE_HOST`: loopback, RFC 1918 and a single-label `.local` name. No IPv6. The pattern
	 * is the TypeScript one; `D` makes `$` the end of the text, as in JavaScript.
	 */
	const PRIVATE_HOST = '/^(localhost|127\.\d+\.\d+\.\d+|10\.\d+\.\d+\.\d+|192\.168\.\d+\.\d+|172\.(1[6-9]|2\d|3[01])\.\d+\.\d+|[a-z0-9-]+\.local)$/iD';

	/**
	 * Sentence punctuation the reader drops from the end of a link.
	 */
	const TRAILING = array( '.', ',', ')', ';', ':', '?' );

	/**
	 * Chat wrappers (*bold*, _italic_, ~strike~, 'quoted') dropped from the end of a link they opened before.
	 */
	const WRAPPERS = array( '*', '_', '~', "'" );

	/**
	 * LINK_RX of setup-link.ts: a link ends at white space (JavaScript `\s`), at a bidi or zero-width mark and at any
	 * character that is never raw in an encoded link. The scheme and path are spelled case by case because PCRE's
	 * Unicode case folding would also match `ſ` (U+017F) for `s`, which JavaScript's /i does not.
	 */
	const LINK_RX = '/[qQ][eE][rR][sS][hH]:\/\/[sS][eE][tT][uU][pP]\?[^\x{0009}-\x{000D}\x{0020}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}\x{200B}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}`"\x{201C}\x{201D}\x{2018}\x{2019}\x{00AB}\x{00BB}<>{}\[\]|\\\\^\x{060C}\x{061B}\x{061F}\x{2026}]+/u';

	/**
	 * Marks a copy from a chat may leave around a pasted key: zero-width, bidi, BOM and the Arabic letter mark.
	 */
	const INVISIBLE = '\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}\x{061C}';

	/**
	 * Schemes the WHATWG URL parser treats as special.
	 */
	const SPECIAL_SCHEMES = array( 'ftp', 'file', 'http', 'https', 'ws', 'wss' );

	/**
	 * Hosts for which the app allows plain http (developer mode).
	 *
	 * @param string $host Host name.
	 * @return bool
	 */
	public static function is_local_http_host( string $host ): bool {
		return 1 === preg_match( self::PRIVATE_HOST, $host );
	}

	/**
	 * The app's rule for a key inside a link (`SetupLink.isUsableSecret`): the pasted-key rule (24+ characters, no
	 * white space), not a URL, and a key that starts like a Qersh key is made only of the characters such a key has.
	 *
	 * @param mixed $s Value.
	 * @return bool
	 */
	public static function is_usable_secret( $s ): bool {
		if ( ! Config::is_acceptable_key( $s ) ) {
			return false;
		}
		$t = Config::js_trim( $s );
		if ( false !== strpos( $t, '://' ) ) {
			return false;
		}
		return 0 !== strpos( $t, 'qsk_' ) || 1 === preg_match( '/^qsk_[A-Za-z0-9_-]+$/D', $t );
	}

	/**
	 * Validates a receiver URL the way the app does for a link (`WebhookUrl.isValid(url, allowInsecure = true)`).
	 *
	 * @param mixed $url Value.
	 * @return array `[ 'ok' => true ]` or `[ 'ok' => false, 'problem' => string ]`.
	 */
	public static function validate_receiver_url( $url ): array {
		if ( ! is_string( $url ) || '' === Config::js_trim( $url ) ) {
			return self::problem( 'url_missing' );
		}
		$text = Config::js_trim( $url );
		if ( Config::utf16_length( $text ) > self::URL_MAX ) {
			return self::problem( 'url_too_long' );
		}
		$parsed = self::url_parts( $text );
		if ( null === $parsed ) {
			return self::problem( 'url_invalid' );
		}
		// Android reports user info for a bare "@" too, which the WHATWG parser swallows: look at the text.
		if ( '' !== $parsed['username'] || '' !== $parsed['password'] || 1 === preg_match( '/^[a-z][a-z0-9+.-]*:\/\/[^\/?#]*@/i', $text ) ) {
			return self::problem( 'url_userinfo' );
		}
		if ( '' === $parsed['hostname'] ) {
			return self::problem( 'url_invalid' );
		}
		if ( 'https' === $parsed['scheme'] ) {
			return array( 'ok' => true );
		}
		if ( 'http' === $parsed['scheme'] ) {
			return self::is_local_http_host( $parsed['hostname'] ) ? array( 'ok' => true ) : self::problem( 'url_insecure_remote' );
		}
		return self::problem( 'url_scheme' );
	}

	/**
	 * Checks link parameters (`url`, `secret`, `label`, `provider`, `events`) against the app's reader rules.
	 * Never throws.
	 *
	 * @param array $params Parameters.
	 * @return array `[ 'ok' => true, 'warnings' => string[] ]` or `[ 'ok' => false, 'problem' => string ]`, with
	 *               `detail` (the event name) for event_unknown.
	 */
	public static function validate( array $params ): array {
		$url = self::validate_receiver_url( array_key_exists( 'url', $params ) ? $params['url'] : null );
		if ( ! $url['ok'] ) {
			return self::problem( $url['problem'] );
		}
		$warnings = array();
		$secret   = array_key_exists( 'secret', $params ) ? $params['secret'] : null;
		if ( is_string( $secret ) && '' !== Config::js_trim( $secret ) ) {
			if ( ! self::is_usable_secret( $secret ) ) {
				return self::problem( 'secret_invalid' );
			}
			$warnings[] = 'secret_included';
		} elseif ( null !== $secret && '' !== $secret ) {
			return self::problem( 'secret_invalid' );
		}
		foreach ( self::event_names( array_key_exists( 'events', $params ) ? $params['events'] : null ) as $e ) {
			if ( ! in_array( $e, self::EVENTS, true ) ) {
				return array(
					'ok'      => false,
					'problem' => 'event_unknown',
					'detail'  => $e,
				);
			}
		}
		$label = array_key_exists( 'label', $params ) ? $params['label'] : null;
		if ( is_string( $label ) && Config::utf16_length( Config::js_trim( $label ) ) > self::LABEL_MAX ) {
			$warnings[] = 'label_truncated';
		}
		$provider = array_key_exists( 'provider', $params ) ? $params['provider'] : null;
		if ( is_string( $provider ) && '' !== Config::js_trim( $provider ) && ! in_array( self::lower( Config::js_trim( $provider ) ), self::PROVIDERS, true ) ) {
			$warnings[] = 'provider_unknown';
		}
		return array(
			'ok'       => true,
			'warnings' => $warnings,
		);
	}

	/**
	 * Percent-encodes a link parameter like encodeURIComponent, plus the characters `!'()*` it leaves raw: exactly
	 * rawurlencode(). Invalid UTF-8 is replaced first (U+FFFD), as the TypeScript replaces lone surrogates.
	 *
	 * @param string $value Text.
	 * @return string
	 */
	public static function encode_link_param( string $value ): string {
		return rawurlencode( Config::to_valid_utf8( $value ) );
	}

	/**
	 * Builds the link. Validate first with validate(); this only shapes what it is given (label cut to 60 UTF-16
	 * units, provider lower-cased, empty parts omitted, a final `. , ) ; : ?` percent-encoded). `events` is never
	 * written, whatever the parameters say.
	 *
	 * @param array $params `url`, and optionally `secret`, `label`, `provider`.
	 * @return string
	 */
	public static function build( array $params ): string {
		$url  = isset( $params['url'] ) && is_string( $params['url'] ) ? Config::js_trim( $params['url'] ) : '';
		$link = 'qersh://setup?v=1&url=' . self::encode_link_param( $url );
		if ( isset( $params['secret'] ) && is_string( $params['secret'] ) && '' !== Config::js_trim( $params['secret'] ) ) {
			$link .= '&secret=' . self::encode_link_param( Config::js_trim( $params['secret'] ) );
		}
		$label = self::cut_label( array_key_exists( 'label', $params ) ? $params['label'] : null );
		if ( '' !== $label ) {
			$link .= '&label=' . self::encode_link_param( $label );
		}
		$provider = isset( $params['provider'] ) && is_string( $params['provider'] ) ? self::lower( Config::js_trim( $params['provider'] ) ) : '';
		if ( '' !== $provider ) {
			$link .= '&provider=' . self::encode_link_param( $provider );
		}
		$last = substr( $link, -1 );
		if ( in_array( $last, self::TRAILING, true ) ) {
			$link = substr( $link, 0, -1 ) . sprintf( '%%%02X', ord( $last ) );
		}
		return $link;
	}

	/**
	 * The setup link inside any text a chat app or the clipboard hands over, or null. Same rules as the app.
	 *
	 * @param mixed $text Value.
	 * @return string|null
	 */
	public static function extract( $text ): ?string {
		if ( ! is_string( $text ) ) {
			return null;
		}
		$text = Config::to_valid_utf8( $text );
		if ( 1 !== preg_match( self::LINK_RX, $text, $m, PREG_OFFSET_CAPTURE ) ) {
			return null;
		}
		$link  = self::trim_trailing( $m[0][0] );
		$index = (int) $m[0][1];
		// A byte below 0x80 is a whole character; anything else is never a wrapper.
		$before = $index > 0 ? $text[ $index - 1 ] : '';
		if ( '' !== $before && in_array( $before, self::WRAPPERS, true ) && substr( $link, -1 ) === $before ) {
			$link = self::trim_trailing( substr( $link, 0, -1 ) );
		}
		return $link;
	}

	/**
	 * Reads a link back with the app's reader rules.
	 *
	 * @param mixed $text Value.
	 * @return array `[ 'ok' => true, 'link' => [ url, secret, label, provider, events ] ]` (null for an absent
	 *               part) or `[ 'ok' => false, 'problem' => string ]` (a SetupLink problem, `not_a_setup_link` or
	 *               `bad_version`).
	 */
	public static function parse( $text ): array {
		$link = self::extract( $text );
		if ( null === $link ) {
			return self::problem( 'not_a_setup_link' );
		}
		$q     = strpos( $link, '?' );
		$query = self::query_pairs( false === $q ? $link : substr( $link, $q + 1 ) );
		$v     = self::query_get( $query, 'v' );
		if ( null !== $v && '1' !== $v ) {
			return self::problem( 'bad_version' );
		}
		$url   = Config::js_trim( (string) self::query_get( $query, 'url' ) );
		$check = self::validate_receiver_url( $url );
		if ( ! $check['ok'] ) {
			return self::problem( $check['problem'] );
		}
		$secret = Config::js_trim( (string) self::query_get( $query, 'secret' ) );
		if ( '' !== $secret && ! self::is_usable_secret( $secret ) ) {
			return self::problem( 'secret_invalid' );
		}
		$events = self::event_names( explode( ',', (string) self::query_get( $query, 'events' ) ) );
		foreach ( $events as $e ) {
			if ( ! in_array( $e, self::EVENTS, true ) ) {
				return self::problem( 'event_unknown' );
			}
		}
		$label    = self::cut_label( self::query_get( $query, 'label' ) );
		$provider = self::lower( Config::js_trim( (string) self::query_get( $query, 'provider' ) ) );
		return array(
			'ok'   => true,
			'link' => array(
				'url'      => $url,
				'secret'   => '' === $secret ? null : $secret,
				'label'    => '' === $label ? null : $label,
				'provider' => '' === $provider ? null : $provider,
				'events'   => array() === $events ? null : $events,
			),
		);
	}

	/**
	 * Reads a connection key out of whatever the merchant pasted (docs/17 §4.1 and §4.8), in this order:
	 * - a setup link anywhere in the text (the phone's whole share message, or the link alone), read with the
	 *   rules of parse(): its secret (`message` or `link`), `keyless_link` when it has none, `invalid` when the
	 *   link is not readable;
	 * - a line `QERSH_WEBHOOK_SECRET=…` (optionally `export`, quotes removed): `line`. A line naming only
	 *   QERSH_WEBHOOK_SECRET_PREVIOUS is `invalid`: that key stops working on the phone within 24 hours;
	 * - the key itself, by Config::is_acceptable_key(), quotes around it removed as in the app's readPastedKey():
	 *   `key`.
	 * Zero-width and bidi marks around a key are dropped. Text with nothing key-like is `not_found`.
	 *
	 * `url` is the link's receiver URL for the `message` and `link` sources (null for `line` and `key`): the phone's
	 * link names the wallet it was made for (…/qersh/v1/webhook/<wallet id>), so a caller can refuse a paste that
	 * belongs to another wallet.
	 *
	 * @param mixed $text Value.
	 * @return array `[ 'ok' => true, 'secret' => string, 'source' => 'message'|'link'|'line'|'key', 'url' => ?string ]`
	 *               or `[ 'ok' => false, 'error' => 'keyless_link'|'not_found'|'invalid' ]`.
	 */
	public static function extract_secret( $text ): array {
		if ( ! is_string( $text ) ) {
			return self::secret_error( 'not_found' );
		}
		$pasted = Config::js_trim( Config::to_valid_utf8( $text ) );
		if ( '' === self::strip_invisible( $pasted ) ) {
			return self::secret_error( 'not_found' );
		}

		$link = self::extract( $pasted );
		if ( null !== $link ) {
			$parsed = self::parse( $pasted );
			if ( ! $parsed['ok'] ) {
				return self::secret_error( 'invalid' );
			}
			if ( null === $parsed['link']['secret'] ) {
				return self::secret_error( 'keyless_link' );
			}
			return self::secret_ok( $parsed['link']['secret'], self::strip_invisible( $pasted ) === $link ? 'link' : 'message', $parsed['link']['url'] );
		}

		$saw_previous = false;
		$lines        = preg_split( '/\r\n|[\n\r\x{2028}\x{2029}]/u', $pasted );
		foreach ( false === $lines ? array() : $lines as $line ) {
			$line = self::strip_invisible( $line );
			if ( 1 !== preg_match( '/^(?:export[' . Config::JS_SPACE . ']+)?QERSH_WEBHOOK_SECRET(_PREVIOUS)?[' . Config::JS_SPACE . ']*=(.*)$/uD', $line, $m ) ) {
				continue;
			}
			if ( '' !== $m[1] ) {
				$saw_previous = true;
				continue;
			}
			$value = self::strip_invisible( self::unquote( Config::js_trim( $m[2] ) ) );
			if ( false === strpos( $value, '://' ) && Config::is_acceptable_key( $value ) ) {
				return self::secret_ok( Config::js_trim( $value ), 'line' );
			}
			return self::secret_error( 'invalid' );
		}
		if ( $saw_previous ) {
			return self::secret_error( 'invalid' );
		}

		// The app's readPastedKey() removes surrounding quotes from every paste without a link, as for the line.
		$key = self::strip_invisible( self::unquote( self::strip_invisible( $pasted ) ) );
		if ( false === strpos( $key, '://' ) && Config::is_acceptable_key( $key ) ) {
			return self::secret_ok( Config::js_trim( $key ), 'key' );
		}
		if ( false === strpos( $key, '://' ) && 1 === preg_match( '/[' . Config::JS_SPACE . ']/u', $key ) ) {
			return self::secret_error( 'not_found' );
		}
		return self::secret_error( 'invalid' );
	}

	/**
	 * The names of an `events` list the way the reader cleans them: trimmed, empty ones dropped.
	 *
	 * @param mixed $events Value.
	 * @return string[]
	 */
	private static function event_names( $events ): array {
		$out = array();
		foreach ( is_array( $events ) ? $events : array() as $e ) {
			$name = is_string( $e ) ? Config::js_trim( $e ) : '';
			if ( '' !== $name ) {
				$out[] = $name;
			}
		}
		return $out;
	}

	/**
	 * The label as the app keeps it: trimmed, cut to 60 UTF-16 units, without a character the cut would split.
	 *
	 * @param mixed $label Value.
	 * @return string
	 */
	private static function cut_label( $label ): string {
		if ( ! is_string( $label ) ) {
			return '';
		}
		$s     = Config::js_trim( $label );
		$out   = '';
		$units = 0;
		$chars = preg_split( '//u', $s, -1, PREG_SPLIT_NO_EMPTY );
		foreach ( false === $chars ? array() : $chars as $ch ) {
			$width = strlen( $ch ) >= 4 ? 2 : 1;
			if ( $units + $width > self::LABEL_MAX ) {
				break;
			}
			$out   .= $ch;
			$units += $width;
		}
		return $out;
	}

	/**
	 * Drops sentence punctuation from the end.
	 *
	 * @param string $s Text.
	 * @return string
	 */
	private static function trim_trailing( string $s ): string {
		$end = strlen( $s );
		while ( $end > 0 && in_array( $s[ $end - 1 ], self::TRAILING, true ) ) {
			--$end;
		}
		return substr( $s, 0, $end );
	}

	/**
	 * URLSearchParams of a query string: `&`-separated pairs, the first `=` splits name and value, `+` is a space,
	 * percent-decoding leaves a malformed `%` as it is, and invalid UTF-8 becomes U+FFFD.
	 *
	 * @param string $query Query, without the `?`.
	 * @return array List of [ name, value ].
	 */
	private static function query_pairs( string $query ): array {
		if ( 0 === strpos( $query, '?' ) ) {
			$query = substr( $query, 1 );
		}
		$pairs = array();
		foreach ( explode( '&', $query ) as $sequence ) {
			if ( '' === $sequence ) {
				continue;
			}
			$eq      = strpos( $sequence, '=' );
			$name    = false === $eq ? $sequence : substr( $sequence, 0, $eq );
			$value   = false === $eq ? '' : substr( $sequence, $eq + 1 );
			$pairs[] = array( self::form_decode( $name ), self::form_decode( $value ) );
		}
		return $pairs;
	}

	/**
	 * URLSearchParams.get(): the first value with that name, or null.
	 *
	 * @param array  $pairs Pairs.
	 * @param string $name  Name.
	 * @return string|null
	 */
	private static function query_get( array $pairs, string $name ): ?string {
		foreach ( $pairs as $pair ) {
			if ( $pair[0] === $name ) {
				return $pair[1];
			}
		}
		return null;
	}

	/**
	 * Decodes one application/x-www-form-urlencoded part.
	 *
	 * @param string $s Encoded text.
	 * @return string
	 */
	private static function form_decode( string $s ): string {
		return Config::to_valid_utf8( rawurldecode( str_replace( '+', ' ', $s ) ) );
	}

	/**
	 * JavaScript toLowerCase() (mbstring when present; ASCII otherwise).
	 *
	 * @param string $s Text.
	 * @return string
	 */
	private static function lower( string $s ): string {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : self::ascii_lower( $s );
	}

	/**
	 * ASCII lower-casing that ignores the locale (strtolower() follows it before PHP 8.2).
	 *
	 * @param string $s Text.
	 * @return string
	 */
	private static function ascii_lower( string $s ): string {
		return strtr( $s, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz' );
	}

	/**
	 * Removes zero-width and bidi marks at both ends (and the white space they hid).
	 *
	 * @param string $s Text.
	 * @return string
	 */
	private static function strip_invisible( string $s ): string {
		$class = '[' . self::INVISIBLE . Config::JS_SPACE . ']';
		$out   = preg_replace( '/^' . $class . '+|' . $class . '+$/uD', '', $s );
		return null === $out ? $s : $out;
	}

	/**
	 * Kotlin removeSurrounding("\"") then removeSurrounding("'"), then trim.
	 *
	 * @param string $s Text.
	 * @return string
	 */
	private static function unquote( string $s ): string {
		foreach ( array( '"', "'" ) as $quote ) {
			if ( strlen( $s ) >= 2 && $quote === $s[0] && substr( $s, -1 ) === $quote ) {
				$s = substr( $s, 1, -1 );
			}
		}
		return Config::js_trim( $s );
	}

	/**
	 * A failed check.
	 *
	 * @param string $problem Problem code.
	 * @return array
	 */
	private static function problem( string $problem ): array {
		return array(
			'ok'      => false,
			'problem' => $problem,
		);
	}

	/**
	 * A key read by extract_secret().
	 *
	 * @param string      $secret Key.
	 * @param string      $source Where it came from.
	 * @param string|null $url    The link's receiver URL (link and message sources), else null.
	 * @return array
	 */
	private static function secret_ok( string $secret, string $source, ?string $url = null ): array {
		return array(
			'ok'     => true,
			'secret' => $secret,
			'source' => $source,
			'url'    => $url,
		);
	}

	/**
	 * A refusal of extract_secret().
	 *
	 * @param string $error Error code.
	 * @return array
	 */
	private static function secret_error( string $error ): array {
		return array(
			'ok'    => false,
			'error' => $error,
		);
	}

	/**
	 * What new URL( $text ) would expose of scheme, user info and host name, or null where it throws. This is the
	 * WHATWG URL Standard's basic URL parser without a base, cut down to the states that decide those parts (path,
	 * query and fragment never make it fail): scheme, the special and file authority states, host, port, and host
	 * parsing with IPv4, IPv6, opaque hosts and domain to ASCII (ASCII lower-casing; UTS 46 through intl for a
	 * non-ASCII or `xn--` host when intl is present).
	 *
	 * @param string $text URL text, already trimmed.
	 * @return array|null `[ scheme, username, password, hostname ]`.
	 */
	private static function url_parts( string $text ): ?array {
		$s     = (string) preg_replace( '/^[\x00-\x20]+|[\x00-\x20]+$/D', '', $text );
		$s     = str_replace( array( "\t", "\n", "\r" ), '', $s );
		$chars = '' === $s ? array() : preg_split( '//u', $s, -1, PREG_SPLIT_NO_EMPTY );
		$cp    = false === $chars ? array() : $chars;
		$n     = count( $cp );
		$i     = 0;

		if ( 0 === $n || 1 !== preg_match( '/^[A-Za-z]$/D', $cp[0] ) ) {
			return null;
		}
		$scheme = '';
		while ( $i < $n && 1 === preg_match( '/^[A-Za-z0-9+.\-]$/D', $cp[ $i ] ) ) {
			$scheme .= self::ascii_lower( $cp[ $i ] );
			++$i;
		}
		if ( $i >= $n || ':' !== $cp[ $i ] ) {
			return null;
		}
		++$i;
		$special = in_array( $scheme, self::SPECIAL_SCHEMES, true );
		$parts   = array(
			'scheme'   => $scheme,
			'username' => '',
			'password' => '',
			'hostname' => '',
		);

		if ( 'file' === $scheme ) {
			if ( $i < $n && ( '/' === $cp[ $i ] || '\\' === $cp[ $i ] ) && $i + 1 < $n && ( '/' === $cp[ $i + 1 ] || '\\' === $cp[ $i + 1 ] ) ) {
				$i     += 2;
				$buffer = '';
				while ( $i < $n && ! in_array( $cp[ $i ], array( '/', '\\', '?', '#' ), true ) ) {
					$buffer .= $cp[ $i ];
					++$i;
				}
				if ( '' !== $buffer && 1 !== preg_match( '/^[A-Za-z][:|]$/D', $buffer ) ) {
					$host = self::host_parse( $buffer, false );
					if ( null === $host ) {
						return null;
					}
					$parts['hostname'] = 'localhost' === $host ? '' : $host;
				}
			}
			return $parts;
		}

		if ( $special ) {
			while ( $i < $n && ( '/' === $cp[ $i ] || '\\' === $cp[ $i ] ) ) {
				++$i;
			}
		} elseif ( $i + 1 < $n && '/' === $cp[ $i ] && '/' === $cp[ $i + 1 ] ) {
			$i += 2;
		} else {
			return $parts; // A path or an opaque path: no host, and no failure.
		}

		// Authority state.
		$buffer    = array();
		$at_seen   = false;
		$pass_seen = false;
		while ( true ) {
			$c = $i < $n ? $cp[ $i ] : null;
			if ( '@' === $c ) {
				if ( $at_seen ) {
					array_unshift( $buffer, '%', '4', '0' );
				}
				$at_seen = true;
				foreach ( $buffer as $b ) {
					if ( ':' === $b && ! $pass_seen ) {
						$pass_seen = true;
						continue;
					}
					if ( $pass_seen ) {
						$parts['password'] .= $b;
					} else {
						$parts['username'] .= $b;
					}
				}
				$buffer = array();
				++$i;
				continue;
			}
			if ( null === $c || '/' === $c || '?' === $c || '#' === $c || ( $special && '\\' === $c ) ) {
				if ( $at_seen && array() === $buffer ) {
					return null;
				}
				break;
			}
			$buffer[] = $c;
			++$i;
		}

		// Host state, then port state, on the authority's host part.
		$host_buffer = '';
		$inside      = false;
		$count       = count( $buffer );
		for ( $k = 0; $k < $count; $k++ ) {
			$c = $buffer[ $k ];
			if ( ':' === $c && ! $inside ) {
				if ( '' === $host_buffer ) {
					return null;
				}
				$host = self::host_parse( $host_buffer, ! $special );
				if ( null === $host ) {
					return null;
				}
				$port = implode( '', array_slice( $buffer, $k + 1 ) );
				if ( '' !== $port && ( 1 !== preg_match( '/^[0-9]+$/D', $port ) || strlen( ltrim( $port, '0' ) ) > 5 || (int) $port > 65535 ) ) {
					return null;
				}
				$parts['hostname'] = $host;
				return $parts;
			}
			if ( '[' === $c ) {
				$inside = true;
			} elseif ( ']' === $c ) {
				$inside = false;
			}
			$host_buffer .= $c;
		}
		if ( $special && '' === $host_buffer ) {
			return null;
		}
		$host = self::host_parse( $host_buffer, ! $special );
		if ( null === $host ) {
			return null;
		}
		$parts['hostname'] = $host;
		return $parts;
	}

	/**
	 * The WHATWG host parser.
	 *
	 * @param string $input  Host text.
	 * @param bool   $opaque True for a scheme that is not special.
	 * @return string|null The serialised host, or null on failure.
	 */
	private static function host_parse( string $input, bool $opaque ): ?string {
		if ( 0 === strpos( $input, '[' ) ) {
			if ( ']' !== substr( $input, -1 ) || strlen( $input ) < 2 ) {
				return null;
			}
			return self::ipv6_ok( substr( $input, 1, -1 ) ) ? $input : null;
		}
		if ( $opaque ) {
			if ( 1 === preg_match( '/[\x00\x09\x0A\x0D\x20#\/:<>?@\[\\\\\]^|]/', $input ) ) {
				return null;
			}
			return $input;
		}
		$domain = Config::to_valid_utf8( rawurldecode( $input ) );
		$ascii  = self::domain_to_ascii( $domain );
		if ( null === $ascii || '' === $ascii ) {
			return null;
		}
		if ( 1 === preg_match( '/[\x00-\x20#%\/:<>?@\[\\\\\]^|\x7F]/', $ascii ) ) {
			return null;
		}
		if ( self::ends_in_number( $ascii ) ) {
			return self::ipv4_parse( $ascii );
		}
		return $ascii;
	}

	/**
	 * Domain to ASCII with beStrict false: ASCII lower-casing for an ASCII domain without an `xn--` label, UTS 46
	 * ToASCII (non-transitional, CheckHyphens and VerifyDnsLength off) otherwise. Without intl, a non-ASCII domain
	 * is only lower-cased.
	 *
	 * @param string $domain Decoded domain.
	 * @return string|null
	 */
	private static function domain_to_ascii( string $domain ): ?string {
		$ascii = 1 !== preg_match( '/[^\x00-\x7F]/', $domain );
		if ( $ascii && 1 !== preg_match( '/(?:^|\.)xn--/i', $domain ) ) {
			return self::ascii_lower( $domain );
		}
		if ( ! function_exists( 'idn_to_ascii' ) || ! defined( 'INTL_IDNA_VARIANT_UTS46' ) ) {
			// Without intl only the replacement character, which UTS 46 always refuses, is caught.
			return false === strpos( $domain, "\xEF\xBF\xBD" ) ? self::lower( $domain ) : null;
		}
		$info   = array();
		$flags  = ( defined( 'IDNA_NONTRANSITIONAL_TO_ASCII' ) ? IDNA_NONTRANSITIONAL_TO_ASCII : 0 ) | ( defined( 'IDNA_CHECK_BIDI' ) ? IDNA_CHECK_BIDI : 0 ) | ( defined( 'IDNA_CHECK_CONTEXTJ' ) ? IDNA_CHECK_CONTEXTJ : 0 );
		$result = idn_to_ascii( $domain, $flags, INTL_IDNA_VARIANT_UTS46, $info );
		if ( is_string( $result ) ) {
			return $result;
		}
		if ( ! is_array( $info ) || ! isset( $info['result'], $info['errors'] ) || ! is_string( $info['result'] ) ) {
			return null;
		}
		$ignored = 0;
		foreach ( array( 'IDNA_ERROR_EMPTY_LABEL', 'IDNA_ERROR_LABEL_TOO_LONG', 'IDNA_ERROR_DOMAIN_NAME_TOO_LONG', 'IDNA_ERROR_LEADING_HYPHEN', 'IDNA_ERROR_TRAILING_HYPHEN', 'IDNA_ERROR_HYPHEN_3_4' ) as $name ) {
			if ( defined( $name ) ) {
				$ignored |= (int) constant( $name );
			}
		}
		return 0 === ( (int) $info['errors'] & ~$ignored ) ? $info['result'] : null;
	}

	/**
	 * Whether a domain ends in a number (then it must be an IPv4 address).
	 *
	 * @param string $ascii ASCII domain.
	 * @return bool
	 */
	private static function ends_in_number( string $ascii ): bool {
		$parts = explode( '.', $ascii );
		if ( '' === end( $parts ) ) {
			if ( 1 === count( $parts ) ) {
				return false;
			}
			array_pop( $parts );
		}
		$last = (string) end( $parts );
		if ( '' !== $last && 1 === preg_match( '/^[0-9]+$/D', $last ) ) {
			return true;
		}
		return null !== self::ipv4_number( $last );
	}

	/**
	 * The WHATWG IPv4 number parser (decimal, 0x hex, 0 octal). Values above 2^40 are capped: they fail anyway.
	 *
	 * @param string $part One part.
	 * @return float|null
	 */
	private static function ipv4_number( string $part ): ?float {
		if ( '' === $part ) {
			return null;
		}
		$radix = 10;
		if ( strlen( $part ) >= 2 && ( '0x' === substr( $part, 0, 2 ) || '0X' === substr( $part, 0, 2 ) ) ) {
			$part  = substr( $part, 2 );
			$radix = 16;
		} elseif ( strlen( $part ) >= 2 && '0' === $part[0] ) {
			$part  = substr( $part, 1 );
			$radix = 8;
		}
		if ( '' === $part ) {
			return 0.0;
		}
		$digits = array(
			8  => '/^[0-7]+$/D',
			10 => '/^[0-9]+$/D',
			16 => '/^[0-9A-Fa-f]+$/D',
		);
		if ( 1 !== preg_match( $digits[ $radix ], $part ) ) {
			return null;
		}
		$value = 0.0;
		$len   = strlen( $part );
		for ( $k = 0; $k < $len; $k++ ) {
			$value = $value * $radix + (float) hexdec( $part[ $k ] );
			if ( $value > 1099511627776.0 ) {
				return 1099511627777.0;
			}
		}
		return $value;
	}

	/**
	 * The WHATWG IPv4 parser, serialised as dotted decimal.
	 *
	 * @param string $ascii ASCII domain that ends in a number.
	 * @return string|null
	 */
	private static function ipv4_parse( string $ascii ): ?string {
		$parts = explode( '.', $ascii );
		if ( '' === end( $parts ) && count( $parts ) > 1 ) {
			array_pop( $parts );
		}
		if ( count( $parts ) > 4 ) {
			return null;
		}
		$numbers = array();
		foreach ( $parts as $part ) {
			$value = self::ipv4_number( $part );
			if ( null === $value ) {
				return null;
			}
			$numbers[] = $value;
		}
		$size = count( $numbers );
		for ( $k = 0; $k < $size - 1; $k++ ) {
			if ( $numbers[ $k ] > 255 ) {
				return null;
			}
		}
		if ( $numbers[ $size - 1 ] >= pow( 256, 5 - $size ) ) {
			return null;
		}
		$ipv4 = $numbers[ $size - 1 ];
		for ( $k = 0; $k < $size - 1; $k++ ) {
			$ipv4 += $numbers[ $k ] * pow( 256, 3 - $k );
		}
		$octets = array();
		for ( $k = 0; $k < 4; $k++ ) {
			array_unshift( $octets, (string) (int) fmod( $ipv4, 256 ) );
			$ipv4 = floor( $ipv4 / 256 );
		}
		return implode( '.', $octets );
	}

	/**
	 * Whether the WHATWG IPv6 parser accepts the text between the brackets.
	 *
	 * @param string $input Address text.
	 * @return bool
	 */
	private static function ipv6_ok( string $input ): bool {
		$chars    = '' === $input ? array() : preg_split( '//u', $input, -1, PREG_SPLIT_NO_EMPTY );
		$c        = false === $chars ? array() : $chars;
		$n        = count( $c );
		$p        = 0;
		$piece    = 0;
		$compress = null;
		$at       = function ( $k ) use ( $c, $n ) {
			return $k < $n ? $c[ $k ] : null;
		};
		$hex      = function ( $ch ) {
			return null !== $ch && 1 === preg_match( '/^[0-9A-Fa-f]$/D', $ch );
		};
		$digit    = function ( $ch ) {
			return null !== $ch && 1 === preg_match( '/^[0-9]$/D', $ch );
		};

		if ( ':' === $at( $p ) ) {
			if ( ':' !== $at( $p + 1 ) ) {
				return false;
			}
			$p += 2;
			++$piece;
			$compress = $piece;
		}
		while ( null !== $at( $p ) ) {
			if ( 8 === $piece ) {
				return false;
			}
			if ( ':' === $at( $p ) ) {
				if ( null !== $compress ) {
					return false;
				}
				++$p;
				++$piece;
				$compress = $piece;
				continue;
			}
			$length = 0;
			while ( $length < 4 && $hex( $at( $p ) ) ) {
				++$p;
				++$length;
			}
			if ( '.' === $at( $p ) ) {
				if ( 0 === $length ) {
					return false;
				}
				$p -= $length;
				if ( $piece > 6 ) {
					return false;
				}
				$seen = 0;
				while ( null !== $at( $p ) ) {
					$v4 = null;
					if ( $seen > 0 ) {
						if ( '.' === $at( $p ) && $seen < 4 ) {
							++$p;
						} else {
							return false;
						}
					}
					if ( ! $digit( $at( $p ) ) ) {
						return false;
					}
					while ( $digit( $at( $p ) ) ) {
						$number = (int) $at( $p );
						if ( null === $v4 ) {
							$v4 = $number;
						} elseif ( 0 === $v4 ) {
							return false;
						} else {
							$v4 = $v4 * 10 + $number;
						}
						if ( $v4 > 255 ) {
							return false;
						}
						++$p;
					}
					++$seen;
					if ( 2 === $seen || 4 === $seen ) {
						++$piece;
					}
				}
				if ( 4 !== $seen ) {
					return false;
				}
				break;
			} elseif ( ':' === $at( $p ) ) {
				++$p;
				if ( null === $at( $p ) ) {
					return false;
				}
			} elseif ( null !== $at( $p ) ) {
				return false;
			}
			++$piece;
		}
		return null !== $compress || 8 === $piece;
	}
}
