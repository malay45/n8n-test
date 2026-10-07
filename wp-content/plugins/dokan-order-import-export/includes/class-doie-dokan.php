<?php
/**
 * Dokan / Dokan Pro integration helpers.
 *
 * Dokan splits a multi-vendor checkout into one parent order (meta `has_sub_order`) and one
 * sub-order per vendor (parent_id = parent order, meta `_dokan_vendor_id` = vendor). Each
 * vendor order also gets a row in `{prefix}dokan_orders` (order total / vendor net amount)
 * and in `{prefix}dokan_vendor_balance` (vendor ledger). Every call here is guarded so the
 * plugin keeps working as a plain WooCommerce importer/exporter when Dokan is not active.
 */

defined( 'ABSPATH' ) || exit;

class DOIE_Dokan {

	/**
	 * @var array<string,bool>
	 */
	private static $tables = array();

	/**
	 * @return bool
	 */
	public static function is_active() {
		return function_exists( 'dokan' ) || class_exists( 'WeDevs_Dokan' );
	}

	/**
	 * @return bool
	 */
	public static function is_pro_active() {
		return function_exists( 'dokan_pro' ) || class_exists( 'Dokan_Pro' );
	}

	/**
	 * Checks (and caches) whether a Dokan table exists.
	 *
	 * @param string $name Table name without prefix.
	 * @return bool
	 */
	public static function table_exists( $name ) {
		global $wpdb;
		if ( ! isset( self::$tables[ $name ] ) ) {
			$table                 = $wpdb->prefix . $name;
			self::$tables[ $name ] = $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		return self::$tables[ $name ];
	}

	/**
	 * @param WC_Order $order Order.
	 * @return int Vendor (seller) user ID, 0 if none.
	 */
	public static function get_vendor_id( WC_Order $order ) {
		$vendor_id = (int) $order->get_meta( '_dokan_vendor_id', true );
		if ( ! $vendor_id && ! self::has_sub_order( $order ) && function_exists( 'dokan_get_seller_id_by_order' ) ) {
			$vendor_id = (int) dokan_get_seller_id_by_order( $order->get_id() );
		}
		return $vendor_id;
	}

	/**
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	public static function has_sub_order( WC_Order $order ) {
		return wc_string_to_bool( (string) $order->get_meta( 'has_sub_order', true ) );
	}

	/**
	 * @param int $vendor_id Vendor user ID.
	 * @return string
	 */
	public static function get_store_name( $vendor_id ) {
		if ( ! $vendor_id ) {
			return '';
		}
		if ( function_exists( 'dokan_get_store_info' ) ) {
			$info = dokan_get_store_info( $vendor_id );
			if ( ! empty( $info['store_name'] ) ) {
				return (string) $info['store_name'];
			}
		}
		$name = get_user_meta( $vendor_id, 'dokan_store_name', true );
		if ( $name ) {
			return (string) $name;
		}
		$user = get_userdata( $vendor_id );
		return $user ? $user->display_name : '';
	}

	/**
	 * Reads the Dokan earnings row for an order.
	 *
	 * @param int $order_id Order ID.
	 * @return array{order_total:string,vendor_earning:string,admin_commission:string}
	 */
	public static function get_earnings( $order_id ) {
		global $wpdb;
		$empty = array(
			'order_total'      => '',
			'vendor_earning'   => '',
			'admin_commission' => '',
		);
		if ( ! self::table_exists( 'dokan_orders' ) ) {
			return $empty;
		}
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT order_total, net_amount FROM {$wpdb->prefix}dokan_orders WHERE order_id = %d LIMIT 1", $order_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( ! $row ) {
			return $empty;
		}
		return array(
			'order_total'      => wc_format_decimal( $row->order_total ),
			'vendor_earning'   => wc_format_decimal( $row->net_amount ),
			'admin_commission' => wc_format_decimal( (float) $row->order_total - (float) $row->net_amount ),
		);
	}

	/**
	 * Vendors for the export filter dropdown.
	 *
	 * @return WP_User[]
	 */
	public static function get_vendors() {
		return get_users(
			array(
				'role'     => 'seller',
				'orderby'  => 'display_name',
				'number'   => 1000,
				'fields'   => array( 'ID', 'display_name', 'user_email' ),
			)
		);
	}

	/**
	 * Finds the vendor on this site matching an exported vendor. Email is the most reliable
	 * key between sites; the user ID is only trusted when no email was exported or it matches.
	 *
	 * @param int    $vendor_id  Exported vendor ID.
	 * @param string $email      Exported vendor email.
	 * @param string $store_name Exported store name.
	 * @return int Vendor user ID on this site, 0 if not found.
	 */
	public static function resolve_vendor( $vendor_id, $email, $store_name ) {
		$vendor_id = absint( $vendor_id );
		$email     = sanitize_email( $email );

		if ( $email ) {
			$user = get_user_by( 'email', $email );
			if ( $user ) {
				return (int) $user->ID;
			}
		}

		// A different email under the same ID means a different person on this site.
		if ( $vendor_id && ! $email ) {
			$user = get_userdata( $vendor_id );
			if ( $user && self::is_seller( $user->ID ) ) {
				return (int) $user->ID;
			}
		}

		if ( '' !== $store_name ) {
			$users = get_users(
				array(
					'meta_key'   => 'dokan_store_name', // phpcs:ignore WordPress.DB.SlowDBQuery
					'meta_value' => $store_name, // phpcs:ignore WordPress.DB.SlowDBQuery
					'number'     => 1,
					'fields'     => 'ID',
				)
			);
			if ( $users ) {
				return (int) $users[0];
			}
		}

		return 0;
	}

	/**
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function is_seller( $user_id ) {
		if ( function_exists( 'dokan_is_user_seller' ) ) {
			return (bool) dokan_is_user_seller( $user_id );
		}
		$user = get_userdata( $user_id );
		return $user && in_array( 'seller', (array) $user->roles, true );
	}

	/**
	 * Rebuilds Dokan's bookkeeping rows for an imported order.
	 *
	 * @param WC_Order $order  Imported order (already saved).
	 * @param array    $record Source record.
	 * @param bool     $preserve_earnings Overwrite the computed vendor earning with the exported one.
	 * @return string[] Warnings.
	 */
	public static function sync_order( WC_Order $order, array $record, $preserve_earnings ) {
		global $wpdb;

		$warnings = array();
		$order_id = $order->get_id();

		if ( self::table_exists( 'dokan_orders' ) ) {
			$wpdb->delete( $wpdb->prefix . 'dokan_orders', array( 'order_id' => $order_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		if ( self::table_exists( 'dokan_vendor_balance' ) ) {
			$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prefix . 'dokan_vendor_balance',
				array(
					'trn_id'   => $order_id,
					'trn_type' => 'dokan_orders',
				),
				array( '%d', '%s' )
			);
		}

		// Dokan keeps no earnings row for a parent order; its sub-orders carry them.
		if ( self::has_sub_order( $order ) ) {
			return $warnings;
		}

		$vendor_id = (int) $order->get_meta( '_dokan_vendor_id', true );
		if ( ! $vendor_id || ! self::table_exists( 'dokan_orders' ) ) {
			return $warnings;
		}

		if ( function_exists( 'dokan_sync_insert_order' ) ) {
			dokan_sync_insert_order( $order_id );
		}

		$exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}dokan_orders WHERE order_id = %d", $order_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( ! $exists ) {
			// Fallback for Dokan versions without dokan_sync_insert_order() or when it bailed out.
			$earning = isset( $record['dokan_vendor_earning'] ) && '' !== $record['dokan_vendor_earning'] ? (float) $record['dokan_vendor_earning'] : (float) $order->get_total();
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prefix . 'dokan_orders',
				array(
					'order_id'     => $order_id,
					'seller_id'    => $vendor_id,
					'order_total'  => $order->get_total(),
					'net_amount'   => $earning,
					'order_status' => 'wc-' . $order->get_status(),
				),
				array( '%d', '%d', '%f', '%f', '%s' )
			);
		}

		// Dokan updates the ledger status on status changes, which ran before this row existed.
		if ( self::table_exists( 'dokan_vendor_balance' ) ) {
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prefix . 'dokan_vendor_balance',
				array( 'status' => 'wc-' . $order->get_status() ),
				array(
					'trn_id'   => $order_id,
					'trn_type' => 'dokan_orders',
				),
				array( '%s' ),
				array( '%d', '%s' )
			);
		}

		if ( $preserve_earnings && isset( $record['dokan_order_total'] ) && is_numeric( $record['dokan_order_total'] ) ) {
			// Dokan lowers this after refunds; the exported value already reflects them.
			$wpdb->update( $wpdb->prefix . 'dokan_orders', array( 'order_total' => (float) $record['dokan_order_total'] ), array( 'order_id' => $order_id ), array( '%f' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		if ( $preserve_earnings && isset( $record['dokan_vendor_earning'] ) && is_numeric( $record['dokan_vendor_earning'] ) ) {
			$earning = (float) $record['dokan_vendor_earning'];
			$wpdb->update( $wpdb->prefix . 'dokan_orders', array( 'net_amount' => $earning ), array( 'order_id' => $order_id ), array( '%f' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( self::table_exists( 'dokan_vendor_balance' ) ) {
				$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->prefix . 'dokan_vendor_balance',
					array( 'debit' => $earning ),
					array(
						'trn_id'   => $order_id,
						'trn_type' => 'dokan_orders',
					),
					array( '%f' ),
					array( '%d', '%s' )
				);
			}
		}

		return $warnings;
	}
}
