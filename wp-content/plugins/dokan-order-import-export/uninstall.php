<?php
/**
 * Removes the plugin's temporary import files and job state. Imported orders are left intact.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$doie_uploads = wp_upload_dir();
$doie_dir     = trailingslashit( $doie_uploads['basedir'] ) . 'doie-jobs';
if ( is_dir( $doie_dir ) ) {
	foreach ( (array) glob( $doie_dir . '/{,.}*', GLOB_BRACE ) as $doie_file ) {
		if ( $doie_file && is_file( $doie_file ) ) {
			wp_delete_file( $doie_file );
		}
	}
	@rmdir( $doie_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
}

$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_doie\\_job\\_%' OR option_name LIKE '\\_transient\\_timeout\\_doie\\_job\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
