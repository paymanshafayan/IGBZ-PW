<?php
namespace IGBZ\Suite\Modules\MultiTenant\Payments;

defined( 'ABSPATH' ) || exit;

use IGBZ\Suite\Support\Http;

/**
 * Sadad (Bank Melli) direct gateway — REST + RSA signature.
 *
 *   Request: POST https://sadad.shaparak.ir/api/v0/Request/PaymentRequest
 *     { TerminalId, MerchantId, Amount, OrderId, LocalDateTime, ReturnUrl,
 *       SignData = RSA_SHA1(merchantId#terminalId#orderId#amount#localDateTime#returnUrl) }
 *   Response: { Token } -> redirect https://sadad.shaparak.ir/api/v0/StartPay/{Token}
 *   Verify:  POST /api/v0/Advice/Verify { Token } -> { ResCode:0, ... }
 */
final class SadadGateway extends AbstractIpgGateway {

	private const REQUEST_URL = 'https://sadad.shaparak.ir/api/v0/Request/PaymentRequest';
	private const VERIFY_URL  = 'https://sadad.shaparak.ir/api/v0/Advice/Verify';
	private const START_URL   = 'https://sadad.shaparak.ir/api/v0/StartPay/';

	public function __construct( Http $http ) {
		parent::__construct( $http, 'payments.sadad' );
	}

	public function id(): string {
		return 'sadad';
	}

	public function title(): string {
		return __( 'Sadad (Melli)', 'igbz-suite' );
	}

	public function required_settings(): array {
		return [ 'payments.sadad.merchant_id', 'payments.sadad.terminal_id', 'payments.sadad.private_key' ];
	}

	public function request( float $amount, string $callback_url, array $context = [] ): PaymentRequestResult {
		$order_id = (string) ( $context['order_id'] ?? 'ORD-' . time() );
		$date     = gmdate( 'm/d/Y H:i:s' );
		$amount   = Money::to_rial( $amount );

		$merchant = $this->cfg( 'merchant_id' );
		$terminal = $this->cfg( 'terminal_id' );
		$key      = $this->cfg( 'private_key' );

		$plain = implode( '#', [ $merchant, $terminal, $order_id, $amount, $date, $callback_url ] );
		$sign  = '';
		$pkey  = openssl_pkey_get_private( $key );
		if ( $pkey && openssl_sign( $plain, $sign, $pkey, OPENSSL_ALGO_SHA1 ) ) {
			$sign = base64_encode( $sign );
		}

		$response = $this->post_json(
			self::REQUEST_URL,
			[
				'TerminalId'     => (int) $terminal,
				'MerchantId'     => $merchant,
				'Amount'         => $amount,
				'OrderId'        => $order_id,
				'LocalDateTime'  => $date,
				'ReturnUrl'      => $callback_url,
				'SignData'       => $sign,
			]
		);

		$ok   = (bool) $response['ok'];
		$body = (array) $response['body'];

		$token = (string) ( $body['Token'] ?? $body['token'] ?? '' );
		if ( $ok && '' !== $token ) {
			return PaymentRequestResult::ok( $token, self::START_URL . rawurlencode( $token ) );
		}

		return PaymentRequestResult::failure(
			(string) ( $body['ResCode'] ?? 'sadad_failed' ),
			(string) ( $body['Description'] ?? $body['errorMessage'] ?? __( 'Sadad rejected the request.', 'igbz-suite' ) )
		);
	}

	public function verify( float $amount, array $callback_params ): PaymentVerifyResult {
		$token = (string) ( $callback_params['Token'] ?? $callback_params['token'] ?? '' );
		if ( '' === $token ) {
			return PaymentVerifyResult::failure( 'missing_token', __( 'Sadad did not return a token.', 'igbz-suite' ) );
		}

		$response = $this->post_json( self::VERIFY_URL, [ 'Token' => $token ] );
		$ok       = (bool) $response['ok'];
		$body     = (array) $response['body'];

		if ( $ok && '0' === (string) ( $body['ResCode'] ?? '1' ) ) {
			return PaymentVerifyResult::ok( (string) ( $body['RetrivalRefNo'] ?? $token ), (string) ( $body['CardNumber'] ?? '' ), 0.0 );
		}

		return PaymentVerifyResult::failure(
			(string) ( $body['ResCode'] ?? 'verify_failed' ),
			(string) ( $body['Description'] ?? __( 'Sadad could not verify the payment.', 'igbz-suite' ) )
		);
	}
}
