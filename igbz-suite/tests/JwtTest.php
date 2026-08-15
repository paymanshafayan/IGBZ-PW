<?php
declare( strict_types=1 );

use IGBZ\Suite\Modules\RestApi\Auth\Jwt;

final class JwtTest extends TestCase {

	public function run(): void {
		$secret = str_repeat( 'k', 64 );
		$claims = [
			'iss'    => Jwt::issuer(),
			'sub'    => 42,
			'jti'    => str_repeat( 'a', 64 ),
			'iat'    => time(),
			'nbf'    => time(),
			'exp'    => time() + 3600,
			'tenant' => 7,
			'device' => 'device-abc',
			'scope'  => [ 'account', 'tenant' ],
		];

		$token   = Jwt::encode( $claims, $secret );
		$decoded = Jwt::decode( $token, $secret );

		$this->assert_same( 3, count( explode( '.', $token ) ), 'a JWT has three segments' );
		$this->assert_true( $decoded['ok'], 'a freshly minted token verifies' );
		$this->assert_same( 42, (int) $decoded['claims']['sub'], 'claims survive the round trip' );
		$this->assert_same( 7, (int) $decoded['claims']['tenant'], 'the tenant claim survives' );

		$this->assert_false( Jwt::decode( $token, 'another-secret' )['ok'], 'a wrong secret is rejected' );
		$this->assert_same( 'bad_signature', Jwt::decode( $token, 'another-secret' )['error'], 'the wrong secret reports bad_signature' );

		$this->assert_same( 'malformed', Jwt::decode( 'not.a.jwt.at.all', $secret )['error'], 'a malformed token is rejected' );
		$this->assert_same( 'malformed', Jwt::decode( 'abc', $secret )['error'], 'a single segment is rejected' );

		// alg=none downgrade: re-sign the payload with an unsigned header.
		[ , $payload ] = explode( '.', $token );
		$none          = Jwt::b64_encode( (string) wp_json_encode( [ 'typ' => 'JWT', 'alg' => 'none' ] ) ) . '.' . $payload . '.';
		$this->assert_same( 'bad_algorithm', Jwt::decode( $none, $secret )['error'], 'alg=none is rejected' );

		$hs512 = Jwt::b64_encode( (string) wp_json_encode( [ 'typ' => 'JWT', 'alg' => 'HS512' ] ) ) . '.' . $payload . '.' . Jwt::b64_encode( 'x' );
		$this->assert_same( 'bad_algorithm', Jwt::decode( $hs512, $secret )['error'], 'a swapped algorithm is rejected' );

		$expired = Jwt::encode( [ 'iss' => Jwt::issuer(), 'exp' => time() - 120 ], $secret );
		$this->assert_same( 'expired', Jwt::decode( $expired, $secret )['error'], 'an expired token is rejected' );
		$this->assert_true( Jwt::decode( Jwt::encode( [ 'iss' => Jwt::issuer(), 'exp' => time() - 5 ], $secret ), $secret )['ok'], 'the 30s leeway tolerates small clock skew' );

		$future = Jwt::encode( [ 'iss' => Jwt::issuer(), 'nbf' => time() + 600 ], $secret );
		$this->assert_same( 'not_yet_valid', Jwt::decode( $future, $secret )['error'], 'a not-yet-valid token is rejected' );

		$foreign = Jwt::encode( [ 'iss' => 'https://evil.test/', 'exp' => time() + 60 ], $secret );
		$this->assert_same( 'bad_issuer', Jwt::decode( $foreign, $secret )['error'], 'a foreign issuer is rejected' );

		// base64url: no padding, no + or / characters.
		$encoded = Jwt::b64_encode( "\xfb\xff\xfe binary" );
		$this->assert_false( str_contains( $encoded, '=' ), 'base64url output is unpadded' );
		$this->assert_false( str_contains( $encoded, '+' ) || str_contains( $encoded, '/' ), 'base64url avoids + and /' );
		$this->assert_same( "\xfb\xff\xfe binary", Jwt::b64_decode( $encoded ), 'base64url round trips binary data' );
	}
}
