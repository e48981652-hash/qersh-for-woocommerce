<?php
/**
 * Runs `php -l` on every PHP file of the plugin (with the compiled languages/*.l10n.php), its tests and the
 * development scripts (not vendor). Exit code 1 on any syntax error.
 * Usage: php scripts/lint-php.php
 *
 * @package QershPay\WooCommerce\Scripts
 */

// A command-line tool that WordPress never loads: WordPress's functions (WP_Filesystem, wp_json_encode(), the
// escaping functions) do not exist here, its output goes to a terminal or to files of this repository, and its global
// names cannot meet those of WordPress or of a plugin.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, WordPress.WP.GlobalVariablesOverride, WordPress.WP.AlternativeFunctions -- a command-line tool (above).

$root  = dirname( __DIR__ );
$dirs  = array( 'qersh-for-woocommerce.php', 'uninstall.php', 'src', 'templates', 'languages', 'scripts', 'tests/php', 'tests/phpstan', 'tests/parity', 'tests/local', 'tests/e2e' );
$files = array();
foreach ( $dirs as $dir ) {
	$path = $root . '/' . $dir;
	if ( is_file( $path ) ) {
		$files[] = $path;
		continue;
	}
	if ( ! is_dir( $path ) ) {
		continue;
	}
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $file ) {
		if ( 'php' === strtolower( $file->getExtension() ) ) {
			$files[] = $file->getPathname();
		}
	}
}
$failed = 0;
foreach ( $files as $file ) {
	$output = array();
	$code   = 0;
	exec( escapeshellarg( PHP_BINARY ) . ' -l ' . escapeshellarg( $file ) . ' 2>&1', $output, $code ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- runs php -l, the whole point of this tool.
	if ( 0 !== $code ) {
		++$failed;
		fwrite( STDERR, implode( "\n", $output ) . "\n" );
	}
}
fwrite( STDOUT, sprintf( "lint-php: %d file(s), %d error(s)\n", count( $files ), $failed ) );
exit( $failed ? 1 : 0 );
