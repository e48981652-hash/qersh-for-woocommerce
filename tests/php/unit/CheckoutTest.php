<?php
/**
 * Tests of the pure rules of package C: the customer-facing status, the amount to transfer, the confirmation line
 * (one for every wallet), the status line, the store's limits, and the route helpers. WordPress-free.
 *
 * @package QershPay\WooCommerce\Tests
 */

declare( strict_types=1 );

namespace QershPay\WooCommerce\Tests\Unit;

use QershPay\WooCommerce\Checkout;
use QershPay\WooCommerce\ClaimRoute;
use QershPay\WooCommerce\OrderStatusRoute;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

defined( 'ABSPATH' ) || exit;

/**
 * Checkout, OrderStatusRoute and ClaimRoute helpers.
 */
final class CheckoutTest extends TestCase {

	/** Every qersh_orders state maps to one of the six customer statuses (CONTRACTS.md §12). */
	public function test_status_from_state(): void {
		$cases = array(
			'open'     => 'waiting',
			'partial'  => 'partial',
			'paid'     => 'paid',
			'overpaid' => 'paid',
			'review'   => 'review',
			'expired'  => 'expired',
			'closed'   => 'cancelled',
		);
		foreach ( $cases as $state => $status ) {
			$this->assertSame( $status, Checkout::status_of( $state, 'pending' ), $state );
		}
	}

	/** Where the row stops following the order, the merchant's status wins. */
	public function test_status_settled_by_hand(): void {
		foreach ( array( 'processing', 'completed' ) as $wc ) {
			foreach ( array( 'closed', 'review', 'expired' ) as $state ) {
				$this->assertSame( 'paid', Checkout::status_of( $state, $wc ), "$state + $wc: confirmed by hand" );
			}
			$this->assertSame( 'paid', Checkout::status_of( 'paid', $wc ) );
		}
		$this->assertSame( 'review', Checkout::status_of( 'closed', 'on-hold' ), 'moved to on-hold by hand' );
		$this->assertSame( 'review', Checkout::status_of( 'review', 'on-hold' ), 'a forgery hold' );
		foreach ( array( 'cancelled', 'refunded', 'failed', 'trash' ) as $wc ) {
			$this->assertSame( 'cancelled', Checkout::status_of( 'closed', $wc ), "closed + $wc" );
		}
		$this->assertSame( 'expired', Checkout::status_of( 'expired', 'cancelled' ), 'our expiry' );
		$this->assertSame( 'review', Checkout::status_of( 'review', 'cancelled' ), 'still for the merchant to decide' );
		$this->assertSame( 'waiting', Checkout::status_of( 'open', 'processing' ), 'an open row keeps its state' );
	}

	/** A completed payment whose order was cancelled or refunded afterwards shows cancelled, unless still completing. */
	public function test_status_paid_then_cancelled(): void {
		foreach ( array( 'cancelled', 'refunded', 'trash' ) as $wc ) {
			$this->assertSame( 'cancelled', Checkout::status_of( 'paid', $wc ), $wc );
			$this->assertSame( 'cancelled', Checkout::status_of( 'overpaid', $wc ), $wc );
			$this->assertSame( 'paid', Checkout::status_of( 'paid', $wc, true ), "$wc: Completion is still completing it" );
		}
		$this->assertSame( 'paid', Checkout::status_of( 'paid', 'pending' ), 'a paid decision waiting for its completion' );
		$this->assertSame( 'paid', Checkout::status_of( 'paid', 'failed' ) );
		$this->assertSame( 'paid', Checkout::status_of( 'paid', 'processing' ) );
	}

	/** The third argument of status_of(): a paid row whose completion is due or running. */
	public function test_completing(): void {
		$this->assertFalse( Checkout::completing( null ) );
		$this->assertTrue(
			Checkout::completing(
				array(
					'state'      => 'paid',
					'completion' => 'due',
				)
			)
		);
		$this->assertTrue(
			Checkout::completing(
				array(
					'state'      => 'paid',
					'completion' => 'running',
				)
			)
		);
		$this->assertFalse(
			Checkout::completing(
				array(
					'state'      => 'paid',
					'completion' => 'done',
				)
			)
		);
		$this->assertFalse(
			Checkout::completing(
				array(
					'state'      => 'review',
					'completion' => 'due',
				)
			)
		);
		$this->assertFalse( Checkout::completing( array( 'state' => 'paid' ) ), 'a row without the column' );
	}

	/** The "confirmed" line: in the Processing email, else in the first Completed email (both parts of a multipart). */
	public function test_confirmed_line_due(): void {
		$this->assertTrue( Checkout::confirmed_line_due( 'customer_processing_order', '' ) );
		$this->assertTrue( Checkout::confirmed_line_due( 'customer_processing_order', 'customer_processing_order' ) );
		$this->assertTrue( Checkout::confirmed_line_due( 'customer_completed_order', '' ), 'an order that needs no processing' );
		$this->assertTrue( Checkout::confirmed_line_due( 'customer_completed_order', 'customer_completed_order' ), 'the other part, or a resend' );
		$this->assertFalse( Checkout::confirmed_line_due( 'customer_completed_order', 'customer_processing_order' ), 'shipped later: said already' );
		foreach ( array( 'customer_on_hold_order', 'customer_invoice', 'customer_note', 'new_order', '' ) as $id ) {
			$this->assertFalse( Checkout::confirmed_line_due( $id, '' ), $id );
		}
	}

	/** In WooCommerce's plain emails an apostrophe is typographic, so their entity catch-all never deletes it. */
	public function test_plain_apostrophes(): void {
		$this->assertSame( "Votre transfert de l\u{2019}ordre", Checkout::plain_apostrophes( "Votre transfert de l'ordre" ) );
		$this->assertSame( "l\u{2019}a, l\u{2019}b", Checkout::plain_apostrophes( "l'a, l\u{2019}b" ) );
		$text = "وصل تحويلك من \u{2066}0111***445\u{2069} وتمّ تأكيد طلبك.";
		$this->assertSame( $text, Checkout::plain_apostrophes( $text ), 'nothing else changes' );
		$this->assertStringNotContainsString( '&', Checkout::plain_apostrophes( "it's" ) );
	}

	/** Without a row, the WooCommerce status decides. */
	public function test_status_without_row(): void {
		$this->assertSame( 'waiting', Checkout::status_of( null, 'pending' ) );
		$this->assertSame( 'paid', Checkout::status_of( null, 'processing' ) );
		$this->assertSame( 'paid', Checkout::status_of( null, 'completed' ) );
		$this->assertSame( 'cancelled', Checkout::status_of( null, 'cancelled' ) );
		$this->assertSame( 'review', Checkout::status_of( null, 'on-hold' ) );
		$this->assertSame( 'waiting', Checkout::status_of( 'unknown', 'pending' ) );
		$allowed = array( 'waiting', 'partial', 'paid', 'review', 'expired', 'cancelled' );
		foreach ( array( 'pending', 'processing', 'completed', 'cancelled', 'refunded', 'failed', 'on-hold', 'draft', 'checkout-draft' ) as $wc ) {
			$this->assertContains( Checkout::status_of( null, $wc ), $allowed, $wc );
		}
	}

	/** The amount: what remains, rounded up to the pound when the store allows it (139.50 → 140). */
	public function test_amounts(): void {
		$this->assertSame(
			array(
				'exact' => 13950,
				'due'   => 14000,
			),
			Checkout::amounts( 13950, 0, true )
		);
		$this->assertSame(
			array(
				'exact' => 13950,
				'due'   => 13950,
			),
			Checkout::amounts( 13950, 0, false )
		);
		$this->assertSame(
			array(
				'exact' => 3950,
				'due'   => 4000,
			),
			Checkout::amounts( 13950, 10000, true )
		);
		$this->assertSame(
			array(
				'exact' => 0,
				'due'   => 0,
			),
			Checkout::amounts( 10000, 12000, true ),
			'overpaid never goes below zero'
		);
		$this->assertSame( 14000, Checkout::amounts( 14000, -5, true )['due'], 'a negative paid amount counts as zero' );
	}

	/** One confirmation line for every wallet (docs/17 §3), its transaction-id part only where the page takes one. */
	public function test_confirm_kind(): void {
		$this->assertSame( 'txn', Checkout::confirm_kind( true ) );
		$this->assertSame( 'plain', Checkout::confirm_kind( false ), 'transaction id entry off, or the order page takes none now' );
	}

	/**
	 * A wallet entry of any wallet takes transaction ids, turned off or not (turning off only hides it from the
	 * checkout). The type an older entry still carries changes nothing.
	 */
	public function test_txn_wallet(): void {
		$wallet = array(
			'id'              => 'ccccccccccc3',
			'label'           => 'Main',
			'receive_address' => '01001234567',
			'enabled'         => true,
		);
		$this->assertTrue( Checkout::txn_wallet( $wallet ) );
		$off            = $wallet;
		$off['enabled'] = false;
		$this->assertTrue( Checkout::txn_wallet( $off ), 'a turned-off wallet keeps the field of its waiting orders' );
		unset( $off['enabled'] );
		$this->assertTrue( Checkout::txn_wallet( $off ) );
		$this->assertFalse( Checkout::txn_wallet( null ), 'a deleted wallet has no entry' );
		foreach ( array( 'vodafone_cash', 'etisalat_cash', 'orange_cash', 'instapay_app', 'bank_nbe', 'some_new_wallet', '' ) as $provider ) {
			$legacy             = $wallet;
			$legacy['provider'] = $provider;
			$this->assertTrue( Checkout::txn_wallet( $legacy ), "a legacy provider '$provider' is ignored" );
		}
		$this->assertTrue( Checkout::txn_wallet( array( 'id' => 'ddddddddddd4' ) ), 'an id is all it needs' );
		$this->assertFalse( Checkout::txn_wallet( array( 'receive_address' => '01001234567' ) ), 'an entry without an id' );
		$this->assertFalse( Checkout::txn_wallet( array( 'id' => '' ) ) );
		$this->assertFalse( Checkout::txn_wallet( array( 'id' => 42 ) ), 'an id is a string' );
	}

	/** After 15 minutes: the transaction-id reminder only while the field is there and empty, else the base line. */
	public function test_nudge_key(): void {
		$this->assertSame( 'nudge_other', Checkout::nudge_key( 'waiting', true, false, false ), 'no field' );
		$this->assertSame( 'nudge_other', Checkout::nudge_key( 'waiting', true, false, true ), 'no field: the reminder would point at nothing' );
		$this->assertSame( 'nudge_txn', Checkout::nudge_key( 'waiting', true, true, false ) );
		$this->assertSame( '', Checkout::nudge_key( 'waiting', true, true, true ), 'an id was entered' );
		$this->assertSame( '', Checkout::nudge_key( 'waiting', false, true, false ), 'before 15 minutes' );
		$this->assertSame( '', Checkout::nudge_key( 'waiting', false, false, false ), 'before 15 minutes' );
		foreach ( array( 'partial', 'paid', 'review', 'expired', 'cancelled' ) as $status ) {
			$this->assertSame( '', Checkout::nudge_key( $status, true, true, false ), $status );
			$this->assertSame( '', Checkout::nudge_key( $status, true, false, false ), $status );
		}
	}

	/** The status line: one waiting line for every wallet; the neutral saved line once a transaction id is saved. */
	public function test_status_line_key(): void {
		$this->assertSame( 'waiting', Checkout::status_line_key( 'waiting', false ) );
		$this->assertSame( 'saved', Checkout::status_line_key( 'waiting', true ) );
		foreach ( array( 'partial', 'paid', 'review', 'expired', 'cancelled' ) as $status ) {
			$this->assertSame( $status, Checkout::status_line_key( $status, true ), $status );
			$this->assertSame( $status, Checkout::status_line_key( $status, false ), $status );
		}
		$this->assertSame( 'waiting', Checkout::status_line_key( 'other', false ) );
	}

	/** Every refusal code of the transaction-id reader has a text on the order page (Claims::message()). */
	public function test_txn_error_codes(): void {
		foreach ( array( 'qersh_txn_empty', 'qersh_txn_too_long', 'qersh_txn_format', 'qersh_txn_wallet_number', 'qersh_txn_several' ) as $code ) {
			$this->assertContains( $code, Checkout::TXN_ERROR_CODES, $code );
		}
		foreach ( array( 'qersh_txn_wait', 'qersh_txn_locked', 'qersh_txn_closed' ) as $code ) {
			$this->assertContains( $code, Checkout::TXN_ERROR_CODES, "the route's own $code" );
		}
		$this->assertSame( array_values( array_unique( Checkout::TXN_ERROR_CODES ) ), Checkout::TXN_ERROR_CODES );
		$this->assertNotContains( 'other', Checkout::TXN_ERROR_CODES, 'added by txn_error_texts()' );
	}

	/**
	 * What the customer reads (checkout, order page, email) never names a wallet type or brand, never says "*9#",
	 * and the storefront code never reads the type of a wallet: the plugin does not know it.
	 */
	public function test_storefront_never_names_a_wallet_type(): void {
		$files  = array(
			'src/Checkout.php',
			'src/Gateway.php',
			'src/PayerField.php',
			'src/BlocksIntegration.php',
			'src/Emails/Instructions.php',
			'templates/checkout/payer-field.php',
			'templates/order/thankyou-box.php',
			'templates/order/txn-box.php',
			'templates/emails/instructions.php',
			'templates/emails/plain/instructions.php',
			'assets/js/payer-field.js',
			'assets/js/checkout-blocks.js',
			'assets/js/thankyou.js',
		);
		$brands = '/vodafone|orange|etisalat|e&\s*(money|cash)|instapay|\bbank_|\bCIB\b|EGBANK|\*9#|فودافون|أورانج|اورنج|اتصالات|انستا ?باي/iu';
		foreach ( $files as $file ) {
			$source = qersh_wc_test_fixture( $file );
			$this->assertSame( 0, preg_match( $brands, $source, $hit ), $file . ': ' . ( isset( $hit[0] ) ? $hit[0] : '' ) );
			// The webhook's `provider_txn_id` is the transfer's id, not a type.
			$this->assertSame( 0, preg_match( '/provider(?!_txn_id)/i', $source, $hit ), $file . ': reads a wallet type' );
		}
	}

	/** The store's minimum and maximum. */
	public function test_total_allowed(): void {
		$this->assertTrue( Checkout::total_allowed( 100, null, null ) );
		$this->assertFalse( Checkout::total_allowed( 999, 1000, null ) );
		$this->assertTrue( Checkout::total_allowed( 1000, 1000, 1000 ) );
		$this->assertFalse( Checkout::total_allowed( 1001, null, 1000 ) );
		$this->assertSame( 500, Checkout::int_or_null( array( 'min_total_minor' => '500' ), 'min_total_minor' ) );
		$this->assertNull( Checkout::int_or_null( array( 'min_total_minor' => null ), 'min_total_minor' ) );
		$this->assertNull( Checkout::int_or_null( array( 'min_total_minor' => 'x' ), 'min_total_minor' ) );
		$this->assertNull( Checkout::int_or_null( array(), 'max_total_minor' ) );
	}

	/** The order id of a route body: a positive integer, or digits. */
	public function test_order_id_of(): void {
		$this->assertSame( 42, OrderStatusRoute::order_id_of( array( 'order_id' => 42 ) ) );
		$this->assertSame( 42, OrderStatusRoute::order_id_of( array( 'order_id' => '42' ) ) );
		$this->assertSame( 0, OrderStatusRoute::order_id_of( array( 'order_id' => '42abc' ) ) );
		$this->assertSame( 0, OrderStatusRoute::order_id_of( array( 'order_id' => -3 ) ) );
		$this->assertSame( 0, OrderStatusRoute::order_id_of( array( 'order_id' => 4.2 ) ) );
		$this->assertSame( 0, OrderStatusRoute::order_id_of( array( 'order_id' => array( 1 ) ) ) );
		$this->assertSame( 0, OrderStatusRoute::order_id_of( array() ) );
	}

	/** The 2 KB limit of the transaction-id route, for callers with a decoded body. */
	public function test_claim_body_size(): void {
		$this->assertFalse(
			ClaimRoute::too_big(
				array(
					'order_id' => 1,
					'key'      => 'wc_order_abc',
					'txn'      => str_repeat( 'x', 400 ),
				)
			)
		);
		$this->assertTrue(
			ClaimRoute::too_big(
				array(
					'order_id' => 1,
					'key'      => 'wc_order_abc',
					'txn'      => str_repeat( 'x', 2100 ),
				)
			)
		);
		$this->assertFalse( ClaimRoute::too_big( array( 'txn' => str_repeat( 'ر', 900 ) ) ), 'Arabic counts its UTF-8 bytes, unescaped' );
		$this->assertTrue( ClaimRoute::too_big( array( 'txn' => str_repeat( 'ر', 1100 ) ) ) );
	}
}
