<?php
/**
 * The payer field of the classic checkout and of "Pay for order", and the server-side check of what it sends.
 *
 * @package QershPay\WooCommerce
 */

namespace QershPay\WooCommerce;

use QershPay\WooCommerce\Core\Payer;

defined( 'ABSPATH' ) || exit;

/**
 * The same input serves the classic checkout, "Pay for order" and (through checkout-blocks.js) the Blocks checkout
 * (docs/17 §4.1, §6.1):
 * - a choice of the receiving wallet when more than one is enabled ("Which wallet will you transfer to?"), each
 *   shown by the merchant's label and its number, never by a wallet type;
 * - the field "Wallet number you will transfer from" (numeric keypad, dir="ltr", Arabic digits and +20 accepted):
 *   an Egyptian e-wallet number of 11 digits starting with 01, the same for every wallet;
 * - no `value` attribute on the classic checkout, and fixed ids, because WooCommerce restores the payment box
 *   inputs by id after every checkout refresh; payer-field.js fills it from the billing phone only while it is
 *   empty and untouched. On "Pay for order" (no refresh there) PHP fills it with the payer the order already has,
 *   else the order's billing phone (order_prefill());
 * - normalised on blur, and refused when it is one of the store's own wallet numbers (last nine digits, as the
 *   phone compares them).
 */
final class PayerField {

	/** The $_POST key (and input id) of the payer number. */
	const PAYER = 'qersh_payer';

	/** The $_POST key of the chosen wallet. */
	const WALLET = 'qersh_wallet';

	/** The id of the wallet choice group (the classic template's radio group, where its error is shown). */
	const WALLETS = 'qersh_wallets';

	/** Longest value read; a wallet number, in any spelling, is far shorter. */
	const MAX_LENGTH = 200;

	/** A normalised Egyptian e-wallet number (Core\Payer::normalize()), the only payer the field takes. */
	const NUMBER = '/^01[0-9]{9}$/D';

	/**
	 * The enabled wallet entries of the configuration, in order. Pure.
	 *
	 * @param array $wallets Wallet entries (`wallets` of the configuration).
	 * @return array[]
	 */
	public static function enabled_wallets( array $wallets ): array {
		$out = array();
		foreach ( $wallets as $wallet ) {
			if ( is_array( $wallet ) && isset( $wallet['id'] ) && is_string( $wallet['id'] ) && ! empty( $wallet['enabled'] ) ) {
				$out[] = $wallet;
			}
		}
		return $out;
	}

	/**
	 * The payer number of a value: its normalised form when it is an e-wallet number (`01XXXXXXXXX`, from any
	 * spelling Core\Payer reads), else null. An address or a bare name, which Core\Payer reads as a handle, is no
	 * payer here. Pure (Core\Payer only).
	 *
	 * @param mixed $raw Any value.
	 * @return string|null
	 */
	public static function number_of( $raw ): ?string {
		$payer = Payer::normalize( $raw );
		return null !== $payer && 1 === preg_match( self::NUMBER, $payer ) ? $payer : null;
	}

	/**
	 * Checks what the customer sent. Pure (Core\Payer only).
	 *
	 * @param mixed $raw_payer  The payer field as posted.
	 * @param mixed $raw_wallet The chosen wallet id as posted (may be empty with one wallet).
	 * @param array $wallets    Every wallet entry of the configuration (enabled or not: none of the store's own
	 *                          numbers may be the payer).
	 * @return array `[ 'ok' => true, 'payer' => string, 'wallet' => string ]`, or `[ 'ok' => false, 'error' =>
	 *               'no_wallet'|'wallet_choice'|'payer_invalid'|'payer_store' ]`.
	 */
	public static function check( $raw_payer, $raw_wallet, array $wallets ): array {
		$enabled = self::enabled_wallets( $wallets );
		if ( empty( $enabled ) ) {
			return self::error( 'no_wallet' );
		}
		$wallet_id = is_string( $raw_wallet ) ? trim( $raw_wallet ) : '';
		if ( '' === $wallet_id && 1 === count( $enabled ) ) {
			$wallet_id = (string) $enabled[0]['id'];
		}
		$chosen = null;
		foreach ( $enabled as $wallet ) {
			if ( $wallet['id'] === $wallet_id ) {
				$chosen = $wallet;
				break;
			}
		}
		if ( null === $chosen ) {
			return self::error( 'wallet_choice' );
		}

		$text = is_string( $raw_payer ) ? $raw_payer : '';
		if ( strlen( $text ) > self::MAX_LENGTH * 4 ) {
			return self::error( 'payer_invalid' );
		}
		$payer = self::number_of( $text );
		if ( null === $payer ) {
			return self::error( 'payer_invalid' );
		}
		if ( self::is_store_number( $payer, $wallets ) ) {
			return self::error( 'payer_store' );
		}
		return array(
			'ok'     => true,
			'payer'  => $payer,
			'wallet' => $wallet_id,
		);
	}

	/**
	 * Whether a payer number is one of the store's own wallet numbers: the same last nine digits (as the phone
	 * compares them). Anything that is not a number (number_of()), on either side, is never a store number. Pure.
	 *
	 * @param string $payer   Normalised payer number (number_of()).
	 * @param array  $wallets Wallet entries.
	 * @return bool
	 */
	public static function is_store_number( string $payer, array $wallets ): bool {
		if ( 1 !== preg_match( self::NUMBER, $payer ) ) {
			return false;
		}
		foreach ( $wallets as $wallet ) {
			if ( ! is_array( $wallet ) || ! isset( $wallet['receive_address'] ) ) {
				continue;
			}
			$own = self::number_of( $wallet['receive_address'] );
			if ( null !== $own && substr( $own, -9 ) === substr( $payer, -9 ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The store's own wallet numbers, as configured (for the scripts' check). Pure.
	 *
	 * @param array $wallets Wallet entries.
	 * @return string[]
	 */
	public static function store_numbers( array $wallets ): array {
		$out = array();
		foreach ( $wallets as $wallet ) {
			if ( is_array( $wallet ) && isset( $wallet['receive_address'] ) && is_string( $wallet['receive_address'] ) && '' !== $wallet['receive_address'] ) {
				$out[] = $wallet['receive_address'];
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * The wallet numbers a page may carry for its scripts' check: the enabled wallets only, so a wallet the
	 * merchant turned off (often a personal line) never reaches the page source; plus, on the order page, the
	 * order's own wallet, whose number that page shows anyway. The server keeps checking against every wallet
	 * (check(), Claims::submit()), so a turned-off wallet's number is still refused, with the server's message. Pure.
	 *
	 * @param array  $wallets   Every wallet entry of the configuration.
	 * @param string $order_one The order's wallet id ('' at checkout).
	 * @return string[]
	 */
	public static function browser_numbers( array $wallets, string $order_one = '' ): array {
		$shown = array();
		foreach ( $wallets as $wallet ) {
			if ( ! is_array( $wallet ) || ! isset( $wallet['id'] ) || ! is_string( $wallet['id'] ) ) {
				continue;
			}
			if ( ! empty( $wallet['enabled'] ) || ( '' !== $order_one && $wallet['id'] === $order_one ) ) {
				$shown[] = $wallet;
			}
		}
		return self::store_numbers( $shown );
	}

	/**
	 * The message of an error code (docs/17 §6.1, §7). checkout-blocks.js holds the same texts.
	 *
	 * @param string $code Code from check().
	 * @return string
	 */
	public static function message( string $code ): string {
		switch ( $code ) {
			case 'wallet_choice':
				return __( 'Choose the wallet you will transfer to.', 'qersh-for-woocommerce' );
			case 'payer_store':
				return __( "This is the store's wallet number. Enter the wallet number you will transfer from.", 'qersh-for-woocommerce' );
			case 'no_wallet':
				return __( 'This store has no wallet to transfer to right now. Choose another way to pay.', 'qersh-for-woocommerce' );
			default:
				return __( 'Enter an Egyptian wallet number of 11 digits starting with 01.', 'qersh-for-woocommerce' );
		}
	}

	/**
	 * The field a refusal belongs to, for the id of WooCommerce's notice (the classic checkout links the notice to
	 * it and marks it invalid): the wallet choice for a missing wallet, the payer field otherwise. Pure.
	 *
	 * @param string $code Code from check().
	 * @return string
	 */
	public static function notice_id( string $code ): string {
		return 'wallet_choice' === $code ? self::WALLETS : self::PAYER;
	}

	/**
	 * The posted values of the field (from $_POST, which the Store API also fills from paymentMethodData).
	 * WooCommerce checked the checkout or pay-for-order nonce before it calls validate_fields() and
	 * process_payment(); the Store API checks its own nonce.
	 *
	 * @return array{0: string, 1: string} Payer text and wallet id.
	 */
	public static function posted(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verified the checkout nonce before calling the gateway.
		$payer  = isset( $_POST[ self::PAYER ] ) && is_string( $_POST[ self::PAYER ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::PAYER ] ) ) : '';
		$wallet = isset( $_POST[ self::WALLET ] ) && is_string( $_POST[ self::WALLET ] ) ? sanitize_key( wp_unslash( $_POST[ self::WALLET ] ) ) : '';
		// phpcs:enable
		return array( $payer, $wallet );
	}

	/**
	 * Prints the field (classic checkout, or "Pay for order" with the order).
	 *
	 * @param Plugin         $plugin The plugin.
	 * @param \WC_Order|null $order  The order on "Pay for order", or null at checkout.
	 */
	public static function render( Plugin $plugin, $order = null ): void {
		$config  = $plugin->config();
		$all     = isset( $config['wallets'] ) && is_array( $config['wallets'] ) ? $config['wallets'] : array();
		$views   = Checkout::wallet_views( $plugin );
		$checked = '';
		if ( $order instanceof \WC_Order ) {
			$previous = (string) $order->get_meta( '_qersh_wallet' );
			foreach ( $views as $view ) {
				if ( $view['id'] === $previous ) {
					$checked = $previous;
				}
			}
		}
		if ( 1 === count( $views ) ) {
			$checked = $views[0]['id'];
		}
		$value = '';
		if ( $order instanceof \WC_Order ) {
			$row    = Checkout::order_row( $plugin, $order->get_id() );
			$stored = null !== $row && isset( $row['payer_key'] ) && '' !== (string) $row['payer_key'] ? (string) $row['payer_key'] : (string) $order->get_meta( '_qersh_payer_key' );
			$value  = self::order_prefill( $stored, (string) $order->get_billing_phone(), $all )['value'];
		}

		wc_get_template(
			'checkout/payer-field.php',
			array(
				'views'         => $views,
				'checked'       => $checked,
				'value'         => $value,
				'store_numbers' => self::browser_numbers( $all ),
				'messages'      => array(
					'invalid' => self::message( 'payer_invalid' ),
					'store'   => self::message( 'payer_store' ),
					'choice'  => self::message( 'wallet_choice' ),
				),
			),
			'qersh-for-woocommerce/',
			QERSH_WC_DIR . 'templates/'
		);
	}

	/**
	 * What "Pay for order" puts in the field (docs/17 §6.4: the same field, which the customer may change): the
	 * payer the order already has, so a customer who only came to switch wallets keeps the number their transfer
	 * is matched by; else the order's billing phone, as at checkout. Only a number is filled in (an address an
	 * older order may carry is no payer the field takes), and never one of the store's own wallets. Pure
	 * (Core\Payer only).
	 *
	 * @param string $stored        The order's payer (its qersh_orders row, else `_qersh_payer_key`), or ''.
	 * @param string $billing_phone The order's billing phone.
	 * @param array  $wallets       Every wallet entry of the configuration.
	 * @return array{value: string, kept: bool} `kept` when the value is the stored payer.
	 */
	public static function order_prefill( string $stored, string $billing_phone, array $wallets ): array {
		$payer = '' === trim( $stored ) ? null : self::number_of( $stored );
		if ( null !== $payer && ! self::is_store_number( $payer, $wallets ) ) {
			return array(
				'value' => $payer,
				'kept'  => true,
			);
		}
		$phone = self::number_of( $billing_phone );
		return array(
			'value' => null !== $phone && ! self::is_store_number( $phone, $wallets ) ? $phone : '',
			'kept'  => false,
		);
	}

	/**
	 * A refusal.
	 *
	 * @param string $code Code.
	 * @return array
	 */
	private static function error( string $code ): array {
		return array(
			'ok'    => false,
			'error' => $code,
		);
	}
}
