<?php
/**
 * Shared helpers of the translation tools (scripts/make-pot.php, scripts/build-translations.php): reading and
 * writing PO and POT files, and the placeholder rules. Development tool only, not shipped (see .distignore and
 * scripts/build-zip.mjs). No WordPress, no gettext: this machine and CI have neither.
 *
 * PHP 7.4 syntax, like the plugin, because CI lints every PHP file on 7.4.
 *
 * @package QershPay\WooCommerce\Scripts
 */

// A command-line tool that WordPress never loads: WordPress's functions (WP_Filesystem, wp_json_encode(), the
// escaping functions) do not exist here, its output goes to a terminal or to files of this repository, and its global
// names cannot meet those of WordPress or of a plugin.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput -- a command-line tool (above).

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

const QERSH_I18N_DOMAIN = 'qersh-for-woocommerce';

/**
 * The printf placeholders a translation must keep (php-format and the sprintf of wp.i18n): %s, %d, %1$s, %%, …
 * The space flag is left out on purpose, so "50% sale" is not read as a placeholder.
 */
const QERSH_I18N_PLACEHOLDER = '/%(?:%|(?:[1-9][0-9]*\$)?(?:[-+0]|\'.)?[0-9]*(?:\.[0-9]+)?[bcdeEfFgGosuxX])/';

/**
 * Escapes a string for a PO file.
 *
 * @param string $s Raw string.
 * @return string
 */
function qersh_i18n_po_escape( $s ) {
	return strtr(
		$s,
		array(
			'\\' => '\\\\',
			'"'  => '\\"',
			"\n" => '\\n',
			"\t" => '\\t',
			"\r" => '\\r',
		)
	);
}

/**
 * Reverses qersh_i18n_po_escape(), plus the other C escapes gettext accepts.
 *
 * @param string $s Escaped string (without the quotes).
 * @return string
 */
function qersh_i18n_po_unescape( $s ) {
	return preg_replace_callback(
		'/\\\\(?:([0-7]{1,3})|x([0-9a-fA-F]{1,2})|(.))/s',
		static function ( $m ) {
			// A group before the one that matched is present as ''; the ones after it are left out.
			if ( '' !== $m[1] ) {
				return chr( octdec( $m[1] ) );
			}
			if ( '' !== $m[2] ) {
				return chr( hexdec( $m[2] ) );
			}
			$map = array(
				'n' => "\n",
				't' => "\t",
				'r' => "\r",
				'a' => "\x07",
				'b' => "\x08",
				'f' => "\x0c",
				'v' => "\x0b",
			);
			return isset( $map[ $m[3] ] ) ? $map[ $m[3] ] : $m[3];
		},
		$s
	);
}

/**
 * A new, empty entry.
 *
 * @return array
 */
function qersh_i18n_entry() {
	return array(
		'comments'     => array(), // "# " translator comments.
		'extracted'    => array(), // "#. " comments from the source (translators: …).
		'references'   => array(), // "#: " file:line.
		'flags'        => array(), // "#, " fuzzy, php-format, …
		'msgctxt'      => null,
		'msgid'        => '',
		'msgid_plural' => null,
		'msgstr'       => array( '' ),
		'obsolete'     => false,
	);
}

/**
 * The lookup key of an entry: context EOT msgid, as gettext, WordPress and Jed use it.
 *
 * @param array $entry Entry.
 * @return string
 */
function qersh_i18n_key( array $entry ) {
	return null === $entry['msgctxt'] ? $entry['msgid'] : $entry['msgctxt'] . "\x04" . $entry['msgid'];
}

/**
 * Parses a PO or POT file.
 *
 * @param string $content File content.
 * @param string $label   Name for error messages.
 * @return array { header: array<string,string>, header_entry: array, entries: array<int,array> } (entries in file order).
 * @throws RuntimeException On a syntax error.
 */
function qersh_i18n_po_parse( $content, $label = 'po' ) {
	$content = str_replace( "\r\n", "\n", $content );
	if ( 0 === strncmp( $content, "\xEF\xBB\xBF", 3 ) ) {
		$content = substr( $content, 3 );
	}
	$lines   = explode( "\n", $content );
	$entries = array();
	$entry   = qersh_i18n_entry();
	$started = false;
	$last    = null; // Field the next continuation line belongs to.
	// Ends the entry being read: keeps it once it has a keyword, then starts a fresh one.
	$flush = static function ( array &$entries, array &$entry, bool &$started, ?string &$last ): void {
		if ( $started ) {
			$entries[] = $entry;
		}
		$entry   = qersh_i18n_entry();
		$started = false;
		$last    = null;
	};
	foreach ( $lines as $n => $line ) {
		$line = rtrim( $line );
		$obs  = false;
		if ( 0 === strncmp( $line, '#~', 2 ) ) {
			$obs  = true;
			$line = ltrim( substr( $line, 2 ) );
			if ( '' === $line ) {
				continue;
			}
		}
		if ( '' === $line ) {
			$flush( $entries, $entry, $started, $last );
			continue;
		}
		if ( '#' === $line[0] && ! $obs ) {
			if ( $started && null !== $last ) {
				$flush( $entries, $entry, $started, $last );
			}
			$kind = substr( $line, 0, 2 );
			if ( '#.' === $kind ) {
				$entry['extracted'][] = ltrim( substr( $line, 2 ) );
			} elseif ( '#:' === $kind ) {
				foreach ( preg_split( '/\s+/', trim( substr( $line, 2 ) ) ) as $ref ) {
					if ( '' !== $ref ) {
						$entry['references'][] = $ref;
					}
				}
			} elseif ( '#,' === $kind ) {
				foreach ( explode( ',', substr( $line, 2 ) ) as $flag ) {
					$flag = trim( $flag );
					if ( '' !== $flag ) {
						$entry['flags'][] = $flag;
					}
				}
			} elseif ( '#|' === $kind ) {
				continue; // Previous msgid: not kept.
			} else {
				$entry['comments'][] = ltrim( substr( $line, 1 ) );
			}
			continue;
		}
		if ( preg_match( '/^(msgctxt|msgid_plural|msgid|msgstr(?:\[(\d+)\])?)\s+"(.*)"$/s', $line, $m ) ) {
			$field = $m[1];
			$value = qersh_i18n_po_unescape( $m[3] );
			if ( 'msgctxt' === $field || ( 'msgid' === $field && 'msgctxt' !== $last ) ) {
				if ( $started && null !== $last && 0 === strncmp( $last, 'msgstr', 6 ) ) {
					$flush( $entries, $entry, $started, $last );
				}
			}
			$started           = true;
			$entry['obsolete'] = $obs;
			if ( 'msgctxt' === $field ) {
				$entry['msgctxt'] = $value;
				$last             = 'msgctxt';
			} elseif ( 'msgid' === $field ) {
				$entry['msgid'] = $value;
				$last           = 'msgid';
			} elseif ( 'msgid_plural' === $field ) {
				$entry['msgid_plural'] = $value;
				$last                  = 'msgid_plural';
			} else {
				$index = '' !== $m[2] ? (int) $m[2] : 0; // Group 3 always matches, so group 2 is there, '' without [n].
				if ( 'msgstr' === $field ) {
					$entry['msgstr'] = array( 0 => $value );
				} else {
					if ( 0 === $index ) {
						$entry['msgstr'] = array();
					}
					$entry['msgstr'][ $index ] = $value;
				}
				$last = 'msgstr:' . $index;
			}
			continue;
		}
		if ( preg_match( '/^"(.*)"$/s', $line, $m ) && null !== $last ) {
			$value = qersh_i18n_po_unescape( $m[1] );
			if ( 'msgctxt' === $last ) {
				$entry['msgctxt'] .= $value;
			} elseif ( 'msgid' === $last ) {
				$entry['msgid'] .= $value;
			} elseif ( 'msgid_plural' === $last ) {
				$entry['msgid_plural'] .= $value;
			} else {
				$index                      = (int) substr( $last, 7 );
				$entry['msgstr'][ $index ] .= $value;
			}
			continue;
		}
		throw new RuntimeException( sprintf( '%s:%d: cannot read this line: %s', $label, $n + 1, $line ) );
	}
	$flush( $entries, $entry, $started, $last );

	$header       = array();
	$header_entry = null;
	$out          = array();
	foreach ( $entries as $e ) {
		if ( null === $header_entry && '' === $e['msgid'] && null === $e['msgctxt'] && ! $e['obsolete'] ) {
			$header_entry = $e;
			foreach ( explode( "\n", $e['msgstr'][0] ) as $h ) {
				$pos = strpos( $h, ':' );
				if ( false !== $pos ) {
					$header[ trim( substr( $h, 0, $pos ) ) ] = trim( substr( $h, $pos + 1 ) );
				}
			}
			continue;
		}
		ksort( $e['msgstr'] );
		$out[] = $e;
	}
	return array(
		'header'       => $header,
		'header_entry' => null === $header_entry ? qersh_i18n_entry() : $header_entry,
		'entries'      => $out,
	);
}

/**
 * One PO field, split after each \n so long multi-line texts stay readable.
 *
 * @param string $keyword The keyword: msgid, msgstr[0] or another.
 * @param string $value   Raw value.
 * @param string $prefix  "#~ " for obsolete entries.
 * @return string
 */
function qersh_i18n_po_field( $keyword, $value, $prefix = '' ) {
	$parts = preg_split( '/(?<=\n)/', $value, -1, PREG_SPLIT_NO_EMPTY );
	if ( count( $parts ) <= 1 ) {
		return $prefix . $keyword . ' "' . qersh_i18n_po_escape( $value ) . "\"\n";
	}
	$out = $prefix . $keyword . " \"\"\n";
	foreach ( $parts as $part ) {
		$out .= $prefix . '"' . qersh_i18n_po_escape( $part ) . "\"\n";
	}
	return $out;
}

/**
 * Writes one entry.
 *
 * @param array $e Entry.
 * @return string
 */
function qersh_i18n_po_entry( array $e ) {
	$out = '';
	foreach ( $e['comments'] as $c ) {
		$out .= rtrim( '# ' . $c ) . "\n";
	}
	foreach ( $e['extracted'] as $c ) {
		$out .= rtrim( '#. ' . $c ) . "\n";
	}
	foreach ( $e['references'] as $r ) {
		$out .= '#: ' . $r . "\n";
	}
	if ( $e['flags'] ) {
		$out .= '#, ' . implode( ', ', $e['flags'] ) . "\n";
	}
	$p = $e['obsolete'] ? '#~ ' : '';
	if ( null !== $e['msgctxt'] ) {
		$out .= qersh_i18n_po_field( 'msgctxt', $e['msgctxt'], $p );
	}
	$out .= qersh_i18n_po_field( 'msgid', $e['msgid'], $p );
	if ( null !== $e['msgid_plural'] ) {
		$out .= qersh_i18n_po_field( 'msgid_plural', $e['msgid_plural'], $p );
		foreach ( $e['msgstr'] as $i => $s ) {
			$out .= qersh_i18n_po_field( 'msgstr[' . $i . ']', $s, $p );
		}
	} else {
		$out .= qersh_i18n_po_field( 'msgstr', isset( $e['msgstr'][0] ) ? $e['msgstr'][0] : '', $p );
	}
	return $out;
}

/**
 * Writes a whole PO or POT file.
 *
 * @param array    $head_comments Lines of the comment block above the header (without "# ").
 * @param string[] $header        Ordered header fields.
 * @param array    $entries       Entries.
 * @return string
 */
function qersh_i18n_po_write( array $head_comments, array $header, array $entries ) {
	$out = '';
	foreach ( $head_comments as $c ) {
		$out .= rtrim( '# ' . $c ) . "\n";
	}
	$out .= "msgid \"\"\nmsgstr \"\"\n";
	foreach ( $header as $name => $value ) {
		$out .= '"' . qersh_i18n_po_escape( $name . ': ' . $value . "\n" ) . "\"\n";
	}
	foreach ( $entries as $e ) {
		$out .= "\n" . qersh_i18n_po_entry( $e );
	}
	return $out;
}

/**
 * The placeholders of a text, in order.
 *
 * @param string $text Text.
 * @return string[]
 */
function qersh_i18n_placeholders( $text ) {
	preg_match_all( QERSH_I18N_PLACEHOLDER, $text, $m );
	return $m[0];
}

/**
 * Reads a plugin header the way WordPress' get_file_data() does.
 *
 * @param string $file   Main plugin file.
 * @param array  $fields Header names.
 * @return array<string,string>
 */
function qersh_i18n_plugin_headers( $file, array $fields ) {
	$data = (string) file_get_contents( $file, false, null, 0, 8192 );
	$data = str_replace( "\r", "\n", $data );
	$out  = array();
	foreach ( $fields as $field ) {
		if ( preg_match( '/^(?:[ \t]*<\?php)?[ \t\/*#@]*' . preg_quote( $field, '/' ) . ':(.*)$/mi', $data, $m ) ) {
			$out[ $field ] = trim( preg_replace( '/\s*(?:\*\/|\?>).*/', '', $m[1] ) );
		} else {
			$out[ $field ] = '';
		}
	}
	return $out;
}
