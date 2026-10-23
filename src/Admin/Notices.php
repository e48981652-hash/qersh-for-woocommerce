<?php
/**
 * The plugin's admin notices (package E): the alerts of Diagnostics on the screens they concern, and the one line of
 * the Plugins screen while WooCommerce is missing. Every notice names the problem and the fix, is dismissible, stays
 * dismissed, and goes when its cause is gone (wordpress.org guideline 11: nothing of ours on the Dashboard, Posts,
 * Media or any other unrelated screen, and nothing promotional anywhere).
 *
 * @package QershPay\WooCommerce
 */

namespace QershPay\WooCommerce\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Where a notice may appear, by the kind of screen (kind()):
 *
 * - WooCommerce → Qersh (`ours`): every alert, except what the tab shows itself (the Wallets tab prints each
 *   wallet's alerts in its card, the Status tab lists the site-wide ones);
 * - WooCommerce → Settings → Payments (`payments`): errors and warnings, each with a link to our page;
 * - the orders list and the order screen (`orders`): errors only, the ones that mean orders are not being confirmed
 *   or recorded on their own;
 * - the Plugins screen: the WooCommerce-missing line alone, and only while WooCommerce is missing.
 *
 * The admin_notices hook is added on `current_screen` (on `load-plugins.php` for the WooCommerce-missing line), so
 * no other screen even runs the checks. Each notice carries its cause in `data-qersh-notice`; a dismissal is
 * remembered by that id in OPTION (assets/js/admin.js posts it; the Plugins screen has its own lines of script), and
 * our page forgets the ids whose cause is gone, so the list never grows and a new episode shows again.
 */
final class Notices {

	/**
	 * Option with the ids of dismissed notices (autoload off).
	 */
	const OPTION = 'qersh_wc_notices';

	/**
	 * The AJAX action, and nonce action, that remembers a dismissal.
	 */
	const DISMISS_ACTION = 'qersh_wc_notice_dismiss';

	/**
	 * Id of the WooCommerce-missing notice.
	 */
	const WC_MISSING = 'wc_missing';

	/**
	 * The kinds of screen a notice may appear on.
	 */
	const OURS     = 'ours';
	const PAYMENTS = 'payments';
	const ORDERS   = 'orders';

	/**
	 * Screen id of WooCommerce → Settings, and its Payments tab.
	 */
	const SETTINGS_SCREEN = 'woocommerce_page_wc-settings';
	const PAYMENTS_TAB    = 'checkout';

	/**
	 * Screen id of the orders list on the posts storage (HPOS lists and edits on one screen, in OrderBox::screens()).
	 */
	const ORDERS_LIST_SCREEN = 'edit-shop_order';

	/**
	 * The kind of the current screen, read once per request.
	 *
	 * @var string|null
	 */
	private static $kind = null;

	/**
	 * Registers the hooks. The dismissal handler runs in both states: the WooCommerce-missing line is dismissible too.
	 *
	 * @param bool $wc_ready Whether WooCommerce at the minimum version is active.
	 */
	public static function register( bool $wc_ready ): void {
		add_action( 'wp_ajax_' . self::DISMISS_ACTION, array( __CLASS__, 'ajax_dismiss' ) );
		if ( ! $wc_ready ) {
			// load-plugins.php fires on the Plugins screen alone.
			add_action( 'load-plugins.php', array( __CLASS__, 'hook_wc_missing' ) );
			return;
		}
		add_action( 'current_screen', array( __CLASS__, 'hook_alerts' ) );
	}

	/*
	 * ---------------------------------------------------------------------------------------------------------------
	 * Screens.
	 * ---------------------------------------------------------------------------------------------------------------
	 */

	/**
	 * The kind of a screen: OURS, PAYMENTS, ORDERS or '' (no notice of ours there). Pure.
	 *
	 * @param string   $screen_id     WP_Screen::$id.
	 * @param string   $settings_tab  The `tab` of WooCommerce → Settings ('' elsewhere).
	 * @param string[] $order_screens Screen ids of the orders list and the order screen.
	 * @return string
	 */
	public static function kind( string $screen_id, string $settings_tab, array $order_screens ): string {
		if ( '' === $screen_id ) {
			return '';
		}
		if ( Module::screen_id() === $screen_id ) {
			return self::OURS;
		}
		if ( self::SETTINGS_SCREEN === $screen_id ) {
			return self::PAYMENTS_TAB === $settings_tab ? self::PAYMENTS : '';
		}
		return in_array( $screen_id, $order_screens, true ) ? self::ORDERS : '';
	}

	/**
	 * Whether an alert of a level shows on a kind of screen. Pure.
	 *
	 * @param string $kind  A kind of kind(), or ''.
	 * @param string $level `error`, `warning` or `info`.
	 * @return bool
	 */
	public static function shows( string $kind, string $level ): bool {
		switch ( $kind ) {
			case self::OURS:
				return true;
			case self::PAYMENTS:
				return 'error' === $level || 'warning' === $level;
			case self::ORDERS:
				return 'error' === $level;
		}
		return false;
	}

	/**
	 * The alerts to print on a screen, each cause once, and the dismissed ids whose cause is still there. Pure.
	 *
	 * @param array[]  $alerts    Alerts of Diagnostics (`id`, `level`, `wallet`, `message`), site-wide first.
	 * @param string[] $dismissed Dismissed ids.
	 * @param string   $kind      Kind of the screen.
	 * @param string   $tab       On our page, the tab ('' elsewhere): the Wallets tab shows each wallet's alerts in
	 *                            its card and the Status tab lists the site-wide ones, so those are not repeated above.
	 * @return array `[ 'show' => array[], 'keep' => string[] ]`
	 */
	public static function select( array $alerts, array $dismissed, string $kind, string $tab = '' ): array {
		$show = array();
		$keep = array();
		$seen = array();
		foreach ( $alerts as $alert ) {
			if ( ! is_array( $alert ) || ! isset( $alert['id'], $alert['level'], $alert['message'] ) ) {
				continue;
			}
			$id = (string) $alert['id'];
			if ( '' === $id || isset( $seen[ $id ] ) ) {
				continue;
			}
			$seen[ $id ] = true;
			if ( in_array( $id, $dismissed, true ) ) {
				$keep[] = $id;
				continue;
			}
			if ( ! self::shows( $kind, (string) $alert['level'] ) ) {
				continue;
			}
			$wallet = isset( $alert['wallet'] ) ? (string) $alert['wallet'] : '';
			if ( self::OURS === $kind && ( ( 'wallets' === $tab && '' !== $wallet ) || ( 'status' === $tab && '' === $wallet ) ) ) {
				continue;
			}
			$show[] = $alert;
		}
		return array(
			'show' => $show,
			'keep' => $keep,
		);
	}

	/**
	 * The arguments of wp_admin_notice() for a notice of ours: its type, dismissible, the cause in
	 * `data-qersh-notice` (what the dismissal posts) and the class the script listens on. Pure.
	 *
	 * @param string $type `error`, `warning` or `info`.
	 * @param string $id   The cause.
	 * @return array
	 */
	public static function args( string $type, string $id ): array {
		return array(
			'type'               => $type,
			'dismissible'        => true,
			'additional_classes' => array( 'qersh-wc-notice' ),
			'attributes'         => array( 'data-qersh-notice' => $id ),
		);
	}

	/**
	 * The kind of the current screen ('' before the screen is known, and on every screen without a notice of ours).
	 *
	 * @return string
	 */
	public static function current_kind(): string {
		if ( null === self::$kind ) {
			self::$kind = self::kind_of( function_exists( 'get_current_screen' ) ? get_current_screen() : null );
		}
		return self::$kind;
	}

	/**
	 * The kind of a screen object.
	 *
	 * @param \WP_Screen|null $screen Screen.
	 * @return string
	 */
	private static function kind_of( $screen ): string {
		if ( ! $screen instanceof \WP_Screen ) {
			return '';
		}
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only.
		return self::kind( (string) $screen->id, $tab, array_merge( OrderBox::screens(), array( self::ORDERS_LIST_SCREEN ) ) );
	}

	/*
	 * ---------------------------------------------------------------------------------------------------------------
	 * The alerts of Diagnostics.
	 * ---------------------------------------------------------------------------------------------------------------
	 */

	/**
	 * On current_screen: on a screen the alerts concern, prints them to a user who can act on them.
	 *
	 * @param \WP_Screen $screen The screen.
	 */
	public static function hook_alerts( $screen ): void {
		self::$kind = self::kind_of( $screen );
		if ( '' === self::$kind || ! current_user_can( Module::CAP ) ) {
			return;
		}
		add_action( 'admin_notices', array( __CLASS__, 'print_alerts' ) );
	}

	/**
	 * Prints the alerts (admin_notices).
	 */
	public static function print_alerts(): void {
		$kind = self::current_kind();
		if ( '' === $kind || ! current_user_can( Module::CAP ) ) {
			return;
		}
		$ours   = self::OURS === $kind;
		$hidden = self::dismissed();
		$picked = self::select( self::active_alerts( $ours ), $hidden, $kind, $ours ? Module::current_tab() : '' );
		foreach ( $picked['show'] as $alert ) {
			self::print_alert( $alert, $ours );
		}
		if ( $ours && count( $picked['keep'] ) !== count( $hidden ) ) {
			// Forget dismissals whose cause is gone, so the list never grows and a new episode shows again.
			update_option( self::OPTION, array_values( $picked['keep'] ), false );
		}
	}

	/**
	 * Every alert now: site-wide, then each wallet's.
	 *
	 * @param bool $ours True on our page.
	 * @return array[]
	 */
	public static function active_alerts( bool $ours ): array {
		$plugin      = Module::plugin();
		$diagnostics = $plugin->diagnostics();
		$alerts      = $diagnostics->global_alerts( $ours );
		if ( $plugin->tables_ready() ) {
			foreach ( $plugin->config()['wallets'] as $wallet ) {
				if ( is_array( $wallet ) && isset( $wallet['id'] ) ) {
					$alerts = array_merge( $alerts, $diagnostics->wallet_alerts( (string) $wallet['id'] ) );
				}
			}
		}
		return $alerts;
	}

	/**
	 * One alert, as WordPress prints notices, with a link to our page from the other screens.
	 *
	 * @param array $alert Alert (`id`, `level`, `wallet`, `message` as escaped HTML).
	 * @param bool  $ours  True on our page.
	 */
	private static function print_alert( array $alert, bool $ours ): void {
		$level = (string) $alert['level'];
		$link  = '';
		if ( ! $ours ) {
			$wallet = isset( $alert['wallet'] ) ? (string) $alert['wallet'] : '';
			$link   = ' <a href="' . esc_url( Module::page_url( '' === $wallet ? 'status' : 'wallets' ) ) . '">' . esc_html__( 'Open Qersh', 'qersh-for-woocommerce' ) . '</a>';
		}
		wp_admin_notice(
			'<strong>' . esc_html__( 'Qersh:', 'qersh-for-woocommerce' ) . '</strong> ' . wp_kses( (string) $alert['message'] . $link, View::allowed_html() ),
			self::args( 'error' === $level || 'warning' === $level ? $level : 'info', (string) $alert['id'] )
		);
	}

	/*
	 * ---------------------------------------------------------------------------------------------------------------
	 * WooCommerce missing.
	 * ---------------------------------------------------------------------------------------------------------------
	 */

	/**
	 * On load-plugins.php: the WooCommerce-missing line, to a user who can act on it, unless dismissed.
	 */
	public static function hook_wc_missing(): void {
		if ( ! current_user_can( 'activate_plugins' ) || in_array( self::WC_MISSING, self::dismissed(), true ) ) {
			return;
		}
		add_action( 'admin_notices', array( __CLASS__, 'wc_missing_notice' ) );
		add_action( 'admin_print_footer_scripts', array( __CLASS__, 'print_dismiss_script' ) );
	}

	/**
	 * WooCommerce is missing, inactive or older than the minimum: one line, with what to do.
	 */
	public static function wc_missing_notice(): void {
		wp_admin_notice(
			esc_html(
				sprintf(
					/* translators: %s: WooCommerce version number. */
					__( 'Qersh for WooCommerce needs WooCommerce %s or later, active.', 'qersh-for-woocommerce' ),
					QERSH_WC_MIN_WC
				)
			),
			self::args( 'warning', self::WC_MISSING )
		);
	}

	/**
	 * Remembers the dismissal of the WooCommerce-missing line like assets/js/admin.js remembers the others (the
	 * script is not loaded without WooCommerce): the clicked dismiss button's cause goes to ajax_dismiss().
	 */
	public static function print_dismiss_script(): void {
		$config = array(
			'url'    => admin_url( 'admin-ajax.php' ),
			'action' => self::DISMISS_ACTION,
			'nonce'  => wp_create_nonce( self::DISMISS_ACTION ),
		);
		wp_print_inline_script_tag(
			'document.addEventListener( "click", function ( event ) {'
			. ' var button = event.target.closest ? event.target.closest( ".qersh-wc-notice .notice-dismiss" ) : null;'
			. ' var notice = button ? button.closest( ".qersh-wc-notice" ) : null;'
			. ' var id = notice ? notice.getAttribute( "data-qersh-notice" ) : "";'
			. ' if ( ! id ) { return; }'
			. ' var config = ' . wp_json_encode( $config ) . ', body = new window.FormData();'
			. ' body.append( "action", config.action ); body.append( "nonce", config.nonce ); body.append( "notice", id );'
			. ' window.fetch( config.url, { method: "POST", credentials: "same-origin", cache: "no-store", body: body } ).catch( function () {} );'
			. ' } );'
		);
	}

	/*
	 * ---------------------------------------------------------------------------------------------------------------
	 * Dismissals.
	 * ---------------------------------------------------------------------------------------------------------------
	 */

	/**
	 * Ids of dismissed notices.
	 *
	 * @return string[]
	 */
	public static function dismissed(): array {
		$ids = get_option( self::OPTION, array() );
		return is_array( $ids ) ? array_values( array_filter( $ids, 'is_string' ) ) : array();
	}

	/**
	 * Whether a string is shaped like a cause of ours: a code of Diagnostics with its `:` suffixes (a time, a day,
	 * an md5, a wallet id), or WC_MISSING. Pure.
	 *
	 * @param string $id Id.
	 * @return bool
	 */
	public static function valid_id( string $id ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9_:.-]{1,160}$/D', $id );
	}

	/**
	 * The capability a dismissal needs: the WooCommerce-missing line is shown to whoever can activate plugins (a store
	 * without WooCommerce may have nobody with manage_woocommerce), every other notice to manage_woocommerce. Pure.
	 *
	 * @param string $id The cause.
	 * @return string
	 */
	public static function dismiss_cap( string $id ): string {
		return self::WC_MISSING === $id ? 'activate_plugins' : Module::CAP;
	}

	/**
	 * AJAX: remembers a dismissed notice.
	 */
	public static function ajax_dismiss(): void {
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) ) {
			wp_send_json( array( 'code' => 'qersh_method' ), 405 );
		}
		if ( ! check_ajax_referer( self::DISMISS_ACTION, 'nonce', false ) ) {
			wp_send_json( array( 'code' => 'qersh_forbidden' ), 403 );
		}
		$id = isset( $_POST['notice'] ) ? sanitize_text_field( wp_unslash( $_POST['notice'] ) ) : '';
		if ( ! current_user_can( self::dismiss_cap( $id ) ) ) {
			wp_send_json( array( 'code' => 'qersh_forbidden' ), 403 );
		}
		if ( ! self::valid_id( $id ) ) {
			wp_send_json( array( 'code' => 'qersh_invalid' ), 400 );
		}
		$ids = self::dismissed();
		if ( ! in_array( $id, $ids, true ) ) {
			$ids[] = $id;
			update_option( self::OPTION, array_slice( $ids, -200 ), false );
		}
		wp_send_json( array( 'ok' => true ) );
	}
}
