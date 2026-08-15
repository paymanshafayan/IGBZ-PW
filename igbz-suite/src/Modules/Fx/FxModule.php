<?php
namespace IGBZ\Suite\Modules\Fx;

use IGBZ\Suite\Modules\Fx\Admin\FxPage;
use IGBZ\Suite\Modules\Fx\Providers\PstNetPayoutAdapter;
use IGBZ\Suite\Modules\Fx\Providers\RedotPayPayoutAdapter;
use IGBZ\Suite\Support\Cron;
use IGBZ\Suite\Support\ModuleInterface;
use IGBZ\Suite\Support\Modules;
use IGBZ\Suite\Support\Plugin;

/**
 * FX payment gateway — the foreign-currency intermediary.
 *
 * Store admins without a foreign card top up a USD credit wallet with Rials
 * (existing Iranian gateways, +fx.fee_percent on top of the USD amount, per
 * the client's rule). The actual Manus/ManyChat bills are paid by the
 * operator's payout adapter. The module never queues a task: it only gates a
 * tenant's own credit at dispatch time.
 *
 * Off by default; `multitenant` must be enabled for the Rial top-ups, and
 * `instagram` for the Manus meter.
 */

defined( 'ABSPATH' ) || exit;

/**
 * FX payment gateway — the foreign-currency intermediary.
 *
 * Store admins without a foreign card top up a USD credit wallet with Rials
 * (existing Iranian gateways, +fx.fee_percent on top of the USD amount, per
 * the client's rule). The actual Manus/ManyChat bills are paid by the
 * operator's payout adapter. The module never queues a task: it only gates a
 * tenant's own credit at dispatch time.
 *
 * Off by default; `multitenant` must be enabled for the Rial top-ups, and
 * `instagram` for the Manus meter.
 */
final class FxModule implements ModuleInterface {

	public function id(): string {
		return Modules::FX;
	}

	public function title(): string {
		return __( 'FX payments', 'igbz-suite' );
	}

	public function description(): string {
		return __( 'Foreign-currency intermediary: Rial top-ups for a USD credit wallet, automatic payout adapter, and per-task credit gating for Manus.', 'igbz-suite' );
	}

	public function register( Plugin $plugin ): void {
		$this->bind_services( $plugin );

		$topup = $plugin->get( 'fx.topup' );
		add_action( 'igbz_payment_verified', [ $topup, 'on_payment_verified' ], 10, 2 );

		$billing = $plugin->get( 'fx.billing' );
		add_action( Cron::HOOK_DAILY, [ $billing, 'run_daily' ] );

		( new FxPage() )->register();
	}

	private function bind_services( Plugin $plugin ): void {
		$plugin->bind( 'fx.wallet', static fn ( Plugin $c ) => new FxWalletService( $c->get( 'db' ) ) );
		$plugin->bind( 'fx.rates', static fn ( Plugin $c ) => new FxRateService( $c->get( 'db' ), $c->settings(), $c->get( 'http' ) ) );
		$plugin->bind( 'fx.meter', static fn ( Plugin $c ) => new FxMeter( $c->get( 'db' ), $c->get( 'fx.wallet' ), $c->logger() ) );
		$plugin->bind(
			'fx.topup',
			static fn ( Plugin $c ) => new FxTopupService(
				$c->get( 'db' ),
				$c->settings(),
				$c->get( 'payments' ),
				$c->get( 'fx.wallet' ),
				$c->get( 'fx.rates' ),
				$c->logger()
			)
		);
		$plugin->bind( 'fx.accounts', static fn ( Plugin $c ) => new FxAccountsService( $c->get( 'db' ) ) );

		$registry = new FxPayoutRegistry();
		$registry->register( new PstNetPayoutAdapter( $plugin->settings(), $plugin->get( 'http' ), $plugin->logger() ) );
		$registry->register( new RedotPayPayoutAdapter( $plugin->settings(), $plugin->get( 'http' ), $plugin->logger() ) );
		do_action( 'igbz_register_fx_payout_providers', $registry );
		$plugin->bind( 'fx.payouts', static fn () => $registry );

		$plugin->bind(
			'fx.billing',
			static fn ( Plugin $c ) => new FxBillingService(
				$c->get( 'db' ),
				$c->settings(),
				$c->get( 'fx.wallet' ),
				$c->get( 'fx.meter' ),
				$c->get( 'fx.payouts' ),
				$c->get( 'fx.accounts' ),
				$c->logger()
			)
		);
	}

	/** @return array<int,array{label:string,status:string,detail:string}> */
	public function health(): array {
		$rows   = [];
		$rates  = igbz()->get( 'fx.rates' );
		$wallet = igbz()->get( 'fx.wallet' );

		$rate = $rates->current();
		if ( $rate > 0 ) {
			$rows[] = [ 'label' => 'FX rate', 'status' => 'ok', 'detail' => sprintf( '%s IRT/USD', number_format( $rate, 0 ) ) ];
		} else {
			$rows[] = [ 'label' => 'FX rate', 'status' => 'warn', 'detail' => __( 'No rate configured — top-ups are refused.', 'igbz-suite' ) ];
		}

		$payouts = igbz()->get( 'fx.payouts' );
		$active  = $payouts->active();
		if ( $active && $active->is_configured() ) {
			$rows[] = [ 'label' => 'FX payout', 'status' => 'ok', 'detail' => $active->title() ];
		} else {
			$rows[] = [ 'label' => 'FX payout', 'status' => 'warn', 'detail' => __( 'No payout adapter configured — bills cannot be paid automatically yet.', 'igbz-suite' ) ];
		}

		return $rows;
	}
}
