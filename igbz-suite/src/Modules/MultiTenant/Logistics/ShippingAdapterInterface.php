<?php
namespace IGBZ\Suite\Modules\MultiTenant\Logistics;

defined( 'ABSPATH' ) || exit;

/**
 * Shipping carrier adapter (Tapin, Postex, Snapp Business ...).
 */
interface ShippingAdapterInterface {

	public function id(): string;

	public function title(): string;

	public function is_configured(): bool;

	/**
	 * Register a shipment with the carrier.
	 *
	 * @param array<string,mixed> $shipment An ig_shipments row.
	 * @return array{ok:bool,tracking_code:string,message:string}
	 */
	public function register( array $shipment ): array;

	/**
	 * Query live status for a tracking code.
	 *
	 * @return array{status:string,detail:string}
	 */
	public function track( string $tracking_code ): array;
}
