<?php
namespace IGBZ\Suite\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Thin $wpdb helper: prefixed table names, typed fetch helpers and a real transaction wrapper
 * (the nopCommerce original summed wallet rows without a lock, which allowed overdrafts).
 */
final class Db {

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

	/** Acquire a named advisory lock (used to serialise wallet debits per customer). */
	public function lock( string $name, int $timeout = 5 ): bool {
		return '1' === (string) $this->scalar( 'SELECT GET_LOCK(%s, %d)', 'igbz_' . $name, $timeout );
	}

	public function unlock( string $name ): void {
		$this->scalar( 'SELECT RELEASE_LOCK(%s)', 'igbz_' . $name );
	}
}
