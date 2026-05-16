<?php
/**
 * Meesho Master SEO Module
 */

class Meesho_Master_SEO {
public function __construct() {
add_action( 'wp_ajax_meesho_run_seo_crawl', array( $this, 'ajax_run_crawl' ) );
add_action( 'wp_ajax_mm_run_seo_scan', array( $this, 'ajax_run_crawl' ) );
add_action( 'wp_ajax_meesho_get_seo_scores', array( $this, 'ajax_get_scores' ) );
add_action( 'wp_ajax_meesho_get_suggestions', array( $this, 'ajax_get_suggestions' ) );
add_action( 'wp_ajax_meesho_apply_suggestion', array( $this, 'ajax_apply_suggestion' ) );
add_action( 'wp_ajax_meesho_apply_all_safe', array( $this, 'ajax_apply_all_safe' ) );
add_action( 'wp_ajax_meesho_reject_suggestion', array( $this, 'ajax_reject_suggestion' ) );
add_action( 'wp_ajax_meesho_generate_llms_txt', array( $this, 'ajax_generate_llms_txt' ) );
add_action( 'wp_ajax_mm_research_keywords', array( $this, 'ajax_research_keywords' ) );
add_action( 'wp_ajax_mm_list_targetable_posts', array( $this, 'ajax_list_targetable_posts' ) );
// v6.5 — flat handlers for new dashboard JS
add_action( 'wp_ajax_mm_seo_list_scores', array( $this, 'ajax_list_scores' ) );
add_action( 'wp_ajax_mm_seo_score_trends', array( $this, 'ajax_score_trends' ) );
add_action( 'mm_seo_run_morning', array( $this, 'run_scheduled_batch' ) );
add_action( 'mm_seo_run_evening', array( $this, 'run_scheduled_batch' ) );
add_action( 'admin_notices', array( $this, 'maybe_render_stale_run_notice' ) );
$this->maybe_schedule_cron();
}

/**
 * Return all posts/pages/products targetable for SEO scans.
 * Includes existing scores so the UI can show progress at a glance.
 */
public function ajax_list_targetable_posts() {
meesho_master_verify_ajax_nonce();
if ( ! current_user_can( 'manage_options' ) ) {
wp_send_json_error( 'Unauthorized' );
}
// v6.5 — accept both 'post_type' (legacy) and 'type' (new dropdown)
$post_type = isset( $_POST['post_type'] )
	? sanitize_key( $_POST['post_type'] )
	: ( isset( $_POST['type'] ) ? sanitize_key( $_POST['type'] ) : 'any' );
$search    = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) )
	: ( isset( $_POST['q'] ) ? sanitize_text_field( wp_unslash( $_POST['q'] ) ) : '' );
$paged     = isset( $_POST['paged'] ) ? max( 1, absint( $_POST['paged'] ) ) : 1;
$flat_mode = ! empty( $_POST['all'] );  // v6.5 — return a flat array of {id,title,post_type}

$args = array(
'post_type'      => 'any' === $post_type
? array_values( array_diff( get_post_types( array( 'public' => true ) ), array( 'attachment' ) ) )
: $post_type,
'post_status'    => array( 'publish', 'draft', 'private' ),
'posts_per_page' => $flat_mode ? 500 : 50,
'paged'          => $paged,
'orderby'        => 'modified',
'order'          => 'DESC',
);
if ( $search ) {
$args['s'] = $search;
}
$query = new WP_Query( $args );

// v6.5 — flat shape for the new dropdown selector
if ( $flat_mode ) {
	$flat = array();
	foreach ( $query->posts as $p ) {
		$flat[] = array(
			'id'        => $p->ID,
			'title'     => $p->post_title ?: '(no title)',
			'post_type' => $p->post_type,
		);
	}
	wp_send_json_success( $flat );
	return;
}

global $wpdb;
$score_table = MM_DB::table( 'seo_post_scores' );
$out = array();
foreach ( $query->posts as $p ) {
$score = $wpdb->get_row( $wpdb->prepare(
"SELECT seo_score, aeo_score, geo_score, last_scanned FROM {$score_table} WHERE post_id = %d",
$p->ID
) );
$out[] = array(
'id'           => $p->ID,
'title'        => $p->post_title ?: '(no title)',
'type'         => $p->post_type,
'status'       => $p->post_status,
'modified'     => $p->post_modified,
'edit_url'     => get_edit_post_link( $p->ID, 'raw' ),
'view_url'     => get_permalink( $p->ID ),
'seo_score'    => $score ? intval( $score->seo_score ) : null,
'aeo_score'    => $score ? intval( $score->aeo_score ) : null,
'geo_score'    => $score ? intval( $score->geo_score ) : null,
'last_scanned' => $score ? $score->last_scanned : null,
);
}

wp_send_json_success( array(
'posts'       => $out,
'total'       => intval( $query->found_posts ),
'paged'       => $paged,
'total_pages' => intval( $query->max_num_pages ),
'post_types'  => array_values( array_diff( get_post_types( array( 'public' => true ) ), array( 'attachment' ) ) ),
) );
}

private function maybe_schedule_cron() {
$settings = new Meesho_Master_Settings();
if ( 'yes' !== $settings->get( 'automation_enabled', 'yes' ) ) {
return;
}
$this->schedule_daily_event( 'mm_seo_run_morning', '08:00' );
$this->schedule_daily_event( 'mm_seo_run_evening', '20:00' );
}

private function schedule_daily_event( $hook, $time_string ) {
if ( wp_next_scheduled( $hook ) ) {
return;
}
$tz    = new DateTimeZone( 'Asia/Kolkata' );
$now   = new DateTimeImmutable( 'now', $tz );
$event = DateTimeImmutable::createFromFormat( 'Y-m-d H:i', $now->format( 'Y-m-d' ) . ' ' . $time_string, $tz );
if ( $event <= $now ) {
$event = $event->modify( '+1 day' );
}
wp_schedule_event( $event->getTimestamp(), 'daily', $hook );
}

public function maybe_render_stale_run_notice() {
if ( ! current_user_can( 'manage_options' ) ) {
return;
}
$last_run = (int) get_option( 'mm_seo_last_run_ts', 0 );
if ( $last_run && ( time() - $last_run ) <= 90000 ) {
return;
}
$url = wp_nonce_url( admin_url( 'admin.php?page=meesho-master&tab=seo' ), 'mm_nonce', 'mm_run_notice' );
echo '<div class="notice notice-warning"><p>SEO scan hasn\'t run in 25+ hours. <a href="' . esc_url( $url ) . '">Open SEO tab and run now</a>.</p></div>';
}

public function crawl_page( $post_id ) {
return MM_SEO_Crawler::collect_post_data( $post_id );
}

public function detect_seo_plugin() {
return MM_SEO_Crawler::detect_seo_plugin();
}

public function get_meta_keys() {
return MM_SEO_Crawler::get_meta_keys();
}

public function run_scheduled_batch() {
return $this->run_scan( 'cron' );
}

public function run_scan( $trigger_type = 'manual', $selected_post_ids = array() ) {
global $wpdb;

$run_table  = MM_DB::table( 'seo_runs' );
$score_table = MM_DB::table( 'seo_post_scores' );
$wpdb->insert(
$run_table,
array(
'trigger_type' => sanitize_text_field( $trigger_type ),
'status'       => 'running',
'started_at'   => current_time( 'mysql' ),
),
array( '%s', '%s', '%s' )
);
$run_id = (int) $wpdb->insert_id;

$posts = ! empty( $selected_post_ids ) ? array_map( 'absint', $selected_post_ids ) : $this->get_priority_posts();
$scorer = new MM_SEO_Scorer();
$analyzer = new MM_SEO_Analyzer();
$implementor = new MM_SEO_Implementor();
$processed = 0;
$created = 0;
$applied = 0;
$failed = 0;
$error_log = array();

foreach ( $posts as $post_id ) {
$data = MM_SEO_Crawler::collect_post_data( $post_id );
if ( empty( $data ) ) {
$failed++;
continue;
}
$scores = $scorer->score( $data );
$this->persist_scores( $post_id, $data, $scores, $run_id );
$processed++;

$suggestions = $analyzer->analyze( $data );
if ( is_wp_error( $suggestions ) ) {
$failed++;
$error_log[] = sprintf( 'Post %d: %s', $post_id, $suggestions->get_error_message() );
break;
}

$stored = $this->store_suggestions( $post_id, $suggestions, $scores, $run_id );
$created += count( $stored );

foreach ( $stored as $suggestion ) {
if ( MM_SEO_Safety::can_auto_apply( $suggestion ) || ( 'schema' === $suggestion['type'] && MM_SEO_Safety::can_auto_apply_schema( $suggestion, $data['existing_schema'] ?? '' ) ) ) {
$result = $implementor->apply( $suggestion, 'ai_auto' );
if ( ! is_wp_error( $result ) ) {
$applied++;
}
}
}

sleep( 1 );
}

$wpdb->update(
$run_table,
array(
'posts_scanned'       => $processed,
'suggestions_created' => $created,
'suggestions_applied' => $applied,
'failed_posts'        => $failed,
'status'              => $failed > 0 ? 'partial_fail' : 'done',
'error_log'           => implode( "\n", $error_log ),
'finished_at'         => current_time( 'mysql' ),
),
array( 'id' => $run_id ),
array( '%d', '%d', '%d', '%d', '%s', '%s', '%s' ),
array( '%d' )
);
update_option( 'mm_seo_last_run_ts', time() );

return array(
'run_id'     => $run_id,
'processed'  => $processed,
'created'    => $created,
'applied'    => $applied,
'failed'     => $failed,
'error_log'  => implode( "\n", $error_log ),
);
}

private function get_priority_posts() {
global $wpdb;
$settings   = new Meesho_Master_Settings();
$batch_size = max( 1, min( 10, (int) $settings->get( 'automation_batch_size', 5 ) ) );
$score_table = MM_DB::table( 'seo_post_scores' );
$query = "SELECT p.ID
FROM {$wpdb->posts} p
LEFT JOIN {$score_table} s ON s.post_id = p.ID
WHERE p.post_status = 'publish'
AND p.post_type IN ('post','page','product')
ORDER BY
CASE
WHEN s.post_id IS NULL THEN 0
WHEN s.last_scanned IS NULL THEN 1
WHEN p.post_modified_gmt > s.last_scanned THEN 1
ELSE 2
END ASC,
COALESCE(s.last_scanned, '1970-01-01 00:00:00') ASC,
p.ID DESC
LIMIT %d";
return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( $query, $batch_size ) ) );
}

private function persist_scores( $post_id, $data, $scores, $run_id ) {
global $wpdb;
$score_table   = MM_DB::table( 'seo_post_scores' );
$history_table = MM_DB::table( 'seo_score_history' );
$existing_id   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$score_table} WHERE post_id = %d", $post_id ) );
$payload = array(
'post_id'       => $post_id,
'post_type'     => sanitize_text_field( $data['post_type'] ?? 'post' ),
'seo_score'     => (int) $scores['seo'],
'aeo_score'     => (int) $scores['aeo'],
'geo_score'     => (int) $scores['geo'],
'focus_keyword' => sanitize_text_field( $scores['keyword'] ?? '' ),
'last_scanned'  => current_time( 'mysql' ),
'scan_count'    => $existing_id ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT scan_count FROM {$score_table} WHERE id = %d", $existing_id ) ) + 1 : 1,
);
if ( $existing_id ) {
$wpdb->update( $score_table, $payload, array( 'id' => $existing_id ), array( '%d', '%s', '%d', '%d', '%d', '%s', '%s', '%d' ), array( '%d' ) );
} else {
$wpdb->insert( $score_table, $payload, array( '%d', '%s', '%d', '%d', '%d', '%s', '%s', '%d' ) );
}
$wpdb->insert(
$history_table,
array(
'post_id'     => $post_id,
'seo_score'   => (int) $scores['seo'],
'aeo_score'   => (int) $scores['aeo'],
'geo_score'   => (int) $scores['geo'],
'run_id'      => $run_id,
'recorded_at' => current_time( 'mysql' ),
),
array( '%d', '%d', '%d', '%d', '%d', '%s' )
);
update_post_meta( $post_id, '_meesho_seo_score', (int) $scores['seo'] );
update_post_meta( $post_id, '_meesho_aeo_score', (int) $scores['aeo'] );
update_post_meta( $post_id, '_meesho_geo_score', (int) $scores['geo'] );
}

private function store_suggestions( $post_id, $suggestions, $scores, $run_id = 0 ) {
global $wpdb;
$table  = MM_DB::table( 'seo_suggestions' );
$stored = array();
$types  = array();
foreach ( $suggestions as $suggestion ) {
$type = $this->normalize_suggestion_type( $suggestion['type'] ?? '' );
if ( '' === $type ) {
continue;
}
$types[] = $type;
$suggested_value = wp_kses_post( (string) ( $suggestion['suggested_value'] ?? '' ) );
if ( '' === trim( wp_strip_all_tags( $suggested_value ) ) ) {
	continue;
}
if ( $this->has_pending_suggestion( $table, $post_id, $type, $suggested_value ) ) {
	continue;
}
$payload = array(
'post_id'         => $post_id,
'suggestion_type' => $type,
'current_value'   => wp_kses_post( (string) ( $suggestion['current_value'] ?? '' ) ),
'suggested_value' => $suggested_value,
'reasoning'       => sanitize_textarea_field( $suggestion['reasoning'] ?? '' ),
'priority'        => $this->normalize_priority( $suggestion['priority'] ?? 'medium' ),
'confidence'      => max( 0, min( 100, (int) ( $suggestion['confidence'] ?? 0 ) ) ),
'safe_to_apply'   => ! empty( $suggestion['safe_to_apply'] ) ? 1 : 0,
'status'          => 'pending',
'seo_score'       => (int) ( $scores['seo'] ?? 0 ),
'aeo_score'       => (int) ( $scores['aeo'] ?? 0 ),
'geo_score'       => (int) ( $scores['geo'] ?? 0 ),
'run_id'          => (int) $run_id,
'created_at'      => current_time( 'mysql' ),
);
$wpdb->insert( $table, $payload, array( '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%d', '%d', '%d', '%d', '%s' ) );
$payload['type'] = $type;
$payload['id'] = (int) $wpdb->insert_id;
$stored[]      = $payload;
}

if ( isset( $scores['breakdown']['geo']['factual'] ) && 0 === (int) $scores['breakdown']['geo']['factual'] && ! in_array( 'statistics_inject', $types, true ) ) {
	$stats_value = 'Add one verified factual sentence with a current statistic relevant to this topic.';
	if ( ! $this->has_pending_suggestion( $table, $post_id, 'statistics_inject', $stats_value ) ) {
$wpdb->insert(
$table,
array(
'post_id'         => $post_id,
'suggestion_type' => 'statistics_inject',
'current_value'   => '',
'suggested_value' => $stats_value,
'reasoning'       => 'Factual density is too low for GEO scoring.',
'priority'        => 'medium',
'confidence'      => 80,
'safe_to_apply'   => 0,
'status'          => 'pending',
'seo_score'       => (int) ( $scores['seo'] ?? 0 ),
'aeo_score'       => (int) ( $scores['aeo'] ?? 0 ),
'geo_score'       => (int) ( $scores['geo'] ?? 0 ),
'run_id'          => (int) $run_id,
'created_at'      => current_time( 'mysql' ),
),
array( '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%d', '%d', '%d', '%d', '%s' )
);
$stored[] = array(
'id'              => (int) $wpdb->insert_id,
'post_id'         => $post_id,
'type'            => 'statistics_inject',
'current_value'   => '',
'suggested_value' => $stats_value,
'reasoning'       => 'Factual density is too low for GEO scoring.',
'priority'        => 'medium',
'confidence'      => 80,
'safe_to_apply'   => 0,
);
	}
}

return $stored;
}

public function apply_suggestion( $suggestion_id, $actor = 'manual' ) {
global $wpdb;
$table = MM_DB::table( 'seo_suggestions' );
$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $suggestion_id ), ARRAY_A );
if ( empty( $row ) ) {
return new WP_Error( 'not_found', 'Suggestion not found.' );
}
$row = $this->normalize_suggestion_row( $row );
$implementor = new MM_SEO_Implementor();
return $implementor->apply( $row, $actor );
}

public static function inject_schema() {
if ( ! is_singular() ) {
return;
}
$post_id = get_the_ID();
$data    = MM_SEO_Crawler::collect_post_data( $post_id );
if ( 'seo_plugin' === ( $data['schema_source'] ?? '' ) ) {
return;
}
$schema = get_post_meta( $post_id, '_mm_schema_json', true );
if ( '' === $schema ) {
$schema = get_post_meta( $post_id, '_meesho_schema_jsonld', true );
}
if ( '' !== trim( $schema ) && null !== json_decode( $schema, true ) ) {
echo '<script type="application/ld+json">' . wp_kses_post( $schema ) . '</script>' . "\n";
}
}

public static function inject_fallback_meta() {
if ( ! is_singular() || 'none' !== MM_SEO_Crawler::detect_seo_plugin() ) {
return;
}
$post_id = get_the_ID();
$title   = get_post_meta( $post_id, '_mm_seo_title', true );
$desc    = get_post_meta( $post_id, '_mm_seo_desc', true );
if ( $title ) {
echo '<meta name="title" content="' . esc_attr( $title ) . '">' . "\n";
}
if ( $desc ) {
echo '<meta name="description" content="' . esc_attr( $desc ) . '">' . "\n";
}
}

public function ajax_run_crawl() {
meesho_master_verify_ajax_nonce();
if ( ! current_user_can( 'manage_options' ) ) {
wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
}
$selected = isset( $_POST['post_ids'] ) ? array_map( 'absint', (array) $_POST['post_ids'] ) : array();
$selected = array_values( array_filter( $selected ) );
$result = $this->run_scan( 'manual', $selected );
wp_send_json_success( $result );
}

public function ajax_get_scores() {
meesho_master_verify_ajax_nonce();
if ( ! current_user_can( 'manage_options' ) ) {
wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
}
$post_id = absint( $_POST['post_id'] ?? 0 );
$data    = MM_SEO_Crawler::collect_post_data( $post_id );
$scores  = ( new MM_SEO_Scorer() )->score( $data );
wp_send_json_success( $scores );
}

public function ajax_get_suggestions() {
meesho_master_verify_ajax_nonce();
if ( ! current_user_can( 'manage_options' ) ) {
wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
}
global $wpdb;
$table = MM_DB::table( 'seo_suggestions' );
$where = array( 'status = %s' );
$params = array( 'pending' );
$priority = sanitize_text_field( wp_unslash( $_POST['priority'] ?? '' ) );
$type     = sanitize_text_field( wp_unslash( $_POST['type'] ?? '' ) );
if ( '' !== $priority ) {
$where[]  = 'priority = %s';
$params[] = $priority;
}
if ( '' !== $type ) {
$where[]  = 'suggestion_type = %s';
$params[] = $type;
}
$params[] = 50;
$sql = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY created_at DESC LIMIT %d';
$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ) );
// v6.5 — enrich with clickable post info
if ( is_array( $rows ) ) {
	foreach ( $rows as &$r ) {
		$r = $this->normalize_suggestion_row( $r );
		if ( ! empty( $r->post_id ) ) {
			$r->post_title = get_the_title( $r->post_id ) ?: '(no title)';
			$r->edit_url   = get_edit_post_link( $r->post_id, 'raw' );
		}
	}
	unset( $r );
}
wp_send_json_success( $rows );
}

public function ajax_apply_suggestion() {
meesho_master_verify_ajax_nonce();
if ( ! current_user_can( 'manage_options' ) ) {
wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
}
$id = absint( $_POST['suggestion_id'] ?? 0 );
$result = $this->apply_suggestion( $id, 'manual' );
if ( is_wp_error( $result ) ) {
wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
}
wp_send_json_success( 'Suggestion applied.' );
}

public function ajax_apply_all_safe() {
meesho_master_verify_ajax_nonce();
if ( ! current_user_can( 'manage_options' ) ) {
wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
}
global $wpdb;
$table = MM_DB::table( 'seo_suggestions' );
$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s", 'pending' ), ARRAY_A );
$applied = 0;
foreach ( $rows as $row ) {
	$row = $this->normalize_suggestion_row( $row );
if ( MM_SEO_Safety::can_auto_apply( $row ) ) {
$result = $this->apply_suggestion( (int) $row['id'], 'ai_auto' );
if ( ! is_wp_error( $result ) ) {
$applied++;
}
}
}
wp_send_json_success( sprintf( '%d safe suggestions applied.', $applied ) );
}

public function ajax_reject_suggestion() {
meesho_master_verify_ajax_nonce();
if ( ! current_user_can( 'manage_options' ) ) {
wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
}
global $wpdb;
$id = absint( $_POST['suggestion_id'] ?? 0 );
$wpdb->update( MM_DB::table( 'seo_suggestions' ), array( 'status' => 'rejected' ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
wp_send_json_success( 'Suggestion rejected.' );
}

public function ajax_generate_llms_txt() {
meesho_master_verify_ajax_nonce();
if ( ! current_user_can( 'manage_options' ) ) {
wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
}
$result = MM_SEO_Geo::generate_llms_txt();
if ( is_wp_error( $result ) ) {
wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
}
wp_send_json_success( array( 'message' => 'llms.txt generated.', 'content' => $result ) );
}

	public function ajax_research_keywords() {
		meesho_master_verify_ajax_nonce();
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
		}
		$post_id = absint( $_POST['post_id'] ?? 0 );
		if ( ! $post_id ) {
			wp_send_json_error( array( 'message' => 'Invalid post ID.' ), 400 );
		}
		$data = MM_SEO_Crawler::collect_post_data( $post_id );
		if ( empty( $data ) ) {
			wp_send_json_error( array( 'message' => 'Could not collect post data.' ), 400 );
		}
		$analyzer = new MM_SEO_Analyzer();
		$keywords = $analyzer->research_keywords( $data );
		if ( is_wp_error( $keywords ) ) {
			wp_send_json_error( array( 'message' => $keywords->get_error_message() ), 400 );
		}
		if ( ! is_array( $keywords ) || empty( $keywords ) ) {
			wp_send_json_error( array( 'message' => 'No keywords returned from research.' ), 400 );
		}
		wp_send_json_success( $keywords );
	}

	/**
	 * v6.5 — Flat list of scored posts for the Dashboard table.
	 * Joins seo_post_scores → posts and includes counts of pending suggestions.
	 */
	public function ajax_list_scores() {
		meesho_master_verify_ajax_nonce();
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
		}
		global $wpdb;
		$scores = MM_DB::table( 'seo_post_scores' );
		$sugg   = MM_DB::table( 'seo_suggestions' );
		$rows = $wpdb->get_results(
			"SELECT s.post_id, s.seo_score, s.aeo_score, s.geo_score, s.last_scanned,
				p.post_title, p.post_type
				FROM {$scores} s LEFT JOIN {$wpdb->posts} p ON p.ID = s.post_id
				ORDER BY s.last_scanned DESC LIMIT 100",
			ARRAY_A
		);
		if ( ! is_array( $rows ) ) {
			$rows = array();
		}
		$out = array();
		foreach ( $rows as $r ) {
			$count = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$sugg} WHERE post_id = %d AND status = 'pending'",
				$r['post_id']
			) );
			$out[] = array(
				'post_id'          => (int) $r['post_id'],
				'title'            => $r['post_title'] ?: '(no title)',
				'post_type'        => $r['post_type'] ?: '',
				'seo_score'        => $r['seo_score'] !== null ? (int) $r['seo_score'] : null,
				'aeo_score'        => $r['aeo_score'] !== null ? (int) $r['aeo_score'] : null,
				'geo_score'        => $r['geo_score'] !== null ? (int) $r['geo_score'] : null,
				'last_scanned'     => $r['last_scanned'],
				'suggestion_count' => $count,
				'edit_url'         => get_edit_post_link( $r['post_id'], 'raw' ),
				'permalink'        => get_permalink( $r['post_id'] ),
			);
		}
		wp_send_json_success( $out );
	}

	/**
	 * v6.5 — Daily trend rollup for the Score Trends panel.
	 */
	public function ajax_score_trends() {
		meesho_master_verify_ajax_nonce();
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
		}
		global $wpdb;
		$scores = MM_DB::table( 'seo_post_scores' );
		// Daily averages over the last 30 days
		$rows = $wpdb->get_results(
			"SELECT DATE(last_scanned) AS day,
				ROUND(AVG(seo_score)) AS avg_seo,
				ROUND(AVG(aeo_score)) AS avg_aeo,
				ROUND(AVG(geo_score)) AS avg_geo,
				COUNT(*) AS pages
				FROM {$scores}
				WHERE last_scanned >= DATE_SUB(NOW(), INTERVAL 30 DAY)
				GROUP BY DATE(last_scanned)
				ORDER BY day DESC",
			ARRAY_A
		);
		wp_send_json_success( $rows ?: array() );
	}

	private function has_pending_suggestion( $table, $post_id, $type, $suggested_value ) {
		global $wpdb;

		$exists = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE post_id = %d AND suggestion_type = %s AND suggested_value = %s AND status = %s LIMIT 1",
			$post_id,
			$type,
			$suggested_value,
			'pending'
		) );

		return $exists > 0;
	}

	private function normalize_suggestion_type( $type ) {
		$type = sanitize_key( (string) $type );
		$allowed = array( 'meta_title', 'meta_desc', 'alt_tag', 'internal_link', 'content', 'schema', 'faq', 'howto_schema', 'llms_txt', 'citability_block', 'statistics_inject' );
		return in_array( $type, $allowed, true ) ? $type : '';
	}

	private function normalize_priority( $priority ) {
		$priority = sanitize_key( (string) $priority );
		$allowed  = array( 'high', 'medium', 'low' );
		return in_array( $priority, $allowed, true ) ? $priority : 'medium';
	}

	private function normalize_suggestion_row( $row ) {
		$type = '';
		$suggestion_type = '';
		if ( is_array( $row ) ) {
			$type            = (string) ( $row['type'] ?? '' );
			$suggestion_type = (string) ( $row['suggestion_type'] ?? '' );
		} elseif ( is_object( $row ) ) {
			$type            = (string) ( $row->type ?? '' );
			$suggestion_type = (string) ( $row->suggestion_type ?? '' );
		}

		if ( '' === $type && '' !== $suggestion_type ) {
			if ( is_array( $row ) ) {
				$row['type'] = $suggestion_type;
			} elseif ( is_object( $row ) ) {
				$row->type = $suggestion_type;
			}
		}

		return $row;
	}
}
