<?php
/**
 * Plugin Name:          Qersh for WooCommerce
 * Plugin URI:           https://qersh.cfd/en/integrations/woocommerce
 * Description:          Confirms WooCommerce orders paid by e-wallet transfer, using the Qersh app on the phone that receives your wallet's messages.
 * Version:              0.1.0
 * Requires at least:    6.9
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce
 * Author:               Qersh
 * Author URI:           https://qersh.cfd/en/
 * License:              GPLv2 or later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          qersh-for-woocommerce
 * Domain Path:          /languages
 * WC requires at least: 11.0
 * WC tested up to:      11.2
 *
 * @package QershPay\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

define( 'QERSH_WC_VERSION', '0.1.0' );
define( 'QERSH_WC_FILE', __FILE__ );
define( 'QERSH_WC_DIR', plugin_dir_path( __FILE__ ) );
define( 'QERSH_WC_URL', plugin_dir_url( __FILE__ ) );
define( 'QERSH_WC_DB_VERSION', 1 );
define( 'QERSH_WC_MIN_WC', '11.0' );

require_once __DIR__ . '/src/Autoloader.php';
\QershPay\WooCommerce\Autoloader::register();

// The fallback address (?wc-api=qersh) is caught here, before WooCommerce is checked, so a store whose WooCommerce
// is off still answers 503 JSON instead of the storefront page with 200.
add_action( 'parse_request', array( \QershPay\WooCommerce\Webhook\Fallback::class, 'maybe_handle' ), -1 );

// WooCommerce reads this filter once, at init: it must be registered before that.
add_filter( 'woocommerce_defer_transactional_emails', array( \QershPay\WooCommerce\Plugin::class, 'filter_defer_emails' ) );

add_action( 'before_woocommerce_init', array( \QershPay\WooCommerce\Plugin::class, 'declare_compatibility' ) );
register_activation_hook( QERSH_WC_FILE, array( \QershPay\WooCommerce\Plugin::class, 'activate' ) );
register_deactivation_hook( QERSH_WC_FILE, array( \QershPay\WooCommerce\Plugin::class, 'deactivate' ) );
add_action( 'plugins_loaded', array( \QershPay\WooCommerce\Plugin::class, 'boot' ), 20 );
