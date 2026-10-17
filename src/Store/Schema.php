<?php
/**
 * The five tables of the plugin, created and upgraded with dbDelta (docs/17 §4.3, docs/16 §8).
 *
 * @package QershPay\WooCommerce
 */

namespace QershPay\WooCommerce\Store;

defined( 'ABSPATH' ) || exit;

/**
 * Table definitions, the schema version check and the storage engine check.
 *
 * The CREATE statements follow the dbDelta rules: one field per line, two spaces after PRIMARY KEY, KEY and never
 * INDEX, no backticks. Integer columns carry their classic display width (`BIGINT(20) UNSIGNED`) so a second dbDelta
 * run changes nothing on MariaDB, which reports the width, as well as on MySQL 8, which dbDelta forgives for it.
 */
final class Schema {

	/**
	 * Option holding the installed schema version (QERSH_WC_DB_VERSION).
	 */
	const VERSION_OPTION = 'qersh_wc_db_version';

	/**
	 * Transient that pauses automatic installs for ten minutes after one failed (for example without CREATE rights).
	 */
	const RETRY_TRANSIENT = 'qersh_wc_schema_retry';

	/**
	 * Short table names, without `{prefix}qersh_`.
	 */
	const TABLES = array( 'deliveries', 'transfers', 'orders', 'wallets', 'locks' );

	/**
	 * Every column of every table and how the plugin writes it.
	 *
	 * `int`, `key:N` (a string that must fit N characters and is refused, never cut, when longer: it identifies or
	 * matches something), `nul:N?` (a value from outside that is compared for equality but may be longer: kept whole
	 * when it fits N characters, otherwise stored as NULL, never cut, so two different long values never become
	 * equal; an empty string is NULL too), `str:N` (a display string cut to N characters), `text` (up to 65535
	 * bytes), `json` and `json:N` (an array stored as JSON, read back as an array). A trailing `?` allows NULL.
	 */
	const COLUMNS = array(
		'deliveries' => array(
			'id'          => 'int',
			'delivery_id' => 'key:36',
			'wallet'      => 'key:32',
			'event'       => 'str:40',
			'redelivery'  => 'str:16?',
			'state'       => 'key:12',
			'claim_token' => 'key:32',
			'http_status' => 'int?',
			'reply_json'  => 'text?',
			'claimed_at'  => 'int',
			'answered_at' => 'int?',
		),
		'transfers'  => array(
			'id'               => 'int',
			'transfer_id'      => 'key:40',
			'wallet'           => 'key:32',
			'provider'         => 'str:32',
			'delivery_id'      => 'key:36',
			'amount_minor'     => 'int?',
			'currency'         => 'str:8',
			'payer_key'        => 'key:80?',
			'payer_name'       => 'str:120?',
			'provider_txn_id'  => 'nul:40?',
			'money_at'         => 'int',
			'sms_at'           => 'int?',
			'risk'             => 'str:16?',
			'risk_reasons'     => 'json?',
			'auto_confirm_ok'  => 'int',
			'balance_chain'    => 'str:24?',
			'outcome'          => 'key:16?',
			'reason'           => 'str:40?',
			'claim_eval'       => 'str:40?',
			'claim_eval_at'    => 'int?',
			'order_id'         => 'int?',
			'flag'             => 'str:32?',
			'phone_sync'       => 'key:8',
			'phone_sync_since' => 'int?',
			'phone_sync_count' => 'int',
			'note'             => 'str:255?',
			'created_at'       => 'int',
			'updated_at'       => 'int',
		),
		'orders'     => array(
			'order_id'      => 'int',
			'wallet'        => 'key:32',
			'payer_key'     => 'key:80',
			'price_minor'   => 'int',
			'paid_minor'    => 'int',
			'state'         => 'key:12',
			'created_at'    => 'int',
			'expires_at'    => 'int',
			'paid_at'       => 'int?',
			'completion'    => 'key:10',
			'completion_at' => 'int?',
			'claim_txn'     => 'key:40?',
			'claim_at'      => 'int?',
			'claim_tries'   => 'int',
		),
		'wallets'    => array(
			'wallet'                  => 'key:32',
			'state'                   => 'key:10',
			'deleted_at'              => 'int?',
			'gone_at'                 => 'int?',
			'key_erased_at'           => 'int?',
			'snapshot'                => 'json?',
			'last_delivery_at'        => 'int?',
			'last_event'              => 'str:40?',
			'last_heartbeat_at'       => 'int?',
			'last_device_state'       => 'json:255?',
			'grace_until'             => 'int?',
			'sender_masked'           => 'int',
			'auto_seen'               => 'int',
			'seen_host'               => 'str:190?',
			'seen_form'               => 'str:16?',
			'last_test_at'            => 'int?',
			'last_test_app_version'   => 'str:20?',
			'last_test_provider'      => 'str:32?',
			'last_test_number_masked' => 'str:16?',
			'silent_since'            => 'int?',
			'silent_notified'         => 'int',
		),
		'locks'      => array(
			'name'       => 'key:100',
			'created_at' => 'int',
		),
	);

	/**
	 * Runs on plugins_loaded: installs or upgrades the tables when the stored version is behind. The activation hook
	 * does not run on updates, so this check is what upgrades an updated plugin.
	 */
	public static function maybe_upgrade(): void {
		if ( (int) get_option( self::VERSION_OPTION, 0 ) >= self::target_version() ) {
			return;
		}
		if ( false !== get_transient( self::RETRY_TRANSIENT ) ) {
			return;
		}
		self::install();
	}

	/**
	 * Creates or upgrades the five tables, then records the schema version, but only once every table exists, so
	 * Plugin::tables_ready() stays false (and the routes answer 503) while they are missing.
	 */
	public static function install(): void {
		global $wpdb;
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		$suppress = $wpdb->suppress_errors( true );
		dbDelta( self::sql( $wpdb->prefix, $wpdb->get_charset_collate() ) );
		$wpdb->suppress_errors( $suppress );

		if ( ! self::tables_exist() ) {
			set_transient( self::RETRY_TRANSIENT, 1, 600 );
			self::log( 'warning', 'The Qersh tables could not be created. Check that the database user may CREATE and ALTER tables.' );
			return;
		}
		delete_transient( self::RETRY_TRANSIENT );
		update_option( self::VERSION_OPTION, self::target_version(), true );

		$other = self::non_innodb_tables();
		if ( ! empty( $other ) ) {
			self::log( 'warning', 'Some Qersh tables do not use InnoDB, so the locks that keep two deliveries apart do not work: ' . implode( ', ', $other ) );
		}
	}

	/**
	 * The CREATE TABLE statements for dbDelta. Pure: no WordPress call.
	 *
	 * @param string $prefix  Table prefix ($wpdb->prefix).
	 * @param string $collate Charset and collation clause ($wpdb->get_charset_collate()).
	 * @return string[] One statement per table, keyed by short name.
	 */
	public static function sql( string $prefix, string $collate ): array {
		$p   = $prefix . 'qersh_';
		$end = rtrim( ') ' . $collate ); // No trailing semicolon: dbDelta gets an array and runs each entry as it is.

		$deliveries = "CREATE TABLE {$p}deliveries (
id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
delivery_id CHAR(36) NOT NULL,
wallet VARCHAR(32) NOT NULL,
event VARCHAR(40) NOT NULL,
redelivery VARCHAR(16) NULL,
state VARCHAR(12) NOT NULL,
claim_token CHAR(32) NOT NULL,
http_status SMALLINT(6) NULL,
reply_json TEXT NULL,
claimed_at BIGINT(20) UNSIGNED NOT NULL,
answered_at BIGINT(20) UNSIGNED NULL,
PRIMARY KEY  (id),
UNIQUE KEY delivery_id (delivery_id),
KEY claimed_at (claimed_at)
{$end}";

		$transfers = "CREATE TABLE {$p}transfers (
id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
transfer_id VARCHAR(40) NOT NULL,
wallet VARCHAR(32) NOT NULL,
provider VARCHAR(32) NOT NULL,
delivery_id CHAR(36) NOT NULL,
amount_minor BIGINT(20) NULL,
currency VARCHAR(8) NOT NULL,
payer_key VARCHAR(80) NULL,
payer_name VARCHAR(120) NULL,
provider_txn_id VARCHAR(40) NULL,
money_at BIGINT(20) UNSIGNED NOT NULL,
sms_at BIGINT(20) UNSIGNED NULL,
risk VARCHAR(16) NULL,
risk_reasons TEXT NULL,
auto_confirm_ok TINYINT(4) NOT NULL DEFAULT 0,
balance_chain VARCHAR(24) NULL,
outcome VARCHAR(16) NULL,
reason VARCHAR(40) NULL,
claim_eval VARCHAR(40) NULL,
claim_eval_at BIGINT(20) UNSIGNED NULL,
order_id BIGINT(20) UNSIGNED NULL,
flag VARCHAR(32) NULL,
phone_sync VARCHAR(8) NOT NULL DEFAULT 'none',
phone_sync_since BIGINT(20) UNSIGNED NULL,
phone_sync_count TINYINT(4) NOT NULL DEFAULT 0,
note VARCHAR(255) NULL,
created_at BIGINT(20) UNSIGNED NOT NULL,
updated_at BIGINT(20) UNSIGNED NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY transfer_id (transfer_id),
KEY provider_txn (provider,provider_txn_id),
KEY outcome_created (outcome,created_at),
KEY order_id (order_id),
KEY payer_key (payer_key),
KEY wallet_sync (wallet,phone_sync)
{$end}";

		$orders = "CREATE TABLE {$p}orders (
order_id BIGINT(20) UNSIGNED NOT NULL,
wallet VARCHAR(32) NOT NULL,
payer_key VARCHAR(80) NOT NULL,
price_minor BIGINT(20) NOT NULL,
paid_minor BIGINT(20) NOT NULL DEFAULT 0,
state VARCHAR(12) NOT NULL,
created_at BIGINT(20) UNSIGNED NOT NULL,
expires_at BIGINT(20) UNSIGNED NOT NULL,
paid_at BIGINT(20) UNSIGNED NULL,
completion VARCHAR(10) NOT NULL DEFAULT 'none',
completion_at BIGINT(20) UNSIGNED NULL,
claim_txn VARCHAR(40) NULL,
claim_at BIGINT(20) UNSIGNED NULL,
claim_tries TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
PRIMARY KEY  (order_id),
KEY payer_state (payer_key,state,created_at),
KEY wallet_state (wallet,state,created_at),
KEY claim_txn (claim_txn)
{$end}";

		$wallets = "CREATE TABLE {$p}wallets (
wallet VARCHAR(32) NOT NULL,
state VARCHAR(10) NOT NULL DEFAULT 'active',
deleted_at BIGINT(20) UNSIGNED NULL,
gone_at BIGINT(20) UNSIGNED NULL,
key_erased_at BIGINT(20) UNSIGNED NULL,
snapshot TEXT NULL,
last_delivery_at BIGINT(20) UNSIGNED NULL,
last_event VARCHAR(40) NULL,
last_heartbeat_at BIGINT(20) UNSIGNED NULL,
last_device_state VARCHAR(255) NULL,
grace_until BIGINT(20) UNSIGNED NULL,
sender_masked TINYINT(4) NOT NULL DEFAULT 0,
auto_seen TINYINT(4) NOT NULL DEFAULT 0,
seen_host VARCHAR(190) NULL,
seen_form VARCHAR(16) NULL,
last_test_at BIGINT(20) UNSIGNED NULL,
last_test_app_version VARCHAR(20) NULL,
last_test_provider VARCHAR(32) NULL,
last_test_number_masked VARCHAR(16) NULL,
silent_since BIGINT(20) UNSIGNED NULL,
silent_notified TINYINT(4) NOT NULL DEFAULT 0,
PRIMARY KEY  (wallet),
KEY state_deleted (state,deleted_at)
{$end}";

		$locks = "CREATE TABLE {$p}locks (
name VARCHAR(100) NOT NULL,
created_at BIGINT(20) UNSIGNED NOT NULL,
PRIMARY KEY  (name)
{$end}";

		return array(
			'deliveries' => $deliveries,
			'transfers'  => $transfers,
			'orders'     => $orders,
			'wallets'    => $wallets,
			'locks'      => $locks,
		);
	}

	/**
	 * Full table names for a prefix (for uninstall and diagnostics). Pure.
	 *
	 * @param string $prefix Table prefix.
	 * @return string[]
	 */
	public static function table_names( string $prefix ): array {
		$names = array();
		foreach ( self::TABLES as $short ) {
			$names[] = $prefix . 'qersh_' . $short;
		}
		return $names;
	}

	/**
	 * Parses one entry of COLUMNS into [ type, max length or 0, nullable ]. Pure.
	 *
	 * @param string $spec Entry such as `key:40?`.
	 * @return array{0: string, 1: int, 2: bool}
	 */
	public static function parse_spec( string $spec ): array {
		$nullable = '?' === substr( $spec, -1 );
		if ( $nullable ) {
			$spec = substr( $spec, 0, -1 );
		}
		$parts = explode( ':', $spec, 2 );
		return array( $parts[0], isset( $parts[1] ) ? (int) $parts[1] : 0, $nullable );
	}

	/**
	 * True when every table exists.
	 *
	 * @return bool
	 */
	public static function tables_exist(): bool {
		global $wpdb;
		$suppress = $wpdb->suppress_errors( true );
		$ok       = true;
		foreach ( self::table_names( $wpdb->prefix ) as $name ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $name ) ) );
			if ( $found !== $name ) {
				$ok = false;
				break;
			}
		}
		$wpdb->suppress_errors( $suppress );
		return $ok;
	}

	/**
	 * Our tables whose storage engine is not InnoDB (MySQL and MariaDB only; empty on SQLite, or when the engine
	 * cannot be read). Without InnoDB the transactions and row locks of docs/17 §4.4 step 8 do nothing.
	 *
	 * @return string[] Table names.
	 */
	public static function non_innodb_tables(): array {
		global $wpdb;
		if ( ( new Db() )->is_sqlite() ) {
			return array();
		}
		$names        = self::table_names( $wpdb->prefix );
		$placeholders = implode( ', ', array_fill( 0, count( $names ), '%s' ) );
		$suppress     = $wpdb->suppress_errors( true );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- only a list of %s placeholders is interpolated; the names are prepared.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- information_schema
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT TABLE_NAME AS t, ENGINE AS e FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ($placeholders)", $names ), 'ARRAY_A' );
		// phpcs:enable
		$wpdb->suppress_errors( $suppress );
		$other = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( isset( $row['t'], $row['e'] ) && 'innodb' !== strtolower( (string) $row['e'] ) ) {
				$other[] = (string) $row['t'];
			}
		}
		return $other;
	}

	/**
	 * The schema version this code expects.
	 *
	 * @return int
	 */
	private static function target_version(): int {
		return defined( 'QERSH_WC_DB_VERSION' ) ? (int) QERSH_WC_DB_VERSION : 1;
	}

	/**
	 * Writes a line through the plugin logger when WooCommerce is there (activation can run without it).
	 *
	 * @param string $level   Level.
	 * @param string $message Message.
	 */
	private static function log( string $level, string $message ): void {
		$logger = new \QershPay\WooCommerce\Logger();
		if ( 'warning' === $level ) {
			$logger->warning( $message );
		} else {
			$logger->info( $message );
		}
	}
}
