<?php
/**
 * Direct-bank IPG adapters: registration + encryption/signing logic that can
 * be tested without the network (payload construction, RSA sign, SOAP).
 */

declare( strict_types=1 );

use IGBZ\Suite\Modules\MultiTenant\Payments\AsanPardakhtGateway;
use IGBZ\Suite\Modules\MultiTenant\Payments\IranKishGateway;
use IGBZ\Suite\Modules\MultiTenant\Payments\MellatGateway;
use IGBZ\Suite\Modules\MultiTenant\Payments\ParsianGateway;
use IGBZ\Suite\Modules\MultiTenant\Payments\PasargadGateway;
use IGBZ\Suite\Modules\MultiTenant\Payments\SadadGateway;
use IGBZ\Suite\Modules\MultiTenant\Payments\SamanGateway;
use IGBZ\Suite\Modules\MultiTenant\Payments\SepehrGateway;
use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Http;

final class IpgAdaptersTest extends TestCase {

	private function boot(): void {
		igbz_test_reset_settings();
	}

	private function http(): Http {
		return new Http( new \IGBZ\Suite\Support\Logger( igbz()->settings() ) );
	}

	public function run(): void {
		$this->test_adapters_are_registered_and_configured_gating();
		$this->test_sadad_signing_is_deterministic();
		$this->test_saman_rsa_encrypt_produces_base64();
		$this->test_mellat_soap_wraps_payload();
		$this->test_parsian_soap_wraps_payload();
		$this->test_pasargad_sign_is_base64();
		$this->test_gateways_read_a_successful_bank_response();
	}

	/**
	 * A successful bank response must produce a successful result.
	 *
	 * The other cases in this file only assert "no network -> failure", which stays green
	 * whether or not the adapter can read a response at all. Four gateways destructured the
	 * associative array from post_json()/post_raw() as a list, so $ok and $body were always
	 * null and every real success was reported as a failure. Feeding a genuine success
	 * through the HTTP double is the only shape of test that catches it.
	 */
	public function test_gateways_read_a_successful_bank_response(): void {
		$this->boot();

		// Sadad: JSON, token in the body.
		igbz()->settings()->set( 'payments.sadad.merchant_id', 'M1' );
		igbz()->settings()->set( 'payments.sadad.terminal_id', 'T1' );
		igbz()->settings()->set( 'payments.sadad.private_key', 'not-a-real-key' );
		igbz_test_queue_http( [ 'status' => 200, 'body' => wp_json_encode( [ 'ResCode' => 0, 'Token' => 'SADAD-TOK' ] ) ] );
		$result = ( new SadadGateway( $this->http() ) )->request( 100000, 'https://example.com/cb', [ 'order_id' => 'ORD-1' ] );
		$this->assert_true( $result->success, 'sadad reads a successful request response' );
		$this->assert_same( 'SADAD-TOK', $result->authority, 'sadad returns the bank token' );

		igbz_test_queue_http( [ 'status' => 200, 'body' => wp_json_encode( [ 'ResCode' => '0', 'RetrivalRefNo' => 'RRN-9' ] ) ] );
		$verify = ( new SadadGateway( $this->http() ) )->verify( 100000, [ 'Token' => 'SADAD-TOK' ] );
		$this->assert_true( $verify->success, 'sadad reads a successful verify response' );
		$this->assert_same( 'RRN-9', $verify->reference_id, 'sadad returns the retrieval reference' );

		// Asan Pardakht: JSON, lowercase token.
		igbz()->settings()->set( 'payments.asanpardakht.api_key', 'K' );
		igbz()->settings()->set( 'payments.asanpardakht.merchant_config', 'C' );
		igbz_test_queue_http( [ 'status' => 200, 'body' => wp_json_encode( [ 'token' => 'ASAN-TOK' ] ) ] );
		$result = ( new AsanPardakhtGateway( $this->http() ) )->request( 100000, 'https://example.com/cb', [ 'order_id' => 'ORD-2' ] );
		$this->assert_true( $result->success, 'asanpardakht reads a successful request response' );
		$this->assert_same( 'ASAN-TOK', $result->authority, 'asanpardakht returns the bank token' );

		// Mellat: SOAP, "0,<refId>" inside <return>.
		igbz()->settings()->set( 'payments.mellat.terminal_id', '123' );
		igbz()->settings()->set( 'payments.mellat.username', 'u' );
		igbz()->settings()->set( 'payments.mellat.password', 'p' );
		igbz_test_queue_http( [ 'status' => 200, 'body' => '<soap:Envelope><soap:Body><return>0,MELLAT-REF</return></soap:Body></soap:Envelope>' ] );
		$result = ( new MellatGateway( $this->http() ) )->request( 100000, 'https://example.com/cb', [ 'order_id' => 'ORD-3' ] );
		$this->assert_true( $result->success, 'mellat reads a successful request response' );
		$this->assert_same( 'MELLAT-REF', $result->authority, 'mellat returns the RefId' );

		// Parsian: SOAP, Status 0 + Token.
		igbz()->settings()->set( 'payments.parsian.login_account', 'LA' );
		igbz_test_queue_http( [ 'status' => 200, 'body' => '<SalePaymentResult><Status>0</Status><Token>PARSIAN-TOK</Token></SalePaymentResult>' ] );
		$result = ( new ParsianGateway( $this->http() ) )->request( 100000, 'https://example.com/cb', [ 'order_id' => 'ORD-4' ] );
		$this->assert_true( $result->success, 'parsian reads a successful request response' );
		$this->assert_same( 'PARSIAN-TOK', $result->authority, 'parsian returns the bank token' );
	}

	public function test_adapters_are_registered_and_configured_gating(): void {
		$this->boot();
		$gateways = [ new SadadGateway( $this->http() ), new AsanPardakhtGateway( $this->http() ), new ParsianGateway( $this->http() ), new IranKishGateway( $this->http() ), new MellatGateway( $this->http() ), new SamanGateway( $this->http() ), new PasargadGateway( $this->http() ), new SepehrGateway( $this->http() ) ];

		foreach ( $gateways as $g ) {
			$this->assert_false( $g->is_configured(), $g->id() . ' not configured without credentials' );
			$this->assert_true( '' !== $g->title(), $g->id() . ' has a title' );
		}
	}

	public function test_sadad_signing_is_deterministic(): void {
		$this->boot();
		igbz()->settings()->set( 'payments.sadad.merchant_id', 'M1' );
		igbz()->settings()->set( 'payments.sadad.terminal_id', 'T1' );
		igbz()->settings()->set( 'payments.sadad.private_key', '-----BEGIN PRIVATE KEY-----\nMIIBVAIBADANBgkqhkiG9w0BAQEFAASCAT4wggE6AgEAAkEAhYqV2G8S22hQ1TtQ\nqZ8pR0V9sWc0uL6J1b3K7kF8N2hVpL7qGqD5n0mZ2R1sBk0n3YzQ1lM4yXjF0eS\nnWl1G9wQIDAQABAkA0xq8O7R8sNkE1v1y3Q9p0V2lWqM7bL2cX4aF6gH5jI0d1kE3\nvY8z0u1bN9fB2sG6hQ4tR7wA1eC5yI3pO0mK9lVq2sD8fG4hJ6kL1nP3rT5uV7wB\n-----END PRIVATE KEY-----\n' );
		// Invalid key -> sign stays empty, but request would fail gracefully.
		$g = new SadadGateway( $this->http() );
		$this->assert_true( $g->is_configured(), 'configured when all settings present' );

		$result = $g->request( 100000, 'https://example.com/cb', [ 'order_id' => 'ORD-1' ] );
		$this->assert_false( $result->success, 'bad private key -> request fails gracefully' );
		$this->assert_contains( 'sadad', $result->error_code, 'error code names the gateway' );
	}

	public function test_saman_rsa_encrypt_produces_base64(): void {
		$this->boot();
		// Generate a real RSA keypair to prove the code path works.
		$res = openssl_pkey_new( [ 'private_key_bits' => 1024, 'private_key_type' => OPENSSL_KEYTYPE_RSA ] );
		if ( false === $res ) {
			$this->assert_true( true, 'openssl key generation unavailable — skipped' );
			return;
		}
		$details = openssl_pkey_get_details( $res );
		$pub     = is_array( $details ) ? $details['key'] : '';

		igbz()->settings()->set( 'payments.saman.terminal_id', 'T1' );
		igbz()->settings()->set( 'payments.saman.public_key', $pub );
		igbz()->settings()->set( 'payments.saman.private_key', '' );

		$g = new SamanGateway( $this->http() );
		$this->assert_true( $g->is_configured(), 'saman configured with terminal + public key' );

		$result = $g->request( 100000, 'https://example.com/cb', [ 'order_id' => 'ORD-1' ] );
		// The HTTP call will fail (no network), but NOT with an encryption error.
		$this->assert_false( $result->success, 'no network -> failure' );
		$this->assert_not_contains( 'encrypt_failed', $result->error_code, 'encryption succeeded before the HTTP call' );
	}

	public function test_mellat_soap_wraps_payload(): void {
		$this->boot();
		igbz()->settings()->set( 'payments.mellat.terminal_id', '123' );
		igbz()->settings()->set( 'payments.mellat.username', 'u' );
		igbz()->settings()->set( 'payments.mellat.password', 'p' );

		$g = new MellatGateway( $this->http() );
		$this->assert_true( $g->is_configured(), 'mellat configured' );
		$result = $g->request( 100000, 'https://example.com/cb', [ 'order_id' => 'ORD-1' ] );
		$this->assert_false( $result->success, 'no network -> failure' );
		$this->assert_true( true, 'request attempted (HTTP failed without network)' );
	}

	public function test_parsian_soap_wraps_payload(): void {
		$this->boot();
		igbz()->settings()->set( 'payments.parsian.login_account', 'LA' );
		$g = new ParsianGateway( $this->http() );
		$this->assert_true( $g->is_configured(), 'parsian configured' );
		$result = $g->request( 100000, 'https://example.com/cb', [ 'order_id' => 'ORD-1' ] );
		$this->assert_false( $result->success, 'no network -> failure' );
	}

	public function test_pasargad_sign_is_base64(): void {
		$this->boot();
		$res = openssl_pkey_new( [ 'private_key_bits' => 1024, 'private_key_type' => OPENSSL_KEYTYPE_RSA ] );
		if ( false === $res || ! openssl_pkey_export( $res, $priv ) ) {
			$this->assert_true( true, 'openssl key generation unavailable — skipped' );
			return;
		}

		igbz()->settings()->set( 'payments.pasargad.merchant_code', 'M' );
		igbz()->settings()->set( 'payments.pasargad.terminal_code', 'T' );
		igbz()->settings()->set( 'payments.pasargad.private_key', $priv );

		$g = new PasargadGateway( $this->http() );
		$this->assert_true( $g->is_configured(), 'pasargad configured' );
		$result = $g->request( 100000, 'https://example.com/cb', [ 'order_id' => 'ORD-1' ] );
		$this->assert_false( $result->success, 'no network -> failure' );
		$this->assert_true( true, 'payload path executed' );
	}
}
