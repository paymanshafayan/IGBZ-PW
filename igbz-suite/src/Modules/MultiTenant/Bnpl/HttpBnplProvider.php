<?php
namespace IGBZ\Suite\Modules\MultiTenant\Bnpl;

use IGBZ\Suite\Support\Http;

defined( 'ABSPATH' ) || exit;

/**
 * Config-driven external BNPL provider (SnappPay / Tara / Digipay ...).
 *
 * The nop original shipped a SnappPay gateway class that was never
 * registered anywhere. Here one config-driven adapter covers any PSP that
 * exposes underwrite + status over HTTP: base URL, paths and auth are
 * settings, so wiring a new provider is configuration, not code.
 */
final class HttpBnplProvider implements BnplProviderInterface {

	public function __construct(
		private string $provider_id,
		private string $provider_title,
		private string $settings_prefix,
		private Http $http
	) {}

	public function id(): string {
		return $this->provider_id;
	}

	public function title(): string {
		return $this->provider_title;
	}

	public function is_configured(): bool {
		return '' !== $this->key() && '' !== $this->base();
	}

	public function underwrite( array $contract ): array {
		if ( ! $this->is_configured() ) {
			return [ 'approved' => false, 'reference' => '', 'message' => __( 'BNPL provider is not configured.', 'igbz-suite' ) ];
		}

		$response = $this->http->post(
			$this->base() . igbz()->settings()->string( $this->settings_prefix . '_underwrite_path', '/v1/credit/check' ),
			[
				'json'    => [
					'customer_mobile' => (string) ( $contract['customer_mobile'] ?? '' ),
					'amount'          => (float) ( $contract['amount'] ?? 0 ),
					'installments'    => (int) ( $contract['installments'] ?? 1 ),
					'external_id'     => 'bnpl:' . (int) ( $contract['id'] ?? 0 ),
				],
				'headers' => $this->headers(),
				'channel' => 'bnpl',
				'timeout' => 25,
			]
		);

		$body = $response->json();
		if ( ! $response->ok() ) {
			return [ 'approved' => false, 'reference' => '', 'message' => (string) ( $body['message'] ?? $body['error'] ?? 'bnpl_request_failed' ) ];
		}

		$approved = in_array( strtolower( (string) ( $body['approved'] ?? $body['status'] ?? '' ) ), [ '1', 'true', 'approved', 'ok' ], true );
		return [
			'approved'  => $approved,
			'reference' => (string) ( $body['reference'] ?? $body['credit_id'] ?? '' ),
			'message'   => (string) ( $body['message'] ?? '' ),
		];
	}

	public function report_payment( array $installment ): bool {
		if ( ! $this->is_configured() ) {
			return false;
		}
		$response = $this->http->post(
			$this->base() . igbz()->settings()->string( $this->settings_prefix . '_pay_path', '/v1/installments/pay' ),
			[
				'json'    => [
					'credit_id'  => (string) ( $installment['provider_ref'] ?? '' ),
					'amount'     => (float) ( $installment['amount'] ?? 0 ),
					'external_id' => 'installment:' . (int) ( $installment['id'] ?? 0 ),
				],
				'headers' => $this->headers(),
				'channel' => 'bnpl',
				'timeout' => 25,
			]
		);
		return $response->ok();
	}

	public function cancel( string $reference ): bool {
		if ( ! $this->is_configured() || '' === $reference ) {
			return false;
		}
		$response = $this->http->post(
			$this->base() . igbz()->settings()->string( $this->settings_prefix . '_cancel_path', '/v1/credit/cancel' ),
			[ 'json' => [ 'credit_id' => $reference ], 'headers' => $this->headers(), 'channel' => 'bnpl', 'timeout' => 25 ]
		);
		return $response->ok();
	}

	private function key(): string {
		return igbz()->settings()->string( $this->settings_prefix . '_api_key' );
	}

	private function base(): string {
		return rtrim( igbz()->settings()->string( $this->settings_prefix . '_base_url' ), '/' );
	}

	/** @return array<string,string> */
	private function headers(): array {
		$scheme = igbz()->settings()->string( $this->settings_prefix . '_auth_scheme', 'Bearer' );
		return [ 'Authorization' => ( '' === $scheme ? '' : $scheme . ' ' ) . $this->key(), 'Accept' => 'application/json' ];
	}
}
