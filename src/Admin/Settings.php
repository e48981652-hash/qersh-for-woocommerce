<?php
/**
 * The Settings tab: the store values of qersh_wc_config (docs/17 §4.2), import and export without keys or runtime
 * state, read-only with QERSH_WC_CONFIG.
 *
 * @package QershPay\WooCommerce
 */

namespace QershPay\WooCommerce\Admin;

use QershPay\WooCommerce\Core\Money;

defined( 'ABSPATH' ) || exit;

/**
 * The gateway's display texts (title, description, instructions) stay on WooCommerce → Settings → Payments; this
 * page holds every value that changes behaviour.
 */
final class Settings {

	/**
	 * The longest import read.
	 */
	const IMPORT_MAX = 262144;

	/**
	 * The time to transfer, in minutes (store.order_ttl_min, docs/17 §4.7).
	 */
	const TTL_MIN = 15;

	/**
	 * Its largest value.
	 */
	const TTL_MAX = 1440;

	/**
	 * Days an unmatched transfer keeps its payer (store.unmatched_retention_days).
	 */
	const RETENTION_MIN = 1;

	/**
	 * Its largest value.
	 */
	const RETENTION_MAX = 3650;

	/**
	 * Renders the tab.
	 */
	public static function render() {
		$plugin   = Module::plugin();
		$store    = $plugin->config_store();
		$config   = $plugin->config();
		$s        = $config['store'];
		$decimals = function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2;
		Module::template(
			'settings',
			array(
				'constant'      => $store->is_constant(),
				'issues'        => $store->is_constant() ? $store->issues() : array(),
				'store'         => $s,
				'min_total'     => self::pounds( $s['min_total_minor'] ),
				'max_total'     => self::pounds( $s['max_total_minor'] ),
				'late_min'      => (int) $config['receiver']['matching']['late_window_min'],
				'piaster_hint'  => $decimals > 0 && empty( $s['round_up_to_pound'] ),
				'payments_url'  => admin_url( 'admin.php?page=wc-settings&tab=checkout&section=qersh' ),
				'remove_forced' => defined( 'QERSH_WC_REMOVE_ALL_DATA' ),
			)
		);
	}

	/**
	 * Piasters shown as pounds in a field ('' for none). Pure.
	 *
	 * @param mixed $minor Piasters or null.
	 * @return string
	 */
	public static function pounds( $minor ): string {
		if ( ! is_int( $minor ) ) {
			return '';
		}
		$pounds = intdiv( $minor, 100 );
		$rest   = $minor % 100;
		return 0 === $rest ? (string) $pounds : $pounds . '.' . str_pad( (string) $rest, 2, '0', STR_PAD_LEFT );
	}

	/**
	 * A pounds field to piasters: '' is no limit (null), anything else must be a plain amount. Pure.
	 *
	 * @param string $text Field value.
	 * @return array `[ 'ok' => bool, 'value' => ?int ]`
	 */
	public static function to_minor( string $text ): array {
		$text = trim( str_replace( array( ',', ' ' ), '', $text ) );
		if ( '' === $text ) {
			return array(
				'ok'    => true,
				'value' => null,
			);
		}
		$minor = Money::to_minor( $text );
		return array(
			'ok'    => null !== $minor,
			'value' => $minor,
		);
	}

	/**
	 * What is wrong with the store values of the form, by Core\Config's store rules (docs/17 §4.2): `order_ttl`
	 * (15 to 1440 minutes), `retention` (1 to 3650 days), `min_max` (the smallest total above the largest). Pure.
	 *
	 * @param array $s The store values.
	 * @return string[]
	 */
	public static function store_problems( array $s ): array {
		$out = array();
		$ttl = isset( $s['order_ttl_min'] ) ? $s['order_ttl_min'] : null;
		if ( ! is_int( $ttl ) || $ttl < self::TTL_MIN || $ttl > self::TTL_MAX ) {
			$out[] = 'order_ttl';
		}
		$days = isset( $s['unmatched_retention_days'] ) ? $s['unmatched_retention_days'] : null;
		if ( ! is_int( $days ) || $days < self::RETENTION_MIN || $days > self::RETENTION_MAX ) {
			$out[] = 'retention';
		}
		$min = isset( $s['min_total_minor'] ) ? $s['min_total_minor'] : null;
		$max = isset( $s['max_total_minor'] ) ? $s['max_total_minor'] : null;
		if ( is_int( $min ) && is_int( $max ) && $min > $max ) {
			$out[] = 'min_max';
		}
		return $out;
	}

	/**
	 * The merchant's words for a store_problems() code.
	 *
	 * @param string $problem Code.
	 * @return string Plain text.
	 */
	public static function problem_text( string $problem ): string {
		switch ( $problem ) {
			case 'order_ttl':
				/* translators: 1: smallest number of minutes, 2: largest number of minutes. */
				return sprintf( __( 'Enter the time to transfer as a whole number of minutes from %1$s to %2$s.', 'qersh-for-woocommerce' ), number_format_i18n( self::TTL_MIN ), number_format_i18n( self::TTL_MAX ) );
			case 'retention':
				/* translators: 1: smallest number of days, 2: largest number of days. */
				return sprintf( __( 'Enter how long unmatched transfers are kept as a whole number of days from %1$s to %2$s.', 'qersh-for-woocommerce' ), number_format_i18n( self::RETENTION_MIN ), number_format_i18n( self::RETENTION_MAX ) );
			case 'min_max':
				return __( 'The minimum order total cannot be larger than the maximum.', 'qersh-for-woocommerce' );
		}
		return __( 'The settings were not saved. Check the values and try again.', 'qersh-for-woocommerce' );
	}

	/**
	 * Saves the store values (admin-post qersh_wc_settings_save).
	 */
	public static function handle_save() {
		Module::check_action( 'qersh_wc_settings_save' );
		check_admin_referer( 'qersh_wc_settings_save' );
		$store = Module::plugin()->config_store();
		if ( $store->is_constant() ) {
			Module::flash( 'error', esc_html__( 'These settings are set in wp-config.php (QERSH_WC_CONFIG) and cannot be changed here.', 'qersh-for-woocommerce' ) );
			Module::redirect( 'settings' );
		}
		$config = $store->get();
		$s      = $config['store'];
		$min    = self::to_minor( isset( $_POST['min_total'] ) ? sanitize_text_field( wp_unslash( $_POST['min_total'] ) ) : '' );
		$max    = self::to_minor( isset( $_POST['max_total'] ) ? sanitize_text_field( wp_unslash( $_POST['max_total'] ) ) : '' );
		if ( ! $min['ok'] || ! $max['ok'] ) {
			Module::flash( 'error', esc_html__( 'Enter the order limits as amounts in pounds, such as 100 or 99.50, or leave them empty.', 'qersh-for-woocommerce' ) );
			Module::redirect( 'settings' );
		}
		$s['order_ttl_min']                = isset( $_POST['order_ttl_min'] ) ? absint( wp_unslash( $_POST['order_ttl_min'] ) ) : (int) $s['order_ttl_min'];
		$s['unmatched_retention_days']     = isset( $_POST['unmatched_retention_days'] ) ? absint( wp_unslash( $_POST['unmatched_retention_days'] ) ) : (int) $s['unmatched_retention_days'];
		$s['min_total_minor']              = $min['value'];
		$s['max_total_minor']              = $max['value'];
		$s['round_up_to_pound']            = ! empty( $_POST['round_up_to_pound'] );
		$s['allow_key_link']               = ! empty( $_POST['allow_key_link'] );
		$s['txn_entry']                    = ! empty( $_POST['txn_entry'] );
		$s['admin_cancel_email_on_expiry'] = ! empty( $_POST['admin_cancel_email_on_expiry'] );
		$s['remove_data_on_uninstall']     = ! empty( $_POST['remove_data_on_uninstall'] );
		// Our own translated words first: Core\Config's messages are developer English with JSON paths, kept only
		// as the last fallback below.
		$problems = self::store_problems( $s );
		if ( ! empty( $problems ) ) {
			foreach ( $problems as $problem ) {
				Module::flash( 'error', esc_html( self::problem_text( $problem ) ) );
			}
			Module::redirect( 'settings' );
		}
		$config['store'] = $s;
		$result          = $store->save( $config );
		if ( is_wp_error( $result ) ) {
			Module::flash( 'error', esc_html( $result->get_error_message() ) );
		} else {
			Module::flash( 'success', esc_html__( 'The settings were saved.', 'qersh-for-woocommerce' ) );
		}
		Module::redirect( 'settings' );
	}

	/**
	 * Imports settings pasted as JSON (admin-post qersh_wc_config_import). The imported wallets replace the current
	 * ones; a wallet missing from the import is deleted (and can be restored for 72 hours).
	 */
	public static function handle_import() {
		Module::check_action( 'qersh_wc_config_import' );
		check_admin_referer( 'qersh_wc_config_import' );
		$store = Module::plugin()->config_store();
		if ( $store->is_constant() ) {
			Module::flash( 'error', esc_html__( 'These settings are set in wp-config.php (QERSH_WC_CONFIG) and cannot be changed here.', 'qersh-for-woocommerce' ) );
			Module::redirect( 'settings' );
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON is decoded and every value validated by Core\Config.
		$raw = isset( $_POST['config_json'] ) && is_string( $_POST['config_json'] ) ? (string) wp_unslash( $_POST['config_json'] ) : '';
		if ( strlen( $raw ) > self::IMPORT_MAX ) {
			$raw = '';
		}
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			Module::flash( 'error', esc_html__( 'Paste the settings file exported from Qersh for WooCommerce: a JSON object.', 'qersh-for-woocommerce' ) );
			Module::redirect( 'settings' );
		}
		$result = $store->import( $decoded );
		if ( is_wp_error( $result ) ) {
			Module::flash( 'error', esc_html( $result->get_error_message() ) );
		} else {
			Module::flash( 'success', esc_html__( 'The settings were imported. Connection keys are never part of a settings file: link each new wallet\'s phone from its card.', 'qersh-for-woocommerce' ) );
		}
		Module::redirect( 'settings' );
	}

	/**
	 * Downloads the settings as JSON (admin-post qersh_wc_config_export): no keys, no runtime state.
	 */
	public static function handle_export() {
		Module::check_action( 'qersh_wc_config_export' );
		check_admin_referer( 'qersh_wc_config_export' );
		$export = Module::plugin()->config_store()->export();
		$json   = wp_json_encode( $export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="qersh-for-woocommerce-settings-' . gmdate( 'Y-m-d' ) . '.json"' );
		header( 'X-Content-Type-Options: nosniff' );
		echo is_string( $json ) ? $json : '{}'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a JSON file download, not HTML.
		exit;
	}
}
