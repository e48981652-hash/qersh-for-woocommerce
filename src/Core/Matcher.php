<?php
/**
 * Payer-and-time matching: a port of matchTransfer and judgeUpdate (packages/qersh-webhook/src/core/match.ts), in
 * integer seconds instead of milliseconds.
 *
 * @package QershPay\WooCommerce
 */

declare( strict_types=1 );

namespace QershPay\WooCommerce\Core;

defined( 'ABSPATH' ) || exit;

/**
 * A line-by-line port of match.ts. Pure PHP: nothing is mutated and nothing is stored. It never throws for array
 * input; a candidate list that is not an array is a TypeError, because CONTRACTS.md §6 types it `array` (match.ts
 * reads such a list as no candidates).
 *
 * Rules (the contract, docs/02 §E.8 of the Qersh repository):
 * - A transfer is recognised by the payer the customer registered at checkout, inside the order's time window; the
 *   amount only decides between that payer's own open orders (*9# transfers are whole pounds).
 * - "Time" is when the money arrived (`received_at`), never when the delivery reached the store. Candidates are the
 *   payer's orders that did not expire more than `late_window_min` before the money and were not created more than
 *   `skew_min` after it; a transfer never pays an order opened after the money arrived.
 * - A late delivery (money older than the late window) with no such order is `review`, not `unmatched`.
 * - `auto_confirm_ok` false is always `review`.
 *
 * The core's safe differences from the kit's reference are kept: a transfer without an integer amount is `review`,
 * and an order of this payer whose price or times cannot be read is never matched and turns a miss into `review`.
 *
 * Differences from match.ts, all forced by PHP (see docs-dev/CONTRACTS.md §6):
 * - Every time is integer UTC seconds: `$now`, the candidates' `created_at`/`expires_at`, and the two windows
 *   (minutes × 60). An ISO string is read with Time::iso_to_sec() (CONTRACTS.md §1), which refuses a date-time
 *   without an offset. The transfer's `received_at` is read like the candidates' times, so a number there is seconds
 *   (match.ts reads a number as epoch milliseconds; the schema and the phone only send ISO strings with an offset).
 * - So a `received_at` without an offset (`2027-01-12T08:00:00`) is unreadable and counts as now, like `not a date`
 *   (kit check 12). That is the unsafe direction: a backfilled transfer looks fresh and can pay an order opened
 *   after the money. match.ts reads it as the machine's local time (UTC on a UTC server), and docs/17 §4.6 names
 *   DateTimeImmutable, which reads it as UTC under WordPress; both see it as old. This conflict between docs/17 and
 *   CONTRACTS.md §1 is reported; the guard belongs to the caller (MatchPolicy: a `received_at` that is present but
 *   unreadable goes to review). Schema-valid envelopes always carry an offset.
 * - "Integer" means what Number.isInteger() means: a PHP int, or a float with no fraction (as json_decode() returns
 *   for `1.5e4`) inside ±(2^53 − 1) and PHP_INT_MAX. A numeric string is never an integer, as in JavaScript.
 * - A JavaScript array and object both arrive as PHP arrays. A list (keys 0..n-1) counts as a JavaScript array, any
 *   other array, and the empty array, as an object. The candidates are read by value whatever their keys.
 * - Usort is not stable before PHP 8, so ties in `created_at` are broken by the candidates' input order, which is
 *   what JavaScript's stable sort does. Without that tie-break only PHP 7.4 would differ, and only with more than 16
 *   candidates in the window (zend_sort uses insertion sort up to 16).
 */
final class Matcher {

	/** Default of `late_window_min` when the option is missing or not a number ≥ 0, as in match.ts. */
	const LATE_WINDOW_MIN = 120;

	/**
	 * Default of `skew_min` when the option is missing or not a number ≥ 0, as in match.ts. The plugin's own default
	 * is 0 (the owner's decision, docs/17 §4.6), so the caller always passes the configured value.
	 */
	const SKEW_MIN = 10;

	/** Number.MAX_SAFE_INTEGER. */
	const MAX_SAFE_INTEGER = 9007199254740991;

	/**
	 * Matches one transfer.received against the candidate orders (matchTransfer).
	 *
	 * Building the candidates (the caller, MatchPolicy, docs/17 §4.6 rule 3):
	 * - `status` is read right after the payer, before the price and times: a candidate of this payer whose status
	 *   is not `open` or `partial` (null included) is skipped silently. It is not counted as unreadable, so it neither stops the only-order rule nor turns
	 *   `unmatched` into `review`. A row of `qersh_orders` has `state`, not `status`, so a typed row passed as it is
	 *   counts as open whatever its state: always map it. A normal candidate gets `partial` when `paid_minor` > 0,
	 *   else `open`.
	 * - A blocking order (a `closed` row in the window, an order the merchant moved to processing or completed, one
	 *   with `_qersh_flag`, a `review` row) must be passed with status `open` (or none) and `price_minor` null. Passed
	 *   with its real state it is skipped, and the transfer can go to another order of the payer by mistake (a part
	 *   payment, or the only-order rule).
	 * - `order_number` must be a string: the int `order_id` of a row goes in as `(string) $row['order_id']`, or the
	 *   order is unreadable. Prices, payments and times must be ints, not the strings $wpdb returns.
	 * - An unreadable order is counted without the window check, so pass only rows whose life overlaps the window
	 *   (`created_at` ≤ at + skew and `expires_at` ≥ at − late, with at = the money time read as here, a future or
	 *   unreadable one being now): OrdersIndex::for_payer( $key, $at - $late, $at + $skew ) does that.
	 *
	 * @param mixed $transfer   `data` of transfer.received (`counterparty`), or the summarised transfer (`payer.key`).
	 * @param array $candidates Orders: `order_number` string, `price_minor` int (null or not an integer = unreadable),
	 *                          `paid_minor` int (missing or null = 0), `payer_key` string, `created_at` and
	 *                          `expires_at` int seconds (or an ISO string, or a DateTimeInterface), `status`
	 *                          (`open` or `partial` are candidates, missing means open, anything else is skipped).
	 * @param int   $now        The store's clock, UTC seconds.
	 * @param array $options    `late_window_min` (default 120) and `skew_min` (default 10), in minutes.
	 * @return array The MatchResult: status, reference and note (only when set), order_number, action, amount_minor,
	 *               transfer_id, provider_txn_id, payer_key, stop_order (always false).
	 */
	public static function match( $transfer, array $candidates, int $now, array $options = array() ): array {
		$t    = self::read_transfer( $transfer );
		$base = array(
			'order_number'    => null,
			'action'          => null,
			'amount_minor'    => null === $t ? null : $t['amount_minor'],
			'transfer_id'     => null === $t ? null : $t['transfer_id'],
			'provider_txn_id' => null === $t ? null : $t['txn_id'],
			'payer_key'       => null === $t ? null : $t['payer_key'],
			'stop_order'      => false,
		);
		if ( null === $t ) {
			return self::verdict( 'review', 'no transfer data', $base );
		}
		if ( ! $t['auto_ok'] ) {
			return self::verdict( 'review', null, $base ); // risk != low → a human looks
		}
		if ( null === $t['amount_minor'] || $t['amount_minor'] < 0 ) {
			return self::verdict( 'review', 'no amount in the message', $base );
		}
		if ( null === $t['payer_key'] || '' === $t['payer_key'] ) {
			return self::verdict( 'unmatched', 'no payer identifier in the message', $base );
		}

		$late  = self::minutes( array_key_exists( 'late_window_min', $options ) ? $options['late_window_min'] : null, self::LATE_WINDOW_MIN );
		$skew  = self::minutes( array_key_exists( 'skew_min', $options ) ? $options['skew_min'] : null, self::SKEW_MIN );
		$got   = self::to_sec( $t['received_at'] );
		$at    = null !== $got && $got < $now ? $got : $now; // when the money arrived; a clock ahead counts as "now"
		$stale = $now - $at > $late; // a retry, a manual resend or a backfill

		$unreadable = 0;
		$readable   = array();
		foreach ( $candidates as $value ) {
			$read = self::read_order( $value, $t['payer_key'] );
			if ( null !== $read['order'] ) {
				$read['order']['index'] = count( $readable );
				$readable[]             = $read['order'];
			} elseif ( $read['same_payer'] ) {
				++$unreadable;
			}
		}
		$in_window = array();
		foreach ( $readable as $c ) {
			if ( $c['expires'] >= $at - $late && $c['created'] <= $at + $skew ) { // incl. just-expired; never an order opened after the money
				$in_window[] = $c;
			}
		}
		usort(
			$in_window,
			static function ( array $a, array $b ): int {
				$by_created = $b['created'] <=> $a['created']; // newest first
				return 0 !== $by_created ? $by_created : $a['index'] <=> $b['index'];
			}
		);

		if ( 0 === count( $in_window ) ) {
			if ( $unreadable > 0 ) {
				return self::verdict( 'review', $unreadable . ' order(s) of this payer could not be read (price or times)', $base );
			}
			return $stale
				? self::verdict( 'review', 'late delivery: no order of this payer was open when the money arrived', $base )
				: self::verdict( 'unmatched', null, $base );
		}
		$amount = $t['amount_minor'];
		$chosen = null;
		foreach ( $in_window as $c ) {
			if ( $c['price'] - $c['paid'] === $amount ) {
				$chosen = $c; // amount decides
				break;
			}
		}
		if ( null === $chosen && 1 === count( $in_window ) && 0 === $unreadable ) {
			$chosen = $in_window[0]; // else the only one
		}
		if ( null === $chosen ) {
			$note = $unreadable > 0 ? 'another order of this payer could not be read' : 'several open orders for this payer, none matches the amount';
			return self::verdict( 'review', $note, $base );
		}

		$remaining = $chosen['price'] - $chosen['paid'];
		$found     = array_merge(
			$base,
			array(
				'order_number' => $chosen['order_number'],
				'reference'    => $chosen['order_number'],
			)
		);
		if ( $amount < $remaining ) {
			return array_merge(
				$found,
				array(
					'status' => 'partial',
					'action' => 'mark_partial',
				)
			);
		}
		if ( $chosen['expires'] < $at ) {
			return array_merge(
				$found,
				array(
					'status' => 'matched',
					'action' => 'mark_paid',
					'note'   => 'paid after expiry',
				)
			);
		}
		return array_merge(
			$found,
			array(
				'status' => $amount > $remaining ? 'overpaid' : 'matched',
				'action' => 'mark_paid',
			)
		);
	}

	/**
	 * Judges `data` of a transfer.updated, or a summarised transfer (judgeUpdate). Always `ok`; never throws.
	 *
	 * @param mixed $data The event data.
	 * @return array `[ 'status' => 'ok', 'transfer_id' => ?string, 'stop_order' => bool, 'reason' => 'suspected_forgery'|null ]`.
	 */
	public static function judge_update( $data ): array {
		$record      = self::is_record( $data ) ? $data : array();
		$transfer_id = isset( $record['transfer_id'] ) && is_string( $record['transfer_id'] ) ? $record['transfer_id'] : null;
		$forged      = self::is_suspected_forgery( $record )
			|| ( array_key_exists( 'suspected_forgery', $record ) && true === $record['suspected_forgery'] )
			|| ( array_key_exists( 'balance_chain', $record ) && 'refuted' === $record['balance_chain'] );
		return array(
			'status'      => 'ok',
			'transfer_id' => $transfer_id,
			'stop_order'  => $forged,
			'reason'      => $forged ? 'suspected_forgery' : null,
		);
	}

	/**
	 * A result without an order: the status, the note when there is one, then the base fields (the core's
	 * `{ status, note, ...base }`, in that key order).
	 *
	 * @param string      $status Status.
	 * @param string|null $note   Note, or null for none.
	 * @param array       $base   The base fields.
	 * @return array
	 */
	private static function verdict( string $status, ?string $note, array $base ): array {
		$head = array( 'status' => $status );
		if ( null !== $note ) {
			$head['note'] = $note;
		}
		return array_merge( $head, $base );
	}

	/**
	 * The core's isSuspectedForgery (envelope.ts): `risk_reasons` contains `suspected_forgery`, or
	 * `checks.balance_chain` is `refuted`.
	 *
	 * @param array $data The event data.
	 * @return bool
	 */
	private static function is_suspected_forgery( array $data ): bool {
		$reasons = array_key_exists( 'risk_reasons', $data ) && self::is_js_array( $data['risk_reasons'] ) ? $data['risk_reasons'] : array();
		$checks  = array_key_exists( 'checks', $data ) && self::is_record( $data['checks'] ) ? $data['checks'] : null;
		return in_array( 'suspected_forgery', $reasons, true )
			|| ( null !== $checks && array_key_exists( 'balance_chain', $checks ) && 'refuted' === $checks['balance_chain'] );
	}

	/**
	 * Reads the fields the matcher needs from either shape: raw `data` of transfer.received, or the summarised
	 * `transfer` (readTransfer).
	 *
	 * @param mixed $input The transfer.
	 * @return array|null `payer_key`, `amount_minor`, `received_at`, `auto_ok`, `transfer_id`, `txn_id`; null when it is not an object.
	 */
	private static function read_transfer( $input ): ?array {
		if ( ! self::is_record( $input ) ) {
			return null;
		}
		$payer        = array_key_exists( 'payer', $input ) && self::is_record( $input['payer'] ) ? $input['payer'] : null;
		$counterparty = array_key_exists( 'counterparty', $input ) && self::is_record( $input['counterparty'] ) ? $input['counterparty'] : null;
		$payer_key    = null !== $payer && array_key_exists( 'key', $payer ) && is_string( $payer['key'] ) ? Payer::normalize( $payer['key'] ) : null;
		if ( null === $payer_key ) {
			$payer_key = Payer::key_of( $counterparty );
		}
		if ( null === $payer_key ) {
			$payer_key = Payer::key_of( $payer );
		}
		return array(
			'payer_key'    => $payer_key,
			'amount_minor' => self::integer( array_key_exists( 'amount_minor', $input ) ? $input['amount_minor'] : null ),
			'received_at'  => array_key_exists( 'received_at', $input ) ? $input['received_at'] : null,
			'auto_ok'      => array_key_exists( 'auto_confirm_ok', $input ) && true === $input['auto_confirm_ok'],
			'transfer_id'  => array_key_exists( 'transfer_id', $input ) && is_string( $input['transfer_id'] ) ? $input['transfer_id'] : null,
			'txn_id'       => array_key_exists( 'provider_txn_id', $input ) && is_string( $input['provider_txn_id'] ) ? $input['provider_txn_id'] : null,
		);
	}

	/**
	 * An order of this payer as the matcher can use it, or null when its price or times cannot be read (readOrder).
	 *
	 * @param mixed  $value     One candidate.
	 * @param string $payer_key The transfer's payer key.
	 * @return array `[ 'order' => ?array, 'same_payer' => bool ]`; the order has order_number, created, expires, price, paid.
	 */
	private static function read_order( $value, string $payer_key ): array {
		if ( ! self::is_record( $value ) ) {
			return array(
				'order'      => null,
				'same_payer' => false,
			);
		}
		$o   = $value;
		$key = null;
		if ( array_key_exists( 'payer_key', $o ) && is_string( $o['payer_key'] ) ) {
			$normal = Payer::normalize( $o['payer_key'] );
			$key    = null !== $normal ? $normal : $o['payer_key'];
		}
		if ( $key !== $payer_key ) {
			return array(
				'order'      => null,
				'same_payer' => false,
			);
		}
		$open = ! array_key_exists( 'status', $o ) || 'open' === $o['status'] || 'partial' === $o['status'];
		if ( ! $open ) {
			return array(
				'order'      => null,
				'same_payer' => false,
			);
		}
		$created  = self::to_sec( array_key_exists( 'created_at', $o ) ? $o['created_at'] : null );
		$expires  = self::to_sec( array_key_exists( 'expires_at', $o ) ? $o['expires_at'] : null );
		$price    = self::integer( array_key_exists( 'price_minor', $o ) ? $o['price_minor'] : null );
		$price    = null !== $price && $price >= 0 ? $price : null;
		$paid_raw = array_key_exists( 'paid_minor', $o ) ? $o['paid_minor'] : null;
		$paid     = self::integer( $paid_raw );
		if ( null === $paid || $paid < 0 ) {
			$paid = null === $paid_raw ? 0 : null;
		}
		if ( ! array_key_exists( 'order_number', $o ) || ! is_string( $o['order_number'] ) || null === $created || null === $expires || null === $price || null === $paid ) {
			return array(
				'order'      => null,
				'same_payer' => true,
			);
		}
		return array(
			'order'      => array(
				'order_number' => $o['order_number'],
				'created'      => $created,
				'expires'      => $expires,
				'price'        => $price,
				'paid'         => $paid,
			),
			'same_payer' => true,
		);
	}

	/**
	 * A time as UTC seconds (toMs in seconds): an int or finite float is seconds, a string is ISO 8601 with an offset
	 * (Time::iso_to_sec), a DateTimeInterface its timestamp. Null for anything else.
	 *
	 * @param mixed $value Value.
	 * @return int|float|null
	 */
	private static function to_sec( $value ) {
		if ( $value instanceof \DateTimeInterface ) {
			return $value->getTimestamp();
		}
		if ( is_int( $value ) ) {
			return $value;
		}
		if ( is_float( $value ) ) {
			return is_finite( $value ) ? $value : null;
		}
		if ( is_string( $value ) ) {
			return Time::iso_to_sec( $value );
		}
		return null;
	}

	/**
	 * A window in seconds: the option when it is a finite number ≥ 0, else the default, times 60 (minutes()).
	 *
	 * @param mixed $value    Minutes.
	 * @param int   $fallback Default minutes.
	 * @return int|float
	 */
	private static function minutes( $value, int $fallback ) {
		$number = is_int( $value ) || ( is_float( $value ) && is_finite( $value ) );
		return $number && $value >= 0 ? $value * 60 : $fallback * 60;
	}

	/**
	 * The value as an int when JavaScript's Number.isInteger() would accept it, else null.
	 *
	 * @param mixed $value Value.
	 * @return int|null
	 */
	private static function integer( $value ): ?int {
		if ( is_int( $value ) ) {
			return $value;
		}
		if ( is_float( $value ) && is_finite( $value ) && floor( $value ) === $value
			&& abs( $value ) <= (float) self::MAX_SAFE_INTEGER && abs( $value ) <= (float) PHP_INT_MAX ) {
			return (int) $value;
		}
		return null;
	}

	/**
	 * A JavaScript object (isRecord): an array that is not a non-empty list. The empty array counts as an object,
	 * because json_decode() turns `{}` and `[]` into the same value.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function is_record( $value ): bool {
		return is_array( $value ) && ( array() === $value || ! self::is_list( $value ) );
	}

	/**
	 * A JavaScript array (Array.isArray): a PHP list, including the empty array.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function is_js_array( $value ): bool {
		return is_array( $value ) && self::is_list( $value );
	}

	/**
	 * Array_is_list() for PHP 7.4.
	 *
	 * @param array $value Array.
	 * @return bool
	 */
	private static function is_list( array $value ): bool {
		$i = 0;
		foreach ( $value as $k => $unused ) {
			if ( $k !== $i ) {
				return false;
			}
			++$i;
		}
		return true;
	}
}
