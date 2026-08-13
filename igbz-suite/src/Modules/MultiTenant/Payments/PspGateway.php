<?php
namespace IGBZ\Suite\Modules\MultiTenant\Payments;

defined( 'ABSPATH' ) || exit;

/**
 * Generic WooCommerce wrapper around any GatewayInterface adapter.
 *
 * One instance is created per registered PSP (Zarinpal, IDPay, ...) so each one shows up as a
 * real, configurable payment method at checkout. Verification always happens server side in
 * PaymentService::handle_callback().
 */
final class PspGateway extends \WC_Payment_Gateway {

	public function __construct( private GatewayInterface $adapter ) {
		$this->id                 = 'igbz_' . $adapter->id();
		$this->method_title       = sprintf( /* translators: %s: gateway title */ __( 'IGBZ: %s', 'igbz-suite' ), $adapter->title() );
		$this->method_description = __( 'Online payment handled by the IGBZ payment service.', 'igbz-suite' );
		$this->has_fields         = false;
		$this->supports           = [ 'products' ];
		$this->icon               = (string) apply_filters( 'igbz_psp_gateway_icon', '', $adapter->id() );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title', $adapter->title() );
		$this->description = $this->get_option( 'description', '' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );
	}

	public function adapter(): GatewayInterface {
		return $this->adapter;
	}

	public function init_form_fields(): void {
		$this->form_fields = [
			'enabled'     => [
				'title'   => __( 'Enable/Disable', 'igbz-suite' ),
				'type'    => 'checkbox',
				'label'   => sprintf( /* translators: %s: gateway title */ __( 'Enable %s', 'igbz-suite' ), $this->adapter->title() ),
				'default' => 'no',
			],
			'title'       => [
				'title'   => __( 'Title', 'igbz-suite' ),
				'type'    => 'text',
				'default' => $this->adapter->title(),
			],
			'description' => [
				'title'   => __( 'Description', 'igbz-suite' ),
				'type'    => 'textarea',
				'default' => __( 'You will be redirected to the payment gateway to complete your purchase.', 'igbz-suite' ),
			],
			'note'        => [
				'title'       => __( 'Credentials', 'igbz-suite' ),
				'type'        => 'title',
				'description' => sprintf(
					/* translators: %s: settings page URL */
					__( 'API credentials for this gateway are stored encrypted in the IGBZ settings screen: %s', 'igbz-suite' ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=igbz-settings#payments' ) ) . '">' . esc_html__( 'IGBZ settings', 'igbz-suite' ) . '</a>'
				),
			],
		];
	}

	public function is_available(): bool {
		return parent::is_available()
			&& \IGBZ\Suite\Support\Modules::enabled( \IGBZ\Suite\Support\Modules::MULTITENANT )
			&& igbz()->settings()->bool( 'payments.' . $this->adapter->id() . '.enabled', false )
			&& $this->adapter->is_configured();
	}

	/**
	 * @param int $order_id
	 * @return array<string,string>
	 */
	public function process_payment( $order_id ): array {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return [ 'result' => 'failure' ];
		}

		/** @var PaymentService $service */
		$service = igbz()->get( 'payments' );

		$result = $service->start(
			(float) $order->get_total(),
			PaymentService::PURPOSE_ORDER,
			[
				'order_id'    => $order_id,
				'user_id'     => (int) $order->get_customer_id(),
				'tenant_id'   => igbz()->tenancy()->id(),
				'description' => sprintf( /* translators: %s: order number */ __( 'Order %s', 'igbz-suite' ), $order->get_order_number() ),
				'mobile'      => $order->get_billing_phone(),
				'email'       => $order->get_billing_email(),
			],
			$this->adapter->id()
		);

		if ( ! $result['ok'] ) {
			wc_add_notice( $result['error'], 'error' );
			$order->add_order_note( sprintf( /* translators: %s: error */ __( 'Payment request failed: %s', 'igbz-suite' ), $result['error'] ) );
			return [ 'result' => 'failure' ];
		}

		$order->update_status( 'pending', __( 'Awaiting online payment.', 'igbz-suite' ) );
		$order->update_meta_data( '_igbz_payment_id', $result['payment_id'] );
		$order->save();

		return [ 'result' => 'success', 'redirect' => $result['redirect_url'] ];
	}
}
