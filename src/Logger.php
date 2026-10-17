<?php
/**
 * The plugin's log, through WooCommerce's logger (WooCommerce → Status → Logs, source "qersh").
 *
 * @package QershPay\WooCommerce
 */

namespace QershPay\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Writes redacted lines only. Never a key, a signature, a body or what a customer typed: connection keys
 * (`qsk_…`, `secret=…`), hex strings of 32 characters or more (signatures, tokens, hashes), phone numbers and long
 * digit runs (transaction ids) keep their last three digits, InstaPay handles their last three characters, e-mail
 * addresses their domain. Context values under sensitive names are dropped. Logging never throws.
 */
final class Logger {

	/**
	 * Log source shown by WooCommerce.
	 */
	const SOURCE = 'qersh';

	/**
	 * Throttle flags live this long.
	 */
	const THROTTLE_SEC = 60;

	/**
	 * Context keys whose values are never written.
	 */
	const SECRET_KEYS = '/^(?:key|keys|secret|secrets|signature|signatures|sig|token|claim_token|body|raw|raw_body|payload|password|header|headers|payer_name|name|input|typed|text|sms|message_text)$/i';

	/**
	 * Context keys whose scalar values keep their last three characters only.
	 */
	const TAIL_KEYS = '/(?:phone|msisdn|mobile|handle|payer|txn|counterparty|sender)/i';

	/**
	 * One digit, Latin, Arabic-Indic or Persian.
	 */
	const DIGIT = '[0-9\x{0660}-\x{0669}\x{06F0}-\x{06F9}]';

	/**
	 * The plugin, if any.
	 *
	 * @var Plugin|null
	 */
	private $plugin;

	/**
	 * Builds the service.
	 *
	 * @param Plugin|null $plugin The plugin.
	 */
	public function __construct( $plugin = null ) {
		$this->plugin = $plugin;
	}

	/**
	 * Logs at level info.
	 *
	 * @param string $message Message.
	 * @param array  $context Context.
	 */
	public function info( string $message, array $context = array() ) {
		$this->write( 'info', $message, $context );
	}

	/**
	 * Logs at level warning.
	 *
	 * @param string $message Message.
	 * @param array  $context Context.
	 */
	public function warning( string $message, array $context = array() ) {
		$this->write( 'warning', $message, $context );
	}

	/**
	 * Logs at level error.
	 *
	 * @param string $message Message.
	 * @param array  $context Context.
	 */
	public function error( string $message, array $context = array() ) {
		$this->write( 'error', $message, $context );
	}

	/**
	 * One line per minute at most for a key (signature failures, docs/17 §4.1 Logger row). The flag is the only
	 * database write allowed before a valid signature (docs/17 §4.3): wp_cache_add() with a persistent object
	 * cache, otherwise a 60-second transient.
	 *
	 * @param string $key     Throttle key, for example `sig:<wallet>`.
	 * @param string $message Message (redacted like every line).
	 */
	public function throttled( string $key, string $message ) {
		try {
			$flag = 'qersh_wc_log_' . substr( md5( $key ), 0, 20 );
			if ( function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() ) {
				if ( ! wp_cache_add( $flag, 1, 'qersh_wc', self::THROTTLE_SEC ) ) {
					return;
				}
			} else {
				if ( false !== get_transient( $flag ) ) {
					return;
				}
				set_transient( $flag, 1, self::THROTTLE_SEC );
			}
			$this->write( 'warning', $message, array() );
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * Redacts one text. Pure.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function redact( string $text ): string {
		if ( '' === $text ) {
			return '';
		}
		$d    = self::DIGIT;
		$text = (string) preg_replace( '/qsk_\S+/u', 'qsk_[redacted]', $text );
		$text = (string) preg_replace( '/(secret=)[^&\s"\'<>]+/iu', '$1[redacted]', $text );
		$text = (string) preg_replace( '/(QERSH_WEBHOOK_SECRET\s*=\s*)\S+/u', '$1[redacted]', $text );
		$text = (string) preg_replace( '/(?<![0-9A-Fa-f])[0-9A-Fa-f]{32,}(?![0-9A-Fa-f])/u', '[hex]', $text );
		$text = (string) preg_replace_callback(
			'/([A-Za-z0-9._-]+)@instapay\b/iu',
			function ( $m ) {
				return '***' . self::tail( $m[1] ) . '@instapay';
			},
			$text
		);
		$text = (string) preg_replace( '/[A-Za-z0-9._%+-]+@([A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)+)/u', '***@$1', $text );

		// Ids that are not personal stay readable: delivery UUIDs and the app's trf_/wlt_/dev_ ids, tried first at each
		// position. A number is a whole digit run (never one that starts or ends inside a longer run) taken
		// atomically, so it is masked whole or not at all, even where it touches a letter or an underscore: only its
		// last three digits ever show.
		$keep  = '[0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{12}|(?:trf|wlt|dev)_[0-9A-Z]{26}';
		$phone = '(?:\+\s?)?' . $d . '(?:[ \-]?' . $d . '){9,}+';
		$run   = $d . '{8,}+';
		$text  = (string) preg_replace_callback(
			'/(?<keep>' . $keep . ')|(?<!' . $d . ')(?<num>(?>' . $phone . '|' . $run . '))(?!' . $d . ')/u',
			function ( $m ) {
				if ( isset( $m['keep'] ) && '' !== $m['keep'] ) {
					return $m['keep'];
				}
				return '***' . self::tail( (string) preg_replace( '/[^' . substr( self::DIGIT, 1, -1 ) . ']/u', '', $m['num'] ) );
			},
			$text
		);
		return $text;
	}

	/**
	 * Redacts a context array. Pure.
	 *
	 * @param array $context Context.
	 * @param int   $depth   Current depth.
	 * @return array
	 */
	public static function redact_context( array $context, int $depth = 0 ): array {
		$out = array();
		foreach ( $context as $key => $value ) {
			$name = (string) $key;
			if ( 1 === preg_match( self::SECRET_KEYS, $name ) ) {
				$out[ $key ] = '[redacted]';
			} elseif ( is_array( $value ) ) {
				$out[ $key ] = $depth >= 4 ? '[array]' : self::redact_context( $value, $depth + 1 );
			} elseif ( is_object( $value ) ) {
				$out[ $key ] = '[object]';
			} elseif ( is_string( $value ) ) {
				$tail        = 1 === preg_match( self::TAIL_KEYS, $name ) && false === stripos( $value, '@instapay' );
				$out[ $key ] = $tail ? '***' . self::tail( $value ) : self::redact( $value );
			} elseif ( ( is_int( $value ) || is_float( $value ) ) && 1 === preg_match( self::TAIL_KEYS, $name ) ) {
				$out[ $key ] = '***' . self::tail( (string) $value );
			} else {
				$out[ $key ] = $value;
			}
		}
		return $out;
	}

	/**
	 * The last three characters of a value, for display as `***123` (also used by others for phone numbers and
	 * transaction ids). Pure.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	public static function tail( string $value ): string {
		if ( function_exists( 'mb_substr' ) ) {
			return (string) mb_substr( $value, -3, null, 'UTF-8' );
		}
		return substr( $value, -3 );
	}

	/**
	 * Writes one redacted line. Does nothing without WooCommerce.
	 *
	 * @param string $level   WooCommerce level.
	 * @param string $message Message.
	 * @param array  $context Context.
	 */
	private function write( string $level, string $message, array $context ) {
		try {
			if ( ! function_exists( 'wc_get_logger' ) ) {
				return;
			}
			$logger = wc_get_logger();
			if ( ! is_object( $logger ) || ! method_exists( $logger, 'log' ) ) {
				return;
			}
			$context           = self::redact_context( $context );
			$context['source'] = self::SOURCE;
			$logger->log( $level, self::redact( $message ), $context );
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}
}
