<?php
/**
 * The risk reasons this version knows: `$defs.riskReason` of the locked fixtures/webhook.v1.schema.json.
 *
 * @package QershPay\WooCommerce
 */

declare( strict_types=1 );

namespace QershPay\WooCommerce\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Known and unknown risk reasons. Pure PHP.
 *
 * The kit says to treat any `risk_reasons` value the receiver does not know like `auto_confirm_ok` false
 * (kit/AI_PROMPT.md item 2, and the schema's own description), while matchTransfer reads only `auto_confirm_ok`.
 * MatchPolicy therefore asks unknown_reasons() first and sends a transfer with an unknown reason to review
 * (docs/17 §4.6 rule 1). scripts/check-fixtures.mjs fails when KNOWN_REASONS differs from the schema.
 */
final class Risk {

	/** The 22 values of `$defs.riskReason`, in the schema's order. */
	const KNOWN_REASONS = array(
		'sim_mismatch',
		'sim_ambiguous',
		'sim_unknown',
		'receiver_wallet_mismatch',
		'balance_inconsistent',
		'balance_gap',
		'no_baseline',
		'balance_unavailable',
		'balance_stale',
		'txn_id_duplicate',
		'ref_malformed',
		'no_txn_id',
		'local_template',
		'unverified_template',
		'clock_skew',
		'amount_over_limit',
		'amount_unusual',
		'amount_over_max',
		'notification_only',
		'partial_template',
		'amount_invalid',
		'suspected_forgery',
	);

	/**
	 * Whether the value is one of KNOWN_REASONS (exact, case-sensitive).
	 *
	 * @param mixed $reason Value.
	 * @return bool
	 */
	public static function is_known( $reason ): bool {
		return is_string( $reason ) && in_array( $reason, self::KNOWN_REASONS, true );
	}

	/**
	 * The values of `risk_reasons` this version does not know, each once, in the order they first appear.
	 *
	 * - A missing field (null) or an empty list has no unknown reason.
	 * - An unknown string is returned as it is. Any other value (a number, null, an array) is unknown too and is
	 *   returned as its JSON text, so a malformed list still sends the transfer to review.
	 * - A `risk_reasons` that is not an array at all is one unknown value, returned as its JSON text.
	 *
	 * The texts come from the phone: escape them where they are shown, and shorten them where space is short.
	 *
	 * @param mixed $reasons The `risk_reasons` field.
	 * @return string[]
	 */
	public static function unknown_reasons( $reasons ): array {
		if ( null === $reasons ) {
			return array();
		}
		if ( ! is_array( $reasons ) ) {
			return array( self::describe( $reasons ) );
		}
		$unknown = array();
		foreach ( $reasons as $reason ) {
			if ( self::is_known( $reason ) ) {
				continue;
			}
			$text = is_string( $reason ) ? $reason : self::describe( $reason );
			if ( ! in_array( $text, $unknown, true ) ) {
				$unknown[] = $text;
			}
		}
		return $unknown;
	}

	/**
	 * A value as JSON text, or its type when it cannot be encoded.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function describe( $value ): string {
		$json = json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- src/Core is pure PHP without WordPress.
		return false === $json ? gettype( $value ) : $json;
	}
}
