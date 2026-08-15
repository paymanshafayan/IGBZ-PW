<?php
namespace IGBZ\Suite\Modules\MultiTenant\Payments;

defined( 'ABSPATH' ) || exit;

/**
 * Contract every Iranian PSP adapter implements.
 *
 * Port note: the nopCommerce original called a placeholder host (api.parbad.local) with guessed
 * payload field names. These adapters target the real, documented endpoints instead.
 */
interface GatewayInterface {

	public function id(): string;

	public function title(): string;

	/** Field keys this gateway needs from the settings store. */
	public function required_settings(): array;

	/**
	 * Start a payment.
	 *
	 * @param float  $amount       Amount in the store currency.
	 * @param string $callback_url Absolute return URL.
	 * @param array<string,mixed> $context order_id, description, mobile, email...
	 */
	public function request( float $amount, string $callback_url, array $context = [] ): PaymentRequestResult;

	/**
	 * Verify a callback.
	 *
	 * @param array<string,mixed> $callback_params Raw query/post parameters from the PSP.
	 */
	public function verify( float $amount, array $callback_params ): PaymentVerifyResult;

	public function is_configured(): bool;
}
