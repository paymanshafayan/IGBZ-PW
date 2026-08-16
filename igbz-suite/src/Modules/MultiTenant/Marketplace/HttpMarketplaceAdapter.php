<?php
namespace IGBZ\Suite\Modules\MultiTenant\Marketplace;

use IGBZ\Suite\Support\Http;

defined( 'ABSPATH' ) || exit;

/**
 * Config-driven marketplace adapter (Digikala / Divar / future hubs).
 *
 * Base URL, paths and auth are settings (marketplace.digikala_*, marketplace
 * .divar_*), mirroring the HttpRampAdapter pattern. The remote id is read
 * from the provider's real response.
 */
final class HttpMarketplaceAdapter implements MarketplaceAdapterInterface {

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

	public function upsert( array $product, array $mapping ): array {
		if ( ! $this->is_configured() ) {
			return [ 'ok' => false, 'remote_id' => '', 'message' => __( 'Marketplace is not configured.', 'igbz-suite' ) ];
		}

		$response = $this->http->post(
			$this->base() . igbz()->settings()->string( $this->settings_prefix . '_products_path', '/v1/products' ),
			[
				'json'    => [
					'name'        => (string) ( $product['name'] ?? '' ),
					'description' => (string) ( $product['description'] ?? '' ),
					'price'       => (int) ( $product['price_irt'] ?? 0 ),
					'stock'       => (int) ( $product['stock'] ?? 0 ),
					'category'    => (string) ( $mapping['remote_category'] ?? '' ),
					'images'      => (array) ( $product['images'] ?? [] ),
					'external_id' => 'igbz:' . (int) ( $product['id'] ?? 0 ),
				],
				'headers' => $this->headers(),
				'channel' => 'marketplace',
				'timeout' => 30,
			]
		);
		$body = $response->json();

		if ( ! $response->ok() ) {
			return [ 'ok' => false, 'remote_id' => '', 'message' => (string) ( $body['message'] ?? $body['error'] ?? 'marketplace_request_failed' ) ];
		}

		$remote = (string) ( $body['id'] ?? $body['product_id'] ?? $body['data']['id'] ?? '' );
		if ( '' === $remote ) {
			return [ 'ok' => false, 'remote_id' => '', 'message' => __( 'The marketplace did not return a product id.', 'igbz-suite' ) ];
		}

		return [ 'ok' => true, 'remote_id' => $remote, 'message' => '' ];
	}

	public function update_price_stock( string $remote_id, float $price_irt, int $stock ): array {
		if ( ! $this->is_configured() ) {
			return [ 'ok' => false, 'message' => __( 'Marketplace is not configured.', 'igbz-suite' ) ];
		}

		$response = $this->http->post(
			$this->base() . igbz()->settings()->string( $this->settings_prefix . '_products_path', '/v1/products' )
				. '/' . rawurlencode( $remote_id ),
			[
				'json'    => [ 'price' => (int) $price_irt, 'stock' => (int) $stock ],
				'headers' => $this->headers(),
				'channel' => 'marketplace',
				'timeout' => 30,
			]
		);

		return $response->ok()
			? [ 'ok' => true, 'message' => '' ]
			: [ 'ok' => false, 'message' => $response->error_message() ];
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
