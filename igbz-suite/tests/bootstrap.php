<?php
/**
 * A very small WordPress test double.
 *
 * The suite has no Composer tree and CI has no WordPress install, so instead of pulling in the
 * WordPress test library we stub the handful of core functions the pure-logic classes touch.
 * Only classes that do not talk to the database or the network are exercised here; anything that
 * does is covered by the health checks on the Status screen instead.
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
define( 'AUTH_KEY', 'test-auth-key-0123456789abcdefghijklmnop' );
define( 'SECURE_AUTH_SALT', 'test-secure-salt-0123456789abcdefghijkl' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'OBJECT', 'OBJECT' );
define( 'ARRAY_A', 'ARRAY_A' );

$GLOBALS['igbz_test_options'] = [];

function get_option( string $name, $default = false ) {
	return $GLOBALS['igbz_test_options'][ $name ] ?? $default;
}

function update_option( string $name, $value, $autoload = null ): bool {
	$GLOBALS['igbz_test_options'][ $name ] = $value;
	return true;
}

function delete_option( string $name ): bool {
	unset( $GLOBALS['igbz_test_options'][ $name ] );
	return true;
}

function wp_json_encode( $data, int $flags = 0 ) {
	return json_encode( $data, $flags | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
}

function home_url( string $path = '' ): string {
	return 'https://shop.test' . $path;
}

function site_url( string $path = '' ): string {
	return home_url( $path );
}

function rest_url( string $path = '' ): string {
	return home_url( '/wp-json/' . ltrim( $path, '/' ) );
}

function esc_url_raw( string $url ): string {
	return $url;
}

function add_query_arg( string $key, string $value, string $url ): string {
	return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . $key . '=' . rawurlencode( $value );
}

function sanitize_key( string $key ): string {
	return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', $key ) );
}

function sanitize_text_field( string $value ): string {
	return trim( strip_tags( $value ) );
}

function absint( $value ): int {
	return abs( (int) $value );
}

function wp_parse_args( $args, array $defaults = [] ): array {
	return array_merge( $defaults, is_array( $args ) ? $args : [] );
}

function apply_filters( string $hook, $value, ...$rest ) {
	return $value;
}

function do_action( string $hook, ...$args ): void {}

function add_action( string $hook, $callback, int $priority = 10, int $accepted = 1 ): bool {
	return true;
}

function add_filter( string $hook, $callback, int $priority = 10, int $accepted = 1 ): bool {
	return true;
}

/**
 * Number of times each action has "fired". Tests set this directly to simulate a point in the
 * WordPress request lifecycle — see CronScheduleTest, which checks that translation is deferred
 * until `init`.
 *
 * @var array<string,int>
 */
$GLOBALS['igbz_test_did_action'] = [];

function did_action( string $hook ): int {
	return (int) ( $GLOBALS['igbz_test_did_action'][ $hook ] ?? 0 );
}

/**
 * Records a call to __() so tests can assert that translation did not happen too early.
 *
 * @var array<int,string>
 */
$GLOBALS['igbz_test_translated'] = [];

function __( string $text, string $domain = '' ): string {
	$GLOBALS['igbz_test_translated'][] = $text;
	return $text;
}

function esc_html__( string $text, string $domain = '' ): string {
	return $text;
}

function _n( string $single, string $plural, int $number, string $domain = '' ): string {
	return 1 === $number ? $single : $plural;
}

function current_time( string $type = 'mysql', $gmt = 0 ): string {
	return gmdate( 'Y-m-d H:i:s' );
}

function wp_generate_uuid4(): string {
	return sprintf(
		'%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
		random_int( 0, 0xffff ),
		random_int( 0, 0xffff ),
		random_int( 0, 0xffff ),
		random_int( 0, 0x0fff ) | 0x4000,
		random_int( 0, 0x3fff ) | 0x8000,
		random_int( 0, 0xffff ),
		random_int( 0, 0xffff ),
		random_int( 0, 0xffff )
	);
}

/** Just enough of $wpdb for Schema to build its statements. */
/**
 * Named `wpdb` so that Db::wpdb()'s `: \wpdb` return type is satisfied. Real WordPress declares
 * this class in wp-includes/wp-db.php, which is not loaded here.
 */
class wpdb {
	public string $prefix = 'wp_';

	/** Every query passed to query(), so tests can assert on generated SQL. */
	public array $queries = [];

	public int $insert_id = 0;

	public string $last_error = '';

	/** When true, query() reports a driver-level rejection (as $wpdb does) instead of succeeding. */
	public bool $fail_query = false;

	public function get_charset_collate(): string {
		return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
	}

	public function prepare( string $query, ...$args ): string {
		$query = str_replace( [ '%d', '%f' ], '%s', $query );
		return vsprintf( $query, array_map( static fn ( $a ): string => "'" . $a . "'", $args ) );
	}

	public function query( string $sql ): int|bool {
		$this->queries[] = $sql;

		// The real $wpdb returns false when the driver rejects a statement.
		if ( $this->fail_query ) {
			return false;
		}

		return 1;
	}

	public function last_query(): string {
		return $this->queries ? $this->queries[ count( $this->queries ) - 1 ] : '';
	}

	/**
	 * Column-name => format map, mirroring the real wpdb::$field_types.
	 *
	 * Only the entries that collide with IGBZ column names are modelled. `post_id` is the dangerous
	 * one: core forces it to %d even on a VARCHAR column in a plugin table.
	 *
	 * @var array<string,string>
	 */
	public array $field_types = [
		'post_id' => '%d',
		'user_id' => '%d',
		'ID'      => '%d',
	];

	/** Records the [$data, $formats] of the last write, so tests can assert on the formats. */
	public array $last_write = [];

	/**
	 * Rows handed back by get_row()/get_results()/get_var(), newest first.
	 *
	 * Tests that exercise read paths push the rows they expect the code under test to see; the
	 * double does no SQL parsing, so ordering is the test's responsibility.
	 *
	 * @var array<int,mixed>
	 */
	public array $next_results = [];

	public function get_row( string $sql, $output = null ) {
		$this->queries[] = $sql;
		$next            = array_shift( $this->next_results );

		if ( is_array( $next ) && $next && isset( $next[0] ) && is_array( $next[0] ) ) {
			return $next[0];
		}

		return is_array( $next ) ? $next : null;
	}

	public function get_results( string $sql, $output = null ) {
		$this->queries[] = $sql;
		$next            = array_shift( $this->next_results );

		return is_array( $next ) ? $next : [];
	}

	public function get_var( string $sql ) {
		$this->queries[] = $sql;

		return array_shift( $this->next_results );
	}

	public function get_col( string $sql ) {
		$this->queries[] = $sql;
		$next            = array_shift( $this->next_results );

		return is_array( $next ) ? $next : [];
	}

	/**
	 * Mirrors wpdb::insert(), including the name-based format guessing applied when $format is
	 * omitted — that guess is exactly what silently cast ig_funnels.post_id to 0.
	 */
	public function insert( string $table, array $data, $format = null ): int|bool {
		$this->last_write = [
			'table'   => $table,
			'data'    => $data,
			'formats' => $format ?? $this->guess_formats( $data ),
			'guessed' => null === $format,
		];

		$this->queries[] = 'INSERT INTO ' . $table;
		++$this->insert_id;

		return $this->fail_query ? false : 1;
	}

	public function update( string $table, array $data, array $where, $format = null, $where_format = null ): int|bool {
		$this->last_write = [
			'table'   => $table,
			'data'    => $data,
			'formats' => $format ?? $this->guess_formats( $data ),
			'guessed' => null === $format,
		];

		$this->queries[] = 'UPDATE ' . $table;

		return $this->fail_query ? false : 1;
	}

	public function delete( string $table, array $where, $where_format = null ): int|bool {
		$this->last_write = [
			'table'   => $table,
			'data'    => $where,
			'formats' => $where_format ?? $this->guess_formats( $where ),
			'guessed' => null === $where_format,
		];

		$this->queries[] = 'DELETE FROM ' . $table;

		return $this->fail_query ? false : 1;
	}

	/** @return string[] */
	private function guess_formats( array $data ): array {
		$out = [];

		foreach ( $data as $column => $value ) {
			$out[] = $this->field_types[ $column ] ?? '%s';
		}

		return $out;
	}
}

$GLOBALS['wpdb'] = new wpdb();

require_once dirname( __DIR__ ) . '/src/Support/Autoloader.php';
\IGBZ\Suite\Support\Autoloader::register( 'IGBZ\\Suite\\', dirname( __DIR__ ) . '/src' );

function get_bloginfo( string $show = '' ): string {
	return 'IGBZ Test Store';
}

function wp_timezone_string(): string {
	return 'Asia/Tehran';
}

function wp_timezone(): DateTimeZone {
	return new DateTimeZone( wp_timezone_string() );
}

function number_format_i18n( $number, int $decimals = 0 ): string {
	return number_format( (float) $number, $decimals );
}

function wp_date( string $format, ?int $timestamp = null, ?DateTimeZone $timezone = null ) {
	$date = new DateTimeImmutable( '@' . ( $timestamp ?? time() ) );
	return $date->setTimezone( $timezone ?? wp_timezone() )->format( $format );
}

function is_admin(): bool {
	return false;
}

function wp_next_scheduled( string $hook ) {
	return false;
}

function igbz(): \IGBZ\Suite\Support\Plugin {
	return \IGBZ\Suite\Support\Plugin::instance();
}

// Boot the container without the WordPress hook side effects that boot() would add.
( function (): void {
	$plugin     = \IGBZ\Suite\Support\Plugin::instance();
	$reflection = new ReflectionMethod( $plugin, 'register_core_services' );
	$reflection->invoke( $plugin );
} )();

/**
 * Wipe the stored options and hand back a clean Settings instance that is also the one the
 * container (and therefore every service reached through igbz()) will use.
 */
function igbz_test_reset_settings(): \IGBZ\Suite\Support\Settings {
	$GLOBALS['igbz_test_options'] = [];
	igbz()->bind( 'settings', static fn () => new \IGBZ\Suite\Support\Settings() );
	igbz()->bind( 'logger', static fn ( $c ) => new \IGBZ\Suite\Support\Logger( $c->get( 'settings' ) ) );
	return igbz()->settings();
}
