<?php
namespace IGBZ\Suite\Modules\MultiTenant\Admin;

use IGBZ\Suite\Modules\MultiTenant\Domain\DomainService;
use IGBZ\Suite\Modules\MultiTenant\Domain\WebPresenceService;
use IGBZ\Suite\Support\Admin\Menu;
use IGBZ\Suite\Support\Admin\View;
use IGBZ\Suite\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Domain onboarding: subdomain (default) or standalone domain (search/
 * register/transfer), benefits, DNS verification, and web-presence status.
 */
final class DomainPage {

	public const SLUG = 'igbz-domains';

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_page' ], 17 );
	}

	public function add_page(): void {
		Menu::add( self::SLUG, __( 'Domain', 'igbz-suite' ), [ $this, 'render' ], Capabilities::MANAGE_SUITE );
	}

	public function render(): void {
		$this->handle_post();

		View::open(
			__( 'Domain', 'igbz-suite' ),
			__( 'Choose the mother-site subdomain or a standalone domain. A standalone domain unlocks the bank gateway and automatic Google/Bing registration.', 'igbz-suite' )
		);

		$tenant  = (int) igbz()->tenancy()->id();
		$domains = igbz()->get( 'domain' )->domains( $tenant );

		// Benefits banner.
		echo '<div class="notice notice-info"><p><strong>' . esc_html__( 'Why a standalone domain?', 'igbz-suite' ) . '</strong> ' . esc_html__( 'Brand trust, better SEO (automatic Google/Bing registration), a professional look, and full ownership of your domain. It is required to activate bank payment gateways.', 'igbz-suite' ) . '</p></div>';

		echo '<h2>' . esc_html__( 'My domains', 'igbz-suite' ) . '</h2>';
		if ( ! $domains ) {
			echo '<p>' . esc_html__( 'No domain yet.', 'igbz-suite' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Name', 'igbz-suite' ) . '</th><th>' . esc_html__( 'Type', 'igbz-suite' ) . '</th><th>' . esc_html__( 'Status', 'igbz-suite' ) . '</th><th>' . esc_html__( 'DNS', 'igbz-suite' ) . '</th></tr></thead><tbody>';
			foreach ( $domains as $d ) {
				printf(
					'<tr><td>%1$s</td><td>%2$s</td><td>%3$s</td><td>%4$s</td></tr>',
					esc_html( (string) $d['name'] ),
					esc_html( (string) $d['type'] ),
					esc_html( (string) $d['status'] ),
					(int) $d['dns_verified'] ? esc_html__( 'verified', 'igbz-suite' ) : esc_html__( 'pending', 'igbz-suite' )
				);
			}
			echo '</tbody></table>';
		}

		echo '<h2>' . esc_html__( 'Use the mother-site subdomain (free)', 'igbz-suite' ) . '</h2>';
		echo '<form method="post" style="max-width:420px">';
		wp_nonce_field( 'igbz_domain_sub' );
		printf( '<input type="hidden" name="igbz_dom_action" value="subdomain" />' );
		printf( '<p><input type="text" name="slug" class="regular-text" placeholder="mystore" required /> .%s</p>', esc_html( igbz()->settings()->string( 'domain.mother_subdomain', 'igbz.ir' ) ) );
		submit_button( __( 'Use subdomain', 'igbz-suite' ) );
		echo '</form>';

		echo '<h2>' . esc_html__( 'Register a standalone domain', 'igbz-suite' ) . '</h2>';
		echo '<form method="post" style="max-width:420px">';
		wp_nonce_field( 'igbz_domain_reg' );
		printf( '<input type="hidden" name="igbz_dom_action" value="register" />' );
		echo '<p><input type="text" name="name" class="regular-text" placeholder="mystore" required /> .<select name="tld"><option value="ir">ir</option><option value="com">com</option></select></p>';
		submit_button( __( 'Register now', 'igbz-suite' ) );
		echo '</form>';

		echo '<h2>' . esc_html__( 'Web presence', 'igbz-suite' ) . '</h2>';
		$wp = igbz()->get( 'webpresence' )->status( $tenant );
		if ( ! $wp ) {
			echo '<p>' . esc_html__( 'Register a verified domain first, then run web presence.', 'igbz-suite' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Service', 'igbz-suite' ) . '</th><th>' . esc_html__( 'Status', 'igbz-suite' ) . '</th><th>' . esc_html__( 'Detail', 'igbz-suite' ) . '</th></tr></thead><tbody>';
			foreach ( $wp as $w ) {
				printf( '<tr><td>%1$s</td><td>%2$s</td><td>%3$s</td></tr>', esc_html( (string) $w['service'] ), esc_html( (string) $w['status'] ), esc_html( (string) $w['detail'] ) );
			}
			echo '</tbody></table>';
		}

		View::close();
	}

	private function handle_post(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$action = isset( $_POST['igbz_dom_action'] ) ? sanitize_key( (string) $_POST['igbz_dom_action'] ) : '';
		if ( '' === $action ) {
			return;
		}
		$tenant = (int) igbz()->tenancy()->id();

		if ( 'subdomain' === $action ) {
			View::check_nonce( 'igbz_domain_sub' );
			$result = igbz()->get( 'domain' )->use_subdomain( $tenant, sanitize_title( (string) ( $_POST['slug'] ?? '' ) ) );
			View::notice( $result['ok'] ? __( 'Subdomain set.', 'igbz-suite' ) : $result['error'], $result['ok'] ? 'success' : 'error' );
			return;
		}

		if ( 'register' === $action ) {
			View::check_nonce( 'igbz_domain_reg' );
			$result = igbz()->get( 'domain' )->register(
				$tenant,
				sanitize_title( (string) ( $_POST['name'] ?? '' ) ),
				sanitize_key( (string) ( $_POST['tld'] ?? 'ir' ) )
			);
			View::notice( $result['ok'] ? __( 'Domain order created.', 'igbz-suite' ) : $result['error'], $result['ok'] ? 'success' : 'error' );
		}
	}
}
