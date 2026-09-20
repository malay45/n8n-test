<?php

/**
 * Audit log storage, Tab C queries, chunked CSV export, and the WP-Cron backup/pruning
 * engine (spec #6, #9). Every export — manual "Export & Wipe", per-user "Export Student
 * History", the automated 90-day compile, and retention pruning — funnels through
 * export_to_csv() below, which streams the result set in fixed-size batches so peak PHP
 * memory stays flat whether the table holds 10 rows or 10 million (spec #9's memory-crash
 * concern). All of it runs off an HTTP request via wp_schedule_single_event()/WP-Cron, so a
 * slow export can never hit a browser or PHP-FPM request timeout.
 */

defined('ABSPATH') || exit;

class BG_Logs
{

	const VALID_STATUSES     = array('success', 'failure', 'timeout', 'cloud_bypass');
	const BATCH_SIZE         = 1000;
	const PROGRESS_TRANSIENT = 'bg_export_progress';

	public static function init()
	{
		add_action('bg_recurring_export_compile', array(__CLASS__, 'run_periodic_export'));
		add_action('bg_recurring_retention_prune', array(__CLASS__, 'run_retention_prune'));
		add_action('bg_job_export_and_wipe', array(__CLASS__, 'run_export_and_wipe'), 10, 2);
		add_action('bg_job_export_user', array(__CLASS__, 'run_export_user'), 10, 2);
	}

	/**
	 * Record one scan event. Called from the REST controller only, after the server has
	 * independently confirmed the result — never from client-supplied "status: success" input.
	 *
	 * @return int|false Inserted row ID, or false on invalid input.
	 */
	public static function insert($user_id, $status, $page_title, $page_url, $confidence_score = null)
	{
		if (! in_array($status, self::VALID_STATUSES, true)) {
			return false;
		}

		global $wpdb;

		return $wpdb->insert(
			BG_Activator::table_name(),
			array(
				'user_id'          => absint($user_id),
				'scan_status'      => $status,
				'page_title'       => mb_substr(wp_strip_all_tags((string) $page_title), 0, 255),
				'page_url'         => mb_substr(esc_url_raw((string) $page_url), 0, 500),
				'confidence_score' => null === $confidence_score ? null : round((float) $confidence_score, 2),
				'created_at'       => current_time('mysql', true),
			),
			array('%d', '%s', '%s', '%s', '%f', '%s')
		) ? $wpdb->insert_id : false;
	}

	/**
	 * Paginated, filterable query for Tab C's live audit table.
	 *
	 * @param array $args {
	 *     @type string $search    Free-text match against user display_name/user_login/email.
	 *     @type int    $user_id   Filter to one user (the "click a name to drill down" view).
	 *     @type string $orderby   'time' (default) or 'name'.
	 *     @type string $order     'DESC' (default) or 'ASC'.
	 *     @type int    $page      1-based page number.
	 *     @type int    $per_page  Rows per page.
	 * }
	 * @return array{rows: array<int,array>, total: int}
	 */
	public static function query(array $args = array())
	{
		global $wpdb;

		$defaults = array(
			'search'   => '',
			'user_id'  => 0,
			'orderby'  => 'time',
			'order'    => 'DESC',
			'page'     => 1,
			'per_page' => 50,
		);
		$args = wp_parse_args($args, $defaults);

		$logs_table  = BG_Activator::table_name();
		$users_table = $wpdb->users;

		$where  = array('1=1');
		$params = array();

		if (! empty($args['user_id'])) {
			$where[]  = 'l.user_id = %d';
			$params[] = absint($args['user_id']);
		}

		if ('' !== trim((string) $args['search'])) {
			$like     = '%' . $wpdb->esc_like($args['search']) . '%';
			$where[]  = '(u.display_name LIKE %s OR u.user_email LIKE %s)';
			$params[] = $like;
			$params[] = $like;
		}

		$order   = ('ASC' === strtoupper($args['order'])) ? 'ASC' : 'DESC';
		$orderby = ('name' === $args['orderby']) ? 'u.display_name' : 'l.created_at';

		$per_page = max(1, min(200, absint($args['per_page'])));
		$page     = max(1, absint($args['page']));
		$offset   = ($page - 1) * $per_page;

		$where_sql = implode(' AND ', $where);

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- table names are hardcoded, $where_sql built only from placeholders above.
		$count_sql = "SELECT COUNT(*) FROM {$logs_table} l LEFT JOIN {$users_table} u ON u.ID = l.user_id WHERE {$where_sql}";
		$total     = (int) $wpdb->get_var($wpdb->prepare($count_sql, $params));

		$rows_sql = "SELECT l.id, l.user_id, l.scan_status, l.page_title, l.page_url, l.confidence_score, l.created_at, u.display_name
			FROM {$logs_table} l LEFT JOIN {$users_table} u ON u.ID = l.user_id
			WHERE {$where_sql}
			ORDER BY {$orderby} {$order}
			LIMIT %d OFFSET %d";

		$rows = $wpdb->get_results($wpdb->prepare($rows_sql, array_merge($params, array($per_page, $offset))), ARRAY_A);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

		return array(
			'rows'  => $rows ? $rows : array(),
			'total' => $total,
		);
	}

	// ---------------------------------------------------------------------
	// Chunked CSV export
	// ---------------------------------------------------------------------

	/**
	 * Stream a (optionally filtered) result set to a CSV file in fixed-size, keyset-paginated
	 * batches. Keyset pagination (WHERE id > $last_id) is used instead of LIMIT/OFFSET so
	 * performance stays flat even many millions of rows in, where OFFSET would otherwise force
	 * MySQL to scan and discard every preceding row on each batch.
	 *
	 * @param string      $filepath   Absolute path to write to (inside BG_BACKUP_DIR).
	 * @param int         $user_id    0 for all users, or a specific user ID to filter to.
	 * @param string|null $progress_key Transient key to publish {processed} progress to, or null to skip.
	 * @return int Total rows written.
	 */
	public static function export_to_csv($filepath, $user_id = 0, $progress_key = null)
	{
		global $wpdb;

		$logs_table = BG_Activator::table_name();;

		if ( ! file_exists( BG_BACKUP_DIR ) ) {
			wp_mkdir_p( BG_BACKUP_DIR );
		}

		$handle = @fopen( $filepath, 'w' );

		if (false === $handle) {
			if ( $progress_key ) {
				set_transient( $progress_key, array( 'status' => 'error', 'message' => 'Could not write to backup directory.' ), HOUR_IN_SECONDS );
			}
			return 0;
		}

		fputcsv($handle, array('Timestamp (UTC)', 'User ID', 'User Full Name', 'Scan Status', 'Confidence Score (%)', 'Page Title', 'Page URL'));

		$last_id  = 0;
		$written  = 0;

		if ($progress_key) {
			set_transient($progress_key, array('status' => 'running', 'processed' => 0), HOUR_IN_SECONDS);
		}

		do {
			$where  = array('id > %d');
			$params = array($last_id);

			if ($user_id > 0) {
				$where[]  = 'user_id = %d';
				$params[] = $user_id;
			}

			$where_sql = implode(' AND ', $where);

			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- table name hardcoded, $where_sql built only from placeholders above.
			$sql  = "SELECT id, user_id, scan_status, page_title, page_url, confidence_score, created_at FROM {$logs_table} WHERE {$where_sql} ORDER BY id ASC LIMIT %d";
			$rows = $wpdb->get_results($wpdb->prepare($sql, array_merge($params, array(self::BATCH_SIZE))), ARRAY_A);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

			if (empty($rows)) {
				break;
			}

			// One lookup per batch (not per row) to resolve display names.
			$user_ids = array_unique(array_map('intval', wp_list_pluck($rows, 'user_id')));
			$names    = array();
			if ($user_ids) {
				foreach (get_users(array('include' => $user_ids, 'fields' => array('ID', 'display_name'))) as $u) {
					$names[(int) $u->ID] = $u->display_name;
				}
			}

			foreach ($rows as $row) {
				fputcsv(
					$handle,
					array(
						$row['created_at'],
						$row['user_id'],
						isset($names[(int) $row['user_id']]) ? $names[(int) $row['user_id']] : '(deleted user)',
						$row['scan_status'],
						null === $row['confidence_score'] ? '' : $row['confidence_score'],
						$row['page_title'],
						$row['page_url'],
					)
				);
				$last_id = max($last_id, (int) $row['id']);
			}

			$written += count($rows);
			unset($rows, $user_ids, $names); // Free the batch before fetching the next one.

			if ($progress_key) {
				set_transient($progress_key, array('status' => 'running', 'processed' => $written), HOUR_IN_SECONDS);
			}
		} while (true);

		fclose($handle);

		if ($progress_key) {
			set_transient(
				$progress_key,
				array('status' => 'done', 'processed' => $written, 'file' => basename($filepath)),
				HOUR_IN_SECONDS
			);
		}

		return $written;
	}

	private static function backup_filepath($prefix)
	{
		$filename = sprintf('%s-%s.csv', sanitize_file_name($prefix), gmdate('Y-m-d-His'));
		return trailingslashit(BG_BACKUP_DIR) . $filename;
	}

	// ---------------------------------------------------------------------
	// Background jobs (all run off-request via WP-Cron / wp_schedule_single_event)
	// ---------------------------------------------------------------------

	/**
	 * Tab C's "Export & Wipe Live Logs": compile to CSV, confirm it's on disk, then delete.
	 * Queued via wp_schedule_single_event() so it runs outside the HTTP request that clicked
	 * the button (spec #9's memory-crash guard).
	 *
	 * $user_id scopes BOTH the export and the deletion to one student when Tab C's log view
	 * is filtered to them — without this, clicking the button while filtered to a single user
	 * still wiped every user's rows via a blanket TRUNCATE, which is the exact "structural
	 * data leak" the client's QA pass flagged. 0 (unfiltered) preserves the original
	 * whole-table TRUNCATE behavior.
	 *
	 * @param int         $user_id
	 * @param string|null $progress_key
	 */
	public static function run_export_and_wipe($user_id = 0, $progress_key = null)
	{
		global $wpdb;

		$user_id      = absint($user_id);
		$progress_key = $progress_key ? $progress_key : self::PROGRESS_TRANSIENT;

		$prefix   = $user_id > 0 ? ('biometric-logs-wipe-user-' . $user_id) : 'biometric-logs-manual-export';
		$filepath = self::backup_filepath($prefix);
		$written  = self::export_to_csv($filepath, $user_id, $progress_key);

		if (file_exists($filepath) && filesize($filepath) > 0) {
			$table = BG_Activator::table_name();
			if ($user_id > 0) {
				$wpdb->delete($table, array('user_id' => $user_id), array('%d'));
			} else {
				$wpdb->query("TRUNCATE TABLE {$table}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- hardcoded table name.
			}
		}

		return $written;
	}

	/**
	 * Tab A's per-student "Export Student History": export only, no deletion.
	 *
	 * @param int $user_id
	 * @param string $progress_key
	 */
	public static function run_export_user($user_id, $progress_key)
	{
		$filepath = self::backup_filepath('biometric-logs-user-' . absint($user_id));
		self::export_to_csv($filepath, absint($user_id), $progress_key);
	}

	/**
	 * The fixed automated backup snapshot ("compile history logs into CSV every 90 days").
	 * Full export, no deletion — independent of the retention/pruning setting below.
	 */
	public static function run_periodic_export()
	{
		$filepath = self::backup_filepath('biometric-logs-periodic');
		self::export_to_csv($filepath, 0);
	}

	/**
	 * Daily: back up and delete only the rows older than the admin's configured retention
	 * window (spec #6's "Pruning & Backup Architecture"). "Keep Forever" disables this entirely.
	 */
	public static function run_retention_prune()
	{
		$retention = BG_Settings::get()['retention'];

		if ('forever' === $retention) {
			return;
		}

		$days = absint($retention);
		if ($days < 1) {
			return;
		}

		global $wpdb;
		$table  = BG_Activator::table_name();
		$cutoff = gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));

		$has_old_rows = (int) $wpdb->get_var(
			$wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE created_at < %s", $cutoff) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);

		if ($has_old_rows < 1) {
			return;
		}

		// Back up exactly the rows about to be pruned before deleting them.
		$filepath = self::backup_filepath('biometric-logs-retention-prune');
		self::export_expired_to_csv($filepath, $cutoff);

		if (file_exists($filepath) && filesize($filepath) > 0) {
			$wpdb->query(
				$wpdb->prepare("DELETE FROM {$table} WHERE created_at < %s", $cutoff) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			);
		}
	}

	/**
	 * Same batch-streaming approach as export_to_csv(), scoped to rows older than $cutoff.
	 */
	private static function export_expired_to_csv($filepath, $cutoff)
	{
		global $wpdb;

		$logs_table = BG_Activator::table_name();
		$handle     = @fopen($filepath, 'w');
		if (false === $handle) {
			return;
		}

		fputcsv($handle, array('Timestamp (UTC)', 'User ID', 'User Full Name', 'Scan Status', 'Confidence Score (%)', 'Page Title', 'Page URL'));

		$last_id = 0;
		do {
			$sql  = "SELECT id, user_id, scan_status, page_title, page_url, confidence_score, created_at FROM {$logs_table} WHERE id > %d AND created_at < %s ORDER BY id ASC LIMIT %d";
			$rows = $wpdb->get_results($wpdb->prepare($sql, $last_id, $cutoff, self::BATCH_SIZE), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

			if (empty($rows)) {
				break;
			}

			$user_ids = array_unique(array_map('intval', wp_list_pluck($rows, 'user_id')));
			$names    = array();
			foreach (get_users(array('include' => $user_ids, 'fields' => array('ID', 'display_name'))) as $u) {
				$names[(int) $u->ID] = $u->display_name;
			}

			foreach ($rows as $row) {
				fputcsv(
					$handle,
					array(
						$row['created_at'],
						$row['user_id'],
						isset($names[(int) $row['user_id']]) ? $names[(int) $row['user_id']] : '(deleted user)',
						$row['scan_status'],
						null === $row['confidence_score'] ? '' : $row['confidence_score'],
						$row['page_title'],
						$row['page_url'],
					)
				);
				$last_id = max($last_id, (int) $row['id']);
			}

			unset($rows, $user_ids, $names);
		} while (true);

		fclose($handle);
	}

	public static function get_export_progress()
	{
		$progress = get_transient(self::PROGRESS_TRANSIENT);
		return $progress ? $progress : array('status' => 'idle');
	}

	// ---------------------------------------------------------------------
	// Server document archive list (Tab C)
	// ---------------------------------------------------------------------

	/**
	 * @return array<int,array{name:string,size:int,modified:int}>
	 */
	public static function list_backup_files()
	{
		$files = glob(trailingslashit(BG_BACKUP_DIR) . '*.csv');
		if (! $files) {
			return array();
		}

		$out = array();
		foreach ($files as $file) {
			$out[] = array(
				'name'     => basename($file),
				'size'     => filesize($file),
				'modified' => filemtime($file),
			);
		}

		usort($out, function ($a, $b) {
			return $b['modified'] <=> $a['modified'];
		});

		return $out;
	}

	/**
	 * @param string $filename Basename only — validated to prevent path traversal.
	 * @return string|null Absolute path if it safely resolves inside BG_BACKUP_DIR, else null.
	 */
	public static function resolve_backup_path($filename)
	{
		$safe_name = basename((string) $filename);
		if ('' === $safe_name || $safe_name !== $filename) {
			return null;
		}

		$path = trailingslashit(BG_BACKUP_DIR) . $safe_name;
		$real_backup_dir = realpath(BG_BACKUP_DIR);
		$real_path       = realpath($path);

		if (false === $real_path || false === $real_backup_dir || 0 !== strpos($real_path, $real_backup_dir)) {
			return null;
		}

		return $real_path;
	}

	public static function delete_backup_file($filename)
	{
		$path = self::resolve_backup_path($filename);
		if (null === $path || ! file_exists($path)) {
			return false;
		}
		return unlink($path);
	}
}
