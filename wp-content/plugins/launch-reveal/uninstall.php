<?php
/**
 * Remove plugin settings on uninstall.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}
delete_option( 'launch_reveal_settings' );
