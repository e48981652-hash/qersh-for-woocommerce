<?php
/**
 * Tests of Core\Matcher: the 19 checks of kit/tools/test-match.mjs and every test of
 * packages/qersh-webhook/test/match.test.ts, in seconds, plus the PHP-specific rules.
 *
 * @package QershPay\WooCommerce\Tests
 */

declare( strict_types=1 );

namespace QershPay\WooCommerce\Tests\Unit\Core;

use QershPay\WooCommerce\Core\Matcher;
use QershPay\WooCommerce\Core\Time;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

defined( 'ABSPATH' ) || exit;

/**
 * Matcher::match() and Matcher::judge_update().
 */
final class MatcherTest extends TestCase {

	const MIN         = 60;
	const HOUR        = 3600;
	const DAY         = 86400;
	const NOW         = 1800000000; // a fixed clock (2027-01-15); everything is relative to it
	const PAYER       = '01012345678';
	const TRANSFER_ID = 'trf_01J9Z8Y7X6W5V4T3S2R1Q0P9N8';

	/**
	 * The kit's in-memory store: orders by number.
	 *
	 * @var array<string, array>
	 */
	private $orders = array();

	/**
	 * The kit's store: transfer id → order number it paid.
	 *
	 * @var array<string, string>
	 */
	private $payments = array();

	/**
	 * The kit's store: transfer id → flag reason.
	 *
	 * @var array<string, string>
	 */
	private $flagged = array();

	/**
	 * Empties the kit store before each test.
	 */
	protected function set_up() {
		parent::set_up();
		$this->fresh();
	}

	/**
	 * The kit's fresh(): an empty store.
	 */
	private function fresh() {
		$this->orders   = array();
		$this->payments = array();
		$this->flagged  = array();
	}

	/*
	 * Helpers, as in the two test files.
	 */

	/**
	 * `data` of a transfer.received whose money arrived `$age` seconds ago.
	 *
	 * @param int      $age    Seconds before NOW.
	 * @param int|null $amount Piasters.
	 * @param array    $extra  Fields to override.
	 * @return array
	 */
	private static function transfer( int $age, $amount = 15000, array $extra = array() ): array {
		return array_merge(
			array(
				'transfer_id'     => self::TRANSFER_ID,
				'auto_confirm_ok' => true,
				'amount_minor'    => $amount,
				'provider_txn_id' => '099409626954',
				'counterparty'    => array(
					'number' => self::PAYER,
					'name'   => 'Customer',
					'handle' => null,
				),
				'received_at'     => Time::sec_to_iso( self::NOW - $age ),
			),
			$extra
		);
	}

	/**
	 * `data` of a transfer.updated for that transfer.
	 *
	 * @param array $extra Fields to override.
	 * @return array
	 */
	private static function update( array $extra = array() ): array {
		return array_merge(
			array(
				'transfer_id'     => self::TRANSFER_ID,
				'updated_fields'  => array( 'checks.balance_chain', 'risk', 'risk_reasons', 'auto_confirm_ok' ),
				'checks'          => array( 'balance_chain' => 'refuted' ),
				'risk'            => 'high',
				'risk_reasons'    => array( 'balance_inconsistent', 'suspected_forgery' ),
				'auto_confirm_ok' => false,
			),
			$extra
		);
	}

	/**
	 * An order opened `$opened_ago` seconds ago with the given time to live.
	 *
	 * @param string $number     Order number.
	 * @param int    $opened_ago Seconds before NOW.
	 * @param int    $ttl        Seconds.
	 * @param int    $price      Piasters.
	 * @return array
	 */
	private static function old_order( string $number, int $opened_ago, int $ttl, int $price = 15000 ): array {
		return array(
			'order_number' => $number,
			'price_minor'  => $price,
			'paid_minor'   => 0,
			'payer_key'    => self::PAYER,
			'created_at'   => self::NOW - $opened_ago,
			'expires_at'   => self::NOW - $opened_ago + $ttl,
			'status'       => 'open',
		);
	}

	/**
	 * An order opened now with a time to live of 120 minutes, like OrderMatcher.open().
	 *
	 * @param string $number Order number.
	 * @param int    $price  Piasters.
	 * @param string $payer  Payer key.
	 * @return array
	 */
	private static function open_order( string $number, int $price = 15000, string $payer = self::PAYER ): array {
		return array(
			'order_number' => $number,
			'price_minor'  => $price,
			'paid_minor'   => 0,
			'payer_key'    => $payer,
			'created_at'   => self::NOW,
			'expires_at'   => self::NOW + 120 * self::MIN,
			'status'       => 'open',
		);
	}

	/**
	 * Runs the matcher at NOW with the core's default windows.
	 *
	 * @param mixed $transfer The transfer.
	 * @param array $orders   Candidates.
	 * @return array
	 */
	private static function match_now( $transfer, array $orders ): array {
		return Matcher::match( $transfer, $orders, self::NOW );
	}

	/**
	 * Applies the matcher's action to the candidate list, as a store would.
	 *
	 * @param array $orders Candidates, changed in place.
	 * @param array $r      Match result.
	 */
	private static function apply( array &$orders, array $r ) {
		foreach ( $orders as $i => $o ) {
			if ( $o['order_number'] !== $r['order_number'] ) {
				continue;
			}
			if ( 'mark_paid' === $r['action'] ) {
				$orders[ $i ]['status'] = 'paid';
			}
			if ( 'mark_partial' === $r['action'] ) {
				$orders[ $i ]['status'] = 'partial';
			}
			if ( null !== $r['action'] ) {
				$orders[ $i ]['paid_minor'] = ( isset( $o['paid_minor'] ) ? $o['paid_minor'] : 0 ) + ( isset( $r['amount_minor'] ) ? $r['amount_minor'] : 0 );
			}
			return;
		}
	}

	/**
	 * The merchant reply: status, plus reference and note when set.
	 *
	 * @param array $r Match result.
	 * @return array
	 */
	private static function reply( array $r ): array {
		$out = array( 'status' => $r['status'] );
		if ( isset( $r['reference'] ) && '' !== $r['reference'] ) {
			$out['reference'] = $r['reference'];
		}
		if ( isset( $r['note'] ) && '' !== $r['note'] ) {
			$out['note'] = $r['note'];
		}
		return $out;
	}

	/**
	 * A merchant reply with a reference, as reply() returns it.
	 *
	 * @param string $status    Status.
	 * @param string $reference Order number.
	 * @return array
	 */
	private static function answer( string $status, string $reference ): array {
		return array(
			'status'    => $status,
			'reference' => $reference,
		);
	}

	/*
	 * The kit's store and OrderMatcher (kit/tools/test-match.mjs), over Matcher.
	 */

	/**
	 * OrderMatcher.open(): an order opened now for 120 minutes.
	 *
	 * @param string $number Order number.
	 * @param int    $price  Piasters.
	 */
	private function kit_open( string $number, int $price ) {
		$this->orders[ $number ] = self::open_order( $number, $price );
	}

	/**
	 * The kit's oldOrder(): an order opened `$opened_ago` seconds ago.
	 *
	 * @param string $number     Order number.
	 * @param int    $opened_ago Seconds before NOW.
	 * @param int    $ttl        Seconds.
	 */
	private function kit_old( string $number, int $opened_ago, int $ttl ) {
		$this->orders[ $number ] = self::old_order( $number, $opened_ago, $ttl );
	}

	/**
	 * OrderMatcher.match(): runs the matcher on the store, applies the action, returns the reply.
	 *
	 * @param array $data The transfer.
	 * @return array
	 */
	private function kit_match( array $data ): array {
		$r = Matcher::match( $data, $this->orders, self::NOW );
		if ( null !== $r['action'] ) {
			$number             = $r['order_number'];
			$o                  = $this->orders[ $number ];
			$o['status']        = 'mark_paid' === $r['action'] ? 'paid' : 'partial';
			$o['paid_minor']   += $r['amount_minor'];
			$this->orders[ $number ] = $o;

			$this->payments[ $r['transfer_id'] ] = $number;
		}
		return self::reply( $r );
	}

	/**
	 * OrderMatcher.updated(): a forgery label stops the order the transfer paid; always answered ok.
	 *
	 * @param array $data The transfer.updated data.
	 * @return array
	 */
	private function kit_updated( array $data ): array {
		$judgement = Matcher::judge_update( $data );
		if ( $judgement['stop_order'] ) {
			$this->flagged[ $judgement['transfer_id'] ] = $judgement['reason'];
			if ( isset( $this->payments[ $judgement['transfer_id'] ] ) ) {
				$this->orders[ $this->payments[ $judgement['transfer_id'] ] ]['status'] = 'review';
			}
		}
		return array( 'status' => $judgement['status'] );
	}

	/*
	 * kit/tools/test-match.mjs: the 19 checks.
	 */

	/** Kit check 1. */
	public function test_kit_a_transfer_that_just_arrived_pays_the_open_order() {
		$this->kit_open( 'B', 15000 );
		$this->assertSame( self::answer( 'matched', 'B' ), $this->kit_match( self::transfer( 10 ) ) );
		$this->assertSame( 'paid', $this->orders['B']['status'] );
	}

	/** Kit check 2. */
	public function test_kit_a_retry_34_minutes_late_pays_the_order_that_was_open_when_the_money_arrived() {
		$this->kit_old( 'B', 40 * self::MIN, 2 * self::HOUR );
		$this->assertSame( self::answer( 'matched', 'B' ), $this->kit_match( self::transfer( 34 * self::MIN ) ) );
	}

	/** Kit check 3. */
	public function test_kit_a_retry_34_minutes_late_never_pays_an_order_opened_30_minutes_after_the_money() {
		$this->kit_old( 'B', 4 * self::MIN, 2 * self::HOUR );
		$this->assertSame( 'unmatched', $this->kit_match( self::transfer( 34 * self::MIN ) )['status'] );
		$this->assertSame( 'open', $this->orders['B']['status'] );
	}

	/** Kit check 4. */
	public function test_kit_a_transfer_held_15_minutes_pays_the_order_open_when_the_money_arrived_not_one_opened_during_the_hold() {
		$this->kit_old( 'A', 20 * self::MIN, 2 * self::HOUR );
		$this->kit_old( 'B', 2 * self::MIN, 2 * self::HOUR ); // the customer opened a second order while the first payment was held
		$this->assertSame( self::answer( 'matched', 'A' ), $this->kit_match( self::transfer( 15 * self::MIN ) ) );
		$this->assertSame( 'open', $this->orders['B']['status'] );
	}

	/** Kit check 5. */
	public function test_kit_a_transfer_held_15_minutes_whose_order_expired_during_the_hold_the_money_came_in_time() {
		$this->kit_old( 'A', 2 * self::HOUR + 10 * self::MIN, 2 * self::HOUR ); // expired 10 minutes ago, the money arrived 15 minutes ago
		$this->assertSame( self::answer( 'matched', 'A' ), $this->kit_match( self::transfer( 15 * self::MIN ) ) );
	}

	/** Kit check 6. */
	public function test_kit_a_3_day_old_transfer_never_pays_an_order_opened_today_same_amount() {
		$this->kit_open( 'B', 15000 );
		$this->assertSame( 'review', $this->kit_match( self::transfer( 3 * self::DAY ) )['status'] );
		$this->assertSame( 'open', $this->orders['B']['status'] );
	}

	/** Kit check 7. */
	public function test_kit_a_3_day_old_transfer_never_part_pays_the_only_order_opened_today_other_amount() {
		$this->kit_open( 'B', 20000 );
		$this->assertSame( 'review', $this->kit_match( self::transfer( 3 * self::DAY ) )['status'] );
		$this->assertSame( 0, $this->orders['B']['paid_minor'] );
	}

	/** Kit check 8. */
	public function test_kit_a_3_day_old_transfer_pays_the_order_that_was_open_when_the_money_arrived_and_only_that_one() {
		$this->kit_old( 'A', 3 * self::DAY + 20 * self::MIN, 2 * self::HOUR );
		$this->kit_open( 'B', 15000 );
		$this->assertSame( self::answer( 'matched', 'A' ), $this->kit_match( self::transfer( 3 * self::DAY ) ) );
		$this->assertSame( 'open', $this->orders['B']['status'] );
	}

	/** Kit check 9. */
	public function test_kit_late_transfer_the_order_of_that_time_had_expired_less_than_2_hours_before_the_money_paid_after_expiry() {
		$this->kit_old( 'A', 3 * self::DAY + 3 * self::HOUR, 2 * self::HOUR );
		$this->assertSame(
			array(
				'status'    => 'matched',
				'reference' => 'A',
				'note'      => 'paid after expiry',
			),
			$this->kit_match( self::transfer( 3 * self::DAY ) )
		);
	}

	/** Kit check 10. */
	public function test_kit_late_transfer_the_order_of_that_time_had_expired_more_than_2_hours_before_the_money_review() {
		$this->kit_old( 'A', 3 * self::DAY + 6 * self::HOUR, 2 * self::HOUR );
		$this->assertSame( 'review', $this->kit_match( self::transfer( 3 * self::DAY ) )['status'] );
		$this->assertSame( 'open', $this->orders['A']['status'] );
	}

	/** Kit check 11. */
	public function test_kit_an_order_opened_a_few_minutes_after_the_money_clock_difference_still_takes_a_late_delivery() {
		$this->kit_old( 'A', 5 * self::HOUR - 5 * self::MIN, 2 * self::HOUR );
		$this->assertSame( self::answer( 'matched', 'A' ), $this->kit_match( self::transfer( 5 * self::HOUR ) ) );
	}

	/** Kit check 12. */
	public function test_kit_received_at_in_the_future_missing_or_unreadable_counts_as_now() {
		foreach ( array( Time::sec_to_iso( self::NOW + 3 * self::HOUR ), null, 'not a date' ) as $received_at ) {
			$this->fresh();
			$this->kit_open( 'B', 15000 );
			$this->assertSame( self::answer( 'matched', 'B' ), $this->kit_match( self::transfer( 0, 15000, array( 'received_at' => $received_at ) ) ) );
		}
	}

	/** Kit check 13. */
	public function test_kit_received_at_with_the_numeric_offset_the_phone_sends_is_read() {
		$this->kit_open( 'B', 15000 );
		$this->assertSame( 'review', $this->kit_match( self::transfer( 0, 15000, array( 'received_at' => '2026-10-11T16:36:41+03:00' ) ) )['status'] );
		$this->assertSame( 'open', $this->orders['B']['status'] );
	}

	/** Kit check 14. */
	public function test_kit_no_order_partial_and_overpaid_answers_are_unchanged_for_a_transfer_that_just_arrived() {
		$this->assertSame( array( 'status' => 'unmatched' ), $this->kit_match( self::transfer( 1 ) ) );
		$this->kit_open( 'B', 20000 );
		$this->assertSame( self::answer( 'partial', 'B' ), $this->kit_match( self::transfer( 1, 15000 ) ) );
		$this->assertSame( self::answer( 'overpaid', 'B' ), $this->kit_match( self::transfer( 1, 9000 ) ) );
	}

	/** Kit check 15. */
	public function test_kit_auto_confirm_ok_false_is_always_review() {
		$this->kit_open( 'B', 15000 );
		$this->assertSame( array( 'status' => 'review' ), $this->kit_match( self::transfer( 1, 15000, array( 'auto_confirm_ok' => false ) ) ) );
		$this->assertSame( 'open', $this->orders['B']['status'] );
	}

	/** Kit check 16. */
	public function test_kit_transfer_updated_with_suspected_forgery_stops_the_order_that_transfer_paid_and_is_answered_ok() {
		$this->kit_open( 'B', 15000 );
		$this->assertSame( 'matched', $this->kit_match( self::transfer( 1 ) )['status'] );
		$this->assertSame( array( 'status' => 'ok' ), $this->kit_updated( self::update() ) );
		$this->assertSame( 'review', $this->orders['B']['status'] );
		$this->assertSame( 'suspected_forgery', $this->flagged[ self::TRANSFER_ID ] );
	}

	/** Kit check 17. */
	public function test_kit_transfer_updated_with_balance_chain_refuted_alone_also_stops_the_order() {
		$this->kit_open( 'B', 15000 );
		$this->kit_match( self::transfer( 1 ) );
		$this->assertSame( array( 'status' => 'ok' ), $this->kit_updated( self::update( array( 'risk_reasons' => array( 'balance_inconsistent' ) ) ) ) );
		$this->assertSame( 'review', $this->orders['B']['status'] );
	}

	/** Kit check 18. */
	public function test_kit_other_transfer_updated_late_proof_merchant_confirmed_change_nothing_and_are_answered_ok() {
		foreach ( array( 'late_consistent', 'merchant_confirmed' ) as $chain ) {
			$this->fresh();
			$this->kit_open( 'B', 15000 );
			$this->kit_match( self::transfer( 1 ) );
			$extra = array(
				'checks'          => array( 'balance_chain' => $chain ),
				'risk'            => 'low',
				'risk_reasons'    => array(),
				'auto_confirm_ok' => true,
			);
			$this->assertSame( array( 'status' => 'ok' ), $this->kit_updated( self::update( $extra ) ) );
			$this->assertSame( 'paid', $this->orders['B']['status'] );
			$this->assertCount( 0, $this->flagged );
		}
	}

	/** Kit check 19. */
	public function test_kit_transfer_updated_with_suspected_forgery_for_a_transfer_that_paid_nothing_is_remembered_and_answered_ok() {
		$this->assertSame( array( 'status' => 'ok' ), $this->kit_updated( self::update() ) );
		$this->assertSame( 'suspected_forgery', $this->flagged[ self::TRANSFER_ID ] );
	}

	/*
	 * packages/qersh-webhook/test/match.test.ts: every test.
	 */

	/** Core test 1. */
	public function test_core_a_transfer_that_just_arrived_pays_the_open_order() {
		$orders = array( self::open_order( 'B' ) );
		$r      = self::match_now( self::transfer( 10 ), $orders );
		$this->assertSame( self::answer( 'matched', 'B' ), self::reply( $r ) );
		$this->assertSame( 'mark_paid', $r['action'] );
		self::apply( $orders, $r );
		$this->assertSame( 'paid', $orders[0]['status'] );
	}

	/** Core test 2. */
	public function test_core_a_retry_34_minutes_late_pays_the_order_that_was_open_when_the_money_arrived() {
		$this->assertSame(
			self::answer( 'matched', 'B' ),
			self::reply( self::match_now( self::transfer( 34 * self::MIN ), array( self::old_order( 'B', 40 * self::MIN, 2 * self::HOUR ) ) ) )
		);
	}

	/** Core test 3. */
	public function test_core_a_retry_34_minutes_late_never_pays_an_order_opened_30_minutes_after_the_money() {
		$r = self::match_now( self::transfer( 34 * self::MIN ), array( self::old_order( 'B', 4 * self::MIN, 2 * self::HOUR ) ) );
		$this->assertSame( 'unmatched', $r['status'] );
		$this->assertNull( $r['action'] );
	}

	/** Core test 4. */
	public function test_core_a_transfer_held_15_minutes_pays_the_order_open_when_the_money_arrived_not_one_opened_during_the_hold() {
		$orders = array( self::old_order( 'A', 20 * self::MIN, 2 * self::HOUR ), self::old_order( 'B', 2 * self::MIN, 2 * self::HOUR ) );
		$this->assertSame( self::answer( 'matched', 'A' ), self::reply( self::match_now( self::transfer( 15 * self::MIN ), $orders ) ) );
	}

	/** Core test 5. */
	public function test_core_a_transfer_held_15_minutes_whose_order_expired_during_the_hold_the_money_came_in_time() {
		$this->assertSame(
			self::answer( 'matched', 'A' ),
			self::reply( self::match_now( self::transfer( 15 * self::MIN ), array( self::old_order( 'A', 2 * self::HOUR + 10 * self::MIN, 2 * self::HOUR ) ) ) )
		);
	}

	/** Core test 6. */
	public function test_core_a_3_day_old_transfer_never_pays_an_order_opened_today_same_amount() {
		$r = self::match_now( self::transfer( 3 * self::DAY ), array( self::open_order( 'B' ) ) );
		$this->assertSame( 'review', $r['status'] );
		$this->assertNull( $r['action'] );
	}

	/** Core test 7. */
	public function test_core_a_3_day_old_transfer_never_part_pays_the_only_order_opened_today_other_amount() {
		$r = self::match_now( self::transfer( 3 * self::DAY ), array( self::open_order( 'B', 20000 ) ) );
		$this->assertSame( 'review', $r['status'] );
		$this->assertNull( $r['action'] );
	}

	/** Core test 8. */
	public function test_core_a_3_day_old_transfer_pays_the_order_that_was_open_when_the_money_arrived_and_only_that_one() {
		$orders = array( self::old_order( 'A', 3 * self::DAY + 20 * self::MIN, 2 * self::HOUR ), self::open_order( 'B' ) );
		$r      = self::match_now( self::transfer( 3 * self::DAY ), $orders );
		$this->assertSame( self::answer( 'matched', 'A' ), self::reply( $r ) );
		self::apply( $orders, $r );
		$this->assertSame( 'open', $orders[1]['status'] );
	}

	/** Core test 9. */
	public function test_core_late_transfer_the_order_of_that_time_had_expired_less_than_2_hours_before_the_money_paid_after_expiry() {
		$this->assertSame(
			array(
				'status'    => 'matched',
				'reference' => 'A',
				'note'      => 'paid after expiry',
			),
			self::reply( self::match_now( self::transfer( 3 * self::DAY ), array( self::old_order( 'A', 3 * self::DAY + 3 * self::HOUR, 2 * self::HOUR ) ) ) )
		);
	}

	/** Core test 10. */
	public function test_core_late_transfer_the_order_of_that_time_had_expired_more_than_2_hours_before_the_money_review() {
		$r = self::match_now( self::transfer( 3 * self::DAY ), array( self::old_order( 'A', 3 * self::DAY + 6 * self::HOUR, 2 * self::HOUR ) ) );
		$this->assertSame( 'review', $r['status'] );
		$this->assertNull( $r['action'] );
	}

	/** Core test 11. */
	public function test_core_an_order_opened_a_few_minutes_after_the_money_clock_difference_still_takes_a_late_delivery() {
		$this->assertSame(
			self::answer( 'matched', 'A' ),
			self::reply( self::match_now( self::transfer( 5 * self::HOUR ), array( self::old_order( 'A', 5 * self::HOUR - 5 * self::MIN, 2 * self::HOUR ) ) ) )
		);
	}

	/** Core test 12: `undefined` is a missing key here, and null too. */
	public function test_core_received_at_in_the_future_missing_or_unreadable_counts_as_now() {
		foreach ( array( Time::sec_to_iso( self::NOW + 3 * self::HOUR ), null, 'not a date' ) as $received_at ) {
			$this->assertSame(
				self::answer( 'matched', 'B' ),
				self::reply( self::match_now( self::transfer( 0, 15000, array( 'received_at' => $received_at ) ), array( self::open_order( 'B' ) ) ) )
			);
		}
		$missing = self::transfer( 0 );
		unset( $missing['received_at'] );
		$this->assertSame( self::answer( 'matched', 'B' ), self::reply( self::match_now( $missing, array( self::open_order( 'B' ) ) ) ) );
	}

	/** Core test 13. */
	public function test_core_received_at_with_the_numeric_offset_the_phone_sends_is_read() {
		$r = self::match_now( self::transfer( 0, 15000, array( 'received_at' => '2026-10-11T16:36:41+03:00' ) ), array( self::open_order( 'B' ) ) );
		$this->assertSame( 'review', $r['status'] );
		$this->assertNull( $r['action'] );
	}

	/** Core test 14. */
	public function test_core_no_order_partial_and_overpaid_answers_are_unchanged_for_a_transfer_that_just_arrived() {
		$this->assertSame( array( 'status' => 'unmatched' ), self::reply( self::match_now( self::transfer( 1 ), array() ) ) );
		$orders  = array( self::open_order( 'B', 20000 ) );
		$partial = self::match_now( self::transfer( 1, 15000 ), $orders );
		$this->assertSame( self::answer( 'partial', 'B' ), self::reply( $partial ) );
		$this->assertSame( 'mark_partial', $partial['action'] );
		self::apply( $orders, $partial ); // 15000 of 20000 paid: 5000 remain
		$this->assertSame( 'partial', $orders[0]['status'] );
		$this->assertSame( self::answer( 'overpaid', 'B' ), self::reply( self::match_now( self::transfer( 1, 9000 ), $orders ) ) );
	}

	/** Core test 15. */
	public function test_core_auto_confirm_ok_false_is_always_review() {
		$r = self::match_now( self::transfer( 1, 15000, array( 'auto_confirm_ok' => false ) ), array( self::open_order( 'B' ) ) );
		$this->assertSame( array( 'status' => 'review' ), self::reply( $r ) );
		$this->assertNull( $r['action'] );
	}

	/** Core test 16. */
	public function test_core_transfer_updated_with_suspected_forgery_stops_the_order_that_transfer_paid_and_is_answered_ok() {
		$this->assertSame(
			array(
				'status'      => 'ok',
				'transfer_id' => self::TRANSFER_ID,
				'stop_order'  => true,
				'reason'      => 'suspected_forgery',
			),
			Matcher::judge_update( self::update() )
		);
	}

	/** Core test 17. */
	public function test_core_transfer_updated_with_balance_chain_refuted_alone_also_stops_the_order() {
		$this->assertTrue( Matcher::judge_update( self::update( array( 'risk_reasons' => array( 'balance_inconsistent' ) ) ) )['stop_order'] );
	}

	/** Core test 18. */
	public function test_core_other_transfer_updated_late_proof_merchant_confirmed_change_nothing_and_are_answered_ok() {
		foreach ( array( 'late_consistent', 'merchant_confirmed' ) as $chain ) {
			$extra = array(
				'checks'          => array( 'balance_chain' => $chain ),
				'risk'            => 'low',
				'risk_reasons'    => array(),
				'auto_confirm_ok' => true,
			);
			$this->assertSame(
				array(
					'status'      => 'ok',
					'transfer_id' => self::TRANSFER_ID,
					'stop_order'  => false,
					'reason'      => null,
				),
				Matcher::judge_update( self::update( $extra ) )
			);
		}
	}

	/** Core test 19. */
	public function test_core_transfer_updated_with_suspected_forgery_for_a_transfer_that_paid_nothing_is_still_a_stop_signal() {
		$this->assertTrue( Matcher::judge_update( self::update() )['stop_order'] );
		$this->assertFalse( Matcher::judge_update( null )['stop_order'] );
	}

	/** Core test 20. */
	public function test_core_a_masked_payer_number_falls_through_to_the_handle_and_no_identifier_at_all_is_unmatched() {
		$masked = self::transfer(
			1,
			15000,
			array(
				'counterparty' => array(
					'number' => '0111***3445',
					'name'   => 'Customer',
					'handle' => 'shop@instapay',
				),
			)
		);
		$this->assertSame( self::answer( 'matched', 'H' ), self::reply( self::match_now( $masked, array( self::open_order( 'H', 15000, 'shop@instapay' ) ) ) ) );
		$none = self::match_now(
			self::transfer(
				1,
				15000,
				array(
					'counterparty' => array(
						'number' => '0111***3445',
						'name'   => 'Customer',
						'handle' => null,
					),
				)
			),
			array( self::open_order( 'B' ) )
		);
		$this->assertSame( 'unmatched', $none['status'] );
		$this->assertNull( $none['payer_key'] );
	}

	/** Core test 21. */
	public function test_core_the_summarised_item_shape_and_iso_order_times_are_accepted_other_payers_and_closed_orders_are_skipped() {
		$summarised = array(
			'transfer_id'     => self::TRANSFER_ID,
			'auto_confirm_ok' => true,
			'amount_minor'    => 15000,
			'received_at'     => Time::sec_to_iso( self::NOW - 1 ),
			'payer'           => array(
				'number' => '+20 101 234 5678',
				'handle' => null,
				'name'   => 'C',
				'key'    => self::PAYER,
			),
		);
		$other              = self::open_order( 'OTHER' );
		$other['payer_key'] = '01099999999';
		$paid               = self::open_order( 'PAID' );
		$paid['status']     = 'paid';
		$orders             = array(
			$other,
			$paid,
			array(
				'order_number' => 'ISO',
				'price_minor'  => 15000,
				'payer_key'    => '0101 234 5678',
				'created_at'   => Time::sec_to_iso( self::NOW - 5 * self::MIN ),
				'expires_at'   => Time::sec_to_iso( self::NOW + 60 * self::MIN ),
			),
		);
		$this->assertSame( self::answer( 'matched', 'ISO' ), self::reply( self::match_now( $summarised, $orders ) ) );
	}

	/** Core test 22. */
	public function test_core_a_transfer_without_an_integer_amount_is_review_never_a_partial_payment_of_0() {
		$this->assertSame( 'review', self::match_now( self::transfer( 1, null ), array( self::open_order( 'B' ) ) )['status'] );
	}

	/**
	 * Core test 23 ("hostile candidate lists never throw"). Two of its inputs differ in PHP: candidates that are not
	 * an array are a TypeError from the signature (CONTRACTS.md §6 types them `array`; match.ts reads them as no
	 * candidates), and `$now` is an int, so a NaN clock is the real clock here. Array input never throws.
	 */
	public function test_core_hostile_candidate_lists() {
		foreach ( array( 'nope', null ) as $not_a_list ) {
			try {
				Matcher::match( self::transfer( 1 ), $not_a_list, self::NOW ); // @phpstan-ignore-line
				$this->fail( 'a non-array candidate list must be refused by the signature' );
			} catch ( \TypeError $e ) {
				$this->assertStringContainsString( 'array', $e->getMessage() );
			}
		}
		$this->assertSame( 'unmatched', self::match_now( self::transfer( 1 ), array( null, 42 ) )['status'] );
		// an order of THIS payer whose price is a string is never matched and turns the miss into review
		$string_price                = self::open_order( 'S' );
		$string_price['price_minor'] = '15000';
		$r                           = self::match_now( self::transfer( 1 ), array( $string_price ) );
		$this->assertSame( 'review', $r['status'] );
		$this->assertNull( $r['action'] );
		$this->assertStringContainsString( 'could not be read', (string) $r['note'] );
		$no_price = self::open_order( 'S' );
		unset( $no_price['price_minor'] );
		$this->assertSame( 'review', self::match_now( self::transfer( 1 ), array( $no_price ) )['status'] );
		$null_price                = self::open_order( 'S' );
		$null_price['price_minor'] = null;
		$this->assertSame( 'review', self::match_now( self::transfer( 1 ), array( $null_price ) )['status'] );
		// unreadable times of this payer: review, not unmatched
		$bad_time               = self::open_order( 'T' );
		$bad_time['created_at'] = 'yesterday';
		$this->assertSame( 'review', self::match_now( self::transfer( 1 ), array( $bad_time ) )['status'] );
		// another payer's unreadable order changes nothing
		$other                = self::open_order( 'X', 15000, '01099999999' );
		$other['price_minor'] = 'x';
		$this->assertSame( self::answer( 'matched', 'B' ), self::reply( self::match_now( self::transfer( 1 ), array( self::open_order( 'B' ), $other ) ) ) );
		// DateTimeInterface values (JavaScript's Date objects) and the real clock are accepted
		$dated = array(
			'order_number' => 'D',
			'price_minor'  => 15000,
			'payer_key'    => self::PAYER,
			'created_at'   => new \DateTimeImmutable( '@' . ( self::NOW - 5 * self::MIN ) ),
			'expires_at'   => new \DateTimeImmutable( '@' . ( self::NOW + self::HOUR ) ),
		);
		$this->assertSame( self::answer( 'matched', 'D' ), self::reply( Matcher::match( self::transfer( 1 ), array( $dated ), self::NOW ) ) );
		$clock               = time();
		$dated['created_at'] = $clock - self::MIN;
		$dated['expires_at'] = $clock + self::HOUR;
		$this->assertSame( 'matched', Matcher::match( array_merge( self::transfer( 0 ), array( 'received_at' => Time::sec_to_iso( $clock ) ) ), array( $dated ), $clock )['status'] );
		// a readable candidate next to an unreadable one of the same payer is not taken on the single-candidate rule
		$unreadable                = self::open_order( 'U', 20000 );
		$unreadable['price_minor'] = 'x';
		$this->assertSame( 'review', self::match_now( self::transfer( 1, 9000 ), array( self::open_order( 'A', 20000 ), $unreadable ) )['status'] );
	}

	/*
	 * The PHP port's own rules.
	 */

	/** The result has every MatchResult key, in the order the core's object spreads give them. */
	public function test_result_keys_and_order_match_the_core() {
		$this->assertSame(
			array(
				'order_number'    => 'B',
				'action'          => 'mark_paid',
				'amount_minor'    => 15000,
				'transfer_id'     => self::TRANSFER_ID,
				'provider_txn_id' => '099409626954',
				'payer_key'       => self::PAYER,
				'stop_order'      => false,
				'reference'       => 'B',
				'status'          => 'matched',
			),
			self::match_now( self::transfer( 10 ), array( self::open_order( 'B' ) ) )
		);
		$this->assertSame(
			array(
				'status'          => 'review',
				'note'            => 'no transfer data',
				'order_number'    => null,
				'action'          => null,
				'amount_minor'    => null,
				'transfer_id'     => null,
				'provider_txn_id' => null,
				'payer_key'       => null,
				'stop_order'      => false,
			),
			self::match_now( 'not a transfer', array() )
		);
		$this->assertSame(
			array(
				'status'          => 'unmatched',
				'note'            => 'no payer identifier in the message',
				'order_number'    => null,
				'action'          => null,
				'amount_minor'    => 15000,
				'transfer_id'     => self::TRANSFER_ID,
				'provider_txn_id' => '099409626954',
				'payer_key'       => null,
				'stop_order'      => false,
			),
			self::match_now( self::transfer( 1, 15000, array( 'counterparty' => null ) ), array() )
		);
		$expired = self::match_now( self::transfer( 3 * self::DAY ), array( self::old_order( 'A', 3 * self::DAY + 3 * self::HOUR, 2 * self::HOUR ) ) );
		$this->assertSame( array( 'order_number', 'action', 'amount_minor', 'transfer_id', 'provider_txn_id', 'payer_key', 'stop_order', 'reference', 'status', 'note' ), array_keys( $expired ) );
		$partial = self::match_now( self::transfer( 1, 100 ), array( self::open_order( 'B' ) ) );
		$this->assertSame( array( 'order_number', 'action', 'amount_minor', 'transfer_id', 'provider_txn_id', 'payer_key', 'stop_order', 'reference', 'status' ), array_keys( $partial ) );
	}

	/** Every note string of the core, exactly. */
	public function test_notes_are_the_core_strings() {
		$this->assertSame( 'no amount in the message', self::match_now( self::transfer( 1, -1 ), array() )['note'] );
		$this->assertSame( 'late delivery: no order of this payer was open when the money arrived', self::match_now( self::transfer( 3 * self::DAY ), array() )['note'] );
		$bad                = self::open_order( 'U' );
		$bad['expires_at']  = null;
		$bad2               = self::open_order( 'V' );
		$bad2['paid_minor'] = -1;
		$this->assertSame( '2 order(s) of this payer could not be read (price or times)', self::match_now( self::transfer( 1 ), array( $bad, $bad2 ) )['note'] );
		$this->assertSame( 'another order of this payer could not be read', self::match_now( self::transfer( 1, 9000 ), array( self::open_order( 'A', 20000 ), $bad ) )['note'] );
		$this->assertSame( 'several open orders for this payer, none matches the amount', self::match_now( self::transfer( 1, 9000 ), array( self::open_order( 'A', 20000 ), self::open_order( 'B', 30000 ) ) )['note'] );
		$this->assertArrayNotHasKey( 'note', self::match_now( self::transfer( 1 ), array() ) );
	}

	/** The plugin passes skew_min = 0 (the owner's decision): an order opened after the money never takes it. */
	public function test_skew_min_zero_never_takes_an_order_opened_after_the_money() {
		$options = array(
			'late_window_min' => 120,
			'skew_min'        => 0,
		);
		$late    = Matcher::match( self::transfer( 5 * self::HOUR ), array( self::old_order( 'A', 5 * self::HOUR - 5 * self::MIN, 2 * self::HOUR ) ), self::NOW, $options );
		$this->assertSame( 'review', $late['status'] );
		$this->assertNull( $late['action'] );
		$fresh = Matcher::match( self::transfer( 60 ), array( self::old_order( 'A', 59, 2 * self::HOUR ) ), self::NOW, $options );
		$this->assertSame( 'unmatched', $fresh['status'] );
		$same_second = Matcher::match( self::transfer( 60 ), array( self::old_order( 'A', 60, 2 * self::HOUR ) ), self::NOW, $options );
		$this->assertSame( 'matched', $same_second['status'] );
	}

	/** The late window comes from the options, in minutes; an invalid option falls back to the core default. */
	public function test_windows_come_from_the_options_in_minutes() {
		$expired_40_min_before = array( self::old_order( 'A', 2 * self::HOUR + 40 * self::MIN, 2 * self::HOUR ) );
		$this->assertSame( 'matched', Matcher::match( self::transfer( 0 ), $expired_40_min_before, self::NOW, array( 'late_window_min' => 60 ) )['status'] );
		$this->assertSame( 'unmatched', Matcher::match( self::transfer( 0 ), $expired_40_min_before, self::NOW, array( 'late_window_min' => 30 ) )['status'] );
		$this->assertSame( 'matched', Matcher::match( self::transfer( 0 ), $expired_40_min_before, self::NOW, array( 'late_window_min' => 40 ) )['status'] );
		$this->assertSame( 'matched', Matcher::match( self::transfer( 0 ), $expired_40_min_before, self::NOW, array( 'late_window_min' => -1 ) )['status'] );
		$this->assertSame( 'matched', Matcher::match( self::transfer( 0 ), $expired_40_min_before, self::NOW, array( 'late_window_min' => '30' ) )['status'] );
		$this->assertSame( 'unmatched', Matcher::match( self::transfer( 0 ), $expired_40_min_before, self::NOW, array( 'late_window_min' => 39.5 ) )['status'] );
		// a transfer 31 minutes old is stale with a 30-minute window: review instead of unmatched
		$this->assertSame( 'review', Matcher::match( self::transfer( 31 * self::MIN ), array(), self::NOW, array( 'late_window_min' => 30 ) )['status'] );
		$this->assertSame( 'unmatched', Matcher::match( self::transfer( 30 * self::MIN ), array(), self::NOW, array( 'late_window_min' => 30 ) )['status'] );
	}

	/** "Integer" is Number.isInteger(): a float without a fraction counts, a numeric string never does. */
	public function test_integers_follow_number_is_integer() {
		$this->assertSame( 'matched', self::match_now( self::transfer( 1, 1.5e4 ), array( self::open_order( 'B' ) ) )['status'] );
		$this->assertSame( 15000, self::match_now( self::transfer( 1, 15000.0 ), array() )['amount_minor'] );
		$this->assertSame( 'review', self::match_now( self::transfer( 1, 15000.5 ), array( self::open_order( 'B' ) ) )['status'] );
		$this->assertSame( 'review', self::match_now( self::transfer( 1, '15000' ), array( self::open_order( 'B' ) ) )['status'] );
		$this->assertSame( 'review', self::match_now( self::transfer( 1, true ), array( self::open_order( 'B' ) ) )['status'] );
		$this->assertSame( 'review', self::match_now( self::transfer( 1, 1e300 ), array( self::open_order( 'B' ) ) )['status'] );
		$float_price                = self::open_order( 'F' );
		$float_price['price_minor'] = 15000.0;
		$this->assertSame( 'matched', self::match_now( self::transfer( 1 ), array( $float_price ) )['status'] );
		$this->assertSame( 0, self::match_now( self::transfer( 1, 0 ), array() )['amount_minor'] );
	}

	/** Values as $wpdb returns them (strings) are unreadable: the caller casts price, paid and times to int. */
	public function test_database_strings_are_unreadable() {
		$row = array(
			'order_number' => '1234',
			'price_minor'  => '15000',
			'paid_minor'   => '0',
			'payer_key'    => self::PAYER,
			'created_at'   => (string) self::NOW,
			'expires_at'   => (string) ( self::NOW + self::HOUR ),
			'status'       => 'open',
		);
		$this->assertSame( 'review', self::match_now( self::transfer( 1 ), array( $row ) )['status'] );
		$cast = array_merge(
			$row,
			array(
				'price_minor' => (int) $row['price_minor'],
				'paid_minor'  => (int) $row['paid_minor'],
				'created_at'  => (int) $row['created_at'],
				'expires_at'  => (int) $row['expires_at'],
			)
		);
		$this->assertSame( self::answer( 'matched', '1234' ), self::reply( self::match_now( self::transfer( 1 ), array( $cast ) ) ) );
		$numeric_number                 = $cast;
		$numeric_number['order_number'] = 1234;
		$this->assertSame( 'review', self::match_now( self::transfer( 1 ), array( $numeric_number ) )['status'] );
	}

	/**
	 * Candidates keyed by order id are read by value; ties in created_at keep the input order (a stable sort).
	 *
	 * Only PHP 7.4 can show a missing tie-break: usort is stable from PHP 8.0. On 7.4, zend_sort uses insertion sort
	 * for 16 elements or fewer, which never moves equal elements, and its quicksort path for more never moves the
	 * element at index 0 when every comparison is 0. So these lists have more than 16 ties and their first exact
	 * match is not at index 0: without the tie-break, 7.4 picks the second exact match, T15 of 17 or T30 of 40 (from
	 * an emulation of PHP 7.4's zend_sort.c). CI must run this suite on PHP 7.4 too.
	 */
	public function test_keyed_candidates_and_stable_ties() {
		$keyed = array(
			901 => self::open_order( 'A', 20000 ),
			17  => self::open_order( 'B', 15000 ),
		);
		$this->assertSame( 'B', self::match_now( self::transfer( 1 ), $keyed )['reference'] );
		foreach ( array( array( 17, 2, 15 ), array( 40, 3, 30 ) ) as $case ) {
			list( $count, $first, $second ) = $case;
			$ties                           = array();
			for ( $i = 0; $i < $count; $i++ ) {
				$ties[] = self::open_order( 'T' . $i, $first === $i || $second === $i ? 15000 : 20000 ); // same created_at
			}
			$this->assertSame( 'T' . $first, self::match_now( self::transfer( 1 ), $ties )['reference'], $count . ' ties' );
		}
		$newer               = self::open_order( 'NEW' );
		$newer['created_at'] = self::NOW - 1;
		$older               = self::open_order( 'OLD' );
		$older['created_at'] = self::NOW - 2;
		$this->assertSame( 'NEW', self::match_now( self::transfer( 1 ), array( $older, $newer ) )['reference'] );
	}

	/** received_at: ISO with Z or an offset; an int is seconds; an offset-less date-time counts as now. */
	public function test_received_at_forms() {
		$order_then = array( self::old_order( 'A', 3 * self::DAY + 20 * self::MIN, 2 * self::HOUR ) );
		$then       = self::NOW - 3 * self::DAY;
		$this->assertSame( 'matched', self::match_now( self::transfer( 0, 15000, array( 'received_at' => Time::sec_to_iso( $then ) ) ), $order_then )['status'] );
		$this->assertSame( 'matched', self::match_now( self::transfer( 0, 15000, array( 'received_at' => gmdate( 'Y-m-d\TH:i:s', $then + 3 * self::HOUR ) . '+03:00' ) ), $order_then )['status'] );
		$this->assertSame( 'matched', self::match_now( self::transfer( 0, 15000, array( 'received_at' => $then ) ), $order_then )['status'] );
		// without an offset the time is unreadable, so the money counts as now and the old order is out of the window
		$this->assertSame( 'unmatched', self::match_now( self::transfer( 0, 15000, array( 'received_at' => gmdate( 'Y-m-d\TH:i:s', $then ) ) ), $order_then )['status'] );
		// the money time equal to now is now
		$this->assertSame( 'unmatched', self::match_now( self::transfer( 0, 15000, array( 'received_at' => self::NOW ) ), array() )['status'] );
	}

	/**
	 * The unsafe side of "unreadable = now", pinned so it is not changed by accident. A backfill whose received_at
	 * has no offset (3 days old) looks fresh and pays an order opened today, even with skew_min 0. match.ts (UTC
	 * server) and DateTimeImmutable (docs/17 §4.6) read it as 3 days old, so the core gives review. The conflict
	 * between docs/17 and CONTRACTS.md §1 is reported; the guard is MatchPolicy's (present but unreadable → review),
	 * because the matcher must keep `not a date` = now for kit check 12.
	 */
	public function test_received_at_without_offset_counts_as_now() {
		$options    = array(
			'late_window_min' => 120,
			'skew_min'        => 0,
		);
		$opened_now = array( self::old_order( 'B', 0, 2 * self::HOUR ) );
		$backfill   = self::transfer( 0, 15000, array( 'received_at' => gmdate( 'Y-m-d\TH:i:s', self::NOW - 3 * self::DAY ) ) );
		$this->assertSame( 'matched', Matcher::match( $backfill, $opened_now, self::NOW, $options )['status'] );
		$with_offset = self::transfer( 3 * self::DAY );
		$this->assertSame( 'review', Matcher::match( $with_offset, $opened_now, self::NOW, $options )['status'] );
	}

	/** The summarised payer.key wins over the counterparty; a bad key falls back to the counterparty, then the payer. */
	public function test_payer_key_precedence() {
		$transfer = self::transfer(
			1,
			15000,
			array(
				'payer'        => array( 'key' => 'Shop@InstaPay' ),
				'counterparty' => array( 'number' => self::PAYER ),
			)
		);
		$this->assertSame( 'shop@instapay', self::match_now( $transfer, array() )['payer_key'] );
		$transfer['payer'] = array(
			'key'    => '0111***3445',
			'number' => '01099999999',
		);
		$this->assertSame( self::PAYER, self::match_now( $transfer, array() )['payer_key'] );
		unset( $transfer['counterparty'] );
		$this->assertSame( '01099999999', self::match_now( $transfer, array() )['payer_key'] );
		$transfer['payer'] = array( 'key' => 5 );
		$this->assertNull( self::match_now( $transfer, array() )['payer_key'] );
		$list                 = self::transfer( 1 );
		$list['counterparty'] = array( self::PAYER );
		$this->assertSame( 'unmatched', self::match_now( $list, array() )['status'] );
		// an array-valued number is read as String() reads it, as in the core: ['01012345678'] matches B
		// (see PayerTest::test_arrays_and_objects); the schema types it a string.
		$array_number                 = self::transfer( 1 );
		$array_number['counterparty'] = array( 'number' => array( self::PAYER ) );
		$r                            = self::match_now( $array_number, array( self::open_order( 'B' ) ) );
		$this->assertSame( 'matched', $r['status'] );
		$this->assertSame( self::PAYER, $r['payer_key'] );
	}

	/**
	 * The hand-off to MatchPolicy for blocking orders (docs/17 §4.6 rule 3): status is read before readability, so a
	 * blocker counts only when it is passed as `open` (or without a status) with an unreadable price. Passed with its
	 * real state, it is skipped and the transfer part-pays the other order. And an unreadable order counts whatever
	 * its times, so the caller passes only the rows of the window.
	 */
	public function test_blocking_orders_must_be_passed_open_with_no_price() {
		$options = array(
			'late_window_min' => 120,
			'skew_min'        => 0,
		);
		$open_a  = self::old_order( 'A', self::MIN, 2 * self::HOUR, 20000 );
		$blocker = array(
			'order_number' => '1234',
			'price_minor'  => null,
			'payer_key'    => self::PAYER,
			'created_at'   => self::NOW - 30 * self::MIN,
			'expires_at'   => self::NOW + self::HOUR,
		);
		foreach ( array( 'closed', 'review', 'processing', 'completed', 'paid' ) as $state ) {
			$wrong = array_merge( $blocker, array( 'status' => $state ) );
			$this->assertSame( 'partial', Matcher::match( self::transfer( 1, 9000 ), array( $open_a, $wrong ), self::NOW, $options )['status'], $state );
		}
		foreach ( array( array( 'status' => 'open' ), array() ) as $status ) {
			$right = array_merge( $blocker, $status );
			$r     = Matcher::match( self::transfer( 1, 9000 ), array( $open_a, $right ), self::NOW, $options );
			$this->assertSame( 'review', $r['status'] );
			$this->assertSame( 'another order of this payer could not be read', $r['note'] );
			$alone = Matcher::match( self::transfer( 1, 9000 ), array( $right ), self::NOW, $options );
			$this->assertSame( 'review', $alone['status'] ); // not unmatched
			$this->assertSame( '1 order(s) of this payer could not be read (price or times)', $alone['note'] );
			// the exact amount for another open order still decides
			$this->assertSame( self::answer( 'matched', 'A' ), self::reply( Matcher::match( self::transfer( 1, 20000 ), array( $open_a, $right ), self::NOW, $options ) ) );
		}
		// the int order_id of a row is unreadable as order_number: cast it to a string
		$int_number                 = $open_a;
		$int_number['order_number'] = 1234;
		$this->assertSame( 'review', Matcher::match( self::transfer( 1, 20000 ), array( $int_number ), self::NOW, $options )['status'] );
		// a blocker long gone still blocks: the window check applies only to readable orders
		$gone = array_merge(
			$blocker,
			array(
				'created_at' => self::NOW - 10 * self::DAY,
				'expires_at' => self::NOW - 9 * self::DAY,
			)
		);
		$this->assertSame( 'review', Matcher::match( self::transfer( 1, 9000 ), array( $open_a, $gone ), self::NOW, $options )['status'] );
	}

	/** Statuses: missing is open, open and partial are candidates, anything else (null included) is skipped. */
	public function test_candidate_statuses() {
		foreach ( array( 'open', 'partial' ) as $status ) {
			$o           = self::open_order( 'B' );
			$o['status'] = $status;
			$this->assertSame( 'matched', self::match_now( self::transfer( 1 ), array( $o ) )['status'] );
		}
		$o = self::open_order( 'B' );
		unset( $o['status'] );
		$this->assertSame( 'matched', self::match_now( self::transfer( 1 ), array( $o ) )['status'] );
		foreach ( array( null, 'paid', 'review', 'expired', 'OPEN' ) as $status ) {
			$o           = self::open_order( 'B' );
			$o['status'] = $status;
			$this->assertSame( 'unmatched', self::match_now( self::transfer( 1 ), array( $o ) )['status'] );
		}
		$paid_more               = self::open_order( 'B' );
		$paid_more['paid_minor'] = 20000; // more than the price: what remains is negative, so any amount overpays
		$paid_more['status']     = 'partial';
		$this->assertSame( 'overpaid', self::match_now( self::transfer( 1, 100 ), array( $paid_more ) )['status'] );
	}

	/** judge_update() reads only well-shaped fields and never throws. */
	public function test_judge_update_shapes() {
		$this->assertTrue( Matcher::judge_update( array( 'suspected_forgery' => true ) )['stop_order'] );
		$this->assertFalse( Matcher::judge_update( array( 'suspected_forgery' => 'true' ) )['stop_order'] );
		$this->assertTrue( Matcher::judge_update( array( 'balance_chain' => 'refuted' ) )['stop_order'] );
		$this->assertFalse( Matcher::judge_update( array( 'risk_reasons' => 'suspected_forgery' ) )['stop_order'] );
		$this->assertFalse( Matcher::judge_update( array( 'risk_reasons' => array( 'x' => 'suspected_forgery' ) ) )['stop_order'] );
		$this->assertFalse( Matcher::judge_update( array( 'checks' => array( 'refuted' ) ) )['stop_order'] );
		$this->assertFalse( Matcher::judge_update( array( 'checks' => null ) )['stop_order'] );
		$this->assertNull( Matcher::judge_update( array( 'transfer_id' => 5 ) )['transfer_id'] );
		foreach ( array( null, 'x', 42, array(), array( 1, 2 ), new \stdClass() ) as $data ) {
			$this->assertSame(
				array(
					'status'      => 'ok',
					'transfer_id' => null,
					'stop_order'  => false,
					'reason'      => null,
				),
				Matcher::judge_update( $data )
			);
		}
	}
}
