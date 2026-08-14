<?php
namespace IGBZ\Suite\Support;

defined( 'ABSPATH' ) || exit;

/**
 * All IGBZ tables. Every tenant-scoped table carries tenant_id so a single WordPress install can
 * host many stores without Multisite (the design decision that replaces nopCommerce's Store entity).
 */
final class Schema {

	/** @return string[] dbDelta statements */
	/**
	 * Every table this plugin owns, without the `{$wpdb->prefix}igbz_` prefix.
	 *
	 * Kept next to statements() on purpose: uninstall.php reads this list, so a new CREATE TABLE
	 * can never be forgotten in the drop routine. tests/SchemaTest asserts the two stay in sync.
	 *
	 * @return string[]
	 */
	public static function tables(): array {
		return [
			'tenants',
			'tenant_domains',
			'tenant_members',
			'wallet_ledger',
			'wallet_balances',
			'plans',
			'subscriptions',
			'bnpl_credit',
			'bnpl_contracts',
			'bnpl_installments',
			'affiliates',
			'affiliate_commissions',
			'referral_clicks',
			'courses',
			'lessons',
			'enrollments',
			'lesson_progress',
			'quizzes',
			'quiz_attempts',
			'payments',
			'otp_codes',
			'marketplace_links',
			'ig_accounts',
			'ig_content',
			'ig_insights',
			'ig_funnels',
			'ig_subscribers',
			'ig_funnel_hits',
			'ig_intake',
			'api_tokens',
			'devices',
			'jobs',
			'logs',
		];
	}

	/** Fully qualified table name. */
	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'igbz_' . $name;
	}

	public static function statements(): array {
		global $wpdb;
		$p       = $wpdb->prefix . 'igbz_';
		$charset = $wpdb->get_charset_collate();
		$sql     = [];

		$sql[] = "CREATE TABLE {$p}tenants (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			slug VARCHAR(64) NOT NULL,
			name VARCHAR(191) NOT NULL,
			owner_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			plan_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			theme VARCHAR(64) NOT NULL DEFAULT '',
			logo_url VARCHAR(255) NOT NULL DEFAULT '',
			primary_color VARCHAR(16) NOT NULL DEFAULT '',
			currency VARCHAR(8) NOT NULL DEFAULT 'IRT',
			locale VARCHAR(10) NOT NULL DEFAULT 'fa_IR',
			settings LONGTEXT NULL,
			trial_ends_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug),
			KEY owner_user_id (owner_user_id),
			KEY status (status)
		) {$charset};";

		$sql[] = "CREATE TABLE {$p}tenant_domains (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL,
			domain VARCHAR(191) NOT NULL,
			is_primary TINYINT(1) NOT NULL DEFAULT 0,
			verified_at DATETIME NULL,
			verification_token VARCHAR(64) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY domain (domain),
			KEY tenant_id (tenant_id)
		) {$charset};";

		$sql[] = "CREATE TABLE {$p}tenant_members (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			role VARCHAR(32) NOT NULL DEFAULT 'staff',
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY tenant_user (tenant_id,user_id),
			KEY user_id (user_id)
		) {$charset};";

		// ------------------------------------------------------------ wallet
		$sql[] = "CREATE TABLE {$p}wallet_ledger (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			user_id BIGINT UNSIGNED NOT NULL,
			amount DECIMAL(18,4) NOT NULL,
			balance_after DECIMAL(18,4) NOT NULL DEFAULT 0,
			currency VARCHAR(8) NOT NULL DEFAULT 'IRT',
			direction VARCHAR(8) NOT NULL,
			reason VARCHAR(64) NOT NULL,
			reference_code VARCHAR(128) NOT NULL DEFAULT '',
			order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			note VARCHAR(255) NOT NULL DEFAULT '',
			meta LONGTEXT NULL,
			created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY idempotency (tenant_id,user_id,reason,reference_code),
			KEY user_tenant (user_id,tenant_id),
			KEY order_id (order_id),
			KEY created_at (created_at)
		) {$charset};";

		$sql[] = "CREATE TABLE {$p}wallet_balances (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			user_id BIGINT UNSIGNED NOT NULL,
			balance DECIMAL(18,4) NOT NULL DEFAULT 0,
			currency VARCHAR(8) NOT NULL DEFAULT 'IRT',
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY tenant_user (tenant_id,user_id)
		) {$charset};";

		// ------------------------------------------------------------ plans
		$sql[] = "CREATE TABLE {$p}plans (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			slug VARCHAR(64) NOT NULL,
			name VARCHAR(191) NOT NULL,
			description TEXT NULL,
			price DECIMAL(18,4) NOT NULL DEFAULT 0,
			currency VARCHAR(8) NOT NULL DEFAULT 'IRT',
			billing_period VARCHAR(16) NOT NULL DEFAULT 'monthly',
			trial_days INT NOT NULL DEFAULT 0,
			max_products INT NOT NULL DEFAULT 0,
			max_orders INT NOT NULL DEFAULT 0,
			max_staff INT NOT NULL DEFAULT 0,
			features LONGTEXT NULL,
			is_active TINYINT(1) NOT NULL DEFAULT 1,
			sort_order INT NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug)
		) {$charset};";

		$sql[] = "CREATE TABLE {$p}subscriptions (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL,
			plan_id BIGINT UNSIGNED NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			starts_at DATETIME NOT NULL,
			ends_at DATETIME NULL,
			cancelled_at DATETIME NULL,
			auto_renew TINYINT(1) NOT NULL DEFAULT 1,
			price_paid DECIMAL(18,4) NOT NULL DEFAULT 0,
			last_invoice_at DATETIME NULL,
			renewal_failures INT NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY tenant_id (tenant_id),
			KEY status_ends (status,ends_at)
		) {$charset};";

		// ------------------------------------------------------------ BNPL
		$sql[] = "CREATE TABLE {$p}bnpl_credit (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			user_id BIGINT UNSIGNED NOT NULL,
			credit_limit DECIMAL(18,4) NOT NULL DEFAULT 0,
			used_credit DECIMAL(18,4) NOT NULL DEFAULT 0,
			score INT NOT NULL DEFAULT 0,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			national_code VARCHAR(32) NOT NULL DEFAULT '',
			verified_at DATETIME NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY tenant_user (tenant_id,user_id)
		) {$charset};";

		$sql[] = "CREATE TABLE {$p}bnpl_contracts (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			user_id BIGINT UNSIGNED NOT NULL,
			order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			provider VARCHAR(32) NOT NULL DEFAULT 'internal',
			provider_ref VARCHAR(128) NOT NULL DEFAULT '',
			principal DECIMAL(18,4) NOT NULL DEFAULT 0,
			down_payment DECIMAL(18,4) NOT NULL DEFAULT 0,
			fee_amount DECIMAL(18,4) NOT NULL DEFAULT 0,
			total_payable DECIMAL(18,4) NOT NULL DEFAULT 0,
			installment_count INT NOT NULL DEFAULT 0,
			interval_days INT NOT NULL DEFAULT 30,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			signed_at DATETIME NULL,
			settled_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY tenant_user (tenant_id,user_id),
			KEY order_id (order_id),
			KEY status (status)
		) {$charset};";

		$sql[] = "CREATE TABLE {$p}bnpl_installments (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			contract_id BIGINT UNSIGNED NOT NULL,
			tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			user_id BIGINT UNSIGNED NOT NULL,
			sequence INT NOT NULL DEFAULT 1,
			amount DECIMAL(18,4) NOT NULL DEFAULT 0,
			penalty DECIMAL(18,4) NOT NULL DEFAULT 0,
			due_date DATE NOT NULL,
			paid_at DATETIME NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'due',
			payment_ref VARCHAR(128) NOT NULL DEFAULT '',
			reminder_sent_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY contract_seq (contract_id,sequence),
			KEY due_status (due_date,status),
			KEY user_id (user_id)
		) {$charset};";

		// ------------------------------------------------------------ affiliate
		$sql[] = "CREATE TABLE {$p}affiliates (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			user_id BIGINT UNSIGNED NOT NULL,
			code VARCHAR(32) NOT NULL,
			parent_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			tier INT NOT NULL DEFAULT 1,
			commission_rate DECIMAL(6,3) NOT NULL DEFAULT 0,
			total_earned DECIMAL(18,4) NOT NULL DEFAULT 0,
			total_paid DECIMAL(18,4) NOT NULL DEFAULT 0,
			clicks BIGINT UNSIGNED NOT NULL DEFAULT 0,
			signups BIGINT UNSIGNED NOT NULL DEFAULT 0,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY code (code),
			UNIQUE KEY tenant_user (tenant_id,user_id),
			KEY parent_id (parent_id)
		) {$charset};";

		$sql[] = "CREATE TABLE {$p}affiliate_commissions (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			affiliate_id BIGINT UNSIGNED NOT NULL,
			order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			referred_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			tier INT NOT NULL DEFAULT 1,
			base_amount DECIMAL(18,4) NOT NULL DEFAULT 0,
			rate DECIMAL(6,3) NOT NULL DEFAULT 0,
			amount DECIMAL(18,4) NOT NULL DEFAULT 0,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			approved_at DATETIME NULL,
			paid_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY order_affiliate_tier (order_id,affiliate_id,tier),
			KEY affiliate_id (affiliate_id),
			KEY status (status)
		) {$charset};";

		$sql[] = "CREATE TABLE {$p}referral_clicks (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			affiliate_id BIGINT UNSIGNED NOT NULL,
			source VARCHAR(64) NOT NULL DEFAULT '',
			landing_url VARCHAR(255) NOT NULL DEFAULT '',
			ip_hash CHAR(64) NOT NULL DEFAULT '',
			user_agent VARCHAR(255) NOT NULL DEFAULT '',
			converted_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY affiliate_id (affiliate_id),
			KEY created_at (created_at)
		) {$charset};";

		// ------------------------------------------------------------ LMS
		$sql[] = "CREATE TABLE {$p}courses (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			title VARCHAR(191) NOT NULL,
			slug VARCHAR(191) NOT NULL,
			summary TEXT NULL,
			description LONGTEXT NULL,
			cover_url VARCHAR(255) NOT NULL DEFAULT '',
			level VARCHAR(20) NOT NULL DEFAULT 'beginner',
			duration_minutes INT NOT NULL DEFAULT 0,
			instructor_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			certificate_enabled TINYINT(1) NOT NULL DEFAULT 0,
			pass_score INT NOT NULL DEFAULT 60,
			is_published TINYINT(1) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY tenant_slug (tenant_id,slug),
			KEY product_id (product_id)
		) {$charset};";

		$sql[] = "CREATE TABLE {$p}lessons (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			course_id BIGINT UNSIGNED NOT NULL,
			tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			title VARCHAR(191) NOT NULL,
			content LONGTEXT NULL,
			video_key VARCHAR(255) NOT NULL DEFAULT '',
			attachment_url VARCHAR(255) NOT NULL DEFAULT '',
			duration_minutes INT NOT NULL DEFAULT 0,
			sort_order INT NOT NULL DEFAULT 0,
			is_free_preview TINYINT(1) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY course_sort (course_id,sort_order)
		) {$charset};";

		$sql[] = "CREATE TABLE {$p}enrollments (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			course_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			progress_percent INT NOT NULL DEFAULT 0,
			completed_at DATETIME NULL,
			certificate_code VARCHAR(64) NOT NULL DEFAULT '',
			expires_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY course_user (course_id,user_id),
			KEY user_id (user_id)
		) {$charset};";

		$sql[] = "CREATE TABLE {$p}lesson_progress (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			enrollment_id BIGINT UNSIGNED NOT NULL,
			lesson_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			seconds_watched INT NOT NULL DEFAULT 0,
			completed TINYINT(1) NOT NULL DEFAULT 0,
			completed_at DATETIME NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY enrollment_lesson (enrollment_id,lesson_id)
		) {$charset};";

		$sql[] = "CREATE TABLE {$p}quizzes (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			course_id BIGINT UNSIGNED NOT NULL,
			lesson_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			title VARCHAR(191) NOT NULL,
			questions LONGTEXT NULL,
			pass_score INT NOT NULL DEFAULT 60,
			max_attempts INT NOT NULL DEFAULT 3,
			time_limit_minutes INT NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY course_id (course_id)
		) {$charset};";

		$sql[] = "CREATE TABLE {$p}quiz_attempts (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			quiz_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			answers LONGTEXT NULL,
			score INT NOT NULL DEFAULT 0,
			passed TINYINT(1) NOT NULL DEFAULT 0,
			started_at DATETIME NOT NULL,
			finished_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY quiz_user (quiz_id,user_id)
		) {$charset};";

		// ------------------------------------------------------------ payments & OTP
		$sql[] = "CREATE TABLE {$p}payments (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			gateway VARCHAR(32) NOT NULL,
			purpose VARCHAR(32) NOT NULL DEFAULT 'order',
			amount DECIMAL(18,4) NOT NULL DEFAULT 0,
			currency VARCHAR(8) NOT NULL DEFAULT 'IRT',
			authority VARCHAR(191) NOT NULL DEFAULT '',
			reference_id VARCHAR(191) NOT NULL DEFAULT '',
			card_pan VARCHAR(32) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'created',
			error_code VARCHAR(32) NOT NULL DEFAULT '',
			error_message VARCHAR(255) NOT NULL DEFAULT '',
			verified_at DATETIME NULL,
			meta LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY authority (authority),
			KEY order_id (order_id),
			KEY gateway_status (gateway,status)
		) {$charset};";

		$sql[] = "CREATE TABLE {$p}otp_codes (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			phone VARCHAR(32) NOT NULL,
			code_hash CHAR(64) NOT NULL,
			purpose VARCHAR(32) NOT NULL DEFAULT 'login',
			attempts INT NOT NULL DEFAULT 0,
			consumed_at DATETIME NULL,
			expires_at DATETIME NOT NULL,
			ip_hash CHAR(64) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY phone_purpose (phone,purpose),
			KEY expires_at (expires_at)
		) {$charset};";

		// ------------------------------------------------------------ marketplace
		$sql[] = "CREATE TABLE {$p}marketplace_links (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			product_id BIGINT UNSIGNED NOT NULL,
			channel VARCHAR(32) NOT NULL,
			external_id VARCHAR(128) NOT NULL DEFAULT '',
			last_synced_at DATETIME NULL,
			sync_status VARCHAR(20) NOT NULL DEFAULT 'pending',
			sync_message VARCHAR(255) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			UNIQUE KEY product_channel (product_id,channel),
			KEY tenant_channel (tenant_id,channel)
		) {$charset};";

		// ------------------------------------------------------------ Instagram
		$sql[] = "CREATE TABLE {$p}ig_accounts (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			username VARCHAR(64) NOT NULL,
			display_name VARCHAR(191) NOT NULL DEFAULT '',
			manus_project_id VARCHAR(128) NOT NULL DEFAULT '',
			manychat_page_id VARCHAR(128) NOT NULL DEFAULT '',
			manus_api_key TEXT NULL,
			manychat_api_key TEXT NULL,
			manus_webhook_token VARCHAR(64) NULL DEFAULT NULL,
			manychat_webhook_token VARCHAR(64) NULL DEFAULT NULL,
			credential_mode VARCHAR(16) NOT NULL DEFAULT 'own',
			trial_started_at DATETIME NULL,
			trial_expires_at DATETIME NULL,
			trial_tasks_used INT NOT NULL DEFAULT 0,
			timezone VARCHAR(64) NOT NULL DEFAULT 'Asia/Tehran',
			niche VARCHAR(191) NOT NULL DEFAULT '',
			brand_voice TEXT NULL,
			peak_hours VARCHAR(191) NOT NULL DEFAULT '',
			is_active TINYINT(1) NOT NULL DEFAULT 1,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY tenant_username (tenant_id,username),
			UNIQUE KEY manychat_webhook_token (manychat_webhook_token),
			UNIQUE KEY manus_webhook_token (manus_webhook_token)
		) {$charset};";

		$sql[] = "CREATE TABLE {$p}ig_content (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			account_id BIGINT UNSIGNED NOT NULL,
			kind VARCHAR(20) NOT NULL DEFAULT 'post',
			title VARCHAR(191) NOT NULL DEFAULT '',
			brief LONGTEXT NULL,
			caption LONGTEXT NULL,
			hashtags TEXT NULL,
			media LONGTEXT NULL,
			product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			funnel_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			provider VARCHAR(32) NOT NULL DEFAULT 'manus',
			provider_task_id VARCHAR(191) NOT NULL DEFAULT '',
			provider_status VARCHAR(32) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'draft',
			scheduled_for DATETIME NULL,
			published_at DATETIME NULL,
			permalink VARCHAR(255) NOT NULL DEFAULT '',
			last_error VARCHAR(500) NOT NULL DEFAULT '',
			retry_count INT NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY account_status (account_id,status),
			KEY scheduled_for (scheduled_for),
			KEY provider_task (provider_task_id)
		) {$charset};";

		$sql[] = "CREATE TABLE {$p}ig_insights (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			account_id BIGINT UNSIGNED NOT NULL,
			metric VARCHAR(64) NOT NULL,
			dimension VARCHAR(64) NOT NULL DEFAULT '',
			value DECIMAL(18,4) NOT NULL DEFAULT 0,
			captured_for DATE NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY account_metric_day (account_id,metric,dimension,captured_for)
		) {$charset};";

		$sql[] = "CREATE TABLE {$p}ig_funnels (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			account_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			name VARCHAR(191) NOT NULL,
			keyword VARCHAR(64) NOT NULL,
			match_mode VARCHAR(16) NOT NULL DEFAULT 'contains',
			post_id VARCHAR(128) NOT NULL DEFAULT '',
			reply_text LONGTEXT NULL,
			target_type VARCHAR(20) NOT NULL DEFAULT 'url',
			target_url VARCHAR(255) NOT NULL DEFAULT '',
			product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			coupon_code VARCHAR(64) NOT NULL DEFAULT '',
			manychat_flow_ns VARCHAR(128) NOT NULL DEFAULT '',
			manychat_tag VARCHAR(64) NOT NULL DEFAULT '',
			grant_wallet_credit DECIMAL(18,4) NOT NULL DEFAULT 0,
			per_user_limit INT NOT NULL DEFAULT 1,
			total_limit INT NOT NULL DEFAULT 0,
			hits BIGINT UNSIGNED NOT NULL DEFAULT 0,
			conversions BIGINT UNSIGNED NOT NULL DEFAULT 0,
			starts_at DATETIME NULL,
			ends_at DATETIME NULL,
			is_active TINYINT(1) NOT NULL DEFAULT 1,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY keyword_active (keyword,is_active),
			KEY tenant_id (tenant_id)
		) {$charset};";

		$sql[] = "CREATE TABLE {$p}ig_subscribers (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			manychat_subscriber_id VARCHAR(64) NOT NULL,
			ig_username VARCHAR(64) NOT NULL DEFAULT '',
			ig_user_id VARCHAR(64) NOT NULL DEFAULT '',
			first_name VARCHAR(191) NOT NULL DEFAULT '',
			last_name VARCHAR(191) NOT NULL DEFAULT '',
			phone VARCHAR(32) NOT NULL DEFAULT '',
			email VARCHAR(191) NOT NULL DEFAULT '',
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			custom_fields LONGTEXT NULL,
			tags TEXT NULL,
			last_interaction_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY subscriber (manychat_subscriber_id),
			KEY user_id (user_id),
			KEY ig_username (ig_username)
		) {$charset};";

		$sql[] = "CREATE TABLE {$p}ig_funnel_hits (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			funnel_id BIGINT UNSIGNED NOT NULL,
			subscriber_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			manychat_subscriber_id VARCHAR(64) NOT NULL DEFAULT '',
			ig_username VARCHAR(64) NOT NULL DEFAULT '',
			comment_id VARCHAR(128) NOT NULL DEFAULT '',
			comment_text TEXT NULL,
			post_id VARCHAR(128) NOT NULL DEFAULT '',
			event VARCHAR(32) NOT NULL DEFAULT 'comment',
			delivered TINYINT(1) NOT NULL DEFAULT 0,
			delivery_error VARCHAR(255) NOT NULL DEFAULT '',
			coupon_issued VARCHAR(64) NOT NULL DEFAULT '',
			occurred_at DATETIME NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY dedupe (funnel_id,comment_id),
			KEY funnel_id (funnel_id),
			KEY subscriber (manychat_subscriber_id)
		) {$charset};";

		// One row per product registered from the phone. This is the state machine behind the
		// "shoot a photo -> answer a few questions -> the post is live" flow: the app never
		// touches wp-admin, so every intermediate artefact (the graded photo, the cleaned-up
		// image, the edited version, the dictated description, the generated video) has to be
		// stored somewhere durable between REST calls.
		$sql[] = "CREATE TABLE {$p}ig_intake (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			account_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			status VARCHAR(32) NOT NULL DEFAULT 'uploaded',
			sku VARCHAR(32) NULL DEFAULT NULL,
			source_attachment_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			source_url VARCHAR(500) NOT NULL DEFAULT '',
			clean_attachment_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			clean_url VARCHAR(500) NOT NULL DEFAULT '',
			edited_attachment_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			edited_url VARCHAR(500) NOT NULL DEFAULT '',
			quality_score INT NOT NULL DEFAULT 0,
			quality_verdict VARCHAR(20) NOT NULL DEFAULT '',
			quality_reasons LONGTEXT NULL,
			attempt INT NOT NULL DEFAULT 1,
			raw_description LONGTEXT NULL,
			input_mode VARCHAR(16) NOT NULL DEFAULT 'text',
			transcript LONGTEXT NULL,
			specs LONGTEXT NULL,
			price DECIMAL(18,4) NOT NULL DEFAULT 0,
			sale_price DECIMAL(18,4) NOT NULL DEFAULT 0,
			stock INT NOT NULL DEFAULT 0,
			category_ids VARCHAR(255) NOT NULL DEFAULT '',
			copy_json LONGTEXT NULL,
			translations LONGTEXT NULL,
			product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			funnel_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			content_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			post_kind VARCHAR(16) NOT NULL DEFAULT '',
			video_prompt LONGTEXT NULL,
			video_url VARCHAR(500) NOT NULL DEFAULT '',
			video_approved TINYINT(1) NOT NULL DEFAULT 0,
			provider_task_id VARCHAR(191) NOT NULL DEFAULT '',
			provider_stage VARCHAR(32) NOT NULL DEFAULT '',
			last_error VARCHAR(500) NOT NULL DEFAULT '',
			retry_count INT NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY sku (sku),
			KEY tenant_status (tenant_id,status),
			KEY account_id (account_id),
			KEY provider_task (provider_task_id),
			KEY product_id (product_id)
		) {$charset};";

		// ------------------------------------------------------------ API
		$sql[] = "CREATE TABLE {$p}api_tokens (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			user_id BIGINT UNSIGNED NOT NULL,
			jti CHAR(64) NOT NULL,
			refresh_hash CHAR(64) NOT NULL DEFAULT '',
			device_id VARCHAR(128) NOT NULL DEFAULT '',
			issued_at DATETIME NOT NULL,
			expires_at DATETIME NOT NULL,
			refresh_expires_at DATETIME NULL,
			revoked_at DATETIME NULL,
			last_used_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY jti (jti),
			KEY user_id (user_id),
			KEY refresh_hash (refresh_hash)
		) {$charset};";

		$sql[] = "CREATE TABLE {$p}devices (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			device_id VARCHAR(128) NOT NULL,
			platform VARCHAR(16) NOT NULL DEFAULT '',
			fcm_token VARCHAR(255) NOT NULL DEFAULT '',
			app_version VARCHAR(32) NOT NULL DEFAULT '',
			locale VARCHAR(10) NOT NULL DEFAULT '',
			last_seen_at DATETIME NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY device (device_id),
			KEY user_id (user_id),
			KEY fcm_token (fcm_token(191))
		) {$charset};";

		$sql[] = "CREATE TABLE {$p}jobs (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			queue VARCHAR(64) NOT NULL DEFAULT 'default',
			handler VARCHAR(128) NOT NULL,
			payload LONGTEXT NULL,
			attempts INT NOT NULL DEFAULT 0,
			max_attempts INT NOT NULL DEFAULT 5,
			available_at DATETIME NOT NULL,
			reserved_at DATETIME NULL,
			completed_at DATETIME NULL,
			last_error VARCHAR(500) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY dispatch (queue,completed_at,available_at)
		) {$charset};";

		$sql[] = "CREATE TABLE {$p}logs (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			level VARCHAR(16) NOT NULL DEFAULT 'info',
			channel VARCHAR(64) NOT NULL DEFAULT '',
			message VARCHAR(1000) NOT NULL DEFAULT '',
			context LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY level_channel (level,channel),
			KEY created_at (created_at)
		) {$charset};";

		return $sql;
	}
}
