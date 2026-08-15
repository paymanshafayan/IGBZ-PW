<?php
namespace IGBZ\Suite\Support\Admin;

use IGBZ\Suite\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Single admin menu for the whole suite.
 *
 * Port note: the nopCommerce original never implemented IAdminMenuPlugin, so roughly 26 admin
 * controllers were only reachable by typing a URL. Here every screen is registered as a submenu
 * of one top-level "IGBZ" menu and is capability gated.
 */
final class Menu {

	public const SLUG = 'igbz';

	/** Register the top-level entry once, no matter which page asks first. */
	public static function ensure_parent(): void {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;

		add_menu_page(
			__( 'IGBZ Suite', 'igbz-suite' ),
			__( 'IGBZ', 'igbz-suite' ),
			Capabilities::MANAGE_SUITE,
			self::SLUG,
			[ StatusPage::class, 'render_static' ],
			'dashicons-store',
			56
		);
	}

	/**
	 * Add a submenu page.
	 *
	 * @param callable $render
	 */
	public static function add( string $slug, string $menu_title, $render, string $capability = Capabilities::MANAGE_SUITE ): void {
		self::ensure_parent();

		add_submenu_page(
			self::SLUG,
			$menu_title,
			$menu_title,
			$capability,
			$slug,
			static function () use ( $render, $capability ): void {
				Capabilities::require( $capability );
				$render();
			}
		);
	}

	public static function url( string $slug, array $args = [] ): string {
		return add_query_arg( array_merge( [ 'page' => $slug ], $args ), admin_url( 'admin.php' ) );
	}

	public static function is_igbz_screen(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading the current screen only.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		return str_starts_with( $page, 'igbz' );
	}
}
