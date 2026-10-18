<?php
/**
 * The store's rules around the literal Matcher (docs/17 §4.6): candidate shaping, the checks before it, the
 * corrections after it, rule 4a (the transaction id a customer typed), the personal-transfer tag, the advice of
 * "Needs decision", and the texts of the reasons.
 *
 * @package QershPay\WooCommerce
 */

namespace QershPay\WooCommerce;

use QershPay\WooCommerce\Admin\Reasons;
use QershPay\WooCommerce\Admin\View;
use QershPay\WooCommerce\Core\Matcher;
use QershPay\WooCommerce\Core\Money;
use QershPay\WooCommerce\Core\Payer;
use QershPay\WooCommerce\Core\Risk;
use QershPay\WooCommerce\Core\Time;
use QershPay\WooCommerce\Core\TxnId;

defined( 'ABSPATH' ) || exit;

/**
 * Every rule here is a deliberate difference from the core, on the safe side, named and tested outside parity.
 *
 * The decision logic is pure: static methods that take arrays (the transfer, the shaped orders, the claimants, the
 * competitors, the time and the configuration) and return the decision. Orders reads WordPress around them, under
 * the locks, and applies the result. Only the text methods at the end call WordPress (and Admin\Reasons, whose
 * sentences they are).
 *
 * The normalised transfer (read_transfer(), transfer_from_row()) has: transfer_id, wallet, provider, amount_minor
 * (int|null), currency, payer_key (string|null), payer_name, masked (bool: the sender number came with `*`), txn (the
 * canonical id of a claim provider, or null), txn_stored (the value stored in provider_txn_id), money_at (UTC
 * seconds: data.received_at, a missing or future one being now), time_ok (false when received_at is present but
 * unreadable), sms_at (int|null), risk, risk_reasons (array), auto_confirm_ok (bool), balance_chain, forgery (bool),
 * channel, and matcher (what Matcher::match() reads: the envelope data itself, or a copy built from the row).
 *
 * An order row (qersh_orders, typed) gets `kind` from classify(): `normal`, `expired` (cancelled by our expiry, or
 * about to be), `obstacle` (docs/17 §4.6 rule 3), `paid` (decided paid: never a candidate, rule 7 reads it).
 * For `normal` and `expired` rows, `price_minor` is the live total of the WooCommerce order.
 */
final class MatchPolicy {

	/**
	 * Wallets whose messages name the payer, so a missing number never needs a typed transaction id.
	 */
	const PAYER_PROVIDERS = array( 'vodafone_cash', 'etisalat_cash' );

	/**
	 * States of a live order: it can still take a transfer (expired = cancelled by our expiry, revivable).
	 */
	const LIVE_STATES = array( 'open', 'partial', 'expired' );

	/**
	 * Transfer outcomes that paid an order, all of it or a part.
	 */
	const PAYING_OUTCOMES = array( 'matched', 'partial', 'overpaid', 'attached' );

	/**
	 * Outcomes of a transfer linked to an order whose amount still counts in that order's paid_minor: the paying
	 * ones, and `review` left by Completion or a cancellation (order on hold, completion failed, stock short, closed,
	 * cancelled with money), which keeps order_id and paid_minor (docs/17 §4.7). Never `dismissed`: a release keeps
	 * order_id but takes the amount out. A review decided at arrival has no order_id (Orders::write_decision()).
	 */
	const COUNTED_OUTCOMES = array( 'matched', 'partial', 'overpaid', 'attached', 'review' );

	/**
	 * Live claimants of one transaction id whose payer locks a delivery takes at most (more are contested anyway).
	 */
	const MAX_CLAIM_LOCKS = 4;

	/**
	 * Builds the service. Every rule is a static method, so the plugin the service container passes is not kept.
	 *
	 * @param Plugin|null $plugin The plugin.
	 */
	public function __construct( $plugin = null ) {
		unset( $plugin );
	}

	/**
	 * The matching options of an effective configuration (docs/17 §4.2), with the plugin's defaults. Pure.
	 *
	 * @param array $config The configuration (Plugin::config()).
	 * @return array `late_window_min`, `skew_min`, `round_up_to_pound`, `txn_entry`, `order_ttl_min`.
	 */
	public static function options( array $config ): array {
		$matching = isset( $config['receiver']['matching'] ) && is_array( $config['receiver']['matching'] ) ? $config['receiver']['matching'] : array();
		$store    = isset( $config['store'] ) && is_array( $config['store'] ) ? $config['store'] : array();
		return array(
			'late_window_min'   => self::minutes( isset( $matching['late_window_min'] ) ? $matching['late_window_min'] : null, 120 ),
			'skew_min'          => self::minutes( isset( $matching['skew_min'] ) ? $matching['skew_min'] : null, 0 ),
			'round_up_to_pound' => ! array_key_exists( 'round_up_to_pound', $store ) || true === $store['round_up_to_pound'],
			'txn_entry'         => ! array_key_exists( 'txn_entry', $store ) || true === $store['txn_entry'],
			'order_ttl_min'     => self::minutes( isset( $store['order_ttl_min'] ) ? $store['order_ttl_min'] : null, 120 ),
		);
	}

	/**
	 * Reads `data` of a transfer.received into the normalised transfer. Pure.
	 *
	 * @param mixed  $data     Envelope data.
	 * @param string $wallet   Wallet id (from the URL).
	 * @param string $provider Provider of that wallet (the store's configuration).
	 * @param int    $now      UTC seconds.
	 * @return array|null Null when there is no usable transfer_id (a row cannot be stored without it).
	 */
	public static function read_transfer( $data, string $wallet, string $provider, int $now ): ?array {
		if ( ! is_array( $data ) ) {
			return null;
		}
		$id = isset( $data['transfer_id'] ) && is_string( $data['transfer_id'] ) ? $data['transfer_id'] : '';
		if ( '' === $id || strlen( $id ) > 40 ) {
			return null;
		}
		$cp       = isset( $data['counterparty'] ) && is_array( $data['counterparty'] ) ? $data['counterparty'] : null;
		$number   = null !== $cp && isset( $cp['number'] ) && is_string( $cp['number'] ) ? $cp['number'] : '';
		$name     = null !== $cp && isset( $cp['name'] ) && is_string( $cp['name'] ) ? trim( $cp['name'] ) : '';
		$raw_txn  = isset( $data['provider_txn_id'] ) && is_string( $data['provider_txn_id'] ) ? trim( $data['provider_txn_id'] ) : '';
		$canon    = '' === $raw_txn ? null : TxnId::canonical_stored( $provider, $raw_txn );
		$stored   = null !== $canon ? $canon : ( '' === $raw_txn || strlen( $raw_txn ) > 40 ? null : $raw_txn );
		$received = array_key_exists( 'received_at', $data ) ? $data['received_at'] : null;
		$got      = is_string( $received ) ? Time::iso_to_sec( $received ) : ( is_int( $received ) ? $received : null );
		$checks   = isset( $data['checks'] ) && is_array( $data['checks'] ) ? $data['checks'] : array();
		$reasons  = array_key_exists( 'risk_reasons', $data ) ? $data['risk_reasons'] : array();
		$sms      = isset( $data['sms_timestamp'] ) && is_string( $data['sms_timestamp'] ) ? Time::iso_to_sec( $data['sms_timestamp'] ) : null;
		return array(
			'transfer_id'     => $id,
			'wallet'          => $wallet,
			'provider'        => $provider,
			'amount_minor'    => self::integer( isset( $data['amount_minor'] ) ? $data['amount_minor'] : null ),
			'currency'        => isset( $data['currency'] ) && is_string( $data['currency'] ) && '' !== $data['currency'] ? substr( $data['currency'], 0, 8 ) : 'EGP',
			'payer_key'       => Payer::key_of( $cp ),
			'payer_name'      => '' === $name ? null : $name,
			'masked'          => false !== strpos( $number, '*' ),
			'txn'             => in_array( $provider, TxnId::CLAIM_PROVIDERS, true ) ? $canon : null,
			'txn_stored'      => $stored,
			'money_at'        => null !== $got && $got < $now ? $got : $now,
			'time_ok'         => null === $received || null !== $got,
			'sms_at'          => $sms,
			'risk'            => isset( $data['risk'] ) && is_string( $data['risk'] ) ? $data['risk'] : null,
			'risk_reasons'    => $reasons,
			'auto_confirm_ok' => isset( $data['auto_confirm_ok'] ) && true === $data['auto_confirm_ok'],
			'balance_chain'   => isset( $checks['balance_chain'] ) && is_string( $checks['balance_chain'] ) ? $checks['balance_chain'] : null,
			'forgery'         => self::forged( $data ),
			'channel'         => isset( $data['channel'] ) && is_string( $data['channel'] ) ? $data['channel'] : null,
			'matcher'         => $data,
		);
	}

	/**
	 * The normalised transfer of a stored row (the late path of a typed transaction id, and the advice). Pure.
	 *
	 * @param array $row Typed qersh_transfers row.
	 * @return array
	 */
	public static function transfer_from_row( array $row ): array {
		$provider = isset( $row['provider'] ) ? (string) $row['provider'] : '';
		$stored   = isset( $row['provider_txn_id'] ) ? (string) $row['provider_txn_id'] : null;
		$payer    = isset( $row['payer_key'] ) && '' !== $row['payer_key'] ? (string) $row['payer_key'] : null;
		$money    = isset( $row['money_at'] ) ? (int) $row['money_at'] : 0;
		$amount   = isset( $row['amount_minor'] ) ? (int) $row['amount_minor'] : null;
		$auto     = isset( $row['auto_confirm_ok'] ) && 1 === (int) $row['auto_confirm_ok'];
		$reasons  = isset( $row['risk_reasons'] ) && is_array( $row['risk_reasons'] ) ? $row['risk_reasons'] : array();
		$matcher  = array(
			'transfer_id'     => isset( $row['transfer_id'] ) ? (string) $row['transfer_id'] : '',
			'amount_minor'    => $amount,
			'received_at'     => $money,
			'auto_confirm_ok' => $auto,
			'provider_txn_id' => $stored,
		);
		if ( null !== $payer ) {
			$matcher['payer'] = array( 'key' => $payer );
		}
		return array(
			'transfer_id'     => $matcher['transfer_id'],
			'wallet'          => isset( $row['wallet'] ) ? (string) $row['wallet'] : '',
			'provider'        => $provider,
			'amount_minor'    => $amount,
			'currency'        => isset( $row['currency'] ) ? (string) $row['currency'] : 'EGP',
			'payer_key'       => $payer,
			'payer_name'      => isset( $row['payer_name'] ) ? $row['payer_name'] : null,
			'masked'          => isset( $row['reason'] ) && 'masked' === $row['reason'],
			'txn'             => null !== $stored && in_array( $provider, TxnId::CLAIM_PROVIDERS, true ) ? TxnId::canonical_stored( $provider, $stored ) : null,
			'txn_stored'      => $stored,
			'money_at'        => $money,
			'time_ok'         => true,
			'sms_at'          => isset( $row['sms_at'] ) ? (int) $row['sms_at'] : null,
			'risk'            => isset( $row['risk'] ) ? $row['risk'] : null,
			'risk_reasons'    => $reasons,
			'auto_confirm_ok' => $auto,
			'balance_chain'   => isset( $row['balance_chain'] ) ? $row['balance_chain'] : null,
			'forgery'         => isset( $row['flag'] ) && 'forgery' === $row['flag'],
			'channel'         => 'instapay_app' === $provider ? 'notification' : 'sms',
			'matcher'         => $matcher,
		);
	}

	/**
	 * The lock of the transfer itself (docs/17 §4.4 step 8): `payer:<key>` when it has a payer, else
	 * `txn:<provider>:<id>` for a claim provider with a well-formed id, else `trf:<transfer_id>`. Pure.
	 *
	 * @param array $t Normalised transfer, or a typed qersh_transfers row.
	 * @return string
	 */
	public static function transfer_lock( array $t ): string {
		$payer = isset( $t['payer_key'] ) && is_string( $t['payer_key'] ) ? $t['payer_key'] : '';
		if ( '' !== $payer ) {
			return 'payer:' . $payer;
		}
		$provider = isset( $t['provider'] ) ? (string) $t['provider'] : '';
		$txn      = null;
		if ( array_key_exists( 'txn', $t ) ) {
			$txn = $t['txn'];
		} elseif ( isset( $t['provider_txn_id'] ) && is_string( $t['provider_txn_id'] ) ) {
			$txn = TxnId::canonical_stored( $provider, $t['provider_txn_id'] );
		}
		if ( is_string( $txn ) && '' !== $txn && in_array( $provider, TxnId::CLAIM_PROVIDERS, true ) ) {
			return 'txn:' . $provider . ':' . $txn;
		}
		return 'trf:' . ( isset( $t['transfer_id'] ) ? (string) $t['transfer_id'] : '' );
	}

	/**
	 * Every lock a delivery takes, in the total order (Db sorts them again): the transfer's lock, then, for a txn:
	 * lock while customers may type ids, the payer locks of the live claimants of the hint read before. Pure.
	 *
	 * @param array $t         Normalised transfer.
	 * @param array $claimants Hint: qersh_orders rows whose claim_txn is the transfer's id (with `provider`).
	 * @param bool  $txn_entry store.txn_entry.
	 * @return string[]
	 */
	public static function lock_names( array $t, array $claimants, bool $txn_entry ): array {
		$names = array( self::transfer_lock( $t ) );
		if ( 0 !== strpos( $names[0], 'txn:' ) || ! $txn_entry ) {
			return $names;
		}
		$payers = array();
		foreach ( self::orange_claimants( $claimants ) as $row ) {
			if ( in_array( (string) $row['state'], self::LIVE_STATES, true ) && '' !== (string) $row['payer_key'] ) {
				$payers[ 'payer:' . $row['payer_key'] ] = true;
			}
		}
		if ( count( $payers ) > self::MAX_CLAIM_LOCKS ) {
			return $names; // So many live owners are contested whatever their orders say.
		}
		return array_merge( $names, array_keys( $payers ) );
	}

	/**
	 * A short fingerprint of claimant rows (ids, states, payers, wallets), to compare the hint with the rows read
	 * under the locks. Pure.
	 *
	 * @param array $rows Rows.
	 * @return string
	 */
	public static function claimants_print( array $rows ): string {
		$parts = array();
		foreach ( $rows as $row ) {
			$parts[] = (int) $row['order_id'] . '/' . $row['state'] . '/' . $row['payer_key'] . '/' . $row['wallet'];
		}
		sort( $parts );
		return implode( ',', $parts );
	}

	/**
	 * Rule 3: what an order row is to the matcher, from the row and the WooCommerce order read under the lock. Pure.
	 *
	 * Facts: `exists`, `method`, `flagged` (`_qersh_flag`), `status` (without `wc-`), `currency`, `expired_at`
	 * (`_qersh_expired_at`, 0 when missing).
	 *
	 * @param array $row   Typed qersh_orders row.
	 * @param array $facts Facts.
	 * @return string `normal`, `expired`, `obstacle` or `paid`.
	 */
	public static function classify( array $row, array $facts ): string {
		$state = isset( $row['state'] ) ? (string) $row['state'] : '';
		if ( 'paid' === $state ) {
			return 'paid';
		}
		if ( empty( $facts['exists'] ) || 'qersh' !== ( isset( $facts['method'] ) ? $facts['method'] : '' ) || ! empty( $facts['flagged'] ) ) {
			return 'obstacle';
		}
		if ( 'closed' === $state || 'review' === $state ) {
			return 'obstacle';
		}
		$status   = isset( $facts['status'] ) ? (string) $facts['status'] : '';
		$currency = isset( $facts['currency'] ) ? (string) $facts['currency'] : '';
		if ( 'EGP' !== $currency ) {
			return 'obstacle';
		}
		if ( 'pending' === $status ) {
			if ( 'open' === $state || 'partial' === $state ) {
				return 'normal';
			}
			if ( 'expired' === $state ) {
				return 'expired'; // Our expiry wrote the row and the cancellation is still to come.
			}
		}
		if ( 'cancelled' === $status && 'expired' === $state && ! empty( $facts['expired_at'] ) ) {
			return 'expired';
		}
		return 'obstacle';
	}

	/**
	 * Whether an amount pays exactly what remains, the remainder rounded up to the pound when the store allows it
	 * (a *9# transfer carries no piasters). Pure.
	 *
	 * @param int      $remainder Remainder in piasters.
	 * @param int|null $amount    Amount, or null when the message has none.
	 * @param bool     $round     store.round_up_to_pound.
	 * @return bool
	 */
	public static function fits( int $remainder, ?int $amount, bool $round ): bool {
		if ( null === $amount ) {
			return false;
		}
		if ( $remainder === $amount ) {
			return true;
		}
		return $round && $amount > 0 && 0 === $amount % 100 && 0 !== $remainder % 100 && Money::round_up_pound( $remainder ) === $amount;
	}

	/**
	 * When the operator sent the message, for gate 4: sms_at kept inside [money − late window, money], or the money
	 * time without one. So a merchant's phone that was off does not make an honest customer look like a guesser. Pure.
	 *
	 * @param int|null $sms_at   sms_timestamp in seconds.
	 * @param int      $money_at Money time.
	 * @param int      $late     Late window in seconds.
	 * @return int
	 */
	public static function operator_at( ?int $sms_at, int $money_at, int $late ): int {
		if ( null === $sms_at ) {
			return $money_at;
		}
		return max( $money_at - $late, min( $money_at, $sms_at ) );
	}

	/**
	 * The one reason of a transfer the phone did not confirm on its own (auto_confirm_ok false), by the reasons
	 * table of docs/17 §4.6. The notification channel is decided here, since the row does not keep it. Pure.
	 *
	 * @param array $t Normalised transfer.
	 * @return string
	 */
	public static function risk_reason( array $t ): string {
		$reasons = array();
		foreach ( is_array( $t['risk_reasons'] ) ? $t['risk_reasons'] : array() as $reason ) {
			if ( is_string( $reason ) ) {
				$reasons[] = $reason;
			}
		}
		$has = static function ( array $codes ) use ( $reasons ) {
			foreach ( $codes as $code ) {
				if ( in_array( $code, $reasons, true ) ) {
					return $code;
				}
			}
			return null;
		};
		if ( ! empty( $t['forgery'] ) ) {
			return 'forgery';
		}
		$baseline = $has( array( 'no_baseline', 'balance_stale' ) );
		if ( null !== $baseline ) {
			return $baseline;
		}
		if ( null !== $has( array( 'amount_over_limit' ) ) ) {
			return 'amount_over_limit';
		}
		$notification = 'notification' === $t['channel'] || 'instapay_app' === $t['provider'];
		if ( $notification && null !== $has( array( 'notification_only', 'balance_unavailable' ) ) ) {
			return 'notification_only';
		}
		if ( null !== $has( array( 'balance_unavailable' ) ) ) {
			return 'balance_unavailable';
		}
		$template = $has( array( 'partial_template', 'unverified_template' ) );
		if ( null !== $template ) {
			return $template;
		}
		return empty( $reasons ) ? 'not_confirmed' : $reasons[0];
	}

	/**
	 * The decision on a transfer.received (docs/17 §4.6): the rules before the Matcher, the literal Matcher, then the
	 * rules after it. Pure.
	 *
	 * Context: `now`; `config` (options()); `duplicates` (rows of Transfers::find_by_txn( provider, txn_stored ));
	 * `payer_rows` (rows of the payer from at − 2 × late to at + skew, with `kind`); `wallet_rows` (rows of the
	 * wallet in the same span, for a transfer without a payer); `claimants` (rows whose claim_txn is the transfer's
	 * canonical id, with `provider` of their wallet and, for live ones, `kind`).
	 *
	 * @param array $t   Normalised transfer.
	 * @param array $ctx Context.
	 * @return array See result().
	 */
	public static function decide( array $t, array $ctx ): array {
		$now  = (int) $ctx['now'];
		$cfg  = $ctx['config'];
		$flag = ! empty( $t['forgery'] ) ? 'forgery' : null;

		if ( empty( $t['time_ok'] ) ) {
			return self::review( 'bad_time', array(), $flag );
		}
		$unknown = Risk::unknown_reasons( $t['risk_reasons'] );
		if ( ! empty( $unknown ) ) {
			return self::review( 'unknown_risk', array( 'values' => implode( ', ', $unknown ) ), $flag );
		}
		$duplicate = self::duplicate( $t, isset( $ctx['duplicates'] ) ? $ctx['duplicates'] : array() );
		if ( null !== $duplicate ) {
			$args = array(
				'T' => (string) $duplicate['transfer_id'],
				'N' => isset( $duplicate['order_id'] ) && in_array( $duplicate['outcome'], self::PAYING_OUTCOMES, true ) ? (int) $duplicate['order_id'] : null,
			);
			return self::review( 'duplicate_txn', $args, $flag );
		}
		$out = null !== $t['payer_key'] && '' !== $t['payer_key']
			? self::decide_payer( $t, $ctx )
			: self::decide_payerless( $t, $ctx );
		if ( null === $flag ) {
			return $out;
		}
		if ( null !== $out['order_id'] && in_array( $out['outcome'], array( 'matched', 'partial', 'overpaid' ), true ) ) {
			// A message suspected to be forged pays no order, whatever auto_confirm_ok says: neither a payer match nor
			// a typed transaction id passes the forgery flag (docs/17 §4.6 rule 4a, §4.4 step 9). Its order is pinned.
			return self::review( 'forgery', array(), $flag, array( (int) $out['order_id'] ) );
		}
		$out['flag'] = $flag; // Reviews keep their reason; a personal transfer stays personal whatever its risk (rule 5).
		return $out;
	}

	/**
	 * Rule 4a (docs/17 §4.6): the transaction id a customer typed, for a transfer without a payer. Counts the
	 * claimants, then runs gates 1 to 6 on the one live claimant X. Pure.
	 *
	 * Context: as decide(); the claimants carry `kind` (rule 3 under the payer lock: gate 2), `provider` of their
	 * wallet, and `price_minor` live for normal and expired ones. With `$advice` (the suggestions of the admin
	 * screens) auto_confirm_ok is ignored.
	 *
	 * @param array $t      Normalised transfer.
	 * @param array $ctx    Context.
	 * @param bool  $advice True for the advice (gates as advice only).
	 * @return array See result(); outcome `none` when nobody typed this id.
	 */
	public static function claim_decision( array $t, array $ctx, bool $advice = false ): array {
		$now    = (int) $ctx['now'];
		$cfg    = $ctx['config'];
		$late   = (int) $cfg['late_window_min'] * 60;
		$round  = ! empty( $cfg['round_up_to_pound'] );
		$at     = (int) $t['money_at'];
		$amount = $t['amount_minor'];
		$txn    = (string) $t['txn'];

		$live = array();
		$dead = array();
		foreach ( self::orange_claimants( isset( $ctx['claimants'] ) ? $ctx['claimants'] : array() ) as $row ) {
			if ( in_array( (string) $row['state'], self::LIVE_STATES, true ) ) {
				$live[] = $row;
			} else {
				$dead[] = $row;
			}
		}
		if ( empty( $live ) && empty( $dead ) ) {
			return self::result( 'none' );
		}
		if ( empty( $live ) ) {
			return self::review( 'closed_order', array( 'N' => (int) $dead[0]['order_id'] ), null, self::ids( $dead ) );
		}
		if ( count( $live ) > 1 ) {
			return self::review(
				'txn_contested',
				array(
					'N'    => (int) $live[0]['order_id'],
					'M'    => (int) $live[1]['order_id'],
					'form' => 'two',
				),
				null,
				self::ids( $live )
			);
		}
		$x  = $live[0];
		$xi = (int) $x['order_id'];
		foreach ( $dead as $row ) {
			if ( (string) $row['payer_key'] !== (string) $x['payer_key'] ) {
				return self::review(
					'txn_contested',
					array(
						'N'    => $xi,
						'M'    => (int) $row['order_id'],
						'form' => 'two',
					),
					null,
					array_merge( array( $xi ), self::ids( $dead ) )
				);
			}
		}

		// Gate 1: the wallet X chose is the wallet the money reached.
		if ( (string) $x['wallet'] !== (string) $t['wallet'] ) {
			return self::review(
				'txn_wallet',
				array(
					'N' => $xi,
					'W' => (string) $x['wallet'],
				),
				null,
				array( $xi )
			);
		}
		// Gate 2: X shaped by rule 3 under its payer lock.
		$kind = isset( $x['kind'] ) ? (string) $x['kind'] : '';
		if ( 'normal' !== $kind && 'expired' !== $kind ) {
			return self::review( 'closed_order', array( 'N' => $xi ), null, array( $xi ) );
		}
		// Gate 3: X existed before the money (the owner's decision).
		if ( (int) $x['created_at'] > $at ) {
			return self::review(
				'txn_window',
				array(
					'N'    => $xi,
					'form' => 'window',
				),
				null,
				array( $xi )
			);
		}
		// Gate 4: the id was typed after the operator sent the message.
		$claim_at = isset( $x['claim_at'] ) ? (int) $x['claim_at'] : null;
		if ( null === $claim_at || $claim_at < self::operator_at( $t['sms_at'], $at, $late ) ) {
			return self::review(
				'txn_window',
				array(
					'N'    => $xi,
					'form' => 'early',
				),
				null,
				array( $xi )
			);
		}
		// Gate 5: the literal Matcher, on a copy with X's payer key and X alone, and the exact remainder.
		$price     = (int) $x['price_minor'];
		$paid      = (int) $x['paid_minor'];
		$remainder = $price - $paid;
		$z         = $round ? Money::round_up_pound( $remainder ) : $remainder;
		if ( ! self::fits( $remainder, $amount, $round ) ) {
			return self::review(
				'txn_amount',
				array(
					'N' => $xi,
					'Z' => $z,
				),
				null,
				array( $xi )
			);
		}
		$passed = $remainder === $amount ? $price : $paid + (int) $amount;
		$copy   = is_array( $t['matcher'] ) ? $t['matcher'] : array();
		unset( $copy['counterparty'] );
		$copy['payer'] = array( 'key' => (string) $x['payer_key'] );
		if ( $advice ) {
			$copy['auto_confirm_ok'] = true;
		}
		$candidate = array(
			'order_number' => (string) $xi,
			'price_minor'  => $passed,
			'paid_minor'   => $paid,
			'payer_key'    => (string) $x['payer_key'],
			'created_at'   => (int) $x['created_at'],
			'expires_at'   => (int) $x['expires_at'],
			'status'       => $paid > 0 ? 'partial' : 'open',
		);

		$m = Matcher::match( $copy, array( $candidate ), $now, self::matcher_options( $cfg ) );
		if ( 'matched' !== $m['status'] || (string) $xi !== (string) $m['order_number'] ) {
			$code = in_array( $m['status'], array( 'partial', 'overpaid' ), true ) ? 'txn_amount' : 'txn_window';
			$args = 'txn_amount' === $code ? array(
				'N' => $xi,
				'Z' => $z,
			) : array(
				'N'    => $xi,
				'form' => 'window',
			);
			return self::review( $code, $args, null, array( $xi ) );
		}
		// Gate 6: no competitor on the same wallet.
		foreach ( isset( $ctx['wallet_rows'] ) ? $ctx['wallet_rows'] : array() as $y ) {
			if ( (int) $y['order_id'] === $xi || (string) $y['wallet'] !== (string) $t['wallet'] ) {
				continue;
			}
			$y_claim = isset( $y['claim_txn'] ) ? (string) $y['claim_txn'] : '';
			if ( '' !== $y_claim ) {
				continue; // The same id: counted above as a claimant; another well-formed id: it does not compete.
			}
			if ( ! self::fits( (int) $y['price_minor'] - (int) $y['paid_minor'], $amount, $round ) ) {
				continue;
			}
			if ( (int) $y['created_at'] > $at || (int) $y['expires_at'] < $at - $late ) {
				continue;
			}
			$state = (string) $y['state'];
			if ( in_array( $state, self::LIVE_STATES, true ) || 'closed' === $state ) {
				return self::review(
					'txn_contested',
					array(
						'N'    => $xi,
						'M'    => (int) $y['order_id'],
						'form' => 'competitor',
					),
					null,
					array( $xi, (int) $y['order_id'] )
				);
			}
		}

		$out             = self::result( 'matched' );
		$out['order_id'] = $xi;
		$out['paid_add'] = (int) $amount;
		$out['state']    = 'paid';
		$out['price']    = $price;
		$out['by_claim'] = true;
		$out['late']     = isset( $m['note'] ) && 'paid after expiry' === $m['note'];
		$out['rounded']  = $passed - $price;
		$out['revive']   = 'expired' === $kind;
		$out['reply']    = array(
			'status' => 'matched',
			'note'   => 'by_claim',
			'order'  => $xi,
		);
		if ( $out['rounded'] > 0 ) {
			$out['hints'][] = array( 'rounded', array( 'X' => $out['rounded'] ) );
		}
		if ( $out['late'] ) {
			$out['hints'][] = array( 'paid_late', array() );
		}
		return $out;
	}

	/**
	 * The suggestions of "Needs decision", computed when read (docs/17 §4.6), so they include an id typed after the
	 * transfer arrived. Pure.
	 *
	 * - The owners of the transaction id (claim_txn = the transfer's canonical id, on an Orange wallet) come first,
	 *   pinned. Gates 1 to 6 run as advice only, ignoring auto_confirm_ok: `ready` (the badge and the ready button)
	 *   only when one owner passes them all; otherwise each owner shows its reason. A dead order is never preselected.
	 * - Then the payer's live orders (or, without a payer, the live orders of the wallet created before the money
	 *   whose remainder fits the amount), those that fit the amount first.
	 *
	 * Context: as decide(), with `payer_rows` and `wallet_rows` shaped (`kind`) where possible.
	 *
	 * @param array $t   Normalised transfer.
	 * @param array $ctx Context.
	 * @return array[] Each `[ 'order_id' => int, 'source' => 'claim'|'payer'|'wallet', 'ready' => bool,
	 *                 'preselect' => bool, 'fits' => bool, 'after' => bool (created after the money), 'live' => bool,
	 *                 'reason' => ?string, 'args' => array ]`.
	 */
	public static function advice( array $t, array $ctx ): array {
		$cfg    = $ctx['config'];
		$round  = ! empty( $cfg['round_up_to_pound'] );
		$at     = (int) $t['money_at'];
		$amount = $t['amount_minor'];
		$items  = array();
		$seen   = array();
		$add    = static function ( array $item ) use ( &$items, &$seen ) {
			if ( isset( $seen[ $item['order_id'] ] ) ) {
				return;
			}
			$seen[ $item['order_id'] ] = true;
			$items[]                   = $item;
		};

		if ( null !== $t['txn'] ) {
			$live = array();
			$dead = array();
			foreach ( self::orange_claimants( isset( $ctx['claimants'] ) ? $ctx['claimants'] : array() ) as $row ) {
				if ( in_array( (string) $row['state'], self::LIVE_STATES, true ) ) {
					$live[] = $row;
				} else {
					$dead[] = $row;
				}
			}
			if ( 1 === count( $live ) ) {
				$verdict = self::claim_decision( $t, $ctx, true );
				$ready   = 'matched' === $verdict['outcome'];
				$add(
					array(
						'order_id'  => (int) $live[0]['order_id'],
						'source'    => 'claim',
						'ready'     => $ready,
						'preselect' => $ready,
						'fits'      => self::fits( (int) $live[0]['price_minor'] - (int) $live[0]['paid_minor'], $amount, $round ),
						'after'     => (int) $live[0]['created_at'] > $at,
						'live'      => true,
						'reason'    => $ready ? null : $verdict['reason'],
						'args'      => $ready ? array() : $verdict['args'],
					)
				);
			} else {
				foreach ( $live as $i => $row ) {
					$other = isset( $live[ 0 === $i ? 1 : 0 ] ) ? (int) $live[ 0 === $i ? 1 : 0 ]['order_id'] : null;
					$add(
						array(
							'order_id'  => (int) $row['order_id'],
							'source'    => 'claim',
							'ready'     => false,
							'preselect' => false,
							'fits'      => self::fits( (int) $row['price_minor'] - (int) $row['paid_minor'], $amount, $round ),
							'after'     => (int) $row['created_at'] > $at,
							'live'      => true,
							'reason'    => 'txn_contested',
							'args'      => array(
								'N'    => (int) $row['order_id'],
								'M'    => $other,
								'form' => 'two',
							),
						)
					);
				}
			}
			foreach ( $dead as $row ) {
				$add(
					array(
						'order_id'  => (int) $row['order_id'],
						'source'    => 'claim',
						'ready'     => false,
						'preselect' => false,
						'fits'      => false,
						'after'     => (int) $row['created_at'] > $at,
						'live'      => false,
						'reason'    => 'closed_order',
						'args'      => array( 'N' => (int) $row['order_id'] ),
					)
				);
			}
		}

		$payer   = null !== $t['payer_key'] && '' !== $t['payer_key'];
		$rows    = $payer ? ( isset( $ctx['payer_rows'] ) ? $ctx['payer_rows'] : array() ) : ( isset( $ctx['wallet_rows'] ) ? $ctx['wallet_rows'] : array() );
		$fitting = array();
		$others  = array();
		foreach ( $rows as $row ) {
			$kind = isset( $row['kind'] ) ? (string) $row['kind'] : ( in_array( (string) $row['state'], self::LIVE_STATES, true ) ? 'normal' : 'obstacle' );
			if ( 'normal' !== $kind && 'expired' !== $kind ) {
				continue;
			}
			if ( $payer && (string) $row['payer_key'] !== (string) $t['payer_key'] ) {
				continue;
			}
			if ( ! $payer && ( (string) $row['wallet'] !== (string) $t['wallet'] || (int) $row['created_at'] > $at ) ) {
				continue;
			}
			$fits = self::fits( (int) $row['price_minor'] - (int) $row['paid_minor'], $amount, $round );
			if ( ! $payer && ! $fits ) {
				continue;
			}
			$item = array(
				'order_id'  => (int) $row['order_id'],
				'source'    => $payer ? 'payer' : 'wallet',
				'ready'     => false,
				'preselect' => false,
				'fits'      => $fits,
				'after'     => (int) $row['created_at'] > $at,
				'live'      => true,
				'reason'    => null,
				'args'      => array(),
			);
			if ( $fits ) {
				$fitting[] = $item;
			} else {
				$others[] = $item;
			}
		}
		$has_ready = false;
		foreach ( $items as $item ) {
			$has_ready = $has_ready || $item['ready'];
		}
		if ( ! $has_ready && 1 === count( $fitting ) && ! isset( $seen[ $fitting[0]['order_id'] ] ) && ! $fitting[0]['after'] ) {
			$fitting[0]['preselect'] = true;
		}
		foreach ( array_merge( $fitting, $others ) as $item ) {
			$add( $item );
		}
		return $items;
	}

	/**
	 * Rule 2: an earlier transfer with the same provider and stored transaction id, and a verdict. Pure.
	 *
	 * @param array $t    Normalised transfer.
	 * @param array $rows Rows of Transfers::find_by_txn().
	 * @return array|null
	 */
	public static function duplicate( array $t, array $rows ): ?array {
		if ( null === $t['txn_stored'] || '' === $t['txn_stored'] ) {
			return null;
		}
		foreach ( $rows as $row ) {
			if ( (string) $row['transfer_id'] === (string) $t['transfer_id'] ) {
				continue;
			}
			if ( (string) $row['provider'] !== (string) $t['provider'] || (string) $row['provider_txn_id'] !== (string) $t['txn_stored'] ) {
				continue;
			}
			if ( isset( $row['outcome'] ) ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * The rows of a list whose life overlaps the matcher's window: created at or before at + skew and expiring at
	 * or after at − late (the unreadable ones are counted by the Matcher without its own window check). Pure.
	 *
	 * @param array $rows Rows.
	 * @param int   $at   Money time.
	 * @param int   $late Seconds.
	 * @param int   $skew Seconds.
	 * @return array
	 */
	public static function in_window( array $rows, int $at, int $late, int $skew ): array {
		$out = array();
		foreach ( $rows as $row ) {
			if ( (int) $row['created_at'] <= $at + $skew && (int) $row['expires_at'] >= $at - $late ) {
				$out[] = $row;
			}
		}
		return $out;
	}

	/**
	 * The candidates of the Matcher from shaped rows (rule 3), with the rounding to the pound: when no candidate's
	 * remainder equals the amount literally, a candidate whose remainder rounds up to it is passed with a price that
	 * makes the remainder equal (orders of 139.50 and 140 and a transfer of 140 go to the 140 one). Pure.
	 *
	 * @param array    $rows   Shaped rows in the window (normal, expired, obstacle).
	 * @param int|null $amount Amount.
	 * @param bool     $round  store.round_up_to_pound.
	 * @return array `[ 'list' => array, 'rounded' => array<int, int> ]` (order id => piasters added).
	 */
	public static function candidates( array $rows, ?int $amount, bool $round ): array {
		$literal = false;
		foreach ( $rows as $row ) {
			if ( in_array( $row['kind'], array( 'normal', 'expired' ), true ) && null !== $amount && (int) $row['price_minor'] - (int) $row['paid_minor'] === $amount ) {
				$literal = true;
				break;
			}
		}
		$list    = array();
		$rounded = array();
		foreach ( $rows as $row ) {
			$id   = (int) $row['order_id'];
			$base = array(
				'order_number' => (string) $id,
				'payer_key'    => (string) $row['payer_key'],
				'created_at'   => (int) $row['created_at'],
				'expires_at'   => (int) $row['expires_at'],
				'status'       => 'open',
			);
			if ( 'obstacle' === $row['kind'] ) {
				$base['price_minor'] = null;
				$base['paid_minor']  = 0;
				$list[]              = $base;
				continue;
			}
			$price = (int) $row['price_minor'];
			$paid  = 'expired' === $row['kind'] ? 0 : (int) $row['paid_minor'];
			if ( ! $literal && null !== $amount && $price - $paid !== $amount && self::fits( $price - $paid, $amount, $round ) ) {
				$rounded[ $id ] = $amount - ( $price - $paid );
				$price          = $paid + $amount;
			}
			$base['price_minor'] = $price;
			$base['paid_minor']  = $paid;
			$base['status']      = $paid > 0 ? 'partial' : 'open';
			$list[]              = $base;
		}
		return array(
			'list'    => $list,
			'rounded' => $rounded,
		);
	}

	/**
	 * Rule 5 for a transfer without a payer: whether any order could take it at arrival — a live order on the same
	 * wallet created before the money, open then, whose remainder (rounded) equals the amount; or, while customers
	 * may type ids, someone who typed this one. Pure.
	 *
	 * @param array $t   Normalised transfer.
	 * @param array $ctx Context of decide().
	 * @return array Order ids that could take it (empty: personal), the claimants first.
	 */
	public static function wallet_takers( array $t, array $ctx ): array {
		$cfg    = $ctx['config'];
		$late   = (int) $cfg['late_window_min'] * 60;
		$round  = ! empty( $cfg['round_up_to_pound'] );
		$at     = (int) $t['money_at'];
		$amount = $t['amount_minor'];
		$ids    = array();
		if ( ! empty( $cfg['txn_entry'] ) && null !== $t['txn'] ) {
			foreach ( self::orange_claimants( isset( $ctx['claimants'] ) ? $ctx['claimants'] : array() ) as $row ) {
				$ids[] = (int) $row['order_id'];
			}
		}
		foreach ( isset( $ctx['wallet_rows'] ) ? $ctx['wallet_rows'] : array() as $row ) {
			if ( (string) $row['wallet'] !== (string) $t['wallet'] || ! in_array( (string) $row['state'], self::LIVE_STATES, true ) ) {
				continue;
			}
			if ( (int) $row['created_at'] > $at || (int) $row['expires_at'] < $at - $late ) {
				continue;
			}
			if ( null !== $amount && ! self::fits( (int) $row['price_minor'] - (int) $row['paid_minor'], $amount, $round ) ) {
				continue;
			}
			$ids[] = (int) $row['order_id'];
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * The transfer has a payer key: rule 3, the Matcher, then rules 5 to 8 and the reasons of a review.
	 *
	 * @param array $t   Normalised transfer.
	 * @param array $ctx Context.
	 * @return array
	 */
	private static function decide_payer( array $t, array $ctx ): array {
		$now    = (int) $ctx['now'];
		$cfg    = $ctx['config'];
		$late   = (int) $cfg['late_window_min'] * 60;
		$skew   = (int) $cfg['skew_min'] * 60;
		$round  = ! empty( $cfg['round_up_to_pound'] );
		$at     = (int) $t['money_at'];
		$amount = $t['amount_minor'];

		$rows = array();
		$paid = array();
		foreach ( isset( $ctx['payer_rows'] ) ? $ctx['payer_rows'] : array() as $row ) {
			if ( (string) $row['payer_key'] !== (string) $t['payer_key'] ) {
				continue;
			}
			if ( 'paid' === $row['kind'] ) {
				$paid[] = $row;
			} else {
				$rows[] = $row;
			}
		}
		$rows      = self::in_window( $rows, $at, $late, $skew );
		$built     = self::candidates( $rows, $amount, $round );
		$m         = Matcher::match( $t['matcher'], $built['list'], $now, self::matcher_options( $cfg ) );
		$by_id     = array();
		$obstacles = array();
		$live      = array();
		foreach ( $rows as $row ) {
			$by_id[ (int) $row['order_id'] ] = $row;
			if ( 'obstacle' === $row['kind'] ) {
				$obstacles[] = $row;
			} else {
				$live[] = $row;
			}
		}
		$second = self::second_payment( $paid, $at, $late );

		if ( in_array( $m['status'], array( 'matched', 'partial', 'overpaid' ), true ) ) {
			$id     = (int) $m['order_number'];
			$chosen = $by_id[ $id ];
			$passed = null;
			foreach ( $built['list'] as $c ) {
				if ( (string) $id === $c['order_number'] ) {
					$passed = $c;
				}
			}
			if ( 'partial' === $m['status'] && 'expired' === $chosen['kind'] ) {
				return self::review( 'partial_overdue', array( 'N' => $id ), null, array( $id ) ); // Rule 8: no revival.
			}
			$status = $m['status'];
			$late_p = isset( $m['note'] ) && 'paid after expiry' === $m['note'];
			$left   = (int) $passed['price_minor'] - (int) $passed['paid_minor'];
			if ( 'matched' === $status && $late_p && (int) $amount > $left ) {
				$status = 'overpaid'; // Rule 6: the core says matched before it looks at the excess.
			}
			$out             = self::result( $status );
			$out['order_id'] = $id;
			$out['paid_add'] = (int) $amount;
			$out['state']    = 'partial' === $status ? 'partial' : 'paid';
			$out['price']    = (int) $chosen['price_minor'];
			$out['late']     = $late_p;
			$out['rounded']  = isset( $built['rounded'][ $id ] ) ? (int) $built['rounded'][ $id ] : 0;
			$out['over']     = 'overpaid' === $status ? (int) $amount - $left : 0;
			$out['revive']   = 'expired' === $chosen['kind'] && 'partial' !== $status;
			$out['reply']    = array(
				'status' => $status,
				'note'   => $late_p ? 'paid_late' : null,
				'order'  => $id,
			);
			if ( $out['rounded'] > 0 ) {
				$out['hints'][] = array( 'rounded', array( 'X' => $out['rounded'] ) );
			}
			if ( $late_p ) {
				$out['hints'][] = array( 'paid_late', array() );
			}
			if ( 'overpaid' === $status ) {
				$out['hints'][] = array( 'overpaid', array( 'X' => $out['over'] ) );
			}
			return $out;
		}

		$nothing = empty( $rows ) && null === $second;
		if ( 'unmatched' === $m['status'] ) {
			if ( null !== $second ) {
				$out          = self::result( 'unmatched' ); // Rule 7: the same reply, never personal.
				$out['hints'] = array( array( 'second_payment', array( 'N' => (int) $second['order_id'] ) ) );
				$out['reply'] = array( 'status' => 'unmatched' );
				return $out;
			}
			return self::personal();
		}

		// The Matcher says review.
		if ( empty( $t['auto_confirm_ok'] ) ) {
			if ( $nothing ) {
				return self::personal();
			}
			return self::review( self::risk_reason( $t ), array(), null, self::suggest_ids( $live, $amount, $round ) );
		}
		if ( null === $amount || $amount < 0 ) {
			return $nothing ? self::personal() : self::review( 'no_amount', array(), null, self::ids( $live ) );
		}
		if ( ! empty( $obstacles ) ) {
			$newest = $obstacles[0];
			foreach ( $obstacles as $row ) {
				if ( (int) $row['created_at'] >= (int) $newest['created_at'] ) {
					$newest = $row;
				}
			}
			$args = array(
				'N'     => (int) $newest['order_id'],
				'payer' => true,
			);
			return self::review( 'closed_order', $args, null, array( (int) $newest['order_id'] ) );
		}
		if ( count( $live ) > 1 ) {
			return self::review( 'several_orders', array(), null, self::ids( $live ) );
		}
		if ( $nothing ) {
			return self::personal();
		}
		$out = self::review( 'late_delivery', array(), null, self::ids( $live ) );
		if ( null !== $second ) {
			$out['hints'] = array( array( 'second_payment', array( 'N' => (int) $second['order_id'] ) ) );
		}
		return $out;
	}

	/**
	 * The transfer has no payer key: the Matcher (which then never reads the candidates), rule 4a, rule 5, rule 4.
	 *
	 * @param array $t   Normalised transfer.
	 * @param array $ctx Context.
	 * @return array
	 */
	private static function decide_payerless( array $t, array $ctx ): array {
		$now = (int) $ctx['now'];
		$cfg = $ctx['config'];
		$m   = Matcher::match( $t['matcher'], array(), $now, self::matcher_options( $cfg ) );

		if ( 'review' === $m['status'] ) {
			$code   = empty( $t['auto_confirm_ok'] ) ? self::risk_reason( $t ) : 'no_amount';
			$takers = self::wallet_takers( $t, $ctx );
			if ( empty( $takers ) ) {
				return self::personal();
			}
			return self::review( $code, array(), null, $takers );
		}
		if ( 'unmatched' !== $m['status'] ) {
			return self::review( 'no_payer', array(), null ); // The Matcher never matches without a payer.
		}
		$claim_rule = ! empty( $cfg['txn_entry'] ) && null !== $t['txn'] && in_array( $t['provider'], TxnId::CLAIM_PROVIDERS, true );
		if ( $claim_rule ) {
			$claim = self::claim_decision( $t, $ctx );
			if ( 'none' !== $claim['outcome'] ) {
				return $claim;
			}
		}
		$takers = self::wallet_takers( $t, $ctx );
		if ( empty( $takers ) ) {
			return self::personal();
		}
		$code         = ! empty( $t['masked'] ) ? 'masked' : 'no_payer';
		$orange       = in_array( $t['provider'], TxnId::CLAIM_PROVIDERS, true ) && ! empty( $cfg['txn_entry'] );
		$out          = self::review( $code, 'no_payer' === $code ? array( 'orange' => $orange ) : array(), null, $takers );
		$out['reply'] = array(
			'status' => 'review',
			'note'   => 'masked' === $code ? 'no_payer' : ( $orange ? 'no_payer_orange' : 'no_payer' ),
		);
		return $out;
	}

	/**
	 * Rule 7: the payer's order decided paid within the late window before the money (the newest).
	 *
	 * @param array $paid Rows of kind `paid`.
	 * @param int   $at   Money time.
	 * @param int   $late Seconds.
	 * @return array|null
	 */
	private static function second_payment( array $paid, int $at, int $late ): ?array {
		$found = null;
		foreach ( $paid as $row ) {
			$paid_at = isset( $row['paid_at'] ) ? (int) $row['paid_at'] : null;
			if ( null === $paid_at || $paid_at < $at - $late ) {
				continue;
			}
			if ( null === $found || $paid_at > (int) $found['paid_at'] ) {
				$found = $row;
			}
		}
		return $found;
	}

	/**
	 * The candidates to suggest first for a transfer not confirmed on its own: the one whose remainder fits the
	 * amount, else all of them.
	 *
	 * @param array    $rows   Live shaped rows.
	 * @param int|null $amount Amount.
	 * @param bool     $round  Rounding.
	 * @return int[]
	 */
	private static function suggest_ids( array $rows, ?int $amount, bool $round ): array {
		$fit = array();
		foreach ( $rows as $row ) {
			if ( self::fits( (int) $row['price_minor'] - (int) $row['paid_minor'], $amount, $round ) ) {
				$fit[] = (int) $row['order_id'];
			}
		}
		return empty( $fit ) ? self::ids( $rows ) : $fit;
	}

	/**
	 * The claimants on an Orange wallet (a claim provider), in their order.
	 *
	 * @param array $rows Rows with `provider`.
	 * @return array
	 */
	private static function orange_claimants( array $rows ): array {
		$out = array();
		foreach ( $rows as $row ) {
			if ( isset( $row['provider'] ) && in_array( $row['provider'], TxnId::CLAIM_PROVIDERS, true ) ) {
				$out[] = $row;
			}
		}
		return $out;
	}

	/**
	 * Order ids of rows.
	 *
	 * @param array $rows Rows.
	 * @return int[]
	 */
	private static function ids( array $rows ): array {
		$out = array();
		foreach ( $rows as $row ) {
			$out[] = (int) $row['order_id'];
		}
		return $out;
	}

	/**
	 * The options of Matcher::match(): the configured windows, literally (skew 0 is the owner's decision).
	 *
	 * @param array $cfg options().
	 * @return array
	 */
	private static function matcher_options( array $cfg ): array {
		return array(
			'late_window_min' => (int) $cfg['late_window_min'],
			'skew_min'        => (int) $cfg['skew_min'],
		);
	}

	/**
	 * A decision without an order.
	 *
	 * @param string $outcome Outcome (`matched`, `partial`, `overpaid`, `unmatched`, `review` or `none`).
	 * @return array Keys: outcome, reason, args, order_id, paid_add, state, price, pinned, hints, by_claim, late,
	 *               rounded, over, revive, flag, personal, reply (`status`, `note` code, `order`).
	 */
	private static function result( string $outcome ): array {
		return array(
			'outcome'  => $outcome,
			'reason'   => null,
			'args'     => array(),
			'order_id' => null,
			'paid_add' => 0,
			'state'    => null,
			'price'    => null,
			'pinned'   => array(),
			'hints'    => array(),
			'by_claim' => false,
			'late'     => false,
			'rounded'  => 0,
			'over'     => 0,
			'revive'   => false,
			'flag'     => null,
			'personal' => false,
			'reply'    => array( 'status' => $outcome ),
		);
	}

	/**
	 * A review with its reason.
	 *
	 * @param string      $reason Reason code.
	 * @param array       $args   Arguments of the reason text.
	 * @param string|null $flag   Flag.
	 * @param int[]       $pinned Orders to suggest first.
	 * @return array
	 */
	private static function review( string $reason, array $args, ?string $flag, array $pinned = array() ): array {
		$out           = self::result( 'review' );
		$out['reason'] = $reason;
		$out['args']   = $args;
		$out['flag']   = $flag;
		$out['pinned'] = array_values( array_unique( array_map( 'intval', $pinned ) ) );
		$out['reply']  = array(
			'status' => 'review',
			'note'   => $reason,
			'args'   => $args,
		);
		return $out;
	}

	/**
	 * Rule 5: a personal transfer (no order in the store could take it).
	 *
	 * @return array
	 */
	private static function personal(): array {
		$out             = self::result( 'unmatched' );
		$out['reason']   = 'personal';
		$out['personal'] = true;
		$out['reply']    = array(
			'status' => 'unmatched',
			'note'   => 'personal',
		);
		return $out;
	}

	/**
	 * Whether data carries the forgery verdict (isSuspectedForgery, or `suspected_forgery: true`).
	 *
	 * @param array $data Data.
	 * @return bool
	 */
	private static function forged( array $data ): bool {
		$judge = Matcher::judge_update( $data );
		return ! empty( $judge['stop_order'] );
	}

	/**
	 * A window in minutes: an integer ≥ 0, else the default.
	 *
	 * @param mixed $value    Value.
	 * @param int   $fallback Default.
	 * @return int
	 */
	private static function minutes( $value, int $fallback ): int {
		if ( is_int( $value ) && $value >= 0 ) {
			return $value;
		}
		if ( is_float( $value ) && is_finite( $value ) && $value >= 0 && floor( $value ) === $value ) {
			return (int) $value;
		}
		return $fallback;
	}

	/**
	 * An integer as Number.isInteger() reads it, or null.
	 *
	 * @param mixed $value Value.
	 * @return int|null
	 */
	private static function integer( $value ): ?int {
		if ( is_int( $value ) ) {
			return $value;
		}
		if ( is_float( $value ) && is_finite( $value ) && floor( $value ) === $value && abs( $value ) <= 9007199254740991.0 && abs( $value ) < (float) PHP_INT_MAX ) {
			return (int) $value;
		}
		return null;
	}

	/*
	 * ------------------------------------------------------------------------------------------------------------
	 * Texts (WordPress). Plain text with every number isolated by U+2066/U+2069, for the phone's notes, the transfer
	 * list and the note column. Amounts there are plain text from wc_price(). The sentence of every reason the admin
	 * screens name is Admin\Reasons::text(), the one English source of those texts: reason_text() takes it as plain
	 * text, and writes here only the reasons that Admin\Reasons reads from this class.
	 * ------------------------------------------------------------------------------------------------------------
	 */

	/**
	 * The merchant's line of a reason (the reasons table of docs/17 §4.6), plain text: the sentence of
	 * Admin\Reasons::text() for every reason it names, Completion::reason_note() for the two reasons Completion
	 * writes, and the lines here for the others.
	 *
	 * @param string $code Reason code (or a risk reason).
	 * @param array  $args `N`, `M` (order ids), `T` (transfer id), `Z` (piasters), `W` (wallet id), `form`, `values`,
	 *                     `orange` (bool, for no_payer).
	 * @return string
	 */
	public static function reason_text( string $code, array $args = array() ): string {
		$order = isset( $args['N'] ) ? (int) $args['N'] : null;
		if ( Completion::REASON_ON_HOLD === $code || Completion::REASON_FAILED === $code ) {
			return (string) Completion::reason_note( $code, null === $order ? 0 : $order );
		}
		$admin = self::admin_reason( $code );
		if ( null !== $admin ) {
			$text = self::admin_text( $admin, '', $args );
			if ( 'duplicate_txn' === $admin && null !== $order ) {
				/* translators: %s: order number. */
				$text .= ' ' . sprintf( __( 'That transfer paid order %s.', 'qersh-for-woocommerce' ), self::order_tag( $order ) );
			}
			return $text;
		}
		switch ( $code ) {
			case 'several_orders':
				return __( 'Several open orders of this payer, and none matches the amount.', 'qersh-for-woocommerce' );
			case 'late_delivery':
				return __( 'Arrived late: no order of this payer was open when the money arrived.', 'qersh-for-woocommerce' );
			case 'no_amount':
				return __( 'The message does not show the amount.', 'qersh-for-woocommerce' );
			case 'bad_time':
				return __( 'The time the money arrived cannot be read from the message: check the transfer in the wallet app.', 'qersh-for-woocommerce' );
			case 'cancelled_paid':
				/* translators: %s: order number. */
				return sprintf( __( 'Order %s was cancelled or its payment method changed after money arrived for it: refund the customer from the wallet app.', 'qersh-for-woocommerce' ), null === $order ? '…' : self::order_tag( $order ) );
			case 'not_confirmed':
				return __( 'Qersh did not confirm this transfer on its own: check that the money arrived in the wallet app.', 'qersh-for-woocommerce' );
		}
		// A known risk reason without a sentence of its own, or a code this version does not know (docs/17 §4.6 rule 1).
		return self::admin_text( Risk::is_known( $code ) ? 'risk_other' : 'unknown_risk', $code, array() );
	}

	/**
	 * A note of the reply to the phone, or of a status update, plain text of at most 200 characters. The two notes of
	 * a transfer without the payer's number are the reason's sentence without its full stop, as the kit writes them.
	 *
	 * @param string|null $code Note code: a reason, or `personal`, `no_payer_orange`, `by_claim`, `attached`,
	 *                          `paid_late`.
	 * @param array       $args Arguments of reason_text().
	 * @return string|null
	 */
	public static function reply_note( ?string $code, array $args = array() ): ?string {
		if ( null === $code || '' === $code ) {
			return null;
		}
		switch ( $code ) {
			case 'no_payer':
				$text = self::without_period( self::reason_text( 'no_payer' ) );
				break;
			case 'no_payer_orange':
				$text = self::without_period( self::reason_text( 'no_payer', array( 'orange' => true ) ) );
				break;
			case 'by_claim':
				$text = __( 'Matched by the transaction id the customer entered', 'qersh-for-woocommerce' );
				break;
			case 'attached':
				$text = __( 'Attached to the order by the merchant', 'qersh-for-woocommerce' );
				break;
			case 'paid_late':
				$text = self::without_period( self::hint_text( 'paid_late' ) );
				break;
			case 'closed_order':
				if ( empty( $args['payer'] ) ) {
					$text = self::reason_text( $code, $args );
					break;
				}
				/* translators: %s: order number. */
				$text = sprintf( __( 'A transfer from this payer arrived after order %s was closed; decide: attach it or refund the money.', 'qersh-for-woocommerce' ), isset( $args['N'] ) ? self::order_tag( (int) $args['N'] ) : '…' );
				break;
			default:
				$text = self::reason_text( $code, $args );
		}
		return self::cut( wp_strip_all_tags( $text ), 200 );
	}

	/**
	 * A line of the transfer list for one hint of a decision (rounding, a payment after the deadline, a possible
	 * second payment…), plain text.
	 *
	 * @param string $code Hint code.
	 * @param array  $args Arguments.
	 * @return string
	 */
	public static function hint_text( string $code, array $args = array() ): string {
		switch ( $code ) {
			case 'rounded':
				/* translators: %s: the piasters added, as an amount. */
				return sprintf( __( 'Rounded to the pound (+%s).', 'qersh-for-woocommerce' ), self::plain_price( isset( $args['X'] ) ? (int) $args['X'] : 0 ) );
			case 'paid_late':
				return __( 'Paid after the deadline.', 'qersh-for-woocommerce' );
			case 'overpaid':
				/* translators: %s: amount. */
				return sprintf( __( 'Overpaid by %s', 'qersh-for-woocommerce' ), self::plain_price( isset( $args['X'] ) ? (int) $args['X'] : 0 ) );
			case 'second_payment':
				/* translators: %s: order number. */
				return sprintf( __( 'Possibly a second payment for order %s from the same payer; contact them to refund the amount from the wallet app.', 'qersh-for-woocommerce' ), isset( $args['N'] ) ? self::order_tag( (int) $args['N'] ) : '…' );
			default:
				return self::reason_text( $code, $args );
		}
	}

	/**
	 * The number of an order as the merchant and the customer see it (get_order_number()), isolated.
	 *
	 * @param int $order_id Order id.
	 * @return string
	 */
	public static function order_ref( int $order_id ): string {
		$number = (string) $order_id;
		if ( $order_id > 0 && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $order_id );
			if ( $order instanceof \WC_Order ) {
				$number = (string) $order->get_order_number();
			}
		}
		return self::iso( $number );
	}

	/**
	 * `#` and the number of an order, isolated together, as the admin screens write it in "order %s".
	 *
	 * @param int $order_id Order id.
	 * @return string
	 */
	public static function order_tag( int $order_id ): string {
		$number = (string) $order_id;
		if ( $order_id > 0 && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $order_id );
			if ( $order instanceof \WC_Order ) {
				$number = (string) $order->get_order_number();
			}
		}
		return self::iso( '#' . $number );
	}

	/**
	 * An amount as plain text from wc_price(), isolated (Format::price()).
	 *
	 * @param int $minor Piasters.
	 * @return string
	 */
	public static function plain_price( int $minor ): string {
		return Format::price( $minor, Format::PLAIN, 'EGP' );
	}

	/**
	 * Plain-text isolation by the rule of Format::isolate(): U+2066 … U+2069, or U+2068 … U+2069 for a value with
	 * right-to-left letters.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	public static function iso( string $value ): string {
		return Format::isolate( $value, Format::PLAIN );
	}

	/**
	 * The Admin\Reasons code whose sentence is the text of a reason, or null for a reason whose text is here.
	 *
	 * @param string $code Reason code (or a risk reason).
	 * @return string|null
	 */
	private static function admin_reason( string $code ): ?string {
		if ( 'suspected_forgery' === $code ) {
			return 'forgery';
		}
		if ( 'merchant_confirmed' === $code || in_array( $code, Reasons::STORE_REASONS, true ) || in_array( $code, Reasons::RISK_ORDER, true ) ) {
			return $code;
		}
		return null;
	}

	/**
	 * A sentence of Admin\Reasons::text(), from the arguments of reason_text(), as plain text.
	 *
	 * @param string $code   An Admin\Reasons code.
	 * @param string $detail The code a `risk_other` or `unknown_risk` sentence names ('' to take `values`).
	 * @param array  $args   Arguments of reason_text().
	 * @return string
	 */
	private static function admin_text( string $code, string $detail, array $args ): string {
		if ( 'unknown_risk' === $code && '' === $detail && isset( $args['values'] ) ) {
			$detail = (string) $args['values'];
		}
		$form    = isset( $args['form'] ) ? (string) $args['form'] : '';
		$variant = '';
		if ( 'no_payer' === $code ) {
			$variant = empty( $args['orange'] ) ? '' : 'orange';
		} elseif ( 'txn_contested' === $code ) {
			$variant = 'competitor' === $form ? 'competitor' : 'two';
		} elseif ( 'txn_window' === $code ) {
			$variant = 'early' === $form ? 'early' : 'created';
		}
		$pick   = array(
			'code'    => $code,
			'variant' => 'balance_unavailable' === $code ? 'sms' : '', // risk_reason() names the notification channel apart.
			'detail'  => $detail,
		);
		$params = array(
			'n'       => isset( $args['N'] ) ? (int) $args['N'] : null,
			'm'       => isset( $args['M'] ) ? (int) $args['M'] : null,
			't'       => isset( $args['T'] ) && '' !== (string) $args['T'] ? (string) $args['T'] : null,
			'z'       => isset( $args['Z'] ) ? (int) $args['Z'] : null,
			'w'       => isset( $args['W'] ) ? self::wallet_label( (string) $args['W'] ) : null,
			'variant' => $variant,
		);
		return self::plain( Reasons::text( $pick, $params ) );
	}

	/**
	 * The plain text of a line of escaped HTML: each isolating element (a `dir` attribute, or a `<bdi>`, which takes
	 * the direction of its first strong letter) becomes the matching isolate, U+2066, U+2067 or U+2068 … U+2069; the
	 * other tags go, and the entities are decoded. Pure.
	 *
	 * @param string $html Escaped HTML.
	 * @return string
	 */
	private static function plain( string $html ): string {
		$isolates = array(
			'ltr'  => "\u{2066}",
			'rtl'  => "\u{2067}",
			'auto' => "\u{2068}",
		);
		$parts    = preg_split( '/(<[^>]*>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
		$out      = '';
		$open     = array();
		foreach ( false === $parts ? array( $html ) : $parts as $part ) {
			if ( '' === $part || '<' !== $part[0] ) {
				$out .= $part;
				continue;
			}
			if ( 0 === strpos( $part, '</' ) ) {
				$out .= true === array_pop( $open ) ? "\u{2069}" : '';
				continue;
			}
			if ( '/>' === substr( $part, -2 ) || 1 === preg_match( '/^<(?:br|wbr|img|hr)\b/i', $part ) ) {
				continue; // A void element closes nothing.
			}
			$isolate = '';
			if ( 1 === preg_match( '/\sdir\s*=\s*["\']?(ltr|rtl|auto)\b/i', $part, $dir ) ) {
				$isolate = $isolates[ strtolower( $dir[1] ) ];
			} elseif ( 1 === preg_match( '/^<bdi\b/i', $part ) ) {
				$isolate = $isolates['auto'];
			}
			$open[] = '' !== $isolate;
			$out   .= $isolate;
		}
		return trim( html_entity_decode( $out, ENT_QUOTES, 'UTF-8' ) );
	}

	/**
	 * A sentence without its final full stop (a note of the reply to the phone).
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function without_period( string $text ): string {
		return '.' === substr( $text, -1 ) ? substr( $text, 0, -1 ) : $text;
	}

	/**
	 * The name the admin screens give a wallet of the configuration (its label, or its type), or its id.
	 *
	 * @param string $wallet Wallet id.
	 * @return string
	 */
	private static function wallet_label( string $wallet ): string {
		$entry = Plugin::instance()->wallet( $wallet );
		$name  = null !== $entry ? View::wallet_name( $entry ) : '';
		return '' !== $name ? $name : $wallet;
	}

	/**
	 * Cuts text to a number of characters.
	 *
	 * @param string $text Text.
	 * @param int    $len  Characters.
	 * @return string
	 */
	public static function cut( string $text, int $len ): string {
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $text, 'UTF-8' ) > $len ) {
			return (string) mb_substr( $text, 0, $len, 'UTF-8' );
		}
		return $text;
	}
}
