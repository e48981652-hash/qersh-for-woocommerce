<?php
/**
 * Database access for the plugin's tables: prepared queries, transactions and row locks (docs/17 §4.4 step 8,
 * docs/16 §8).
 *
 * @package QershPay\WooCommerce
 */

namespace QershPay\WooCommerce\Store;

defined( 'ABSPATH' ) || exit;

/**
 * Every query of the Store goes through here: prepared with `%i` for the table, errors suppressed (a failed query
 * would otherwise print HTML into a JSON reply and write the whole query, with any payer number in it, to the PHP
 * error log), and lock waits and deadlocks turned into LockTimeout.
 *
 * Works on MySQL 8, MariaDB and the SQLite drop-in (sqlite-database-integration). On SQLite the drop-in turns
 * START TRANSACTION into BEGIN IMMEDIATE, INSERT IGNORE into INSERT OR IGNORE and ON DUPLICATE KEY UPDATE into
 * ON CONFLICT DO UPDATE; the session variables of MySQL are skipped there.
 */
final class Db {

	/**
	 * Seconds InnoDB waits for a row lock inside our transactions (the default is 50, longer than the reply budget).
	 */
	const LOCK_WAIT_SEC = 5;

	/**
	 * Longest lock name the locks table keeps; longer names are replaced by a hash of themselves.
	 */
	const LOCK_NAME_MAX = 100;

	/**
	 * True between begin() and commit() or rollback().
	 *
	 * @var bool
	 */
	private $in_txn = false;

	/**
	 * The session's innodb_lock_wait_timeout before begin() changed it, or null when it was not changed.
	 *
	 * @var int|null
	 */
	private $saved_timeout = null;

	/**
	 * Lock names taken in the open transaction, in the order taken (lock() keeps the total order across calls).
	 *
	 * @var string[]
	 */
	private $held = array();

	/**
	 * True when a query of the open transaction found one of our tables missing: Schema::forget_version() runs once
	 * the transaction ends, because a rollback would undo the delete of the option.
	 *
	 * @var bool
	 */
	private $forget_after_txn = false;

	/**
	 * Builds the service. It needs nothing from the plugin; the parameter is the container's `new X( $plugin )`.
	 *
	 * @param \QershPay\WooCommerce\Plugin|null $plugin The plugin (not used).
	 */
	public function __construct( $plugin = null ) {
		unset( $plugin );
	}

	/**
	 * The WordPress database object.
	 *
	 * @return \wpdb
	 */
	public function wpdb() {
		global $wpdb;
		return $wpdb;
	}

	/**
	 * Full name of one of our tables.
	 *
	 * @param string $short `deliveries`, `transfers`, `orders`, `wallets` or `locks` (a leading `qersh_` is accepted).
	 * @return string
	 * @throws \InvalidArgumentException For any other name.
	 */
	public function table( string $short ): string {
		if ( 0 === strpos( $short, 'qersh_' ) ) {
			$short = substr( $short, 6 );
		}
		if ( ! in_array( $short, Schema::TABLES, true ) ) {
			// rawurlencode() escapes without WordPress (the unit tests load the Store without it); a table or column
			// name, made of [a-z_], comes out as it is.
			throw new \InvalidArgumentException( 'qersh: unknown table ' . rawurlencode( $short ) );
		}
		return $this->wpdb()->prefix . 'qersh_' . $short;
	}

	/**
	 * True on the SQLite drop-in.
	 *
	 * @return bool
	 */
	public function is_sqlite(): bool {
		return ( defined( 'DB_ENGINE' ) && 'sqlite' === DB_ENGINE ) || class_exists( 'WP_SQLite_DB', false );
	}

	/**
	 * True between begin() and commit() or rollback().
	 *
	 * @return bool
	 */
	public function in_transaction(): bool {
		return $this->in_txn;
	}

	/**
	 * Starts a transaction with `START TRANSACTION` itself (never wc_transaction_query(), which does nothing when a
	 * site sets WC_USE_TRANSACTIONS to false). On MySQL it then reads @@innodb_lock_wait_timeout and sets the
	 * session value to 5 seconds; commit() and rollback() put the old value back, so payment_complete() and
	 * everything after us run with the site's own value. Reading a variable reads no table, so the REPEATABLE READ
	 * snapshot is still taken only after the lock statements.
	 *
	 * On the SQLite drop-in START TRANSACTION becomes BEGIN IMMEDIATE, which takes the write lock at once: that is
	 * where a second writer waits (PDO's busy timeout) and fails with "database is locked", so a lock error here is a
	 * LockTimeout like a lock wait on MySQL (retry once, then 429, docs/17 §4.4 step 8).
	 *
	 * @throws \LogicException When a transaction is already open.
	 * @throws LockTimeout When the transaction could not get the database's write lock in time (SQLite).
	 * @throws \RuntimeException When the transaction could not be started for another reason.
	 */
	public function begin(): void {
		if ( $this->in_txn ) {
			throw new \LogicException( 'qersh: a transaction is already open' );
		}
		$this->saved_timeout = null;
		$this->held          = array();
		$started             = $this->raw( 'START TRANSACTION' );
		if ( false === $started['result'] ) {
			if ( self::is_lock_error( $started['error'], $started['errno'] ) ) {
				throw new LockTimeout();
			}
			throw new \RuntimeException( 'qersh: a transaction could not be started' );
		}
		$this->in_txn = true;
		if ( $this->is_sqlite() ) {
			return;
		}
		$current = $this->raw( 'SELECT @@innodb_lock_wait_timeout', 'var' );
		if ( is_numeric( $current['result'] ) && self::LOCK_WAIT_SEC !== (int) $current['result'] ) {
			$set = $this->raw( 'SET SESSION innodb_lock_wait_timeout = ' . self::LOCK_WAIT_SEC );
			if ( false !== $set['result'] ) {
				$this->saved_timeout = (int) $current['result'];
			}
		}
	}

	/**
	 * Commits. A deadlock reported at commit (how Galera reports a certification conflict) throws LockTimeout; any
	 * other failure throws RuntimeException, because the writes did not happen.
	 *
	 * @throws \LogicException Without an open transaction.
	 * @throws LockTimeout On a deadlock reported at commit.
	 * @throws \RuntimeException When the commit failed for another reason.
	 */
	public function commit(): void {
		if ( ! $this->in_txn ) {
			throw new \LogicException( 'qersh: commit() without begin()' );
		}
		$done         = $this->raw( 'COMMIT' );
		$this->in_txn = false;
		$this->held   = array();
		$this->restore_timeout();
		$this->forget_lost_schema();
		if ( false === $done['result'] ) {
			if ( self::is_lock_error( $done['error'], $done['errno'] ) ) {
				throw new LockTimeout();
			}
			throw new \RuntimeException( 'qersh: the transaction could not be committed' );
		}
	}

	/**
	 * Rolls back. Never throws, so it is safe in every catch block; does nothing without an open transaction.
	 */
	public function rollback(): void {
		if ( ! $this->in_txn ) {
			return;
		}
		$this->raw( 'ROLLBACK' );
		$this->in_txn = false;
		$this->held   = array();
		$this->restore_timeout();
		$this->forget_lost_schema();
	}

	/**
	 * Takes the named locks, in the total order of docs/17 §4.4 step 8: at most one `txn:` or `trf:` name first, then
	 * the `payer:` names sorted with strcmp; equal names are taken once. Each lock is one
	 * `INSERT … ON DUPLICATE KEY UPDATE` on its row of qersh_locks, which takes an exclusive record lock at once,
	 * whether the row existed or not. Call it right after begin(), before any read.
	 *
	 * The order also holds across calls in one transaction (a later call may only continue it): a name already held
	 * is skipped, and a txn:/trf: name after any lock, or a payer: name that sorts before a payer: name already held,
	 * is a programming error (LogicException, before any statement), because it could wait in a circle with
	 * another path. Roll back and take all the names in one call instead.
	 *
	 * @param string[] $names Lock names such as `payer:01001234567`, `txn:orange_cash:12345678901234`, `trf:trf_…`.
	 * @throws \LogicException Outside a transaction, or for names that break the order of the locks already held.
	 * @throws \RuntimeException LockTimeout (a RuntimeException) when a lock could not be had in time; a plain
	 *                           RuntimeException when a lock statement failed for another reason.
	 */
	public function lock( array $names ): void {
		if ( ! $this->in_txn ) {
			throw new \LogicException( 'qersh: lock() needs an open transaction' );
		}
		$todo = self::continue_lock_order( $this->held, self::order_lock_names( $names ) );
		if ( empty( $todo ) ) {
			return;
		}
		$table = $this->table( 'locks' );
		$now   = time();
		foreach ( $todo as $name ) {
			$result = $this->query(
				'INSERT INTO %i (name, created_at) VALUES (%s, %d) ON DUPLICATE KEY UPDATE created_at = %d',
				array( $table, self::lock_row_name( $name ), $now, $now )
			);
			if ( false === $result ) {
				throw new \RuntimeException( 'qersh: a lock could not be taken' );
			}
			$this->held[] = $name;
		}
	}

	/**
	 * Lock names taken in the open transaction, in the order taken.
	 *
	 * @return string[]
	 */
	public function held_locks(): array {
		return $this->held;
	}

	/**
	 * The names of a new lock() call that are not held yet, checked against the total order of those that are. Pure.
	 *
	 * @param string[] $held  Names already held, in the order taken.
	 * @param string[] $names Names of the new call, already in order (order_lock_names()).
	 * @return string[] The names still to take, in order.
	 * @throws \LogicException When a new name would break the total order.
	 */
	public static function continue_lock_order( array $held, array $names ): array {
		$todo       = array();
		$last_payer = null;
		foreach ( $held as $name ) {
			if ( 0 === strpos( $name, 'payer:' ) && ( null === $last_payer || strcmp( $name, $last_payer ) > 0 ) ) {
				$last_payer = $name;
			}
		}
		foreach ( $names as $name ) {
			if ( in_array( $name, $held, true ) ) {
				continue;
			}
			if ( 0 !== strpos( $name, 'payer:' ) ) {
				if ( ! empty( $held ) ) {
					throw new \LogicException( 'qersh: a txn: or trf: lock must be the first lock of the transaction' );
				}
			} elseif ( null !== $last_payer && strcmp( $name, $last_payer ) < 0 ) {
				throw new \LogicException( 'qersh: payer: locks must be taken in sorted order within a transaction' );
			}
			$todo[] = $name;
		}
		return $todo;
	}

	/**
	 * Puts lock names in the one order every path takes them (so two paths never wait on each other in a circle):
	 * at most one `txn:`/`trf:` name first, then the `payer:` names sorted with strcmp, without repeats. Pure.
	 *
	 * @param array $names Lock names.
	 * @return string[]
	 * @throws \InvalidArgumentException For a name of another kind, an empty name, or two different txn:/trf: names.
	 */
	public static function order_lock_names( array $names ): array {
		$head  = array();
		$payer = array();
		foreach ( $names as $name ) {
			if ( ! is_string( $name ) ) {
				throw new \InvalidArgumentException( 'qersh: a lock name must be a string' );
			}
			if ( self::is_payer_lock( $name ) ) {
				if ( ! in_array( $name, $payer, true ) ) {
					$payer[] = $name;
				}
			} elseif ( ! in_array( $name, $head, true ) ) {
				$head[] = $name;
			}
		}
		if ( count( $head ) > 1 ) {
			throw new \InvalidArgumentException( 'qersh: at most one txn: or trf: lock per transaction' );
		}
		usort( $payer, 'strcmp' );
		return array_merge( $head, $payer );
	}

	/**
	 * The row name stored for a lock: the name itself, or `<kind>:#<sha256 of the name>` when it is longer than the
	 * column. Pure.
	 *
	 * @param string $name Lock name.
	 * @return string
	 */
	public static function lock_row_name( string $name ): string {
		if ( strlen( $name ) <= self::LOCK_NAME_MAX ) {
			return $name;
		}
		$kind = substr( $name, 0, (int) strpos( $name, ':' ) + 1 );
		return $kind . '#' . hash( 'sha256', $name );
	}

	/**
	 * True for MySQL and MariaDB 1205/1213 (also by message, as the server prints them in English by default) and for
	 * SQLite busy or locked. Pure.
	 *
	 * @param string $message Database error text.
	 * @param int    $errno   MySQL error number, 0 when unknown.
	 * @return bool
	 */
	public static function is_lock_error( string $message, int $errno = 0 ): bool {
		if ( 1205 === $errno || 1213 === $errno ) {
			return true;
		}
		return 1 === preg_match( '/lock wait timeout|deadlock|database is locked|database table is locked|SQLITE_BUSY|SQLITE_LOCKED/i', $message );
	}

	/**
	 * True when a database error says that one of our tables (`{prefix}qersh_…`, in any case) does not exist: MySQL
	 * and MariaDB error 1146 naming it (in any language), their English "Table '…' doesn't exist" (also what the
	 * SQLite drop-in reports), and SQLite's own "no such table". A missing table of anyone else, and "doesn't exist in
	 * engine" (a damaged table, which dbDelta cannot create again), are not. Pure.
	 *
	 * @param string $message Database error text.
	 * @param int    $errno   MySQL error number, 0 when unknown.
	 * @param string $prefix  Table prefix ($wpdb->prefix).
	 * @return bool
	 */
	public static function is_missing_table_error( string $message, int $errno, string $prefix ): bool {
		$ours = preg_quote( $prefix . 'qersh_', '/' );
		if ( '' === $message || 1 !== preg_match( '/' . $ours . '/i', $message ) ) {
			return false;
		}
		if ( 1146 === $errno ) {
			return true;
		}
		return 1 === preg_match( "/Table '(?:[^'.]*\\.)?{$ours}[a-z_]*' doesn't exist(?! in engine)|no such table: (?:[^ .]+\\.)?{$ours}/i", $message );
	}

	/**
	 * Runs a write (or any statement) and returns the rows affected, or false on an error that is not a lock error.
	 *
	 * @param string $sql  SQL with placeholders (`%i` for the table).
	 * @param array  $args Values.
	 * @return int|false
	 * @throws LockTimeout On a lock wait timeout or deadlock.
	 */
	public function query( string $sql, array $args = array() ) {
		$out = $this->run( 'query', $sql, $args );
		return false === $out['result'] ? false : (int) $out['result'];
	}

	/**
	 * Like query(), but a failed statement throws RuntimeException instead of returning false. The Store uses it so
	 * a broken write is never read as "the row was already there".
	 *
	 * @param string $sql  SQL with placeholders.
	 * @param array  $args Values.
	 * @return int Rows affected.
	 * @throws \RuntimeException LockTimeout (a RuntimeException) on a lock wait timeout or deadlock; a plain
	 *                           RuntimeException when the statement failed for another reason.
	 */
	public function exec( string $sql, array $args = array() ): int {
		$out = $this->run( 'query', $sql, $args );
		if ( false === $out['result'] ) {
			throw new \RuntimeException( 'qersh: a database write failed' );
		}
		return (int) $out['result'];
	}

	/**
	 * One row as an associative array, or null when there is none. A failed read throws RuntimeException (an
	 * empty answer would read as "no order" and change a decision).
	 *
	 * @param string $sql  SQL with placeholders.
	 * @param array  $args Values.
	 * @return array|null
	 * @throws \RuntimeException LockTimeout (a RuntimeException) on a lock wait timeout or deadlock; a plain
	 *                           RuntimeException when the read failed for another reason.
	 */
	public function row( string $sql, array $args = array() ): ?array {
		$out = $this->run( 'row', $sql, $args );
		if ( '' !== $out['error'] ) {
			throw new \RuntimeException( 'qersh: a database read failed' );
		}
		return is_array( $out['result'] ) ? $out['result'] : null;
	}

	/**
	 * All rows as associative arrays. A failed read throws RuntimeException.
	 *
	 * @param string $sql  SQL with placeholders.
	 * @param array  $args Values.
	 * @return array[]
	 * @throws \RuntimeException LockTimeout (a RuntimeException) on a lock wait timeout or deadlock; a plain
	 *                           RuntimeException when the read failed for another reason.
	 */
	public function rows( string $sql, array $args = array() ): array {
		$out = $this->run( 'rows', $sql, $args );
		if ( '' !== $out['error'] ) {
			throw new \RuntimeException( 'qersh: a database read failed' );
		}
		return is_array( $out['result'] ) ? array_values( $out['result'] ) : array();
	}

	/**
	 * One value, or null. A failed read throws RuntimeException.
	 *
	 * @param string $sql  SQL with placeholders.
	 * @param array  $args Values.
	 * @return string|null
	 * @throws \RuntimeException LockTimeout (a RuntimeException) on a lock wait timeout or deadlock; a plain
	 *                           RuntimeException when the read failed for another reason.
	 */
	public function var( string $sql, array $args = array() ): ?string {
		$out = $this->run( 'var', $sql, $args );
		if ( '' !== $out['error'] ) {
			throw new \RuntimeException( 'qersh: a database read failed' );
		}
		return null === $out['result'] ? null : (string) $out['result'];
	}

	/**
	 * Builds `INSERT [IGNORE] INTO %i (columns) VALUES (…)` for one row, with NULL written literally.
	 *
	 * @param string $short Table short name.
	 * @param array  $row   Column => value (validated against Schema::COLUMNS).
	 * @param string $verb  `INSERT` or `INSERT IGNORE`.
	 * @return array{0: string, 1: array} SQL and arguments, the table name first.
	 * @throws \InvalidArgumentException For another verb, an empty row, or a value its column cannot hold.
	 */
	public function insert_sql( string $short, array $row, string $verb = 'INSERT' ): array {
		if ( 'INSERT' !== $verb && 'INSERT IGNORE' !== $verb ) {
			throw new \InvalidArgumentException( 'qersh: insert_sql() takes INSERT or INSERT IGNORE' );
		}
		$values = self::values( $short, $row );
		if ( empty( $values ) ) {
			throw new \InvalidArgumentException( 'qersh: an insert needs at least one column' );
		}
		$args         = array( $this->table( $short ) );
		$placeholders = array();
		foreach ( $values as $value ) {
			$placeholders[] = $value[0];
			if ( 'NULL' !== $value[0] ) {
				$args[] = $value[1];
			}
		}
		$sql = $verb . ' INTO %i (' . implode( ', ', array_keys( $values ) ) . ') VALUES (' . implode( ', ', $placeholders ) . ')';
		return array( $sql, $args );
	}

	/**
	 * Builds the `a = %s, b = NULL` part of an UPDATE (or of ON DUPLICATE KEY UPDATE).
	 *
	 * @param string $short  Table short name.
	 * @param array  $fields Column => value.
	 * @return array{0: string, 1: array} SQL fragment and its arguments.
	 */
	public static function set_sql( string $short, array $fields ): array {
		$parts = array();
		$args  = array();
		foreach ( self::values( $short, $fields ) as $column => $value ) {
			$parts[] = $column . ' = ' . $value[0];
			if ( 'NULL' !== $value[0] ) {
				$args[] = $value[1];
			}
		}
		return array( implode( ', ', $parts ), $args );
	}

	/**
	 * Validates a row against Schema::COLUMNS and returns column => [ placeholder or 'NULL', value ]. Pure.
	 *
	 * @param string $short Table short name.
	 * @param array  $row   Column => value.
	 * @return array<string, array{0: string, 1: mixed}>
	 */
	public static function values( string $short, array $row ): array {
		$out = array();
		foreach ( $row as $column => $value ) {
			$out[ (string) $column ] = self::value( $short, (string) $column, $value );
		}
		return $out;
	}

	/**
	 * Checks one value the way MySQL would otherwise do silently (IGNORE turns a too-long value into a warning and
	 * cuts it): a `key` column refuses a value that does not fit, a `nul` column stores NULL for it (never a cut
	 * value that could equal another), a `str` column is cut to its length in characters, an empty `key` or `nul`
	 * value is NULL where NULL is allowed, arrays become JSON. Pure.
	 *
	 * @param string $short  Table short name.
	 * @param string $column Column.
	 * @param mixed  $value  Value.
	 * @return array{0: string, 1: mixed} Placeholder (`%d`, `%s` or `NULL`) and value.
	 * @throws \InvalidArgumentException For an unknown column or a value the column cannot hold (the message names the
	 *                                   column, never the value).
	 */
	public static function value( string $short, string $column, $value ): array {
		if ( ! isset( Schema::COLUMNS[ $short ][ $column ] ) ) {
			throw new \InvalidArgumentException( 'qersh: unknown column ' . rawurlencode( $short . '.' . $column ) );
		}
		list( $type, $len, $nullable ) = Schema::parse_spec( Schema::COLUMNS[ $short ][ $column ] );
		if ( ( 'key' === $type || 'nul' === $type ) && '' === $value && $nullable ) {
			$value = null;
		}
		if ( null === $value ) {
			if ( ! $nullable ) {
				throw new \InvalidArgumentException( 'qersh: ' . rawurlencode( $short . '.' . $column ) . ' cannot be NULL' );
			}
			return array( 'NULL', null );
		}
		if ( 'int' === $type ) {
			if ( is_bool( $value ) ) {
				return array( '%d', $value ? 1 : 0 );
			}
			if ( is_int( $value ) ) {
				return array( '%d', $value );
			}
			if ( is_string( $value ) && preg_match( '/^-?[0-9]{1,18}$/', $value ) ) {
				return array( '%d', (int) $value );
			}
			if ( is_float( $value ) && is_finite( $value ) && floor( $value ) === $value && abs( $value ) < PHP_INT_MAX ) {
				return array( '%d', (int) $value );
			}
			throw new \InvalidArgumentException( 'qersh: ' . rawurlencode( $short . '.' . $column ) . ' must be an integer' );
		}
		if ( 'json' === $type ) {
			$text = is_string( $value ) ? $value : self::json( $value );
			$long = $len > 0 ? self::length( $text ) > $len : strlen( $text ) > 65535;
			if ( $long ) {
				if ( $nullable ) {
					return array( 'NULL', null );
				}
				throw new \InvalidArgumentException( 'qersh: ' . rawurlencode( $short . '.' . $column ) . ' is too long' );
			}
			return array( '%s', $text );
		}
		if ( ! is_scalar( $value ) ) {
			throw new \InvalidArgumentException( 'qersh: ' . rawurlencode( $short . '.' . $column ) . ' must be a string' );
		}
		$text = is_bool( $value ) ? ( $value ? '1' : '0' ) : (string) $value;
		if ( 'key' === $type ) {
			if ( '' === $text || self::length( $text ) > $len ) {
				throw new \InvalidArgumentException( 'qersh: ' . rawurlencode( $short . '.' . $column ) . ' is empty or longer than ' . (int) $len . ' characters' );
			}
			return array( '%s', $text );
		}
		if ( 'nul' === $type ) {
			if ( '' !== $text && self::length( $text ) <= $len ) {
				return array( '%s', $text );
			}
			if ( ! $nullable ) {
				throw new \InvalidArgumentException( 'qersh: ' . rawurlencode( $short . '.' . $column ) . ' is empty or longer than ' . (int) $len . ' characters' );
			}
			return array( 'NULL', null );
		}
		if ( 'text' === $type ) {
			return array( '%s', self::cut_bytes( $text, 65535 ) );
		}
		return array( '%s', self::cut( $text, $len ) );
	}

	/**
	 * Casts a row read from the database: integer columns to int, JSON columns to arrays (null when unreadable),
	 * others to string; NULL stays null. Pure.
	 *
	 * @param string $short Table short name.
	 * @param array  $row   Row as read.
	 * @return array
	 */
	public static function typed( string $short, array $row ): array {
		$columns = isset( Schema::COLUMNS[ $short ] ) ? Schema::COLUMNS[ $short ] : array();
		foreach ( $row as $column => $value ) {
			if ( null === $value || ! isset( $columns[ $column ] ) ) {
				continue;
			}
			$type = Schema::parse_spec( $columns[ $column ] )[0];
			if ( 'int' === $type ) {
				$row[ $column ] = (int) $value;
			} elseif ( 'json' === $type ) {
				$decoded        = is_string( $value ) ? json_decode( $value, true ) : null;
				$row[ $column ] = is_array( $decoded ) ? $decoded : null;
			} else {
				$row[ $column ] = (string) $value;
			}
		}
		return $row;
	}

	/**
	 * JSON for storage: Arabic and slashes as they are. Pure (wp_json_encode when WordPress is loaded).
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	public static function json( $value ): string {
		$flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
		if ( function_exists( 'wp_json_encode' ) ) {
			$out = wp_json_encode( $value, $flags );
		} else {
			$out = json_encode( $value, $flags ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- unit tests run without WordPress.
		}
		return is_string( $out ) ? $out : 'null';
	}

	/**
	 * Length in characters (what VARCHAR counts).
	 *
	 * @param string $text Text.
	 * @return int
	 */
	private static function length( string $text ): int {
		return function_exists( 'mb_strlen' ) ? (int) mb_strlen( $text, 'UTF-8' ) : strlen( $text );
	}

	/**
	 * Cuts to a number of characters.
	 *
	 * @param string $text Text.
	 * @param int    $len  Characters.
	 * @return string
	 */
	private static function cut( string $text, int $len ): string {
		if ( self::length( $text ) <= $len ) {
			return $text;
		}
		return function_exists( 'mb_substr' ) ? (string) mb_substr( $text, 0, $len, 'UTF-8' ) : substr( $text, 0, $len );
	}

	/**
	 * Cuts to a number of bytes without splitting a character.
	 *
	 * @param string $text  Text.
	 * @param int    $bytes Bytes.
	 * @return string
	 */
	private static function cut_bytes( string $text, int $bytes ): string {
		if ( strlen( $text ) <= $bytes ) {
			return $text;
		}
		return function_exists( 'mb_strcut' ) ? (string) mb_strcut( $text, 0, $bytes, 'UTF-8' ) : substr( $text, 0, $bytes );
	}

	/**
	 * True for a `payer:` lock; validates the other kinds.
	 *
	 * @param string $name Lock name.
	 * @return bool
	 * @throws \InvalidArgumentException For an unknown kind or an empty name.
	 */
	private static function is_payer_lock( string $name ): bool {
		foreach ( array( 'payer:', 'txn:', 'trf:' ) as $kind ) {
			if ( 0 === strpos( $name, $kind ) ) {
				if ( strlen( $name ) === strlen( $kind ) ) {
					throw new \InvalidArgumentException( 'qersh: empty lock name' );
				}
				return 'payer:' === $kind;
			}
		}
		throw new \InvalidArgumentException( 'qersh: a lock name starts with payer:, txn: or trf:' );
	}

	/**
	 * Prepares and runs one query with errors suppressed, and maps lock errors.
	 *
	 * @param string $method `query`, `row`, `rows` or `var`.
	 * @param string $sql    SQL with placeholders.
	 * @param array  $args   Values.
	 * @return array{result: mixed, error: string, errno: int}
	 * @throws \InvalidArgumentException When wpdb::prepare() refuses the query.
	 * @throws LockTimeout On a lock wait timeout or deadlock.
	 */
	private function run( string $method, string $sql, array $args ): array {
		$wpdb = $this->wpdb();
		if ( empty( $args ) ) {
			$prepared = $sql;
		} else {
			$prepared = $wpdb->prepare( $sql, $args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- the one place where the Store prepares.
		}
		if ( ! is_string( $prepared ) || '' === $prepared ) {
			throw new \InvalidArgumentException( 'qersh: a query could not be prepared' );
		}
		$out = $this->raw( $prepared, $method );
		if ( '' !== $out['error'] && self::is_lock_error( $out['error'], $out['errno'] ) ) {
			throw new LockTimeout();
		}
		return $out;
	}

	/**
	 * Runs one prepared statement with errors suppressed.
	 *
	 * @param string $sql    Prepared SQL.
	 * @param string $method `query`, `row`, `rows` or `var`.
	 * @return array{result: mixed, error: string, errno: int}
	 */
	private function raw( string $sql, string $method = 'query' ): array {
		$wpdb     = $this->wpdb();
		$suppress = $wpdb->suppress_errors( true );
		try {
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- prepared in run() with %i/%s/%d (or a fixed statement of this class); custom tables.
			if ( 'row' === $method ) {
				$result = $wpdb->get_row( $sql, 'ARRAY_A' );
			} elseif ( 'rows' === $method ) {
				$result = $wpdb->get_results( $sql, 'ARRAY_A' );
			} elseif ( 'var' === $method ) {
				$result = $wpdb->get_var( $sql );
			} else {
				$result = $wpdb->query( $sql );
			}
			// phpcs:enable
			$error = (string) $wpdb->last_error;
			$errno = 0;
			if ( isset( $wpdb->dbh ) && $wpdb->dbh instanceof \mysqli ) {
				$errno = (int) $wpdb->dbh->errno;
			}
		} finally {
			$wpdb->suppress_errors( $suppress );
		}
		if ( '' !== $error && self::is_missing_table_error( $error, $errno, (string) $wpdb->prefix ) ) {
			$this->table_missing( (string) $wpdb->prefix );
		}
		return array(
			'result' => $result,
			'error'  => $error,
			'errno'  => $errno,
		);
	}

	/**
	 * One of our tables is missing although the schema version says installed: from now on Plugin::tables_ready()
	 * is false for this request (on this site), and the version is forgotten so the next request creates the tables
	 * again (Schema::forget_version()), at once or after the open transaction.
	 *
	 * @param string $prefix Table prefix of the query.
	 */
	private function table_missing( string $prefix ): void {
		Schema::table_missing( $prefix );
		if ( $this->in_txn ) {
			$this->forget_after_txn = true;
			return;
		}
		self::forget_schema();
	}

	/**
	 * After commit() or rollback(): forgets the schema version when a query of that transaction found a table missing.
	 */
	private function forget_lost_schema(): void {
		if ( ! $this->forget_after_txn ) {
			return;
		}
		$this->forget_after_txn = false;
		self::forget_schema();
	}

	/**
	 * Schema::forget_version(), which never fails the query, commit or rollback that found the missing table.
	 */
	private static function forget_schema(): void {
		try {
			Schema::forget_version();
		} catch ( \Throwable $e ) {
			unset( $e ); // Plugin::tables_ready() is false for this request anyway; the next query that fails tries again.
		}
	}

	/**
	 * Puts back the session's lock wait timeout after commit() or rollback().
	 */
	private function restore_timeout(): void {
		if ( null === $this->saved_timeout ) {
			return;
		}
		$saved               = $this->saved_timeout;
		$this->saved_timeout = null;
		$this->raw( 'SET SESSION innodb_lock_wait_timeout = ' . $saved );
	}
}
