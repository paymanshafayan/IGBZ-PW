<?php
namespace IGBZ\Suite\Modules\Fx\Providers;

use IGBZ\Suite\Modules\Fx\Contracts\FxPayoutAdapterInterface;
use IGBZ\Suite\Support\Http;
use IGBZ\Suite\Support\Logger;
use IGBZ\Suite\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * RedotPay payout adapter — the pilot/secondary virtual-card provider.
 *
 * RedotPay is popular in Iran, funds with USDT, and its virtual Visa cards are
 * accepted broadly (Google Play, Apple, cloud panels). It is the pilot option:
 * public developer-API access is not guaranteed for every account level, so
 * this adapter is only useful once the operator confirms their RedotPay API
 * credentials actually work. If they do, switching from PST.NET is a single
 * settings change (fx.payout_provider = redotpay) thanks to the shared
 * FxPayoutAdapterInterface.
 */

final class RedotPayPayoutAdapter implements FxPayoutAdapterInterface {

	private const DEFAULT_BASE = 'https://openapi.redotpay.com';

	public function __construct(
		private Settings $settings,
		private Http $http,
		private Logger $logger
	) {}

	public function id(): string {
		return 'redotpay';
	}

	public function title(): string {
		return 'RedotPay';
	}

	public function is_configured(): bool {
		return '' !== trim( $this->settings->string( 'fx.redotpay_api_key', '' ) )
			&& '' !== trim( $this->settings->string( 'fx.redotpay_card_id', '' ) );
	}

	/**
	 * @param array<string,mixed> $bill
	 * @return array{ok:bool,reference:string,error:string}
	 */
	public function pay( array $bill ): array {
		if ( ! $this->is_configured() ) {
			return [ 'ok' => false, 'reference' => '', 'error' => 'redotpay_not_configured' ];
		}

		$response = $this->http->post(
			$this->base() . '/v1/cards/' . rawurlencode( $this->card_id() ) . '/payment',
			[
				'headers' => $this->headers(),
				'body'    => wp_json_encode(
					[
						'amount'   => (float) $bill['amount_usd'],
						'currency' => 'USD',
						'metadata' => [ 'igbz_bill_id' => (int) $bill['id'] ],
					]
				),
			]
		);

		if ( ! $response->ok() ) {
			$this->logger->error( 'fx', 'RedotPay charge failed', [ 'bill_id' => (int) $bill['id'], 'status' => $response->status, 'error' => $response->error_message() ] );

			return [ 'ok' => false, 'reference' => '', 'error' => $response->error_message() ];
		}

		$data = $response->json();
		$ref  = (string) ( $data['paymentId'] ?? $data['id'] ?? $data['reference'] ?? '' );

		$this->logger->info( 'fx', 'RedotPay charge accepted', [ 'bill_id' => (int) $bill['id'], 'reference' => $ref ] );

		return [ 'ok' => true, 'reference' => $ref, 'error' => '' ];
	}

	public function card_balance(): float {
		if ( ! $this->is_configured() ) {
			return 0.0;
		}

		$response = $this->http->get(
			$this->base() . '/v1/cards/' . rawurlencode( $this->card_id() ),
			[ 'headers' => $this->headers() ]
		);
		if ( ! $response->ok() ) {
			return 0.0;
		}

		$data = $response->json();

		return (float) ( $data['balance'] ?? $data['availableBalance'] ?? 0 );
	}

	public function webhook( array $payload ): void {
		$bill_id = (int) ( $payload['metadata']['igbz_bill_id'] ?? $payload['bill_id'] ?? 0 );
		$this->logger->info( 'fx', 'RedotPay webhook', [ 'bill_id' => $bill_id, 'event' => $payload['event'] ?? 'unknown' ] );
	}

	private function base(): string {
		return rtrim( $this->settings->string( 'fx.redotpay_base_url', self::DEFAULT_BASE ), '/' );
	}

	private function card_id(): string {
		return (string) $this->settings->string( 'fx.redotpay_card_id', '' );
	}

	/** @return array<string,string> */
	private function headers(): array {
		return [
			'Authorization' => 'Bearer ' . $this->settings->string( 'fx.redotpay_api_key', '' ),
			'Content-Type'  => 'application/json',
			'Accept'        => 'application/json',
		];
	}
}
