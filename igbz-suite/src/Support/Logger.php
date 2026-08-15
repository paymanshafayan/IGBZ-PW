<?php
namespace IGBZ\Suite\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Structured logger writing to a dedicated table (bounded) and, when WP_DEBUG_LOG is on,
 * to the PHP error log. Secrets are redacted before anything is persisted.
 */
final class Logger {

	public const DEBUG   = 'debug';
	public const INFO    = 'info';
	public const WARNING = 'warning';
	public const ERROR   = 'error';

	private const LEVELS = [ self::DEBUG => 10, self::INFO => 20, self::WARNING => 30, self::ERROR => 40 ];

	public function __construct( private Settings $settings ) {}

	public function debug( string $channel, string $message, array $context = [] ): void {
		$this->log( self::DEBUG, $channel, $message, $context );
	}

	public function info( string $channel, string $message, array $context = [] ): void {
		$this->log( self::INFO, $channel, $message, $context );
	}

	public function warning( string $channel, string $message, array $context = [] ): void {
		$this->log( self::WARNING, $channel, $message, $context );
	}

	public function error( string $channel, string $message, array $context = [] ): void {
		$this->log( self::ERROR, $channel, $message, $context );
	}

	public function log( string $level, string $channel, string $message, array $context = [] ): void {
		$min = self::LEVELS[ $this->settings->string( 'log.level', self::INFO ) ] ?? 20;
		if ( ( self::LEVELS[ $level ] ?? 20 ) < $min ) {
			return;
		}

		global $wpdb;
		$context = self::redact( $context );

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'igbz_logs',
			[
				'tenant_id'  => (int) ( $context['tenant_id'] ?? 0 ),
				'level'      => $level,
				'channel'    => $channel,
				'message'    => mb_substr( $message, 0, 1000 ),
				'context'    => wp_json_encode( $context ),
				'created_at' => current_time( 'mysql', true ),
			],
			[ '%d', '%s', '%s', '%s', '%s', '%s' ]
		);

		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log( sprintf( '[IGBZ][%s][%s] %s %s', $level, $channel, $message, wp_json_encode( $context ) ) ); // phpcs:ignore
		}
	}

	/**
	 * @param array<string,mixed> $context
	 * @return array<string,mixed>
	 */
	public static function redact( array $context ): array {
		$needles = [ 'key', 'token', 'secret', 'password', 'authorization', 'merchant', 'signature' ];
		foreach ( $context as $k => $v ) {
			$lower = strtolower( (string) $k );
			foreach ( $needles as $needle ) {
				if ( str_contains( $lower, $needle ) ) {
					$context[ $k ] = Crypto::MASK;
					continue 2;
				}
			}
			if ( is_array( $v ) ) {
				$context[ $k ] = self::redact( $v );
			}
		}
		return $context;
	}

	/** Trim the log table to the configured retention window. */
	public function prune( int $days = 30 ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'igbz_logs';
		return (int) $wpdb->query( // phpcs:ignore
			$wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) // phpcs:ignore
		);
	}
}
