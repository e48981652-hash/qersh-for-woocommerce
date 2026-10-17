<?php
/**
 * The administrator's alert email (docs/17 §4.1 Emails row): a suspected forged transfer message, a wallet's phone
 * gone silent, and orders that wait for a decision at their deadline.
 *
 * @package QershPay\WooCommerce
 */

namespace QershPay\WooCommerce\Emails;

use QershPay\WooCommerce\Admin\Module;
use QershPay\WooCommerce\Admin\View;
use QershPay\WooCommerce\Plugin;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WC_Email', false ) ) {
	return; // Loaded only by WooCommerce's mailer (woocommerce_email_classes), never on its own.
}

/**
 * Sent on do_action( 'qersh_wc_admin_alert', $kind, $args ), which is one of woocommerce_email_actions, so it is
 * deferred with a webhook request's other emails. Kinds and their arguments:
 * - `forgery`: `order_id`, optional `transfer_id`, `amount_minor`;
 * - `phone_silent`: `wallet`, `since` (UTC seconds), optional `orders` (how many wait);
 * - `claim_overdue` and `partial_overdue`: `order_id`, optional `transfer_id`, `amount_minor`.
 * Editable in WooCommerce → Settings → Emails, with a plain-text version.
 */
class AdminAlert extends \WC_Email {

	/**
	 * The trigger action.
	 */
	const ACTION = 'qersh_wc_admin_alert';

	/**
	 * Alert kinds.
	 */
	const KINDS = array( 'forgery', 'phone_silent', 'claim_overdue', 'partial_overdue' );

	/**
	 * The kind of the alert being sent.
	 *
	 * @var string
	 */
	public $kind = '';

	/**
	 * Its arguments.
	 *
	 * @var array
	 */
	public $alert = array();

	/**
	 * Builds the email.
	 */
	public function __construct() {
		$this->id             = 'qersh_admin_alert';
		$this->title          = __( 'Qersh alert', 'qersh-for-woocommerce' );
		$this->description    = __( 'Sent to the store\'s administrator when a transfer message is suspected to be forged, when a wallet\'s phone goes silent while orders wait, and when an order waits for your decision at its deadline.', 'qersh-for-woocommerce' );
		$this->template_html  = 'emails/admin-alert.php';
		$this->template_plain = 'emails/plain/admin-alert.php';
		$this->template_base  = QERSH_WC_DIR . 'templates/';
		$this->placeholders   = array(
			'{order_number}' => '',
			'{wallet}'       => '',
		);

		add_action( self::ACTION . '_notification', array( $this, 'trigger' ), 10, 2 );

		parent::__construct();

		$this->recipient = $this->get_option( 'recipient', get_option( 'admin_email' ) );
	}

	/**
	 * Default subject, by kind.
	 *
	 * @return string
	 */
	public function get_default_subject() {
		switch ( $this->kind ) {
			case 'forgery':
				return __( '[{site_title}]: Suspected forged transfer message on order #{order_number}', 'qersh-for-woocommerce' );
			case 'phone_silent':
				return __( '[{site_title}]: The phone of wallet {wallet} seems to be offline', 'qersh-for-woocommerce' );
			case 'claim_overdue':
			case 'partial_overdue':
				return __( '[{site_title}]: Order #{order_number} waits for your decision', 'qersh-for-woocommerce' );
		}
		return __( '[{site_title}]: Qersh alert', 'qersh-for-woocommerce' );
	}

	/**
	 * Default heading, by kind.
	 *
	 * @return string
	 */
	public function get_default_heading() {
		switch ( $this->kind ) {
			case 'forgery':
				return __( 'Suspected forged transfer message', 'qersh-for-woocommerce' );
			case 'phone_silent':
				return __( 'A wallet\'s phone seems to be offline', 'qersh-for-woocommerce' );
			case 'claim_overdue':
			case 'partial_overdue':
				return __( 'An order waits for your decision', 'qersh-for-woocommerce' );
		}
		return __( 'Qersh alert', 'qersh-for-woocommerce' );
	}

	/**
	 * The subject: the merchant's own when saved, otherwise the default of this alert's kind. WC_Email would keep the
	 * first default it read for the rest of the request, so two alerts of different kinds would share one subject.
	 *
	 * @return string
	 */
	public function get_subject() {
		$saved   = $this->saved_setting( 'subject' );
		$subject = '' !== $saved ? $saved : $this->get_default_subject();
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- WooCommerce's own email filter.
		return apply_filters( 'woocommerce_email_subject_' . $this->id, $this->format_string( $subject ), $this->object, $this );
	}

	/**
	 * The heading, as get_subject().
	 *
	 * @return string
	 */
	public function get_heading() {
		$saved   = $this->saved_setting( 'heading' );
		$heading = '' !== $saved ? $saved : $this->get_default_heading();
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- WooCommerce's own email filter.
		return apply_filters( 'woocommerce_email_heading_' . $this->id, $this->format_string( $heading ), $this->object, $this );
	}

	/**
	 * A text setting as the merchant saved it ('' when not set).
	 *
	 * @param string $key Setting.
	 * @return string
	 */
	private function saved_setting( string $key ): string {
		$settings = get_option( $this->get_option_key(), array() );
		return is_array( $settings ) && isset( $settings[ $key ] ) && is_string( $settings[ $key ] ) ? trim( $settings[ $key ] ) : '';
	}

	/**
	 * Default additional content.
	 *
	 * @return string
	 */
	public function get_default_additional_content() {
		return '';
	}

	/**
	 * Sends the alert.
	 *
	 * @param string $kind Alert kind.
	 * @param array  $args Its arguments.
	 */
	public function trigger( $kind = '', $args = array() ) {
		$kind = 'silent' === $kind ? 'phone_silent' : (string) $kind;
		if ( ! in_array( $kind, self::KINDS, true ) ) {
			return;
		}
		$this->setup_locale();
		$this->kind   = $kind;
		$this->alert  = is_array( $args ) ? $args : array();
		$this->object = null;

		$order_id = isset( $this->alert['order_id'] ) ? (int) $this->alert['order_id'] : 0;
		$order    = $order_id > 0 ? wc_get_order( $order_id ) : null;
		if ( $order ) {
			$this->object                         = $order;
			$this->placeholders['{order_number}'] = $order->get_order_number();
		}
		$wallet                         = isset( $this->alert['wallet'] ) ? Plugin::instance()->wallet( (string) $this->alert['wallet'] ) : null;
		$this->placeholders['{wallet}'] = null === $wallet ? ( isset( $this->alert['wallet'] ) ? (string) $this->alert['wallet'] : '' ) : View::wallet_name( $wallet );

		if ( $this->is_enabled() && $this->get_recipient() ) {
			$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		}
		$this->restore_locale();
	}

	/**
	 * The lines of the alert.
	 *
	 * @param bool $plain True for the plain-text version.
	 * @return string[] HTML (escaped) or plain text.
	 */
	public function lines( bool $plain ): array {
		$ltr   = function ( $value ) use ( $plain ) {
			return View::email_ltr( (string) $value, $plain );
		};
		$text  = function ( $value ) use ( $plain ) {
			return $plain ? (string) $value : esc_html( (string) $value );
		};
		$money = function ( $minor ) use ( $plain ) {
			return $plain ? View::money_plain( $minor ) : View::money( $minor );
		};
		$a     = $this->alert;
		$lines = array();
		$order = $this->object;
		$num   = $order ? $ltr( '#' . $order->get_order_number() ) : '';
		switch ( $this->kind ) {
			case 'forgery':
				$lines[] = $text( __( 'This transfer\'s message is suspected to be forged. Do not deliver the order until you have checked that the money arrived in the wallet app.', 'qersh-for-woocommerce' ) );
				break;
			case 'phone_silent':
				/* translators: %s: date and time. */
				$lines[] = sprintf( $text( __( 'The phone of this wallet is suspected to be offline since %s: orders will not be confirmed on their own until it is back.', 'qersh-for-woocommerce' ) ), $ltr( View::time( isset( $a['since'] ) ? (int) $a['since'] : 0 ) . ' ' . __( 'Cairo time', 'qersh-for-woocommerce' ) ) );
				if ( ! empty( $a['orders'] ) ) {
					/* translators: %s: number of orders. */
					$lines[] = sprintf( $text( _n( '%s order waits for a transfer to this wallet.', '%s orders wait for a transfer to this wallet.', (int) $a['orders'], 'qersh-for-woocommerce' ) ), $ltr( (string) (int) $a['orders'] ) );
				}
				$lines[] = $text( __( 'Check that the phone is on and connected, and that Qersh can run in the background. What the phone missed arrives when it is back.', 'qersh-for-woocommerce' ) );
				break;
			case 'claim_overdue':
				/* translators: %s: order number. */
				$lines[] = sprintf( $text( __( 'The customer of order %s entered this transaction id, and its deadline came before your decision; the order is on hold until you decide.', 'qersh-for-woocommerce' ) ), $num );
				break;
			case 'partial_overdue':
				/* translators: %s: order number. */
				$lines[] = sprintf( $text( __( 'Part of the amount of order %s arrived, and the rest did not arrive before the deadline.', 'qersh-for-woocommerce' ) ), $num );
				if ( $order && function_exists( 'wc_get_order' ) ) {
					try {
						$row = Plugin::instance()->orders_index()->get( (int) $order->get_id() );
					} catch ( \Throwable $e ) {
						$row = null;
					}
					if ( is_array( $row ) ) {
						/* translators: 1: amount received, 2: order amount. */
						$lines[] = sprintf( $text( __( 'Received: %1$s of %2$s', 'qersh-for-woocommerce' ) ), $money( (int) $row['paid_minor'] ), $money( (int) $row['price_minor'] ) );
					}
				}
				break;
		}
		if ( $order && 'phone_silent' !== $this->kind && 'partial_overdue' !== $this->kind ) {
			/* translators: %s: order number. */
			$lines[] = sprintf( $text( __( 'Order: %s', 'qersh-for-woocommerce' ) ), $num );
		}
		if ( isset( $a['amount_minor'] ) && is_int( $a['amount_minor'] ) ) {
			/* translators: %s: amount. */
			$lines[] = sprintf( $text( __( 'Transfer amount: %s', 'qersh-for-woocommerce' ) ), $money( $a['amount_minor'] ) );
		}
		if ( ! empty( $a['transfer_id'] ) && is_string( $a['transfer_id'] ) ) {
			/* translators: %s: transfer id. */
			$lines[] = sprintf( $text( __( 'Transfer: %s', 'qersh-for-woocommerce' ) ), $ltr( $a['transfer_id'] ) );
		}
		return $lines;
	}

	/**
	 * Where the merchant acts on the alert.
	 *
	 * @return string
	 */
	public function action_url(): string {
		if ( 'phone_silent' === $this->kind ) {
			return Module::page_url( 'wallets' );
		}
		return Module::page_url( 'transfers', array( 'view' => 'needs_decision' ) );
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
	 * Plain-text content.
	 *
	 * @return string
	 */
	public function get_content_plain() {
		return wc_get_template_html( $this->template_plain, $this->template_args( true ), '', $this->template_base );
	}

	/**
	 * Values for the templates.
	 *
	 * @param bool $plain Plain text.
	 * @return array
	 */
	private function template_args( bool $plain ): array {
		return array(
			'email_heading'      => $this->get_heading(),
			'kind'               => $this->kind,
			'lines'              => $this->lines( $plain ),
			'action_url'         => $this->action_url(),
			'action_label'       => 'phone_silent' === $this->kind ? __( 'Open the wallets', 'qersh-for-woocommerce' ) : __( 'Open “Needs a decision”', 'qersh-for-woocommerce' ),
			'additional_content' => $this->get_additional_content(),
			'sent_to_admin'      => true,
			'plain_text'         => $plain,
			'email'              => $this,
		);
	}

	/**
	 * Settings fields: who receives it, and the usual subject, heading and format.
	 */
	public function init_form_fields() {
		/* translators: %s: list of placeholders. */
		$placeholder_text  = sprintf( __( 'Available placeholders: %s', 'qersh-for-woocommerce' ), '<code>' . implode( '</code>, <code>', array_keys( $this->placeholders ) ) . '</code>' );
		$this->form_fields = array(
			'enabled'            => array(
				'title'   => __( 'Enable/Disable', 'qersh-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable this email notification', 'qersh-for-woocommerce' ),
				'default' => 'yes',
			),
			'recipient'          => array(
				'title'       => __( 'Recipient(s)', 'qersh-for-woocommerce' ),
				'type'        => 'text',
				/* translators: %s: the site admin email address. */
				'description' => sprintf( __( 'Enter recipients (comma separated) for this email. Defaults to %s.', 'qersh-for-woocommerce' ), '<code>' . esc_attr( get_option( 'admin_email' ) ) . '</code>' ),
				'placeholder' => '',
				'default'     => '',
				'desc_tip'    => true,
			),
			'subject'            => array(
				'title'       => __( 'Subject', 'qersh-for-woocommerce' ),
				'type'        => 'text',
				'desc_tip'    => true,
				'description' => $placeholder_text . ' ' . __( 'Leave empty for a subject that names the kind of alert.', 'qersh-for-woocommerce' ),
				'placeholder' => '',
				'default'     => '',
			),
			'heading'            => array(
				'title'       => __( 'Email heading', 'qersh-for-woocommerce' ),
				'type'        => 'text',
				'desc_tip'    => true,
				'description' => $placeholder_text . ' ' . __( 'Leave empty for a heading that names the kind of alert.', 'qersh-for-woocommerce' ),
				'placeholder' => '',
				'default'     => '',
			),
			'additional_content' => array(
				'title'       => __( 'Additional content', 'qersh-for-woocommerce' ),
				'description' => __( 'Text to appear below the main email content.', 'qersh-for-woocommerce' ) . ' ' . $placeholder_text,
				'css'         => 'width:400px; height: 75px;',
				'placeholder' => __( 'N/A', 'qersh-for-woocommerce' ),
				'type'        => 'textarea',
				'default'     => '',
				'desc_tip'    => true,
			),
			'email_type'         => array(
				'title'       => __( 'Email type', 'qersh-for-woocommerce' ),
				'type'        => 'select',
				'description' => __( 'Choose which format of email to send.', 'qersh-for-woocommerce' ),
				'default'     => 'html',
				'class'       => 'email_type wc-enhanced-select',
				'options'     => $this->get_email_type_options(),
				'desc_tip'    => true,
			),
		);
	}
}
