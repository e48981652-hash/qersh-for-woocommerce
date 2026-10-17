<?php
/**
 * The plugin's entry point and service container.
 *
 * @package QershPay\WooCommerce
 */

namespace QershPay\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Boots the plugin and holds its services. Each service is built lazily as new X( $plugin ).
 */
final class Plugin {

	/**
	 * The one instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Built services, by class name.
	 *
	 * @var array<string, object>
	 */
	private $services = array();

	/**
	 * The effective configuration, cached for the request.
	 *
	 * @var array|null
	 */
	private $config = null;

	/**
	 * Returns the singleton.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::instance_or_null() ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * The instance if it exists.
	 *
	 * @return Plugin|null
	 */
	private static function instance_or_null() {
		return self::$instance;
	}

	/**
	 * Runs on plugins_loaded: checks the schema, then lets each module register its hooks.
	 */
	public static function boot() {
		$plugin   = self::instance();
		$wc_ready = $plugin->wc_ready();

		if ( class_exists( Store\Schema::class ) ) {
			Store\Schema::maybe_upgrade();
		}

		$modules = array( Checkout::class, Webhook\Module::class, Admin\Module::class );
		foreach ( $modules as $module ) {
			if ( class_exists( $module ) ) {
				$module::register( $plugin, $wc_ready );
			}
		}
	}

	/**
	 * Activation: create or upgrade the tables. WooCommerce may not be active yet; the tables do not need it.
	 */
	public static function activate() {
		if ( class_exists( Store\Schema::class ) ) {
			Store\Schema::install();
		}
	}

	/**
	 * Declares HPOS and Checkout Blocks compatibility, with the main plugin file (never a file inside src/).
	 */
	public static function declare_compatibility() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', QERSH_WC_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', QERSH_WC_FILE, true );
		}
	}

	/**
	 * Adds email deferral for our webhook requests when the host cannot finish the response early. Never removes a
	 * deferral someone else switched on. WooCommerce reads this once, at init (docs/17 §4.7).
	 *
	 * @param bool $defer Current value.
	 * @return bool
	 */
	public static function filter_defer_emails( $defer ) {
		return (bool) $defer || ( self::is_webhook_request() && ! self::finish_available() );
	}

	/**
	 * True when this host can send the response and keep running (PHP-FPM or LiteSpeed).
	 *
	 * @return bool
	 */
	public static function finish_available() {
		return function_exists( 'fastcgi_finish_request' ) || function_exists( 'litespeed_finish_request' );
	}

	/**
	 * True for a POST to our webhook in any of its three address forms. Reads the raw request because neither
	 * REST_REQUEST nor rest_get_url_prefix() is known before parse_request.
	 *
	 * @return bool
	 */
	public static function is_webhook_request() {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
		if ( 'POST' !== $method ) {
			return false;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- signed webhook, HMAC-authenticated later.
		$uri        = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only searched, never output.
		$rest_route = isset( $_GET['rest_route'] ) ? (string) wp_unslash( $_GET['rest_route'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only searched, never output.
		$wc_api     = isset( $_GET['wc-api'] ) ? sanitize_key( wp_unslash( $_GET['wc-api'] ) ) : '';
		$has_wallet = isset( $_GET['qw'] );
		// phpcs:enable
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		if ( false !== strpos( $path, '/qersh/v1/webhook/' ) || false !== strpos( $rest_route, '/qersh/v1/webhook/' ) ) {
			return true;
		}
		if ( 'qersh' === $wc_api && $has_wallet ) {
			return true;
		}
		return false !== strpos( $path, '/wc-api/qersh/' );
	}

	/**
	 * True when a WooCommerce at or above the minimum version is active.
	 *
	 * @return bool
	 */
	public function wc_ready() {
		return defined( 'WC_VERSION' ) && version_compare( WC_VERSION, QERSH_WC_MIN_WC, '>=' ) && function_exists( 'wc_get_order' );
	}

	/**
	 * True when our tables are at the current schema version.
	 *
	 * @return bool
	 */
	public function tables_ready() {
		return (int) get_option( 'qersh_wc_db_version', 0 ) >= QERSH_WC_DB_VERSION;
	}

	/**
	 * The JSON answer of every route while WooCommerce or the tables are missing (docs/17 §4.5: 503).
	 *
	 * @return \WP_REST_Response
	 */
	public static function unavailable_response() {
		$response = new \WP_REST_Response( array( 'code' => 'qersh_unavailable' ), 503 );
		$response->header( 'Cache-Control', 'no-store, private' );
		return $response;
	}

	/**
	 * The effective configuration (with defaults), cached for this request.
	 *
	 * @return array
	 */
	public function config() {
		if ( null === $this->config ) {
			$this->config = $this->config_store()->get();
		}
		return $this->config;
	}

	/**
	 * Forgets the cached configuration (after a save).
	 */
	public function forget_config() {
		$this->config = null;
	}

	/**
	 * One wallet entry of the configuration.
	 *
	 * @param string $id Wallet id.
	 * @return array|null
	 */
	public function wallet( $id ) {
		$config = $this->config();
		foreach ( isset( $config['wallets'] ) ? $config['wallets'] : array() as $wallet ) {
			if ( isset( $wallet['id'] ) && $wallet['id'] === $id ) {
				return $wallet;
			}
		}
		return null;
	}

	/**
	 * Returns a service, building it on first use.
	 *
	 * @param string $class_name Class name.
	 * @return object
	 */
	private function service( $class_name ) {
		if ( ! isset( $this->services[ $class_name ] ) ) {
			$this->services[ $class_name ] = new $class_name( $this );
		}
		return $this->services[ $class_name ];
	}

	/** @return ConfigStore */
	public function config_store() {
		return $this->service( ConfigStore::class );
	}

	/** @return Store\Db */
	public function db() {
		return $this->service( Store\Db::class );
	}

	/** @return Store\Deliveries */
	public function deliveries() {
		return $this->service( Store\Deliveries::class );
	}

	/** @return Store\Transfers */
	public function transfers() {
		return $this->service( Store\Transfers::class );
	}

	/** @return Store\OrdersIndex */
	public function orders_index() {
		return $this->service( Store\OrdersIndex::class );
	}

	/** @return Store\Wallets */
	public function wallets() {
		return $this->service( Store\Wallets::class );
	}

	/** @return Secrets */
	public function secrets() {
		return $this->service( Secrets::class );
	}

	/** @return Logger */
	public function logger() {
		return $this->service( Logger::class );
	}

	/** @return MatchPolicy */
	public function policy() {
		return $this->service( MatchPolicy::class );
	}

	/** @return Orders */
	public function orders() {
		return $this->service( Orders::class );
	}

	/** @return Claims */
	public function claims() {
		return $this->service( Claims::class );
	}

	/** @return Completion */
	public function completion() {
		return $this->service( Completion::class );
	}

	/** @return Expiry */
	public function expiry() {
		return $this->service( Expiry::class );
	}

	/** @return Diagnostics */
	public function diagnostics() {
		return $this->service( Diagnostics::class );
	}
}
