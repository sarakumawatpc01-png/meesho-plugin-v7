<?php
/**
 * MM Blogs — v6.5
 * AJAX endpoints for AI-generated blog drafts.
 */
if ( ! class_exists( 'Meesho_Master_Blogs' ) ) {
class Meesho_Master_Blogs {

	public function __construct() {
		add_action( 'wp_ajax_mm_blog_generate', array( $this, 'ajax_generate' ) );
		add_action( 'wp_ajax_mm_blog_save', array( $this, 'ajax_save' ) );
		add_action( 'wp_ajax_mm_blog_list_drafts', array( $this, 'ajax_list_drafts' ) );
		add_action( 'wp_ajax_mm_blog_delete_draft', array( $this, 'ajax_delete_draft' ) );
	}

	private function settings() {
		static $s = null;
		if ( null === $s ) {
			$s = new Meesho_Master_Settings();
		}
		return $s;
	}

	private function normalize_post_status( $status ) {
		$status = sanitize_key( (string) $status );
		$allowed = array( 'draft', 'pending', 'publish', 'future' );
		if ( ! in_array( $status, $allowed, true ) ) {
			return 'draft';
		}
		return $status;
	}

	private function parse_tags( $raw_tags ) {
		$parts = array_filter( array_map( 'trim', explode( ',', (string) $raw_tags ) ) );
		$tags = array();
		foreach ( $parts as $tag ) {
			$clean = sanitize_text_field( $tag );
			if ( '' !== $clean ) {
				$tags[] = $clean;
			}
		}
		return array_values( array_unique( $tags ) );
	}

	private function maybe_attach_featured_image( $post_id, $image_url ) {
		$image_url = esc_url_raw( (string) $image_url );
		if ( '' === $image_url ) {
			return;
		}
		$validated = wp_http_validate_url( $image_url );
		if ( ! $validated ) {
			return;
		}
		update_post_meta( $post_id, '_mm_featured_image_url', $validated );
		$attachment_id = attachment_url_to_postid( $validated );
		if ( $attachment_id ) {
			set_post_thumbnail( $post_id, (int) $attachment_id );
		}
	}

	/**
	 * AJAX: generate a blog draft. Calls OpenRouter with master prompt
	 * + default instructions + user inputs. Does NOT save — returns the
	 * generated content for review.
	 */
	public function ajax_generate() {
		meesho_master_verify_ajax_nonce();
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
		}

		$topic       = sanitize_text_field( wp_unslash( $_POST['topic'] ?? '' ) );
		$keyword     = sanitize_text_field( wp_unslash( $_POST['keyword'] ?? '' ) );
		$length      = sanitize_key( $_POST['length'] ?? 'medium' );
		$tone        = sanitize_key( $_POST['tone'] ?? 'warm' );
		$extra       = sanitize_textarea_field( wp_unslash( $_POST['extra'] ?? '' ) );

		if ( empty( $topic ) ) {
			wp_send_json_error( array( 'message' => 'Topic is required.' ) );
		}

		$length_words = array(
			'short'  => '400-600 words',
			'medium' => '800-1200 words',
			'long'   => '1500-2000 words',
		);
		$length_label = $length_words[ $length ] ?? '800-1200 words';

		$settings = $this->settings();
		$api_key  = $settings->get( 'mm_openrouter_key' );
		if ( empty( $api_key ) ) {
			$api_key = $settings->get( 'openrouter_api_key' );
		}
		if ( empty( $api_key ) ) {
			wp_send_json_error( array( 'message' => 'OpenRouter API key not set. Open Settings → AI / OpenRouter.' ) );
		}

		$model = $settings->get( 'mm_openrouter_model_blog' );
		if ( empty( $model ) ) {
			$model = $settings->get( 'mm_openrouter_model_seo' );
		}
		if ( empty( $model ) ) {
			$model = 'openai/gpt-4o-mini';
		}

		$system_prompt = $settings->get( 'mm_prompt_blog_master' );
		if ( empty( $system_prompt ) ) {
			$system_prompt = 'You are an expert blog writer. Write SEO-friendly content with proper structure (H1, H2s, lists where helpful). Output clean HTML only, no preamble.';
		}
		$default_instr = $settings->get( 'mm_blog_default_instructions' );

		$user_prompt  = "Write a complete blog post on this topic: \"{$topic}\".\n";
		if ( $keyword ) {
			$user_prompt .= "Primary SEO keyword: \"{$keyword}\". Use naturally — never stuff.\n";
		}
		$user_prompt .= "Length: {$length_label}.\nTone: {$tone}.\n";
		if ( $extra ) {
			$user_prompt .= "Extra instructions:\n{$extra}\n";
		}
		if ( $default_instr ) {
			$user_prompt .= "\nDefault rules to always follow:\n{$default_instr}\n";
		}
		$user_prompt .= "\nReturn JSON ONLY with this exact shape (no markdown fences, no preamble): {\"title\":\"...\",\"content\":\"<full HTML content>\",\"meta_description\":\"<under 160 chars>\"}";

		$res = wp_remote_post(
			'https://openrouter.ai/api/v1/chat/completions',
			array(
				'timeout' => 120,
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
					'HTTP-Referer'  => home_url(),
					'X-Title'       => 'Meesho Master',
				),
				'body' => wp_json_encode( array(
					'model'    => $model,
					'messages' => array(
						array( 'role' => 'system', 'content' => $system_prompt ),
						array( 'role' => 'user',   'content' => $user_prompt ),
					),
				) ),
			)
		);
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		$raw  = $body['choices'][0]['message']['content'] ?? '';
		if ( empty( $raw ) ) {
			wp_send_json_error( array( 'message' => 'AI returned empty response. Raw: ' . wp_remote_retrieve_body( $res ) ) );
		}

		// Try to parse strict JSON. If the model wrapped in code fences, strip.
		$cleaned = trim( preg_replace( '/^```(?:json)?\s*|\s*```$/m', '', $raw ) );
		$parsed  = json_decode( $cleaned, true );
		if ( ! is_array( $parsed ) || empty( $parsed['title'] ) || empty( $parsed['content'] ) ) {
			// Fallback: treat the whole thing as content with a derived title
			wp_send_json_success( array(
				'title'            => $topic,
				'content'          => $raw,
				'meta_description' => '',
				'model'            => $model,
				'note'             => 'Model did not return strict JSON — using raw output as content.',
			) );
		}

		wp_send_json_success( array(
			'title'            => $parsed['title'],
			'content'          => $parsed['content'],
			'meta_description' => $parsed['meta_description'] ?? '',
			'model'            => $model,
		) );
	}

	/**
	 * AJAX: save the generated content as a WordPress post draft.
	 */
	public function ajax_save() {
		meesho_master_verify_ajax_nonce();
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
		}
		$title     = sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) );
		$content   = wp_kses_post( wp_unslash( $_POST['content'] ?? '' ) );
		$meta      = sanitize_text_field( wp_unslash( $_POST['meta_description'] ?? '' ) );
		$category  = absint( $_POST['category'] ?? 0 );
		$slug      = sanitize_title( wp_unslash( $_POST['slug'] ?? '' ) );
		$status    = $this->normalize_post_status( wp_unslash( $_POST['status'] ?? 'draft' ) );
		$tags      = $this->parse_tags( wp_unslash( $_POST['tags'] ?? '' ) );
		$excerpt   = sanitize_textarea_field( wp_unslash( $_POST['excerpt'] ?? '' ) );
		$featured  = wp_unslash( $_POST['featured_image'] ?? '' );
		$schedule  = sanitize_text_field( wp_unslash( $_POST['schedule_at'] ?? '' ) );
		$post_date = '';

		if ( empty( $title ) || empty( $content ) ) {
			wp_send_json_error( array( 'message' => 'Title and content required.' ) );
		}
		if ( 'future' === $status ) {
			if ( empty( $schedule ) ) {
				wp_send_json_error( array( 'message' => 'Schedule date/time is required for scheduled posts.' ) );
			}
			$timestamp = strtotime( $schedule );
			if ( false === $timestamp || $timestamp <= 0 ) {
				wp_send_json_error( array( 'message' => 'Invalid schedule date/time.' ) );
			}
			$post_date = gmdate( 'Y-m-d H:i:s', $timestamp );
		}

		$post_data = array(
			'post_title'   => $title,
			'post_content' => $content,
			'post_status'  => $status,
			'post_type'    => 'post',
			'post_excerpt' => $excerpt ? $excerpt : $meta,
			'post_category' => $category ? array( $category ) : array(),
			'tags_input'   => $tags,
		);
		if ( ! empty( $slug ) ) {
			$post_data['post_name'] = $slug;
		}
		if ( 'future' === $status && ! empty( $post_date ) ) {
			$post_data['post_date'] = $post_date;
		}
		$post_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $post_id ) ) {
			wp_send_json_error( array( 'message' => $post_id->get_error_message() ) );
		}

		// E1: Log blog draft creation to audit_log
		( new MM_Undo() )->log_before_change(
			'blog_created',
			'post',
			$post_id,
			null,
			wp_json_encode( array( 'title' => $title ) ),
			0,
			'copilot',
			'Blog draft: ' . $title,
			0
		);

		// Save meta description in common SEO plugin formats
		if ( $meta ) {
			update_post_meta( $post_id, '_yoast_wpseo_metadesc', $meta );      // Yoast
			update_post_meta( $post_id, 'rank_math_description', $meta );     // RankMath
			update_post_meta( $post_id, '_aioseo_description', $meta );       // AIOSEO
		}
		$this->maybe_attach_featured_image( $post_id, $featured );

		wp_send_json_success( array(
			'post_id'  => $post_id,
			'post_status' => get_post_status( $post_id ),
			'edit_url' => admin_url( 'post.php?post=' . $post_id . '&action=edit' ),
			'message'  => 'Post saved.',
		) );
	}

	/**
	 * AJAX: list recent drafts (any draft post, not just AI-generated).
	 */
	public function ajax_list_drafts() {
		meesho_master_verify_ajax_nonce();
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
		}
		$drafts = get_posts( array(
			'post_type'   => 'post',
			'post_status' => array( 'draft', 'pending' ),
			'numberposts' => 20,
			'orderby'     => 'modified',
			'order'       => 'DESC',
		) );
		$out = array();
		foreach ( $drafts as $d ) {
			$out[] = array(
				'id'        => $d->ID,
				'title'     => $d->post_title,
				'modified'  => $d->post_modified,
				'edit_url'  => admin_url( 'post.php?post=' . $d->ID . '&action=edit' ),
				'preview'   => get_permalink( $d->ID ) . '&preview=true',
				'word_count' => str_word_count( wp_strip_all_tags( $d->post_content ) ),
			);
		}
		wp_send_json_success( $out );
	}

	public function ajax_delete_draft() {
		meesho_master_verify_ajax_nonce();
		if ( ! current_user_can( 'delete_posts' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
		}
		$id = absint( $_POST['id'] ?? 0 );
		if ( ! $id ) {
			wp_send_json_error( array( 'message' => 'Missing id.' ) );
		}
		$post = get_post( $id );
		if ( ! $post || $post->post_type !== 'post' ) {
			wp_send_json_error( array( 'message' => 'Not a post.' ) );
		}
		wp_trash_post( $id );
		wp_send_json_success( array( 'trashed' => true ) );
	}
}
}
