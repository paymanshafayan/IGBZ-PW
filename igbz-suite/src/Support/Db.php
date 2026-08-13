<?php
namespace IGBZ\Suite\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Thin $wpdb helper: prefixed table names, typed fetch helpers and a real transaction wrapper
 * (the nopCommerce original summed wallet rows without a lock, which allowed overdrafts).
 */
final class Db {

	private ?bool $is_sqlite = null;

	public function wpdb(): \wpdb {
		global $wpdb;
		return $wpdb;
	}

	public function table( string $name ): string {
		return $this->wpdb()->prefix . 'igbz_' . ltrim( $name, '_' );
	}

	public function prepare( string $sql, mixed ...$args ): string {
		return $args ? $this->wpdb()->prepare( $sql, ...$args ) : $sql; // phpcs:ignore
	}

	/** @return array<string,mixed>|null */
	public function row( string $sql, mixed ...$args ): ?array {
		$row = $this->wpdb()->get_row( $this->prepare( $sql, ...$args ), ARRAY_A ); // phpcs:ignore
		return is_array( $row ) ? $row : null;
	}

	/** @return array<int,array<string,mixed>> */
	public function results( string $sql, mixed ...$args ): array {
		$rows = $this->wpdb()->get_results( $this->prepare( $sql, ...$args ), ARRAY_A ); // phpcs:ignore
		return is_array( $rows ) ? $rows : [];
	}

	public function scalar( string $sql, mixed ...$args ): mixed {
		return $this->wpdb()->get_var( $this->prepare( $sql, ...$args ) ); // phpcs:ignore
	}

	/** @return array<int,mixed> */
	public function column( string $sql, mixed ...$args ): array {
		$col = $this->wpdb()->get_col( $this->prepare( $sql, ...$args ) ); // phpcs:ignore
		return is_array( $col ) ? $col : [];
	}

	public function query( string $sql, mixed ...$args ): int {
		return (int) $this->wpdb()->query( $this->prepare( $sql, ...$args ) ); // phpcs:ignore
	}

	/**
	 * @param array<string,mixed> $data
	 * @return int Inserted id, 0 on failure.
	 */
	public function insert( string $table, array $data ): int {
		$ok = $this->wpdb()->insert( $this->table( $table ), $data ); // phpcs:ignore
		return $ok ? (int) $this->wpdb()->insert_id : 0;
	}

	/**
	 * @param array<string,mixed> $data
	 * @param array<string,mixed> $where
	 */
	public function update( string $table, array $data, array $where ): int {
		return (int) $this->wpdb()->update( $this->table( $table ), $data, $where ); // phpcs:ignore
	}

	/** @param array<string,mixed> $where */
	public function delete( string $table, array $where ): int {
		return (int) $this->wpdb()->delete( $this->table( $table ), $where ); // phpcs:ignore
	}

	public function last_error(): string {
		return (string) $this->wpdb()->last_error;
	}

	/**
	 * Run a closure inside a real SQL transaction. InnoDB is required for SELECT ... FOR UPDATE
	 * to be meaningful; on MyISAM the callback still runs but without isolation.
	 *
	 * @template T
	 * @param callable():T $callback
	 * @return T
	 */
	public function transaction( callable $callback ): mixed {
		$wpdb = $this->wpdb();
		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore
		try {
			$result = $callback( $this );
			$wpdb->query( 'COMMIT' ); // phpcs:ignore
			return $result;
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore
			throw $e;
		}
	}

	/**
	 * True when the site runs on something other than MySQL/MariaDB — in practice SQLite, which is
	 * what WordPress Playground and the `sqlite-database-integration` plugin use.
	 *
	 * MySQL-only syntax (ON DUPLICATE KEY UPDATE, GET_LOCK) has to be avoided on that path, so the
	 * result is cached for the request.
	 */
	public function is_sqlite(): bool {
		if ( null === $this->is_sqlite ) {
			$this->is_sqlite = defined( 'DB_ENGINE' ) && 'sqlite' === constant( 'DB_ENGINE' )
				|| class_exists( '\WP_SQLite_DB' )
				|| class_exists( '\WP_SQLite_Translator' );
		}
		return $this->is_sqlite;
	}

	/**
	 * Portable "insert or update" for tables with a UNIQUE key.
	 *
	 * MySQL gets a single atomic `INSERT ... ON DUPLICATE KEY UPDATE`. SQLite gets the equivalent
	 * `INSERT ... ON CONFLICT (...) DO UPDATE SET ...`, which needs the conflicting columns named
	 * explicitly, hence `$conflict_keys`.
	 *
	 * @param array<string,mixed>  $data          Column => value for the INSERT.
	 * @param array<string,string> $update        Column => strategy applied on conflict. Strategies:
	 *                                            `value` overwrite, `greatest` keep the larger,
	 *                                            `coalesce` keep the existing non-null.
	 * @param string[]             $conflict_keys Columns forming the UNIQUE key. Required for SQLite.
	 */
	public function upsert( string $table, array $data, array $update, array $conflict_keys = [] ): int {
		$full         = $this->table( $table );
		$columns      = array_keys( $data );
		$placeholders = [];
		$values       = [];

		foreach ( $data as $value ) {
			if ( null === $value ) {
				$placeholders[] = 'NULL';
				continue;
			}
			$placeholders[] = is_int( $value ) ? '%d' : ( is_float( $value ) ? '%f' : '%s' );
			$values[]       = $value;
		}

		$sqlite = $this->is_sqlite();
		$sets   = [];

		foreach ( $update as $column => $strategy ) {
			// On MySQL the incoming row is VALUES(col); on SQLite it is excluded.col.
			$incoming = $sqlite ? 'excluded.' . $column : 'VALUES(' . $column . ')';
			switch ( $strategy ) {
				case 'greatest':
					// SQLite has no GREATEST; its multi-argument MAX() does the same job.
					$fn     = $sqlite ? 'MAX' : 'GREATEST';
					$sets[] = "{$column} = {$fn}({$column}, {$incoming})";
					break;
				case 'coalesce':
					$sets[] = "{$column} = COALESCE({$column}, {$incoming})";
					break;
				default:
					$sets[] = "{$column} = {$incoming}";
			}
		}

		$sql = 'INSERT INTO ' . $full . ' (' . implode( ', ', $columns ) . ') VALUES (' . implode( ', ', $placeholders ) . ')';

		if ( $sets ) {
			if ( $sqlite ) {
				$target = $conflict_keys ? ' (' . implode( ', ', $conflict_keys ) . ')' : '';
				$sql   .= ' ON CONFLICT' . $target . ' DO UPDATE SET ' . implode( ', ', $sets );
			} else {
				$sql .= ' ON DUPLICATE KEY UPDATE ' . implode( ', ', $sets );
			}
		}

		return $this->query( $sql, ...$values );
	}

	/**
	 * Acquire a named advisory lock (used to serialise wallet debits per customer).
	 *
	 * GET_LOCK is MySQL-only. On SQLite the whole database is single-writer anyway, so there is
	 * nothing to serialise and we report success rather than failing every wallet operation.
	 */
	public function lock( string $name, int $timeout = 5 ): bool {
		if ( $this->is_sqlite() ) {
			return true;
		}
		return '1' === (string) $this->scalar( 'SELECT GET_LOCK(%s, %d)', 'igbz_' . $name, $timeout );
	}

	public function unlock( string $name ): void {
		if ( $this->is_sqlite() ) {
			return;
		}
		$this->scalar( 'SELECT RELEASE_LOCK(%s)', 'igbz_' . $name );
	}
}
