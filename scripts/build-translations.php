<?php
/**
 * Compiles every languages/*.po into the files WordPress loads (docs/17 §7), without gettext or WP-CLI:
 *
 * - <name>.mo: the GNU MO format (magic 0x950412de, little endian, revision 0, originals sorted byte-wise, no hash
 *   table). A context is stored as "context\x04msgid", a plural original as "msgid\0msgid_plural" and its
 *   translations as "form0\0form1\0…", exactly as msgfmt does.
 * - <name>.l10n.php: the PHP translation file of WordPress 6.5+ (WP_Translation_File_PHP), which WordPress prefers
 *   to the .mo: return array( headers…, 'messages' => array( original => translation ) ), the key being
 *   "context\x04msgid" or "msgid" and plural translations joined with "\0".
 * - <domain>-<locale>-<md5 of the script path>.json for each script of assets/js whose strings appear in the .po:
 *   the Jed 1.x data that wp_set_script_translations() loads, with only the strings that script uses (taken from
 *   the references of the .po, as "wp i18n make-json" does).
 *
 * Fuzzy, untranslated and obsolete entries are left out, as msgfmt leaves them out. The output depends only on the
 * .po, so building twice gives the same bytes.
 *
 * Usage, from the repository root:
 *   php scripts/build-translations.php          writes the files
 *   php scripts/build-translations.php --check  writes nothing; exit 1 when a compiled file is missing, differs
 *                                               from its .po (stale) or no longer belongs to it
 *   php scripts/build-translations.php --help   prints the usage
 * Any other argument (a mistyped flag) prints the usage and exits 2 before anything is read or written.
 *
 * @package QershPay\WooCommerce\Scripts
 */

// A command-line tool that WordPress never loads: WordPress's functions (WP_Filesystem, wp_json_encode(), the
// escaping functions) do not exist here, its output goes to a terminal or to files of this repository, and its global
// names cannot meet those of WordPress or of a plugin.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, WordPress.WP.GlobalVariablesOverride, WordPress.WP.AlternativeFunctions -- a command-line tool (above).

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

require __DIR__ . '/lib/i18n.php';

$root  = dirname( __DIR__ );
$args  = array_slice( $argv, 1 );
$usage = implode(
	"\n",
	array(
		'Usage: php scripts/build-translations.php [--check]',
		'  (no flag)  compile every languages/*.po into its .mo, .l10n.php and script JSON files',
		'  --check    write nothing; exit 1 when a compiled file is missing, stale or no longer belongs to its .po',
		'',
	)
);
if ( in_array( '--help', $args, true ) || in_array( '-h', $args, true ) ) {
	fwrite( STDOUT, $usage );
	exit( 0 );
}
$unknown = array_values( array_diff( $args, array( '--check' ) ) );
if ( $unknown ) {
	fwrite( STDERR, 'build-translations: unknown argument(s): ' . implode( ' ', $unknown ) . "; nothing written\n" . $usage );
	exit( 2 );
}
$check = in_array( '--check', $args, true );

/**
 * The translated, usable entries of a parsed .po: [ key => [ 'entry' => …, 'mo_key' => …, 'value' => … ] ].
 *
 * @param array $po      Parsed .po.
 * @param int   $nplural nplurals of the header.
 * @return array
 */
function qersh_build_usable( array $po, $nplural ) {
	$out = array();
	foreach ( $po['entries'] as $e ) {
		if ( $e['obsolete'] || in_array( 'fuzzy', $e['flags'], true ) ) {
			continue;
		}
		$forms = array_values( $e['msgstr'] );
		if ( null !== $e['msgid_plural'] ) {
			$forms = array_slice( $forms, 0, $nplural );
		} else {
			$forms = array_slice( $forms, 0, 1 );
		}
		$empty = ( array() === $forms );
		foreach ( $forms as $f ) {
			if ( '' === $f ) {
				$empty = true;
			}
		}
		if ( $empty ) {
			continue;
		}
		$key         = qersh_i18n_key( $e );
		$out[ $key ] = array(
			'entry'  => $e,
			'mo_key' => null === $e['msgid_plural'] ? $key : $key . "\0" . $e['msgid_plural'],
			'value'  => implode( "\0", $forms ),
			'forms'  => $forms,
		);
	}
	return $out;
}

/**
 * The MO file.
 *
 * @param string $header_string The header entry ("Name: value\n" lines).
 * @param array  $usable        From qersh_build_usable().
 * @return string
 */
function qersh_build_mo( $header_string, array $usable ) {
	$pairs = array( '' => $header_string );
	foreach ( $usable as $u ) {
		$pairs[ $u['mo_key'] ] = $u['value'];
	}
	$keys = array_map( 'strval', array_keys( $pairs ) );
	usort( $keys, 'strcmp' );
	$count     = count( $keys );
	$orig_addr = 28;
	$tran_addr = $orig_addr + 8 * $count;
	$data_addr = $tran_addr + 8 * $count;
	$o_table   = '';
	$t_table   = '';
	$o_data    = '';
	$t_data    = '';
	$offset    = $data_addr;
	foreach ( $keys as $k ) {
		$o_table .= pack( 'VV', strlen( $k ), $offset );
		$o_data  .= $k . "\0";
		$offset  += strlen( $k ) + 1;
	}
	foreach ( $keys as $k ) {
		$v        = $pairs[ $k ];
		$t_table .= pack( 'VV', strlen( $v ), $offset );
		$t_data  .= $v . "\0";
		$offset  += strlen( $v ) + 1;
	}
	// Magic, revision, count, originals table, translations table, hash table size (0) and offset.
	$head = pack( 'VVVVVVV', 0x950412de, 0, $count, $orig_addr, $tran_addr, 0, $data_addr );
	return $head . $o_table . $t_table . $o_data . $t_data;
}

/**
 * A PHP string literal: single-quoted, with control characters (the EOT of contexts, the NUL of plurals) as
 * double-quoted escapes joined with ".".
 *
 * @param string $s Value.
 * @return string
 */
function qersh_build_php_string( $s ) {
	$parts = preg_split( '/([\x00-\x08\x0b\x0c\x0e-\x1f\x7f])/', $s, -1, PREG_SPLIT_DELIM_CAPTURE );
	$out   = array();
	foreach ( $parts as $i => $part ) {
		if ( 1 === $i % 2 ) {
			$out[] = '"' . ( "\0" === $part ? '\\0' : sprintf( '\\x%02x', ord( $part ) ) ) . '"';
		} elseif ( '' !== $part || 1 === count( $parts ) ) {
			$out[] = "'" . strtr(
				$part,
				array(
					'\\' => '\\\\',
					"'"  => "\\'",
				)
			) . "'";
		}
	}
	return implode( ' . ', $out );
}

/**
 * The .l10n.php file.
 *
 * @param string $po_rel  The .po, relative to the root.
 * @param array  $headers Lower-case headers to keep, in order.
 * @param array  $usable  From qersh_build_usable().
 * @return string
 */
function qersh_build_php( $po_rel, array $headers, array $usable ) {
	$out  = "<?php\n";
	$out .= "/**\n";
	$out .= ' * Translations of Qersh for WooCommerce, compiled from ' . $po_rel . " by scripts/build-translations.php.\n";
	$out .= " * Do not edit: change the .po and run the script again. WordPress 6.5+ loads this file instead of the .mo.\n";
	$out .= " *\n";
	$out .= " * @package QershPay\\WooCommerce\n";
	$out .= " */\n\n";
	$out .= "defined( 'ABSPATH' ) || exit;\n\n";
	$out .= "return array(\n";
	foreach ( $headers as $name => $value ) {
		$out .= "\t" . qersh_build_php_string( $name ) . ' => ' . qersh_build_php_string( $value ) . ",\n";
	}
	$out .= "\t'messages' => array(\n";
	foreach ( $usable as $key => $u ) {
		$out .= "\t\t" . qersh_build_php_string( (string) $key ) . ' => ' . qersh_build_php_string( $u['value'] ) . ",\n";
	}
	$out .= "\t),\n";
	$out .= ");\n";
	return $out;
}

/**
 * The Jed JSON of one script.
 *
 * @param string $source  Script path, relative to the plugin.
 * @param string $lang    Language code.
 * @param string $plural  Plural-Forms.
 * @param string $date    PO-Revision-Date.
 * @param array  $usable  Entries used by the script.
 * @return string
 */
function qersh_build_json( $source, $lang, $plural, $date, array $usable ) {
	$messages = array(
		'' => array(
			'domain'       => 'messages',
			'lang'         => $lang,
			'plural-forms' => $plural,
		),
	);
	foreach ( $usable as $key => $u ) {
		$messages[ (string) $key ] = $u['forms'];
	}
	$data = array(
		'translation-revision-date' => $date,
		'generator'                 => 'scripts/build-translations.php',
		'source'                    => $source,
		'domain'                    => 'messages',
		'locale_data'               => array( 'messages' => $messages ),
	);
	return json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n";
}

$problems = array();
$written  = array();
$po_files = glob( $root . '/languages/*.po' );
if ( ! $po_files ) {
	fwrite( STDERR, "build-translations: no languages/*.po\n" );
	exit( 1 );
}
foreach ( $po_files as $po_path ) {
	$po_rel = 'languages/' . basename( $po_path );
	try {
		$po = qersh_i18n_po_parse( (string) file_get_contents( $po_path ), $po_rel );
	} catch ( RuntimeException $e ) {
		fwrite( STDERR, 'build-translations: ' . $e->getMessage() . "\n" );
		exit( 1 );
	}
	$h = $po['header'];
	if ( empty( $h['Language'] ) || empty( $h['Plural-Forms'] ) ) {
		fwrite( STDERR, "build-translations: $po_rel needs the Language and Plural-Forms headers\n" );
		exit( 1 );
	}
	$nplural = preg_match( '/nplurals\s*=\s*(\d+)/', $h['Plural-Forms'], $m ) ? (int) $m[1] : 2;
	$usable  = qersh_build_usable( $po, $nplural );
	$base    = substr( $po_path, 0, -3 );
	$domain  = isset( $h['X-Domain'] ) && '' !== $h['X-Domain'] ? $h['X-Domain'] : QERSH_I18N_DOMAIN;
	$locale  = $h['Language'];

	$header_string = '';
	foreach ( $h as $name => $value ) {
		$header_string .= $name . ': ' . $value . "\n";
	}
	$outputs = array();

	$outputs[ $base . '.mo' ] = qersh_build_mo( $header_string, $usable );

	$php_headers = array( 'domain' => $domain );
	foreach ( array( 'Plural-Forms', 'Language', 'Project-Id-Version', 'POT-Creation-Date', 'PO-Revision-Date', 'X-Generator' ) as $name ) {
		if ( isset( $h[ $name ] ) ) {
			$php_headers[ strtolower( $name ) ] = $h[ $name ];
		}
	}
	$outputs[ $base . '.l10n.php' ] = qersh_build_php( $po_rel, $php_headers, $usable );

	// One JSON per script, from the references of the .po.
	$by_script = array();
	foreach ( $usable as $key => $u ) {
		foreach ( $u['entry']['references'] as $ref ) {
			$file = preg_replace( '/:\d+$/', '', $ref );
			if ( preg_match( '#^assets/js/.+\.js$#', $file ) ) {
				$by_script[ $file ][ $key ] = $u;
			}
		}
	}
	ksort( $by_script );
	$json_prefix = $root . '/languages/' . $domain . '-' . $locale . '-';
	foreach ( $by_script as $source => $entries ) {
		$date = isset( $h['PO-Revision-Date'] ) ? $h['PO-Revision-Date'] : '';
		$outputs[ $json_prefix . md5( $source ) . '.json' ] = qersh_build_json( $source, $locale, $h['Plural-Forms'], $date, $entries );
	}

	// JSON files of this locale that no script needs any more.
	foreach ( glob( $json_prefix . '*.json' ) as $old ) {
		if ( ! isset( $outputs[ $old ] ) && preg_match( '/-[0-9a-f]{32}\.json$/', $old ) ) {
			if ( $check ) {
				$problems[] = 'languages/' . basename( $old ) . ': no script of the .po uses it any more';
			} else {
				unlink( $old );
			}
		}
	}

	$po_mtime = filemtime( $po_path );
	foreach ( $outputs as $path => $bytes ) {
		$rel = 'languages/' . basename( $path );
		if ( $check ) {
			if ( ! is_file( $path ) ) {
				$problems[] = $rel . ': missing; run php scripts/build-translations.php';
			} elseif ( (string) file_get_contents( $path ) !== $bytes ) {
				$older      = filemtime( $path ) < $po_mtime ? 'older than ' . $po_rel . ' and ' : '';
				$problems[] = $rel . ': ' . $older . 'stale (does not match ' . $po_rel . '); run php scripts/build-translations.php';
			}
			continue;
		}
		if ( ! is_file( $path ) || (string) file_get_contents( $path ) !== $bytes ) {
			file_put_contents( $path, $bytes );
		} else {
			touch( $path ); // Same bytes: only mark it as built from the current .po.
		}
		$written[] = $rel;
	}
	if ( ! $check ) {
		fwrite( STDOUT, sprintf( "build-translations: %s, %d of %d entries translated -> %s\n", $po_rel, count( $usable ), count( $po['entries'] ), implode( ', ', array_map( 'basename', array_keys( $outputs ) ) ) ) );
	}
}

if ( $check ) {
	if ( $problems ) {
		fwrite( STDERR, 'build-translations --check: ' . count( $problems ) . " problem(s)\n" . implode( "\n", $problems ) . "\n" );
		exit( 1 );
	}
	fwrite( STDOUT, "build-translations --check: ok\n" );
}
exit( 0 );
