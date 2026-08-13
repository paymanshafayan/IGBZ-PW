<?php
declare( strict_types=1 );

use IGBZ\Suite\Support\Schema;

/**
 * The nopCommerce original drifted between its migrations and its entity list; this keeps the
 * table catalogue and the DDL in lockstep so Status page health checks stay meaningful.
 */
final class SchemaTest extends TestCase {

	public function run(): void {
		$tables     = Schema::tables();
		$statements = Schema::statements();

		$this->assert_same( count( $tables ), count( array_unique( $tables ) ), 'tables() has no duplicates' );
		$this->assert_same( count( $tables ), count( $statements ), 'every catalogued table has exactly one CREATE statement' );

		$declared = [];
		foreach ( $statements as $sql ) {
			$this->assert_contains( 'CREATE TABLE', $sql, 'statement is a CREATE TABLE' );
			$this->assert_contains( 'PRIMARY KEY', $sql, 'statement declares a primary key' );
			$this->assert_contains( 'utf8mb4', $sql, 'statement carries the charset collate' );

			if ( preg_match( '/CREATE TABLE\s+(\S+)\s*\(/', $sql, $m ) ) {
				$declared[] = $m[1];
			}
		}

		$this->assert_same( count( $statements ), count( $declared ), 'every statement names a table' );

		$expected = array_map( static fn ( string $t ): string => 'wp_igbz_' . $t, $tables );
		sort( $expected );
		sort( $declared );
		$this->assert_same( $expected, $declared, 'the DDL creates exactly the catalogued tables' );

		// dbDelta is whitespace sensitive: it needs two spaces after PRIMARY KEY and lowercase "key".
		foreach ( $statements as $sql ) {
			if ( str_contains( $sql, 'PRIMARY KEY' ) ) {
				$this->assert_contains( 'PRIMARY KEY  (', $sql, 'dbDelta requires two spaces after PRIMARY KEY' );
			}
		}

		foreach ( [ 'tenants', 'wallet_ledger', 'api_tokens', 'devices', 'ig_content' ] as $table ) {
			$this->assert_true( in_array( $table, $tables, true ), "core table {$table} is catalogued" );
		}

		$this->assert_same( 'wp_igbz_tenants', Schema::table( 'tenants' ), 'table() prefixes correctly' );

		// Tenant scoping is the backbone of the suite: nearly every table must carry the column.
		// lesson_progress inherits its tenant through enrollment_id, so it deliberately has none.
		$unscoped = [ 'plans', 'logs', 'jobs', 'tenant_domains', 'tenant_members', 'tenants', 'lesson_progress' ];
		foreach ( $statements as $sql ) {
			preg_match( '/CREATE TABLE\s+wp_igbz_(\S+)\s*\(/', $sql, $m );
			$name = $m[1] ?? '';
			if ( '' === $name || in_array( $name, $unscoped, true ) ) {
				continue;
			}
			$this->assert_contains( 'tenant_id', $sql, "{$name} carries a tenant_id column" );
		}
	}
}
