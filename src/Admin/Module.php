<?php
/**
 * The admin side of the plugin: the WooCommerce → Qersh page, its actions, notices, the order box, privacy, the
 * administrator's alert email and the diagnostics hooks.
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
	 * Option with the ids of dismissed notices (autoload off).
	 */
	const NOTICES_OPTION = 'qersh_wc_notices';

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
	 * admin-post actions and their handlers.
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

		if ( ! $wc_ready ) {
			add_action( 'admin_notices', array( __CLASS__, 'wc_missing_notice' ) );
			return;
		}

		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 60 );
		add_filter( 'woocommerce_screen_ids', array( __CLASS__, 'screen_ids' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( QERSH_WC_FILE ), array( __CLASS__, 'action_links' ) );

		foreach ( self::ACTIONS as $action => $handler ) {
			add_action( 'admin_post_' . $action, $handler );
		}
		add_action( 'wp_ajax_qersh_wc_key_link', array( Wallets::class, 'ajax_key_link' ) );
		add_action( 'wp_ajax_qersh_wc_notice_dismiss', array( __CLASS__, 'ajax_dismiss_notice' ) );

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
	public static function menu() {
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
	 * True on a WooCommerce screen (ours included).
	 *
	 * @return bool
	 */
	public static function is_wc_screen(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return false;
		}
		if ( self::screen_id() === $screen->id ) {
			return true;
		}
		return function_exists( 'wc_get_screen_ids' ) && in_array( $screen->id, wc_get_screen_ids(), true );
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
		return add_query_arg( array_merge( array( 'page' => self::SLUG, 'tab' => $tab ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Renders WooCommerce → Qersh.
	 */
	public static function render_page() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage this store.', 'qersh-for-woocommerce' ), 403 );
		}
		$tab = self::current_tab();
		self::plugin()->diagnostics()->as_health();
		self::plugin()->diagnostics()->check_silence(); // Also without a working Cron, the one email per episode goes.
		ob_start();
		switch ( $tab ) {
			case 'transfers':
				Transfers::render();
				break;
			case 'settings':
				Settings::render();
				break;
			case 'status':
				Status::render();
				break;
			default:
				Wallets::render();
		}
		$content = (string) ob_get_clean();
		$tabs    = array(
			'wallets'   => __( 'Wallets', 'qersh-for-woocommerce' ),
			'transfers' => __( 'Transfers', 'qersh-for-woocommerce' ),
			'settings'  => __( 'Settings', 'qersh-for-woocommerce' ),
			'status'    => __( 'Status', 'qersh-for-woocommerce' ),
		);
		self::template(
			'page',
			array(
				'tab'      => $tab,
				'tabs'     => $tabs,
				'badge'    => self::badge_count(),
				'flash'    => self::take_flash(),
				'content'  => $content,
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
	 * @param array  $args Values for the template.
	 */
	public static function template( string $name, array $args = array() ) {
		$name = sanitize_key( $name );
		$file = QERSH_WC_DIR . 'templates/admin/' . str_replace( '_', '-', $name ) . '.php';
		if ( is_readable( $file ) ) {
			include $file;
		}
	}

	/**
	 * Scripts and styles: everything on our page, the stylesheet on the order screens.
	 *
	 * @param string $hook_suffix Hook suffix of the screen.
	 */
	public static function enqueue( $hook_suffix ) {
		$ours  = self::screen_id() === $hook_suffix || self::is_our_screen();
		$order = OrderBox::is_order_screen();
		if ( ! $ours && ! $order && ! self::is_wc_screen() ) {
			return;
		}
		wp_enqueue_style( 'qersh-wc-admin', QERSH_WC_URL . 'assets/css/admin.css', array(), QERSH_WC_VERSION );
		wp_register_script( 'qersh-wc-qrcode', QERSH_WC_URL . 'assets/vendor/qrcode-generator/qrcode.js', array(), '2.0.4', true );
		wp_register_script( 'qersh-wc-admin', QERSH_WC_URL . 'assets/js/admin.js', array( 'wp-i18n', 'clipboard', 'qersh-wc-qrcode' ), QERSH_WC_VERSION, true );
		wp_set_script_translations( 'qersh-wc-admin', 'qersh-for-woocommerce' );
		$data = array(
			'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
			'postUrl'      => admin_url( 'admin-post.php' ),
			'keyLinkNonce' => wp_create_nonce( 'qersh_wc_key_link' ),
			'noticeNonce'  => wp_create_nonce( 'qersh_wc_notice_dismiss' ),
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
	 * Notices (our screens and WooCommerce screens only, dismissible, gone when their cause is gone).
	 * ---------------------------------------------------------------------------------------------------------------
	 */

	/**
	 * Prints the alerts of Diagnostics.
	 */
	public static function notices() {
		if ( ! current_user_can( self::CAP ) || ! self::is_wc_screen() ) {
			return;
		}
		$ours   = self::is_our_screen();
		$cards  = $ours && 'wallets' === self::current_tab();
		$alerts = self::active_alerts( $ours );
		$hidden = self::dismissed();
		$keep   = array();
		foreach ( $alerts as $alert ) {
			if ( in_array( $alert['id'], $hidden, true ) ) {
				$keep[] = $alert['id'];
				continue;
			}
			if ( ( ! $ours && 'info' === $alert['level'] ) || ( $cards && '' !== $alert['wallet'] ) ) {
				continue; // On the Wallets tab each wallet's alerts are in its card.
			}
			$class = 'error' === $alert['level'] ? 'notice-error' : ( 'warning' === $alert['level'] ? 'notice-warning' : 'notice-info' );
			$link  = '';
			if ( ! $ours ) {
				$link = ' <a href="' . esc_url( self::page_url( '' === $alert['wallet'] ? 'status' : 'wallets' ) ) . '">' . esc_html__( 'Open Qersh', 'qersh-for-woocommerce' ) . '</a>';
			}
			echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible qersh-wc-notice" data-qersh-notice="' . esc_attr( $alert['id'] ) . '"><p><strong>' . esc_html__( 'Qersh:', 'qersh-for-woocommerce' ) . '</strong> ' . wp_kses( $alert['message'] . $link, View::allowed_html() ) . '</p></div>';
		}
		if ( $ours ) {
			// Forget dismissals whose cause is gone, so the list never grows and a new episode shows again.
			$stored = self::dismissed();
			if ( count( $keep ) !== count( $stored ) ) {
				update_option( self::NOTICES_OPTION, array_values( array_unique( $keep ) ), false );
			}
		}
	}

	/**
	 * Every alert now: site-wide, then each wallet's.
	 *
	 * @param bool $ours True on our page.
	 * @return array[]
	 */
	public static function active_alerts( bool $ours ): array {
		$diagnostics = self::plugin()->diagnostics();
		$alerts      = $diagnostics->global_alerts( $ours );
		if ( self::plugin()->tables_ready() ) {
			foreach ( self::plugin()->config()['wallets'] as $wallet ) {
				if ( is_array( $wallet ) && isset( $wallet['id'] ) ) {
					$alerts = array_merge( $alerts, $diagnostics->wallet_alerts( (string) $wallet['id'] ) );
				}
			}
		}
		return $alerts;
	}

	/**
	 * Ids of dismissed notices.
	 *
	 * @return string[]
	 */
	public static function dismissed(): array {
		$ids = get_option( self::NOTICES_OPTION, array() );
		return is_array( $ids ) ? array_values( array_filter( $ids, 'is_string' ) ) : array();
	}

	/**
	 * AJAX: remembers a dismissed notice.
	 */
	public static function ajax_dismiss_notice() {
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) ) {
			wp_send_json( array( 'code' => 'qersh_method' ), 405 );
		}
		if ( ! check_ajax_referer( 'qersh_wc_notice_dismiss', 'nonce', false ) || ! current_user_can( self::CAP ) ) {
			wp_send_json( array( 'code' => 'qersh_forbidden' ), 403 );
		}
		$id = isset( $_POST['notice'] ) ? sanitize_text_field( wp_unslash( $_POST['notice'] ) ) : '';
		if ( '' === $id || strlen( $id ) > 160 ) {
			wp_send_json( array( 'code' => 'qersh_invalid' ), 400 );
		}
		$ids = self::dismissed();
		if ( ! in_array( $id, $ids, true ) ) {
			$ids[] = $id;
			update_option( self::NOTICES_OPTION, array_slice( $ids, -200 ), false );
		}
		wp_send_json( array( 'ok' => true ) );
	}

	/**
	 * WooCommerce is active but older than the minimum: one line on the plugins screen.
	 */
	public static function wc_missing_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'plugins' !== $screen->id || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>' . esc_html(
			sprintf(
				/* translators: %s: WooCommerce version number. */
				__( 'Qersh for WooCommerce needs WooCommerce %s or later, active.', 'qersh-for-woocommerce' ),
				QERSH_WC_MIN_WC
			)
		) . '</p></div>';
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
	public static function check_action( string $action ) {
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
	public static function redirect( string $tab, array $args = array() ) {
		wp_safe_redirect( self::page_url( $tab, $args ) );
		exit;
	}

	/**
	 * Keeps a message for the next page of this user (no secret ever goes in it: fingerprints only).
	 *
	 * @param string $type    `success`, `error`, `warning` or `info`.
	 * @param string $message Escaped HTML.
	 */
	public static function flash( string $type, string $message ) {
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
