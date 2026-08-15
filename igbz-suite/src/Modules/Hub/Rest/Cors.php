<?php
namespace IGBZ\Suite\Modules\Hub\Rest;

defined( 'ABSPATH' ) || exit;

/**
 * CORS for the hub namespace only.
 *
 * Port note: the nopCommerce hub registered `AllowAnyOrigin()`, so any site on the internet could
 * read the platform's public API with credentials disabled but no origin control at all. Here the
 * allow-list is exactly the configured mother origin (plus anything a site owner opts into with
 * the `igbz_hub_allowed_origins` filter). There is no wildcard code path.
 */
final class Cors {

	public function register(): void {
		add_filter( 'rest_pre_serve_request', [ $this, 'send_headers' ], 10, 4 );
		add_action( 'rest_api_init', [ $this, 'handle_preflight' ], 15 );
	}

	/** @return string[] */
	public static function allowed_origins(): array {
		$origins = [];

		$mother = igbz()->settings()->string( 'hub.mother_origin', '' );
		foreach ( preg_split( '/[\s,]+/', $mother ) ?: [] as $candidate ) {
			$candidate = self::normalize( (string) $candidate );
			if ( '' !== $candidate ) {
				$origins[] = $candidate;
			}
		}

		/** @var string[] $filtered */
		$filtered = (array) apply_filters( 'igbz_hub_allowed_origins', $origins );

		return array_values( array_unique( array_filter( array_map( [ self::class, 'normalize' ], $filtered ) ) ) );
	}

	public static function normalize( string $origin ): string {
		$origin = trim( $origin );
		if ( '' === $origin || '*' === $origin ) {
			return '';
		}
		$parts = wp_parse_url( $origin );
		if ( empty( $parts['host'] ) ) {
			return '';
		}
		$scheme = $parts['scheme'] ?? 'https';
		$port   = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';

		return strtolower( $scheme . '://' . $parts['host'] . $port );
	}

	public static function is_allowed( string $origin ): bool {
		$origin = self::normalize( $origin );
		return '' !== $origin && in_array( $origin, self::allowed_origins(), true );
	}

	private static function current_origin(): string {
		return isset( $_SERVER['HTTP_ORIGIN'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ) : '';
	}

	private static function is_hub_route(): bool {
		$route = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		return str_contains( $route, '/' . HubController::NAMESPACE . '/' );
	}

	/**
	 * @param bool              $served
	 * @param mixed             $result
	 * @param \WP_REST_Request  $request
	 * @param \WP_REST_Server   $server
	 */
	public function send_headers( $served, $result, $request, $server ) {
		if ( ! $request instanceof \WP_REST_Request || ! str_starts_with( ltrim( $request->get_route(), '/' ), HubController::NAMESPACE ) ) {
			return $served;
		}

		$origin = self::current_origin();
		if ( '' === $origin || ! self::is_allowed( $origin ) ) {
			// Same-origin requests carry no Origin header and need nothing; a foreign origin gets
			// no CORS header at all, which the browser treats as a refusal.
			return $served;
		}

		header( 'Access-Control-Allow-Origin: ' . self::normalize( $origin ) );
		header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
		header( 'Access-Control-Allow-Headers: Content-Type, Authorization, X-IGBZ-Token, X-WP-Nonce' );
		header( 'Access-Control-Max-Age: 600' );
		header( 'Vary: Origin', false );

		return $served;
	}

	/** Answer the browser preflight without booting the rest of the route. */
	public function handle_preflight(): void {
		if ( 'OPTIONS' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! self::is_hub_route() ) {
			return;
		}

		$origin = self::current_origin();
		if ( ! self::is_allowed( $origin ) ) {
			status_header( 403 );
			exit;
		}

		header( 'Access-Control-Allow-Origin: ' . self::normalize( $origin ) );
		header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
		header( 'Access-Control-Allow-Headers: Content-Type, Authorization, X-IGBZ-Token, X-WP-Nonce' );
		header( 'Access-Control-Max-Age: 600' );
		header( 'Vary: Origin', false );
		status_header( 204 );
		exit;
	}
}
