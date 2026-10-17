<?php
/**
 * Extracts every translatable string of the plugin into languages/qersh-for-woocommerce.pot (docs/17 §7), without
 * gettext or WP-CLI.
 *
 * - PHP (qersh-for-woocommerce.php, uninstall.php, src/, templates/): the PHP tokenizer (token_get_all) finds
 *   __, _e, _x, _ex, _n, _nx, _n_noop, _nx_noop and the esc_html / esc_attr variants.
 * - JavaScript (assets/js/*.js, not assets/vendor): a small tokenizer that knows strings, template literals,
 *   comments and regular expressions finds the wp.i18n calls __, _x, _n and _nx.
 * - The plugin header (Plugin Name, Plugin URI, Description, Author, Author URI), as WordPress translates it on the
 *   Plugins screen.
 *
 * Each entry carries its references (file:line), its context, its plural and the translators comment that the
 * WordPress coding standards accept: the closest comment before the call, ending on the line above it or followed
 * by nothing but whitespace up to the call's line, and starting with "translators:".
 *
 * Fails (exit 1, nothing written) on a call whose text, plural or context is not one string literal, or whose text
 * domain is missing, not a literal or not qersh-for-woocommerce. Warns about a string with placeholders and no
 * translators comment (Plugin Check reports the same).
 *
 * Usage, from the repository root:
 *   php scripts/make-pot.php              writes the POT (the creation date is kept when nothing else changed)
 *   php scripts/make-pot.php --update-po  also merges it into every languages/*.po (msgmerge: translations kept,
 *                                         references and comments refreshed, new strings added empty, removed
 *                                         strings kept as obsolete "#~" entries)
 *   php scripts/make-pot.php --stdout     prints the POT and writes nothing: the dry run, which
 *                                         scripts/check-translations.mjs compares with the file
 *   php scripts/make-pot.php --strict     also fails on the warnings
 *   php scripts/make-pot.php --help       prints the usage
 * Any other argument (a mistyped flag such as --check) prints the usage and exits 2 before anything is read or
 * written, so a typo never rewrites the POT.
 *
 * @package QershPay\WooCommerce\Scripts
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

require __DIR__ . '/lib/i18n.php';

$root  = dirname( __DIR__ );
$args  = array_slice( $argv, 1 );
$usage = implode(
	"\n",
	array(
		'Usage: php scripts/make-pot.php [--update-po] [--stdout] [--strict]',
		'  (no flag)    write languages/' . QERSH_I18N_DOMAIN . '.pot',
		'  --update-po  also merge it into every languages/*.po',
		'  --stdout     print the POT and write nothing (dry run)',
		'  --strict     also fail on the warnings',
		'',
	)
);
if ( in_array( '--help', $args, true ) || in_array( '-h', $args, true ) ) {
	fwrite( STDOUT, $usage );
	exit( 0 );
}
$unknown = array_values( array_diff( $args, array( '--update-po', '--stdout', '--strict' ) ) );
if ( $unknown ) {
	fwrite( STDERR, 'make-pot: unknown argument(s): ' . implode( ' ', $unknown ) . "; nothing written\n" . $usage );
	exit( 2 );
}
$to_stdout = in_array( '--stdout', $args, true );
$update_po = in_array( '--update-po', $args, true );
$strict    = in_array( '--strict', $args, true );
$pot_path  = $root . '/languages/' . QERSH_I18N_DOMAIN . '.pot';

/**
 * Argument layout of each gettext function. "number" is the count of _n and is not read.
 */
const QERSH_POT_PHP_FUNCTIONS = array(
	'__'           => array( 'text', 'domain' ),
	'_e'           => array( 'text', 'domain' ),
	'esc_html__'   => array( 'text', 'domain' ),
	'esc_html_e'   => array( 'text', 'domain' ),
	'esc_attr__'   => array( 'text', 'domain' ),
	'esc_attr_e'   => array( 'text', 'domain' ),
	'_x'           => array( 'text', 'context', 'domain' ),
	'_ex'          => array( 'text', 'context', 'domain' ),
	'esc_html_x'   => array( 'text', 'context', 'domain' ),
	'esc_attr_x'   => array( 'text', 'context', 'domain' ),
	'_n'           => array( 'text', 'plural', 'number', 'domain' ),
	'_nx'          => array( 'text', 'plural', 'number', 'context', 'domain' ),
	'_n_noop'      => array( 'text', 'plural', 'domain' ),
	'_nx_noop'     => array( 'text', 'plural', 'context', 'domain' ),
	'translate'    => null, // Never with a literal in a plugin: reported.
	'_c'           => null,
);

const QERSH_POT_JS_FUNCTIONS = array(
	'__'  => array( 'text', 'domain' ),
	'_x'  => array( 'text', 'context', 'domain' ),
	'_n'  => array( 'text', 'plural', 'number', 'domain' ),
	'_nx' => array( 'text', 'plural', 'number', 'context', 'domain' ),
);

/**
 * Collected entries (key => entry) in order of first appearance, errors and warnings.
 */
$GLOBALS['qersh_pot'] = array(
	'entries'  => array(),
	'errors'   => array(),
	'warnings' => array(),
);

/**
 * Adds one occurrence.
 *
 * @param string      $ref       file:line, or '' for the plugin header.
 * @param string      $text      msgid.
 * @param string|null $plural    msgid_plural.
 * @param string|null $context   msgctxt.
 * @param string|null $comment   Extracted comment.
 * @param bool        $is_php    Whether the call is in PHP (php-format flag).
 */
function qersh_pot_add( $ref, $text, $plural, $context, $comment, $is_php ) {
	$entries = &$GLOBALS['qersh_pot']['entries'];
	$key     = null === $context ? $text : $context . "\x04" . $text;
	if ( ! isset( $entries[ $key ] ) ) {
		$e            = qersh_i18n_entry();
		$e['msgid']   = $text;
		$e['msgctxt'] = $context;
		$entries[ $key ] = $e;
	}
	$e = &$entries[ $key ];
	if ( null !== $plural ) {
		if ( null !== $e['msgid_plural'] && $e['msgid_plural'] !== $plural ) {
			$GLOBALS['qersh_pot']['errors'][] = sprintf( '%s: "%s" has two different plurals ("%s" and "%s")', $ref, $text, $e['msgid_plural'], $plural );
		}
		$e['msgid_plural'] = $plural;
		$e['msgstr']       = array( '', '' );
	}
	if ( '' !== $ref && ! in_array( $ref, $e['references'], true ) ) {
		$e['references'][] = $ref;
	}
	if ( null !== $comment && '' !== $comment ) {
		foreach ( explode( "\n", $comment ) as $line ) {
			if ( ! in_array( $line, $e['extracted'], true ) ) {
				$e['extracted'][] = $line;
			}
		}
	}
	$has_placeholder = qersh_i18n_placeholders( $text ) || ( null !== $plural && qersh_i18n_placeholders( $plural ) );
	if ( $is_php && $has_placeholder && ! in_array( 'php-format', $e['flags'], true ) ) {
		$e['flags'][] = 'php-format';
	}
	if ( $has_placeholder && ( null === $comment || '' === $comment ) && '' !== $ref ) {
		$GLOBALS['qersh_pot']['warnings'][] = sprintf( '%s: "%s" has placeholders but no translators comment directly above the call', $ref, qersh_pot_short( $text ) );
	}
}

/**
 * Shortens a text for a message.
 *
 * @param string $s Text.
 * @return string
 */
function qersh_pot_short( $s ) {
	$s = str_replace( "\n", '\n', $s );
	return strlen( $s ) > 70 ? substr( $s, 0, 67 ) . '…' : $s;
}

/**
 * The text of a comment without its markers, one line per line, or null when it is no translators comment.
 *
 * @param string $raw Comment as in the source.
 * @return string|null
 */
function qersh_pot_translators_comment( $raw ) {
	$raw = trim( $raw );
	if ( ! preg_match( '`^(?:(?://|/\*{1,2}|#) ?)?\s*translators:`i', $raw ) ) {
		return null;
	}
	if ( 0 === strncmp( $raw, '/*', 2 ) ) {
		$raw = preg_replace( '`^/\*+`', '', $raw );
		$raw = preg_replace( '`\*+/$`', '', $raw );
	} elseif ( 0 === strncmp( $raw, '//', 2 ) ) {
		$raw = substr( $raw, 2 );
	} elseif ( '#' === $raw[0] ) {
		$raw = substr( $raw, 1 );
	}
	$lines = array();
	foreach ( explode( "\n", str_replace( "\r", '', $raw ) ) as $line ) {
		$line = trim( preg_replace( '/^\s*\*(?!\/)/', '', $line ) );
		if ( '' !== $line ) {
			$lines[] = $line;
		}
	}
	return implode( ' ', $lines );
}

/* ---------------------------------------------------------------------------------------------------------------
 * PHP.
 * ------------------------------------------------------------------------------------------------------------- */

/**
 * The value of a single-quoted or double-quoted PHP literal (T_CONSTANT_ENCAPSED_STRING, so without variables).
 *
 * @param string $lit Literal with its quotes (and maybe a b prefix).
 * @return string
 */
function qersh_pot_php_string( $lit ) {
	if ( 'b' === strtolower( $lit[0] ) ) {
		$lit = substr( $lit, 1 );
	}
	$quote = $lit[0];
	$body  = substr( $lit, 1, -1 );
	if ( "'" === $quote ) {
		return preg_replace( '/\\\\([\\\\\'])/', '$1', $body );
	}
	return preg_replace_callback(
		'/\\\\(?:([nrtvef\\\\$"])|([0-7]{1,3})|x([0-9A-Fa-f]{1,2})|u\{([0-9A-Fa-f]+)\})/',
		static function ( $m ) {
			if ( isset( $m[1] ) && '' !== $m[1] ) {
				$map = array(
					'n'  => "\n",
					'r'  => "\r",
					't'  => "\t",
					'v'  => "\x0b",
					'e'  => "\x1b",
					'f'  => "\x0c",
					'\\' => '\\',
					'$'  => '$',
					'"'  => '"',
				);
				return $map[ $m[1] ];
			}
			if ( isset( $m[2] ) && '' !== $m[2] ) {
				return chr( octdec( $m[2] ) & 255 );
			}
			if ( isset( $m[3] ) && '' !== $m[3] ) {
				return chr( hexdec( $m[3] ) );
			}
			return mb_convert_encoding( pack( 'N', hexdec( $m[4] ) ), 'UTF-8', 'UTF-32BE' );
		},
		$body
	);
}

/**
 * Scans one PHP file.
 *
 * @param string $rel  Path relative to the repository root.
 * @param string $code File content.
 */
function qersh_pot_scan_php( $rel, $code ) {
	$raw    = token_get_all( $code );
	$tokens = array();
	$line   = 1;
	foreach ( $raw as $t ) {
		if ( is_array( $t ) ) {
			$tokens[] = array( $t[0], $t[1], $t[2] );
			$line     = $t[2] + substr_count( $t[1], "\n" );
		} else {
			$tokens[] = array( $t, $t, $line );
		}
	}
	$fq      = defined( 'T_NAME_FULLY_QUALIFIED' ) ? constant( 'T_NAME_FULLY_QUALIFIED' ) : -1;
	$nullsafe = defined( 'T_NULLSAFE_OBJECT_OPERATOR' ) ? constant( 'T_NULLSAFE_OBJECT_OPERATOR' ) : -1;
	$skip    = array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT );
	$count   = count( $tokens );

	$prev_sig = static function ( $i ) use ( $tokens, $skip ) {
		for ( $j = $i - 1; $j >= 0; $j-- ) {
			if ( ! in_array( $tokens[ $j ][0], $skip, true ) ) {
				return $j;
			}
		}
		return -1;
	};
	$next_sig = static function ( $i ) use ( $tokens, $skip, $count ) {
		for ( $j = $i + 1; $j < $count; $j++ ) {
			if ( ! in_array( $tokens[ $j ][0], $skip, true ) ) {
				return $j;
			}
		}
		return -1;
	};

	for ( $i = 0; $i < $count; $i++ ) {
		$tok = $tokens[ $i ];
		if ( T_STRING === $tok[0] ) {
			$name = $tok[1];
			// PHP 7.4 tokenizes \__ as T_NS_SEPARATOR + T_STRING.
			$p = $prev_sig( $i );
			if ( $p >= 0 && T_NS_SEPARATOR === $tokens[ $p ][0] ) {
				$pp = $prev_sig( $p );
				if ( $pp >= 0 && T_STRING === $tokens[ $pp ][0] ) {
					continue; // Foo\__: some other namespace's function.
				}
				$p = $pp;
			}
		} elseif ( $fq === $tok[0] ) {
			$name = ltrim( $tok[1], '\\' );
			$p    = $prev_sig( $i );
		} else {
			continue;
		}
		if ( ! array_key_exists( $name, QERSH_POT_PHP_FUNCTIONS ) ) {
			continue;
		}
		if ( $p >= 0 ) {
			$before = $tokens[ $p ][0];
			if ( in_array( $before, array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, $nullsafe, T_FUNCTION, T_NEW, T_CONST ), true ) ) {
				continue;
			}
		}
		$open = $next_sig( $i );
		if ( $open < 0 || '(' !== $tokens[ $open ][0] ) {
			continue;
		}
		$ref = $rel . ':' . $tok[2];
		if ( null === QERSH_POT_PHP_FUNCTIONS[ $name ] ) {
			$GLOBALS['qersh_pot']['errors'][] = sprintf( '%s: %s() is not extracted; use __() and its variants', $ref, $name );
			continue;
		}

		// The arguments: token lists split at the commas of depth 1.
		$args  = array( array() );
		$depth = 0;
		for ( $j = $open; $j < $count; $j++ ) {
			$t = $tokens[ $j ];
			if ( in_array( $t[0], $skip, true ) ) {
				continue;
			}
			if ( in_array( $t[0], array( '(', '[', '{', T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) {
				$depth++;
				if ( 1 === $depth ) {
					continue;
				}
			} elseif ( in_array( $t[0], array( ')', ']', '}' ), true ) ) {
				$depth--;
				if ( 0 === $depth ) {
					break;
				}
			} elseif ( ',' === $t[0] && 1 === $depth ) {
				$args[] = array();
				continue;
			}
			$args[ count( $args ) - 1 ][] = $t;
		}
		if ( array() === end( $args ) ) {
			array_pop( $args ); // Trailing comma, or no argument.
		}

		$values = array();
		foreach ( QERSH_POT_PHP_FUNCTIONS[ $name ] as $k => $role ) {
			if ( 'number' === $role ) {
				continue;
			}
			if ( ! isset( $args[ $k ] ) ) {
				$values[ $role ] = false;
				continue;
			}
			$arg = $args[ $k ];
			if ( 1 === count( $arg ) && T_CONSTANT_ENCAPSED_STRING === $arg[0][0] ) {
				$values[ $role ] = qersh_pot_php_string( $arg[0][1] );
			} else {
				$values[ $role ] = null;
			}
		}
		$bad = false;
		foreach ( array( 'text', 'plural', 'context' ) as $role ) {
			if ( array_key_exists( $role, $values ) && ! is_string( $values[ $role ] ) ) {
				$GLOBALS['qersh_pot']['errors'][] = sprintf( '%s: %s() needs a single string literal as its %s', $ref, $name, $role );
				$bad = true;
			}
		}
		if ( ! is_string( $values['domain'] ) ) {
			$GLOBALS['qersh_pot']['errors'][] = sprintf( '%s: %s() needs the text domain \'%s\' as a string literal', $ref, $name, QERSH_I18N_DOMAIN );
			$bad = true;
		} elseif ( QERSH_I18N_DOMAIN !== $values['domain'] ) {
			$GLOBALS['qersh_pot']['errors'][] = sprintf( '%s: %s() uses the text domain \'%s\', not \'%s\'', $ref, $name, $values['domain'], QERSH_I18N_DOMAIN );
			$bad = true;
		}
		if ( $bad ) {
			continue;
		}
		if ( '' === $values['text'] ) {
			$GLOBALS['qersh_pot']['errors'][] = sprintf( '%s: %s() with an empty text', $ref, $name );
			continue;
		}

		// The translators comment, by the rule of the WordPress coding standards (WordPress.WP.I18n).
		$comment = null;
		for ( $c = $i - 1; $c >= 0; $c-- ) {
			if ( T_COMMENT === $tokens[ $c ][0] || T_DOC_COMMENT === $tokens[ $c ][0] ) {
				break;
			}
		}
		if ( $c >= 0 ) {
			$text     = $tokens[ $c ][1];
			$end_line = $tokens[ $c ][2] + substr_count( rtrim( $text ), "\n" );
			$placed   = ( $end_line + 1 === $tok[2] ) || ( $end_line === $tok[2] );
			if ( ! $placed ) {
				$n = $c + 1;
				while ( $n < $i && T_WHITESPACE === $tokens[ $n ][0] ) {
					$n++;
				}
				$placed = ( $n >= $i ) || ( $tokens[ $n ][2] === $tok[2] );
			}
			if ( $placed ) {
				$comment = qersh_pot_translators_comment( $text );
			}
		}
		qersh_pot_add( $ref, $values['text'], isset( $values['plural'] ) ? $values['plural'] : null, isset( $values['context'] ) ? $values['context'] : null, $comment, true );
	}
}

/* ---------------------------------------------------------------------------------------------------------------
 * JavaScript.
 * ------------------------------------------------------------------------------------------------------------- */

/**
 * Splits JavaScript into tokens: [ type, value, line, end_line ] with type id, str, tpl, num, re, punct or comment.
 * For str the value is the decoded string.
 *
 * @param string $src  Source.
 * @param string $rel  Path for error messages.
 * @return array
 * @throws RuntimeException On an unterminated string, comment or regular expression.
 */
function qersh_pot_js_tokens( $src, $rel ) {
	$tokens = array();
	$len    = strlen( $src );
	$i      = 0;
	$line   = 1;
	$prev   = null; // Last significant token.
	$re_kw  = array( 'return', 'typeof', 'instanceof', 'in', 'of', 'new', 'delete', 'void', 'throw', 'case', 'do', 'else', 'yield', 'await' );
	while ( $i < $len ) {
		$ch = $src[ $i ];
		if ( "\n" === $ch ) {
			$line++;
			$i++;
			continue;
		}
		if ( ' ' === $ch || "\t" === $ch || "\r" === $ch || "\f" === $ch || "\v" === $ch ) {
			$i++;
			continue;
		}
		$start_line = $line;
		$two        = substr( $src, $i, 2 );
		if ( '//' === $two ) {
			$end      = strpos( $src, "\n", $i );
			$end      = false === $end ? $len : $end;
			$tokens[] = array( 'comment', substr( $src, $i, $end - $i ), $start_line, $start_line );
			$i        = $end;
			continue;
		}
		if ( '/*' === $two ) {
			$end = strpos( $src, '*/', $i + 2 );
			if ( false === $end ) {
				throw new RuntimeException( $rel . ':' . $line . ': unterminated comment' );
			}
			$text     = substr( $src, $i, $end + 2 - $i );
			$line    += substr_count( $text, "\n" );
			$tokens[] = array( 'comment', $text, $start_line, $line );
			$i        = $end + 2;
			continue;
		}
		if ( "'" === $ch || '"' === $ch ) {
			$value = '';
			$j     = $i + 1;
			while ( true ) {
				if ( $j >= $len || "\n" === $src[ $j ] ) {
					throw new RuntimeException( $rel . ':' . $line . ': unterminated string' );
				}
				$c = $src[ $j ];
				if ( $c === $ch ) {
					break;
				}
				if ( '\\' === $c ) {
					$n = $j + 1 < $len ? $src[ $j + 1 ] : '';
					if ( "\n" === $n ) {
						$line++;
						$j += 2;
						continue;
					}
					if ( "\r" === $n ) {
						$j += ( "\n" === substr( $src, $j + 2, 1 ) ) ? 3 : 2;
						$line++;
						continue;
					}
					$map = array(
						'n' => "\n",
						't' => "\t",
						'r' => "\r",
						'b' => "\x08",
						'f' => "\x0c",
						'v' => "\x0b",
					);
					if ( isset( $map[ $n ] ) ) {
						$value .= $map[ $n ];
						$j     += 2;
						continue;
					}
					if ( '0' === $n && ! ctype_digit( substr( $src, $j + 2, 1 ) ) ) {
						$value .= "\0";
						$j     += 2;
						continue;
					}
					if ( 'x' === $n && preg_match( '/^[0-9A-Fa-f]{2}/', substr( $src, $j + 2, 2 ) ) ) {
						$value .= mb_convert_encoding( pack( 'N', hexdec( substr( $src, $j + 2, 2 ) ) ), 'UTF-8', 'UTF-32BE' );
						$j     += 4;
						continue;
					}
					if ( 'u' === $n ) {
						if ( preg_match( '/^\{([0-9A-Fa-f]+)\}/', substr( $src, $j + 2, 10 ), $m ) ) {
							$value .= mb_convert_encoding( pack( 'N', hexdec( $m[1] ) ), 'UTF-8', 'UTF-32BE' );
							$j     += 2 + strlen( $m[0] );
							continue;
						}
						if ( preg_match( '/^[0-9A-Fa-f]{4}/', substr( $src, $j + 2, 4 ) ) ) {
							$cp = hexdec( substr( $src, $j + 2, 4 ) );
							$j += 6;
							// A surrogate pair.
							if ( $cp >= 0xD800 && $cp <= 0xDBFF && preg_match( '/^\\\\u([0-9A-Fa-f]{4})/', substr( $src, $j, 6 ), $m ) ) {
								$lo = hexdec( $m[1] );
								if ( $lo >= 0xDC00 && $lo <= 0xDFFF ) {
									$cp = 0x10000 + ( ( $cp - 0xD800 ) << 10 ) + ( $lo - 0xDC00 );
									$j += 6;
								}
							}
							$value .= mb_convert_encoding( pack( 'N', $cp ), 'UTF-8', 'UTF-32BE' );
							continue;
						}
					}
					$value .= $n;
					$j     += 2;
					continue;
				}
				$value .= $c;
				$j++;
			}
			$tokens[] = $prev = array( 'str', $value, $start_line, $line );
			$i        = $j + 1;
			continue;
		}
		if ( '`' === $ch ) {
			// Template literal: kept whole; ${…} may nest braces and strings.
			$j     = $i + 1;
			$depth = 0;
			while ( true ) {
				if ( $j >= $len ) {
					throw new RuntimeException( $rel . ':' . $start_line . ': unterminated template literal' );
				}
				$c = $src[ $j ];
				if ( "\n" === $c ) {
					$line++;
				}
				if ( '\\' === $c ) {
					$j += 2;
					continue;
				}
				if ( 0 === $depth && '`' === $c ) {
					break;
				}
				if ( '$' === $c && '{' === substr( $src, $j + 1, 1 ) ) {
					$depth++;
					$j += 2;
					continue;
				}
				if ( $depth > 0 && '{' === $c ) {
					$depth++;
				} elseif ( $depth > 0 && '}' === $c ) {
					$depth--;
				}
				$j++;
			}
			$tokens[] = $prev = array( 'tpl', substr( $src, $i, $j + 1 - $i ), $start_line, $line );
			$i        = $j + 1;
			continue;
		}
		if ( preg_match( '/\G[A-Za-z_$][A-Za-z0-9_$]*/', $src, $m, 0, $i ) ) {
			$tokens[] = $prev = array( 'id', $m[0], $start_line, $start_line );
			$i       += strlen( $m[0] );
			continue;
		}
		if ( preg_match( '/\G(?:0[xX][0-9A-Fa-f_]+|0[oO][0-7_]+|0[bB][01_]+|(?:[0-9][0-9_]*(?:\.[0-9_]*)?|\.[0-9][0-9_]*)(?:[eE][+-]?[0-9]+)?n?)/', $src, $m, 0, $i ) ) {
			$tokens[] = $prev = array( 'num', $m[0], $start_line, $start_line );
			$i       += strlen( $m[0] );
			continue;
		}
		if ( '/' === $ch ) {
			$is_re = null === $prev
				|| ( 'punct' === $prev[0] && ! in_array( $prev[1], array( ')', ']', '}' ), true ) )
				|| ( 'id' === $prev[0] && in_array( $prev[1], $re_kw, true ) );
			if ( $is_re ) {
				$j     = $i + 1;
				$class = false;
				while ( true ) {
					if ( $j >= $len || "\n" === $src[ $j ] ) {
						throw new RuntimeException( $rel . ':' . $line . ': unterminated regular expression' );
					}
					$c = $src[ $j ];
					if ( '\\' === $c ) {
						$j += 2;
						continue;
					}
					if ( '[' === $c ) {
						$class = true;
					} elseif ( ']' === $c ) {
						$class = false;
					} elseif ( '/' === $c && ! $class ) {
						break;
					}
					$j++;
				}
				$j++;
				while ( $j < $len && ctype_alpha( $src[ $j ] ) ) {
					$j++;
				}
				$tokens[] = $prev = array( 're', substr( $src, $i, $j - $i ), $start_line, $start_line );
				$i        = $j;
				continue;
			}
		}
		// Punctuators: only the brackets, the comma, the dot and ";" matter; the rest is one char at a time.
		if ( '?.' === $two && ! ctype_digit( substr( $src, $i + 2, 1 ) ) ) {
			$tokens[] = $prev = array( 'punct', '.', $start_line, $start_line );
			$i       += 2;
			continue;
		}
		if ( preg_match( '/\G(?:\+\+|--)/', $src, $m, 0, $i ) ) {
			// Postfix ++ / -- ends an expression; prefix starts one. Treat it as an operand end, like ")".
			$tokens[] = $prev = array( 'punct', ')', $start_line, $start_line );
			$i       += 2;
			continue;
		}
		$tokens[] = $prev = array( 'punct', $ch, $start_line, $start_line );
		$i++;
	}
	return $tokens;
}

/**
 * Scans one JavaScript file.
 *
 * @param string $rel  Path relative to the repository root.
 * @param string $code File content.
 */
function qersh_pot_scan_js( $rel, $code ) {
	try {
		$tokens = qersh_pot_js_tokens( $code, $rel );
	} catch ( RuntimeException $e ) {
		$GLOBALS['qersh_pot']['errors'][] = $e->getMessage();
		return;
	}
	$sig = array();
	foreach ( $tokens as $k => $t ) {
		if ( 'comment' !== $t[0] ) {
			$sig[] = $k;
		}
	}
	$nsig = count( $sig );
	for ( $s = 0; $s < $nsig; $s++ ) {
		$i   = $sig[ $s ];
		$tok = $tokens[ $i ];
		if ( 'id' !== $tok[0] || ! array_key_exists( $tok[1], QERSH_POT_JS_FUNCTIONS ) ) {
			continue;
		}
		if ( $s + 1 >= $nsig || '(' !== $tokens[ $sig[ $s + 1 ] ][1] || 'punct' !== $tokens[ $sig[ $s + 1 ] ][0] ) {
			continue;
		}
		if ( $s > 0 && 'id' === $tokens[ $sig[ $s - 1 ] ][0] && 'function' === $tokens[ $sig[ $s - 1 ] ][1] ) {
			continue;
		}
		$name = $tok[1];
		$ref  = $rel . ':' . $tok[2];
		$args = array( array() );
		$depth = 0;
		for ( $k = $s + 1; $k < $nsig; $k++ ) {
			$t = $tokens[ $sig[ $k ] ];
			if ( 'punct' === $t[0] && in_array( $t[1], array( '(', '[', '{' ), true ) ) {
				$depth++;
				if ( 1 === $depth ) {
					continue;
				}
			} elseif ( 'punct' === $t[0] && in_array( $t[1], array( ')', ']', '}' ), true ) ) {
				$depth--;
				if ( 0 === $depth ) {
					break;
				}
			} elseif ( 'punct' === $t[0] && ',' === $t[1] && 1 === $depth ) {
				$args[] = array();
				continue;
			}
			$args[ count( $args ) - 1 ][] = $t;
		}
		if ( array() === end( $args ) ) {
			array_pop( $args );
		}
		$values = array();
		foreach ( QERSH_POT_JS_FUNCTIONS[ $name ] as $a => $role ) {
			if ( 'number' === $role ) {
				continue;
			}
			if ( ! isset( $args[ $a ] ) ) {
				$values[ $role ] = false;
				continue;
			}
			$arg             = $args[ $a ];
			$values[ $role ] = ( 1 === count( $arg ) && 'str' === $arg[0][0] ) ? $arg[0][1] : null;
		}
		$bad = false;
		foreach ( array( 'text', 'plural', 'context' ) as $role ) {
			if ( array_key_exists( $role, $values ) && ! is_string( $values[ $role ] ) ) {
				$GLOBALS['qersh_pot']['errors'][] = sprintf( '%s: %s() needs a single string literal as its %s', $ref, $name, $role );
				$bad = true;
			}
		}
		if ( ! is_string( $values['domain'] ) ) {
			$GLOBALS['qersh_pot']['errors'][] = sprintf( '%s: %s() needs the text domain \'%s\' as a string literal', $ref, $name, QERSH_I18N_DOMAIN );
			$bad = true;
		} elseif ( QERSH_I18N_DOMAIN !== $values['domain'] ) {
			$GLOBALS['qersh_pot']['errors'][] = sprintf( '%s: %s() uses the text domain \'%s\', not \'%s\'', $ref, $name, $values['domain'], QERSH_I18N_DOMAIN );
			$bad = true;
		}
		if ( $bad ) {
			continue;
		}
		$comment = null;
		for ( $c = $i - 1; $c >= 0; $c-- ) {
			if ( 'comment' === $tokens[ $c ][0] ) {
				break;
			}
		}
		if ( $c >= 0 ) {
			$end_line = $tokens[ $c ][3];
			$placed   = ( $end_line + 1 === $tok[2] ) || ( $end_line === $tok[2] );
			if ( ! $placed ) {
				$placed = ( $c + 1 >= $i ) || ( $tokens[ $c + 1 ][2] === $tok[2] );
			}
			if ( $placed ) {
				$comment = qersh_pot_translators_comment( $tokens[ $c ][1] );
			}
		}
		qersh_pot_add( $ref, $values['text'], isset( $values['plural'] ) ? $values['plural'] : null, isset( $values['context'] ) ? $values['context'] : null, $comment, false );
	}
}

/* ---------------------------------------------------------------------------------------------------------------
 * Run.
 * ------------------------------------------------------------------------------------------------------------- */

/**
 * Files of a directory, sorted, with forward slashes.
 *
 * @param string $root Repository root.
 * @param string $dir  Relative directory.
 * @param string $ext  Extension.
 * @return string[]
 */
function qersh_pot_files( $root, $dir, $ext ) {
	$out = array();
	if ( ! is_dir( $root . '/' . $dir ) ) {
		return $out;
	}
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/' . $dir, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $file ) {
		if ( strtolower( $file->getExtension() ) === $ext ) {
			$out[] = $dir . '/' . str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root . '/' . $dir ) + 1 ) );
		}
	}
	sort( $out, SORT_STRING );
	return $out;
}

$main    = $root . '/' . QERSH_I18N_DOMAIN . '.php';
$headers = qersh_i18n_plugin_headers( $main, array( 'Plugin Name', 'Plugin URI', 'Description', 'Author', 'Author URI', 'Version', 'Text Domain' ) );
if ( QERSH_I18N_DOMAIN !== $headers['Text Domain'] ) {
	$GLOBALS['qersh_pot']['errors'][] = sprintf( '%s.php: the header Text Domain is "%s"', QERSH_I18N_DOMAIN, $headers['Text Domain'] );
}
foreach ( array( 'Plugin Name', 'Plugin URI', 'Description', 'Author', 'Author URI' ) as $field ) {
	if ( '' !== $headers[ $field ] ) {
		qersh_pot_add( '', $headers[ $field ], null, null, $field . ' of the plugin', false );
	}
}

$php_files = array_merge( array( QERSH_I18N_DOMAIN . '.php', 'uninstall.php' ), qersh_pot_files( $root, 'src', 'php' ), qersh_pot_files( $root, 'templates', 'php' ) );
foreach ( $php_files as $rel ) {
	if ( is_file( $root . '/' . $rel ) ) {
		qersh_pot_scan_php( $rel, (string) file_get_contents( $root . '/' . $rel ) );
	}
}
foreach ( qersh_pot_files( $root, 'assets/js', 'js' ) as $rel ) {
	qersh_pot_scan_js( $rel, (string) file_get_contents( $root . '/' . $rel ) );
}

$state = $GLOBALS['qersh_pot'];
foreach ( $state['warnings'] as $w ) {
	fwrite( STDERR, 'make-pot: warning: ' . $w . "\n" );
}
if ( $state['errors'] || ( $strict && $state['warnings'] ) ) {
	foreach ( $state['errors'] as $e ) {
		fwrite( STDERR, 'make-pot: error: ' . $e . "\n" );
	}
	fwrite( STDERR, sprintf( "make-pot: %d error(s), %d warning(s); nothing written\n", count( $state['errors'] ), count( $state['warnings'] ) ) );
	exit( 1 );
}

$entries = array_values( $state['entries'] );
$created = gmdate( 'Y-m-d\TH:i:s+00:00' );
$header  = array(
	'Project-Id-Version'        => 'Qersh for WooCommerce ' . $headers['Version'],
	'Report-Msgid-Bugs-To'      => 'https://wordpress.org/support/plugin/' . QERSH_I18N_DOMAIN,
	'Last-Translator'           => 'FULL NAME <EMAIL@ADDRESS>',
	'Language-Team'             => 'LANGUAGE <LL@li.org>',
	'MIME-Version'              => '1.0',
	'Content-Type'              => 'text/plain; charset=UTF-8',
	'Content-Transfer-Encoding' => '8bit',
	'POT-Creation-Date'         => $created,
	'PO-Revision-Date'          => 'YEAR-MO-DA HO:MI+ZONE',
	'X-Generator'               => 'scripts/make-pot.php',
	'X-Domain'                  => QERSH_I18N_DOMAIN,
);
$head    = array(
	'Copyright (C) ' . gmdate( 'Y' ) . ' Qersh',
	'This file is distributed under the GPLv2 or later.',
);

// Keep the creation date (and year) of the current file when nothing else changed, so a re-run makes no diff.
if ( is_file( $pot_path ) ) {
	$old = (string) file_get_contents( $pot_path );
	if ( preg_match( '/^"POT-Creation-Date: ([^"\\\\]+)\\\\n"$/m', $old, $m ) ) {
		$try_header                      = $header;
		$try_header['POT-Creation-Date'] = $m[1];
		$try_head                        = $head;
		if ( preg_match( '/^# Copyright \(C\) (\d{4}) Qersh$/m', $old, $y ) ) {
			$try_head[0] = 'Copyright (C) ' . $y[1] . ' Qersh';
		}
		if ( qersh_i18n_po_write( $try_head, $try_header, $entries ) === $old ) {
			$header = $try_header;
			$head   = $try_head;
		}
	}
}
$pot = qersh_i18n_po_write( $head, $header, $entries );

if ( $to_stdout ) {
	fwrite( STDOUT, $pot );
	exit( 0 );
}

if ( ! is_dir( dirname( $pot_path ) ) ) {
	mkdir( dirname( $pot_path ), 0777, true );
}
file_put_contents( $pot_path, $pot );
fwrite( STDOUT, sprintf( "make-pot: %d string(s) into languages/%s.pot, %d warning(s)\n", count( $entries ), QERSH_I18N_DOMAIN, count( $state['warnings'] ) ) );

if ( ! $update_po ) {
	exit( 0 );
}

// msgmerge: the POT decides which entries exist and their references, comments and flags; the PO keeps its
// translations, its translator comments and its fuzzy flags.
foreach ( glob( $root . '/languages/*.po' ) as $po_path ) {
	$po      = qersh_i18n_po_parse( (string) file_get_contents( $po_path ), basename( $po_path ) );
	$nplural = 2;
	if ( isset( $po['header']['Plural-Forms'] ) && preg_match( '/nplurals\s*=\s*(\d+)/', $po['header']['Plural-Forms'], $m ) ) {
		$nplural = (int) $m[1];
	}
	$old = array();
	foreach ( $po['entries'] as $e ) {
		if ( ! $e['obsolete'] ) {
			$old[ qersh_i18n_key( $e ) ] = $e;
		}
	}
	$merged = array();
	$added  = 0;
	foreach ( $entries as $e ) {
		$key = qersh_i18n_key( $e );
		$n   = $e;
		if ( isset( $old[ $key ] ) ) {
			$o             = $old[ $key ];
			$n['comments'] = $o['comments'];
			$n['msgstr']   = $o['msgstr'];
			if ( in_array( 'fuzzy', $o['flags'], true ) ) {
				array_unshift( $n['flags'], 'fuzzy' );
			}
			unset( $old[ $key ] );
		} else {
			$added++;
		}
		$size = null === $n['msgid_plural'] ? 1 : $nplural;
		$fill = array();
		for ( $k = 0; $k < $size; $k++ ) {
			$fill[ $k ] = isset( $n['msgstr'][ $k ] ) ? $n['msgstr'][ $k ] : '';
		}
		$n['msgstr'] = $fill;
		$merged[]    = $n;
	}
	foreach ( $old as $o ) {
		$o['obsolete']   = true;
		$o['references'] = array();
		$o['extracted']  = array();
		$merged[]        = $o;
	}
	$po_header                       = $po['header'];
	$po_header['Project-Id-Version'] = $header['Project-Id-Version'];
	$po_header['POT-Creation-Date']  = $header['POT-Creation-Date'];
	$po_head                         = $po['header_entry']['comments'];
	file_put_contents( $po_path, qersh_i18n_po_write( $po_head, $po_header, $merged ) );
	fwrite( STDOUT, sprintf( "make-pot: merged into languages/%s: %d new, %d obsolete\n", basename( $po_path ), $added, count( $old ) ) );
}
