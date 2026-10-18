<?php
/**
 * What uninstall.php does (docs/17 §4.1): the plugin's data goes only when the merchant asked for it.
 *
 * @package QershPay\WooCommerce
 */

namespace QershPay\WooCommerce\Admin;

use QershPay\WooCommerce\Core\Config;
use QershPay\WooCommerce\Secrets;
use QershPay\WooCommerce\Store\Db;
use QershPay\WooCommerce\Store\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Runs for every site of a network. On each site the scheduled actions of the plugin are removed (they would fail
 * without it); the tables, options, transients and connection keys are removed only with
 * store.remove_data_on_uninstall, or QERSH_WC_REMOVE_ALL_DATA, which decides instead of the setting when defined
 * (as WC_REMOVE_ALL_DATA does for WooCommerce). Orders, their notes and their `_qersh_*` meta stay: they are the
 * store's records.
 */
final class Uninstall {

	/**
	 * Options of the plugin.
	 */
	const OPTIONS = array(
		'qersh_wc_config',
		'qersh_wc_db_version',
		'qersh_wc_as_ok',
		'qersh_wc_txn_drift',
		'qersh_wc_txn_long',
		'qersh_wc_notices',
		'woocommerce_qersh_settings',
		'woocommerce_qersh_instructions_settings',
		'woocommerce_qersh_admin_alert_settings',
	);

	/**
	 * Hooks of the plugin's scheduled actions.
	 */
	const HOOKS = array( 'qersh_wc_complete', 'qersh_wc_expire', 'qersh_wc_claim_eval', 'qersh_wc_sweep', 'qersh_wc_daily' );

	/**
	 * Uninstalls on this site, or on every site of the network.
	 */
	public static function run(): void {
		if ( function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'get_sites' ) ) {
			$sites = get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			);
			foreach ( $sites as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::site();
				restore_current_blog();
			}
			return;
		}
		self::site();
	}

	/**
	 * Whether the data goes. Pure.
	 *
	 * @param mixed $config           The configuration (option or decoded QERSH_WC_CONFIG).
	 * @param bool  $constant_defined Whether QERSH_WC_REMOVE_ALL_DATA is defined.
	 * @param mixed $constant_value   Its value.
	 * @return bool
	 */
	public static function should_remove( $config, bool $constant_defined, $constant_value ): bool {
		if ( $constant_defined ) {
			return true === $constant_value || 1 === $constant_value || '1' === $constant_value || 'true' === $constant_value;
		}
		return is_array( $config ) && isset( $config['store'] ) && is_array( $config['store'] ) && isset( $config['store']['remove_data_on_uninstall'] ) && true === $config['store']['remove_data_on_uninstall'];
	}

	/**
	 * One site.
	 */
	private static function site(): void {
		self::unschedule();
		if ( ! self::should_remove( self::config(), defined( 'QERSH_WC_REMOVE_ALL_DATA' ), defined( 'QERSH_WC_REMOVE_ALL_DATA' ) ? constant( 'QERSH_WC_REMOVE_ALL_DATA' ) : null ) ) {
			return;
		}
		global $wpdb;
		$db  = new Db();
		$ids = self::wallet_ids( $db );

		$secrets = new Secrets();
		foreach ( $ids as $id ) {
			try {
				$secrets->erase( $id );
			} catch ( \Throwable $e ) {
				delete_option( Secrets::OPTION_PREFIX . $id );
			}
		}
		foreach ( Schema::table_names( $wpdb->prefix ) as $table ) {
			$db->query( 'DROP TABLE IF EXISTS %i', array( $table ) );
		}
		foreach ( self::OPTIONS as $option ) {
			delete_option( $option );
		}
		foreach ( self::option_names( $db, $wpdb, '_transient_qersh_wc_' ) as $name ) {
			delete_transient( substr( $name, strlen( '_transient_' ) ) );
		}
		foreach ( self::option_names( $db, $wpdb, '_transient_timeout_qersh_wc_' ) as $name ) {
			delete_option( $name );
		}
		foreach ( self::option_names( $db, $wpdb, Secrets::OPTION_PREFIX ) as $name ) {
			delete_option( $name );
		}
	}

	/**
	 * The site's configuration: QERSH_WC_CONFIG when defined, otherwise the option.
	 *
	 * @return mixed
	 */
	private static function config() {
		if ( defined( 'QERSH_WC_CONFIG' ) ) {
			$raw = constant( 'QERSH_WC_CONFIG' );
			return is_array( $raw ) ? $raw : ( is_string( $raw ) ? json_decode( $raw, true ) : null );
		}
		return get_option( 'qersh_wc_config', array() );
	}

	/**
	 * Every wallet id that may have a key: in the configuration, in the wallet rows, or in a secret option name.
	 *
	 * @param Db $db Database helper.
	 * @return string[]
	 */
	private static function wallet_ids( Db $db ): array {
		global $wpdb;
		$ids    = array();
		$config = self::config();
		foreach ( is_array( $config ) && isset( $config['wallets'] ) && is_array( $config['wallets'] ) ? $config['wallets'] : array() as $wallet ) {
			if ( is_array( $wallet ) && isset( $wallet['id'] ) && Config::wallet_id_ok( $wallet['id'] ) ) {
				$ids[] = $wallet['id'];
			}
		}
		try {
			foreach ( $db->rows( 'SELECT wallet FROM %i', array( $db->table( 'wallets' ) ) ) as $row ) {
				if ( Config::wallet_id_ok( $row['wallet'] ) ) {
					$ids[] = (string) $row['wallet'];
				}
			}
		} catch ( \Throwable $e ) {
			unset( $e ); // The table may be gone already.
		}
		foreach ( self::option_names( $db, $wpdb, Secrets::OPTION_PREFIX ) as $name ) {
			$id = substr( $name, strlen( Secrets::OPTION_PREFIX ) );
			if ( Config::wallet_id_ok( $id ) ) {
				$ids[] = $id;
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Option names that start with a prefix.
	 *
	 * @param Db     $db     Database helper.
	 * @param \wpdb  $wpdb   WordPress database.
	 * @param string $prefix Prefix.
	 * @return string[]
	 */
	private static function option_names( Db $db, $wpdb, string $prefix ): array {
		try {
			$rows = $db->rows( 'SELECT option_name FROM %i WHERE option_name LIKE %s', array( $wpdb->options, $wpdb->esc_like( $prefix ) . '%' ) );
		} catch ( \Throwable $e ) {
			return array();
		}
		$out = array();
		foreach ( $rows as $row ) {
			if ( isset( $row['option_name'] ) ) {
				$out[] = (string) $row['option_name'];
			}
		}
		return $out;
	}

	/**
	 * Removes the plugin's scheduled actions (they would fail once the plugin is gone).
	 *
	 * Each call names the hook alone, or the group alone. With a hook and a group together, Action Scheduler
	 * matches the arguments exactly, so array() would cancel only the actions scheduled without arguments (the
	 * sweep and the daily cleanup) and leave every per-order action ( order_id ) pending.
	 */
	private static function unschedule(): void {
		if ( ! function_exists( 'as_unschedule_all_actions' ) || ! did_action( 'action_scheduler_init' ) ) {
			return;
		}
		foreach ( self::unschedule_calls() as $call ) {
			try {
				as_unschedule_all_actions( $call[0], $call[1], $call[2] );
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}
	}

	/**
	 * The as_unschedule_all_actions() calls of unschedule(), as `[ hook, args, group ]`: the whole `qersh` group,
	 * then each hook in any group. Pure.
	 *
	 * @return array[]
	 */
	public static function unschedule_calls(): array {
		$calls = array( array( '', array(), 'qersh' ) );
		foreach ( self::HOOKS as $hook ) {
			$calls[] = array( $hook, array(), '' );
		}
		return $calls;
	}
}
