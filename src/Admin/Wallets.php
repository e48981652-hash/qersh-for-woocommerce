<?php
/**
 * The Wallets tab: adding wallets, the wallet card with the store URL and the developer's links, the connection key
 * (generated link, paste, pending key), deleting and restoring (docs/17 §4.4 step 3, §4.8, §4.9, §5).
 *
 * @package QershPay\WooCommerce
 */

namespace QershPay\WooCommerce\Admin;

use QershPay\WooCommerce\ConfigStore;
use QershPay\WooCommerce\Core\Config;
use QershPay\WooCommerce\Core\Payer;
use QershPay\WooCommerce\Core\SetupLink;
use QershPay\WooCommerce\Diagnostics;
use QershPay\WooCommerce\Store\Wallets as WalletRows;

defined( 'ABSPATH' ) || exit;

/**
 * The connection key is never printed by the server. The generated link with the key comes from one POST
 * (wp_ajax_qersh_wc_key_link) whose reply is not stored anywhere, and admin.js shows it in a window it builds itself.
 */
final class Wallets {

	/**
	 * A second key link for the same wallet within this many seconds is refused with 429 (docs/17 §4.8 rule 1).
	 */
	const LINK_RATE_SEC = 10;

	/**
	 * Transient prefix of that rate limit (it holds a time, never a key).
	 */
	const RATE_PREFIX = 'qersh_wc_keylink_';

	/**
	 * The longest paste read (a share message is a few hundred characters).
	 */
	const PASTE_MAX = 8192;

	/*
	 * ---------------------------------------------------------------------------------------------------------------
	 * Screen.
	 * ---------------------------------------------------------------------------------------------------------------
	 */

	/**
	 * Renders the tab.
	 */
	public static function render(): void {
		$plugin   = Module::plugin();
		$store    = $plugin->config_store();
		$constant = $store->is_constant();
		$config   = $plugin->config();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- navigation between views of the tab.
		$edit   = isset( $_GET['edit'] ) ? sanitize_key( wp_unslash( $_GET['edit'] ) ) : '';
		$delete = isset( $_GET['delete'] ) ? sanitize_key( wp_unslash( $_GET['delete'] ) ) : '';
		$add    = isset( $_GET['add'] );
		// phpcs:enable

		if ( '' !== $delete && ! $constant && null !== $plugin->wallet( $delete ) ) {
			Module::template( 'wallet-delete', self::delete_args( $plugin->wallet( $delete ) ) );
			return;
		}
		if ( ! $constant && ( $add || ( '' !== $edit && null !== $plugin->wallet( $edit ) ) ) ) {
			Module::template( 'wallet-form', array( 'wallet' => '' !== $edit ? $plugin->wallet( $edit ) : null ) );
			return;
		}

		$cards = array();
		foreach ( $config['wallets'] as $wallet ) {
			if ( is_array( $wallet ) && isset( $wallet['id'] ) ) {
				$cards[] = self::card( $wallet );
			}
		}
		Module::template(
			'wallets',
			array(
				'constant' => $constant,
				'issues'   => $constant ? $store->issues() : array(),
				'dropped'  => $constant ? array() : $store->dropped(),
				'cards'    => $cards,
				'deleted'  => self::deleted_rows( $constant ),
				'add_url'  => Module::page_url( 'wallets', array( 'add' => 1 ) ),
			)
		);
	}

	/**
	 * Everything the card of one wallet shows (docs/17 §5 steps 2 and 5, §4.8, §4.9): its name and number, never a
	 * wallet type. Values are escaped HTML or plain text as named; the template escapes the plain ones.
	 *
	 * @param array $wallet Wallet entry.
	 * @return array
	 */
	public static function card( array $wallet ): array {
		$plugin  = Module::plugin();
		$id      = (string) $wallet['id'];
		$secrets = $plugin->secrets();
		$config  = $plugin->config();
		$row     = null;
		$has_any = false;
		try {
			$row = $plugin->tables_ready() ? $plugin->wallets()->get( $id ) : null;
			if ( $plugin->tables_ready() ) {
				$has_any = $plugin->transfers()->list_page( array( 'wallet' => $id ), 1, 1 )['total'] > 0;
			}
		} catch ( \Throwable $e ) {
			$row = null;
		}
		$fp        = $secrets->fingerprint( $id );
		$key_state = $secrets->state( $id );
		$constant  = $secrets->from_constant( $id );
		$store_url = Diagnostics::store_url( $id );
		$label     = View::wallet_name( $wallet );
		$keyless   = self::setup_link( $store_url, $label, null );
		$lines     = array();

		// The key.
		if ( $constant ) {
			$lines[] = 'constant_invalid' === $key_state
				? esc_html__( 'The connection key in wp-config.php is not a valid key.', 'qersh-for-woocommerce' )
				/* translators: 1: a constant name, 2: key fingerprint. */
				: sprintf( esc_html__( 'Connection key: set in wp-config.php (%1$s), fingerprint %2$s.', 'qersh-for-woocommerce' ), View::ltr( \QershPay\WooCommerce\Secrets::constant_name( $id ) ), View::ltr( isset( $fp['fp'] ) ? (string) $fp['fp'] : '' ) );
		} elseif ( is_array( $fp ) && ! empty( $fp['fp'] ) ) {
			/* translators: 1: key fingerprint, 2: date and time. */
			$lines[] = sprintf( esc_html__( 'Connection key: fingerprint %1$s, set %2$s.', 'qersh-for-woocommerce' ), View::ltr( (string) $fp['fp'] ), View::ltr( View::time( isset( $fp['set_at'] ) ? $fp['set_at'] : null ) ) );
		} else {
			$lines[] = esc_html__( 'No connection key yet. Generate your developer\'s link with the connection key below, or paste the message from the phone.', 'qersh-for-woocommerce' );
		}

		// Test connection and the last delivery.
		$test_at = is_array( $row ) && ! empty( $row['last_test_at'] ) ? (int) $row['last_test_at'] : 0;
		if ( $test_at > 0 ) {
			/* translators: %s: date and time. */
			$line = sprintf( esc_html__( '“Test connection” arrived from the phone at %s ✓', 'qersh-for-woocommerce' ), View::ltr( View::time( $test_at ) ) );
			if ( is_array( $fp ) && ! empty( $fp['set_at'] ) && ! empty( $row['last_delivery_at'] ) && abs( (int) $fp['set_at'] - (int) $row['last_delivery_at'] ) <= 5 && empty( $fp['pending_at'] ) ) {
				$line .= ' ' . esc_html__( 'What the phone sent carries the new key, so it is the connection key now.', 'qersh-for-woocommerce' );
			}
			$lines[] = $line;
			if ( ! $has_any ) {
				$lines[] = esc_html__( 'The test does not mean Qersh is activated: real transfers do not arrive before it is activated in the app. Try a transfer of one pound.', 'qersh-for-woocommerce' );
			}
		}
		if ( is_array( $row ) && ! empty( $row['last_delivery_at'] ) ) {
			/* translators: %s: date and time. */
			$lines[] = sprintf( esc_html__( 'Last delivery from this wallet\'s phone: %s', 'qersh-for-woocommerce' ), View::ltr( View::time( (int) $row['last_delivery_at'] ) ) );
		}
		if ( is_array( $row ) && ! empty( $row['grace_until'] ) ) {
			/* translators: %s: date. */
			$lines[] = sprintf( esc_html__( 'The phone says the Qersh subscription is in its grace period until %s; after that it does not send transfers until it is renewed.', 'qersh-for-woocommerce' ), View::ltr( View::time( (int) $row['grace_until'] ) ) );
		}

		// One paragraph for every wallet: what happens depends on what each message shows, never on the wallet type.
		$advice = array( self::advice( ! empty( $config['store']['txn_entry'] ) ) );

		$warnings = array();
		$home     = Diagnostics::url_parts( rest_url() );
		if ( 'http' === $home['scheme'] && 0 === strpos( $store_url, 'https:' ) ) {
			$warnings[] = esc_html__( 'Your store does not use https://. The Qersh app sends only to https:// addresses (except local test addresses), so set up https on your store first.', 'qersh-for-woocommerce' );
		}
		if ( null === $keyless ) {
			$warnings[] = esc_html__( 'The Qersh app would not accept this store URL. Check the site address in Settings → General.', 'qersh-for-woocommerce' );
		}

		$alerts = $plugin->diagnostics()->wallet_alerts( $id );

		return array(
			'id'         => $id,
			'label'      => $label,
			// Without a name of its own the label is made from the number, which the card then shows only once.
			'named'      => View::has_label( $wallet ),
			'address'    => isset( $wallet['receive_address'] ) ? (string) $wallet['receive_address'] : '',
			'enabled'    => ! empty( $wallet['enabled'] ),
			'store_url'  => $store_url,
			'keyless'    => $keyless,
			'lines'      => $lines,
			'advice'     => $advice,
			'warnings'   => $warnings,
			'alerts'     => $alerts,
			// No button when the app would refuse the store URL: the warning above says why.
			'key_link'   => ! $constant && ! empty( $config['store']['allow_key_link'] ) && null !== $keyless,
			'paste'      => ! $constant,
			'pending'    => ! $constant && is_array( $fp ) && ! empty( $fp['pending_fp'] ) ? array(
				'fp' => (string) $fp['pending_fp'],
				'at' => (int) $fp['pending_at'],
			) : null,
			'editable'   => ! $plugin->config_store()->is_constant(),
			'edit_url'   => Module::page_url( 'wallets', array( 'edit' => $id ) ),
			'delete_url' => Module::page_url( 'wallets', array( 'delete' => $id ) ),
		);
	}

	/**
	 * The card's advice, the same for every wallet (docs/17 §4.6): a transfer whose message shows the sender's number
	 * confirms its order on its own; the others wait for the merchant's click, or for the transaction id the
	 * customer enters when store.txn_entry is on; a transfer no open order waits for is a personal transfer.
	 *
	 * @param bool $txn_entry store.txn_entry.
	 * @return string Escaped HTML.
	 */
	private static function advice( bool $txn_entry ): string {
		$parts = array(
			esc_html__( 'A transfer whose message shows the sender\'s number confirms its order on its own.', 'qersh-for-woocommerce' ),
			$txn_entry
				? esc_html__( 'The others (the sender\'s number masked or missing) wait for your click in “Needs a decision”, or for the transaction id the customer enters on their order page after transferring.', 'qersh-for-woocommerce' )
				: esc_html__( 'The others (the sender\'s number masked or missing) wait for your click in “Needs a decision”: customers are not asked for the transaction id (Qersh → Settings).', 'qersh-for-woocommerce' ),
			esc_html__( 'Use this wallet for online store orders only: a transfer that no open order waits for, such as one for a sale in your shop or on WhatsApp, is filed as a “personal transfer”, and an order created after it never takes it on its own.', 'qersh-for-woocommerce' ),
		);
		return implode( ' ', $parts );
	}

	/**
	 * A developer's link for a wallet (docs/17 §4.1 SetupLink row): url and label, and the key only for the
	 * generated link. Never `provider`: the plugin does not know the wallet type, and the Qersh app asks for it when
	 * the link is opened (Core\SetupLink keeps its optional provider; nothing here passes one). Never `events`. Null
	 * when the app would refuse the URL.
	 *
	 * @param string      $url    Store URL.
	 * @param string      $label  Wallet name.
	 * @param string|null $secret Key, or null for the key-less link.
	 * @return string|null
	 */
	public static function setup_link( string $url, string $label, ?string $secret ): ?string {
		$params = array(
			'url'   => $url,
			'label' => $label,
		);
		if ( null !== $secret ) {
			$params['secret'] = $secret;
		}
		$check = SetupLink::validate( $params );
		if ( empty( $check['ok'] ) ) {
			return null;
		}
		return SetupLink::build( $params );
	}

	/**
	 * Rows of deleted wallets (witnesses), for the list below the cards.
	 *
	 * @param bool $constant True with QERSH_WC_CONFIG.
	 * @return array[]
	 */
	private static function deleted_rows( bool $constant ): array {
		$plugin = Module::plugin();
		if ( ! $plugin->tables_ready() ) {
			return array();
		}
		try {
			$rows = $plugin->wallets()->all();
		} catch ( \Throwable $e ) {
			return array();
		}
		$in_constant = $constant ? self::constant_wallet_ids() : array();
		$now         = time();
		$out         = array();
		foreach ( $rows as $id => $row ) {
			if ( 'deleted' !== $row['state'] ) {
				continue;
			}
			$snapshot = is_array( $row['snapshot'] ) ? $row['snapshot'] : array( 'id' => $id );
			$state    = WalletRows::gone_state( $row, $now );
			$erased   = ! empty( $row['key_erased_at'] );
			$deleted  = View::ltr( View::time( isset( $row['deleted_at'] ) ? $row['deleted_at'] : null ) );
			$gone     = View::ltr( View::time( isset( $row['gone_at'] ) ? $row['gone_at'] : null ) );
			if ( 'deleted_recent' === $state && ! $erased ) {
				/* translators: 1: date and time of deletion, 2: date and time. */
				$text   = sprintf( esc_html__( 'Deleted %1$s. It can be restored with one click until %2$s Cairo time, and what the phone resends arrives on its own.', 'qersh-for-woocommerce' ), $deleted, $gone );
				$button = __( 'Restore wallet', 'qersh-for-woocommerce' );
			} elseif ( 'deleted_recent' === $state ) {
				/* translators: %s: date and time of deletion. */
				$text   = sprintf( esc_html__( 'Deleted %s, and its connection key was erased, so restoring it needs a new key.', 'qersh-for-woocommerce' ), $deleted );
				$button = __( 'Restore with a new key', 'qersh-for-woocommerce' );
			} else {
				/* translators: 1: date and time of deletion, 2: date and time. */
				$text   = sprintf( esc_html__( 'Deleted %1$s. Since %2$s the store has told the phone that it was removed for good, so the phone does not resend its transfers.', 'qersh-for-woocommerce' ), $deleted, $gone );
				$button = __( 'Restore with a new key', 'qersh-for-woocommerce' );
			}
			$restorable = ! $constant || in_array( (string) $id, $in_constant, true );
			// A snapshot needs its number to come back; a `provider` saved by an older version is ignored.
			if ( ! $constant && ! isset( $snapshot['receive_address'] ) ) {
				$restorable = false;
			}
			$out[] = array(
				'id'         => (string) $id,
				'label'      => View::wallet_name( $snapshot ) ? View::wallet_name( $snapshot ) : (string) $id,
				'text'       => $text,
				'button'     => $button,
				'restorable' => $restorable,
			);
		}
		return $out;
	}

	/**
	 * Wallet ids listed in QERSH_WC_CONFIG.
	 *
	 * @return string[]
	 */
	private static function constant_wallet_ids(): array {
		if ( ! defined( 'QERSH_WC_CONFIG' ) ) {
			return array();
		}
		$raw     = constant( 'QERSH_WC_CONFIG' );
		$decoded = is_array( $raw ) ? $raw : ( is_string( $raw ) ? json_decode( $raw, true ) : null );
		$out     = array();
		if ( is_array( $decoded ) && isset( $decoded['wallets'] ) && is_array( $decoded['wallets'] ) ) {
			foreach ( $decoded['wallets'] as $wallet ) {
				if ( is_array( $wallet ) && isset( $wallet['id'] ) && is_string( $wallet['id'] ) ) {
					$out[] = $wallet['id'];
				}
			}
		}
		return $out;
	}

	/**
	 * What the delete dialog says (docs/17 §5 step 10).
	 *
	 * @param array $wallet Wallet entry.
	 * @return array
	 */
	private static function delete_args( array $wallet ): array {
		$plugin  = Module::plugin();
		$id      = (string) $wallet['id'];
		$now     = time();
		$waiting = $plugin->diagnostics()->orders_waiting( $id, $now );
		$enabled = 0;
		foreach ( $plugin->config()['wallets'] as $other ) {
			if ( is_array( $other ) && ! empty( $other['enabled'] ) ) {
				++$enabled;
			}
		}
		if ( $waiting > 0 ) {
			/* translators: %s: number of orders. */
			$orders = sprintf( esc_html( _n( '%s order waits for a transfer to this wallet.', '%s orders wait for a transfer to this wallet.', $waiting, 'qersh-for-woocommerce' ) ), View::ltr( (string) $waiting ) );
		} else {
			$orders = esc_html__( 'No order waits for a transfer to this wallet.', 'qersh-for-woocommerce' );
		}
		return array(
			'id'       => $id,
			'label'    => View::wallet_name( $wallet ),
			'until'    => View::ltr( View::time( $now + WalletRows::GONE_SEC ) ),
			'orders'   => $orders,
			'last_one' => ! empty( $wallet['enabled'] ) && 1 === $enabled,
			'back_url' => Module::page_url( 'wallets' ),
		);
	}

	/*
	 * ---------------------------------------------------------------------------------------------------------------
	 * Actions.
	 * ---------------------------------------------------------------------------------------------------------------
	 */

	/**
	 * Adds or edits a wallet (admin-post qersh_wc_wallet_save): its number and name only. A wallet has no type in the
	 * plugin (the Qersh app asks for it on the phone), and a `provider` an edited entry still carries is dropped.
	 */
	public static function handle_save(): void {
		Module::check_action( 'qersh_wc_wallet_save' );
		check_admin_referer( 'qersh_wc_wallet_save' );
		$plugin = Module::plugin();
		$store  = $plugin->config_store();
		if ( $store->is_constant() ) {
			Module::flash( 'error', esc_html__( 'These settings are set in wp-config.php (QERSH_WC_CONFIG) and cannot be changed here.', 'qersh-for-woocommerce' ) );
			Module::redirect( 'wallets' );
		}
		$id      = isset( $_POST['wallet'] ) ? sanitize_key( wp_unslash( $_POST['wallet'] ) ) : '';
		$address = isset( $_POST['receive_address'] ) ? sanitize_text_field( wp_unslash( $_POST['receive_address'] ) ) : '';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- ConfigStore::clean_label() runs sanitize_text_field() and cuts to LABEL_MAX, the rule ConfigStore::clean() applies to an import.
		$label   = isset( $_POST['label'] ) && is_string( $_POST['label'] ) ? ConfigStore::clean_label( wp_unslash( $_POST['label'] ) ) : '';
		$enabled = ! empty( $_POST['enabled'] );

		$config  = $store->get();
		$wallets = array_values( is_array( $config['wallets'] ) ? $config['wallets'] : array() );
		$index   = null;
		foreach ( $wallets as $i => $wallet ) {
			if ( is_array( $wallet ) && isset( $wallet['id'] ) && $wallet['id'] === $id ) {
				$index = $i;
			}
		}
		$is_new = null === $index;
		if ( $is_new ) {
			$id = self::new_id( $wallets );
		}
		// Our own translated words first: Core\Config's messages are developer English with JSON paths.
		$problem = self::address_problem( $address );
		if ( '' !== $problem ) {
			Module::flash( 'error', esc_html( self::address_problem_text( $problem ) ) );
			Module::redirect( 'wallets', $is_new ? array( 'add' => 1 ) : array( 'edit' => $id ) );
		}
		$normal = Payer::normalize( $address );
		// An empty name stays empty: View::wallet_name() then shows "E-wallet" and the number.
		$entry = array(
			'id'              => $id,
			'label'           => $label,
			'receive_address' => null === $normal ? $address : $normal,
			'enabled'         => $enabled,
		);
		if ( $is_new ) {
			$wallets[] = $entry;
		} else {
			$wallets[ $index ] = $entry;
		}
		$config['wallets'] = $wallets;
		$result            = $store->save( $config );
		if ( is_wp_error( $result ) ) {
			Module::flash( 'error', esc_html( self::error_text( $result ) ) );
			Module::redirect( 'wallets', $is_new ? array( 'add' => 1 ) : array( 'edit' => $id ) );
		}
		if ( $is_new ) {
			self::note_link_shown( $id );
			Module::flash( 'success', esc_html__( 'The wallet was added. Now link the phone: generate your developer\'s link with the connection key and open it on the wallet\'s phone.', 'qersh-for-woocommerce' ) );
		} else {
			Module::flash( 'success', esc_html__( 'The wallet was saved.', 'qersh-for-woocommerce' ) );
		}
		Module::redirect( 'wallets' );
	}

	/**
	 * Deletes a wallet (admin-post qersh_wc_wallet_delete): it leaves the configuration, its row becomes the witness
	 * with the entry as snapshot (ConfigStore::save() calls Store\Wallets::mark_deleted(), which erases the pending
	 * key and seals the current one for 72 hours), and with "Erase the connection key now" the key is erased at once.
	 */
	public static function handle_delete(): void {
		Module::check_action( 'qersh_wc_wallet_delete' );
		check_admin_referer( 'qersh_wc_wallet_delete' );
		$plugin = Module::plugin();
		$store  = $plugin->config_store();
		if ( $store->is_constant() ) {
			Module::flash( 'error', esc_html__( 'These settings are set in wp-config.php (QERSH_WC_CONFIG) and cannot be changed here.', 'qersh-for-woocommerce' ) );
			Module::redirect( 'wallets' );
		}
		$id      = isset( $_POST['wallet'] ) ? sanitize_key( wp_unslash( $_POST['wallet'] ) ) : '';
		$erase   = ! empty( $_POST['erase_key'] );
		$config  = $store->get();
		$kept    = array();
		$removed = null;
		foreach ( is_array( $config['wallets'] ) ? $config['wallets'] : array() as $wallet ) {
			if ( is_array( $wallet ) && isset( $wallet['id'] ) && $wallet['id'] === $id ) {
				$removed = $wallet;
				continue;
			}
			$kept[] = $wallet;
		}
		if ( null === $removed ) {
			Module::flash( 'error', esc_html__( 'This wallet does not exist any more.', 'qersh-for-woocommerce' ) );
			Module::redirect( 'wallets' );
		}
		$config['wallets'] = $kept;
		$result            = $store->save( $config );
		if ( is_wp_error( $result ) ) {
			Module::flash( 'error', esc_html( self::error_text( $result ) ) );
			Module::redirect( 'wallets' );
		}
		$now = time();
		try {
			$row = $plugin->wallets()->get( $id );
			if ( null === $row || 'deleted' !== $row['state'] ) {
				$plugin->wallets()->mark_deleted( $id, $now, $removed );
			}
			if ( $erase ) {
				$plugin->wallets()->erase_key( $id, $now );
			} else {
				$plugin->secrets()->seal( $id, $now );
			}
		} catch ( \Throwable $e ) {
			$plugin->logger()->error( 'Qersh could not seal or erase the key of a deleted wallet.', array( 'error' => get_class( $e ) ) );
		}
		$name = esc_html( View::wallet_name( $removed ) );
		if ( $erase ) {
			/* translators: %s: wallet name. */
			Module::flash( 'success', sprintf( esc_html__( 'The wallet “%s” was deleted and its connection key erased. Restoring it now needs a new key.', 'qersh-for-woocommerce' ), $name ) );
		} else {
			/* translators: 1: wallet name, 2: date and time. */
			Module::flash( 'success', sprintf( esc_html__( 'The wallet “%1$s” was deleted. You can restore it with one click until %2$s Cairo time.', 'qersh-for-woocommerce' ), $name, View::ltr( View::time( $now + WalletRows::GONE_SEC ) ) ) );
		}
		Module::redirect( 'wallets' );
	}

	/**
	 * "Restore wallet" and "Restore with a new key" (admin-post qersh_wc_wallet_restore, docs/17 §4.4 step 3): the
	 * row is active again with the same id and URL; within 72 hours the sealed key works again, after that (or after
	 * "Erase the connection key now") the wallet answers 401 no_key until it gets a new key. The saved entry goes
	 * back into the configuration (with QERSH_WC_CONFIG the constant's entry is used).
	 */
	public static function handle_restore(): void {
		Module::check_action( 'qersh_wc_wallet_restore' );
		check_admin_referer( 'qersh_wc_wallet_restore' );
		$plugin   = Module::plugin();
		$store    = $plugin->config_store();
		$constant = $store->is_constant();
		$id       = isset( $_POST['wallet'] ) ? sanitize_key( wp_unslash( $_POST['wallet'] ) ) : '';
		if ( ! Config::wallet_id_ok( $id ) ) {
			Module::redirect( 'wallets' );
		}
		try {
			$row = $plugin->tables_ready() ? $plugin->wallets()->get( $id ) : null;
		} catch ( \Throwable $e ) {
			$row = null;
		}
		if ( null === $row || 'deleted' !== $row['state'] ) {
			Module::flash( 'error', esc_html__( 'This wallet is not deleted, so there is nothing to restore.', 'qersh-for-woocommerce' ) );
			Module::redirect( 'wallets' );
		}
		// Everything is checked before the row changes: a refused restore leaves the deletion, its time and the
		// 72-hour window exactly as they were (and so what the store answers the phone).
		$config = null;
		if ( $constant ) {
			if ( ! in_array( $id, self::constant_wallet_ids(), true ) ) {
				Module::flash( 'error', esc_html__( 'This wallet is no longer in QERSH_WC_CONFIG in wp-config.php, so it cannot be restored. Add it back there with the same id first.', 'qersh-for-woocommerce' ) );
				Module::redirect( 'wallets' );
			}
		} else {
			$config = self::config_with_entry( $store->get(), is_array( $row['snapshot'] ) ? $row['snapshot'] : array(), $id );
			if ( ! empty( Config::validate( Config::with_defaults( $config ) ) ) ) {
				Module::flash( 'error', esc_html__( 'The saved copy of this wallet is incomplete or no longer valid, so it cannot be restored. Add the wallet again with “Add wallet” and link the phone to it.', 'qersh-for-woocommerce' ) );
				Module::redirect( 'wallets' );
			}
		}
		$snapshot = $plugin->wallets()->restore( $id );
		if ( null === $snapshot ) {
			Module::flash( 'error', esc_html__( 'This wallet is not deleted, so there is nothing to restore.', 'qersh-for-woocommerce' ) );
			Module::redirect( 'wallets' );
		}
		if ( ! $constant ) {
			$result = $store->save( $config );
			if ( is_wp_error( $result ) ) {
				// Only a failed write gets here. Delete it again with its first deletion time, so the 72 hours do
				// not start again, and keep the erased key's record.
				$deleted_at = ! empty( $row['deleted_at'] ) ? (int) $row['deleted_at'] : time();
				$erased_at  = ! empty( $row['key_erased_at'] ) ? (int) $row['key_erased_at'] : ( 'gone' === WalletRows::gone_state( $row, time() ) ? time() : 0 );
				try {
					$plugin->wallets()->mark_deleted( $id, $deleted_at, $snapshot );
					if ( $erased_at > 0 ) {
						$plugin->wallets()->erase_key( $id, $erased_at );
					}
				} catch ( \Throwable $e ) {
					$plugin->logger()->error( 'Qersh could not undo a wallet restore that was not saved.', array( 'error' => get_class( $e ) ) );
				}
				Module::flash( 'error', esc_html( self::error_text( $result ) ) );
				Module::redirect( 'wallets' );
			}
		}
		$plugin->forget_config();
		if ( $plugin->secrets()->has_key( $id ) ) {
			Module::flash( 'success', esc_html__( 'The wallet was restored with its key. Tap “Test connection” on the phone so what is waiting arrives now; otherwise it arrives with the next attempt, within about 12 hours.', 'qersh-for-woocommerce' ) );
		} else {
			Module::flash( 'success', esc_html__( 'The wallet was restored with the same link, without a key. 1) Generate your developer\'s link with the connection key and open it on the wallet\'s phone (“Update wallet”). 2) Tap “Test connection”. 3) Open “Not delivered” and tap “Resend all”.', 'qersh-for-woocommerce' ) );
		}
		Module::redirect( 'wallets' );
	}

	/**
	 * A pasted key, link, share message or QERSH_WEBHOOK_SECRET line (admin-post qersh_wc_key_paste, docs/17 §4.8):
	 * becomes the current key at once (the old one stays valid for 24 hours). A paste equal to the pending key is
	 * refused; a different one while a key link is pending needs the merchant's confirmation, because it cancels the
	 * link. The paste is never sanitized, stored or logged.
	 */
	public static function handle_key_paste(): void {
		Module::check_action( 'qersh_wc_key_paste' );
		check_admin_referer( 'qersh_wc_key_paste' );
		$plugin  = Module::plugin();
		$secrets = $plugin->secrets();
		$id      = isset( $_POST['wallet'] ) ? sanitize_key( wp_unslash( $_POST['wallet'] ) ) : '';
		if ( null === $plugin->wallet( $id ) ) {
			Module::flash( 'error', esc_html__( 'This wallet does not exist any more.', 'qersh-for-woocommerce' ) );
			Module::redirect( 'wallets' );
		}
		if ( $secrets->from_constant( $id ) ) {
			Module::flash( 'error', esc_html__( 'The connection key of this wallet is set in wp-config.php and cannot be changed here.', 'qersh-for-woocommerce' ) );
			Module::redirect( 'wallets' );
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- a connection key is never sanitized (docs/17 §4.8); it is only parsed.
		$raw = isset( $_POST['qersh_key'] ) && is_string( $_POST['qersh_key'] ) ? (string) wp_unslash( $_POST['qersh_key'] ) : '';
		if ( strlen( $raw ) > self::PASTE_MAX ) {
			$raw = '';
		}
		$found = SetupLink::extract_secret( $raw );
		unset( $raw );
		if ( empty( $found['ok'] ) ) {
			if ( isset( $found['error'] ) && 'keyless_link' === $found['error'] ) {
				Module::flash( 'error', esc_html__( 'This is your developer\'s link that the plugin made, and it has no key in it. Paste the message the phone showed after saving.', 'qersh-for-woocommerce' ) );
			} else {
				Module::flash( 'error', esc_html__( 'No connection key was found in what you pasted. Paste the whole message from the phone, the link alone, or the key itself (at least 24 characters, no spaces).', 'qersh-for-woocommerce' ) );
			}
			Module::redirect( 'wallets', array( 'paste' => $id ) );
		}
		if ( isset( $found['url'] ) && is_string( $found['url'] ) ) {
			$for = self::wallet_of_url( $found['url'] );
			if ( null === $for ) {
				Module::flash( 'error', esc_html__( 'The link in this message does not point to this store, so the phone sends that wallet\'s transfers somewhere else. Open your developer\'s link (under “Another way”) on the wallet\'s phone first, then paste the new message.', 'qersh-for-woocommerce' ) );
				Module::redirect( 'wallets' );
			}
			if ( $for !== $id ) {
				Module::flash( 'error', esc_html__( 'This message belongs to another wallet of this store. Paste it in the card of that wallet.', 'qersh-for-woocommerce' ) );
				Module::redirect( 'wallets' );
			}
		}
		$key = (string) $found['secret'];
		$fp  = $secrets->fingerprint( $id );
		$new = Config::fingerprint( $key );
		if ( is_array( $fp ) && ! empty( $fp['pending_fp'] ) ) {
			if ( $new === $fp['pending_fp'] ) {
				Module::flash( 'error', esc_html__( 'This is the key of the link the plugin generated. Open the link on the wallet\'s phone, then tap “Test connection”; do not paste it here.', 'qersh-for-woocommerce' ) );
				Module::redirect( 'wallets' );
			}
			if ( empty( $_POST['confirm_replace'] ) ) {
				Module::flash( 'warning', esc_html__( 'You have a link with the connection key that nothing has arrived with yet. Pasting this message cancels it and makes the pasted key the connection key. Tick the box under the paste box to continue, then paste again.', 'qersh-for-woocommerce' ) );
				Module::redirect( 'wallets' );
			}
		}
		if ( is_array( $fp ) && isset( $fp['fp'] ) && $new === $fp['fp'] && empty( $fp['pending_fp'] ) ) {
			/* translators: %s: key fingerprint. */
			Module::flash( 'info', sprintf( esc_html__( 'This key is already the connection key of this wallet (fingerprint %s).', 'qersh-for-woocommerce' ), View::ltr( $new ) ) );
			Module::redirect( 'wallets' );
		}
		try {
			$secrets->set_current( $id, $key, time() );
		} catch ( \Throwable $e ) {
			unset( $key );
			Module::flash( 'error', sprintf( esc_html( self::key_not_saved_format() ), View::ltr( \QershPay\WooCommerce\Secrets::constant_name( $id ) ) ) );
			Module::redirect( 'wallets' );
		}
		unset( $key );
		$plugin->logger()->info( 'Qersh saved a pasted connection key for wallet ' . $id . ' (fingerprint ' . $new . ').' );
		/* translators: %s: key fingerprint. */
		Module::flash( 'success', sprintf( esc_html__( 'The connection key was saved (fingerprint %s). Tap “Test connection” on the phone.', 'qersh-for-woocommerce' ), View::ltr( $new ) ) );
		Module::redirect( 'wallets' );
	}

	/**
	 * Cancels a generated link (admin-post qersh_wc_key_pending_cancel): "Cancel this link" in the key window, or the
	 * card's pending key. A pending key is cancelled; a link that became the current key of a wallet that had none is
	 * erased (docs/17 §4.8 rule 7). The fingerprint and time posted name the link, so a newer one is never touched.
	 */
	public static function handle_pending_cancel(): void {
		Module::check_action( 'qersh_wc_key_pending_cancel' );
		check_admin_referer( 'qersh_wc_key_pending_cancel' );
		$plugin  = Module::plugin();
		$secrets = $plugin->secrets();
		$id      = isset( $_POST['wallet'] ) ? sanitize_key( wp_unslash( $_POST['wallet'] ) ) : '';
		$fp_in   = isset( $_POST['fp'] ) ? sanitize_key( wp_unslash( $_POST['fp'] ) ) : '';
		$at_in   = isset( $_POST['created_at'] ) ? absint( wp_unslash( $_POST['created_at'] ) ) : 0;
		if ( ! Config::wallet_id_ok( $id ) || $secrets->from_constant( $id ) || 1 !== preg_match( '/^[0-9a-f]{8}$/', $fp_in ) ) {
			Module::redirect( 'wallets' );
		}
		$fp = $secrets->fingerprint( $id );
		if ( is_array( $fp ) && isset( $fp['pending_fp'] ) && $fp_in === $fp['pending_fp'] ) {
			$secrets->cancel_pending( $id );
			Module::flash( 'success', esc_html__( 'The link was cancelled: its connection key no longer works. The wallet keeps its current key.', 'qersh-for-woocommerce' ) );
		} elseif ( is_array( $fp ) && isset( $fp['fp'] ) && $fp_in === $fp['fp'] && $at_in > 0 && (int) $fp['set_at'] === $at_in && empty( $fp['pending_fp'] ) ) {
			$secrets->erase( $id );
			Module::flash( 'success', esc_html__( 'The link was cancelled and its connection key erased. This wallet has no connection key now.', 'qersh-for-woocommerce' ) );
		} else {
			Module::flash( 'info', esc_html__( 'This link was already replaced or cancelled, so nothing was changed.', 'qersh-for-woocommerce' ) );
		}
		Module::redirect( 'wallets' );
	}

	/**
	 * AJAX (POST only): "Generate your developer's link with the connection key" (docs/17 §4.8 rules 1–3). A wallet
	 * without a key gets it as its current key; a wallet with one gets it as the pending key, which never replaces
	 * the current one until the phone delivers with it. The reply `{link, fingerprint, created_at}` is not cached
	 * and nothing keeps the key outside Secrets.
	 */
	public static function ajax_key_link(): void {
		nocache_headers();
		if ( ! headers_sent() ) {
			header( 'Cache-Control: no-store, private' );
		}
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) ) {
			wp_send_json( array( 'code' => 'qersh_method' ), 405 );
		}
		if ( ! check_ajax_referer( 'qersh_wc_key_link', 'nonce', false ) || ! current_user_can( Module::CAP ) ) {
			wp_send_json( array( 'code' => 'qersh_forbidden' ), 403 );
		}
		$plugin  = Module::plugin();
		$secrets = $plugin->secrets();
		$id      = isset( $_POST['wallet'] ) ? sanitize_key( wp_unslash( $_POST['wallet'] ) ) : '';
		$wallet  = $plugin->wallet( $id );
		if ( null === $wallet ) {
			wp_send_json(
				array(
					'code'    => 'qersh_unknown_wallet',
					'message' => __( 'This wallet does not exist any more.', 'qersh-for-woocommerce' ),
				),
				404
			);
		}
		if ( empty( $plugin->config()['store']['allow_key_link'] ) || $secrets->from_constant( $id ) ) {
			wp_send_json(
				array(
					'code'    => 'qersh_key_link_off',
					'message' => __( 'The link with the connection key is turned off for this wallet.', 'qersh-for-woocommerce' ),
				),
				403
			);
		}
		$rate = self::RATE_PREFIX . $id;
		if ( false !== get_transient( $rate ) ) {
			header( 'Retry-After: ' . self::LINK_RATE_SEC );
			wp_send_json(
				array(
					'code'    => 'qersh_busy',
					'message' => __( 'A link was generated a few seconds ago. Wait a moment before you generate another one.', 'qersh-for-woocommerce' ),
				),
				429
			);
		}
		$now = time();
		$key = Config::new_key();
		// The link is built before the key is stored: when the app would refuse the store URL nothing is kept, so
		// no key is left that nobody holds (docs/17 §4.8 rule 2).
		$link = self::setup_link( Diagnostics::store_url( $id ), View::wallet_name( $wallet ), $key );
		if ( null === $link ) {
			unset( $key );
			wp_send_json(
				array(
					'code'    => 'qersh_bad_url',
					'message' => __( 'The Qersh app would not accept this store URL. Check the site address in Settings → General.', 'qersh-for-woocommerce' ),
				),
				409
			);
		}
		set_transient( $rate, $now, self::LINK_RATE_SEC );
		try {
			$secrets->set_pending( $id, $key, $now );
		} catch ( \Throwable $e ) {
			unset( $link );
			unset( $key );
			wp_send_json(
				array(
					'code'    => 'qersh_key_not_saved',
					'message' => sprintf( self::key_not_saved_format(), \QershPay\WooCommerce\Secrets::constant_name( $id ) ),
				),
				500
			);
		}
		$fingerprint = Config::fingerprint( $key );
		$after       = $secrets->fingerprint( $id );
		$slot        = is_array( $after ) && isset( $after['pending_fp'] ) && $fingerprint === $after['pending_fp'] ? 'pending' : 'current';
		unset( $key );
		self::note_link_shown( $id );
		$plugin->logger()->info( 'Qersh generated a link with a connection key for wallet ' . $id . ' (fingerprint ' . $fingerprint . ', ' . $slot . ').' );
		wp_send_json(
			array(
				'link'        => $link,
				'fingerprint' => $fingerprint,
				'created_at'  => $now,
				'slot'        => $slot,
			),
			200
		);
	}

	/*
	 * ---------------------------------------------------------------------------------------------------------------
	 * Helpers.
	 * ---------------------------------------------------------------------------------------------------------------
	 */

	/**
	 * Before the first delivery, the store URL shown with a link is what "the store URL changed" compares with
	 * (docs/17 §4.9): it is kept as seen_host and seen_form until a delivery writes the real ones.
	 *
	 * @param string $id Wallet id.
	 */
	private static function note_link_shown( string $id ): void {
		$plugin = Module::plugin();
		try {
			if ( ! $plugin->tables_ready() ) {
				return;
			}
			$plugin->wallets()->ensure( $id );
			$row = $plugin->wallets()->get( $id );
			if ( null === $row || ! empty( $row['last_delivery_at'] ) ) {
				return;
			}
			$parts = Diagnostics::url_parts( Diagnostics::store_url( $id ) );
			$plugin->wallets()->touch(
				$id,
				array(
					'seen_host' => $parts['host'],
					'seen_form' => $parts['form'],
				)
			);
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * The wallet id a store URL names (`…/qersh/v1/webhook/<id>`, `rest_route=…` or `qw=<id>`), or null. Pure.
	 *
	 * @param string $url URL from a pasted message.
	 * @return string|null
	 */
	public static function wallet_of_url( string $url ): ?string {
		$text = rawurldecode( $url );
		if ( 1 === preg_match( '#/qersh/v1/webhook/([a-z0-9]{12})(?![a-z0-9])#', $text, $m ) ) {
			return $m[1];
		}
		if ( 1 === preg_match( '#[?&]wc-api=qersh(?:&|$)#', $text ) && 1 === preg_match( '#[?&]qw=([a-z0-9]{12})(?![a-z0-9])#', $text, $m ) ) {
			return $m[1];
		}
		return null;
	}

	/**
	 * A new wallet id that no wallet and no witness row uses.
	 *
	 * @param array $wallets Current wallets.
	 * @return string
	 */
	private static function new_id( array $wallets ): string {
		$taken = array();
		foreach ( $wallets as $wallet ) {
			if ( is_array( $wallet ) && isset( $wallet['id'] ) ) {
				$taken[] = (string) $wallet['id'];
			}
		}
		try {
			$taken = array_merge( $taken, array_keys( Module::plugin()->wallets()->all() ) );
		} catch ( \Throwable $e ) {
			unset( $e );
		}
		do {
			$id = Config::new_wallet_id();
		} while ( in_array( $id, $taken, true ) );
		return $id;
	}

	/**
	 * What is wrong with the number customers transfer to, by Core\Config's rule for receive_address (the same for
	 * every wallet): '' when Payer::normalize() reads an e-wallet number `01XXXXXXXXX` from it (+20…, spaces and
	 * Arabic-Indic digits included), `number` for anything else (a payment address or another handle, a masked or
	 * short number, nothing). Pure.
	 *
	 * @param string $address What the merchant typed.
	 * @return string
	 */
	public static function address_problem( string $address ): string {
		$normal = Payer::normalize( $address );
		return null !== $normal && 1 === preg_match( '/^01[0-9]{9}$/D', $normal ) ? '' : 'number';
	}

	/**
	 * The merchant's words for an address_problem() code.
	 *
	 * @param string $problem Code.
	 * @return string Plain text.
	 */
	public static function address_problem_text( string $problem ): string {
		unset( $problem ); // One code, one message.
		return __( 'Enter the wallet number customers transfer to: 11 digits starting with 01, such as 01001234567.', 'qersh-for-woocommerce' );
	}

	/**
	 * The configuration with a deleted wallet's saved entry back in it, as "Restore wallet" saves it. Pure.
	 *
	 * @param array  $config   The current configuration.
	 * @param array  $snapshot The entry saved when the wallet was deleted.
	 * @param string $id       Wallet id.
	 * @return array
	 */
	public static function config_with_entry( array $config, array $snapshot, string $id ): array {
		$wallets = array();
		foreach ( isset( $config['wallets'] ) && is_array( $config['wallets'] ) ? $config['wallets'] : array() as $wallet ) {
			if ( is_array( $wallet ) && isset( $wallet['id'] ) && $wallet['id'] === $id ) {
				continue;
			}
			$wallets[] = $wallet;
		}
		$entry             = array_intersect_key( $snapshot, array_flip( Config::WALLET_FIELDS ) );
		$entry['id']       = $id;
		$wallets[]         = $entry;
		$config['wallets'] = $wallets;
		return $config;
	}

	/**
	 * What the merchant reads when a connection key cannot be stored on this site, after a paste or a generated
	 * link: a sprintf() format whose %s is the name of the wallet's constant.
	 *
	 * @return string Plain text.
	 */
	private static function key_not_saved_format(): string {
		/* translators: %s: a constant name. */
		return __( 'The connection key could not be saved on this site. Define LOGGED_IN_KEY and LOGGED_IN_SALT in wp-config.php, or put a key in the constant %s there.', 'qersh-for-woocommerce' );
	}

	/**
	 * The message of a configuration error.
	 *
	 * @param \WP_Error $error Error.
	 * @return string Plain text.
	 */
	public static function error_text( $error ): string {
		return is_wp_error( $error ) ? (string) $error->get_error_message() : '';
	}
}
