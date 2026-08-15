<?php
namespace IGBZ\Suite\Modules\Hub\Frontend;

use IGBZ\Suite\Modules\Hub\Services\ContentBlockService;
use IGBZ\Suite\Modules\Hub\Services\DirectoryService;
use IGBZ\Suite\Modules\Hub\Services\HubStats;

defined( 'ABSPATH' ) || exit;

/**
 * Shortcodes for running the mother site on WordPress itself, instead of the separate Next.js
 * front end. Every one of them reads the same data the hub REST routes return.
 *
 *   [igbz_store_directory limit="12" columns="4"]
 *   [igbz_hub_grid tenant="12" limit="12"]
 *   [igbz_hub_stats]
 *   [igbz_hub_blocks]
 */
final class HubShortCodes {

	public function register(): void {
		add_shortcode( 'igbz_store_directory', [ $this, 'directory' ] );
		add_shortcode( 'igbz_hub_grid', [ $this, 'grid' ] );
		add_shortcode( 'igbz_hub_stats', [ $this, 'stats' ] );
		add_shortcode( 'igbz_hub_blocks', [ $this, 'blocks' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'register_assets' ] );
	}

	public function register_assets(): void {
		wp_register_style( 'igbz-hub', IGBZ_URL . 'assets/css/hub.css', [], IGBZ_VERSION );
	}

	private function assets(): void {
		wp_enqueue_style( 'igbz-hub' );
	}

	private function directory_service(): DirectoryService {
		return igbz()->get( 'hub.directory' );
	}

	/** @param array<string,string>|string $atts */
	public function directory( $atts = [] ): string {
		$atts = shortcode_atts( [ 'limit' => 12, 'columns' => 4 ], (array) $atts, 'igbz_store_directory' );
		$this->assets();

		$stores = $this->directory_service()->featured( (int) $atts['limit'] );
		if ( ! $stores ) {
			return '<p class="igbz-empty">' . esc_html__( 'No stores to show yet.', 'igbz-suite' ) . '</p>';
		}

		ob_start();
		printf( '<div class="igbz-store-grid igbz-cols-%d">', (int) $atts['columns'] );
		foreach ( $stores as $store ) {
			printf( '<a class="igbz-store-card" href="%s">', esc_url( (string) $store['url'] ) );
			if ( '' !== (string) $store['logo_url'] ) {
				printf(
					'<img src="%1$s" alt="%2$s" loading="lazy" />',
					esc_url( (string) $store['logo_url'] ),
					esc_attr( (string) $store['name'] )
				);
			} else {
				printf( '<span class="igbz-store-initial">%s</span>', esc_html( mb_substr( (string) $store['name'], 0, 1 ) ) );
			}
			printf( '<strong>%s</strong>', esc_html( (string) $store['name'] ) );
			if ( '' !== (string) $store['category'] ) {
				printf( '<span class="igbz-store-cat">%s</span>', esc_html( (string) $store['category'] ) );
			}
			printf(
				'<span class="igbz-store-count">%s</span>',
				esc_html(
					sprintf(
						/* translators: %d: product count */
						_n( '%d product', '%d products', (int) $store['product_count'], 'igbz-suite' ),
						(int) $store['product_count']
					)
				)
			);
			echo '</a>';
		}
		echo '</div>';

		return (string) ob_get_clean();
	}

	/** @param array<string,string>|string $atts */
	public function grid( $atts = [] ): string {
		$atts = shortcode_atts( [ 'tenant' => 0, 'limit' => 12 ], (array) $atts, 'igbz_hub_grid' );
		$this->assets();

		$tenant_id = (int) $atts['tenant'] ?: igbz()->tenancy()->id();
		$tiles     = $this->directory_service()->grid( $tenant_id, (int) $atts['limit'] );

		if ( ! $tiles ) {
			return '<p class="igbz-empty">' . esc_html__( 'No products to show yet.', 'igbz-suite' ) . '</p>';
		}

		ob_start();
		echo '<div class="igbz-ig-grid">';
		foreach ( $tiles as $tile ) {
			printf(
				'<a class="igbz-ig-tile" href="%1$s"><img src="%2$s" alt="%3$s" loading="lazy" /><span class="igbz-ig-meta"><strong>%3$s</strong><em>%4$s</em></span></a>',
				esc_url( (string) $tile['url'] ),
				esc_url( (string) $tile['image_url'] ),
				esc_attr( (string) $tile['name'] ),
				esc_html( function_exists( 'wc_price' ) ? wp_strip_all_tags( wc_price( (float) $tile['price'] ) ) : (string) $tile['price'] )
			);
		}
		echo '</div>';

		return (string) ob_get_clean();
	}

	public function stats(): string {
		$this->assets();

		/** @var HubStats $service */
		$service = igbz()->get( 'hub.stats' );
		$summary = $service->summary();

		$cards = [
			__( 'Active stores', 'igbz-suite' ) => number_format_i18n( (int) $summary['active_tenants'] ),
			__( 'Paid orders', 'igbz-suite' )   => number_format_i18n( (int) $summary['orders'] ),
		];

		ob_start();
		echo '<div class="igbz-hub-stats">';
		foreach ( $cards as $label => $value ) {
			printf( '<div class="igbz-hub-stat"><strong>%1$s</strong><span>%2$s</span></div>', esc_html( $value ), esc_html( $label ) );
		}
		echo '</div>';

		return (string) ob_get_clean();
	}

	public function blocks(): string {
		$this->assets();

		/** @var ContentBlockService $service */
		$service = igbz()->get( 'hub.blocks' );

		ob_start();
		echo '<div class="igbz-hub-blocks">';
		foreach ( $service->all( true ) as $block ) {
			echo '<section class="igbz-hub-block">';
			if ( '' !== (string) $block['image_url'] ) {
				printf( '<img src="%1$s" alt="%2$s" loading="lazy" />', esc_url( (string) $block['image_url'] ), esc_attr( (string) $block['title'] ) );
			}
			printf( '<h3>%s</h3>', esc_html( (string) $block['title'] ) );
			printf( '<p>%s</p>', esc_html( (string) $block['summary'] ) );
			if ( $block['bullets'] ) {
				echo '<ul>';
				foreach ( (array) $block['bullets'] as $bullet ) {
					printf( '<li>%s</li>', esc_html( (string) $bullet ) );
				}
				echo '</ul>';
			}
			if ( '' !== (string) $block['cta_text'] && '' !== (string) $block['cta_url'] ) {
				printf(
					'<a class="button igbz-hub-cta" href="%1$s">%2$s</a>',
					esc_url( (string) $block['cta_url'] ),
					esc_html( (string) $block['cta_text'] )
				);
			}
			echo '</section>';
		}
		echo '</div>';

		return (string) ob_get_clean();
	}
}
