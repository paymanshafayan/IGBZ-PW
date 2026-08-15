<?php
namespace IGBZ\Suite\Modules\MultiTenant;

use IGBZ\Suite\Modules\MultiTenant\Affiliate\AffiliateService;
use IGBZ\Suite\Modules\MultiTenant\Bnpl\BnplGateway;
use IGBZ\Suite\Modules\MultiTenant\Bnpl\BnplService;
use IGBZ\Suite\Modules\MultiTenant\Bnpl\ProviderRegistry;
use IGBZ\Suite\Modules\MultiTenant\Lms\LmsService;
use IGBZ\Suite\Modules\MultiTenant\Marketplace\MarketplaceService;
use IGBZ\Suite\Modules\MultiTenant\Otp\OtpService;
use IGBZ\Suite\Modules\MultiTenant\Payments\CallbackHandler;
use IGBZ\Suite\Modules\MultiTenant\Payments\PaymentService;
use IGBZ\Suite\Modules\MultiTenant\Payments\PspGateway;
use IGBZ\Suite\Modules\MultiTenant\Plans\PlanService;
use IGBZ\Suite\Modules\MultiTenant\Repository\TenantRepository;
use IGBZ\Suite\Modules\MultiTenant\Wallet\WalletGateway;
use IGBZ\Suite\Modules\MultiTenant\Wallet\WalletService;
use IGBZ\Suite\Support\Cron;
use IGBZ\Suite\Support\ModuleInterface;
use IGBZ\Suite\Support\Modules;
use IGBZ\Suite\Support\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Port of the nopCommerce "IGBZ.MultiTenantStores" plugin.
 *
 * Owns tenants, wallet, plans/subscriptions, BNPL, affiliate marketing, the LMS, the PSP layer,
 * phone OTP and the marketplace feeds. Every service is registered in the container so the other
 * modules (and third-party code) can reuse them.
 */
final class MultiTenantModule implements ModuleInterface {

	public function id(): string {
		return Modules::MULTITENANT;
	}

	public function title(): string {
		return __( 'Multi-tenant stores', 'igbz-suite' );
	}

	public function description(): string {
		return __( 'Tenants, wallet, subscription plans, instalments (BNPL), affiliate marketing, courses, payment gateways, phone OTP and marketplace feeds.', 'igbz-suite' );
	}

	public function register( Plugin $plugin ): void {
		$this->bind_services( $plugin );

		// --- storefront / account plumbing -----------------------------------
		add_action( 'init', [ $this, 'capture_referral' ], 5 );
		add_action( 'init', [ $this, 'maybe_render_feed' ], 6 );
		( new CallbackHandler() )->register();

		// --- WooCommerce integration -----------------------------------------
		add_filter( 'woocommerce_payment_gateways', [ $this, 'register_gateways' ] );
		add_action( 'woocommerce_order_status_completed', [ $this, 'on_order_completed' ] );
		add_action( 'woocommerce_order_status_processing', [ $this, 'on_order_completed' ] );
		add_action( 'woocommerce_order_status_refunded', [ $this, 'on_order_reversed' ] );
		add_action( 'woocommerce_order_status_cancelled', [ $this, 'on_order_reversed' ] );
		add_action( 'woocommerce_order_status_failed', [ $this, 'on_order_reversed' ] );
		// A partial refund never changes the order status, so the status hooks above never fire
		// for one. Refunding a course line item out of a mixed order has to revoke that course.
		add_action( 'woocommerce_order_refunded', [ $this, 'on_order_partially_refunded' ], 10, 2 );
		add_action( 'woocommerce_checkout_order_created', [ $this, 'stamp_tenant_on_order' ] );
		add_action( 'user_register', [ $this, 'on_user_register' ] );

		// --- scheduled work ----------------------------------------------------
		add_action( Cron::HOOK_HOURLY, [ $this, 'run_hourly' ] );
		add_action( Cron::HOOK_DAILY, [ $this, 'run_daily' ] );

		// --- admin -------------------------------------------------------------
		if ( is_admin() ) {
			( new Admin\TenantsPage() )->register();
			( new Admin\WalletPage() )->register();
			( new Admin\PlansPage() )->register();
			( new Admin\BnplPage() )->register();
			( new Admin\AffiliatePage() )->register();
			( new Admin\LmsPage() )->register();
			( new Admin\PaymentsPage() )->register();
		}

		( new Frontend\AccountEndpoints() )->register();
		( new Frontend\ShortCodes() )->register();

		( new Lms\CertificatePage( $plugin->get( 'lms' ), $plugin->settings() ) )->register();
	}

	private function bind_services( Plugin $plugin ): void {
		$plugin->bind( 'tenants', static fn ( Plugin $c ) => new TenantRepository( $c->db() ) );
		$plugin->bind( 'wallet', static fn ( Plugin $c ) => new WalletService( $c->db(), $c->logger() ) );
		$plugin->bind( 'plans', static fn ( Plugin $c ) => new PlanService( $c->db(), $c->get( 'wallet' ), $c->logger() ) );
		$plugin->bind( 'bnpl.providers', static fn () => new ProviderRegistry() );
		$plugin->bind(
			'bnpl',
			static fn ( Plugin $c ) => new BnplService( $c->db(), $c->get( 'wallet' ), $c->logger(), $c->get( 'bnpl.providers' ) )
		);
		$plugin->bind( 'affiliate', static fn ( Plugin $c ) => new AffiliateService( $c->db(), $c->get( 'wallet' ), $c->logger() ) );
		$plugin->bind( 'lms', static fn ( Plugin $c ) => new LmsService( $c->db() ) );
		$plugin->bind(
			'payments',
			static fn ( Plugin $c ) => new PaymentService( $c->db(), $c->http(), $c->get( 'wallet' ), $c->logger() )
		);
		$plugin->bind( 'otp', static fn ( Plugin $c ) => new OtpService( $c->db(), $c->http(), $c->logger() ) );
		$plugin->bind( 'marketplace', static fn ( Plugin $c ) => new MarketplaceService( $c->db(), $c->logger() ) );
	}

	// ------------------------------------------------------------------ hooks

	public function capture_referral(): void {
		if ( is_admin() || wp_doing_cron() ) {
			return;
		}
		if ( ! igbz()->settings()->bool( 'affiliate.enabled', true ) ) {
			return;
		}
		igbz()->get( 'affiliate' )->capture_click();
	}

	/**
	 * Public marketplace feed: /?igbz_feed=torob[&tenant=12].
	 *
	 * Port note: in the nop original this lived in a controller that the compatibility document
	 * claimed did not exist; here it is a single early-init responder with no theme overhead.
	 */
	public function maybe_render_feed(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- public read-only feed.
		if ( empty( $_GET['igbz_feed'] ) ) {
			return;
		}
		$channel = sanitize_key( wp_unslash( $_GET['igbz_feed'] ) );
		$tenant  = isset( $_GET['tenant'] ) ? absint( wp_unslash( $_GET['tenant'] ) ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		/** @var MarketplaceService $marketplace */
		$marketplace = igbz()->get( 'marketplace' );

		if ( ! in_array( $channel, array_keys( $marketplace->channels() ), true ) || ! $marketplace->is_channel_enabled( $channel ) ) {
			status_header( 404 );
			nocache_headers();
			exit;
		}

		if ( $tenant > 0 ) {
			igbz()->tenancy()->force( $tenant );
		}

		$body = $marketplace->render_feed( $channel, $tenant );

		status_header( 200 );
		header( 'Content-Type: ' . $marketplace->feed_content_type( $channel ) );
		header( 'X-Robots-Tag: noindex' );
		header( 'Cache-Control: public, max-age=' . igbz()->settings()->int( 'marketplace.cache_ttl', 900 ) );
		echo $body; // phpcs:ignore WordPress.Security.EscapingOutput -- feed body is built and escaped by MarketplaceService.
		exit;
	}

	/**
	 * @param array<int,string> $gateways
	 * @return array<int,string|\WC_Payment_Gateway>
	 */
	public function register_gateways( $gateways ): array {
		$gateways   = is_array( $gateways ) ? $gateways : [];
		$settings   = igbz()->settings();

		if ( $settings->bool( 'wallet.enabled', true ) ) {
			$gateways[] = new WalletGateway();
		}
		if ( $settings->bool( 'bnpl.enabled', true ) ) {
			$gateways[] = new BnplGateway();
		}

		/**
		 * Every adapter is registered, not just the enabled ones, so each keeps its own row in
		 * WooCommerce > Settings > Payments. PspGateway::is_available() is what hides a gateway
		 * that is switched off or missing its credentials from the actual checkout.
		 */
		/** @var PaymentService $payments */
		$payments = igbz()->get( 'payments' );
		foreach ( $payments->gateways() as $adapter ) {
			$gateways[] = new PspGateway( $adapter );
		}

		return $gateways;
	}

	/** @param int $order_id */
	public function on_order_completed( $order_id ): void {
		$order_id = (int) $order_id;

		if ( igbz()->settings()->bool( 'affiliate.enabled', true ) ) {
			igbz()->get( 'affiliate' )->record_order_commission( $order_id );
		}
		if ( igbz()->settings()->bool( 'lms.enabled', true ) ) {
			igbz()->get( 'lms' )->enroll_from_order( $order_id );
		}

		$this->maybe_cashback( $order_id );
	}

	/**
	 * A refunded, cancelled or failed order: take back everything it granted.
	 *
	 * The commission was always voided here. Course access was not, so a customer could buy a
	 * course, watch it, ask for a refund and keep it — the enrollment row outlived the order that
	 * paid for it and nothing ever looked at it again.
	 *
	 * @param int $order_id
	 */
	public function on_order_reversed( $order_id ): void {
		$order_id = (int) $order_id;

		igbz()->get( 'affiliate' )->void_order_commission( $order_id );

		if ( igbz()->settings()->bool( 'lms.enabled', true ) && igbz()->settings()->bool( 'lms.revoke_on_refund', true ) ) {
			$revoked = igbz()->get( 'lms' )->revoke_from_order( $order_id );
			if ( $revoked > 0 ) {
				igbz()->logger()->info(
					'lms',
					sprintf( 'revoked %d enrollment(s) for reversed order %d', $revoked, $order_id ),
					[ 'order_id' => $order_id, 'count' => $revoked ]
				);
			}
		}
	}

	/**
	 * A partial refund: revoke only the courses whose line items were actually refunded.
	 *
	 * WooCommerce records a refund as a child order holding negative quantities, so "was this
	 * line refunded?" is `get_qty_refunded_for_item() < 0`. Refunding the shipping on an order
	 * that also contains a course must not cost the customer the course.
	 *
	 * @param int $order_id
	 * @param int $refund_id
	 */
	public function on_order_partially_refunded( $order_id, $refund_id ): void {
		$order_id = (int) $order_id;

		if ( ! igbz()->settings()->bool( 'lms.enabled', true ) || ! igbz()->settings()->bool( 'lms.revoke_on_refund', true ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		// A full refund flips the status too, and that path already revokes everything; letting
		// both run would double-log the same revocation.
		if ( $order->has_status( [ 'refunded', 'cancelled', 'failed' ] ) ) {
			return;
		}

		$user_id = (int) $order->get_customer_id();
		if ( $user_id <= 0 ) {
			return;
		}

		/** @var LmsService $lms */
		$lms = igbz()->get( 'lms' );

		foreach ( $order->get_items() as $item_id => $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			if ( (float) $order->get_qty_refunded_for_item( (int) $item_id ) >= 0 ) {
				continue;
			}

			$course = $lms->course_by_product( $item->get_product_id() );
			if ( ! $course ) {
				continue;
			}

			$enrollment = $lms->enrollment( (int) $course['id'], $user_id );
			// Only the access this order granted; a second purchase or a manual enrollment stands.
			if ( ! $enrollment || (int) $enrollment['order_id'] !== $order_id ) {
				continue;
			}

			$lms->unenroll( (int) $course['id'], $user_id );
			igbz()->logger()->info(
				'lms',
				sprintf( 'revoked course %d for user %d after a partial refund on order %d', (int) $course['id'], $user_id, $order_id ),
				[ 'order_id' => $order_id, 'refund_id' => (int) $refund_id, 'course_id' => (int) $course['id'], 'user_id' => $user_id ]
			);
		}
	}

	private function maybe_cashback( int $order_id ): void {
		$percent = (float) igbz()->settings()->get( 'wallet.order_cashback_percent', 0 );
		if ( $percent <= 0 ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order || (int) $order->get_customer_id() <= 0 ) {
			return;
		}

		$amount = round( (float) $order->get_total() * $percent / 100, 2 );
		if ( $amount <= 0 ) {
			return;
		}

		igbz()->get( 'wallet' )->credit(
			(int) $order->get_customer_id(),
			$amount,
			WalletService::REASON_CASHBACK,
			'cashback:' . $order_id,
			[ 'percent' => $percent ],
			(int) $order->get_meta( '_igbz_tenant_id' ),
			$order_id,
			__( 'Purchase cashback', 'igbz-suite' )
		);
	}

	/** @param \WC_Order $order */
	public function stamp_tenant_on_order( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$tenant_id = igbz()->tenancy()->id();
		if ( $tenant_id > 0 ) {
			$order->update_meta_data( '_igbz_tenant_id', $tenant_id );
		}
		$code = igbz()->get( 'affiliate' )->cookie_code();
		if ( '' !== $code ) {
			$order->update_meta_data( '_igbz_ref_code', $code );
		}
		$order->save();
	}

	/** @param int $user_id */
	public function on_user_register( $user_id ): void {
		igbz()->get( 'affiliate' )->attach_referral_to_user( (int) $user_id );
	}

	public function run_hourly(): void {
		igbz()->get( 'bnpl' )->process_overdue();
		igbz()->get( 'bnpl' )->send_reminders();
	}

	public function run_daily(): void {
		igbz()->get( 'plans' )->process_due_renewals();
		igbz()->get( 'affiliate' )->process_pending_commissions();
		igbz()->get( 'marketplace' )->flush_cache();
	}

	// ----------------------------------------------------------------- health

	/** @return array<int,array{label:string,status:string,detail:string}> */
	public function health(): array {
		$settings = igbz()->settings();
		$rows     = [];

		$rows[] = [
			'label'  => __( 'WooCommerce', 'igbz-suite' ),
			'status' => igbz()->woocommerce_active() ? 'ok' : 'error',
			'detail' => igbz()->woocommerce_active()
				? sprintf( /* translators: %s: version */ __( 'Active (%s)', 'igbz-suite' ), defined( 'WC_VERSION' ) ? WC_VERSION : '?' )
				: __( 'WooCommerce is not active.', 'igbz-suite' ),
		];

		$tenants = igbz()->get( 'tenants' )->count();
		$rows[]  = [
			'label'  => __( 'Tenants', 'igbz-suite' ),
			'status' => $tenants > 0 ? 'ok' : 'warn',
			'detail' => sprintf( /* translators: %d: count */ _n( '%d tenant configured', '%d tenants configured', $tenants, 'igbz-suite' ), $tenants ),
		];

		/** @var PaymentService $payments */
		$payments  = igbz()->get( 'payments' );
		$ready     = $payments->enabled_gateways();
		$rows[]    = [
			'label'  => __( 'Payment gateways', 'igbz-suite' ),
			'status' => $ready ? 'ok' : 'warn',
			'detail' => $ready
				? implode( ', ', array_map( static fn ( $g ) => $g->title(), $ready ) )
				: __( 'No PSP credentials configured yet.', 'igbz-suite' ),
		];

		$secret = $settings->string( 'lms.video_hmac_secret', '' );
		$rows[] = [
			'label'  => __( 'Signed video links', 'igbz-suite' ),
			'status' => '' !== $secret ? 'ok' : 'error',
			'detail' => '' !== $secret
				? __( 'HMAC secret present.', 'igbz-suite' )
				: __( 'lms.video_hmac_secret is empty; video URLs cannot be signed.', 'igbz-suite' ),
		];

		$rows[] = [
			'label'  => __( 'SMS provider', 'igbz-suite' ),
			'status' => 'log' === $settings->string( 'otp.sms_provider', 'log' ) ? 'warn' : 'ok',
			'detail' => sprintf(
				/* translators: %s: provider id */
				__( 'Current provider: %s', 'igbz-suite' ),
				$settings->string( 'otp.sms_provider', 'log' )
			),
		];

		return $rows;
	}
}
