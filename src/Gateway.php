<?php
/**
 * The "E-wallet transfer" method: WooCommerce's payment method class for orders paid by wallet transfer.
 *
 * @package QershPay\WooCommerce
 */

namespace QershPay\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * The customer transfers from their own wallet app to the store's wallet; Qersh, on the merchant's phone, relays
 * the wallet's notification to the store, which confirms the order. No money passes through this plugin.
 *
 * Settings (woocommerce_qersh_settings) hold display texts only: enabled, title, description, instructions
 * (docs/17 §4.2). Everything that changes behaviour is in qersh_wc_config. The title left empty follows the enabled
 * wallets: "E-wallet transfer", or "E-wallet or InstaPay transfer" with an InstaPay wallet.
 */
class Gateway extends \WC_Payment_Gateway {

	/** Method id, also the Blocks name and the settings key. */
	const ID = Checkout::METHOD;

	/**
	 * The merchant's instructions, shown in the thank-you box and the instructions email.
	 *
	 * @var string
	 */
	public $instructions = '';

	/**
	 * Sets up the method.
	 */
	public function __construct() {
		$this->id                 = self::ID;
		$this->has_fields         = true;
		$this->supports           = array( 'products' );
		$this->method_title       = 'Wallet transfer (Qersh)';
		$this->method_description = '';

		if ( did_action( 'init' ) ) {
			$this->set_texts();
		} else {
			// Built before init (another plugin asked early): no translation yet, the texts follow at init.
			$this->init_settings();
			$this->load_settings();
			add_action( 'init', array( $this, 'set_texts' ), 1 );
		}

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/**
	 * The translated admin texts, the form fields and the settings (no translation call before init).
	 */
	public function set_texts() {
		$this->method_title       = __( 'Wallet transfer (Qersh)', 'qersh-for-woocommerce' );
		$this->method_description = self::disclosure();
		$this->init_form_fields();
		$this->init_settings();
		$this->load_settings();
	}

	/**
	 * Reads the display settings into the object.
	 */
	private function load_settings() {
		$this->title        = $this->resolve_title();
		$this->description  = (string) $this->get_option( 'description' );
		$this->instructions = (string) $this->get_option( 'instructions' );
	}

	/**
	 * The permanent disclosure (docs/17 §3): the paid app it needs, where to get it, and support. HTML, printed
	 * by WooCommerce through wp_kses_post().
	 *
	 * @return string
	 */
	public static function disclosure(): string {
		return sprintf(
			/* translators: 1: link to the app's download page, 2: support email link. */
			esc_html__( "Works with the paid Qersh Android app (monthly subscription) on the phone that receives your wallet's messages. Get the app: %1\$s. Support: the plugin's forum on wordpress.org or %2\$s.", 'qersh-for-woocommerce' ),
			'<a href="' . esc_url( 'https://qersh.cfd/en/download' ) . '" target="_blank" rel="noopener">https://qersh.cfd/en/download</a>',
			'<a href="' . esc_url( 'mailto:support@qersh.cfd' ) . '">support@qersh.cfd</a>'
		);
	}

	/**
	 * Only the display texts (docs/17 §4.2).
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'      => array(
				'title'   => __( 'Enable/Disable', 'qersh-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable wallet transfer', 'qersh-for-woocommerce' ),
				'default' => 'no',
			),
			'title'        => array(
				'title'       => __( 'Title', 'qersh-for-woocommerce' ),
				'type'        => 'safe_text',
				'description' => __( 'What customers see at checkout. Leave it empty to follow your enabled wallets.', 'qersh-for-woocommerce' ),
				'default'     => '',
				'placeholder' => self::default_title( $this->has_instapay() ),
				'desc_tip'    => true,
			),
			'description'  => array(
				'title'       => __( 'Description', 'qersh-for-woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'Shown under the title at checkout.', 'qersh-for-woocommerce' ),
				'default'     => __( 'Transfer the amount from your wallet to the store account shown to you.', 'qersh-for-woocommerce' ),
				'desc_tip'    => true,
			),
			'instructions' => array(
				'title'       => __( 'Instructions', 'qersh-for-woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'Added to the order page after checkout and to the transfer instructions email.', 'qersh-for-woocommerce' ),
				'default'     => '',
				'desc_tip'    => true,
			),
		);
	}

	/**
	 * The title of the method: the merchant's, or the default for the enabled wallets.
	 *
	 * @return string
	 */
	private function resolve_title(): string {
		$title = (string) $this->get_option( 'title' );
		if ( '' !== trim( $title ) ) {
			return $title;
		}
		if ( ! did_action( 'init' ) ) {
			return $this->has_instapay() ? 'E-wallet or InstaPay transfer' : 'E-wallet transfer';
		}
		return self::default_title( $this->has_instapay() );
	}

	/**
	 * The default title (docs/17 §3).
	 *
	 * @param bool $instapay Whether an InstaPay wallet is enabled.
	 * @return string
	 */
	public static function default_title( bool $instapay ): string {
		return $instapay
			? __( 'E-wallet or InstaPay transfer', 'qersh-for-woocommerce' )
			: __( 'E-wallet transfer', 'qersh-for-woocommerce' );
	}

	/**
	 * Whether an enabled wallet is an InstaPay one.
	 *
	 * @return bool
	 */
	private function has_instapay(): bool {
		$config = Plugin::instance()->config();
		return PayerField::has_instapay( PayerField::enabled_wallets( isset( $config['wallets'] ) && is_array( $config['wallets'] ) ? $config['wallets'] : array() ) );
	}

	/**
	 * Available when enabled, in EGP, with at least one enabled wallet, our tables in place, and the total within
	 * store.min_total_minor and store.max_total_minor (qersh_wc_config).
	 *
	 * @return bool
	 */
	public function is_available() {
		if ( 'yes' !== $this->enabled ) {
			return false;
		}
		$plugin = Plugin::instance();
		if ( ! $plugin->tables_ready() ) {
			return false;
		}
		$config  = $plugin->config();
		$wallets = PayerField::enabled_wallets( isset( $config['wallets'] ) && is_array( $config['wallets'] ) ? $config['wallets'] : array() );
		if ( empty( $wallets ) ) {
			return false;
		}
		list( $total, $currency ) = $this->total_and_currency();
		if ( 'EGP' !== $currency ) {
			return false;
		}
		$store = isset( $config['store'] ) && is_array( $config['store'] ) ? $config['store'] : array();
		return null === $total || Checkout::total_allowed( $total, Checkout::int_or_null( $store, 'min_total_minor' ), Checkout::int_or_null( $store, 'max_total_minor' ) );
	}

	/**
	 * The total in piasters and the currency of what is being paid: the order on "Pay for order", else the cart.
	 * The total is null when there is nothing to read (the method list in the admin, for example).
	 *
	 * @return array{0: int|null, 1: string}
	 */
	private function total_and_currency(): array {
		$order_id = absint( get_query_var( 'order-pay' ) );
		if ( $order_id > 0 ) {
			$order = wc_get_order( $order_id );
			if ( $order instanceof \WC_Order ) {
				return array( (int) round( (float) $order->get_total() * 100 ), (string) $order->get_currency() );
			}
		}
		$currency = get_woocommerce_currency();
		if ( function_exists( 'WC' ) && WC()->cart ) {
			return array( (int) round( (float) WC()->cart->get_total( 'edit' ) * 100 ), $currency );
		}
		return array( null, $currency );
	}

	/**
	 * The fields under the method: description, the wallet choice and the payer field. On "Pay for order" of an
	 * Orange Cash order, the line that sends a customer who already transferred to the order page (docs/17 §4.10).
	 */
	public function payment_fields() {
		$plugin      = Plugin::instance();
		$description = $this->get_description();
		if ( '' !== trim( (string) $description ) ) {
			echo wp_kses_post( wpautop( wptexturize( $description ) ) );
		}

		$order    = null;
		$order_id = absint( get_query_var( 'order-pay' ) );
		if ( $order_id > 0 ) {
			$found = wc_get_order( $order_id );
			if ( $found instanceof \WC_Order ) {
				$order = $found;
			}
		}
		if ( null !== $order && self::ID === $order->get_payment_method() ) {
			$this->order_pay_lines( $plugin, $order );
		}
		PayerField::render( $plugin, $order );
	}

	/**
	 * Lines for "Pay for order" of one of our orders: the transaction-id line of an Orange Cash order, and why the
	 * order cannot be cancelled once a transaction id was entered.
	 *
	 * @param Plugin    $plugin The plugin.
	 * @param \WC_Order $order  Order.
	 */
	private function order_pay_lines( Plugin $plugin, \WC_Order $order ) {
		$row    = Checkout::order_row( $plugin, $order->get_id() );
		$wallet = $plugin->wallet( Checkout::order_wallet_id( $order, $row ) );
		if ( ! Checkout::txn_entry_open( $plugin, $wallet ) ) {
			return;
		}
		$live_claim = null !== $row && ! empty( $row['claim_txn'] ) && in_array( (string) $row['state'], array( 'open', 'partial' ), true );
		echo '<p class="qersh-order-pay-txn">';
		printf(
			wp_kses(
				/* translators: %s: URL of the order page. */
				__( 'Already transferred for this order? Do not transfer again, and enter the transaction id on <a href="%s">your order page</a>.', 'qersh-for-woocommerce' ),
				array( 'a' => array( 'href' => array() ) )
			),
			esc_url( Checkout::txn_page_url( $order ) )
		);
		echo '</p>';
		if ( $live_claim ) {
			echo '<p class="qersh-order-pay-claim">' . esc_html__( 'You entered a transaction id for this order, so it cannot be cancelled here. Contact the store with your order number.', 'qersh-for-woocommerce' ) . '</p>';
		}
	}

	/**
	 * Checks the payer field and the chosen wallet before the order is paid for (classic, Blocks through the Store
	 * API's $_POST, and "Pay for order").
	 *
	 * @return bool
	 */
	public function validate_fields() {
		$result = $this->checked_post();
		if ( $result['ok'] ) {
			return true;
		}
		wc_add_notice( $result['message'], 'error', array( 'id' => PayerField::PAYER ) );
		return false;
	}

	/**
	 * The checked posted values, with the message of a refusal.
	 *
	 * @return array
	 */
	private function checked_post(): array {
		$plugin = Plugin::instance();
		$config = $plugin->config();
		$all    = isset( $config['wallets'] ) && is_array( $config['wallets'] ) ? $config['wallets'] : array();

		list( $payer, $wallet ) = PayerField::posted();

		$result = PayerField::check( $payer, $wallet, $all );
		if ( ! $result['ok'] ) {
			$result['message'] = PayerField::message( $result['error'], PayerField::has_instapay( PayerField::enabled_wallets( $all ) ) );
		}
		return $result;
	}

	/**
	 * Starts the wait for the transfer: the order stays pending; Orders writes its row, meta, deadline, expiry
	 * action and instructions email (docs/17 §4.3, §4.7). Then the cart is emptied and the customer goes to the
	 * order page with the instructions.
	 *
	 * @param int $order_id Order id.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return $this->failure( __( 'We could not find your order. Please try again.', 'qersh-for-woocommerce' ) );
		}
		$result = $this->checked_post();
		if ( ! $result['ok'] ) {
			wc_add_notice( $result['message'], 'error', array( 'id' => PayerField::PAYER ) );
			return array( 'result' => 'failure' );
		}
		$plugin = Plugin::instance();
		if ( ! class_exists( Orders::class ) || ! $plugin->tables_ready() ) {
			$plugin->logger()->error( 'Qersh could not start waiting for a transfer: the order service is not available.', array( 'order' => $order->get_id() ) );
			return $this->failure( __( 'Your order could not be saved. Please try again in a moment.', 'qersh-for-woocommerce' ) );
		}

		// "Pay for order": WooCommerce holds no stock there, so we do. Out of stock: the old deadline stays.
		if ( absint( get_query_var( 'order-pay' ) ) === $order->get_id() && function_exists( 'wc_reserve_stock_for_order' ) ) {
			try {
				wc_reserve_stock_for_order( $order );
			} catch ( \Exception $e ) {
				return $this->failure( $e->getMessage() );
			}
		}

		try {
			$plugin->orders()->on_process_payment( $order, (string) $result['payer'], (string) $result['wallet'], time() );
		} catch ( \Throwable $e ) {
			$plugin->logger()->error(
				'Qersh could not start waiting for a transfer.',
				array(
					'order' => $order->get_id(),
					'error' => get_class( $e ),
				)
			);
			return $this->failure( __( 'Your order could not be saved. Please try again in a moment.', 'qersh-for-woocommerce' ) );
		}

		if ( function_exists( 'WC' ) && WC()->cart ) {
			WC()->cart->empty_cart();
		}
		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}

	/**
	 * A refusal with its notice.
	 *
	 * @param string $message Message.
	 * @return array
	 */
	private function failure( string $message ): array {
		wc_add_notice( $message, 'error' );
		return array( 'result' => 'failure' );
	}

	/**
	 * The thank-you box (Checkout hooks woocommerce_thankyou_qersh itself, so the box prints even when no method
	 * object was built in this request).
	 *
	 * @param int $order_id Order id.
	 */
	public function thankyou_page( $order_id ) {
		Checkout::thankyou( $order_id );
	}
}
