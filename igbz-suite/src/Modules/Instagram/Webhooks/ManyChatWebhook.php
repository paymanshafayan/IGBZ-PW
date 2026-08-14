<?php
namespace IGBZ\Suite\Modules\Instagram\Webhooks;

use IGBZ\Suite\Modules\Instagram\Gateways\ManyChatClient;
use IGBZ\Suite\Modules\Instagram\Services\AccountCredentials;
use IGBZ\Suite\Modules\Instagram\Services\FunnelService;
use IGBZ\Suite\Modules\Instagram\Services\SubscriberService;
use IGBZ\Suite\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Inbound endpoint for ManyChat.
 *
 * ManyChat has no generic webhook-subscription API: real-time delivery is done with the
 * "External Request" action inside a Flow. The flow POSTs a JSON body we define (comment text,
 * subscriber id, post id, timestamp) to:
 *
 *   POST /wp-json/igbz/v1/manychat/comment?token=<per-account token>
 *
 * ManyChat gives up after roughly ten seconds, so this controller does only local work and
 * answers with a Dynamic Content envelope plus a field map; every outbound API call is deferred
 * to a background event (FunnelService::followup()).
 */
final class ManyChatWebhook {

	public const NAMESPACE = 'igbz/v1';

	/**
	 * The account resolved from the request token, set by authorize() and consumed by the
	 * callbacks. Tenancy is taken from here and never from the request body.
	 */
	private ?array $account = null;

	public function __construct(
		private FunnelService $funnels,
		private SubscriberService $subscribers,
		private Logger $logger,
		private AccountCredentials $credentials
	) {}

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
		add_action( 'igbz_ig_funnel_followup', [ $this->funnels, 'followup' ], 10, 1 );
	}

	public function register_routes(): void {
		$args = [
			'permission_callback' => [ $this, 'authorize' ],
			'methods'             => 'POST',
		];

		register_rest_route( self::NAMESPACE, '/manychat/comment', $args + [ 'callback' => [ $this, 'handle_comment' ] ] );
		register_rest_route( self::NAMESPACE, '/manychat/event', $args + [ 'callback' => [ $this, 'handle_event' ] ] );
		register_rest_route( self::NAMESPACE, '/manychat/subscriber', $args + [ 'callback' => [ $this, 'handle_subscriber' ] ] );
		register_rest_route(
			self::NAMESPACE,
			'/manychat/ping',
			[
				'methods'             => 'GET',
				'permission_callback' => [ $this, 'authorize' ],
				'callback'            => [ $this, 'handle_ping' ],
			]
		);
	}

	/**
	 * Per-account token auth. The token may arrive as ?token=, X-IGBZ-Token or a Bearer header, so
	 * the External Request can be configured either way.
	 *
	 * The token identifies the account, and the account supplies the tenant. Previously the token
	 * was global and tenant_id/account_id were read from the POST body, so any tenant holding the
	 * shared token could fire another tenant's funnel and spend their coupons and wallet credit.
	 */
	public function authorize( \WP_REST_Request $request ): bool {
		$this->account = null;

		$given = $this->token_from( $request );
		if ( '' === $given ) {
			$this->logger->warning( 'manychat', 'Webhook rejected: no token supplied' );
			return false;
		}

		$account = $this->credentials->account_by_webhook_token( $given, AccountCredentials::SERVICE_MANYCHAT );
		if ( ! $account ) {
			$this->logger->warning( 'manychat', 'Webhook rejected: unknown token' );
			return false;
		}
		if ( ! (int) ( $account['is_active'] ?? 0 ) ) {
			$this->logger->warning( 'manychat', 'Webhook rejected: account is inactive', [ 'account_id' => (int) $account['id'] ] );
			return false;
		}

		$this->account = $account;
		return true;
	}

	private function token_from( \WP_REST_Request $request ): string {
		$given = (string) $request->get_param( 'token' );
		if ( '' === $given ) {
			$given = (string) $request->get_header( 'x_igbz_token' );
		}
		if ( '' === $given ) {
			$given = trim( str_ireplace( 'Bearer', '', (string) $request->get_header( 'authorization' ) ) );
		}
		return trim( $given );
	}

	public function handle_ping(): \WP_REST_Response {
		return new \WP_REST_Response(
			[
				'ok'      => true,
				'plugin'  => 'igbz-suite',
				'version' => defined( 'IGBZ_VERSION' ) ? IGBZ_VERSION : '',
				'time'    => current_time( 'mysql', true ),
			],
			200
		);
	}

	/**
	 * "Comment the word X and I'll DM you the link".
	 *
	 * Expected body (all optional except the comment text and subscriber id):
	 *   { "subscriber_id": "...", "comment_text": "...", "comment_id": "...", "post_id": "...",
	 *     "timestamp": 1723459200, "ig_username": "...", "ig_user_id": "...",
	 *     "first_name": "...", "last_name": "..." }
	 *
	 * The account and tenant come from the token, not the body.
	 */
	public function handle_comment( \WP_REST_Request $request ): \WP_REST_Response {
		$event  = $this->normalize_request( $request );
		$result = $this->funnels->handle_event_async( $event );

		if ( ! $result['matched'] ) {
			return $this->respond(
				[
					'matched' => false,
					'version' => 'v2',
					'content' => [ 'messages' => [], 'actions' => [], 'quick_replies' => [] ],
				]
			);
		}

		$funnel = $result['funnel'] ?? [];

		if ( $result['duplicate'] ) {
			$text = igbz()->settings()->string(
				'manychat.duplicate_message',
				__( 'You have already received this link.', 'igbz-suite' )
			);
			$text = strtr( $text, [ '{link}' => $result['link'] ] );

			return $this->respond(
				ManyChatClient::dynamic_content( [ [ 'type' => 'text', 'text' => $text ] ] ) + [
					'matched'    => true,
					'duplicate'  => true,
					'igbz_link'  => $result['link'],
					'igbz_funnel' => (string) ( $funnel['name'] ?? '' ),
				]
			);
		}

		$messages = [
			[
				'type'    => 'text',
				'text'    => mb_substr( $result['text'], 0, 2000 ),
				'buttons' => [
					[
						'type'    => 'url',
						'caption' => mb_substr( igbz()->settings()->string( 'manychat.button_label', __( 'Open the link', 'igbz-suite' ) ), 0, 20 ),
						'url'     => $result['link'],
					],
				],
			],
		];

		// Returned at the top level too, so the flow can map them into custom fields.
		return $this->respond(
			ManyChatClient::dynamic_content( $messages ) + [
				'matched'      => true,
				'duplicate'    => false,
				'igbz_link'    => $result['link'],
				'igbz_coupon'  => $result['coupon'],
				'igbz_message' => $result['text'],
				'igbz_funnel'  => (string) ( $funnel['name'] ?? '' ),
				'igbz_hit_id'  => $result['hit_id'],
			]
		);
	}

	/**
	 * Generic Instagram interaction events (story reply, DM keyword, mention...). Same funnel
	 * matching, different event label.
	 */
	public function handle_event( \WP_REST_Request $request ): \WP_REST_Response {
		$event          = $this->normalize_request( $request );
		$event['event'] = sanitize_key( (string) ( $request->get_param( 'event' ) ?: 'interaction' ) );

		$result = $this->funnels->handle_event_async( $event );

		do_action( 'igbz_manychat_event', $event, $result );

		return $this->respond(
			[
				'matched'     => $result['matched'],
				'duplicate'   => $result['duplicate'],
				'igbz_link'   => $result['link'],
				'igbz_coupon' => $result['coupon'],
			]
		);
	}

	/**
	 * Store or refresh a subscriber profile pushed by a flow. Optionally links it to a WordPress
	 * user and returns the customer's wallet balance and order count so the flow can use them.
	 */
	public function handle_subscriber( \WP_REST_Request $request ): \WP_REST_Response {
		$subscriber_id = (string) $request->get_param( 'subscriber_id' );
		if ( '' === $subscriber_id ) {
			return $this->respond( [ 'ok' => false, 'error' => 'missing_subscriber_id' ], 400 );
		}

		$row_id = $this->subscribers->upsert(
			[
				'manychat_subscriber_id' => $subscriber_id,
				'ig_username'            => (string) $request->get_param( 'ig_username' ),
				'ig_user_id'             => (string) $request->get_param( 'ig_user_id' ),
				'first_name'             => (string) $request->get_param( 'first_name' ),
				'last_name'              => (string) $request->get_param( 'last_name' ),
				'phone'                  => (string) $request->get_param( 'phone' ),
				'email'                  => (string) $request->get_param( 'email' ),
			],
			(int) ( $this->account['tenant_id'] ?? 0 )
		);

		$user_id = $this->subscribers->maybe_link_user( $row_id );

		$balance = 0.0;
		$orders  = 0;
		if ( $user_id > 0 && igbz()->has( 'wallet' ) ) {
			$balance = igbz()->get( 'wallet' )->balance( $user_id );
			$orders  = function_exists( 'wc_get_customer_order_count' ) ? (int) wc_get_customer_order_count( $user_id ) : 0;
		}

		return $this->respond(
			[
				'ok'                 => true,
				'igbz_user_id'       => $user_id,
				'igbz_wallet'        => $balance,
				'igbz_order_count'   => $orders,
				'igbz_subscriber_id' => $row_id,
			]
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function normalize_request( \WP_REST_Request $request ): array {
		$body = $request->get_json_params();
		$body = is_array( $body ) ? $body : $request->get_params();

		$timestamp = $body['timestamp'] ?? 0;
		if ( is_string( $timestamp ) && ! ctype_digit( $timestamp ) ) {
			$timestamp = strtotime( $timestamp ) ?: 0;
		}

		return [
			'subscriber_id' => sanitize_text_field( (string) ( $body['subscriber_id'] ?? $body['id'] ?? '' ) ),
			'comment_text'  => (string) ( $body['comment_text'] ?? $body['text'] ?? $body['last_input_text'] ?? '' ),
			'comment_id'    => sanitize_text_field( (string) ( $body['comment_id'] ?? '' ) ),
			'post_id'       => sanitize_text_field( (string) ( $body['post_id'] ?? $body['media_id'] ?? '' ) ),
			'ig_username'   => sanitize_text_field( (string) ( $body['ig_username'] ?? $body['username'] ?? '' ) ),
			'ig_user_id'    => sanitize_text_field( (string) ( $body['ig_user_id'] ?? '' ) ),
			'first_name'    => sanitize_text_field( (string) ( $body['first_name'] ?? '' ) ),
			'last_name'     => sanitize_text_field( (string) ( $body['last_name'] ?? '' ) ),
			'timestamp'     => (int) $timestamp,
			'event'         => 'comment',
			// Authoritative, from the token lookup. Any tenant_id/account_id in the body is ignored.
			'tenant_id'     => (int) ( $this->account['tenant_id'] ?? 0 ),
			'account_id'    => (int) ( $this->account['id'] ?? 0 ),
		];
	}

	/** @param array<string,mixed> $payload */
	private function respond( array $payload, int $status = 200 ): \WP_REST_Response {
		$response = new \WP_REST_Response( $payload, $status );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}
}
