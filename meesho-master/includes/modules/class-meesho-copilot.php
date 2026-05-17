<?php

class Meesho_Master_Copilot {
private $forbidden_tokens = array( 'DROP', 'TRUNCATE', 'DELETE FROM', 'wp_delete_site', 'deactivate_plugin', 'delete_user', 'wp_set_password' );
private $allowed_actions = array(
	// Existing
	'publish_post', 'unpublish_post', 'update_post_title', 'update_post_content',
	'apply_seo_suggestion', 'update_product_price', 'update_product_stock',
	'create_blog_post', 'create_landing_page', 'search_orders',
	'update_reviews_css', 'reset_reviews_css',
	// v6.2 — content creation
	'create_page', 'create_post', 'create_product_draft',
	// v6.2 — analysis & suggestions (read-only / advisory)
	'analyze_product', 'analyze_order', 'analyze_orders_batch',
	'suggest_seo_improvements', 'suggest_aeo_improvements', 'suggest_geo_improvements',
	'flag_site_issues',
	// v6.2 — integrations (read-only)
	'read_woocommerce_snapshot', 'read_recent_orders',
	'read_google_analytics', 'read_search_console',
	'read_google_ads_status', 'read_meta_status',
);

public function __construct() {
add_action( 'wp_ajax_mm_copilot_chat', array( $this, 'ajax_chat' ) );
add_action( 'wp_ajax_mm_copilot_apply', array( $this, 'ajax_apply_action' ) );
add_action( 'wp_ajax_mm_copilot_history', array( $this, 'ajax_get_history' ) );
add_action( 'wp_ajax_mm_copilot_queue_state', array( $this, 'ajax_get_queue_state' ) );
add_action( 'wp_ajax_mm_copilot_undo_last', array( $this, 'ajax_undo_last' ) );
add_action( 'wp_ajax_mm_copilot_list_undo_history', array( $this, 'ajax_list_undo_history' ) );
add_action( 'wp_ajax_mm_copilot_upload_file', array( $this, 'ajax_upload_file' ) );
}

public function ajax_chat() {
check_ajax_referer( 'mm_nonce', 'nonce' );
if ( ! current_user_can( 'manage_options' ) ) {
wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
}
$settings = new Meesho_Master_Settings();
if ( 'yes' !== $settings->get( 'mm_copilot_enabled', 'yes' ) ) {
wp_send_json_error( array( 'message' => 'Copilot is disabled.' ), 403 );
}
$message   = sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) );
$model     = sanitize_text_field( wp_unslash( $_POST['model'] ?? '' ) );
$thread_key = sanitize_key( wp_unslash( $_POST['thread_key'] ?? '' ) );
$attachments = isset( $_POST['attachments'] ) ? json_decode( wp_unslash( $_POST['attachments'] ), true ) : array();
if ( ! is_array( $attachments ) ) {
	$attachments = array();
}
if ( '' === $message ) {
wp_send_json_error( array( 'message' => 'Message is required' ), 400 );
}
$api_key  = $settings->get( 'openrouter_api_key' );
if ( '' === $api_key ) {
wp_send_json_error( array( 'message' => 'OpenRouter API key not configured' ), 400 );
}
if ( '' === $model ) {
$model = $settings->get( 'mm_openrouter_model_copilot', 'openai/gpt-4o-mini' );
}
if ( '' === $thread_key ) {
$thread_key = 'thread_' . wp_generate_uuid4();
}

// Check for hard denials in message
if ( $this->contains_forbidden_patterns( $message ) ) {
wp_send_json_error( array( 'message' => 'This request contains forbidden operations.' ), 403 );
}

// Build message content — plain text or multi-modal array (D4)
$vision_models = array( 'openai/gpt-4o', 'openai/gpt-4o-mini', 'anthropic/claude-3.5-sonnet', 'google/gemini-pro-vision' );
$model_supports_vision = in_array( $model, $vision_models, true );
if ( ! empty( $attachments ) ) {
	$content = array( array( 'type' => 'text', 'text' => $message ) );
	foreach ( $attachments as $att ) {
		if ( ! empty( $att['is_image'] ) && ! empty( $att['url'] ) ) {
			if ( $model_supports_vision ) {
				$content[] = array( 'type' => 'image_url', 'image_url' => array( 'url' => esc_url_raw( $att['url'] ) ) );
			} else {
				$content[] = array( 'type' => 'text', 'text' => 'User attached an image named ' . sanitize_text_field( $att['name'] ?? 'image' ) . ' but this model cannot process images.' );
			}
		} elseif ( ! empty( $att['content'] ) ) {
			$content[] = array( 'type' => 'text', 'text' => '--- Attached file: ' . sanitize_text_field( $att['name'] ?? 'file' ) . " ---\n" . wp_kses_post( $att['content'] ) );
		}
	}
	$user_message_payload = $content;
} else {
	$user_message_payload = $message;
}

// Build full conversation including prior turns for multi-turn memory
$messages_payload = array_merge(
	array( array( 'role' => 'system', 'content' => $this->build_system_prompt() ) ),
	$this->build_thread_messages( $thread_key ),
	array( array( 'role' => 'user', 'content' => $user_message_payload ) )
);

$response = wp_remote_post( 'https://openrouter.ai/api/v1/chat/completions', array(
'timeout' => 90,
'headers' => array(
	'Authorization' => 'Bearer ' . $api_key,
	'Content-Type'  => 'application/json',
	'HTTP-Referer'  => home_url(),
	'X-Title'       => 'Meesho Master',
),
'body'    => wp_json_encode( array(
'model'    => $model,
'messages' => $messages_payload,
) ),
) );
if ( is_wp_error( $response ) ) {
wp_send_json_error( array( 'message' => $response->get_error_message() ), 400 );
}
$body   = json_decode( wp_remote_retrieve_body( $response ), true );
$reply  = $this->scrub_secret_output( (string) ( $body['choices'][0]['message']['content'] ?? '' ) );
$actions = $this->extract_actions( $reply );
$applied = array();
$auto = 'yes' === $settings->get( 'mm_copilot_auto_implement', 'no' );
$queue = $this->init_action_queue( $thread_key, $actions );
foreach ( $actions as $action ) {
	$action_key = $this->action_key( $action );
// Hard denial: destructive actions always need confirmation
if ( ! empty( $action['is_destructive'] ) ) {
$action['requires_confirmation'] = true;
}
if ( $this->is_allowed_action( $action ) ) {
if ( ! empty( $action['is_destructive'] ) || $this->requires_explicit_approval( $action ) ) {
// Never auto-apply destructive actions
	$this->set_queue_state( $queue, $action_key, 'needs_approval', 'Requires explicit approval.' );
} elseif ( $auto ) {
$result = $this->execute_action( $action );
if ( ! is_wp_error( $result ) ) {
$applied[] = $action;
	$this->set_queue_state( $queue, $action_key, 'applied', 'Auto-applied.' );
} else {
	$this->set_queue_state( $queue, $action_key, 'failed', $result->get_error_message() );
}
} else {
	$this->set_queue_state( $queue, $action_key, 'pending', 'Waiting for manual approval.' );
}
} else {
	$this->set_queue_state( $queue, $action_key, 'blocked', 'Action not allowed.' );
}
}
$this->persist_action_queue( $thread_key, $queue );
$this->persist_thread( $thread_key, $message, $reply, $applied );
wp_send_json_success( array( 'reply' => $reply, 'actions' => $actions, 'auto_applied' => $applied, 'auto_implement' => $auto, 'thread_key' => $thread_key, 'timestamp' => wp_date( 'd/m/Y H:i' ) ) );
}


/**
 * Build the prior conversation turns for multi-turn chat.
 * Loads last 10 exchanges from the thread so the AI has context.
 */
private function build_thread_messages( $thread_key ) {
if ( empty( $thread_key ) ) {
return array();
}
global $wpdb;
$table = MM_DB::table( 'copilot_threads' );
if ( ! $table ) {
return array();
}
$row = $wpdb->get_row( $wpdb->prepare( "SELECT messages FROM {$table} WHERE thread_key = %s", $thread_key ) );
if ( ! $row || empty( $row->messages ) ) {
return array();
}
$history = json_decode( $row->messages, true );
if ( ! is_array( $history ) ) {
return array();
}
// Take last 10 messages (5 exchanges) to stay within context limits
$history = array_slice( $history, -10 );
$out = array();
foreach ( $history as $entry ) {
if ( ! empty( $entry['role'] ) && ! empty( $entry['content'] ) ) {
$out[] = array(
'role'    => $entry['role'],
'content' => $entry['content'],
);
}
}
return $out;
}

private function build_system_prompt() {
		$css_vars = $this->get_css_variables_doc();
		$integrations = class_exists( 'MM_Integrations' ) ? MM_Integrations::detect_available() : array();
		$active_integrations = array_keys( array_filter( $integrations ) );
		return 'You are Meesho Master Copilot, an AI assistant embedded in a WooCommerce store running on WordPress. ' .
			'You help with content creation, product/order analysis, SEO/AEO/GEO suggestions, and surfacing site issues with concrete fixes. ' .
			"\n\n" . 'ALLOWED ACTIONS: ' . implode( ', ', $this->allowed_actions ) . '. ' .
			"\n" . 'FORBIDDEN — never do, never suggest: ' . implode( ', ', $this->forbidden_tokens ) . ', deactivating security plugins, modifying user roles, exposing API keys/secrets/credentials, bulk-deleting content. ' .
			"\n" . 'You may NEVER do anything that touches site security: changing passwords, modifying user permissions, disabling firewalls, editing wp-config.php, or deactivating security/backup plugins. Refuse such requests politely. ' .
			"\n\n" . 'OUTPUT FORMAT: When you want to take an action, emit a fenced ```json block with keys: action, params, explanation, is_destructive. The user must click "Apply" to execute it — never assume auto-execution. ' .
			"\n" . 'For destructive actions (bulk operations on >5 items, mass updates, anything irreversible) ALWAYS set is_destructive: true. ' .
			"\n\n" . 'NEW CONTENT (create_page / create_post / create_product_draft) is always created as DRAFT for the human to review. Do not request status:publish. ' .
			"\n\n" . 'WHEN ASKED ABOUT THE STORE: prefer using read_woocommerce_snapshot, read_recent_orders, analyze_orders_batch, flag_site_issues to gather real data before answering. Do not invent numbers. ' .
			"\n" . 'WHEN ASKED ABOUT GOOGLE/META: use read_google_analytics, read_search_console, read_google_ads_status, read_meta_status. If a service returns available:false, tell the user that integration is not connected and suggest installing the official plugin. ' .
			"\n\n" . 'INTEGRATIONS DETECTED ON THIS SITE: ' . ( $active_integrations ? implode( ', ', $active_integrations ) : 'none' ) . '. ' .
			"\n\n" . 'CSS STYLING FOR MEESHO REVIEWS TAB: update_reviews_css action accepts CSS variable overrides. Available variables: ' . $css_vars . '. Use reset_reviews_css to revert. ' .
			"\n" . 'Example: ```json {"action":"update_reviews_css","params":{"css":":root{--mm-stars-color:#FFD700;}"},"explanation":"Made stars gold","is_destructive":false}```';
	}

	private function get_css_variables_doc() {
		return ':root variables: --mm-reviews-max-width, --mm-avg-rating-font-size, --mm-avg-rating-color, --mm-stars-color, --mm-stars-font-size, --mm-review-count-color, --mm-bar-height, --mm-bar-bg, --mm-bar-border-radius, --mm-star-label-font-size, --mm-pct-color, --mm-reviewer-name-font-size, --mm-review-text-color, --mm-review-text-font-size, --mm-review-img-max-width, --mm-review-img-border-radius, --mm-disclaimer-bg, --mm-disclaimer-color, --mm-font-family, --mm-star-5-color, --mm-star-4-color, --mm-star-3-color, --mm-star-2-color, --mm-star-1-color';
	}

private function contains_forbidden_patterns( $text ) {
$forbidden_patterns = array(
'/DROP\s+TABLE/i',
'/TRUNCATE\s+TABLE/i',
'/DELETE\s+FROM/i',
'/wp_delete_site/i',
'/mm_.*(key|secret|credentials)/i',
'/DELETE.*wp_/i',
);

foreach ( $forbidden_patterns as $pattern ) {
if ( preg_match( $pattern, $text ) ) {
return true;
}
}
return false;
}

private function scrub_secret_output( $text ) {
$settings = new Meesho_Master_Settings();
foreach ( $settings->get_all() as $key => $value ) {
if ( preg_match( '/(key|secret|credentials)$/i', $key ) && is_string( $value ) && '' !== $value ) {
$text = str_replace( $value, '[REDACTED]', $text );
}
}
return $text;
}

private function extract_actions( $reply ) {
$actions = array();
if ( preg_match_all( '/```json\s*(\{.*?\})\s*```/is', $reply, $matches ) ) {
foreach ( $matches[1] as $block ) {
$decoded = json_decode( $block, true );
if ( is_array( $decoded ) && ! empty( $decoded['action'] ) ) {
$actions[] = $decoded;
}
}
}
return $actions;
}

private function is_allowed_action( $action ) {
$action_name = strtoupper( (string) ( $action['action'] ?? '' ) );
foreach ( $this->forbidden_tokens as $token ) {
if ( false !== strpos( $action_name, $token ) ) {
return false;
}
}
$params_json = wp_json_encode( $action['params'] ?? array() );
if ( preg_match( '/^mm_.*(key|secret|credentials)/i', $params_json ) ) {
return false;
}
return in_array( $action['action'] ?? '', $this->allowed_actions, true );
}

public function execute_action( $action ) {
if ( ! $this->is_allowed_action( $action ) ) {
return new WP_Error( 'forbidden', 'This action is not allowed.' );
}
$params = $action['params'] ?? array();
$actor  = 'copilot';
switch ( $action['action'] ) {
case 'update_meta_title':
case 'update_meta_desc':
$suggestion = array(
'id' => 0,
'post_id' => absint( $params['post_id'] ?? 0 ),
'type' => 'update_meta_title' === $action['action'] ? 'meta_title' : 'meta_desc',
'current_value' => '',
'suggested_value' => sanitize_text_field( $params['value'] ?? '' ),
);
return ( new MM_SEO_Implementor() )->apply( $suggestion, $actor );
case 'update_post_title':
$post_id = absint( $params['post_id'] ?? 0 );
$new_title = sanitize_text_field( $params['value'] ?? '' );
( new MM_Logger() )->log_before_change( 'copilot_edit', 'post', $post_id, get_the_title( $post_id ), $new_title, 0, $actor, 'title' );
wp_update_post( array( 'ID' => $post_id, 'post_title' => $new_title ) );
return true;
case 'update_post_content':
$post_id = absint( $params['post_id'] ?? 0 );
$new_content = wp_kses_post( $params['value'] ?? '' );
( new MM_Logger() )->log_before_change( 'copilot_edit', 'post', $post_id, get_post_field( 'post_content', $post_id ), $new_content, 0, $actor, 'content' );
wp_update_post( array( 'ID' => $post_id, 'post_content' => $new_content ) );
return true;
case 'unpublish':
$post_id = absint( $params['post_id'] ?? 0 );
( new MM_Logger() )->log_before_change( 'copilot_edit', 'post', $post_id, get_post_status( $post_id ), 'draft', 0, $actor, 'status' );
wp_update_post( array( 'ID' => $post_id, 'post_status' => 'draft' ) );
return true;
case 'update_product_price':
$product = function_exists( 'wc_get_product' ) ? wc_get_product( absint( $params['post_id'] ?? 0 ) ) : null;
if ( ! $product ) {
return new WP_Error( 'not_found', 'Product not found.' );
}
$new_price = wc_format_decimal( $params['value'] ?? '' );
( new MM_Logger() )->log_before_change( 'copilot_edit', 'post', $product->get_id(), $product->get_regular_price(), $new_price, 0, $actor, 'price' );
$product->set_regular_price( $new_price );
$product->save();
return true;
case 'apply_seo_suggestion':
return ( new Meesho_Master_SEO() )->apply_suggestion( absint( $params['suggestion_id'] ?? 0 ), $actor );

		case 'update_reviews_css':
			$css = $params['css'] ?? '';
			if ( empty( $css ) ) {
				return new WP_Error( 'invalid', 'No CSS provided.' );
			}
			// Validate CSS contains only safe properties (no JavaScript or malicious content)
			if ( preg_match( '/javascript:|expression\(|<script/i', $css ) ) {
				return new WP_Error( 'unsafe', 'CSS contains unsafe content.' );
			}
			( new MM_Logger() )->log_before_change( 'copilot_edit', 'setting', 0, (string) get_option( 'mm_meesho_reviews_css', '' ), (string) $css, 0, $actor, 'reviews_css' );
			update_option( 'mm_meesho_reviews_css', wp_strip_all_tags( $css ) );
			return true;

		case 'reset_reviews_css':
			( new MM_Logger() )->log_before_change( 'copilot_edit', 'setting', 0, (string) get_option( 'mm_meesho_reviews_css', '' ), '', 0, $actor, 'reviews_css_reset' );
			delete_option( 'mm_meesho_reviews_css' );
			return true;

		// ============================================================
		// v6.2 — content creation
		// ============================================================
		case 'create_page':
		case 'create_post':
		case 'create_blog_post':
		case 'create_landing_page':
		case 'create_product_draft':
			$post_type = ( 'create_page' === $action['action'] || 'create_landing_page' === $action['action'] ) ? 'page' :
				( 'create_product_draft' === $action['action'] ? 'product' : 'post' );
			$title = sanitize_text_field( $params['title'] ?? '' );
			$content = wp_kses_post( $params['content'] ?? '' );
			if ( empty( $title ) ) {
				return new WP_Error( 'no_title', 'Title is required.' );
			}
			$post_status = sanitize_key( $params['status'] ?? 'draft' );
			if ( ! in_array( $post_status, array( 'draft', 'pending', 'private' ), true ) ) {
				$post_status = 'draft'; // Force draft — Copilot never auto-publishes new content
			}
			$post_id = wp_insert_post( array(
				'post_type'    => $post_type,
				'post_title'   => $title,
				'post_content' => $content,
				'post_status'  => $post_status,
				'post_excerpt' => sanitize_text_field( $params['excerpt'] ?? '' ),
			), true );
			if ( is_wp_error( $post_id ) ) {
				return $post_id;
			}
			( new MM_Logger() )->log_before_change( 'copilot_edit', 'post', (int) $post_id, '', wp_json_encode( array( 'title' => $title, 'status' => $post_status ) ), 0, $actor, 'create_content' );
			return array(
				'created'   => true,
				'post_id'   => $post_id,
				'edit_url'  => admin_url( 'post.php?post=' . $post_id . '&action=edit' ),
				'post_type' => $post_type,
				'note'      => 'Created as draft for safety. Review and publish manually.',
			);

		// ============================================================
		// v6.2 — analysis (read-only, returns data)
		// ============================================================
		case 'analyze_product':
			$post_id = absint( $params['post_id'] ?? 0 );
			if ( ! $post_id ) {
				return new WP_Error( 'no_post_id', 'post_id required.' );
			}
			if ( class_exists( 'MM_SEO_Crawler' ) && class_exists( 'MM_SEO_Scorer' ) ) {
				$data = MM_SEO_Crawler::collect_post_data( $post_id );
				$scores = ( new MM_SEO_Scorer() )->score( $data );
				return array( 'analysis' => $scores, 'post_id' => $post_id );
			}
			return new WP_Error( 'unavailable', 'SEO analyzer not loaded.' );

		case 'analyze_order':
			$order_id = absint( $params['order_id'] ?? 0 );
			if ( ! $order_id || ! function_exists( 'wc_get_order' ) ) {
				return new WP_Error( 'unavailable', 'WooCommerce not active or order_id missing.' );
			}
			$o = wc_get_order( $order_id );
			if ( ! $o ) {
				return new WP_Error( 'not_found', 'Order not found.' );
			}
			return array(
				'order_id' => $order_id,
				'status'   => $o->get_status(),
				'total'    => (float) $o->get_total(),
				'items'    => $o->get_item_count(),
				'customer' => $o->get_billing_first_name() . ' ' . $o->get_billing_last_name(),
			);

		case 'analyze_orders_batch':
			if ( ! class_exists( 'MM_Integrations' ) ) {
				return new WP_Error( 'unavailable', 'Integrations not loaded.' );
			}
			$snapshot = MM_Integrations::woocommerce_snapshot();
			$recent = MM_Integrations::woocommerce_recent_orders( min( 50, absint( $params['limit'] ?? 25 ) ) );
			return array( 'snapshot' => $snapshot, 'recent' => $recent );

		case 'suggest_seo_improvements':
		case 'suggest_aeo_improvements':
		case 'suggest_geo_improvements':
			$post_id = absint( $params['post_id'] ?? 0 );
			if ( ! $post_id || ! class_exists( 'MM_DB' ) ) {
				return new WP_Error( 'no_post_id', 'post_id required.' );
			}
			global $wpdb;
			$rows = $wpdb->get_results( $wpdb->prepare(
				'SELECT * FROM ' . MM_DB::table( 'seo_suggestions' ) . ' WHERE post_id = %d AND status = %s ORDER BY priority DESC',
				$post_id,
				'pending'
			) );
			return array( 'post_id' => $post_id, 'suggestions' => $rows ?: array() );

		case 'flag_site_issues':
			if ( ! class_exists( 'MM_Integrations' ) ) {
				return new WP_Error( 'unavailable', 'Integrations not loaded.' );
			}
			return array( 'flags' => MM_Integrations::site_health_flags() );

		// ============================================================
		// v6.2 — integration reads
		// ============================================================
		case 'read_woocommerce_snapshot':
			return class_exists( 'MM_Integrations' ) ? MM_Integrations::woocommerce_snapshot() : array( 'available' => false );

		case 'read_recent_orders':
			return class_exists( 'MM_Integrations' )
				? array( 'orders' => MM_Integrations::woocommerce_recent_orders( min( 50, absint( $params['limit'] ?? 25 ) ) ) )
				: array( 'available' => false );

		case 'read_google_analytics':
			return class_exists( 'MM_Integrations' ) ? MM_Integrations::google_analytics_snapshot() : array( 'available' => false );

		case 'read_search_console':
			return class_exists( 'MM_Integrations' ) ? MM_Integrations::search_console_snapshot() : array( 'available' => false );

		case 'read_google_ads_status':
			return class_exists( 'MM_Integrations' ) ? MM_Integrations::google_ads_snapshot() : array( 'available' => false );

		case 'read_meta_status':
			return class_exists( 'MM_Integrations' ) ? MM_Integrations::meta_snapshot() : array( 'available' => false );
}
return new WP_Error( 'unknown_action', 'Unknown action.' );
}

private function persist_thread( $thread_key, $message, $reply, $applied ) {
global $wpdb;
$table = MM_DB::table( 'copilot_threads' );
$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE thread_key = %s", $thread_key ) );
$messages = $existing ? json_decode( $existing->messages, true ) : array();
if ( ! is_array( $messages ) ) {
$messages = array();
}
$messages[] = array( 'role' => 'user',      'content' => $message, 'timestamp' => current_time( 'mysql' ) );
$messages[] = array( 'role' => 'assistant',  'content' => $reply,   'timestamp' => current_time( 'mysql' ), 'action_taken' => $applied );
// Cap at 50 messages per thread to prevent unbounded growth
if ( count( $messages ) > 50 ) {
$messages = array_slice( $messages, -50 );
}
$payload = array( 'title' => wp_trim_words( $message, 8 ), 'messages' => wp_json_encode( $messages ), 'updated_at' => current_time( 'mysql' ) );
if ( $existing ) {
$wpdb->update( $table, $payload, array( 'id' => $existing->id ), array( '%s', '%s', '%s' ), array( '%d' ) );
} else {
$wpdb->insert( $table, array_merge( array( 'thread_key' => $thread_key, 'created_at' => current_time( 'mysql' ) ), $payload ), array( '%s', '%s', '%s', '%s', '%s' ) );
}
}

public function ajax_apply_action() {
meesho_master_verify_ajax_nonce();
if ( ! current_user_can( 'manage_options' ) ) {
wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
}
$raw_action = wp_unslash( $_POST['action_data'] ?? ( $_POST['action'] ?? '' ) );
$action = json_decode( $raw_action, true );
if ( ! is_array( $action ) ) {
wp_send_json_error( array( 'message' => 'Invalid action data' ), 400 );
}
$approved = ! empty( $_POST['approved'] );
if ( $this->requires_explicit_approval( $action ) && ! $approved ) {
wp_send_json_error( array( 'message' => 'Approval is required for this action.' ), 400 );
}
$thread_key = sanitize_key( wp_unslash( $_POST['thread_key'] ?? '' ) );
$queue = $this->load_action_queue( $thread_key );
$action_key = $this->action_key( $action );
$this->set_queue_state( $queue, $action_key, 'applying', 'Applying action.' );
$this->persist_action_queue( $thread_key, $queue );
$result = $this->execute_action( $action );
if ( is_wp_error( $result ) ) {
$this->set_queue_state( $queue, $action_key, 'failed', $result->get_error_message() );
$this->persist_action_queue( $thread_key, $queue );
wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
}
$this->set_queue_state( $queue, $action_key, 'applied', 'Applied manually.' );
$this->persist_action_queue( $thread_key, $queue );
wp_send_json_success( 'Action applied.' );
}

public function ajax_get_queue_state() {
meesho_master_verify_ajax_nonce();
if ( ! current_user_can( 'manage_options' ) ) {
wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
}
$thread_key = sanitize_key( wp_unslash( $_POST['thread_key'] ?? '' ) );
wp_send_json_success( $this->load_action_queue( $thread_key ) );
}

public function ajax_get_history() {
meesho_master_verify_ajax_nonce();
if ( ! current_user_can( 'manage_options' ) ) {
wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
}
global $wpdb;
$table = MM_DB::table( 'copilot_threads' );
$thread_key = sanitize_key( wp_unslash( $_POST['thread_key'] ?? '' ) );
if ( $thread_key ) {
$thread = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE thread_key = %s", $thread_key ) );
wp_send_json_success( $thread ? json_decode( $thread->messages, true ) : array() );
}
$rows = $wpdb->get_results( $wpdb->prepare( "SELECT thread_key, title, updated_at FROM {$table} ORDER BY updated_at DESC LIMIT %d", 20 ) );
wp_send_json_success( $rows );
}

public function ajax_undo_last() {
meesho_master_verify_ajax_nonce();
if ( ! current_user_can( 'manage_options' ) ) {
wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
}
$result = ( new Meesho_Master_Undo() )->revert_last( get_current_user_id() );
if ( is_wp_error( $result ) ) {
wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
}
wp_send_json_success( 'Last Copilot action undone.' );
}

// D3 — List last 25 undoable actions within 7 days
public function ajax_list_undo_history() {
meesho_master_verify_ajax_nonce();
if ( ! current_user_can( 'manage_options' ) ) {
wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
}
global $wpdb;
$table = MM_DB::table( 'audit_log' );
$uid   = get_current_user_id();
$rows  = $wpdb->get_results( $wpdb->prepare(
	"SELECT id, action_type, target_type, target_id, note, created_at, undone
	 FROM {$table}
	 WHERE actor_user_id = %d
	   AND undoable = 1
	   AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
	 ORDER BY created_at DESC
	 LIMIT 25",
	$uid
), ARRAY_A );
if ( ! is_array( $rows ) ) {
	$rows = array();
}
foreach ( $rows as &$r ) {
	$post      = ! empty( $r['target_id'] ) ? get_post( $r['target_id'] ) : null;
	$r['label'] = $post
		? $post->post_title . ' (#' . $r['target_id'] . ')'
		: ( $r['note'] ?: 'Action #' . $r['id'] );
	$r['created_at'] = mysql2date( 'd M Y H:i', $r['created_at'] );
}
unset( $r );
wp_send_json_success( $rows );
}

// D4 — Upload a file (image/PDF/text) into WP media and return metadata
public function ajax_upload_file() {
meesho_master_verify_ajax_nonce();
if ( ! current_user_can( 'manage_options' ) ) {
wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
}
if ( empty( $_FILES['file'] ) ) {
wp_send_json_error( array( 'message' => 'No file sent.' ) );
}
$file    = $_FILES['file'];
$ext     = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
$allowed = array( 'jpg', 'jpeg', 'png', 'webp', 'gif', 'pdf', 'txt', 'html', 'htm' );
if ( ! in_array( $ext, $allowed, true ) ) {
wp_send_json_error( array( 'message' => 'File type not allowed: ' . esc_html( $ext ) ) );
}
if ( $file['size'] > 8 * 1024 * 1024 ) {
wp_send_json_error( array( 'message' => 'File too large. Max 8MB.' ) );
}
// Server-side MIME check
$check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'] );
if ( false === $check['ext'] || false === $check['type'] ) {
wp_send_json_error( array( 'message' => 'File MIME type is not allowed.' ) );
}
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
$attachment_id = media_handle_upload( 'file', 0 );
if ( is_wp_error( $attachment_id ) ) {
wp_send_json_error( array( 'message' => $attachment_id->get_error_message() ) );
}
$url     = wp_get_attachment_url( $attachment_id );
$content = '';
if ( in_array( $ext, array( 'txt', 'html', 'htm' ), true ) ) {
	$path = get_attached_file( $attachment_id );
	if ( $path && is_readable( $path ) ) {
		$content = substr( file_get_contents( $path ), 0, 8000 );
	}
}
wp_send_json_success( array(
	'attachment_id' => $attachment_id,
	'url'           => $url,
	'name'          => sanitize_text_field( $file['name'] ),
	'type'          => $ext,
	'content'       => $content,
	'is_image'      => in_array( $ext, array( 'jpg', 'jpeg', 'png', 'webp', 'gif' ), true ),
) );
}

private function requires_explicit_approval( $action ) {
	$write_actions = array(
		'publish_post', 'unpublish_post', 'update_post_title', 'update_post_content',
		'update_product_price', 'update_product_stock', 'create_blog_post', 'create_landing_page',
		'create_page', 'create_post', 'create_product_draft', 'update_reviews_css', 'reset_reviews_css',
		'apply_seo_suggestion',
	);
	return in_array( (string) ( $action['action'] ?? '' ), $write_actions, true );
}

private function action_key( $action ) {
	return hash( 'sha256', (string) wp_json_encode( $action ) );
}

private function queue_option_name( $thread_key ) {
	return 'mm_copilot_queue_' . sanitize_key( (string) $thread_key );
}

private function load_action_queue( $thread_key ) {
	if ( '' === (string) $thread_key ) {
		return array();
	}
	$queue = get_option( $this->queue_option_name( $thread_key ), array() );
	return is_array( $queue ) ? $queue : array();
}

private function init_action_queue( $thread_key, $actions ) {
	if ( '' === (string) $thread_key ) {
		return array();
	}
	$queue = $this->load_action_queue( $thread_key );
	foreach ( (array) $actions as $a ) {
		$key = $this->action_key( $a );
		if ( empty( $queue[ $key ] ) ) {
			$queue[ $key ] = array(
				'action' => $a,
				'state' => 'queued',
				'note' => 'Queued from assistant response.',
				'updated_at' => current_time( 'mysql' ),
			);
		}
	}
	return $queue;
}

private function set_queue_state( &$queue, $action_key, $state, $note = '' ) {
	if ( ! is_array( $queue ) ) {
		$queue = array();
	}
	if ( empty( $queue[ $action_key ] ) ) {
		$queue[ $action_key ] = array(
			'action' => array(),
			'state' => 'queued',
			'note' => '',
			'updated_at' => current_time( 'mysql' ),
		);
	}
	$queue[ $action_key ]['state'] = sanitize_key( $state );
	$queue[ $action_key ]['note'] = sanitize_text_field( (string) $note );
	$queue[ $action_key ]['updated_at'] = current_time( 'mysql' );
}

private function persist_action_queue( $thread_key, $queue ) {
	if ( empty( $queue ) || ! is_array( $queue ) ) {
		return;
	}
	$thread_key = sanitize_key( (string) $thread_key );
	if ( '' === (string) $thread_key ) {
		return;
	}
	update_option( $this->queue_option_name( $thread_key ), $queue, false );
}
}
