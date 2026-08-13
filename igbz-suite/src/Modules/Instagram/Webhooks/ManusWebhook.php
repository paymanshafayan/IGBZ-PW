<?php
namespace IGBZ\Suite\Modules\Instagram\Webhooks;

use IGBZ\Suite\Modules\Instagram\Services\ManusService;
use IGBZ\Suite\Support\Crypto;
use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Inbound endpoint for Manus task events, so we do not have to wait for the five-minute poll.
 *
 *   POST /wp-json/igbz/v1/manus/task?token=<shared secret>
 *
 * Events: task_created, task_progress, task_stopped. The interesting one is task_stopped, which
 * carries stop_reason and the attachments produced by the agent.
 */
final class ManusWebhook {

	public const NAMESPACE = 'igbz/v1';

	public function __construct( private Db $db, private ManusService $manus, private Logger $logger ) {}

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

	public function authorize( \WP_REST_Request $request ): bool {
		$expected = igbz()->settings()->string( 'manus.webhook_token', '' );
		if ( '' === $expected ) {
			return false;
		}

		$given = (string) $request->get_param( 'token' );
		if ( '' === $given ) {
			$given = (string) $request->get_header( 'x_igbz_token' );
		}

		$signature = (string) $request->get_header( 'x_manus_signature' );
		if ( '' !== $signature ) {
			return Crypto::hmac_equals( Crypto::hmac( (string) $request->get_body(), $expected ), $signature );
		}

		return Crypto::hmac_equals( $expected, $given );
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

		$content = $this->db->row(
			'SELECT * FROM ' . $this->db->table( 'ig_content' ) . ' WHERE provider_task_id = %s ORDER BY id DESC LIMIT 1',
			$task_id
		);

		if ( ! $content ) {
			do_action( 'igbz_manus_webhook', $event, $body );
			return new \WP_REST_Response( [ 'ok' => true, 'handled' => false ], 200 );
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
		$state = $this->manus->client()->task_state( $task_id );

		if ( ManusService::STATUS_PUBLISHING === (string) $content['status'] ) {
			$output = $this->manus->parse_json_block( $state['text'] );
			$this->manus->mark_published( $content_id, (string) ( $output['permalink'] ?? '' ) );
		} else {
			$this->manus->absorb_result( $content_id, $state );
		}

		return new \WP_REST_Response( [ 'ok' => true, 'handled' => true ], 200 );
	}
}
