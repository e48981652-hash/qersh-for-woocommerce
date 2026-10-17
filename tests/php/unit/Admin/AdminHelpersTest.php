<?php
/**
 * Tests of the pure helpers of the admin (package E): which reason a transfer row shows, the wallet a pasted link
 * names, the suggestions of Orders, the settings' amounts, and whether uninstall removes data.
 *
 * @package QershPay\WooCommerce\Tests
 */

declare( strict_types=1 );

namespace QershPay\WooCommerce\Tests\Unit\Admin;

use QershPay\WooCommerce\Admin\Reasons;
use QershPay\WooCommerce\Admin\Settings;
use QershPay\WooCommerce\Admin\Transfers;
use QershPay\WooCommerce\Admin\Uninstall;
use QershPay\WooCommerce\Admin\Wallets;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

defined( 'ABSPATH' ) || exit;

/**
 * Admin helpers, without WordPress.
 */
final class AdminHelpersTest extends TestCase {

	/**
	 * A transfer row.
	 *
	 * @param array $fields Fields.
	 * @return array
	 */
	private function row( array $fields ): array {
		return array_merge(
			array(
				'transfer_id'     => 'trf_01JA2Y4X5M7Q9R0S1T2U3V4W5Z',
				'provider'        => 'vodafone_cash',
				'wallet'          => 'k7q2m9x4p1rz',
				'amount_minor'    => 14000,
				'payer_key'       => '01112233445',
				'provider_txn_id' => null,
				'money_at'        => 1792000000,
				'sms_at'          => null,
				'risk_reasons'    => array(),
				'auto_confirm_ok' => 0,
				'balance_chain'   => null,
				'outcome'         => 'review',
				'reason'          => null,
				'claim_eval'      => null,
				'order_id'        => null,
				'flag'            => null,
			),
			$fields
		);
	}

	/** The forgery flag wins; a store reason comes next; then the risk reasons in order. */
	public function test_pick_order() {
		$this->assertSame( 'forgery', Reasons::pick( $this->row( array( 'flag' => 'forgery', 'reason' => 'closed_order' ) ) )['code'] );
		$this->assertSame( 'closed_order', Reasons::pick( $this->row( array( 'reason' => 'closed_order', 'risk_reasons' => array( 'no_baseline' ) ) ) )['code'] );
		$this->assertSame( 'no_baseline', Reasons::pick( $this->row( array( 'risk_reasons' => array( 'amount_over_limit', 'no_baseline' ) ) ) )['code'] );
		$this->assertSame( 'forgery', Reasons::pick( $this->row( array( 'risk_reasons' => array( 'no_baseline', 'suspected_forgery' ) ) ) )['code'] );
		$this->assertSame( 'merchant_confirmed', Reasons::pick( $this->row( array( 'balance_chain' => 'merchant_confirmed', 'risk_reasons' => array( 'no_baseline' ) ) ) )['code'] );
		$this->assertSame( '', Reasons::pick( $this->row( array( 'outcome' => 'matched' ) ) )['code'] );
	}

	/** balance_unavailable reads differently on the InstaPay notification channel and on a text message. */
	public function test_pick_balance_unavailable() {
		$sms = Reasons::pick( $this->row( array( 'provider' => 'bank_cib', 'risk_reasons' => array( 'balance_unavailable' ) ) ) );
		$this->assertSame( array( 'balance_unavailable', 'sms' ), array( $sms['code'], $sms['variant'] ) );
		$ipn = Reasons::pick( $this->row( array( 'provider' => 'instapay_app', 'risk_reasons' => array( 'balance_unavailable' ) ) ) );
		$this->assertSame( 'notification', $ipn['variant'] );
		$both = Reasons::pick( $this->row( array( 'provider' => 'bank_nbe', 'risk_reasons' => array( 'balance_unavailable', 'notification_only' ) ) ) );
		$this->assertSame( 'notification_only', $both['code'] );
	}

	/** no_payer: Orange's own sentence, and a failed evaluation of a customer's id shown instead. */
	public function test_pick_no_payer() {
		$this->assertSame( '', Reasons::pick( $this->row( array( 'reason' => 'no_payer' ) ) )['variant'] );
		$this->assertSame( 'orange', Reasons::pick( $this->row( array( 'reason' => 'no_payer', 'provider' => 'orange_cash' ) ) )['variant'] );
		$eval = Reasons::pick( $this->row( array( 'reason' => 'no_payer', 'provider' => 'orange_cash', 'claim_eval' => 'txn_amount' ) ) );
		$this->assertSame( array( 'txn_amount', 'claim_eval' ), array( $eval['code'], $eval['variant'] ) );
	}

	/** Unknown codes, and known risk reasons without a sentence. */
	public function test_pick_unknown() {
		$this->assertSame( array( 'unknown', 'new_code' ), array_values( array_intersect_key( Reasons::pick( $this->row( array( 'reason' => 'new_code' ) ) ), array_flip( array( 'code', 'detail' ) ) ) ) );
		$other = Reasons::pick( $this->row( array( 'risk_reasons' => array( 'sim_mismatch' ) ) ) );
		$this->assertSame( array( 'risk_other', 'sim_mismatch' ), array( $other['code'], $other['detail'] ) );
		$new = Reasons::pick( $this->row( array( 'risk_reasons' => array( 'brand_new_reason' ) ) ) );
		$this->assertSame( array( 'unknown', 'brand_new_reason' ), array( $new['code'], $new['detail'] ) );
		$this->assertSame( 'personal', Reasons::pick( $this->row( array( 'outcome' => 'unmatched', 'reason' => 'personal' ) ) )['code'] );
	}

	/** txn_window: before the order existed, or typed before the operator's message. */
	public function test_window_variant() {
		$row = $this->row( array( 'sms_at' => 1792000000 - 600 ) );
		$this->assertSame( 'created', Reasons::window_variant( $row, array( 'created_at' => 1792000100, 'claim_at' => 1792000200 ), 7200 ) );
		$this->assertSame( 'early', Reasons::window_variant( $row, array( 'created_at' => 1791990000, 'claim_at' => 1792000000 - 1200 ), 7200 ) );
		$this->assertSame( 'created', Reasons::window_variant( $row, array( 'created_at' => 1791990000, 'claim_at' => 1792000000 - 300 ), 7200 ) );
		$this->assertSame( 'created', Reasons::window_variant( $row, null, 7200 ) );
	}

	/** What is left to pay, rounded up to the pound when the store does. */
	public function test_remainder() {
		$order = array(
			'price_minor' => 13950,
			'paid_minor'  => 0,
		);
		$this->assertSame( 14000, Reasons::remainder( $order, true ) );
		$this->assertSame( 13950, Reasons::remainder( $order, false ) );
		$this->assertSame( 0, Reasons::remainder( array( 'price_minor' => 100, 'paid_minor' => 500 ), true ) );
	}

	/** The wallet a pasted link names, in the three address forms. */
	public function test_wallet_of_url() {
		$this->assertSame( 'k7q2m9x4p1rz', Wallets::wallet_of_url( 'https://shop.example/wp-json/qersh/v1/webhook/k7q2m9x4p1rz' ) );
		$this->assertSame( 'k7q2m9x4p1rz', Wallets::wallet_of_url( 'https://shop.example/index.php?rest_route=%2Fqersh%2Fv1%2Fwebhook%2Fk7q2m9x4p1rz' ) );
		$this->assertSame( 'k7q2m9x4p1rz', Wallets::wallet_of_url( 'https://shop.example/?wc-api=qersh&qw=k7q2m9x4p1rz' ) );
		$this->assertNull( Wallets::wallet_of_url( 'https://n8n.example/webhook/abc' ) );
		$this->assertNull( Wallets::wallet_of_url( 'https://shop.example/wp-json/qersh/v1/webhook/k7q2m9x4p1rzz' ) );
	}

	/** Suggestions of Orders::suggestions() in the screen's shape. */
	public function test_normalize_suggestion() {
		$ready = Transfers::normalize_suggestion(
			array(
				'order_id'  => 1042,
				'source'    => 'claim',
				'ready'     => true,
				'preselect' => true,
				'live'      => true,
				'reason'    => null,
				'args'      => array(),
			)
		);
		$this->assertTrue( $ready['ready'] );
		$this->assertTrue( $ready['claimant'] );
		$this->assertSame( 1042, $ready['params']['n'] );

		$contested = Transfers::normalize_suggestion(
			array(
				'order_id' => 1042,
				'source'   => 'claim',
				'ready'    => false,
				'live'     => true,
				'reason'   => 'txn_contested',
				'args'     => array(
					'N'    => 1042,
					'M'    => 1043,
					'form' => 'two',
				),
			)
		);
		$this->assertFalse( $contested['ready'] );
		$this->assertSame( 'txn_contested', $contested['reason'] );
		$this->assertSame( 1043, $contested['params']['m'] );
		$this->assertSame( 'two', $contested['params']['variant'] );

		$dead = Transfers::normalize_suggestion(
			array(
				'order_id'  => 7,
				'source'    => 'claim',
				'ready'     => true,
				'preselect' => true,
				'live'      => false,
			)
		);
		$this->assertTrue( $dead['dead'] );
		$this->assertFalse( $dead['ready'], 'a dead order is never ready' );
		$this->assertFalse( $dead['preselect'], 'a dead order is never preselected' );

		$this->assertSame( 9, Transfers::normalize_suggestion( '9' )['order_id'] );
		$this->assertNull( Transfers::normalize_suggestion( array( 'order_id' => 0 ) ) );
		$this->assertNull( Transfers::normalize_suggestion( 'x' ) );
	}

	/** Transfer ids as stored. */
	public function test_clean_transfer_id() {
		$this->assertSame( 'trf_01JA2Y4X5M7Q9R0S1T2U3V4W5Z', Transfers::clean_transfer_id( 'trf_01JA2Y4X5M7Q9R0S1T2U3V4W5Z' ) );
		$this->assertSame( '', Transfers::clean_transfer_id( 'trf 1' ) );
		$this->assertSame( '', Transfers::clean_transfer_id( str_repeat( 'a', 41 ) ) );
	}

	/** Pounds in the settings fields. */
	public function test_settings_amounts() {
		$this->assertSame( '', Settings::pounds( null ) );
		$this->assertSame( '100', Settings::pounds( 10000 ) );
		$this->assertSame( '99.50', Settings::pounds( 9950 ) );
		$this->assertSame( '0.05', Settings::pounds( 5 ) );
		$this->assertSame(
			array(
				'ok'    => true,
				'value' => null,
			),
			Settings::to_minor( '  ' )
		);
		$this->assertSame( 150000, Settings::to_minor( '1,500' )['value'] );
		$this->assertSame( 9950, Settings::to_minor( '99.5' )['value'] );
		$this->assertFalse( Settings::to_minor( '-3' )['ok'] );
		$this->assertFalse( Settings::to_minor( '1.999' )['ok'] );
	}

	/** The data goes only when asked; the constant decides instead of the setting when defined. */
	public function test_uninstall_should_remove() {
		$on  = array( 'store' => array( 'remove_data_on_uninstall' => true ) );
		$off = array( 'store' => array( 'remove_data_on_uninstall' => false ) );
		$this->assertTrue( Uninstall::should_remove( $on, false, null ) );
		$this->assertFalse( Uninstall::should_remove( $off, false, null ) );
		$this->assertFalse( Uninstall::should_remove( array(), false, null ) );
		$this->assertFalse( Uninstall::should_remove( null, false, null ) );
		$this->assertTrue( Uninstall::should_remove( $off, true, true ) );
		$this->assertFalse( Uninstall::should_remove( $on, true, false ) );
		$this->assertFalse( Uninstall::should_remove( array( 'store' => array( 'remove_data_on_uninstall' => 'yes' ) ), false, null ) );
	}
}
