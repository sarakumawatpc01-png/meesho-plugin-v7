<?php
/**
 * MM_Integrations — read-only data access into WooCommerce, Google Analytics
 * (GA4), Google Ads, Site Kit, and Meta plugins.
 *
 * Used by the Copilot to gather context and answer questions without ever
 * needing destructive write access to those plugins. All methods are
 * defensive — if a target plugin is not active, the method returns an
 * empty array or 'not_available' instead of throwing.
 */
if ( ! class_exists( 'MM_Integrations' ) ) {
class MM_Integrations {

	/**
	 * Detect which integrations are currently available on this site.
	 */
	public static function detect_available() {
		return array(
			'woocommerce'    => class_exists( 'WooCommerce' ),
			'site_kit'       => is_plugin_active_safe( 'google-site-kit/google-site-kit.php' ),
			'analytify'      => is_plugin_active_safe( 'analytify/wp-analytify.php' ),
			'monsterinsights' => is_plugin_active_safe( 'google-analytics-for-wordpress/googleanalytics.php' ),
			'google_ads_official' => is_plugin_active_safe( 'google-listings-and-ads/google-listings-and-ads.php' ),
			'meta_official'  => is_plugin_active_safe( 'official-facebook-pixel/facebook-for-wordpress.php' )
				|| is_plugin_active_safe( 'facebook-for-woocommerce/facebook-for-woocommerce.php' ),
			'yoast'          => defined( 'WPSEO_VERSION' ),
			'rankmath'       => class_exists( 'RankMath' ),
			'aioseo'         => class_exists( 'AIOSEO\\Plugin\\AIOSEO' ) || function_exists( 'aioseo' ),
		);
	}

	/* ====================================================================
	 *  WooCommerce
	 * ==================================================================== */

	/**
	 * High-level snapshot of WC store health (read-only).
	 */
	public static function woocommerce_snapshot() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return array( 'available' => false );
		}
		global $wpdb;
		$snapshot = array( 'available' => true );

		// Order counts (last 30 days)
		$thirty_ago = gmdate( 'Y-m-d H:i:s', strtotime( '-30 days' ) );
		$snapshot['orders_last_30d'] = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'shop_order' AND post_date_gmt >= %s",
			$thirty_ago
		) );

		// Revenue last 30d (approximate — sums order_total meta)
		$snapshot['revenue_last_30d'] = (float) $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(SUM(CAST(pm.meta_value AS DECIMAL(20,2))), 0)
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_order_total'
			 WHERE p.post_type = 'shop_order' AND p.post_date_gmt >= %s
			   AND p.post_status IN ('wc-completed','wc-processing','wc-on-hold')",
			$thirty_ago
		) );

		$snapshot['products_total'] = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish'"
		);
		$snapshot['products_out_of_stock'] = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT p.ID)
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
			 WHERE p.post_type = 'product' AND pm.meta_key = '_stock_status' AND pm.meta_value = 'outofstock'"
		);

		// Order-status distribution
		$status_rows = $wpdb->get_results(
			"SELECT post_status, COUNT(*) AS c FROM {$wpdb->posts}
			 WHERE post_type = 'shop_order' GROUP BY post_status"
		);
		$snapshot['order_status_breakdown'] = array();
		foreach ( (array) $status_rows as $r ) {
			$snapshot['order_status_breakdown'][ $r->post_status ] = (int) $r->c;
		}

		return $snapshot;
	}

	/**
	 * Recent WooCommerce orders (lightweight).
	 */
	public static function woocommerce_recent_orders( $limit = 25 ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}
		$orders = wc_get_orders( array( 'limit' => max( 1, min( 100, (int) $limit ) ), 'orderby' => 'date', 'order' => 'DESC' ) );
		$out = array();
		foreach ( (array) $orders as $o ) {
			if ( ! is_object( $o ) || ! method_exists( $o, 'get_id' ) ) {
				continue;
			}
			$out[] = array(
				'id'         => $o->get_id(),
				'status'     => $o->get_status(),
				'total'      => (float) $o->get_total(),
				'currency'   => $o->get_currency(),
				'date'       => $o->get_date_created() ? $o->get_date_created()->format( 'Y-m-d H:i' ) : '',
				'item_count' => $o->get_item_count(),
				'customer'   => $o->get_billing_first_name() . ' ' . $o->get_billing_last_name(),
				'edit_url'   => admin_url( 'post.php?post=' . $o->get_id() . '&action=edit' ),
			);
		}
		return $out;
	}

	/* ====================================================================
	 *  Google — Site Kit / Analytify / MonsterInsights
	 * ==================================================================== */

	/**
	 * Fetch GA4 metrics if Google Site Kit is connected. Returns null if
	 * unavailable so the caller can fall back to other GA plugins.
	 */
	public static function google_analytics_snapshot() {
		// Site Kit is the canonical Google plugin and the only one whose
		// runtime API we can rely on. Other plugins (Analytify,
		// MonsterInsights) we only flag as "present" — actual data fetch
		// would need their proprietary API.
		if ( ! is_plugin_active_safe( 'google-site-kit/google-site-kit.php' ) ) {
			return array( 'available' => false, 'reason' => 'Google Site Kit not active.' );
		}
		if ( ! class_exists( '\\Google\\Site_Kit\\Plugin' ) ) {
			return array( 'available' => false, 'reason' => 'Site Kit class not loaded.' );
		}
		// Pull whatever the modules expose via WP options. Site Kit stores
		// its analytics options under googlesitekit_analytics_4_settings.
		$ga4_settings = get_option( 'googlesitekit_analytics-4_settings', array() );
		return array(
			'available'   => true,
			'property_id' => $ga4_settings['propertyID'] ?? '',
			'measurement_id' => $ga4_settings['measurementID'] ?? '',
			'note' => 'Site Kit detected. For real-time GA4 metrics, use the Site Kit dashboard.',
		);
	}

	/**
	 * Search Console snapshot via Site Kit.
	 */
	public static function search_console_snapshot() {
		if ( ! is_plugin_active_safe( 'google-site-kit/google-site-kit.php' ) ) {
			return array( 'available' => false );
		}
		$sc = get_option( 'googlesitekit_search-console_settings', array() );
		return array(
			'available'    => true,
			'property_url' => $sc['propertyID'] ?? '',
		);
	}

	/* ====================================================================
	 *  Google Ads (via Google Listings & Ads — official plugin)
	 * ==================================================================== */

	public static function google_ads_snapshot() {
		if ( ! is_plugin_active_safe( 'google-listings-and-ads/google-listings-and-ads.php' ) ) {
			return array( 'available' => false );
		}
		$mc = get_option( 'gla_merchant_center_id', '' );
		$ads = get_option( 'gla_ads_id', '' );
		return array(
			'available'       => true,
			'merchant_center_id' => $mc,
			'ads_id'          => $ads,
			'note' => 'Google Listings & Ads detected. Use its native dashboard for live campaign data.',
		);
	}

	/* ====================================================================
	 *  Meta (Facebook for WooCommerce — official Meta plugin)
	 * ==================================================================== */

	public static function meta_snapshot() {
		$active = is_plugin_active_safe( 'facebook-for-woocommerce/facebook-for-woocommerce.php' );
		if ( ! $active ) {
			return array( 'available' => false );
		}
		$pixel_id = get_option( 'wc_facebook_pixel_id', '' );
		$catalog_id = get_option( 'wc_facebook_catalog_id', '' );
		return array(
			'available'  => true,
			'pixel_id'   => $pixel_id,
			'catalog_id' => $catalog_id,
		);
	}

	/* ====================================================================
	 *  Site health flags (used by Copilot to surface issues)
	 * ==================================================================== */

	/**
	 * Returns an array of health/issue flags with concrete suggested fixes.
	 * Copilot calls this when the user asks "anything wrong with my site?"
	 */
	public static function site_health_flags() {
		global $wpdb;
		$flags = array();

		// 1. WP cron disabled
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			$flags[] = array(
				'severity' => 'warning',
				'area'     => 'cron',
				'message'  => 'WP Cron is disabled — scheduled SEO scans and reports may not run.',
				'fix'      => 'Either remove the DISABLE_WP_CRON define from wp-config.php, or set up a real system cron that hits wp-cron.php every 5 minutes.',
			);
		}

		// 2. Out-of-stock products
		if ( class_exists( 'WooCommerce' ) ) {
			$oos = (int) $wpdb->get_var(
				"SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
				 WHERE p.post_type = 'product' AND pm.meta_key = '_stock_status' AND pm.meta_value = 'outofstock'"
			);
			if ( $oos > 0 ) {
				$flags[] = array(
					'severity' => 'info',
					'area'     => 'inventory',
					'message'  => "{$oos} product(s) are out of stock.",
					'fix'      => 'Review them in WooCommerce → Products → filter by Stock Status. Either restock, hide, or schedule re-import.',
				);
			}
		}

		// 3. Stale products in mm_products with no WC link
		if ( class_exists( 'MM_DB' ) ) {
			$stranded = (int) $wpdb->get_var(
				'SELECT COUNT(*) FROM ' . MM_DB::table( 'products' ) . " WHERE status = 'staged' AND import_date < DATE_SUB(NOW(), INTERVAL 14 DAY)"
			);
			if ( $stranded > 0 ) {
				$flags[] = array(
					'severity' => 'info',
					'area'     => 'staging',
					'message'  => "{$stranded} product(s) staged more than 14 days ago and never pushed to WooCommerce.",
					'fix'      => 'Review the Products tab — push or delete to keep the staging table clean.',
				);
			}
		}

		// 4. SEO scores: any pages scoring < 40
		if ( class_exists( 'MM_DB' ) ) {
			$low_seo = (int) $wpdb->get_var(
				'SELECT COUNT(*) FROM ' . MM_DB::table( 'seo_post_scores' ) . ' WHERE seo_score < 40'
			);
			if ( $low_seo > 0 ) {
				$flags[] = array(
					'severity' => 'warning',
					'area'     => 'seo',
					'message'  => "{$low_seo} page(s) have a low SEO score (< 40).",
					'fix'      => 'Open the SEO tab → review pending suggestions → apply safe ones in bulk.',
				);
			}
		}

		// 5. OpenRouter API key not configured
		$settings_arr = get_option( 'meesho_master_settings', array() );
		if ( empty( $settings_arr['openrouter_api_key'] ) && empty( $settings_arr['mm_openrouter_key'] ) ) {
			$flags[] = array(
				'severity' => 'info',
				'area'     => 'config',
				'message'  => 'OpenRouter API key is not configured — Copilot and AI optimizers will not work.',
				'fix'      => 'Add an OpenRouter key in Settings → AI Models. Get one at openrouter.ai.',
			);
		}

		return $flags;
	}
}
}

if ( ! function_exists( 'is_plugin_active_safe' ) ) {
	/**
	 * Safe wrapper for is_plugin_active that loads its dependencies if needed.
	 */
	function is_plugin_active_safe( $plugin_file ) {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			$pf = ABSPATH . 'wp-admin/includes/plugin.php';
			if ( file_exists( $pf ) ) {
				include_once $pf;
			}
		}
		return function_exists( 'is_plugin_active' ) ? is_plugin_active( $plugin_file ) : false;
	}
}
