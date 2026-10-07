<?php
/**
 * Order queries with meta conditions that work on both order storages. HPOS accepts
 * `meta_query` in wc_get_orders(); the legacy posts store ignores it (with a notice since
 * WooCommerce 9.2), so there it is injected into the underlying WP_Query instead.
 */

defined( 'ABSPATH' ) || exit;

class DOIE_Query {

	const VAR_NAME = 'doie_meta_query';

	public static function init() {
		add_filter( 'woocommerce_order_data_store_cpt_get_orders_query', array( __CLASS__, 'cpt_meta_query' ), 10, 2 );
	}

	/**
	 * @param array $args       wc_get_orders() arguments.
	 * @param array $meta_query WP-style meta query.
	 * @return array|stdClass Result of wc_get_orders().
	 */
	public static function get_orders( array $args, array $meta_query = array() ) {
		if ( $meta_query ) {
			if ( self::is_hpos() ) {
				$args['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery
			} else {
				$args[ self::VAR_NAME ] = $meta_query;
			}
		}
		return wc_get_orders( $args );
	}

	/**
	 * @param array $wp_query_args WP_Query arguments built by the CPT data store.
	 * @param array $query_vars    Original wc_get_orders() arguments.
	 * @return array
	 */
	public static function cpt_meta_query( $wp_query_args, $query_vars ) {
		if ( ! empty( $query_vars[ self::VAR_NAME ] ) ) {
			$existing                    = isset( $wp_query_args['meta_query'] ) ? (array) $wp_query_args['meta_query'] : array();
			$wp_query_args['meta_query'] = $existing ? array( 'relation' => 'AND', $existing, $query_vars[ self::VAR_NAME ] ) : $query_vars[ self::VAR_NAME ]; // phpcs:ignore WordPress.DB.SlowDBQuery
		}
		return $wp_query_args;
	}

	/**
	 * @return bool
	 */
	public static function is_hpos() {
		return class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}
}
