<?php
namespace IGBZ\Suite\Modules\Fx;

use IGBZ\Suite\Modules\Fx\Contracts\FxPayoutAdapterInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Registry of FX payout providers.
 *
 * Providers register themselves on `igbz_register_fx_payout_providers`, the
 * same pattern as `igbz_register_payment_gateways`. In phase 1 the list can
 * be empty or hold a single manually-implemented adapter; the FX screen and
 * the monthly billing cron only ever talk to the one selected by
 * `fx.payout_provider`.
 */
final class FxPayoutRegistry {

	/** @var array<string,FxPayoutAdapterInterface> */
	private array $adapters = [];

	public function register( FxPayoutAdapterInterface $adapter ): void {
		$this->adapters[ $adapter->id() ] = $adapter;
	}

	/** @return array<string,FxPayoutAdapterInterface> */
	public function all(): array {
		return $this->adapters;
	}

	public function get( string $id ): ?FxPayoutAdapterInterface {
		return $this->adapters[ $id ] ?? null;
	}

	public function active(): ?FxPayoutAdapterInterface {
		$selected = (string) igbz()->settings()->string( 'fx.payout_provider', '' );
		return '' !== $selected ? $this->get( $selected ) : null;
	}
}
