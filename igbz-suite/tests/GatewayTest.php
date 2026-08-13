<?php
declare( strict_types=1 );

use IGBZ\Suite\Modules\MultiTenant\Payments\GatewayInterface;
use IGBZ\Suite\Modules\MultiTenant\Payments\IdPayGateway;
use IGBZ\Suite\Modules\MultiTenant\Payments\NextPayGateway;
use IGBZ\Suite\Modules\MultiTenant\Payments\PayirGateway;
use IGBZ\Suite\Modules\MultiTenant\Payments\PaymentRequestResult;
use IGBZ\Suite\Modules\MultiTenant\Payments\PaymentVerifyResult;
use IGBZ\Suite\Modules\MultiTenant\Payments\ZarinpalGateway;

/**
 * Contract tests for the PSP adapters. No network: these check the parts that decide whether real
 * money moves - configuration gating and the shape of the result objects.
 */
final class GatewayTest extends TestCase {

	public function run(): void {
		$settings = igbz_test_reset_settings();
		$http     = igbz()->http();

		/** @var array<string,GatewayInterface> $gateways */
		$gateways = [
			'zarinpal' => new ZarinpalGateway( $http ),
			'idpay'    => new IdPayGateway( $http ),
			'nextpay'  => new NextPayGateway( $http ),
			'payir'    => new PayirGateway( $http ),
		];

		foreach ( $gateways as $id => $gateway ) {
			$this->assert_same( $id, $gateway->id(), "{$id} reports its own id" );
			$this->assert_false( '' === $gateway->title(), "{$id} has a human title" );
			$this->assert_true( count( $gateway->required_settings() ) > 0, "{$id} declares its credentials" );

			// Nothing configured yet: every adapter must refuse rather than call out.
			$this->assert_false( $gateway->is_configured(), "{$id} is not configured on a fresh install" );

			$result = $gateway->request( 100000.0, 'https://shop.test/callback' );
			$this->assert_false( $result->success, "{$id} refuses to start an unconfigured payment" );
			$this->assert_same( 'not_configured', $result->error_code, "{$id} reports not_configured" );
			$this->assert_false( '' === $result->error_message, "{$id} explains why it refused" );
		}

		// Once credentials exist the adapter reports itself ready.
		$settings->set( 'payments.zarinpal.merchant_id', str_repeat( 'a', 36 ) );
		$settings->set( 'payments.idpay.api_key', 'idpay-key' );
		$settings->set( 'payments.nextpay.api_key', 'b11ee9c3-d23d-414e-8b6e-f2370baac97b' );
		$settings->set( 'payments.payir.api_key', 'payir-key' );

		foreach ( $gateways as $id => $gateway ) {
			$this->assert_true( $gateway->is_configured(), "{$id} is configured once its key is stored" );
		}

		// Pay.ir sandbox mode works without a real key at all.
		$sandbox = igbz_test_reset_settings();
		$sandbox->set( 'payments.payir.sandbox', true );
		$this->assert_true( ( new PayirGateway( $http ) )->is_configured(), 'the Pay.ir sandbox needs no key' );

		// Pay.ir rejects anything under 10,000 Rial before it ever hits the network.
		$sandbox->set( 'general.default_currency', 'IRR' );
		$low = ( new PayirGateway( $http ) )->request( 500.0, 'https://shop.test/callback' );
		$this->assert_false( $low->success, 'Pay.ir refuses an amount below its minimum' );
		$this->assert_same( 'amount_too_low', $low->error_code, 'the low amount is reported precisely' );

		// Callbacks missing their identifier must fail closed, never verify.
		$missing = ( new NextPayGateway( $http ) )->verify( 1000.0, [] );
		$this->assert_false( $missing->success, 'NextPay refuses a callback with no trans_id' );
		$this->assert_same( 'missing_params', $missing->error_code, 'NextPay reports missing_params' );

		$missing = ( new PayirGateway( $http ) )->verify( 1000.0, [] );
		$this->assert_false( $missing->success, 'Pay.ir refuses a callback with no token' );

		$cancelled = ( new PayirGateway( $http ) )->verify( 1000.0, [ 'token' => 'abc', 'status' => 0 ] );
		$this->assert_false( $cancelled->success, 'Pay.ir treats status 0 as a cancellation' );
		$this->assert_same( 'cancelled', $cancelled->error_code, 'the cancellation is reported as such' );

		$cancelled = ( new IdPayGateway( $http ) )->verify( 1000.0, [ 'id' => 'x', 'order_id' => '1', 'status' => 2 ] );
		$this->assert_false( $cancelled->success, 'IDPay treats a status below 100 as a cancellation' );

		// Result value objects.
		$ok = PaymentRequestResult::ok( 'auth-1', 'https://gateway.test/pay/auth-1' );
		$this->assert_true( $ok->success, 'a successful request result is successful' );
		$this->assert_same( 'auth-1', $ok->authority, 'the authority is carried' );

		$fail = PaymentRequestResult::failure( 'code', 'message' );
		$this->assert_false( $fail->success, 'a failed request result is not successful' );
		$this->assert_same( '', $fail->redirect_url, 'a failed request has no redirect' );

		$verified = PaymentVerifyResult::ok( 'ref-1', '6219-****-****-1234', 500.0 );
		$this->assert_true( $verified->success, 'a verified payment is successful' );
		$this->assert_false( $verified->already_verified, 'a first verification is not a duplicate' );
		$this->assert_same( 500.0, $verified->fee, 'the gateway fee is carried' );

		$duplicate = PaymentVerifyResult::duplicate( 'ref-1' );
		$this->assert_true( $duplicate->success, 'a duplicate verification still counts as paid' );
		$this->assert_true( $duplicate->already_verified, 'a duplicate is flagged' );
	}
}
