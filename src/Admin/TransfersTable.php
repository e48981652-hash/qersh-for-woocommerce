<?php
/**
 * The list of transfers on the Transfers tab.
 *
 * @package QershPay\WooCommerce
 */

namespace QershPay\WooCommerce\Admin;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * One row per transfer, newest first, read through Store\Transfers::list_page(). Each open row carries its
 * suggestions (computed now) and small forms for attach and dismiss; the table itself is not inside a form.
 */
final class TransfersTable extends \WP_List_Table {

	/**
	 * Rows per page.
	 */
	const PER_PAGE = 20;

	/**
	 * The list's tab.
	 *
	 * @var string
	 */
	private $view;

	/**
	 * Search term.
	 *
	 * @var string
	 */
	private $search;

	/**
	 * Builds the table.
	 *
	 * @param string $view   Tab.
	 * @param string $search Search term.
	 */
	public function __construct( string $view, string $search ) {
		$this->view   = $view;
		$this->search = $search;
		parent::__construct(
			array(
				'singular' => 'qersh-transfer',
				'plural'   => 'qersh-transfers',
				'ajax'     => false,
				'screen'   => Module::screen_id(),
			)
		);
	}

	/**
	 * Columns.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'received' => __( 'Money arrived', 'qersh-for-woocommerce' ),
			'amount'   => __( 'Amount', 'qersh-for-woocommerce' ),
			'payer'    => __( 'Payer', 'qersh-for-woocommerce' ),
			'wallet'   => __( 'Wallet', 'qersh-for-woocommerce' ),
			'txn'      => __( 'Transaction id', 'qersh-for-woocommerce' ),
			'status'   => __( 'Status', 'qersh-for-woocommerce' ),
			'order'    => __( 'Order', 'qersh-for-woocommerce' ),
			'decide'   => __( 'Decide', 'qersh-for-woocommerce' ),
		);
	}

	/**
	 * Sortable columns.
	 *
	 * @return array
	 */
	protected function get_sortable_columns() {
		return array(
			'received' => array( 'money_at', true ),
			'amount'   => array( 'amount_minor', false ),
		);
	}

	/**
	 * Reads one page.
	 */
	public function prepare_items(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- list sorting.
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'created_at';
		$order   = isset( $_GET['order'] ) ? strtoupper( sanitize_key( wp_unslash( $_GET['order'] ) ) ) : 'DESC';
		// phpcs:enable
		$filter = array(
			'view'    => $this->view,
			'orderby' => in_array( $orderby, array( 'created_at', 'money_at', 'amount_minor' ), true ) ? $orderby : 'created_at',
			'order'   => 'ASC' === $order ? 'ASC' : 'DESC',
		);
		if ( '' !== $this->search ) {
			$filter['search'] = $this->search;
		}
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'received' );
		try {
			$page = Module::plugin()->transfers()->list_page( $filter, $this->get_pagenum(), self::PER_PAGE );
		} catch ( \Throwable $e ) {
			$page = array(
				'rows'  => array(),
				'total' => 0,
			);
		}
		$this->items = $page['rows'];
		$this->set_pagination_args(
			array(
				'total_items' => (int) $page['total'],
				'per_page'    => self::PER_PAGE,
			)
		);
	}

	/**
	 * Text of an empty list.
	 */
	public function no_items(): void {
		switch ( $this->view ) {
			case 'needs_decision':
				esc_html_e( 'Nothing needs a decision.', 'qersh-for-woocommerce' );
				break;
			case 'personal':
				esc_html_e( 'No personal transfers.', 'qersh-for-woocommerce' );
				break;
			default:
				esc_html_e( 'No transfers yet.', 'qersh-for-woocommerce' );
		}
	}

	/**
	 * Any other column.
	 *
	 * @param array  $item        Row.
	 * @param string $column_name Column.
	 * @return string
	 */
	protected function column_default( $item, $column_name ) {
		return '';
	}

	/**
	 * When the money arrived. The Wallet column names the wallet; the list never shows or filters by a wallet type
	 * (the row's `provider` is an opaque copy of the delivery's wallet.provider).
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_received( $item ) {
		return View::kses( View::ltr( View::time( (int) $item['money_at'] ) ) );
	}

	/**
	 * The amount.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_amount( $item ) {
		return View::kses( View::money( isset( $item['amount_minor'] ) ? $item['amount_minor'] : null ) );
	}

	/**
	 * Payer number and name.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_payer( $item ) {
		$parts = array();
		if ( ! empty( $item['payer_key'] ) ) {
			$parts[] = View::ltr( (string) $item['payer_key'] );
		}
		if ( ! empty( $item['payer_name'] ) ) {
			$parts[] = esc_html( (string) $item['payer_name'] );
		}
		return empty( $parts ) ? '—' : View::kses( implode( '<br>', $parts ) );
	}

	/**
	 * The wallet's name.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_wallet( $item ) {
		$wallet = Module::plugin()->wallet( (string) $item['wallet'] );
		return esc_html( null === $wallet ? (string) $item['wallet'] : View::wallet_name( $wallet ) );
	}

	/**
	 * The operator's transaction id.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_txn( $item ) {
		return empty( $item['provider_txn_id'] ) ? '—' : View::kses( View::ltr( (string) $item['provider_txn_id'] ) );
	}

	/**
	 * Outcome, badges, the reason and the note.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_status( $item ) {
		$html = '<strong>' . esc_html( Transfers::outcome_label( $item ) ) . '</strong>';
		if ( Transfers::matched_by_txn( $item ) ) {
			$html .= ' <mark class="qersh-wc-badge">' . esc_html__( 'By transaction id', 'qersh-for-woocommerce' ) . '</mark>';
		}
		if ( isset( $item['flag'] ) && 'forgery' === $item['flag'] ) {
			$html .= ' <mark class="qersh-wc-badge qersh-wc-badge-danger">' . esc_html__( 'Suspected forgery', 'qersh-for-woocommerce' ) . '</mark>';
		}
		$reason = Reasons::for_row( $item, Module::plugin() );
		if ( '' !== $reason ) {
			$html .= '<br>' . $reason;
		} elseif ( ! empty( $item['note'] ) ) {
			// The note of a decision without a reason (by transaction id, attached, dismissed, a possible second payment).
			$html .= '<br><span class="qersh-wc-muted">' . esc_html( (string) $item['note'] ) . '</span>';
		}
		return View::kses( $html );
	}

	/**
	 * The order it paid.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_order( $item ) {
		return empty( $item['order_id'] ) ? '—' : View::kses( View::order_link( (int) $item['order_id'] ) );
	}

	/**
	 * Suggestions, attach and dismiss, for a transfer that is still open.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_decide( $item ) {
		if ( Transfers::is_resolvable( $item ) ) {
			return $this->kses_forms( $this->resolve_html( $item ) );
		}
		if ( ! Transfers::is_open( $item ) ) {
			return '';
		}
		$plugin      = Module::plugin();
		$html        = '';
		$suggestions = Transfers::suggestions( $item );
		foreach ( $suggestions as $suggestion ) {
			$link = View::ltr( '#' . View::order_number( $suggestion['order_id'] ) );
			if ( $suggestion['claimant'] && $suggestion['ready'] ) {
				/* translators: %s: order number. */
				$label = sprintf( esc_html__( 'Its customer entered this transaction id · Attach to order %s', 'qersh-for-woocommerce' ), $link );
				$html .= $this->attach_form( $item, $suggestion['order_id'], $label, 'button-primary' );
				continue;
			}
			if ( $suggestion['claimant'] ) {
				/* translators: %s: order number. */
				$line = sprintf( esc_html__( 'The customer of order %s entered this transaction id.', 'qersh-for-woocommerce' ), View::order_link( $suggestion['order_id'] ) );
				if ( '' !== $suggestion['reason'] ) {
					$text = Reasons::text(
						array(
							'code'    => $suggestion['reason'],
							'variant' => $suggestion['params']['variant'],
							'detail'  => $suggestion['reason'],
						),
						$this->suggestion_params( $item, $suggestion )
					);
					// Most of these sentences start with "The customer of order #N entered this transaction id"
					// themselves; the others (a closed order, the stock) keep the line before them.
					$line = Reasons::names_claimant( $suggestion['reason'] ) ? $text : $line . ' ' . $text;
				}
				$html .= '<p class="qersh-wc-suggestion">' . $line . '</p>';
				continue;
			}
			if ( $suggestion['dead'] ) {
				/* translators: %s: order number. */
				$html .= '<p class="qersh-wc-suggestion">' . sprintf( esc_html__( 'Order %s is closed.', 'qersh-for-woocommerce' ), View::order_link( $suggestion['order_id'] ) ) . '</p>';
				continue;
			}
			/* translators: %s: order number. */
			$html .= $this->attach_form( $item, $suggestion['order_id'], sprintf( esc_html__( 'Attach to order %s', 'qersh-for-woocommerce' ), $link ), $suggestion['preselect'] ? 'button-primary' : 'button-secondary' );
			if ( $suggestion['after'] ) {
				/* translators: %s: order number. */
				$html .= '<p class="qersh-wc-muted">' . sprintf( esc_html__( 'This transfer arrived before order %s was created, so attach it only if you are sure it is for that order.', 'qersh-for-woocommerce' ), $link ) . '</p>';
			}
		}
		if ( ! empty( $suggestions ) && empty( $item['auto_confirm_ok'] ) ) {
			$html .= '<p class="qersh-wc-muted">' . esc_html__( 'Check the payer\'s name and the amount in the wallet app before you attach it.', 'qersh-for-woocommerce' ) . '</p>';
		}
		$html .= $this->attach_form( $item, 0, esc_html__( 'Attach', 'qersh-for-woocommerce' ), 'button-secondary' );
		$html .= $this->form_start( 'qersh_wc_transfer_dismiss', $item ) . '<button type="submit" class="button-link qersh-wc-dismiss">' . esc_html__( 'Dismiss: not for the store', 'qersh-for-woocommerce' ) . '</button></form>';
		return $this->kses_forms( $html );
	}

	/**
	 * A transfer that needs a decision but is linked to an order: attach and dismiss cannot apply, so the merchant
	 * deals with it on the order and then marks it as decided.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	private function resolve_html( array $item ): string {
		$order = View::order_link( (int) $item['order_id'] );
		/* translators: %s: order number. */
		$html  = '<p class="qersh-wc-muted">' . sprintf( esc_html__( 'This transfer is already linked to order %s, so it cannot be attached or dismissed. Deal with it on the order, for example by refunding the customer from the wallet app, then mark it as decided.', 'qersh-for-woocommerce' ), $order ) . '</p>';
		$html .= $this->form_start( 'qersh_wc_transfer_resolve', $item ) . '<button type="submit" class="button button-secondary">' . esc_html__( 'Mark as decided', 'qersh-for-woocommerce' ) . '</button></form>';
		return $html;
	}

	/**
	 * The values a claimant's reason names: those Orders::suggestions() gives, completed from the store.
	 *
	 * @param array $item       Row.
	 * @param array $suggestion Normalised suggestion.
	 * @return array Reasons::params() shape.
	 */
	private function suggestion_params( array $item, array $suggestion ): array {
		$plugin = Module::plugin();
		$given  = $suggestion['params'];
		$pick   = array(
			'code'    => $suggestion['reason'],
			'variant' => $given['variant'],
			'detail'  => $suggestion['reason'],
		);
		$params = Reasons::params( $item, $pick, $plugin );
		foreach ( array( 'n', 'm', 't', 'z' ) as $key ) {
			if ( null !== $given[ $key ] ) {
				$params[ $key ] = $given[ $key ];
			}
		}
		if ( null !== $given['w'] ) {
			$wallet      = $plugin->wallet( $given['w'] );
			$params['w'] = null === $wallet ? $given['w'] : View::wallet_name( $wallet );
		}
		if ( '' !== $given['variant'] ) {
			$params['variant'] = $given['variant'];
		}
		return $params;
	}

	/**
	 * An attach form: a ready button for a suggestion, or the order field.
	 *
	 * @param array  $item         Row.
	 * @param int    $order_id     Suggested order, or 0 for the field.
	 * @param string $label        Escaped button label.
	 * @param string $button_class Button class.
	 * @return string
	 */
	private function attach_form( array $item, int $order_id, string $label, string $button_class ): string {
		$html = $this->form_start( 'qersh_wc_transfer_attach', $item );
		if ( $order_id > 0 ) {
			$html .= '<input type="hidden" name="order_id" value="' . esc_attr( (string) $order_id ) . '">';
		} else {
			$html .= '<label class="screen-reader-text" for="qersh-order-' . esc_attr( (string) $item['id'] ) . '">' . esc_html__( 'Order number, or the number the customer transferred from', 'qersh-for-woocommerce' ) . '</label>';
			$html .= '<input type="text" id="qersh-order-' . esc_attr( (string) $item['id'] ) . '" name="order" dir="ltr" class="qersh-wc-order-field" placeholder="' . esc_attr__( 'Order number or payer number', 'qersh-for-woocommerce' ) . '" required> ';
		}
		$html .= '<button type="submit" class="button ' . esc_attr( $button_class ) . '">' . $label . '</button></form>';
		return $html;
	}

	/**
	 * The opening of a small admin-post form with its nonce.
	 *
	 * @param string $action Action.
	 * @param array  $item   Row.
	 * @return string
	 */
	private function form_start( string $action, array $item ): string {
		return '<form method="post" class="qersh-wc-inline-form" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
			. '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">'
			. '<input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( $action ) ) . '">'
			. '<input type="hidden" name="transfer" value="' . esc_attr( (string) $item['transfer_id'] ) . '">'
			. '<input type="hidden" name="view" value="' . esc_attr( $this->view ) . '">';
	}

	/**
	 * Late escaping for the decision column (forms included).
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private function kses_forms( string $html ): string {
		$allowed           = View::allowed_html();
		$allowed['form']   = array(
			'method' => true,
			'action' => true,
			'class'  => true,
		);
		$allowed['input']  = array(
			'type'        => true,
			'name'        => true,
			'value'       => true,
			'id'          => true,
			'dir'         => true,
			'class'       => true,
			'placeholder' => true,
			'required'    => true,
		);
		$allowed['button'] = array(
			'type'  => true,
			'class' => true,
		);
		$allowed['label']  = array(
			'class' => true,
			'for'   => true,
		);
		$allowed['p']      = array( 'class' => true );
		return wp_kses( $html, $allowed );
	}
}
