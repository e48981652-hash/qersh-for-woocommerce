<?php
/**
 * Tests of Core\Config: the receiver tests of packages/qersh-webhook/test/config.test.ts (without keys), the plugin
 * rules of docs/17 §4.2 (a wallet has no type: one number rule for every e-wallet, a legacy `provider` dropped), and
 * the key and id helpers.
 *
 * @package QershPay\WooCommerce\Tests
 */

declare( strict_types=1 );

namespace QershPay\WooCommerce\Tests\Unit\Core;

use QershPay\WooCommerce\Core\Config;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

defined( 'ABSPATH' ) || exit;

/**
 * Config defaults, validation, keys and ids.
 */
final class ConfigTest extends TestCase {

	/**
	 * The kit's published test vector key (no key literal lives in this file).
	 *
	 * @return string
	 */
	private static function vector_key(): string {
		$vectors = json_decode( qersh_wc_test_fixture( 'fixtures/test-vectors.json' ), true );
		return (string) $vectors['secret'];
	}

	/**
	 * The paths of the issues of a configuration.
	 *
	 * @param array $c Configuration.
	 * @return string[]
	 */
	private static function paths( array $c ): array {
		return array_column( Config::validate( $c ), 'path' );
	}

	/**
	 * A valid configuration with one wallet.
	 *
	 * @return array
	 */
	private static function valid(): array {
		$c            = Config::DEFAULTS;
		$c['wallets'] = array(
			array(
				'id'              => 'k7q2m9x4p1rz',
				'label'           => 'محفظة المتجر',
				'receive_address' => '01001234567',
				'enabled'         => true,
			),
		);
		return $c;
	}

	/**
	 * The issues of a configuration whose only wallet has this receive_address.
	 *
	 * @param mixed $address Value.
	 * @return array
	 */
	private static function address_issues( $address ): array {
		$c                                  = self::valid();
		$c['wallets'][0]['receive_address'] = $address;
		return Config::validate( $c );
	}

	public function test_defaults_are_the_docs_17_values(): void {
		$r = Config::DEFAULTS['receiver'];
		$this->assertSame( 'qersh/v1/webhook', $r['endpoint']['path'] );
		$this->assertSame( 300, $r['tolerance_sec'] );
		$this->assertSame( array( 'transfer.received', 'transfer.updated', 'webhook.test' ), $r['events'] );
		$this->assertSame( 120, $r['matching']['late_window_min'] );
		$this->assertSame( 0, $r['matching']['skew_min'] ); // the owner's decision; the core default is 10
		$this->assertSame( 180, $r['dedup']['keep_days'] );
		$this->assertArrayNotHasKey( 'keys', $r );
		$this->assertSame( array(), Config::DEFAULTS['wallets'] );
		$this->assertSame(
			array(
				'order_ttl_min'                => 120,
				'min_total_minor'              => null,
				'max_total_minor'              => null,
				'round_up_to_pound'            => true,
				'allow_key_link'               => true,
				'txn_entry'                    => true,
				'admin_cancel_email_on_expiry' => true,
				'unmatched_retention_days'     => 90,
				'remove_data_on_uninstall'     => false,
			),
			Config::DEFAULTS['store']
		);
		$this->assertSame( array(), Config::validate( Config::DEFAULTS ) );
		$this->assertSame( Config::DEFAULTS, Config::with_defaults( array() ) );
	}

	public function test_constants(): void {
		$this->assertSame( array( 'transfer.received', 'transfer.updated', 'webhook.test' ), Config::EVENTS_ALLOWED );
		// A wallet has no type: no list of wallet types, and no type field in a wallet entry.
		$this->assertSame( array( 'id', 'label', 'receive_address', 'enabled' ), Config::WALLET_FIELDS );
		$this->assertFalse( defined( Config::class . '::PROVIDERS' ) );
		$this->assertCount( 9, Config::QERSH_EVENTS );
		$schema = json_decode( qersh_wc_test_fixture( 'fixtures/webhook.v1.schema.json' ), true );
		$this->assertSame( $schema['$defs']['eventName']['enum'], Config::QERSH_EVENTS );
	}

	public function test_with_defaults_completes_a_partial_form(): void {
		$c = Config::with_defaults(
			array(
				'receiver' => array(
					'endpoint' => array( 'path' => 'hooks/qersh' ),
					'keys'     => array( self::vector_key() ),
				),
			)
		);
		$this->assertSame( 'hooks/qersh', $c['receiver']['endpoint']['path'] );
		$this->assertCount( 3, $c['receiver']['events'] );
		$this->assertArrayNotHasKey( 'keys', $c['receiver'] ); // keys never reach the stored configuration
		$this->assertSame( array( 'receiver.endpoint.path' ), self::paths( $c ) ); // only the plugin's fixed path is wrong
		$this->assertSame( 0, Config::with_defaults( array( 'receiver' => array( 'matching' => array( 'late_window_min' => 30 ) ) ) )['receiver']['matching']['skew_min'] );
	}

	public function test_with_defaults_survives_odd_input(): void {
		$this->assertSame( array(), Config::with_defaults( array( 'receiver' => array( 'events' => 'transfer.received' ) ) )['receiver']['events'] );
		$this->assertSame( array(), Config::with_defaults( array( 'receiver' => array( 'events' => null ) ) )['receiver']['events'] );
		$this->assertSame( array( 'transfer.received' ), Config::with_defaults( array( 'receiver' => array( 'events' => array( 'transfer.received', 7, null ) ) ) )['receiver']['events'] );
		// A non-object receiver takes the defaults (withDefaults of config.ts); a wallets or store of the wrong type is
		// kept so that validate() names it.
		$odd = Config::with_defaults(
			array(
				'receiver' => 'x',
				'wallets'  => 'x',
				'store'    => 'x',
			)
		);
		$this->assertSame( Config::DEFAULTS['receiver'], $odd['receiver'] );
		$this->assertSame( 'x', $odd['wallets'] );
		$this->assertSame( 'x', $odd['store'] );
		$this->assertSame( array( 'wallets', 'store' ), self::paths( $odd ) );
		$c = Config::with_defaults(
			array(
				'receiver' => array(
					'tolerance_sec' => 300.0,
					'matching'      => 'x',
					'dedup'         => array( 'keep_days' => null ),
				),
			)
		);
		$this->assertSame( 300, $c['receiver']['tolerance_sec'] ); // JSON 300.0
		$this->assertSame( Config::DEFAULTS['receiver']['matching'], $c['receiver']['matching'] );
		$this->assertSame( 180, $c['receiver']['dedup']['keep_days'] );
	}

	public function test_a_malformed_wallets_or_store_is_refused_not_emptied(): void {
		// An import or QERSH_WC_CONFIG of {"wallets": null} must be refused (docs/17 §4.2), never saved as "no
		// wallets", which would delete every wallet and seal its key.
		foreach ( array( null, 'x', 5, true, 1.5 ) as $bad ) {
			$c = Config::with_defaults( array( 'wallets' => $bad ) );
			$this->assertSame( $bad, $c['wallets'], var_export( $bad, true ) );
			$this->assertSame(
				array(
					array(
						'path'    => 'wallets',
						'message' => 'an array of wallets',
					),
				),
				Config::validate( $c ),
				var_export( $bad, true )
			);
			$c = Config::with_defaults( array( 'store' => $bad ) );
			$this->assertSame(
				array(
					array(
						'path'    => 'store',
						'message' => 'an object',
					),
				),
				Config::validate( $c ),
				var_export( $bad, true )
			);
		}
		// Missing parts still take the defaults.
		$this->assertSame( array(), Config::with_defaults( array( 'store' => array() ) )['wallets'] );
		$this->assertSame( Config::DEFAULTS['store'], Config::with_defaults( array( 'wallets' => array() ) )['store'] );
		$this->assertSame( Config::DEFAULTS['store'], Config::with_defaults( array( 'store' => array() ) )['store'] );
		$this->assertSame( array( 'wallets' ), self::paths( Config::with_defaults( array( 'wallets' => array( 'x' => array() ) ) ) ) );
	}

	public function test_whole_numbers_beyond_the_int_range_are_refused_not_wrapped(): void {
		$beyond = - (float) PHP_INT_MIN; // 2^63 on 64-bit PHP, 2^31 on 32-bit PHP: the first whole float (int) cannot hold
		$last   = (float) PHP_INT_MIN; // exactly PHP_INT_MIN as a float
		foreach ( array( 1e300, $beyond, $beyond * 4 ) as $big ) {
			$c = Config::with_defaults(
				array(
					'receiver' => array(
						'matching' => array(
							'late_window_min' => $big,
							'skew_min'        => $big,
						),
						'dedup'    => array( 'keep_days' => $big ),
					),
					'store'    => array(
						'min_total_minor' => $big,
						'max_total_minor' => $big,
					),
				)
			);
			// Never cast: (int) would wrap the value into another, valid-looking one.
			$this->assertSame( $big, $c['receiver']['matching']['late_window_min'] );
			$this->assertSame( $big, $c['store']['max_total_minor'] );
			$issues = Config::validate( $c );
			$this->assertSame( array( 'receiver.matching.late_window_min', 'receiver.matching.skew_min', 'receiver.dedup.keep_days', 'store.min_total_minor', 'store.max_total_minor' ), array_column( $issues, 'path' ), (string) $big );
			foreach ( $issues as $issue ) {
				$this->assertSame( 'too large for this server (at most ' . PHP_INT_MAX . ')', $issue['message'] );
			}
		}
		// The receiver rule itself is Number.isInteger(): a huge whole number is an integer, a huge fraction-less
		// negative is still "non-negative" wrong, and tolerance_sec keeps its 3600 bound.
		$c = Config::DEFAULTS;
		$c['receiver']['matching']['late_window_min'] = -1e300;
		$c['receiver']['tolerance_sec']               = 1e300;
		$this->assertSame(
			array(
				array(
					'path'    => 'receiver.tolerance_sec',
					'message' => 'an integer number of seconds between 1 and 3600 (the contract uses 300)',
				),
				array(
					'path'    => 'receiver.matching.late_window_min',
					'message' => 'a non-negative integer number of minutes',
				),
			),
			Config::validate( $c )
		);
		// Within the int range a whole float becomes an exact int, beyond 2^53 too on 64-bit PHP.
		$fits = PHP_INT_SIZE >= 8 ? 4611686018427387904.0 : 1073741824.0; // 2^62 or 2^30
		$c    = Config::with_defaults( array( 'store' => array( 'max_total_minor' => $fits ) ) );
		$this->assertSame( (int) $fits, $c['store']['max_total_minor'] );
		$this->assertSame( array(), Config::validate( $c ) );
		$c = Config::with_defaults( array( 'receiver' => array( 'matching' => array( 'skew_min' => $last ) ) ) );
		$this->assertSame( PHP_INT_MIN, $c['receiver']['matching']['skew_min'] );
		$this->assertSame( array( 'receiver.matching.skew_min' ), self::paths( $c ) );
	}

	public function test_validate_names_every_receiver_problem(): void {
		$bad             = Config::DEFAULTS;
		$bad['receiver'] = array(
			'endpoint'      => array( 'path' => '/qersh' ),
			'keys'          => array( 'short' ),
			'tolerance_sec' => 0,
			'events'        => array( 'transfer.received', 'nope' ),
			'matching'      => array(
				'late_window_min' => -1,
				'skew_min'        => 'x',
			),
			'dedup'         => array( 'keep_days' => 0 ),
		);
		$this->assertSame(
			array( 'receiver.endpoint.path', 'receiver.tolerance_sec', 'receiver.events[1]', 'receiver.matching.late_window_min', 'receiver.matching.skew_min', 'receiver.dedup.keep_days' ),
			self::paths( $bad )
		);
		$issues = Config::validate( $bad );
		$this->assertSame( 'a non-empty path without a leading slash or white space', $issues[0]['message'] );
		$this->assertSame( 'unknown event; one of ' . implode( ', ', Config::QERSH_EVENTS ), $issues[2]['message'] );

		$empty                       = Config::DEFAULTS;
		$empty['receiver']['events'] = array();
		$this->assertSame( array( 'receiver.events' ), self::paths( $empty ) );

		$this->assertSame( array( 'receiver', 'wallets', 'store' ), self::paths( array() ) );
		$this->assertSame( array( 'receiver.endpoint.path', 'receiver.tolerance_sec', 'receiver.events', 'receiver.matching.late_window_min', 'receiver.matching.skew_min', 'receiver.dedup.keep_days' ), self::paths( array_merge( Config::DEFAULTS, array( 'receiver' => array() ) ) ) );
	}

	public function test_receiver_numbers_follow_number_is_integer(): void {
		foreach ( array( 1, 300, 3600, 300.0 ) as $ok ) {
			$c                              = Config::DEFAULTS;
			$c['receiver']['tolerance_sec'] = $ok;
			$this->assertSame( array(), Config::validate( $c ), (string) $ok );
		}
		foreach ( array( 0, 3601, 300.5, '300', true, null, NAN, INF ) as $bad ) {
			$c                              = Config::DEFAULTS;
			$c['receiver']['tolerance_sec'] = $bad;
			$this->assertSame( array( 'receiver.tolerance_sec' ), self::paths( $c ), var_export( $bad, true ) );
		}
		$c                                     = Config::DEFAULTS;
		$c['receiver']['matching']['skew_min'] = 10;
		$c['receiver']['matching']['late_window_min'] = 0;
		$this->assertSame( array(), Config::validate( $c ) );
	}

	public function test_plugin_receiver_rules(): void {
		$c                                 = Config::DEFAULTS;
		$c['receiver']['endpoint']['path'] = 'qersh';
		$this->assertSame( array( 'receiver.endpoint.path' ), self::paths( $c ) );
		$this->assertStringContainsString( 'qersh/v1/webhook', Config::validate( $c )[0]['message'] );

		$c                       = Config::DEFAULTS;
		$c['receiver']['events'] = array( 'transfer.updated', 'webhook.test' );
		$this->assertSame( array( 'receiver.events' ), self::paths( $c ) );
		$this->assertSame( 'must include transfer.received', Config::validate( $c )[0]['message'] );

		$c                       = Config::DEFAULTS;
		$c['receiver']['events'] = array( 'transfer.received', 'transfer.sent', 'device.heartbeat' );
		$this->assertSame( array( 'receiver.events[1]', 'receiver.events[2]' ), self::paths( $c ) );
		$this->assertStringStartsWith( 'not handled by this plugin', Config::validate( $c )[0]['message'] );

		$c                       = Config::DEFAULTS;
		$c['receiver']['events'] = array( 'transfer.received' );
		$this->assertSame( array(), Config::validate( $c ) );
	}

	public function test_wallet_rules(): void {
		$this->assertSame( array(), Config::validate( self::valid() ) );

		$c              = self::valid();
		$c['wallets'][] = $c['wallets'][0];
		$this->assertSame( array( 'wallets[1].id' ), self::paths( $c ) );

		$c                     = self::valid();
		$c['wallets'][0]['id'] = 'K7Q2M9X4P1RZ';
		$this->assertSame( array( 'wallets[0].id' ), self::paths( $c ) );
		foreach ( array( 'k7q2m9x4p1r', 'k7q2m9x4p1rzz', "k7q2m9x4p1rz\n", 'k7q2m9x4p1r_', 12 ) as $id ) {
			$c                     = self::valid();
			$c['wallets'][0]['id'] = $id;
			$this->assertSame( array( 'wallets[0].id' ), self::paths( $c ), var_export( $id, true ) );
		}

		$c                          = self::valid();
		$c['wallets'][0]['enabled'] = 1;
		$c['wallets'][0]['label']   = null;
		$this->assertSame( array( 'wallets[0].label', 'wallets[0].enabled' ), self::paths( $c ) );

		$c            = self::valid();
		$c['wallets'] = array( 'x' => $c['wallets'][0] );
		$this->assertSame( array( 'wallets' ), self::paths( $c ) );
		$c['wallets'] = array( 'not a wallet' );
		$this->assertSame( array( 'wallets[0]' ), self::paths( $c ) );

		// The number rule names the second wallet by its own index.
		$c                                  = self::valid();
		$c['wallets'][]                     = array_merge( $c['wallets'][0], array( 'id' => 'a1b2c3d4e5f6' ) );
		$c['wallets'][1]['receive_address'] = 'shop@instapay';
		$this->assertSame( array( 'wallets[1].receive_address' ), self::paths( $c ) );
	}

	public function test_receive_address_is_a_wallet_number_for_every_wallet(): void {
		// Any spelling of an 11-digit 01 number is accepted (with_defaults() stores it normalised).
		foreach ( array( '01001234567', '01112223344', '01212345678', '01501234567', '+20 100 123 4567', '+2001001234567', '00201001234567', '201001234567', '1001234567', '0100-123-4567', ' 0100 123 4567 ', '٠١٠٠١٢٣٤٥٦٧', '۰۱۰۰۱۲۳۴۵۶۷' ) as $ok ) {
			$this->assertSame( array(), self::address_issues( $ok ), $ok );
		}
		// Anything else is refused with one message, whatever the wallet: an InstaPay address or another handle, a
		// masked or short number, a landline, a value that is not text, or no value.
		$refused = array( 'shop@instapay', 'Shop.Cairo@InstaPay', 'shopcairo', '0111***3445', '0100123456', '010012345678', '0223456789', '', '   ', 1001234567, 1.5, true, null, array( '01001234567' ) );
		foreach ( $refused as $bad ) {
			$this->assertSame(
				array(
					array(
						'path'    => 'wallets[0].receive_address',
						'message' => 'an 11-digit wallet number such as 01001234567',
					),
				),
				self::address_issues( $bad ),
				var_export( $bad, true )
			);
		}
		$c = self::valid();
		unset( $c['wallets'][0]['receive_address'] );
		$this->assertSame( array( 'wallets[0].receive_address' ), self::paths( $c ) );
	}

	public function test_a_legacy_provider_is_ignored_never_refused(): void {
		// Configurations saved while wallets had a type (the option, an export, QERSH_WC_CONFIG, a deleted wallet's
		// snapshot) still pass, whatever their `provider` says, and the type changes no rule: the old InstaPay type
		// with a number passes, and with an address it is refused like any other wallet.
		foreach ( array( 'vodafone_cash', 'etisalat_cash', 'orange_cash', 'instapay_app', 'bank_nbe', 'another_wallet', '', 7, null, array( 'x' ) ) as $provider ) {
			$c                           = self::valid();
			$c['wallets'][0]['provider'] = $provider;
			$this->assertSame( array(), Config::validate( $c ), var_export( $provider, true ) );
			$this->assertSame( array(), Config::validate( Config::with_defaults( $c ) ), var_export( $provider, true ) );
			$this->assertArrayNotHasKey( 'provider', Config::with_defaults( $c )['wallets'][0], var_export( $provider, true ) );
		}
		$c                                  = self::valid();
		$c['wallets'][0]['provider']        = 'instapay_app';
		$c['wallets'][0]['receive_address'] = 'shop.cairo@instapay';
		$this->assertSame( array( 'wallets[0].receive_address' ), self::paths( $c ) );
		$this->assertSame( array( 'wallets[0].receive_address' ), self::paths( Config::with_defaults( $c ) ) );
	}

	public function test_with_defaults_shapes_wallets(): void {
		$c = Config::with_defaults(
			array(
				'wallets' => array(
					array(
						'id'              => 'k7q2m9x4p1rz',
						'provider'        => 'orange_cash',
						'receive_address' => '+20 121 234 5678',
						'secret'          => 'never stored',
					),
					array(
						'enabled'         => false,
						'receive_address' => '٠١١١٢٢٢٣٣٤٤',
						'label'           => 'محفظة ثانية',
						'id'              => 'a1b2c3d4e5f6',
						'type'            => 'e_wallet',
					),
				),
			)
		);
		// The known fields only, in their order: the legacy provider and any other field are dropped.
		$this->assertSame(
			array(
				array(
					'id'              => 'k7q2m9x4p1rz',
					'label'           => '',
					'receive_address' => '01212345678',
					'enabled'         => true,
				),
				array(
					'id'              => 'a1b2c3d4e5f6',
					'label'           => 'محفظة ثانية',
					'receive_address' => '01112223344',
					'enabled'         => false,
				),
			),
			$c['wallets']
		);
		$this->assertSame( array(), Config::validate( $c ) );
		$this->assertSame( $c, Config::with_defaults( $c ) ); // a stored configuration reads back the same

		$broken = Config::with_defaults( array( 'wallets' => array( array( 'label' => 'x' ) ) ) );
		$this->assertSame( array( 'wallets[0].id', 'wallets[0].receive_address' ), self::paths( $broken ) );
		// A value that is not a wallet number is kept as given, for validate() to name: a handle never becomes
		// name@instapay, a masked number stays masked.
		foreach ( array( 'ShopCairo', 'Shop@InstaPay', '0111***3445', 1001234567 ) as $kept ) {
			$c = Config::with_defaults(
				array(
					'wallets' => array(
						array(
							'id'              => 'k7q2m9x4p1rz',
							'receive_address' => $kept,
						),
					),
				)
			);
			$this->assertSame( $kept, $c['wallets'][0]['receive_address'], var_export( $kept, true ) );
			$this->assertSame( array( 'wallets[0].receive_address' ), self::paths( $c ), var_export( $kept, true ) );
		}
	}

	public function test_wallet_number(): void {
		$this->assertSame( '01001234567', Config::wallet_number( '01001234567' ) );
		$this->assertSame( '01001234567', Config::wallet_number( '+20 100 123 4567' ) );
		$this->assertSame( '01001234567', Config::wallet_number( '0020 100 123 4567' ) );
		$this->assertSame( '01001234567', Config::wallet_number( '1001234567' ) );
		$this->assertSame( '01112223344', Config::wallet_number( '٠١١١٢٢٢٣٣٤٤' ) );
		$this->assertSame( '01112223344', Config::wallet_number( "\u{00A0}0111-222-3344\n" ) );
		foreach ( array( 'shop@instapay', 'shopcairo', '0111***3445', '0100123456', '0223456789', '', 1001234567, null, array( '01001234567' ) ) as $bad ) {
			$this->assertNull( Config::wallet_number( $bad ), var_export( $bad, true ) );
		}
	}

	public function test_store_rules(): void {
		$cases = array(
			array( 'order_ttl_min', 14, false ),
			array( 'order_ttl_min', 15, true ),
			array( 'order_ttl_min', 1440, true ),
			array( 'order_ttl_min', 1441, false ),
			array( 'order_ttl_min', '120', false ),
			array( 'min_total_minor', 0, true ),
			array( 'min_total_minor', -1, false ),
			array( 'min_total_minor', 10.5, false ),
			array( 'max_total_minor', 500000, true ),
			array( 'unmatched_retention_days', 0, false ),
			array( 'unmatched_retention_days', 1, true ),
			array( 'unmatched_retention_days', 3650, true ),
			array( 'unmatched_retention_days', 3651, false ),
			array( 'round_up_to_pound', 1, false ),
			array( 'allow_key_link', false, true ),
			array( 'txn_entry', 'yes', false ),
			array( 'admin_cancel_email_on_expiry', null, false ),
			array( 'remove_data_on_uninstall', true, true ),
		);
		foreach ( $cases as $case ) {
			list( $key, $value, $ok ) = $case;
			$c                        = Config::DEFAULTS;
			$c['store'][ $key ]       = $value;
			$this->assertSame( $ok ? array() : array( 'store.' . $key ), self::paths( $c ), $key . ' = ' . var_export( $value, true ) );
		}
		$c                             = Config::DEFAULTS;
		$c['store']['min_total_minor'] = 50000;
		$c['store']['max_total_minor'] = 10000;
		$this->assertSame( array( 'store.min_total_minor' ), self::paths( $c ) );
		$c['store']['max_total_minor'] = 50000;
		$this->assertSame( array(), Config::validate( $c ) );
	}

	public function test_is_acceptable_key_follows_the_app_form(): void {
		$vector = self::vector_key();
		$this->assertTrue( Config::is_acceptable_key( $vector ) );
		$this->assertTrue( Config::is_acceptable_key( ' ' . $vector . "\n" ) );
		$this->assertFalse( Config::is_acceptable_key( 'a merchant key with spaces inside' ) );
		$this->assertTrue( Config::is_acceptable_key( 'a-merchant-chosen-secret-of-24' ) );
		$this->assertTrue( Config::is_acceptable_key( 'qsk_with*odd*chars*but*long*enough' ) ); // the form accepts it; only a link refuses it
		$this->assertFalse( Config::is_acceptable_key( 'qsk_short' ) );
		$this->assertFalse( Config::is_acceptable_key( 42 ) );
		$this->assertFalse( Config::is_acceptable_key( null ) );
		$this->assertFalse( Config::is_acceptable_key( str_repeat( 'k', 23 ) ) );
		$this->assertTrue( Config::is_acceptable_key( str_repeat( 'k', 24 ) ) );
		$this->assertTrue( Config::is_acceptable_key( str_repeat( "\u{1F600}", 12 ) ) ); // 24 UTF-16 units, as JavaScript counts
		$this->assertFalse( Config::is_acceptable_key( str_repeat( "\u{00E9}", 23 ) ) );
		$this->assertFalse( Config::is_acceptable_key( str_repeat( 'k', 12 ) . "\u{00A0}" . str_repeat( 'k', 12 ) ) ); // NBSP is \s in JavaScript
		$this->assertTrue( Config::is_acceptable_key( str_repeat( 'k', 12 ) . "\u{0085}" . str_repeat( 'k', 12 ) ) ); // NEL is not
		$this->assertTrue( Config::is_acceptable_key( "\u{FEFF}" . str_repeat( 'k', 24 ) . "\u{3000}" ) ); // trimmed like String.trim
		$this->assertFalse( Config::is_acceptable_key( str_repeat( 'k', 24 ) . "\xFF" ) ); // not UTF-8
	}

	public function test_wallet_ids(): void {
		$this->assertTrue( Config::wallet_id_ok( 'k7q2m9x4p1rz' ) );
		$this->assertFalse( Config::wallet_id_ok( "k7q2m9x4p1rz\n" ) );
		$this->assertFalse( Config::wallet_id_ok( null ) );
		$seen = array();
		for ( $i = 0; $i < 50; $i++ ) {
			$id = Config::new_wallet_id();
			$this->assertMatchesRegularExpression( '/^[a-z0-9]{12}$/D', $id );
			$this->assertTrue( Config::wallet_id_ok( $id ) );
			$seen[ $id ] = true;
		}
		$this->assertCount( 50, $seen );
	}

	public function test_new_key_has_the_app_format(): void {
		$keys = array();
		for ( $i = 0; $i < 20; $i++ ) {
			$key = Config::new_key();
			$this->assertMatchesRegularExpression( '/^qsk_[A-Za-z0-9_-]{43}$/D', $key );
			$this->assertSame( 47, strlen( $key ) );
			$this->assertTrue( Config::is_acceptable_key( $key ) );
			$keys[ $key ] = true;
		}
		$this->assertCount( 20, $keys );
	}

	public function test_fingerprint(): void {
		$this->assertSame( '1e78ed71', Config::fingerprint( 'qsk_' . str_repeat( 'A', 43 ) ) ); // computed with node:crypto
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{8}$/D', Config::fingerprint( self::vector_key() ) );
		$this->assertNotSame( Config::fingerprint( 'a' ), Config::fingerprint( 'b' ) );
	}

	public function test_text_helpers_have_javascript_semantics(): void {
		$this->assertSame( 'a b', Config::js_trim( "\u{FEFF}\u{00A0}\t a b \u{2028}\u{3000}" ) );
		$this->assertSame( "\u{0085}a", Config::js_trim( "\u{0085}a" ) );
		$this->assertSame( 2, Config::utf16_length( "\u{1F600}" ) );
		$this->assertSame( 3, Config::utf16_length( "a\u{0628}\u{20AC}" ) );
		$this->assertSame( "a\u{FFFD}b", Config::to_valid_utf8( "a\xFFb" ) );
		$this->assertSame( "\u{FFFD}%A", Config::to_valid_utf8( "\xE0\xA4%A" ) ); // one replacement for a maximal subpart
		$this->assertSame( "\u{FFFD}\u{FFFD}", Config::to_valid_utf8( "\xED\xA0" ) ); // a surrogate is two errors
		$this->assertSame( "\u{FFFD}", Config::to_valid_utf8( "\xF0\x9F\x98" ) );
		$this->assertSame( "\u{1F600}", Config::to_valid_utf8( "\xF0\x9F\x98\x80" ) );
	}
}
