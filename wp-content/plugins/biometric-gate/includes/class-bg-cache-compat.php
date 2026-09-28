<?php
/**
 * Cache-bypass integration (spec #10). Rather than depending on any single cache plugin's
 * proprietary API — which would break portability the moment this plugin lands on a site
 * without Breeze/Object Cache Pro — this uses the two most universal WordPress conventions:
 * explicit no-store HTTP headers on our own REST responses, and the de facto DONOTCACHEPAGE
 * constant that Breeze, WP Rocket, W3 Total Cache, and most other full-page cache plugins
 * all honor for content that must never be served from a shared page cache.
 */

defined( 'ABSPATH' ) || exit;

class BG_Cache_Compat {

	public static function init() {
		add_filter( 'rest_pre_serve_request', array( __CLASS__, 'no_store_rest_responses' ), 10, 3 );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_disable_page_cache' ), 1 );
	}

	/**
	 * @param bool             $served
	 * @param WP_REST_Response $result
	 * @param WP_REST_Request  $request
	 * @return bool
	 */
	public static function no_store_rest_responses( $served, $result, $request ) {
		if ( 0 === strpos( $request->get_route(), '/' . BG_REST_NAMESPACE ) && ! headers_sent() ) {
			// Strip first: header()'s default replace-on-same-name only protects against a
			// header *we* already sent this request — it does nothing against one some other
			// plugin or drop-in queued before this filter ran (client QA: a stale 30-day
			// Cache-Control was still reaching the browser on /session/status here).
			header_remove( 'Cache-Control' );
			header_remove( 'Pragma' );
			header_remove( 'Expires' );
			header( 'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0, s-maxage=0' );
			header( 'Pragma: no-cache' );
			header( 'Expires: Thu, 01 Jan 1970 00:00:00 GMT' );
			header( 'X-Accel-Expires: 0' ); // Nginx proxy cache.
			header( 'Surrogate-Control: no-store' ); // Varnish / CDN edge caches.
		}
		return $served;
	}

	/**
	 * Protected-path responses are inherently per-user/session state and must never be served
	 * from a shared page cache, regardless of which caching plugin (or none) is active.
	 */
	public static function maybe_disable_page_cache() {
		if ( ! BG_Route_Matcher::path_is_protected( BG_Route_Matcher::current_path() ) ) {
			return;
		}

		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}

		nocache_headers();
	}
}
