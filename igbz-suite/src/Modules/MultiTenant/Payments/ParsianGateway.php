<?php
namespace IGBZ\Suite\Modules\MultiTenant\Payments;

defined( 'ABSPATH' ) || exit;

use IGBZ\Suite\Support\Http;

/**
 * Parsian (PEC) direct gateway — SOAP request, token redirect.
 *
 *   Request: SOAP POST https://pec.shaparak.ir/NewIPGServices/Sale/SaleService.asmx
 *     SalePaymentRequest { LoginAccount, Amount, OrderId, CallBackUrl, AdditionalData }
 *     -> { Token } ; redirect https://pec.shaparak.ir/NewIPG/StartPayShort/{Token}
 *   Verify: SOAP ConfirmPaymentData { LoginAccount, Token } -> { Status:0, ... }
 */
final class ParsianGateway extends AbstractIpgGateway {

	private const WSDL    = 'https://pec.shaparak.ir/NewIPGServices/Sale/SaleService.asmx';
	private const CONFIRM = 'https://pec.shaparak.ir/NewIPGServices/Confirm/ConfirmService.asmx';
	private const START   = 'https://pec.shaparak.ir/NewIPG/StartPayShort/';

	public function __construct( Http $http ) {
		parent::__construct( $http, 'payments.parsian' );
	}

	public function id(): string {
		return 'parsian';
	}

	public function title(): string {
		return __( 'Parsian', 'igbz-suite' );
	}

	public function required_settings(): array {
		return [ 'payments.parsian.login_account' ];
	}

	private function soap_request( int $amount, string $callback, string $order_id ): string {
		return '<?xml version="1.0" encoding="utf-8"?>'
			. '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/" xmlns:tem="http://tempuri.org/">'
			. '<soap:Body><tem:SalePaymentRequest>'
			. '<tem:requestData>'
			. '<tem:LoginAccount>' . htmlspecialchars( $this->cfg( 'login_account' ) ) . '</tem:LoginAccount>'
			. '<tem:Amount>' . $amount . '</tem:Amount>'
			. '<tem:OrderId>' . htmlspecialchars( $order_id ) . '</tem:OrderId>'
			. '<tem:CallBackUrl>' . htmlspecialchars( $callback ) . '</tem:CallBackUrl>'
			. '<tem:AdditionalData></tem:AdditionalData>'
			. '</tem:requestData></tem:SalePaymentRequest></soap:Body></soap:Envelope>';
	}

	private function soap_confirm( string $token ): string {
		return '<?xml version="1.0" encoding="utf-8"?>'
			. '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/" xmlns:tem="http://tempuri.org/">'
			. '<soap:Body><tem:ConfirmPaymentData>'
			. '<tem:requestData>'
			. '<tem:LoginAccount>' . htmlspecialchars( $this->cfg( 'login_account' ) ) . '</tem:LoginAccount>'
			. '<tem:Token>' . htmlspecialchars( $token ) . '</tem:Token>'
			. '</tem:requestData></tem:ConfirmPaymentData></soap:Body></soap:Envelope>';
	}

	public function request( float $amount, string $callback_url, array $context = [] ): PaymentRequestResult {
		$order_id = (string) ( $context['order_id'] ?? 'ORD-' . time() );
		[ $ok, , $raw ] = $this->post_raw( self::WSDL, $this->soap_request( Money::to_rial( $amount ), $callback_url, $order_id ) );

		if ( $ok && preg_match( '#<SalePaymentResult>(.*?)</SalePaymentResult>#s', (string) $raw, $m ) ) {
			$xml = @simplexml_load_string( '<r>' . $m[1] . '</r>' );
			if ( $xml && isset( $xml->Token ) && (int) $xml->Status === 0 ) {
				$token = (string) $xml->Token;
				return PaymentRequestResult::ok( $token, self::START . rawurlencode( $token ) );
			}
		}

		return PaymentRequestResult::failure( 'parsian_failed', __( 'Parsian rejected the request.', 'igbz-suite' ) );
	}

	public function verify( float $amount, array $callback_params ): PaymentVerifyResult {
		$token = (string) ( $callback_params['Token'] ?? $callback_params['token'] ?? '' );
		if ( '' === $token ) {
			return PaymentVerifyResult::failure( 'missing_token', __( 'Parsian did not return a token.', 'igbz-suite' ) );
		}

		[ $ok, , $raw ] = $this->post_raw( self::CONFIRM, $this->soap_confirm( $token ) );

		if ( $ok && preg_match( '#<ConfirmPaymentDataResult>(.*?)</ConfirmPaymentDataResult>#s', (string) $raw, $m ) ) {
			$xml = @simplexml_load_string( '<r>' . $m[1] . '</r>' );
			if ( $xml && (int) $xml->Status === 0 ) {
				return PaymentVerifyResult::ok( (string) ( $xml->RRN ?? $token ), (string) ( $xml->CardNumberMasked ?? '' ), 0.0 );
			}
		}

		return PaymentVerifyResult::failure( 'verify_failed', __( 'Parsian could not verify.', 'igbz-suite' ) );
	}
}
