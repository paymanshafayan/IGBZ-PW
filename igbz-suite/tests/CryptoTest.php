<?php
declare( strict_types=1 );

use IGBZ\Suite\Support\Crypto;

final class CryptoTest extends TestCase {

	public function run(): void {
		$plain  = 'zarinpal-merchant-11111111-2222-3333-4444-555555555555';
		$cipher = Crypto::encrypt( $plain );

		$this->assert_contains( 'igbz1:', $cipher, 'ciphertext carries the version prefix' );
		$this->assert_false( str_contains( $cipher, $plain ), 'ciphertext does not leak the plaintext' );
		$this->assert_same( $plain, Crypto::decrypt( $cipher ), 'round trip returns the plaintext' );

		$this->assert_false(
			Crypto::encrypt( $plain ) === Crypto::encrypt( $plain ),
			'a random IV makes two encryptions of the same value differ'
		);

		// GCM must reject a tampered payload rather than returning garbage.
		$raw       = base64_decode( substr( $cipher, 6 ), true );
		$raw[ strlen( $raw ) - 1 ] = chr( ord( $raw[ strlen( $raw ) - 1 ] ) ^ 0x01 );
		$tampered  = 'igbz1:' . base64_encode( $raw );
		$this->assert_same( null, Crypto::decrypt( $tampered ), 'a flipped bit fails authentication' );

		$this->assert_same( 'legacy-plain', Crypto::decrypt( 'legacy-plain' ), 'unprefixed values pass through unchanged' );
		$this->assert_same( null, Crypto::decrypt( 'igbz1:not-base64!!' ), 'malformed payloads decrypt to null' );

		$code = Crypto::numeric_code( 6 );
		$this->assert_same( 6, strlen( $code ), 'numeric_code respects the digit count' );
		$this->assert_true( ctype_digit( $code ), 'numeric_code returns digits only' );
		$this->assert_same( 5, strlen( Crypto::numeric_code( 5 ) ), 'short codes are zero padded to length' );

		$this->assert_same( 64, strlen( Crypto::token( 32 ) ), 'token() returns hex of twice the byte count' );

		$this->assert_true( Crypto::hmac_equals( 'abc', 'abc' ), 'hmac_equals accepts identical strings' );
		$this->assert_false( Crypto::hmac_equals( 'abc', 'abd' ), 'hmac_equals rejects different strings' );
	}
}
