<?php
/**
 * The customer email "Transfer instructions for your order #N".
 *
 * @package QershPay\WooCommerce
 */

namespace QershPay\WooCommerce\Emails;

use QershPay\WooCommerce\Checkout;
use QershPay\WooCommerce\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Sent at the end of each successful process_payment() of a wallet transfer order, after its meta and its
 * qersh_orders row are saved, through our own action qersh_wc_send_instructions ( order_id ), which Checkout adds to
 * woocommerce_email_actions (so it is deferred with the rest when WooCommerce defers emails). Never on
 * woocommerce_new_order or woocommerce_order_status_pending: both run before our data exists, and the second does
 * not run in the classic checkout (docs/17 §4.1). The same content as the thank-you box, with the deadline as date and
 * time in Cairo (no relative time: the email may be read later), and for an Orange Cash order a link to the order
 * page's transaction-id section (#qersh-txn, never carrying the id). Editable in WooCommerce's email settings, with
 * a plain-text version.
 */
class Instructions extends \WC_Email {

	/** Email id. */
	const ID = 'qersh_instructions';

	/** Our trigger action (Orders fires it with the order id). */
	const ACTION = Checkout::EMAIL_ACTION;

	/**
	 * Sets up the email.
	 */
	public function __construct() {
		$this->id             = self::ID;
		$this->customer_email = true;
		$this->title          = __( 'Wallet transfer instructions', 'qersh-for-woocommerce' );
		$this->description    = __( 'Sent to the customer when an order is placed with wallet transfer, and again after each new attempt from “Pay for order”. It holds the amount, the wallet to transfer to, the number to transfer from and the deadline.', 'qersh-for-woocommerce' );
		$this->email_group    = 'orders';
		$this->template_html  = 'emails/instructions.php';
		$this->template_plain = 'emails/plain/instructions.php';
		$this->template_base  = QERSH_WC_DIR . 'templates/';
		$this->placeholders   = array(
			'{order_date}'   => '',
			'{order_number}' => '',
		);

		add_action( self::ACTION . '_notification', array( $this, 'trigger' ), 10, 2 );

		parent::__construct();
	}

	/**
	 * Default subject.
	 *
	 * @return string
	 */
	public function get_default_subject() {
		return __( 'Transfer instructions for your order #{order_number}', 'qersh-for-woocommerce' );
	}

	/**
	 * Default heading.
	 *
	 * @return string
	 */
	public function get_default_heading() {
		return __( 'Transfer instructions for your order', 'qersh-for-woocommerce' );
	}

	/**
	 * Default content below the instructions.
	 *
	 * @return string
	 */
	public function get_default_additional_content() {
		return __( 'If you need help with your order, reply to this email with your order number.', 'qersh-for-woocommerce' );
	}

	/**
	 * Sends the email for an order that still waits for its transfer.
	 *
	 * @param int            $order_id Order id.
	 * @param \WC_Order|bool $order    Order, when the caller has it.
	 * @return void
	 */
	public function trigger( $order_id, $order = false ) {
		$this->setup_locale();

		if ( $order_id && ! $order instanceof \WC_Order ) {
			$order = wc_get_order( absint( $order_id ) );
		}
		if ( $order instanceof \WC_Order && Checkout::METHOD === $order->get_payment_method() ) {
			$this->object                         = $order;
			$this->recipient                      = $order->get_billing_email();
			$this->placeholders['{order_date}']   = wc_format_datetime( $order->get_date_created() );
			$this->placeholders['{order_number}'] = $order->get_order_number();

			$box = Checkout::box( Plugin::instance(), $order, time() );
			if ( null !== $box && $box['pending'] ) {
				$this->send_notification();
			}
		}

		$this->restore_locale();
	}

	/**
	 * The arguments of both templates.
	 *
	 * @param bool $plain_text Plain text.
	 * @return array
	 */
	private function template_args( bool $plain_text ): array {
		$order = $this->object instanceof \WC_Order ? $this->object : null;
		$box   = null;
		if ( null !== $order && Checkout::METHOD === $order->get_payment_method() ) {
			$box = Checkout::box( Plugin::instance(), $order, time() );
		}
		return array(
			'order'              => $this->object,
			'box'                => $box,
			'now'                => time(),
			'email_heading'      => $this->get_heading(),
			'additional_content' => $this->get_additional_content(),
			'sent_to_admin'      => false,
			'plain_text'         => $plain_text,
			'email'              => $this,
		);
	}

	/**
	 * HTML content.
	 *
	 * @return string
	 */
	public function get_content_html() {
		return wc_get_template_html( $this->template_html, $this->template_args( false ), '', $this->template_base );
	}

	/**
	 * Plain content.
	 *
	 * @return string
	 */
	public function get_content_plain() {
		return wc_get_template_html( $this->template_plain, $this->template_args( true ), '', $this->template_base );
	}
}
