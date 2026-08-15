<?php
namespace IGBZ\Suite\Modules\MultiTenant\Seo;

defined( 'ABSPATH' ) || exit;

/**
 * Retargeting product feeds (Yektanet / Tapsell) built from the real catalog.
 */
final class ProductFeedService {

	/** @return array<int,array<string,mixed>> */
	private function products( int $limit ): array {
		$out  = [];
		$args = [
			'limit'    => $limit,
			'status'   => 'publish',
			'paginate' => true,
		];
		$result = wc_get_products( $args );

		foreach ( $result->products as $product ) {
			$image = (string) wp_get_attachment_url( (int) $product->get_image_id() );
			$out[] = [
				'id'          => $product->get_id(),
				'name'        => (string) $product->get_name(),
				'price_irt'   => \IGBZ\Suite\Modules\MultiTenant\Payments\Money::to_rial( (float) $product->get_price() ),
				'stock'       => max( 0, (int) $product->get_stock_quantity() ),
				'url'         => (string) $product->get_permalink(),
				'image'       => $image,
			];
		}

		return $out;
	}

	public function xml( int $limit = 500 ): string {
		$limit = max( 1, min( 10000, $limit ) );

		$xml = new \SimpleXMLElement( '<rss version="2.0"><channel><title>IGBZ Product Feed</title><link>' . esc_url( home_url( '/' ) ) . '</link></channel></rss>' );
		$channel = $xml->channel;

		foreach ( $this->products( $limit ) as $p ) {
			$item = $channel->addChild( 'item' );
			$item->addChild( 'g:id', (string) $p['id'] );
			$item->addChild( 'g:title', htmlspecialchars( (string) $p['name'], ENT_XML1 ) );
			$item->addChild( 'g:price', (string) $p['price_irt'] . ' IRT' );
			$item->addChild( 'g:availability', $p['stock'] > 0 ? 'in stock' : 'out of stock' );
			$item->addChild( 'g:link', (string) $p['url'] );
			if ( '' !== $p['image'] ) {
				$item->addChild( 'g:image_link', (string) $p['image'] );
			}
		}

		return $xml->asXML();
	}

	/** @return array<string,mixed> */
	public function json( int $limit = 500 ): array {
		return [ 'items' => $this->products( $limit ) ];
	}
}
