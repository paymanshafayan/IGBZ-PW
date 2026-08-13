<?php
/**
 * Uninstall routine. Data is only dropped when the operator explicitly opted in through the
 * "Remove all data on uninstall" setting.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

if ( '1' !== (string) get_option( 'igbz_purge_on_uninstall' ) ) {
	return;
}

require_once __DIR__ . '/src/Support/Schema.php';

// Drop children before parents so foreign-key-less installs stay tidy, then the rest.
$tables = array_reverse( \IGBZ\Suite\Support\Schema::tables() );

foreach ( $tables as $table ) {
	$wpdb->query( 'DROP TABLE IF EXISTS `' . $wpdb->prefix . 'igbz_' . $table . '`' ); // phpcs:ignore WordPress.DB
}

$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'igbz\\_%'" ); // phpcs:ignore WordPress.DB
$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'igbz\\_%'" ); // phpcs:ignore WordPress.DB

foreach ( [ 'igbz_cron_five_minutes', 'igbz_cron_hourly', 'igbz_cron_daily' ] as $hook ) {
	wp_clear_scheduled_hook( $hook );
}

foreach ( [ 'igbz_tenant_owner', 'igbz_tenant_staff', 'igbz_instructor' ] as $role ) {
	remove_role( $role );
}
