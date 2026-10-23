<?php
/**
 * Tests of the admin notices (wordpress.org guideline 11): which screens get a notice of ours, which alerts each
 * kind of screen shows, that each cause is printed once and a dismissed one stays hidden while its cause lasts, the
 * arguments every notice is printed with, and that the hooks are bound to the screens instead of being filtered late.
 *
 * @package QershPay\WooCommerce\Tests
 */

declare( strict_types=1 );

namespace QershPay\WooCommerce\Tests\Unit\Admin;

use QershPay\WooCommerce\Admin\Module;
use QershPay\WooCommerce\Admin\Notices;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

defined( 'ABSPATH' ) || exit;

/**
 * Admin notices, without WordPress.
 */
final class NoticesTest extends TestCase {

	const WALLET = 'k7q2m9x4p1rz';

	/**
	 * The screen ids of the orders list and the order screen, with and without HPOS.
	 *
	 * @return string[]
	 */
	private function order_screens(): array {
		return array( 'shop_order', 'woocommerce_page_wc-orders', 'edit-shop_order' );
	}

	/** Our page, the Payments tab of WooCommerce → Settings and the order screens; nothing else, whatever the tab. */
	public function test_kind(): void {
		$orders = $this->order_screens();
		$this->assertSame( 'woocommerce_page_qersh', Module::screen_id() );
		$this->assertSame( Notices::OURS, Notices::kind( 'woocommerce_page_qersh', '', $orders ) );
		$this->assertSame( Notices::OURS, Notices::kind( 'woocommerce_page_qersh', 'status', $orders ), 'our own tab changes nothing' );
		$this->assertSame( Notices::PAYMENTS, Notices::kind( 'woocommerce_page_wc-settings', 'checkout', $orders ) );
		foreach ( array( '', 'general', 'products', 'email', 'advanced' ) as $tab ) {
			$this->assertSame( '', Notices::kind( 'woocommerce_page_wc-settings', $tab, $orders ), 'settings tab ' . $tab );
		}
		foreach ( $orders as $screen ) {
			$this->assertSame( Notices::ORDERS, Notices::kind( $screen, '', $orders ), $screen );
			$this->assertSame( Notices::ORDERS, Notices::kind( $screen, 'checkout', $orders ), $screen . ' with a stray tab' );
		}
		$elsewhere = array(
			'dashboard',
			'edit-post',
			'post',
			'upload',
			'plugins',
			'users',
			'options-general',
			'edit-product',
			'product',
			'edit-shop_coupon',
			'woocommerce_page_wc-admin',
			'woocommerce_page_wc-status',
			'woocommerce_page_wc-reports',
			'toplevel_page_woocommerce',
			'',
		);
		foreach ( $elsewhere as $screen ) {
			$this->assertSame( '', Notices::kind( $screen, 'checkout', $orders ), $screen );
		}
		$this->assertSame( '', Notices::kind( 'woocommerce_page_wc-orders', '', array( 'shop_order' ) ), 'an order screen the list does not name' );
	}

	/** Everything on our page, errors and warnings on Payments, errors alone on the order screens, nothing elsewhere. */
	public function test_shows(): void {
		foreach ( array( 'error', 'warning', 'info' ) as $level ) {
			$this->assertTrue( Notices::shows( Notices::OURS, $level ), $level );
			$this->assertFalse( Notices::shows( '', $level ), $level . ' nowhere' );
			$this->assertFalse( Notices::shows( 'plugins', $level ), $level . ' on an unknown kind' );
		}
		$this->assertTrue( Notices::shows( Notices::PAYMENTS, 'error' ) );
		$this->assertTrue( Notices::shows( Notices::PAYMENTS, 'warning' ) );
		$this->assertFalse( Notices::shows( Notices::PAYMENTS, 'info' ) );
		$this->assertTrue( Notices::shows( Notices::ORDERS, 'error' ) );
		$this->assertFalse( Notices::shows( Notices::ORDERS, 'warning' ) );
		$this->assertFalse( Notices::shows( Notices::ORDERS, 'info' ) );
	}

	/**
	 * An alert as Diagnostics returns it.
	 *
	 * @param string $id     Id (the cause).
	 * @param string $level  Level.
	 * @param string $wallet Wallet id, '' for a site-wide alert.
	 * @return array
	 */
	private function alert( string $id, string $level, string $wallet = '' ): array {
		return array(
			'id'      => $id,
			'level'   => $level,
			'code'    => strtok( $id, ':' ),
			'wallet'  => $wallet,
			'message' => 'Message of ' . $id,
		);
	}

	/**
	 * Three site-wide alerts then three of one wallet, one of each level.
	 *
	 * @return array[]
	 */
	private function alerts(): array {
		return array(
			$this->alert( 'tables', 'error' ),
			$this->alert( 'timezone', 'warning' ),
			$this->alert( 'competition:20261023', 'info' ),
			$this->alert( 'silent_down:1792000000:' . self::WALLET, 'error', self::WALLET ),
			$this->alert( 'masked:' . self::WALLET, 'warning', self::WALLET ),
			$this->alert( 'pending_waiting:1792000000:3:' . self::WALLET, 'info', self::WALLET ),
		);
	}

	/**
	 * The ids of the alerts select() shows.
	 *
	 * @param array[]  $alerts    Alerts.
	 * @param string[] $dismissed Dismissed ids.
	 * @param string   $kind      Kind.
	 * @param string   $tab       Tab.
	 * @return string[]
	 */
	private function shown( array $alerts, array $dismissed, string $kind, string $tab = '' ): array {
		return array_column( Notices::select( $alerts, $dismissed, $kind, $tab )['show'], 'id' );
	}

	/** Each kind of screen gets its levels; our Wallets and Status tabs skip what they show themselves. */
	public function test_select_by_screen(): void {
		$all    = $this->alerts();
		$ids    = array_column( $all, 'id' );
		$site   = array_slice( $ids, 0, 3 );
		$wallet = array_slice( $ids, 3 );

		$this->assertSame( $ids, $this->shown( $all, array(), Notices::OURS, 'transfers' ) );
		$this->assertSame( $ids, $this->shown( $all, array(), Notices::OURS, 'settings' ) );
		$this->assertSame( $ids, $this->shown( $all, array(), Notices::OURS ), 'no tab named' );
		$this->assertSame( $site, $this->shown( $all, array(), Notices::OURS, 'wallets' ), 'the cards hold the wallet alerts' );
		$this->assertSame( $wallet, $this->shown( $all, array(), Notices::OURS, 'status' ), 'the Status tab lists the site-wide alerts' );

		$this->assertSame( array( 'tables', 'timezone', 'silent_down:1792000000:' . self::WALLET, 'masked:' . self::WALLET ), $this->shown( $all, array(), Notices::PAYMENTS ) );
		$this->assertSame( array( 'tables', 'silent_down:1792000000:' . self::WALLET ), $this->shown( $all, array(), Notices::ORDERS ) );
		$this->assertSame( array(), $this->shown( $all, array(), '' ) );
		$this->assertSame( array( 'tables', 'silent_down:1792000000:' . self::WALLET ), $this->shown( $all, array(), Notices::ORDERS, 'wallets' ), 'the tab rule is our page\'s alone' );
		$this->assertSame( array( 'tables', 'timezone', 'silent_down:1792000000:' . self::WALLET, 'masked:' . self::WALLET ), $this->shown( $all, array(), Notices::PAYMENTS, 'status' ), 'the tab rule is our page\'s alone' );
	}

	/** A dismissed alert stays hidden on every screen while its cause lasts; a dismissal whose cause is gone is dropped. */
	public function test_select_dismissed(): void {
		$all       = $this->alerts();
		$dismissed = array( 'tables', 'masked:' . self::WALLET, 'silent_down:1791000000:' . self::WALLET, Notices::WC_MISSING );

		$picked = Notices::select( $all, $dismissed, Notices::OURS, 'transfers' );
		$this->assertSame( array( 'timezone', 'competition:20261023', 'silent_down:1792000000:' . self::WALLET, 'pending_waiting:1792000000:3:' . self::WALLET ), array_column( $picked['show'], 'id' ) );
		$this->assertSame( array( 'tables', 'masked:' . self::WALLET ), $picked['keep'], 'an older episode of the phone and the WooCommerce-missing line are forgotten' );

		foreach ( array( Notices::PAYMENTS, Notices::ORDERS, '' ) as $kind ) {
			$picked = Notices::select( $all, $dismissed, $kind );
			$this->assertNotContains( 'tables', array_column( $picked['show'], 'id' ), $kind );
			$this->assertSame( array( 'tables', 'masked:' . self::WALLET ), $picked['keep'], 'kept whatever the screen shows: ' . $kind );
		}
		$this->assertSame(
			array( 'tables', 'masked:' . self::WALLET ),
			Notices::select( $all, $dismissed, Notices::OURS, 'wallets' )['keep'],
			'kept on the tab that shows it in a card too'
		);
		$this->assertSame( array(), Notices::select( array(), $dismissed, Notices::OURS )['keep'], 'no cause left' );
	}

	/** One notice per cause: a repeated id prints once, and malformed entries are left out. */
	public function test_select_once_per_cause(): void {
		$twice = array_merge( $this->alerts(), array( $this->alert( 'tables', 'error' ), $this->alert( 'masked:' . self::WALLET, 'warning', self::WALLET ) ) );
		$this->assertSame( array_column( $this->alerts(), 'id' ), $this->shown( $twice, array(), Notices::OURS, 'transfers' ) );
		$this->assertSame( array( 'tables' ), Notices::select( $twice, array( 'tables' ), Notices::OURS )['keep'], 'kept once too' );

		$odd = array(
			'x',
			null,
			array( 'id' => 'no_level' ),
			array(
				'id'      => '',
				'level'   => 'error',
				'message' => 'no id',
			),
			array(
				'id'      => 'no_wallet_key',
				'level'   => 'error',
				'message' => 'A site-wide alert without the key.',
			),
		);
		$this->assertSame( array( 'no_wallet_key' ), $this->shown( $odd, array(), Notices::ORDERS ) );
		$this->assertSame( array(), $this->shown( $odd, array(), Notices::OURS, 'status' ), 'read as site-wide' );
	}

	/** Every notice of ours is dismissible and carries its cause where the script reads it. */
	public function test_args(): void {
		$args = Notices::args( 'error', 'silent_down:1792000000:' . self::WALLET );
		$this->assertSame( 'error', $args['type'] );
		$this->assertTrue( $args['dismissible'] );
		$this->assertSame( array( 'qersh-wc-notice' ), $args['additional_classes'] );
		$this->assertSame( array( 'data-qersh-notice' => 'silent_down:1792000000:' . self::WALLET ), $args['attributes'] );
		$this->assertSame( 'warning', Notices::args( 'warning', Notices::WC_MISSING )['type'] );
		// assets/js/admin.js listens on the same class and attribute.
		$script = (string) file_get_contents( QERSH_WC_TESTS_ROOT . '/assets/js/admin.js' );
		$this->assertStringContainsString( "'.qersh-wc-notice .notice-dismiss'", $script );
		$this->assertStringContainsString( "'data-qersh-notice'", $script );
		$this->assertStringContainsString( "action: '" . Notices::DISMISS_ACTION . "'", $script );
	}

	/** The ids a dismissal may store, and who may store one. */
	public function test_dismissal_rules(): void {
		foreach ( array( 'tables', 'competition:20261023', 'url_changed:' . md5( 'x' ), 'silent_down:1792000000:' . self::WALLET, Notices::WC_MISSING, str_repeat( 'a', 160 ) ) as $id ) {
			$this->assertTrue( Notices::valid_id( $id ), $id );
		}
		foreach ( array( '', ' ', 'a b', '<b>', 'a/b', "tables\n", str_repeat( 'a', 161 ), 'عربي' ) as $id ) {
			$this->assertFalse( Notices::valid_id( $id ), $id );
		}
		$this->assertSame( 'activate_plugins', Notices::dismiss_cap( Notices::WC_MISSING ) );
		$this->assertSame( Module::CAP, Notices::dismiss_cap( 'tables' ) );
		$this->assertSame( 'manage_woocommerce', Notices::dismiss_cap( '' ) );
	}

	/**
	 * The hooks are bound to the screens: admin_notices is added on `current_screen` and `load-plugins.php` only,
	 * never at registration; every notice goes through wp_admin_notice(); no dashboard widget, no network or
	 * user admin notice, and nothing promotional, anywhere in the plugin.
	 */
	public function test_hooks_bound_to_screens(): void {
		$root    = QERSH_WC_TESTS_ROOT;
		$notices = (string) file_get_contents( $root . '/src/Admin/Notices.php' );
		$module  = (string) file_get_contents( $root . '/src/Admin/Module.php' );

		$this->assertStringNotContainsString( 'admin_notices', $module, 'Module leaves the notices to Notices' );
		$this->assertStringContainsString( "Notices::register( \$wc_ready );\n\t\tif ( ! \$wc_ready ) {", $module, 'registered in both states, before the early return' );
		$this->assertStringContainsString( "add_action( 'current_screen', array( __CLASS__, 'hook_alerts' ) )", $notices );
		$this->assertStringContainsString( "add_action( 'load-plugins.php', array( __CLASS__, 'hook_wc_missing' ) )", $notices );
		$this->assertSame( 1, preg_match( '/function register\( bool \$wc_ready \): void \{(.*?)\n\t\}/s', $notices, $register ) );
		$this->assertStringNotContainsString( 'admin_notices', $register[1], 'no global admin_notices hook' );
		$this->assertSame( 2, preg_match_all( "/add_action\( 'admin_notices', array\( __CLASS__, '(print_alerts|wc_missing_notice)' \) \)/", $notices ), 'one hook for the alerts, one for the WooCommerce-missing line' );
		$this->assertStringContainsString( "add_action( 'admin_print_footer_scripts', array( __CLASS__, 'print_dismiss_script' ) )", $notices, 'the WooCommerce-missing line is dismissible without admin.js' );
		$this->assertSame( 2, preg_match_all( '/^\t\twp_admin_notice\(/m', $notices ), 'the alerts and the WooCommerce-missing line' );
		$this->assertSame( 2, preg_match_all( '/self::args\( /', $notices ), 'both with the dismissible arguments' );
		$this->assertSame( 0, preg_match( '/echo\s+[\'"]<div class="notice/', $notices . $module ), 'no hand-written notice markup' );
		$this->assertStringContainsString( 'current_user_can( Module::CAP )', $notices );
		$this->assertStringContainsString( "current_user_can( 'activate_plugins' )", $notices );

		$sources = array();
		foreach ( array_merge( glob( $root . '/src/*.php' ), glob( $root . '/src/*/*.php' ), glob( $root . '/templates/*/*.php' ), glob( $root . '/assets/js/*.js' ) ) as $file ) {
			$sources[ substr( $file, strlen( $root ) + 1 ) ] = (string) file_get_contents( $file );
		}
		$this->assertGreaterThan( 30, count( $sources ) );
		$rules = array(
			'a notice outside admin_notices' => '/network_admin_notices|all_admin_notices|user_admin_notices/',
			'a dashboard widget'             => '/wp_dashboard_setup|wp_add_dashboard_widget|wp_network_dashboard_setup/',
			'a WooCommerce admin note'       => '/WC_Admin_Notices|add_custom_notice|Automattic\\\\WooCommerce\\\\Admin\\\\Notes/',
			'an upgrade or review nag'       => '/upgrade now|upgrade to (?:pro|premium)|go pro|premium|\bpro version|discount|coupon code|rate us|review us|leave a review|special offer|\bbuy now\b/i',
		);
		foreach ( $sources as $path => $source ) {
			foreach ( $rules as $what => $rule ) {
				$this->assertSame( 0, preg_match( $rule, $source, $m ), $path . ': ' . $what . ( isset( $m[0] ) ? ' (' . $m[0] . ')' : '' ) );
			}
		}
		unset( $sources['src/Admin/Notices.php'] );
		$this->assertSame( 0, preg_match_all( "/add_action\( '(?:admin_notices|current_screen|load-plugins\.php)'/", implode( '', $sources ) ), 'no other file hooks a notice' );
	}
}
