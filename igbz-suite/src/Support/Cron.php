<?php
namespace IGBZ\Suite\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Central scheduler. Individual modules attach their handlers to these hooks so a disabled
 * module simply has nothing listening.
 */
final class Cron {

	public const HOOK_FIVE_MINUTES = 'igbz_cron_five_minutes';
	public const HOOK_HOURLY       = 'igbz_cron_hourly';
	public const HOOK_DAILY        = 'igbz_cron_daily';

	/** @return array<string,string> hook => recurrence */
	public static function events(): array {
		return [
			self::HOOK_FIVE_MINUTES => 'igbz_five_minutes',
			self::HOOK_HOURLY       => 'hourly',
			self::HOOK_DAILY        => 'daily',
		];
	}

	public function register(): void {
		add_filter( 'cron_schedules', [ $this, 'add_schedules' ] ); // phpcs:ignore WordPress.WP.CronInterval
		add_action( self::HOOK_DAILY, [ $this, 'housekeeping' ] );
	}

	/**
	 * @param array<string,array{interval:int,display:string}> $schedules
	 * @return array<string,array{interval:int,display:string}>
	 */
	public function add_schedules( array $schedules ): array {
		$schedules['igbz_five_minutes'] = [
			'interval' => 300,
			'display'  => __( 'Every five minutes (IGBZ)', 'igbz-suite' ),
		];
		$schedules['igbz_fifteen_minutes'] = [
			'interval' => 900,
			'display'  => __( 'Every fifteen minutes (IGBZ)', 'igbz-suite' ),
		];
		return $schedules;
	}

	public function housekeeping(): void {
		$settings = igbz()->settings();
		igbz()->logger()->prune( $settings->int( 'log.retention_days', 30 ) );

		$db = igbz()->db();
		$db->query( 'DELETE FROM ' . $db->table( 'otp_codes' ) . ' WHERE expires_at < %s', gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) );
		$db->query( 'DELETE FROM ' . $db->table( 'api_tokens' ) . ' WHERE expires_at < %s AND ( refresh_expires_at IS NULL OR refresh_expires_at < %s )', gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ), gmdate( 'Y-m-d H:i:s' ) );
		$db->query( 'DELETE FROM ' . $db->table( 'jobs' ) . ' WHERE completed_at IS NOT NULL AND completed_at < %s', gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS ) );
	}
}
