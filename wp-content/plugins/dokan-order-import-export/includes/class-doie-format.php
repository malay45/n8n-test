<?php
/**
 * File format definition shared by the exporter and the importer.
 *
 * One order = one record. In CSV, scalar fields are plain columns and nested data (items,
 * notes, meta, ...) is stored as JSON inside a cell, so a file round-trips without loss.
 * In JSON, the same record keys are used with the nested data as real arrays.
 */

defined( 'ABSPATH' ) || exit;

class DOIE_Format {

	const ADDRESS_FIELDS = array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'email', 'phone' );

	const JSON_COLUMNS = array( 'line_items', 'shipping_lines', 'fee_lines', 'coupon_lines', 'tax_lines', 'refunds', 'order_notes', 'meta_data' );

	/**
	 * Ordered list of export columns.
	 *
	 * @return string[]
	 */
	public static function columns() {
		$columns = array(
			'order_id',
			'order_number',
			'parent_id',
			'status',
			'currency',
			'prices_include_tax',
			'date_created',
			'date_modified',
			'date_paid',
			'date_completed',
			'customer_id',
			'customer_email',
			'customer_note',
		);

		foreach ( self::ADDRESS_FIELDS as $field ) {
			$columns[] = 'billing_' . $field;
		}
		foreach ( self::ADDRESS_FIELDS as $field ) {
			if ( 'email' !== $field ) {
				$columns[] = 'shipping_' . $field;
			}
		}

		$columns = array_merge(
			$columns,
			array(
				'payment_method',
				'payment_method_title',
				'transaction_id',
				'customer_ip_address',
				'customer_user_agent',
				'created_via',
				'discount_total',
				'discount_tax',
				'shipping_total',
				'shipping_tax',
				'cart_tax',
				'total_tax',
				'total',
				'total_refunded',
				'dokan_vendor_id',
				'dokan_vendor_email',
				'dokan_store_name',
				'dokan_has_sub_order',
				'dokan_order_total',
				'dokan_vendor_earning',
				'dokan_admin_commission',
			),
			self::JSON_COLUMNS
		);

		return apply_filters( 'doie_export_columns', $columns );
	}

	/**
	 * Converts a record to a CSV row in column order.
	 *
	 * @param array    $record  Order record.
	 * @param string[] $columns Columns.
	 * @return array
	 */
	public static function record_to_row( array $record, array $columns ) {
		$row = array();
		foreach ( $columns as $column ) {
			$value = isset( $record[ $column ] ) ? $record[ $column ] : '';
			if ( is_array( $value ) || is_object( $value ) ) {
				$value = wp_json_encode( $value );
			} elseif ( is_bool( $value ) ) {
				$value = $value ? 'yes' : 'no';
			} else {
				$value = self::escape_cell( (string) $value );
			}
			$row[] = $value;
		}
		return $row;
	}

	/**
	 * Guards against spreadsheet formula injection. Negative numbers are left untouched so
	 * totals stay numeric; everything else starting with a formula trigger gets a leading quote.
	 *
	 * @param string $value Cell value.
	 * @return string
	 */
	public static function escape_cell( $value ) {
		if ( '' === $value || is_numeric( $value ) ) {
			return $value;
		}
		if ( in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			return "'" . $value;
		}
		return $value;
	}

	/**
	 * Reverses escape_cell().
	 *
	 * @param string $value Cell value.
	 * @return string
	 */
	public static function unescape_cell( $value ) {
		if ( strlen( $value ) > 1 && "'" === $value[0] && in_array( $value[1], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			return substr( $value, 1 );
		}
		return $value;
	}

	/**
	 * Builds the export entry for one meta value. Values holding PHP objects (for example the
	 * WC_Meta_Data objects inside the coupon_data snapshot Dokan stores on sub-order coupons) lose
	 * their type in JSON, and plugins reading them back expect objects. Such values also carry a
	 * serialized copy that import_meta_value() restores.
	 *
	 * @param string $key   Meta key.
	 * @param mixed  $value Meta value.
	 * @return array
	 */
	public static function export_meta( $key, $value ) {
		$entry = array(
			'key'   => $key,
			'value' => self::contains_object( $value ) ? json_decode( wp_json_encode( $value ), true ) : $value,
		);
		if ( self::contains_object( $value ) ) {
			$entry['php'] = base64_encode( serialize( $value ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		}
		return $entry;
	}

	/**
	 * Restores a meta value from an export entry.
	 *
	 * @param array $entry Entry with key, value and optional php.
	 * @return mixed
	 */
	public static function import_meta_value( array $entry ) {
		if ( ! empty( $entry['php'] ) && is_string( $entry['php'] ) ) {
			$serialized = base64_decode( $entry['php'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
			$allowed    = apply_filters( 'doie_import_allowed_meta_classes', array( 'WC_Meta_Data', 'stdClass', 'WC_DateTime', 'DateTime', 'DateTimeZone' ) );
			if ( is_string( $serialized ) && self::only_allowed_classes( $serialized, $allowed ) ) {
				$value = @unserialize( $serialized, array( 'allowed_classes' => $allowed ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.PHP.DiscouragedPHPFunctions
				if ( false !== $value || 'b:0;' === $serialized ) {
					return $value;
				}
			}
		}
		return self::rehydrate( isset( $entry['value'] ) ? $entry['value'] : '' );
	}

	/**
	 * Files exported before the php field existed hold WC_Meta_Data objects as {id, key, value}
	 * arrays under a meta_data key; turn those back into objects.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	public static function rehydrate( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		foreach ( $value as $k => $child ) {
			if ( 'meta_data' === $k && is_array( $child ) && self::is_meta_list( $child ) ) {
				$value[ $k ] = array_map(
					function ( $meta ) {
						return new WC_Meta_Data(
							array(
								'id'    => isset( $meta['id'] ) ? $meta['id'] : null,
								'key'   => $meta['key'],
								'value' => self::rehydrate( $meta['value'] ),
							)
						);
					},
					$child
				);
			} else {
				$value[ $k ] = self::rehydrate( $child );
			}
		}
		return $value;
	}

	/**
	 * @param array $list Candidate list.
	 * @return bool
	 */
	private static function is_meta_list( array $list ) {
		if ( ! wp_is_numeric_array( $list ) ) {
			return false;
		}
		foreach ( $list as $item ) {
			if ( ! is_array( $item ) || ! array_key_exists( 'key', $item ) || ! array_key_exists( 'value', $item ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function contains_object( $value ) {
		if ( is_object( $value ) ) {
			return true;
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $child ) {
				if ( self::contains_object( $child ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * @param string   $serialized Serialized string.
	 * @param string[] $allowed    Allowed class names.
	 * @return bool
	 */
	private static function only_allowed_classes( $serialized, array $allowed ) {
		preg_match_all( '/(?:^|[;{}])[OC]:\d+:"([^"]+)"/', $serialized, $matches );
		$allowed = array_map( 'strtolower', $allowed );
		foreach ( $matches[1] as $class ) {
			if ( ! in_array( strtolower( $class ), $allowed, true ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Detects the file format from a file name.
	 *
	 * @param string $filename File name.
	 * @return string|WP_Error 'csv' or 'json'.
	 */
	public static function detect_format( $filename ) {
		$ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
		if ( in_array( $ext, array( 'csv', 'json' ), true ) ) {
			return $ext;
		}
		return new WP_Error( 'doie_format', __( 'Unsupported file type. Please upload a .csv or .json file.', 'dokan-order-import-export' ) );
	}

	/**
	 * Iterates over the records in an import file.
	 *
	 * @param string $path   File path.
	 * @param string $format 'csv' or 'json'.
	 * @return Generator|WP_Error Yields normalised records.
	 */
	public static function read_records( $path, $format ) {
		if ( 'json' === $format ) {
			$data = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( ! is_array( $data ) ) {
				return new WP_Error( 'doie_json', __( 'The JSON file could not be parsed.', 'dokan-order-import-export' ) );
			}
			$orders = isset( $data['orders'] ) && is_array( $data['orders'] ) ? $data['orders'] : $data;
			if ( ! wp_is_numeric_array( $orders ) ) {
				return new WP_Error( 'doie_json', __( 'The JSON file does not contain a list of orders.', 'dokan-order-import-export' ) );
			}
			return self::json_generator( $orders );
		}

		$handle = fopen( $path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $handle ) {
			return new WP_Error( 'doie_csv', __( 'The CSV file could not be opened.', 'dokan-order-import-export' ) );
		}
		$header = fgetcsv( $handle, 0, ',', '"', '' );
		if ( ! is_array( $header ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return new WP_Error( 'doie_csv', __( 'The CSV file is empty.', 'dokan-order-import-export' ) );
		}
		$header[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $header[0] );
		$header    = array_map(
			function ( $column ) {
				return sanitize_key( trim( (string) $column ) );
			},
			$header
		);

		if ( ! array_intersect( array( 'order_id', 'line_items', 'status', 'total' ), $header ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return new WP_Error( 'doie_csv', __( 'The CSV header was not recognised. Please use a file produced by the export tool.', 'dokan-order-import-export' ) );
		}

		return self::csv_generator( $handle, $header );
	}

	/**
	 * @param array $orders Decoded JSON orders.
	 * @return Generator
	 */
	private static function json_generator( array $orders ) {
		foreach ( $orders as $index => $order ) {
			$record          = is_array( $order ) ? $order : array();
			$record['_line'] = $index + 1;
			yield $record;
		}
	}

	/**
	 * @param resource $handle Open CSV handle positioned after the header.
	 * @param string[] $header Column names.
	 * @return Generator
	 */
	private static function csv_generator( $handle, array $header ) {
		$line = 1;
		try {
			while ( false !== ( $row = fgetcsv( $handle, 0, ',', '"', '' ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
				++$line;
				if ( array( null ) === $row || '' === implode( '', $row ) ) {
					continue;
				}
				$record = array( '_line' => $line );
				foreach ( $header as $i => $column ) {
					if ( '' === $column ) {
						continue;
					}
					$value = isset( $row[ $i ] ) ? (string) $row[ $i ] : '';
					if ( in_array( $column, self::JSON_COLUMNS, true ) ) {
						if ( '' === trim( $value ) ) {
							$record[ $column ] = array();
							continue;
						}
						$decoded = json_decode( $value, true );
						if ( ! is_array( $decoded ) ) {
							/* translators: %s: column name */
							$record['_error'] = sprintf( __( 'Column "%s" does not contain valid JSON.', 'dokan-order-import-export' ), $column );
							$decoded          = array();
						}
						$record[ $column ] = $decoded;
					} else {
						$record[ $column ] = self::unescape_cell( $value );
					}
				}
				yield $record;
			}
		} finally {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
	}
}
