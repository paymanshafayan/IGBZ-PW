<?php
namespace IGBZ\Suite\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Installation, incremental upgrades and role/capability setup.
 *
 * Unlike the nopCommerce original (three migrations all stamped 2025/01/01 and marked
 * Installation-only) this uses a numeric IGBZ_DB_VERSION so upgrades really do run.
 */
final class Activator {

	public const VERSION_OPTION = 'igbz_db_version';

	public static function activate(): void {
		self::install_tables();
		self::add_roles();
		self::seed_defaults();
		update_option( self::VERSION_OPTION, IGBZ_DB_VERSION, true );
		if ( false === get_option( Modules::OPTION, false ) ) {
			Modules::save( Modules::defaults() );
		}
		self::schedule_events();
		flush_rewrite_rules();
	}

	public static function deactivate(): void {
		foreach ( array_keys( Cron::events() ) as $hook ) {
			$timestamp = wp_next_scheduled( $hook );
			while ( $timestamp ) {
				wp_unschedule_event( $timestamp, $hook );
				$timestamp = wp_next_scheduled( $hook );
			}
		}
		flush_rewrite_rules();
	}

	public static function maybe_upgrade(): void {
		$current = (int) get_option( self::VERSION_OPTION, 0 );
		if ( $current === IGBZ_DB_VERSION ) {
			return;
		}
		self::install_tables();
		self::add_roles();
		self::seed_defaults();
		self::schedule_events();
		update_option( self::VERSION_OPTION, IGBZ_DB_VERSION, true );
	}

	public static function install_tables(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		foreach ( Schema::statements() as $statement ) {
			dbDelta( $statement );
		}
	}

	/**
	 * Whether translations may safely be requested yet.
	 *
	 * maybe_upgrade() runs on `plugins_loaded`, i.e. before `init`. Calling __() there forces a
	 * just-in-time textdomain load, which WordPress 6.7+ reports as a
	 * `_load_textdomain_just_in_time` doing-it-wrong notice. Both the role labels and the seeded
	 * defaults below are *persisted* values, so the English original is the correct thing to store
	 * anyway — WordPress itself stores role names untranslated. When this runs later than `init`
	 * (the real activation request does) the translated string is used.
	 */
	private static function can_translate(): bool {
		return did_action( 'init' ) > 0;
	}

	public static function add_roles(): void {
		$caps = Capabilities::all();
		$t    = self::can_translate();

		add_role(
			Capabilities::ROLE_TENANT_OWNER,
			$t ? __( 'IGBZ Tenant Owner', 'igbz-suite' ) : 'IGBZ Tenant Owner',
			array_merge(
				[ 'read' => true, 'upload_files' => true ],
				array_fill_keys(
					[
						Capabilities::MANAGE_OWN_TENANT,
						Capabilities::MANAGE_WALLET,
						Capabilities::MANAGE_INSTAGRAM,
						Capabilities::MANAGE_LMS,
						Capabilities::MANAGE_AFFILIATE,
						Capabilities::MANAGE_BNPL,
					],
					true
				)
			)
		);

		add_role(
			Capabilities::ROLE_TENANT_STAFF,
			$t ? __( 'IGBZ Tenant Staff', 'igbz-suite' ) : 'IGBZ Tenant Staff',
			[ 'read' => true, 'upload_files' => true, Capabilities::MANAGE_OWN_TENANT => true ]
		);

		add_role(
			Capabilities::ROLE_INSTRUCTOR,
			$t ? __( 'IGBZ Instructor', 'igbz-suite' ) : 'IGBZ Instructor',
			[ 'read' => true, 'upload_files' => true, Capabilities::MANAGE_LMS => true ]
		);

		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( $caps as $cap ) {
				$admin->add_cap( $cap );
			}
		}
	}

	public static function seed_defaults(): void {
		$settings = new Settings();
		$t        = self::can_translate();
		$defaults = [
			'general.default_currency'      => 'IRT',
			'general.tenant_resolution'     => 'domain',
			'general.tenant_path_base'      => 'store',
			'general.default_tenant_id'     => 0,
			'general.allow_self_signup'     => true,
			'general.auto_approve_tenants'  => false,
			'log.level'                     => Logger::INFO,
			'log.retention_days'            => 30,
			'http.timeout'                  => 20,
			'purge_on_uninstall'            => false,
			'wallet.enabled'                => true,
			'wallet.allow_negative'         => false,
			'wallet.order_cashback_percent' => 2.0,
			'wallet.max_topup'              => 50000000,
			'wallet.min_topup'              => 10000,
			'wallet.topup_enabled'          => true,
			'wallet.checkout_enabled'       => true,
			'bnpl.enabled'                  => true,
			'bnpl.default_installments'     => 4,
			'bnpl.interval_days'            => 30,
			'bnpl.fee_percent'              => 0.0,
			'bnpl.penalty_percent_per_day'  => 0.1,
			'bnpl.min_order_total'          => 500000,
			'bnpl.default_credit_limit'     => 20000000,
			'bnpl.provider'                 => 'internal',
			'bnpl.auto_collect'             => true,
			'bnpl.reminder_days_before'     => 3,
			'bnpl.default_after_days'       => 14,
			'affiliate.enabled'             => true,
			'affiliate.tier1_rate'          => 5.0,
			'affiliate.tier2_rate'          => 2.0,
			'affiliate.cookie_days'         => 30,
			'affiliate.approve_after_days'  => 7,
			'affiliate.min_payout'          => 1000000,
			'affiliate.payout_to_wallet'    => true,
			'lms.enabled'                   => true,
			'lms.video_link_ttl'            => 7200,
			'lms.max_quiz_attempts'         => 3,
			'lms.course_page_id'            => 0,
			'lms.pass_score'                => 60,
			'lms.certificate_enabled'       => true,
			'otp.enabled'                   => true,
			'otp.code_length'               => 6,
			'otp.ttl_seconds'               => 300,
			'otp.max_attempts'              => 5,
			'otp.resend_seconds'            => 120,
			'otp.max_per_hour'              => 5,
			'otp.sms_provider'              => 'log',
			'otp.message_template'          => $t ? __( 'Your verification code: {code}', 'igbz-suite' ) : 'Your verification code: {code}',
			'otp.kavenegar.template'        => '',
			'otp.kavenegar.sender'          => '',
			'otp.smsir.template_id'         => 0,
			'plans.enabled'                 => true,
			'plans.grace_days'              => 3,
			'plans.renewal_retries'         => 3,
			'plans.notify_days_before'      => 5,
			'plans.six_month_discount'      => 10.0,
			'plans.yearly_discount'         => 20.0,
			'payments.default_gateway'      => 'zarinpal',
			'payments.zarinpal.enabled'     => true,
			'payments.idpay.enabled'        => false,
			'payments.nextpay.enabled'      => false,
			'payments.payir.enabled'        => false,
			'payments.payir.sandbox'        => false,
			'payments.currency_multiplier'  => 10,
			'payments.zarinpal.sandbox'     => false,
			'payments.idpay.sandbox'        => false,
			'marketplace.enabled'           => true,
			'marketplace.torob.enabled'     => true,
			'marketplace.emalls.enabled'    => true,
			'marketplace.google.enabled'    => false,
			'marketplace.feed_limit'        => 500,
			'marketplace.cache_ttl'         => 900,
			'instagram.provider'            => 'manus',
			'instagram.autopublish'         => true,
			'instagram.unique_coupons'      => true,
			'instagram.coupon_ttl_days'     => 7,
			'manus.agent_profile'           => 'manus-1.6',
			'manus.locale'                  => 'fa-IR',
			'manus.content_language'        => 'Persian (Farsi)',
			'manus.poll_interval'           => 300,
			'manus.use_canva'               => true,
			'manus.auto_generate'           => true,
			'manus.auto_schedule'           => true,
			'manus.collect_insights'        => true,
			'manus.default_peak_hours'      => '12:00,18:30,21:00',
			'manus.min_gap_minutes'         => 90,
			'manus.reel_seconds'            => 25,
			'manus.weekly_slots'            => 5,
			'manychat.async_reply'          => true,
			'manychat.link_field_name'      => 'igbz_link',
			'manychat.coupon_field_name'    => 'igbz_coupon',
			'manychat.button_label'         => $t ? __( 'Open the link', 'igbz-suite' ) : 'Open the link',
			'manychat.duplicate_message'    => $t ? __( 'You have already received this link.', 'igbz-suite' ) : 'You have already received this link.',
			'hub.enabled'                   => true,
			'hub.vip_link_ttl'              => 900,
			'hub.sync_interval'             => 3600,
			'hub.featured_limit'            => 12,
			'hub.subdomain_base'            => '',
			'hub.cname_target'              => '',
			'hub.mother_origin'             => '',
			'api.jwt_ttl'                   => 3600,
			'api.refresh_ttl'               => 2592000,
			'api.rate_limit_per_minute'     => 120,
			'api.push_enabled'              => false,
			'api.push_channel_id'           => 'igbz_default',
			'api.push_order_updates'        => true,
			'api.device_retention_days'     => 180,
			'api.app_scheme'                => 'igbz',
			'api.min_app_version'           => '',
			'api.latest_app_version'        => '',
			'api.apk_url'                   => '',
			'api.ios_store_url'             => '',
			'api.android_package'           => '',
			'api.ios_bundle_id'             => '',
			'api.universal_link'            => '',
		];
		foreach ( $defaults as $key => $value ) {
			if ( ! $settings->has( $key ) ) {
				$settings->set( $key, $value );
			}
		}

		// Secrets that must exist for signed URLs / tokens are generated once, never hardcoded.
		$generated = [
			'api.jwt_secret',
			'lms.video_hmac_secret',
			'hub.vip_link_secret',
			'manychat.webhook_token',
			'manus.webhook_token',
		];
		foreach ( $generated as $key ) {
			if ( ! $settings->has( $key ) ) {
				$settings->set( $key, Crypto::token( 32 ) );
			}
		}
	}

	public static function schedule_events(): void {
		// Guarantee the custom recurrences are known even if this runs before the plugin
		// bootstrap did it (e.g. a direct call from an upgrade routine or WP-CLI).
		Cron::register_schedules();

		foreach ( Cron::events() as $hook => $recurrence ) {
			if ( ! wp_next_scheduled( $hook ) ) {
				wp_schedule_event( time() + 60, $recurrence, $hook );
			}
		}
	}
}
