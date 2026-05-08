<?php
/**
 * Native Meesho scraper — v6.4 (rebuilt from real __NEXT_DATA__ payload).
 *
 * Key fixes vs. v6.3:
 *  • SKU is the canonical product number (e.g. 389546965) extracted from
 *    image URLs or `original_product_id`, NOT the URL handle (6fxcd1).
 *  • Variations get unique SKUs: {parent_sku}-{SIZE} (e.g. 389546965-S).
 *  • Reviews are scraped from `review_summary.data.reviews[]` with full
 *    media URLs and authors.
 *  • Brand/title/description never include the literal word "Meesho".
 *  • Sizes come from inventory[] with proper in_stock flags.
 *
 * Strategy chain:
 *   1. __NEXT_DATA__ JSON (full product object — most reliable)
 *   2. JSON-LD Product schema (fallback, also stripped of marketplace refs)
 *   3. og: meta tags (last resort — bare-minimum data)
 */
if ( ! class_exists( 'MM_Native_Scraper' ) ) {
class MM_Native_Scraper {

	private static $user_agents = array(
		'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
		'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36',
		'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
		'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1',
	);

	/**
	 * Public entry point. Accepts a URL OR pre-fetched HTML.
	 */
	public static function fetch( $url, $html = null ) {
		$url = esc_url_raw( trim( $url ) );

		if ( null === $html ) {
			if ( empty( $url ) || false === strpos( $url, 'meesho.com' ) ) {
				return new WP_Error( 'invalid_url', 'Not a marketplace URL.' );
			}
			$html = self::http_get( $url );
			if ( is_wp_error( $html ) ) {
				return $html;
			}
			if ( empty( $html ) ) {
				return new WP_Error( 'empty_body', 'Empty response from server. The site may be blocking the request.' );
			}
		}

		// Strategy 1: __NEXT_DATA__ — the gold mine
		$data = self::parse_next_data( $html, $url );
		if ( ! is_wp_error( $data ) && self::is_usable( $data ) ) {
			return self::sanitize_branding( $data );
		}

		// Strategy 2: JSON-LD Product schema
		$data = self::parse_json_ld( $html, $url );
		if ( ! is_wp_error( $data ) && self::is_usable( $data ) ) {
			return self::sanitize_branding( $data );
		}

		// Strategy 3: og: tags
		$data = self::parse_dom_fallback( $html, $url );
		if ( ! is_wp_error( $data ) && self::is_usable( $data ) ) {
			return self::sanitize_branding( $data );
		}

		return new WP_Error(
			'all_strategies_failed',
			'Could not extract product data. The page may be blocked or its structure has changed. Try the HTML-paste fallback.'
		);
	}

	/* ====================================================================
	 *  HTTP fetch
	 * ==================================================================== */

	private static function http_get( $url ) {
		$ua  = self::$user_agents[ array_rand( self::$user_agents ) ];
		$res = wp_remote_get( $url, array(
			'timeout'     => 25,
			'redirection' => 5,
			'headers'     => array(
				'User-Agent'                => $ua,
				'Accept'                    => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
				'Accept-Language'           => 'en-IN,en;q=0.9',
				'Accept-Encoding'           => 'gzip, deflate, br',
				'Cache-Control'             => 'no-cache',
				'Sec-Fetch-Dest'            => 'document',
				'Sec-Fetch-Mode'            => 'navigate',
				'Sec-Fetch-Site'            => 'none',
				'Upgrade-Insecure-Requests' => '1',
			),
		) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = wp_remote_retrieve_response_code( $res );
		if ( 200 !== (int) $code ) {
			return new WP_Error( 'http_' . $code, 'HTTP ' . $code . ' from server.' );
		}
		return wp_remote_retrieve_body( $res );
	}

	/* ====================================================================
	 *  Strategy 1 — __NEXT_DATA__
	 * ==================================================================== */

	private static function parse_next_data( $html, $url ) {
		if ( ! preg_match( '#<script[^>]+id=["\']__NEXT_DATA__["\'][^>]*>(.*?)</script>#is', $html, $m ) ) {
			return new WP_Error( 'no_next_data', '__NEXT_DATA__ not found.' );
		}
		$json = trim( $m[1] );
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'bad_next_data', 'Could not decode __NEXT_DATA__ JSON.' );
		}

		// The real path — verified against actual Meesho product page
		$details = $data['props']['pageProps']['initialState']['product']['details']['data'] ?? null;
		if ( ! is_array( $details ) ) {
			// fall back to older shapes
			foreach ( array( 'productDetails', 'product', 'singleProduct', 'productData', 'pdpDetails' ) as $key ) {
				if ( ! empty( $data['props']['pageProps'][ $key ] ) && is_array( $data['props']['pageProps'][ $key ] ) ) {
					$details = $data['props']['pageProps'][ $key ];
					break;
				}
			}
		}
		if ( ! is_array( $details ) ) {
			return new WP_Error( 'no_product_in_next', 'No product object in __NEXT_DATA__.' );
		}
		return self::normalize_from_next( $details, $url, $html );
	}

	/**
	 * Convert Meesho's product object into our internal shape.
	 */
	private static function normalize_from_next( $pd, $url, $html = '' ) {
		// CANONICAL SKU — the actual product number (e.g. 389546965).
		// Order of preference:
		//   1. original_product_id (always present in newer payloads)
		//   2. product_id_stringified
		//   3. catalog->catalog_id (parent catalog)
		//   4. extracted from image URL
		$sku = '';
		if ( ! empty( $pd['original_product_id'] ) ) {
			$sku = (string) $pd['original_product_id'];
		} elseif ( ! empty( $pd['product_id_stringified'] ) ) {
			$sku = (string) $pd['product_id_stringified'];
		} elseif ( ! empty( $pd['catalog']['catalog_id'] ) ) {
			$sku = (string) $pd['catalog']['catalog_id'];
		}

		// Images — real path is data.images[] with .jpg URLs
		$images = array();
		if ( ! empty( $pd['images'] ) && is_array( $pd['images'] ) ) {
			foreach ( $pd['images'] as $img ) {
				if ( is_string( $img ) ) {
					$images[] = self::upgrade_image_url( $img );
				} elseif ( is_array( $img ) && ! empty( $img['url'] ) ) {
					$images[] = self::upgrade_image_url( $img['url'] );
				}
			}
		}

		// Fallback SKU extraction from image URL
		if ( empty( $sku ) && ! empty( $images[0] ) && preg_match( '#/products/(\d+)/#', $images[0], $im ) ) {
			$sku = $im[1];
		}
		if ( empty( $sku ) && $html && preg_match( '#/images/products/(\d+)/#', $html, $hm ) ) {
			$sku = $hm[1];
		}

		$title = self::clean_text( $pd['name'] ?? $pd['title'] ?? '' );
		$desc  = self::clean_description( $pd['description'] ?? '' );

		// Pricing — try multiple paths
		$price = 0; $mrp = 0;
		if ( ! empty( $pd['suppliers'][0]['price_details'] ) ) {
			$pdets = $pd['suppliers'][0]['price_details'];
			$price = (float) ( $pdets['final_price']['value'] ?? $pdets['product_price']['value'] ?? 0 );
			$mrp   = (float) ( $pdets['mrp_price']['value'] ?? $price );
		}
		if ( ! $price ) {
			$price = (float) ( $pd['price'] ?? $pd['min_price'] ?? $pd['suppliers'][0]['price'] ?? 0 );
		}
		if ( ! $mrp ) {
			$mrp = (float) ( $pd['mrp_details']['mrp'] ?? $pd['original_price'] ?? $price );
		}

		// Brand — strip marketplace name if present
		$brand = self::clean_text( $pd['catalog']['brand_name'] ?? $pd['suppliers'][0]['name'] ?? '' );

		// Sizes — come from suppliers[0].inventory[] with in_stock flag
		$sizes = array();
		if ( ! empty( $pd['suppliers'][0]['inventory'] ) && is_array( $pd['suppliers'][0]['inventory'] ) ) {
			foreach ( $pd['suppliers'][0]['inventory'] as $inv ) {
				$name = $inv['variation']['name'] ?? '';
				if ( ! $name ) {
					continue;
				}
				$sizes[] = array(
					'size'      => (string) $name,
					'price'     => (float) ( $inv['variation']['final_price'] ?? $price ),
					'mrp'       => $mrp,
					'available' => ! empty( $inv['in_stock'] ),
				);
			}
		} elseif ( ! empty( $pd['variations'] ) && is_array( $pd['variations'] ) ) {
			// Older payload — array of strings
			foreach ( $pd['variations'] as $v ) {
				if ( is_string( $v ) ) {
					$sizes[] = array( 'size' => $v, 'price' => $price, 'mrp' => $mrp, 'available' => true );
				} elseif ( is_array( $v ) ) {
					$label = $v['name'] ?? $v['size'] ?? '';
					if ( $label ) {
						$sizes[] = array(
							'size'      => (string) $label,
							'price'     => (float) ( $v['final_price'] ?? $v['price'] ?? $price ),
							'mrp'       => $mrp,
							'available' => $v['in_stock'] ?? true,
						);
					}
				}
			}
		}

		// Reviews — under review_summary.data.reviews[]
		$reviews = array();
		$rev_count = 0;
		$avg_rating = 0;
		if ( ! empty( $pd['review_summary']['data'] ) ) {
			$rs = $pd['review_summary']['data'];
			$rev_count  = (int) ( $rs['review_count'] ?? 0 );
			$avg_rating = (float) ( $rs['average_rating'] ?? 0 );
			$rating_count = (int) ( $rs['rating_count'] ?? 0 );
			if ( ! empty( $rs['reviews'] ) && is_array( $rs['reviews'] ) ) {
				foreach ( $rs['reviews'] as $r ) {
					$media_urls = array();
					foreach ( ( $r['media'] ?? array() ) as $mediaitem ) {
						if ( ! empty( $mediaitem['url'] ) ) {
							$media_urls[] = $mediaitem['url'];
						}
					}
					$reviews[] = array(
						'reviewer_name' => self::clean_text( $r['reviewer_name'] ?? $r['author']['name'] ?? 'Anonymous' ),
						'rating'        => (int) ( $r['rating'] ?? 5 ),
						'comment'       => self::clean_text( $r['comments'] ?? '' ),
						'date'          => $r['created'] ?? $r['created_iso'] ?? '',
						'helpful_count' => (int) ( $r['helpful_count'] ?? 0 ),
						'media'         => $media_urls,
					);
				}
			}
		}

		// Structured product attributes (Color, Fabric, Fit/Shape, etc.)
		$attributes = array();
		foreach ( array( 'product_highlights', 'additional_details' ) as $key ) {
			if ( ! empty( $pd['product_details'][ $key ]['attributes'] ) && is_array( $pd['product_details'][ $key ]['attributes'] ) ) {
				foreach ( $pd['product_details'][ $key ]['attributes'] as $attr ) {
					if ( ! empty( $attr['display_name'] ) && ! empty( $attr['value'] ) ) {
						$attributes[] = array(
							'name'  => self::clean_text( $attr['display_name'] ),
							'value' => self::clean_text( $attr['value'] ),
						);
					}
				}
			}
		}

		return array(
			'meesho_sku'    => $sku,
			'title'         => $title,
			'description'   => $desc,
			'price'         => $price,
			'mrp'           => $mrp,
			'brand'         => $brand,
			'images'        => array_values( array_unique( $images ) ),
			'sizes'         => $sizes,
			'reviews'       => $reviews,
			'review_count'  => $rev_count,
			'avg_rating'    => $avg_rating,
			'attributes'    => $attributes,
			'meesho_url'    => $url,
			'source'        => 'native:next_data',
		);
	}

	/* ====================================================================
	 *  Strategy 2 — JSON-LD Product schema
	 * ==================================================================== */

	private static function parse_json_ld( $html, $url ) {
		if ( ! preg_match_all( '#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $matches ) ) {
			return new WP_Error( 'no_json_ld', 'No JSON-LD blocks.' );
		}
		foreach ( $matches[1] as $blob ) {
			$decoded = json_decode( trim( $blob ), true );
			if ( ! is_array( $decoded ) ) {
				continue;
			}
			$candidates = isset( $decoded['@graph'] ) ? $decoded['@graph'] : array( $decoded );
			foreach ( $candidates as $item ) {
				$type = $item['@type'] ?? '';
				if ( 'Product' !== $type && ( ! is_array( $type ) || ! in_array( 'Product', $type, true ) ) ) {
					continue;
				}

				// Get images
				$images = array();
				if ( ! empty( $item['image'] ) ) {
					$raw = is_array( $item['image'] ) ? $item['image'] : array( $item['image'] );
					foreach ( $raw as $img ) {
						$images[] = self::upgrade_image_url( $img );
					}
				}

				// Get canonical SKU from image URLs (more reliable than item.sku)
				$sku = '';
				if ( ! empty( $images[0] ) && preg_match( '#/products/(\d+)/#', $images[0], $im ) ) {
					$sku = $im[1];
				}
				if ( empty( $sku ) && ! empty( $item['sku'] ) ) {
					$sku = (string) $item['sku'];
				}

				// Reviews
				$reviews = array();
				if ( ! empty( $item['review'] ) && is_array( $item['review'] ) ) {
					foreach ( $item['review'] as $r ) {
						$reviews[] = array(
							'reviewer_name' => self::clean_text( $r['author']['name'] ?? 'Anonymous' ),
							'rating'        => (int) ( $r['reviewRating']['ratingValue'] ?? 5 ),
							'comment'       => self::clean_text( $r['reviewBody'] ?? '' ),
							'date'          => $r['datePublished'] ?? '',
							'media'         => array(),
						);
					}
				}

				$price = (float) ( $item['offers']['price'] ?? 0 );

				return array(
					'meesho_sku'   => $sku,
					'title'        => self::clean_text( $item['name'] ?? '' ),
					'description'  => self::clean_description( $item['description'] ?? '' ),
					'price'        => $price,
					'mrp'          => $price,
					'brand'        => self::clean_text( is_array( $item['brand'] ?? null ) ? ( $item['brand']['name'] ?? '' ) : (string) ( $item['brand'] ?? '' ) ),
					'images'       => $images,
					'sizes'        => array(),
					'reviews'      => $reviews,
					'review_count' => (int) ( $item['aggregateRating']['reviewCount'] ?? 0 ),
					'avg_rating'   => (float) ( $item['aggregateRating']['ratingValue'] ?? 0 ),
					'attributes'   => array(),
					'meesho_url'   => $url,
					'source'       => 'native:json_ld',
				);
			}
		}
		return new WP_Error( 'no_product_jsonld', 'No Product JSON-LD found.' );
	}

	/* ====================================================================
	 *  Strategy 3 — og: tags
	 * ==================================================================== */

	private static function parse_dom_fallback( $html, $url ) {
		$title = '';
		$image = '';
		$desc  = '';
		if ( preg_match( '#<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)["\']#i', $html, $m ) ) {
			$title = html_entity_decode( $m[1] );
		}
		if ( preg_match( '#<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']#i', $html, $m ) ) {
			$image = self::upgrade_image_url( $m[1] );
		}
		if ( preg_match( '#<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']+)["\']#i', $html, $m ) ) {
			$desc = html_entity_decode( $m[1] );
		}

		$sku = '';
		if ( $image && preg_match( '#/products/(\d+)/#', $image, $im ) ) {
			$sku = $im[1];
		}
		if ( empty( $title ) && empty( $image ) ) {
			return new WP_Error( 'dom_fallback_empty', 'No og:title / og:image found.' );
		}
		return array(
			'meesho_sku'   => $sku,
			'title'        => self::clean_text( $title ),
			'description'  => self::clean_description( $desc ),
			'price'        => 0,
			'mrp'          => 0,
			'brand'        => '',
			'images'       => $image ? array( $image ) : array(),
			'sizes'        => array(),
			'reviews'      => array(),
			'review_count' => 0,
			'avg_rating'   => 0,
			'attributes'   => array(),
			'meesho_url'   => $url,
			'source'       => 'native:og_tags',
		);
	}

	/* ====================================================================
	 *  Helpers
	 * ==================================================================== */

	/**
	 * Upgrade a Meesho thumbnail URL to a full-size JPG/WEBP. Their
	 * `_128.avif?width=128` URLs become `_512.jpg`.
	 */
	private static function upgrade_image_url( $url ) {
		if ( ! is_string( $url ) || empty( $url ) ) {
			return '';
		}
		// Strip ?width=NNN
		$url = preg_replace( '#\?.*$#', '', $url );
		// Upgrade resolution suffix (e.g. _128.avif -> _512.jpg)
		$url = preg_replace( '#_\d+\.(avif|webp|jpg|jpeg|png)$#i', '_512.jpg', $url );
		return $url;
	}

	private static function clean_text( $text ) {
		if ( ! is_string( $text ) ) {
			return '';
		}
		return trim( html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}

	/**
	 * Clean a product description. Strips marketplace-specific format cruft,
	 * removes leading "Name: ..." line, removes "Country of Origin: India",
	 * and formats the Sizes measurement block as an HTML table for WooCommerce.
	 */
	private static function clean_description( $desc ) {
		if ( ! is_string( $desc ) ) {
			return '';
		}
		$desc = html_entity_decode( $desc, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		// Remove the leading "Name: Product Name" line (duplicates the title)
		$desc = preg_replace( '/^Name\s*:\s*[^\n]+\n?/m', '', $desc );

		// Remove "Country of Origin: India" line (not needed in WC store)
		$desc = preg_replace( '/Country of Origin\s*:\s*[^\n]+\n?/mi', '', $desc );

		// Format the Sizes measurement block as an HTML table
		$has_sizes_block = preg_match( '/^Sizes\s*:\s*\n?((?:[A-Z0-9XS]+\s*\([^\)]+\)\s*\n?)+)/mi', $desc, $sm );
		if ( $has_sizes_block ) {
			$raw_rows  = trim( $sm[1] );
			$size_rows = preg_split( '/\n/', $raw_rows );
			$table_html = '<table style="border-collapse:collapse;width:auto;font-size:14px;margin:10px 0;">';
			$table_html .= '<thead><tr><th style="padding:6px 12px;border:1px solid #ddd;background:#f5f5f5;">Size</th><th style="padding:6px 12px;border:1px solid #ddd;background:#f5f5f5;">Measurements</th></tr></thead><tbody>';
			foreach ( $size_rows as $row ) {
				$row = trim( $row );
				if ( empty( $row ) ) { continue; }
				if ( preg_match( '/^([A-Z0-9XS]+)\s*\(([^\)]+)\)/i', $row, $rm ) ) {
					$table_html .= '<tr><td style="padding:6px 12px;border:1px solid #ddd;font-weight:bold;">' . esc_html( strtoupper( $rm[1] ) ) . '</td><td style="padding:6px 12px;border:1px solid #ddd;">' . esc_html( $rm[2] ) . '</td></tr>';
				}
			}
			$table_html .= '</tbody></table>';
			$desc = preg_replace( '/Sizes\s*:\s*\n?((?:[A-Z0-9XS]+\s*\([^\)]+\)\s*\n?)+)/mi', "\n<strong>Size Chart:</strong>\n" . $table_html . "\n", $desc );
		} else {
			$desc = preg_replace( '/^Sizes\s*:\s*\n?/mi', '', $desc );
		}

		// Clean up extra blank lines
		$desc = preg_replace( '/\n{3,}/', "\n\n", $desc );

		return trim( $desc );
	}

	/**
	 * Strip the marketplace brand name from text fields that go straight
	 * into the user's WooCommerce store. The user has explicitly asked
	 * for this — they don't want "Meesho" appearing in their own product
	 * pages or descriptions.
	 */
	private static function sanitize_branding( $data ) {
		$strip = function ( $text ) {
			if ( ! is_string( $text ) || empty( $text ) ) {
				return $text;
			}
			$text = preg_replace( '/\bmeesho\b/i', '', $text );
			$text = preg_replace( '/\s{2,}/', ' ', $text );
			return trim( $text );
		};
		$data['title']       = $strip( $data['title'] ?? '' );
		$data['description'] = $strip( $data['description'] ?? '' );
		$data['brand']       = $strip( $data['brand'] ?? '' );
		// Reviewer "Meesho User" → "Customer"
		if ( ! empty( $data['reviews'] ) && is_array( $data['reviews'] ) ) {
			foreach ( $data['reviews'] as &$r ) {
				if ( ! empty( $r['reviewer_name'] ) ) {
					$r['reviewer_name'] = preg_replace( '/\bmeesho\s*user\b/i', 'Customer', $r['reviewer_name'] );
					$r['reviewer_name'] = trim( preg_replace( '/\bmeesho\b/i', '', $r['reviewer_name'] ) );
					if ( '' === $r['reviewer_name'] ) {
						$r['reviewer_name'] = 'Customer';
					}
				}
				if ( ! empty( $r['comment'] ) ) {
					$r['comment'] = $strip( $r['comment'] );
				}
			}
			unset( $r );
		}
		return $data;
	}

	private static function is_usable( $data ) {
		return is_array( $data )
			&& ! empty( $data['meesho_sku'] )
			&& ! empty( $data['title'] )
			&& ! empty( $data['images'] );
	}
}
}
