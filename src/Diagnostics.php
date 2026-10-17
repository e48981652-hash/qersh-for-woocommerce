<?php
/**
 * Wallet state, alerts and the self-test (docs/17 §4.9). The only file of the plugin that sends an HTTP request.
 *
 * @package QershPay\WooCommerce
 */

namespace QershPay\WooCommerce;

use QershPay\WooCommerce\Admin\View;
use QershPay\WooCommerce\Core\Config;
use QershPay\WooCommerce\Core\Payer;
use QershPay\WooCommerce\Core\SetupLink;
use QershPay\WooCommerce\Core\Time;
use QershPay\WooCommerce\Core\TxnId;
use QershPay\WooCommerce\Store\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * What the admin shows about each wallet and the site, read from the database and from the last envelope each wallet
 * delivered. Nothing here sends a request on its own: the self-test runs only when an administrator clicks "Check
 * your store URL", and Cron and Action Scheduler health are read from the database.
 *
 * The static methods with no WordPress call are the pure rules (unit-tested); the instance methods read the store
 * and turn the rules into texts.
 */
final class Diagnostics {

	/**
	 * 35 minutes without a heartbeat or a transfer, with an order waiting: the phone is suspected to be offline.
	 */
	const SILENT_SUSPECT_SEC = 2100;

	/**
	 * After two hours the alert says the phone is offline.
	 */
	const SILENT_DOWN_SEC = 7200;

	/**
	 * A wallet counts as sending heartbeats when its last heartbeat came within a day of its last delivery.
	 */
	const HEARTBEAT_WINDOW_SEC = 86400;

	/**
	 * A pending key link older than this is reminded once a day.
	 */
	const PENDING_REMIND_SEC = 86400;

	/**
	 * A pending key link older than this gets the 7-day alert, and may be cancelled (docs/17 §4.8 rule 9).
	 */
	const PENDING_STALE_SEC = 604800;

	/**
	 * Scheduled actions overdue by more than this mean Cron is not running them (docs/17 §4.7).
	 */
	const AS_LATE_SEC = 600;

	/**
	 * The Action Scheduler check runs at most once a minute.
	 */
	const AS_CHECK_SEC = 60;

	/**
	 * Option with the Action Scheduler health flag (autoload off).
	 */
	const AS_OPTION = 'qersh_wc_as_ok';

	/**
	 * Option where the webhook and the order page record a transaction id of an unexpected format.
	 */
	const DRIFT_OPTION = 'qersh_wc_txn_drift';

	/**
	 * Transient caching the storage engine check (an information_schema read).
	 */
	const ENGINE_TRANSIENT = 'qersh_wc_engine_check';

	/**
	 * Seconds each self-test request may take. The test answers 401 or 404 at once, and up to five requests run in
	 * one admin request, which proxies and FastCGI usually end after 60 seconds.
	 */
	const PROBE_TIMEOUT = 5;

	/**
	 * The three address forms a delivery can arrive on.
	 */
	const FORMS = array( 'wp-json', 'rest_route', 'wc-api' );

	/**
	 * Wallet types that read text messages (all but the InstaPay app, which reads notifications).
	 */
	const NOTIFICATION_PROVIDERS = array( 'instapay_app' );

	/**
	 * Wallet types whose messages always carry the payer's number when "Send sender details" is on full.
	 */
	const NUMBER_PROVIDERS = array( 'vodafone_cash', 'etisalat_cash' );

	/**
	 * Security plugins and firewalls recognised in a blocked answer, by a pattern of their body or headers.
	 */
	const BLOCKERS = array(
		'/wordfence|wfwaf/i'                                 => 'Wordfence',
		'/disable[ -]rest[ -]api|\bDRA:/i'                   => 'Disable REST API',
		'/solid ?security|ithemes ?security|\bitsec/i'       => 'Solid Security',
		'/all[- ]in[- ]one[- ](wp[- ])?security|aiowps/i'    => 'All-In-One Security',
		'/sucuri/i'                                          => 'Sucuri',
		'/imunify/i'                                         => 'Imunify360',
		'/mod_?security|not acceptable!/i'                   => 'ModSecurity',
		'/perfmatters/i'                                     => 'Perfmatters',
		'/wp[- ]hide/i'                                      => 'WP Hide & Security Enhancer',
		'/shield ?security|\bicwp/i'                         => 'Shield Security',
		'/clearfy/i'                                         => 'Clearfy',
		'/wp[- ]cerber|cerber/i'                             => 'WP Cerber',
		'/litespeed.*(waf|firewall)|lsws.*(waf|firewall)/i'  => 'LiteSpeed',
	);

	/**
	 * The plugin.
	 *
	 * @var Plugin|null
	 */
	private $plugin;

	/**
	 * Builds the service.
	 *
	 * @param Plugin|null $plugin The plugin.
	 */
	public function __construct( $plugin = null ) {
		$this->plugin = $plugin;
	}

	/*
	 * ---------------------------------------------------------------------------------------------------------------
	 * Deliveries (called by Webhook\Controller for every verified delivery, before dispatch).
	 * ---------------------------------------------------------------------------------------------------------------
	 */

	/**
	 * Writes the wallet's runtime state from a verified delivery, in one UPDATE (docs/17 §4.4 step 7): when it
	 * arrived and which event, the phone's state, grace_until, the address form it came on, the heartbeat, whether
	 * the payer's number arrived masked, and for webhook.test the last_test_* columns. Any delivery also ends a
	 * "phone silent" episode. Never throws.
	 *
	 * @param string $wallet   Wallet id.
	 * @param array  $envelope Parsed envelope.
	 * @param string $form     `wp-json`, `rest_route` or `wc-api`.
	 * @param int    $now      UTC seconds (0 = now).
	 * @param array  $verified Optional Verifier result: `v1_index` > 0 means the store's key matched a v1 that is not
	 *                         the first, so the phone holds a newer key (docs/17 §4.8).
	 */
	public function record_delivery( string $wallet, array $envelope, string $form, int $now = 0, array $verified = array() ): void {
		try {
			if ( ! $this->plugin instanceof Plugin || ! Config::wallet_id_ok( $wallet ) ) {
				return;
			}
			$now      = $now > 0 ? $now : time();
			$cfg      = $this->plugin->wallet( $wallet );
			$provider = is_array( $cfg ) && isset( $cfg['provider'] ) ? (string) $cfg['provider'] : '';
			$fields   = self::delivery_fields( $envelope, $provider, $form, self::request_host(), $now, $verified );
			$store    = $this->plugin->wallets();
			$store->ensure( $wallet );
			$store->touch( $wallet, $fields );

			$drift = self::drift_of( $envelope, $provider );
			if ( null !== $drift && null === self::drift_tail( get_option( self::DRIFT_OPTION, null ) ) ) {
				update_option(
					self::DRIFT_OPTION,
					array(
						$provider => array(
							'tail'    => $drift,
							'at'      => $now,
							'version' => defined( 'QERSH_WC_VERSION' ) ? QERSH_WC_VERSION : '',
						),
					),
					false
				);
				$this->plugin->logger()->warning( 'Qersh received an Orange Cash transaction id of an unexpected format (…' . $drift . '); the transaction id box is hidden until the plugin is updated.' );
			}
		} catch ( \Throwable $e ) {
			if ( $this->plugin instanceof Plugin ) {
				$this->plugin->logger()->warning( 'Qersh could not record the wallet state of a delivery.', array( 'error' => get_class( $e ) ) );
			}
		}
	}

	/**
	 * The qersh_wallets columns a delivery writes. Pure.
	 *
	 * @param array  $envelope Parsed envelope.
	 * @param string $provider The wallet's type in the store.
	 * @param string $form     Address form.
	 * @param string $host     Host the request arrived on (lower case, may carry a port), or ''.
	 * @param int    $now      UTC seconds.
	 * @param array  $verified Optional `[ 'v1_index' => int ]`.
	 * @return array Column => value.
	 */
	public static function delivery_fields( array $envelope, string $provider, string $form, string $host, int $now, array $verified = array() ): array {
		$event  = isset( $envelope['event'] ) && is_string( $envelope['event'] ) ? $envelope['event'] : '';
		$device = isset( $envelope['device'] ) && is_array( $envelope['device'] ) ? $envelope['device'] : null;
		$data   = isset( $envelope['data'] ) && is_array( $envelope['data'] ) ? $envelope['data'] : array();
		$fields = array(
			'last_delivery_at' => $now,
			'last_event'       => '' === $event ? null : $event,
			'silent_since'     => null,
			'silent_notified'  => 0,
		);
		if ( '' !== $host ) {
			$fields['seen_host'] = $host;
		}
		if ( in_array( $form, self::FORMS, true ) ) {
			$fields['seen_form'] = $form;
		}
		$state = self::device_state( $device, $verified );
		if ( null !== $state ) {
			$fields['last_device_state'] = $state;
		}
		$subscription          = isset( $envelope['subscription'] ) && is_array( $envelope['subscription'] ) ? $envelope['subscription'] : array();
		$grace                 = isset( $subscription['grace_until'] ) ? Time::iso_to_sec( $subscription['grace_until'] ) : null;
		$fields['grace_until'] = null !== $grace && $grace > 0 ? $grace : null;

		if ( 'device.heartbeat' === $event ) {
			$fields['last_heartbeat_at'] = $now;
		}
		if ( 'transfer.received' === $event ) {
			$masked = self::sender_masked_value( $provider, isset( $data['counterparty'] ) ? $data['counterparty'] : null );
			if ( null !== $masked ) {
				$fields['sender_masked'] = $masked;
			}
			if ( isset( $data['auto_confirm_ok'] ) && true === $data['auto_confirm_ok'] ) {
				$fields['auto_seen'] = 1;
			}
		}
		return $fields;
	}

	/**
	 * The last three digits of an Orange Cash transaction id whose format this version does not expect, or null
	 * (docs/17 §4.9, "transaction id format drift"). Pure.
	 *
	 * @param array  $envelope Parsed envelope.
	 * @param string $provider The wallet's type.
	 * @return string|null
	 */
	public static function drift_of( array $envelope, string $provider ): ?string {
		if ( ! in_array( $provider, TxnId::CLAIM_PROVIDERS, true ) || ! isset( $envelope['event'] ) || 'transfer.received' !== $envelope['event'] ) {
			return null;
		}
		$data = isset( $envelope['data'] ) && is_array( $envelope['data'] ) ? $envelope['data'] : array();
		$raw  = isset( $data['provider_txn_id'] ) && is_string( $data['provider_txn_id'] ) ? trim( $data['provider_txn_id'] ) : '';
		if ( '' === $raw || null !== TxnId::canonical_stored( $provider, $raw ) ) {
			return null;
		}
		$digits = (string) preg_replace( '/[^0-9A-Za-z]/', '', $raw );
		return '' === $digits ? '***' : substr( $digits, -3 );
	}

	/**
	 * The small JSON kept from `device.state` (docs/17 §4.3, at most 255 characters): only the booleans the envelope
	 * carries, the app version, and `nk` when the phone holds a newer key. Null without a device. Pure.
	 *
	 * @param array|null $device   `device` of the envelope.
	 * @param array      $verified Optional `[ 'v1_index' => int ]`.
	 * @return array|null
	 */
	public static function device_state( $device, array $verified = array() ): ?array {
		if ( ! is_array( $device ) ) {
			return null;
		}
		$state = isset( $device['state'] ) && is_array( $device['state'] ) ? $device['state'] : array();
		$out   = array();
		$map   = array(
			'sms_permission'        => 'sms',
			'notification_access'   => 'notif',
			'background_restricted' => 'bg',
			'battery_optimized'     => 'batt',
		);
		foreach ( $map as $key => $short ) {
			if ( array_key_exists( $key, $state ) && is_bool( $state[ $key ] ) ) {
				$out[ $short ] = $state[ $key ];
			}
		}
		if ( isset( $device['app_version'] ) && is_string( $device['app_version'] ) && '' !== $device['app_version'] ) {
			$out['v'] = substr( preg_replace( '/[^0-9A-Za-z.+_-]/', '', $device['app_version'] ), 0, 20 );
		}
		if ( isset( $verified['v1_index'] ) && is_int( $verified['v1_index'] ) && $verified['v1_index'] > 0 ) {
			$out['nk'] = 1;
		}
		return $out;
	}

	/**
	 * Whether a transfer's payer arrived masked (docs/17 §4.9): 1 for a `*` in the number, or for a Vodafone Cash or
	 * e& money transfer with no number, name or handle; 0 once a full number or handle arrives; null to leave the
	 * flag as it is (an Orange Cash or bank message that names no number). Pure.
	 *
	 * @param string $provider     Wallet type.
	 * @param mixed  $counterparty `data.counterparty`.
	 * @return int|null
	 */
	public static function sender_masked_value( string $provider, $counterparty ): ?int {
		$cp     = is_array( $counterparty ) ? $counterparty : array();
		$number = isset( $cp['number'] ) && is_string( $cp['number'] ) ? trim( $cp['number'] ) : '';
		$name   = isset( $cp['name'] ) && is_string( $cp['name'] ) ? trim( $cp['name'] ) : '';
		$handle = isset( $cp['handle'] ) && is_string( $cp['handle'] ) ? trim( $cp['handle'] ) : '';
		if ( false !== strpos( $number, '*' ) ) {
			return 1;
		}
		if ( null !== Payer::key_of( $cp ) ) {
			return 0;
		}
		if ( '' === $number && '' === $name && '' === $handle && in_array( $provider, self::NUMBER_PROVIDERS, true ) ) {
			return 1;
		}
		return null;
	}

	/*
	 * ---------------------------------------------------------------------------------------------------------------
	 * Alerts.
	 * ---------------------------------------------------------------------------------------------------------------
	 */

	/**
	 * The alerts of one wallet (docs/17 §4.9), each `[ 'id', 'level', 'code', 'wallet', 'message' ]` with the
	 * message as escaped HTML. Card-only lines (grace period, last delivery, the test line) are the card's own.
	 *
	 * @param string $wallet Wallet id.
	 * @return array[]
	 */
	public function wallet_alerts( string $wallet ): array {
		if ( ! $this->plugin instanceof Plugin || ! $this->plugin->tables_ready() ) {
			return array();
		}
		$cfg = $this->plugin->wallet( $wallet );
		if ( null === $cfg ) {
			return array();
		}
		try {
			$row = $this->plugin->wallets()->get( $wallet );
		} catch ( \Throwable $e ) {
			return array();
		}
		$now      = time();
		$provider = isset( $cfg['provider'] ) ? (string) $cfg['provider'] : '';
		$secrets  = $this->plugin->secrets();
		$fp       = $secrets->fingerprint( $wallet );
		$current  = self::url_parts( self::store_url( $wallet ) );
		$waiting  = false;
		if ( null !== $row && 'none' !== self::silent_state( $row, $now, true )['state'] ) {
			$waiting = $this->orders_waiting( $wallet, $now ) > 0;
		}
		$codes = self::wallet_alert_codes(
			array(
				'provider'       => $provider,
				'row'            => $row,
				'fp'             => $fp,
				'key_state'      => $secrets->state( $wallet ),
				'now'            => $now,
				'cur_host'       => $current['host'],
				'cur_form'       => $current['form'],
				'orders_waiting' => $waiting,
			)
		);
		$out = array();
		foreach ( $codes as $item ) {
			$message = $this->wallet_alert_text( $item, $cfg );
			if ( '' === $message ) {
				continue;
			}
			$out[] = array(
				'id'      => $item['id'] . ':' . $wallet,
				'level'   => $item['level'],
				'code'    => $item['code'],
				'wallet'  => $wallet,
				'message' => $message,
			);
		}
		return $out;
	}

	/**
	 * The alert codes of a wallet. Pure.
	 *
	 * @param array $ctx `provider`, `row` (qersh_wallets row or null), `fp` (Secrets::fingerprint()), `key_state`,
	 *                   `now`, `cur_host` and `cur_form` (the store URL now), `orders_waiting` (bool).
	 * @return array[] Each `[ 'code', 'level', 'id', 'since' => ?int ]`.
	 */
	public static function wallet_alert_codes( array $ctx ): array {
		$provider = isset( $ctx['provider'] ) ? (string) $ctx['provider'] : '';
		$row      = isset( $ctx['row'] ) && is_array( $ctx['row'] ) ? $ctx['row'] : null;
		$fp       = isset( $ctx['fp'] ) && is_array( $ctx['fp'] ) ? $ctx['fp'] : null;
		$now      = isset( $ctx['now'] ) ? (int) $ctx['now'] : 0;
		$out      = array();
		$add      = function ( $code, $level, $id, $since = null ) use ( &$out ) {
			$out[] = array(
				'code'  => $code,
				'level' => $level,
				'id'    => $id,
				'since' => $since,
			);
		};

		$key_state = isset( $ctx['key_state'] ) ? (string) $ctx['key_state'] : '';
		if ( 'unreadable' === $key_state ) {
			$add( 'key_unreadable', 'error', 'key_unreadable' );
		} elseif ( 'constant_invalid' === $key_state ) {
			$add( 'key_constant_invalid', 'error', 'key_constant_invalid' );
		}

		if ( null !== $row ) {
			foreach ( self::device_alerts( $provider, isset( $row['last_device_state'] ) ? $row['last_device_state'] : null ) as $code ) {
				$add( $code, 'error', $code );
			}
			$silent = self::silent_state( $row, $now, ! empty( $ctx['orders_waiting'] ) );
			if ( 'none' !== $silent['state'] ) {
				$add( 'silent_' . $silent['state'], 'error', 'silent_' . $silent['state'] . ':' . $silent['since'], $silent['since'] );
			}
			if ( ! empty( $row['sender_masked'] ) ) {
				$add( 'masked', 'warning', 'masked' );
			}
			$seen_host = isset( $row['seen_host'] ) ? (string) $row['seen_host'] : '';
			$seen_form = isset( $row['seen_form'] ) ? (string) $row['seen_form'] : '';
			if ( self::url_changed( isset( $ctx['cur_host'] ) ? (string) $ctx['cur_host'] : '', isset( $ctx['cur_form'] ) ? (string) $ctx['cur_form'] : '', $seen_host, $seen_form ) ) {
				$add( 'url_changed', 'error', 'url_changed:' . md5( $seen_host . '|' . $seen_form . '|' . ( isset( $ctx['cur_host'] ) ? $ctx['cur_host'] : '' ) ) );
			}
			$state = isset( $row['last_device_state'] ) && is_array( $row['last_device_state'] ) ? $row['last_device_state'] : array();
			if ( ! empty( $state['nk'] ) ) {
				$add( 'newer_key', 'warning', 'newer_key' );
			}
		}

		$pending = self::pending_state( $fp, $now );
		if ( 'stale' === $pending['state'] ) {
			$add( 'pending_stale', 'warning', 'pending_stale:' . $pending['since'], $pending['since'] );
		} elseif ( 'waiting' === $pending['state'] ) {
			$add( 'pending_waiting', 'info', 'pending_waiting:' . $pending['since'] . ':' . $pending['day'], $pending['since'] );
		}
		return $out;
	}

	/**
	 * Phone state alerts from the stored `last_device_state` (only for keys the last envelope carried). Pure.
	 *
	 * @param string $provider Wallet type.
	 * @param mixed  $state    Decoded last_device_state.
	 * @return string[] `device_sms`, `device_notif`, `device_bg`.
	 */
	public static function device_alerts( string $provider, $state ): array {
		$state = is_array( $state ) ? $state : array();
		$out   = array();
		$sms   = ! in_array( $provider, self::NOTIFICATION_PROVIDERS, true );
		if ( $sms && array_key_exists( 'sms', $state ) && false === $state['sms'] ) {
			$out[] = 'device_sms';
		}
		if ( ! $sms && array_key_exists( 'notif', $state ) && false === $state['notif'] ) {
			$out[] = 'device_notif';
		}
		if ( array_key_exists( 'bg', $state ) && true === $state['bg'] ) {
			$out[] = 'device_bg';
		}
		return $out;
	}

	/**
	 * The "phone silent" rule (docs/17 §4.9): for a wallet whose phone sends heartbeats, 35 minutes without a
	 * heartbeat or a transfer while an order waits is `suspect`, two hours `down`. A wallet without heartbeats (the
	 * basic plan) never alarms. Pure.
	 *
	 * @param array|null $row            qersh_wallets row.
	 * @param int        $now            UTC seconds.
	 * @param bool       $orders_waiting Whether a pending order waits for a transfer to this wallet.
	 * @return array `[ 'state' => 'none'|'suspect'|'down', 'since' => ?int ]`
	 */
	public static function silent_state( $row, int $now, bool $orders_waiting ): array {
		$none = array(
			'state' => 'none',
			'since' => null,
		);
		if ( ! is_array( $row ) ) {
			return $none;
		}
		$beat = isset( $row['last_heartbeat_at'] ) ? (int) $row['last_heartbeat_at'] : 0;
		$last = max( $beat, isset( $row['last_delivery_at'] ) ? (int) $row['last_delivery_at'] : 0 );
		if ( $beat <= 0 || $last - $beat > self::HEARTBEAT_WINDOW_SEC ) {
			return $none;
		}
		$quiet = $now - $last;
		if ( $quiet < self::SILENT_SUSPECT_SEC || ! $orders_waiting ) {
			return $none;
		}
		return array(
			'state' => $quiet >= self::SILENT_DOWN_SEC ? 'down' : 'suspect',
			'since' => $last,
		);
	}

	/**
	 * How long a generated link has been pending (docs/17 §4.8 rule 9). Pure.
	 *
	 * @param array|null $fp  Secrets::fingerprint().
	 * @param int        $now UTC seconds.
	 * @return array `[ 'state' => 'none'|'waiting'|'stale', 'since' => ?int, 'day' => int ]`
	 */
	public static function pending_state( $fp, int $now ): array {
		$at = is_array( $fp ) && ! empty( $fp['pending_at'] ) ? (int) $fp['pending_at'] : 0;
		if ( $at <= 0 ) {
			return array(
				'state' => 'none',
				'since' => null,
				'day'   => 0,
			);
		}
		$age = $now - $at;
		if ( $age >= self::PENDING_STALE_SEC ) {
			$state = 'stale';
		} elseif ( $age >= self::PENDING_REMIND_SEC ) {
			$state = 'waiting';
		} else {
			$state = 'none';
		}
		return array(
			'state' => $state,
			'since' => $at,
			'day'   => intdiv( max( 0, $age ), 86400 ),
		);
	}

	/**
	 * Whether a pending key may be cancelled on its own: proven not taken by the phone, because a valid delivery
	 * that did not promote it arrived after it was made, and 7 days have passed (docs/17 §4.8 rule 9). Pure.
	 *
	 * @param array|null $fp  Secrets::fingerprint().
	 * @param array|null $row qersh_wallets row.
	 * @param int        $now UTC seconds.
	 * @return bool
	 */
	public static function should_cancel_pending( $fp, $row, int $now ): bool {
		$at = is_array( $fp ) && ! empty( $fp['pending_at'] ) ? (int) $fp['pending_at'] : 0;
		if ( $at <= 0 || $now - $at < self::PENDING_STALE_SEC || ! is_array( $row ) ) {
			return false;
		}
		return isset( $row['last_delivery_at'] ) && (int) $row['last_delivery_at'] > $at;
	}

	/**
	 * Whether the store URL changed since the phone last delivered (docs/17 §4.9): another host, or another form
	 * (pretty or plain permalinks). A phone on the fallback address (`wc-api`) is compared by host only. Pure.
	 *
	 * @param string $cur_host  Host of rest_url() now.
	 * @param string $cur_form  Form of rest_url() now.
	 * @param string $seen_host Host of the last delivery ('' when none).
	 * @param string $seen_form Form of the last delivery.
	 * @return bool
	 */
	public static function url_changed( string $cur_host, string $cur_form, string $seen_host, string $seen_form ): bool {
		if ( '' === $seen_host || '' === $cur_host ) {
			return false;
		}
		if ( self::normal_host( $cur_host ) !== self::normal_host( $seen_host ) ) {
			return true;
		}
		if ( 'wc-api' === $seen_form || ! in_array( $seen_form, self::FORMS, true ) ) {
			return false;
		}
		return $seen_form !== $cur_form;
	}

	/**
	 * A host for comparison: lower case, without a default port. Pure.
	 *
	 * @param string $host Host, optionally with `:port`.
	 * @return string
	 */
	public static function normal_host( string $host ): string {
		$host = strtolower( trim( $host ) );
		return (string) preg_replace( '/:(80|443)$/', '', $host );
	}

	/**
	 * The address form of a store URL built by rest_url() or the fallback. Pure.
	 *
	 * @param string $query Query string of the URL.
	 * @return string
	 */
	public static function form_of_query( string $query ): string {
		if ( 1 === preg_match( '/(^|&)wc-api=/', $query ) ) {
			return 'wc-api';
		}
		if ( 1 === preg_match( '/(^|&)rest_route=/', $query ) ) {
			return 'rest_route';
		}
		return 'wp-json';
	}

	/**
	 * Whether PHP's time zone database knows Egypt's summer time (Cairo is UTC+3 on 2026-07-01). Pure.
	 *
	 * @return bool
	 */
	public static function tzdata_ok(): bool {
		try {
			$cairo = new \DateTimeZone( 'Africa/Cairo' );
			$day   = new \DateTimeImmutable( '2026-07-01 12:00:00', new \DateTimeZone( 'UTC' ) );
			return 10800 === $cairo->getOffset( $day );
		} catch ( \Exception $e ) {
			return false;
		}
	}

	/**
	 * Site-wide alerts (docs/17 §4.9), each `[ 'id', 'level', 'code', 'wallet' => '', 'message' ]`.
	 *
	 * @param bool $ours True on the plugin's own page (adds the informational lines).
	 * @return array[]
	 */
	public function global_alerts( bool $ours = false ): array {
		$out = array();
		$add = function ( $id, $level, $code, $message ) use ( &$out ) {
			$out[] = array(
				'id'      => $id,
				'level'   => $level,
				'code'    => $code,
				'wallet'  => '',
				'message' => $message,
			);
		};
		if ( ! $this->plugin instanceof Plugin ) {
			return $out;
		}
		if ( ! $this->plugin->tables_ready() ) {
			$add( 'tables', 'error', 'tables', esc_html__( 'The Qersh tables are missing, so the store answers the phone 503 and no transfer is recorded. Deactivate and activate Qersh for WooCommerce, and check that the database user may create tables.', 'qersh-for-woocommerce' ) );
			return $out;
		}
		if ( 'Africa/Cairo' !== (string) get_option( 'timezone_string', '' ) ) {
			$add( 'timezone', 'warning', 'timezone', esc_html__( 'The site time zone is not Cairo, so order times in the admin and in WooCommerce emails differ from your clock. Set it in Settings → General → Timezone: Cairo.', 'qersh-for-woocommerce' ) );
		}
		if ( ! self::tzdata_ok() ) {
			$add( 'tzdata', 'warning', 'tzdata', esc_html__( 'The time zone database of PHP on your server is old, so email deadlines are an hour late in summer. Ask your host to update it.', 'qersh-for-woocommerce' ) );
		}
		if ( ! $this->as_ok() ) {
			$add( 'as_late', 'error', 'as_late', esc_html__( '“New order” and “Processing” emails for Qersh orders are waiting for Cron and will not be sent on their own. Set up a real Cron on your hosting, or run them now from Tools → Scheduled Actions.', 'qersh-for-woocommerce' ) );
		}
		$engines = $this->non_innodb_tables();
		if ( ! empty( $engines ) ) {
			/* translators: %s: list of database table names. */
			$add( 'innodb', 'error', 'innodb', sprintf( esc_html__( 'These Qersh tables do not use the InnoDB engine, so the locks that keep two deliveries apart do not work: %s. Ask your host to convert them to InnoDB.', 'qersh-for-woocommerce' ), View::ltr( implode( ', ', $engines ) ) ) );
		}
		$tail = self::drift_tail( get_option( self::DRIFT_OPTION, null ) );
		if ( null !== $tail && $this->has_provider( 'orange_cash' ) ) {
			/* translators: %s: the last three digits of a transaction id. */
			$add( 'txn_drift:' . $tail, 'error', 'txn_drift', sprintf( esc_html__( 'An Orange Cash transfer arrived with a transaction id whose format this version does not expect (…%s), so your customers\' ids may not match it. Update Qersh for WooCommerce.', 'qersh-for-woocommerce' ), View::ltr( $tail ) ) . '<br>' . esc_html__( 'Customers cannot enter the transaction id for this wallet until you update Qersh for WooCommerce.', 'qersh-for-woocommerce' ) );
		}
		if ( $ours ) {
			$count = $this->competition_today();
			if ( $count > 0 ) {
				/* translators: %s: number of transfers. */
				$add( 'competition:' . gmdate( 'Ymd' ), 'info', 'competition', sprintf( esc_html( _n( 'Unpaid orders with the same amount kept %s transfer from being confirmed on its own today.', 'Unpaid orders with the same amount kept %s transfers from being confirmed on their own today.', $count, 'qersh-for-woocommerce' ) ), View::ltr( (string) $count ) ) );
			}
		}
		return $out;
	}

	/**
	 * The text of one wallet alert, as escaped HTML ('' for an unknown code).
	 *
	 * @param array $item Item of wallet_alert_codes().
	 * @param array $cfg  Wallet entry.
	 * @return string
	 */
	private function wallet_alert_text( array $item, array $cfg ): string {
		$name   = esc_html( View::wallet_name( $cfg ) ) . ': ';
		$at     = esc_html__( 'At the last delivery:', 'qersh-for-woocommerce' ) . ' ';
		$since  = isset( $item['since'] ) && null !== $item['since'] ? View::ltr( View::time( (int) $item['since'] ) ) : '';
		$prefix = $name;
		switch ( $item['code'] ) {
			case 'key_unreadable':
				return $prefix . esc_html__( 'The connection key is stored but cannot be read any more (the site\'s security keys in wp-config.php may have changed), so the store answers the phone 401. Generate your developer\'s link with the connection key and open it on the wallet\'s phone, or paste the key again.', 'qersh-for-woocommerce' );
			case 'key_constant_invalid':
				return $prefix . esc_html__( 'The connection key in wp-config.php is not a valid key (at least 24 characters, no spaces), so the store answers the phone 401.', 'qersh-for-woocommerce' );
			case 'device_sms':
				return $prefix . $at . esc_html__( 'The phone has no permission to read messages: no transfer will arrive. Open Qersh → “Prepare the phone”.', 'qersh-for-woocommerce' );
			case 'device_notif':
				return $prefix . $at . esc_html__( 'Qersh cannot reach InstaPay notifications: no transfer will arrive.', 'qersh-for-woocommerce' );
			case 'device_bg':
				return $prefix . $at . esc_html__( 'The system stops Qersh from working in the background: open “Prepare the phone”.', 'qersh-for-woocommerce' );
			case 'silent_suspect':
				/* translators: %s: date and time. */
				return $prefix . sprintf( esc_html__( 'The phone of this wallet is suspected to be offline since %s: orders will not be confirmed on their own until it is back.', 'qersh-for-woocommerce' ), $since );
			case 'silent_down':
				/* translators: %s: date and time. */
				return $prefix . sprintf( esc_html__( 'The phone of this wallet has been offline since %s.', 'qersh-for-woocommerce' ), $since );
			case 'masked':
				return $prefix . esc_html__( 'Transfers of this wallet arrive without the payer\'s full number, so they are not matched automatically. In the Qersh app open the wallet page → “Developer settings” and set “Send sender details” to “Full name and number”.', 'qersh-for-woocommerce' );
			case 'url_changed':
				return $prefix . esc_html__( 'Your store URL has changed since the phone last delivered, so the phone still sends to the old URL. Open your new developer\'s link on the wallet\'s phone (or paste it in “More → Wallets → Add wallet”), which updates the same wallet and keeps the connection key, then tap “Test connection”.', 'qersh-for-woocommerce' );
			case 'newer_key':
				return $prefix . esc_html__( 'Your phone has a newer connection key than the store, and the phone keeps accepting the old one for only 24 hours after the new one was made. Generate your developer\'s link with the connection key and open it on the phone, or tap “Send this wallet\'s link to your developer” on the wallet page on the phone and paste the message here.', 'qersh-for-woocommerce' );
			case 'pending_waiting':
				/* translators: %s: date and time. */
				return $prefix . sprintf( esc_html__( 'A link with the connection key has been waiting since %s. If you opened it on the phone, tap “Test connection”; otherwise cancel it or generate a new link.', 'qersh-for-woocommerce' ), $since );
			case 'pending_stale':
				return $prefix . esc_html__( 'Nothing has arrived with the pending key for 7 days. If you opened the link on the phone, tap “Test connection”; otherwise cancel it or generate a new link.', 'qersh-for-woocommerce' );
		}
		return '';
	}

	/**
	 * The last three digits recorded in qersh_wc_txn_drift, or null when there is no current drift. Accepts
	 * `[ 'tail' => '123', 'version' => '0.1.0' ]`, the same keyed by provider, or `last3` for `tail`; a record made by
	 * another plugin version is ignored (the update brought a new format). Pure apart from the version constant.
	 *
	 * @param mixed $option Option value.
	 * @return string|null
	 */
	public static function drift_tail( $option ): ?string {
		if ( ! is_array( $option ) || empty( $option ) ) {
			return null;
		}
		$record = $option;
		if ( isset( $option['orange_cash'] ) && is_array( $option['orange_cash'] ) ) {
			$record = $option['orange_cash'];
		}
		if ( isset( $record['version'] ) && defined( 'QERSH_WC_VERSION' ) && QERSH_WC_VERSION !== $record['version'] ) {
			return null;
		}
		foreach ( array( 'tail', 'last3' ) as $key ) {
			if ( isset( $record[ $key ] ) && is_scalar( $record[ $key ] ) ) {
				$tail = (string) preg_replace( '/[^0-9A-Za-z]/', '', (string) $record[ $key ] );
				return '' === $tail ? '***' : substr( $tail, -3 );
			}
		}
		return '***';
	}

	/*
	 * ---------------------------------------------------------------------------------------------------------------
	 * Store URL.
	 * ---------------------------------------------------------------------------------------------------------------
	 */

	/**
	 * The webhook URL of a wallet, as shown and put in the developer's links: rest_url() (so plain permalinks give
	 * `index.php?rest_route=…`), with https:// forced except for a private host (docs/17 §4.4 step 1).
	 *
	 * @param string $wallet Wallet id.
	 * @return string
	 */
	public static function store_url( string $wallet ): string {
		return self::force_https( rest_url( Config::ENDPOINT_PATH . '/' . $wallet ) );
	}

	/**
	 * The fallback webhook URL of a wallet (`?wc-api=qersh&qw=<wallet>`).
	 *
	 * @param string $wallet Wallet id.
	 * @return string
	 */
	public static function fallback_url( string $wallet ): string {
		return self::force_https( add_query_arg( array( 'wc-api' => 'qersh', 'qw' => $wallet ), home_url( '/' ) ) );
	}

	/**
	 * https:// for any host the app does not accept plain http for.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function force_https( string $url ): string {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || ! isset( $parts['scheme'] ) || 'http' !== strtolower( $parts['scheme'] ) ) {
			return $url;
		}
		$host = isset( $parts['host'] ) ? (string) $parts['host'] : '';
		return SetupLink::is_local_http_host( $host ) ? $url : 'https' . substr( $url, 4 );
	}

	/**
	 * Host (with a port, lower case) and form of a URL.
	 *
	 * @param string $url URL.
	 * @return array `[ 'host' => string, 'form' => string, 'scheme' => string ]`
	 */
	public static function url_parts( string $url ): array {
		$parts = wp_parse_url( $url );
		$parts = is_array( $parts ) ? $parts : array();
		$host  = isset( $parts['host'] ) ? strtolower( (string) $parts['host'] ) : '';
		if ( '' !== $host && isset( $parts['port'] ) ) {
			$host .= ':' . (int) $parts['port'];
		}
		return array(
			'host'   => $host,
			'form'   => self::form_of_query( isset( $parts['query'] ) ? (string) $parts['query'] : '' ),
			'scheme' => isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : '',
		);
	}

	/**
	 * The URL the phone last delivered to, rebuilt from seen_host and seen_form, or null before any delivery.
	 *
	 * @param string     $wallet Wallet id.
	 * @param array|null $row    qersh_wallets row.
	 * @return string|null
	 */
	public static function seen_url( string $wallet, $row ): ?string {
		if ( ! is_array( $row ) || empty( $row['seen_host'] ) ) {
			return null;
		}
		$host    = (string) $row['seen_host'];
		$current = self::url_parts( self::store_url( $wallet ) );
		$form    = isset( $row['seen_form'] ) ? (string) $row['seen_form'] : 'wp-json';
		$scheme  = SetupLink::is_local_http_host( (string) preg_replace( '/:\d+$/', '', $host ) ) && 'http' === $current['scheme'] ? 'http' : 'https';
		$home    = wp_parse_url( home_url( '/' ) );
		$path    = is_array( $home ) && isset( $home['path'] ) ? rtrim( (string) $home['path'], '/' ) : '';
		$base    = $scheme . '://' . $host . $path;
		if ( 'wc-api' === $form ) {
			return $base . '/?wc-api=qersh&qw=' . rawurlencode( $wallet );
		}
		if ( 'rest_route' === $form ) {
			return $base . '/index.php?rest_route=' . rawurlencode( '/' . Config::ENDPOINT_PATH . '/' . $wallet );
		}
		return $base . '/' . rest_get_url_prefix() . '/' . Config::ENDPOINT_PATH . '/' . $wallet;
	}

	/**
	 * The host this request arrived on, for seen_host (never used for anything but the comparison).
	 *
	 * @return string
	 */
	private static function request_host(): string {
		if ( ! isset( $_SERVER['HTTP_HOST'] ) ) {
			return '';
		}
		$host = strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) );
		$host = (string) preg_replace( '/[^a-z0-9.:\[\]-]/', '', $host );
		return substr( $host, 0, 190 );
	}

	/*
	 * ---------------------------------------------------------------------------------------------------------------
	 * Self-test ("Check your store URL"): only on the administrator's click.
	 * ---------------------------------------------------------------------------------------------------------------
	 */

	/**
	 * Sends unsigned test requests to the store's own addresses and names what blocks them (docs/17 §4.9):
	 * POST `{}` with a deliberately invalid signature and no cookies to the address the phone last used and to
	 * rest_url() now (expected: 401 JSON qersh_invalid_signature), the fallback address when those fail, and a POST
	 * with a wrong order key to the transaction-id route (expected: 404 JSON qersh_not_found), with its fallback when
	 * blocked. No order or customer data is sent.
	 *
	 * @param string $wallet Wallet id.
	 * @return array `[ 'wallet', 'at', 'probes' => [ [ 'name', 'url', 'code', 'verdict', 'detail' ] ] ]`
	 */
	public function self_test( string $wallet ): array {
		$now    = time();
		$result = array(
			'wallet' => $wallet,
			'at'     => $now,
			'probes' => array(),
		);
		if ( ! $this->plugin instanceof Plugin || null === $this->plugin->wallet( $wallet ) ) {
			return $result;
		}
		$row = null;
		try {
			$row = $this->plugin->wallets()->get( $wallet );
		} catch ( \Throwable $e ) {
			$row = null;
		}
		$current = self::store_url( $wallet );
		$steps   = array( array( 'current', $current, 'webhook', '' ) );
		$seen    = self::seen_url( $wallet, $row );
		if ( null !== $seen && self::url_key( $seen ) !== self::url_key( $current ) ) {
			$steps[] = array( 'saved', $seen, 'webhook', '' );
		}
		$steps[] = array( 'fallback', self::fallback_url( $wallet ), 'webhook', 'current' );
		$steps[] = array( 'txn', self::force_https( rest_url( 'qersh/v1/order-txn' ) ), 'txn', '' );
		$steps[] = array( 'txn_fallback', self::force_https( add_query_arg( array( 'wc-api' => 'qersh', 'qo' => 'txn' ), home_url( '/' ) ) ), 'txn', 'txn' );

		$result['probes'] = self::run_probes(
			$steps,
			function ( $name, $url, $expect ) use ( $now ) {
				return $this->probe( $name, $url, $expect, $now );
			}
		);
		return $result;
	}

	/**
	 * Runs the self-test's probes in order (docs/17 §4.9) within one admin request. A step with a fourth item runs
	 * only when the probe it names ran and did not answer as expected (the fallback addresses). Once a probe of a
	 * host is `inconclusive` (the server cannot reach its own address, usually a timeout), the later probes of that
	 * host are not sent and are reported with the verdict `skipped`, so a dropped loopback costs one timeout, not
	 * five. Pure apart from `$send`.
	 *
	 * @param array[]  $steps Each `[ name, url, expect, only_when_failed ]`, only_when_failed '' or a probe name.
	 * @param callable $send  function ( string $name, string $url, string $expect ): array, one probe().
	 * @return array[] The probes, in the shape of probe().
	 */
	public static function run_probes( array $steps, callable $send ): array {
		$out     = array();
		$verdict = array();
		$dead    = array();
		foreach ( $steps as $step ) {
			list( $name, $url, $expect, $after ) = array_pad( array_values( $step ), 4, '' );
			$name = (string) $name;
			$url  = (string) $url;
			if ( '' !== (string) $after && ( ! isset( $verdict[ $after ] ) || 'ok' === $verdict[ $after ] || 'skipped' === $verdict[ $after ] ) ) {
				continue;
			}
			$host = self::url_host( $url );
			if ( isset( $dead[ $host ] ) ) {
				$probe = array(
					'name'    => $name,
					'url'     => $url,
					'code'    => 0,
					'verdict' => 'skipped',
					'detail'  => '',
				);
			} else {
				$probe = $send( $name, $url, (string) $expect );
				if ( isset( $probe['verdict'] ) && 'inconclusive' === $probe['verdict'] ) {
					$dead[ $host ] = true;
				}
			}
			$verdict[ $name ] = isset( $probe['verdict'] ) ? (string) $probe['verdict'] : '';
			$out[]            = $probe;
		}
		return $out;
	}

	/**
	 * The host of a URL, lower case, for run_probes(). Pure.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function url_host( string $url ): string {
		return 1 === preg_match( '~^[a-z][a-z0-9+.-]*://([^/?#]+)~i', $url, $m ) ? strtolower( $m[1] ) : '';
	}

	/**
	 * A URL compared without its scheme's default port and trailing slash.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function url_key( string $url ): string {
		return rtrim( (string) preg_replace( '#^(https?://[^/:?]+):(80|443)#i', '$1', strtolower( $url ) ), '/' );
	}

	/**
	 * One test request.
	 *
	 * @param string $name   Probe name.
	 * @param string $url    URL (the store's own).
	 * @param string $expect `webhook` or `txn`.
	 * @param int    $now    UTC seconds.
	 * @return array
	 */
	private function probe( string $name, string $url, string $expect, int $now ): array {
		$body     = 'txn' === $expect ? '{"order_id":0,"key":"wc_order_qersh_self_test","txn":""}' : '{}';
		$response = wp_remote_post(
			$url,
			array(
				'timeout'     => self::PROBE_TIMEOUT,
				'redirection' => 0,
				'httpversion' => '1.1',
				'cookies'     => array(),
				'user-agent'  => 'Qersh-for-WooCommerce/' . ( defined( 'QERSH_WC_VERSION' ) ? QERSH_WC_VERSION : '0' ) . ' self-test',
				'headers'     => array(
					'Content-Type'      => 'application/json; charset=utf-8',
					'Accept'            => 'application/json',
					'X-Qersh-Signature' => 't=' . $now . ',v1=' . str_repeat( '0', 64 ),
					'X-Qersh-Delivery'  => '00000000-0000-4000-8000-000000000000',
				),
				'body'        => $body,
			)
		);
		if ( is_wp_error( $response ) ) {
			$resp = array(
				'error'   => $response->get_error_message(),
				'code'    => 0,
				'headers' => array(),
				'body'    => '',
			);
		} else {
			$headers = array();
			foreach ( (array) wp_remote_retrieve_headers( $response ) as $key => $value ) {
				$headers[ strtolower( (string) $key ) ] = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
			}
			if ( empty( $headers ) ) {
				$raw = wp_remote_retrieve_headers( $response );
				if ( is_object( $raw ) && method_exists( $raw, 'getAll' ) ) {
					foreach ( $raw->getAll() as $key => $value ) {
						$headers[ strtolower( (string) $key ) ] = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
					}
				}
			}
			$resp = array(
				'error'   => null,
				'code'    => (int) wp_remote_retrieve_response_code( $response ),
				'headers' => $headers,
				'body'    => substr( (string) wp_remote_retrieve_body( $response ), 0, 8192 ),
			);
		}
		$verdict = self::classify( $resp, $expect );
		return array(
			'name'    => $name,
			'url'     => $url,
			'code'    => $resp['code'],
			'verdict' => $verdict['verdict'],
			'detail'  => $verdict['detail'],
		);
	}

	/**
	 * Names what answered a test request (docs/17 §4.9, docs/16 §7.3). Pure.
	 *
	 * @param array  $resp   `[ 'error' => ?string, 'code' => int, 'headers' => [ lower-case name => string ], 'body' => string ]`.
	 * @param string $expect `webhook` (401 qersh_invalid_signature) or `txn` (404 qersh_not_found).
	 * @return array `[ 'verdict' => string, 'detail' => string ]` with verdict `ok`, `tls`, `inconclusive`,
	 *               `cloudflare`, `redirect` (detail: Location), `blocked` (detail: the blocker's name or ''),
	 *               `maintenance`, `unavailable`, `route_missing`, `html`, `other_wallet` or `unexpected`.
	 */
	public static function classify( array $resp, string $expect ): array {
		$error   = isset( $resp['error'] ) && is_string( $resp['error'] ) ? $resp['error'] : '';
		$code    = isset( $resp['code'] ) ? (int) $resp['code'] : 0;
		$headers = isset( $resp['headers'] ) && is_array( $resp['headers'] ) ? array_change_key_case( $resp['headers'], CASE_LOWER ) : array();
		$body    = isset( $resp['body'] ) && is_string( $resp['body'] ) ? $resp['body'] : '';
		$out     = function ( $verdict, $detail = '' ) {
			return array(
				'verdict' => $verdict,
				'detail'  => (string) $detail,
			);
		};
		if ( '' !== $error || $code <= 0 ) {
			if ( 1 === preg_match( '/ssl|certificate|tls/i', $error ) ) {
				return $out( 'tls', self::short( $error ) );
			}
			return $out( 'inconclusive', self::short( $error ) );
		}
		$type = isset( $headers['content-type'] ) ? strtolower( (string) $headers['content-type'] ) : '';
		$json = null;
		if ( false !== strpos( $type, 'json' ) || 1 === preg_match( '/^\s*\{/', $body ) ) {
			$decoded = json_decode( $body, true );
			$json    = is_array( $decoded ) ? $decoded : null;
		}
		$json_code = is_array( $json ) && isset( $json['code'] ) && is_string( $json['code'] ) ? $json['code'] : '';

		if ( 'webhook' === $expect && 401 === $code && 'qersh_invalid_signature' === $json_code ) {
			return $out( 'ok' );
		}
		if ( 'txn' === $expect && 404 === $code && 'qersh_not_found' === $json_code ) {
			return $out( 'ok' );
		}
		if ( isset( $headers['cf-mitigated'] ) && '' !== trim( (string) $headers['cf-mitigated'] ) ) {
			return $out( 'cloudflare', trim( (string) $headers['cf-mitigated'] ) );
		}
		if ( $code >= 300 && $code < 400 ) {
			return $out( 'redirect', isset( $headers['location'] ) ? self::short( (string) $headers['location'] ) : '' );
		}
		if ( 'qersh_unavailable' === $json_code ) {
			return $out( 'unavailable' );
		}
		if ( in_array( $json_code, array( 'qersh_unknown_wallet', 'qersh_wallet_deleted', 'qersh_wallet_gone' ), true ) ) {
			return $out( 'other_wallet', $json_code );
		}
		if ( 503 === $code && ( null === $json || 1 === preg_match( '/maintenance|briefly unavailable/i', $body ) ) ) {
			return $out( 'maintenance' );
		}
		if ( 404 === $code && 'rest_no_route' === $json_code ) {
			return $out( 'route_missing' );
		}
		$haystack = $body . "\n" . implode( "\n", array_map( 'strval', array_keys( $headers ) ) ) . "\n" . implode( "\n", array_map( 'strval', $headers ) );
		if ( in_array( $code, array( 401, 403, 406, 429, 444, 451, 418 ), true ) ) {
			if ( isset( $headers['server'] ) && false !== stripos( (string) $headers['server'], 'cloudflare' ) && null === $json ) {
				return $out( 'cloudflare', 'block' );
			}
			foreach ( self::BLOCKERS as $pattern => $name ) {
				if ( 1 === preg_match( $pattern, $haystack ) ) {
					return $out( 'blocked', $name );
				}
			}
			if ( isset( $headers['x-sucuri-id'] ) || isset( $headers['x-sucuri-block'] ) ) {
				return $out( 'blocked', 'Sucuri' );
			}
			return $out( 'blocked', '' );
		}
		if ( ( 200 === $code || 404 === $code ) && null === $json ) {
			return $out( 'html' );
		}
		return $out( 'unexpected', (string) $code );
	}

	/**
	 * A detail cut for display.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function short( string $text ): string {
		$text = trim( (string) preg_replace( '/\s+/', ' ', $text ) );
		return function_exists( 'mb_substr' ) ? (string) mb_substr( $text, 0, 200, 'UTF-8' ) : substr( $text, 0, 200 );
	}

	/*
	 * ---------------------------------------------------------------------------------------------------------------
	 * Cron and Action Scheduler (read from the database, never a request).
	 * ---------------------------------------------------------------------------------------------------------------
	 */

	/**
	 * Recomputes the qersh_wc_as_ok flag (docs/17 §4.7): no pending action of the groups `woocommerce-emails` and
	 * `qersh` overdue by more than 10 minutes. At most once a minute; called at the end of webhook requests, when our
	 * pages open, and by the sweep.
	 */
	public function as_health(): void {
		try {
			if ( ! function_exists( 'as_get_scheduled_actions' ) || ! did_action( 'action_scheduler_init' ) ) {
				return;
			}
			$now    = time();
			$stored = get_option( self::AS_OPTION, null );
			if ( is_array( $stored ) && isset( $stored['at'] ) && (int) $stored['at'] > $now - self::AS_CHECK_SEC ) {
				return;
			}
			$late = 0;
			foreach ( array( 'woocommerce-emails', 'qersh' ) as $group ) {
				$ids = as_get_scheduled_actions(
					array(
						'group'        => $group,
						'status'       => 'pending',
						'date'         => gmdate( 'Y-m-d H:i:s', $now - self::AS_LATE_SEC ),
						'date_compare' => '<=',
						'per_page'     => 5,
					),
					'ids'
				);
				$late += is_array( $ids ) ? count( $ids ) : 0;
			}
			update_option(
				self::AS_OPTION,
				array(
					'ok'   => 0 === $late,
					'at'   => $now,
					'late' => $late,
				),
				false
			);
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * The flag: true until a check found overdue actions.
	 *
	 * @return bool
	 */
	public function as_ok(): bool {
		$stored = get_option( self::AS_OPTION, null );
		return ! is_array( $stored ) || ! isset( $stored['ok'] ) || (bool) $stored['ok'];
	}

	/**
	 * Our tables that are not InnoDB (cached for twelve hours).
	 *
	 * @return string[]
	 */
	private function non_innodb_tables(): array {
		$cached = get_transient( self::ENGINE_TRANSIENT );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		try {
			$list = Schema::non_innodb_tables();
		} catch ( \Throwable $e ) {
			$list = array();
		}
		set_transient( self::ENGINE_TRANSIENT, $list, 12 * HOUR_IN_SECONDS );
		return $list;
	}

	/*
	 * ---------------------------------------------------------------------------------------------------------------
	 * Periodic work.
	 * ---------------------------------------------------------------------------------------------------------------
	 */

	/**
	 * Every 15 minutes (qersh_wc_sweep): the Action Scheduler flag, and the "phone silent" episodes, with one email
	 * to the administrator per episode.
	 */
	public function sweep(): void {
		$this->as_health();
		$this->check_silence();
	}

	/**
	 * Once a day (qersh_wc_daily): cancels a pending key link the phone proved it does not have (docs/17 §4.8 rule 9).
	 */
	public function daily(): void {
		if ( ! $this->plugin instanceof Plugin ) {
			return;
		}
		try {
			$now  = time();
			$rows = $this->plugin->tables_ready() ? $this->plugin->wallets()->all() : array();
			foreach ( $this->wallet_ids() as $wallet ) {
				if ( $this->plugin->secrets()->from_constant( $wallet ) ) {
					continue;
				}
				$fp = $this->plugin->secrets()->fingerprint( $wallet );
				if ( self::should_cancel_pending( $fp, isset( $rows[ $wallet ] ) ? $rows[ $wallet ] : null, $now ) ) {
					$this->plugin->secrets()->cancel_pending( $wallet );
					$this->plugin->logger()->info( 'Qersh cancelled a pending connection key link of wallet ' . $wallet . ': the phone kept delivering with the current key for 7 days and never used it.' );
				}
			}
		} catch ( \Throwable $e ) {
			$this->plugin->logger()->warning( 'Qersh could not check the pending connection keys.', array( 'error' => get_class( $e ) ) );
		}
	}

	/**
	 * Finds "phone silent" episodes and sends the administrator one email for each (the UPDATE that sets
	 * silent_notified is the claim, so two checks never both send).
	 */
	public function check_silence(): void {
		if ( ! $this->plugin instanceof Plugin || ! $this->plugin->tables_ready() ) {
			return;
		}
		try {
			$now  = time();
			$rows = $this->plugin->wallets()->all();
			$db   = $this->plugin->db();
			foreach ( $this->wallet_ids() as $wallet ) {
				$row = isset( $rows[ $wallet ] ) ? $rows[ $wallet ] : null;
				if ( null === $row || 'none' === self::silent_state( $row, $now, true )['state'] ) {
					continue;
				}
				$waiting = $this->orders_waiting( $wallet, $now );
				$state   = self::silent_state( $row, $now, $waiting > 0 );
				if ( 'none' === $state['state'] || ! empty( $row['silent_notified'] ) ) {
					continue;
				}
				$claimed = $db->exec(
					'UPDATE %i SET silent_since = %d, silent_notified = 1 WHERE wallet = %s AND silent_notified = 0',
					array( $db->table( 'wallets' ), (int) $state['since'], $wallet )
				);
				if ( 1 !== $claimed ) {
					continue;
				}
				do_action(
					'qersh_wc_admin_alert',
					'phone_silent',
					array(
						'wallet' => $wallet,
						'since'  => (int) $state['since'],
						'orders' => $waiting,
					)
				);
			}
		} catch ( \Throwable $e ) {
			$this->plugin->logger()->warning( 'Qersh could not check whether the wallet phones are silent.', array( 'error' => get_class( $e ) ) );
		}
	}

	/**
	 * Orders that wait for a transfer to a wallet: rows `open` or `partial` whose deadline plus the late window has
	 * not passed.
	 *
	 * @param string $wallet Wallet id.
	 * @param int    $now    UTC seconds.
	 * @return int
	 */
	public function orders_waiting( string $wallet, int $now ): int {
		if ( ! $this->plugin instanceof Plugin ) {
			return 0;
		}
		$config = $this->plugin->config();
		$late   = isset( $config['receiver']['matching']['late_window_min'] ) ? (int) $config['receiver']['matching']['late_window_min'] : 120;
		try {
			$rows = $this->plugin->orders_index()->for_wallet( $wallet, $now - $late * 60, $now );
		} catch ( \Throwable $e ) {
			return 0;
		}
		$count = 0;
		foreach ( $rows as $row ) {
			if ( isset( $row['state'] ) && in_array( $row['state'], array( 'open', 'partial' ), true ) ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Orange Cash transfers that a competing unpaid order of the same amount kept from being confirmed on their own
	 * today, Cairo time (docs/17 §4.9): verdict or evaluation `txn_contested`.
	 *
	 * @return int
	 */
	public function competition_today(): int {
		if ( ! $this->plugin instanceof Plugin || ! $this->plugin->tables_ready() ) {
			return 0;
		}
		try {
			$start = ( new \DateTimeImmutable( 'today', new \DateTimeZone( View::TIME_ZONE ) ) )->getTimestamp();
			$db    = $this->plugin->db();
			return (int) $db->var(
				"SELECT COUNT(*) FROM %i WHERE provider = %s AND ( reason = 'txn_contested' OR claim_eval = 'txn_contested' ) AND updated_at >= %d",
				array( $db->table( 'transfers' ), 'orange_cash', $start )
			);
		} catch ( \Throwable $e ) {
			return 0;
		}
	}

	/**
	 * Ids of the configured wallets.
	 *
	 * @return string[]
	 */
	private function wallet_ids(): array {
		$out    = array();
		$config = $this->plugin instanceof Plugin ? $this->plugin->config() : array();
		foreach ( isset( $config['wallets'] ) && is_array( $config['wallets'] ) ? $config['wallets'] : array() as $wallet ) {
			if ( is_array( $wallet ) && isset( $wallet['id'] ) && Config::wallet_id_ok( $wallet['id'] ) ) {
				$out[] = (string) $wallet['id'];
			}
		}
		return $out;
	}

	/**
	 * Whether a wallet of this type is configured.
	 *
	 * @param string $provider Wallet type.
	 * @return bool
	 */
	private function has_provider( string $provider ): bool {
		$config = $this->plugin instanceof Plugin ? $this->plugin->config() : array();
		foreach ( isset( $config['wallets'] ) && is_array( $config['wallets'] ) ? $config['wallets'] : array() as $wallet ) {
			if ( is_array( $wallet ) && isset( $wallet['provider'] ) && $provider === $wallet['provider'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether the customer may enter a transaction id for this wallet type now (the setting, a claim provider, and
	 * no format drift).
	 *
	 * @param string $provider Wallet type.
	 * @return bool
	 */
	public function txn_entry_open( string $provider ): bool {
		if ( ! $this->plugin instanceof Plugin || ! in_array( $provider, TxnId::CLAIM_PROVIDERS, true ) ) {
			return false;
		}
		$config = $this->plugin->config();
		if ( empty( $config['store']['txn_entry'] ) ) {
			return false;
		}
		return null === self::drift_tail( get_option( self::DRIFT_OPTION, null ) );
	}
}
