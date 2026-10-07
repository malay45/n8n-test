<?php
/**
 * WP-CLI commands for scripted / very large imports and exports.
 */

defined( 'ABSPATH' ) || exit;

class DOIE_CLI {

	/**
	 * Exports orders.
	 *
	 * ## OPTIONS
	 *
	 * [--file=<path>]
	 * : Output file. Defaults to STDOUT.
	 *
	 * [--format=<format>]
	 * : csv or json.
	 * ---
	 * default: csv
	 * ---
	 *
	 * [--from=<date>]
	 * : Created on or after (YYYY-MM-DD).
	 *
	 * [--to=<date>]
	 * : Created on or before (YYYY-MM-DD).
	 *
	 * [--status=<statuses>]
	 * : Comma-separated statuses, e.g. processing,completed.
	 *
	 * [--vendor=<id>]
	 * : Dokan vendor ID.
	 *
	 * [--scope=<scope>]
	 * : all, vendor (skip split parent orders) or parent (top-level orders only).
	 * ---
	 * default: all
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp doie export --file=orders.csv --status=completed --from=2026-01-01
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Options.
	 */
	public function export( $args, $assoc_args ) {
		$format   = 'json' === WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'csv' ) ? 'json' : 'csv';
		$file     = WP_CLI\Utils\get_flag_value( $assoc_args, 'file', '' );
		$exporter = new DOIE_Exporter(
			array(
				'date_from' => WP_CLI\Utils\get_flag_value( $assoc_args, 'from', '' ),
				'date_to'   => WP_CLI\Utils\get_flag_value( $assoc_args, 'to', '' ),
				'statuses'  => array_filter( explode( ',', (string) WP_CLI\Utils\get_flag_value( $assoc_args, 'status', '' ) ) ),
				'vendor_id' => (int) WP_CLI\Utils\get_flag_value( $assoc_args, 'vendor', 0 ),
				'scope'     => WP_CLI\Utils\get_flag_value( $assoc_args, 'scope', 'all' ),
			)
		);

		$handle = fopen( $file ? $file : 'php://stdout', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $handle ) {
			WP_CLI::error( "Cannot write to {$file}." );
		}
		$count = 'json' === $format ? $exporter->write_json( $handle ) : $exporter->write_csv( $handle );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		if ( $file ) {
			WP_CLI::success( "Exported {$count} orders to {$file}." );
		}
	}

	/**
	 * Imports orders from a CSV or JSON export file.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to the .csv or .json file.
	 *
	 * [--existing=<mode>]
	 * : skip, update or create.
	 * ---
	 * default: skip
	 * ---
	 *
	 * [--match-by-id]
	 * : Treat an existing order with the same ID as the same order.
	 *
	 * [--send-emails]
	 * : Send order emails.
	 *
	 * [--reduce-stock]
	 * : Reduce product stock.
	 *
	 * [--recalculate]
	 * : Recalculate totals.
	 *
	 * [--no-preserve-earnings]
	 * : Recalculate Dokan vendor earnings instead of keeping the exported ones.
	 *
	 * [--batch=<size>]
	 * : Orders per batch.
	 * ---
	 * default: 50
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp doie import orders.csv --existing=update
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Options.
	 */
	public function import( $args, $assoc_args ) {
		list( $file ) = $args;
		if ( ! is_readable( $file ) ) {
			WP_CLI::error( "Cannot read {$file}." );
		}

		$state = DOIE_Job::create(
			$file,
			basename( $file ),
			array(
				'existing'          => WP_CLI\Utils\get_flag_value( $assoc_args, 'existing', 'skip' ),
				'match_by_id'       => (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'match-by-id', false ),
				'send_emails'       => (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'send-emails', false ),
				'reduce_stock'      => (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'reduce-stock', false ),
				'recalculate'       => (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'recalculate', false ),
				'preserve_earnings' => (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'preserve-earnings', true ),
			)
		);
		if ( is_wp_error( $state ) ) {
			WP_CLI::error( $state->get_error_message() );
		}

		$batch    = max( 1, (int) WP_CLI\Utils\get_flag_value( $assoc_args, 'batch', 50 ) );
		$progress = WP_CLI\Utils\make_progress_bar( 'Importing orders', $state['total'] );
		$shown    = 0;
		do {
			$before = $state['processed'];
			$state  = DOIE_Job::run_batch( $state['id'], $batch );
			if ( is_wp_error( $state ) ) {
				WP_CLI::error( $state->get_error_message() );
			}
			$progress->tick( $state['processed'] - $before );
			foreach ( array_slice( $state['messages'], $shown ) as $message ) {
				WP_CLI::warning( $message['text'] );
			}
			$shown = count( $state['messages'] );
		} while ( ! $state['done'] );
		$progress->finish();

		WP_CLI::success( sprintf( 'Created %d, updated %d, skipped %d, failed %d.', $state['created'], $state['updated'], $state['skipped'], $state['failed'] ) );
	}
}
