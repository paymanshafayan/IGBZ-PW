<?php
namespace IGBZ\Suite\Modules\MultiTenant\Lms;

use IGBZ\Suite\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Public certificate verification: /{lms.certificate_slug}/{CODE}
 *
 * A certificate code that nobody can check is decoration. This is the page an employer opens
 * after a candidate types the code from their CV, so it is deliberately public, deliberately
 * thin — the holder's name, the course, the date — and says nothing about the enrollment, the
 * order or the student's contact details.
 *
 * It follows the VIP share page rather than a WordPress page for the same reason: the URL is
 * printed on certificates that are already out in the world, so it cannot depend on a page a shop
 * owner might rename or unpublish. The ?igbz_certificate= fallback keeps it working on a site
 * with plain permalinks.
 */
final class CertificatePage {

	public const QUERY_VAR = 'igbz_certificate';

	public function __construct( private LmsService $lms, private Settings $settings ) {}

	public function register(): void {
		add_action( 'init', [ $this, 'add_rules' ] );
		add_filter( 'query_vars', [ $this, 'add_query_var' ] );
		add_action( 'template_redirect', [ $this, 'maybe_render' ] );
	}

	public function add_rules(): void {
		add_rewrite_rule(
			'^' . preg_quote( $this->slug(), '/' ) . '/([^/]+)/?$',
			'index.php?' . self::QUERY_VAR . '=$matches[1]',
			'top'
		);
	}

	/**
	 * @param array<int,string> $vars
	 * @return array<int,string>
	 */
	public function add_query_var( $vars ): array {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	private function slug(): string {
		$slug = trim( $this->settings->string( 'lms.certificate_slug', 'certificate' ), '/' );
		return '' !== $slug ? $slug : 'certificate';
	}

	/** The URL printed on the certificate itself. */
	public function url( string $code ): string {
		return home_url( '/' . $this->slug() . '/' . rawurlencode( $code ) );
	}

	// ------------------------------------------------------------- rendering

	public function maybe_render(): void {
		$code = get_query_var( self::QUERY_VAR );

		if ( '' === $code ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public read-only page.
			$code = isset( $_GET[ self::QUERY_VAR ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::QUERY_VAR ] ) ) : '';
		}

		if ( '' === (string) $code ) {
			return;
		}

		if ( ! $this->settings->bool( 'lms.enabled', true ) || ! $this->lms->certificates_enabled() ) {
			$this->render_unknown( (string) $code );
		}

		$certificate = $this->lms->certificate( (string) $code );
		if ( ! $certificate ) {
			$this->render_unknown( (string) $code );
		}

		$this->render( $certificate );
	}

	/** @param array{code:string,student:string,course:string,completed_at:string,progress:int} $certificate */
	private function render( array $certificate ): void {
		status_header( 200 );
		nocache_headers();

		$completed = '' !== $certificate['completed_at']
			? date_i18n( (string) get_option( 'date_format' ), (int) ( strtotime( $certificate['completed_at'] . ' UTC' ) ?: time() ) )
			: '';

		$this->head( __( 'Certificate verification', 'igbz-suite' ) );

		echo '<div class="igbz-cert-wrap"><div class="igbz-cert-card igbz-cert-valid">';
		printf( '<p class="igbz-cert-badge">%s</p>', esc_html__( 'Verified', 'igbz-suite' ) );
		printf( '<h1>%s</h1>', esc_html__( 'This certificate is genuine', 'igbz-suite' ) );

		echo '<dl class="igbz-cert-facts">';
		if ( '' !== $certificate['student'] ) {
			printf( '<dt>%s</dt><dd>%s</dd>', esc_html__( 'Awarded to', 'igbz-suite' ), esc_html( $certificate['student'] ) );
		}
		printf( '<dt>%s</dt><dd>%s</dd>', esc_html__( 'Course', 'igbz-suite' ), esc_html( $certificate['course'] ) );
		if ( '' !== $completed ) {
			printf( '<dt>%s</dt><dd>%s</dd>', esc_html__( 'Completed', 'igbz-suite' ), esc_html( $completed ) );
		}
		printf( '<dt>%s</dt><dd><code>%s</code></dd>', esc_html__( 'Code', 'igbz-suite' ), esc_html( $certificate['code'] ) );
		echo '</dl>';

		printf(
			'<p class="igbz-cert-issuer">%s</p>',
			esc_html(
				sprintf(
					/* translators: %s: site name */
					__( 'Issued by %s.', 'igbz-suite' ),
					(string) get_bloginfo( 'name' )
				)
			)
		);

		$this->lookup_form( '' );
		echo '</div></div>';

		$this->foot();
		exit;
	}

	/**
	 * An unknown code gets 404, not a friendly 200.
	 *
	 * The page is a verification tool, and a tool that answers "no" with a success status will
	 * eventually be scripted by somebody who only checks the status code.
	 */
	private function render_unknown( string $code ): void {
		status_header( 404 );
		nocache_headers();

		$this->head( __( 'Certificate not found', 'igbz-suite' ) );

		echo '<div class="igbz-cert-wrap"><div class="igbz-cert-card igbz-cert-invalid">';
		printf( '<p class="igbz-cert-badge">%s</p>', esc_html__( 'Not found', 'igbz-suite' ) );
		printf( '<h1>%s</h1>', esc_html__( 'We cannot verify this code', 'igbz-suite' ) );
		printf(
			'<p>%s</p>',
			esc_html__( 'No certificate with this code has been issued. Check for a typo — the code is a group of letters and digits after IGBZ- — or ask the holder to re-send it.', 'igbz-suite' )
		);

		$this->lookup_form( $code );
		echo '</div></div>';

		$this->foot();
		exit;
	}

	/** The "check another code" box, which is also the page's own entry point. */
	private function lookup_form( string $value ): void {
		printf( '<form class="igbz-cert-form" method="get" action="%s">', esc_url( home_url( '/' ) ) );
		printf(
			'<label for="igbz-cert-code">%s</label>',
			esc_html__( 'Check another certificate', 'igbz-suite' )
		);
		printf(
			'<input type="text" id="igbz-cert-code" name="%1$s" value="%2$s" placeholder="%3$s" required />',
			esc_attr( self::QUERY_VAR ),
			esc_attr( $value ),
			esc_attr( 'IGBZ-0123456789AB' )
		);
		printf( '<button type="submit">%s</button>', esc_html__( 'Verify', 'igbz-suite' ) );
		echo '</form>';
	}

	private function head( string $title ): void {
		header( 'Content-Type: text/html; charset=utf-8' );
		printf(
			'<!DOCTYPE html><html lang="%1$s"%2$s><head><meta charset="utf-8" />'
			. '<meta name="viewport" content="width=device-width, initial-scale=1" />'
			. '<meta name="robots" content="noindex, follow" /><title>%3$s</title>'
			. '<link rel="stylesheet" href="%4$s" /></head><body class="igbz-certificate">',
			esc_attr( (string) get_bloginfo( 'language' ) ),
			is_rtl() ? ' dir="rtl"' : '',
			esc_html( $title . ' — ' . get_bloginfo( 'name' ) ),
			esc_url( IGBZ_URL . 'assets/css/certificate.css?ver=' . IGBZ_VERSION )
		);
	}

	private function foot(): void {
		echo '</body></html>';
	}
}
