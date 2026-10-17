<?php
/**
 * Why a transfer needs the merchant: one reason per row, in the merchant's words (docs/17 §4.6 table).
 *
 * @package QershPay\WooCommerce
 */

namespace QershPay\WooCommerce\Admin;

use QershPay\WooCommerce\Core\Money;
use QershPay\WooCommerce\Core\Risk;
use QershPay\WooCommerce\Core\TxnId;
use QershPay\WooCommerce\MatchPolicy;
use QershPay\WooCommerce\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * The one English source of the reason sentences of docs/17 §4.6. MatchPolicy::reason_text() builds the phone's
 * notes and the note column from text() (it turns the HTML into plain text), and keeps only the sentences of the
 * codes this class does not name (several_orders, late_delivery, no_amount, bad_time, cancelled_paid,
 * not_confirmed), which text() reads back from it for an `unknown` pick.
 *
 * Public API (other modules rely on it; keep it stable):
 * - Constants: STORE_REASONS (the codes of qersh_transfers.reason), CLAIM_EVALS, RISK_ORDER (the risk reasons with
 *   a sentence, in the order they are shown).
 * - Pure: needs_decision( array $row ): bool, names_claimant( string $code ): bool,
 *   pick( array $row ): array `[ 'code', 'variant', 'detail' ]`, window_variant( array $row, ?array $claimant,
 *   int $late ): string, remainder( array $order, bool $round ): int.
 * - Store reads: params( array $row, array $pick, Plugin $plugin ): array `[ 'n', 'm', 't', 'z', 'w', 'variant' ]`.
 * - Texts: text( array $pick, array $params ): string (escaped HTML, values isolated with `<bdi>`),
 *   for_row( array $row, Plugin $plugin ): string (pick, params and text in one call), and forgery_warning():
 *   string (plain text, the warning a forged transfer's order carries).
 */
final class Reasons {

	/**
	 * Reason codes the plugin writes into qersh_transfers.reason (docs/17 §4.3, §4.6).
	 */
	const STORE_REASONS = array(
		'personal',
		'no_payer',
		'masked',
		'unknown_risk',
		'duplicate_txn',
		'closed_order',
		'stock_short',
		'partial_overdue',
		'claim_overdue',
		'forgery',
		'txn_contested',
		'txn_amount',
		'txn_window',
		'txn_wallet',
	);

	/**
	 * Evaluations of a customer's transaction id that explain a `no_payer` row better than its reason.
	 */
	const CLAIM_EVALS = array( 'txn_contested', 'txn_amount', 'txn_window', 'txn_wallet', 'closed_order' );

	/**
	 * Risk reasons with their own sentence, in the order they are shown when several apply.
	 */
	const RISK_ORDER = array(
		'suspected_forgery',
		'no_baseline',
		'balance_stale',
		'amount_over_limit',
		'amount_over_max',
		'notification_only',
		'balance_unavailable',
		'partial_template',
		'unverified_template',
		'local_template',
	);

	/**
	 * Whether a transfer row is in "Needs a decision" (docs/17 §4.6): review and overpaid, a transfer flagged as
	 * forged that was not dismissed, and a partial payment past its deadline or a customer's transaction id waiting
	 * at the deadline that was not dismissed (attached included: an order paid in part by an attach). The same rule
	 * as Store\Transfers::NEEDS_DECISION_SQL. Pure.
	 *
	 * @param array $row qersh_transfers row.
	 * @return bool
	 */
	public static function needs_decision( array $row ): bool {
		$outcome = isset( $row['outcome'] ) && is_string( $row['outcome'] ) ? $row['outcome'] : null;
		$reason  = isset( $row['reason'] ) && is_string( $row['reason'] ) ? $row['reason'] : null;
		if ( in_array( $outcome, array( 'review', 'overpaid' ), true ) ) {
			return true;
		}
		if ( isset( $row['flag'] ) && 'forgery' === $row['flag'] && 'dismissed' !== $outcome ) {
			return true;
		}
		return in_array( $reason, array( 'partial_overdue', 'claim_overdue' ), true ) && 'dismissed' !== $outcome;
	}

	/**
	 * Whether the sentence of a claimant's reason already names the claimant's order ("The customer of order #N
	 * entered this transaction id, but …"), so the suggestion prints it alone. Pure.
	 *
	 * @param string $code Reason code.
	 * @return bool
	 */
	public static function names_claimant( string $code ): bool {
		return in_array( $code, array( 'claim_overdue', 'txn_contested', 'txn_amount', 'txn_window', 'txn_wallet' ), true );
	}

	/**
	 * Chooses the one reason a transfer row shows. Pure.
	 *
	 * The risk reasons explain why Qersh did not confirm a transfer on its own, and what to check before deciding,
	 * so they are shown only on a row that needs a decision; an attached, matched, partial or unmatched row shows its
	 * note instead. A dismissed row shows its note ("Dismissed by …") too.
	 *
	 * @param array $row qersh_transfers row (typed: risk_reasons an array).
	 * @return array `[ 'code' => string, 'variant' => string, 'detail' => string ]`; code '' when there is none.
	 *               Codes are the STORE_REASONS, the RISK_ORDER risk reasons, `merchant_confirmed`, `risk_other`
	 *               (a known risk reason without a sentence; detail = it) and `unknown` (detail = the code). For
	 *               `unknown_risk` the detail is the risk reasons this version does not know, comma separated.
	 */
	public static function pick( array $row ): array {
		$out      = function ( $code, $variant = '', $detail = '' ) {
			return array(
				'code'    => $code,
				'variant' => $variant,
				'detail'  => (string) $detail,
			);
		};
		$provider = isset( $row['provider'] ) ? (string) $row['provider'] : '';
		$reason   = isset( $row['reason'] ) && is_string( $row['reason'] ) ? $row['reason'] : '';
		$eval     = isset( $row['claim_eval'] ) && is_string( $row['claim_eval'] ) ? $row['claim_eval'] : '';
		$reasons  = isset( $row['risk_reasons'] ) && is_array( $row['risk_reasons'] ) ? array_values( array_filter( $row['risk_reasons'], 'is_string' ) ) : array();
		$outcome  = isset( $row['outcome'] ) && is_string( $row['outcome'] ) ? $row['outcome'] : '';
		if ( 'decided' === $reason ) {
			// "Mark as decided" (Orders::resolved_fields()) replaced an overdue reason: no reason is left, so the row
			// shows its note ("Marked as decided by …").
			$reason = '';
		}

		if ( isset( $row['flag'] ) && 'forgery' === $row['flag'] ) {
			return $out( 'forgery' );
		}
		if ( 'dismissed' === $outcome ) {
			return $out( '' );
		}
		if ( 'no_payer' === $reason && in_array( $eval, self::CLAIM_EVALS, true ) ) {
			return $out( $eval, 'claim_eval' );
		}
		if ( 'no_payer' === $reason ) {
			return $out( 'no_payer', in_array( $provider, TxnId::CLAIM_PROVIDERS, true ) ? 'orange' : '' );
		}
		if ( 'unknown_risk' === $reason ) {
			return $out( 'unknown_risk', '', implode( ', ', Risk::unknown_reasons( $reasons ) ) );
		}
		if ( '' !== $reason && in_array( $reason, self::STORE_REASONS, true ) ) {
			return $out( $reason );
		}
		if ( isset( $row['balance_chain'] ) && 'merchant_confirmed' === $row['balance_chain'] && in_array( $outcome, array( 'review', 'overpaid' ), true ) ) {
			return $out( 'merchant_confirmed' );
		}
		if ( ! self::needs_decision( $row ) ) {
			return '' !== $reason ? $out( 'unknown', '', $reason ) : $out( '' );
		}
		foreach ( self::RISK_ORDER as $risk ) {
			if ( in_array( $risk, $reasons, true ) ) {
				if ( 'suspected_forgery' === $risk ) {
					return $out( 'forgery' );
				}
				if ( 'balance_unavailable' === $risk ) {
					$notification = 'instapay_app' === $provider || in_array( 'notification_only', $reasons, true );
					return $out( 'balance_unavailable', $notification ? 'notification' : 'sms' );
				}
				return $out( $risk );
			}
		}
		if ( '' !== $reason ) {
			return $out( 'unknown', '', $reason );
		}
		if ( ! empty( $reasons ) ) {
			return $out( Risk::is_known( $reasons[0] ) ? 'risk_other' : 'unknown', '', $reasons[0] );
		}
		return $out( '' );
	}

	/**
	 * The variant of txn_window: the money arrived before the order existed (`created`), or the id was typed before
	 * the operator's message (`early`). Pure.
	 *
	 * @param array      $row      Transfer row.
	 * @param array|null $claimant qersh_orders row of the order whose customer typed the id.
	 * @param int        $late     Late window in seconds.
	 * @return string `created` or `early`.
	 */
	public static function window_variant( array $row, $claimant, int $late ): string {
		if ( ! is_array( $claimant ) ) {
			return 'created';
		}
		$money = isset( $row['money_at'] ) ? (int) $row['money_at'] : 0;
		if ( isset( $claimant['created_at'] ) && (int) $claimant['created_at'] > $money ) {
			return 'created';
		}
		$sms      = isset( $row['sms_at'] ) ? (int) $row['sms_at'] : $money;
		$operator = min( $money, max( $money - $late, $sms ) );
		if ( isset( $claimant['claim_at'] ) && (int) $claimant['claim_at'] < $operator ) {
			return 'early';
		}
		return 'created';
	}

	/**
	 * What is left to pay on an order row, rounded up to a whole pound when the store does so. Pure.
	 *
	 * @param array $order qersh_orders row.
	 * @param bool  $round round_up_to_pound.
	 * @return int Piasters.
	 */
	public static function remainder( array $order, bool $round ): int {
		$left = max( 0, (int) ( isset( $order['price_minor'] ) ? $order['price_minor'] : 0 ) - (int) ( isset( $order['paid_minor'] ) ? $order['paid_minor'] : 0 ) );
		return $round ? Money::round_up_pound( $left ) : $left;
	}

	/**
	 * The values the sentence of a row names: order numbers (N, M), a transfer id (T), a remainder (Z) and a wallet
	 * (W), read from the store when the row is shown.
	 *
	 * @param array  $row    Transfer row.
	 * @param array  $pick   pick() result.
	 * @param Plugin $plugin The plugin.
	 * @return array `[ 'n' => ?int, 'm' => ?int, 't' => ?string, 'z' => ?int, 'w' => ?string, 'variant' => string ]`
	 */
	public static function params( array $row, array $pick, Plugin $plugin ): array {
		$out    = array(
			'n'       => null,
			'm'       => null,
			't'       => null,
			'z'       => null,
			'w'       => null,
			'variant' => $pick['variant'],
		);
		$code   = $pick['code'];
		$config = $plugin->config();
		$late   = isset( $config['receiver']['matching']['late_window_min'] ) ? (int) $config['receiver']['matching']['late_window_min'] * 60 : 7200;
		$round  = ! empty( $config['store']['round_up_to_pound'] );
		$txn    = isset( $row['provider_txn_id'] ) && is_string( $row['provider_txn_id'] ) ? $row['provider_txn_id'] : '';
		try {
			if ( 'duplicate_txn' === $code && '' !== $txn ) {
				foreach ( $plugin->transfers()->find_by_txn( (string) $row['provider'], $txn ) as $other ) {
					if ( $other['transfer_id'] !== $row['transfer_id'] ) {
						$out['t'] = (string) $other['transfer_id'];
						$out['n'] = isset( $other['order_id'] ) ? $other['order_id'] : null;
						break;
					}
				}
				return $out;
			}
			$claimants = array();
			if ( '' !== $txn && in_array( (string) $row['provider'], TxnId::CLAIM_PROVIDERS, true ) ) {
				$claimants = $plugin->orders_index()->claimants( $txn );
			}
			$first = isset( $claimants[0] ) ? $claimants[0] : null;
			switch ( $code ) {
				case 'no_payer':
					// "…and no customer has entered this transaction id yet" stops being true once one has.
					$out['variant'] = 'orange' === $pick['variant'] && empty( $claimants ) ? 'orange' : '';
					break;
				case 'closed_order':
					$out['n'] = null !== $first ? (int) $first['order_id'] : self::closed_order_of_payer( $row, $plugin, $late );
					break;
				case 'stock_short':
					$out['n'] = ! empty( $row['order_id'] ) ? (int) $row['order_id'] : ( null !== $first ? (int) $first['order_id'] : null );
					break;
				case 'claim_overdue':
					$out['n'] = null !== $first ? (int) $first['order_id'] : ( ! empty( $row['order_id'] ) ? (int) $row['order_id'] : null );
					break;
				case 'txn_contested':
					if ( count( $claimants ) >= 2 ) {
						$out['n']       = (int) $claimants[0]['order_id'];
						$out['m']       = (int) $claimants[1]['order_id'];
						$out['variant'] = 'two';
					} else {
						$out['n']       = null !== $first ? (int) $first['order_id'] : null;
						$out['m']       = self::competitor( $row, $plugin, $late, $round, null === $out['n'] ? 0 : $out['n'] );
						$out['variant'] = 'competitor';
					}
					break;
				case 'txn_amount':
					$out['n'] = null !== $first ? (int) $first['order_id'] : null;
					$out['z'] = null !== $first ? self::remainder( $first, $round ) : null;
					break;
				case 'txn_window':
					$out['n']       = null !== $first ? (int) $first['order_id'] : null;
					$out['variant'] = self::window_variant( $row, $first, $late );
					break;
				case 'txn_wallet':
					$out['n'] = null !== $first ? (int) $first['order_id'] : null;
					if ( null !== $first ) {
						$wallet   = $plugin->wallet( (string) $first['wallet'] );
						$out['w'] = null !== $wallet ? View::wallet_name( $wallet ) : (string) $first['wallet'];
					}
					break;
				default:
					$out['n'] = ! empty( $row['order_id'] ) ? (int) $row['order_id'] : null;
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}
		return $out;
	}

	/**
	 * The merchant's sentence for a row, as escaped HTML.
	 *
	 * @param array $pick   pick() result.
	 * @param array $params params() result.
	 * @return string
	 */
	public static function text( array $pick, array $params ): string {
		$n = null !== $params['n'] ? View::ltr( '#' . View::order_number( (int) $params['n'] ) ) : '';
		$m = null !== $params['m'] ? View::ltr( '#' . View::order_number( (int) $params['m'] ) ) : '';
		switch ( $pick['code'] ) {
			case '':
				return '';
			case 'no_baseline':
			case 'balance_stale':
				return esc_html__( 'Qersh has not confirmed the wallet balance yet: confirm your balance with “Sync balance” on the phone.', 'qersh-for-woocommerce' );
			case 'amount_over_limit':
				return esc_html__( 'Larger than the “Auto-confirm limit (EGP)” in the wallet settings on the phone.', 'qersh-for-woocommerce' );
			case 'amount_over_max':
				// Above the largest amount the app's message templates accept, not the merchant's limit: no setting
				// on the phone changes it.
				/* translators: %s: risk reason code. */
				return sprintf( esc_html__( 'Qersh did not confirm this transfer on its own (%s): check that the money arrived in the wallet app.', 'qersh-for-woocommerce' ), View::ltr( 'amount_over_max' ) );
			case 'notification_only':
				return esc_html__( 'An InstaPay notification alone, with no bank message to confirm it; check that the money arrived in the InstaPay app.', 'qersh-for-woocommerce' );
			case 'balance_unavailable':
				if ( 'notification' === $pick['variant'] ) {
					return esc_html__( 'An InstaPay notification alone, with no bank message to confirm it; check that the money arrived in the InstaPay app.', 'qersh-for-woocommerce' );
				}
				return esc_html__( 'This message does not state the balance, so Qersh cannot check it: check that the money arrived in the bank or wallet app.', 'qersh-for-woocommerce' );
			case 'partial_template':
			case 'unverified_template':
			case 'local_template':
				return esc_html__( 'Qersh reads this message with a template that is not fully verified yet: check that the money arrived in the bank or wallet app.', 'qersh-for-woocommerce' );
			case 'no_payer':
				if ( 'orange' === $params['variant'] ) {
					return esc_html__( 'The message does not name the payer\'s number, and no customer has entered this transaction id yet.', 'qersh-for-woocommerce' );
				}
				return esc_html__( 'The message does not name the payer\'s number.', 'qersh-for-woocommerce' );
			case 'masked':
				return esc_html__( 'The payer\'s number arrived masked; set “Send sender details” to “Full name and number”.', 'qersh-for-woocommerce' );
			case 'duplicate_txn':
				/* translators: %s: transfer id. */
				return sprintf( esc_html__( 'Duplicate transaction id: it came before in transfer %s.', 'qersh-for-woocommerce' ), View::ltr( null !== $params['t'] ? (string) $params['t'] : '…' ) );
			case 'closed_order':
				if ( '' === $n ) {
					return esc_html__( 'Arrived after the payer\'s order was closed.', 'qersh-for-woocommerce' );
				}
				/* translators: %s: order number. */
				return sprintf( esc_html__( 'Arrived after order %s was closed.', 'qersh-for-woocommerce' ), $n );
			case 'stock_short':
				/* translators: %s: order number. */
				return sprintf( esc_html__( 'There is not enough stock to bring back order %s.', 'qersh-for-woocommerce' ), '' === $n ? '…' : $n );
			case 'partial_overdue':
				return esc_html__( 'Part of the amount arrived, and the rest did not arrive before the deadline.', 'qersh-for-woocommerce' );
			case 'claim_overdue':
				/* translators: %s: order number. */
				return sprintf( esc_html__( 'The customer of order %s entered this transaction id, and its deadline came before your decision; the order is on hold until you decide.', 'qersh-for-woocommerce' ), '' === $n ? '…' : $n );
			case 'txn_contested':
				if ( 'two' === $params['variant'] ) {
					/* translators: 1: order number, 2: order number. */
					return sprintf( esc_html__( 'This transaction id was entered on orders %1$s and %2$s.', 'qersh-for-woocommerce' ), $n, $m );
				}
				/* translators: 1: order number, 2: order number. */
				return sprintf( esc_html__( 'The customer of order %1$s entered this transaction id, but order %2$s waits for the same amount on this wallet. Check the payer\'s name in the wallet app before you attach it.', 'qersh-for-woocommerce' ), '' === $n ? '…' : $n, '' === $m ? '…' : $m );
			case 'txn_amount':
				/* translators: 1: order number, 2: amount. */
				return sprintf( esc_html__( 'The customer of order %1$s entered this transaction id, but the amount differs from what is left to pay on the order (%2$s).', 'qersh-for-woocommerce' ), '' === $n ? '…' : $n, null === $params['z'] ? '…' : View::money( (int) $params['z'] ) );
			case 'txn_window':
				if ( 'early' === $params['variant'] ) {
					/* translators: %s: order number. */
					return sprintf( esc_html__( 'The transaction id was entered on order %s before the operator sent the message of this transfer.', 'qersh-for-woocommerce' ), '' === $n ? '…' : $n );
				}
				/* translators: %s: order number. */
				return sprintf( esc_html__( 'The customer of order %s entered this transaction id, but the money arrived before the order was created, or outside its time.', 'qersh-for-woocommerce' ), '' === $n ? '…' : $n );
			case 'txn_wallet':
				/* translators: 1: order number, 2: wallet name. */
				return sprintf( esc_html__( 'The customer of order %1$s entered this transaction id, but chose the wallet %2$s.', 'qersh-for-woocommerce' ), '' === $n ? '…' : $n, esc_html( null === $params['w'] ? '…' : (string) $params['w'] ) );
			case 'forgery':
				return esc_html__( 'This transfer\'s message is suspected to be forged.', 'qersh-for-woocommerce' );
			case 'merchant_confirmed':
				return esc_html__( 'You confirmed the balance on the phone after the transfer arrived: attach it to its order.', 'qersh-for-woocommerce' );
			case 'personal':
				return esc_html__( 'Personal transfer: no open order for it in the store', 'qersh-for-woocommerce' );
			case 'unknown_risk':
				if ( '' !== $pick['detail'] ) {
					// docs/17 §4.6 rule 1 and the table's last row: the new value is named.
					/* translators: %s: reason code. */
					return sprintf( esc_html__( 'A reason this version does not know (%s): check the transfer in the wallet app.', 'qersh-for-woocommerce' ), View::ltr( $pick['detail'] ) );
				}
				return esc_html__( 'A new risk reason that this version does not know: check the transfer in the wallet app.', 'qersh-for-woocommerce' );
			case 'risk_other':
				/* translators: %s: risk reason code. */
				return sprintf( esc_html__( 'Qersh did not confirm this transfer on its own (%s): check that the money arrived in the wallet app.', 'qersh-for-woocommerce' ), View::ltr( $pick['detail'] ) );
		}
		// A reason code the matching rules write beyond the table of docs/17 §4.6 has its sentence there, and only
		// there. MatchPolicy::reason_text() never sends an `unknown` pick back here, so this cannot loop.
		if ( 'unknown' === $pick['code'] && '' !== $pick['detail'] ) {
			try {
				$text = MatchPolicy::reason_text( $pick['detail'], array( 'N' => $params['n'] ) );
			} catch ( \Throwable $e ) {
				$text = '';
			}
			if ( '' !== $text ) {
				return esc_html( $text );
			}
		}
		/* translators: %s: reason code. */
		return sprintf( esc_html__( 'A reason this version does not know (%s): check the transfer in the wallet app.', 'qersh-for-woocommerce' ), View::ltr( '' === $pick['detail'] ? (string) $pick['code'] : $pick['detail'] ) );
	}

	/**
	 * The sentence of a row in one call.
	 *
	 * @param array  $row    Transfer row.
	 * @param Plugin $plugin The plugin.
	 * @return string Escaped HTML.
	 */
	public static function for_row( array $row, Plugin $plugin ): string {
		$pick = self::pick( $row );
		if ( '' === $pick['code'] ) {
			return '';
		}
		return self::text( $pick, self::params( $row, $pick, $plugin ) );
	}

	/**
	 * The warning an order paid by a transfer suspected to be forged carries: the order box, the administrator's
	 * alert email and the order note say the same sentence.
	 *
	 * @return string Plain text (escape it for its context).
	 */
	public static function forgery_warning(): string {
		return __( 'This transfer\'s message is suspected to be forged. Do not deliver the order until you have checked that the money arrived in the wallet app.', 'qersh-for-woocommerce' );
	}

	/**
	 * A closed order of the payer in the transfer's window, for closed_order without a claimant.
	 *
	 * @param array  $row    Transfer row.
	 * @param Plugin $plugin The plugin.
	 * @param int    $late   Late window in seconds.
	 * @return int|null
	 */
	private static function closed_order_of_payer( array $row, Plugin $plugin, int $late ): ?int {
		if ( empty( $row['payer_key'] ) ) {
			return ! empty( $row['order_id'] ) ? (int) $row['order_id'] : null;
		}
		$money = (int) $row['money_at'];
		foreach ( array_reverse( $plugin->orders_index()->for_payer( (string) $row['payer_key'], $money - $late, $money ) ) as $order ) {
			if ( in_array( $order['state'], array( 'closed', 'review' ), true ) ) {
				return (int) $order['order_id'];
			}
		}
		return null;
	}

	/**
	 * Another live order on the transfer's wallet that waits for the same amount (rule 4a gate 6), for the text.
	 *
	 * @param array  $row    Transfer row.
	 * @param Plugin $plugin The plugin.
	 * @param int    $late   Late window in seconds.
	 * @param bool   $round  round_up_to_pound.
	 * @param int    $not    Order id to leave out.
	 * @return int|null
	 */
	private static function competitor( array $row, Plugin $plugin, int $late, bool $round, int $not ): ?int {
		$money  = (int) $row['money_at'];
		$amount = isset( $row['amount_minor'] ) ? (int) $row['amount_minor'] : -1;
		foreach ( $plugin->orders_index()->for_wallet( (string) $row['wallet'], $money - $late, $money ) as $order ) {
			if ( (int) $order['order_id'] === $not || ! in_array( $order['state'], array( 'open', 'partial', 'expired', 'closed' ), true ) ) {
				continue;
			}
			if ( self::remainder( $order, $round ) === $amount ) {
				return (int) $order['order_id'];
			}
		}
		return null;
	}
}
