<?php
/**
 * Order importer. Turns one export record into a WooCommerce order and restores its Dokan
 * vendor, parent/sub-order link and earnings.
 */

defined( 'ABSPATH' ) || exit;

class DOIE_Importer {

	const SOURCE_META = '_doie_source_order_id';
	const JOB_META    = '_doie_import_job';

	/**
	 * Generic meta keys that the importer sets itself.
	 */
	const PROTECTED_META = array( '_dokan_vendor_id', 'has_sub_order', '_order_stock_reduced', '_recorded_sales', '_recorded_coupon_usage_counts', '_edit_lock', '_edit_last', self::SOURCE_META, self::JOB_META, '_doie_source_order_number' );

	/**
	 * @var array
	 */
	private $options;

	/**
	 * @var string
	 */
	private $job_id;

	/**
	 * @var callable[]
	 */
	private $email_filters = array();

	/**
	 * @param array  $options {
	 *     @type string $existing          skip | update | create — what to do when the order was imported before.
	 *     @type bool   $match_by_id       Treat an existing order with the same ID as the same order (same-site restore).
	 *     @type bool   $send_emails       Let WooCommerce / Dokan send order emails.
	 *     @type bool   $reduce_stock      Reduce product stock for imported paid orders.
	 *     @type bool   $preserve_earnings Keep the exported Dokan vendor earning instead of recalculating it.
	 *     @type bool   $recalculate       Recalculate totals instead of using the exported ones.
	 * }
	 * @param string $job_id Import job ID, used to link sub-orders to parents imported in the same run.
	 */
	public function __construct( array $options = array(), $job_id = '' ) {
		$this->options = self::normalize_options( $options );
		$this->job_id  = (string) $job_id;
	}

	/**
	 * @param array $options Raw options.
	 * @return array
	 */
	public static function normalize_options( array $options ) {
		$options = wp_parse_args(
			$options,
			array(
				'existing'          => 'skip',
				'match_by_id'       => false,
				'send_emails'       => false,
				'reduce_stock'      => false,
				'preserve_earnings' => true,
				'recalculate'       => false,
			)
		);
		if ( ! in_array( $options['existing'], array( 'skip', 'update', 'create' ), true ) ) {
			$options['existing'] = 'skip';
		}
		foreach ( array( 'match_by_id', 'send_emails', 'reduce_stock', 'preserve_earnings', 'recalculate' ) as $key ) {
			$options[ $key ] = (bool) $options[ $key ];
		}
		return $options;
	}

	/**
	 * Call before importing a batch.
	 */
	public function begin() {
		if ( ! defined( 'DOIE_IMPORTING' ) ) {
			define( 'DOIE_IMPORTING', true );
		}
		if ( function_exists( 'wc_set_time_limit' ) ) {
			wc_set_time_limit( 0 );
		}
		if ( ! $this->options['send_emails'] ) {
			// The refund email switches its ID to customer_partially_refunded_order at send time.
			$ids = array( 'customer_partially_refunded_order' );
			foreach ( WC()->mailer()->get_emails() as $email ) {
				$ids[] = $email->id;
			}
			foreach ( array_unique( $ids ) as $id ) {
				$hook = 'woocommerce_email_enabled_' . $id;
				add_filter( $hook, '__return_false', 999 );
				$this->email_filters[] = $hook;
			}
			// Catch-all for emails registered late or with dynamic IDs.
			add_filter( 'woocommerce_mail_callback', array( __CLASS__, 'mail_noop_callback' ), 999 );
		}
		do_action( 'doie_before_import', $this->options );
	}

	/**
	 * Call after importing a batch.
	 */
	public function end() {
		foreach ( $this->email_filters as $hook ) {
			remove_filter( $hook, '__return_false', 999 );
		}
		$this->email_filters = array();
		remove_filter( 'woocommerce_mail_callback', array( __CLASS__, 'mail_noop_callback' ), 999 );
		do_action( 'doie_after_import', $this->options );
	}

	/**
	 * @return callable Mail callback that sends nothing.
	 */
	public static function mail_noop_callback() {
		return '__return_true';
	}

	/**
	 * Imports one record.
	 *
	 * @param array $record Record from DOIE_Format::read_records().
	 * @return array{result:string,order_id:int,warnings:string[]}
	 * @throws Exception When the record cannot be imported.
	 */
	public function import_record( array $record ) {
		if ( ! empty( $record['_error'] ) ) {
			throw new Exception( $record['_error'] );
		}

		$record    = apply_filters( 'doie_import_record', $record, $this->options );
		$warnings  = array();
		$source_id = isset( $record['order_id'] ) ? absint( $record['order_id'] ) : 0;
		$existing  = null;

		if ( $source_id && 'create' !== $this->options['existing'] ) {
			$existing = $this->find_existing( $source_id );
			if ( $existing && 'skip' === $this->options['existing'] ) {
				return array(
					'result'   => 'skipped',
					'order_id' => $existing->get_id(),
					'warnings' => array(),
				);
			}
		}

		$is_new = ! $existing;
		$order  = $existing ? $existing : new WC_Order();

		if ( ! $is_new ) {
			$order->remove_order_items();
		}

		$status = isset( $record['status'] ) ? preg_replace( '/^wc-/', '', sanitize_key( $record['status'] ) ) : 'pending';
		if ( ! array_key_exists( 'wc-' . $status, wc_get_order_statuses() ) ) {
			/* translators: %s: status */
			$warnings[] = sprintf( __( 'Unknown status "%s", imported as pending.', 'dokan-order-import-export' ), $status );
			$status     = 'pending';
		}

		$this->set_core_props( $order, $record, $warnings );
		$this->add_line_items( $order, $record, $warnings );
		$this->add_shipping_lines( $order, $record );
		$this->add_fee_lines( $order, $record );
		$this->add_coupon_lines( $order, $record );
		$this->add_tax_lines( $order, $record );
		$this->set_totals( $order, $record );
		$this->set_meta( $order, $record );
		$this->set_dokan_props( $order, $record, $warnings );

		if ( $source_id ) {
			$order->update_meta_data( self::SOURCE_META, $source_id );
		}
		if ( ! empty( $record['order_number'] ) ) {
			$order->update_meta_data( '_doie_source_order_number', sanitize_text_field( $record['order_number'] ) );
		}
		if ( $this->job_id ) {
			$order->update_meta_data( self::JOB_META, $this->job_id );
		}

		// In wp-admin, Dokan splits any top-level order whose status changes into per-vendor
		// sub-orders. Imported orders already carry their sub-orders (or vendor), so Dokan is
		// shown no vendors while the order is written and leaves it exactly as imported.
		add_filter( 'dokan_get_sellers_by', '__return_zero', 999 );
		try {
			$this->mark_inventory_handled( $order );
			$order->save();
			$this->mark_inventory_handled_in_store( $order );

			// Status goes on in a second save so WooCommerce runs the normal status-transition
			// hooks (Dokan listens to them) with the stock / sales flags above already in place.
			$order->set_status( $status );
			$order->save();

			if ( $is_new ) {
				$this->add_notes( $order, $record );
				$warnings = array_merge( $warnings, $this->add_refunds( $order, $record ) );
				if ( $order->get_status() !== $status ) {
					$order->set_status( $status );
					$order->save();
				}
			}
		} finally {
			remove_filter( 'dokan_get_sellers_by', '__return_zero', 999 );
		}

		if ( DOIE_Dokan::is_active() ) {
			$warnings = array_merge( $warnings, DOIE_Dokan::sync_order( $order, $record, $this->options['preserve_earnings'] ) );
		}

		do_action( 'doie_order_imported', $order, $record, $is_new );

		return array(
			'result'   => $is_new ? 'created' : 'updated',
			'order_id' => $order->get_id(),
			'warnings' => $warnings,
		);
	}

	/**
	 * Finds an order previously imported from (or identical to) a source order.
	 *
	 * @param int $source_id Source order ID.
	 * @return WC_Order|null
	 */
	public function find_existing( $source_id ) {
		$ids = DOIE_Query::get_orders(
			array(
				'type'    => 'shop_order',
				'status'  => array_keys( wc_get_order_statuses() ),
				'limit'   => 1,
				'orderby' => 'ID',
				'order'   => 'DESC',
				'return'  => 'ids',
			),
			array(
				array(
					'key'   => self::SOURCE_META,
					'value' => $source_id,
				),
			)
		);
		if ( $ids ) {
			$order = wc_get_order( $ids[0] );
			if ( $order instanceof WC_Order ) {
				return $order;
			}
		}

		if ( $this->options['match_by_id'] ) {
			$order = wc_get_order( $source_id );
			if ( $order instanceof WC_Order && 'shop_order' === $order->get_type() ) {
				return $order;
			}
		}

		return null;
	}

	/**
	 * Maps a source parent order ID to an order on this site. Orders from the current job win
	 * so that "always create new" imports link sub-orders to the parent created in this run.
	 *
	 * @param int $source_parent_id Source parent ID.
	 * @return int
	 */
	private function resolve_parent( $source_parent_id ) {
		if ( $this->job_id ) {
			$ids = DOIE_Query::get_orders(
				array(
					'type'   => 'shop_order',
					'status' => array_keys( wc_get_order_statuses() ),
					'limit'  => 1,
					'return' => 'ids',
				),
				array(
					'relation' => 'AND',
					array(
						'key'   => self::SOURCE_META,
						'value' => $source_parent_id,
					),
					array(
						'key'   => self::JOB_META,
						'value' => $this->job_id,
					),
				)
			);
			if ( $ids ) {
				return (int) $ids[0];
			}
		}
		$parent = $this->find_existing( $source_parent_id );
		return $parent ? $parent->get_id() : 0;
	}

	/**
	 * @param WC_Order $order    Order.
	 * @param array    $record   Record.
	 * @param string[] $warnings Warnings (by reference).
	 */
	private function set_core_props( WC_Order $order, array $record, array &$warnings ) {
		$parent_source = isset( $record['parent_id'] ) ? absint( $record['parent_id'] ) : 0;
		if ( $parent_source ) {
			$parent_id = $this->resolve_parent( $parent_source );
			if ( ! $parent_id ) {
				/* translators: %d: order ID */
				$warnings[] = sprintf( __( 'Parent order #%d was not found; imported without a parent.', 'dokan-order-import-export' ), $parent_source );
			}
			$order->set_parent_id( $parent_id );
		} else {
			$order->set_parent_id( 0 );
		}

		$text_props = array( 'currency', 'payment_method', 'payment_method_title', 'transaction_id', 'customer_ip_address', 'customer_user_agent', 'created_via' );
		foreach ( $text_props as $prop ) {
			if ( isset( $record[ $prop ] ) ) {
				$order->{"set_$prop"}( sanitize_text_field( $record[ $prop ] ) );
			}
		}
		if ( empty( $record['created_via'] ) ) {
			$order->set_created_via( 'doie-import' );
		}
		if ( isset( $record['customer_note'] ) ) {
			$order->set_customer_note( sanitize_textarea_field( $record['customer_note'] ) );
		}
		if ( isset( $record['prices_include_tax'] ) && '' !== $record['prices_include_tax'] ) {
			$order->set_prices_include_tax( wc_string_to_bool( $record['prices_include_tax'] ) );
		}

		foreach ( array( 'billing', 'shipping' ) as $type ) {
			foreach ( DOIE_Format::ADDRESS_FIELDS as $field ) {
				$key    = $type . '_' . $field;
				$setter = 'set_' . $key;
				if ( isset( $record[ $key ] ) && is_callable( array( $order, $setter ) ) ) {
					$order->$setter( 'billing_email' === $key ? sanitize_email( $record[ $key ] ) : sanitize_text_field( $record[ $key ] ) );
				}
			}
		}

		$order->set_customer_id( $this->resolve_customer( $record ) );

		foreach ( array( 'date_created', 'date_paid', 'date_completed' ) as $prop ) {
			if ( ! empty( $record[ $prop ] ) ) {
				try {
					$order->{"set_$prop"}( $record[ $prop ] );
				} catch ( Exception $e ) {
					/* translators: 1: field, 2: value */
					$warnings[] = sprintf( __( 'Invalid %1$s "%2$s" ignored.', 'dokan-order-import-export' ), $prop, $record[ $prop ] );
				}
			}
		}
	}

	/**
	 * Customers are matched by email; the exported user ID is only meaningful on the same site.
	 *
	 * @param array $record Record.
	 * @return int
	 */
	private function resolve_customer( array $record ) {
		$emails = array();
		if ( ! empty( $record['customer_email'] ) ) {
			$emails[] = $record['customer_email'];
		}
		if ( ! empty( $record['billing_email'] ) ) {
			$emails[] = $record['billing_email'];
		}
		foreach ( $emails as $email ) {
			$user = get_user_by( 'email', sanitize_email( $email ) );
			if ( $user ) {
				return (int) $user->ID;
			}
		}
		if ( $this->options['match_by_id'] && ! empty( $record['customer_id'] ) && get_userdata( absint( $record['customer_id'] ) ) ) {
			return absint( $record['customer_id'] );
		}
		return (int) apply_filters( 'doie_import_customer_id', 0, $record );
	}

	/**
	 * @param array $record Record.
	 * @param string $key   JSON column.
	 * @return array
	 */
	private function lines( array $record, $key ) {
		return isset( $record[ $key ] ) && is_array( $record[ $key ] ) ? array_filter( $record[ $key ], 'is_array' ) : array();
	}

	/**
	 * @param WC_Order_Item $item Item.
	 * @param array         $line Line data.
	 */
	private function add_item_meta( WC_Order_Item $item, array $line ) {
		if ( empty( $line['meta'] ) || ! is_array( $line['meta'] ) ) {
			return;
		}
		foreach ( $line['meta'] as $meta ) {
			if ( is_array( $meta ) && isset( $meta['key'] ) && '' !== $meta['key'] ) {
				$item->add_meta_data( (string) $meta['key'], DOIE_Format::import_meta_value( $meta ), false );
			}
		}
	}

	/**
	 * @param array $line Line data.
	 * @return array
	 */
	private function taxes( array $line ) {
		$taxes = isset( $line['taxes'] ) && is_array( $line['taxes'] ) ? $line['taxes'] : array();
		return array(
			'total'    => isset( $taxes['total'] ) && is_array( $taxes['total'] ) ? $taxes['total'] : array(),
			'subtotal' => isset( $taxes['subtotal'] ) && is_array( $taxes['subtotal'] ) ? $taxes['subtotal'] : array(),
		);
	}

	/**
	 * Finds the product for a line item: SKU first (stable between sites), then IDs.
	 *
	 * @param array $line Line data.
	 * @return WC_Product|null
	 */
	private function resolve_product( array $line ) {
		if ( ! empty( $line['sku'] ) ) {
			$id = wc_get_product_id_by_sku( $line['sku'] );
			if ( $id ) {
				$product = wc_get_product( $id );
				if ( $product ) {
					return $product;
				}
			}
		}
		foreach ( array( 'variation_id', 'product_id' ) as $key ) {
			if ( ! empty( $line[ $key ] ) ) {
				$product = wc_get_product( absint( $line[ $key ] ) );
				$trusted = $this->options['match_by_id'] || empty( $line['name'] );
				if ( $product && ( $trusted || $this->same_product_name( $product, $line['name'] ) ) ) {
					return $product;
				}
			}
		}

		// Last resort for products without SKU: a single product with exactly this name.
		if ( ! empty( $line['name'] ) ) {
			$ids = get_posts(
				array(
					'post_type'      => array( 'product', 'product_variation' ),
					'post_status'    => 'any',
					'title'          => $line['name'],
					'posts_per_page' => 2,
					'fields'         => 'ids',
				)
			);
			if ( 1 === count( $ids ) ) {
				return wc_get_product( $ids[0] );
			}
		}
		return null;
	}

	/**
	 * Guards ID matching between sites: product #123 here may be a different product.
	 *
	 * @param WC_Product $product Product found by ID.
	 * @param string     $name    Line item name from the export.
	 * @return bool
	 */
	private function same_product_name( WC_Product $product, $name ) {
		$name = wp_strip_all_tags( (string) $name );
		return 0 === strcasecmp( $product->get_name(), $name ) || 0 === stripos( $name, $product->get_title() );
	}

	/**
	 * @param WC_Order $order    Order.
	 * @param array    $record   Record.
	 * @param string[] $warnings Warnings (by reference).
	 */
	private function add_line_items( WC_Order $order, array $record, array &$warnings ) {
		foreach ( $this->lines( $record, 'line_items' ) as $line ) {
			$item    = new WC_Order_Item_Product();
			$product = $this->resolve_product( $line );

			$product_id   = 0;
			$variation_id = 0;
			if ( $product ) {
				$product_id   = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
				$variation_id = $product->is_type( 'variation' ) ? $product->get_id() : 0;
			} else {
				/* translators: 1: item name, 2: sku */
				$warnings[] = sprintf( __( 'Product "%1$s" (SKU: %2$s) not found; the line was imported without a product link.', 'dokan-order-import-export' ), isset( $line['name'] ) ? $line['name'] : '', isset( $line['sku'] ) ? $line['sku'] : '' );
			}

			$item->set_props(
				array(
					'name'         => isset( $line['name'] ) ? sanitize_text_field( $line['name'] ) : ( $product ? $product->get_name() : '' ),
					'product_id'   => $product_id,
					'variation_id' => $variation_id,
					'quantity'     => isset( $line['quantity'] ) ? wc_stock_amount( $line['quantity'] ) : 1,
					'tax_class'    => isset( $line['tax_class'] ) ? sanitize_title( $line['tax_class'] ) : '',
					'subtotal'     => isset( $line['subtotal'] ) ? wc_format_decimal( $line['subtotal'] ) : 0,
					'subtotal_tax' => isset( $line['subtotal_tax'] ) ? wc_format_decimal( $line['subtotal_tax'] ) : 0,
					'total'        => isset( $line['total'] ) ? wc_format_decimal( $line['total'] ) : 0,
					'total_tax'    => isset( $line['total_tax'] ) ? wc_format_decimal( $line['total_tax'] ) : 0,
				)
			);
			$item->set_taxes( $this->taxes( $line ) );
			$this->add_item_meta( $item, $line );
			$order->add_item( $item );
		}
	}

	/**
	 * @param WC_Order $order  Order.
	 * @param array    $record Record.
	 */
	private function add_shipping_lines( WC_Order $order, array $record ) {
		foreach ( $this->lines( $record, 'shipping_lines' ) as $line ) {
			$item = new WC_Order_Item_Shipping();
			$item->set_props(
				array(
					'method_title' => isset( $line['method_title'] ) ? sanitize_text_field( $line['method_title'] ) : '',
					'method_id'    => isset( $line['method_id'] ) ? sanitize_text_field( $line['method_id'] ) : '',
					'instance_id'  => isset( $line['instance_id'] ) ? sanitize_text_field( $line['instance_id'] ) : '',
					'total'        => isset( $line['total'] ) ? wc_format_decimal( $line['total'] ) : 0,
				)
			);
			$item->set_taxes( $this->taxes( $line ) );
			$this->add_item_meta( $item, $line );
			$order->add_item( $item );
		}
	}

	/**
	 * @param WC_Order $order  Order.
	 * @param array    $record Record.
	 */
	private function add_fee_lines( WC_Order $order, array $record ) {
		foreach ( $this->lines( $record, 'fee_lines' ) as $line ) {
			$item = new WC_Order_Item_Fee();
			$item->set_props(
				array(
					'name'       => isset( $line['name'] ) ? sanitize_text_field( $line['name'] ) : '',
					'tax_class'  => isset( $line['tax_class'] ) ? sanitize_title( $line['tax_class'] ) : '',
					'tax_status' => isset( $line['tax_status'] ) ? sanitize_key( $line['tax_status'] ) : 'taxable',
					'amount'     => isset( $line['amount'] ) ? wc_format_decimal( $line['amount'] ) : 0,
					'total'      => isset( $line['total'] ) ? wc_format_decimal( $line['total'] ) : 0,
					'total_tax'  => isset( $line['total_tax'] ) ? wc_format_decimal( $line['total_tax'] ) : 0,
				)
			);
			$item->set_taxes( $this->taxes( $line ) );
			$this->add_item_meta( $item, $line );
			$order->add_item( $item );
		}
	}

	/**
	 * @param WC_Order $order  Order.
	 * @param array    $record Record.
	 */
	private function add_coupon_lines( WC_Order $order, array $record ) {
		foreach ( $this->lines( $record, 'coupon_lines' ) as $line ) {
			$item = new WC_Order_Item_Coupon();
			$item->set_props(
				array(
					'code'         => isset( $line['code'] ) ? wc_format_coupon_code( $line['code'] ) : '',
					'discount'     => isset( $line['discount'] ) ? wc_format_decimal( $line['discount'] ) : 0,
					'discount_tax' => isset( $line['discount_tax'] ) ? wc_format_decimal( $line['discount_tax'] ) : 0,
				)
			);
			$this->add_item_meta( $item, $line );
			$order->add_item( $item );
		}
	}

	/**
	 * @param WC_Order $order  Order.
	 * @param array    $record Record.
	 */
	private function add_tax_lines( WC_Order $order, array $record ) {
		foreach ( $this->lines( $record, 'tax_lines' ) as $line ) {
			$item  = new WC_Order_Item_Tax();
			$props = array(
				'rate_id'            => isset( $line['rate_id'] ) ? absint( $line['rate_id'] ) : 0,
				'rate_code'          => isset( $line['rate_code'] ) ? sanitize_text_field( $line['rate_code'] ) : '',
				'label'              => isset( $line['label'] ) ? sanitize_text_field( $line['label'] ) : '',
				'compound'           => ! empty( $line['compound'] ),
				'tax_total'          => isset( $line['tax_total'] ) ? wc_format_decimal( $line['tax_total'] ) : 0,
				'shipping_tax_total' => isset( $line['shipping_tax_total'] ) ? wc_format_decimal( $line['shipping_tax_total'] ) : 0,
			);
			if ( isset( $line['rate_percent'] ) && is_callable( array( $item, 'set_rate_percent' ) ) ) {
				$props['rate_percent'] = (float) $line['rate_percent'];
			}
			$item->set_props( $props );
			$this->add_item_meta( $item, $line );
			$order->add_item( $item );
		}
	}

	/**
	 * @param WC_Order $order  Order.
	 * @param array    $record Record.
	 */
	private function set_totals( WC_Order $order, array $record ) {
		if ( $this->options['recalculate'] ) {
			$order->calculate_totals( false );
			return;
		}
		$props = array( 'discount_total', 'discount_tax', 'shipping_total', 'shipping_tax', 'cart_tax', 'total' );
		foreach ( $props as $prop ) {
			if ( isset( $record[ $prop ] ) && '' !== $record[ $prop ] ) {
				$order->{"set_$prop"}( wc_format_decimal( $record[ $prop ] ) );
			}
		}
	}

	/**
	 * @param WC_Order $order  Order.
	 * @param array    $record Record.
	 */
	private function set_meta( WC_Order $order, array $record ) {
		foreach ( $this->lines( $record, 'meta_data' ) as $meta ) {
			if ( ! isset( $meta['key'] ) || '' === $meta['key'] || in_array( $meta['key'], self::PROTECTED_META, true ) ) {
				continue;
			}
			$order->update_meta_data( (string) $meta['key'], DOIE_Format::import_meta_value( $meta ) );
		}
	}

	/**
	 * @param WC_Order $order    Order.
	 * @param array    $record   Record.
	 * @param string[] $warnings Warnings (by reference).
	 */
	private function set_dokan_props( WC_Order $order, array $record, array &$warnings ) {
		if ( ! empty( $record['dokan_has_sub_order'] ) && wc_string_to_bool( $record['dokan_has_sub_order'] ) ) {
			$order->update_meta_data( 'has_sub_order', true );
		} else {
			$order->delete_meta_data( 'has_sub_order' );
		}

		$has_vendor = ! empty( $record['dokan_vendor_id'] ) || ! empty( $record['dokan_vendor_email'] ) || ! empty( $record['dokan_store_name'] );
		if ( ! $has_vendor ) {
			$order->delete_meta_data( '_dokan_vendor_id' );
			return;
		}

		$vendor_id = DOIE_Dokan::resolve_vendor(
			isset( $record['dokan_vendor_id'] ) ? $record['dokan_vendor_id'] : 0,
			isset( $record['dokan_vendor_email'] ) ? $record['dokan_vendor_email'] : '',
			isset( $record['dokan_store_name'] ) ? (string) $record['dokan_store_name'] : ''
		);
		$vendor_id = (int) apply_filters( 'doie_import_vendor_id', $vendor_id, $record );

		if ( $vendor_id ) {
			$order->update_meta_data( '_dokan_vendor_id', $vendor_id );
		} else {
			$order->delete_meta_data( '_dokan_vendor_id' );
			/* translators: 1: store name, 2: email */
			$warnings[] = sprintf( __( 'Vendor "%1$s" (%2$s) not found on this site; order imported without a vendor.', 'dokan-order-import-export' ), isset( $record['dokan_store_name'] ) ? $record['dokan_store_name'] : '', isset( $record['dokan_vendor_email'] ) ? $record['dokan_vendor_email'] : '' );
		}
	}

	/**
	 * Flags the order so status transitions do not reduce stock or inflate sales / coupon counts.
	 *
	 * @param WC_Order $order Order.
	 */
	private function mark_inventory_handled( WC_Order $order ) {
		if ( $this->options['reduce_stock'] ) {
			return;
		}
		foreach ( array( 'set_order_stock_reduced', 'set_recorded_sales', 'set_recorded_coupon_usage_counts' ) as $setter ) {
			if ( is_callable( array( $order, $setter ) ) ) {
				$order->$setter( true );
			}
		}
	}

	/**
	 * Same as mark_inventory_handled() for WooCommerce versions without the order setters.
	 *
	 * @param WC_Order $order Saved order.
	 */
	private function mark_inventory_handled_in_store( WC_Order $order ) {
		if ( $this->options['reduce_stock'] || is_callable( array( $order, 'set_order_stock_reduced' ) ) ) {
			return;
		}
		$store = $order->get_data_store();
		foreach ( array( 'set_stock_reduced', 'set_recorded_sales', 'set_recorded_coupon_usage_counts' ) as $method ) {
			if ( is_callable( array( $store, $method ) ) ) {
				$store->$method( $order->get_id(), true );
			}
		}
	}

	/**
	 * @param WC_Order $order  Order.
	 * @param array    $record Record.
	 */
	private function add_notes( WC_Order $order, array $record ) {
		foreach ( $this->lines( $record, 'order_notes' ) as $note ) {
			if ( empty( $note['content'] ) ) {
				continue;
			}
			$note_id = $order->add_order_note( wp_kses_post( $note['content'] ), ! empty( $note['customer_note'] ) ? 1 : 0, false );
			if ( ! $note_id ) {
				continue;
			}
			$update = array( 'comment_ID' => $note_id );
			if ( ! empty( $note['added_by'] ) && 'system' !== $note['added_by'] ) {
				$update['comment_author'] = sanitize_text_field( $note['added_by'] );
			}
			if ( ! empty( $note['date_created'] ) ) {
				$date = wc_string_to_datetime( $note['date_created'] );
				if ( $date ) {
					$update['comment_date']     = $date->date( 'Y-m-d H:i:s' );
					$update['comment_date_gmt'] = gmdate( 'Y-m-d H:i:s', $date->getTimestamp() );
				}
			}
			if ( count( $update ) > 1 ) {
				wp_update_comment( $update );
			}
		}
	}

	/**
	 * @param WC_Order $order  Order.
	 * @param array    $record Record.
	 * @return string[] Warnings.
	 */
	private function add_refunds( WC_Order $order, array $record ) {
		$warnings = array();
		foreach ( $this->lines( $record, 'refunds' ) as $line ) {
			$amount = isset( $line['amount'] ) ? wc_format_decimal( $line['amount'] ) : 0;
			if ( (float) $amount <= 0 ) {
				continue;
			}
			$refund = wc_create_refund(
				array(
					'order_id'       => $order->get_id(),
					'amount'         => $amount,
					'reason'         => isset( $line['reason'] ) ? sanitize_text_field( $line['reason'] ) : '',
					'refund_payment' => false,
					'restock_items'  => false,
				)
			);
			if ( is_wp_error( $refund ) ) {
				/* translators: 1: amount, 2: error */
				$warnings[] = sprintf( __( 'Refund of %1$s could not be created: %2$s', 'dokan-order-import-export' ), $amount, $refund->get_error_message() );
				continue;
			}
			if ( ! empty( $line['date_created'] ) ) {
				$refund->set_date_created( $line['date_created'] );
			}
			if ( ! empty( $line['refunded_payment'] ) ) {
				$refund->set_refunded_payment( true );
			}
			$refund->save();
		}
		return $warnings;
	}
}
