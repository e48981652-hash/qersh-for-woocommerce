<?php
/**
 * Base of the integration tests: wallets with fresh test keys, products, orders through the method's
 * process_payment(), signed deliveries shaped like tests/e2e/deliver.mjs (from fixtures/test-vectors.json), REST
 * dispatch, and captured mail.
 *
 * @package QershPay\WooCommerce\Tests
 */

namespace QershPay\WooCommerce\Tests\Integration;

use QershPay\WooCommerce\Core\Config;
use QershPay\WooCommerce\Core\Verifier;
use QershPay\WooCommerce\Gateway;
use QershPay\WooCommerce\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Every test runs inside the test library's one transaction, rolled back at the end. The plugin opens its own
 * transactions (START TRANSACTION … COMMIT, docs/17 §4.4 step 8), and in MySQL a START TRANSACTION inside a
 * transaction commits it; so while a test runs, the plugin's three statements become a savepoint, its release and
 * a rollback to it (savepoints()). The plugin's logic is the same on one connection, and the test data still goes
 * away with the outer rollback.
 */
abstract class TestCase extends \WP_UnitTestCase {

	/** Savepoint name used for the plugin's transactions. */
	const SAVEPOINT = 'qersh_wc_test';

	/** The receive number of the Orange Cash test wallet (the store number of tests/vectors/txn-normalize.json). */
	const ORANGE_NUMBER = '01212345678';

	/** The payer of the Orange Cash vectors (their payer_key). */
	const ORANGE_PAYER = '01001234567';

	/**
	 * The plugin.
	 *
	 * @var Plugin
	 */
	protected $plugin;

	/**
	 * The REST server of this test.
	 *
	 * @var \WP_REST_Server
	 */
	protected $server;

	/**
	 * Mail sent during the test: each the arguments of wp_mail().
	 *
	 * @var array[]
	 */
	protected $mails = array();

	/**
	 * Test keys of the configured wallets, by wallet id.
	 *
	 * @var array<string, string>
	 */
	protected $keys = array();

	/**
	 * Decoded fixtures/test-vectors.json.
	 *
	 * @var array|null
	 */
	private static $vectors = null;

	/**
	 * The REST server, built once per process: WooCommerce registers hundreds of routes on rest_api_init, and a new
	 * server per test would hold them all again.
	 *
	 * @var \WP_REST_Server|null
	 */
	private static $rest = null;

	/**
	 * Per-test setup: the savepoint filter, mail capture, the REST server, a clean plugin cache.
	 */
	public function set_up() {
		parent::set_up();
		add_filter( 'query', array( $this, 'savepoints' ), 0 );
		add_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 10, 2 );
		$this->mails = array();
		$this->keys  = array();

		$this->plugin = Plugin::instance();
		$this->plugin->forget_config();

		// register_rest_route() writes to rest_get_server(), the global: it is set before rest_api_init fires.
		global $wp_rest_server;
		if ( null === self::$rest ) {
			self::$rest     = new \WP_REST_Server();
			$wp_rest_server = self::$rest; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			do_action( 'rest_api_init', self::$rest );
		}
		$wp_rest_server = self::$rest; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$this->server   = self::$rest;

		unset( $_POST['qersh_payer'], $_POST['qersh_wallet'] );
	}

	/**
	 * Per-test teardown: the savepoint filter goes before the test library's ROLLBACK runs.
	 */
	public function tear_down() {
		remove_filter( 'query', array( $this, 'savepoints' ), 0 );
		global $wp_rest_server;
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		unset( $_POST['qersh_payer'], $_POST['qersh_wallet'] );
		$this->plugin->forget_config();
		parent::tear_down();
	}

	/**
	 * The `query` filter of a test: the plugin's own transaction statements become savepoint statements.
	 *
	 * @param string $query SQL.
	 * @return string
	 */
	public function savepoints( $query ) {
		$statement = strtoupper( rtrim( trim( (string) $query ), "; \t\n\r" ) );
		if ( 'START TRANSACTION' === $statement || 'BEGIN' === $statement ) {
			return 'SAVEPOINT ' . self::SAVEPOINT;
		}
		if ( 'COMMIT' === $statement ) {
			return 'RELEASE SAVEPOINT ' . self::SAVEPOINT;
		}
		if ( 'ROLLBACK' === $statement ) {
			return 'ROLLBACK TO SAVEPOINT ' . self::SAVEPOINT;
		}
		return $query;
	}

	/**
	 * pre_wp_mail: keeps the message and says it was sent.
	 *
	 * @param null|bool $short Earlier value.
	 * @param array     $atts  wp_mail() arguments.
	 * @return bool
	 */
	public function capture_mail( $short, $atts ) {
		unset( $short );
		$this->mails[] = $atts;
		return true;
	}

	/**
	 * Subjects of the captured mail.
	 *
	 * @return string[]
	 */
	protected function mail_subjects() {
		$out = array();
		foreach ( $this->mails as $mail ) {
			$out[] = isset( $mail['subject'] ) ? (string) $mail['subject'] : '';
		}
		return $out;
	}

	/*
	 * ------------------------------------------------------------------------------------------------------------
	 * Store setup.
	 * ------------------------------------------------------------------------------------------------------------
	 */

	/**
	 * Configures the plugin with wallets (each gets a fresh id and test key) and enables the method.
	 *
	 * @param array $wallets Each `[ 'provider' => …, 'receive_address' => …, 'label' => … ]`.
	 * @param array $store   Store settings to change.
	 * @return array[] The configured entries, in order.
	 */
	protected function configure( array $wallets, array $store = array() ) {
		$entries = array();
		foreach ( $wallets as $wallet ) {
			$entries[] = array(
				'id'              => Config::new_wallet_id(),
				'label'           => isset( $wallet['label'] ) ? $wallet['label'] : 'Test ' . $wallet['provider'],
				'provider'        => $wallet['provider'],
				'receive_address' => $wallet['receive_address'],
				'enabled'         => true,
			);
		}
		$current            = $this->plugin->config_store()->get();
		$current['wallets'] = array_merge( isset( $current['wallets'] ) ? $current['wallets'] : array(), $entries );
		$current['store']   = array_merge( isset( $current['store'] ) ? $current['store'] : array(), $store );
		$this->save_config( $current );
		foreach ( $entries as $entry ) {
			$key                        = Config::new_key();
			$this->keys[ $entry['id'] ] = $key;
			$this->plugin->secrets()->set_current( $entry['id'], $key, time() );
		}
		update_option(
			'woocommerce_qersh_settings',
			array(
				'enabled' => 'yes',
				'title'   => 'Wallet transfer',
			)
		);
		return $entries;
	}

	/**
	 * Saves a whole configuration and forgets the cached one.
	 *
	 * @param array $config Configuration.
	 */
	protected function save_config( array $config ) {
		$saved = $this->plugin->config_store()->save( $config );
		$this->assertTrue( true === $saved, 'the configuration was refused: ' . ( is_wp_error( $saved ) ? $saved->get_error_message() : '' ) );
		$this->plugin->forget_config();
	}

	/**
	 * One Vodafone Cash wallet.
	 *
	 * @return array
	 */
	protected function vodafone_wallet() {
		$entries = $this->configure(
			array(
				array(
					'provider'        => 'vodafone_cash',
					'receive_address' => '01000000101',
				),
			)
		);
		return $entries[0];
	}

	/**
	 * One Orange Cash wallet, numbered like the store of the transaction-id vectors.
	 *
	 * @return array
	 */
	protected function orange_wallet() {
		$entries = $this->configure(
			array(
				array(
					'provider'        => 'orange_cash',
					'receive_address' => self::ORANGE_NUMBER,
				),
			)
		);
		return $entries[0];
	}

	/**
	 * A simple product with managed stock.
	 *
	 * @param string $price Price in pounds.
	 * @param int    $stock Stock.
	 * @return \WC_Product_Simple
	 */
	protected function product( $price = '140', $stock = 10 ) {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Qersh integration ' . $price );
		$product->set_regular_price( $price );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( $stock );
		$product->set_status( 'publish' );
		$product->save();
		return $product;
	}

	/**
	 * A pending order of one product, paid with our method, not yet through process_payment().
	 *
	 * @param \WC_Product $product Product.
	 * @param string      $phone   Billing phone.
	 * @param string      $email   Billing email.
	 * @return \WC_Order
	 */
	protected function order( $product, $phone = '01112223344', $email = 'customer@example.test' ) {
		$order = wc_create_order();
		$order->add_product( $product, 1 );
		$order->set_billing_first_name( 'Test' );
		$order->set_billing_last_name( 'Customer' );
		$order->set_billing_email( $email );
		$order->set_billing_phone( $phone );
		$order->set_billing_country( 'EG' );
		$order->set_currency( 'EGP' );
		$order->set_payment_method( 'qersh' );
		$order->set_payment_method_title( 'Wallet transfer' );
		$order->calculate_totals( false );
		$order->save();
		return $order;
	}

	/**
	 * The method's process_payment() for an order, with the payer and wallet as the checkout posts them. The order's
	 * row is then moved two minutes back: the money of a delivery "arrives one second before now", and with
	 * skew_min = 0 an order newer than the money is never a candidate (docs/17 §4.6).
	 *
	 * @param \WC_Order $order    Order.
	 * @param string    $payer    What the customer typed.
	 * @param string    $wallet   Wallet id.
	 * @param bool      $backdate Whether to move the row back.
	 * @return array The method's result.
	 */
	protected function pay( $order, $payer, $wallet, $backdate = true ) {
		$_POST['qersh_payer']  = $payer; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$_POST['qersh_wallet'] = $wallet; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$gateway               = new Gateway();
		$result                = $gateway->process_payment( $order->get_id() );
		unset( $_POST['qersh_payer'], $_POST['qersh_wallet'] );
		if ( $backdate && isset( $result['result'] ) && 'success' === $result['result'] ) {
			$this->backdate( $order->get_id(), 120 );
		}
		return $result;
	}

	/**
	 * Moves an order's row back in time.
	 *
	 * @param int $order_id Order id.
	 * @param int $seconds  Seconds.
	 */
	protected function backdate( $order_id, $seconds ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET created_at = created_at - %d WHERE order_id = %d', $wpdb->prefix . 'qersh_orders', $seconds, $order_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Moves an order's deadline into the past, past the late window too.
	 *
	 * @param int $order_id Order id.
	 */
	protected function expire_now( $order_id ) {
		global $wpdb;
		$past = time() - 7 * HOUR_IN_SECONDS;
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET expires_at = %d WHERE order_id = %d', $wpdb->prefix . 'qersh_orders', $past, $order_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$order = wc_get_order( $order_id );
		$order->update_meta_data( '_qersh_deadline', $past );
		$order->save();
	}

	/**
	 * The qersh_orders row of an order.
	 *
	 * @param int $order_id Order id.
	 * @return array|null
	 */
	protected function row( $order_id ) {
		return $this->plugin->orders_index()->get( (int) $order_id );
	}

	/**
	 * A fresh copy of an order.
	 *
	 * @param int $order_id Order id.
	 * @return \WC_Order
	 */
	protected function fresh( $order_id ) {
		if ( function_exists( 'wc_get_container' ) ) {
			wp_cache_flush();
		}
		$order = wc_get_order( $order_id );
		$this->assertInstanceOf( \WC_Order::class, $order );
		return $order;
	}

	/**
	 * Order notes, newest first, as plain text.
	 *
	 * @param int $order_id Order id.
	 * @return string[]
	 */
	protected function notes( $order_id ) {
		$out = array();
		foreach ( wc_get_order_notes( array( 'order_id' => $order_id ) ) as $note ) {
			$out[] = wp_strip_all_tags( $note->content );
		}
		return $out;
	}

	/*
	 * ------------------------------------------------------------------------------------------------------------
	 * Deliveries.
	 * ------------------------------------------------------------------------------------------------------------
	 */

	/**
	 * Decoded fixtures/test-vectors.json.
	 *
	 * @return array
	 */
	protected static function vectors() {
		if ( null === self::$vectors ) {
			self::$vectors = json_decode( (string) file_get_contents( QERSH_WC_TESTS_ROOT . '/fixtures/test-vectors.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}
		return self::$vectors;
	}

	/**
	 * The body of one vector case, decoded.
	 *
	 * @param string $name Case name.
	 * @return array
	 */
	protected static function vector_body( $name ) {
		foreach ( self::vectors()['cases'] as $case ) {
			if ( $case['name'] === $name ) {
				return json_decode( $case['body'], true );
			}
		}
		throw new \RuntimeException( 'no vector ' . $name );
	}

	/**
	 * An ISO 8601 UTC time with milliseconds, as JavaScript's toISOString() writes it.
	 *
	 * @param int $sec UTC seconds.
	 * @return string
	 */
	protected static function iso( $sec ) {
		return gmdate( 'Y-m-d\TH:i:s', $sec ) . '.000Z';
	}

	/**
	 * An envelope shaped like tests/e2e/deliver.mjs builds it.
	 *
	 * Options: delivery_id, transfer_id, amount_minor, payer (a number or handle; null for none), txn (null for
	 * none), risk, reasons, auto_ok, channel.
	 *
	 * @param string $event  Event.
	 * @param array  $wallet Wallet entry.
	 * @param array  $opts   Options.
	 * @return array
	 */
	protected function envelope( $event, array $wallet, array $opts = array() ) {
		$now = time();
		$env = self::vector_body( 'webhook.test' === $event ? 'valid_webhook_test' : 'valid_transfer_received' );

		$env['event']               = $event;
		$env['delivery_id']         = isset( $opts['delivery_id'] ) ? $opts['delivery_id'] : wp_generate_uuid4();
		$env['created_at']          = self::iso( $now );
		$env['created_at_epoch_ms'] = $now * 1000;
		$env['wallet']['id']        = $wallet['id'];
		$env['wallet']['provider']  = $wallet['provider'];

		if ( 'transfer.received' === $event ) {
			$env['data'] = $this->shape_transfer( $env['data'], $now, $opts );
		} elseif ( 'transfer.updated' === $event ) {
			$env['data'] = array_merge(
				array(
					'transfer_id'     => isset( $opts['transfer_id'] ) ? $opts['transfer_id'] : 'trf_missing',
					'updated_fields'  => array( 'risk', 'risk_reasons', 'auto_confirm_ok' ),
					'risk'            => 'low',
					'risk_reasons'    => array(),
					'auto_confirm_ok' => true,
				),
				isset( $opts['data'] ) ? $opts['data'] : array()
			);
		} elseif ( 'device.heartbeat' === $event ) {
			$env['data'] = array( 'at' => self::iso( $now ) );
		}
		return $env;
	}

	/**
	 * The transfer of a transfer.received, as deliver.mjs shapes it.
	 *
	 * @param array $data Vector data.
	 * @param int   $now  UTC seconds.
	 * @param array $opts Options of envelope().
	 * @return array
	 */
	private function shape_transfer( array $data, $now, array $opts ) {
		$data['transfer_id'] = isset( $opts['transfer_id'] ) ? $opts['transfer_id'] : 'trf_' . bin2hex( random_bytes( 12 ) );
		$data['received_at'] = self::iso( $now - 1 );
		if ( array_key_exists( 'sms_timestamp', $data ) ) {
			$data['sms_timestamp'] = self::iso( $now - 4 );
		}
		if ( isset( $opts['amount_minor'] ) ) {
			$data['amount_minor'] = (int) $opts['amount_minor'];
			$data['amount']       = number_format( $opts['amount_minor'] / 100, 2, '.', '' );
		}
		if ( array_key_exists( 'payer', $opts ) ) {
			$name = isset( $data['counterparty']['name'] ) ? $data['counterparty']['name'] : null;
			if ( null === $opts['payer'] ) {
				$data['counterparty'] = array(
					'name'   => $name,
					'number' => null,
					'handle' => null,
				);
			} elseif ( false !== strpos( $opts['payer'], '@' ) ) {
				$data['counterparty']['number'] = null;
				$data['counterparty']['handle'] = $opts['payer'];
			} else {
				$data['counterparty']['number'] = $opts['payer'];
				$data['counterparty']['handle'] = null;
			}
		}
		// Every real transfer has its own operator id; a fixed one would make each run a duplicate (rule 2).
		$data['provider_txn_id'] = array_key_exists( 'txn', $opts ) ? $opts['txn'] : str_pad( (string) random_int( 0, 999999999999 ), 12, '0', STR_PAD_LEFT );
		foreach ( array(
			'risk'    => 'risk',
			'reasons' => 'risk_reasons',
			'auto_ok' => 'auto_confirm_ok',
			'channel' => 'channel',
		) as $opt => $field ) {
			if ( array_key_exists( $opt, $opts ) ) {
				$data[ $field ] = $opts[ $opt ];
			}
		}
		return $data;
	}

	/**
	 * Signs a body with a wallet's test key (or the given key) for now.
	 *
	 * @param string      $body   Raw body.
	 * @param string      $wallet Wallet id.
	 * @param string|null $key    Another key.
	 * @return string X-Qersh-Signature.
	 */
	protected function sign( $body, $wallet, $key = null ) {
		return Verifier::sign_header( time(), $body, array( null !== $key ? $key : $this->keys[ $wallet ] ) );
	}

	/**
	 * POSTs a raw body to the webhook route, with the headers the phone sends.
	 *
	 * @param string      $wallet    Wallet id of the address.
	 * @param string      $body      Raw body.
	 * @param string|null $signature X-Qersh-Signature, or null for none.
	 * @return \WP_REST_Response
	 */
	protected function post_webhook( $wallet, $body, $signature ) {
		$request = new \WP_REST_Request( 'POST', '/qersh/v1/webhook/' . $wallet );
		$request->set_header( 'content-type', 'application/json; charset=utf-8' );
		$request->set_header( 'user-agent', 'Qersh/integration-test' );
		$request->set_body( $body );
		if ( null !== $signature ) {
			$request->set_header( 'x-qersh-signature', $signature );
		}
		$env = json_decode( $body, true );
		if ( is_array( $env ) && isset( $env['event'], $env['delivery_id'] ) ) {
			$request->set_header( 'x-qersh-version', '1' );
			$request->set_header( 'x-qersh-event', (string) $env['event'] );
			$request->set_header( 'x-qersh-delivery', (string) $env['delivery_id'] );
			$request->set_header( 'x-qersh-attempt', '1' );
			$request->set_header( 'x-qersh-wallet', $wallet );
			$request->set_header( 'idempotency-key', (string) $env['delivery_id'] );
		}
		return $this->server->dispatch( $request );
	}

	/**
	 * Builds, signs and posts one delivery.
	 *
	 * @param string $event  Event.
	 * @param array  $wallet Wallet entry.
	 * @param array  $opts   Options of envelope().
	 * @return array `[ 'response' => \WP_REST_Response, 'envelope' => array, 'body' => string ]`
	 */
	protected function deliver( $event, array $wallet, array $opts = array() ) {
		$env  = $this->envelope( $event, $wallet, $opts );
		$body = (string) wp_json_encode( $env, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		return array(
			'response' => $this->post_webhook( $wallet['id'], $body, $this->sign( $body, $wallet['id'] ) ),
			'envelope' => $env,
			'body'     => $body,
		);
	}

	/**
	 * The forgery update of deliver.mjs --forgery for a transfer.
	 *
	 * @param array  $wallet      Wallet entry.
	 * @param string $transfer_id Transfer id.
	 * @return \WP_REST_Response
	 */
	protected function deliver_forgery( array $wallet, $transfer_id ) {
		$out = $this->deliver(
			'transfer.updated',
			$wallet,
			array(
				'transfer_id' => $transfer_id,
				'data'        => array(
					'updated_fields'  => array( 'checks.balance_chain', 'risk', 'risk_reasons', 'auto_confirm_ok' ),
					'checks'          => array( 'balance_chain' => 'refuted' ),
					'risk'            => 'high',
					'risk_reasons'    => array( 'balance_inconsistent', 'suspected_forgery' ),
					'auto_confirm_ok' => false,
				),
			)
		);
		return $out['response'];
	}

	/**
	 * POSTs JSON to a storefront route.
	 *
	 * @param string $route Route, e.g. `/qersh/v1/order-status`.
	 * @param mixed  $body  Body, encoded as JSON unless a string.
	 * @return \WP_REST_Response
	 */
	protected function post_json( $route, $body ) {
		$request = new \WP_REST_Request( 'POST', $route );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( is_string( $body ) ? $body : (string) wp_json_encode( $body ) );
		return $this->server->dispatch( $request );
	}

	/**
	 * A header of a response, any case.
	 *
	 * @param \WP_REST_Response $response Response.
	 * @param string            $name     Header name.
	 * @return string
	 */
	protected static function header( $response, $name ) {
		foreach ( $response->get_headers() as $key => $value ) {
			if ( 0 === strcasecmp( $key, $name ) ) {
				return (string) $value;
			}
		}
		return '';
	}
}
