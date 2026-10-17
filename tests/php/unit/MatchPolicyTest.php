<?php
/**
 * Tests of MatchPolicy: the store's rules around the literal Matcher (docs/17 §4.6), outside parity.
 *
 * @package QershPay\WooCommerce\Tests
 */

namespace QershPay\WooCommerce\Tests\Unit;

use QershPay\WooCommerce\Claims;
use QershPay\WooCommerce\Core\Time;
use QershPay\WooCommerce\Expiry;
use QershPay\WooCommerce\MatchPolicy;
use QershPay\WooCommerce\Orders;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

defined( 'ABSPATH' ) || exit;

/**
 * Rules 1–8, rule 4a with its six gates, the personal tag, the advice, and the small pure helpers of Orders,
 * Claims and Expiry.
 */
final class MatchPolicyTest extends TestCase {

	/** The store's clock. */
	const NOW = 1791800000;

	/** The money arrived ten minutes ago. */
	const MONEY = 1791799400;

	/** The wallet the money reached. */
	const WALLET = 'wal000000001';

	/** Another wallet of the store. */
	const OTHER_WALLET = 'wal000000002';

	/** A payer. */
	const PAYER = '01112233445';

	/** A transaction id of 14 digits. */
	const TXN = '25005172944183';

	/**
	 * The options of the plugin's defaults.
	 *
	 * @param array $over Changes.
	 * @return array
	 */
	private function cfg( array $over = array() ): array {
		return array_merge(
			array(
				'late_window_min'   => 120,
				'skew_min'          => 0,
				'round_up_to_pound' => true,
				'txn_entry'         => true,
				'order_ttl_min'     => 120,
			),
			$over
		);
	}

	/**
	 * Envelope data of a transfer.received.
	 *
	 * @param array $over Changes (null removes a key).
	 * @return array
	 */
	private function data( array $over = array() ): array {
		$data = array(
			'transfer_id'     => 'trf_1',
			'direction'       => 'in',
			'status'          => 'completed',
			'amount_minor'    => 14000,
			'currency'        => 'EGP',
			'provider_txn_id' => '099409626954',
			'received_at'     => Time::sec_to_iso( self::MONEY ),
			'counterparty'    => array(
				'number' => self::PAYER,
				'name'   => 'Assaad K Mansouri',
			),
			'channel'         => 'sms',
			'checks'          => array( 'balance_chain' => 'consistent' ),
			'risk'            => 'low',
			'risk_reasons'    => array(),
			'auto_confirm_ok' => true,
		);
		foreach ( $over as $key => $value ) {
			if ( null === $value ) {
				unset( $data[ $key ] );
			} else {
				$data[ $key ] = $value;
			}
		}
		return $data;
	}

	/**
	 * A normalised transfer.
	 *
	 * @param array  $over     Data changes.
	 * @param string $provider Provider.
	 * @return array
	 */
	private function transfer( array $over = array(), string $provider = 'vodafone_cash' ): array {
		$t = MatchPolicy::read_transfer( $this->data( $over ), self::WALLET, $provider, self::NOW );
		$this->assertIsArray( $t );
		return $t;
	}

	/**
	 * An Orange transfer without a payer, with the transaction id.
	 *
	 * @param array $over Data changes.
	 * @return array
	 */
	private function orange( array $over = array() ): array {
		return $this->transfer(
			array_merge(
				array(
					'counterparty'    => array( 'name' => 'Mona' ),
					'provider_txn_id' => self::TXN,
					'sms_timestamp'   => Time::sec_to_iso( self::MONEY - 5 ),
				),
				$over
			),
			'orange_cash'
		);
	}

	/**
	 * A shaped qersh_orders row.
	 *
	 * @param int   $id   Order id.
	 * @param array $over Changes.
	 * @return array
	 */
	private function row( int $id, array $over = array() ): array {
		return array_merge(
			array(
				'order_id'    => $id,
				'wallet'      => self::WALLET,
				'payer_key'   => self::PAYER,
				'price_minor' => 14000,
				'paid_minor'  => 0,
				'state'       => 'open',
				'created_at'  => self::MONEY - 1800,
				'expires_at'  => self::MONEY + 5400,
				'paid_at'     => null,
				'completion'  => 'none',
				'claim_txn'   => null,
				'claim_at'    => null,
				'claim_tries' => 0,
				'kind'        => 'normal',
			),
			$over
		);
	}

	/**
	 * A claimant row on the Orange wallet.
	 *
	 * @param int   $id   Order id.
	 * @param array $over Changes.
	 * @return array
	 */
	private function claimant( int $id, array $over = array() ): array {
		return $this->row(
			$id,
			array_merge(
				array(
					'payer_key' => '01011112222',
					'claim_txn' => self::TXN,
					'claim_at'  => self::MONEY + 60,
					'provider'  => 'orange_cash',
				),
				$over
			)
		);
	}

	/**
	 * A decision context.
	 *
	 * @param array $over Changes.
	 * @return array
	 */
	private function ctx( array $over = array() ): array {
		return array_merge(
			array(
				'now'         => self::NOW,
				'config'      => $this->cfg(),
				'duplicates'  => array(),
				'payer_rows'  => array(),
				'wallet_rows' => array(),
				'claimants'   => array(),
			),
			$over
		);
	}

	// ----- Reading the transfer -----------------------------------------------------------------------------------

	/** The envelope data become the normalised transfer. */
	public function test_read_transfer() {
		$t = $this->transfer();
		$this->assertSame( 'trf_1', $t['transfer_id'] );
		$this->assertSame( self::PAYER, $t['payer_key'] );
		$this->assertSame( 14000, $t['amount_minor'] );
		$this->assertSame( self::MONEY, $t['money_at'] );
		$this->assertTrue( $t['time_ok'] );
		$this->assertNull( $t['txn'], 'Vodafone ids are never claim ids' );
		$this->assertSame( '099409626954', $t['txn_stored'], 'stored as it came' );
		$this->assertFalse( $t['masked'] );
		$this->assertNull( MatchPolicy::read_transfer( $this->data( array( 'transfer_id' => null ) ), self::WALLET, 'vodafone_cash', self::NOW ) );
		$this->assertNull( MatchPolicy::read_transfer( $this->data( array( 'transfer_id' => str_repeat( 'x', 41 ) ) ), self::WALLET, 'vodafone_cash', self::NOW ) );
	}

	/** A future or missing money time is now; a present but unreadable one is not ok. */
	public function test_read_transfer_times() {
		$this->assertSame( self::NOW, $this->transfer( array( 'received_at' => Time::sec_to_iso( self::NOW + 900 ) ) )['money_at'] );
		$missing = $this->transfer( array( 'received_at' => null ) );
		$this->assertSame( self::NOW, $missing['money_at'] );
		$this->assertTrue( $missing['time_ok'] );
		$this->assertFalse( $this->transfer( array( 'received_at' => '2027-01-12T08:00:00' ) )['time_ok'] );
	}

	/** Orange: the canonical id is the claim id; a masked sender is noted; a drifted id never is a claim id. */
	public function test_read_orange_transfer() {
		$t = $this->orange();
		$this->assertNull( $t['payer_key'] );
		$this->assertSame( self::TXN, $t['txn'] );
		$masked = $this->transfer( array( 'counterparty' => array( 'number' => '0111***3445' ) ) );
		$this->assertTrue( $masked['masked'] );
		$this->assertNull( $masked['payer_key'] );
		$drift = $this->orange( array( 'provider_txn_id' => '25005172942' ) );
		$this->assertNull( $drift['txn'] );
		$this->assertSame( '25005172942', $drift['txn_stored'] );
	}

	// ----- Rules 1 and 2 -------------------------------------------------------------------------------------------

	/** Rule 1: an unknown risk reason goes to review even with auto_confirm_ok. */
	public function test_unknown_risk_reason() {
		$d = MatchPolicy::decide( $this->transfer( array( 'risk_reasons' => array( 'brand_new_reason' ) ) ), $this->ctx( array( 'payer_rows' => array( $this->row( 10 ) ) ) ) );
		$this->assertSame( 'review', $d['outcome'] );
		$this->assertSame( 'unknown_risk', $d['reason'] );
		$this->assertSame( 'brand_new_reason', $d['args']['values'] );
		$this->assertNull( $d['order_id'] );
	}

	/** Rule 2: the same id from another transfer with a verdict is a duplicate, with the order it paid. */
	public function test_duplicate_transaction_id() {
		$dup = array(
			'transfer_id'     => 'trf_0',
			'provider'        => 'vodafone_cash',
			'provider_txn_id' => '099409626954',
			'outcome'         => 'matched',
			'order_id'        => 7,
		);
		$d   = MatchPolicy::decide( $this->transfer(), $this->ctx( array( 'duplicates' => array( $dup ), 'payer_rows' => array( $this->row( 10 ) ) ) ) );
		$this->assertSame( 'review', $d['outcome'] );
		$this->assertSame( 'duplicate_txn', $d['reason'] );
		$this->assertSame( 'trf_0', $d['args']['T'] );
		$this->assertSame( 7, $d['args']['N'] );
		$this->assertSame( 0, $d['paid_add'] );

		$self_row  = array_merge( $dup, array( 'transfer_id' => 'trf_1' ) );
		$undecided = array_merge( $dup, array( 'outcome' => null ) );
		$d         = MatchPolicy::decide( $this->transfer(), $this->ctx( array( 'duplicates' => array( $self_row, $undecided ), 'payer_rows' => array( $this->row( 10 ) ) ) ) );
		$this->assertSame( 'matched', $d['outcome'], 'its own row and a row without a verdict are no duplicates' );
	}

	/** An unreadable money time goes to review before anything else. */
	public function test_bad_time() {
		$d = MatchPolicy::decide( $this->transfer( array( 'received_at' => 'not a date' ) ), $this->ctx( array( 'payer_rows' => array( $this->row( 10 ) ) ) ) );
		$this->assertSame( 'review', $d['outcome'] );
		$this->assertSame( 'bad_time', $d['reason'] );
	}

	// ----- The payer path ------------------------------------------------------------------------------------------

	/** One open order and the exact amount: matched, paid. */
	public function test_matched() {
		$d = MatchPolicy::decide( $this->transfer(), $this->ctx( array( 'payer_rows' => array( $this->row( 10 ) ) ) ) );
		$this->assertSame( 'matched', $d['outcome'] );
		$this->assertSame( 10, $d['order_id'] );
		$this->assertSame( 'paid', $d['state'] );
		$this->assertSame( 14000, $d['paid_add'] );
		$this->assertSame( 0, $d['rounded'] );
		$this->assertFalse( $d['late'] );
		$this->assertSame( 'matched', $d['reply']['status'] );
		$this->assertSame( 10, $d['reply']['order'] );
	}

	/** Less than the remainder: partial; more (in time): overpaid. */
	public function test_partial_and_overpaid() {
		$d = MatchPolicy::decide( $this->transfer( array( 'amount_minor' => 10000 ) ), $this->ctx( array( 'payer_rows' => array( $this->row( 10 ) ) ) ) );
		$this->assertSame( 'partial', $d['outcome'] );
		$this->assertSame( 'partial', $d['state'] );
		$d = MatchPolicy::decide( $this->transfer( array( 'amount_minor' => 15000 ) ), $this->ctx( array( 'payer_rows' => array( $this->row( 10 ) ) ) ) );
		$this->assertSame( 'overpaid', $d['outcome'] );
		$this->assertSame( 'paid', $d['state'] );
		$this->assertSame( 1000, $d['over'] );
	}

	/** A partial order: the remainder decides. */
	public function test_rest_of_a_partial_order() {
		$d = MatchPolicy::decide( $this->transfer( array( 'amount_minor' => 4000 ) ), $this->ctx( array( 'payer_rows' => array( $this->row( 10, array( 'paid_minor' => 10000, 'state' => 'partial' ) ) ) ) ) );
		$this->assertSame( 'matched', $d['outcome'] );
		$this->assertSame( 4000, $d['paid_add'] );
	}

	/** Rounding to the pound: 139.50 takes 140 (with the note), 139 is partial, and the literal match wins. */
	public function test_rounding_to_the_pound() {
		$order = $this->row( 10, array( 'price_minor' => 13950 ) );
		$d     = MatchPolicy::decide( $this->transfer(), $this->ctx( array( 'payer_rows' => array( $order ) ) ) );
		$this->assertSame( 'matched', $d['outcome'] );
		$this->assertSame( 50, $d['rounded'] );
		$this->assertSame( array( 'rounded', array( 'X' => 50 ) ), $d['hints'][0] );
		$this->assertSame( 13950, $d['price'] );

		$d = MatchPolicy::decide( $this->transfer( array( 'amount_minor' => 13950 ) ), $this->ctx( array( 'payer_rows' => array( $order ) ) ) );
		$this->assertSame( 'matched', $d['outcome'] );
		$this->assertSame( 0, $d['rounded'] );

		$d = MatchPolicy::decide( $this->transfer( array( 'amount_minor' => 13900 ) ), $this->ctx( array( 'payer_rows' => array( $order ) ) ) );
		$this->assertSame( 'partial', $d['outcome'] );

		$newer = $this->row( 11, array( 'price_minor' => 13950, 'created_at' => self::MONEY - 60 ) );
		$exact = $this->row( 12, array( 'price_minor' => 14000, 'created_at' => self::MONEY - 1200 ) );
		$d     = MatchPolicy::decide( $this->transfer(), $this->ctx( array( 'payer_rows' => array( $newer, $exact ) ) ) );
		$this->assertSame( 12, $d['order_id'], 'orders of 139.50 and 140 and a transfer of 140: the 140 one' );
		$this->assertSame( 0, $d['rounded'] );

		$d = MatchPolicy::decide( $this->transfer(), $this->ctx( array( 'config' => $this->cfg( array( 'round_up_to_pound' => false ) ), 'payer_rows' => array( $order ) ) ) );
		$this->assertSame( 'overpaid', $d['outcome'], 'without rounding 140 overpays 139.50' );
	}

	/** Rule 3: a closed order in the window is an obstacle: review closed_order, never another order by mistake. */
	public function test_closed_order_is_an_obstacle() {
		$closed = $this->row( 10, array( 'state' => 'closed', 'kind' => 'obstacle' ) );
		$d      = MatchPolicy::decide( $this->transfer(), $this->ctx( array( 'payer_rows' => array( $closed ) ) ) );
		$this->assertSame( 'review', $d['outcome'] );
		$this->assertSame( 'closed_order', $d['reason'] );
		$this->assertSame( 10, $d['args']['N'] );
		$this->assertTrue( $d['args']['payer'] );
		$this->assertSame( array( 10 ), $d['pinned'] );

		$open = $this->row( 11, array( 'price_minor' => 25000 ) );
		$d    = MatchPolicy::decide( $this->transfer(), $this->ctx( array( 'payer_rows' => array( $closed, $open ) ) ) );
		$this->assertSame( 'review', $d['outcome'], 'another open order with a different amount does not take it' );
		$this->assertSame( 'closed_order', $d['reason'] );

		$exact = $this->row( 12 );
		$d     = MatchPolicy::decide( $this->transfer(), $this->ctx( array( 'payer_rows' => array( $closed, $exact ) ) ) );
		$this->assertSame( 'matched', $d['outcome'], 'the exact amount of another open order stays decisive' );
		$this->assertSame( 12, $d['order_id'] );
	}

	/** Rule 5: no order of this payer at all, or only one created after the money (skew 0): personal. */
	public function test_personal_transfer_with_a_payer() {
		$d = MatchPolicy::decide( $this->transfer(), $this->ctx() );
		$this->assertSame( 'unmatched', $d['outcome'] );
		$this->assertSame( 'personal', $d['reason'] );
		$this->assertTrue( $d['personal'] );
		$this->assertSame( 'personal', $d['reply']['note'] );

		$later = $this->row( 10, array( 'created_at' => self::MONEY + 60 ) );
		$d     = MatchPolicy::decide( $this->transfer(), $this->ctx( array( 'payer_rows' => array( $later ) ) ) );
		$this->assertSame( 'personal', $d['reason'], 'an order created a minute after the money never takes it' );
	}

	/** Rule 7: unmatched with a paid order of this payer within the late window: a hint, not personal. */
	public function test_possible_second_payment() {
		$paid = $this->row( 10, array( 'state' => 'paid', 'kind' => 'paid', 'paid_at' => self::MONEY - 600 ) );
		$d    = MatchPolicy::decide( $this->transfer(), $this->ctx( array( 'payer_rows' => array( $paid ) ) ) );
		$this->assertSame( 'unmatched', $d['outcome'] );
		$this->assertNull( $d['reason'] );
		$this->assertFalse( $d['personal'] );
		$this->assertSame( array( 'second_payment', array( 'N' => 10 ) ), $d['hints'][0] );
		$this->assertArrayNotHasKey( 'note', array_filter( $d['reply'] ) );

		$old = $this->row( 10, array( 'state' => 'paid', 'kind' => 'paid', 'paid_at' => self::MONEY - 8000 ) );
		$d   = MatchPolicy::decide( $this->transfer(), $this->ctx( array( 'payer_rows' => array( $old ) ) ) );
		$this->assertSame( 'personal', $d['reason'], 'paid long before the money: personal' );
	}

	/** auto_confirm_ok false: review with the phone's reason and the candidate pinned; without any order, personal. */
	public function test_not_confirmed_on_its_own() {
		$t = $this->transfer(
			array(
				'auto_confirm_ok' => false,
				'risk'            => 'medium',
				'risk_reasons'    => array( 'no_baseline' ),
			)
		);
		$d = MatchPolicy::decide( $t, $this->ctx( array( 'payer_rows' => array( $this->row( 10 ), $this->row( 11, array( 'price_minor' => 99900 ) ) ) ) ) );
		$this->assertSame( 'review', $d['outcome'] );
		$this->assertSame( 'no_baseline', $d['reason'] );
		$this->assertSame( array( 10 ), $d['pinned'] );
		$this->assertNull( $d['order_id'] );

		$d = MatchPolicy::decide( $t, $this->ctx() );
		$this->assertSame( 'unmatched', $d['outcome'] );
		$this->assertSame( 'personal', $d['reason'] );
	}

	/** A forged message: review for forgery, and the flag. */
	public function test_forgery_on_arrival() {
		$t = $this->transfer(
			array(
				'auto_confirm_ok' => false,
				'risk'            => 'high',
				'risk_reasons'    => array( 'suspected_forgery' ),
			)
		);
		$this->assertTrue( $t['forgery'] );
		$d = MatchPolicy::decide( $t, $this->ctx( array( 'payer_rows' => array( $this->row( 10 ) ) ) ) );
		$this->assertSame( 'review', $d['outcome'] );
		$this->assertSame( 'forgery', $d['reason'] );
		$this->assertSame( 'forgery', $d['flag'] );
	}

	/** A forged message pays no order even with auto_confirm_ok: not by the payer, not by a typed id; personal stays. */
	public function test_forgery_never_pays_an_order() {
		$forged = array(
			'risk'         => 'high',
			'risk_reasons' => array( 'suspected_forgery' ),
		);
		$t      = $this->transfer( $forged );
		$this->assertTrue( $t['auto_confirm_ok'] );
		$d = MatchPolicy::decide( $t, $this->ctx( array( 'payer_rows' => array( $this->row( 10 ) ) ) ) );
		$this->assertSame( 'review', $d['outcome'] );
		$this->assertSame( 'forgery', $d['reason'] );
		$this->assertSame( 'forgery', $d['flag'] );
		$this->assertNull( $d['order_id'] );
		$this->assertSame( 0, $d['paid_add'] );
		$this->assertSame( array( 10 ), $d['pinned'] );
		$this->assertSame( 'forgery', $d['reply']['note'] );

		$refuted = $this->transfer( array( 'checks' => array( 'balance_chain' => 'refuted' ) ) );
		$d       = MatchPolicy::decide( $refuted, $this->ctx( array( 'payer_rows' => array( $this->row( 10, array( 'price_minor' => 20000 ) ) ) ) ) );
		$this->assertSame( 'forgery', $d['reason'], 'a refuted balance chain is the same verdict, partial included' );

		$x = $this->claimant( 20 );
		$d = MatchPolicy::decide( $this->orange( $forged ), $this->ctx( array( 'claimants' => array( $x ), 'wallet_rows' => array( $x ) ) ) );
		$this->assertSame( 'review', $d['outcome'], 'a typed transaction id never passes the forgery flag' );
		$this->assertSame( 'forgery', $d['reason'] );
		$this->assertSame( array( 20 ), $d['pinned'] );
		$this->assertFalse( $d['by_claim'] );

		$d = MatchPolicy::decide( $t, $this->ctx() );
		$this->assertSame( 'unmatched', $d['outcome'], 'no order could take it: personal, whatever its risk' );
		$this->assertSame( 'personal', $d['reason'] );
		$this->assertSame( 'forgery', $d['flag'] );

		$closed = $this->row( 10, array( 'state' => 'closed', 'kind' => 'obstacle' ) );
		$d      = MatchPolicy::decide( $t, $this->ctx( array( 'payer_rows' => array( $closed ) ) ) );
		$this->assertSame( 'closed_order', $d['reason'], 'a review keeps its reason, with the flag' );
		$this->assertSame( 'forgery', $d['flag'] );
	}

	/** An order our expiry cancelled is passed as open with its deadline: inside the window it is revived. */
	public function test_expired_order_revived() {
		$expired = $this->row( 10, array( 'state' => 'expired', 'kind' => 'expired', 'expires_at' => self::MONEY + 60 ) );
		$d       = MatchPolicy::decide( $this->transfer(), $this->ctx( array( 'payer_rows' => array( $expired ) ) ) );
		$this->assertSame( 'matched', $d['outcome'] );
		$this->assertTrue( $d['revive'] );
		$this->assertSame( 'paid', $d['state'] );

		$late = $this->row( 10, array( 'state' => 'expired', 'kind' => 'expired', 'expires_at' => self::MONEY - 600 ) );
		$d    = MatchPolicy::decide( $this->transfer(), $this->ctx( array( 'payer_rows' => array( $late ) ) ) );
		$this->assertSame( 'matched', $d['outcome'] );
		$this->assertTrue( $d['late'], 'paid after expiry, inside the late window' );
		$this->assertContains( array( 'paid_late', array() ), $d['hints'] );
	}

	/** Rule 8: part of the amount for an order our expiry cancelled: review partial_overdue, no revival. */
	public function test_partial_for_an_expired_order() {
		$expired = $this->row( 10, array( 'state' => 'expired', 'kind' => 'expired', 'expires_at' => self::MONEY - 600 ) );
		$d       = MatchPolicy::decide( $this->transfer( array( 'amount_minor' => 5000 ) ), $this->ctx( array( 'payer_rows' => array( $expired ) ) ) );
		$this->assertSame( 'review', $d['outcome'] );
		$this->assertSame( 'partial_overdue', $d['reason'] );
		$this->assertNull( $d['order_id'] );
		$this->assertSame( array( 10 ), $d['pinned'] );
	}

	/** Rule 6: more than the remainder after the deadline is overpaid with the late note. */
	public function test_overpaid_after_expiry() {
		$late = $this->row( 10, array( 'expires_at' => self::MONEY - 600 ) );
		$d    = MatchPolicy::decide( $this->transfer( array( 'amount_minor' => 15000 ) ), $this->ctx( array( 'payer_rows' => array( $late ) ) ) );
		$this->assertSame( 'overpaid', $d['outcome'] );
		$this->assertTrue( $d['late'] );
		$this->assertSame( 1000, $d['over'] );
	}

	/** Several open orders, none with the amount: review several_orders with them pinned. */
	public function test_several_orders() {
		$rows = array( $this->row( 10, array( 'price_minor' => 20000 ) ), $this->row( 11, array( 'price_minor' => 30000 ) ) );
		$d    = MatchPolicy::decide( $this->transfer(), $this->ctx( array( 'payer_rows' => $rows ) ) );
		$this->assertSame( 'review', $d['outcome'] );
		$this->assertSame( 'several_orders', $d['reason'] );
		$this->assertSame( array( 10, 11 ), $d['pinned'] );
	}

	/** Rows outside the window are ignored, obstacles included. */
	public function test_window_filter() {
		$gone = $this->row( 10, array( 'state' => 'closed', 'kind' => 'obstacle', 'expires_at' => self::MONEY - 7300 ) );
		$d    = MatchPolicy::decide( $this->transfer(), $this->ctx( array( 'payer_rows' => array( $gone ) ) ) );
		$this->assertSame( 'personal', $d['reason'] );
	}

	// ----- Without a payer -----------------------------------------------------------------------------------------

	/** Rule 4: an Orange transfer nobody typed, with a live order for the amount: review no_payer, Orange note. */
	public function test_no_payer_with_a_possible_order() {
		$d = MatchPolicy::decide( $this->orange(), $this->ctx( array( 'wallet_rows' => array( $this->row( 10 ) ) ) ) );
		$this->assertSame( 'review', $d['outcome'] );
		$this->assertSame( 'no_payer', $d['reason'] );
		$this->assertTrue( $d['args']['orange'] );
		$this->assertSame( 'no_payer_orange', $d['reply']['note'] );
		$this->assertSame( array( 10 ), $d['pinned'] );
	}

	/** A masked sender on Vodafone: review masked; nothing for the amount on the wallet: personal. */
	public function test_masked_and_personal_without_a_payer() {
		$t = $this->transfer( array( 'counterparty' => array( 'number' => '0111***3445' ) ) );
		$d = MatchPolicy::decide( $t, $this->ctx( array( 'wallet_rows' => array( $this->row( 10 ) ) ) ) );
		$this->assertSame( 'masked', $d['reason'] );
		$this->assertSame( 'no_payer', $d['reply']['note'] );

		$d = MatchPolicy::decide( $this->orange(), $this->ctx( array( 'wallet_rows' => array( $this->row( 10, array( 'price_minor' => 50000 ) ) ) ) ) );
		$this->assertSame( 'personal', $d['reason'], 'no live order for the amount' );

		$later = $this->row( 10, array( 'created_at' => self::MONEY + 30 ) );
		$d     = MatchPolicy::decide( $this->orange(), $this->ctx( array( 'wallet_rows' => array( $later ) ) ) );
		$this->assertSame( 'personal', $d['reason'], 'an order opened after the money never takes it' );

		$other = $this->row( 10, array( 'wallet' => self::OTHER_WALLET ) );
		$d     = MatchPolicy::decide( $this->orange(), $this->ctx( array( 'wallet_rows' => array( $other ) ) ) );
		$this->assertSame( 'personal', $d['reason'], 'only the wallet the money reached counts' );
	}

	// ----- Rule 4a -------------------------------------------------------------------------------------------------

	/** One live claimant passing every gate: matched by the typed id. */
	public function test_claim_matched() {
		$x = $this->claimant( 20 );
		$d = MatchPolicy::decide( $this->orange(), $this->ctx( array( 'claimants' => array( $x ), 'wallet_rows' => array( $x ) ) ) );
		$this->assertSame( 'matched', $d['outcome'] );
		$this->assertTrue( $d['by_claim'] );
		$this->assertSame( 20, $d['order_id'] );
		$this->assertSame( 'paid', $d['state'] );
		$this->assertSame( 'by_claim', $d['reply']['note'] );
	}

	/** Counting the claimants: two live, dead only, live and dead of the same payer, of another payer. */
	public function test_claimant_counting() {
		$a = $this->claimant( 20 );
		$b = $this->claimant( 21, array( 'payer_key' => '01033334444' ) );
		$d = MatchPolicy::decide( $this->orange(), $this->ctx( array( 'claimants' => array( $a, $b ) ) ) );
		$this->assertSame( 'txn_contested', $d['reason'] );
		$this->assertSame( 'two', $d['args']['form'] );
		$this->assertSame( array( 20, 21 ), $d['pinned'] );

		$dead = $this->claimant( 22, array( 'state' => 'closed', 'kind' => 'obstacle' ) );
		$d    = MatchPolicy::decide( $this->orange(), $this->ctx( array( 'claimants' => array( $dead ) ) ) );
		$this->assertSame( 'closed_order', $d['reason'] );
		$this->assertSame( 22, $d['args']['N'] );

		$d = MatchPolicy::decide( $this->orange(), $this->ctx( array( 'claimants' => array( $dead, $a ), 'wallet_rows' => array( $dead, $a ) ) ) );
		$this->assertSame( 'matched', $d['outcome'], 'the customer cancelled one order and placed another' );
		$this->assertSame( 20, $d['order_id'] );

		$stranger = $this->claimant( 23, array( 'state' => 'closed', 'kind' => 'obstacle', 'payer_key' => '01055556666' ) );
		$d        = MatchPolicy::decide( $this->orange(), $this->ctx( array( 'claimants' => array( $stranger, $a ) ) ) );
		$this->assertSame( 'txn_contested', $d['reason'] );
		$this->assertSame( 20, $d['pinned'][0], 'X first' );

		$elsewhere = $this->claimant( 24, array( 'provider' => 'vodafone_cash' ) );
		$d         = MatchPolicy::decide( $this->orange(), $this->ctx( array( 'claimants' => array( $elsewhere ), 'wallet_rows' => array( $this->row( 10 ) ) ) ) );
		$this->assertSame( 'no_payer', $d['reason'], 'only claimants on an Orange wallet count' );
	}

	/** Gates 1 to 3: the wallet, rule 3 on X, X created before the money. */
	public function test_claim_gates_one_to_three() {
		$d = MatchPolicy::decide( $this->orange(), $this->ctx( array( 'claimants' => array( $this->claimant( 20, array( 'wallet' => self::OTHER_WALLET ) ) ) ) ) );
		$this->assertSame( 'txn_wallet', $d['reason'] );
		$this->assertSame( self::OTHER_WALLET, $d['args']['W'] );

		$d = MatchPolicy::decide( $this->orange(), $this->ctx( array( 'claimants' => array( $this->claimant( 20, array( 'kind' => 'obstacle' ) ) ) ) ) );
		$this->assertSame( 'closed_order', $d['reason'] );

		$d = MatchPolicy::decide( $this->orange(), $this->ctx( array( 'claimants' => array( $this->claimant( 20, array( 'created_at' => self::MONEY + 60, 'claim_at' => self::MONEY + 120 ) ) ) ) ) );
		$this->assertSame( 'txn_window', $d['reason'] );
		$this->assertSame( 'window', $d['args']['form'] );
		$this->assertSame( array( 20 ), $d['pinned'] );
	}

	/** Gate 4: typed 11 minutes before the operator's message: window; the phone 40 minutes late is no guesser. */
	public function test_claim_gate_four() {
		$d = MatchPolicy::decide( $this->orange(), $this->ctx( array( 'claimants' => array( $this->claimant( 20, array( 'claim_at' => self::MONEY - 5 - 660 ) ) ) ) ) );
		$this->assertSame( 'txn_window', $d['reason'] );
		$this->assertSame( 'early', $d['args']['form'] );

		$t = $this->orange( array( 'sms_timestamp' => Time::sec_to_iso( self::MONEY - 2400 ) ) );
		$x = $this->claimant( 20, array( 'claim_at' => self::MONEY - 2400 + 120, 'created_at' => self::MONEY - 3600 ) );
		$d = MatchPolicy::decide( $t, $this->ctx( array( 'claimants' => array( $x ) ) ) );
		$this->assertSame( 'matched', $d['outcome'] );

		$this->assertSame( self::MONEY, MatchPolicy::operator_at( null, self::MONEY, 7200 ) );
		$this->assertSame( self::MONEY - 7200, MatchPolicy::operator_at( self::MONEY - 9000, self::MONEY, 7200 ) );
		$this->assertSame( self::MONEY, MatchPolicy::operator_at( self::MONEY + 300, self::MONEY, 7200 ) );
	}

	/** Gate 5: the exact remainder after rounding, and never more after the deadline. */
	public function test_claim_gate_five() {
		$x = $this->claimant( 20, array( 'price_minor' => 13950 ) );
		$d = MatchPolicy::decide( $this->orange(), $this->ctx( array( 'claimants' => array( $x ) ) ) );
		$this->assertSame( 'matched', $d['outcome'] );
		$this->assertSame( 50, $d['rounded'] );
		$d = MatchPolicy::decide( $this->orange( array( 'amount_minor' => 13950 ) ), $this->ctx( array( 'claimants' => array( $x ) ) ) );
		$this->assertSame( 'matched', $d['outcome'] );

		foreach ( array( 13900, 14100 ) as $amount ) {
			$d = MatchPolicy::decide( $this->orange( array( 'amount_minor' => $amount ) ), $this->ctx( array( 'claimants' => array( $x ) ) ) );
			$this->assertSame( 'txn_amount', $d['reason'], (string) $amount );
			$this->assertSame( 14000, $d['args']['Z'] );
		}

		$small = $this->claimant( 20, array( 'price_minor' => 10000 ) );
		$d     = MatchPolicy::decide( $this->orange( array( 'amount_minor' => 150000 ) ), $this->ctx( array( 'claimants' => array( $small ) ) ) );
		$this->assertSame( 'txn_amount', $d['reason'] );
		$this->assertNull( $d['order_id'] );

		$late = $this->claimant( 20, array( 'price_minor' => 10000, 'state' => 'expired', 'kind' => 'expired', 'expires_at' => self::MONEY - 600 ) );
		$d    = MatchPolicy::decide( $this->orange( array( 'amount_minor' => 15000 ) ), $this->ctx( array( 'claimants' => array( $late ) ) ) );
		$this->assertSame( 'txn_amount', $d['reason'], '150 for an order of 100 after the deadline' );

		$gone = $this->claimant( 20, array( 'expires_at' => self::MONEY - 7300 ) );
		$d    = MatchPolicy::decide( $this->orange(), $this->ctx( array( 'claimants' => array( $gone ) ) ) );
		$this->assertSame( 'txn_window', $d['reason'], 'outside the late window the Matcher finds no order' );
	}

	/** Gate 6: a competitor without an id, a closed one, and one with another id (which does not compete). */
	public function test_claim_gate_six() {
		$x    = $this->claimant( 20 );
		$open = $this->row( 30, array( 'payer_key' => '01077778888' ) );
		$d    = MatchPolicy::decide( $this->orange(), $this->ctx( array( 'claimants' => array( $x ), 'wallet_rows' => array( $x, $open ) ) ) );
		$this->assertSame( 'txn_contested', $d['reason'] );
		$this->assertSame( 'competitor', $d['args']['form'] );
		$this->assertSame( array( 20, 30 ), $d['pinned'] );

		$typed = $this->row( 31, array( 'payer_key' => '01077778888', 'claim_txn' => '25005172940000' ) );
		$d     = MatchPolicy::decide( $this->orange(), $this->ctx( array( 'claimants' => array( $x ), 'wallet_rows' => array( $x, $typed ) ) ) );
		$this->assertSame( 'matched', $d['outcome'] );

		$closed = $this->row( 32, array( 'payer_key' => '01077778888', 'state' => 'closed' ) );
		$d      = MatchPolicy::decide( $this->orange(), $this->ctx( array( 'claimants' => array( $x ), 'wallet_rows' => array( $x, $closed ) ) ) );
		$this->assertSame( 'txn_contested', $d['reason'] );

		$other_amount = $this->row( 33, array( 'payer_key' => '01077778888', 'price_minor' => 50000 ) );
		$later        = $this->row( 34, array( 'payer_key' => '01077778888', 'created_at' => self::MONEY + 30 ) );
		$d            = MatchPolicy::decide( $this->orange(), $this->ctx( array( 'claimants' => array( $x ), 'wallet_rows' => array( $x, $other_amount, $later ) ) ) );
		$this->assertSame( 'matched', $d['outcome'] );
	}

	/** The invariants of 4a: auto_confirm_ok false, unknown reasons and duplicates come first; txn_entry off. */
	public function test_claim_invariants() {
		$x = $this->claimant( 20 );
		$t = $this->orange(
			array(
				'auto_confirm_ok' => false,
				'risk_reasons'    => array( 'no_baseline' ),
			)
		);
		$d = MatchPolicy::decide( $t, $this->ctx( array( 'claimants' => array( $x ) ) ) );
		$this->assertSame( 'review', $d['outcome'] );
		$this->assertSame( 'no_baseline', $d['reason'] );
		$this->assertSame( array( 20 ), $d['pinned'] );

		$d = MatchPolicy::decide( $this->orange( array( 'risk_reasons' => array( 'new_thing' ) ) ), $this->ctx( array( 'claimants' => array( $x ) ) ) );
		$this->assertSame( 'unknown_risk', $d['reason'] );

		$dup = array(
			'transfer_id'     => 'trf_0',
			'provider'        => 'orange_cash',
			'provider_txn_id' => self::TXN,
			'outcome'         => 'review',
			'order_id'        => null,
		);
		$d   = MatchPolicy::decide( $this->orange(), $this->ctx( array( 'claimants' => array( $x ), 'duplicates' => array( $dup ) ) ) );
		$this->assertSame( 'duplicate_txn', $d['reason'] );

		$d = MatchPolicy::decide( $this->orange(), $this->ctx( array( 'config' => $this->cfg( array( 'txn_entry' => false ) ), 'claimants' => array( $x ), 'wallet_rows' => array( $x ) ) ) );
		$this->assertSame( 'no_payer', $d['reason'], 'txn_entry off: rule 4 as it is' );
		$this->assertFalse( $d['args']['orange'] );

		$bank = MatchPolicy::read_transfer( $this->data( array( 'counterparty' => array( 'name' => 'Mona' ), 'provider_txn_id' => self::TXN ) ), self::WALLET, 'bank_nbe', self::NOW );
		$d    = MatchPolicy::decide( $bank, $this->ctx( array( 'claimants' => array( $x ), 'wallet_rows' => array( $x ) ) ) );
		$this->assertSame( 'no_payer', $d['reason'], 'banks never enter 4a' );

		$named = $this->transfer( array( 'provider_txn_id' => self::TXN ), 'orange_cash' );
		$d     = MatchPolicy::decide( $named, $this->ctx( array( 'claimants' => array( $x ) ) ) );
		$this->assertSame( 'personal', $d['reason'], 'a transfer with a payer key ignores typed ids' );
	}

	/** The late path: a stored no_payer row, a claim typed hours later, X still in the window of the money. */
	public function test_late_path_from_a_row() {
		$row = array(
			'transfer_id'     => 'trf_9',
			'wallet'          => self::WALLET,
			'provider'        => 'orange_cash',
			'amount_minor'    => 14000,
			'currency'        => 'EGP',
			'payer_key'       => null,
			'payer_name'      => 'Mona',
			'provider_txn_id' => self::TXN,
			'money_at'        => self::MONEY,
			'sms_at'          => self::MONEY - 5,
			'risk'            => 'low',
			'risk_reasons'    => array(),
			'auto_confirm_ok' => 1,
			'balance_chain'   => 'consistent',
			'outcome'         => 'review',
			'reason'          => 'no_payer',
			'order_id'        => null,
			'flag'            => null,
		);
		$t   = MatchPolicy::transfer_from_row( $row );
		$this->assertSame( self::TXN, $t['txn'] );
		$this->assertSame( 'txn:orange_cash:' . self::TXN, MatchPolicy::transfer_lock( $t ) );
		$this->assertSame( 'txn:orange_cash:' . self::TXN, MatchPolicy::transfer_lock( $row ) );
		$x   = $this->claimant( 20, array( 'claim_at' => self::NOW + 10000 ) );
		$ctx = $this->ctx(
			array(
				'now'       => self::NOW + 10000,
				'claimants' => array( $x ),
			)
		);
		$d   = MatchPolicy::claim_decision( $t, $ctx );
		$this->assertSame( 'matched', $d['outcome'] );
		$this->assertSame( 20, $d['order_id'] );
		$this->assertTrue( Claims::eligible( $row ) );
		$this->assertFalse( Claims::eligible( array_merge( $row, array( 'auto_confirm_ok' => 0 ) ) ) );
		$this->assertFalse( Claims::eligible( array_merge( $row, array( 'flag' => 'forgery' ) ) ) );
		$this->assertFalse( Claims::eligible( array_merge( $row, array( 'reason' => 'txn_amount' ) ) ) );

		$row['auto_confirm_ok'] = 0;
		$d                      = MatchPolicy::claim_decision( MatchPolicy::transfer_from_row( $row ), $ctx );
		$this->assertSame( 'txn_window', $d['reason'], 'the Matcher still reads auto_confirm_ok' );
	}

	// ----- Advice --------------------------------------------------------------------------------------------------

	/** The ready button only for one owner passing every gate (auto_confirm_ok ignored); dead owners never preselected. */
	public function test_advice() {
		$t     = $this->orange( array( 'auto_confirm_ok' => false, 'risk_reasons' => array( 'no_baseline' ) ) );
		$x     = $this->claimant( 20 );
		$items = MatchPolicy::advice( $t, $this->ctx( array( 'claimants' => array( $x ), 'wallet_rows' => array( $x ) ) ) );
		$this->assertCount( 1, $items );
		$this->assertTrue( $items[0]['ready'] );
		$this->assertTrue( $items[0]['preselect'] );
		$this->assertSame( 'claim', $items[0]['source'] );

		$small = $this->claimant( 20, array( 'price_minor' => 10000 ) );
		$dead  = $this->claimant( 21, array( 'state' => 'closed', 'kind' => 'obstacle' ) );
		$items = MatchPolicy::advice( $this->orange(), $this->ctx( array( 'claimants' => array( $small, $dead ), 'wallet_rows' => array( $small, $dead, $this->row( 30, array( 'payer_key' => '01077778888' ) ) ) ) ) );
		$this->assertSame( array( 20, 21, 30 ), array_column( $items, 'order_id' ) );
		$this->assertFalse( $items[0]['ready'] );
		$this->assertSame( 'txn_amount', $items[0]['reason'] );
		$this->assertFalse( $items[1]['preselect'] );
		$this->assertFalse( $items[1]['live'] );
		$this->assertTrue( $items[2]['preselect'], 'the one order of the wallet that fits' );

		$items = MatchPolicy::advice( $this->transfer(), $this->ctx( array( 'payer_rows' => array( $this->row( 10, array( 'price_minor' => 30000 ) ), $this->row( 11 ), $this->row( 12, array( 'created_at' => self::MONEY + 600 ) ) ) ) ) );
		$this->assertSame( array( 11, 12, 10 ), array_column( $items, 'order_id' ), 'those that fit first' );
		$this->assertFalse( $items[0]['preselect'], 'two fit: none preselected' );
		$this->assertTrue( $items[1]['after'] );
	}

	// ----- Helpers -------------------------------------------------------------------------------------------------

	/** Rule 3 classification. */
	public function test_classify() {
		$facts = array(
			'exists'     => true,
			'method'     => 'qersh',
			'flagged'    => false,
			'status'     => 'pending',
			'currency'   => 'EGP',
			'expired_at' => 0,
		);
		$row   = $this->row( 10 );
		$this->assertSame( 'normal', MatchPolicy::classify( $row, $facts ) );
		$this->assertSame( 'normal', MatchPolicy::classify( array_merge( $row, array( 'state' => 'partial' ) ), $facts ) );
		$this->assertSame( 'paid', MatchPolicy::classify( array_merge( $row, array( 'state' => 'paid' ) ), $facts ) );
		$this->assertSame( 'obstacle', MatchPolicy::classify( array_merge( $row, array( 'state' => 'closed' ) ), $facts ) );
		$this->assertSame( 'obstacle', MatchPolicy::classify( array_merge( $row, array( 'state' => 'review' ) ), $facts ) );
		$this->assertSame( 'obstacle', MatchPolicy::classify( $row, array_merge( $facts, array( 'flagged' => true ) ) ) );
		$this->assertSame( 'obstacle', MatchPolicy::classify( $row, array_merge( $facts, array( 'method' => 'cod' ) ) ) );
		$this->assertSame( 'obstacle', MatchPolicy::classify( $row, array_merge( $facts, array( 'status' => 'processing' ) ) ) );
		$this->assertSame( 'obstacle', MatchPolicy::classify( $row, array_merge( $facts, array( 'currency' => 'USD' ) ) ) );
		$this->assertSame( 'obstacle', MatchPolicy::classify( $row, array( 'exists' => false ) ) );
		$expired = array_merge( $row, array( 'state' => 'expired' ) );
		$this->assertSame( 'expired', MatchPolicy::classify( $expired, array_merge( $facts, array( 'status' => 'cancelled', 'expired_at' => self::NOW ) ) ) );
		$this->assertSame( 'expired', MatchPolicy::classify( $expired, $facts ), 'a cancellation still to come' );
		$this->assertSame( 'obstacle', MatchPolicy::classify( $expired, array_merge( $facts, array( 'status' => 'cancelled' ) ) ), 'cancelled by someone else' );
	}

	/** Fitting a remainder, with and without rounding. */
	public function test_fits() {
		$this->assertTrue( MatchPolicy::fits( 14000, 14000, false ) );
		$this->assertTrue( MatchPolicy::fits( 13950, 14000, true ) );
		$this->assertFalse( MatchPolicy::fits( 13950, 14000, false ) );
		$this->assertFalse( MatchPolicy::fits( 13950, 14050, true ) );
		$this->assertFalse( MatchPolicy::fits( 13900, 14000, true ), 'a whole remainder never rounds' );
		$this->assertFalse( MatchPolicy::fits( 14000, null, true ) );
	}

	/** The locks of a delivery, in their total order. */
	public function test_lock_names() {
		$this->assertSame( array( 'payer:' . self::PAYER ), MatchPolicy::lock_names( $this->transfer(), array(), true ) );
		$hint = array( $this->claimant( 20 ), $this->claimant( 21, array( 'state' => 'closed', 'payer_key' => '01099990000' ) ) );
		$this->assertSame( array( 'txn:orange_cash:' . self::TXN, 'payer:01011112222' ), MatchPolicy::lock_names( $this->orange(), $hint, true ) );
		$this->assertSame( array( 'txn:orange_cash:' . self::TXN ), MatchPolicy::lock_names( $this->orange(), $hint, false ) );
		$bank = MatchPolicy::read_transfer( $this->data( array( 'counterparty' => null ) ), self::WALLET, 'bank_cib', self::NOW );
		$this->assertSame( array( 'trf:trf_1' ), MatchPolicy::lock_names( $bank, array(), true ) );
		$this->assertSame( MatchPolicy::claimants_print( array_reverse( $hint ) ), MatchPolicy::claimants_print( $hint ) );
	}

	/** The one reason of a transfer not confirmed on its own. */
	public function test_risk_reason() {
		$t = $this->transfer( array( 'auto_confirm_ok' => false ) );
		$this->assertSame( 'not_confirmed', MatchPolicy::risk_reason( $t ) );
		$t['risk_reasons'] = array( 'balance_unavailable' );
		$this->assertSame( 'balance_unavailable', MatchPolicy::risk_reason( $t ) );
		$t['channel'] = 'notification';
		$this->assertSame( 'notification_only', MatchPolicy::risk_reason( $t ) );
		$t['risk_reasons'] = array( 'sim_mismatch', 'amount_over_limit' );
		$this->assertSame( 'amount_over_limit', MatchPolicy::risk_reason( $t ) );
		$t['risk_reasons'] = array( 'unverified_template', 'balance_stale' );
		$this->assertSame( 'balance_stale', MatchPolicy::risk_reason( $t ) );
		$t['risk_reasons'] = array( 'sim_mismatch' );
		$this->assertSame( 'sim_mismatch', MatchPolicy::risk_reason( $t ) );
		$t['forgery'] = true;
		$this->assertSame( 'forgery', MatchPolicy::risk_reason( $t ) );
	}

	/** The options of a configuration, with the plugin's defaults. */
	public function test_options() {
		$this->assertSame( $this->cfg(), MatchPolicy::options( array() ) );
		$opts = MatchPolicy::options(
			array(
				'receiver' => array( 'matching' => array( 'late_window_min' => 60, 'skew_min' => 5 ) ),
				'store'    => array( 'round_up_to_pound' => false, 'txn_entry' => false, 'order_ttl_min' => 30 ),
			)
		);
		$this->assertSame( 60, $opts['late_window_min'] );
		$this->assertSame( 5, $opts['skew_min'] );
		$this->assertFalse( $opts['round_up_to_pound'] );
		$this->assertFalse( $opts['txn_entry'] );
		$this->assertSame( 30, $opts['order_ttl_min'] );
	}

	/** The pure rules of Expiry and Orders. */
	public function test_expiry_and_order_rules() {
		$opts = $this->cfg();
		$this->assertSame( 0, Expiry::hold_minutes( 0, $opts ), 'a hold switched off stays off' );
		$this->assertSame( 240, Expiry::hold_minutes( 60, $opts ) );
		$this->assertSame( 300, Expiry::hold_minutes( 300, $opts ) );

		$this->assertSame( 'payer', Expiry::note_kind( 'vodafone_cash', false, true ) );
		$this->assertSame( 'orange', Expiry::note_kind( 'orange_cash', false, true ) );
		$this->assertSame( 'orange_txn', Expiry::note_kind( 'orange_cash', true, true ) );
		$this->assertSame( 'decision', Expiry::note_kind( 'orange_cash', false, false ) );
		$this->assertSame( 'decision', Expiry::note_kind( 'instapay_app', false, true ) );
		$this->assertSame( 'decision', Expiry::note_kind( 'bank_nbe', false, true ) );

		$this->assertFalse( Expiry::locked_row( $this->row( 10 ) ) );
		$this->assertTrue( Expiry::locked_row( $this->row( 10, array( 'paid_minor' => 100 ) ) ) );
		$this->assertTrue( Expiry::locked_row( $this->row( 10, array( 'claim_txn' => self::TXN ) ) ) );
		$this->assertFalse( Expiry::locked_row( $this->row( 10, array( 'claim_txn' => self::TXN, 'state' => 'closed' ) ) ) );
		$this->assertTrue( Expiry::locked_row( $this->row( 10, array( 'claim_txn' => self::TXN, 'state' => 'review' ) ) ), 'claim_overdue: pending until the merchant decides' );
		$this->assertFalse( Expiry::locked_row( $this->row( 10, array( 'state' => 'review' ) ) ), 'a review row without money or a typed id' );

		$this->assertSame( 'open', Orders::state_on_payment( null ) );
		$this->assertSame( 'open', Orders::state_on_payment( $this->row( 10, array( 'state' => 'closed' ) ) ) );
		$this->assertSame( 'partial', Orders::state_on_payment( $this->row( 10, array( 'state' => 'closed', 'paid_minor' => 500 ) ) ) );
		$this->assertSame( 'partial', Orders::state_on_payment( $this->row( 10, array( 'state' => 'partial', 'paid_minor' => 500 ) ) ) );
		$this->assertSame( 'review', Orders::state_on_payment( $this->row( 10, array( 'state' => 'review' ) ) ) );

		$this->assertTrue( Orders::completion_pending( $this->row( 10, array( 'state' => 'paid', 'completion' => 'due' ) ) ) );
		$this->assertFalse( Orders::completion_pending( $this->row( 10, array( 'state' => 'paid', 'completion' => 'done' ) ) ) );

		$this->assertSame( array(), Orders::payer_lock( '' ) );
		$this->assertSame( array( 'payer:' . self::PAYER ), Orders::payer_lock( self::PAYER ) );
		$this->assertTrue( Orders::reason_of_order( 'stock_short', 10 ) );
		$this->assertFalse( Orders::reason_of_order( 'stock_short', 0 ) );
		$this->assertFalse( Orders::reason_of_order( 'txn_amount', 10 ), 'its arguments live in the note' );
		$this->assertSame( array( 'order_id' => 10 ), Orders::action_args( 10 ) );
	}
}
