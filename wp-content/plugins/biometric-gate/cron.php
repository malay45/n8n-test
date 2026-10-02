<?php
/**
 * Direct local PHP CLI script for retention pruning.
 * Bypasses web server and login walls completely.
 */

if ( 'cli' !== php_sapi_name() ) {
	die( 'This script can only be run from the command line.' );
}

// Bootstrap WordPress
$dir = dirname( __FILE__ );
$wp_load_path = '';
while ( $dir !== dirname( $dir ) ) {
	if ( file_exists( $dir . '/wp-load.php' ) ) {
		$wp_load_path = $dir . '/wp-load.php';
		break;
	}
	$dir = dirname( $dir );
}

if ( empty( $wp_load_path ) ) {
	die( "Could not find wp-load.php\n" );
}

require_once $wp_load_path;

if ( ! class_exists( 'BG_Settings' ) || ! class_exists( 'BG_Logs' ) ) {
	die( "Biometric Gate plugin is not active or missing classes.\n" );
}

$retention = BG_Settings::get()['retention'];
if ( 'forever' === $retention ) {
	echo "Retention is set to 'Keep Forever'. Skipping.\n";
	exit( 0 );
}

BG_Logs::run_retention_prune();
echo "Pruned (lock permitting).\n";
