<?php
namespace IGBZ\Suite\Modules\Hub\Rest;

use IGBZ\Suite\Modules\Hub\Services\ContentBlockService;
use IGBZ\Suite\Modules\Hub\Services\DirectoryService;
use IGBZ\Suite\Modules\Hub\Services\DomainVerifier;
use IGBZ\Suite\Modules\Hub\Services\HubStats;
use IGBZ\Suite\Modules\Hub\Services\SignupService;
use IGBZ\Suite\Modules\Hub\Services\VipLinkService;
use IGBZ\Suite\Modules\MultiTenant\Payments\PaymentService;
use IGBZ\Suite\Modules\MultiTenant\Plans\PlanService;
use IGBZ\Suite\Modules\MultiTenant\Repository\Tenant;
use IGBZ\Suite\Modules\MultiTenant\Repository\TenantRepository;
use IGBZ\Suite\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * REST surface consumed by the separate mother site (the Next.js front end in the original
 * project). Public routes are read-only marketing data plus sign-up; administrative routes need
 * a logged-in super admin.
 *
 *   GET  /wp-json/igbz-hub/v1/landing
 *   GET  /wp-json/igbz-hub/v1/stores            ?limit=
 *   GET  /wp-json/igbz-hub/v1/stores/{slug}
 *   GET  /wp-json/igbz-hub/v1/plans
 *   GET  /wp-json/igbz-hub/v1/blocks            /blocks/{page_key}
 *   GET  /wp-json/igbz-hub/v1/check-slug        ?slug=
 *   POST /wp-json/igbz-hub/v1/signup
 *   POST /wp-json/igbz-hub/v1/signup/verify-payment
 *   GET  /wp-json/igbz-hub/v1/admin/summary
 *   GET  /wp-json/igbz-hub/v1/admin/domains
 *   POST /wp-json/igbz-hub/v1/admin/domains/{id}/verify
 *   POST /wp-json/igbz-hub/v1/admin/tenants/{id}/status
 *   POST /wp-json/igbz-hub/v1/admin/vip-link
 */
final class HubController {

	public const NAMESPACE = 'igbz-hub/v1';

	public function __construct(
		private HubStats $stats,
		private DirectoryService $directory,
		private SignupService $signup,
		private VipLinkService $vip,
		private DomainVerifier $domains,
		private ContentBlockService $blocks,
		private TenantRepository $tenants,
		private PlanService $plans
	) {}

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		$public = [ 'permission_callback' => '__return_true' ];
		$admin  = [ 'permission_callback' => [ $this, 'can_manage' ] ];

		register_rest_route( self::NAMESPACE, '/landing', $public + [ 'methods' => 'GET', 'callback' => [ $this, 'landing' ] ] );
		register_rest_route( self::NAMESPACE, '/stores', $public + [ 'methods' => 'GET', 'callback' => [ $this, 'stores' ] ] );
		register_rest_route( self::NAMESPACE, '/stores/(?P<slug>[a-z0-9\-]+)', $public + [ 'methods' => 'GET', 'callback' => [ $this, 'store' ] ] );
		register_rest_route( self::NAMESPACE, '/plans', $public + [ 'methods' => 'GET', 'callback' => [ $this, 'plans' ] ] );
		register_rest_route( self::NAMESPACE, '/blocks', $public + [ 'methods' => 'GET', 'callback' => [ $this, 'blocks' ] ] );
		register_rest_route( self::NAMESPACE, '/blocks/(?P<page_key>[a-z0-9_\-]+)', $public + [ 'methods' => 'GET', 'callback' => [ $this, 'block' ] ] );
		register_rest_route( self::NAMESPACE, '/check-slug', $public + [ 'methods' => 'GET', 'callback' => [ $this, 'check_slug' ] ] );
		register_rest_route( self::NAMESPACE, '/signup', $public + [ 'methods' => 'POST', 'callback' => [ $this, 'do_signup' ] ] );
		register_rest_route( self::NAMESPACE, '/signup/verify-payment', $public + [ 'methods' => 'POST', 'callback' => [ $this, 'verify_payment' ] ] );

		register_rest_route( self::NAMESPACE, '/admin/summary', $admin + [ 'methods' => 'GET', 'callback' => [ $this, 'admin_summary' ] ] );
		register_rest_route( self::NAMESPACE, '/admin/domains', $admin + [ 'methods' => 'GET', 'callback' => [ $this, 'admin_domains' ] ] );
		register_rest_route( self::NAMESPACE, '/admin/domains/(?P<id>\d+)/verify', $admin + [ 'methods' => 'POST', 'callback' => [ $this, 'admin_verify_domain' ] ] );
		register_rest_route( self::NAMESPACE, '/admin/tenants/(?P<id>\d+)/status', $admin + [ 'methods' => 'POST', 'callback' => [ $this, 'admin_set_status' ] ] );
		register_rest_route( self::NAMESPACE, '/admin/vip-link', $admin + [ 'methods' => 'POST', 'callback' => [ $this, 'admin_vip_link' ] ] );
	}

	public function can_manage(): bool {
		return Capabilities::current_user_can( Capabilities::MANAGE_TENANTS );
	}

	// -------------------------------------------------------------- public

	public function landing(): \WP_REST_Response {
		return rest_ensure_response(
			[
				'hero'       => [
					'title'       => igbz()->settings()->string( 'hub.hero_title', __( 'Build your Instagram store in one minute', 'igbz-suite' ) ),
					'description' => igbz()->settings()->string( 'hub.hero_description', __( 'A multilingual shop with its own domain, website and mobile app — no technical knowledge required.', 'igbz-suite' ) ),
				],
				'stores'     => $this->directory->featured(),
				'plans'      => $this->plan_payload(),
				'blocks'     => $this->blocks->all( true ),
				'statistics' => $this->public_statistics(),
			]
		);
	}

	/**
	 * Only figures that come from real rows are published. Uptime and "average setup time" from
	 * the original are gone: nothing in this system measures them.
	 *
	 * @return array<string,mixed>
	 */
	private function public_statistics(): array {
		$summary = $this->stats->summary();

		return [
			'active_stores'    => $summary['active_tenants'],
			'orders_processed' => $summary['orders'],
			'refreshed_at'     => $summary['refreshed_at'],
		];
	}

	public function stores( \WP_REST_Request $request ): \WP_REST_Response {
		$limit = (int) $request->get_param( 'limit' );
		return rest_ensure_response( [ 'stores' => $this->directory->featured( $limit ) ] );
	}

	public function store( \WP_REST_Request $request ): \WP_REST_Response {
		$tenant = $this->tenants->find_by_slug( (string) $request->get_param( 'slug' ) );
		if ( ! $tenant instanceof Tenant || ! $tenant->is_active() ) {
			return new \WP_REST_Response( [ 'error' => __( 'Store not found.', 'igbz-suite' ) ], 404 );
		}

		$card         = $this->directory->card( $tenant );
		$card['grid'] = $this->directory->grid( $tenant->id, (int) ( $request->get_param( 'grid' ) ?: 12 ) );

		return rest_ensure_response( $card );
	}

	public function plans(): \WP_REST_Response {
		return rest_ensure_response( [ 'plans' => $this->plan_payload() ] );
	}

	/** @return array<int,array<string,mixed>> */
	private function plan_payload(): array {
		$out = [];
		foreach ( $this->plans->plans( true ) as $plan ) {
			$features = json_decode( (string) ( $plan['features'] ?? '' ), true );
			$out[]    = [
				'id'             => (int) $plan['id'],
				'slug'           => (string) $plan['slug'],
				'name'           => (string) $plan['name'],
				'description'    => (string) $plan['description'],
				'price'          => (float) $plan['price'],
				'currency'       => (string) $plan['currency'],
				'billing_period' => (string) $plan['billing_period'],
				'trial_days'     => (int) $plan['trial_days'],
				'max_products'   => (int) $plan['max_products'],
				'max_orders'     => (int) $plan['max_orders'],
				'max_staff'      => (int) $plan['max_staff'],
				'features'       => is_array( $features ) ? $features : [],
			];
		}
		return $out;
	}

	public function blocks(): \WP_REST_Response {
		return rest_ensure_response( [ 'blocks' => $this->blocks->all( true ) ] );
	}

	public function block( \WP_REST_Request $request ): \WP_REST_Response {
		$block = $this->blocks->get( (string) $request->get_param( 'page_key' ) );
		if ( ! $block || ! $block['is_active'] ) {
			return new \WP_REST_Response( [ 'error' => __( 'Page not found.', 'igbz-suite' ) ], 404 );
		}
		return rest_ensure_response( $block );
	}

	public function check_slug( \WP_REST_Request $request ): \WP_REST_Response {
		return rest_ensure_response( $this->signup->check_slug( (string) $request->get_param( 'slug' ) ) );
	}

	public function do_signup( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! $this->signup->enabled() ) {
			return new \WP_REST_Response( [ 'ok' => false, 'error' => __( 'Self sign-up is disabled.', 'igbz-suite' ) ], 403 );
		}
		if ( ! $this->throttle( 'signup', 5, HOUR_IN_SECONDS ) ) {
			return new \WP_REST_Response( [ 'ok' => false, 'error' => __( 'Too many attempts. Try again later.', 'igbz-suite' ) ], 429 );
		}

		$result = $this->signup->signup( (array) $request->get_json_params() ?: $request->get_params() );

		return new \WP_REST_Response( $result, $result['ok'] ? 201 : 400 );
	}

	/**
	 * Close the loop the original left open: after the buyer comes back from the PSP the mother
	 * site calls this, the payment is verified server-side and the subscription is activated.
	 */
	public function verify_payment( \WP_REST_Request $request ): \WP_REST_Response {
		$payment_id = (int) $request->get_param( 'payment_id' );
		if ( $payment_id <= 0 ) {
			return new \WP_REST_Response( [ 'ok' => false, 'error' => __( 'payment_id is required.', 'igbz-suite' ) ], 400 );
		}

		/** @var PaymentService $payments */
		$payments = igbz()->get( 'payments' );
		$payment  = $payments->payment( $payment_id );
		if ( ! $payment ) {
			return new \WP_REST_Response( [ 'ok' => false, 'error' => __( 'Payment not found.', 'igbz-suite' ) ], 404 );
		}

		if ( PaymentService::STATUS_PAID === $payment['status'] ) {
			return rest_ensure_response(
				[
					'ok'                => true,
					'already_processed' => true,
					'tenant_id'         => (int) $payment['tenant_id'],
				]
			);
		}

		$params = (array) $request->get_json_params() ?: $request->get_params();
		$result = $payments->handle_callback( (string) $payment['gateway'], $payment_id, $params );

		if ( ! $result->success ) {
			return new \WP_REST_Response( [ 'ok' => false, 'error' => $result->error_message ], 400 );
		}

		$tenant_id = (int) $payment['tenant_id'];
		if ( $tenant_id > 0 ) {
			$this->tenants->set_status( $tenant_id, Tenant::STATUS_ACTIVE );
		}

		return rest_ensure_response(
			[
				'ok'                => true,
				'already_processed' => false,
				'tenant_id'         => $tenant_id,
				'reference_id'      => $result->reference_id,
			]
		);
	}

	// --------------------------------------------------------------- admin

	public function admin_summary( \WP_REST_Request $request ): \WP_REST_Response {
		return rest_ensure_response( $this->stats->summary( (bool) $request->get_param( 'refresh' ) ) );
	}

	public function admin_domains(): \WP_REST_Response {
		$db   = igbz()->db();
		$rows = $db->results(
			'SELECT d.*, t.name AS tenant_name, t.slug AS tenant_slug
			 FROM ' . $db->table( 'tenant_domains' ) . ' d
			 LEFT JOIN ' . $db->table( 'tenants' ) . ' t ON t.id = d.tenant_id
			 ORDER BY d.id DESC'
		);

		$out = [];
		foreach ( $rows as $row ) {
			$out[] = [
				'id'           => (int) $row['id'],
				'tenant_id'    => (int) $row['tenant_id'],
				'tenant_name'  => (string) ( $row['tenant_name'] ?? '' ),
				'domain'       => (string) $row['domain'],
				'is_primary'   => (bool) $row['is_primary'],
				'verified'     => null !== $row['verified_at'],
				'verified_at'  => $row['verified_at'],
				'instructions' => $this->domains->instructions( (string) $row['domain'], (string) $row['verification_token'] ),
			];
		}

		return rest_ensure_response( [ 'domains' => $out, 'cname_target' => $this->domains->expected_cname() ] );
	}

	public function admin_verify_domain( \WP_REST_Request $request ): \WP_REST_Response {
		$result = $this->domains->check( (int) $request->get_param( 'id' ) );
		return new \WP_REST_Response( $result, $result['ok'] ? 200 : 400 );
	}

	public function admin_set_status( \WP_REST_Request $request ): \WP_REST_Response {
		$tenant_id = (int) $request->get_param( 'id' );
		$status    = sanitize_key( (string) $request->get_param( 'status' ) );

		$allowed = [ Tenant::STATUS_ACTIVE, Tenant::STATUS_SUSPENDED, Tenant::STATUS_CLOSED, Tenant::STATUS_PENDING ];
		if ( ! in_array( $status, $allowed, true ) ) {
			return new \WP_REST_Response( [ 'ok' => false, 'error' => __( 'Unsupported status.', 'igbz-suite' ) ], 400 );
		}
		if ( ! $this->tenants->find( $tenant_id ) instanceof Tenant ) {
			return new \WP_REST_Response( [ 'ok' => false, 'error' => __( 'Tenant not found.', 'igbz-suite' ) ], 404 );
		}

		$this->tenants->set_status( $tenant_id, $status );
		$this->stats->flush();

		return rest_ensure_response( [ 'ok' => true, 'tenant_id' => $tenant_id, 'status' => $status ] );
	}

	public function admin_vip_link( \WP_REST_Request $request ): \WP_REST_Response {
		$tenant_id = (int) $request->get_param( 'tenant_id' );
		$user_id   = (int) $request->get_param( 'user_id' );
		$redirect  = (string) ( $request->get_param( 'redirect' ) ?: '/' );

		if ( ! $this->tenants->find( $tenant_id ) instanceof Tenant ) {
			return new \WP_REST_Response( [ 'ok' => false, 'error' => __( 'Tenant not found.', 'igbz-suite' ) ], 404 );
		}

		return rest_ensure_response(
			[
				'ok'         => true,
				'url'        => $this->vip->issue_url( $tenant_id, $user_id, $redirect ),
				'expires_in' => $this->vip->ttl(),
			]
		);
	}

	// ------------------------------------------------------------ throttle

	private function throttle( string $bucket, int $max, int $window ): bool {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$key = 'igbz_hub_thr_' . $bucket . '_' . md5( $ip );

		$hits = (int) get_transient( $key );
		if ( $hits >= $max ) {
			return false;
		}
		set_transient( $key, $hits + 1, $window );

		return true;
	}
}
