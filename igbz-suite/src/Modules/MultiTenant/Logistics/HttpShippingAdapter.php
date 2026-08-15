<?php
namespace IGBZ\Suite\Modules\MultiTenant\Logistics;

use IGBZ\Suite\Support\Http;

defined( 'ABSPATH' ) || exit;

/**
 * Config-driven shipping hub adapter (Tapin / Postex / Snapp Business).
 *
 * One adapter, driven by settings: base URL, register/track paths, auth
 * scheme and response JSON paths — exactly the HttpRampAdapter pattern, so
 * wiring a different hub is configuration, not code.
 */
final class HttpShippingAdapter implements ShippingAdapterInterface {

	public function __construct(
		private string $adapter_id,
		private string $adapter_title,
		private string $settings_prefix,
		private Http $http
	) {}

	public function id(): string {
		return $this->adapter_id;
	}

	public function title(): string {
		return $this->adapter_title;
	}

	public function is_configured(): bool {
		return '' !== $this->key() && '' !== $this->base();
	}

	public function register( array $shipment ): array {
		if ( ! $this->is_configured() ) {
			return [ 'ok' => false, 'tracking_code' => '', 'message' => __( 'Shipping carrier is not configured.', 'igbz-suite' ) ];
		}

		$response = $this->http->post(
			$this->base() . igbz()->settings()->string( $this->settings_prefix . '_register_path', '/v1/shipments' ),
			[
				'json'    => [
					'external_order_id' => (int) ( $shipment['order_id'] ?? 0 ),
					'recipient_name'    => (string) ( $shipment['recipient_name'] ?? '' ),
					'recipient_phone'   => (string) ( $shipment['recipient_phone'] ?? '' ),
					'recipient_address' => (string) ( $shipment['recipient_address'] ?? '' ),
					'is_cod'            => (bool) ( $shipment['is_cod'] ?? false ),
					'delivery_pin'      => (string) ( $shipment['delivery_pin'] ?? '' ),
				],
				'headers' => $this->headers(),
				'channel' => 'logistics',
				'timeout' => 25,
			]
		);
		$body = $response->json();

		if ( ! $response->ok() ) {
			return [ 'ok' => false, 'tracking_code' => '', 'message' => (string) ( $body['message'] ?? $body['error'] ?? 'shipping_request_failed' ) ];
		}

		$code = $this->field( $body, 'tracking_code' );
		if ( '' === $code ) {
			return [ 'ok' => false, 'tracking_code' => '', 'message' => __( 'The carrier did not return a tracking code.', 'igbz-suite' ) ];
		}

		return [ 'ok' => true, 'tracking_code' => $code, 'message' => '' ];
	}

	public function track( string $tracking_code ): array {
		if ( ! $this->is_configured() ) {
			return [ 'status' => 'unknown', 'detail' => '' ];
		}
		$response = $this->http->get(
			$this->base() . igbz()->settings()->string( $this->settings_prefix . '_track_path', '/v1/shipments/track' )
				. '?tracking_code=' . rawurlencode( $tracking_code ),
			[ 'headers' => $this->headers(), 'channel' => 'logistics', 'timeout' => 25 ]
		);
		$body = $response->json();
		return [
			'status' => (string) ( $body['status'] ?? 'unknown' ),
			'detail' => (string) ( $body['detail'] ?? $body['message'] ?? '' ),
		];
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

	/** @param array<string,mixed> $body */
	private function field( array $body, string $what ): string {
		$path = igbz()->settings()->string( $this->settings_prefix . '_field_' . $what, '' );
		if ( '' === $path ) {
			$value = $body[ $what ] ?? '';
			return is_scalar( $value ) ? (string) $value : '';
		}
		$value = $body;
		foreach ( explode( '.', $path ) as $seg ) {
			if ( ! is_array( $value ) || ! array_key_exists( $seg, $value ) ) {
				return '';
			}
			$value = $value[ $seg ];
		}
		return is_scalar( $value ) ? (string) $value : '';
	}
}
