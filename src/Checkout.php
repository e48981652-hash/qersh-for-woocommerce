<?php
/**
 * Package C's entry point: the wallet transfer method, Blocks, the two storefront routes, the thank-you box and the
 * instructions email.
 *
 * @package QershPay\WooCommerce
 */

namespace QershPay\WooCommerce;

use QershPay\WooCommerce\Core\Money;
use QershPay\WooCommerce\Core\TxnId;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the customer-facing side (docs/17 §3, §4.1, §4.7, §4.10, §6, §7), and holds what the checkout, the
 * thank-you box, the Blocks data and the instructions email share: the wallet views, the confirmation line of each
 * wallet, the amounts, and the customer-facing status.
 */
final class Checkout {

	/**
	 * The method id (WC_Payment_Gateway::$id, the Blocks name, the settings key). Gateway::ID is the same value; this
	 * copy can be read without loading a WooCommerce subclass.
	 */
	const METHOD = 'qersh';

	/**
	 * The trigger action of the instructions email, fired by Orders with the order id (CONTRACTS.md §9). Kept here,
	 * not on Emails\Instructions, so reading it never loads that class before WC_Email exists.
	 */
	const EMAIL_ACTION = 'qersh_wc_send_instructions';

	/** Script and style handles. */
	const H_NORMALIZE = 'qersh-wc-normalize';
	const H_PAYER     = 'qersh-wc-payer-field';
	const H_THANKYOU  = 'qersh-wc-thankyou';
	const H_BLOCKS    = 'qersh-wc-checkout-blocks';
	const H_STYLE     = 'qersh-wc-front';

	/** Live polling (docs/17 §6.2): every 15 s for the first 20 minutes, then every 60 s until deadline + grace. */
	const POLL_FAST_SEC   = 15;
	const POLL_SLOW_SEC   = 60;
	const POLL_FAST_UNTIL = 1200;

	/** After 15 minutes of waiting, the "Transferred from another number?" line (or the Orange reminder). */
	const NUDGE_SEC = 900;

	/** Wallet types whose messages name the payer: the order is confirmed on its own. */
	const AUTO_PROVIDERS = array( 'vodafone_cash', 'etisalat_cash' );

	/**
	 * Thank-you boxes already printed in this request (classic and Blocks may both fire the hook).
	 *
	 * @var array<int, bool>
	 */
	private static $printed = array();

	/**
	 * Hooks. Routes are registered even when WooCommerce is off: they then answer 503 JSON.
	 *
	 * @param Plugin $plugin   The plugin.
	 * @param bool   $wc_ready Whether WooCommerce (at the minimum version) is active.
	 */
	public static function register( Plugin $plugin, bool $wc_ready ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- the module signature; services are read from Plugin::instance() when hooks run.
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
		if ( ! $wc_ready ) {
			return;
		}
		add_filter( 'woocommerce_payment_gateways', array( self::class, 'add_gateway' ) );
		add_action( 'woocommerce_blocks_payment_method_type_registration', array( self::class, 'register_blocks' ) );
		add_action( 'woocommerce_thankyou_' . self::METHOD, array( self::class, 'thankyou' ) );
		add_filter( 'woocommerce_email_classes', array( self::class, 'add_emails' ) );
		add_filter( 'woocommerce_email_actions', array( self::class, 'add_email_actions' ) );
		add_action( 'woocommerce_email_before_order_table', array( self::class, 'email_confirmed_line' ), 10, 4 );
		add_action( 'wp_enqueue_scripts', array( self::class, 'register_assets' ), 5 );
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue_checkout' ), 20 );
	}

	/**
	 * The two storefront routes (POST only, not in the index).
	 */
	public static function register_routes() {
		register_rest_route(
			'qersh/v1',
			'/order-status',
			array(
				'methods'             => 'POST',
				'callback'            => array( OrderStatusRoute::class, 'rest' ),
				'permission_callback' => array( OrderStatusRoute::class, 'permission' ),
				'show_in_index'       => false,
			)
		);
		register_rest_route(
			'qersh/v1',
			'/order-txn',
			array(
				'methods'             => 'POST',
				'callback'            => array( ClaimRoute::class, 'rest' ),
				'permission_callback' => array( ClaimRoute::class, 'permission' ),
				'show_in_index'       => false,
			)
		);
	}

	/**
	 * Adds the method.
	 *
	 * @param array $gateways Class names or objects.
	 * @return array
	 */
	public static function add_gateway( $gateways ) {
		$gateways   = is_array( $gateways ) ? $gateways : array();
		$gateways[] = Gateway::class;
		return $gateways;
	}

	/**
	 * Registers the Blocks integration.
	 *
	 * @param object $registry PaymentMethodRegistry.
	 */
	public static function register_blocks( $registry ) {
		if ( is_object( $registry ) && method_exists( $registry, 'register' ) && class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
			$registry->register( new BlocksIntegration() );
		}
	}

	/**
	 * Adds the instructions email.
	 *
	 * @param array $emails Email objects.
	 * @return array
	 */
	public static function add_emails( $emails ) {
		$emails = is_array( $emails ) ? $emails : array();
		if ( class_exists( '\WC_Email' ) ) {
			$emails['Qersh_WC_Email_Instructions'] = new Emails\Instructions();
		}
		return $emails;
	}

	/**
	 * Lets WooCommerce dispatch (and defer, with the rest) our trigger action.
	 *
	 * @param array $actions Action names.
	 * @return array
	 */
	public static function add_email_actions( $actions ) {
		$actions = is_array( $actions ) ? $actions : array();
		if ( ! in_array( self::EMAIL_ACTION, $actions, true ) ) {
			$actions[] = self::EMAIL_ACTION;
		}
		return $actions;
	}

	/**
	 * Registers the front scripts and the style (enqueued only where they are needed).
	 */
	public static function register_assets() {
		if ( wp_script_is( self::H_NORMALIZE, 'registered' ) ) {
			return;
		}
		$url = QERSH_WC_URL . 'assets/';
		wp_register_script( self::H_NORMALIZE, $url . 'js/txn-normalize.js', array(), QERSH_WC_VERSION, true );
		wp_register_script( self::H_PAYER, $url . 'js/payer-field.js', array( self::H_NORMALIZE, 'jquery' ), QERSH_WC_VERSION, true );
		wp_register_script( self::H_THANKYOU, $url . 'js/thankyou.js', array( self::H_NORMALIZE ), QERSH_WC_VERSION, true );
		wp_register_style( self::H_STYLE, $url . 'css/front.css', array(), QERSH_WC_VERSION );
	}

	/**
	 * The classic checkout and "Pay for order": the payer field script and the style. The Blocks checkout gets its
	 * own script through BlocksIntegration; the thank-you page enqueues when it prints the box.
	 */
	public static function enqueue_checkout() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_order_received_page() ) {
			return;
		}
		$order_pay = is_wc_endpoint_url( 'order-pay' );
		if ( ! $order_pay && function_exists( 'has_block' ) && has_block( 'woocommerce/checkout' ) ) {
			return;
		}
		$settings = get_option( 'woocommerce_qersh_settings', array() );
		if ( ! is_array( $settings ) || ! isset( $settings['enabled'] ) || 'yes' !== $settings['enabled'] ) {
			return;
		}
		self::register_assets();
		wp_enqueue_script( self::H_PAYER );
		wp_enqueue_style( self::H_STYLE );
	}

	/**
	 * The thank-you box (classic template and the Blocks order confirmation, both through woocommerce_thankyou_qersh,
	 * which WooCommerce fires only for someone allowed to see the order).
	 *
	 * @param int $order_id Order id.
	 */
	public static function thankyou( $order_id ) {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( absint( $order_id ) ) : null;
		if ( ! $order instanceof \WC_Order || self::METHOD !== $order->get_payment_method() ) {
			return;
		}
		if ( isset( self::$printed[ $order->get_id() ] ) ) {
			return;
		}
		self::$printed[ $order->get_id() ] = true;

		$plugin = Plugin::instance();
		$now    = time();
		$box    = self::box( $plugin, $order, $now );
		if ( null === $box ) {
			return;
		}
		self::register_assets();
		wp_enqueue_style( self::H_STYLE );
		wp_enqueue_script( self::H_THANKYOU );

		wc_get_template(
			'order/thankyou-box.php',
			array(
				'order'  => $order,
				'box'    => $box,
				'script' => self::thankyou_script_data( $plugin, $order, $box, $now ),
			),
			'qersh-for-woocommerce/',
			QERSH_WC_DIR . 'templates/'
		);
	}

	/**
	 * What the thank-you box and the instructions email show for an order, or null when the order has none of our
	 * data (no row and no chosen wallet).
	 *
	 * @param Plugin    $plugin The plugin.
	 * @param \WC_Order $order  Order.
	 * @param int       $now    UTC seconds.
	 * @return array|null
	 */
	public static function box( Plugin $plugin, \WC_Order $order, int $now ): ?array {
		$row       = self::order_row( $plugin, $order->get_id() );
		$wallet_id = self::order_wallet_id( $order, $row );
		if ( null === $row && '' === $wallet_id ) {
			return null;
		}
		$config = $plugin->config();
		$store  = isset( $config['store'] ) && is_array( $config['store'] ) ? $config['store'] : array();
		$late   = isset( $config['receiver']['matching']['late_window_min'] ) ? (int) $config['receiver']['matching']['late_window_min'] : 120;
		$wallet = '' === $wallet_id ? null : $plugin->wallet( $wallet_id );
		$seen   = self::auto_seen( $plugin );

		$provider = null === $wallet ? '' : (string) $wallet['provider'];
		$txn_open = self::txn_entry_open( $plugin, $wallet );
		$kind     = '' === $provider ? 'decision' : self::confirm_kind( $provider, ! empty( $seen[ $wallet_id ] ), $txn_open );

		$price  = null !== $row && isset( $row['price_minor'] ) ? (int) $row['price_minor'] : (int) round( (float) $order->get_total() * 100 );
		$paid   = null !== $row && isset( $row['paid_minor'] ) ? (int) $row['paid_minor'] : 0;
		$amount = self::amounts( $price, $paid, ! empty( $store['round_up_to_pound'] ) );

		$payer = null !== $row && isset( $row['payer_key'] ) ? (string) $row['payer_key'] : (string) $order->get_meta( '_qersh_payer_key' );

		$deadline = null !== $row && isset( $row['expires_at'] ) ? (int) $row['expires_at'] : (int) $order->get_meta( '_qersh_deadline' );
		$placed   = null !== $row && isset( $row['created_at'] ) ? (int) $row['created_at'] : (int) $order->get_meta( '_qersh_placed_at' );
		if ( $placed <= 0 && $order->get_date_created() ) {
			$placed = (int) $order->get_date_created()->getTimestamp();
		}

		$state  = null !== $row && isset( $row['state'] ) ? (string) $row['state'] : null;
		$status = self::status_of( $state, (string) $order->get_status() );
		$claim  = null !== $row && ! empty( $row['claim_txn'] );

		$txn_now = $txn_open && self::txn_accepted( $order, $row, $now );

		return array(
			'status'        => $status,
			'state'         => $state,
			'wallet'        => $wallet,
			'wallet_id'     => $wallet_id,
			'provider'      => $provider,
			'provider_name' => self::provider_name( $provider ),
			'wallet_label'  => null === $wallet ? '' : (string) $wallet['label'],
			'address'       => null === $wallet ? '' : (string) $wallet['receive_address'],
			'payer'         => $payer,
			'payer_handle'  => false !== strpos( $payer, '@' ),
			'due_minor'     => $amount['due'],
			'exact_minor'   => $amount['exact'],
			'rounded'       => $amount['due'] !== $amount['exact'],
			'paid_minor'    => $paid,
			'currency'      => (string) $order->get_currency(),
			'deadline'      => $deadline > 0 ? $deadline : null,
			'placed_at'     => $placed > 0 ? $placed : null,
			'stop_at'       => $deadline > 0 ? $deadline + 60 * max( 0, $late ) : null,
			'kind'          => $kind,
			'confirm'       => self::confirm_text( $kind ),
			'decision'      => 'decision' === $kind,
			'orange'        => 'orange_cash' === $provider,
			'txn_open'      => $txn_now,
			'claim'         => $claim,
			'pending'       => in_array( $status, array( 'waiting', 'partial' ), true ),
			'instructions'  => self::merchant_instructions(),
		);
	}

	/**
	 * Whether the route would take a transaction id for this order now (step 4 of docs/17 §4.10), by the route's own
	 * rule, Claims::accepts(): pending with its row open or partial, or cancelled by our expiry less than 72 hours
	 * ago. Without a row the route answers 404, so the field is not shown either.
	 *
	 * @param \WC_Order  $order Order.
	 * @param array|null $row   Its qersh_orders row, or null.
	 * @param int        $now   UTC seconds.
	 * @return bool
	 */
	public static function txn_accepted( \WC_Order $order, ?array $row, int $now ): bool {
		if ( null === $row || ! class_exists( Claims::class ) || ! method_exists( Claims::class, 'accepts' ) ) {
			return false;
		}
		try {
			return (bool) Claims::accepts( $order, $row, $now );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * The customer-facing status of an order: from its qersh_orders state (`overpaid` never is a state; `closed`
	 * shows `cancelled`), or, without a row, from the WooCommerce status. Pure.
	 *
	 * @param string|null $state     qersh_orders.state, or null without a row.
	 * @param string      $wc_status WooCommerce status without `wc-`.
	 * @return string waiting|partial|paid|review|expired|cancelled
	 */
	public static function status_of( ?string $state, string $wc_status ): string {
		$map = array(
			'open'     => 'waiting',
			'partial'  => 'partial',
			'paid'     => 'paid',
			'overpaid' => 'paid',
			'review'   => 'review',
			'expired'  => 'expired',
			'closed'   => 'cancelled',
		);
		if ( null !== $state && isset( $map[ $state ] ) ) {
			return $map[ $state ];
		}
		if ( in_array( $wc_status, array( 'processing', 'completed' ), true ) ) {
			return 'paid';
		}
		if ( in_array( $wc_status, array( 'cancelled', 'refunded', 'failed', 'trash' ), true ) ) {
			return 'cancelled';
		}
		if ( 'on-hold' === $wc_status ) {
			return 'review';
		}
		return 'waiting';
	}

	/**
	 * The amount to transfer: what remains, rounded up to the pound when the store allows it (a *9# transfer
	 * carries no piasters, docs/17 §4.6). Pure.
	 *
	 * @param int  $price_minor Order total.
	 * @param int  $paid_minor  Received so far.
	 * @param bool $round       store.round_up_to_pound.
	 * @return array{exact: int, due: int}
	 */
	public static function amounts( int $price_minor, int $paid_minor, bool $round ): array {
		$exact = max( 0, $price_minor - max( 0, $paid_minor ) );
		return array(
			'exact' => $exact,
			'due'   => $round ? Money::round_up_pound( $exact ) : $exact,
		);
	}

	/**
	 * Whether a total (piasters) lies within store.min_total_minor and store.max_total_minor (each may be null).
	 * Pure; Gateway::is_available() and checkout-blocks.js apply the same rule.
	 *
	 * @param int      $total_minor Total.
	 * @param int|null $min         Minimum, or null.
	 * @param int|null $max         Maximum, or null.
	 * @return bool
	 */
	public static function total_allowed( int $total_minor, ?int $min, ?int $max ): bool {
		if ( null !== $min && $total_minor < $min ) {
			return false;
		}
		if ( null !== $max && $total_minor > $max ) {
			return false;
		}
		return true;
	}

	/**
	 * An integer value of the store settings, or null. Pure.
	 *
	 * @param array  $store Store settings.
	 * @param string $key   Key.
	 * @return int|null
	 */
	public static function int_or_null( array $store, string $key ): ?int {
		return isset( $store[ $key ] ) && is_numeric( $store[ $key ] ) ? (int) $store[ $key ] : null;
	}

	/**
	 * Which confirmation line a wallet gets (docs/17 §3). Pure.
	 *
	 * @param string $provider  Provider id.
	 * @param bool   $auto_seen Whether this wallet has seen a transfer with auto_confirm_ok (Store\Wallets).
	 * @param bool   $txn_open  Whether customers may enter a transaction id for it.
	 * @return string `auto`, `orange_auto`, `orange` or `decision`.
	 */
	public static function confirm_kind( string $provider, bool $auto_seen, bool $txn_open ): string {
		if ( in_array( $provider, self::AUTO_PROVIDERS, true ) ) {
			return 'auto';
		}
		if ( 'orange_cash' === $provider && $txn_open ) {
			return $auto_seen ? 'orange_auto' : 'orange';
		}
		return 'decision';
	}

	/**
	 * The confirmation line printed next to a wallet, in the thank-you box and in the email (not editable).
	 *
	 * @param string $kind From confirm_kind().
	 * @return string
	 */
	public static function confirm_text( string $kind ): string {
		switch ( $kind ) {
			case 'auto':
				return __( 'Your order is confirmed on its own when your transfer arrives, usually within minutes.', 'qersh-for-woocommerce' );
			case 'orange_auto':
				return __( 'After you transfer, enter the transaction id from your transfer message on your order page, and your order is usually confirmed on its own within minutes.', 'qersh-for-woocommerce' );
			case 'orange':
				return __( 'After you transfer, enter the transaction id on your order page so the store finds your transfer faster. The store confirms your order once it checks that your transfer arrived.', 'qersh-for-woocommerce' );
			default:
				return __( 'The store confirms your order once it checks that your transfer arrived.', 'qersh-for-woocommerce' );
		}
	}

	/**
	 * The customer-facing name of a wallet type.
	 *
	 * @param string $provider Provider id.
	 * @return string
	 */
	public static function provider_name( string $provider ): string {
		switch ( $provider ) {
			case 'vodafone_cash':
				return __( 'Vodafone Cash', 'qersh-for-woocommerce' );
			case 'etisalat_cash':
				return __( 'e& money', 'qersh-for-woocommerce' );
			case 'orange_cash':
				return __( 'Orange Cash', 'qersh-for-woocommerce' );
			case 'instapay_app':
				return __( 'InstaPay', 'qersh-for-woocommerce' );
			case 'bank_nbe':
				return __( 'National Bank of Egypt', 'qersh-for-woocommerce' );
			case 'bank_cib':
				return __( 'CIB', 'qersh-for-woocommerce' );
			case 'bank_egbank':
				return __( 'EGBANK', 'qersh-for-woocommerce' );
			default:
				return '';
		}
	}

	/**
	 * The enabled wallets as the checkout shows them, in configuration order.
	 *
	 * @param Plugin $plugin The plugin.
	 * @return array[] Each `[ id, label, provider, provider_name, address, address_display, instapay, kind,
	 *                 confirm, decision ]`.
	 */
	public static function wallet_views( Plugin $plugin ): array {
		$config = $plugin->config();
		$seen   = self::auto_seen( $plugin );
		$out    = array();
		foreach ( PayerField::enabled_wallets( isset( $config['wallets'] ) && is_array( $config['wallets'] ) ? $config['wallets'] : array() ) as $wallet ) {
			$provider = isset( $wallet['provider'] ) ? (string) $wallet['provider'] : '';
			$kind     = self::confirm_kind( $provider, ! empty( $seen[ $wallet['id'] ] ), self::txn_entry_open( $plugin, $wallet ) );
			$address  = isset( $wallet['receive_address'] ) ? (string) $wallet['receive_address'] : '';
			$out[]    = array(
				'id'              => (string) $wallet['id'],
				'label'           => isset( $wallet['label'] ) ? (string) $wallet['label'] : '',
				'provider'        => $provider,
				'provider_name'   => self::provider_name( $provider ),
				'address'         => $address,
				'address_display' => Format::number( $address ),
				'instapay'        => 'instapay_app' === $provider,
				'kind'            => $kind,
				'confirm'         => self::confirm_text( $kind ),
				'decision'        => 'auto' !== $kind,
			);
		}
		return $out;
	}

	/**
	 * Whether customers may enter a transaction id for this wallet: an Orange Cash wallet of the configuration
	 * (txn_wallet()), store.txn_entry on, and the field not hidden by the transaction-id drift alert (docs/17 §4.9).
	 *
	 * @param Plugin     $plugin The plugin.
	 * @param array|null $wallet Wallet entry.
	 * @return bool
	 */
	public static function txn_entry_open( Plugin $plugin, ?array $wallet ): bool {
		if ( ! self::txn_wallet( $wallet ) ) {
			return false;
		}
		$config = $plugin->config();
		if ( empty( $config['store']['txn_entry'] ) ) {
			return false;
		}
		if ( class_exists( Diagnostics::class ) ) {
			try {
				$diagnostics = $plugin->diagnostics();
				if ( method_exists( $diagnostics, 'txn_entry_open' ) ) {
					return (bool) $diagnostics->txn_entry_open( (string) $wallet['provider'] );
				}
			} catch ( \Throwable $e ) {
				return true;
			}
		}
		return true;
	}

	/**
	 * Whether a wallet entry takes transaction ids: an Orange Cash entry (TxnId::CLAIM_PROVIDERS). Enabled or not:
	 * turning a wallet off only hides it from the checkout (docs/17 §5 step 10), so the orders already waiting on it
	 * keep their field. A deleted wallet has no entry in the configuration, so it never gets here. Pure.
	 *
	 * @param array|null $wallet Wallet entry (Plugin::wallet()), or null.
	 * @return bool
	 */
	public static function txn_wallet( ?array $wallet ): bool {
		if ( null === $wallet || ! isset( $wallet['provider'], $wallet['id'] ) ) {
			return false;
		}
		return 'orange_cash' === $wallet['provider'] && in_array( $wallet['provider'], TxnId::CLAIM_PROVIDERS, true );
	}

	/**
	 * Which wallets have seen a transfer with auto_confirm_ok (qersh_wallets.auto_seen), for the Orange line.
	 *
	 * @param Plugin $plugin The plugin.
	 * @return array<string, bool>
	 */
	public static function auto_seen( Plugin $plugin ): array {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		$cache = array();
		if ( ! $plugin->tables_ready() ) {
			return $cache;
		}
		try {
			foreach ( $plugin->wallets()->all() as $id => $row ) {
				$cache[ (string) $id ] = ! empty( $row['auto_seen'] );
			}
		} catch ( \Throwable $e ) {
			$cache = array();
		}
		return $cache;
	}

	/**
	 * The qersh_orders row of an order, or null (no row, or the table cannot be read).
	 *
	 * @param Plugin $plugin   The plugin.
	 * @param int    $order_id Order id.
	 * @return array|null
	 */
	public static function order_row( Plugin $plugin, int $order_id ): ?array {
		if ( $order_id <= 0 || ! $plugin->tables_ready() ) {
			return null;
		}
		try {
			return $plugin->orders_index()->get( $order_id );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * The wallet the customer chose: the row's, else the order meta.
	 *
	 * @param \WC_Order  $order Order.
	 * @param array|null $row   Row.
	 * @return string
	 */
	public static function order_wallet_id( \WC_Order $order, ?array $row ): string {
		if ( null !== $row && isset( $row['wallet'] ) && '' !== (string) $row['wallet'] ) {
			return (string) $row['wallet'];
		}
		return (string) $order->get_meta( '_qersh_wallet' );
	}

	/**
	 * The merchant's "Instructions" text of the method settings.
	 *
	 * @return string
	 */
	public static function merchant_instructions(): string {
		$settings = get_option( 'woocommerce_qersh_settings', array() );
		return is_array( $settings ) && isset( $settings['instructions'] ) && is_string( $settings['instructions'] ) ? $settings['instructions'] : '';
	}

	/**
	 * The page where the customer enters the transaction id (the order-received page, which carries the order key).
	 *
	 * @param \WC_Order $order Order.
	 * @return string
	 */
	public static function txn_page_url( \WC_Order $order ): string {
		return $order->get_checkout_order_received_url() . '#qersh-txn';
	}

	/**
	 * The status lines of the thank-you box, by key (docs/17 §6.2, §4.10).
	 *
	 * @return array<string, string>
	 */
	public static function status_lines(): array {
		return array(
			'waiting'          => __( 'Waiting for your transfer…', 'qersh-for-woocommerce' ),
			'waiting_decision' => __( 'Waiting for the store to confirm that your transfer arrived…', 'qersh-for-woocommerce' ),
			'saved'            => __( 'We have your transaction id. Your order is confirmed once the store matches your transfer; do not transfer again.', 'qersh-for-woocommerce' ),
			'paid'             => __( 'Your transfer arrived and your order is confirmed.', 'qersh-for-woocommerce' ),
			'partial'          => __( 'Part of the amount arrived. Send the rest from the same number before the deadline.', 'qersh-for-woocommerce' ),
			'review'           => __( 'We are checking your transfer and will let you know.', 'qersh-for-woocommerce' ),
			'expired'          => __( 'The transfer window has ended.', 'qersh-for-woocommerce' ),
			'cancelled'        => __( 'This order was cancelled.', 'qersh-for-woocommerce' ),
		);
	}

	/**
	 * Which status line to show. Pure.
	 *
	 * @param string $status   Customer-facing status.
	 * @param bool   $decision Whether the wallet needs the store's decision.
	 * @param bool   $claim    Whether a transaction id is saved.
	 * @return string Key of status_lines().
	 */
	public static function status_line_key( string $status, bool $decision, bool $claim ): string {
		if ( 'waiting' === $status ) {
			if ( $claim ) {
				return 'saved';
			}
			return $decision ? 'waiting_decision' : 'waiting';
		}
		return in_array( $status, array( 'partial', 'paid', 'review', 'expired', 'cancelled' ), true ) ? $status : 'waiting';
	}

	/**
	 * Which line follows the status after 15 minutes of waiting (docs/17 §4.10, §6.2): "Transferred from another
	 * number?", replaced for an Orange Cash order by the transaction-id reminder only while the field is there and
	 * no id was entered (the reminder points at the field "above"). thankyou.js applies the same rule. Pure.
	 *
	 * @param string $status   Customer-facing status.
	 * @param bool   $due      Whether the 15 minutes have passed.
	 * @param bool   $orange   Whether the order's wallet is Orange Cash.
	 * @param bool   $txn_open Whether the transaction-id field is shown for the order.
	 * @param bool   $claim    Whether a transaction id is saved.
	 * @return string `nudge_other`, `nudge_txn`, or '' for none.
	 */
	public static function nudge_key( string $status, bool $due, bool $orange, bool $txn_open, bool $claim ): string {
		if ( 'waiting' !== $status || ! $due ) {
			return '';
		}
		if ( $orange && $txn_open ) {
			return $claim ? '' : 'nudge_txn';
		}
		return 'nudge_other';
	}

	/**
	 * What thankyou.js needs (printed as JSON in a data attribute).
	 *
	 * @param Plugin    $plugin The plugin.
	 * @param \WC_Order $order  Order.
	 * @param array     $box    From box().
	 * @param int       $now    UTC seconds.
	 * @return array
	 */
	public static function thankyou_script_data( Plugin $plugin, \WC_Order $order, array $box, int $now ): array {
		$config  = $plugin->config();
		$wallets = isset( $config['wallets'] ) && is_array( $config['wallets'] ) ? $config['wallets'] : array();
		$placed  = null === $box['placed_at'] ? $now : (int) $box['placed_at'];
		$stop    = null === $box['stop_at'] ? $now + self::POLL_FAST_UNTIL : (int) $box['stop_at'];
		$fallback = home_url( '/' );
		return array(
			'order_id'  => $order->get_id(),
			'key'       => $order->get_order_key(),
			'now'       => $now,
			'placed_at' => $placed,
			'deadline'  => $box['deadline'],
			'stop_at'   => $stop,
			'fast'      => self::POLL_FAST_SEC,
			'slow'      => self::POLL_SLOW_SEC,
			'fast_for'  => self::POLL_FAST_UNTIL,
			'nudge_at'  => $placed + self::NUDGE_SEC,
			'status'    => $box['status'],
			'decision'  => $box['decision'],
			'orange'    => $box['orange'],
			'txn_open'  => $box['txn_open'],
			'claim'     => $box['claim'],
			'urls'      => array(
				'status'          => rest_url( 'qersh/v1/order-status' ),
				'txn'             => rest_url( 'qersh/v1/order-txn' ),
				'status_fallback' => add_query_arg(
					array(
						'wc-api' => 'qersh',
						'qo'     => 'status',
					),
					$fallback
				),
				'txn_fallback'    => add_query_arg(
					array(
						'wc-api' => 'qersh',
						'qo'     => 'txn',
					),
					$fallback
				),
			),
			'txn'       => array(
				'provider'      => $box['provider'],
				'payer_key'     => $box['payer'],
				'store_numbers' => PayerField::browser_numbers( $wallets, (string) $box['wallet_id'] ),
				'shapes'        => self::txn_shapes(),
			),
			'lines'     => self::status_lines(),
			'strings'   => array(
				'copy'          => __( 'Copy', 'qersh-for-woocommerce' ),
				'copied'        => __( 'Copied', 'qersh-for-woocommerce' ),
				'sending'       => __( 'Sending…', 'qersh-for-woocommerce' ),
				'nudge_other'   => __( 'Transferred from another number? Contact the store with your order number so it can attach your transfer.', 'qersh-for-woocommerce' ),
				'nudge_txn'     => __( 'Transferred? Enter the transaction id above so the store finds your transfer.', 'qersh-for-woocommerce' ),
				'txn_errors'    => self::txn_error_texts( $order ),
				'txn_saved'     => self::status_lines()['saved'],
			),
		);
	}

	/**
	 * The transaction-id shapes, from Core\TxnId (so the script never drifts from the server).
	 *
	 * @return array
	 */
	public static function txn_shapes(): array {
		$out = array();
		foreach ( TxnId::PATTERNS as $provider => $pattern ) {
			$out[ $provider ] = array(
				'pattern' => $pattern,
				'lengths' => TxnId::LENGTHS[ $provider ],
				'cls'     => TxnId::CLASSES[ $provider ],
			);
		}
		return $out;
	}

	/**
	 * The messages of the transaction-id field, by route code (docs/17 §7).
	 *
	 * @param \WC_Order $order Order.
	 * @return array<string, string>
	 */
	public static function txn_error_texts( \WC_Order $order ): array {
		$format = __( 'This does not look like an Orange Cash transaction id. Check it in your message.', 'qersh-for-woocommerce' );
		return array(
			'qersh_txn_empty'         => __( 'Enter the transaction id.', 'qersh-for-woocommerce' ),
			'qersh_txn_format'        => $format,
			'qersh_txn_too_long'      => $format,
			'qersh_txn_several'       => __( 'We found more than one possible number in what you pasted. Enter the transaction id only.', 'qersh-for-woocommerce' ),
			'qersh_txn_wallet_number' => __( 'This is a wallet number, not a transaction id.', 'qersh-for-woocommerce' ),
			'qersh_txn_wait'          => __( 'You have changed it several times. Try again in 10 minutes.', 'qersh-for-woocommerce' ),
			/* translators: %s: order number. */
			'qersh_txn_locked'        => sprintf( __( 'The transaction id cannot be changed again. Contact the store with your order number #%s.', 'qersh-for-woocommerce' ), $order->get_order_number() ),
			'qersh_txn_closed'        => __( 'This order is no longer waiting for a transfer.', 'qersh-for-woocommerce' ),
			'other'                   => __( 'We could not save the transaction id. Try again.', 'qersh-for-woocommerce' ),
		);
	}

	/**
	 * In WooCommerce's "Processing order" email of one of our orders: which transfer confirmed it (docs/17 §6.3).
	 *
	 * @param \WC_Order $order         Order.
	 * @param bool      $sent_to_admin Whether the email goes to the admin.
	 * @param bool      $plain_text    Plain-text email.
	 * @param object    $email         WC_Email.
	 */
	public static function email_confirmed_line( $order, $sent_to_admin = false, $plain_text = false, $email = null ) {
		if ( $sent_to_admin || ! $order instanceof \WC_Order || self::METHOD !== $order->get_payment_method() ) {
			return;
		}
		if ( ! is_object( $email ) || ! isset( $email->id ) || 'customer_processing_order' !== $email->id ) {
			return;
		}
		$line = self::confirmed_line( Plugin::instance(), $order, $plain_text ? Format::PLAIN : Format::EMAIL_HTML );
		if ( '' === $line ) {
			return;
		}
		if ( $plain_text ) {
			echo esc_html( wp_strip_all_tags( $line ) ) . "\n\n";
			return;
		}
		echo '<p>' . wp_kses( $line, Format::allowed_html() ) . '</p>';
	}

	/**
	 * "Your transfer from 0111***445 (transaction id ...) arrived and your order is confirmed." (each value isolated)
	 * from the transfer that paid the order, or '' when there is none to name.
	 *
	 * @param Plugin    $plugin  The plugin.
	 * @param \WC_Order $order   Order.
	 * @param string    $context Format context.
	 * @return string
	 */
	public static function confirmed_line( Plugin $plugin, \WC_Order $order, string $context ): string {
		if ( ! $plugin->tables_ready() ) {
			return '';
		}
		try {
			$page = $plugin->transfers()->list_page(
				array(
					'order_id' => $order->get_id(),
					'view'     => 'all',
				),
				1,
				10
			);
		} catch ( \Throwable $e ) {
			return '';
		}
		$transfer = null;
		foreach ( $page['rows'] as $row ) {
			if ( in_array( (string) $row['outcome'], array( 'matched', 'overpaid', 'attached' ), true ) ) {
				$transfer = $row;
				break;
			}
		}
		if ( null === $transfer ) {
			return '';
		}
		$txn   = isset( $transfer['provider_txn_id'] ) ? (string) $transfer['provider_txn_id'] : '';
		$payer = isset( $transfer['payer_key'] ) ? (string) $transfer['payer_key'] : '';
		$claim = (string) $order->get_meta( '_qersh_claim_txn' );
		if ( '' !== $txn && '' === $payer && '' !== $claim && $claim === $txn ) {
			/* translators: %s: transaction id. */
			return sprintf( esc_html__( 'Your transfer (transaction id %s) arrived and your order is confirmed.', 'qersh-for-woocommerce' ), Format::ltr( $txn, $context ) );
		}
		if ( '' === $payer ) {
			return '';
		}
		if ( '' !== $txn ) {
			/* translators: 1: masked wallet number, 2: transaction id. */
			return sprintf( esc_html__( 'Your transfer from %1$s (transaction id %2$s) arrived and your order is confirmed.', 'qersh-for-woocommerce' ), Format::ltr( Format::masked( $payer ), $context ), Format::ltr( $txn, $context ) );
		}
		/* translators: %s: masked wallet number. */
		return sprintf( esc_html__( 'Your transfer from %s arrived and your order is confirmed.', 'qersh-for-woocommerce' ), Format::ltr( Format::masked( $payer ), $context ) );
	}
}
