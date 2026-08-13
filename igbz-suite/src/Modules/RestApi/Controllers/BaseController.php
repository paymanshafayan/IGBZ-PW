<?php
namespace IGBZ\Suite\Modules\RestApi\Controllers;

use IGBZ\Suite\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Shared plumbing for every mobile API controller: the namespace, permission callbacks and
 * response helpers.
 *
 * Port note: the nop base class (`AuthorizedTenantOwnerApiController`) was referenced by five
 * controllers but never existed, so the whole admin API failed to compile. It also delegated its
 * tenant scoping to a filter that ran elsewhere. Here the check is in one place and every
 * privileged route calls it.
 */
abstract class BaseController {

	public const NAMESPACE = 'igbz/v1';

	abstract public function register_routes(): void;

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	// ------------------------------------------------------------ routing

	/**
	 * @param array<string,mixed> $args
	 * @return array<string,mixed>
	 */
	protected function route( string $methods, callable $callback, ?callable $permission = null, array $args = [] ): array {
		return [
			'methods'             => $methods,
			'callback'            => $callback,
			'permission_callback' => $permission ?? '__return_true',
			'args'                => $args,
		];
	}

	// -------------------------------------------------------- permissions

	public function is_logged_in(): bool|\WP_Error {
		if ( get_current_user_id() > 0 ) {
			return true;
		}
		return new \WP_Error( 'igbz_unauthorized', __( 'Authentication is required.', 'igbz-suite' ), [ 'status' => 401 ] );
	}

	public function can_manage_tenant(): bool|\WP_Error {
		$logged_in = $this->is_logged_in();
		if ( is_wp_error( $logged_in ) ) {
			return $logged_in;
		}

		if ( Capabilities::current_user_can( Capabilities::MANAGE_OWN_TENANT )
			|| Capabilities::current_user_can( Capabilities::MANAGE_TENANTS ) ) {
			return true;
		}

		return new \WP_Error( 'igbz_forbidden', __( 'This endpoint is limited to store owners.', 'igbz-suite' ), [ 'status' => 403 ] );
	}

	public function can_manage_platform(): bool|\WP_Error {
		$logged_in = $this->is_logged_in();
		if ( is_wp_error( $logged_in ) ) {
			return $logged_in;
		}
		if ( Capabilities::current_user_can( Capabilities::MANAGE_TENANTS ) ) {
			return true;
		}
		return new \WP_Error( 'igbz_forbidden', __( 'Super admin only.', 'igbz-suite' ), [ 'status' => 403 ] );
	}

	// ------------------------------------------------------------ context

	/**
	 * The tenant this request is allowed to act on. A store owner is pinned to their own tenant
	 * even if the client asks for a different id; a platform admin may pass ?tenant_id=.
	 */
	protected function scoped_tenant_id( ?\WP_REST_Request $request = null ): int {
		$tenancy = igbz()->tenancy();

		if ( Capabilities::current_user_can( Capabilities::MANAGE_TENANTS ) && $request ) {
			$requested = (int) $request->get_param( 'tenant_id' );
			if ( $requested > 0 ) {
				return $requested;
			}
		}

		$current = $tenancy->id();
		if ( $current > 0 && $tenancy->user_can_access( $current ) ) {
			return $current;
		}

		$accessible = $tenancy->accessible_tenant_ids();

		return $accessible ? (int) $accessible[0] : 0;
	}

	// ---------------------------------------------------------- responses

	/** @param mixed $data */
	protected function ok( $data, int $status = 200 ): \WP_REST_Response {
		return new \WP_REST_Response( $data, $status );
	}

	protected function fail( string $code, string $message, int $status = 400 ): \WP_REST_Response {
		return new \WP_REST_Response( [ 'ok' => false, 'code' => $code, 'error' => $message ], $status );
	}

	/**
	 * @param array<int,mixed> $items
	 * @return \WP_REST_Response
	 */
	protected function paged( array $items, int $total, int $page, int $per_page ): \WP_REST_Response {
		$response = new \WP_REST_Response(
			[
				'items'       => $items,
				'total'       => $total,
				'page'        => $page,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total / max( 1, $per_page ) ),
			]
		);
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) ceil( $total / max( 1, $per_page ) ) );

		return $response;
	}

	protected function page_args( \WP_REST_Request $request, int $default_per_page = 20 ): array {
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = (int) $request->get_param( 'per_page' );
		$per_page = $per_page > 0 ? min( 100, $per_page ) : $default_per_page;

		return [ $page, $per_page, ( $page - 1 ) * $per_page ];
	}
}
