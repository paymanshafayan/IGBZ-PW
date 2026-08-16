<?php
namespace IGBZ\Suite\Modules\MultiTenant\Translation;

use IGBZ\Suite\Support\Db;

defined( 'ABSPATH' ) || exit;

/**
 * Multilingual store (phase 12): admin-selected languages, default language
 * and per-product translations keyed by language (already stored as
 * igbz_translation_<lang> by TranslationService).
 */
final class I18nService {

	public function __construct( private Db $db ) {}

	public function is_enabled(): bool {
		return igbz()->settings()->bool( 'i18n.enabled', false );
	}

	/** @return string[] */
	public function languages(): array {
		$raw = igbz()->settings()->string( 'i18n.languages', 'fa' );
		$langs = array_values( array_filter( array_map( 'trim', explode( ',', $raw ) ) ) );
		return $langs ?: [ 'fa' ];
	}

	public function default_language(): string {
		$def = igbz()->settings()->string( 'i18n.default_language', 'fa' );
		return in_array( $def, $this->languages(), true ) ? $def : 'fa';
	}

	/** Translated name/description of a product for a language, if stored. */
	public function product_translation( int $product_id, string $language ): array {
		$meta = get_post_meta( $product_id, 'igbz_translation_' . sanitize_key( $language ), true );
		return is_array( $meta ) ? $meta : [];
	}
}
