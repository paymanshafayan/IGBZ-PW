<?php
namespace IGBZ\Suite\Modules\MultiTenant\Marketplace;

use IGBZ\Suite\Support\Db;

defined( 'ABSPATH' ) || exit;

/**
 * Local-category → remote-category mapping per marketplace and tenant.
 */
final class CategoryMappingService {

	public function __construct( private Db $db ) {}

	/** @return array<int,array<string,mixed>> */
	public function all( int $tenant_id, string $marketplace = '' ): array {
		$where  = [ 'tenant_id = %d' ];
		$params = [ $tenant_id ];
		if ( '' !== $marketplace ) {
			$where[]  = 'marketplace = %s';
			$params[] = $marketplace;
		}

		return $this->db->results(
			'SELECT * FROM ' . $this->db->table( 'ig_category_mapping' ) . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY id',
			...$params
		);
	}

	public function set( int $tenant_id, string $marketplace, string $local_category, string $remote_category ): void {
		$existing = $this->db->row(
			'SELECT id FROM ' . $this->db->table( 'ig_category_mapping' ) . '
			 WHERE tenant_id = %d AND marketplace = %s AND local_category = %s LIMIT 1',
			$tenant_id,
			$marketplace,
			$local_category
		);

		if ( $existing ) {
			$this->db->update(
				'ig_category_mapping',
				[ 'remote_category' => $remote_category ],
				[ 'id' => (int) $existing['id'] ]
			);
			return;
		}

		$this->db->insert(
			'ig_category_mapping',
			[
				'tenant_id'       => $tenant_id,
				'marketplace'     => $marketplace,
				'local_category'  => $local_category,
				'remote_category' => $remote_category,
				'created_at'      => current_time( 'mysql', true ),
			]
		);
	}
}
