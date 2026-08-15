<?php
namespace IGBZ\Suite\Modules\Instagram\Services;

use IGBZ\Suite\Support\Http;
use IGBZ\Suite\Support\HttpResponse;
use IGBZ\Suite\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Thin client for the Manus Agent API (v2).
 *
 * Base URL:  https://api.manus.ai
 * Auth:      x-manus-api-key: <key>          (v1's API_KEY header is deprecated)
 * Endpoints used here:
 *   POST /v2/task.create        {message:{content}, agent_profile, project_id, locale, title, ...}
 *   GET  /v2/task.detail        ?task_id=
 *   GET  /v2/task.listMessages  ?task_id=&order=&limit=
 *   POST /v2/task.sendMessage   {task_id, message:{content}}
 *   POST /v2/task.stop          {task_id}
 *   GET  /v2/task.list          ?scope=&cursor=
 *   GET  /v2/skill.list, /v2/connector.list, /v2/project.list
 *
 * Tasks run ASYNCHRONOUSLY: task.create returns immediately with a task_id and the result must be
 * collected either by polling task.listMessages or from the task_stopped webhook.
 */
final class ManusClient {

	public const BASE = 'https://api.manus.ai';

	public const STATUS_RUNNING = 'running';
	public const STATUS_STOPPED = 'stopped';
	public const STATUS_WAITING = 'waiting';
	public const STATUS_ERROR   = 'error';

	/**
	 * The key this instance authenticates with. Empty means "unconfigured": every call short
	 * circuits instead of firing an unauthenticated request.
	 */
	private string $api_key = '';

	public function __construct( private Http $http, private Logger $logger ) {}

	/**
	 * A copy of this client bound to one account's key.
	 *
	 * Returning a clone rather than mutating $this keeps the container's shared instance stateless,
	 * so a cron tick that walks several tenants can never leak tenant A's key into tenant B's call.
	 */
	public function for_key( string $api_key ): self {
		$clone          = clone $this;
		$clone->api_key = trim( $api_key );
		return $clone;
	}

	public function is_configured(): bool {
		return '' !== $this->api_key;
	}

	/** @return array<string,string> */
	private function headers(): array {
		return [
			'x-manus-api-key' => $this->api_key,
			'Content-Type'    => 'application/json',
			'Accept'          => 'application/json',
		];
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>
	 */
	private function post( string $path, array $payload ): array {
		$response = $this->http->post(
			self::BASE . $path,
			[
				'json'    => $payload,
				'headers' => $this->headers(),
				'channel' => 'manus',
				'timeout' => 45,
			]
		);
		return $this->unwrap( $response, $path );
	}

	/**
	 * @param array<string,mixed> $query
	 * @return array<string,mixed>
	 */
	private function get( string $path, array $query = [] ): array {
		$response = $this->http->get(
			add_query_arg( $query, self::BASE . $path ),
			[
				'headers' => $this->headers(),
				'channel' => 'manus',
				'timeout' => 30,
			]
		);
		return $this->unwrap( $response, $path );
	}

	/** @return array<string,mixed> */
	private function unwrap( HttpResponse $response, string $path ): array {
		$body = $response->json();
		if ( ! $response->ok() ) {
			$this->logger->error(
				'manus',
				'API call failed',
				[ 'path' => $path, 'status' => $response->status, 'error' => $response->error_message(), 'body' => $body ]
			);
			return [ 'ok' => false, 'error' => $body['message'] ?? $response->error_message() ];
		}
		return $body + [ 'ok' => true ];
	}

	// ------------------------------------------------------------------ tasks

	/**
	 * Create a task.
	 *
	 * @param array<string,mixed> $options
	 * @return array{ok:bool,task_id:string,task_url:string,share_url:string,error:string}
	 */
	public function create_task( string $prompt, array $options = [] ): array {
		if ( ! $this->is_configured() ) {
			return [ 'ok' => false, 'task_id' => '', 'task_url' => '', 'share_url' => '', 'error' => __( 'Manus API key is not configured.', 'igbz-suite' ) ];
		}

		$payload = [
			'message'       => [
				'content' => $prompt,
			],
			'agent_profile' => (string) ( $options['agent_profile'] ?? igbz()->settings()->string( 'manus.agent_profile', 'manus-1.6' ) ),
			'locale'        => (string) ( $options['locale'] ?? igbz()->settings()->string( 'manus.locale', 'fa-IR' ) ),
		];

		if ( ! empty( $options['attachments'] ) ) {
			$content = [ [ 'type' => 'text', 'text' => $prompt ] ];
			foreach ( (array) $options['attachments'] as $url ) {
				$content[] = [ 'type' => 'file', 'url' => (string) $url ];
			}
			$payload['message']['content'] = $content;
		}
		if ( ! empty( $options['connectors'] ) ) {
			$payload['message']['connectors'] = array_values( (array) $options['connectors'] );
		}
		if ( ! empty( $options['enable_skills'] ) ) {
			$payload['message']['enable_skills'] = array_values( (array) $options['enable_skills'] );
		}
		if ( ! empty( $options['force_skills'] ) ) {
			$payload['message']['force_skills'] = array_values( (array) $options['force_skills'] );
		}
		if ( ! empty( $options['project_id'] ) ) {
			$payload['project_id'] = (string) $options['project_id'];
		}
		if ( ! empty( $options['title'] ) ) {
			$payload['title'] = mb_substr( (string) $options['title'], 0, 191 );
		}
		if ( isset( $options['structured_output_schema'] ) ) {
			$payload['structured_output_schema'] = $options['structured_output_schema'];
		}
		if ( isset( $options['hide_in_task_list'] ) ) {
			$payload['hide_in_task_list'] = (bool) $options['hide_in_task_list'];
		}
		$payload['interactive_mode'] = (bool) ( $options['interactive_mode'] ?? false );

		$body = $this->post( '/v2/task.create', $payload );

		return [
			'ok'        => ! empty( $body['ok'] ) && ! empty( $body['task_id'] ),
			'task_id'   => (string) ( $body['task_id'] ?? '' ),
			'task_url'  => (string) ( $body['task_url'] ?? '' ),
			'share_url' => (string) ( $body['share_url'] ?? '' ),
			'error'     => (string) ( $body['error'] ?? '' ),
		];
	}

	/** @return array<string,mixed> */
	public function task_detail( string $task_id ): array {
		return $this->get( '/v2/task.detail', [ 'task_id' => $task_id ] );
	}

	/** @return array<int,array<string,mixed>> */
	public function task_messages( string $task_id, int $limit = 50, string $order = 'desc' ): array {
		$body     = $this->get( '/v2/task.listMessages', [ 'task_id' => $task_id, 'limit' => $limit, 'order' => $order ] );
		$messages = $body['messages'] ?? ( $body['data'] ?? [] );
		return is_array( $messages ) ? $messages : [];
	}

	/** @return array<string,mixed> */
	public function send_message( string $task_id, string $content ): array {
		return $this->post( '/v2/task.sendMessage', [ 'task_id' => $task_id, 'message' => [ 'content' => $content ] ] );
	}

	public function stop_task( string $task_id ): bool {
		$body = $this->post( '/v2/task.stop', [ 'task_id' => $task_id ] );
		return ! empty( $body['ok'] );
	}

	public function delete_task( string $task_id ): bool {
		$body = $this->post( '/v2/task.delete', [ 'task_id' => $task_id ] );
		return ! empty( $body['ok'] );
	}

	/** @return array<string,mixed> */
	public function list_tasks( string $scope = 'all', string $cursor = '' ): array {
		return $this->get( '/v2/task.list', array_filter( [ 'scope' => $scope, 'cursor' => $cursor ] ) );
	}

	/** @return array<int,array<string,mixed>> */
	public function list_skills(): array {
		$body = $this->get( '/v2/skill.list' );
		return is_array( $body['skills'] ?? null ) ? $body['skills'] : [];
	}

	/** @return array<int,array<string,mixed>> */
	public function list_connectors(): array {
		$body = $this->get( '/v2/connector.list' );
		return is_array( $body['connectors'] ?? null ) ? $body['connectors'] : [];
	}

	/** @return array<int,array<string,mixed>> */
	public function list_projects(): array {
		$body = $this->get( '/v2/project.list' );
		return is_array( $body['projects'] ?? null ) ? $body['projects'] : [];
	}

	/**
	 * Normalised view of a task used by the scheduler.
	 *
	 * @return array{status:string,stop_reason:string,attachments:array<int,array<string,mixed>>,text:string}
	 */
	public function task_state( string $task_id ): array {
		$detail = $this->task_detail( $task_id );
		$task   = is_array( $detail['task_detail'] ?? null ) ? $detail['task_detail'] : $detail;

		$attachments = [];
		$text        = '';
		foreach ( $this->task_messages( $task_id, 30, 'desc' ) as $message ) {
			foreach ( (array) ( $message['attachments'] ?? [] ) as $attachment ) {
				$attachments[] = [
					'file_name'  => (string) ( $attachment['file_name'] ?? '' ),
					'url'        => (string) ( $attachment['url'] ?? '' ),
					'size_bytes' => (int) ( $attachment['size_bytes'] ?? 0 ),
				];
			}
			if ( '' === $text && ! empty( $message['content'] ) && is_string( $message['content'] ) ) {
				$text = (string) $message['content'];
			}
		}

		return [
			'status'      => (string) ( $task['status'] ?? self::STATUS_RUNNING ),
			'stop_reason' => (string) ( $task['stop_reason'] ?? '' ),
			'attachments' => $attachments,
			'text'        => $text,
		];
	}
}
