<?php
/**
 * Db::upsert() must emit MySQL dialect on BOTH supported engines.
 *
 * That is deliberate and was learned the hard way. $wpdb always speaks MySQL: under WordPress
 * Playground the `sqlite-database-integration` drop-in is a MySQL-to-SQLite *translator*, not a
 * raw SQLite connection. An earlier revision emitted native SQLite spelling (`ON CONFLICT ... DO
 * UPDATE SET excluded.col`) whenever is_sqlite() was true; the translator could not parse it, the
 * statement aborted the surrounding transaction, and because $wpdb still reported success the
 * wallet reported credited balances that were never actually written.
 *
 * These tests therefore pin the dialect to MySQL on both engines and assert that a rejected
 * statement raises instead of silently returning.
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
		$this->test_sqlite_uses_mysql_dialect_too();
		$this->test_strategies();
		$this->test_null_and_types();
		$this->test_failed_upsert_raises();
		$this->test_write_formats_are_explicit();
		$this->test_locking_is_skipped_on_sqlite();
	}

	/**
	 * insert()/update()/delete() must pass explicit formats to $wpdb.
	 *
	 * Left to itself $wpdb guesses the format from the column *name* via its $field_types map,
	 * which is hard-coded for core tables yet applied to every table. `post_id` is forced to %d
	 * there, so the VARCHAR Instagram media id in ig_funnels.post_id was cast to 0 and the funnel
	 * stopped matching any comment — silently, on MySQL and SQLite alike.
	 */
	private function test_write_formats_are_explicit(): void {
		$wpdb = $GLOBALS['wpdb'];
		$db   = $this->db( false );

		$db->insert(
			'ig_funnels',
			[ 'tenant_id' => 1, 'post_id' => 'POST-123', 'keyword' => 'coffee', 'grant_wallet_credit' => 2.5, 'is_active' => 1 ]
		);

		$this->assert_false( $wpdb->last_write['guessed'], 'insert() never lets wpdb guess formats' );

		$formats = $wpdb->last_write['formats'];
		$columns = array_keys( $wpdb->last_write['data'] );
		$by_name = array_combine( $columns, $formats );

		$this->assert_same( '%s', $by_name['post_id'], 'a string post_id is bound as a string, not %d' );
		$this->assert_same( '%d', $by_name['tenant_id'], 'an int column is bound as %d' );
		$this->assert_same( '%f', $by_name['grant_wallet_credit'], 'a float column is bound as %f' );
		$this->assert_same( '%s', $by_name['keyword'], 'a string column is bound as %s' );

		// The same guard has to apply to updates, which is how an existing funnel is edited.
		$db->update( 'ig_funnels', [ 'post_id' => 'POST-456' ], [ 'id' => 3 ] );
		$this->assert_false( $wpdb->last_write['guessed'], 'update() never lets wpdb guess formats' );
		$this->assert_same( '%s', $wpdb->last_write['formats'][0], 'update binds a string post_id as %s' );

		$db->delete( 'ig_funnels', [ 'post_id' => 'POST-456' ] );
		$this->assert_false( $wpdb->last_write['guessed'], 'delete() never lets wpdb guess formats' );
		$this->assert_same( '%s', $wpdb->last_write['formats'][0], 'delete binds a string post_id as %s' );
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

	private function test_sqlite_uses_mysql_dialect_too(): void {
		$this->reset_queries();

		$this->db( true )->upsert(
			'wallet_balances',
			[ 'tenant_id' => 1, 'user_id' => 7, 'balance' => 250.5 ],
			[ 'balance' => 'value' ],
			[ 'tenant_id', 'user_id' ]
		);

		$sql = $this->last();

		// The sqlite drop-in parses MySQL, so the statement must be identical to the MySQL one.
		$this->assert_contains( 'ON DUPLICATE KEY UPDATE', $sql, 'sqlite still receives ON DUPLICATE KEY UPDATE' );
		$this->assert_contains( 'balance = VALUES(balance)', $sql, 'sqlite still receives VALUES(col)' );

		// Native SQLite spelling is unparseable by the translator and must never be emitted.
		$this->assert_false( str_contains( $sql, 'ON CONFLICT' ), 'never emits native SQLite ON CONFLICT' );
		$this->assert_false( str_contains( $sql, 'excluded.' ), 'never emits native SQLite excluded.col' );
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

		// GREATEST() is translated for us, so the SQLite path emits the same MySQL text.
		$this->assert_same( $mysql, $sqlite, 'both engines receive byte-identical SQL' );
		$this->assert_contains( 'seconds_watched = GREATEST(seconds_watched, VALUES(seconds_watched))', $sqlite, 'greatest strategy stays MySQL' );
		$this->assert_false( str_contains( $sqlite, 'MAX(' ), 'never rewrites GREATEST into SQLite MAX()' );
	}

	private function test_failed_upsert_raises(): void {
		// A rejected statement must not look like a successful write. $wpdb->query() returns false
		// on error; upsert() has to turn that into an exception so the caller's transaction rolls
		// back instead of committing a half-written record.
		$this->reset_queries();

		$wpdb             = $GLOBALS['wpdb'];
		$wpdb->fail_query = true;
		$wpdb->last_error = 'Failed to parse the MySQL query.';

		$raised = false;
		try {
			$this->db( true )->upsert(
				'wallet_balances',
				[ 'tenant_id' => 1, 'user_id' => 7, 'balance' => 250.5 ],
				[ 'balance' => 'value' ],
				[ 'tenant_id', 'user_id' ]
			);
		} catch ( \RuntimeException $e ) {
			$raised = true;
			$this->assert_contains( 'wp_igbz_wallet_balances', $e->getMessage(), 'the error names the table' );
			$this->assert_contains( 'Failed to parse', $e->getMessage(), 'the driver error is preserved' );
		} finally {
			$wpdb->fail_query = false;
			$wpdb->last_error = '';
		}

		$this->assert_true( $raised, 'a rejected upsert raises instead of returning silently' );
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
