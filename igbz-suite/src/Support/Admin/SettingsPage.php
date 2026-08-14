<?php
namespace IGBZ\Suite\Support\Admin;

use IGBZ\Suite\Support\Capabilities;
use IGBZ\Suite\Support\Crypto;
use IGBZ\Suite\Support\Logger;
use IGBZ\Suite\Support\Modules;
use IGBZ\Suite\Support\Settings;
use IGBZ\Suite\Modules\Instagram\Webhooks\ManyChatWebhook;

defined( 'ABSPATH' ) || exit;

/**
 * One tabbed settings screen for the whole suite.
 *
 * Every business constant that was hardcoded in the nopCommerce plugins (cashback percent,
 * commission rates, BNPL instalment count, OTP TTL, ...) is exposed here.
 */
final class SettingsPage {

	public const SLUG = 'igbz-settings';

	private Settings $settings;

	public function __construct( ?Settings $settings = null ) {
		$this->settings = $settings ?? igbz()->settings();
	}

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_page' ], 9 );
	}

	public function add_page(): void {
		Menu::add( self::SLUG, __( 'Settings', 'igbz-suite' ), [ $this, 'render' ], Capabilities::MANAGE_SUITE );
	}

	// ------------------------------------------------------------------ tabs

	/** @return array<string,string> */
	public function tabs(): array {
		$tabs = [
			'general' => __( 'General', 'igbz-suite' ),
			'modules' => __( 'Modules', 'igbz-suite' ),
		];

		if ( Modules::enabled( Modules::MULTITENANT ) ) {
			$tabs['wallet']      = __( 'Wallet', 'igbz-suite' );
			$tabs['plans']       = __( 'Plans', 'igbz-suite' );
			$tabs['bnpl']        = __( 'BNPL', 'igbz-suite' );
			$tabs['affiliate']   = __( 'Affiliate', 'igbz-suite' );
			$tabs['lms']         = __( 'LMS', 'igbz-suite' );
			$tabs['payments']    = __( 'Payments', 'igbz-suite' );
			$tabs['otp']         = __( 'OTP', 'igbz-suite' );
			$tabs['marketplace'] = __( 'Marketplace', 'igbz-suite' );
		}
		if ( Modules::enabled( Modules::INSTAGRAM ) ) {
			$tabs['manus']    = __( 'Manus', 'igbz-suite' );
			$tabs['manychat'] = __( 'ManyChat', 'igbz-suite' );
			$tabs['intake']   = __( 'Product registration', 'igbz-suite' );
		}
		if ( Modules::enabled( Modules::HUB ) ) {
			$tabs['hub'] = __( 'Hub', 'igbz-suite' );
		}
		if ( Modules::enabled( Modules::REST_API ) ) {
			$tabs['api'] = __( 'Mobile API', 'igbz-suite' );
		}

		$tabs['advanced'] = __( 'Advanced', 'igbz-suite' );

		return $tabs;
	}

	/**
	 * Field definitions per tab.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function fields( string $tab ): array {
		switch ( $tab ) {
			case 'general':
				return [
					[
						'key'     => 'general.default_currency',
						'label'   => __( 'Default currency code', 'igbz-suite' ),
						'help'    => __( 'Used for wallet balances and BNPL amounts, e.g. IRT.', 'igbz-suite' ),
					],
					[
						'key'     => 'general.tenant_resolution',
						'label'   => __( 'Tenant resolution', 'igbz-suite' ),
						'type'    => 'select',
						'options' => [
							'domain' => __( 'By domain / subdomain', 'igbz-suite' ),
							'path'   => __( 'By URL path prefix', 'igbz-suite' ),
							'query'  => __( 'By ?tenant= query argument', 'igbz-suite' ),
						],
						'help'    => __( 'How the storefront decides which tenant store the visitor is on.', 'igbz-suite' ),
					],
					[
						'key'   => 'general.tenant_path_base',
						'label' => __( 'Path prefix base', 'igbz-suite' ),
						'help'  => __( 'Only used with path resolution, e.g. "store" gives /store/{slug}/.', 'igbz-suite' ),
					],
					[
						'key'     => 'general.allow_self_signup',
						'label'   => __( 'Allow tenant self sign-up', 'igbz-suite' ),
						'type'    => 'checkbox',
						'help'    => __( 'Create a new WordPress user and tenant from the sign-up shortcode.', 'igbz-suite' ),
					],
					[
						'key'     => 'general.auto_approve_tenants',
						'label'   => __( 'Auto approve new tenants', 'igbz-suite' ),
						'type'    => 'checkbox',
					],
					[
						'key'   => 'general.default_tenant_id',
						'label' => __( 'Fallback tenant id', 'igbz-suite' ),
						'type'  => 'number',
						'max'   => 99999999,
						'help'  => __( 'Used when a request cannot be resolved to a store. 0 means no fallback.', 'igbz-suite' ),
					],
				];

			case 'wallet':
				return [
					[ 'key' => 'wallet.enabled', 'label' => __( 'Enable wallet', 'igbz-suite' ), 'type' => 'checkbox' ],
					[ 'key' => 'wallet.allow_negative', 'label' => __( 'Allow negative balance', 'igbz-suite' ), 'type' => 'checkbox', 'help' => __( 'Leave off: debits are serialised with a row lock and refused when funds are short.', 'igbz-suite' ) ],
					[ 'key' => 'wallet.order_cashback_percent', 'label' => __( 'Order cashback %', 'igbz-suite' ), 'type' => 'number', 'step' => '0.01', 'max' => 100 ],
					[ 'key' => 'wallet.max_topup', 'label' => __( 'Maximum single top-up', 'igbz-suite' ), 'type' => 'number', 'max' => 999999999 ],
					[ 'key' => 'wallet.min_topup', 'label' => __( 'Minimum single top-up', 'igbz-suite' ), 'type' => 'number', 'max' => 999999999 ],
					[ 'key' => 'wallet.checkout_enabled', 'label' => __( 'Offer wallet at checkout', 'igbz-suite' ), 'type' => 'checkbox' ],
					[ 'key' => 'wallet.topup_enabled', 'label' => __( 'Allow customers to top up', 'igbz-suite' ), 'type' => 'checkbox' ],
				];

			case 'plans':
				return [
					[ 'key' => 'plans.enabled', 'label' => __( 'Enable subscription plans', 'igbz-suite' ), 'type' => 'checkbox' ],
					[ 'key' => 'plans.grace_days', 'label' => __( 'Grace period after expiry (days)', 'igbz-suite' ), 'type' => 'number', 'max' => 365 ],
					[ 'key' => 'plans.renewal_retries', 'label' => __( 'Renewal retries before suspending', 'igbz-suite' ), 'type' => 'number', 'max' => 20 ],
					[ 'key' => 'plans.notify_days_before', 'label' => __( 'Notify N days before renewal', 'igbz-suite' ), 'type' => 'number', 'max' => 90 ],
					[ 'key' => 'plans.six_month_discount', 'label' => __( 'Six month discount %', 'igbz-suite' ), 'type' => 'number', 'step' => '0.01', 'max' => 100 ],
					[ 'key' => 'plans.yearly_discount', 'label' => __( 'Yearly discount %', 'igbz-suite' ), 'type' => 'number', 'step' => '0.01', 'max' => 100 ],
				];

			case 'bnpl':
				return [
					[ 'key' => 'bnpl.enabled', 'label' => __( 'Enable BNPL', 'igbz-suite' ), 'type' => 'checkbox' ],
					[
						'key'     => 'bnpl.provider',
						'label'   => __( 'Provider', 'igbz-suite' ),
						'type'    => 'select',
						'options' => [
							'internal'  => __( 'Internal instalments (own credit book)', 'igbz-suite' ),
							'snapppay'  => 'SnappPay',
							'tara'      => 'Tara',
						],
					],
					[ 'key' => 'bnpl.default_installments', 'label' => __( 'Default instalment count', 'igbz-suite' ), 'type' => 'number', 'min' => 1, 'max' => 24 ],
					[ 'key' => 'bnpl.interval_days', 'label' => __( 'Days between instalments', 'igbz-suite' ), 'type' => 'number', 'min' => 1, 'max' => 365 ],
					[ 'key' => 'bnpl.fee_percent', 'label' => __( 'Service fee %', 'igbz-suite' ), 'type' => 'number', 'step' => '0.01', 'max' => 100 ],
					[ 'key' => 'bnpl.penalty_percent_per_day', 'label' => __( 'Late penalty % per day', 'igbz-suite' ), 'type' => 'number', 'step' => '0.01', 'max' => 100 ],
					[ 'key' => 'bnpl.min_order_total', 'label' => __( 'Minimum order total', 'igbz-suite' ), 'type' => 'number', 'max' => 999999999 ],
					[ 'key' => 'bnpl.default_credit_limit', 'label' => __( 'Default credit limit', 'igbz-suite' ), 'type' => 'number', 'max' => 999999999 ],
					[ 'key' => 'bnpl.snapppay.base_url', 'label' => __( 'SnappPay base URL', 'igbz-suite' ), 'placeholder' => 'https://api.snapppay.ir' ],
					[ 'key' => 'bnpl.snapppay.username', 'label' => __( 'SnappPay username', 'igbz-suite' ) ],
					[ 'key' => 'bnpl.snapppay.password', 'label' => __( 'SnappPay password', 'igbz-suite' ), 'type' => 'password' ],
					[ 'key' => 'bnpl.tara.base_url', 'label' => __( 'Tara base URL', 'igbz-suite' ), 'placeholder' => 'https://api.tara-club.ir' ],
					[ 'key' => 'bnpl.tara.api_key', 'label' => __( 'Tara API key', 'igbz-suite' ), 'type' => 'password' ],
					[ 'key' => 'bnpl.auto_collect', 'label' => __( 'Collect instalments from the wallet automatically', 'igbz-suite' ), 'type' => 'checkbox', 'help' => __( 'On the due date the amount is taken from the wallet when the balance covers it.', 'igbz-suite' ) ],
					[ 'key' => 'bnpl.reminder_days_before', 'label' => __( 'Remind N days before an instalment', 'igbz-suite' ), 'type' => 'number', 'max' => 30 ],
					[ 'key' => 'bnpl.default_after_days', 'label' => __( 'Mark as defaulted after (days overdue)', 'igbz-suite' ), 'type' => 'number', 'min' => 1, 'max' => 365 ],
				];

			case 'affiliate':
				return [
					[ 'key' => 'affiliate.enabled', 'label' => __( 'Enable affiliate programme', 'igbz-suite' ), 'type' => 'checkbox' ],
					[ 'key' => 'affiliate.tier1_rate', 'label' => __( 'Tier 1 commission %', 'igbz-suite' ), 'type' => 'number', 'step' => '0.01', 'max' => 100 ],
					[ 'key' => 'affiliate.tier2_rate', 'label' => __( 'Tier 2 commission %', 'igbz-suite' ), 'type' => 'number', 'step' => '0.01', 'max' => 100 ],
					[ 'key' => 'affiliate.cookie_days', 'label' => __( 'Referral cookie lifetime (days)', 'igbz-suite' ), 'type' => 'number', 'max' => 365 ],
					[ 'key' => 'affiliate.approve_after_days', 'label' => __( 'Auto approve commissions after (days)', 'igbz-suite' ), 'type' => 'number', 'max' => 180 ],
					[ 'key' => 'affiliate.min_payout', 'label' => __( 'Minimum payout amount', 'igbz-suite' ), 'type' => 'number', 'max' => 999999999 ],
					[ 'key' => 'affiliate.payout_to_wallet', 'label' => __( 'Pay commissions into the wallet', 'igbz-suite' ), 'type' => 'checkbox' ],
				];

			case 'lms':
				return [
					[ 'key' => 'lms.enabled', 'label' => __( 'Enable LMS', 'igbz-suite' ), 'type' => 'checkbox' ],
					[ 'key' => 'lms.video_link_ttl', 'label' => __( 'Signed video link TTL (seconds)', 'igbz-suite' ), 'type' => 'number', 'min' => 60, 'max' => 86400 ],
					[ 'key' => 'lms.max_quiz_attempts', 'label' => __( 'Maximum quiz attempts', 'igbz-suite' ), 'type' => 'number', 'min' => 1, 'max' => 50 ],
					[ 'key' => 'lms.pass_score', 'label' => __( 'Pass score %', 'igbz-suite' ), 'type' => 'number', 'max' => 100 ],
					[ 'key' => 'lms.certificate_enabled', 'label' => __( 'Issue certificates on completion', 'igbz-suite' ), 'type' => 'checkbox' ],
					[ 'key' => 'lms.course_page_id', 'label' => __( 'Course page id', 'igbz-suite' ), 'type' => 'number', 'max' => 99999999, 'help' => __( 'The page holding the [igbz_course] shortcode; used to build course links.', 'igbz-suite' ) ],
					[
						'key'   => 'lms.video_hmac_secret',
						'label' => __( 'Video signing secret', 'igbz-suite' ),
						'type'  => 'password',
						'help'  => __( 'Generated automatically on activation. Rotating it invalidates every issued video link.', 'igbz-suite' ),
					],
				];

			case 'payments':
				return [
					[
						'key'     => 'payments.default_gateway',
						'label'   => __( 'Default gateway', 'igbz-suite' ),
						'type'    => 'select',
						'options' => [
							'zarinpal' => 'ZarinPal',
							'idpay'    => 'IDPay',
							'nextpay'  => 'NextPay',
							'payir'    => 'Pay.ir',
						],
					],
					[ 'key' => 'payments.zarinpal.enabled', 'label' => __( 'ZarinPal enabled', 'igbz-suite' ), 'type' => 'checkbox' ],
					[ 'key' => 'payments.zarinpal.merchant_id', 'label' => __( 'ZarinPal merchant id', 'igbz-suite' ), 'type' => 'password', 'help' => __( '36 character UUID.', 'igbz-suite' ) ],
					[ 'key' => 'payments.zarinpal.sandbox', 'label' => __( 'ZarinPal sandbox', 'igbz-suite' ), 'type' => 'checkbox' ],
					[ 'key' => 'payments.idpay.enabled', 'label' => __( 'IDPay enabled', 'igbz-suite' ), 'type' => 'checkbox' ],
					[ 'key' => 'payments.idpay.api_key', 'label' => __( 'IDPay API key', 'igbz-suite' ), 'type' => 'password' ],
					[ 'key' => 'payments.idpay.sandbox', 'label' => __( 'IDPay sandbox', 'igbz-suite' ), 'type' => 'checkbox' ],
					[ 'key' => 'payments.nextpay.enabled', 'label' => __( 'NextPay enabled', 'igbz-suite' ), 'type' => 'checkbox' ],
					[ 'key' => 'payments.nextpay.api_key', 'label' => __( 'NextPay API key', 'igbz-suite' ), 'type' => 'password' ],
					[ 'key' => 'payments.payir.enabled', 'label' => __( 'Pay.ir enabled', 'igbz-suite' ), 'type' => 'checkbox' ],
					[ 'key' => 'payments.payir.api_key', 'label' => __( 'Pay.ir API key', 'igbz-suite' ), 'type' => 'password' ],
					[
						'key'   => 'payments.payir.sandbox',
						'label' => __( 'Pay.ir sandbox', 'igbz-suite' ),
						'type'  => 'checkbox',
						'help'  => __( 'Sends the literal test key so payments are simulated rather than charged.', 'igbz-suite' ),
					],
					[
						'key'   => 'payments.currency_multiplier',
						'label' => __( 'Toman to Rial multiplier', 'igbz-suite' ),
						'type'  => 'number',
						'min'   => 1,
						'max'   => 100,
						'help'  => __( 'Iranian gateways charge in Rial. Set 10 when your shop prices are in Toman.', 'igbz-suite' ),
					],
				];

			case 'otp':
				return [
					[ 'key' => 'otp.enabled', 'label' => __( 'Enable OTP login', 'igbz-suite' ), 'type' => 'checkbox' ],
					[
						'key'     => 'otp.sms_provider',
						'label'   => __( 'SMS provider', 'igbz-suite' ),
						'type'    => 'select',
						'options' => [
							'log'       => __( 'Log only (development)', 'igbz-suite' ),
							'kavenegar' => 'Kavenegar',
							'smsir'     => 'SMS.ir',
						],
					],
					[ 'key' => 'otp.code_length', 'label' => __( 'Code length', 'igbz-suite' ), 'type' => 'number', 'min' => 4, 'max' => 10 ],
					[ 'key' => 'otp.ttl_seconds', 'label' => __( 'Code lifetime (seconds)', 'igbz-suite' ), 'type' => 'number', 'min' => 60, 'max' => 3600 ],
					[ 'key' => 'otp.max_attempts', 'label' => __( 'Maximum verify attempts', 'igbz-suite' ), 'type' => 'number', 'min' => 1, 'max' => 20 ],
					[ 'key' => 'otp.resend_seconds', 'label' => __( 'Resend cool-down (seconds)', 'igbz-suite' ), 'type' => 'number', 'min' => 10, 'max' => 3600 ],
					[ 'key' => 'otp.max_per_hour', 'label' => __( 'Maximum codes per hour per number', 'igbz-suite' ), 'type' => 'number', 'min' => 1, 'max' => 100 ],
					[ 'key' => 'otp.message_template', 'label' => __( 'Message template', 'igbz-suite' ), 'type' => 'textarea', 'help' => __( 'Use {code} as the placeholder.', 'igbz-suite' ) ],
					[ 'key' => 'otp.kavenegar.api_key', 'label' => __( 'Kavenegar API key', 'igbz-suite' ), 'type' => 'password' ],
					[ 'key' => 'otp.kavenegar.template', 'label' => __( 'Kavenegar verify template', 'igbz-suite' ) ],
					[ 'key' => 'otp.kavenegar.sender', 'label' => __( 'Kavenegar sender line', 'igbz-suite' ) ],
					[ 'key' => 'otp.smsir.api_key', 'label' => __( 'SMS.ir API key', 'igbz-suite' ), 'type' => 'password' ],
					[ 'key' => 'otp.smsir.template_id', 'label' => __( 'SMS.ir template id', 'igbz-suite' ), 'type' => 'number', 'max' => 9999999 ],
				];

			case 'marketplace':
				return [
					[ 'key' => 'marketplace.enabled', 'label' => __( 'Enable product feeds', 'igbz-suite' ), 'type' => 'checkbox' ],
					[ 'key' => 'marketplace.torob.enabled', 'label' => __( 'Torob feed', 'igbz-suite' ), 'type' => 'checkbox' ],
					[ 'key' => 'marketplace.emalls.enabled', 'label' => __( 'Emalls feed', 'igbz-suite' ), 'type' => 'checkbox' ],
					[ 'key' => 'marketplace.google.enabled', 'label' => __( 'Google Merchant feed', 'igbz-suite' ), 'type' => 'checkbox' ],
					[ 'key' => 'marketplace.feed_limit', 'label' => __( 'Products per feed', 'igbz-suite' ), 'type' => 'number', 'min' => 10, 'max' => 5000 ],
					[ 'key' => 'marketplace.cache_ttl', 'label' => __( 'Feed cache TTL (seconds)', 'igbz-suite' ), 'type' => 'number', 'min' => 0, 'max' => 86400 ],
				];

			case 'manus':
				return [
					[ 'key' => 'manus.api_key', 'label' => __( 'Shared Manus API key (free trial)', 'igbz-suite' ), 'type' => 'password', 'help' => __( 'Only used by accounts set to the free trial. Accounts on their own keys never touch it, so tasks are billed to the tenant.', 'igbz-suite' ) ],
					[
						'key'     => 'manus.agent_profile',
						'label'   => __( 'Agent profile', 'igbz-suite' ),
						'type'    => 'select',
						'options' => [
							'manus-1.6'      => 'manus-1.6',
							'manus-1.6-lite' => 'manus-1.6-lite',
							'manus-1.6-max'  => 'manus-1.6-max',
						],
					],
					[ 'key' => 'manus.project_id', 'label' => __( 'Manus project id', 'igbz-suite' ), 'help' => __( 'Optional. Groups every task the plugin creates.', 'igbz-suite' ) ],
					[ 'key' => 'manus.locale', 'label' => __( 'Task locale', 'igbz-suite' ), 'placeholder' => 'fa-IR' ],
					[ 'key' => 'manus.content_language', 'label' => __( 'Caption language', 'igbz-suite' ), 'placeholder' => 'Persian (Farsi)' ],
					[ 'key' => 'manus.auto_generate', 'label' => __( 'Generate content automatically', 'igbz-suite' ), 'type' => 'checkbox', 'help' => __( 'Niche research, graphics and reels are produced by Manus without manual briefs.', 'igbz-suite' ) ],
					[ 'key' => 'manus.auto_schedule', 'label' => __( 'Auto schedule at peak hours', 'igbz-suite' ), 'type' => 'checkbox' ],
					[ 'key' => 'instagram.autopublish', 'label' => __( 'Publish without manual approval', 'igbz-suite' ), 'type' => 'checkbox', 'help' => __( 'Off means each piece waits in the queue until someone approves it.', 'igbz-suite' ) ],
					[ 'key' => 'manus.use_canva', 'label' => __( 'Use the Canva connector for graphics', 'igbz-suite' ), 'type' => 'checkbox' ],
					[ 'key' => 'canva.api_key', 'label' => __( 'Canva API key', 'igbz-suite' ), 'type' => 'password', 'help' => __( 'Optional: passed to Manus so designs are produced straight into your Canva workspace.', 'igbz-suite' ) ],
					[ 'key' => 'manus.collect_insights', 'label' => __( 'Collect engagement insights', 'igbz-suite' ), 'type' => 'checkbox' ],
					[ 'key' => 'manus.default_peak_hours', 'label' => __( 'Fallback peak hours', 'igbz-suite' ), 'help' => __( 'Comma separated HH:MM values, used until enough insights exist.', 'igbz-suite' ) ],
					[ 'key' => 'manus.min_gap_minutes', 'label' => __( 'Minimum gap between posts (minutes)', 'igbz-suite' ), 'type' => 'number', 'min' => 0, 'max' => 1440 ],
					[ 'key' => 'manus.reel_seconds', 'label' => __( 'Target reel length (seconds)', 'igbz-suite' ), 'type' => 'number', 'min' => 5, 'max' => 90 ],
					[ 'key' => 'manus.weekly_slots', 'label' => __( 'Posts to plan per week', 'igbz-suite' ), 'type' => 'number', 'min' => 1, 'max' => 42, 'help' => __( 'How many pieces the auto planner queues for each account every week.', 'igbz-suite' ) ],
					[ 'key' => 'manus.poll_interval', 'label' => __( 'Task poll interval (seconds)', 'igbz-suite' ), 'type' => 'number', 'min' => 60, 'max' => 3600 ],
					[ 'key' => 'manus.account_concurrency', 'label' => __( 'Concurrent Manus tasks per account', 'igbz-suite' ), 'type' => 'number', 'min' => 0, 'max' => 20, 'help' => __( 'Optional ceiling on how many tasks one account may have in flight. Leave at 0 to let accounts on their own API keys use the capacity they pay for; trial accounts are always limited to what is left of their free quota.', 'igbz-suite' ) ],
					[ 'key' => 'trial.enabled', 'label' => __( 'Offer the free trial', 'igbz-suite' ), 'type' => 'checkbox', 'help' => __( 'Lets a new account borrow the shared keys above for a limited time.', 'igbz-suite' ) ],
					[ 'key' => 'trial.days', 'label' => __( 'Trial length (days)', 'igbz-suite' ), 'type' => 'number', 'min' => 1, 'max' => 365 ],
					[ 'key' => 'trial.task_quota', 'label' => __( 'Trial task quota', 'igbz-suite' ), 'type' => 'number', 'min' => 0, 'max' => 10000, 'help' => __( 'Manus tasks one trial account may run in total, ever. The default of 1 makes the trial a single sample request, after which the account must add its own keys. 0 means unlimited and only the expiry date applies.', 'igbz-suite' ) ],
				];

			case 'manychat':
				return [
					[ 'key' => 'manychat.api_key', 'label' => __( 'Shared ManyChat API key (free trial)', 'igbz-suite' ), 'type' => 'password', 'help' => __( 'Settings &rarr; API in ManyChat, Pro required. A ManyChat key drives one page only, so this is the trial page; every other account needs its own key.', 'igbz-suite' ) ],
					
					[ 'key' => 'manychat.async_reply', 'label' => __( 'Reply asynchronously', 'igbz-suite' ), 'type' => 'checkbox', 'help' => __( 'ManyChat waits about 10 seconds. With this on the webhook answers instantly and the link is pushed back with setCustomField + sendFlow.', 'igbz-suite' ) ],
					[ 'key' => 'manychat.link_field_name', 'label' => __( 'Custom field for the link', 'igbz-suite' ), 'placeholder' => 'igbz_link' ],
					[ 'key' => 'manychat.coupon_field_name', 'label' => __( 'Custom field for the coupon', 'igbz-suite' ), 'placeholder' => 'igbz_coupon' ],
					[ 'key' => 'manychat.default_flow_ns', 'label' => __( 'Default flow namespace', 'igbz-suite' ), 'placeholder' => 'content20180221085508_278589' ],
					[ 'key' => 'manychat.button_label', 'label' => __( 'Button label', 'igbz-suite' ) ],
					[ 'key' => 'manychat.duplicate_message', 'label' => __( 'Message on a repeat request', 'igbz-suite' ), 'type' => 'textarea' ],
					[ 'key' => 'instagram.unique_coupons', 'label' => __( 'Issue a unique coupon per subscriber', 'igbz-suite' ), 'type' => 'checkbox', 'help' => __( 'A single-use WooCommerce coupon is generated for each person the funnel answers.', 'igbz-suite' ) ],
					[ 'key' => 'instagram.coupon_ttl_days', 'label' => __( 'Coupon lifetime (days)', 'igbz-suite' ), 'type' => 'number', 'min' => 1, 'max' => 365 ],
					[
						'key'   => 'manychat.webhook_url',
						'label' => __( 'Webhook URL', 'igbz-suite' ),
						'type'  => 'readonly',
					],
				];

			case 'intake':
				return [
					[
						'key'   => 'intake.enabled',
						'label' => __( 'Enable registration from the app', 'igbz-suite' ),
						'type'  => 'checkbox',
						'help'  => __( 'Shopkeepers photograph a product in the app and the assistant does the rest. No product is ever created through the WooCommerce admin.', 'igbz-suite' ),
					],
					[
						'key'         => 'intake.sku_prefix',
						'label'       => __( 'SKU prefix', 'igbz-suite' ),
						'placeholder' => 'IGBZ',
						'help'        => __( 'Warehouse SKUs look like IGBZ-4F2K. This is the inventory code on invoices and packing lists — customers never see it.', 'igbz-suite' ),
					],
					[
						'key'   => 'intake.code_digits',
						'label' => __( 'Customer code length', 'igbz-suite' ),
						'type'  => 'number',
						'min'   => 4,
						'max'   => 12,
						'help'  => __( 'The number customers comment to get the purchase link is the product ID, padded to this many digits — product 47 becomes 0047. Digits are used because they are easy to type on a Persian keyboard. Four is the minimum: shorter codes get typed under posts by accident and would send links to people who never asked.', 'igbz-suite' ),
					],
					[
						'key'   => 'intake.quality_threshold',
						'label' => __( 'Minimum photo score', 'igbz-suite' ),
						'type'  => 'number',
						'min'   => 0,
						'max'   => 100,
						'help'  => __( 'Photos scoring below this are sent back with the reasons so the shopkeeper can retake them. Raise it for a stricter catalogue, lower it if too many honest photos are being refused.', 'igbz-suite' ),
					],
					[
						'key'     => 'intake.product_status',
						'label'   => __( 'New products are', 'igbz-suite' ),
						'type'    => 'select',
						'options' => [
							'publish' => __( 'Published immediately', 'igbz-suite' ),
							'draft'   => __( 'Saved as drafts for review', 'igbz-suite' ),
							'pending' => __( 'Submitted for review', 'igbz-suite' ),
						],
					],
					[
						'key'   => 'intake.image_style',
						'label' => __( 'Product image style', 'igbz-suite' ),
						'type'  => 'textarea',
						'help'  => __( 'Describes the background and lighting the assistant places every product on. The product itself is never altered.', 'igbz-suite' ),
					],
					[
						'key'   => 'intake.funnel_reply',
						'label' => __( 'Direct message text', 'igbz-suite' ),
						'type'  => 'textarea',
						'help'  => __( 'Sent when somebody comments a product code. Use {link} for the purchase link and {coupon} for a coupon. Leave empty for the default.', 'igbz-suite' ),
					],
					[
						'key'   => 'intake.funnel_per_user_limit',
						'label' => __( 'Deliveries per person per product', 'igbz-suite' ),
						'type'  => 'number',
						'min'   => 1,
						'max'   => 20,
					],
					[
						'key'         => 'intake.languages',
						'label'       => __( 'Translate listings into', 'igbz-suite' ),
						'placeholder' => 'en, ar',
						'help'        => __( 'Only needed when no multilingual plugin is installed. With Polylang or WPML active the language list is read from them and real translated products are created. Without one, translations are stored on the product and turned into real products the day you install a plugin.', 'igbz-suite' ),
					],
					[
						'key'         => 'intake.default_language',
						'label'       => __( 'Original language', 'igbz-suite' ),
						'placeholder' => 'fa',
						'help'        => __( 'Also only needed when no multilingual plugin is installed.', 'igbz-suite' ),
					],
					[
						'key'   => 'stt.enabled',
						'label' => __( 'Accept voice input', 'igbz-suite' ),
						'type'  => 'checkbox',
						'help'  => __( 'Shopkeepers can dictate the product description and the video brief instead of typing.', 'igbz-suite' ),
					],
					[
						'key'     => 'stt.provider',
						'label'   => __( 'Speech-to-text engine', 'igbz-suite' ),
						'type'    => 'select',
						'options' => [
							'manus' => __( 'Manus (no setup, slower)', 'igbz-suite' ),
							'http'  => __( 'Custom endpoint (Whisper or similar)', 'igbz-suite' ),
						],
						'help'    => __( 'Manus needs no configuration but answers in minutes rather than seconds because it runs as a task. A dedicated endpoint is near-instant. Whichever is chosen, Manus is the fallback if it fails.', 'igbz-suite' ),
					],
					[
						'key'         => 'stt.endpoint',
						'label'       => __( 'Endpoint URL', 'igbz-suite' ),
						'placeholder' => 'https://api.openai.com/v1/audio/transcriptions',
					],
					[ 'key' => 'stt.api_key', 'label' => __( 'Endpoint API key', 'igbz-suite' ), 'type' => 'password' ],
					[ 'key' => 'stt.model', 'label' => __( 'Model', 'igbz-suite' ), 'placeholder' => 'whisper-1' ],
					[ 'key' => 'stt.language', 'label' => __( 'Spoken language', 'igbz-suite' ), 'placeholder' => 'fa' ],
					[
						'key'         => 'stt.file_field',
						'label'       => __( 'Audio field name', 'igbz-suite' ),
						'placeholder' => 'file',
						'help'        => __( 'The multipart field the service expects the recording under. Whisper uses "file"; some services use "audio".', 'igbz-suite' ),
					],
					[ 'key' => 'stt.auth_header', 'label' => __( 'Authentication header', 'igbz-suite' ), 'placeholder' => 'Authorization' ],
					[
						'key'         => 'stt.auth_scheme',
						'label'       => __( 'Authentication scheme', 'igbz-suite' ),
						'placeholder' => 'Bearer',
						'help'        => __( 'Leave empty to send the key on its own, which is what X-API-KEY style headers expect.', 'igbz-suite' ),
					],
					[
						'key'         => 'stt.response_path',
						'label'       => __( 'Transcript field', 'igbz-suite' ),
						'placeholder' => 'text',
						'help'        => __( 'Where the transcript sits in the reply, dotted for nested values. Leave empty and the usual field names are tried.', 'igbz-suite' ),
					],
					[ 'key' => 'stt.timeout', 'label' => __( 'Request timeout (seconds)', 'igbz-suite' ), 'type' => 'number', 'min' => 10, 'max' => 600 ],
				];

			case 'hub':
				return [
					[ 'key' => 'hub.enabled', 'label' => __( 'Enable master site hub', 'igbz-suite' ), 'type' => 'checkbox' ],
					[ 'key' => 'hub.mother_origin', 'label' => __( 'Mother site origin', 'igbz-suite' ), 'placeholder' => 'https://igbz.example', 'help' => __( 'CORS is restricted to this origin. Empty means same-origin only, never a wildcard.', 'igbz-suite' ) ],
					[ 'key' => 'hub.vip_link_ttl', 'label' => __( 'VIP link TTL (seconds)', 'igbz-suite' ), 'type' => 'number', 'min' => 60, 'max' => 86400 ],
					[ 'key' => 'hub.vip_link_secret', 'label' => __( 'VIP link signing secret', 'igbz-suite' ), 'type' => 'password' ],
					[ 'key' => 'hub.sync_interval', 'label' => __( 'Aggregate refresh interval (seconds)', 'igbz-suite' ), 'type' => 'number', 'min' => 300, 'max' => 86400 ],
					[ 'key' => 'hub.featured_limit', 'label' => __( 'Featured stores on the hub', 'igbz-suite' ), 'type' => 'number', 'min' => 1, 'max' => 200 ],
					[ 'key' => 'hub.subdomain_base', 'label' => __( 'Sub-domain base', 'igbz-suite' ), 'placeholder' => 'igbz.example', 'help' => __( 'New stores get {slug}.{base}. Leave empty to use path prefixes instead.', 'igbz-suite' ) ],
					[ 'key' => 'hub.cname_target', 'label' => __( 'CNAME target for custom domains', 'igbz-suite' ), 'placeholder' => 'stores.igbz.example', 'help' => __( 'Shown to store owners and checked during domain verification.', 'igbz-suite' ) ],
					[ 'key' => 'hub.hero_title', 'label' => __( 'Hub hero title', 'igbz-suite' ) ],
					[ 'key' => 'hub.hero_description', 'label' => __( 'Hub hero description', 'igbz-suite' ), 'type' => 'textarea' ],
				];

			case 'api':
				return [
					[ 'key' => 'api.jwt_secret', 'label' => __( 'JWT signing secret', 'igbz-suite' ), 'type' => 'password', 'help' => __( 'Generated on activation. Rotating it logs every mobile client out.', 'igbz-suite' ) ],
					[ 'key' => 'api.jwt_ttl', 'label' => __( 'Access token TTL (seconds)', 'igbz-suite' ), 'type' => 'number', 'min' => 300, 'max' => 86400, 'help' => __( 'Short lived on purpose; the original issued 30 day tokens with no revocation.', 'igbz-suite' ) ],
					[ 'key' => 'api.refresh_ttl', 'label' => __( 'Refresh token TTL (seconds)', 'igbz-suite' ), 'type' => 'number', 'min' => 3600, 'max' => 31536000 ],
					[ 'key' => 'api.rate_limit_per_minute', 'label' => __( 'Requests per minute per token', 'igbz-suite' ), 'type' => 'number', 'min' => 10, 'max' => 6000 ],
					[ 'key' => 'api.push_enabled', 'label' => __( 'Enable push notifications', 'igbz-suite' ), 'type' => 'checkbox' ],
					[ 'key' => 'api.fcm_project_id', 'label' => __( 'FCM project id', 'igbz-suite' ) ],
					[ 'key' => 'api.fcm_service_account', 'label' => __( 'FCM service account JSON', 'igbz-suite' ), 'type' => 'password', 'help' => __( 'Paste the whole service account JSON. Stored encrypted, never written to disk.', 'igbz-suite' ) ],
					[ 'key' => 'api.push_channel_id', 'label' => __( 'Android notification channel', 'igbz-suite' ), 'help' => __( 'Must match a channel the app creates, otherwise Android silently drops the notification.', 'igbz-suite' ) ],
					[ 'key' => 'api.push_order_updates', 'label' => __( 'Notify on order status changes', 'igbz-suite' ), 'type' => 'checkbox' ],
					[ 'key' => 'api.device_retention_days', 'label' => __( 'Forget silent devices after (days)', 'igbz-suite' ), 'type' => 'number', 'min' => 30, 'max' => 3650 ],
					[ 'key' => 'api.app_scheme', 'label' => __( 'Deep link scheme', 'igbz-suite' ), 'help' => __( 'Without "://". Used to build igbz://products/42 style links.', 'igbz-suite' ) ],
					[ 'key' => 'api.universal_link', 'label' => __( 'Universal / App Link base URL', 'igbz-suite' ) ],
					[ 'key' => 'api.android_package', 'label' => __( 'Android package name', 'igbz-suite' ), 'help' => 'com.example.shop' ],
					[ 'key' => 'api.ios_bundle_id', 'label' => __( 'iOS bundle id', 'igbz-suite' ) ],
					[ 'key' => 'api.latest_app_version', 'label' => __( 'Latest app version', 'igbz-suite' ) ],
					[ 'key' => 'api.min_app_version', 'label' => __( 'Minimum supported app version', 'igbz-suite' ), 'help' => __( 'Older builds get a forced-update screen from /app/config.', 'igbz-suite' ) ],
					[ 'key' => 'api.apk_url', 'label' => __( 'Direct APK download URL', 'igbz-suite' ), 'help' => __( 'For distribution outside Google Play, which is the norm in Iran.', 'igbz-suite' ) ],
					[ 'key' => 'api.ios_store_url', 'label' => __( 'iOS download URL', 'igbz-suite' ) ],
				];

			case 'advanced':
				return [
					[
						'key'     => 'log.level',
						'label'   => __( 'Log level', 'igbz-suite' ),
						'type'    => 'select',
						'options' => [
							Logger::DEBUG   => 'debug',
							Logger::INFO    => 'info',
							Logger::WARNING => 'warning',
							Logger::ERROR   => 'error',
						],
					],
					[ 'key' => 'log.retention_days', 'label' => __( 'Log retention (days)', 'igbz-suite' ), 'type' => 'number', 'min' => 1, 'max' => 365 ],
					[ 'key' => 'http.timeout', 'label' => __( 'Outbound HTTP timeout (seconds)', 'igbz-suite' ), 'type' => 'number', 'min' => 5, 'max' => 120 ],
					[
						'key'   => 'purge_on_uninstall',
						'label' => __( 'Delete all data on uninstall', 'igbz-suite' ),
						'type'  => 'checkbox',
						'help'  => __( 'Off by default. When on, deleting the plugin drops every IGBZ table.', 'igbz-suite' ),
					],
				];
		}

		return [];
	}

	// ---------------------------------------------------------------- render

	public function render(): void {
		$tabs    = $this->tabs();
		$current = $this->current_tab( $tabs );

		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			$this->handle_post( $current );
		}

		View::open( __( 'IGBZ Suite settings', 'igbz-suite' ) );
		View::tabs( $tabs, $current, self::SLUG );

		echo '<form method="post" action="' . esc_url( Menu::url( self::SLUG, [ 'tab' => $current ] ) ) . '">';
		wp_nonce_field( 'igbz_save_settings_' . $current );

		if ( 'modules' === $current ) {
			$this->render_modules();
		} else {
			echo '<table class="form-table" role="presentation"><tbody>';
			foreach ( $this->fields( $current ) as $field ) {
				View::field( $field, $this->display_value( $field ) );
			}
			echo '</tbody></table>';
		}

		submit_button( __( 'Save changes', 'igbz-suite' ) );
		echo '</form>';
		View::close();
	}

	/** @param array<string,string> $tabs */
	private function current_tab( array $tabs ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- tab selection only.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general';
		return isset( $tabs[ $tab ] ) ? $tab : 'general';
	}

	/** @param array<string,mixed> $field */
	private function display_value( array $field ): mixed {
		$key = (string) $field['key'];

		if ( 'manychat.webhook_url' === $key ) {
			return rest_url( ManyChatWebhook::NAMESPACE . '/manychat/comment' );
		}
		if ( 'purge_on_uninstall' === $key ) {
			return '1' === get_option( 'igbz_purge_on_uninstall', '0' );
		}
		if ( $this->settings->is_secret( $key ) ) {
			return $this->settings->masked( $key );
		}
		if ( 'checkbox' === ( $field['type'] ?? '' ) ) {
			return $this->settings->bool( $key );
		}
		return $this->settings->get( $key, '' );
	}

	private function render_modules(): void {
		$labels = [
			Modules::MULTITENANT => [
				__( 'Multi-tenant stores', 'igbz-suite' ),
				__( 'Tenants, wallet, subscription plans, BNPL, affiliate, LMS, payment gateways, OTP and marketplace feeds.', 'igbz-suite' ),
			],
			Modules::INSTAGRAM   => [
				__( 'Instagram automation (Manus + ManyChat)', 'igbz-suite' ),
				__( 'Content research and design through Manus, auto publishing at peak hours, and comment-to-DM funnels through ManyChat.', 'igbz-suite' ),
			],
			Modules::HUB         => [
				__( 'Master site hub', 'igbz-suite' ),
				__( 'Cross-store aggregation, store directory, VIP links and hub REST endpoints for the mother site.', 'igbz-suite' ),
			],
			Modules::REST_API    => [
				__( 'Mobile REST API', 'igbz-suite' ),
				__( 'JWT authentication with refresh and revocation, catalogue and account endpoints, device registration and FCM push.', 'igbz-suite' ),
			],
		];

		$enabled = Modules::enabled_list();

		echo '<table class="form-table" role="presentation"><tbody>';
		foreach ( Modules::all() as $id ) {
			[ $title, $description ] = $labels[ $id ];
			printf(
				'<tr><th scope="row">%1$s</th><td><label><input type="checkbox" name="igbz_modules[]" value="%2$s" %3$s /> %4$s</label><p class="description">%5$s</p></td></tr>',
				esc_html( $title ),
				esc_attr( $id ),
				checked( in_array( $id, $enabled, true ), true, false ),
				esc_html__( 'Enabled', 'igbz-suite' ),
				esc_html( $description )
			);
		}
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'Disabling a module only stops it from running; its data and tables are kept.', 'igbz-suite' ) . '</p>';
	}

	// ------------------------------------------------------------------ save

	private function handle_post( string $tab ): void {
		Capabilities::require( Capabilities::MANAGE_SUITE );
		View::check_nonce( 'igbz_save_settings_' . $tab );

		if ( 'modules' === $tab ) {
			$raw = isset( $_POST['igbz_modules'] ) ? (array) wp_unslash( $_POST['igbz_modules'] ) : [];
			Modules::save( array_map( 'sanitize_key', $raw ) );
			View::notice( __( 'Modules updated. Reload any open admin screen to see the new menus.', 'igbz-suite' ) );
			return;
		}

		$posted = isset( $_POST['igbz'] ) ? (array) wp_unslash( $_POST['igbz'] ) : [];
		$values = [];

		foreach ( $this->fields( $tab ) as $field ) {
			$key  = (string) $field['key'];
			$type = $field['type'] ?? 'text';

			if ( 'readonly' === $type ) {
				continue;
			}
			if ( 'purge_on_uninstall' === $key ) {
				update_option( 'igbz_purge_on_uninstall', empty( $posted[ $key ] ) ? '0' : '1', true );
				continue;
			}
			if ( ! array_key_exists( $key, $posted ) && 'checkbox' !== $type ) {
				continue;
			}

			$raw = $posted[ $key ] ?? '';
			$values[ $key ] = $this->sanitize( $field, $raw );
		}

		if ( $values ) {
			$this->settings->set_many( $values );
		}

		do_action( 'igbz_settings_saved', $tab, $values );
		View::notice( __( 'Settings saved.', 'igbz-suite' ) );
	}

	/** @param array<string,mixed> $field */
	private function sanitize( array $field, mixed $raw ): mixed {
		$key  = (string) $field['key'];
		$type = $field['type'] ?? 'text';

		switch ( $type ) {
			case 'checkbox':
				return ! empty( $raw );

			case 'number':
				$number = is_scalar( $raw ) ? (float) $raw : 0.0;
				if ( isset( $field['min'] ) ) {
					$number = max( (float) $field['min'], $number );
				}
				if ( isset( $field['max'] ) ) {
					$number = min( (float) $field['max'], $number );
				}
				return isset( $field['step'] ) && '1' !== $field['step'] ? $number : (int) $number;

			case 'select':
				$value   = sanitize_text_field( (string) $raw );
				$options = array_map( 'strval', array_keys( (array) ( $field['options'] ?? [] ) ) );
				return in_array( $value, $options, true ) ? $value : (string) $this->settings->get( $key, reset( $options ) );

			case 'textarea':
				return sanitize_textarea_field( (string) $raw );

			case 'password':
				$value = trim( (string) $raw );
				// The mask means "unchanged"; Settings::set_many() skips it.
				return Crypto::MASK === $value ? Crypto::MASK : $value;

			default:
				return sanitize_text_field( (string) $raw );
		}
	}
}
