<?php
/**
 * Meesho Master Import Module
 * Handles product import via Scrapling URL or HTML paste fallback.
 * SKU extraction from image URL primary, product URL fallback.
 * Variation SKU: {MEESHO_SKU}-{SIZE}. Sizes from scraped data only.
 * All dates stored/displayed as dd/mm/yyyy.
 */

class Meesho_Master_Import {

	private const ORDERED_LIST_PREFIX_PATTERN = '\\d+[\\.\\)]\\s+';
	private const UNORDERED_LIST_PREFIX_PATTERN = '[-*•]\\s+';
	private const DEFAULT_IMAGE_ALT = 'Product image';
	private const IMAGE_FALLBACK_ATTRS = array( 'data-src', 'data-original', 'data-lazy-src' );
	private const IMAGE_EXTENSIONS = array( 'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif' );
	private const DESCRIPTION_STRIP_TAGS = array( 'script', 'style', 'noscript', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'link', 'meta', 'svg', 'canvas', 'video', 'audio' );
	private const DESCRIPTION_BLOCK_TAGS = array( 'p', 'ul', 'ol', 'li', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote' );

	private $settings;
	private $undo;

	public function __construct() {
		add_action( 'wp_ajax_meesho_import_url', array( $this, 'ajax_import_url' ) );
		add_action( 'wp_ajax_meesho_import_html', array( $this, 'ajax_import_html' ) );
		add_action( 'wp_ajax_meesho_manual_sku', array( $this, 'ajax_manual_sku' ) );
		// Staged-product workflow (v6.2)
		add_action( 'wp_ajax_mm_list_staged', array( $this, 'ajax_list_staged' ) );
		add_action( 'wp_ajax_mm_get_staged', array( $this, 'ajax_get_staged' ) );
		add_action( 'wp_ajax_mm_save_staged', array( $this, 'ajax_save_staged' ) );
		add_action( 'wp_ajax_mm_push_to_wc', array( $this, 'ajax_push_to_wc' ) );
		add_action( 'wp_ajax_mm_delete_staged', array( $this, 'ajax_delete_staged' ) );
		add_action( 'wp_ajax_mm_check_duplicate', array( $this, 'ajax_check_duplicate' ) );
		add_action( 'wp_ajax_mm_optimize_description', array( $this, 'ajax_optimize_description' ) );
		add_action( 'wp_ajax_mm_ai_generate_title', array( $this, 'ajax_generate_title' ) );
		add_action( 'wp_ajax_mm_openrouter_models', array( $this, 'ajax_fetch_openrouter_models' ) );
	}

	/**
	 * Lazy-load settings module.
	 */
	private function settings() {
		if ( ! $this->settings ) {
			$this->settings = new Meesho_Master_Settings();
		}
		return $this->settings;
	}

	/**
	 * Lazy-load undo module.
	 */
	private function undo() {
		if ( ! $this->undo ) {
			$this->undo = new Meesho_Master_Undo();
		}
		return $this->undo;
	}

	/* ================================================================
	 *  AJAX — Import by URL (via Scrapling)
	 * ================================================================ */

	public function ajax_import_url() {
		meesho_master_verify_ajax_nonce();
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$url = isset( $_POST['url'] ) ? esc_url_raw( $_POST['url'] ) : '';
		if ( empty( $url ) ) {
			wp_send_json_error( 'Product URL is required.' );
		}

		// Pre-flight duplicate check using SKU from URL
		if ( preg_match( '#/p/(\d+)#', $url, $mm ) ) {
			$dupe = $this->check_duplicate( $mm[1] );
			if ( $dupe ) {
				wp_send_json_error( array(
					'code'    => 'already_scraped',
					'message' => 'This Meesho product (SKU ' . $mm[1] . ') is already in your Products tab. Delete it from there first to re-scrape.',
					'sku'     => $mm[1],
				) );
			}
		}

		// Strategy 1: Native scraper (no Scrapling, no Meesho account).
		$scraped = MM_Native_Scraper::fetch( $url );

		// Strategy 2 (optional): Scrapling fallback if user has it configured.
		if ( is_wp_error( $scraped ) ) {
			$scrapling_url = $this->settings()->get( 'scrapling_url' );
			if ( ! empty( $scrapling_url ) ) {
				$scrapling = $this->fetch_from_scrapling( $url );
				if ( ! is_wp_error( $scrapling ) ) {
					$scraped = $scrapling;
					$scraped['meesho_url'] = $url;
				}
			}
		}

		if ( is_wp_error( $scraped ) ) {
			wp_send_json_error( array(
				'message'  => 'Could not scrape the product: ' . $scraped->get_error_message() . '. Try the HTML-paste method below.',
				'fallback' => true,
			) );
		}

		$scraped['meesho_url'] = $url;

		// Stage instead of immediate WC publish — user reviews in Products tab first.
		try {
			$staged = $this->stage_product( $scraped );
			wp_send_json_success( $staged );
		} catch ( Exception $e ) {
			wp_send_json_error( $e->getMessage() );
		}
	}

	/* ================================================================
	 *  AJAX — Import by HTML paste (fallback)
	 * ================================================================ */

	public function ajax_import_html() {
		meesho_master_verify_ajax_nonce();
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$html = isset( $_POST['html'] ) ? wp_unslash( $_POST['html'] ) : '';
		if ( empty( $html ) ) {
			wp_send_json_error( 'HTML source is required.' );
		}

		$product_url = isset( $_POST['product_url'] ) ? esc_url_raw( $_POST['product_url'] ) : '';

		try {
			$parsed = $this->parse_html( $html, $product_url );
			if ( ! empty( $parsed['meesho_sku'] ) ) {
				$dupe = $this->check_duplicate( $parsed['meesho_sku'] );
				if ( $dupe ) {
					wp_send_json_error( array(
						'code'    => 'already_scraped',
						'message' => 'Already in Products tab. Delete from there first to re-scrape.',
						'sku'     => $parsed['meesho_sku'],
					) );
				}
			}
			$staged = $this->stage_product( $parsed );
			wp_send_json_success( $staged );
		} catch ( Exception $e ) {
			wp_send_json_error( $e->getMessage() );
		}
	}

	/* ================================================================
	 *  AJAX — Manual SKU entry
	 * ================================================================ */

	public function ajax_manual_sku() {
		meesho_master_verify_ajax_nonce();
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$sku  = sanitize_text_field( $_POST['sku'] ?? '' );
		$data = isset( $_POST['product_data'] ) ? json_decode( wp_unslash( $_POST['product_data'] ), true ) : null;

		if ( empty( $sku ) || ! is_numeric( $sku ) ) {
			wp_send_json_error( 'Please enter a valid numeric Meesho SKU.' );
		}
		if ( ! $data ) {
			wp_send_json_error( 'Missing product data.' );
		}

		$data['meesho_sku_override'] = $sku;

		try {
			$staged = $this->stage_product( $data );
			wp_send_json_success( $staged );
		} catch ( Exception $e ) {
			wp_send_json_error( $e->getMessage() );
		}
	}

	/* ================================================================
	 *  Scrapling service call
	 * ================================================================ */

	private function fetch_from_scrapling( $url ) {
		$scrapling_url = $this->settings()->get( 'scrapling_url' );
		$timeout       = intval( $this->settings()->get( 'scrapling_timeout' ) );

		if ( empty( $scrapling_url ) ) {
			return new WP_Error( 'no_scrapling', 'Scrapling service URL is not configured.' );
		}

		$response = wp_remote_post( $scrapling_url, array(
			'timeout' => $timeout > 0 ? $timeout : 30,
			'body'    => wp_json_encode( array( 'url' => $url ) ),
			'headers' => array( 'Content-Type' => 'application/json' ),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code !== 200 ) {
			return new WP_Error( 'scrapling_error', 'Scrapling returned HTTP ' . $code );
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) ) {
			return new WP_Error( 'invalid_json', 'Scrapling returned invalid JSON.' );
		}

		return $data;
	}

	/* ================================================================
	 *  HTML parser (fallback)
	 * ================================================================ */

	private function parse_html( $html, $product_url = '' ) {
		// v6.4 — delegate to the native scraper so URL-fetch and HTML-paste
		// produce IDENTICAL output shapes (canonical SKU, full-size images,
		// proper review array with media, branding stripped).
		$result = MM_Native_Scraper::fetch( $product_url, $html );
		if ( ! is_wp_error( $result ) ) {
			return $result;
		}
		// If even the native scraper couldn't extract anything, fall through
		// to the legacy DOM parser below as a last-ditch effort.
		return $this->legacy_parse_html( $html, $product_url );
	}

	private function legacy_parse_html( $html, $product_url = '' ) {
		$data = array(
			'title'              => '',
			'description'        => '',
			'images'             => array(),
			'sizes'              => array(),
			'reviews'            => array(),
			'rating'             => 0,
			'rating_breakdown'   => array(),
			'delivery_estimate'  => '',
			'meesho_url'         => $product_url,
			'image_url'          => '',
		);

		// Suppress DOM warnings for malformed HTML
		libxml_use_internal_errors( true );
		$doc = new DOMDocument();
		$doc->loadHTML( mb_convert_encoding( $html, 'HTML-ENTITIES', 'UTF-8' ) );
		libxml_clear_errors();

		$xpath = new DOMXPath( $doc );

		// Extract title — typically in <h1> or structured data
		$h1 = $xpath->query( '//h1' );
		if ( $h1->length > 0 ) {
			$data['title'] = trim( $h1->item(0)->textContent );
		}

		// Extract images — look for Meesho product image URLs
		$imgs = $xpath->query( '//img[contains(@src, "images.meesho.com")]' );
		foreach ( $imgs as $img ) {
			$src = $img->getAttribute( 'src' );
			if ( ! empty( $src ) ) {
				$data['images'][] = $src;
				// Use the first image URL for SKU extraction
				if ( empty( $data['image_url'] ) ) {
					$data['image_url'] = $src;
				}
			}
		}

		// Also check srcset and data-src attributes
		$all_imgs = $xpath->query( '//img' );
		foreach ( $all_imgs as $img ) {
			foreach ( array( 'data-src', 'srcset' ) as $attr ) {
				$val = $img->getAttribute( $attr );
				if ( strpos( $val, 'images.meesho.com' ) !== false ) {
					// Extract first URL from srcset if needed
					$first_url = preg_split( '/[\s,]+/', $val )[0];
					if ( ! in_array( $first_url, $data['images'], true ) ) {
						$data['images'][] = $first_url;
					}
					if ( empty( $data['image_url'] ) ) {
						$data['image_url'] = $first_url;
					}
				}
			}
		}
		$raw_images = is_array( $data['images'] ?? null ) ? $data['images'] : array();
		if ( ! empty( $data['image_url'] ) ) {
			$raw_images[] = $data['image_url'];
		}
		$data['images'] = $this->sanitize_image_list( $raw_images );
		$data['image_url'] = $data['images'][0] ?? '';

		// Extract JSON-LD structured data (Meesho often embeds this)
		$scripts = $xpath->query( '//script[@type="application/ld+json"]' );
		foreach ( $scripts as $script ) {
			$json = json_decode( $script->textContent, true );
			if ( is_array( $json ) ) {
				if ( isset( $json['@type'] ) && $json['@type'] === 'Product' ) {
					if ( ! empty( $json['name'] ) && empty( $data['title'] ) ) {
						$data['title'] = $json['name'];
					}
					if ( ! empty( $json['description'] ) ) {
						$data['description'] = $json['description'];
					}
					if ( isset( $json['offers'] ) && is_array( $json['offers'] ) ) {
						foreach ( $json['offers'] as $offer ) {
							$data['sizes'][] = array(
								'size'      => $offer['name'] ?? 'Free Size',
								'price'     => floatval( $offer['price'] ?? 0 ),
								'mrp'       => floatval( $offer['highPrice'] ?? $offer['price'] ?? 0 ),
								'available' => ( $offer['availability'] ?? '' ) !== 'OutOfStock',
							);
						}
					}
				}
			}
		}

		// Attempt to find sizes from button/span elements if not found in JSON-LD
		if ( empty( $data['sizes'] ) ) {
			$size_nodes = $xpath->query( '//*[contains(@class, "size") or contains(@class, "Size")]//button | //*[contains(@class, "size") or contains(@class, "Size")]//span' );
			foreach ( $size_nodes as $node ) {
				$text = trim( $node->textContent );
				if ( preg_match( '/^(XXS|XS|S|M|L|XL|XXL|XXXL|Free Size|\d+)$/i', $text ) ) {
					$data['sizes'][] = array(
						'size'      => $text,
						'price'     => 0,
						'mrp'       => 0,
						'available' => strpos( $node->getAttribute('class'), 'disabled' ) === false,
					);
				}
			}
		}

		// Extract description
		if ( empty( $data['description'] ) ) {
			$desc_nodes = $xpath->query( '//*[contains(@class, "pdp-description") or contains(@class, "product-details") or contains(@class, "ProductDescription")]' );
			foreach ( $desc_nodes as $node ) {
				$data['description'] .= $doc->saveHTML( $node );
			}
		}

		// Extract delivery estimate
		$delivery_nodes = $xpath->query( '//*[contains(text(), "Delivery by") or contains(text(), "delivery by")]' );
		if ( $delivery_nodes->length > 0 ) {
			$data['delivery_estimate'] = trim( $delivery_nodes->item(0)->textContent );
		}

		return $data;
	}

	/* ================================================================
	 *  SKU Extraction — Fix 1
	 *  Primary: image URL /products/{NUMERIC_SKU}/
	 *  Fallback: product URL /p/{NUMERIC_SKU}
	 *  Never silent import without SKU.
	 * ================================================================ */

	public function extract_sku( $image_url, $product_url ) {
		$meesho_sku = null;

		// Primary source: Meesho product image URL
		if ( ! empty( $image_url ) && preg_match( '/\/products\/(\d+)\//', $image_url, $matches ) ) {
			$meesho_sku = $matches[1];
		}
		// Fallback source: Meesho product page URL
		elseif ( ! empty( $product_url ) && preg_match( '/\/p\/(\d+)/', $product_url, $matches ) ) {
			$meesho_sku = $matches[1];
		}

		return $meesho_sku; // null if not found — caller must handle
	}

	/* ================================================================
	 *  Core import pipeline
	 * ================================================================ */

	public function process_import( $data ) {
		// Allow manual SKU override
		if ( ! empty( $data['meesho_sku_override'] ) ) {
			$meesho_sku = $data['meesho_sku_override'];
		} else {
			$meesho_sku = $this->extract_sku( $data['image_url'] ?? '', $data['meesho_url'] ?? '' );
		}

		// If SKU still not found, flag the import — never silently import without a SKU
		if ( ! $meesho_sku ) {
			throw new Exception(
				'Could not extract Meesho SKU from image or product URL. '
				. 'Please enter it manually using the SKU field below.'
			);
		}

		// Duplicate check
		$ignore_staged_id = intval( $data['_staged_row_id'] ?? 0 );
		$duplicate = $this->check_duplicate( $meesho_sku, $ignore_staged_id );
		if ( $duplicate ) {
			return array(
				'status'     => 'duplicate',
				'message'    => 'A product with SKU ' . $meesho_sku . ' already exists (WC Product #' . $duplicate . ').',
				'product_id' => $duplicate,
				'sku'        => $meesho_sku,
				'actions'    => array( 'overwrite', 'skip', 'create_new' ),
			);
		}

		// Clean the description
		$clean_desc = $this->clean_description( $data['description'] ?? '' );

		// Create the parent WooCommerce variable product
		$parent_id = $this->create_parent_product( $meesho_sku, $data, $clean_desc );

		// Create variations from sizes — Fix 2: sizes from scraped data only
		if ( ! empty( $data['sizes'] ) && is_array( $data['sizes'] ) ) {
			$overrides = array(
				'override_price' => floatval( $data['override_price'] ?? 0 ),
				'override_mrp'   => floatval( $data['override_mrp'] ?? 0 ),
				'all_out_of_stock' => ! empty( $data['all_out_of_stock'] ),
			);
			$this->create_variations( $parent_id, $meesho_sku, $data['sizes'], $overrides );
		}

		// Import images
		if ( ! empty( $data['images'] ) ) {
			$this->attach_images( $parent_id, $data['images'] );
		}

		// Import reviews
		if ( ! empty( $data['reviews'] ) ) {
			$this->import_reviews( $meesho_sku, $data['reviews'] );
		}

		// Store rating data
		if ( ! empty( $data['rating'] ) ) {
			update_post_meta( $parent_id, '_meesho_avg_rating', floatval( $data['rating'] ) );
		}
		if ( ! empty( $data['rating_breakdown'] ) ) {
			update_post_meta( $parent_id, '_meesho_rating_breakdown', $data['rating_breakdown'] );
		}
		if ( ! empty( $data['review_count'] ) ) {
			update_post_meta( $parent_id, '_meesho_review_count', intval( $data['review_count'] ) );
		}

		// Store delivery estimate
		if ( ! empty( $data['delivery_estimate'] ) ) {
			update_post_meta( $parent_id, '_meesho_delivery_estimate', sanitize_text_field( $data['delivery_estimate'] ) );
		}

		// E1: Log WC product push to audit_log using MM_Undo (spec Bug 4 fix)
		$import_date = date( 'd/m/Y' );
		( new MM_Undo() )->log_before_change(
			'product_import',
			'wc_product',
			$parent_id,
			null,
			wp_json_encode( array( 'sku' => $meesho_sku, 'wc_id' => $parent_id ) ),
			0,
			'manual',
			'Pushed to WC: ' . $meesho_sku,
			1
		);

		return array(
			'status'     => 'imported',
			'message'    => 'Product "' . ( $data['title'] ?? $meesho_sku ) . '" imported successfully on ' . $import_date,
			'product_id' => $parent_id,
			'sku'        => $meesho_sku,
		);
	}

	/* ================================================================
	 *  Duplicate check
	 * ================================================================ */

	private function check_duplicate( $meesho_sku, $ignore_staged_id = 0 ) {
		global $wpdb;

		// 1. Check staging/published rows in mm_products (catches scraped-but-not-pushed items)
		if ( $ignore_staged_id > 0 ) {
			$staged = $wpdb->get_var( $wpdb->prepare(
				'SELECT id FROM ' . MM_DB::table( 'products' ) . ' WHERE meesho_sku = %s AND id <> %d LIMIT 1',
				$meesho_sku,
				$ignore_staged_id
			) );
		} else {
			$staged = $wpdb->get_var( $wpdb->prepare(
				'SELECT id FROM ' . MM_DB::table( 'products' ) . ' WHERE meesho_sku = %s LIMIT 1',
				$meesho_sku
			) );
		}
		if ( $staged ) {
			return intval( $staged );
		}

		// 2. Check _meesho_sku meta (covers products imported by older plugin versions)
		$existing = $wpdb->get_var( $wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_meesho_sku' AND meta_value = %s LIMIT 1",
			$meesho_sku
		) );
		if ( $existing ) {
			return intval( $existing );
		}

		// 3. Check WooCommerce SKU
		$existing = $wpdb->get_var( $wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_sku' AND meta_value = %s LIMIT 1",
			$meesho_sku
		) );
		if ( $existing ) {
			return intval( $existing );
		}

		// Also check our custom table
		return false;
	}

	/* ================================================================
	 *  Create parent WooCommerce variable product
	 * ================================================================ */

	private function create_parent_product( $meesho_sku, $data, $clean_desc ) {
		$product = new WC_Product_Variable();

		$product->set_name( sanitize_text_field( $data['title'] ?? 'Meesho Product ' . $meesho_sku ) );
		$product->set_description( $clean_desc );
		$product->set_short_description( wp_trim_words( strip_tags( $clean_desc ), 30 ) );
		$product->set_sku( $meesho_sku );
		$product->set_status( 'publish' );
		$product->set_catalog_visibility( 'visible' );
		$product->set_manage_stock( false );

		// Set the size attribute
		$attribute = new WC_Product_Attribute();
		$attribute->set_name( 'Size' );
		$sizes_list = array();
		if ( ! empty( $data['sizes'] ) && is_array( $data['sizes'] ) ) {
			foreach ( $data['sizes'] as $s ) {
				$size_val = '';
				if ( is_array( $s ) && ! empty( $s['size'] ) ) {
					$size_val = (string) $s['size'];
				} elseif ( is_string( $s ) && '' !== trim( $s ) ) {
					$size_val = trim( $s );
				}
				if ( '' === $size_val ) {
					continue;
				}
				$sizes_list[] = strtoupper( $size_val );
			}
		}
		$attribute->set_options( array_values( array_unique( $sizes_list ) ) );
		$attribute->set_visible( true );
		$attribute->set_variation( true );
		$product->set_attributes( array( $attribute ) );

		$parent_id = $product->save();

		// Store the raw Meesho SKU in product meta for deduplication
		update_post_meta( $parent_id, '_meesho_sku', $meesho_sku );
		update_post_meta( $parent_id, '_meesho_source_url', $data['meesho_url'] ?? '' );

		return $parent_id;
	}

	/* ================================================================
	 *  Create variations — Fix 2
	 *  Sizes come entirely from scraped product data.
	 *  Variation SKU = {MEESHO_SKU}-{SIZE}
	 *  Available → instock; Unavailable → outofstock (still created)
	 * ================================================================ */

	private function create_variations( $parent_id, $meesho_sku, $sizes, $product_overrides = array() ) {
		foreach ( $sizes as $size_data ) {
			// Defensive: handle both {size,price,mrp,...} and bare strings
			if ( is_string( $size_data ) ) {
				$size_data = array( 'size' => $size_data, 'price' => 0, 'mrp' => 0, 'available' => true );
			}
			if ( empty( $size_data['size'] ) ) {
				continue;
			}
			// Inject product-level override prices so each variation honors them
			if ( ! empty( $product_overrides['override_price'] ) ) {
				$size_data['override_price'] = $product_overrides['override_price'];
			}
			if ( ! empty( $product_overrides['override_mrp'] ) ) {
				$size_data['override_mrp'] = $product_overrides['override_mrp'];
			}
			if ( ! empty( $product_overrides['all_out_of_stock'] ) ) {
				$size_data['all_out_of_stock'] = true;
			}
			$size_name      = strtoupper( sanitize_text_field( (string) $size_data['size'] ) );
			$size_for_sku   = preg_replace( '/[^A-Z0-9\-_]/', '', str_replace( ' ', '-', $size_name ) );
			$explicit_sku   = sanitize_text_field( (string) ( $size_data['sku'] ?? '' ) );
			$variation_sku  = '' !== $explicit_sku ? $explicit_sku : ( $meesho_sku . '-' . $size_for_sku );
			$stock_quantity = isset( $size_data['stock'] ) && '' !== $size_data['stock'] ? max( 0, intval( $size_data['stock'] ) ) : null;
			$force_oos      = ! empty( $size_data['oos'] ) || ! empty( $size_data['out_of_stock'] );
			$all_oos        = ! empty( $size_data['all_out_of_stock'] );
			$is_available   = isset( $size_data['available'] ) ? (bool) $size_data['available'] : true;
			if ( null !== $stock_quantity && $stock_quantity <= 0 ) {
				$is_available = false;
			}
			$stock_status = ( $force_oos || $all_oos || ! $is_available ) ? 'outofstock' : 'instock';

			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $parent_id );
			$variation->set_sku( $variation_sku );
			$variation->set_stock_status( $stock_status );
			if ( null !== $stock_quantity ) {
				$variation->set_manage_stock( true );
				$variation->set_stock_quantity( $stock_quantity );
			} else {
				$variation->set_manage_stock( false );
			}
			$variation->set_attributes( array( 'size' => $size_name ) );

			// Pricing with markup (or manual override from product data)
			$meesho_price = floatval( $size_data['price'] ?? 0 );
			$mrp          = floatval( $size_data['mrp'] ?? 0 );
			$override_price = floatval( $size_data['override_price'] ?? 0 );
			$override_mrp   = floatval( $size_data['override_mrp'] ?? 0 );

			if ( $override_price > 0 ) {
				$selling_price = $override_price;
				$regular_price = $override_mrp > 0 ? $override_mrp : ( $mrp > $override_price ? $mrp : $override_price );
				$variation->set_regular_price( $regular_price );
				$variation->set_sale_price( $selling_price );
			} elseif ( $meesho_price > 0 ) {
				$selling_price = $this->settings()->calculate_selling_price( $meesho_price );
				$variation->set_regular_price( $mrp > $selling_price ? $mrp : $selling_price );
				$variation->set_sale_price( $selling_price );
			}

			$variation_id = $variation->save();
			if ( $variation_id ) {
				update_post_meta( $variation_id, 'attribute_size', $size_name );
			}
		}
	}

	/* ================================================================
	 *  Attach images
	 * ================================================================ */

	private function attach_images( $parent_id, $images ) {
		if ( ! function_exists( 'media_sideload_image' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$images = $this->sanitize_image_list( $images );
		if ( empty( $images ) ) {
			return array();
		}

		$attached = array();
		$gallery_ids = array();
		foreach ( $images as $i => $url ) {
			$attach_id = media_sideload_image( $url, $parent_id, '', 'id' );
			if ( ! is_wp_error( $attach_id ) ) {
				$attached[] = $attach_id;
				if ( $i === 0 ) {
					set_post_thumbnail( $parent_id, $attach_id );
				} else {
					$gallery_ids[] = $attach_id;
				}
			}
		}

		if ( ! empty( $gallery_ids ) ) {
			update_post_meta( $parent_id, '_product_image_gallery', implode( ',', $gallery_ids ) );
		}

		return $attached;
	}

	/* ================================================================
	 *  Import reviews — Fix 3: dates stored as dd/mm/yyyy
	 * ================================================================ */

	private function import_reviews( $meesho_sku, $reviews ) {
		global $wpdb;
		$table = MM_DB::table( 'reviews' );

		foreach ( $reviews as $review ) {
			// The native scraper outputs: reviewer_name, rating, comment, date, media (array of URLs)
			// Support both new keys (from native scraper) and legacy keys for backward compat
			$reviewer = $review['reviewer_name'] ?? $review['name'] ?? 'Customer';
			$text     = $review['comment'] ?? $review['text'] ?? $review['comments'] ?? '';
			$media    = $review['media'] ?? $review['images'] ?? array();
			// Sanitize reviewer name — strip "Meesho User" → "Customer"
			if ( preg_match( '/meesho\s*(user)?/i', $reviewer ) ) {
				$reviewer = 'Customer';
			}
			// Parse date: supports "2024-07-20 15:00:21" ISO and "DD Month YYYY" formats
			$review_date = $this->parse_meesho_date( $review['date'] ?? $review['created'] ?? '' );

			// Extract URLs from media array (objects have 'url' key)
			$media_urls = array();
			foreach ( (array) $media as $m ) {
				if ( is_string( $m ) && ! empty( $m ) ) {
					$media_urls[] = $m;
				} elseif ( is_array( $m ) && ! empty( $m['url'] ) ) {
					$media_urls[] = $m['url'];
				}
			}
			$media_urls = $this->sanitize_image_list( $media_urls );

			$wpdb->insert(
				$table,
				array(
					'meesho_sku'    => $meesho_sku,
					'reviewer_name' => sanitize_text_field( $reviewer ),
					'review_text'   => sanitize_textarea_field( $text ),
					'star_rating'   => intval( $review['rating'] ?? 5 ),
					'review_date'   => $review_date,
					'review_images' => wp_json_encode( array_values( array_filter( $media_urls ) ) ),
				),
				array( '%s', '%s', '%s', '%d', '%s', '%s' )
			);
		}
	}

	/**
	 * Parse Meesho-style "12 April 2024" to dd/mm/yyyy.
	 */
	private function parse_meesho_date( $date_str ) {
		if ( empty( $date_str ) ) {
			return date( 'd/m/Y' );
		}
		$ts = strtotime( $date_str );
		if ( $ts === false ) {
			return date( 'd/m/Y' );
		}
		return date( 'd/m/Y', $ts );
	}

	/* ================================================================
	 *  Description cleaning
	 * ================================================================ */

	private function clean_description( $html ) {
		if ( empty( $html ) ) {
			return '';
		}

		$html = html_entity_decode( (string) $html, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		// Strip Meesho brand references
		$html = preg_replace( '/(?:Buy\s+on\s+)?Meesho/i', '', $html );

		// Strip script/style tags early
		$html = preg_replace( '/<script[^>]*>.*?<\/script>/is', '', $html );
		$html = preg_replace( '/<style[^>]*>.*?<\/style>/is', '', $html );
		$html = preg_replace( '/<noscript[^>]*>.*?<\/noscript>/is', '', $html );
		$html = preg_replace( '/<!--.*?-->/s', '', $html );

		$html = $this->format_description_plain_text( $html );

		if ( class_exists( 'DOMDocument' ) ) {
			$flags = 0;
			if ( defined( 'LIBXML_HTML_NOIMPLIED' ) ) {
				$flags |= LIBXML_HTML_NOIMPLIED;
			}
			if ( defined( 'LIBXML_HTML_NODEFDTD' ) ) {
				$flags |= LIBXML_HTML_NODEFDTD;
			}
			libxml_use_internal_errors( true );
			$doc = new DOMDocument();
			$doc->loadHTML( '<?xml encoding="utf-8" ?>' . $html, $flags );
			libxml_clear_errors();

			$this->strip_unwanted_description_nodes( $doc );
			$this->replace_heading_level( $doc, 'h1', 'h2' );
			$this->normalize_description_blocks( $doc );
			$this->strip_disallowed_attributes( $doc );

			$html = $doc->saveHTML();
		}

		// Normalize spacing and remove empty tags
		$html = preg_replace( '/(<br\s*\/?>\s*){3,}/i', '<br><br>', $html );
		$html = preg_replace( '/<(p|span|div|b|i|strong|em|h2|h3|h4)\s*>\s*<\/\1>/i', '', $html );

		// Allowed tags for WooCommerce product description
		$html = wp_kses( $html, $this->description_allowed_tags() );

		return trim( $html );
	}

	private function format_description_plain_text( $text ) {
		if ( empty( $text ) || preg_match( '/<[^>]+>/', $text ) ) {
			return $text;
		}

		$text = trim( preg_replace( "/\r\n|\r/", "\n", (string) $text ) );
		if ( '' === $text ) {
			return '';
		}

		$blocks = preg_split( "/\n\s*\n/", $text );
		$out = array();
		foreach ( $blocks as $block ) {
			$block = trim( $block );
			if ( '' === $block ) {
				continue;
			}
			$lines = array_values( array_filter( array_map( 'trim', preg_split( "/\n+/", $block ) ) ) );
			if ( empty( $lines ) ) {
				continue;
			}
			$out[] = $this->format_description_lines( $lines );
		}

		return implode( "\n", array_filter( $out ) );
	}

	private function format_description_lines( array $lines ) {
		if ( empty( $lines ) ) {
			return '';
		}

		$heading = '';
		if ( count( $lines ) > 1 && $this->is_heading_line( $lines[0] ) ) {
			$heading = $this->normalize_heading_text( $lines[0] );
			$lines = array_slice( $lines, 1 );
		}

		$body = $this->render_description_block( $lines );
		if ( '' === $heading ) {
			return $body;
		}

		return '<h3>' . esc_html( $heading ) . '</h3>' . $body;
	}

	private function render_description_block( array $lines ) {
		if ( empty( $lines ) ) {
			return '';
		}

		if ( $this->all_image_lines( $lines ) ) {
			$imgs = array();
			foreach ( $lines as $line ) {
				$url = $this->extract_image_url( $line );
				if ( $url ) {
					$imgs[] = '<p><img src="' . esc_url( $url ) . '" alt="' . esc_attr( self::DEFAULT_IMAGE_ALT ) . '"></p>';
				}
			}
			return implode( '', $imgs );
		}

		if ( $this->all_key_value_lines( $lines ) ) {
			$rows = array();
			foreach ( $lines as $line ) {
				$pair = $this->split_key_value_line( $line );
				if ( ! $pair ) {
					continue;
				}
				$rows[] = '<tr><th>' . esc_html( $pair['key'] ) . '</th><td>' . esc_html( $pair['value'] ) . '</td></tr>';
			}
			return '<table><tbody>' . implode( '', $rows ) . '</tbody></table>';
		}

		$list_type = $this->detect_list_type( $lines );
		if ( $list_type ) {
			$items = array();
			foreach ( $lines as $line ) {
				$text = $this->strip_list_prefix( $line );
				if ( '' !== $text ) {
					$items[] = '<li>' . esc_html( $text ) . '</li>';
				}
			}
			return '<' . $list_type . '>' . implode( '', $items ) . '</' . $list_type . '>';
		}

		$body = esc_html( implode( "\n", $lines ) );
		$body = nl2br( $body, false );
		return '<p>' . $body . '</p>';
	}

	private function is_heading_line( $line ) {
		return (bool) preg_match( '/^[A-Za-z0-9][A-Za-z0-9\s\/\-&()]{0,60}:\s*$/', (string) $line );
	}

	private function normalize_heading_text( $line ) {
		return trim( rtrim( (string) $line, ":\t " ) );
	}

	private function all_image_lines( array $lines ) {
		foreach ( $lines as $line ) {
			if ( ! $this->extract_image_url( $line ) ) {
				return false;
			}
		}
		return true;
	}

	private function extract_image_url( $line ) {
		$line = trim( (string) $line );
		$url = $this->sanitize_image_src( $line );
		if ( '' === $url ) {
			return '';
		}
		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( empty( $path ) ) {
			return '';
		}
		$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, self::IMAGE_EXTENSIONS, true ) ) {
			return '';
		}
		return $url;
	}

	private function all_key_value_lines( array $lines ) {
		foreach ( $lines as $line ) {
			if ( ! $this->split_key_value_line( $line ) ) {
				return false;
			}
		}
		return true;
	}

	private function split_key_value_line( $line ) {
		if ( preg_match( '/^([^:]{1,40}):\s*(.+)$/', (string) $line, $match ) ) {
			return array(
				'key'   => trim( $match[1] ),
				'value' => trim( $match[2] ),
			);
		}
		return null;
	}

	private function detect_list_type( array $lines ) {
		$ordered = true;
		$unordered = true;
		foreach ( $lines as $line ) {
			if ( preg_match( '/^' . self::ORDERED_LIST_PREFIX_PATTERN . '/u', (string) $line ) ) {
				$unordered = false;
			} elseif ( preg_match( '/^' . self::UNORDERED_LIST_PREFIX_PATTERN . '/u', (string) $line ) ) {
				$ordered = false;
			} else {
				return '';
			}
		}
		if ( $ordered ) {
			return 'ol';
		}
		if ( $unordered ) {
			return 'ul';
		}
		return '';
	}

	private function strip_list_prefix( $line ) {
		if ( preg_match( '/^' . self::ORDERED_LIST_PREFIX_PATTERN . '(.*)$/u', (string) $line, $match ) ) {
			return trim( $match[1] );
		}
		if ( preg_match( '/^' . self::UNORDERED_LIST_PREFIX_PATTERN . '(.*)$/u', (string) $line, $match ) ) {
			return trim( $match[1] );
		}
		return trim( (string) $line );
	}

	private function description_allowed_tags() {
		return array(
			'p'      => array(),
			'br'     => array(),
			'ul'     => array(),
			'ol'     => array(),
			'li'     => array(),
			'strong' => array(),
			'em'     => array(),
			'b'      => array(),
			'i'      => array(),
			'h2'     => array(),
			'h3'     => array(),
			'h4'     => array(),
			'table'  => array(),
			'thead'  => array(),
			'tbody'  => array(),
			'tr'     => array(),
			'th'     => array(
				'colspan' => true,
				'rowspan' => true,
				'scope'   => true,
			),
			'td'     => array(
				'colspan' => true,
				'rowspan' => true,
			),
			'img'    => array(
				'src'   => true,
				'alt'   => true,
				'title' => true,
				'width' => true,
				'height' => true,
			),
			'a'      => array(
				'href'   => true,
				'title'  => true,
				'target' => true,
				'rel'    => true,
			),
		);
	}

	private function strip_unwanted_description_nodes( DOMDocument $doc ) {
		foreach ( self::DESCRIPTION_STRIP_TAGS as $tag ) {
			$nodes = $doc->getElementsByTagName( $tag );
			for ( $i = $nodes->length - 1; $i >= 0; $i-- ) {
				$node = $nodes->item( $i );
				if ( $node && $node->parentNode ) {
					$node->parentNode->removeChild( $node );
				}
			}
		}
	}

	private function normalize_description_blocks( DOMDocument $doc ) {
		$divs = $doc->getElementsByTagName( 'div' );
		for ( $i = $divs->length - 1; $i >= 0; $i-- ) {
			$div = $divs->item( $i );
			if ( ! $div || ! $div->parentNode ) {
				continue;
			}
			$has_block_child = false;
			foreach ( $div->childNodes as $child ) {
				if ( XML_ELEMENT_NODE === $child->nodeType && in_array( strtolower( $child->nodeName ), self::DESCRIPTION_BLOCK_TAGS, true ) ) {
					$has_block_child = true;
					break;
				}
			}
			if ( $has_block_child ) {
				$this->unwrap_node( $div );
				continue;
			}
			$this->replace_node_tag( $div, 'p' );
		}

		$spans = $doc->getElementsByTagName( 'span' );
		for ( $i = $spans->length - 1; $i >= 0; $i-- ) {
			$span = $spans->item( $i );
			if ( $span && $span->parentNode ) {
				$this->unwrap_node( $span );
			}
		}
	}

	private function replace_heading_level( DOMDocument $doc, $from_tag, $to_tag ) {
		$headings = $doc->getElementsByTagName( $from_tag );
		for ( $i = $headings->length - 1; $i >= 0; $i-- ) {
			$heading = $headings->item( $i );
			if ( ! $heading || ! $heading->parentNode ) {
				continue;
			}
			$this->replace_node_tag( $heading, $to_tag );
		}
	}

	private function replace_node_tag( DOMNode $node, $new_tag ) {
		if ( ! $node->parentNode ) {
			return;
		}
		$doc = $node->ownerDocument;
		$replacement = $doc->createElement( $new_tag );
		while ( $node->firstChild ) {
			$replacement->appendChild( $node->firstChild );
		}
		$node->parentNode->replaceChild( $replacement, $node );
	}

	private function unwrap_node( DOMNode $node ) {
		if ( ! $node->parentNode ) {
			return;
		}
		while ( $node->firstChild ) {
			$node->parentNode->insertBefore( $node->firstChild, $node );
		}
		$node->parentNode->removeChild( $node );
	}

	private function strip_disallowed_attributes( DOMDocument $doc ) {
		$allowed = $this->description_allowed_tags();
		$xpath = new DOMXPath( $doc );
		foreach ( $xpath->query( '//*' ) as $node ) {
			if ( ! $node instanceof DOMElement ) {
				continue;
			}
			$tag = strtolower( $node->nodeName );
			$allowed_attrs = isset( $allowed[ $tag ] ) ? array_keys( $allowed[ $tag ] ) : array();
			if ( 'img' === $tag ) {
				$src = $this->sanitize_image_src( $node->getAttribute( 'src' ) );
				if ( '' !== $src ) {
					$node->setAttribute( 'src', $src );
				} else {
					$node->removeAttribute( 'src' );
					foreach ( self::IMAGE_FALLBACK_ATTRS as $fallback ) {
						$fallback_value = $this->sanitize_image_src( $node->getAttribute( $fallback ) );
						if ( '' !== $fallback_value ) {
							$node->setAttribute( 'src', $fallback_value );
							break;
						}
					}
				}
				foreach ( self::IMAGE_FALLBACK_ATTRS as $fallback ) {
					if ( $node->hasAttribute( $fallback ) ) {
						$node->removeAttribute( $fallback );
					}
				}
				if ( '' === trim( $node->getAttribute( 'src' ) ) ) {
					if ( $node->parentNode ) {
						$node->parentNode->removeChild( $node );
					}
					continue;
				}
				if ( '' === trim( $node->getAttribute( 'alt' ) ) ) {
					$node->setAttribute( 'alt', self::DEFAULT_IMAGE_ALT );
				}
			}
			if ( $node->hasAttributes() ) {
				$attrs = array();
				foreach ( $node->attributes as $attr ) {
					$attrs[] = $attr->nodeName;
				}
				foreach ( $attrs as $attr_name ) {
					if ( ! in_array( strtolower( $attr_name ), $allowed_attrs, true ) ) {
						$node->removeAttribute( $attr_name );
					}
				}
			}
			$target = '';
			if ( $node->hasAttribute( 'target' ) ) {
				$target = strtolower( $node->getAttribute( 'target' ) );
			}
			if ( 'a' === $tag && '_blank' === $target ) {
				$rel = trim( $node->getAttribute( 'rel' ) );
				$add_rel = array();
				if ( ! preg_match( '/\\bnoopener\\b/i', $rel ) ) {
					$add_rel[] = 'noopener';
				}
				if ( ! preg_match( '/\\bnoreferrer\\b/i', $rel ) ) {
					$add_rel[] = 'noreferrer';
				}
				if ( ! empty( $add_rel ) ) {
					$node->setAttribute( 'rel', trim( $rel . ' ' . implode( ' ', $add_rel ) ) );
				}
			}
		}
	}

	// Validate image URLs (format + allowed protocols) and normalize to a safe URL string.
	private function sanitize_image_src( $src ) {
		$src = trim( (string) $src );
		if ( '' === $src ) {
			return '';
		}
		$sanitized_url = esc_url_raw( $src );
		if ( '' === $sanitized_url ) {
			return '';
		}
		$validated_url = wp_http_validate_url( $sanitized_url );
		if ( ! $validated_url ) {
			return '';
		}
		return $validated_url;
	}

	private function sanitize_image_list( $images ) {
		$clean = array();
		$seen = array();
		foreach ( (array) $images as $image ) {
			if ( ! is_string( $image ) ) {
				continue;
			}
			$url = $this->sanitize_image_src( $image );
			if ( '' === $url || isset( $seen[ $url ] ) ) {
				continue;
			}
			$seen[ $url ] = true;
			$clean[] = $url;
		}
		return $clean;
	}

	/* ================================================================
	 *  v6.2 — Staging workflow
	 * ================================================================ */

	/**
	 * Stage a scraped product. Saves the scraped data to mm_products with
	 * status='staged' so the user can review/edit before pushing to WC.
	 */
	private function stage_product( $data ) {
		global $wpdb;

		// Auto-repair: ensure all DB tables exist before attempting any insert.
		// This fixes "Table doesn't exist" errors on fresh installs or partial upgrades.
		if ( ! MM_DB::table_exists( 'products' ) ) {
			MM_DB::install();
		}

		// Resolve SKU (image-derived → URL-derived → manual override)
		if ( ! empty( $data['meesho_sku_override'] ) ) {
			$meesho_sku = $data['meesho_sku_override'];
		} elseif ( ! empty( $data['meesho_sku'] ) ) {
			$meesho_sku = $data['meesho_sku'];
		} else {
			$meesho_sku = $this->extract_sku( $data['image_url'] ?? '', $data['meesho_url'] ?? '' );
		}

		if ( empty( $meesho_sku ) ) {
			throw new Exception( 'Could not extract Meesho SKU. Please enter it manually.' );
		}

		// Hard duplicate check
		$dupe = $this->check_duplicate( $meesho_sku );
		if ( $dupe ) {
			throw new Exception( 'SKU ' . $meesho_sku . ' already exists. Delete from Products tab first to re-scrape.' );
		}

		$table = MM_DB::table( 'products' );
		$wpdb->insert(
			$table,
			array(
				'meesho_sku'    => $meesho_sku,
				'wc_product_id' => 0,
				'meesho_url'    => $data['meesho_url'] ?? '',
				'title'         => $data['title'] ?? '',
				'scraped_data'  => wp_json_encode( $data ),
				'import_date'   => current_time( 'mysql' ),
				'status'        => 'staged',
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);
		$staged_id = (int) $wpdb->insert_id;

		if ( ! $staged_id ) {
			throw new Exception(
				'Database insert failed. ' .
				( $wpdb->last_error ? 'MySQL: ' . $wpdb->last_error : 'No specific error.' ) .
				' Try deactivating + reactivating the plugin to run the schema migration.'
			);
		}

		// E1: Log staging to audit_log (not the products table)
		( new MM_Undo() )->log_before_change(
			'product_import',
			'product',
			$staged_id,
			null,
			wp_json_encode( $data ),
			0,
			'manual',
			'Staged: ' . $meesho_sku,
			0
		);

		return array(
			'status'      => 'staged',
			'staged_id'   => $staged_id,
			'sku'         => $meesho_sku,
			'title'       => $data['title'] ?? '',
			'image'       => $data['images'][0] ?? '',
			'price'       => $data['price'] ?? 0,
			'images_count' => is_array( $data['images'] ?? null ) ? count( $data['images'] ) : 0,
			'sizes_count' => is_array( $data['sizes'] ?? null ) ? count( $data['sizes'] ) : 0,
			'reviews_count' => is_array( $data['reviews'] ?? null ) ? count( $data['reviews'] ) : 0,
			'message'     => 'Product staged. Review it in the Products tab and push to WooCommerce when ready.',
			'source'      => $data['source'] ?? 'unknown',
		);
	}

	/**
	 * AJAX — list all staged + published products for the Products tab.
	 */
	public function ajax_list_staged() {
		meesho_master_verify_ajax_nonce();
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}
		global $wpdb;
		$table = MM_DB::table( 'products' );
		$rows  = $wpdb->get_results( "SELECT id, meesho_sku, wc_product_id, meesho_url, title, scraped_data, import_date, status FROM {$table} ORDER BY import_date DESC LIMIT 200" );
		$settings = new Meesho_Master_Settings();
		$out = array();
		foreach ( (array) $rows as $r ) {
			$data = json_decode( $r->scraped_data, true );
			// Sizes can be strings or {size,...} objects — normalize to label list
			$size_labels = array();
			$variation_rows = array();
			if ( is_array( $data['sizes'] ?? null ) ) {
				foreach ( $data['sizes'] as $s ) {
					if ( is_string( $s ) && trim( $s ) !== '' ) {
						$sz = strtoupper( trim( $s ) );
						$size_labels[] = $sz;
						$variation_rows[] = array(
							'size'      => $sz,
							'sku'       => $r->meesho_sku . '-' . preg_replace( '/[^A-Z0-9\-_]/', '', str_replace( ' ', '-', $sz ) ),
							'stock'     => '',
							'available' => true,
							'oos'       => false,
						);
					} elseif ( is_array( $s ) && ! empty( $s['size'] ) ) {
						$sz = strtoupper( $s['size'] );
						$size_labels[] = $sz;
						$variation_rows[] = array(
							'size'      => $sz,
							'sku'       => ! empty( $s['sku'] ) ? (string) $s['sku'] : ( $r->meesho_sku . '-' . preg_replace( '/[^A-Z0-9\-_]/', '', str_replace( ' ', '-', $sz ) ) ),
							'stock'     => isset( $s['stock'] ) ? $s['stock'] : '',
							'available' => isset( $s['available'] ) ? (bool) $s['available'] : true,
							'oos'       => ! empty( $s['oos'] ) || ! empty( $s['out_of_stock'] ),
						);
					}
				}
			}
			// Light-weight previews so the Recently-Scraped UI can render
			// reviews + image strip without another round-trip.
			$reviews_preview = array();
			if ( is_array( $data['reviews'] ?? null ) ) {
				foreach ( array_slice( $data['reviews'], 0, 3 ) as $rev ) {
					$reviews_preview[] = array(
						'reviewer_name' => $rev['reviewer_name'] ?? 'Customer',
						'rating'        => (int) ( $rev['rating'] ?? 5 ),
						'comment'       => $rev['comment'] ?? '',
						'date'          => $rev['date'] ?? '',
					);
				}
			}
			// E4: Calculate our_price using markup rules (override takes precedence)
			$raw_price    = (float) ( $data['price'] ?? 0 );
			$override_price = (float) ( $data['override_price'] ?? 0 );
			$our_price    = $override_price > 0 ? $override_price : $settings->calculate_selling_price( $raw_price );
			$out[] = array(
				'id'             => (int) $r->id,
				'sku'            => $r->meesho_sku,
				'title'          => $r->title,
				'meesho_url'     => $r->meesho_url,
				'wc_product_id'  => (int) $r->wc_product_id,
				'wc_edit_url'    => $r->wc_product_id ? admin_url( 'post.php?post=' . $r->wc_product_id . '&action=edit' ) : '',
				'wc_listing_url' => $r->wc_product_id ? admin_url( 'post.php?post=' . $r->wc_product_id . '&action=edit' ) : '',
				'wc_live_url'    => $r->wc_product_id ? get_permalink( (int) $r->wc_product_id ) : '',
				'image'          => $data['images'][0] ?? '',
				'images_preview' => is_array( $data['images'] ?? null ) ? array_slice( $data['images'], 0, 4 ) : array(),
				'images_count'   => is_array( $data['images'] ?? null ) ? count( $data['images'] ) : 0,
				'sizes'          => $size_labels,
				'variation_rows' => $variation_rows,
				'price'          => $raw_price,
				'mrp'            => $data['mrp'] ?? 0,
				'override_price' => $override_price,
				'override_mrp'   => $data['override_mrp'] ?? 0,
				'our_price'      => $our_price,
				'review_count'   => $data['review_count'] ?? 0,
				'avg_rating'     => $data['avg_rating'] ?? 0,
				'reviews_preview' => $reviews_preview,
				'import_date'    => $r->import_date,
				'status'         => $r->status,
			);
		}
		wp_send_json_success( $out );
	}

	/**
	 * AJAX — get full data for a single staged product (for the editor modal).
	 */
	public function ajax_get_staged() {
		meesho_master_verify_ajax_nonce();
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}
		$id = (int) ( $_POST['id'] ?? 0 );
		if ( ! $id ) {
			wp_send_json_error( 'Missing id.' );
		}
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . MM_DB::table( 'products' ) . ' WHERE id = %d',
			$id
		) );
		if ( ! $row ) {
			wp_send_json_error( 'Not found.' );
		}
		$data = json_decode( $row->scraped_data, true );
		$settings = new Meesho_Master_Settings();
		$raw_price = (float) ( is_array( $data ) ? ( $data['price'] ?? 0 ) : 0 );
		$override_price = (float) ( is_array( $data ) ? ( $data['override_price'] ?? 0 ) : 0 );
		$our_price = $override_price > 0 ? $override_price : $settings->calculate_selling_price( $raw_price );
		wp_send_json_success( array(
			'id'         => (int) $row->id,
			'sku'        => $row->meesho_sku,
			'meesho_url' => $row->meesho_url,
			'status'     => $row->status,
			'wc_product_id' => (int) $row->wc_product_id,
			'our_price'  => $our_price,
			'data'       => is_array( $data ) ? $data : array(),
		) );
	}

	/**
	 * AJAX — save edits to a staged product's scraped_data JSON.
	 */
	public function ajax_save_staged() {
		meesho_master_verify_ajax_nonce();
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}
		$id = (int) ( $_POST['id'] ?? 0 );
		if ( ! $id ) {
			wp_send_json_error( 'Missing id.' );
		}
		$fields = isset( $_POST['fields'] ) ? json_decode( wp_unslash( $_POST['fields'] ), true ) : array();
		if ( ! is_array( $fields ) ) {
			wp_send_json_error( 'Invalid fields.' );
		}

		global $wpdb;
		$table = MM_DB::table( 'products' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT scraped_data, title FROM {$table} WHERE id = %d", $id ) );
		if ( ! $row ) {
			wp_send_json_error( 'Not found.' );
		}
		$data = json_decode( $row->scraped_data, true );
		if ( ! is_array( $data ) ) {
			$data = array();
		}
		// Whitelisted editable fields
		$allowed = array( 'title', 'description', 'price', 'mrp', 'brand', 'images', 'sizes', 'reviews', 'override_price', 'override_mrp', 'all_out_of_stock' );
		foreach ( $allowed as $k ) {
			if ( array_key_exists( $k, $fields ) ) {
				$data[ $k ] = $fields[ $k ];
			}
		}
		if ( array_key_exists( 'images', $data ) ) {
			$data['images'] = $this->sanitize_image_list( $data['images'] );
		}
		$wpdb->update(
			$table,
			array(
				'title'        => $data['title'] ?? $row->title,
				'scraped_data' => wp_json_encode( $data ),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		wp_send_json_success( array( 'saved' => true ) );
	}

	/**
	 * AJAX — push a staged product into WooCommerce.
	 */
	public function ajax_push_to_wc() {
		meesho_master_verify_ajax_nonce();
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}
		$id = (int) ( $_POST['id'] ?? 0 );
		if ( ! $id ) {
			wp_send_json_error( 'Missing id.' );
		}
		global $wpdb;
		$table = MM_DB::table( 'products' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
		if ( ! $row ) {
			wp_send_json_error( 'Not found.' );
		}
		if ( 'published' === $row->status && $row->wc_product_id ) {
			wp_send_json_error( 'Already pushed to WooCommerce (#' . $row->wc_product_id . ').' );
		}
		$data = json_decode( $row->scraped_data, true );
		if ( ! is_array( $data ) ) {
			wp_send_json_error( 'Corrupt staged data.' );
		}
		$data['meesho_url'] = $row->meesho_url;
		$data['meesho_sku_override'] = $row->meesho_sku;
		$data['_staged_row_id'] = $id;

		try {
			// process_import handles all the WC heavy lifting (parent + variations + images + reviews)
			$result = $this->process_import( $data );
			if ( isset( $result['status'] ) && 'duplicate' === $result['status'] ) {
				wp_send_json_error( $result['message'] ?? 'Duplicate product exists.' );
			}
			if ( ! empty( $result['product_id'] ) ) {
				$wpdb->update(
					$table,
					array( 'wc_product_id' => (int) $result['product_id'], 'status' => 'published' ),
					array( 'id' => $id ),
					array( '%d', '%s' ),
					array( '%d' )
				);
			}
			wp_send_json_success( $result );
		} catch ( Exception $e ) {
			wp_send_json_error( $e->getMessage() );
		}
	}

	/**
	 * AJAX — delete a staged product. If already published to WC, the WC
	 * product is moved to trash so a re-scrape is possible.
	 */
	public function ajax_delete_staged() {
		meesho_master_verify_ajax_nonce();
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}
		$id = (int) ( $_POST['id'] ?? 0 );
		$delete_wc = ! empty( $_POST['delete_wc'] );
		if ( ! $id ) {
			wp_send_json_error( 'Missing id.' );
		}
		global $wpdb;
		$table = MM_DB::table( 'products' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
		if ( ! $row ) {
			wp_send_json_error( 'Not found.' );
		}
		if ( $delete_wc && $row->wc_product_id ) {
			wp_trash_post( (int) $row->wc_product_id );
		}
		// Always remove reviews tied to this SKU so a re-scrape is clean.
		$wpdb->delete( MM_DB::table( 'reviews' ), array( 'meesho_sku' => $row->meesho_sku ), array( '%s' ) );
		$wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
		wp_send_json_success( array( 'deleted' => true, 'wc_trashed' => $delete_wc ) );
	}

	/**
	 * AJAX — explicit duplicate check (called from JS before submitting).
	 */
	public function ajax_check_duplicate() {
		meesho_master_verify_ajax_nonce();
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}
		$sku = isset( $_POST['sku'] ) ? sanitize_text_field( $_POST['sku'] ) : '';
		if ( '' === $sku ) {
			wp_send_json_error( 'Missing SKU.' );
		}
		$existing = $this->check_duplicate( $sku );
		wp_send_json_success( array(
			'duplicate'  => (bool) $existing,
			'product_id' => $existing,
		) );
	}

	/* ================================================================
	 *  v6.2 — Description optimizer (OpenRouter)
	 * ================================================================ */

	/**
	 * Static so the UI can render the same labels without instantiating.
	 */
	public static function optimizer_presets() {
		return array(
			'seo_friendly' => array(
				'label'  => '🔍 SEO-Friendly',
				'prompt' => "Rewrite this product description to be SEO-friendly. Keep all factual details (size, fabric, care, color) but improve readability, use natural keywords related to the title, add a short intro paragraph and a bulleted features list. Return clean HTML.\n\nTitle: {TITLE}\n\nOriginal description:\n{DESCRIPTION}",
			),
			'concise' => array(
				'label'  => '✂️ Concise & Punchy',
				'prompt' => "Rewrite this product description to be concise and punchy — under 120 words. Keep only the most important details. Use short sentences. Return clean HTML.\n\nTitle: {TITLE}\n\nOriginal:\n{DESCRIPTION}",
			),
			'storytelling' => array(
				'label'  => '📖 Storytelling',
				'prompt' => "Rewrite this product description in a warm, storytelling tone — paint a picture of who this product is for and how it makes them feel. Keep facts accurate. Return clean HTML.\n\nTitle: {TITLE}\n\nOriginal:\n{DESCRIPTION}",
			),
			'features_benefits' => array(
				'label'  => '⭐ Features & Benefits',
				'prompt' => "Rewrite this product description as a structured Features-and-Benefits list. Each bullet should pair a feature with the benefit to the buyer. Add a one-line opening hook. Return clean HTML.\n\nTitle: {TITLE}\n\nOriginal:\n{DESCRIPTION}",
			),
			'aeo_optimized' => array(
				'label'  => '❓ AEO (Answer Engine Optimized)',
				'prompt' => "Rewrite this description for Answer Engine Optimization. Include a short Q&A section answering likely buyer questions (sizing, fabric care, occasion fit). Keep the tone natural. Return clean HTML.\n\nTitle: {TITLE}\n\nOriginal:\n{DESCRIPTION}",
			),
			'geo_local_in' => array(
				'label'  => '🇮🇳 GEO (India local)',
				'prompt' => "Rewrite this product description with India-specific buyer context. Reference common Indian occasions (festive, casual, college, office) and Indian sizing conventions where helpful. Keep facts accurate. Return clean HTML.\n\nTitle: {TITLE}\n\nOriginal:\n{DESCRIPTION}",
			),
		);
	}

	public function ajax_optimize_description() {
		meesho_master_verify_ajax_nonce();
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}
		$desc   = isset( $_POST['description'] ) ? wp_kses_post( wp_unslash( $_POST['description'] ) ) : '';
		$title  = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$preset = isset( $_POST['preset'] ) ? sanitize_key( $_POST['preset'] ) : 'seo_friendly';
		$custom = isset( $_POST['custom_prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['custom_prompt'] ) ) : '';

		if ( empty( $desc ) && empty( $title ) ) {
			wp_send_json_error( 'Need title or description to optimize.' );
		}

		if ( ! empty( $custom ) ) {
			$prompt = $custom . "\n\nTitle: {$title}\n\nOriginal description:\n" . wp_strip_all_tags( $desc );
		} else {
			$presets = self::optimizer_presets();
			$prompt_template = $presets[ $preset ]['prompt'] ?? $presets['seo_friendly']['prompt'];
			$prompt = str_replace(
				array( '{TITLE}', '{DESCRIPTION}' ),
				array( $title, wp_strip_all_tags( $desc ) ),
				$prompt_template
			);
		}

		$settings = $this->settings();
		$api_key  = $settings->get( 'openrouter_api_key' );
		if ( empty( $api_key ) ) {
			$api_key = $settings->get( 'mm_openrouter_key' );
		}
		$model = $settings->get( 'mm_openrouter_model_seo' );
		if ( empty( $model ) ) {
			$model = 'openai/gpt-4o-mini';
		}

		if ( empty( $api_key ) ) {
			wp_send_json_error( 'OpenRouter API key not configured. Set it in Settings.' );
		}

		$response = wp_remote_post(
			'https://openrouter.ai/api/v1/chat/completions',
			array(
				'timeout' => 60,
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
					'HTTP-Referer'  => home_url(),
					'X-Title'       => 'Meesho Master',
				),
				'body'    => wp_json_encode( array(
					'model'    => $model,
					'messages' => array(
						array(
							'role'    => 'system',
							'content' => $settings->get( 'mm_prompt_description_master' ) ?: 'You are an expert ecommerce copywriter. Output ONLY the rewritten product description as clean HTML, no preamble.',
						),
						array( 'role' => 'user', 'content' => $prompt ),
					),
				) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( $response->get_error_message() );
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$out  = $body['choices'][0]['message']['content'] ?? '';
		if ( empty( $out ) ) {
			wp_send_json_error( 'AI returned an empty response: ' . wp_remote_retrieve_body( $response ) );
		}
		wp_send_json_success( array( 'description' => $out, 'preset' => $preset, 'model' => $model ) );
	}

	/**
	 * AJAX — generate a fresh product title from the existing one. Uses the
	 * description-master prompt as the system message (consistent voice).
	 * v6.5
	 */
	public function ajax_generate_title() {
		meesho_master_verify_ajax_nonce();
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
		}
		$current = sanitize_text_field( wp_unslash( $_POST['current_title'] ?? '' ) );
		$sku     = sanitize_text_field( wp_unslash( $_POST['sku'] ?? '' ) );
		if ( empty( $current ) ) {
			wp_send_json_error( array( 'message' => 'No current title to rewrite.' ) );
		}

		$settings = $this->settings();
		$api_key  = $settings->get( 'mm_openrouter_key' ) ?: $settings->get( 'openrouter_api_key' );
		if ( empty( $api_key ) ) {
			wp_send_json_error( array( 'message' => 'OpenRouter API key not set in Settings.' ) );
		}
		$model = $settings->get( 'mm_openrouter_model_seo' ) ?: $settings->get( 'mm_openrouter_model_blog' ) ?: 'openai/gpt-4o-mini';
		$system = $settings->get( 'mm_prompt_description_master' ) ?: 'You are an expert ecommerce copywriter. Output ONLY a single new product title — no quotes, no preamble.';
		$user_msg = "Rewrite this product title to be SEO-friendly, under 70 characters, and customer-appealing. Keep it specific to the product. NEVER mention 'Meesho' or any other marketplace name.\n\nCurrent title: {$current}\n\nReturn ONE new title, no quotes, no explanation.";

		$res = wp_remote_post( 'https://openrouter.ai/api/v1/chat/completions', array(
			'timeout' => 45,
			'headers' => array(
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type'  => 'application/json',
				'HTTP-Referer'  => home_url(),
				'X-Title'       => 'Meesho Master',
			),
			'body' => wp_json_encode( array(
				'model'    => $model,
				'messages' => array(
					array( 'role' => 'system', 'content' => $system ),
					array( 'role' => 'user',   'content' => $user_msg ),
				),
			) ),
		) );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}
		$body  = json_decode( wp_remote_retrieve_body( $res ), true );
		$title = trim( (string) ( $body['choices'][0]['message']['content'] ?? '' ) );
		// Strip leading/trailing quote chars and any "Meesho" leak
		$title = trim( $title, " \t\n\r\0\x0B\"'" );
		$title = preg_replace( '/\bmeesho\b/i', '', $title );
		$title = trim( preg_replace( '/\s{2,}/', ' ', $title ) );
		if ( empty( $title ) ) {
			wp_send_json_error( array( 'message' => 'Model returned empty title.' ) );
		}
		wp_send_json_success( array( 'title' => $title, 'model' => $model ) );
	}

	/**
	 * AJAX — fetch the live OpenRouter model list, cached for 12h.
	 */
	public function ajax_fetch_openrouter_models() {
		meesho_master_verify_ajax_nonce();
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}
		$force = ! empty( $_POST['force'] );
		$cache_key = 'mm_openrouter_models_v1';
		if ( ! $force ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) && ! empty( $cached ) ) {
				$settings    = new Meesho_Master_Settings();
				$assignments = array(
					'seo'     => $settings->get( 'mm_openrouter_model_seo',     '' ),
					'blog'    => $settings->get( 'mm_openrouter_model_blog',    '' ),
					'image'   => $settings->get( 'mm_openrouter_model_image',   '' ),
					'copilot' => $settings->get( 'mm_openrouter_model_copilot', '' ),
				);
				wp_send_json_success( array( 'models' => $cached, 'from_cache' => true, 'assignments' => $assignments ) );
			}
		}
		$res = wp_remote_get( 'https://openrouter.ai/api/v1/models', array( 'timeout' => 30 ) );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( $res->get_error_message() );
		}
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( empty( $body['data'] ) || ! is_array( $body['data'] ) ) {
			wp_send_json_error( 'Bad response from OpenRouter.' );
		}
		$models = array();
		foreach ( $body['data'] as $m ) {
			$id = $m['id'] ?? '';
			if ( ! $id ) {
				continue;
			}
			$prompt_price = isset( $m['pricing']['prompt'] ) ? (float) $m['pricing']['prompt'] : 0.0;
			$is_free = ( false !== strpos( $id, ':free' ) ) || ( 0.0 === $prompt_price );
			$models[] = array(
				'id'      => $id,
				'name'    => $m['name'] ?? $id,
				'context' => (int) ( $m['context_length'] ?? 0 ),
				'is_free' => $is_free,
				'prompt_price' => $prompt_price,
			);
		}
		// Sort: free first, then alphabetical
		usort( $models, function ( $a, $b ) {
			if ( $a['is_free'] !== $b['is_free'] ) {
				return $a['is_free'] ? -1 : 1;
			}
			return strcmp( $a['id'], $b['id'] );
		} );
		set_transient( $cache_key, $models, 12 * HOUR_IN_SECONDS );
		$settings    = new Meesho_Master_Settings();
		$assignments = array(
			'seo'     => $settings->get( 'mm_openrouter_model_seo',     '' ),
			'blog'    => $settings->get( 'mm_openrouter_model_blog',    '' ),
			'image'   => $settings->get( 'mm_openrouter_model_image',   '' ),
			'copilot' => $settings->get( 'mm_openrouter_model_copilot', '' ),
		);
		wp_send_json_success( array( 'models' => $models, 'from_cache' => false, 'assignments' => $assignments ) );
	}
}
