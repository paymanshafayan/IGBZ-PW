<?php
namespace IGBZ\Suite\Modules\RestApi\Push;

use IGBZ\Suite\Modules\RestApi\Auth\Jwt;
use IGBZ\Suite\Support\Http;
use IGBZ\Suite\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Service-account OAuth 2.0 for the FCM HTTP v1 API.
 *
 * FCM legacy (the `key=AAAA...` server key) was shut down in June 2024, so the only way to send is
 * an OAuth bearer token minted from the service account JSON. We sign the RS256 assertion
 * ourselves rather than pulling in google/apiclient, and cache the resulting token in a transient
 * until shortly before it expires.
 */
final class GoogleAuth {

	private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
	private const SCOPE     = 'https://www.googleapis.com/auth/firebase.messaging';
	private const CACHE_KEY = 'igbz_fcm_access_token';

	public function __construct( private Http $http, private Logger $logger ) {}

	/**
	 * The decoded service account JSON, or an empty array when nothing is configured.
	 *
	 * @return array<string,mixed>
	 */
	public function service_account(): array {
		$raw = igbz()->settings()->string( 'api.fcm_service_account', '' );
		if ( '' === $raw ) {
			return [];
		}

		$decoded = json_decode( $raw, true );

		return is_array( $decoded ) ? $decoded : [];
	}

	public function project_id(): string {
		$explicit = igbz()->settings()->string( 'api.fcm_project_id', '' );
		if ( '' !== $explicit ) {
			return $explicit;
		}

		return (string) ( $this->service_account()['project_id'] ?? '' );
	}

	public function is_configured(): bool {
		$account = $this->service_account();

		return '' !== $this->project_id()
			&& ! empty( $account['client_email'] )
			&& ! empty( $account['private_key'] );
	}

	/** @return array{ok:bool,token:string,error:string} */
	public function access_token( bool $fresh = false ): array {
		if ( ! $fresh ) {
			$cached = get_transient( self::CACHE_KEY );
			if ( is_string( $cached ) && '' !== $cached ) {
				return [ 'ok' => true, 'token' => $cached, 'error' => '' ];
			}
		}

		$account = $this->service_account();
		if ( ! $this->is_configured() ) {
			return [ 'ok' => false, 'token' => '', 'error' => __( 'The FCM service account is missing or incomplete.', 'igbz-suite' ) ];
		}

		$now = time();

		try {
			$assertion = Jwt::encode_rs256(
				[
					'iss'   => (string) $account['client_email'],
					'scope' => self::SCOPE,
					'aud'   => self::TOKEN_URL,
					'iat'   => $now,
					'exp'   => $now + 3600,
				],
				(string) $account['private_key'],
				(string) ( $account['private_key_id'] ?? '' )
			);
		} catch ( \RuntimeException $e ) {
			$this->logger->error( 'push', 'Could not sign the Google assertion', [ 'error' => $e->getMessage() ] );

			return [ 'ok' => false, 'token' => '', 'error' => $e->getMessage() ];
		}

		$response = $this->http->post(
			self::TOKEN_URL,
			[
				'channel' => 'push',
				'headers' => [ 'Content-Type' => 'application/x-www-form-urlencoded' ],
				'body'    => http_build_query(
					[
						'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
						'assertion'  => $assertion,
					]
				),
			]
		);

		$body = $response->json();

		if ( ! $response->ok() || empty( $body['access_token'] ) ) {
			$error = (string) ( $body['error_description'] ?? $response->error_message() );
			$this->logger->error( 'push', 'Google token exchange failed', [ 'error' => $error ] );

			return [ 'ok' => false, 'token' => '', 'error' => $error ];
		}

		$token   = (string) $body['access_token'];
		$expires = (int) ( $body['expires_in'] ?? 3600 );

		// Expire our copy a minute early so an in-flight batch never uses a token that just died.
		set_transient( self::CACHE_KEY, $token, max( 60, $expires - 60 ) );

		return [ 'ok' => true, 'token' => $token, 'error' => '' ];
	}

	public function flush(): void {
		delete_transient( self::CACHE_KEY );
	}
}
