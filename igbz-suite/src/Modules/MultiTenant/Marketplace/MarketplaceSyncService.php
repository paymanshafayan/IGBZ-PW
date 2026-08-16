<?php
namespace IGBZ\Suite\Modules\MultiTenant\Marketplace;

use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Durable marketplace sync queue.
 *
 * Product saves enqueue rows; a cron worker drains them through the
 * configured adapters with a retry cap. The queue is durable (same idea as
 * ig_intake / ig_content): a failed push stays pending until retried.
 */
final class MarketplaceSyncService {

	public const STATUS_PENDING  = 'pending';
	public const STATUS_DONE     = 'done';
	public const STATUS_FAILED   = 'failed';

	public function __construct(
		private Db $db,
		private Logger $logger
	) {}

	/** Enqueue a product change. Idempotent per (product, marketplace). */
	public function enqueue( int $product_id, string $marketplace, string $action = 'upsert', int $tenant_id = 0 ): void {
		$existing = (int) $this->db->scalar(
			'SELECT COUNT(*) FROM ' . $this->db->table( 'ig_marketplace_sync' ) . '
			 WHERE product_id = %d AND marketplace = %s AND status = %s',
			$product_id,
			$marketplace,
			self::STATUS_PENDING
		);
		if ( $existing > 0 ) {
			return;
		}

		$now = current_time( 'mysql', true );
		$this->db->insert(
			'ig_marketplace_sync',
			[
				'tenant_id'   => $tenant_id > 0 ? $tenant_id : (int) igbz()->tenancy()->id(),
				'product_id'  => $product_id,
				'marketplace' => $marketplace,
				'action'      => $action,
				'status'      => self::STATUS_PENDING,
				'created_at'  => $now,
				'updated_at'  => $now,
			]
		);
	}

	/** Cron worker: drain the queue. Returns the number of rows processed. */
	public function process_pending( int $limit = 20 ): int {
		$rows = $this->db->results(
			'SELECT * FROM ' . $this->db->table( 'ig_marketplace_sync' ) . '
			 WHERE status = %s ORDER BY id ASC LIMIT %d',
			self::STATUS_PENDING,
			$limit
		);

		$processed = 0;
		foreach ( $rows as $row ) {
			$this->process_row( $row );
			++$processed;
		}

		return $processed;
	}

	/** @param array<string,mixed> $row */
	private function process_row( array $row ): void {
		$adapter = $this->adapter_for( (string) $row['marketplace'] );
		if ( ! $adapter || ! $adapter->is_configured() ) {
			$this->fail( $row, __( 'Marketplace is not configured.', 'igbz-suite' ) );
			return;
		}

		$product = $this->product_payload( (int) $row['product_id'] );
		if ( null === $product ) {
			$this->db->update( 'ig_marketplace_sync', [ 'status' => self::STATUS_DONE, 'updated_at' => current_time( 'mysql', true ) ], [ 'id' => (int) $row['id'] ] );
			return;
		}

		$mapping = $this->category_mapping( (int) $row['tenant_id'], (string) $row['marketplace'], (string) ( $product['category'] ?? '' ) );
		$result  = $adapter->upsert( $product, $mapping );

		if ( ! $result['ok'] ) {
			$this->fail( $row, $result['message'] );
			return;
		}

		$this->db->update(
			'ig_marketplace_sync',
			[
				'status'     => self::STATUS_DONE,
				'last_error' => '',
				'updated_at' => current_time( 'mysql', true ),
			],
			[ 'id' => (int) $row['id'] ]
		);
		$this->logger->info( 'marketplace', 'Product synced', [ 'row' => (int) $row['id'], 'marketplace' => (string) $row['marketplace'], 'remote' => $result['remote_id'] ] );
	}

	/** @param array<string,mixed> $row */
	private function fail( array $row, string $error ): void {
		$attempts = (int) $row['attempts'] + 1;
		$max      = igbz()->settings()->int( 'marketplace.sync_retries', 3 );
		$status   = $attempts >= $max ? self::STATUS_FAILED : self::STATUS_PENDING;

		$this->db->update(
			'ig_marketplace_sync',
			[
				'status'     => $status,
				'attempts'   => $attempts,
				'last_error' => mb_substr( $error, 0, 255 ),
				'updated_at' => current_time( 'mysql', true ),
			],
			[ 'id' => (int) $row['id'] ]
		);
		$this->logger->warning( 'marketplace', 'Marketplace sync failed', [ 'row' => (int) $row['id'], 'error' => $error ] );
	}

	public function adapter_for( string $marketplace ): ?MarketplaceAdapterInterface {
		$settings = igbz()->settings();
		$http     = igbz()->get( 'http' );

		if ( 'digikala' === $marketplace ) {
			return new HttpMarketplaceAdapter( 'digikala', 'Digikala', 'marketplace.digikala', $http );
		}
		if ( 'divar' === $marketplace ) {
			return new HttpMarketplaceAdapter( 'divar', 'Divar', 'marketplace.divar', $http );
		}
		return null;
	}

	/** @return array<string,mixed>|null */
	private function product_payload( int $product_id ): ?array {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return null;
		}

		$images = [];
		$image_id = (int) $product->get_image_id();
		if ( $image_id > 0 ) {
			$images[] = (string) wp_get_attachment_url( $image_id );
		}

		$terms = wc_get_product_terms( $product_id, 'product_cat', [ 'fields' => 'names' ] );

		return [
			'id'          => $product_id,
			'name'        => (string) $product->get_name(),
			'description' => (string) $product->get_description(),
			'price_irt'   => Money::to_rial( (float) $product->get_price() ),
			'stock'       => max( 0, (int) $product->get_stock_quantity() ),
			'category'    => $terms ? (string) $terms[0] : 'default',
			'images'      => $images,
		];
	}

	/** @return array{local_category:string,remote_category:string} */
	private function category_mapping( int $tenant_id, string $marketplace, string $local_category ): array {
		$row = $this->db->row(
			'SELECT * FROM ' . $this->db->table( 'ig_category_mapping' ) . '
			 WHERE tenant_id = %d AND marketplace = %s AND local_category = %s LIMIT 1',
			$tenant_id,
			$marketplace,
			$local_category
		);
		if ( ! $row ) {
			$row = $this->db->row(
				'SELECT * FROM ' . $this->db->table( 'ig_category_mapping' ) . '
				 WHERE tenant_id = 0 AND marketplace = %s AND local_category = %s LIMIT 1',
				$marketplace,
				$local_category
			);
		}

		return [
			'local_category'  => $local_category,
			'remote_category' => $row ? (string) $row['remote_category'] : '',
		];
	}

	/** @return array<int,array<string,mixed>> */
	public function pending( int $limit = 50 ): array {
		return $this->db->results(
			'SELECT * FROM ' . $this->db->table( 'ig_marketplace_sync' ) . ' ORDER BY id DESC LIMIT %d',
			$limit
		);
	}
}
