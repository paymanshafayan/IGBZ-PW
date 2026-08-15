<?php
namespace IGBZ\Suite\Modules\Instagram\Webhooks;

use IGBZ\Suite\Modules\Instagram\Services\AccountCredentials;
use IGBZ\Suite\Modules\Instagram\Services\IntakeWorker;
use IGBZ\Suite\Modules\Instagram\Services\ManusService;
use IGBZ\Suite\Modules\Instagram\Services\ProductIntakeService;
use IGBZ\Suite\Support\Crypto;
use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Inbound endpoint for Manus task events, so we do not have to wait for the five-minute poll.
 *
 *   POST /wp-json/igbz/v1/manus/task?token=<per-account token>
 *
 * Events: task_created, task_progress, task_stopped. The interesting one is task_stopped, which
 * carries stop_reason and the attachments produced by the agent.
 */
final class ManusWebhook {

	public const NAMESPACE = 'igbz/v1';

	/** Account resolved from the request token, set by authorize(). */
	private ?array $account = null;

	public function __construct(
		private Db $db,
		private ManusService $manus,
		private Logger $logger,
		private AccountCredentials $credentials,
		private ProductIntakeService $intake,
		private IntakeWorker $worker
	) {}

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/manus/task',
			[
				'methods'             => 'POST',
				'permission_callback' => [ $this, 'authorize' ],
				'callback'            => [ $this, 'handle' ],
			]
		);
	}

	/**
	 * Per-account token auth: the token names the account, which names the tenant. A signature
	 * header, when Manus sends one, is verified against that same per-account token.
	 */
	public function authorize( \WP_REST_Request $request ): bool {
		$this->account = null;

		$given = (string) $request->get_param( 'token' );
		if ( '' === $given ) {
			$given = (string) $request->get_header( 'x_igbz_token' );
		}
		$given = trim( $given );
		if ( '' === $given ) {
			return false;
		}

		$account = $this->credentials->account_by_webhook_token( $given, AccountCredentials::SERVICE_MANUS );
		if ( ! $account ) {
			$this->logger->warning( 'manus', 'Webhook rejected: unknown token' );
			return false;
		}

		$signature = (string) $request->get_header( 'x_manus_signature' );
		if ( '' !== $signature
			&& ! Crypto::hmac_equals( Crypto::hmac( (string) $request->get_body(), $given ), $signature ) ) {
			$this->logger->warning( 'manus', 'Webhook rejected: bad signature', [ 'account_id' => (int) $account['id'] ] );
			return false;
		}

		$this->account = $account;
		return true;
	}

	public function handle( \WP_REST_Request $request ): \WP_REST_Response {
		$body = $request->get_json_params();
		$body = is_array( $body ) ? $body : [];

		$event   = (string) ( $body['event'] ?? $body['type'] ?? '' );
		$task_id = (string) ( $body['task_id'] ?? $body['data']['task_id'] ?? '' );

		if ( '' === $task_id ) {
			return new \WP_REST_Response( [ 'ok' => false, 'error' => 'missing_task_id' ], 400 );
		}

		$this->logger->debug( 'manus', 'Webhook received', [ 'event' => $event, 'task_id' => $task_id ] );

		// A task id belongs either to a content row or to a product registration. Registrations
		// are checked first because they are the shorter-lived of the two and a shopkeeper is
		// usually watching a spinner while one runs.
		$intake = $this->intake->by_task( $task_id );
		if ( $intake ) {
			return $this->handle_intake( $intake, $event, $body );
		}

		$content = $this->db->row(
			'SELECT * FROM ' . $this->db->table( 'ig_content' ) . ' WHERE provider_task_id = %s ORDER BY id DESC LIMIT 1',
			$task_id
		);

		if ( ! $content ) {
			do_action( 'igbz_manus_webhook', $event, $body );
			return new \WP_REST_Response( [ 'ok' => true, 'handled' => false ], 200 );
		}

		// The task must belong to the account that owns the token, otherwise a tenant could drive
		// another tenant's content row by guessing or replaying its task id.
		if ( (int) $content['account_id'] !== (int) ( $this->account['id'] ?? 0 ) ) {
			$this->logger->warning(
				'manus',
				'Webhook rejected: task belongs to another account',
				[ 'task_id' => $task_id, 'account_id' => (int) ( $this->account['id'] ?? 0 ) ]
			);
			return new \WP_REST_Response( [ 'ok' => false, 'error' => 'forbidden' ], 403 );
		}

		$content_id = (int) $content['id'];

		if ( 'task_stopped' !== $event ) {
			$this->db->update(
				'ig_content',
				[ 'provider_status' => $event, 'updated_at' => current_time( 'mysql', true ) ],
				[ 'id' => $content_id ]
			);
			return new \WP_REST_Response( [ 'ok' => true, 'handled' => true ], 200 );
		}

		$stop_reason = (string) ( $body['stop_reason'] ?? $body['data']['stop_reason'] ?? 'finish' );

		if ( 'finish' !== $stop_reason ) {
			$this->manus->fail( $content_id, sprintf( 'Manus stopped: %s', $stop_reason ) );
			return new \WP_REST_Response( [ 'ok' => true, 'handled' => true ], 200 );
		}

		// The webhook body may omit attachments; ask the API for the authoritative state.
		$state = $this->manus->client_for( $this->account )->task_state( $task_id );

		if ( ManusService::STATUS_PUBLISHING === (string) $content['status'] ) {
			$output = $this->manus->parse_json_block( $state['text'] );
			$this->manus->mark_published( $content_id, (string) ( $output['permalink'] ?? '' ) );
		} else {
			$this->manus->absorb_result( $content_id, $state );
		}

		return new \WP_REST_Response( [ 'ok' => true, 'handled' => true ], 200 );
	}

	/**
	 * A task belonging to a product registration.
	 *
	 * Every step of the registration flow is one Manus task, so this is the fast path for all of
	 * them: it hands the finished state to the same IntakeWorker the cron sweep uses, which means
	 * webhook and poll cannot drift apart in behaviour.
	 *
	 * @param array<string,mixed> $intake
	 * @param array<string,mixed> $body
	 */
	private function handle_intake( array $intake, string $event, array $body ): \WP_REST_Response {
		$intake_id = (int) $intake['id'];

		// Same tenancy check as the content path: a token names one account, and a task claimed
		// by that token must belong to it.
		$account = $this->intake->account_for( $intake );
		if ( ! $account || (int) $account['id'] !== (int) ( $this->account['id'] ?? 0 ) ) {
			$this->logger->warning(
				'manus',
				'Webhook rejected: the registration belongs to another account',
				[ 'intake_id' => $intake_id, 'account_id' => (int) ( $this->account['id'] ?? 0 ) ]
			);
			return new \WP_REST_Response( [ 'ok' => false, 'error' => 'forbidden' ], 403 );
		}

		if ( 'task_stopped' !== $event ) {
			// Progress events carry nothing to absorb; the row already knows it is waiting.
			return new \WP_REST_Response( [ 'ok' => true, 'handled' => true ], 200 );
		}

		$stop_reason = (string) ( $body['stop_reason'] ?? $body['data']['stop_reason'] ?? 'finish' );

		if ( 'finish' !== $stop_reason ) {
			$this->intake->fail(
				$intake_id,
				sprintf( /* translators: %s: reason the task stopped */ __( 'The assistant stopped: %s', 'igbz-suite' ), $stop_reason )
			);
			return new \WP_REST_Response( [ 'ok' => true, 'handled' => true ], 200 );
		}

		$state = $this->manus->client_for( $account )->task_state( (string) $intake['provider_task_id'] );

		$this->worker->settle( $intake, $state );

		return new \WP_REST_Response( [ 'ok' => true, 'handled' => true ], 200 );
	}
}
