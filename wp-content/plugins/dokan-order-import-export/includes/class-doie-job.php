<?php
/**
 * Batched import job. The uploaded file is converted once into an NDJSON queue (parent orders
 * first, then sub-orders) stored in a protected uploads folder; each batch then reads the next
 * N lines from a saved byte offset, so imports of any size never time out.
 */

defined( 'ABSPATH' ) || exit;

class DOIE_Job {

	const MAX_ERRORS = 200;

	/**
	 * @return string|WP_Error Protected working directory.
	 */
	public static function dir() {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return new WP_Error( 'doie_dir', $uploads['error'] );
		}
		$dir = trailingslashit( $uploads['basedir'] ) . 'doie-jobs';
		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'doie_dir', __( 'Could not create the import working directory.', 'dokan-order-import-export' ) );
		}
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents
		}
		if ( ! file_exists( $dir . '/index.php' ) ) {
			file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents
		}
		return $dir;
	}

	/**
	 * Creates a job from an import file.
	 *
	 * @param string $path     Path to the uploaded file.
	 * @param string $filename Original file name (for format detection).
	 * @param array  $options  Importer options.
	 * @return array|WP_Error Job state.
	 */
	public static function create( $path, $filename, array $options ) {
		$format = DOIE_Format::detect_format( $filename );
		if ( is_wp_error( $format ) ) {
			return $format;
		}
		$dir = self::dir();
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}
		self::cleanup( $dir );

		$id    = strtolower( wp_generate_password( 20, false ) );
		$queue = $dir . '/' . $id . '.ndjson';
		$out   = fopen( $queue, 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $out ) {
			return new WP_Error( 'doie_queue', __( 'Could not write the import queue file.', 'dokan-order-import-export' ) );
		}

		// Two passes: top-level orders first so every sub-order can be linked to its parent.
		$total = 0;
		foreach ( array( false, true ) as $children ) {
			$records = DOIE_Format::read_records( $path, $format );
			if ( is_wp_error( $records ) ) {
				fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				wp_delete_file( $queue );
				return $records;
			}
			foreach ( $records as $record ) {
				$is_child = ! empty( $record['parent_id'] ) && absint( $record['parent_id'] ) > 0;
				if ( $is_child === $children ) {
					fwrite( $out, wp_json_encode( $record ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
					++$total;
				}
			}
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		if ( ! $total ) {
			wp_delete_file( $queue );
			return new WP_Error( 'doie_empty', __( 'The file does not contain any orders.', 'dokan-order-import-export' ) );
		}

		$state = array(
			'id'        => $id,
			'file'      => sanitize_file_name( $filename ),
			'options'   => DOIE_Importer::normalize_options( $options ),
			'total'     => $total,
			'processed' => 0,
			'offset'    => 0,
			'created'   => 0,
			'updated'   => 0,
			'skipped'   => 0,
			'failed'    => 0,
			'messages'  => array(),
			'done'      => false,
		);
		self::save( $state );
		return $state;
	}

	/**
	 * @param string $id Job ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		if ( ! preg_match( '/^[a-z0-9]{20}$/', (string) $id ) ) {
			return null;
		}
		$state = get_transient( 'doie_job_' . $id );
		return is_array( $state ) ? $state : null;
	}

	/**
	 * @param array $state Job state.
	 */
	private static function save( array $state ) {
		set_transient( 'doie_job_' . $state['id'], $state, DAY_IN_SECONDS );
	}

	/**
	 * Imports the next batch of a job.
	 *
	 * @param string $id   Job ID.
	 * @param int    $size Batch size.
	 * @return array|WP_Error Updated state.
	 */
	public static function run_batch( $id, $size = 20 ) {
		$state = self::get( $id );
		if ( ! $state ) {
			return new WP_Error( 'doie_job', __( 'Import job not found or expired.', 'dokan-order-import-export' ) );
		}
		if ( $state['done'] ) {
			return $state;
		}
		$dir = self::dir();
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}
		$queue  = $dir . '/' . $state['id'] . '.ndjson';
		$handle = file_exists( $queue ) ? fopen( $queue, 'r' ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $handle ) {
			return new WP_Error( 'doie_job', __( 'The import queue file is missing.', 'dokan-order-import-export' ) );
		}
		fseek( $handle, (int) $state['offset'] );

		$importer = new DOIE_Importer( $state['options'], $state['id'] );
		$importer->begin();

		$count = 0;
		while ( $count < $size && false !== ( $line = fgets( $handle ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			++$count;
			++$state['processed'];
			$record = json_decode( $line, true );
			$label  = self::label( is_array( $record ) ? $record : array() );

			try {
				if ( ! is_array( $record ) ) {
					throw new Exception( __( 'Corrupt queue entry.', 'dokan-order-import-export' ) );
				}
				$result = $importer->import_record( $record );
				++$state[ $result['result'] ];
				foreach ( $result['warnings'] as $warning ) {
					self::message( $state, 'warning', $label . ' ' . $warning );
				}
			} catch ( Throwable $e ) {
				++$state['failed'];
				self::message( $state, 'error', $label . ' ' . $e->getMessage() );
			}
		}
		$state['offset'] = ftell( $handle );
		$state['done']   = feof( $handle ) || false === fgets( $handle );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		$importer->end();

		if ( $state['done'] ) {
			wp_delete_file( $queue );
		}
		self::save( $state );
		return $state;
	}

	/**
	 * @param array $record Record.
	 * @return string
	 */
	private static function label( array $record ) {
		$line = isset( $record['_line'] ) ? (int) $record['_line'] : 0;
		$id   = isset( $record['order_id'] ) ? $record['order_id'] : '';
		/* translators: 1: line/position in file, 2: order ID */
		return sprintf( __( '[Row %1$d, order #%2$s]', 'dokan-order-import-export' ), $line, $id );
	}

	/**
	 * @param array  $state Job state (by reference).
	 * @param string $type  error|warning.
	 * @param string $text  Message.
	 */
	private static function message( array &$state, $type, $text ) {
		if ( count( $state['messages'] ) < self::MAX_ERRORS ) {
			$state['messages'][] = array(
				'type' => $type,
				'text' => $text,
			);
		}
	}

	/**
	 * Removes queue files left behind by abandoned imports.
	 *
	 * @param string $dir Working directory.
	 */
	public static function cleanup( $dir ) {
		foreach ( (array) glob( $dir . '/*.ndjson' ) as $file ) {
			if ( $file && filemtime( $file ) < time() - 2 * DAY_IN_SECONDS ) {
				wp_delete_file( $file );
			}
		}
	}
}
