<?php
/**
 * Tests of the pure rules behind the review fixes of package E: which rows need a decision and which of them are
 * already linked to an order, which reason a row shows, the uninstall's unschedule calls, the wallet and settings
 * form checks, the restore's configuration, the exporter's pages and the self-test's skipped probes.
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
use QershPay\WooCommerce\Core\Config;
use QershPay\WooCommerce\Diagnostics;
use QershPay\WooCommerce\Privacy;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

defined( 'ABSPATH' ) || exit;

/**
 * Review fixes of package E, without WordPress.
 */
final class AdminReviewFixesTest extends TestCase {

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
				'note'            => null,
			),
			$fields
		);
	}

	/** The PHP twin of Store\Transfers::NEEDS_DECISION_SQL. */
	public function test_needs_decision(): void {
		$this->assertTrue( Reasons::needs_decision( $this->row( array( 'outcome' => 'review' ) ) ) );
		$this->assertTrue(
			Reasons::needs_decision(
				$this->row(
					array(
						'outcome'  => 'overpaid',
						'order_id' => 7,
					)
				)
			)
		);
		$this->assertTrue(
			Reasons::needs_decision(
				$this->row(
					array(
						'outcome'  => 'matched',
						'order_id' => 7,
						'flag'     => 'forgery',
						'reason'   => 'forgery',
					)
				)
			)
		);
		$this->assertTrue(
			Reasons::needs_decision(
				$this->row(
					array(
						'outcome' => null,
						'flag'    => 'forgery',
					)
				)
			)
		);
		$this->assertFalse(
			Reasons::needs_decision(
				$this->row(
					array(
						'outcome' => 'dismissed',
						'flag'    => 'forgery',
					)
				)
			)
		);
		$this->assertTrue(
			Reasons::needs_decision(
				$this->row(
					array(
						'outcome'  => 'partial',
						'order_id' => 7,
						'reason'   => 'partial_overdue',
					)
				)
			)
		);
		$this->assertTrue(
			Reasons::needs_decision(
				$this->row(
					array(
						'outcome'  => 'attached',
						'order_id' => 7,
						'reason'   => 'partial_overdue',
					)
				)
			),
			'an order paid in part by an attach'
		);
		$this->assertTrue(
			Reasons::needs_decision(
				$this->row(
					array(
						'outcome' => null,
						'reason'  => 'claim_overdue',
					)
				)
			)
		);
		$this->assertFalse(
			Reasons::needs_decision(
				$this->row(
					array(
						'outcome' => 'dismissed',
						'reason'  => 'claim_overdue',
					)
				)
			)
		);
		$this->assertFalse(
			Reasons::needs_decision(
				$this->row(
					array(
						'outcome'  => 'matched',
						'order_id' => 7,
					)
				)
			)
		);
		$this->assertFalse(
			Reasons::needs_decision(
				$this->row(
					array(
						'outcome' => 'unmatched',
						'reason'  => 'personal',
					)
				)
			)
		);
	}

	/** Reasons::needs_decision() says what Store\Transfers::NEEDS_DECISION_SQL says. */
	public function test_needs_decision_matches_the_sql(): void {
		$sql = \QershPay\WooCommerce\Store\Transfers::NEEDS_DECISION_SQL;
		$this->assertStringContainsString( "outcome IN ('review','overpaid')", $sql );
		$this->assertStringContainsString( "flag = 'forgery' AND ( outcome IS NULL OR outcome <> 'dismissed' )", $sql );
		$this->assertStringContainsString( "reason IN ('partial_overdue','claim_overdue') AND ( outcome IS NULL OR outcome <> 'dismissed' )", $sql, 'update Reasons::needs_decision() with the SQL' );
	}

	/** A linked row is never offered attach or dismiss; the linked rows that need a decision can be marked decided. */
	public function test_linked_rows(): void {
		$linked = array(
			$this->row(
				array(
					'outcome'  => 'overpaid',
					'order_id' => 7,
				)
			),
			$this->row(
				array(
					'outcome'  => 'matched',
					'order_id' => 7,
					'flag'     => 'forgery',
					'reason'   => 'forgery',
				)
			),
			$this->row(
				array(
					'outcome'  => 'review',
					'order_id' => 7,
					'reason'   => 'cancelled_paid',
				)
			),
			$this->row(
				array(
					'outcome'  => 'partial',
					'order_id' => 7,
					'reason'   => 'partial_overdue',
				)
			),
		);
		foreach ( $linked as $row ) {
			$this->assertFalse( Transfers::is_open( $row ) );
			$this->assertTrue( Transfers::is_resolvable( $row ) );
		}
		$this->assertTrue(
			Transfers::is_open(
				$this->row(
					array(
						'outcome' => 'review',
						'reason'  => 'no_payer',
					)
				)
			)
		);
		$this->assertTrue(
			Transfers::is_open(
				$this->row(
					array(
						'outcome' => 'review',
						'reason'  => 'claim_overdue',
					)
				)
			)
		);
		$this->assertTrue(
			Transfers::is_open(
				$this->row(
					array(
						'outcome' => 'unmatched',
						'reason'  => 'personal',
					)
				)
			)
		);
		$this->assertFalse( Transfers::is_resolvable( $this->row( array( 'outcome' => 'review' ) ) ), 'an unlinked row is attached or dismissed instead' );
		$this->assertFalse(
			Transfers::is_resolvable(
				$this->row(
					array(
						'outcome'  => 'matched',
						'order_id' => 7,
					)
				)
			)
		);
		$this->assertFalse(
			Transfers::is_resolvable(
				$this->row(
					array(
						'outcome'  => 'attached',
						'order_id' => 7,
					)
				)
			)
		);
	}

	/** Risk sentences only on rows that need a decision; a dismissed row shows its note. */
	public function test_pick_risk_only_when_deciding(): void {
		$risk = array(
			'risk_reasons' => array( 'notification_only' ),
			'provider'     => 'instapay_app',
		);
		$this->assertSame( 'notification_only', Reasons::pick( $this->row( $risk ) )['code'] );
		$this->assertSame(
			'notification_only',
			Reasons::pick(
				$this->row(
					$risk + array(
						'outcome'  => 'overpaid',
						'order_id' => 7,
					)
				)
			)['code']
		);
		$this->assertSame(
			'',
			Reasons::pick(
				$this->row(
					$risk + array(
						'outcome'  => 'attached',
						'order_id' => 7,
					)
				)
			)['code']
		);
		$this->assertSame(
			'',
			Reasons::pick(
				$this->row(
					$risk + array(
						'outcome'  => 'matched',
						'order_id' => 7,
					)
				)
			)['code']
		);
		$this->assertSame( '', Reasons::pick( $this->row( $risk + array( 'outcome' => 'unmatched' ) ) )['code'] );
		$this->assertSame(
			'',
			Reasons::pick(
				$this->row(
					array(
						'risk_reasons' => array( 'sim_mismatch' ),
						'outcome'      => 'partial',
						'order_id'     => 7,
					)
				)
			)['code']
		);
		$this->assertSame(
			'partial_overdue',
			Reasons::pick(
				$this->row(
					$risk + array(
						'outcome'  => 'partial',
						'order_id' => 7,
						'reason'   => 'partial_overdue',
					)
				)
			)['code']
		);
		$this->assertSame(
			'',
			Reasons::pick(
				$this->row(
					array(
						'outcome' => 'dismissed',
						'reason'  => 'claim_overdue',
					)
				)
			)['code']
		);
		$this->assertSame(
			'forgery',
			Reasons::pick(
				$this->row(
					array(
						'outcome' => 'dismissed',
						'flag'    => 'forgery',
					)
				)
			)['code']
		);
		$this->assertSame(
			'personal',
			Reasons::pick(
				$this->row(
					$risk + array(
						'outcome' => 'unmatched',
						'reason'  => 'personal',
					)
				)
			)['code']
		);
		$unknown = Reasons::pick(
			$this->row(
				array(
					'outcome' => 'unmatched',
					'reason'  => 'late_delivery',
				)
			)
		);
		$this->assertSame( array( 'unknown', 'late_delivery' ), array( $unknown['code'], $unknown['detail'] ) );
	}

	/** unknown_risk names the values this version does not know. */
	public function test_pick_unknown_risk_detail(): void {
		$pick = Reasons::pick(
			$this->row(
				array(
					'reason'       => 'unknown_risk',
					'risk_reasons' => array( 'no_baseline', 'brand_new_reason', 'another_one' ),
				)
			)
		);
		$this->assertSame( 'unknown_risk', $pick['code'] );
		$this->assertSame( 'brand_new_reason, another_one', $pick['detail'] );
		$this->assertSame(
			'',
			Reasons::pick(
				$this->row(
					array(
						'reason'       => 'unknown_risk',
						'risk_reasons' => array( 'no_baseline' ),
					)
				)
			)['detail']
		);
	}

	/** Reasons whose sentence already names the claimant's order are printed alone. */
	public function test_names_claimant(): void {
		foreach ( array( 'claim_overdue', 'txn_contested', 'txn_amount', 'txn_window', 'txn_wallet' ) as $code ) {
			$this->assertTrue( Reasons::names_claimant( $code ), $code );
		}
		foreach ( array( 'closed_order', 'stock_short', 'no_payer', '' ) as $code ) {
			$this->assertFalse( Reasons::names_claimant( $code ), $code );
		}
	}

	/** Never a hook and a group together: Action Scheduler would then match the arguments exactly. */
	public function test_uninstall_unschedule_calls(): void {
		$calls = Uninstall::unschedule_calls();
		$this->assertSame( array( '', array(), 'qersh' ), $calls[0] );
		$hooks = array();
		foreach ( $calls as $call ) {
			$this->assertTrue( '' === $call[0] || '' === $call[2], 'hook or group, not both' );
			$this->assertSame( array(), $call[1] );
			if ( '' !== $call[0] ) {
				$hooks[] = $call[0];
			}
		}
		$this->assertSame( Uninstall::HOOKS, $hooks );
		$this->assertContains( 'qersh_wc_complete', $hooks );
		$this->assertContains( 'qersh_wc_expire', $hooks );
		$this->assertContains( 'qersh_wc_claim_eval', $hooks );
	}

	/** The wallet form's address, by Core\Config's rule, in the merchant's words. */
	public function test_address_problem(): void {
		$this->assertSame( '', Wallets::address_problem( 'vodafone_cash', '01001234567' ) );
		$this->assertSame( '', Wallets::address_problem( 'vodafone_cash', '+20 100 123 4567' ) );
		$this->assertSame( 'number', Wallets::address_problem( 'vodafone_cash', '0100123456' ) );
		$this->assertSame( 'number', Wallets::address_problem( 'orange_cash', 'shop@instapay' ) );
		$this->assertSame( '', Wallets::address_problem( 'instapay_app', 'shop@instapay' ) );
		$this->assertSame( 'instapay', Wallets::address_problem( 'instapay_app', '01001234567' ) );
		$this->assertSame( 'instapay', Wallets::address_problem( 'instapay_app', '' ) );
		// Whatever address_problem() accepts, Core\Config accepts.
		foreach ( array( array( 'vodafone_cash', '٠١٠٠١٢٣٤٥٦٧' ), array( 'bank_cib', '01001234567' ), array( 'instapay_app', 'Shop@InstaPay' ) ) as $case ) {
			if ( '' !== Wallets::address_problem( $case[0], $case[1] ) ) {
				continue;
			}
			$config = Config::with_defaults(
				array(
					'wallets' => array(
						array(
							'id'              => 'k7q2m9x4p1rz',
							'label'           => 'Shop',
							'provider'        => $case[0],
							'receive_address' => $case[1],
							'enabled'         => true,
						),
					),
				)
			);
			$this->assertSame( array(), Config::validate( $config ), $case[0] . ' ' . $case[1] );
		}
	}

	/** The restore's configuration: the saved entry is back, valid; an incomplete one is refused before any change. */
	public function test_config_with_entry(): void {
		$config   = Config::with_defaults(
			array(
				'wallets' => array(
					array(
						'id'              => 'aaaaaaaaaaaa',
						'label'           => 'Main',
						'provider'        => 'vodafone_cash',
						'receive_address' => '01001234567',
						'enabled'         => true,
					),
				),
			)
		);
		$snapshot = array(
			'id'              => 'k7q2m9x4p1rz',
			'label'           => 'Orange',
			'provider'        => 'orange_cash',
			'receive_address' => '01201234567',
			'enabled'         => true,
			'extra'           => 'dropped',
		);
		$out      = Wallets::config_with_entry( $config, $snapshot, 'k7q2m9x4p1rz' );
		$this->assertCount( 2, $out['wallets'] );
		$this->assertSame( 'k7q2m9x4p1rz', $out['wallets'][1]['id'] );
		$this->assertArrayNotHasKey( 'extra', $out['wallets'][1] );
		$this->assertSame( array(), Config::validate( Config::with_defaults( $out ) ) );

		$again = Wallets::config_with_entry( $out, $snapshot, 'k7q2m9x4p1rz' );
		$this->assertCount( 2, $again['wallets'], 'never twice' );

		$bare = Wallets::config_with_entry( $config, array( 'id' => 'k7q2m9x4p1rz' ), 'k7q2m9x4p1rz' );
		$this->assertNotEmpty( Config::validate( Config::with_defaults( $bare ) ), 'a snapshot without type or address is refused' );
	}

	/** The settings form's values, by Core\Config's store rules. */
	public function test_store_problems(): void {
		$ok = array(
			'order_ttl_min'            => 120,
			'unmatched_retention_days' => 90,
			'min_total_minor'          => null,
			'max_total_minor'          => null,
		);
		$this->assertSame( array(), Settings::store_problems( $ok ) );
		$this->assertSame( array( 'order_ttl' ), Settings::store_problems( array( 'order_ttl_min' => 14 ) + $ok ) );
		$this->assertSame( array( 'order_ttl' ), Settings::store_problems( array( 'order_ttl_min' => 1441 ) + $ok ) );
		$this->assertSame( array(), Settings::store_problems( array( 'order_ttl_min' => 15 ) + $ok ) );
		$this->assertSame( array( 'retention' ), Settings::store_problems( array( 'unmatched_retention_days' => 0 ) + $ok ) );
		$this->assertSame( array( 'retention' ), Settings::store_problems( array( 'unmatched_retention_days' => 3651 ) + $ok ) );
		$this->assertSame(
			array( 'min_max' ),
			Settings::store_problems(
				array(
					'min_total_minor' => 50000,
					'max_total_minor' => 10000,
				) + $ok
			)
		);
		$this->assertSame(
			array(),
			Settings::store_problems(
				array(
					'min_total_minor' => 10000,
					'max_total_minor' => 10000,
				) + $ok
			)
		);
		$this->assertSame( array(), Settings::store_problems( array( 'min_total_minor' => 50000 ) + $ok ) );
		// Whatever store_problems() accepts here, Core\Config accepts.
		$this->assertSame(
			array(),
			Config::validate(
				Config::with_defaults(
					array(
						'store' => array(
							'order_ttl_min'            => 15,
							'unmatched_retention_days' => 3650,
							'min_total_minor'          => 100,
							'max_total_minor'          => 100,
						),
					)
				)
			)
		);
	}

	/** The exporter's pages go through payer keys, each once. */
	public function test_privacy_key_page(): void {
		$keys = array( 'a', 'b', 'c', 'd', 'e', 'f', 'g' );
		$this->assertSame(
			array(
				'keys' => array( 'a', 'b', 'c' ),
				'done' => false,
			),
			Privacy::key_page( $keys, 1, 3 )
		);
		$this->assertSame( array( 'd', 'e', 'f' ), Privacy::key_page( $keys, 2, 3 )['keys'] );
		$this->assertSame(
			array(
				'keys' => array( 'g' ),
				'done' => true,
			),
			Privacy::key_page( $keys, 3, 3 )
		);
		$this->assertSame(
			array(
				'keys' => array(),
				'done' => true,
			),
			Privacy::key_page( array(), 1, 5 )
		);
		$this->assertTrue( Privacy::key_page( array( 'a', 'b', 'c' ), 1, 3 )['done'] );
		$this->assertSame( array( 'a', 'b' ), Privacy::key_page( array( 'a', 'a', 'b' ), 1, 5 )['keys'] );
		$all = array();
		for ( $page = 1; $page <= 3; $page++ ) {
			$all = array_merge( $all, Privacy::key_page( $keys, $page, 3 )['keys'] );
		}
		$this->assertSame( $keys, $all, 'every key on exactly one page' );
	}

	/**
	 * The self-test's steps for a wallet whose phone last used an old domain.
	 *
	 * @return array[]
	 */
	private function steps(): array {
		return array(
			array( 'current', 'https://shop.example/wp-json/qersh/v1/webhook/k7q2m9x4p1rz', 'webhook', '' ),
			array( 'saved', 'https://old.example/wp-json/qersh/v1/webhook/k7q2m9x4p1rz', 'webhook', '' ),
			array( 'fallback', 'https://shop.example/?wc-api=qersh&qw=k7q2m9x4p1rz', 'webhook', 'current' ),
			array( 'txn', 'https://shop.example/wp-json/qersh/v1/order-txn', 'txn', '' ),
			array( 'txn_fallback', 'https://shop.example/?wc-api=qersh&qo=txn', 'txn', 'txn' ),
		);
	}

	/**
	 * A fake probe() that answers by host and name.
	 *
	 * @param array $verdicts Verdict by probe name (default ok).
	 * @param array $sent     Names sent, filled in.
	 * @return callable
	 */
	private function sender( array $verdicts, array &$sent ): callable {
		return function ( $name, $url, $expect ) use ( $verdicts, &$sent ) {
			$sent[] = $name;
			return array(
				'name'    => $name,
				'url'     => $url,
				'code'    => 0,
				'verdict' => isset( $verdicts[ $name ] ) ? $verdicts[ $name ] : 'ok',
				'detail'  => $expect,
			);
		};
	}

	/** Fallbacks only after a failure; an unreachable host costs one timeout, and its later probes are skipped. */
	public function test_run_probes(): void {
		$sent   = array();
		$probes = Diagnostics::run_probes( $this->steps(), $this->sender( array(), $sent ) );
		$this->assertSame( array( 'current', 'saved', 'txn' ), $sent, 'no fallback when the first address works' );
		$this->assertCount( 3, $probes );

		$sent   = array();
		$probes = Diagnostics::run_probes(
			$this->steps(),
			$this->sender(
				array(
					'current' => 'blocked',
					'txn'     => 'blocked',
				),
				$sent
			)
		);
		$this->assertSame( array( 'current', 'saved', 'fallback', 'txn', 'txn_fallback' ), $sent );

		$sent   = array();
		$probes = Diagnostics::run_probes( $this->steps(), $this->sender( array( 'current' => 'inconclusive' ), $sent ) );
		$this->assertSame( array( 'current', 'saved' ), $sent, 'only the other host is still asked' );
		$verdicts = array();
		foreach ( $probes as $probe ) {
			$verdicts[ $probe['name'] ] = $probe['verdict'];
		}
		$this->assertSame(
			array(
				'current'  => 'inconclusive',
				'saved'    => 'ok',
				'fallback' => 'skipped',
				'txn'      => 'skipped',
			),
			$verdicts,
			'skipped probes are reported, and a skipped probe brings no fallback'
		);

		$sent = array();
		Diagnostics::run_probes( $this->steps(), $this->sender( array( 'saved' => 'inconclusive' ), $sent ) );
		$this->assertSame( array( 'current', 'saved', 'txn' ), $sent, 'an old host that is gone does not stop the others' );
	}
}
