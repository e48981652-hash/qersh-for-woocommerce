<?php
/**
 * Entry point of package D: the webhook route, the Action Scheduler handlers and the order hooks (docs-dev
 * CONTRACTS.md §3, §9).
 *
 * @package QershPay\WooCommerce
 */

namespace QershPay\WooCommerce\Webhook;

use QershPay\WooCommerce\Claims;
use QershPay\WooCommerce\Expiry;
use QershPay\WooCommerce\Orders;
use QershPay\WooCommerce\Plugin;
use QershPay\WooCommerce\Store\Locks;

defined( 'ABSPATH' ) || exit;

/**
 * Registers, from Plugin::boot():
 * - always: the REST route `POST qersh/v1/webhook/<wallet>` (hidden from the index; it answers 503 while
 *   WooCommerce or the tables are missing), the 64 KB size check, the JSON options and no-cache headers of our
 *   routes, and the handlers of the Action Scheduler hooks;
 * - with WooCommerce: the two recurring actions when they are missing, and the order hooks of Orders and Expiry.
 */
final class Module {

	/**
	 * REST namespace of the plugin.
	 */
	const REST_NAMESPACE = 'qersh/v1';

	/**
	 * The webhook route, relative to the namespace.
	 */
	const ROUTE = '/webhook/(?P<wallet>[a-z0-9]{12})';

	/**
	 * Start of the webhook route as WP_REST_Request::get_route() gives it.
	 */
	const ROUTE_PREFIX = '/qersh/v1/webhook/';

	/**
	 * Start of every route of the plugin.
	 */
	const NAMESPACE_PREFIX = '/qersh/v1/';

	/**
	 * A webhook body above this many bytes is refused with 413 before any work (docs/17 §4.4 step 2).
	 */
	const MAX_BODY = 65536;

	/**
	 * Action Scheduler group of the plugin.
	 */
	const GROUP = 'qersh';

	/**
	 * The recurring actions and their intervals in seconds.
	 */
	const RECURRING = array(
		'qersh_wc_sweep' => 900,
		'qersh_wc_daily' => 86400,
	);

	/**
	 * Lock rows unused for this long are deleted by the daily cleanup.
	 */
	const LOCK_KEEP_SEC = 86400;

	/**
	 * True once registered (boot runs once, but stay safe).
	 *
	 * @var bool
	 */
	private static $registered = false;

	/**
	 * Registers the module's hooks.
	 *
	 * @param Plugin $plugin   The plugin.
	 * @param bool   $wc_ready True when a supported WooCommerce is active.
	 */
	public static function register( Plugin $plugin, bool $wc_ready ) {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'size_check' ), 10, 3 );
		add_filter( 'rest_json_encode_options', array( __CLASS__, 'json_options' ), 10, 2 );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'nocache' ), 10, 3 );

		add_action( 'qersh_wc_complete', array( __CLASS__, 'on_complete' ) );
		add_action( 'qersh_wc_expire', array( __CLASS__, 'on_expire' ) );
		add_action( 'qersh_wc_claim_eval', array( __CLASS__, 'on_claim_eval' ) );
		add_action( 'qersh_wc_sweep', array( __CLASS__, 'on_sweep' ) );
		add_action( 'qersh_wc_daily', array( __CLASS__, 'on_daily' ) );

		if ( ! $wc_ready ) {
			return;
		}
		if ( did_action( 'action_scheduler_init' ) ) {
			self::schedule_recurring();
		} else {
			add_action( 'action_scheduler_init', array( __CLASS__, 'schedule_recurring' ) );
		}
		foreach ( array( Orders::class, Expiry::class ) as $class ) {
			if ( class_exists( $class ) && method_exists( $class, 'register_hooks' ) ) {
				$class::register_hooks( $plugin );
			}
		}
	}

	/**
	 * rest_api_init: the webhook route, POST only and hidden from the REST index.
	 */
	public static function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( Controller::class, 'rest_handle' ),
				'permission_callback' => array( Controller::class, 'rest_permission' ),
				'show_in_index'       => false,
				'args'                => array(),
			)
		);
	}

	/**
	 * rest_pre_dispatch: a webhook body above 64 KB is answered 413 before any other work.
	 *
	 * @param mixed            $result  Earlier result, null by default.
	 * @param \WP_REST_Server  $server  Server.
	 * @param \WP_REST_Request $request Request.
	 * @return mixed
	 */
	public static function size_check( $result, $server, $request ) {
		unset( $server );
		if ( null !== $result || ! $request instanceof \WP_REST_Request || ! self::is_webhook_route( (string) $request->get_route() ) ) {
			return $result;
		}
		if ( self::too_large( strlen( (string) $request->get_body() ) ) ) {
			return new \WP_Error( 'qersh_too_large', 'The body is larger than 64 KB.', array( 'status' => 413 ) );
		}
		return $result;
	}

	/**
	 * rest_json_encode_options: Arabic and slashes as they are, on our routes only.
	 *
	 * @param int              $options JSON options.
	 * @param \WP_REST_Request $request Request.
	 * @return int
	 */
	public static function json_options( $options, $request ) {
		if ( $request instanceof \WP_REST_Request && self::is_our_route( (string) $request->get_route() ) ) {
			$options = (int) $options | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
		}
		return $options;
	}

	/**
	 * rest_post_dispatch: no-cache headers on every answer of the webhook route, errors included.
	 *
	 * @param mixed            $response Response.
	 * @param \WP_REST_Server  $server   Server.
	 * @param \WP_REST_Request $request  Request.
	 * @return mixed
	 */
	public static function nocache( $response, $server, $request ) {
		unset( $server );
		if ( ! $response instanceof \WP_HTTP_Response || ! $request instanceof \WP_REST_Request || ! self::is_webhook_route( (string) $request->get_route() ) ) {
			return $response;
		}
		foreach ( wp_get_nocache_headers() as $name => $value ) {
			if ( is_string( $value ) && '' !== $value ) {
				$response->header( $name, $value );
			}
		}
		return $response;
	}

	/**
	 * action_scheduler_init: schedules the sweep (every 900 s) and the daily cleanup (every 86400 s) when they are
	 * missing. Checked only where it costs nobody a page view: admin, cron, WP-CLI and webhook requests.
	 */
	public static function schedule_recurring() {
		if ( ! function_exists( 'as_has_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}
		if ( ! ( is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) || Plugin::is_webhook_request() ) ) {
			return;
		}
		$plugin = Plugin::instance();
		if ( ! $plugin->tables_ready() ) {
			return;
		}
		try {
			foreach ( self::RECURRING as $hook => $interval ) {
				if ( ! as_has_scheduled_action( $hook, null, self::GROUP ) ) {
					as_schedule_recurring_action( time() + 60, $interval, $hook, array(), self::GROUP, true );
				}
			}
		} catch ( \Throwable $e ) {
			self::log_error( $plugin, 'scheduling', $e );
		}
	}

	/**
	 * qersh_wc_complete ( order_id ): the claimed completion path.
	 *
	 * @param mixed $order_id Order id (Action Scheduler passes the arguments by position).
	 */
	public static function on_complete( $order_id = 0 ) {
		$plugin = self::ready_plugin();
		$id     = self::order_id_of( $order_id );
		if ( null === $plugin || $id <= 0 ) {
			return;
		}
		$plugin->completion()->run( $id );
	}

	/**
	 * qersh_wc_expire ( order_id ): the expiry of one order (package D2).
	 *
	 * @param mixed $order_id Order id.
	 */
	public static function on_expire( $order_id = 0 ) {
		$plugin = self::ready_plugin();
		$id     = self::order_id_of( $order_id );
		if ( null === $plugin || $id <= 0 || ! class_exists( Expiry::class ) || ! method_exists( Expiry::class, 'run' ) ) {
			return;
		}
		try {
			$plugin->expiry()->run( $id );
		} catch ( \Throwable $e ) {
			self::log_error( $plugin, 'expiry', $e );
		}
	}

	/**
	 * qersh_wc_claim_eval ( order_id ): the late evaluation of a customer's transaction id (package D2).
	 *
	 * @param mixed $order_id Order id.
	 */
	public static function on_claim_eval( $order_id = 0 ) {
		$plugin = self::ready_plugin();
		$id     = self::order_id_of( $order_id );
		if ( null === $plugin || $id <= 0 || ! class_exists( Claims::class ) || ! method_exists( Claims::class, 'evaluate' ) ) {
			return;
		}
		try {
			$plugin->claims()->evaluate( $id );
		} catch ( \Throwable $e ) {
			self::log_error( $plugin, 'claim evaluation', $e );
		}
	}

	/**
	 * qersh_wc_sweep (every 15 minutes): completions that did not run, expiries, and the late evaluations.
	 */
	public static function on_sweep() {
		$plugin = self::ready_plugin();
		if ( null === $plugin ) {
			return;
		}
		$plugin->completion()->sweep();
		if ( class_exists( Expiry::class ) && method_exists( Expiry::class, 'sweep' ) ) {
			try {
				$plugin->expiry()->sweep();
			} catch ( \Throwable $e ) {
				self::log_error( $plugin, 'expiry sweep', $e );
			}
		}
		if ( class_exists( Claims::class ) && method_exists( Claims::class, 'sweep' ) ) {
			try {
				$plugin->claims()->sweep();
			} catch ( \Throwable $e ) {
				self::log_error( $plugin, 'claims sweep', $e );
			}
		}
	}

	/**
	 * qersh_wc_daily: forgets old deliveries (dedup.keep_days, 180), erases the payer data of old transfers that
	 * paid no order (store.unmatched_retention_days, 90), deletes lock rows unused for a day, and erases the keys
	 * of wallets deleted 72 hours ago. Each step runs even when another fails.
	 */
	public static function on_daily() {
		$plugin = self::ready_plugin();
		if ( null === $plugin ) {
			return;
		}
		$now       = time();
		$config    = $plugin->config();
		$keep_days = self::days( isset( $config['receiver']['dedup']['keep_days'] ) ? $config['receiver']['dedup']['keep_days'] : null, 180 );
		$retention = self::days( isset( $config['store']['unmatched_retention_days'] ) ? $config['store']['unmatched_retention_days'] : null, 90 );

		try {
			$plugin->deliveries()->purge( $now - $keep_days * 86400 );
		} catch ( \Throwable $e ) {
			self::log_error( $plugin, 'deliveries cleanup', $e );
		}
		try {
			$plugin->transfers()->purge_personal( $now - $retention * 86400 );
		} catch ( \Throwable $e ) {
			self::log_error( $plugin, 'transfers retention', $e );
		}
		try {
			$locks = new Locks( $plugin );
			$locks->purge( $now - self::LOCK_KEEP_SEC );
		} catch ( \Throwable $e ) {
			self::log_error( $plugin, 'locks cleanup', $e );
		}
		try {
			$plugin->wallets()->erase_due_keys( $now );
		} catch ( \Throwable $e ) {
			self::log_error( $plugin, 'key erasure', $e );
		}
	}

	/**
	 * True for the webhook route. Pure.
	 *
	 * @param string $route Route, as WP_REST_Request::get_route() gives it.
	 * @return bool
	 */
	public static function is_webhook_route( string $route ): bool {
		return 0 === strpos( $route, self::ROUTE_PREFIX );
	}

	/**
	 * True for any route of the plugin. Pure.
	 *
	 * @param string $route Route.
	 * @return bool
	 */
	public static function is_our_route( string $route ): bool {
		return 0 === strpos( $route, self::NAMESPACE_PREFIX ) || '/' . self::REST_NAMESPACE === $route;
	}

	/**
	 * True for a body above the 64 KB limit. Pure.
	 *
	 * @param int $bytes Body length in bytes.
	 * @return bool
	 */
	public static function too_large( int $bytes ): bool {
		return $bytes > self::MAX_BODY;
	}

	/**
	 * The order id an Action Scheduler action carries: an integer, a numeric string, or (when an array was passed as
	 * the one argument) its `order_id` or first element. Pure.
	 *
	 * @param mixed $arg Argument.
	 * @return int 0 when there is none.
	 */
	public static function order_id_of( $arg ): int {
		if ( is_array( $arg ) ) {
			$arg = isset( $arg['order_id'] ) ? $arg['order_id'] : reset( $arg );
		}
		if ( is_int( $arg ) ) {
			return max( 0, $arg );
		}
		if ( is_string( $arg ) && 1 === preg_match( '/^[0-9]{1,18}$/', $arg ) ) {
			return (int) $arg;
		}
		return 0;
	}

	/**
	 * A number of days from the configuration, or the default. Pure.
	 *
	 * @param mixed $value    Value.
	 * @param int   $fallback Value when the configuration has none.
	 * @return int
	 */
	public static function days( $value, int $fallback ): int {
		if ( is_int( $value ) && $value > 0 ) {
			return $value;
		}
		if ( is_float( $value ) && $value >= 1 && floor( $value ) === $value ) {
			return (int) $value;
		}
		return $fallback;
	}

	/**
	 * The plugin when WooCommerce and the tables are there, else null.
	 *
	 * @return Plugin|null
	 */
	private static function ready_plugin(): ?Plugin {
		$plugin = Plugin::instance();
		return $plugin->wc_ready() && $plugin->tables_ready() ? $plugin : null;
	}

	/**
	 * Logs a failure without data.
	 *
	 * @param Plugin     $plugin The plugin.
	 * @param string     $where  Step.
	 * @param \Throwable $e      Error.
	 */
	private static function log_error( Plugin $plugin, string $where, \Throwable $e ) {
		try {
			$plugin->logger()->error( 'qersh: ' . $where . ' failed: ' . get_class( $e ) . ' at ' . basename( $e->getFile() ) . ':' . $e->getLine() );
		} catch ( \Throwable $ignored ) {
			unset( $ignored );
		}
	}
}
