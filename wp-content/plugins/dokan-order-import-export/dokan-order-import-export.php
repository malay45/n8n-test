<?php
/**
 * Plugin Name: Dokan Order Import Export for WooCommerce
 * Description: Import and export WooCommerce orders (CSV or JSON) with full Dokan / Dokan Pro awareness: vendor assignment, parent / sub-order relationships, vendor earnings and admin commission.
 * Version: 1.0.0
 * Requires PHP: 7.4
 * Requires at least: 5.9
 * Requires Plugins: woocommerce
 * WC requires at least: 6.0
 * License: GPLv2 or later
 * Text Domain: dokan-order-import-export
 */

defined( 'ABSPATH' ) || exit;

define( 'DOIE_VERSION', '1.0.0' );
define( 'DOIE_FILE', __FILE__ );
define( 'DOIE_DIR', plugin_dir_path( __FILE__ ) );
define( 'DOIE_URL', plugin_dir_url( __FILE__ ) );

// Works with both the legacy posts storage and High-Performance Order Storage (HPOS):
// every order read/write goes through the WooCommerce CRUD layer.
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

add_action( 'plugins_loaded', 'doie_bootstrap', 20 );

/**
 * Loads the plugin once WooCommerce is available.
 */
function doie_bootstrap() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action(
			'admin_notices',
			function () {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'Dokan Order Import Export requires WooCommerce to be installed and active.', 'dokan-order-import-export' ) . '</p></div>';
			}
		);
		return;
	}

	require_once DOIE_DIR . 'includes/class-doie-query.php';
	require_once DOIE_DIR . 'includes/class-doie-format.php';
	require_once DOIE_DIR . 'includes/class-doie-dokan.php';
	require_once DOIE_DIR . 'includes/class-doie-exporter.php';
	require_once DOIE_DIR . 'includes/class-doie-importer.php';
	require_once DOIE_DIR . 'includes/class-doie-job.php';

	DOIE_Query::init();

	if ( is_admin() ) {
		require_once DOIE_DIR . 'includes/class-doie-admin.php';
		new DOIE_Admin();
	}

	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		require_once DOIE_DIR . 'includes/class-doie-cli.php';
		WP_CLI::add_command( 'doie', 'DOIE_CLI' );
	}
}
