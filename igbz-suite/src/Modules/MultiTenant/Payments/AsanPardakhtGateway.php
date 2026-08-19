<?php
namespace IGBZ\Suite\Modules\MultiTenant\Payments;

defined( 'ABSPATH' ) || exit;

use IGBZ\Suite\Support\Http;

/**
 * Asan Pardakht direct gateway — REST.
 *
 *   Request: POST https://asan.shaparak.ir/api/v3/... (token)
 *   Verify:  POST .../verify with token.
 * Configurable endpoints so the exact Asan API version can be pointed at.
 */
final class AsanPardakhtGateway extends AbstractIpgGateway {

	private const REQUEST_URL = 'https://asan.shaparak.ir/api/v3/Request';
	private const VERIFY_URL  = 'https://asan.shaparak.ir/api/v3/Verify';
	private const START_URL   = 'https://asan.shaparak.ir';

	public function __construct( Http $http ) {
		parent::__construct( $http, 'payments.asanpardakht' );
	}

	public function id(): string {
		return 'asanpardakht';
	}

	public function title(): string {
		return __( 'Asan Pardakht', 'igbz-suite' );
	}

	public function required_settings(): array {
		return [ 'payments.asanpardakht.api_key', 'payments.asanpardakht.merchant_config' ];
	}

	public function request( float $amount, string $callback_url, array $context = [] ): PaymentRequestResult {
		$response = $this->post_json(
			self::REQUEST_URL,
			[
				'merchantConfigurationId' => $this->cfg( 'merchant_config' ),
				'localInvoiceId'          => (string) ( $context['order_id'] ?? 'ORD-' . time() ),
				'amountInRial'            => Money::to_rial( $amount ),
				'callbackURL'             => $callback_url,
				'paymenter'               => $this->cfg( 'api_key' ),
			]
		);

		$ok   = (bool) $response['ok'];
		$body = (array) $response['body'];

		$token = (string) ( $body['token'] ?? '' );
		if ( $ok && '' !== $token ) {
			return PaymentRequestResult::ok( $token, self::START_URL . '/api/v3/StartPay/' . rawurlencode( $token ) );
		}

		return PaymentRequestResult::failure( (string) ( $body['errorCode'] ?? 'asan_failed' ), (string) ( $body['errorMessage'] ?? __( 'Asan Pardakht rejected the request.', 'igbz-suite' ) ) );
	}

	public function verify( float $amount, array $callback_params ): PaymentVerifyResult {
		$token = (string) ( $callback_params['token'] ?? '' );
		if ( '' === $token ) {
			return PaymentVerifyResult::failure( 'missing_token', __( 'Asan Pardakht did not return a token.', 'igbz-suite' ) );
		}

		$response = $this->post_json( self::VERIFY_URL, [ 'token' => $token ] );
		$ok       = (bool) $response['ok'];
		$body     = (array) $response['body'];

		if ( $ok && (int) ( $body['resultCode'] ?? 1 ) === 0 ) {
			return PaymentVerifyResult::ok( (string) ( $body['paymentId'] ?? $token ), (string) ( $body['cardNumber'] ?? '' ), 0.0 );
		}

		return PaymentVerifyResult::failure( (string) ( $body['resultCode'] ?? 'verify_failed' ), (string) ( $body['message'] ?? __( 'Asan Pardakht could not verify.', 'igbz-suite' ) ) );
	}
}
