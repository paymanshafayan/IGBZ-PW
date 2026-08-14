<?php
declare( strict_types=1 );

use IGBZ\Suite\Support\Cron;

/**
 * `cron_schedules` is not an init-or-later filter. Anything calling wp_get_schedules() or
 * wp_schedule_event() during `plugins_loaded` fires it — Jetpack's Nonce_Handler does, and so
 * does this plugin's own activation path. Calling __() there forces a just-in-time textdomain
 * load, which WordPress 6.7+ flags with a `_load_textdomain_just_in_time` doing-it-wrong notice.
 *
 * This was observed live on WordPress 7.0.4 and fixed by deferring translation until `init`.
 */
final class CronScheduleTest extends TestCase {

	public function run(): void {
		$this->test_no_translation_before_init();
		$this->test_translation_after_init();
		$this->test_intervals_are_stable();
	}

	private function test_no_translation_before_init(): void {
		$GLOBALS['igbz_test_did_action'] = [];
		$GLOBALS['igbz_test_translated'] = [];

		$schedules = Cron::add_schedules( [] );

		$this->assert_same(
			[],
			$GLOBALS['igbz_test_translated'],
			'add_schedules() must not call __() before init (triggers _load_textdomain_just_in_time on WP 6.7+)'
		);
		$this->assert_same(
			'Every five minutes (IGBZ)',
			$schedules['igbz_five_minutes']['display'],
			'the untranslated English label is still returned before init'
		);
		$this->assert_same(
			'Every fifteen minutes (IGBZ)',
			$schedules['igbz_fifteen_minutes']['display'],
			'the untranslated English label is still returned before init'
		);
	}

	private function test_translation_after_init(): void {
		$GLOBALS['igbz_test_did_action'] = [ 'init' => 1 ];
		$GLOBALS['igbz_test_translated'] = [];

		$schedules = Cron::add_schedules( [] );

		$this->assert_same(
			2,
			count( $GLOBALS['igbz_test_translated'] ),
			'both labels are translated once init has fired'
		);
		$this->assert_same(
			'Every five minutes (IGBZ)',
			$schedules['igbz_five_minutes']['display'],
			'the label survives translation'
		);

		$GLOBALS['igbz_test_did_action'] = [];
		$GLOBALS['igbz_test_translated'] = [];
	}

	private function test_intervals_are_stable(): void {
		$schedules = Cron::add_schedules( [ 'hourly' => [ 'interval' => 3600, 'display' => 'Hourly' ] ] );

		$this->assert_same( 300, $schedules['igbz_five_minutes']['interval'], 'five-minute interval is 300s' );
		$this->assert_same( 900, $schedules['igbz_fifteen_minutes']['interval'], 'fifteen-minute interval is 900s' );
		$this->assert_true(
			isset( $schedules['hourly'] ),
			'existing recurrences from other plugins are preserved'
		);
		$this->assert_same(
			'igbz_five_minutes',
			Cron::events()[ Cron::HOOK_FIVE_MINUTES ],
			'the five-minute event uses the recurrence this filter registers'
		);
	}
}
