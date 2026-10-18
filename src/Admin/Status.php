<?php
/**
 * The Status tab: "Check your store URL" for each wallet and what Diagnostics knows about the site (docs/17 §4.9).
 *
 * @package QershPay\WooCommerce
 */

namespace QershPay\WooCommerce\Admin;

use QershPay\WooCommerce\Core\Config;
use QershPay\WooCommerce\Diagnostics;

defined( 'ABSPATH' ) || exit;

/**
 * The self-test sends requests only when the administrator clicks; its result waits for the next page load in a
 * transient of that user (URLs and verdicts, no keys).
 */
final class Status {

	/**
	 * Prefix of the per-user transient with the last self-test.
	 */
	const RESULT_PREFIX = 'qersh_wc_selftest_';

	/**
	 * Renders the tab.
	 */
	public static function render(): void {
		$plugin  = Module::plugin();
		$wallets = array();
		foreach ( $plugin->config()['wallets'] as $wallet ) {
			if ( is_array( $wallet ) && isset( $wallet['id'] ) ) {
				$wallets[ (string) $wallet['id'] ] = View::wallet_name( $wallet );
			}
		}
		$key    = self::RESULT_PREFIX . get_current_user_id();
		$result = get_transient( $key );
		if ( false !== $result ) {
			delete_transient( $key );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- preselects a wallet only.
		$selected = isset( $_GET['wallet'] ) ? sanitize_key( wp_unslash( $_GET['wallet'] ) ) : '';
		Module::template(
			'status',
			array(
				'wallets'  => $wallets,
				'selected' => isset( $wallets[ $selected ] ) ? $selected : '',
				'result'   => is_array( $result ) ? self::result_view( $result ) : null,
				'alerts'   => $plugin->diagnostics()->global_alerts( true ),
				'facts'    => self::facts(),
			)
		);
	}

	/**
	 * Runs the self-test (admin-post qersh_wc_self_test).
	 */
	public static function handle_self_test(): void {
		Module::check_action( 'qersh_wc_self_test' );
		check_admin_referer( 'qersh_wc_self_test' );
		$plugin = Module::plugin();
		$id     = isset( $_POST['wallet'] ) ? sanitize_key( wp_unslash( $_POST['wallet'] ) ) : '';
		if ( ! Config::wallet_id_ok( $id ) || null === $plugin->wallet( $id ) ) {
			Module::flash( 'error', esc_html__( 'Choose a wallet to check.', 'qersh-for-woocommerce' ) );
			Module::redirect( 'status' );
		}
		$result = $plugin->diagnostics()->self_test( $id );
		set_transient( self::RESULT_PREFIX . get_current_user_id(), $result, 15 * MINUTE_IN_SECONDS );
		Module::redirect( 'status', array( 'wallet' => $id ) );
	}

	/**
	 * The lines of a self-test result.
	 *
	 * @param array $result Diagnostics::self_test() result.
	 * @return array
	 */
	private static function result_view( array $result ): array {
		$plugin  = Module::plugin();
		$wallet  = $plugin->wallet( isset( $result['wallet'] ) ? (string) $result['wallet'] : '' );
		$probes  = array();
		$by_name = array();
		foreach ( isset( $result['probes'] ) && is_array( $result['probes'] ) ? $result['probes'] : array() as $probe ) {
			if ( ! is_array( $probe ) || ! isset( $probe['name'], $probe['verdict'] ) ) {
				continue;
			}
			$by_name[ $probe['name'] ] = $probe;

			$probes[] = array(
				'name' => self::probe_name( (string) $probe['name'] ),
				'url'  => isset( $probe['url'] ) ? (string) $probe['url'] : '',
				'ok'   => 'ok' === $probe['verdict'],
				'text' => self::verdict_text( $probe ),
			);
		}
		$extra = array();
		$link  = null;
		if ( isset( $by_name['current'], $by_name['fallback'] ) && 'ok' !== $by_name['current']['verdict'] && 'ok' === $by_name['fallback']['verdict'] && null !== $wallet ) {
			$extra[] = esc_html__( 'The fallback address works. Open your developer\'s link below on the wallet\'s phone so it sends there; it updates the same wallet and keeps the connection key. Then tap “Test connection”.', 'qersh-for-woocommerce' );
			$link    = Wallets::setup_link( (string) $by_name['fallback']['url'], View::wallet_name( $wallet ), (string) $wallet['provider'], null );
		}
		if ( isset( $by_name['txn'], $by_name['txn_fallback'] ) && 'ok' !== $by_name['txn']['verdict'] && 'ok' === $by_name['txn_fallback']['verdict'] ) {
			$blocker = '' !== (string) $by_name['txn']['detail'] && 'blocked' === $by_name['txn']['verdict'] ? (string) $by_name['txn']['detail'] : __( 'your security plugin or firewall', 'qersh-for-woocommerce' );
			/* translators: %s: name of a security plugin or firewall. */
			$extra[] = sprintf( esc_html__( 'Your store blocks REST requests from visitors, so the order page sends the transaction id through the plugin\'s fallback address. To use the normal one, allow the qersh routes in %s.', 'qersh-for-woocommerce' ), esc_html( $blocker ) );
		}
		return array(
			'wallet' => null === $wallet ? '' : View::wallet_name( $wallet ),
			'at'     => View::ltr( View::time( isset( $result['at'] ) ? (int) $result['at'] : time() ) ),
			'probes' => $probes,
			'extra'  => $extra,
			'link'   => $link,
		);
	}

	/**
	 * The name of a probe.
	 *
	 * @param string $name Probe name.
	 * @return string Plain text.
	 */
	private static function probe_name( string $name ): string {
		switch ( $name ) {
			case 'current':
				return __( 'Your store URL now', 'qersh-for-woocommerce' );
			case 'saved':
				return __( 'The URL the phone last used', 'qersh-for-woocommerce' );
			case 'fallback':
				return __( 'The fallback URL (?wc-api=)', 'qersh-for-woocommerce' );
			case 'txn':
				return __( 'The order page\'s transaction id route', 'qersh-for-woocommerce' );
			case 'txn_fallback':
				return __( 'Its fallback URL (?wc-api=)', 'qersh-for-woocommerce' );
		}
		return $name;
	}

	/**
	 * What a verdict means, with what to do (escaped HTML).
	 *
	 * @param array $probe Probe.
	 * @return string
	 */
	public static function verdict_text( array $probe ): string {
		$code   = isset( $probe['code'] ) ? (int) $probe['code'] : 0;
		$detail = isset( $probe['detail'] ) ? (string) $probe['detail'] : '';
		$txn    = isset( $probe['name'] ) && 0 === strpos( (string) $probe['name'], 'txn' );
		switch ( isset( $probe['verdict'] ) ? (string) $probe['verdict'] : '' ) {
			case 'ok':
				return $txn
					? esc_html__( 'Works: the plugin answered as expected.', 'qersh-for-woocommerce' )
					: esc_html__( 'Works: the plugin answered as expected (401, the test signature is invalid on purpose).', 'qersh-for-woocommerce' );
			case 'blocked':
				if ( '' !== $detail ) {
					/* translators: 1: name of a security plugin or firewall, 2: HTTP status code. */
					return sprintf( esc_html__( 'Blocked: %1$s answers %2$s before the plugin sees the request. Allow the qersh routes (/wp-json/qersh/v1/ and ?wc-api=qersh) in its settings.', 'qersh-for-woocommerce' ), esc_html( $detail ), View::ltr( (string) $code ) );
				}
				/* translators: %s: HTTP status code. */
				return sprintf( esc_html__( 'Blocked: a security plugin or firewall answers %s before the plugin sees the request. Allow the qersh routes (/wp-json/qersh/v1/ and ?wc-api=qersh) in it.', 'qersh-for-woocommerce' ), View::ltr( (string) $code ) );
			case 'cloudflare':
				return esc_html__( 'Cloudflare stops requests to this address with a challenge or a block, and the phone cannot pass it. In Cloudflare, add a rule that skips the challenge, Bot Fight Mode and the firewall for /wp-json/qersh/v1/ and ?wc-api=qersh.', 'qersh-for-woocommerce' );
			case 'redirect':
				/* translators: 1: HTTP status code, 2: URL. */
				return sprintf( esc_html__( 'This address redirects (%1$s) to %2$s, and the phone does not follow redirects. Open your new developer\'s link on the wallet\'s phone so it sends to the final address.', 'qersh-for-woocommerce' ), View::ltr( (string) $code ), View::ltr( '' === $detail ? '…' : $detail ) );
			case 'html':
				/* translators: %s: HTTP status code. */
				return sprintf( esc_html__( 'This address answers %s with a web page instead of the plugin: plain permalinks, or an old domain. Open your new developer\'s link on the wallet\'s phone.', 'qersh-for-woocommerce' ), View::ltr( (string) $code ) );
			case 'maintenance':
				return esc_html__( 'Your store is in maintenance mode, so it answers 503 to the phone. Transfers wait on the phone and arrive after the maintenance.', 'qersh-for-woocommerce' );
			case 'unavailable':
				return esc_html__( 'The plugin answers 503: WooCommerce is off, or the plugin\'s tables are missing.', 'qersh-for-woocommerce' );
			case 'route_missing':
				return esc_html__( 'The address answers, but the plugin\'s route is not there: a plugin removes REST routes, or the address belongs to another site.', 'qersh-for-woocommerce' );
			case 'other_wallet':
				return esc_html__( 'The plugin answers, but does not know this wallet at this address: the address belongs to another site or another copy of the store.', 'qersh-for-woocommerce' );
			case 'tls':
				/* translators: %s: error message. */
				return sprintf( esc_html__( 'The HTTPS certificate of this address could not be checked (%s). The phone needs a valid certificate.', 'qersh-for-woocommerce' ), esc_html( $detail ) );
			case 'inconclusive':
				/* translators: %s: error message. */
				return sprintf( esc_html__( 'Inconclusive: your server could not reach its own address (%s), so this check cannot tell what the phone gets. Tap “Test connection” on the phone instead.', 'qersh-for-woocommerce' ), esc_html( $detail ) );
			case 'skipped':
				return esc_html__( 'Not checked: your server could not reach this address in an earlier check, so this one was skipped. Tap “Test connection” on the phone instead.', 'qersh-for-woocommerce' );
		}
		/* translators: %s: HTTP status code. */
		return sprintf( esc_html__( 'Unexpected answer %s. Tap “Test connection” on the phone to see what it gets.', 'qersh-for-woocommerce' ), View::ltr( (string) $code ) );
	}

	/**
	 * Facts about the site, read without any request.
	 *
	 * @return array[] `[ 'label' => string, 'value' => escaped HTML, 'warn' => bool ]`
	 */
	private static function facts(): array {
		$plugin = Module::plugin();
		$out    = array();
		$add    = function ( $label, $value, $warn = false ) use ( &$out ) {
			$out[] = array(
				'label' => $label,
				'value' => $value,
				'warn'  => $warn,
			);
		};

		$yes = esc_html__( 'Yes', 'qersh-for-woocommerce' );
		$no  = esc_html__( 'No', 'qersh-for-woocommerce' );

		$add( __( 'Versions', 'qersh-for-woocommerce' ), View::ltr( 'Qersh for WooCommerce ' . QERSH_WC_VERSION . ' · WordPress ' . get_bloginfo( 'version' ) . ' · WooCommerce ' . ( defined( 'WC_VERSION' ) ? WC_VERSION : '—' ) . ' · PHP ' . PHP_VERSION ) );
		// SHOW TABLES too (admin screens only): Ready never shows before a query has noticed a missing table.
		$tables = $plugin->tables_ready() && \QershPay\WooCommerce\Store\Schema::tables_exist();
		$add( __( 'Tables', 'qersh-for-woocommerce' ), $tables ? esc_html__( 'Ready', 'qersh-for-woocommerce' ) : esc_html__( 'Missing', 'qersh-for-woocommerce' ), ! $tables );
		$rest = Diagnostics::url_parts( rest_url() );
		$add( __( 'REST address form', 'qersh-for-woocommerce' ), 'rest_route' === $rest['form'] ? esc_html__( 'Plain permalinks (index.php?rest_route=…)', 'qersh-for-woocommerce' ) : esc_html__( 'Pretty permalinks (/wp-json/…)', 'qersh-for-woocommerce' ) );
		$finish = \QershPay\WooCommerce\Plugin::finish_available();
		$add( __( 'Orders completed after the reply to the phone', 'qersh-for-woocommerce' ), $finish ? $yes : esc_html__( 'No: completed inside the request, with WooCommerce emails sent by Action Scheduler', 'qersh-for-woocommerce' ) );
		$cron = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$add( __( 'WordPress Cron turned off (DISABLE_WP_CRON)', 'qersh-for-woocommerce' ), $cron ? esc_html__( 'Yes: make sure a real Cron runs wp-cron.php', 'qersh-for-woocommerce' ) : $no );
		$add( __( 'Scheduled emails and actions', 'qersh-for-woocommerce' ), $plugin->diagnostics()->as_ok() ? esc_html__( 'On time', 'qersh-for-woocommerce' ) : esc_html__( 'Late', 'qersh-for-woocommerce' ), ! $plugin->diagnostics()->as_ok() );
		$tz = (string) get_option( 'timezone_string', '' );
		$add( __( 'Site time zone', 'qersh-for-woocommerce' ), View::ltr( '' === $tz ? (string) get_option( 'gmt_offset', '0' ) : $tz ), 'Africa/Cairo' !== $tz );
		$add( __( 'PHP knows Egypt\'s summer time', 'qersh-for-woocommerce' ), Diagnostics::tzdata_ok() ? $yes : $no, ! Diagnostics::tzdata_ok() );
		if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
			$add( __( 'High-performance order storage', 'qersh-for-woocommerce' ), \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? $yes : $no );
		}
		$software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '';
		if ( false !== stripos( $software, 'nginx' ) ) {
			$add(
				__( 'Web server', 'qersh-for-woocommerce' ),
				esc_html__( 'nginx: WooCommerce log files can be public on nginx. Make sure the log folder cannot be downloaded, for example with:', 'qersh-for-woocommerce' ) . ' ' . View::ltr( 'location ~* /wp-content/uploads/wc-logs/ { deny all; }' ),
				true
			);
		}
		return $out;
	}
}
