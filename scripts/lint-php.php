<?php
/**
 * Runs `php -l` on every PHP file of the plugin and its tests (not vendor). Exit code 1 on any syntax error.
 * Usage: php scripts/lint-php.php
 *
 * @package QershPay\WooCommerce\Scripts
 */

$root  = dirname( __DIR__ );
$dirs  = array( 'qersh-for-woocommerce.php', 'uninstall.php', 'src', 'templates', 'tests/php', 'tests/parity' );
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
	exec( escapeshellarg( PHP_BINARY ) . ' -l ' . escapeshellarg( $file ) . ' 2>&1', $output, $code );
	if ( 0 !== $code ) {
		$failed++;
		fwrite( STDERR, implode( "\n", $output ) . "\n" );
	}
}
fwrite( STDOUT, sprintf( "lint-php: %d file(s), %d error(s)\n", count( $files ), $failed ) );
exit( $failed ? 1 : 0 );
