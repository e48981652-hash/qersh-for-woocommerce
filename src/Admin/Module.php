<?php
/**
 * The admin side of the plugin: the WooCommerce → Qersh page, its actions, the order box, privacy, the
 * administrator's alert email and the diagnostics hooks. The notices are Notices.
 *
 * @package QershPay\WooCommerce
 */

namespace QershPay\WooCommerce\Admin;

use QershPay\WooCommerce\Emails\AdminAlert;
use QershPay\WooCommerce\Plugin;
use QershPay\WooCommerce\Privacy;
use QershPay\WooCommerce\Store\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Entry point of package E. Plugin::boot() calls register() on plugins_loaded; nothing here translates a string
 * before init.
 *
 * Every admin action is a POST to admin-post.php (or admin-ajax.php for the key link and notice dismissal) that
 * checks manage_woocommerce and a nonce, then ends with wp_safe_redirect() and exit.
 */
final class Module {

	/**
	 * The page slug, under the WooCommerce menu.
	 */
	const SLUG = 'qersh';

	/**
	 * The page's tabs.
	 */
	const TABS = array( 'wallets', 'transfers', 'settings', 'status' );

	/**
	 * Capability of every screen and action.
	 */
	const CAP = 'manage_woocommerce';

	/**
	 * The action that sends the administrator's alert email: do_action( 'qersh_wc_admin_alert', $kind, $args ).
	 * A literal here, because Emails\AdminAlert extends WC_Email and is loaded only with WooCommerce's mailer.
	 */
	const ALERT_ACTION = 'qersh_wc_admin_alert';

	/**
	 * Prefix of the per-user transient holding the next page's messages.
	 */
	const FLASH_PREFIX = 'qersh_wc_flash_';

	/**
	 * The admin-post actions and their handlers.
	 */
	const ACTIONS = array(
		'qersh_wc_wallet_save'        => array( Wallets::class, 'handle_save' ),
		'qersh_wc_wallet_delete'      => array( Wallets::class, 'handle_delete' ),
		'qersh_wc_wallet_restore'     => array( Wallets::class, 'handle_restore' ),
		'qersh_wc_key_paste'          => array( Wallets::class, 'handle_key_paste' ),
		'qersh_wc_key_pending_cancel' => array( Wallets::class, 'handle_pending_cancel' ),
		'qersh_wc_transfer_attach'    => array( Transfers::class, 'handle_attach' ),
		'qersh_wc_transfer_dismiss'   => array( Transfers::class, 'handle_dismiss' ),
		'qersh_wc_transfer_resolve'   => array( Transfers::class, 'handle_resolve' ),
		'qersh_wc_settings_save'      => array( Settings::class, 'handle_save' ),
		'qersh_wc_config_import'      => array( Settings::class, 'handle_import' ),
		'qersh_wc_config_export'      => array( Settings::class, 'handle_export' ),
		'qersh_wc_self_test'          => array( Status::class, 'handle_self_test' ),
	);

	/**
	 * The plugin.
	 *
	 * @var Plugin|null
	 */
	private static $plugin = null;

	/**
	 * The needs-decision count, read once per request.
	 *
	 * @var int|null
	 */
	private static $badge = null;

	/**
	 * Registers the hooks of package E.
	 *
	 * @param Plugin $plugin   The plugin.
	 * @param bool   $wc_ready Whether WooCommerce at the minimum version is active.
	 */
	public static function register( Plugin $plugin, bool $wc_ready ): void {
		self::$plugin = $plugin;

		// A deleted site of a network takes our tables with it.
		add_filter( 'wpmu_drop_tables', array( __CLASS__, 'drop_tables' ), 10, 2 );

		// Notices hook themselves on the screens they concern only (the WooCommerce-missing line included).
		Notices::register( $wc_ready );
		if ( ! $wc_ready ) {
			return;
		}

		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 60 );
		add_filter( 'woocommerce_screen_ids', array( __CLASS__, 'screen_ids' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( QERSH_WC_FILE ), array( __CLASS__, 'action_links' ) );

		foreach ( self::ACTIONS as $action => $handler ) {
			add_action( 'admin_post_' . $action, $handler );
		}
		add_action( 'wp_ajax_qersh_wc_key_link', array( Wallets::class, 'ajax_key_link' ) );

		add_action( 'add_meta_boxes', array( OrderBox::class, 'add' ), 30, 2 );

		add_filter( 'woocommerce_email_classes', array( __CLASS__, 'email_classes' ) );
		add_filter( 'woocommerce_email_actions', array( __CLASS__, 'email_actions' ) );

		Privacy::register( $plugin );

		$diagnostics = $plugin->diagnostics();
		add_action( 'qersh_wc_sweep', array( $diagnostics, 'sweep' ), 20 );
		add_action( 'qersh_wc_daily', array( $diagnostics, 'daily' ), 20 );
	}

	/**
	 * The plugin.
	 *
	 * @return Plugin
	 */
	public static function plugin(): Plugin {
		if ( null === self::$plugin ) {
			self::$plugin = Plugin::instance();
		}
		return self::$plugin;
	}

	/*
	 * ---------------------------------------------------------------------------------------------------------------
	 * Menu, page, assets.
	 * ---------------------------------------------------------------------------------------------------------------
	 */

	/**
	 * WooCommerce → Qersh, with the needs-decision count.
	 */
	public static function menu(): void {
		$count = self::badge_count();
		$title = esc_html__( 'Qersh', 'qersh-for-woocommerce' );
		if ( $count > 0 ) {
			$title .= ' <span class="awaiting-mod count-' . (int) $count . '"><span class="pending-count">' . esc_html( number_format_i18n( $count ) ) . '</span></span>';
		}
		add_submenu_page( 'woocommerce', __( 'Qersh', 'qersh-for-woocommerce' ), $title, self::CAP, self::SLUG, array( __CLASS__, 'render_page' ) );
	}

	/**
	 * How many transfers need a decision (0 when the tables cannot be read).
	 *
	 * @return int
	 */
	public static function badge_count(): int {
		if ( null !== self::$badge ) {
			return self::$badge;
		}
		self::$badge = 0;
		try {
			if ( self::plugin()->tables_ready() ) {
				self::$badge = self::plugin()->transfers()->count_needs_decision();
			}
		} catch ( \Throwable $e ) {
			self::$badge = 0;
		}
		return self::$badge;
	}

	/**
	 * Our page counts as a WooCommerce screen (WooCommerce styles and notices).
	 *
	 * @param array $ids Screen ids.
	 * @return array
	 */
	public static function screen_ids( $ids ) {
		$ids   = is_array( $ids ) ? $ids : array();
		$ids[] = self::screen_id();
		return $ids;
	}

	/**
	 * The screen id of our page.
	 *
	 * @return string
	 */
	public static function screen_id(): string {
		return 'woocommerce_page_' . self::SLUG;
	}

	/**
	 * True on our page.
	 *
	 * @return bool
	 */
	public static function is_our_screen(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return $screen && self::screen_id() === $screen->id;
	}

	/**
	 * The current tab.
	 *
	 * @return string
	 */
	public static function current_tab(): string {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'wallets'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only.
		return in_array( $tab, self::TABS, true ) ? $tab : 'wallets';
	}

	/**
	 * The URL of a tab.
	 *
	 * @param string $tab  Tab.
	 * @param array  $args More query arguments.
	 * @return string
	 */
	public static function page_url( string $tab = 'wallets', array $args = array() ): string {
		return add_query_arg(
			array_merge(
				array(
					'page' => self::SLUG,
					'tab'  => $tab,
				),
				$args
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Renders WooCommerce → Qersh. The page template prints the tab in place, through the tab's render(), so every
	 * value is escaped where it is printed.
	 */
	public static function render_page(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage this store.', 'qersh-for-woocommerce' ), 403 );
		}
		$tab = self::current_tab();
		self::plugin()->diagnostics()->as_health();
		self::plugin()->diagnostics()->check_silence(); // Also without a working Cron, the one email per episode goes.
		$renders = array(
			'wallets'   => array( Wallets::class, 'render' ),
			'transfers' => array( Transfers::class, 'render' ),
			'settings'  => array( Settings::class, 'render' ),
			'status'    => array( Status::class, 'render' ),
		);
		$tabs    = array(
			'wallets'   => __( 'Wallets', 'qersh-for-woocommerce' ),
			'transfers' => __( 'Transfers', 'qersh-for-woocommerce' ),
			'settings'  => __( 'Settings', 'qersh-for-woocommerce' ),
			'status'    => __( 'Status', 'qersh-for-woocommerce' ),
		);
		self::template(
			'page',
			array(
				'tab'    => $tab,
				'tabs'   => $tabs,
				'badge'  => self::badge_count(),
				'flash'  => self::take_flash(),
				'render' => $renders[ $tab ],
			)
		);
	}

	/**
	 * The permanent disclosure of docs/17 §3 (escaped HTML).
	 *
	 * @return string
	 */
	public static function disclosure(): string {
		$download = 'https://qersh.cfd/en/download';
		$support  = 'support@qersh.cfd';
		return sprintf(
			/* translators: 1: link to the app download page, 2: support email address. */
			esc_html__( 'Works with the paid Qersh Android app (monthly subscription) on the phone that receives your wallet\'s messages. Get the app: %1$s. Support: the plugin\'s forum on wordpress.org or %2$s.', 'qersh-for-woocommerce' ),
			'<a href="' . esc_url( $download ) . '" target="_blank" rel="noopener noreferrer">' . View::ltr( $download ) . '</a>',
			'<a href="' . esc_url( 'mailto:' . $support ) . '">' . View::ltr( $support ) . '</a>'
		);
	}

	/**
	 * Includes a template of templates/admin/ with `$args` in scope.
	 *
	 * @param string $name Template name without .php.
	 * @param array  $args Values for the template, which reads them as `$args`.
	 */
	public static function template( string $name, array $args = array() ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- the included template reads $args.
		$name = sanitize_key( $name );
		$file = QERSH_WC_DIR . 'templates/admin/' . str_replace( '_', '-', $name ) . '.php';
		if ( is_readable( $file ) ) {
			include $file;
		}
	}

	/**
	 * Scripts and styles: on our page, on the order screens (the box) and on every screen a notice of ours may appear
	 * (its dismissal is remembered by the script); nowhere else.
	 *
	 * @param string $hook_suffix Hook suffix of the screen.
	 */
	public static function enqueue( $hook_suffix ): void {
		$ours = self::screen_id() === $hook_suffix || self::is_our_screen();
		if ( ! $ours && ! OrderBox::is_order_screen() && '' === Notices::current_kind() ) {
			return;
		}
		wp_enqueue_style( 'qersh-wc-admin', QERSH_WC_URL . 'assets/css/admin.css', array(), Plugin::asset_version( 'assets/css/admin.css' ) );
		wp_register_script( 'qersh-wc-qrcode', QERSH_WC_URL . 'assets/vendor/qrcode-generator/qrcode.js', array(), '2.0.4', true );
		wp_register_script( 'qersh-wc-admin', QERSH_WC_URL . 'assets/js/admin.js', array( 'wp-i18n', 'clipboard', 'qersh-wc-qrcode' ), Plugin::asset_version( 'assets/js/admin.js' ), true );
		wp_set_script_translations( 'qersh-wc-admin', 'qersh-for-woocommerce' );
		$data = array(
			'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
			'postUrl'      => admin_url( 'admin-post.php' ),
			'keyLinkNonce' => wp_create_nonce( 'qersh_wc_key_link' ),
			'noticeNonce'  => wp_create_nonce( Notices::DISMISS_ACTION ),
			'cancelNonce'  => wp_create_nonce( 'qersh_wc_key_pending_cancel' ),
			'qrHideMs'     => 5 * MINUTE_IN_SECONDS * 1000,
		);
		wp_add_inline_script( 'qersh-wc-admin', 'window.qershWcAdmin = ' . wp_json_encode( $data ) . ';', 'before' );
		wp_enqueue_script( 'qersh-wc-admin' );
	}

	/**
	 * A "Settings" link on the plugins screen.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public static function action_links( $links ) {
		$links = is_array( $links ) ? $links : array();
		array_unshift( $links, '<a href="' . esc_url( self::page_url( 'wallets' ) ) . '">' . esc_html__( 'Wallets', 'qersh-for-woocommerce' ) . '</a>' );
		return $links;
	}

	/*
	 * ---------------------------------------------------------------------------------------------------------------
	 * Actions.
	 * ---------------------------------------------------------------------------------------------------------------
	 */

	/**
	 * The first checks of every admin-post action: a POST, by a user with manage_woocommerce. Ends the request on
	 * failure. Each handler then checks its nonce with check_admin_referer( $action ) in its own body, right after,
	 * where the code sniffs see it.
	 *
	 * @param string $action The admin-post action (also its nonce action).
	 */
	public static function check_action( string $action ): void {
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) ) {
			wp_die( esc_html__( 'This action needs a form submission.', 'qersh-for-woocommerce' ), 405 );
		}
		if ( '' === $action || ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage this store.', 'qersh-for-woocommerce' ), 403 );
		}
	}

	/**
	 * Redirects to a tab and ends the request.
	 *
	 * @param string $tab  Tab.
	 * @param array  $args More query arguments.
	 */
	public static function redirect( string $tab, array $args = array() ): void {
		wp_safe_redirect( self::page_url( $tab, $args ) );
		exit;
	}

	/**
	 * Keeps a message for the next page of this user (no secret ever goes in it: fingerprints only).
	 *
	 * @param string $type    `success`, `error`, `warning` or `info`.
	 * @param string $message Escaped HTML.
	 */
	public static function flash( string $type, string $message ): void {
		$key      = self::FLASH_PREFIX . get_current_user_id();
		$messages = get_transient( $key );
		$messages = is_array( $messages ) ? $messages : array();

		$messages[] = array(
			'type'    => in_array( $type, array( 'success', 'error', 'warning', 'info' ), true ) ? $type : 'info',
			'message' => $message,
		);
		set_transient( $key, array_slice( $messages, -10 ), 120 );
	}

	/**
	 * Takes the messages kept for this page.
	 *
	 * @return array[]
	 */
	public static function take_flash(): array {
		$key      = self::FLASH_PREFIX . get_current_user_id();
		$messages = get_transient( $key );
		if ( false !== $messages ) {
			delete_transient( $key );
		}
		return is_array( $messages ) ? $messages : array();
	}

	/*
	 * ---------------------------------------------------------------------------------------------------------------
	 * Emails and network.
	 * ---------------------------------------------------------------------------------------------------------------
	 */

	/**
	 * Adds the administrator's alert email.
	 *
	 * @param array $emails Email classes.
	 * @return array
	 */
	public static function email_classes( $emails ) {
		$emails = is_array( $emails ) ? $emails : array();
		if ( class_exists( 'WC_Email' ) ) {
			$emails['Qersh_WC_Email_Admin_Alert'] = new AdminAlert();
		}
		return $emails;
	}

	/**
	 * Our alert action is a transactional email action, so it is deferred with the webhook request's other emails.
	 *
	 * @param array $actions Actions.
	 * @return array
	 */
	public static function email_actions( $actions ) {
		$actions = is_array( $actions ) ? $actions : array();
		if ( ! in_array( self::ALERT_ACTION, $actions, true ) ) {
			$actions[] = self::ALERT_ACTION;
		}
		return $actions;
	}

	/**
	 * Our tables are dropped with a deleted site of a network.
	 *
	 * @param array $tables  Tables to drop.
	 * @param int   $site_id Site id.
	 * @return array
	 */
	public static function drop_tables( $tables, $site_id = 0 ) {
		global $wpdb;
		$tables = is_array( $tables ) ? $tables : array();
		$prefix = $site_id ? $wpdb->get_blog_prefix( (int) $site_id ) : $wpdb->prefix;
		return array_merge( $tables, Schema::table_names( $prefix ) );
	}
}
