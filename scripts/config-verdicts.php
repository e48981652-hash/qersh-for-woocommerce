<?php
/**
 * Helper of scripts/check-config-schema.mjs: prints, as JSON, the plugin's own view of configurations, so the
 * companion schema can be compared with Core\Config. Reads a JSON array of configurations from the file named by
 * the first argument and prints { defaults, providers, events, verdicts: [ [issues…], … ] }, where each list of
 * issues is Config::validate( Config::with_defaults( $case ) ). Pure PHP, no WordPress.
 * Usage: php scripts/config-verdicts.php <cases.json>
 *
 * @package QershPay\WooCommerce\Scripts
 */

// A command-line tool that WordPress never loads: WordPress's functions (WP_Filesystem, wp_json_encode(), the
// escaping functions) do not exist here, its output goes to a terminal or to files of this repository, and its global
// names cannot meet those of WordPress or of a plugin.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, WordPress.WP.AlternativeFunctions -- a command-line tool (above).

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
require dirname( __DIR__ ) . '/src/Autoloader.php';
\QershPay\WooCommerce\Autoloader::register();

use QershPay\WooCommerce\Core\Config;

$qersh_cases    = isset( $argv[1] ) ? json_decode( (string) file_get_contents( $argv[1] ), true ) : array();
$qersh_verdicts = array();
foreach ( is_array( $qersh_cases ) ? $qersh_cases : array() as $qersh_case ) {
	$qersh_verdicts[] = is_array( $qersh_case ) ? Config::validate( Config::with_defaults( $qersh_case ) ) : array(
		array(
			'path'    => '',
			'message' => 'not an object',
		),
	);
}
echo json_encode(
	array(
		'defaults'  => Config::DEFAULTS,
		'providers' => Config::PROVIDERS,
		'events'    => Config::EVENTS_ALLOWED,
		'verdicts'  => $qersh_verdicts,
	),
	JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);
