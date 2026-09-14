<?php
/**
 * Activation / deactivation lifecycle. Only ever touches this plugin's own table, options,
 * and cron hooks — spec #11 requires the plugin never modify other tables or plugin data.
 */

defined( 'ABSPATH' ) || exit;

class BG_Activator {

	const TABLE_SUFFIX = 'ld_biometric_logs';

	public static function activate() {
		self::create_table();
		self::maybe_set_default_options();
		self::ensure_directories();

		if ( ! wp_next_scheduled( 'bg_recurring_export_compile' ) ) {
			wp_schedule_event( time(), 'bg_ninety_days', 'bg_recurring_export_compile' );
		}

		if ( ! wp_next_scheduled( 'bg_recurring_retention_prune' ) ) {
			wp_schedule_event( time(), 'daily', 'bg_recurring_retention_prune' );
		}
	}

	public static function deactivate() {
		// Deliberately conservative: deactivation stops the cron jobs but leaves the table,
		// options, and any encrypted user_meta tokens intact so a temporary deactivation
		// (e.g. during a plugin conflict investigation) never silently loses enrollment data.
		wp_clear_scheduled_hook( 'bg_recurring_export_compile' );
		wp_clear_scheduled_hook( 'bg_recurring_retention_prune' );
	}

	/**
	 * Register custom cron intervals used by this plugin.
	 *
	 * @param array $schedules
	 * @return array
	 */
	public static function register_cron_schedules( $schedules ) {
		$schedules['bg_ninety_days'] = array(
			'interval' => 90 * DAY_IN_SECONDS,
			'display'  => __( 'Every 90 Days', 'biometric-gate' ),
		);
		return $schedules;
	}

	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_SUFFIX;
	}

	private static function create_table() {
		global $wpdb;

		$table_name      = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		// VARCHAR rather than ENUM for scan_status: dbDelta handles column-type changes on
		// upgrade far more reliably for VARCHAR than for ENUM, and validation happens in PHP
		// (BG_Logs::VALID_STATUSES) before any insert, so the DB layer stays portable.
		$sql = "CREATE TABLE {$table_name} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			scan_status VARCHAR(20) NOT NULL,
			page_title VARCHAR(255) NOT NULL DEFAULT '',
			page_url VARCHAR(500) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY idx_user_time (user_id, created_at),
			KEY idx_time (created_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	private static function maybe_set_default_options() {
		if ( false === get_option( BG_Settings::OPTION_KEY, false ) ) {
			add_option( BG_Settings::OPTION_KEY, BG_Settings::get_defaults() );
		}
	}

	/**
	 * Create the private, non-public-web directories this plugin needs:
	 * - biometric-gate-tmp: transient home for an uploaded ID scan mid-crop (spec #2), purged immediately after use.
	 * - biometric-gate-backups: CSV export destination (specs #6, #9).
	 * Both get an index.php stub and a deny-all web-server rule as defense in depth beyond
	 * relying solely on "not being in /uploads/".
	 */
	private static function ensure_directories() {
		foreach ( array( BG_TMP_DIR, BG_BACKUP_DIR ) as $dir ) {
			if ( ! file_exists( $dir ) ) {
				wp_mkdir_p( $dir );
			}

			$index_file = trailingslashit( $dir ) . 'index.php';
			if ( ! file_exists( $index_file ) ) {
				file_put_contents( $index_file, "<?php\n// Silence is golden.\n" );
			}

			$htaccess_file = trailingslashit( $dir ) . '.htaccess';
			if ( ! file_exists( $htaccess_file ) ) {
				file_put_contents( $htaccess_file, "Require all denied\nDeny from all\n" );
			}
		}
	}
}
