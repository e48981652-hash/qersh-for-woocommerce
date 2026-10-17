<?php
/**
 * Parity runner: the PHP side of tests/parity/run.mjs.
 *
 * Reads one JSON request on stdin, `{ "cases": [ { "op": "<name>", "args": [ ... ] }, ... ] }`, runs every case
 * against the PHP port in src/Core, and writes `{ "php": {...}, "results": [ { "v": <value> } | { "e": "<error>" } ] }`
 * on stdout, one result per case and in the same order. Usage: php tests/parity/php-runner.php < request.json
 *
 * Transport markers, so JSON can carry what PHP needs:
 * - `{ "$b": "<base64>" }` and `{ "$s": "<base64>" }` arrive as binary strings (bodies, invalid UTF-8);
 * - `{ "$f": <number> }` arrives as a float (JSON cannot say 15000.0), `{ "$f": "Infinity" }` as INF.
 * Results go back with `{ "$bytes": "<hex>" }` for a string that is not valid UTF-8 and `{ "$nonfinite": "..." }`
 * for INF and NAN, so the comparison sees exactly what PHP produced.
 *
 * Every warning, notice or deprecation raised while a case runs is turned into an error for that case, so a port
 * that only works with a warning is reported, not hidden.
 *
 * @package QershPay\WooCommerce\Tests
 */

declare( strict_types=1 );

use QershPay\WooCommerce\Core\Config;
use QershPay\WooCommerce\Core\Envelope;
use QershPay\WooCommerce\Core\Matcher;
use QershPay\WooCommerce\Core\Payer;
use QershPay\WooCommerce\Core\SetupLink;
use QershPay\WooCommerce\Core\Time;
use QershPay\WooCommerce\Core\Verifier;

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );

require_once dirname( __DIR__, 2 ) . '/src/Autoloader.php';
\QershPay\WooCommerce\Autoloader::register();

/**
 * The operations run.mjs may ask for, by name. Each takes the case's arguments in order.
 *
 * @return array<string, callable>
 */
function qersh_wc_parity_ops() {
	return array(
		'info'                     => static function () {
			return array(
				'php'      => PHP_VERSION,
				'int_size' => PHP_INT_SIZE,
				'mbstring' => function_exists( 'mb_strtolower' ),
				'intl'     => function_exists( 'idn_to_ascii' ),
				'icu'      => defined( 'INTL_ICU_VERSION' ) ? INTL_ICU_VERSION : null,
			);
		},
		'payer.normalize'          => static function ( $raw ) {
			return Payer::normalize( $raw );
		},
		'payer.key_of'             => static function ( $counterparty ) {
			return Payer::key_of( $counterparty );
		},
		'time.iso_to_sec'          => static function ( $iso ) {
			return Time::iso_to_sec( $iso );
		},
		'matcher.match'            => static function ( $transfer, array $candidates, int $now, array $options ) {
			return Matcher::match( $transfer, $candidates, $now, $options );
		},
		'matcher.judge_update'     => static function ( $data ) {
			return Matcher::judge_update( $data );
		},
		'verifier.parse_header'    => static function ( $header ) {
			return Verifier::parse_header( $header );
		},
		'verifier.verify'          => static function ( $raw_body, $header, array $keys, int $now, $tolerance = null ) {
			if ( null === $tolerance ) {
				return Verifier::verify( $raw_body, $header, $keys, $now );
			}
			return Verifier::verify( $raw_body, $header, $keys, $now, $tolerance );
		},
		'verifier.sign_header'     => static function ( int $t, string $raw_body, array $keys ) {
			return Verifier::sign_header( $t, $raw_body, $keys );
		},
		'setup.build'              => static function ( array $params ) {
			return SetupLink::build( $params );
		},
		'setup.validate'           => static function ( array $params ) {
			return SetupLink::validate( $params );
		},
		'setup.parse'              => static function ( $text ) {
			return SetupLink::parse( $text );
		},
		'setup.extract'            => static function ( $text ) {
			return SetupLink::extract( $text );
		},
		'setup.is_local_http_host' => static function ( string $host ) {
			return SetupLink::is_local_http_host( $host );
		},
		'setup.validate_url'       => static function ( $url ) {
			return SetupLink::validate_receiver_url( $url );
		},
		'setup.is_usable_secret'   => static function ( $secret ) {
			return SetupLink::is_usable_secret( $secret );
		},
		'config.validate_receiver' => static function ( $receiver ) {
			// A complete plugin configuration whose receiver part is the generated one, so only receiver issues differ.
			$config             = Config::with_defaults( array() );
			$config['receiver'] = $receiver;
			return Config::validate( $config );
		},
		'config.receiver_defaults' => static function ( $receiver ) {
			$config = Config::with_defaults( array( 'receiver' => $receiver ) );
			return $config['receiver'];
		},
		'config.is_acceptable_key' => static function ( $key ) {
			return Config::is_acceptable_key( $key );
		},
		'envelope.parse'           => static function ( $raw ) {
			return Envelope::parse( $raw );
		},
		'envelope.headers'         => static function ( array $headers ) {
			return Envelope::delivery_headers( $headers );
		},
		'envelope.summarize'       => static function ( $raw, $headers, $webhook_url ) {
			$parsed = Envelope::parse( $raw );
			if ( ! $parsed['ok'] ) {
				return $parsed;
			}
			return Envelope::summarize( $parsed['envelope'], is_array( $headers ) ? Envelope::delivery_headers( $headers ) : null, $webhook_url );
		},
	);
}

/**
 * Turns the transport markers of a request into PHP values.
 *
 * @param mixed $value Decoded JSON.
 * @return mixed
 */
function qersh_wc_parity_revive( $value ) {
	if ( ! is_array( $value ) ) {
		return $value;
	}
	if ( 1 === count( $value ) ) {
		foreach ( array( '$b', '$s' ) as $marker ) {
			if ( array_key_exists( $marker, $value ) && is_string( $value[ $marker ] ) ) {
				return (string) base64_decode( $value[ $marker ], true );
			}
		}
		if ( array_key_exists( '$f', $value ) ) {
			$number = $value['$f'];
			if ( 'Infinity' === $number ) {
				return INF;
			}
			if ( '-Infinity' === $number ) {
				return -INF;
			}
			if ( 'NaN' === $number ) {
				return NAN;
			}
			return (float) $number;
		}
	}
	foreach ( $value as $key => $item ) {
		$value[ $key ] = qersh_wc_parity_revive( $item );
	}
	return $value;
}

/**
 * Makes a result encodable as JSON without changing what it says.
 *
 * @param mixed $value Result.
 * @return mixed
 */
function qersh_wc_parity_export( $value ) {
	if ( is_string( $value ) ) {
		return 1 === preg_match( '//u', $value ) ? $value : array( '$bytes' => bin2hex( $value ) );
	}
	if ( is_float( $value ) ) {
		if ( is_nan( $value ) ) {
			return array( '$nonfinite' => 'NaN' );
		}
		if ( is_infinite( $value ) ) {
			return array( '$nonfinite' => $value > 0 ? 'Infinity' : '-Infinity' );
		}
		return $value;
	}
	if ( is_array( $value ) ) {
		$out = array();
		foreach ( $value as $key => $item ) {
			$out[ $key ] = qersh_wc_parity_export( $item );
		}
		return $out;
	}
	if ( is_object( $value ) ) {
		return array( '$object' => get_class( $value ) );
	}
	return $value;
}

/**
 * Runs one case.
 *
 * @param array $ops  The operations.
 * @param mixed $item One request case.
 * @return array `[ 'v' => value ]` or `[ 'e' => message ]`.
 */
function qersh_wc_parity_run_case( array $ops, $item ) {
	if ( ! is_array( $item ) || ! isset( $item['op'] ) || ! is_string( $item['op'] ) || ! isset( $ops[ $item['op'] ] ) ) {
		return array( 'e' => 'runner: unknown op' );
	}
	$args = isset( $item['args'] ) && is_array( $item['args'] ) ? array_values( qersh_wc_parity_revive( $item['args'] ) ) : array();
	ob_start();
	try {
		$value = call_user_func_array( $ops[ $item['op'] ], $args );
		$noise = (string) ob_get_clean();
		if ( '' !== $noise ) {
			return array( 'e' => 'output while running: ' . substr( $noise, 0, 300 ) );
		}
		return array( 'v' => qersh_wc_parity_export( $value ) );
	} catch ( \Throwable $e ) {
		ob_end_clean();
		$where = basename( $e->getFile() ) . ':' . $e->getLine();
		return array( 'e' => get_class( $e ) . ': ' . $e->getMessage() . ' (' . $where . ')' );
	}
}

error_reporting( E_ALL );
set_error_handler(
	static function ( $errno, $errstr, $errfile, $errline ) {
		if ( ! ( error_reporting() & $errno ) ) {
			return false; // silenced with @ on purpose
		}
		throw new \ErrorException( $errstr, 0, $errno, $errfile, $errline );
	}
);

$qersh_wc_parity_request = json_decode( (string) stream_get_contents( STDIN ), true, 4096 );
if ( ! is_array( $qersh_wc_parity_request ) || ! isset( $qersh_wc_parity_request['cases'] ) || ! is_array( $qersh_wc_parity_request['cases'] ) ) {
	fwrite( STDERR, 'php-runner: the request is not { "cases": [...] } (' . json_last_error_msg() . ")\n" );
	exit( 2 );
}

$qersh_wc_parity_ops     = qersh_wc_parity_ops();
$qersh_wc_parity_results = array();
foreach ( $qersh_wc_parity_request['cases'] as $qersh_wc_parity_case ) {
	$qersh_wc_parity_results[] = qersh_wc_parity_run_case( $qersh_wc_parity_ops, $qersh_wc_parity_case );
}

$qersh_wc_parity_json = json_encode(
	array(
		'php'     => array(
			'version'  => PHP_VERSION,
			'int_size' => PHP_INT_SIZE,
		),
		'results' => $qersh_wc_parity_results,
	),
	JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
	4096
);
if ( false === $qersh_wc_parity_json ) {
	fwrite( STDERR, 'php-runner: cannot encode the results (' . json_last_error_msg() . ")\n" );
	exit( 2 );
}
fwrite( STDOUT, $qersh_wc_parity_json );
exit( 0 );
