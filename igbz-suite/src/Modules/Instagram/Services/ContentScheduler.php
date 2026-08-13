<?php
namespace IGBZ\Suite\Modules\Instagram\Services;

use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Drives the content pipeline on cron:
 *   draft      -> generate()       (Manus creative task)
 *   generating -> sync_generation() (absorb assets)
 *   ready      -> auto-schedule at the next peak-engagement hour
 *   scheduled  -> hand to Manus for publishing when due
 *
 * Peak hours are learned from ig_insights and can be overridden per account.
 */
final class ContentScheduler {

	private const BATCH = 20;

	public function __construct( private Db $db, private ManusService $manus, private Logger $logger ) {}

	/** Runs on igbz_cron_five_minutes. */
	public function tick(): void {
		if ( ! $this->manus->is_configured() ) {
			return;
		}
		$this->start_pending_generation();
		$this->sync_running_generation();
		$this->auto_schedule_ready();
		$this->publish_due();
	}

	private function start_pending_generation(): void {
		$rows = $this->db->results(
			'SELECT id FROM ' . $this->db->table( 'ig_content' ) . '
			 WHERE status = %s AND provider_task_id = %s AND retry_count < 3
			 ORDER BY id LIMIT %d',
			ManusService::STATUS_DRAFT,
			'',
			self::BATCH
		);
		foreach ( $rows as $row ) {
			if ( ! $this->should_autogenerate( (int) $row['id'] ) ) {
				continue;
			}
			$this->manus->generate( (int) $row['id'] );
		}
	}

	private function should_autogenerate( int $content_id ): bool {
		return (bool) apply_filters( 'igbz_ig_autogenerate', igbz()->settings()->bool( 'manus.auto_generate', true ), $content_id );
	}

	private function sync_running_generation(): void {
		$rows = $this->db->results(
			'SELECT id FROM ' . $this->db->table( 'ig_content' ) . '
			 WHERE status = %s AND provider_task_id <> %s ORDER BY id LIMIT %d',
			ManusService::STATUS_GENERATING,
			'',
			self::BATCH
		);
		foreach ( $rows as $row ) {
			$this->manus->sync_generation( (int) $row['id'] );
		}
	}

	private function auto_schedule_ready(): void {
		if ( ! igbz()->settings()->bool( 'manus.auto_schedule', true ) ) {
			return;
		}

		$rows = $this->db->results(
			'SELECT * FROM ' . $this->db->table( 'ig_content' ) . '
			 WHERE status = %s AND scheduled_for IS NULL ORDER BY id LIMIT %d',
			ManusService::STATUS_READY,
			self::BATCH
		);

		foreach ( $rows as $row ) {
			$account = $this->manus->account( (int) $row['account_id'] );
			if ( ! $account ) {
				continue;
			}
			$timestamp = $this->next_peak_slot( $account );
			$this->db->update(
				'ig_content',
				[
					'status'        => ManusService::STATUS_SCHEDULED,
					'scheduled_for' => gmdate( 'Y-m-d H:i:s', $timestamp ),
					'updated_at'    => current_time( 'mysql', true ),
				],
				[ 'id' => (int) $row['id'] ]
			);
			do_action( 'igbz_ig_content_scheduled', (int) $row['id'], $timestamp );
		}
	}

	private function publish_due(): void {
		$rows = $this->db->results(
			'SELECT * FROM ' . $this->db->table( 'ig_content' ) . '
			 WHERE status = %s AND scheduled_for IS NOT NULL AND scheduled_for <= %s
			 ORDER BY scheduled_for LIMIT %d',
			ManusService::STATUS_SCHEDULED,
			current_time( 'mysql', true ),
			self::BATCH
		);

		foreach ( $rows as $row ) {
			$result = $this->manus->publish( $row );
			if ( ! $result->success ) {
				$this->manus->fail( (int) $row['id'], $result->error );
				continue;
			}
			$this->db->update(
				'ig_content',
				[
					'status'           => ManusService::STATUS_PUBLISHING,
					'provider_task_id' => $result->external_id,
					'provider_status'  => ManusClient::STATUS_RUNNING,
					'updated_at'       => current_time( 'mysql', true ),
				],
				[ 'id' => (int) $row['id'] ]
			);
		}

		$this->confirm_publishing();
	}

	private function confirm_publishing(): void {
		$rows = $this->db->results(
			'SELECT * FROM ' . $this->db->table( 'ig_content' ) . ' WHERE status = %s ORDER BY id LIMIT %d',
			ManusService::STATUS_PUBLISHING,
			self::BATCH
		);

		foreach ( $rows as $row ) {
			$state = $this->manus->client()->task_state( (string) $row['provider_task_id'] );
			if ( ManusClient::STATUS_ERROR === $state['status'] ) {
				$this->manus->fail( (int) $row['id'], __( 'The Manus publishing task failed.', 'igbz-suite' ) );
				continue;
			}
			if ( ManusClient::STATUS_STOPPED !== $state['status'] ) {
				continue;
			}
			$output = $this->manus->parse_json_block( $state['text'] );
			$this->manus->mark_published( (int) $row['id'], (string) ( $output['permalink'] ?? '' ) );
		}
	}

	/**
	 * Next peak-engagement slot for an account, in UTC.
	 *
	 * Order of preference: explicit peak_hours on the account -> learned hours from ig_insights ->
	 * the global default list.
	 *
	 * @param array<string,mixed> $account
	 */
	public function next_peak_slot( array $account ): int {
		$hours = $this->peak_hours( $account );
		$tz    = $this->timezone( $account );
		$now   = new \DateTimeImmutable( 'now', $tz );

		$min_gap = igbz()->settings()->int( 'manus.min_gap_minutes', 90 );
		$busy    = $this->booked_slots( (int) $account['id'] );

		for ( $day = 0; $day < 14; $day++ ) {
			foreach ( $hours as $hour ) {
				[ $h, $m ] = array_pad( explode( ':', $hour ), 2, '0' );
				$slot      = $now->modify( sprintf( '+%d day', $day ) )->setTime( (int) $h, (int) $m );
				if ( $slot->getTimestamp() <= $now->getTimestamp() + 300 ) {
					continue;
				}
				if ( $this->slot_is_free( $slot->getTimestamp(), $busy, $min_gap * 60 ) ) {
					return $slot->getTimestamp();
				}
			}
		}

		return $now->getTimestamp() + HOUR_IN_SECONDS;
	}

	/**
	 * @param array<int,int> $busy
	 */
	private function slot_is_free( int $timestamp, array $busy, int $gap ): bool {
		foreach ( $busy as $taken ) {
			if ( abs( $taken - $timestamp ) < $gap ) {
				return false;
			}
		}
		return true;
	}

	/** @return array<int,int> */
	private function booked_slots( int $account_id ): array {
		$rows = $this->db->results(
			'SELECT scheduled_for FROM ' . $this->db->table( 'ig_content' ) . '
			 WHERE account_id = %d AND scheduled_for IS NOT NULL AND scheduled_for >= %s',
			$account_id,
			current_time( 'mysql', true )
		);
		return array_map( static fn( $row ) => (int) strtotime( (string) $row['scheduled_for'] . ' UTC' ), $rows );
	}

	/**
	 * @param array<string,mixed> $account
	 * @return array<int,string>
	 */
	public function peak_hours( array $account ): array {
		$explicit = array_filter( array_map( 'trim', explode( ',', (string) ( $account['peak_hours'] ?? '' ) ) ) );
		if ( $explicit ) {
			return array_values( $explicit );
		}

		$learned = $this->learned_peak_hours( (int) $account['id'] );
		if ( $learned ) {
			return $learned;
		}

		$default = igbz()->settings()->string( 'manus.default_peak_hours', '12:00,18:30,21:00' );
		return array_values( array_filter( array_map( 'trim', explode( ',', $default ) ) ) );
	}

	/** @return array<int,string> */
	private function learned_peak_hours( int $account_id ): array {
		$rows = $this->db->results(
			'SELECT dimension, SUM(value) AS total
			 FROM ' . $this->db->table( 'ig_insights' ) . '
			 WHERE account_id = %d AND metric = %s AND captured_for >= %s
			 GROUP BY dimension ORDER BY total DESC LIMIT 3',
			$account_id,
			'engagement_by_hour',
			gmdate( 'Y-m-d', time() - 30 * DAY_IN_SECONDS )
		);

		$hours = [];
		foreach ( $rows as $row ) {
			$dimension = (string) $row['dimension'];
			if ( '' !== $dimension ) {
				$hours[] = str_contains( $dimension, ':' ) ? $dimension : sprintf( '%02d:00', (int) $dimension );
			}
		}
		sort( $hours );
		return $hours;
	}

	/** @param array<string,mixed> $account */
	private function timezone( array $account ): \DateTimeZone {
		try {
			return new \DateTimeZone( (string) ( $account['timezone'] ?: wp_timezone_string() ) );
		} catch ( \Exception ) {
			return wp_timezone();
		}
	}

	/**
	 * Queue a content row from a plain brief. Used by the admin screen, the REST API and the
	 * "content plan" generator.
	 *
	 * @param array<string,mixed> $brief
	 */
	public function queue( int $account_id, string $kind, array $brief, int $tenant_id = 0 ): int {
		return $this->manus->save_content(
			[
				'tenant_id'  => $tenant_id,
				'account_id' => $account_id,
				'kind'       => $kind,
				'title'      => (string) ( $brief['subject'] ?? __( 'Untitled', 'igbz-suite' ) ),
				'brief'      => $brief,
				'product_id' => (int) ( $brief['product_id'] ?? 0 ),
				'funnel_id'  => (int) ( $brief['funnel_id'] ?? 0 ),
				'status'     => ManusService::STATUS_DRAFT,
			]
		);
	}
}
