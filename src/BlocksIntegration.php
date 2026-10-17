<?php
/**
 * The wallet transfer method in the Blocks checkout.
 *
 * @package QershPay\WooCommerce
 */

namespace QershPay\WooCommerce;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

defined( 'ABSPATH' ) || exit;

/**
 * Registers assets/js/checkout-blocks.js (plain JavaScript with wp.element.createElement, no build step), which
 * draws the wallet choice and the payer field and sends `paymentMethodData { qersh_payer, qersh_wallet }` in
 * onPaymentSetup. Its canMakePayment() applies the same rules as Gateway::is_available() to the cart: EGP, a wallet,
 * and the store's minimum and maximum.
 */
final class BlocksIntegration extends AbstractPaymentMethodType {

	/**
	 * Method name: the key of the script data is `qersh_data`.
	 *
	 * @var string
	 */
	protected $name = Checkout::METHOD;

	/**
	 * Reads the method settings.
	 *
	 * @return void
	 */
	public function initialize() {
		$settings       = get_option( 'woocommerce_qersh_settings', array() );
		$this->settings = is_array( $settings ) ? $settings : array();
	}

	/**
	 * Active when the method is enabled (its scripts are not loaded otherwise).
	 *
	 * @return bool
	 */
	public function is_active() {
		return 'yes' === $this->get_setting( 'enabled', 'no' );
	}

	/**
	 * Registers the script (footer, its translations from the plugin's languages folder or the language pack) and
	 * the style.
	 *
	 * @return string[]
	 */
	public function get_payment_method_script_handles() {
		Checkout::register_assets();
		wp_register_script(
			Checkout::H_BLOCKS,
			QERSH_WC_URL . 'assets/js/checkout-blocks.js',
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities', 'wp-i18n', Checkout::H_NORMALIZE ),
			QERSH_WC_VERSION,
			true
		);
		wp_set_script_translations( Checkout::H_BLOCKS, 'qersh-for-woocommerce' );
		wp_enqueue_style( Checkout::H_STYLE );
		return array( Checkout::H_BLOCKS );
	}

	/**
	 * The data of getSetting( 'qersh_data' ).
	 *
	 * @return array
	 */
	public function get_payment_method_data() {
		$plugin  = Plugin::instance();
		$config  = $plugin->config();
		$all     = isset( $config['wallets'] ) && is_array( $config['wallets'] ) ? $config['wallets'] : array();
		$store   = isset( $config['store'] ) && is_array( $config['store'] ) ? $config['store'] : array();
		$views   = Checkout::wallet_views( $plugin );
		$gateway = $this->gateway();

		$wallets = array();
		foreach ( $views as $view ) {
			$wallets[] = array(
				'id'       => $view['id'],
				'label'    => $view['label'],
				'provider' => $view['provider'],
				'name'     => $view['provider_name'],
				'address'  => $view['address'],
				'display'  => $view['address_display'],
				'instapay' => $view['instapay'],
				'confirm'  => $view['confirm'],
				'decision' => $view['decision'],
			);
		}

		return array(
			'title'           => null !== $gateway ? $gateway->get_title() : Gateway::default_title( PayerField::has_instapay( PayerField::enabled_wallets( $all ) ) ),
			'description'     => null !== $gateway ? $gateway->get_description() : (string) $this->get_setting( 'description', '' ),
			'supports'        => $this->get_supported_features(),
			'ready'           => $plugin->tables_ready(),
			'currency'        => 'EGP',
			'min_total_minor' => Checkout::int_or_null( $store, 'min_total_minor' ),
			'max_total_minor' => Checkout::int_or_null( $store, 'max_total_minor' ),
			'wallets'         => $wallets,
			'instapay'        => PayerField::has_instapay( PayerField::enabled_wallets( $all ) ),
			'store_numbers'   => PayerField::browser_numbers( $all ),
		);
	}

	/**
	 * Supported features.
	 *
	 * @return string[]
	 */
	public function get_supported_features() {
		$gateway = $this->gateway();
		return null !== $gateway ? array_values( array_filter( $gateway->supports, array( $gateway, 'supports' ) ) ) : array( 'products' );
	}

	/**
	 * Our method object, when WooCommerce has built it.
	 *
	 * @return Gateway|null
	 */
	private function gateway() {
		if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
			return null;
		}
		$gateways = WC()->payment_gateways()->payment_gateways();
		return isset( $gateways[ Checkout::METHOD ] ) && $gateways[ Checkout::METHOD ] instanceof Gateway ? $gateways[ Checkout::METHOD ] : null;
	}
}
