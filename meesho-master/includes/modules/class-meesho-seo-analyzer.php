<?php
/**
 * Meesho Master SEO Analyzer
 * AI analysis via OpenRouter, scoring engine, suggestion storage,
 * schema generator, citability optimizer.
 * All dates dd/mm/yyyy.
 */

class Meesho_Master_SEO_Analyzer {

	private $settings;

	private function settings() {
		if ( ! $this->settings ) $this->settings = new Meesho_Master_Settings();
		return $this->settings;
	}

	/* ---- Scoring Engine ---- */

	public function calculate_scores( $page_data ) {
		if ( ! $page_data ) return array( 'seo' => 0, 'aeo' => 0, 'geo' => 0 );

		$post_id = $page_data['post_id'] ?? 0;

		// Check wp_cache first to avoid recomputing on same request
		$cache_key = 'meesho_scores_' . $post_id;
		$cached = wp_cache_get( $cache_key, 'meesho_master' );
		if ( $cached !== false ) return $cached;

		$seo = $this->score_seo( $page_data );
		$aeo = $this->score_aeo( $page_data );
		$geo = $this->score_geo( $page_data );

		$scores = array( 'seo' => $seo, 'aeo' => $aeo, 'geo' => $geo );

		// Persist to post meta for dashboard display
		if ( $post_id ) {
			update_post_meta( $post_id, '_meesho_seo_score', $seo );
			update_post_meta( $post_id, '_meesho_aeo_score', $aeo );
			update_post_meta( $post_id, '_meesho_geo_score', $geo );
		}

		// Store in object cache for this request
		wp_cache_set( $cache_key, $scores, 'meesho_master', 3600 );

		return $scores;
	}

	private function score_seo( $d ) {
		$score = 0;
		$mt = $d['meta_title'] ?? '';
		$md = $d['meta_desc'] ?? '';

		// Meta title optimized (20 pts)
		if ( ! empty( $mt ) ) {
			$len = strlen( $mt );
			if ( $len >= 50 && $len <= 60 ) $score += 20;
			elseif ( $len >= 30 && $len <= 70 ) $score += 12;
			else $score += 5;
		}

		// Heading structure (15 pts)
		$headings = $d['headings'] ?? array();
		$h1_count = count( array_filter( $headings, function($h){ return $h['level'] === 'H1'; } ) );
		if ( $h1_count === 1 ) $score += 10;
		if ( count( $headings ) >= 3 ) $score += 5;

		// Keyword usage (15 pts) — check title words in content
		$title_words = array_filter( explode( ' ', strtolower( $d['title'] ?? '' ) ), function($w){ return strlen($w) > 3; } );
		$content_lower = strtolower( $d['content'] ?? '' );
		$found = 0;
		foreach ( $title_words as $tw ) {
			if ( strpos( $content_lower, $tw ) !== false ) $found++;
		}
		if ( count( $title_words ) > 0 ) {
			$score += min( 15, intval( ( $found / count( $title_words ) ) * 15 ) );
		}

		// Internal links (10 pts)
		$link_count = count( $d['internal_links'] ?? array() );
		if ( $link_count >= 2 ) $score += 10;
		elseif ( $link_count >= 1 ) $score += 5;

		// Content quality/length (20 pts)
		$wc = $d['word_count'] ?? 0;
		if ( $wc >= 1000 ) $score += 20;
		elseif ( $wc >= 500 ) $score += 14;
		elseif ( $wc >= 300 ) $score += 8;
		elseif ( $wc >= 100 ) $score += 4;

		// Technical (20 pts)
		if ( ! empty( $d['schema'] ) ) $score += 7;
		if ( ( $d['missing_alts'] ?? 0 ) === 0 ) $score += 7;
		if ( ! empty( $md ) && strlen( $md ) >= 100 ) $score += 6;

		return min( 100, $score );
	}

	private function score_aeo( $d ) {
		$score = 0;
		$content = strip_tags( $d['content'] ?? '' );

		// Direct answer present (30 pts) — first 300 chars contain a declarative sentence
		$first_300 = substr( $content, 0, 300 );
		if ( preg_match( '/[A-Z][^.!?]{20,}\./s', $first_300 ) ) $score += 30;
		elseif ( strlen( $first_300 ) > 50 ) $score += 15;

		// FAQ section (20 pts)
		$has_faq = false;
		foreach ( $d['headings'] ?? array() as $h ) {
			if ( preg_match( '/\?|FAQ|frequently/i', $h['text'] ) ) { $has_faq = true; break; }
		}
		if ( $has_faq ) $score += 20;

		// Clarity (20 pts) — average sentence length
		$sentences = preg_split( '/[.!?]+/', $content, -1, PREG_SPLIT_NO_EMPTY );
		if ( count( $sentences ) > 0 ) {
			$avg_words = array_sum( array_map( 'str_word_count', $sentences ) ) / count( $sentences );
			if ( $avg_words <= 20 ) $score += 20;
			elseif ( $avg_words <= 30 ) $score += 12;
			else $score += 5;
		}

		// Structured format (15 pts) — lists, tables
		if ( preg_match( '/<(ul|ol|table)/i', $d['content'] ?? '' ) ) $score += 15;

		// Snippet-ready (15 pts) — short paragraphs near top
		if ( preg_match( '/<p>[^<]{50,200}<\/p>/i', substr( $d['content'] ?? '', 0, 1000 ) ) ) $score += 15;

		return min( 100, $score );
	}

	private function score_geo( $d ) {
		$score = 0;
		$content = strip_tags( $d['content'] ?? '' );

		// Citability block — >=1 self-contained ~150 word block (30 pts)
		$paragraphs = preg_split( '/\n\n+/', $content );
		foreach ( $paragraphs as $p ) {
			$wc = str_word_count( $p );
			if ( $wc >= 120 && $wc <= 200 ) { $score += 30; break; }
		}

		// Factual/statistical density (20 pts)
		$numbers = preg_match_all( '/\d+%|\d+\.\d+|₹\d|USD|\$\d/i', $content );
		if ( $numbers >= 3 ) $score += 20;
		elseif ( $numbers >= 1 ) $score += 10;

		// Authority signals (20 pts)
		$author = get_post_meta( $d['post_id'] ?? 0, '_meesho_author', true );
		if ( ! empty( $author ) ) $score += 7;
		if ( preg_match( '/\d{4}/', $content ) ) $score += 7; // dates
		if ( preg_match( '/according to|source:|cited/i', $content ) ) $score += 6;

		// Structured formatting (15 pts)
		if ( preg_match( '/<(ul|ol|table|dl)/i', $d['content'] ?? '' ) ) $score += 8;
		if ( count( $d['headings'] ?? array() ) >= 3 ) $score += 7;

		// AI crawler accessibility (15 pts) — check if llms.txt exists
		if ( file_exists( ABSPATH . 'llms.txt' ) ) $score += 15;

		return min( 100, $score );
	}

	/* ---- AI Analysis via OpenRouter ---- */

	public function analyze_page( $page_data ) {
		$api_key = $this->settings()->get( 'openrouter_api_key' );
		if ( empty( $api_key ) ) return new WP_Error( 'no_key', 'OpenRouter API key not configured' );

		$model = $this->settings()->get( 'ai_model_seo' );
		if ( empty( $model ) ) $model = 'openai/gpt-3.5-turbo';

		$scores = $this->calculate_scores( $page_data );
		$prompt = $this->build_analysis_prompt( $page_data, $scores );

		$response = wp_remote_post( 'https://openrouter.ai/api/v1/chat/completions', array(
			'timeout' => 15,
			'headers' => array(
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type'  => 'application/json',
			),
			'body' => wp_json_encode( array(
				'model'    => $model,
				'messages' => array(
					array( 'role' => 'system', 'content' => 'You are an SEO/AEO/GEO optimization expert. Return ONLY valid JSON.' ),
					array( 'role' => 'user', 'content' => $prompt ),
				),
				'temperature' => 0.3,
			) ),
		) );

		if ( is_wp_error( $response ) ) {
			// Retry once
			usleep( 500000 );
			$response = wp_remote_post( 'https://openrouter.ai/api/v1/chat/completions', array(
				'timeout' => 15,
				'headers' => array( 'Authorization' => 'Bearer ' . $api_key, 'Content-Type' => 'application/json' ),
				'body' => wp_json_encode( array(
					'model' => $model,
					'messages' => array(
						array( 'role' => 'system', 'content' => 'Return ONLY valid JSON.' ),
						array( 'role' => 'user', 'content' => $prompt ),
					),
				) ),
			) );
			if ( is_wp_error( $response ) ) return $response;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );
		$ai_text = $data['choices'][0]['message']['content'] ?? '';

		// Parse JSON — discard entirely if invalid
		$parsed = json_decode( $ai_text, true );
		if ( json_last_error() !== JSON_ERROR_NONE || ! isset( $parsed['suggestions'] ) ) {
			return new WP_Error( 'invalid_json', 'AI returned invalid JSON — batch discarded' );
		}

		// Store suggestions
		return $this->store_suggestions( $page_data['post_id'], $parsed['suggestions'] );
	}

	private function build_analysis_prompt( $page_data, $scores ) {
		$content_excerpt = substr( strip_tags( $page_data['content'] ?? '' ), 0, 2000 );

		return "Analyze this page and return optimization suggestions.\n\n"
			. "Post ID: {$page_data['post_id']}\n"
			. "Title: {$page_data['title']}\n"
			. "Meta Title: {$page_data['meta_title']}\n"
			. "Meta Description: {$page_data['meta_desc']}\n"
			. "Word Count: {$page_data['word_count']}\n"
			. "Internal Links: " . count( $page_data['internal_links'] ) . "\n"
			. "Missing Alt Tags: {$page_data['missing_alts']}\n"
			. "Current Scores — SEO: {$scores['seo']}, AEO: {$scores['aeo']}, GEO: {$scores['geo']}\n"
			. "Content (first 2000 chars):\n{$content_excerpt}\n\n"
			. 'Return JSON: {"post_id":' . $page_data['post_id'] . ',"suggestions":[{"type":"meta_title|meta_desc|content|schema|faq|internal_link|alt_tag|llms_txt|citability_block","current_value":"...","suggested_value":"...","reasoning":"...","priority":"high|medium|low","confidence":0-100,"safe_to_apply":true/false}]}';
	}

	/* ---- Store suggestions in DB ---- */

	private function store_suggestions( $post_id, $suggestions ) {
		global $wpdb;
		$table = $wpdb->prefix . 'meesho_seo_suggestions';
		$stored = array();

		foreach ( $suggestions as $s ) {
			$wpdb->insert( $table, array(
				'post_id'         => $post_id,
				'type'            => sanitize_text_field( $s['type'] ?? '' ),
				'current_value'   => $s['current_value'] ?? '',
				'suggested_value' => $s['suggested_value'] ?? '',
				'reasoning'       => sanitize_textarea_field( $s['reasoning'] ?? '' ),
				'priority'        => sanitize_text_field( $s['priority'] ?? 'low' ),
				'confidence'      => intval( $s['confidence'] ?? 0 ),
				'safe_to_apply'   => intval( $s['safe_to_apply'] ?? 0 ),
				'status'          => 'pending',
				'created_at'      => date( 'd/m/Y' ),
				'applied_at'      => '',
			), array( '%d','%s','%s','%s','%s','%s','%d','%d','%s','%s','%s' ) );

			$s['id'] = $wpdb->insert_id;
			$stored[] = $s;
		}

		return $stored;
	}

	/* ---- Schema Generator ---- */

	public function generate_schema( $post_id, $type = 'auto' ) {
		$post = get_post( $post_id );
		if ( ! $post ) return '';

		if ( $type === 'auto' ) {
			if ( $post->post_type === 'product' ) $type = 'Product';
			elseif ( preg_match( '/how\s*to/i', $post->post_title ) ) $type = 'HowTo';
			else $type = 'Article';
		}

		switch ( $type ) {
			case 'Product':
				return $this->schema_product( $post_id, $post );
			case 'FAQ':
				return $this->schema_faq( $post_id, $post );
			case 'HowTo':
				return $this->schema_howto( $post );
			default:
				return $this->schema_article( $post );
		}
	}

	private function schema_article( $post ) {
		return wp_json_encode( array(
			'@context'      => 'https://schema.org',
			'@type'         => 'Article',
			'headline'      => $post->post_title,
			'datePublished' => get_the_date( 'c', $post ),
			'dateModified'  => get_the_modified_date( 'c', $post ),
			'author'        => array( '@type' => 'Person', 'name' => get_the_author_meta( 'display_name', $post->post_author ) ),
			'description'   => wp_trim_words( strip_tags( $post->post_content ), 30 ),
		), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
	}

	private function schema_product( $post_id, $post ) {
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $post_id ) : null;
		$schema = array(
			'@context' => 'https://schema.org',
			'@type'    => 'Product',
			'name'     => $post->post_title,
			'description' => wp_trim_words( strip_tags( $post->post_content ), 30 ),
		);
		if ( $product ) {
			$schema['sku']   = $product->get_sku();
			$schema['brand'] = array( '@type' => 'Brand', 'name' => get_bloginfo( 'name' ) );
			$schema['offers'] = array(
				'@type'         => 'Offer',
				'price'         => $product->get_price(),
				'priceCurrency' => 'INR',
				'availability'  => $product->is_in_stock() ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
			);
		}
		return wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
	}

	private function schema_faq( $post_id, $post ) {
		$headings = array();
		preg_match_all( '/<h[2-4][^>]*>(.*?)<\/h[2-4]>/is', $post->post_content, $matches );
		$qa = array();
		foreach ( $matches[1] as $q ) {
			$q = strip_tags( $q );
			if ( strpos( $q, '?' ) !== false ) {
				$qa[] = array( '@type' => 'Question', 'name' => $q, 'acceptedAnswer' => array(
					'@type' => 'Answer', 'text' => 'See section: ' . $q
				) );
			}
		}
		return wp_json_encode( array(
			'@context'   => 'https://schema.org',
			'@type'      => 'FAQPage',
			'mainEntity' => $qa,
		), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
	}

	private function schema_howto( $post ) {
		$steps = array();
		preg_match_all( '/<h[2-4][^>]*>(.*?)<\/h[2-4]>/is', $post->post_content, $m );
		$i = 1;
		foreach ( $m[1] as $step ) {
			$steps[] = array( '@type' => 'HowToStep', 'position' => $i++, 'name' => strip_tags( $step ) );
		}
		return wp_json_encode( array(
			'@context' => 'https://schema.org',
			'@type'    => 'HowTo',
			'name'     => $post->post_title,
			'step'     => $steps,
		), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
	}
}
