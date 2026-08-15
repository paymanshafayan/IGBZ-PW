<?php
namespace IGBZ\Suite\Modules\MultiTenant\Payments;

defined( 'ABSPATH' ) || exit;

/**
 * Front controller for PSP return URLs.
 *
 * PaymentService::start() builds callbacks shaped like
 *   https://shop.example/?igbz_payment_callback=zarinpal&payment_id=123
 * The gateway appends its own parameters (Authority/Status, id/track_id, ...). We never trust
 * those parameters: PaymentService::handle_callback() re-verifies with the PSP server side.
 */
final class CallbackHandler {

	public function register(): void {
		add_action( 'init', [ $this, 'maybe_handle' ], 20 );
	}

	public function maybe_handle(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- PSP redirect, verified server side.
		if ( empty( $_GET['igbz_payment_callback'] ) ) {
			return;
		}

		$gateway_id = sanitize_key( wp_unslash( $_GET['igbz_payment_callback'] ) );
		$payment_id = isset( $_GET['payment_id'] ) ? absint( wp_unslash( $_GET['payment_id'] ) ) : 0;
		$params     = array_map(
			static fn( $value ) => is_scalar( $value ) ? sanitize_text_field( wp_unslash( (string) $value ) ) : '',
			$_GET
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( 0 === $payment_id ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}

		/** @var PaymentService $service */
		$service = igbz()->get( 'payments' );
		$payment = $service->payment( $payment_id );

		if ( ! $payment ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}

		$result = $service->handle_callback( $gateway_id, $payment_id, $params );

		wp_safe_redirect( $this->destination( $payment, $result ) );
		exit;
	}

	/** @param array<string,mixed> $payment */
	private function destination( array $payment, PaymentVerifyResult $result ): string {
		$order_id = (int) $payment['order_id'];

		if ( $order_id > 0 && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $order_id );
			if ( $order ) {
				if ( $result->success ) {
					return $order->get_checkout_order_received_url();
				}
				wc_add_notice(
					'' !== $result->error_message ? $result->error_message : __( 'The payment was not completed.', 'igbz-suite' ),
					'error'
				);
				return $order->get_checkout_payment_url();
			}
		}

		$base = wc_get_account_endpoint_url( 'igbz-wallet' );
		$url  = add_query_arg(
			[
				'igbz_payment' => $result->success ? 'success' : 'failed',
				'payment_id'   => (int) $payment['id'],
			],
			$base ?: home_url( '/' )
		);

		/**
		 * Filter where the customer lands after a PSP callback.
		 *
		 * @param string              $url
		 * @param array<string,mixed> $payment
		 * @param PaymentVerifyResult $result
		 */
		return (string) apply_filters( 'igbz_payment_callback_redirect', $url, $payment, $result );
	}
}
