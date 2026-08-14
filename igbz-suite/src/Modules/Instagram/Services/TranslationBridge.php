<?php
namespace IGBZ\Suite\Modules\Instagram\Services;

use IGBZ\Suite\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Publishes translated listings into whichever multilingual plugin the store runs.
 *
 * Polylang and WPML solve the same problem with incompatible data models, and plenty of stores run
 * neither. Rather than pick one and leave the other two broken, this detects what is installed and
 * degrades in a way that never loses the translation:
 *
 *   Polylang  real translated products, linked with pll_save_post_translations()
 *   WPML      real translated products, linked through the wpml_set_element_language_details action
 *   neither   the translations are stored in product meta under _igbz_translations
 *
 * The meta fallback matters more than it looks. A shop that installs Polylang six months from now
 * should not have to re-register its catalogue: the copy is already there, keyed by language, and
 * `backfill()` can turn it into real posts whenever a plugin shows up.
 */
final class TranslationBridge {

	public const META_KEY = '_igbz_translations';

	public const ENGINE_POLYLANG = 'polylang';
	public const ENGINE_WPML     = 'wpml';
	public const ENGINE_META     = 'meta';

	public function __construct( private Logger $logger ) {}

	public function engine(): string {
		if ( function_exists( 'pll_save_post_translations' ) && function_exists( 'pll_set_post_language' ) ) {
			return self::ENGINE_POLYLANG;
		}
		if ( defined( 'ICL_SITEPRESS_VERSION' ) && function_exists( 'icl_object_id' ) ) {
			return self::ENGINE_WPML;
		}
		return self::ENGINE_META;
	}

	public function is_multilingual(): bool {
		return self::ENGINE_META !== $this->engine() && count( $this->languages() ) > 1;
	}

	/**
	 * Language codes the store publishes in, default language first.
	 *
	 * @return array<int,string>
	 */
	public function languages(): array {
		switch ( $this->engine() ) {
			case self::ENGINE_POLYLANG:
				$languages = function_exists( 'pll_languages_list' ) ? (array) pll_languages_list( [ 'fields' => 'slug' ] ) : [];
				break;

			case self::ENGINE_WPML:
				$raw       = apply_filters( 'wpml_active_languages', null, [ 'skip_missing' => 0 ] );
				$languages = is_array( $raw ) ? array_keys( $raw ) : [];
				break;

			default:
				// Configured by hand when no plugin is present, so the copy is still translated
				// and ready for the day one is installed.
				$configured = igbz()->settings()->string( 'intake.languages', '' );
				$languages  = array_filter( array_map( 'trim', explode( ',', $configured ) ) );
		}

		$languages = array_values( array_unique( array_map( 'strval', $languages ) ) );

		$default = $this->default_language();
		if ( '' !== $default ) {
			$languages = array_merge( [ $default ], array_values( array_diff( $languages, [ $default ] ) ) );
		}

		return $languages;
	}

	public function default_language(): string {
		switch ( $this->engine() ) {
			case self::ENGINE_POLYLANG:
				return function_exists( 'pll_default_language' ) ? (string) pll_default_language( 'slug' ) : '';

			case self::ENGINE_WPML:
				return (string) apply_filters( 'wpml_default_language', null );

			default:
				return (string) igbz()->settings()->string( 'intake.default_language', '' );
		}
	}

	/**
	 * The languages a listing should be translated into, i.e. everything but the default.
	 *
	 * @return array<int,string>
	 */
	public function target_languages(): array {
		$default = $this->default_language();
		return array_values( array_filter( $this->languages(), static fn ( string $lang ): bool => $lang !== $default ) );
	}

	/**
	 * Publish the translations of a product.
	 *
	 * @param array<string,array<string,mixed>> $translations Language code => copy fields.
	 * @return array<string,int> Language code => created post id (0 when stored as meta only).
	 */
	public function apply( int $product_id, array $translations, array $context = [] ): array {
		if ( ! $translations ) {
			return [];
		}

		// Always kept, whatever the engine. It costs one meta row and it is the only copy that
		// survives switching multilingual plugins.
		update_post_meta( $product_id, self::META_KEY, wp_slash( wp_json_encode( $translations ) ) );

		$engine = $this->engine();
		if ( self::ENGINE_META === $engine ) {
			return [];
		}

		$created = [];
		$default = $this->default_language();

		foreach ( $translations as $language => $copy ) {
			$language = (string) $language;
			if ( '' === $language || $language === $default || ! is_array( $copy ) ) {
				continue;
			}

			$translated_id = $this->create_translation( $product_id, $language, $copy, $context );
			if ( $translated_id > 0 ) {
				$created[ $language ] = $translated_id;
			}
		}

		if ( $created ) {
			$this->link( $product_id, $default, $created );
		}

		return $created;
	}

	/**
	 * Duplicate the product into one language.
	 *
	 * Everything commercial — price, stock, SKU, categories, image — is copied verbatim from the
	 * original: it is the same physical item, so a translation that could drift on price would be
	 * a bug, not a feature. Only the words differ.
	 *
	 * @param array<string,mixed> $copy
	 * @param array<string,mixed> $context
	 */
	private function create_translation( int $product_id, string $language, array $copy, array $context ): int {
		$original = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
		if ( ! $original ) {
			return 0;
		}

		$title = trim( (string) ( $copy['title'] ?? '' ) );
		if ( '' === $title ) {
			return 0;
		}

		$translated_id = wp_insert_post(
			[
				'post_title'   => $title,
				'post_content' => (string) ( $copy['description'] ?? '' ),
				'post_excerpt' => (string) ( $copy['short_description'] ?? '' ),
				'post_status'  => get_post_status( $product_id ) ?: 'publish',
				'post_type'    => 'product',
				'post_author'  => (int) get_post_field( 'post_author', $product_id ),
			],
			true
		);

		if ( is_wp_error( $translated_id ) ) {
			$this->logger->warning(
				'intake',
				'Could not create a translated product',
				[ 'product_id' => $product_id, 'language' => $language, 'error' => $translated_id->get_error_message() ]
			);
			return 0;
		}

		$translated_id = (int) $translated_id;
		$translation   = wc_get_product( $translated_id );

		if ( $translation ) {
			$translation->set_regular_price( (string) $original->get_regular_price() );
			$translation->set_sale_price( (string) $original->get_sale_price() );
			$translation->set_manage_stock( $original->get_manage_stock() );
			$translation->set_stock_quantity( $original->get_stock_quantity() );
			$translation->set_stock_status( $original->get_stock_status() );
			$translation->set_catalog_visibility( $original->get_catalog_visibility() );
			$translation->set_image_id( $original->get_image_id() );
			$translation->set_gallery_image_ids( $original->get_gallery_image_ids() );
			$translation->set_category_ids( $original->get_category_ids() );

			// The SKU is the funnel keyword and must stay unique, so the translation carries a
			// language-suffixed variant and points back at the original for reporting.
			$sku = (string) $original->get_sku();
			if ( '' !== $sku ) {
				$translation->set_sku( $sku . '-' . strtoupper( substr( $language, 0, 2 ) ) );
			}

			$translation->save();
		}

		update_post_meta( $translated_id, '_igbz_translation_of', $product_id );
		update_post_meta( $translated_id, '_igbz_language', $language );

		if ( ! empty( $context['intake_id'] ) ) {
			update_post_meta( $translated_id, '_igbz_intake_id', (int) $context['intake_id'] );
		}

		$this->set_language( $translated_id, $language );

		return $translated_id;
	}

	private function set_language( int $post_id, string $language ): void {
		if ( self::ENGINE_POLYLANG === $this->engine() ) {
			pll_set_post_language( $post_id, $language );
			return;
		}

		do_action(
			'wpml_set_element_language_details',
			[
				'element_id'    => $post_id,
				'element_type'  => 'post_product',
				'language_code' => $language,
			]
		);
	}

	/** @param array<string,int> $translations */
	private function link( int $product_id, string $default_language, array $translations ): void {
		if ( self::ENGINE_POLYLANG === $this->engine() ) {
			if ( '' !== $default_language ) {
				pll_set_post_language( $product_id, $default_language );
			}
			pll_save_post_translations( array_merge( [ $default_language => $product_id ], $translations ) );
			return;
		}

		// WPML links by pointing every translation at the original's trid, which it allocates on
		// the first set_element_language_details call for the source post.
		$trid = apply_filters( 'wpml_element_trid', null, $product_id, 'post_product' );

		foreach ( $translations as $language => $translated_id ) {
			do_action(
				'wpml_set_element_language_details',
				[
					'element_id'           => $translated_id,
					'element_type'         => 'post_product',
					'trid'                 => $trid,
					'language_code'        => $language,
					'source_language_code' => $default_language,
				]
			);
		}
	}

	/**
	 * Translations stored on a product, whether or not a multilingual plugin ever ran.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function stored( int $product_id ): array {
		$raw     = get_post_meta( $product_id, self::META_KEY, true );
		$decoded = is_string( $raw ) ? json_decode( $raw, true ) : $raw;

		return is_array( $decoded ) ? $decoded : [];
	}

	/**
	 * Turn meta-only translations into real posts, for a store that installed Polylang or WPML
	 * after the fact.
	 *
	 * @return array<string,int>
	 */
	public function backfill( int $product_id ): array {
		if ( self::ENGINE_META === $this->engine() ) {
			return [];
		}

		return $this->apply( $product_id, $this->stored( $product_id ) );
	}
}
