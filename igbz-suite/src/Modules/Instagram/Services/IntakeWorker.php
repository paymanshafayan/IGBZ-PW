<?php
namespace IGBZ\Suite\Modules\Instagram\Services;

use IGBZ\Suite\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Advances registrations whose Manus task finished without the webhook telling us.
 *
 * The webhook is the fast path and this is the guarantee. A webhook can be lost for reasons the
 * plugin has no control over — a firewall, a plugin conflict on the REST route, the site being
 * down for the ninety seconds the callback was attempted — and the failure mode without a sweep is
 * the worst kind: a shopkeeper who photographed a product and is still staring at a spinner an
 * hour later, with no error to report and nothing to retry.
 *
 * So every five minutes each parked row is asked directly: is your task finished? The same
 * absorb_* methods run either way, so a task settled here is indistinguishable from one settled by
 * the webhook, and a webhook arriving after the sweep has already handled the row finds nothing
 * left to do (provider_task_id has been cleared).
 */
final class IntakeWorker {

	/** Rows per tick. Each one costs two Manus API calls, so the batch stays modest. */
	private const BATCH = 15;

	/** How long a row may sit on a task before it is called failed, in seconds. */
	private const TASK_TIMEOUT = 3600;

	public function __construct(
		private ProductIntakeService $intake,
		private ProductPublisher $publisher,
		private ManusService $manus,
		private Logger $logger
	) {}

	/** Runs on igbz_cron_five_minutes. */
	public function tick(): void {
		foreach ( $this->intake->awaiting_tasks( self::BATCH ) as $row ) {
			try {
				$this->advance( $row );
			} catch ( \Throwable $e ) {
				// One malformed row must not stop the queue behind it.
				$this->logger->error(
					'intake',
					'Error while advancing a registration',
					[ 'intake_id' => (int) $row['id'], 'error' => $e->getMessage() ]
				);
			}
		}
	}

	/** @param array<string,mixed> $row */
	public function advance( array $row ): void {
		$id      = (int) $row['id'];
		$task_id = (string) $row['provider_task_id'];

		$account = $this->intake->account_for( $row );
		if ( ! $account ) {
			$this->intake->fail( $id, __( 'The Instagram account this registration belongs to has gone.', 'igbz-suite' ) );
			return;
		}

		$state = $this->manus->client_for( $account )->task_state( $task_id );

		if ( ManusClient::STATUS_ERROR === $state['status'] ) {
			$this->intake->fail( $id, __( 'The assistant ended this step with an error.', 'igbz-suite' ) );
			return;
		}

		if ( ManusClient::STATUS_STOPPED !== $state['status'] ) {
			$this->maybe_time_out( $row );
			return;
		}

		if ( 'ask' === $state['stop_reason'] ) {
			$this->intake->fail( $id, __( 'The assistant is waiting for an answer it cannot get automatically.', 'igbz-suite' ) );
			return;
		}

		$this->settle( $row, $state );
	}

	/**
	 * Route a finished task to the handler for the stage it belongs to.
	 *
	 * @param array<string,mixed>                                                                            $row
	 * @param array{status:string,stop_reason:string,attachments:array<int,array<string,mixed>>,text:string} $state
	 */
	public function settle( array $row, array $state ): void {
		$id = (int) $row['id'];

		switch ( (string) $row['provider_stage'] ) {
			case ProductIntakeService::STAGE_QUALITY:
				$this->intake->absorb_quality( $id, $this->manus->parse_json_block( $state['text'] ) );
				break;

			case ProductIntakeService::STAGE_IMAGE:
				$this->intake->absorb_image( $id, $state['attachments'] );
				break;

			case ProductIntakeService::STAGE_TRANSCRIPT:
				$parsed = $this->manus->parse_json_block( $state['text'] );
				$this->intake->absorb_transcript( $id, (string) ( $parsed['text'] ?? $state['text'] ) );
				break;

			case ProductIntakeService::STAGE_COPY:
				$this->intake->absorb_copy( $id, $this->manus->parse_json_block( $state['text'] ) );
				$this->maybe_create_product( $id );
				break;

			case ProductIntakeService::STAGE_VIDEO:
				$this->intake->absorb_video( $id, $state['attachments'] );
				break;

			case ProductIntakeService::STAGE_POST:
				$this->finish_post( $id, $state );
				break;

			default:
				$this->logger->warning(
					'intake',
					'A finished task belongs to no known stage',
					[ 'intake_id' => $id, 'stage' => (string) $row['provider_stage'] ]
				);
				$this->intake->update( $id, [ 'provider_task_id' => '', 'provider_stage' => '' ] );
		}
	}

	/**
	 * Create the product as soon as the copy lands.
	 *
	 * Done here rather than waiting for the app to call /publish again so that a seller who put
	 * their phone down mid-registration still comes back to a finished product. The app's second
	 * call then finds product_id already set and simply reports it.
	 */
	private function maybe_create_product( int $id ): void {
		$row = $this->intake->get( $id );

		if ( ! $row || (int) $row['product_id'] > 0 || ProductIntakeService::STATUS_FAILED === (string) $row['status'] ) {
			return;
		}
		if ( ! $this->intake->copy( $row ) ) {
			return;
		}

		$result = $this->publisher->publish( $row );

		if ( $result['ok'] ) {
			// Step 8: the product and its code go to the Instagram assistant, which asks the
			// seller whether the post should be an image or a video.
			$this->intake->choose_kind( $id, '' );
		}
	}

	/**
	 * @param array{status:string,stop_reason:string,attachments:array<int,array<string,mixed>>,text:string} $state
	 */
	private function finish_post( int $id, array $state ): void {
		$composed = $this->intake->absorb_post( $id, $state );
		if ( ! $composed ) {
			return;
		}

		$row = $this->intake->get( $id );
		if ( ! $row ) {
			return;
		}

		$content_id = $this->publisher->queue_post( $row, $composed );
		if ( 0 === $content_id ) {
			$this->intake->fail( $id, __( 'The finished post could not be queued.', 'igbz-suite' ) );
			return;
		}

		// Auto-publishing is the store's choice. With it off the post waits in the content queue
		// for a human to approve it, which is exactly what the setting has always promised.
		if ( igbz()->settings()->bool( 'instagram.autopublish', true ) ) {
			$this->publisher->hand_off( (array) $this->intake->get( $id ) );
		}
	}

	/**
	 * Give up on a task that has been running implausibly long.
	 *
	 * Without this a task that Manus never finishes leaves the row polling forever, two API calls
	 * at a time, every five minutes. An hour is far longer than any of these steps legitimately
	 * takes, so crossing it means something is wrong that waiting will not fix.
	 *
	 * @param array<string,mixed> $row
	 */
	private function maybe_time_out( array $row ): void {
		$updated = strtotime( (string) $row['updated_at'] . ' UTC' );

		if ( $updated && ( time() - $updated ) > self::TASK_TIMEOUT ) {
			$this->intake->fail(
				(int) $row['id'],
				__( 'This step took too long and was abandoned. Please try again.', 'igbz-suite' )
			);
		}
	}
}
