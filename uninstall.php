<?php
/**
 * Uninstall of Qersh for WooCommerce. The plugin's data is removed only when the merchant asked for it: the setting
 * "Delete the plugin's tables, settings and connection keys when the plugin is deleted", or QERSH_WC_REMOVE_ALL_DATA
 * in wp-config.php (docs/17 §4.1). Deactivating the plugin removes nothing.
 *
 * @package QershPay\WooCommerce
 */

defined( 'ABSPATH' ) || exit;
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! class_exists( '\QershPay\WooCommerce\Autoloader', false ) ) {
	require_once __DIR__ . '/src/Autoloader.php';
}
\QershPay\WooCommerce\Autoloader::register();

\QershPay\WooCommerce\Admin\Uninstall::run();
