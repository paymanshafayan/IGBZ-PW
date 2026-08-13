<?php
/**
 * Db::upsert() has to emit valid SQL on two different engines: MySQL/MariaDB on a normal host,
 * and SQLite under WordPress Playground or the sqlite-database-integration plugin.
 *
 * The dialects are mutually exclusive — `ON DUPLICATE KEY UPDATE`, `VALUES(col)` and `GREATEST`
 * are MySQL-only, while `ON CONFLICT ... DO UPDATE`, `excluded.col` and multi-arg `MAX` are the
 * SQLite spellings. A mistake here fails only at runtime on one engine, so it is asserted here.
 */

declare( strict_types=1 );

use IGBZ\Suite\Support\Db;

final class UpsertTest extends TestCase {

	private function db( bool $sqlite ): Db {
		$db = new Db();

		// is_sqlite() caches into a private property; set it directly so no DB engine is needed.
		$ref  = new ReflectionProperty( Db::class, 'is_sqlite' );
		$ref->setValue( $db, $sqlite );

		return $db;
	}

	private function reset_queries(): void {
		$GLOBALS['wpdb']->queries = [];
	}

	private function last(): string {
		return $GLOBALS['wpdb']->last_query();
	}

	public function run(): void {
		$this->test_mysql_dialect();
		$this->test_sqlite_dialect();
		$this->test_strategies();
		$this->test_null_and_types();
		$this->test_locking_is_skipped_on_sqlite();
	}

	private function test_mysql_dialect(): void {
		$this->reset_queries();

		$this->db( false )->upsert(
			'wallet_balances',
			[ 'tenant_id' => 1, 'user_id' => 7, 'balance' => 250.5 ],
			[ 'balance' => 'value' ],
			[ 'tenant_id', 'user_id' ]
		);

		$sql = $this->last();
		$this->assert_contains( 'INSERT INTO wp_igbz_wallet_balances', $sql, 'mysql upsert targets the prefixed table' );
		$this->assert_contains( 'ON DUPLICATE KEY UPDATE', $sql, 'mysql uses ON DUPLICATE KEY UPDATE' );
		$this->assert_contains( 'balance = VALUES(balance)', $sql, 'mysql refers to the incoming row as VALUES(col)' );
		$this->assert_false( str_contains( $sql, 'ON CONFLICT' ), 'mysql never emits ON CONFLICT' );
		$this->assert_false( str_contains( $sql, 'excluded.' ), 'mysql never emits excluded.col' );
	}

	private function test_sqlite_dialect(): void {
		$this->reset_queries();

		$this->db( true )->upsert(
			'wallet_balances',
			[ 'tenant_id' => 1, 'user_id' => 7, 'balance' => 250.5 ],
			[ 'balance' => 'value' ],
			[ 'tenant_id', 'user_id' ]
		);

		$sql = $this->last();
		$this->assert_contains( 'ON CONFLICT (tenant_id, user_id) DO UPDATE SET', $sql, 'sqlite names the conflict target' );
		$this->assert_contains( 'balance = excluded.balance', $sql, 'sqlite refers to the incoming row as excluded.col' );
		$this->assert_false( str_contains( $sql, 'ON DUPLICATE KEY' ), 'sqlite never emits ON DUPLICATE KEY' );
		$this->assert_false( str_contains( $sql, 'VALUES(balance)' ), 'sqlite never emits VALUES(col) as a reference' );
	}

	private function test_strategies(): void {
		$data   = [ 'enrollment_id' => 3, 'lesson_id' => 9, 'seconds_watched' => 120, 'completed' => 1, 'completed_at' => '2026-01-01 00:00:00' ];
		$update = [
			'seconds_watched' => 'greatest',
			'completed'       => 'greatest',
			'completed_at'    => 'coalesce',
		];
		$keys   = [ 'enrollment_id', 'lesson_id' ];

		$this->reset_queries();
		$this->db( false )->upsert( 'lesson_progress', $data, $update, $keys );
		$mysql = $this->last();

		$this->assert_contains( 'seconds_watched = GREATEST(seconds_watched, VALUES(seconds_watched))', $mysql, 'mysql greatest strategy' );
		$this->assert_contains( 'completed_at = COALESCE(completed_at, VALUES(completed_at))', $mysql, 'mysql coalesce strategy' );

		$this->reset_queries();
		$this->db( true )->upsert( 'lesson_progress', $data, $update, $keys );
		$sqlite = $this->last();

		// SQLite has no GREATEST(); multi-argument MAX() is the equivalent.
		$this->assert_contains( 'seconds_watched = MAX(seconds_watched, excluded.seconds_watched)', $sqlite, 'sqlite maps greatest onto MAX' );
		$this->assert_contains( 'completed_at = COALESCE(completed_at, excluded.completed_at)', $sqlite, 'sqlite coalesce strategy' );
		$this->assert_false( str_contains( $sqlite, 'GREATEST' ), 'sqlite never emits GREATEST' );
	}

	private function test_null_and_types(): void {
		$this->reset_queries();

		// A null column must become a literal NULL rather than consuming a bound argument, otherwise
		// every later placeholder shifts by one and the row is written to the wrong columns.
		$this->db( false )->upsert(
			'lesson_progress',
			[ 'enrollment_id' => 3, 'lesson_id' => 9, 'completed_at' => null, 'updated_at' => '2026-01-01 00:00:00' ],
			[ 'updated_at' => 'value' ],
			[ 'enrollment_id', 'lesson_id' ]
		);

		$sql = $this->last();
		$this->assert_contains( 'NULL', $sql, 'null values are inlined as NULL' );
		$this->assert_contains( "'2026-01-01 00:00:00'", $sql, 'the argument after a null still binds correctly' );
		$this->assert_contains( '(enrollment_id, lesson_id, completed_at, updated_at)', $sql, 'column order is preserved' );
	}

	private function test_locking_is_skipped_on_sqlite(): void {
		// GET_LOCK is MySQL-only. On SQLite lock() must succeed anyway, or every wallet debit
		// would abort. Guard that it also issues no query at all.
		$this->reset_queries();
		$db = $this->db( true );

		$this->assert_true( $db->lock( 'wallet:7' ), 'lock succeeds on sqlite' );
		$db->unlock( 'wallet:7' );
		$this->assert_same( 0, count( $GLOBALS['wpdb']->queries ), 'sqlite locking issues no SQL' );
	}
}
