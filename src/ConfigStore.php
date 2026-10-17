<?php
/**
 * The one configuration file of the plugin: option qersh_wc_config, or the QERSH_WC_CONFIG constant (docs/17 §4.2).
 *
 * @package QershPay\WooCommerce
 */

namespace QershPay\WooCommerce;

use QershPay\WooCommerce\Core\Config;

defined( 'ABSPATH' ) || exit;

/**
 * Reads, validates and stores the configuration `{ receiver, wallets, store }`. Validation is Core\Config::validate()
 * on the configuration completed with its defaults, plus one rule that needs the database: an id of a deleted wallet
 * (its witness row in qersh_wallets) is refused with "Restore wallet".
 *
 * Never stored here: connection keys (Secrets), and runtime state (Store\Wallets). The effective configuration
 * leaves out every wallet whose row is `deleted`, so Plugin::wallet() never returns a deleted wallet.
 *
 * With QERSH_WC_CONFIG the settings are read-only. A constant that fails validation is refused as a whole (no
 * wallet is served, issues() says why); a deleted wallet in it is left out on its own until "Restore wallet". At the
 * first read of a request that is not a webhook delivery, a wallet that has an active row but is missing from the
 * constant is marked deleted, as a delete in the admin would (docs/17 §4.2).
 */
final class ConfigStore {

	/**
	 * Option name (autoload off).
	 */
	const OPTION = 'qersh_wc_config';

	/**
	 * Constant name.
	 */
	const CONSTANT = 'QERSH_WC_CONFIG';

	/**
	 * Top-level keys of the configuration.
	 */
	const TOP_KEYS = array( 'receiver', 'wallets', 'store' );

	/**
	 * The plugin, if any.
	 *
	 * @var Plugin|null
	 */
	private $plugin;

	/**
	 * Problems found in QERSH_WC_CONFIG at the last read.
	 *
	 * @var array[]
	 */
	private $issues = array();

	/**
	 * True once this request compared the constant's wallets with the wallet rows.
	 *
	 * @var bool
	 */
	private $synced = false;

	/**
	 * Builds the service.
	 *
	 * @param Plugin|null $plugin The plugin.
	 */
	public function __construct( $plugin = null ) {
		$this->plugin = $plugin;
	}

	/**
	 * True when wp-config.php defines QERSH_WC_CONFIG (the settings page is then read-only).
	 *
	 * @return bool
	 */
	public function is_constant(): bool {
		return defined( self::CONSTANT );
	}

	/**
	 * The effective configuration, with defaults. Not cached here: Plugin::config() caches it for the request, and
	 * Plugin::forget_config() makes the next read fresh.
	 *
	 * @return array
	 */
	public function get(): array {
		$rows = $this->wallet_rows();
		if ( $this->is_constant() ) {
			$config = $this->read_constant( $rows );
		} else {
			$stored            = get_option( self::OPTION, array() );
			$config            = Config::with_defaults( self::clean( is_array( $stored ) ? $stored : array() ) );
			$config['wallets'] = self::split_deleted( self::wallets_of( $config ), $rows )[0];
		}
		return $config;
	}

	/**
	 * Problems with QERSH_WC_CONFIG found at the last get(): each `[ 'path' => string, 'message' => string,
	 * 'code' => string ]` (code `invalid`, `not_json` or `deleted_wallet`). Empty without the constant.
	 *
	 * @return array[]
	 */
	public function issues(): array {
		if ( $this->is_constant() ) {
			$this->get();
		}
		$out = array();
		foreach ( $this->issues as $issue ) {
			$issue['message'] = self::message( $issue['code'], $issue['message'] );
			$out[]            = $issue;
		}
		return $out;
	}

	/**
	 * Validates and stores a configuration (the settings and wallet screens). Wallets that leave it are marked
	 * deleted (their key sealed for 72 hours, Store\Wallets::mark_deleted()); new ones get their row. To restore a
	 * wallet, call Store\Wallets::restore() first, then save() with its entry.
	 *
	 * @param array $config Configuration (missing values take their defaults; `receiver.keys` is dropped).
	 * @return true|\WP_Error `qersh_config_constant` with the constant, `qersh_config_invalid` with
	 *                        `[ 'issues' => array ]` in its data, or `qersh_config_not_saved`.
	 */
	public function save( array $config ) {
		if ( $this->is_constant() ) {
			return self::constant_error();
		}
		return $this->store( $config, false );
	}

	/**
	 * The configuration to export: no keys and no runtime state.
	 *
	 * @return array
	 */
	public function export(): array {
		$config = $this->get();
		if ( isset( $config['receiver'] ) && is_array( $config['receiver'] ) ) {
			unset( $config['receiver']['keys'] );
		}
		return array_intersect_key( $config, array_flip( self::TOP_KEYS ) );
	}

	/**
	 * Imports a configuration: the same rules as save() (endpoint.path fixed, events a subset with
	 * transfer.received, wallet ids by the pattern and never the id of a deleted wallet). The imported wallets
	 * replace the current ones; a current wallet missing from the import is deleted as in save().
	 *
	 * @param array $config Configuration.
	 * @return true|\WP_Error As save(), with code `qersh_config_import_invalid` for a refused import.
	 */
	public function import( array $config ) {
		if ( $this->is_constant() ) {
			return self::constant_error();
		}
		return $this->store( $config, true );
	}

	/**
	 * Validates, stores and brings the wallet rows in step.
	 *
	 * @param array $config Configuration.
	 * @param bool  $import True for an import.
	 * @return true|\WP_Error
	 */
	private function store( array $config, bool $import ) {
		$config = self::clean( $config );
		$rows   = $this->wallet_rows();
		$full   = Config::with_defaults( $config );
		$issues = self::normalize_issues( Config::validate( $full ) );
		foreach ( self::split_deleted( self::wallets_of( $full ), $rows )[1] as $issue ) {
			$issue['message'] = self::message( $issue['code'], $issue['message'] );
			$issues[]         = $issue;
		}
		if ( ! empty( $issues ) ) {
			$parts = array();
			foreach ( $issues as $issue ) {
				$parts[] = ( '' === $issue['path'] ? '' : $issue['path'] . ': ' ) . $issue['message'];
			}
			if ( $import ) {
				/* translators: %s: list of the fields that are wrong, each with the reason. */
				$text = sprintf( __( 'The imported settings were refused: %s', 'qersh-for-woocommerce' ), implode( '; ', $parts ) );
			} else {
				/* translators: %s: list of the fields that are wrong, each with the reason. */
				$text = sprintf( __( 'The settings were not saved: %s', 'qersh-for-woocommerce' ), implode( '; ', $parts ) );
			}
			return new \WP_Error(
				$import ? 'qersh_config_import_invalid' : 'qersh_config_invalid',
				$text,
				array(
					'status' => 400,
					'issues' => $issues,
				)
			);
		}
		if ( isset( $full['receiver'] ) && is_array( $full['receiver'] ) ) {
			unset( $full['receiver']['keys'] );
		}

		$before = get_option( self::OPTION, array() );
		$before = self::wallets_of( is_array( $before ) ? $before : array() );
		update_option( self::OPTION, $full, false );
		$saved = get_option( self::OPTION, array() );
		if ( Store\Db::json( $saved ) !== Store\Db::json( $full ) ) {
			return new \WP_Error( 'qersh_config_not_saved', __( 'The settings could not be saved. Please try again.', 'qersh-for-woocommerce' ), array( 'status' => 500 ) );
		}
		if ( null !== $rows ) {
			$this->sync_rows( $rows, self::wallets_of( $full ), $before );
		}
		if ( $this->plugin instanceof Plugin ) {
			$this->plugin->forget_config();
		}
		return true;
	}

	/**
	 * The effective configuration from QERSH_WC_CONFIG.
	 *
	 * @param array|null $rows Wallet rows by id, or null when the tables cannot be read.
	 * @return array
	 */
	private function read_constant( ?array $rows ): array {
		$this->issues = array();
		$raw          = constant( self::CONSTANT );
		$decoded      = is_array( $raw ) ? $raw : ( is_string( $raw ) ? json_decode( $raw, true ) : null );
		if ( ! is_array( $decoded ) ) {
			$this->issues[] = array(
				'path'    => '',
				'message' => 'QERSH_WC_CONFIG in wp-config.php is not a JSON object.',
				'code'    => 'not_json',
			);
			return self::empty_config();
		}
		$config  = Config::with_defaults( self::clean( $decoded ) );
		$invalid = self::normalize_issues( Config::validate( $config ) );
		if ( ! empty( $invalid ) ) {
			$this->issues = $invalid;
			return self::empty_config();
		}
		list( $kept, $deleted ) = self::split_deleted( self::wallets_of( $config ), $rows );
		$config['wallets']      = $kept;
		$this->issues           = $deleted;
		if ( null !== $rows && ! $this->synced && $this->may_sync() ) {
			$this->synced = true;
			$this->sync_rows( $rows, $kept, null );
		}
		return $config;
	}

	/**
	 * True when this request may bring the wallet rows in step with QERSH_WC_CONFIG. Never in a webhook request:
	 * there the configuration is read at step 3, before the signature is checked, and no database write may happen
	 * before a valid signature (docs/17 §4.3). The effective configuration already leaves out a wallet that left the
	 * constant, so the webhook never serves it; the next admin, front-end or cron request writes the rows (and starts
	 * its 72 hours from then).
	 *
	 * @return bool
	 */
	private function may_sync(): bool {
		return $this->plugin instanceof Plugin && ! Plugin::is_webhook_request();
	}

	/**
	 * Brings the wallet rows in step with the configuration: a wallet that left it is marked deleted (with its old
	 * entry as the snapshot, when known), a new wallet gets its row. Never throws.
	 *
	 * @param array      $rows    Wallet rows by id.
	 * @param array      $wallets Wallets of the new configuration.
	 * @param array|null $before  Wallets of the configuration before the change, or null (the constant: every active
	 *                            row not in it has left).
	 */
	private function sync_rows( array $rows, array $wallets, ?array $before ): void {
		if ( ! $this->plugin instanceof Plugin ) {
			return;
		}
		try {
			$store = $this->plugin->wallets();
			$now   = time();
			$ids   = array();
			foreach ( $wallets as $wallet ) {
				$ids[] = (string) $wallet['id'];
				if ( ! isset( $rows[ $wallet['id'] ] ) ) {
					$store->ensure( (string) $wallet['id'] );
				}
			}
			if ( null === $before ) {
				foreach ( $rows as $id => $row ) {
					if ( 'active' === $row['state'] && ! in_array( (string) $id, $ids, true ) ) {
						$store->mark_deleted( (string) $id, $now, array( 'id' => (string) $id ) );
					}
				}
				return;
			}
			foreach ( $before as $old ) {
				if ( ! in_array( (string) $old['id'], $ids, true ) ) {
					$store->mark_deleted( (string) $old['id'], $now, $old );
				}
			}
		} catch ( \Throwable $e ) {
			$this->plugin->logger()->error( 'Qersh could not update the wallet rows after a configuration change.', array( 'error' => get_class( $e ) ) );
		}
	}

	/**
	 * The wallet rows by id, or null when the tables are not ready or cannot be read.
	 *
	 * @return array|null
	 */
	private function wallet_rows(): ?array {
		if ( ! $this->plugin instanceof Plugin || ! $this->plugin->tables_ready() ) {
			return null;
		}
		try {
			return $this->plugin->wallets()->all();
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Splits wallets into those to keep and issues for those whose row is deleted. Pure.
	 *
	 * @param array      $wallets Wallet entries.
	 * @param array|null $rows    Wallet rows by id, or null.
	 * @return array{0: array, 1: array[]}
	 */
	private static function split_deleted( array $wallets, ?array $rows ): array {
		$kept   = array();
		$issues = array();
		foreach ( array_values( $wallets ) as $i => $wallet ) {
			$id = is_array( $wallet ) && isset( $wallet['id'] ) && is_string( $wallet['id'] ) ? $wallet['id'] : '';
			if ( null !== $rows && '' !== $id && isset( $rows[ $id ] ) && 'deleted' === $rows[ $id ]['state'] ) {
				$issues[] = array(
					'path'    => 'wallets[' . $i . '].id',
					'message' => 'This wallet was deleted. Use “Restore wallet” in WooCommerce → Qersh → Wallets instead of adding it again.',
					'code'    => 'deleted_wallet',
				);
				continue;
			}
			$kept[] = $wallet;
		}
		return array( $kept, $issues );
	}

	/**
	 * Keeps the three top-level keys and drops receiver.keys. Pure.
	 *
	 * @param array $config Configuration.
	 * @return array
	 */
	private static function clean( array $config ): array {
		$out = array_intersect_key( $config, array_flip( self::TOP_KEYS ) );
		if ( isset( $out['receiver'] ) && is_array( $out['receiver'] ) ) {
			unset( $out['receiver']['keys'] );
		}
		return $out;
	}

	/**
	 * The wallet entries of a configuration. Pure.
	 *
	 * @param array $config Configuration.
	 * @return array
	 */
	private static function wallets_of( array $config ): array {
		$out = array();
		foreach ( isset( $config['wallets'] ) && is_array( $config['wallets'] ) ? $config['wallets'] : array() as $wallet ) {
			if ( is_array( $wallet ) && isset( $wallet['id'] ) ) {
				$out[] = $wallet;
			}
		}
		return $out;
	}

	/**
	 * The defaults with no wallet: what a refused constant serves.
	 *
	 * @return array
	 */
	private static function empty_config(): array {
		$config            = Config::with_defaults( array() );
		$config['wallets'] = array();
		return $config;
	}

	/**
	 * Issues from Core\Config::validate() in our shape. Pure.
	 *
	 * @param array $issues Issues.
	 * @return array[]
	 */
	private static function normalize_issues( array $issues ): array {
		$out = array();
		foreach ( $issues as $issue ) {
			$out[] = array(
				'path'    => is_array( $issue ) && isset( $issue['path'] ) ? (string) $issue['path'] : '',
				'message' => is_array( $issue ) && isset( $issue['message'] ) ? (string) $issue['message'] : '',
				'code'    => 'invalid',
			);
		}
		return $out;
	}

	/**
	 * The text of one of our own issues, translated once WordPress has loaded translations (init).
	 *
	 * @param string $code    Issue code.
	 * @param string $english Text to use before init, or for another code.
	 * @return string
	 */
	private static function message( string $code, string $english ): string {
		if ( ! function_exists( 'did_action' ) || ! did_action( 'init' ) ) {
			return $english;
		}
		if ( 'not_json' === $code ) {
			return __( 'QERSH_WC_CONFIG in wp-config.php is not a JSON object.', 'qersh-for-woocommerce' );
		}
		if ( 'deleted_wallet' === $code ) {
			return __( 'This wallet was deleted. Use “Restore wallet” in WooCommerce → Qersh → Wallets instead of adding it again.', 'qersh-for-woocommerce' );
		}
		return $english;
	}

	/**
	 * The refusal of a write while QERSH_WC_CONFIG is set.
	 *
	 * @return \WP_Error
	 */
	private static function constant_error() {
		return new \WP_Error(
			'qersh_config_constant',
			__( 'These settings are set in wp-config.php (QERSH_WC_CONFIG) and cannot be changed here.', 'qersh-for-woocommerce' ),
			array( 'status' => 409 )
		);
	}
}
