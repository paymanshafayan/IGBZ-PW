<?php
namespace IGBZ\Suite\Modules\MultiTenant\Payments;

use IGBZ\Suite\Support\Http;

defined( 'ABSPATH' ) || exit;

/**
 * Base for the direct-bank (IPG) adapters.
 *
 * Each Iranian bank has its own protocol (REST/SOAP + specific encryption),
 * so each adapter implements request/verify specifics and reuses the common
 * HTTP + amount + result plumbing here. Settings are read from a per-bank
 * prefix (payments.<bank>.*).
 */
abstract class AbstractIpgGateway implements GatewayInterface {

	protected function __construct( protected Http $http, protected string $prefix ) {}

	public function is_configured(): bool {
		foreach ( $this->required_settings() as $key ) {
			if ( '' === igbz()->settings()->string( $key ) ) {
				return false;
			}
		}
		return true;
	}

	protected function cfg( string $key ): string {
		return (string) igbz()->settings()->string( $this->prefix . '.' . $key );
	}

	protected function bool_cfg( string $key, bool $default = false ): bool {
		return igbz()->settings()->bool( $this->prefix . '.' . $key, $default );
	}

	/** POST JSON and return decoded body. */
	protected function post_json( string $url, array $payload, int $timeout = 30 ): array {
		$response = $this->http->post(
			$url,
			[
				'json'    => $payload,
				'headers' => [ 'Accept' => 'application/json', 'Content-Type' => 'application/json' ],
				'channel' => 'payments',
				'timeout' => $timeout,
			]
		);
		return [ 'ok' => $response->ok(), 'body' => $response->json(), 'raw' => $response->body, 'error' => $response->error_message() ];
	}

	/** POST raw body (SOAP/XML or form) and return raw response. */
	protected function post_raw( string $url, string $body, string $content_type = 'application/soap+xml; charset=utf-8', int $timeout = 30 ): array {
		$response = $this->http->post(
			$url,
			[
				'body'    => $body,
				'headers' => [ 'Content-Type' => $content_type, 'Accept' => 'text/xml, application/json' ],
				'channel' => 'payments',
				'timeout' => $timeout,
			]
		);
		return [ 'ok' => $response->ok(), 'body' => $response->json(), 'raw' => $response->body, 'error' => $response->error_message() ];
	}
}
