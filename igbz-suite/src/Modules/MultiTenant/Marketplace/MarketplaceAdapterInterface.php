<?php
namespace IGBZ\Suite\Modules\MultiTenant\Marketplace;

defined( 'ABSPATH' ) || exit;

/**
 * Marketplace adapter (Digikala Open API, Divar Kenar, ...).
 */
interface MarketplaceAdapterInterface {

	public function id(): string;

	public function title(): string;

	public function is_configured(): bool;

	/**
	 * Create or update a product listing.
	 *
	 * @param array<string,mixed> $product
	 * @param array<string,mixed> $mapping Category mapping row.
	 * @return array{ok:bool,remote_id:string,message:string}
	 */
	public function upsert( array $product, array $mapping ): array;

	/**
	 * Refresh price / stock for an existing listing.
	 *
	 * @return array{ok:bool,message:string}
	 */
	public function update_price_stock( string $remote_id, float $price_irt, int $stock ): array;
}
