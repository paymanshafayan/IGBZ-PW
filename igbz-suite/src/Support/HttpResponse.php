<?php
namespace IGBZ\Suite\Support;

defined( 'ABSPATH' ) || exit;

final class HttpResponse {

	/** @param array<string,mixed> $headers */
	public function __construct(
		public readonly int $status,
		public readonly array $headers,
		public readonly string $body,
		public readonly ?string $error = null
	) {}

	public function ok(): bool {
		return null === $this->error && $this->status >= 200 && $this->status < 300;
	}

	/** @return array<string,mixed> */
	public function json(): array {
		$decoded = json_decode( $this->body, true );
		return is_array( $decoded ) ? $decoded : [];
	}

	public function error_message(): string {
		if ( null === $this->error ) {
			return '';
		}
		$json = $this->json();
		foreach ( [ 'message', 'error', 'error_message', 'detail' ] as $key ) {
			if ( isset( $json[ $key ] ) && is_string( $json[ $key ] ) ) {
				return $json[ $key ];
			}
		}
		return $this->error;
	}
}
