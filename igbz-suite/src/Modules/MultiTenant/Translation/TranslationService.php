<?php
namespace IGBZ\Suite\Modules\MultiTenant\Translation;

use IGBZ\Suite\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * One-click product translation.
 *
 * Translates name + short/long description and stores the result as product
 * meta keyed by language (the same storage the intake TranslationBridge
 * uses), so multilingual plugins can pick it up later.
 */
final class TranslationService {

	public function __construct(
		private HttpTranslationAdapter $adapter,
		private Logger $logger
	) {}

	/**
	 * @return array{ok:bool,language:string,error:string}
	 */
	public function translate_product( int $product_id, string $language ): array {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return [ 'ok' => false, 'language' => $language, 'error' => __( 'Product not found.', 'igbz-suite' ) ];
		}

		$result = $this->adapter->translate(
			[
				(string) $product->get_name(),
				(string) $product->get_short_description(),
				(string) $product->get_description(),
			],
			$language
		);

		if ( ! $result['ok'] ) {
			$this->logger->error( 'translation', 'Product translation failed', [ 'product_id' => $product_id, 'error' => $result['error'] ] );
			return [ 'ok' => false, 'language' => $language, 'error' => $result['error'] ];
		}

		$lang_key = 'igbz_translation_' . sanitize_key( $language );
		update_post_meta( $product_id, $lang_key, [
			'name'              => $result['translated'][0] ?? '',
			'short_description' => $result['translated'][1] ?? '',
			'description'       => $result['translated'][2] ?? '',
			'translated_at'     => current_time( 'mysql', true ),
		] );
		$this->logger->info( 'translation', 'Product translated', [ 'product_id' => $product_id, 'language' => $language ] );

		return [ 'ok' => true, 'language' => $language, 'error' => '' ];
	}
}
