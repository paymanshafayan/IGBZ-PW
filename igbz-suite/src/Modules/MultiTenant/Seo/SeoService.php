<?php
namespace IGBZ\Suite\Modules\MultiTenant\Seo;

defined( 'ABSPATH' ) || exit;

/**
 * Auto SEO meta + hashtags.
 *
 * Template-based by default (deterministic, from the real product name and
 * description); when seo.use_ai is on, the AI studio provider rewrites it.
 */
final class SeoService {

	/**
	 * @return array{meta_title:string,meta_description:string,hashtags:string}
	 */
	public function generate( string $product_name, string $description ): array {
		$description = trim( $description );
		$excerpt     = mb_substr( $description, 0, 100 );

		$title = sprintf(
			/* translators: %s: product name */
			__( 'Buy %s online — best price and fast shipping', 'igbz-suite' ),
			$product_name
		);
		$meta = '' === $excerpt
			? sprintf( /* translators: %s: product name */ __( 'Online shopping for %s with authenticity guarantee.', 'igbz-suite' ), $product_name )
			: $excerpt . '...';

		$slug = str_replace( ' ', '_', trim( $product_name ) );
		$tags = '#' . $slug . ' #خرید_آنلاین #فروشگاه_اینترنتی';

		return [ 'meta_title' => $title, 'meta_description' => $meta, 'hashtags' => $tags ];
	}
}
