<?php
/**
 * MM_Scraper - Built-in Meesho product scraper.
 *
 * Replaces the external Scrapling dependency. Uses WordPress's HTTP API
 * with rotating User-Agents and a multi-strategy parsing chain:
 *   1. Meesho's __NEXT_DATA__ JSON blob (most reliable)
 *   2. JSON-LD Product schema
 *   3. OpenGraph + Twitter Card meta tags
 *   4. DOM heuristics (last resort)
 *
 * Returns the same data shape as the legacy parse_html() method so the
 * downstream import flow doesn't have to change.
 *
 * NOTE: Meesho actively blocks scrapers (Cloudflare, dynamic class names,
 * IP rate limiting). This scraper handles their current page structure
 * but may need adjustments as Meesho ships changes.
 */

if ( ! class_exists( 'MM_Scraper' ) ) {

	class MM_Scraper {

		/**
		 * Pool of realistic browser User-Agents. We rotate per request
		 * to look less like a bot.
		 */
		private static $user_agents = array(
			'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
			'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
			'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:125.0) Gecko/20100101 Firefox/125.0',
			'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/605.1.15',
			'Mozilla/5.0 (Linux; Android 10; SM-G975F) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Mobile Safari/537.36',
		);

		/**
		 * Public entrypoint. Fetches a Meesho product URL and returns
		 * structured data, or WP_Error on failure.
		 *
		 * @param string $url Meesho product URL.
		 * @return array|WP_Error
		 */
		public static function fetch( $url ) {
			$url = esc_url_raw( $url );
			if ( empty( $url ) || false === strpos( $url, 'meesho.com' ) ) {
				return new WP_Error( 'invalid_url', 'URL must be a Meesho product link.' );
			}

			$html = self::http_get( $url );
			if ( is_wp_error( $html ) ) {
				return $html;
			}

			return self::parse( $html, $url );
		}

		/**
		 * Public entrypoint for HTML paste fallback. Same parser, no fetch.
		 */
		public static function parse_paste( $html, $product_url = '' ) {
			return self::parse( $html, $product_url );
		}

		/**
		 * HTTP GET with rotating UA, retries, and Cloudflare detection.
		 */
		private static function http_get( $url, $attempt = 1 ) {
			$ua = self::$user_agents[ array_rand( self::$user_agents ) ];

			$response = wp_remote_get(
				$url,
				array(
					'timeout'     => 30,
					'redirection' => 5,
					'sslverify'   => true,
					'headers'     => array(
						'User-Agent'                => $ua,
						'Accept'                    => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
						'Accept-Language'           => 'en-US,en;q=0.9,hi;q=0.8',
						'Accept-Encoding'           => 'gzip, deflate, br',
						'Cache-Control'             => 'no-cache',
						'Pragma'                    => 'no-cache',
						'Sec-Fetch-Dest'            => 'document',
						'Sec-Fetch-Mode'            => 'navigate',
						'Sec-Fetch-Site'            => 'none',
						'Sec-Fetch-User'            => '?1',
						'Upgrade-Insecure-Requests' => '1',
					),
				)
			);

			if ( is_wp_error( $response ) ) {
				if ( $attempt < 2 ) {
					sleep( 2 );
					return self::http_get( $url, $attempt + 1 );
				}
				return $response;
			}

			$code = wp_remote_retrieve_response_code( $response );
			$body = wp_remote_retrieve_body( $response );

			// Detect Cloudflare or similar challenge pages
			if ( 403 === $code || 503 === $code ) {
				return new WP_Error(
					'blocked',
					'Meesho blocked the request (HTTP ' . $code . '). Try the HTML paste method instead.'
				);
			}

			if ( 200 !== $code ) {
				return new WP_Error( 'http_error', 'HTTP ' . $code . ' returned by Meesho.' );
			}

			if ( empty( $body ) || strlen( $body ) < 1000 ) {
				return new WP_Error( 'empty_response', 'Meesho returned an empty or truncated page.' );
			}

			// If page looks like a Cloudflare challenge, fail loud
			if ( false !== stripos( $body, 'cf-challenge' ) || false !== stripos( $body, 'Just a moment' ) ) {
				return new WP_Error(
					'cloudflare',
					'Meesho served a Cloudflare challenge page. Use HTML paste with a real browser session.'
				);
			}

			return $body;
		}

		/**
		 * Multi-strategy parser. Returns the legacy data shape.
		 */
		private static function parse( $html, $product_url = '' ) {
			$data = array(
				'title'             => '',
				'description'       => '',
				'images'            => array(),
				'sizes'             => array(),
				'reviews'           => array(),
				'rating'            => 0,
				'review_count'      => 0,
				'rating_breakdown'  => array(),
				'delivery_estimate' => '',
				'meesho_url'        => $product_url,
				'image_url'         => '',
				'price'             => 0,
				'mrp'               => 0,
				'_source'           => 'unknown',
			);

			// Strategy 1: __NEXT_DATA__ JSON blob (Meesho is a Next.js app)
			$next_data = self::extract_next_data( $html );
			if ( ! empty( $next_data ) ) {
				$data = self::merge_next_data( $data, $next_data );
				$data['_source'] = 'next_data';
			}

			// Suppress DOM warnings for malformed HTML
			libxml_use_internal_errors( true );
			$doc = new DOMDocument();
			$doc->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR );
			libxml_clear_errors();
			$xpath = new DOMXPath( $doc );

			// Strategy 2: JSON-LD Product schema
			if ( empty( $data['title'] ) || empty( $data['description'] ) ) {
				self::merge_jsonld( $data, $xpath );
			}

			// Strategy 3: OG / Twitter meta tags
			if ( empty( $data['title'] ) ) {
				$og = $xpath->query( '//meta[@property="og:title" or @name="twitter:title"]' );
				if ( $og->length > 0 ) {
					$data['title'] = trim( $og->item( 0 )->getAttribute( 'content' ) );
				}
			}
			if ( empty( $data['description'] ) ) {
				$ogd = $xpath->query( '//meta[@property="og:description" or @name="description"]' );
				if ( $ogd->length > 0 ) {
					$data['description'] = trim( $ogd->item( 0 )->getAttribute( 'content' ) );
				}
			}
			if ( empty( $data['images'] ) ) {
				$ogi = $xpath->query( '//meta[@property="og:image"]' );
				foreach ( $ogi as $img ) {
					$src = $img->getAttribute( 'content' );
					if ( $src && false !== strpos( $src, 'meesho' ) ) {
						$data['images'][] = $src;
					}
				}
			}

			// Strategy 4: DOM heuristics for everything still missing
			if ( empty( $data['title'] ) ) {
				$h1 = $xpath->query( '//h1' );
				if ( $h1->length > 0 ) {
					$data['title'] = trim( $h1->item( 0 )->textContent );
				}
			}

			// Always sweep <img> tags for additional product images
			$imgs = $xpath->query( '//img[contains(@src, "images.meesho.com")]' );
			foreach ( $imgs as $img ) {
				$src = $img->getAttribute( 'src' );
				if ( $src && ! in_array( $src, $data['images'], true ) ) {
					$data['images'][] = $src;
				}
			}
			// Also check data-src and srcset
			$all_imgs = $xpath->query( '//img' );
			foreach ( $all_imgs as $img ) {
				foreach ( array( 'data-src', 'srcset' ) as $attr ) {
					$val = $img->getAttribute( $attr );
					if ( $val && false !== strpos( $val, 'images.meesho.com' ) ) {
						$first = preg_split( '/[\s,]+/', trim( $val ) );
						if ( ! empty( $first[0] ) && ! in_array( $first[0], $data['images'], true ) ) {
							$data['images'][] = $first[0];
						}
					}
				}
			}

			// Pick the first image as the canonical one (used for SKU extraction)
			if ( ! empty( $data['images'] ) && empty( $data['image_url'] ) ) {
				$data['image_url'] = $data['images'][0];
			}

			// Delivery estimate heuristic
			if ( empty( $data['delivery_estimate'] ) ) {
				$delivery_nodes = $xpath->query( '//*[contains(text(), "Delivery by") or contains(text(), "delivery by")]' );
				if ( $delivery_nodes->length > 0 ) {
					$data['delivery_estimate'] = trim( $delivery_nodes->item( 0 )->textContent );
				}
			}

			// Final sanity: must have at least a title or an image to be useful
			if ( empty( $data['title'] ) && empty( $data['images'] ) ) {
				return new WP_Error(
					'parse_failed',
					'Could not extract product data. Meesho may have changed their page structure or blocked the request.'
				);
			}

			return $data;
		}

		/**
		 * Pull the __NEXT_DATA__ JSON payload out of the HTML.
		 */
		private static function extract_next_data( $html ) {
			if ( ! preg_match( '#<script id="__NEXT_DATA__"[^>]*>(.*?)</script>#s', $html, $m ) ) {
				return null;
			}
			$json = json_decode( $m[1], true );
			return is_array( $json ) ? $json : null;
		}

		/**
		 * Walk Meesho's __NEXT_DATA__ tree and pull product fields.
		 * Their schema changes occasionally — we look in multiple known paths.
		 */
		private static function merge_next_data( $data, $next ) {
			$paths = array(
				array( 'props', 'pageProps', 'initialState', 'productDetails', 'data' ),
				array( 'props', 'pageProps', 'product' ),
				array( 'props', 'pageProps', 'productData' ),
			);

			$product = null;
			foreach ( $paths as $path ) {
				$cursor = $next;
				foreach ( $path as $key ) {
					if ( ! is_array( $cursor ) || ! isset( $cursor[ $key ] ) ) {
						$cursor = null;
						break;
					}
					$cursor = $cursor[ $key ];
				}
				if ( is_array( $cursor ) && ! empty( $cursor ) ) {
					$product = $cursor;
					break;
				}
			}

			if ( ! $product ) {
				return $data;
			}

			if ( ! empty( $product['name'] ) ) {
				$data['title'] = (string) $product['name'];
			}
			if ( ! empty( $product['description'] ) ) {
				$data['description'] = (string) $product['description'];
			}
			if ( isset( $product['minProductPrice'] ) ) {
				$data['price'] = floatval( $product['minProductPrice'] );
			} elseif ( isset( $product['transientPrice'] ) ) {
				$data['price'] = floatval( $product['transientPrice'] );
			}
			if ( isset( $product['mrp'] ) ) {
				$data['mrp'] = floatval( $product['mrp'] );
			}
			if ( isset( $product['avgRating'] ) ) {
				$data['rating'] = floatval( $product['avgRating'] );
			}
			if ( isset( $product['ratingCount'] ) ) {
				$data['review_count'] = intval( $product['ratingCount'] );
			}
			if ( ! empty( $product['ratingBreakup'] ) && is_array( $product['ratingBreakup'] ) ) {
				$data['rating_breakdown'] = $product['ratingBreakup'];
			}
			if ( ! empty( $product['images'] ) && is_array( $product['images'] ) ) {
				foreach ( $product['images'] as $img ) {
					$src = is_array( $img ) ? ( $img['url'] ?? $img['imageUrl'] ?? '' ) : (string) $img;
					if ( $src && ! in_array( $src, $data['images'], true ) ) {
						$data['images'][] = $src;
					}
				}
			}
			if ( ! empty( $product['variations'] ) && is_array( $product['variations'] ) ) {
				foreach ( $product['variations'] as $variation ) {
					if ( ! is_array( $variation ) ) {
						continue;
					}
					$data['sizes'][] = array(
						'size'      => (string) ( $variation['size'] ?? $variation['variationName'] ?? 'Free Size' ),
						'price'     => floatval( $variation['price'] ?? $data['price'] ?? 0 ),
						'mrp'       => floatval( $variation['mrp'] ?? $data['mrp'] ?? 0 ),
						'available' => empty( $variation['outOfStock'] ),
					);
				}
			}
			if ( ! empty( $product['reviews'] ) && is_array( $product['reviews'] ) ) {
				foreach ( $product['reviews'] as $review ) {
					if ( ! is_array( $review ) ) {
						continue;
					}
					$data['reviews'][] = array(
						'reviewer_name' => (string) ( $review['reviewerName'] ?? $review['name'] ?? 'Customer' ),
						'star_rating'   => intval( $review['rating'] ?? 0 ),
						'review_text'   => (string) ( $review['reviewText'] ?? $review['text'] ?? '' ),
						'review_image'  => (string) ( $review['imageUrl'] ?? '' ),
						'review_date'   => (string) ( $review['createdAt'] ?? $review['date'] ?? '' ),
					);
				}
			}

			return $data;
		}

		/**
		 * Merge in JSON-LD Product schema if present.
		 */
		private static function merge_jsonld( &$data, $xpath ) {
			$scripts = $xpath->query( '//script[@type="application/ld+json"]' );
			foreach ( $scripts as $script ) {
				$json = json_decode( $script->textContent, true );
				if ( ! is_array( $json ) ) {
					continue;
				}
				// JSON-LD can be an array of items or a single item
				$items = isset( $json['@type'] ) ? array( $json ) : $json;
				foreach ( $items as $item ) {
					if ( ! is_array( $item ) || ( $item['@type'] ?? '' ) !== 'Product' ) {
						continue;
					}
					if ( empty( $data['title'] ) && ! empty( $item['name'] ) ) {
						$data['title'] = (string) $item['name'];
					}
					if ( empty( $data['description'] ) && ! empty( $item['description'] ) ) {
						$data['description'] = (string) $item['description'];
					}
					if ( ! empty( $item['aggregateRating']['ratingValue'] ) ) {
						$data['rating'] = floatval( $item['aggregateRating']['ratingValue'] );
					}
					if ( ! empty( $item['aggregateRating']['reviewCount'] ) ) {
						$data['review_count'] = intval( $item['aggregateRating']['reviewCount'] );
					}
					if ( isset( $item['offers'] ) ) {
						$offers = isset( $item['offers'][0] ) ? $item['offers'] : array( $item['offers'] );
						foreach ( $offers as $offer ) {
							if ( ! is_array( $offer ) ) {
								continue;
							}
							if ( empty( $data['price'] ) && isset( $offer['price'] ) ) {
								$data['price'] = floatval( $offer['price'] );
							}
							if ( ! empty( $offer['name'] ) ) {
								$data['sizes'][] = array(
									'size'      => (string) $offer['name'],
									'price'     => floatval( $offer['price'] ?? 0 ),
									'mrp'       => floatval( $offer['highPrice'] ?? $offer['price'] ?? 0 ),
									'available' => ( $offer['availability'] ?? '' ) !== 'OutOfStock',
								);
							}
						}
					}
				}
			}
		}
	}
}
