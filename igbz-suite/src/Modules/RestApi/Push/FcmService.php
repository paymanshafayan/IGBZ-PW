<?php
namespace IGBZ\Suite\Modules\RestApi\Push;

use IGBZ\Suite\Support\Http;
use IGBZ\Suite\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Firebase Cloud Messaging over the HTTP v1 API.
 *
 * Port of `Nop.Plugin.Api/Services/FcmService.cs`, with the problems the audit found fixed:
 *  - the original reported "sent" as soon as the HTTP call was made, even for tokens FCM rejected;
 *    here every token is accounted for individually as sent / invalid / failed;
 *  - UNREGISTERED and INVALID_ARGUMENT responses now clear the stored token instead of leaving it
 *    to be retried forever;
 *  - the payload is built for both `notification` and `data`, so the app can deep link.
 */
final class FcmService {

	private const ENDPOINT = 'https://fcm.googleapis.com/v1/projects/%s/messages:send';

	public function __construct(
		private Http $http,
		private GoogleAuth $auth,
		private DeviceRepository $devices,
		private Logger $logger
	) {}

	public function is_enabled(): bool {
		return igbz()->settings()->bool( 'api.push_enabled', false ) && $this->auth->is_configured();
	}

	/**
	 * Send one notification to a set of devices.
	 *
	 * @param array{title:string,body:string,type?:string,link?:string,image?:string,data?:array<string,scalar>} $message
	 * @param array{tenant_id?:int,user_ids?:int[],device_ids?:int[],platform?:string,limit?:int}                  $audience
	 *
	 * @return array{ok:bool,sent:int,invalid:int,failed:int,total:int,error:string}
	 */
	public function send( array $message, array $audience = [] ): array {
		$empty = [ 'ok' => false, 'sent' => 0, 'invalid' => 0, 'failed' => 0, 'total' => 0, 'error' => '' ];

		if ( ! igbz()->settings()->bool( 'api.push_enabled', false ) ) {
			$empty['error'] = __( 'Push notifications are disabled in the settings.', 'igbz-suite' );
			return $empty;
		}

		$token = $this->auth->access_token();
		if ( ! $token['ok'] ) {
			$empty['error'] = $token['error'];
			return $empty;
		}

		$targets = $this->devices->targets( $audience );
		if ( ! $targets ) {
			$empty['ok']    = true;
			$empty['error'] = __( 'No registered device matched this audience.', 'igbz-suite' );
			return $empty;
		}

		$url     = sprintf( self::ENDPOINT, rawurlencode( $this->auth->project_id() ) );
		$sent    = 0;
		$invalid = 0;
		$failed  = 0;

		foreach ( $targets as $target ) {
			$result = $this->send_one( $url, (string) $token['token'], (string) $target['fcm_token'], $message );

			if ( 'sent' === $result ) {
				$sent++;
				continue;
			}

			if ( 'invalid' === $result ) {
				$invalid++;
				$this->devices->invalidate_token( (int) $target['id'] );
				continue;
			}

			$failed++;
		}

		$this->logger->info(
			'push',
			'Push batch finished',
			[ 'sent' => $sent, 'invalid' => $invalid, 'failed' => $failed, 'total' => count( $targets ) ]
		);

		return [
			'ok'      => $sent > 0,
			'sent'    => $sent,
			'invalid' => $invalid,
			'failed'  => $failed,
			'total'   => count( $targets ),
			'error'   => $sent > 0 ? '' : __( 'FCM accepted none of the tokens.', 'igbz-suite' ),
		];
	}

	/** @return 'sent'|'invalid'|'failed' */
	private function send_one( string $url, string $access_token, string $registration_token, array $message ): string {
		$response = $this->http->post(
			$url,
			[
				'channel' => 'push',
				'timeout' => 15,
				'headers' => [ 'Authorization' => 'Bearer ' . $access_token ],
				'json'    => [ 'message' => $this->build_message( $registration_token, $message ) ],
			]
		);

		if ( $response->ok() ) {
			return 'sent';
		}

		$body   = $response->json();
		$status = (string) ( $body['error']['status'] ?? '' );

		// UNREGISTERED = the app was uninstalled; INVALID_ARGUMENT on a token means it is malformed.
		if ( in_array( $response->status, [ 400, 404 ], true )
			&& in_array( $status, [ 'UNREGISTERED', 'NOT_FOUND', 'INVALID_ARGUMENT' ], true ) ) {
			return 'invalid';
		}

		if ( 401 === $response->status ) {
			// The cached OAuth token went stale mid-batch; drop it so the next call re-mints one.
			$this->auth->flush();
		}

		$this->logger->warning(
			'push',
			'FCM rejected a token',
			[ 'status' => $response->status, 'fcm_status' => $status, 'message' => (string) ( $body['error']['message'] ?? '' ) ]
		);

		return 'failed';
	}

	/** @return array<string,mixed> */
	private function build_message( string $registration_token, array $message ): array {
		$title = (string) ( $message['title'] ?? '' );
		$body  = (string) ( $message['body'] ?? '' );
		$image = (string) ( $message['image'] ?? '' );

		// Every value in the FCM data block has to be a string.
		$data = [
			'type' => (string) ( $message['type'] ?? 'general' ),
			'link' => (string) ( $message['link'] ?? '' ),
		];
		foreach ( (array) ( $message['data'] ?? [] ) as $key => $value ) {
			$data[ sanitize_key( (string) $key ) ] = is_scalar( $value ) ? (string) $value : wp_json_encode( $value );
		}

		$payload = [
			'token'        => $registration_token,
			'notification' => array_filter(
				[
					'title' => $title,
					'body'  => $body,
					'image' => $image,
				]
			),
			'data'         => $data,
			'android'      => [
				'priority'     => 'high',
				'notification' => array_filter(
					[
						'sound'        => 'default',
						'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
						'channel_id'   => igbz()->settings()->string( 'api.push_channel_id', 'igbz_default' ),
					]
				),
			],
			'apns'         => [
				'headers' => [ 'apns-priority' => '10' ],
				'payload' => [
					'aps' => [
						'sound'             => 'default',
						'content-available' => 1,
					],
				],
			],
		];

		/**
		 * Filter the FCM message before it is sent.
		 *
		 * @param array<string,mixed> $payload
		 * @param array<string,mixed> $message
		 */
		return (array) apply_filters( 'igbz_fcm_message', $payload, $message );
	}

	/**
	 * A single-recipient convenience wrapper used by order and instalment notifications.
	 *
	 * @param array<string,mixed> $message
	 * @return array{ok:bool,sent:int,invalid:int,failed:int,total:int,error:string}
	 */
	public function send_to_user( int $user_id, array $message ): array {
		return $this->send( $message, [ 'user_ids' => [ $user_id ] ] );
	}
}
