<?php
namespace IGBZ\Suite\Modules\MultiTenant\Otp;

use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Http;

defined( 'ABSPATH' ) || exit;

/**
 * Shahkar national-id verification. Only active once the senior admin has
 * stored legal.shahkar_api_key; otherwise the payment-time national-id
 * check stays locked (admins then must accept the legal digital waiver).
 */
final class NationalIdVerifier {

	public function __construct(
		private Db $db,
		private Http $http
	) {}

	public function is_available(): bool {
		return '' !== igbz()->settings()->string( 'legal.shahkar_api_key' );
	}

	/** @return array{ok:bool,ref:string,error:string} */
	public function verify( int $user_id, string $phone, string $national_id ): array {
		if ( ! $this->is_available() ) {
			return [ 'ok' => false, 'ref' => '', 'error' => __( 'National-id verification is not activated (no Shahkar key).', 'igbz-suite' ) ];
		}

		$base = rtrim( igbz()->settings()->string( 'legal.shahkar_base_url' ), '/' );
		$response = $this->http->post(
			$base . '/v1/identity/match',
			[
				'json'    => [ 'phone' => $phone, 'national_id' => $national_id ],
				'headers' => [ 'Authorization' => 'Bearer ' . igbz()->settings()->string( 'legal.shahkar_api_key' ), 'Accept' => 'application/json' ],
				'channel' => 'otp',
				'timeout' => 25,
			]
		);
		$body = $response->json();
		$ok   = $response->ok() && (bool) ( $body['matched'] ?? $body['status'] ?? false );

		$this->db->insert(
			'ig_nid_verifications',
			[
				'tenant_id'        => (int) igbz()->tenancy()->id(),
				'user_id'          => $user_id,
				'national_id_hash' => hash( 'sha256', $national_id ),
				'status'           => $ok ? 'matched' : 'mismatch',
				'ref'              => (string) ( $body['ref'] ?? '' ),
				'created_at'       => current_time( 'mysql', true ),
			]
		);

		return $ok
			? [ 'ok' => true, 'ref' => (string) ( $body['ref'] ?? '' ), 'error' => '' ]
			: [ 'ok' => false, 'ref' => '', 'error' => __( 'National id and phone do not match.', 'igbz-suite' ) ];
	}
}
