<?php
namespace IGBZ\Suite\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Minimal PSR-4 autoloader so the plugin can be installed from a zip without Composer.
 */
final class Autoloader {

	/** @var array<string,string> */
	private static array $prefixes = [];

	public static function register( string $prefix, string $base_dir ): void {
		self::$prefixes[ $prefix ] = rtrim( $base_dir, '/\\' ) . '/';
		spl_autoload_register( [ self::class, 'load' ] );
	}

	public static function load( string $class ): void {
		foreach ( self::$prefixes as $prefix => $base_dir ) {
			if ( 0 !== strncmp( $prefix, $class, strlen( $prefix ) ) ) {
				continue;
			}
			$relative = substr( $class, strlen( $prefix ) );
			$file     = $base_dir . str_replace( '\\', '/', $relative ) . '.php';
			if ( is_readable( $file ) ) {
				require_once $file;
				return;
			}
		}
	}
}
