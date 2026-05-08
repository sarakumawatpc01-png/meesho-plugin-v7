<?php

if ( ! class_exists( 'MM_Scheduler' ) ) {
class MM_Scheduler {
const MORNING_OPTION = 'mm_seo_run_time_morning';
const EVENING_OPTION = 'mm_seo_run_time_evening';
const LAST_RUN_OPTION = 'mm_seo_last_run_ts';
const STALE_HOURS = 25;

public static function init() {
add_action( 'mm_seo_run_morning', array( __CLASS__, 'run_morning_scan' ) );
add_action( 'mm_seo_run_evening', array( __CLASS__, 'run_evening_scan' ) );
add_action( 'admin_notices', array( __CLASS__, 'stale_scan_notice' ) );
add_action( 'wp_ajax_mm_run_seo_scan', array( __CLASS__, 'ajax_run_scan' ) );
}

public static function activate() {
self::schedule_morning();
self::schedule_evening();
if ( ! wp_next_scheduled( 'mm_purge_old_logs' ) ) {
wp_schedule_event( time(), 'weekly', 'mm_purge_old_logs' );
}
}

public static function deactivate() {
wp_clear_scheduled_hook( 'mm_seo_run_morning' );
wp_clear_scheduled_hook( 'mm_seo_run_evening' );
wp_clear_scheduled_hook( 'mm_purge_old_logs' );
}

private static function schedule_morning() {
if ( wp_next_scheduled( 'mm_seo_run_morning' ) ) {
wp_clear_scheduled_hook( 'mm_seo_run_morning' );
}
$morning_time = get_option( self::MORNING_OPTION, '08:00' );
$timestamp = self::get_ist_timestamp( $morning_time );
wp_schedule_event( $timestamp, 'daily', 'mm_seo_run_morning' );
}

private static function schedule_evening() {
if ( wp_next_scheduled( 'mm_seo_run_evening' ) ) {
wp_clear_scheduled_hook( 'mm_seo_run_evening' );
}
$evening_time = get_option( self::EVENING_OPTION, '20:00' );
$timestamp = self::get_ist_timestamp( $evening_time );
wp_schedule_event( $timestamp, 'daily', 'mm_seo_run_evening' );
}

private static function get_ist_timestamp( $time_string ) {
$dt = new DateTime( $time_string, new DateTimeZone( 'Asia/Kolkata' ) );
$dt->setTimezone( new DateTimeZone( 'UTC' ) );
return $dt->getTimestamp();
}

public static function run_morning_scan() {
self::run_scan( 'cron', 'morning' );
}

public static function run_evening_scan() {
self::run_scan( 'cron', 'evening' );
}

private static function run_scan( $trigger_type = 'cron', $label = '' ) {
global $wpdb;

$run_table = MM_DB::table( 'seo_runs' );
$wpdb->insert(
$run_table,
array(
'trigger_type' => $trigger_type,
'status'       => 'running',
'started_at'   => current_time( 'mysql' ),
),
array( '%s', '%s', '%s' )
);
$run_id = $wpdb->insert_id;

$posts_scanned = 0;
$suggestions_created = 0;
$suggestions_applied = 0;
$failed_posts = 0;
$error_log = array();

// Priority 1: Never scanned posts
$never_scanned = self::get_posts_to_scan( 'never_scanned', 10 );
// Priority 2: Modified posts
$modified = self::get_posts_to_scan( 'modified', 10 - count( $never_scanned ) );
// Priority 3: Oldest scanned
$oldest = self::get_posts_to_scan( 'oldest', 10 - count( $never_scanned ) - count( $modified ) );

$posts = array_merge( $never_scanned, $modified, $oldest );

foreach ( $posts as $post_id ) {
$data = MM_SEO_Crawler::collect_post_data( $post_id );
if ( empty( $data ) ) {
$failed_posts++;
$error_log[] = "Failed to collect data for post {$post_id}";
continue;
}

// Score with PHP
$scores = MM_SEO_Scorer::score( $data );
self::upsert_post_scores( $post_id, $data['post_type'], $scores, $run_id );

		// Keyword research (if GSC API available)
		$analyzer = new MM_SEO_Analyzer();
		$keyword_suggestions = $analyzer->research_keywords( $data );
		if ( ! is_wp_error( $keyword_suggestions ) && is_array( $keyword_suggestions ) ) {
			$keyword_table = MM_DB::table( 'seo_suggestions' );
			foreach ( $keyword_suggestions as $kw ) {
				$wpdb->insert(
					$keyword_table,
					array(
						'post_id'         => $post_id,
						'type'            => 'keyword_research',
						'current_value'   => '',
						'suggested_value' => sanitize_text_field( $kw['keyword'] ?? '' ),
						'reasoning'       => sprintf(
							'Intent: %s, Relevance: %d%%, GSC Clicks: %d, Position: %s',
							$kw['intent'] ?? 'unknown',
							$kw['relevance'] ?? 0,
							$kw['gsc_clicks'] ?? 0,
							$kw['gsc_position'] ?? 'N/A'
						),
						'priority'        => ( $kw['relevance'] ?? 0 ) >= 80 ? 'high' : ( ( $kw['relevance'] ?? 0 ) >= 50 ? 'medium' : 'low' ),
						'confidence'      => min( 100, intval( $kw['relevance'] ?? 0 ) ),
						'safe_to_apply'  => 0, // Keywords need manual review
						'seo_score'      => $scores['seo'] ?? 0,
						'aeo_score'      => $scores['aeo'] ?? 0,
						'geo_score'      => $scores['geo'] ?? 0,
						'run_id'         => $run_id,
						'status'         => 'pending',
					),
					array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%s' )
				);
				$suggestions_created++;
			}
		}

		// Analyze with AI
$suggestions = $analyzer->analyze( $data );
if ( is_wp_error( $suggestions ) ) {
$failed_posts++;
$error_log[] = "Post {$post_id}: " . $suggestions->get_error_message();
continue;
}

// Store suggestions
$suggestion_table = MM_DB::table( 'seo_suggestions' );
foreach ( $suggestions as $suggestion ) {
$wpdb->insert(
$suggestion_table,
array(
'post_id'       => $post_id,
'suggestion_type' => $suggestion['type'] ?? 'meta_title',
'current_value'  => $suggestion['current_value'] ?? '',
'suggested_value' => $suggestion['suggested_value'] ?? '',
'reasoning'      => $suggestion['reasoning'] ?? '',
'priority'       => $suggestion['priority'] ?? 'medium',
'confidence'     => (int) ( $suggestion['confidence'] ?? 0 ),
'safe_to_apply'  => ! empty( $suggestion['safe_to_apply'] ) ? 1 : 0,
'seo_score'      => $scores['seo'] ?? 0,
'aeo_score'      => $scores['aeo'] ?? 0,
'geo_score'      => $scores['geo'] ?? 0,
'run_id'         => $run_id,
'status'         => 'pending',
),
array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%s' )
);
$suggestions_created++;

// Auto-apply if safe
if ( MM_SEO_Safety::can_auto_apply( $suggestion ) ) {
$implementor = new MM_SEO_Implementor();
$result = $implementor->apply( $suggestion, 'ai_auto' );
if ( ! is_wp_error( $result ) ) {
$suggestions_applied++;
}
}
}

$posts_scanned++;
sleep( 1 ); // Rate limiting
}

$status = $failed_posts > 0 ? 'partial_fail' : 'done';
$wpdb->update(
$run_table,
array(
'status'              => $status,
'posts_scanned'       => $posts_scanned,
'suggestions_created' => $suggestions_created,
'suggestions_applied' => $suggestions_applied,
'failed_posts'        => $failed_posts,
'error_log'           => implode( "\n", $error_log ),
'finished_at'         => current_time( 'mysql' ),
),
array( 'id' => $run_id ),
array( '%s', '%d', '%d', '%d', '%d', '%s', '%s' ),
array( '%d' )
);

update_option( self::LAST_RUN_OPTION, time() );
}

private static function get_posts_to_scan( $priority, $limit ) {
global $wpdb;

$score_table = MM_DB::table( 'seo_post_scores' );
$post_types = array( 'post', 'page', 'product' );
$post_types_in = "'" . implode( "','", $post_types ) . "'";

switch ( $priority ) {
case 'never_scanned':
return $wpdb->get_col(
$wpdb->prepare(
"SELECT p.ID FROM {$wpdb->posts} p
LEFT JOIN {$score_table} s ON p.ID = s.post_id
WHERE p.post_type IN ({$post_types_in}) AND p.post_status = 'publish' AND s.post_id IS NULL
ORDER BY p.post_date DESC LIMIT %d",
$limit
)
);

case 'modified':
return $wpdb->get_col(
$wpdb->prepare(
"SELECT p.ID FROM {$wpdb->posts} p
INNER JOIN {$score_table} s ON p.ID = s.post_id
WHERE p.post_type IN ({$post_types_in}) AND p.post_status = 'publish' AND p.post_modified > s.last_scanned
ORDER BY p.post_modified DESC LIMIT %d",
$limit
)
);

case 'oldest':
return $wpdb->get_col(
$wpdb->prepare(
"SELECT p.ID FROM {$wpdb->posts} p
INNER JOIN {$score_table} s ON p.ID = s.post_id
WHERE p.post_type IN ({$post_types_in}) AND p.post_status = 'publish'
ORDER BY s.last_scanned ASC LIMIT %d",
$limit
)
);

default:
return array();
}
}

private static function upsert_post_scores( $post_id, $post_type, $scores, $run_id ) {
global $wpdb;

$table = MM_DB::table( 'seo_post_scores' );
$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE post_id = %d", $post_id ) );

if ( $exists ) {
$wpdb->update(
$table,
array(
'post_type'    => $post_type,
'seo_score'    => $scores['seo'] ?? 0,
'aeo_score'    => $scores['aeo'] ?? 0,
'geo_score'    => $scores['geo'] ?? 0,
'focus_keyword' => $scores['keyword'] ?? '',
'last_scanned' => current_time( 'mysql' ),
'scan_count'   => new RuntimeException( 'scan_count + 1' ),
),
array( 'post_id' => $post_id ),
array( '%s', '%d', '%d', '%d', '%s', '%s', '%d' ),
array( '%d' )
);
} else {
$wpdb->insert(
$table,
array(
'post_id'      => $post_id,
'post_type'    => $post_type,
'seo_score'    => $scores['seo'] ?? 0,
'aeo_score'    => $scores['aeo'] ?? 0,
'geo_score'    => $scores['geo'] ?? 0,
'focus_keyword' => $scores['keyword'] ?? '',
'last_scanned' => current_time( 'mysql' ),
'scan_count'   => 1,
),
array( '%d', '%s', '%d', '%d', '%d', '%s', '%s', '%d' )
);
}

// Insert history
$history_table = MM_DB::table( 'seo_score_history' );
$wpdb->insert(
$history_table,
array(
'post_id'   => $post_id,
'seo_score' => $scores['seo'] ?? 0,
'aeo_score' => $scores['aeo'] ?? 0,
'geo_score' => $scores['geo'] ?? 0,
'run_id'    => $run_id,
),
array( '%d', '%d', '%d', '%d', '%d' )
);
}

public static function stale_scan_notice() {
$last_run = get_option( self::LAST_RUN_OPTION, 0 );
if ( time() - $last_run < self::STALE_HOURS * 3600 ) {
return;
}
?>
<div class="notice notice-warning">
<p>
<?php _e( 'SEO scan hasn\'t run in 25+ hours.', 'meesho-master' ); ?>
<button type="button" class="button" id="mm-run-scan-now"><?php _e( 'Run Scan Now', 'meesho-master' ); ?></button>
</p>
</div>
<script>
document.getElementById('mm-run-scan-now')?.addEventListener('click', function() {
fetch(ajaxurl, {
method: 'POST',
headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
body: new URLSearchParams({
action: 'mm_run_seo_scan',
nonce: '<?php echo wp_create_nonce( 'mm_nonce' ); ?>'
})
}).then(r => r.json()).then(data => {
if (data.success) alert('Scan completed successfully.');
else alert('Scan failed: ' + (data.data?.message || 'Unknown error'));
});
});
</script>
<?php
}

public static function ajax_run_scan() {
check_ajax_referer( 'mm_nonce', 'nonce' );
if ( ! current_user_can( 'manage_options' ) ) {
wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
}
self::run_scan( 'manual', 'user' );
wp_send_json_success( 'SEO scan completed successfully.' );
}
}
}
