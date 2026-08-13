<?php
namespace IGBZ\Suite\Modules\MultiTenant\Wallet;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce payment gateway that pays an order from the customer's IGBZ wallet.
 *
 * Port note: the nopCommerce version never implemented IPaymentMethod, so the storefront had no
 * selectable payment method at all (HANDOFF section 6). Here every gateway is a real
 * WC_Payment_Gateway and therefore appears at checkout.
 */
final class WalletGateway extends \WC_Payment_Gateway {

	public function __construct() {
		$this->id                 = 'igbz_wallet';
		$this->method_title       = __( 'IGBZ Wallet', 'igbz-suite' );
		$this->method_description = __( 'Let customers pay from their IGBZ wallet balance.', 'igbz-suite' );
		$this->has_fields         = true;
		$this->supports           = [ 'products', 'refunds' ];

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title', __( 'Wallet balance', 'igbz-suite' ) );
		$this->description = $this->get_option( 'description', __( 'Pay using your store wallet credit.', 'igbz-suite' ) );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );
	}

	public function init_form_fields(): void {
		$this->form_fields = [
			'enabled'     => [
				'title'   => __( 'Enable/Disable', 'igbz-suite' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable wallet payments', 'igbz-suite' ),
				'default' => 'yes',
			],
			'title'       => [
				'title'       => __( 'Title', 'igbz-suite' ),
				'type'        => 'text',
				'default'     => __( 'Wallet balance', 'igbz-suite' ),
				'desc_tip'    => true,
				'description' => __( 'Label shown to the customer at checkout.', 'igbz-suite' ),
			],
			'description' => [
				'title'   => __( 'Description', 'igbz-suite' ),
				'type'    => 'textarea',
				'default' => __( 'Pay using your store wallet credit.', 'igbz-suite' ),
			],
			'partial'     => [
				'title'   => __( 'Partial payments', 'igbz-suite' ),
				'type'    => 'checkbox',
				'label'   => __( 'Allow the wallet to cover part of the order and charge the rest to another gateway', 'igbz-suite' ),
				'default' => 'no',
			],
		];
	}

	public function is_available(): bool {
		if ( ! parent::is_available() || ! is_user_logged_in() ) {
			return false;
		}
		return \IGBZ\Suite\Support\Modules::enabled( \IGBZ\Suite\Support\Modules::MULTITENANT )
			&& igbz()->settings()->bool( 'wallet.enabled', true );
	}

	public function payment_fields(): void {
		$service = igbz()->get( 'wallet' );
		$balance = $service->balance( get_current_user_id(), igbz()->tenancy()->id() );

		echo '<p>' . esc_html( $this->description ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Available balance:', 'igbz-suite' ) . '</strong> '
			. wp_kses_post( wc_price( $balance ) ) . '</p>';

		$total = (float) ( WC()->cart ? WC()->cart->get_total( 'edit' ) : 0 );
		if ( $balance + 0.0001 < $total ) {
			echo '<p class="woocommerce-error">' . esc_html__( 'Your wallet balance does not cover this order. Please top up first.', 'igbz-suite' ) . '</p>';
		}
	}

	public function validate_fields(): bool {
		$order_total = (float) ( WC()->cart ? WC()->cart->get_total( 'edit' ) : 0 );
		$balance     = igbz()->get( 'wallet' )->balance( get_current_user_id(), igbz()->tenancy()->id() );
		if ( $balance + 0.0001 < $order_total ) {
			wc_add_notice( __( 'Insufficient wallet balance.', 'igbz-suite' ), 'error' );
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

		/** @var WalletService $service */
		$service = igbz()->get( 'wallet' );
		$result  = $service->debit(
			(int) $order->get_customer_id(),
			(float) $order->get_total(),
			WalletService::REASON_ORDER_PAY,
			'order:' . $order_id,
			[ 'order_number' => $order->get_order_number() ],
			igbz()->tenancy()->id(),
			$order_id,
			sprintf( __( 'Payment for order %s', 'igbz-suite' ), $order->get_order_number() )
		);

		if ( ! $result->success ) {
			wc_add_notice( $result->error_message, 'error' );
			return [ 'result' => 'failure' ];
		}

		$order->payment_complete( 'wallet:' . $result->entry_id );
		$order->add_order_note(
			sprintf(
				/* translators: 1: amount, 2: remaining balance */
				__( 'Paid %1$s from IGBZ wallet. Remaining balance: %2$s', 'igbz-suite' ),
				wp_strip_all_tags( wc_price( $order->get_total() ) ),
				wp_strip_all_tags( wc_price( $result->balance ) )
			)
		);
		if ( WC()->cart ) {
			WC()->cart->empty_cart();
		}

		return [ 'result' => 'success', 'redirect' => $this->get_return_url( $order ) ];
	}

	/**
	 * @param int        $order_id
	 * @param float|null $amount
	 * @param string     $reason
	 * @return bool|\WP_Error
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return new \WP_Error( 'igbz_refund', __( 'Order not found.', 'igbz-suite' ) );
		}
		$amount = null === $amount ? (float) $order->get_total() : (float) $amount;

		$result = igbz()->get( 'wallet' )->credit(
			(int) $order->get_customer_id(),
			$amount,
			WalletService::REASON_REFUND,
			'refund:' . $order_id . ':' . md5( (string) $amount . $reason ),
			[ 'reason' => $reason ],
			igbz()->tenancy()->id(),
			$order_id,
			$reason
		);

		return $result->success ? true : new \WP_Error( 'igbz_refund', $result->error_message );
	}
}
