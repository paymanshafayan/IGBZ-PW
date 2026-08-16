<?php
namespace IGBZ\Suite\Modules\MultiTenant\Payments;

defined( 'ABSPATH' ) || exit;

use IGBZ\Suite\Support\Http;

/**
 * Mellat (Behpardakht) direct gateway — SOAP with 3DES-encrypted payload.
 *
 *   bpPayRequest: SOAP to https://bpm.shaparak.ir/pgwchannel/services/pgw?wsdl
 *     with TerminalId, UserName, UserPassword, OrderId, Amount(rial),
 *     LocalDate, LocalTime, AdditionalData, CallBackUrl, PayerId
 *     -> "0,<refId>" ; redirect https://bpm.shaparak.ir/pgwchannel/startpay.mellat?RefId=
 *   bpVerifyRequest: "0" confirms; then bpInquiryRequest/bpSettleRequest.
 */
final class MellatGateway extends AbstractIpgGateway {

	private const WSDL   = 'https://bpm.shaparak.ir/pgwchannel/services/pgw?wsdl';
	private const START  = 'https://bpm.shaparak.ir/pgwchannel/startpay.mellat?RefId=';

	public function __construct( Http $http ) {
		parent::__construct( $http, 'payments.mellat' );
	}

	public function id(): string {
		return 'mellat';
	}

	public function title(): string {
		return __( 'Mellat (Behpardakht)', 'igbz-suite' );
	}

	public function required_settings(): array {
		return [ 'payments.mellat.terminal_id', 'payments.mellat.username', 'payments.mellat.password' ];
	}

	private function soap( string $method, array $args ): string {
		$ns = 'http://interfaces.core.sw.bps.com/';
		$parts = '';
		foreach ( $args as $k => $v ) {
			$parts .= '<' . $k . '>' . htmlspecialchars( (string) $v ) . '</' . $k . '>';
		}
		return '<?xml version="1.0" encoding="utf-8"?>'
			. '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/" xmlns:web="' . $ns . '">'
			. '<soap:Body><web:' . $method . '>' . $parts . '</web:' . $method . '></soap:Body></soap:Envelope>';
	}

	public function request( float $amount, string $callback_url, array $context = [] ): PaymentRequestResult {
		$order_id = (string) ( $context['order_id'] ?? 'ORD-' . time() );
		$now      = gmdate( 'Ymd' );
		$time     = gmdate( 'His' );
		$body     = $this->soap(
			'bpPayRequest',
			[
				'terminalId'     => (int) $this->cfg( 'terminal_id' ),
				'userName'       => $this->cfg( 'username' ),
				'userPassword'   => $this->cfg( 'password' ),
				'orderId'        => $order_id,
				'amount'         => Money::to_rial( $amount ),
				'localDate'      => $now,
				'localTime'      => $time,
				'additionalData' => '',
				'callBackUrl'    => $callback_url,
				'payerId'        => 0,
			]
		);

		[ $ok, , $raw ] = $this->post_raw( self::WSDL, $body );

		if ( $ok && preg_match( '#<return>(.*?)</return>#s', (string) $raw, $m ) ) {
			$result = trim( $m[1] );
			$parts  = explode( ',', $result );
			if ( '0' === (string) $parts[0] && isset( $parts[1] ) ) {
				return PaymentRequestResult::ok( (string) $parts[1], self::START . rawurlencode( (string) $parts[1] ) );
			}
			return PaymentRequestResult::failure( (string) $parts[0], sprintf( /* translators: %s: bank code */ __( 'Mellat error code %s.', 'igbz-suite' ), (string) $parts[0] ) );
		}

		return PaymentRequestResult::failure( 'mellat_failed', __( 'Mellat rejected the request.', 'igbz-suite' ) );
	}

	public function verify( float $amount, array $callback_params ): PaymentVerifyResult {
		$ref = (string) ( $callback_params['RefId'] ?? $callback_params['refId'] ?? '' );
		if ( '' === $ref ) {
			return PaymentVerifyResult::failure( 'missing_ref', __( 'Mellat did not return a RefId.', 'igbz-suite' ) );
		}
		$order_id = (string) ( $callback_params['SaleOrderId'] ?? $callback_params['order_id'] ?? '' );

		[ $ok, , $raw ] = $this->post_raw(
			self::WSDL,
			$this->soap(
				'bpVerifyRequest',
				[ 'terminalId' => (int) $this->cfg( 'terminal_id' ), 'userName' => $this->cfg( 'username' ), 'userPassword' => $this->cfg( 'password' ), 'orderId' => $order_id, 'saleOrderId' => $order_id, 'saleReferenceId' => $ref ]
			)
		);

		if ( $ok && preg_match( '#<return>(.*?)</return>#s', (string) $raw, $m ) && '0' === trim( $m[1] ) ) {
			// Settle.
			$this->post_raw(
				self::WSDL,
				$this->soap( 'bpSettleRequest', [ 'terminalId' => (int) $this->cfg( 'terminal_id' ), 'userName' => $this->cfg( 'username' ), 'userPassword' => $this->cfg( 'password' ), 'orderId' => $order_id, 'saleOrderId' => $order_id, 'saleReferenceId' => $ref ] )
			);
			return PaymentVerifyResult::ok( $ref, (string) ( $callback_params['CardHolderPan'] ?? '' ), 0.0 );
		}

		return PaymentVerifyResult::failure( 'verify_failed', __( 'Mellat could not verify.', 'igbz-suite' ) );
	}
}
