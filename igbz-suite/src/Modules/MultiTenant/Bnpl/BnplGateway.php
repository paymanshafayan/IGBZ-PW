<?php
namespace IGBZ\Suite\Modules\MultiTenant\Bnpl;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce checkout gateway for instalment purchases.
 */
final class BnplGateway extends \WC_Payment_Gateway {

	public function __construct() {
		$this->id                 = 'igbz_bnpl';
		$this->method_title       = __( 'IGBZ Instalments (BNPL)', 'igbz-suite' );
		$this->method_description = __( 'Split an order into instalments backed by an internal credit limit.', 'igbz-suite' );
		$this->has_fields         = true;
		$this->supports           = [ 'products' ];

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title', __( 'Pay in instalments', 'igbz-suite' ) );
		$this->description = $this->get_option( 'description', '' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );
	}

	public function init_form_fields(): void {
		$this->form_fields = [
			'enabled'     => [
				'title'   => __( 'Enable/Disable', 'igbz-suite' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable instalment payments', 'igbz-suite' ),
				'default' => 'yes',
			],
			'title'       => [
				'title'   => __( 'Title', 'igbz-suite' ),
				'type'    => 'text',
				'default' => __( 'Pay in instalments', 'igbz-suite' ),
			],
			'description' => [
				'title'   => __( 'Description', 'igbz-suite' ),
				'type'    => 'textarea',
				'default' => __( 'Split your order into equal payments.', 'igbz-suite' ),
			],
		];
	}

	public function is_available(): bool {
		if ( ! parent::is_available() || ! is_user_logged_in() || ! WC()->cart ) {
			return false;
		}
		if ( ! \IGBZ\Suite\Support\Modules::enabled( \IGBZ\Suite\Support\Modules::MULTITENANT ) ) {
			return false;
		}
		/** @var BnplService $service */
		$service = igbz()->get( 'bnpl' );
		return $service->eligibility( get_current_user_id(), (float) WC()->cart->get_total( 'edit' ), igbz()->tenancy()->id() )['eligible'];
	}

	public function payment_fields(): void {
		/** @var BnplService $service */
		$service = igbz()->get( 'bnpl' );
		$total   = (float) WC()->cart->get_total( 'edit' );
		$options = (array) apply_filters( 'igbz_bnpl_installment_options', [ 2, 3, 4, 6, 12 ] );

		echo '<p>' . esc_html( $this->description ) . '</p>';
		echo '<p><label for="igbz_bnpl_count">' . esc_html__( 'Number of instalments', 'igbz-suite' ) . '</label> ';
		echo '<select name="igbz_bnpl_count" id="igbz_bnpl_count">';
		foreach ( $options as $count ) {
			$quote = $service->quote( $total, (int) $count );
			printf(
				'<option value="%1$d">%2$s</option>',
				(int) $count,
				esc_html(
					sprintf(
						/* translators: 1: count, 2: per-instalment amount, 3: total */
						__( '%1$d payments - first %2$s, total %3$s', 'igbz-suite' ),
						(int) $count,
						wp_strip_all_tags( wc_price( $quote['down_payment'] ) ),
						wp_strip_all_tags( wc_price( $quote['total'] ) )
					)
				)
			);
		}
		echo '</select></p>';
		wp_nonce_field( 'igbz_bnpl_checkout', 'igbz_bnpl_nonce' );
	}

	public function validate_fields(): bool {
		if ( ! isset( $_POST['igbz_bnpl_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['igbz_bnpl_nonce'] ) ), 'igbz_bnpl_checkout' ) ) {
			wc_add_notice( __( 'Security check failed. Please refresh and try again.', 'igbz-suite' ), 'error' );
			return false;
		}
		return true;
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

		// Nonce verified in validate_fields().
		$count = isset( $_POST['igbz_bnpl_count'] ) ? absint( wp_unslash( $_POST['igbz_bnpl_count'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification

		/** @var BnplService $service */
		$service = igbz()->get( 'bnpl' );

		try {
			$contract_id = $service->create_contract(
				(int) $order->get_customer_id(),
				(float) $order->get_total(),
				$order_id,
				$count > 0 ? $count : null,
				null,
				igbz()->tenancy()->id()
			);
		} catch ( \Throwable $e ) {
			wc_add_notice( $e->getMessage(), 'error' );
			return [ 'result' => 'failure' ];
		}

		$service->activate_contract( $contract_id );

		$order->update_meta_data( '_igbz_bnpl_contract_id', $contract_id );
		$order->update_status( 'on-hold', __( 'Awaiting instalment payments.', 'igbz-suite' ) );
		$order->save();

		if ( WC()->cart ) {
			WC()->cart->empty_cart();
		}

		return [ 'result' => 'success', 'redirect' => $this->get_return_url( $order ) ];
	}
}
