<?php
/**
 * Uninstall cleanup — scoped strictly to this plugin's own table, options, cron hooks, and
 * user_meta keys. Never touches any other plugin's data or core WordPress tables (spec #11).
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-bg-crypto.php';

global $wpdb;

$table_name = $wpdb->prefix . 'ld_biometric_logs';
// Table name is built only from $wpdb->prefix + a hardcoded literal, never user input.
$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

delete_option( 'bg_settings' );

wp_clear_scheduled_hook( 'bg_recurring_export_compile' );
wp_clear_scheduled_hook( 'bg_recurring_retention_prune' );

$user_ids = get_users( array( 'fields' => 'ID' ) );
foreach ( $user_ids as $user_id ) {
	// Best-effort: remove the vault file on disk before clearing the meta pointer to it.
	// BG_Crypto::decrypt() never throws (returns null on any failure), so this degrades
	// gracefully to "meta cleaned up, file orphaned" if the encryption key is unavailable.
	$encrypted_path = get_user_meta( $user_id, '_secure_face_reference_path', true );
	if ( $encrypted_path ) {
		$path = BG_Crypto::decrypt( $encrypted_path );
		if ( $path && file_exists( $path ) ) {
			@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
	}

	delete_user_meta( $user_id, 'bg_facial_token' );
	delete_user_meta( $user_id, 'bg_bypass_biometric' );
	delete_user_meta( $user_id, 'bg_last_verified_at' );
	delete_user_meta( $user_id, 'bg_account_locked' );
	delete_user_meta( $user_id, '_secure_face_reference_path' );
}
