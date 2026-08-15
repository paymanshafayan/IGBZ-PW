<?php
namespace IGBZ\Suite\Modules\Fx\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Iranian-exchange on-ramp: buy USDT with Rials to fund the payout card.
 *
 * The final link in the automatic chain (Rial → USDT → card → bill). The
 * operator funds an account at an Iranian exchange (Nobitex and the like);
 * the ramp adapter reads the live USDT price, places a buy order, and
 * withdraws the USDT to the payout card's deposit address (TRC20).
 *
 * Withdrawals on Iranian exchanges often require a confirmation step (OTP);
 * when the exchange API cannot complete the withdrawal unattended, the
 * adapter reports the order as placed and the operator confirms the
 * withdrawal in the exchange app — the system still records and tracks it.
 */
interface FxRampInterface {

	/** Stable adapter id, stored in `fx.ramp_provider`. */
	public function id(): string;

	public function title(): string;

	/** Whether the operator has entered what this adapter needs. */
	public function is_configured(): bool;

	/** Live USDT → Rial price, or 0 when unreachable. */
	public function usdt_price(): float;

	/**
	 * Buy USDT with Rials from the exchange balance.
	 *
	 * @return array{ok:bool,usdt_amount:float,reference:string,error:string}
	 */
	public function buy( float $amount_irt, string $reference ): array;

	/**
	 * Withdraw USDT to the payout card's deposit address.
	 *
	 * @return array{ok:bool,reference:string,error:string}
	 */
	public function withdraw( float $usdt_amount, string $address, string $reference ): array;
}
