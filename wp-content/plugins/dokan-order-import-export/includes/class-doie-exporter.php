<?php
/**
 * Order exporter. Streams orders page by page so large stores can be exported without
 * holding every order in memory.
 */

defined( 'ABSPATH' ) || exit;

class DOIE_Exporter {

	const PAGE_SIZE = 100;

	/**
	 * Order meta that is exported through dedicated columns or must not travel between sites.
	 */
	const SKIP_META = array( '_dokan_vendor_id', 'has_sub_order', '_edit_lock', '_edit_last', '_doie_source_order_id', '_doie_source_order_number', '_doie_import_job' );

	/**
	 * @var array
	 */
	private $args;

	/**
	 * @param array $args {
	 *     @type string   $date_from YYYY-MM-DD (inclusive).
	 *     @type string   $date_to   YYYY-MM-DD (inclusive).
	 *     @type string[] $statuses  Statuses without the wc- prefix. Empty = all.
	 *     @type int      $vendor_id Dokan vendor ID. 0 = all.
	 *     @type string   $scope     all | parent (top-level orders only) | vendor (orders without sub-orders).
	 *     @type int[]    $include   Explicit order IDs (overrides every other filter).
	 * }
	 */
	public function __construct( array $args = array() ) {
		$this->args = wp_parse_args(
			$args,
			array(
				'date_from' => '',
				'date_to'   => '',
				'statuses'  => array(),
				'vendor_id' => 0,
				'scope'     => 'all',
				'include'   => array(),
			)
		);
	}

	/**
	 * Builds the wc_get_orders() arguments for one page.
	 *
	 * @param int $page Page number (1-based).
	 * @return array
	 */
	public function query_args( $page ) {
		$args = array(
			'type'     => 'shop_order',
			'limit'    => self::PAGE_SIZE,
			'page'     => $page,
			'orderby'  => 'ID',
			'order'    => 'ASC',
			'return'   => 'ids',
			'paginate' => false,
		);

		$statuses = array_filter( array_map( 'sanitize_key', (array) $this->args['statuses'] ) );
		$args['status'] = $statuses ? array_map(
			function ( $status ) {
				return 'wc-' . preg_replace( '/^wc-/', '', $status );
			},
			$statuses
		) : array_keys( wc_get_order_statuses() );

		$from = $this->valid_date( $this->args['date_from'] );
		$to   = $this->valid_date( $this->args['date_to'] );
		// Plain Y-m-d values are interpreted by WooCommerce as whole days in the site timezone.
		if ( $from && $to ) {
			$args['date_created'] = $from . '...' . $to;
		} elseif ( $from ) {
			$args['date_created'] = '>=' . $from;
		} elseif ( $to ) {
			$args['date_created'] = '<=' . $to;
		}

		if ( 'parent' === $this->args['scope'] ) {
			$args['parent'] = 0;
		}

		return apply_filters( 'doie_export_query_args', $args, $this->args );
	}

	/**
	 * Meta conditions for the vendor / scope filters.
	 *
	 * @return array
	 */
	public function meta_query() {
		$meta_query = array();
		if ( 'vendor' === $this->args['scope'] ) {
			$meta_query[] = array(
				'key'     => 'has_sub_order',
				'compare' => 'NOT EXISTS',
			);
		}
		if ( absint( $this->args['vendor_id'] ) ) {
			$meta_query[] = array(
				'key'   => '_dokan_vendor_id',
				'value' => absint( $this->args['vendor_id'] ),
			);
		}
		return $meta_query ? array_merge( array( 'relation' => 'AND' ), $meta_query ) : array();
	}

	/**
	 * Calls $callback with every matching order.
	 *
	 * @param callable $callback Receives a WC_Order.
	 * @return int Number of exported orders.
	 */
	public function each_order( callable $callback ) {
		$count = 0;

		if ( ! empty( $this->args['include'] ) ) {
			foreach ( array_map( 'absint', (array) $this->args['include'] ) as $order_id ) {
				$order = wc_get_order( $order_id );
				if ( $order instanceof WC_Order && 'shop_order' === $order->get_type() ) {
					$callback( $order );
					++$count;
				}
			}
			return $count;
		}

		$page = 1;
		do {
			$ids = DOIE_Query::get_orders( $this->query_args( $page ), $this->meta_query() );
			foreach ( $ids as $order_id ) {
				$order = wc_get_order( $order_id );
				if ( $order instanceof WC_Order ) {
					$callback( $order );
					++$count;
				}
			}
			++$page;
			if ( function_exists( 'wp_cache_flush_runtime' ) ) {
				wp_cache_flush_runtime();
			}
		} while ( count( $ids ) === self::PAGE_SIZE );

		return $count;
	}

	/**
	 * Writes a CSV export to a stream.
	 *
	 * @param resource $handle Writable stream.
	 * @return int Number of exported orders.
	 */
	public function write_csv( $handle ) {
		$columns = DOIE_Format::columns();
		fwrite( $handle, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- UTF-8 BOM so Excel reads accents correctly.
		fputcsv( $handle, $columns, ',', '"', '' );

		return $this->each_order(
			function ( WC_Order $order ) use ( $handle, $columns ) {
				fputcsv( $handle, DOIE_Format::record_to_row( $this->order_to_record( $order ), $columns ), ',', '"', '' );
			}
		);
	}

	/**
	 * Writes a JSON export to a stream.
	 *
	 * @param resource $handle Writable stream.
	 * @return int Number of exported orders.
	 */
	public function write_json( $handle ) {
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		$header = array(
			'generator'   => 'dokan-order-import-export',
			'version'     => DOIE_VERSION,
			'site_url'    => home_url(),
			'exported_at' => gmdate( DATE_ATOM ),
		);
		fwrite( $handle, substr( wp_json_encode( $header ), 0, -1 ) . ',"orders":[' );
		$first = true;
		$count = $this->each_order(
			function ( WC_Order $order ) use ( $handle, &$first ) {
				fwrite( $handle, ( $first ? '' : ',' ) . "\n" . wp_json_encode( $this->order_to_record( $order ) ) );
				$first = false;
			}
		);
		fwrite( $handle, "\n]}" );
		// phpcs:enable
		return $count;
	}

	/**
	 * Converts an order into an export record.
	 *
	 * @param WC_Order $order Order.
	 * @return array
	 */
	public function order_to_record( WC_Order $order ) {
		$customer_email = $order->get_billing_email();
		if ( $order->get_customer_id() ) {
			$user = get_userdata( $order->get_customer_id() );
			if ( $user ) {
				$customer_email = $user->user_email;
			}
		}

		$record = array(
			'order_id'             => $order->get_id(),
			'order_number'         => $order->get_order_number(),
			'parent_id'            => $order->get_parent_id(),
			'status'               => $order->get_status(),
			'currency'             => $order->get_currency(),
			'prices_include_tax'   => wc_bool_to_string( $order->get_prices_include_tax() ),
			'date_created'         => self::format_date( $order->get_date_created() ),
			'date_modified'        => self::format_date( $order->get_date_modified() ),
			'date_paid'            => self::format_date( $order->get_date_paid() ),
			'date_completed'       => self::format_date( $order->get_date_completed() ),
			'customer_id'          => $order->get_customer_id(),
			'customer_email'       => $customer_email,
			'customer_note'        => $order->get_customer_note(),
		);

		foreach ( DOIE_Format::ADDRESS_FIELDS as $field ) {
			$getter                       = 'get_billing_' . $field;
			$record[ 'billing_' . $field ] = $order->$getter();
		}
		foreach ( DOIE_Format::ADDRESS_FIELDS as $field ) {
			$getter = 'get_shipping_' . $field;
			if ( 'email' !== $field ) {
				$record[ 'shipping_' . $field ] = is_callable( array( $order, $getter ) ) ? $order->$getter() : '';
			}
		}

		$vendor_id = DOIE_Dokan::get_vendor_id( $order );
		$vendor    = $vendor_id ? get_userdata( $vendor_id ) : false;
		$earnings  = DOIE_Dokan::get_earnings( $order->get_id() );

		$record += array(
			'payment_method'         => $order->get_payment_method(),
			'payment_method_title'   => $order->get_payment_method_title(),
			'transaction_id'         => $order->get_transaction_id(),
			'customer_ip_address'    => $order->get_customer_ip_address(),
			'customer_user_agent'    => $order->get_customer_user_agent(),
			'created_via'            => $order->get_created_via(),
			'discount_total'         => $order->get_discount_total(),
			'discount_tax'           => $order->get_discount_tax(),
			'shipping_total'         => $order->get_shipping_total(),
			'shipping_tax'           => $order->get_shipping_tax(),
			'cart_tax'               => $order->get_cart_tax(),
			'total_tax'              => $order->get_total_tax(),
			'total'                  => $order->get_total(),
			'total_refunded'         => $order->get_total_refunded(),
			'dokan_vendor_id'        => $vendor_id ? $vendor_id : '',
			'dokan_vendor_email'     => $vendor ? $vendor->user_email : '',
			'dokan_store_name'       => DOIE_Dokan::get_store_name( $vendor_id ),
			'dokan_has_sub_order'    => DOIE_Dokan::has_sub_order( $order ) ? 'yes' : 'no',
			'dokan_order_total'      => $earnings['order_total'],
			'dokan_vendor_earning'   => $earnings['vendor_earning'],
			'dokan_admin_commission' => $earnings['admin_commission'],
			'line_items'             => $this->line_items( $order ),
			'shipping_lines'         => $this->shipping_lines( $order ),
			'fee_lines'              => $this->fee_lines( $order ),
			'coupon_lines'           => $this->coupon_lines( $order ),
			'tax_lines'              => $this->tax_lines( $order ),
			'refunds'                => $this->refunds( $order ),
			'order_notes'            => $this->notes( $order ),
			'meta_data'              => $this->meta( $order->get_meta_data(), self::SKIP_META ),
		);

		return apply_filters( 'doie_export_record', $record, $order );
	}

	/**
	 * @param WC_DateTime|null $date Date.
	 * @return string ISO 8601 with offset, or ''.
	 */
	public static function format_date( $date ) {
		return $date ? $date->format( DATE_ATOM ) : '';
	}

	/**
	 * @param WC_Meta_Data[] $meta_data Meta objects.
	 * @param string[]       $skip      Keys to leave out.
	 * @return array List of {key, value}.
	 */
	private function meta( array $meta_data, array $skip = array() ) {
		$out = array();
		foreach ( $meta_data as $meta ) {
			$data = $meta->get_data();
			if ( in_array( $data['key'], $skip, true ) ) {
				continue;
			}
			$out[] = DOIE_Format::export_meta( $data['key'], $data['value'] );
		}
		return $out;
	}

	/**
	 * @param WC_Order $order Order.
	 * @return array
	 */
	private function line_items( WC_Order $order ) {
		$items = array();
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			/** @var WC_Order_Item_Product $item */
			$product = $item->get_product();
			$items[] = array(
				'name'         => $item->get_name(),
				'product_id'   => $item->get_product_id(),
				'variation_id' => $item->get_variation_id(),
				'sku'          => $product ? $product->get_sku() : '',
				'quantity'     => $item->get_quantity(),
				'tax_class'    => $item->get_tax_class(),
				'subtotal'     => $item->get_subtotal(),
				'subtotal_tax' => $item->get_subtotal_tax(),
				'total'        => $item->get_total(),
				'total_tax'    => $item->get_total_tax(),
				'taxes'        => $item->get_taxes(),
				// _reduced_stock is dropped: the importer does not reduce stock by default, so
				// carrying it over would make a later cancellation restock items it never took.
				'meta'         => $this->meta( $item->get_meta_data(), array( '_reduced_stock' ) ),
			);
		}
		return $items;
	}

	/**
	 * @param WC_Order $order Order.
	 * @return array
	 */
	private function shipping_lines( WC_Order $order ) {
		$items = array();
		foreach ( $order->get_items( 'shipping' ) as $item ) {
			/** @var WC_Order_Item_Shipping $item */
			$items[] = array(
				'method_title' => $item->get_method_title(),
				'method_id'    => $item->get_method_id(),
				'instance_id'  => $item->get_instance_id(),
				'total'        => $item->get_total(),
				'total_tax'    => $item->get_total_tax(),
				'taxes'        => $item->get_taxes(),
				'meta'         => $this->meta( $item->get_meta_data() ), // Includes Dokan's seller_id on vendor shipping lines.
			);
		}
		return $items;
	}

	/**
	 * @param WC_Order $order Order.
	 * @return array
	 */
	private function fee_lines( WC_Order $order ) {
		$items = array();
		foreach ( $order->get_items( 'fee' ) as $item ) {
			/** @var WC_Order_Item_Fee $item */
			$items[] = array(
				'name'       => $item->get_name(),
				'tax_class'  => $item->get_tax_class(),
				'tax_status' => $item->get_tax_status(),
				'amount'     => $item->get_amount(),
				'total'      => $item->get_total(),
				'total_tax'  => $item->get_total_tax(),
				'taxes'      => $item->get_taxes(),
				'meta'       => $this->meta( $item->get_meta_data() ),
			);
		}
		return $items;
	}

	/**
	 * @param WC_Order $order Order.
	 * @return array
	 */
	private function coupon_lines( WC_Order $order ) {
		$items = array();
		foreach ( $order->get_items( 'coupon' ) as $item ) {
			/** @var WC_Order_Item_Coupon $item */
			$items[] = array(
				'code'         => $item->get_code(),
				'discount'     => $item->get_discount(),
				'discount_tax' => $item->get_discount_tax(),
				'meta'         => $this->meta( $item->get_meta_data() ),
			);
		}
		return $items;
	}

	/**
	 * @param WC_Order $order Order.
	 * @return array
	 */
	private function tax_lines( WC_Order $order ) {
		$items = array();
		foreach ( $order->get_items( 'tax' ) as $item ) {
			/** @var WC_Order_Item_Tax $item */
			$items[] = array(
				'rate_id'            => $item->get_rate_id(),
				'rate_code'          => $item->get_rate_code(),
				'label'              => $item->get_label(),
				'compound'           => $item->get_compound(),
				'tax_total'          => $item->get_tax_total(),
				'shipping_tax_total' => $item->get_shipping_tax_total(),
				'rate_percent'       => is_callable( array( $item, 'get_rate_percent' ) ) ? $item->get_rate_percent() : null,
				'meta'               => $this->meta( $item->get_meta_data() ),
			);
		}
		return $items;
	}

	/**
	 * @param WC_Order $order Order.
	 * @return array
	 */
	private function refunds( WC_Order $order ) {
		$refunds = array();
		foreach ( $order->get_refunds() as $refund ) {
			$refunds[] = array(
				'id'               => $refund->get_id(),
				'amount'           => $refund->get_amount(),
				'reason'           => $refund->get_reason(),
				'date_created'     => self::format_date( $refund->get_date_created() ),
				'refunded_payment' => $refund->get_refunded_payment(),
			);
		}
		return $refunds;
	}

	/**
	 * @param WC_Order $order Order.
	 * @return array
	 */
	private function notes( WC_Order $order ) {
		$notes = array();
		$list  = wc_get_order_notes(
			array(
				'order_id' => $order->get_id(),
				'orderby'  => 'date_created',
				'order'    => 'ASC',
			)
		);
		foreach ( $list as $note ) {
			$notes[] = array(
				'content'       => $note->content,
				'customer_note' => (bool) $note->customer_note,
				'added_by'      => $note->added_by,
				'date_created'  => self::format_date( $note->date_created ),
			);
		}
		return $notes;
	}

	/**
	 * @param string $date Date string.
	 * @return string Y-m-d or ''.
	 */
	private function valid_date( $date ) {
		$date = (string) $date;
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? $date : '';
	}
}
