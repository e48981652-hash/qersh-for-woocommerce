<?php
/**
 * The five tables of the plugin, created and upgraded with dbDelta (docs/17 §4.3, docs/16 §8).
 *
 * @package QershPay\WooCommerce
 */

namespace QershPay\WooCommerce\Store;

use QershPay\WooCommerce\Core\TxnId;

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
	 * Transient set for ten minutes when forget_version() ran: a second loss in that time pauses automatic installs.
	 */
	const LOST_TRANSIENT = 'qersh_wc_schema_lost';

	/**
	 * Table prefixes (one per site of a network) for which a query of this request found one of the tables missing,
	 * as keys (Db reports it through table_missing()).
	 *
	 * @var array<string, true>
	 */
	private static $lost = array();

	/**
	 * Short table names, without `{prefix}qersh_`.
	 */
	const TABLES = array( 'deliveries', 'transfers', 'orders', 'wallets', 'locks' );

	/**
	 * The schema version this code installs when QERSH_WC_DB_VERSION is not defined (the main plugin file defines it
	 * with the same value). Version 2: `transfers.provider` is an opaque copy of the envelope's `wallet.provider`
	 * with a default of '', and transaction ids are looked up by `provider_txn_id` alone, on any wallet (the plugin
	 * never asks which kind of e-wallet a transfer came from).
	 */
	const VERSION = 2;

	/**
	 * Options written by schema 1 that this code no longer reads: the transaction-id format drift record and the
	 * count of refused long ids (the plugin no longer knows the shape of any operator's ids). The upgrade to schema 2
	 * deletes them; Admin\Uninstall::OPTIONS still lists both names, for a store whose upgrade never ran.
	 */
	const OBSOLETE_OPTIONS = array( 'qersh_wc_txn_drift', 'qersh_wc_txn_long' );

	/**
	 * Indexes of schema 1 that the CREATE statements no longer hold, by table: dbDelta adds a missing index but never
	 * drops one, so the upgrade to schema 2 drops `transfers.provider_txn` (wallet type and transaction id) itself,
	 * after dbDelta added `provider_txn_id` in its place.
	 */
	const OBSOLETE_INDEXES = array(
		'transfers' => array( 'provider_txn' ),
	);

	/**
	 * Transfer rows read at a time by the upgrade to schema 2 when it rewrites their transaction ids.
	 */
	const TXN_BATCH = 500;

	/**
	 * Every column of every table and how the plugin writes it.
	 *
	 * `int`, `key:N` (a string that must fit N characters and is refused, never cut, when longer: it identifies or
	 * matches something), `nul:N?` (a value from outside that is compared for equality but may be longer: kept whole
	 * when it fits N characters, otherwise stored as NULL, never cut, so two different long values never become
	 * equal; an empty string is NULL too), `str:N` (a display string cut to N characters), `text` (up to 65535
	 * bytes), `json` and `json:N` (an array stored as JSON, read back as an array). A trailing `?` allows NULL.
	 *
	 * `transfers.provider` and `wallets.last_test_provider` keep the envelope's `wallet.provider` as information
	 * only: nothing in the plugin decides by them.
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
	 * Plugin::tables_ready() stays false (and the routes answer 503) while they are missing. The steps of upgrade()
	 * run between the two, so a store is never marked upgraded before them.
	 *
	 * On a store of schema 1, dbDelta gives `transfers.provider` its default of '' (ALTER COLUMN … SET DEFAULT) and
	 * adds the key `provider_txn_id`; the rows stay as they are. The SQLite drop-in adds the key but leaves the
	 * column without a default, so Transfers::insert() always writes the column and nothing relies on the default.
	 */
	public static function install(): void {
		global $wpdb;
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		$from     = (int) get_option( self::VERSION_OPTION, 0 );
		$suppress = $wpdb->suppress_errors( true );
		dbDelta( self::sql( $wpdb->prefix, $wpdb->get_charset_collate() ) );
		$wpdb->suppress_errors( $suppress );

		if ( ! self::tables_exist() ) {
			set_transient( self::RETRY_TRANSIENT, 1, 600 );
			self::log( 'warning', 'The Qersh tables could not be created. Check that the database user may CREATE and ALTER tables.' );
			return;
		}
		self::upgrade( $from );
		delete_transient( self::RETRY_TRANSIENT );
		update_option( self::VERSION_OPTION, self::target_version(), true );
		unset( self::$lost[ (string) $wpdb->prefix ] );

		$other = self::non_innodb_tables();
		if ( ! empty( $other ) ) {
			self::log( 'warning', 'Some Qersh tables do not use InnoDB, so the locks that keep two deliveries apart do not work: ' . implode( ', ', $other ) );
		}
	}

	/**
	 * The upgrade steps dbDelta cannot do, each for a store recorded below its schema version. A store with no
	 * version recorded (a new one, or one whose tables were found missing) runs them all: they find nothing to change
	 * there. Runs once the tables exist. Never fails the install: a step the database refuses leaves something unused
	 * behind, nothing the plugin reads.
	 *
	 * Schema 2: deletes OBSOLETE_OPTIONS, drops OBSOLETE_INDEXES and rewrites the stored transaction ids in their
	 * canonical form (canonicalize_txn_ids()).
	 *
	 * @param int $from The schema version recorded before this install.
	 */
	private static function upgrade( int $from ): void {
		if ( $from >= 2 ) {
			return;
		}
		foreach ( self::OBSOLETE_OPTIONS as $option ) {
			delete_option( $option );
		}
		self::drop_obsolete_indexes();
		self::canonicalize_txn_ids();
	}

	/**
	 * Rewrites each stored `transfers.provider_txn_id` in the canonical form of Core\TxnId::canonical_stored(),
	 * which schema 2 stores for every new transfer and compares by exact equality (a repeated id, rule 2, and the id
	 * a customer types). Schema 1 kept the id of most wallets as the phone sent it («2500517294 4183»,
	 * «ft-2412305»), which would never equal the same id delivered again. In batches of TXN_BATCH by row id; a
	 * value without a canonical form stays as it is (it equals no canonical id), and a canonical one is not
	 * written, so running it again changes nothing. Errors are suppressed, as in drop_obsolete_indexes().
	 */
	private static function canonicalize_txn_ids(): void {
		global $wpdb;
		$table    = $wpdb->prefix . 'qersh_transfers';
		$last     = 0;
		$suppress = $wpdb->suppress_errors( true );
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, read once per upgrade
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, provider_txn_id FROM %i WHERE id > %d AND provider_txn_id IS NOT NULL ORDER BY id ASC LIMIT %d', $table, $last, self::TXN_BATCH ), 'ARRAY_A' );
			$rows = is_array( $rows ) ? $rows : array();
			$full = count( $rows ) === self::TXN_BATCH;
			foreach ( $rows as $row ) {
				$last  = (int) $row['id'];
				$value = (string) $row['provider_txn_id'];
				$canon = TxnId::canonical_stored( $value );
				if ( null !== $canon && $canon !== $value ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, written once per upgrade
					$wpdb->query( $wpdb->prepare( 'UPDATE %i SET provider_txn_id = %s WHERE id = %d', $table, $canon, $last ) );
				}
			}
		} while ( $full );
		$wpdb->suppress_errors( $suppress );
	}

	/**
	 * Drops the indexes of OBSOLETE_INDEXES that a table still has, by the names SHOW INDEX gives (the statement
	 * dbDelta itself reads, also on the SQLite drop-in). Errors are suppressed: two requests upgrading at once may
	 * both try, and the second one's drop simply fails.
	 */
	private static function drop_obsolete_indexes(): void {
		global $wpdb;
		$suppress = $wpdb->suppress_errors( true );
		foreach ( self::OBSOLETE_INDEXES as $short => $indexes ) {
			$table = $wpdb->prefix . 'qersh_' . $short;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, read once per upgrade
			$rows  = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i', $table ), 'ARRAY_A' );
			$found = array();
			foreach ( is_array( $rows ) ? $rows : array() as $row ) {
				if ( is_array( $row ) && isset( $row['Key_name'] ) && is_string( $row['Key_name'] ) ) {
					$found[ strtolower( $row['Key_name'] ) ] = true;
				}
			}
			foreach ( $indexes as $index ) {
				if ( isset( $found[ $index ] ) ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- an index of our own table that no code uses any more
					$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP INDEX %i', $table, $index ) );
				}
			}
		}
		$wpdb->suppress_errors( $suppress );
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
provider VARCHAR(32) NOT NULL DEFAULT '',
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
KEY provider_txn_id (provider_txn_id),
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
	 * True when a query of this request found one of the current site's tables missing. Plugin::tables_ready() is
	 * then false for the rest of the request, even while an open transaction still holds back forget_version().
	 *
	 * @return bool
	 */
	public static function lost(): bool {
		return isset( self::$lost[ self::prefix() ] );
	}

	/**
	 * Called by Db when a query reports one of our tables missing. Db then calls forget_version(), at once or right
	 * after the open transaction ends (a rollback would undo the delete of the option).
	 *
	 * @param string|null $prefix Table prefix of the query, or null for the current site's.
	 */
	public static function table_missing( ?string $prefix = null ): void {
		self::$lost[ null === $prefix ? self::prefix() : $prefix ] = true;
	}

	/**
	 * Forgets the installed schema version after a query found one of the tables missing although it was installed
	 * (a site moved or restored without the plugin's tables, a host's cleanup). Plugin::tables_ready() then turns
	 * false: the routes answer 503, the payment method is hidden and the tables alert shows, and the next request's
	 * maybe_upgrade() creates the tables again. When the tables are lost again within ten minutes (recreated and
	 * lost, or listed by SHOW TABLES yet unknown to queries), automatic installs pause for ten minutes as after a
	 * failed install, so a broken database never runs dbDelta on every request.
	 */
	public static function forget_version(): void {
		if ( false === get_option( self::VERSION_OPTION, false ) || ! delete_option( self::VERSION_OPTION ) ) {
			return; // Already forgotten, here or by a request that met the missing tables at the same time.
		}
		// The option is back only through install(): a loss marked less than ten minutes ago means a second loss.
		if ( false !== get_transient( self::LOST_TRANSIENT ) ) {
			set_transient( self::RETRY_TRANSIENT, 1, 600 );
		}
		set_transient( self::LOST_TRANSIENT, 1, 600 );
		self::log( 'warning', 'A Qersh table is missing although it was installed. The tables are created again on the next request.' );
	}

	/**
	 * True when every table exists. The name SHOW TABLES returns is compared without case: with MySQL's
	 * lower_case_table_names=1 (the default on Windows) a prefix such as `WP_` comes back as `wp_`.
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
			if ( ! is_string( $found ) || 0 !== strcasecmp( $found, $name ) ) {
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
	 * The current site's table prefix, or '' before $wpdb exists.
	 *
	 * @return string
	 */
	private static function prefix(): string {
		global $wpdb;
		return is_object( $wpdb ) && isset( $wpdb->prefix ) ? (string) $wpdb->prefix : '';
	}

	/**
	 * The schema version this code expects.
	 *
	 * @return int
	 */
	private static function target_version(): int {
		return defined( 'QERSH_WC_DB_VERSION' ) ? (int) QERSH_WC_DB_VERSION : self::VERSION;
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
