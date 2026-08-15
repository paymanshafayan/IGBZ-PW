<?php
namespace IGBZ\Suite\Modules\Hub\Services;

use IGBZ\Suite\Modules\MultiTenant\Repository\Tenant;
use IGBZ\Suite\Modules\MultiTenant\Repository\TenantRepository;
use IGBZ\Suite\Support\Db;

defined( 'ABSPATH' ) || exit;

/**
 * The store directory shown on the mother site: featured stores, their representative image and
 * a real product preview count.
 *
 * Port note: the nop landing controller filled this with a fixed Unsplash avatar, a category name
 * derived from the loop index and a `HasActiveStory` flag that was always true. Every field here
 * either comes from the database or is omitted.
 */
final class DirectoryService {

	private const CACHE_KEY = 'igbz_hub_directory';

	public function __construct( private Db $db, private TenantRepository $tenants ) {}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function featured( int $limit = 0, bool $fresh = false ): array {
		$limit = $limit > 0 ? $limit : igbz()->settings()->int( 'hub.featured_limit', 12 );

		if ( ! $fresh ) {
			$cached = get_transient( self::CACHE_KEY . '_' . $limit );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$rows = $this->db->results(
			'SELECT * FROM ' . $this->db->table( 'tenants' ) . '
			 WHERE status IN (%s, %s)
			 ORDER BY CASE WHEN logo_url <> %s THEN 0 ELSE 1 END, id DESC
			 LIMIT %d',
			Tenant::STATUS_ACTIVE,
			Tenant::STATUS_TRIAL,
			'',
			$limit
		);

		$out = [];
		foreach ( $rows as $row ) {
			$out[] = $this->card( Tenant::from_row( $row ) );
		}

		set_transient( self::CACHE_KEY . '_' . $limit, $out, max( 300, igbz()->settings()->int( 'hub.sync_interval', 3600 ) ) );

		return $out;
	}

	public function flush(): void {
		global $wpdb;
		$wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_' . self::CACHE_KEY ) . '%',
				$wpdb->esc_like( '_transient_timeout_' . self::CACHE_KEY ) . '%'
			)
		);
	}

	/** @return array<string,mixed> */
	public function card( Tenant $tenant ): array {
		[ $image, $category, $products ] = $this->representative( $tenant->id );

		return [
			'id'            => $tenant->id,
			'slug'          => $tenant->slug,
			'name'          => $tenant->name,
			'status'        => $tenant->status,
			'url'           => $this->store_url( $tenant ),
			'logo_url'      => '' !== $tenant->logo_url ? $tenant->logo_url : $image,
			'primary_color' => $tenant->primary_color,
			'category'      => $category,
			'product_count' => $products,
			'currency'      => $tenant->currency,
			'locale'        => $tenant->locale,
		];
	}

	public function store_url( Tenant $tenant ): string {
		$domain = $this->tenants->primary_domain( $tenant->id );
		if ( '' !== $domain ) {
			return 'https://' . $domain . '/';
		}

		return match ( igbz()->settings()->string( 'general.tenant_resolution', 'domain' ) ) {
			'path'  => home_url( '/' . trim( igbz()->settings()->string( 'general.tenant_path_base', 'store' ), '/' ) . '/' . $tenant->slug . '/' ),
			'query' => add_query_arg( 'tenant', $tenant->slug, home_url( '/' ) ),
			default => home_url( '/' ),
		};
	}

	/**
	 * Newest published product of the tenant: its image, its first category and how many
	 * products the store actually has.
	 *
	 * @return array{0:string,1:string,2:int}
	 */
	private function representative( int $tenant_id ): array {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return [ '', '', 0 ];
		}

		$query = [
			'status'     => 'publish',
			'limit'      => 1,
			'orderby'    => 'date',
			'order'      => 'DESC',
			'paginate'   => true,
			'meta_query' => [ [ 'key' => '_igbz_tenant_id', 'value' => $tenant_id ] ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		];

		$result   = wc_get_products( $query );
		$products = is_object( $result ) ? ( $result->products ?? [] ) : (array) $result;
		$total    = is_object( $result ) && isset( $result->total ) ? (int) $result->total : count( $products );

		$product = $products[0] ?? null;
		if ( ! $product ) {
			return [ '', '', $total ];
		}

		$image_id = (int) $product->get_image_id();
		$image    = $image_id > 0 ? (string) wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' ) : '';

		$category  = '';
		$term_ids  = $product->get_category_ids();
		if ( $term_ids ) {
			$term     = get_term( (int) $term_ids[0], 'product_cat' );
			$category = $term instanceof \WP_Term ? $term->name : '';
		}

		return [ $image, $category, $total ];
	}

	/**
	 * Instagram-style tile grid for one tenant, used by [igbz_hub_grid] and the hub REST route.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function grid( int $tenant_id, int $limit = 12 ): array {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return [];
		}

		$args = [
			'status'  => 'publish',
			'limit'   => max( 1, min( 48, $limit ) ),
			'orderby' => 'date',
			'order'   => 'DESC',
		];
		if ( $tenant_id > 0 ) {
			$args['meta_query'] = [ [ 'key' => '_igbz_tenant_id', 'value' => $tenant_id ] ]; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}

		$tiles = [];
		foreach ( wc_get_products( $args ) as $product ) {
			$image_id = (int) $product->get_image_id();
			$tiles[]  = [
				'product_id' => $product->get_id(),
				'name'       => $product->get_name(),
				'sku'        => $product->get_sku(),
				'price'      => (float) $product->get_price(),
				'image_url'  => $image_id > 0 ? (string) wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' ) : wc_placeholder_img_src(),
				'url'        => get_permalink( $product->get_id() ),
			];
		}

		return $tiles;
	}
}
