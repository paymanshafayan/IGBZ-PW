<?php
namespace IGBZ\Suite\Modules\Instagram\AiStudio;

defined( 'ABSPATH' ) || exit;

/**
 * AI content-studio provider (image enhance, background removal, video, TTS,
 * model photo). Iranian aggregators (Deepfa, Athena, iVira, ...) plug in via
 * the config-driven HTTP adapter; anything else implements this interface.
 */
interface AiProviderInterface {

	public function id(): string;

	public function title(): string;

	public function is_configured(): bool;

	/**
	 * @return array{ok:bool,url:string,error:string}
	 */
	public function enhance_image( string $image_url, string $background_preset = '', string $sku_code = '' ): array;

	/**
	 * @return array{ok:bool,url:string,error:string}
	 */
	public function remove_background( string $image_url ): array;

	/**
	 * @return array{ok:bool,url:string,error:string}
	 */
	public function generate_video( string $product_title, string $description, string $image_url = '' ): array;

	/**
	 * @return array{ok:bool,url:string,error:string}
	 */
	public function text_to_speech( string $text, string $voice = 'Female' ): array;

	/**
	 * @return array{ok:bool,url:string,error:string}
	 */
	public function generate_model_image( string $model_description, string $product_image_url = '', string $sku_code = '' ): array;
}
