<?php
namespace IGBZ\Suite\Modules\RestApi\Auth;

use IGBZ\Suite\Support\Crypto;

defined( 'ABSPATH' ) || exit;

/**
 * Minimal HS256 / RS256 JWT codec. Dependency-free on purpose: the plugin ships without Composer,
 * and firebase/php-jwt would be the only reason to pull a vendor tree into a WordPress plugin.
 *
 * HS256 is used for our own access tokens; RS256 signing is only needed to mint the Google OAuth
 * assertion for FCM.
 */
final class Jwt {

	public static function encode( array $claims, string $secret, string $key_id = '' ): string {
		$header = [ 'typ' => 'JWT', 'alg' => 'HS256' ];
		if ( '' !== $key_id ) {
			$header['kid'] = $key_id;
		}

		$segments = [
			self::b64_encode( (string) wp_json_encode( $header ) ),
			self::b64_encode( (string) wp_json_encode( $claims ) ),
		];

		$signing_input = implode( '.', $segments );
		$signature     = hash_hmac( 'sha256', $signing_input, $secret, true );

		return $signing_input . '.' . self::b64_encode( $signature );
	}

	/**
	 * Verify signature, `exp`, `nbf` and `iss`.
	 *
	 * @return array{ok:bool,error:string,claims:array<string,mixed>}
	 */
	public static function decode( string $token, string $secret, int $leeway = 30 ): array {
		$fail = static fn ( string $error ): array => [ 'ok' => false, 'error' => $error, 'claims' => [] ];

		$parts = explode( '.', $token );
		if ( 3 !== count( $parts ) ) {
			return $fail( 'malformed' );
		}

		[ $header_b64, $payload_b64, $signature_b64 ] = $parts;

		$header = json_decode( self::b64_decode( $header_b64 ), true );
		if ( ! is_array( $header ) || 'HS256' !== ( $header['alg'] ?? '' ) ) {
			// Refusing anything but HS256 also rejects the classic alg=none downgrade.
			return $fail( 'bad_algorithm' );
		}

		$expected = hash_hmac( 'sha256', $header_b64 . '.' . $payload_b64, $secret, true );
		if ( ! Crypto::hmac_equals( self::b64_encode( $expected ), $signature_b64 ) ) {
			return $fail( 'bad_signature' );
		}

		$claims = json_decode( self::b64_decode( $payload_b64 ), true );
		if ( ! is_array( $claims ) ) {
			return $fail( 'bad_payload' );
		}

		$now = time();
		if ( isset( $claims['exp'] ) && $now - $leeway >= (int) $claims['exp'] ) {
			return $fail( 'expired' );
		}
		if ( isset( $claims['nbf'] ) && $now + $leeway < (int) $claims['nbf'] ) {
			return $fail( 'not_yet_valid' );
		}
		if ( isset( $claims['iss'] ) && (string) $claims['iss'] !== self::issuer() ) {
			return $fail( 'bad_issuer' );
		}

		return [ 'ok' => true, 'error' => '', 'claims' => $claims ];
	}

	public static function issuer(): string {
		return (string) home_url( '/' );
	}

	/** RS256 assertion, used only for the Google service-account token exchange. */
	public static function encode_rs256( array $claims, string $private_key_pem, string $key_id = '' ): string {
		$header = [ 'typ' => 'JWT', 'alg' => 'RS256' ];
		if ( '' !== $key_id ) {
			$header['kid'] = $key_id;
		}

		$signing_input = self::b64_encode( (string) wp_json_encode( $header ) ) . '.' . self::b64_encode( (string) wp_json_encode( $claims ) );

		$key = openssl_pkey_get_private( $private_key_pem );
		if ( false === $key ) {
			throw new \RuntimeException( 'IGBZ Suite: the FCM service account private key could not be read.' );
		}

		$signature = '';
		if ( ! openssl_sign( $signing_input, $signature, $key, OPENSSL_ALGO_SHA256 ) ) {
			throw new \RuntimeException( 'IGBZ Suite: RS256 signing failed.' );
		}

		return $signing_input . '.' . self::b64_encode( $signature );
	}

	public static function b64_encode( string $raw ): string {
		return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' );
	}

	public static function b64_decode( string $encoded ): string {
		$padded = str_pad( strtr( $encoded, '-_', '+/' ), (int) ( 4 * ceil( strlen( $encoded ) / 4 ) ), '=' );
		return (string) base64_decode( $padded, true );
	}
}
