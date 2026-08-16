<?php
namespace IGBZ\Suite\Modules\Fx;

use IGBZ\Suite\Modules\Fx\Contracts\FxRampInterface;
use IGBZ\Suite\Modules\Fx\Providers\HttpRampAdapter;
use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Logger;
use IGBZ\Suite\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Orchestrates the automatic Rial → USDT on-ramp.
 *
 * The daily cron calls ensure_card_funded(): when the ramp is enabled, the
 * active payout card's balance is below fx.ramp_min_card_balance, and the
 * exchange has a price, it buys enough USDT (capped by
 * fx.ramp_max_irt_per_run, 0 = uncapped) and withdraws it to
 * fx.ramp_usdt_deposit_address. Every run is recorded in the FX ledger under
 * tenant 0 (operator) with reason `ramp`, so the operator's report shows how
 * much Rial went into the card.
 *
 * Nothing here touches tenant wallets — it funds the operator's card, which
 * the billing cron then spends on tenant bills.
 */
final class FxRampService {

	public const REASON_RAMP = 'ramp';

	public function __construct(
		private Db $db,
		private Settings $settings,
		private FxPayoutRegistry $payouts,
		private Logger $logger
	) {}

	public function adapter(): ?FxRampInterface {
		if ( ! $this->settings->bool( 'fx.ramp_enabled', false ) ) {
			return null;
		}

		return new HttpRampAdapter( $this->settings, igbz()->get( 'http' ), $this->logger );
	}

	/** Live USDT → Rial price through the ramp, or 0. */
	public function usdt_price(): float {
		$adapter = $this->adapter();
		return $adapter ? $adapter->usdt_price() : 0.0;
	}

	/**
	 * Manual buy (button on the FX screen): buy fx.ramp_manual_irt worth of
	 * USDT and withdraw to the deposit address.
	 *
	 * @return array{ok:bool,message:string}
	 */
	public function buy_now(): array {
		$adapter = $this->adapter();
		if ( ! $adapter ) {
			return [ 'ok' => false, 'message' => __( 'The ramp is not enabled or not configured.', 'igbz-suite' ) ];
		}

		$amount_irt = (float) $this->settings->float( 'fx.ramp_manual_irt', 0 );
		if ( $amount_irt <= 0 ) {
			return [ 'ok' => false, 'message' => __( 'Set fx.ramp_manual_irt first.', 'igbz-suite' ) ];
		}

		return $this->run_ramp( $adapter, $amount_irt );
	}

	/**
	 * Daily cron: top the card up when it is low. Returns a short message for
	 * the log/status screen.
	 */
	public function ensure_card_funded(): array {
		$adapter = $this->adapter();
		if ( ! $adapter ) {
			return [ 'ok' => true, 'message' => 'ramp disabled' ];
		}

		$card = $this->payouts->active();
		if ( ! $card ) {
			return [ 'ok' => true, 'message' => 'no payout card' ];
		}

		$min = (float) $this->settings->float( 'fx.ramp_min_card_balance', 50 );
		if ( $card->card_balance() >= $min ) {
			return [ 'ok' => true, 'message' => 'card funded' ];
		}

		$cap = (float) $this->settings->float( 'fx.ramp_max_irt_per_run', 0 );
		$need = (float) $this->settings->float( 'fx.ramp_manual_irt', 0 );
		$price = $adapter->usdt_price();
		if ( $price <= 0 ) {
			return [ 'ok' => false, 'message' => 'ramp unpriced' ];
		}

		$amount_irt = $need > 0 ? $need : $min * $price;
		if ( $cap > 0 ) {
			$amount_irt = min( $amount_irt, $cap );
		}

		return $this->run_ramp( $adapter, $amount_irt );
	}

	/**
	 * @param FxRampInterface $adapter
	 * @return array{ok:bool,message:string}
	 */
	private function run_ramp( FxRampInterface $adapter, float $amount_irt ): array {
		$reference = 'ramp:' . gmdate( 'YmdHis' ) . ':' . bin2hex( random_bytes( 3 ) );

		$buy = $adapter->buy( $amount_irt, $reference );
		if ( ! $buy['ok'] ) {
			$this->logger->error( 'fx', 'Ramp buy failed', [ 'reference' => $reference, 'error' => $buy['error'] ] );

			return [ 'ok' => false, 'message' => $buy['error'] ];
		}

		$usdt = $buy['usdt_amount'];
		if ( $usdt <= 0 ) {
			// Some exchanges return the order id only; the amount is implied by the price.
			$price = $adapter->usdt_price();
			$usdt  = $price > 0 ? round( $amount_irt / $price, 6 ) : 0;
		}

		$address = trim( $this->settings->string( 'fx.ramp_usdt_deposit_address', '' ) );
		$withdraw = $adapter->withdraw( $usdt, $address, $reference );
		if ( ! $withdraw['ok'] ) {
			$this->logger->warning( 'fx', 'Ramp withdrawal needs manual confirmation', [ 'reference' => $reference, 'error' => $withdraw['error'] ] );

			return [ 'ok' => false, 'message' => $withdraw['error'] ];
		}

		// Record in the operator ledger (tenant 0) so reports can show it.
		$this->db->insert(
			'fx_ledger',
			[
				'tenant_id'  => 0,
				'user_id'    => 0,
				'reason'     => self::REASON_RAMP,
				'reference'  => $reference,
				'amount_usd' => $usdt,
				'amount_irt' => $amount_irt,
				'rate_id'    => 0,
				'meta'       => wp_json_encode(
					[
						'buy_order'   => $buy['reference'],
						'withdraw'    => $withdraw['reference'],
						'address'     => $address,
					]
				),
				'created_at' => current_time( 'mysql', true ),
			]
		);

		$this->logger->info( 'fx', 'Ramp complete', [ 'reference' => $reference, 'usdt' => $usdt, 'irt' => $amount_irt ] );

		return [ 'ok' => true, 'message' => sprintf( '%s IRT → %s USDT', number_format( $amount_irt, 0 ), number_format( $usdt, 4 ) ) ];
	}
}
