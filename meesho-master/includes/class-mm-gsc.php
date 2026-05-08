<?php

if ( ! class_exists( 'MM_GSC' ) ) {
	class MM_GSC {
		private $credentials;
		private $client;
		private $service;

		public function __construct() {
			$settings = new Meesho_Master_Settings();
			$this->credentials = MM_Crypto::decrypt_key_pattern( $settings->get( 'mm_gsc_credentials', '' ) );
		}

		/**
		 * Initialize Google Client with service account credentials
		 */
		private function init_client() {
			if ( $this->client ) {
				return true;
			}

			if ( empty( $this->credentials ) ) {
				return new WP_Error( 'mm_gsc_no_credentials', 'GSC credentials not configured.' );
			}

			$creds = json_decode( $this->credentials, true );
			if ( ! is_array( $creds ) || empty( $creds['client_email'] ) || empty( $creds['private_key'] ) ) {
				return new WP_Error( 'mm_gsc_invalid_creds', 'Invalid GSC credentials format. Must be a service account JSON key file.' );
			}

			// Check if Google Client Library is available
			if ( ! class_exists( 'Google_Client' ) ) {
				// Try to load via Composer autoload if present
				$autoload_paths = array(
					MEESHO_MASTER_PLUGIN_DIR . 'vendor/autoload.php',
					ABSPATH . 'vendor/autoload.php',
				);
				foreach ( $autoload_paths as $autoload ) {
					if ( file_exists( $autoload ) ) {
						require_once $autoload;
						break;
					}
				}
			}

			if ( ! class_exists( 'Google_Client' ) ) {
				return new WP_Error( 'mm_gsc_no_library', 'Google Client Library not found. Please run "composer require google/apiclient" in the plugin directory.' );
			}

			$this->client = new Google_Client();
			$this->client->setAuthConfig( $creds );
			$this->client->addScope( 'https://www.googleapis.com/auth/webmasters.readonly' );
			$this->service = new Google_Service_Webmasters( $this->client );

			return true;
		}

		/**
		 * Get GSC data for a URL with 24h caching
		 */
		public function get_data( $url, $start_date = null, $end_date = null ) {
			$cache_key = 'mm_gsc_' . md5( $url . $start_date . $end_date );
			$cached = get_transient( $cache_key );
			if ( false !== $cached ) {
				return $cached;
			}

			$init = $this->init_client();
			if ( is_wp_error( $init ) ) {
				return $init;
			}

			if ( ! $start_date ) {
				$start_date = date( 'Y-m-d', strtotime( '-30 days' ) );
			}
			if ( ! $end_date ) {
				$end_date = date( 'Y-m-d' );
			}

			try {
				$request = new Google_Service_Webmasters_SearchAnalyticsQueryRequest();
				$request->setStartDate( $start_date );
				$request->setEndDate( $end_date );
				$request->setDimensions( array( 'query' ) );
				$request->setRowLimit( 1000 );

				$result = $this->service->searchanalytics->query( $url, $request );
				$rows   = $result->getRows();

				$total_clicks = 0;
				$total_impressions = 0;
				$total_ctr = 0;
				$total_position = 0;
				$row_count = 0;

				if ( $rows ) {
					foreach ( $rows as $row ) {
						$total_clicks      += $row->getClicks();
						$total_impressions += $row->getImpressions();
						$total_ctr         += $row->getCtr();
						$total_position    += $row->getPosition();
						$row_count++;
					}
				}

				$data = array(
					'clicks'      => $total_clicks,
					'impressions' => $total_impressions,
					'ctr'         => $row_count > 0 ? ( $total_ctr / $row_count ) : 0,
					'position'    => $row_count > 0 ? ( $total_position / $row_count ) : 0,
					'keywords'    => $this->extract_keywords( $rows ),
				);

				// Cache for 24 hours
				set_transient( $cache_key, $data, DAY_IN_SECONDS );
				return $data;

			} catch ( Exception $e ) {
				return new WP_Error( 'mm_gsc_api_error', 'GSC API error: ' . $e->getMessage() );
			}
		}

		/**
		 * Get keyword rankings with 24h caching
		 */
		public function get_rankings( $site_url, $start_date = null, $end_date = null ) {
			$cache_key = 'mm_gsc_rankings_' . md5( $site_url . $start_date . $end_date );
			$cached = get_transient( $cache_key );
			if ( false !== $cached ) {
				return $cached;
			}

			$init = $this->init_client();
			if ( is_wp_error( $init ) ) {
				return $init;
			}

			if ( ! $start_date ) {
				$start_date = date( 'Y-m-d', strtotime( '-30 days' ) );
			}
			if ( ! $end_date ) {
				$end_date = date( 'Y-m-d' );
			}

			try {
				$request = new Google_Service_Webmasters_SearchAnalyticsQueryRequest();
				$request->setStartDate( $start_date );
				$request->setEndDate( $end_date );
				$request->setDimensions( array( 'query', 'page' ) );
				$request->setRowLimit( 1000 );

				$result = $this->service->searchanalytics->query( $site_url, $request );
				$rows   = $result->getRows();

				$rankings = array();
				if ( $rows ) {
					foreach ( $rows as $row ) {
						$keys = $row->getKeys();
						$rankings[] = array(
							'keyword'     => $keys[0] ?? '',
							'page_url'    => $keys[1] ?? '',
							'position'    => round( $row->getPosition(), 1 ),
							'clicks'      => $row->getClicks(),
							'impressions' => $row->getImpressions(),
							'ctr'         => round( $row->getCtr() * 100, 2 ) . '%',
						);
					}
				}

				// Cache for 24 hours
				set_transient( $cache_key, $rankings, DAY_IN_SECONDS );
				return $rankings;

			} catch ( Exception $e ) {
				return new WP_Error( 'mm_gsc_api_error', 'GSC API error: ' . $e->getMessage() );
			}
		}

		/**
		 * Get search queries for keyword research
		 */
		public function get_search_queries( $site_url, $start_date = null, $end_date = null ) {
			$cache_key = 'mm_gsc_queries_' . md5( $site_url . $start_date . $end_date );
			$cached = get_transient( $cache_key );
			if ( false !== $cached ) {
				return $cached;
			}

			$init = $this->init_client();
			if ( is_wp_error( $init ) ) {
				return $init;
			}

			if ( ! $start_date ) {
				$start_date = date( 'Y-m-d', strtotime( '-90 days' ) );
			}
			if ( ! $end_date ) {
				$end_date = date( 'Y-m-d' );
			}

			try {
				$request = new Google_Service_Webmasters_SearchAnalyticsQueryRequest();
				$request->setStartDate( $start_date );
				$request->setEndDate( $end_date );
				$request->setDimensions( array( 'query' ) );
				$request->setRowLimit( 500 );
				$request->setDataState( 'final' );

				$result = $this->service->searchanalytics->query( $site_url, $request );
				$rows   = $result->getRows();

				$queries = array();
				if ( $rows ) {
					foreach ( $rows as $row ) {
						$queries[] = array(
							'keyword'     => $row->getKeys()[0] ?? '',
							'clicks'      => $row->getClicks(),
							'impressions' => $row->getImpressions(),
							'ctr'         => round( $row->getCtr() * 100, 2 ),
							'position'    => round( $row->getPosition(), 1 ),
						);
					}
				}

				// Cache for 24 hours
				set_transient( $cache_key, $queries, DAY_IN_SECONDS );
				return $queries;

			} catch ( Exception $e ) {
				return new WP_Error( 'mm_gsc_api_error', 'GSC API error: ' . $e->getMessage() );
			}
		}

		/**
		 * Extract keywords from GSC rows
		 */
		private function extract_keywords( $rows ) {
			$keywords = array();
			if ( ! $rows ) {
				return $keywords;
			}
			foreach ( $rows as $row ) {
				$keys = $row->getKeys();
				if ( ! empty( $keys[0] ) ) {
					$keywords[] = $keys[0];
				}
			}
			return $keywords;
		}

		/**
		 * Check if llms.txt allows major bots (with 6h caching)
		 */
		public static function check_llms_bot_access() {
			$cache_key = 'mm_llms_bot_access';
			$cached = get_transient( $cache_key );
			if ( false !== $cached ) {
				return $cached;
			}

			$content = MM_SEO_Geo::get_llms_txt_content();
			$result = array(
				'llms_exists'   => $content !== '',
				'gptbot'       => false !== stripos( $content, 'User-agent: GPTBot' ),
				'claudebot'    => false !== stripos( $content, 'User-agent: ClaudeBot' ),
				'googlebot'    => false !== stripos( $content, 'User-agent: Googlebot' ),
				'sitemap'       => false !== stripos( $content, 'Sitemap:' ),
			);

			// Cache for 6 hours
			set_transient( $cache_key, $result, 6 * HOUR_IN_SECONDS );
			return $result;
		}
	}
}
