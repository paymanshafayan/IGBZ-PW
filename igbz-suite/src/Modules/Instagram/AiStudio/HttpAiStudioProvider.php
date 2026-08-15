<?php
namespace IGBZ\Suite\Modules\Instagram\AiStudio;

use IGBZ\Suite\Support\Http;

defined( 'ABSPATH' ) || exit;

/**
 * Config-driven AI studio provider.
 *
 * Endpoints, auth scheme and the JSON path of the result URL are settings
 * (ai_studio.*), so Deepfa / Athena / iVira and any OpenAI-compatible media
 * API can be wired without code changes. The result URL is always read from
 * the provider's real response — never synthesised from the input URL.
 */
final class HttpAiStudioProvider implements AiProviderInterface {

	public function __construct(
		private Http $http
	) {}

	public function id(): string {
		return 'http';
	}

	public function title(): string {
		return __( 'HTTP AI studio (configurable)', 'igbz-suite' );
	}

	public function is_configured(): bool {
		return '' !== igbz()->settings()->string( 'ai_studio.api_key' )
			&& '' !== igbz()->settings()->string( 'ai_studio.base_url' );
	}

	public function enhance_image( string $image_url, string $background_preset = '', string $sku_code = '' ): array {
		return $this->post( 'image_path', [ 'source_url' => $image_url, 'background_preset' => $background_preset, 'sku_code' => $sku_code ] );
	}

	public function remove_background( string $image_url ): array {
		return $this->post( 'background_path', [ 'source_url' => $image_url ] );
	}

	public function generate_video( string $product_title, string $description, string $image_url = '' ): array {
		return $this->post( 'video_path', [ 'title' => $product_title, 'description' => $description, 'image_url' => $image_url ] );
	}

	public function text_to_speech( string $text, string $voice = 'Female' ): array {
		return $this->post( 'tts_path', [ 'text' => $text, 'voice' => $voice ] );
	}

	public function generate_model_image( string $model_description, string $product_image_url = '', string $sku_code = '' ): array {
		return $this->post( 'model_image_path', [ 'model_description' => $model_description, 'product_image_url' => $product_image_url, 'sku_code' => $sku_code ] );
	}

	/** @param array<string,mixed> $payload */
	private function post( string $path_key, array $payload ): array {
		if ( ! $this->is_configured() ) {
			return [ 'ok' => false, 'url' => '', 'error' => 'ai_studio_not_configured' ];
		}

		$path = igbz()->settings()->string( 'ai_studio.' . $path_key, '' );
		if ( '' === $path ) {
			return [ 'ok' => false, 'url' => '', 'error' => 'ai_studio_path_missing' ];
		}

		$scheme   = igbz()->settings()->string( 'ai_studio.auth_scheme', 'Bearer' );
		$response = $this->http->post(
			rtrim( igbz()->settings()->string( 'ai_studio.base_url' ), '/' ) . $path,
			[
				'json'    => $payload,
				'headers' => [
					'Authorization' => ( '' === $scheme ? '' : $scheme . ' ' ) . igbz()->settings()->string( 'ai_studio.api_key' ),
					'Accept'        => 'application/json',
				],
				'channel' => 'ai_studio',
				'timeout' => 90,
			]
		);

		if ( ! $response->ok() ) {
			return [ 'ok' => false, 'url' => '', 'error' => $response->error_message() ];
		}

		$body  = $response->json();
		$value = $body;
		$path  = trim( igbz()->settings()->string( 'ai_studio.result_json_path', 'result_url' ) );
		foreach ( explode( '.', $path ) as $seg ) {
			if ( ! is_array( $value ) || ! array_key_exists( $seg, $value ) ) {
				return [ 'ok' => false, 'url' => '', 'error' => __( 'The AI provider response did not contain a result URL.', 'igbz-suite' ) ];
			}
			$value = $value[ $seg ];
		}

		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return [ 'ok' => false, 'url' => '', 'error' => __( 'The AI provider returned an empty result URL.', 'igbz-suite' ) ];
		}

		return [ 'ok' => true, 'url' => trim( $value ), 'error' => '' ];
	}
}
