<?php
/**
 * The plugin never depends on the type of the e-wallet (the owner's decision of 2026-10-18): the same delivery,
 * sent with wallet.provider (and the template's provider in the checks) set to each e-wallet type of the qersh.v1
 * schema, to a type nobody knows yet and to '', gets the same HTTP status, the same reply body (apart from the ids),
 * the same transfer verdict and the same order state. The type is only copied into qersh_transfers.provider, as
 * information. A wallet saved with a type by an earlier version keeps working, its type dropped.
 *
 * Each run gets its own wallet (same label, same number), its own order and payer number and its own transaction
 * id, so that no run sees another: payers and transaction ids are store-wide (the payer's orders, rule 2).
 *
 * @package QershPay\WooCommerce\Tests
 */

namespace QershPay\WooCommerce\Tests\Integration;

use QershPay\WooCommerce\Core\Config;

defined( 'ABSPATH' ) || exit;

/**
 * One delivery, every wallet type.
 */
class WalletTypeInvarianceTest extends TestCase {

	/**
	 * The values of wallet.provider each scenario is sent with: the first is the reference.
	 */
	const PROVIDERS = array( 'vodafone_cash', 'orange_cash', 'etisalat_cash', 'instapay_app', 'qersh_test_wallet', '' );

	/**
	 * Scenarios, each with the reply status and the order state every run must reach.
	 *
	 * @return array[]
	 */
	public static function scenarios() {
		return array(
			'the payer\'s number, the order total'        => array( 'payer', 'matched', 'paid' ),
			'no sender number, the id the customer typed' => array( 'none_claim', 'matched', 'paid' ),
			'a masked sender, the id the customer typed'  => array( 'masked_claim', 'matched', 'paid' ),
			'a handle only, the id the customer typed'    => array( 'handle_claim', 'matched', 'paid' ),
			'no sender number, no id typed'               => array( 'none', 'review', 'open' ),
			'a masked sender, no id typed'                => array( 'masked', 'review', 'open' ),
			'no order of the payer (personal)'            => array( 'personal', 'unmatched', null ),
			'not confirmed by the phone'                  => array( 'unconfirmed', 'review', 'open' ),
		);
	}

	/**
	 * The same delivery with every wallet type: one outcome.
	 *
	 * @dataProvider scenarios
	 *
	 * @param string      $scenario Scenario.
	 * @param string      $status   The reply status of every run.
	 * @param string|null $state    The order row's state of every run (null: no order).
	 */
	public function test_the_wallet_type_changes_nothing( $scenario, $status, $state ): void {
		$reference = null;
		foreach ( self::PROVIDERS as $run => $provider ) {
			$seen = $this->run_scenario( $scenario, $provider, $run );
			$this->assertSame( $status, isset( $seen['body']['status'] ) ? $seen['body']['status'] : null, $scenario . ', wallet.provider "' . $provider . '"' );
			$this->assertSame( $state, null === $seen['order'] ? null : $seen['order']['state'], $scenario . ', wallet.provider "' . $provider . '"' );
			if ( null === $reference ) {
				$reference = $seen;
				continue;
			}
			$this->assertSame( $reference, $seen, $scenario . ': wallet.provider "' . $provider . '" changed something' );
		}
	}

	/**
	 * qersh_transfers.provider is the envelope's wallet.provider as the phone sent it, '' for an empty one, and any
	 * value is accepted.
	 */
	public function test_the_transfer_keeps_the_envelope_type_as_information(): void {
		foreach ( self::PROVIDERS as $provider ) {
			$wallet = $this->wallet();
			$sent   = $this->deliver(
				'transfer.received',
				$wallet,
				array(
					'payer'        => '01122334460',
					'amount_minor' => 7700,
					'provider'     => $provider,
				)
			);
			$this->assertSame( 200, $sent['response']->get_status(), $provider );
			$transfer = $this->plugin->transfers()->get( $sent['envelope']['data']['transfer_id'] );
			$this->assertNotNull( $transfer, $provider );
			$this->assertSame( $provider, (string) $transfer['provider'] );
		}
	}

	/**
	 * Every qersh.v1 delivery carries wallet.provider as a string (the kit's envelope check, unchanged): an envelope
	 * without it is not a qersh.v1 envelope, so '' is the only "no type" a transfer row ever gets.
	 */
	public function test_an_envelope_without_a_wallet_type_is_refused_as_not_qersh_v1(): void {
		$wallet = $this->wallet();
		$env    = $this->envelope( 'transfer.received', $wallet, array( 'amount_minor' => 7700 ) );
		unset( $env['wallet']['provider'] );
		$body     = (string) wp_json_encode( $env, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$response = $this->post_webhook( $wallet['id'], $body, $this->sign( $body, $wallet['id'] ) );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'qersh_invalid_envelope', $response->get_data()['code'] );
		$this->assertSame( 'not_qersh_v1', $response->get_data()['data']['reason'] );
		$this->assertNull( $this->plugin->transfers()->get( $env['data']['transfer_id'] ) );
	}

	/**
	 * A wallet stored with a type by an earlier version: the entry is read without it, deliveries match as for any
	 * wallet, and the next save stores it without the type.
	 */
	public function test_a_wallet_type_saved_by_an_earlier_version_is_dropped(): void {
		$wallet = $this->wallet();
		$stored = get_option( 'qersh_wc_config', array() );
		foreach ( $stored['wallets'] as $i => $entry ) {
			if ( $entry['id'] === $wallet['id'] ) {
				$stored['wallets'][ $i ]['provider'] = 'orange_cash';
			}
		}
		update_option( 'qersh_wc_config', $stored, false );
		$this->plugin->forget_config();

		$entry = $this->plugin->wallet( $wallet['id'] );
		$this->assertNotNull( $entry );
		$this->assertArrayNotHasKey( 'provider', $entry );
		$this->assertEqualsCanonicalizing( Config::WALLET_FIELDS, array_keys( $entry ) );

		$order = $this->order( $this->product( '140' ) );
		$this->assertSame( 'success', $this->pay( $order, '01122334461', $wallet['id'] )['result'] );
		$sent = $this->deliver(
			'transfer.received',
			$wallet,
			array(
				'payer'        => '01122334461',
				'amount_minor' => 14000,
				'provider'     => 'vodafone_cash',
			)
		);
		$this->assertSame( 'matched', $sent['response']->get_data()['status'], 'the stored type and the envelope\'s disagree, and nothing cares' );

		$config = $this->plugin->config_store()->get();
		foreach ( $config['wallets'] as $i => $item ) {
			$config['wallets'][ $i ]['provider'] = 'orange_cash'; // As an old export would carry it.
		}
		$this->save_config( $config );
		foreach ( get_option( 'qersh_wc_config', array() )['wallets'] as $item ) {
			$this->assertArrayNotHasKey( 'provider', $item );
		}
	}

	/**
	 * An InstaPay wallet a test build saved (an address, no number): it is left out of the effective configuration,
	 * so the checkout never offers it and the Wallets screen names it; the next save, which starts from get(),
	 * succeeds, removes it from the option and marks its row deleted with its stored entry; restoring it is refused.
	 */
	public function test_a_wallet_saved_with_an_instapay_address_is_left_out(): void {
		$wallet              = $this->wallet();
		$legacy              = Config::new_wallet_id();
		$stored              = get_option( 'qersh_wc_config', array() );
		$stored['wallets'][] = array(
			'id'              => $legacy,
			'label'           => 'InstaPay',
			'provider'        => 'instapay_app',
			'receive_address' => 'shop@instapay',
			'enabled'         => true,
		);
		update_option( 'qersh_wc_config', $stored, false );
		$this->plugin->wallets()->ensure( $legacy );
		$this->plugin->forget_config();

		$this->assertNull( $this->plugin->wallet( $legacy ), 'never offered or served' );
		$this->assertNotNull( $this->plugin->wallet( $wallet['id'] ), 'the number wallets stay' );
		$this->assertSame(
			array(
				array(
					'id'      => $legacy,
					'label'   => 'InstaPay',
					'address' => 'shop@instapay',
				),
			),
			$this->plugin->config_store()->dropped()
		);

		$config                           = $this->plugin->config_store()->get();
		$config['store']['order_ttl_min'] = 90;
		$this->save_config( $config ); // The Settings screen's save: refused before, while the address was stored.
		$this->assertSame( array( $wallet['id'] ), array_column( get_option( 'qersh_wc_config', array() )['wallets'], 'id' ) );
		$this->assertSame( array(), $this->plugin->config_store()->dropped() );
		$row = $this->plugin->wallets()->get( $legacy );
		$this->assertSame( 'deleted', $row['state'] );
		$this->assertSame( 'shop@instapay', $row['snapshot']['receive_address'], 'the stored entry, without its type' );
		$this->assertArrayNotHasKey( 'provider', $row['snapshot'] );
		$this->assertNotSame( array(), Config::validate( Config::with_defaults( \QershPay\WooCommerce\Admin\Wallets::config_with_entry( $this->plugin->config_store()->get(), $row['snapshot'], $legacy ) ) ), 'a restore of it is refused' );
	}

	/**
	 * One run of a scenario with one wallet.provider: what the phone, the merchant and the customer can see of it,
	 * with the ids of the run replaced by their names.
	 *
	 * @param string $scenario Scenario.
	 * @param string $provider wallet.provider.
	 * @param int    $run      Index of the run (makes the payer number).
	 * @return array
	 */
	private function run_scenario( $scenario, $provider, $run ) {
		$wallet = $this->wallet();
		$payer  = '011224400' . sprintf( '%02d', $run );
		$txn    = self::txn_id();
		$order  = null;
		if ( 'personal' !== $scenario ) {
			$order = $this->order( $this->product( '140' ), $payer );
			$this->assertSame( 'success', $this->pay( $order, $payer, $wallet['id'] )['result'] );
		}
		if ( null !== $order && '_claim' === substr( $scenario, -6 ) ) {
			$saved = $this->plugin->claims()->submit( $this->fresh( $order->get_id() ), $txn, time() );
			$this->assertSame( 200, $saved['http'] );
		}

		$opts = array(
			'txn'          => $txn,
			'amount_minor' => 14000,
			'risk'         => 'low',
			'reasons'      => array(),
			'auto_ok'      => true,
			'provider'     => $provider,
		);
		$kind = explode( '_', $scenario )[0];
		if ( 'none' === $kind ) {
			$opts['payer'] = null;
		} elseif ( 'masked' === $kind ) {
			$opts['payer'] = '0100****567';
		} elseif ( 'handle' === $kind ) {
			$opts['handle'] = 'test.buyer';
		} else {
			$opts['payer'] = $payer;
		}
		if ( 'unconfirmed' === $scenario ) {
			$opts['risk']    = 'medium';
			$opts['reasons'] = array( 'balance_unavailable' );
			$opts['auto_ok'] = false;
		}
		$sent = $this->deliver( 'transfer.received', $wallet, $opts );

		$transfer_id = (string) $sent['envelope']['data']['transfer_id'];
		$transfer    = $this->plugin->transfers()->get( $transfer_id );
		$this->assertNotNull( $transfer );
		$this->assertSame( $provider, (string) $transfer['provider'], 'the type is kept as information' );

		$ids        = array(
			'transfer' => $transfer_id,
			'delivery' => (string) $sent['envelope']['delivery_id'],
			'wallet'   => (string) $wallet['id'],
			'txn'      => $txn,
			'payer'    => $payer,
		);
		$seen_order = null;
		if ( null !== $order ) {
			$ids['order'] = (string) $order->get_order_number();
			$this->plugin->completion()->run( $order->get_id() );
			$row        = $this->row( $order->get_id() );
			$seen_order = array(
				'state'      => (string) $row['state'],
				'paid_minor' => (int) $row['paid_minor'],
				'claim'      => self::neutral( $row['claim_txn'], $ids ),
				'status'     => $this->fresh( $order->get_id() )->get_status(),
			);
		}

		return array(
			'http'     => $sent['response']->get_status(),
			'body'     => self::neutral( $sent['response']->get_data(), $ids ),
			'transfer' => array(
				'outcome'      => $transfer['outcome'],
				'reason'       => $transfer['reason'],
				'note'         => self::neutral( $transfer['note'], $ids ),
				'flag'         => $transfer['flag'],
				'order'        => null === $transfer['order_id'] ? null : ( null !== $order && (int) $transfer['order_id'] === $order->get_id() ? 'this order' : 'another order' ),
				'payer_key'    => self::neutral( $transfer['payer_key'], $ids ),
				'payer_name'   => $transfer['payer_name'],
				'txn'          => self::neutral( $transfer['provider_txn_id'], $ids ),
				'amount_minor' => $transfer['amount_minor'],
				'claim_eval'   => $transfer['claim_eval'],
			),
			'order'    => $seen_order,
		);
	}

	/**
	 * A value with every id of the run, standing alone (not inside a longer word or number), replaced by its name in
	 * braces; arrays one value at a time.
	 *
	 * @param mixed $value Value.
	 * @param array $ids   Name => id.
	 * @return mixed
	 */
	private static function neutral( $value, array $ids ) {
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $key => $item ) {
				$out[ $key ] = self::neutral( $item, $ids );
			}
			return $out;
		}
		if ( is_int( $value ) && in_array( (string) $value, $ids, true ) ) {
			$value = (string) $value;
		}
		if ( ! is_string( $value ) ) {
			return $value;
		}
		foreach ( $ids as $name => $id ) {
			if ( '' === $id ) {
				continue;
			}
			$pattern = '/(?<![0-9A-Za-z_]|[0-9][.,])' . preg_quote( $id, '/' ) . '(?![0-9A-Za-z_]|[.,][0-9])/';
			$value   = (string) preg_replace( $pattern, '{' . $name . '}', $value );
		}
		return $value;
	}
}
