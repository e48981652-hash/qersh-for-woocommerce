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
 * - a choice of the receiving wallet when more than one is enabled ("Which wallet will you transfer to?");
 * - the field "Wallet number you will transfer from" (numeric keypad, dir="ltr", Arabic digits and +20 accepted),
 *   with a "Wallet number / InstaPay address" switch that only exists while an InstaPay wallet is enabled;
 * - no `value` attribute on the classic checkout, and fixed ids, because WooCommerce restores the payment box
 *   inputs by id after every checkout refresh; payer-field.js fills it from the billing phone only while it is
 *   empty and untouched. On "Pay for order" (no refresh there) PHP fills it from the order's billing phone;
 * - normalised on blur, and refused when it is one of the store's own wallet numbers (last nine digits, as the
 *   phone compares them).
 */
final class PayerField {

	/** The $_POST key (and input id) of the payer number or address. */
	const PAYER = 'qersh_payer';

	/** The $_POST key of the chosen wallet. */
	const WALLET = 'qersh_wallet';

	/** The name of the number / InstaPay address switch (not needed by the server). */
	const KIND = 'qersh_payer_kind';

	/** Longest value read; a wallet number or an InstaPay address is far shorter. */
	const MAX_LENGTH = 200;

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
	 * Whether an InstaPay wallet is among these. Pure.
	 *
	 * @param array $wallets Wallet entries.
	 * @return bool
	 */
	public static function has_instapay( array $wallets ): bool {
		foreach ( $wallets as $wallet ) {
			if ( is_array( $wallet ) && isset( $wallet['provider'] ) && 'instapay_app' === $wallet['provider'] ) {
				return true;
			}
		}
		return false;
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
		$payer = Payer::normalize( $text );
		if ( null === $payer || ( false !== strpos( $payer, '@' ) && ! self::has_instapay( $enabled ) ) ) {
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
	 * Whether a normalised payer is one of the store's own wallets: the same last nine digits as a wallet number,
	 * or the same InstaPay address. Pure.
	 *
	 * @param string $payer   Normalised payer (Payer::normalize()).
	 * @param array  $wallets Wallet entries.
	 * @return bool
	 */
	public static function is_store_number( string $payer, array $wallets ): bool {
		$is_handle = false !== strpos( $payer, '@' );
		foreach ( $wallets as $wallet ) {
			if ( ! is_array( $wallet ) || ! isset( $wallet['receive_address'] ) ) {
				continue;
			}
			$own = Payer::normalize( $wallet['receive_address'] );
			if ( null === $own ) {
				continue;
			}
			if ( $is_handle ) {
				if ( $own === $payer ) {
					return true;
				}
				continue;
			}
			if ( false === strpos( $own, '@' ) && substr( $own, -9 ) === substr( $payer, -9 ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The store's own receive addresses (for the scripts' check). Pure.
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
	 * The receive addresses a page may carry for its scripts' check: the enabled wallets only, so a wallet the
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
	 * The message of an error code (docs/17 §6.1, §7).
	 *
	 * @param string $code     Code from check().
	 * @param bool   $instapay Whether the InstaPay switch is shown.
	 * @return string
	 */
	public static function message( string $code, bool $instapay ): string {
		switch ( $code ) {
			case 'wallet_choice':
				return __( 'Choose the wallet you will transfer to.', 'qersh-for-woocommerce' );
			case 'payer_store':
				return __( "This is the store's wallet number. Enter the wallet number you will transfer from.", 'qersh-for-woocommerce' );
			case 'no_wallet':
				return __( 'This store has no wallet to transfer to right now. Choose another way to pay.', 'qersh-for-woocommerce' );
			default:
				return $instapay
					? __( 'Enter an Egyptian wallet number of 11 digits starting with 01, or an InstaPay address like name@instapay.', 'qersh-for-woocommerce' )
					: __( 'Enter an Egyptian wallet number of 11 digits starting with 01.', 'qersh-for-woocommerce' );
		}
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
		$value   = '';
		if ( $order instanceof \WC_Order ) {
			$previous = (string) $order->get_meta( '_qersh_wallet' );
			foreach ( $views as $view ) {
				if ( $view['id'] === $previous ) {
					$checked = $previous;
				}
			}
			$phone = Payer::normalize( (string) $order->get_billing_phone() );
			if ( null !== $phone && false === strpos( $phone, '@' ) ) {
				$value = $phone;
			}
		}
		if ( 1 === count( $views ) ) {
			$checked = $views[0]['id'];
		}
		$instapay = false;
		foreach ( $views as $view ) {
			if ( $view['instapay'] ) {
				$instapay = true;
			}
		}
		$kind = 'number';
		foreach ( $views as $view ) {
			if ( $view['id'] === $checked && $view['instapay'] ) {
				$kind  = 'instapay';
				$value = '';
			}
		}

		wc_get_template(
			'checkout/payer-field.php',
			array(
				'views'         => $views,
				'checked'       => $checked,
				'value'         => $value,
				'instapay'      => $instapay,
				'kind'          => $kind,
				'store_numbers' => self::browser_numbers( $all ),
				'messages'      => array(
					'invalid' => self::message( 'payer_invalid', $instapay ),
					'store'   => self::message( 'payer_store', $instapay ),
					'choice'  => self::message( 'wallet_choice', $instapay ),
				),
			),
			'qersh-for-woocommerce/',
			QERSH_WC_DIR . 'templates/'
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
