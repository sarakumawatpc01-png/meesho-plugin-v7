<?php

class Meesho_Master_Analytics {
public function __construct() {
add_action( 'wp_ajax_meesho_get_rankings', array( $this, 'ajax_get_rankings' ) );
add_action( 'wp_ajax_meesho_add_keyword', array( $this, 'ajax_add_keyword' ) );
add_action( 'wp_ajax_mm_get_rankings', array( $this, 'ajax_get_rankings' ) );
add_action( 'wp_ajax_mm_add_keyword', array( $this, 'ajax_add_keyword' ) );
add_action( 'wp_ajax_meesho_send_report', array( $this, 'ajax_send_report' ) );
add_action( 'wp_ajax_meesho_get_heatmap_insights', array( $this, 'ajax_heatmap_insights' ) );
add_action( 'wp_ajax_mm_fetch_ga4_data', array( $this, 'ajax_fetch_ga4_data' ) );
add_action( 'wp_ajax_mm_get_integration_status', array( $this, 'ajax_get_integration_status' ) );
add_action( 'wp_head', array( $this, 'inject_hotjar' ) );
// E3: Separate daily/weekly hooks replace the single meesho_email_report hook
add_action( 'mm_send_daily_report', array( $this, 'send_scheduled_report' ) );
add_action( 'mm_send_weekly_report', array( $this, 'send_scheduled_report' ) );
$this->schedule_report_cron();
// Re-schedule when settings are saved
add_action( 'update_option_meesho_master_settings', array( $this, 'reschedule_report_cron' ) );
}

private function schedule_report_cron() {
$settings = new Meesho_Master_Settings();
$freq     = $settings->get( 'email_frequency', 'weekly' );
$hook     = 'daily' === $freq ? 'mm_send_daily_report' : 'mm_send_weekly_report';
if ( ! wp_next_scheduled( $hook ) ) {
	$site_offset  = (float) get_option( 'gmt_offset', 0 ) * HOUR_IN_SECONDS;
	$tomorrow_8am = strtotime( 'tomorrow 08:00:00' ) - (int) $site_offset;
	$recurrence   = 'daily' === $freq ? 'daily' : 'weekly';
	wp_schedule_event( $tomorrow_8am, $recurrence, $hook );
}
}

public function reschedule_report_cron() {
wp_clear_scheduled_hook( 'mm_send_daily_report' );
wp_clear_scheduled_hook( 'mm_send_weekly_report' );
// Also clear legacy hook
wp_clear_scheduled_hook( 'meesho_email_report' );
$this->schedule_report_cron();
}

public function inject_hotjar() {
$settings = new Meesho_Master_Settings();
$site_id  = $settings->get( 'hotjar_site_id' ) ?: $settings->get( 'mm_hotjar_id' );
if ( empty( $site_id ) ) {
return;
}
echo "<!-- Hotjar Tracking Code -->\n<script>(function(h,o,t,j,a,r){h.hj=h.hj||function(){(h.hj.q=h.hj.q||[]).push(arguments)};h._hjSettings={hjid:" . intval( $site_id ) . ",hjsv:6};a=o.getElementsByTagName('head')[0];r=o.createElement('script');r.async=1;r.src=t+h._hjSettings.hjid+j+h._hjSettings.hjsv;a.appendChild(r);})(window,document,'https://static.hotjar.com/c/hotjar-','.js?sv=');</script>\n";
}

public function fetch_gsc_data( $keyword, $force_refresh = false ) {
$cache_key = 'mm_gsc_' . md5( $keyword );
$settings = new Meesho_Master_Settings();
$cache_enabled = 'yes' === $settings->get( 'mm_analytics_cache_enabled', 'yes' );
$cache_ttl_hours = (int) $settings->get( 'mm_analytics_cache_ttl_hours', 4 );
$cache_ttl_hours = min( 168, max( 1, $cache_ttl_hours ) );
if ( $force_refresh ) {
delete_transient( $cache_key );
}
$cached = get_transient( $cache_key );
if ( $cache_enabled && ! $force_refresh && false !== $cached ) {
return $cached;
}
$mode     = $settings->get( 'mm_gsc_mode', 'site_kit' );

// Mode A — proxy through Google Site Kit REST API
if ( 'site_kit' === $mode ) {
if ( ! class_exists( 'Google\Site_Kit\Core\REST_API\REST_Routes' ) ) {
return new WP_Error( 'no_site_kit', 'Google Site Kit is not active. Switch to Service Account mode in Settings → Google APIs.' );
}
$rest_url = rest_url( 'google-site-kit/v1/modules/search-console/data/searchanalytics' );
$body = array(
'startDate'  => gmdate( 'Y-m-d', strtotime( '-30 days' ) ),
'endDate'    => gmdate( 'Y-m-d' ),
'dimensions' => array( 'query', 'page' ),
'rowLimit'   => 50,
);
if ( ! empty( $keyword ) ) {
$body['dimensionFilterGroups'] = array( array( 'filters' => array( array( 'dimension' => 'query', 'operator' => 'contains', 'expression' => $keyword ) ) ) );
}
$response = wp_remote_post( $rest_url, array(
'timeout' => 20,
'headers' => array( 'X-WP-Nonce' => wp_create_nonce( 'wp_rest' ), 'Content-Type' => 'application/json' ),
'body'    => wp_json_encode( $body ),
) );
if ( is_wp_error( $response ) ) {
return $response;
}
$data = json_decode( wp_remote_retrieve_body( $response ), true );
$rows = $data['rows'] ?? $data ?? array();
if ( ! is_array( $rows ) ) {
$rows = array();
}
if ( $cache_enabled ) {
set_transient( $cache_key, $rows, $cache_ttl_hours * HOUR_IN_SECONDS );
}
return $rows;
}

// Mode B — Service Account JWT auth
$sa_json = $settings->get( 'mm_gsc_service_account_json' );
// Fallback: legacy credentials JSON
if ( empty( $sa_json ) ) {
$sa_json = $settings->get( 'mm_gsc_credentials' );
}
if ( empty( $sa_json ) ) {
return new WP_Error( 'no_gsc', 'GSC not configured. Go to Settings → Google APIs → Search Console and add your Service Account JSON.' );
}
$sa = json_decode( $sa_json, true );
// If it is a legacy OAuth client JSON (not service account), fall back to old refresh-token method
if ( ! empty( $sa['installed']['refresh_token'] ) || ! empty( $sa['refresh_token'] ) ) {
$access_token = $this->refresh_gsc_token();
if ( is_wp_error( $access_token ) ) {
return $access_token;
}
} elseif ( ! empty( $sa['private_key'] ) && ! empty( $sa['client_email'] ) ) {
$access_token = $this->gsc_sa_access_token( $sa );
if ( is_wp_error( $access_token ) ) {
return $access_token;
}
} else {
return new WP_Error( 'gsc_config', 'Unrecognised credential format. Paste the full service account JSON from Google Cloud.' );
}

$site_url = home_url();
$req_body = array(
'startDate'  => gmdate( 'Y-m-d', strtotime( '-30 days' ) ),
'endDate'    => gmdate( 'Y-m-d' ),
'dimensions' => array( 'query', 'page' ),
'rowLimit'   => 50,
);
if ( ! empty( $keyword ) ) {
$req_body['dimensionFilterGroups'] = array( array( 'filters' => array( array( 'dimension' => 'query', 'operator' => 'contains', 'expression' => $keyword ) ) ) );
}
$response = wp_remote_post(
'https://searchconsole.googleapis.com/webmasters/v3/sites/' . rawurlencode( $site_url ) . '/searchAnalytics/query',
array(
'timeout' => 20,
'headers' => array( 'Authorization' => 'Bearer ' . $access_token, 'Content-Type' => 'application/json' ),
'body'    => wp_json_encode( $req_body ),
)
);
if ( is_wp_error( $response ) ) {
return $response;
}
$data = json_decode( wp_remote_retrieve_body( $response ), true );
$rows = $data['rows'] ?? array();
if ( $cache_enabled ) {
set_transient( $cache_key, $rows, $cache_ttl_hours * HOUR_IN_SECONDS );
}
return $rows;
}

// F3 — Build short-lived GSC access token from Service Account JSON
private function gsc_sa_access_token( array $sa ) {
if ( ! function_exists( 'openssl_sign' ) ) {
return new WP_Error( 'no_openssl', 'openssl_sign() is not available on this server. Cannot use Service Account auth.' );
}
$now       = time();
$header    = rtrim( strtr( base64_encode( wp_json_encode( array( 'alg' => 'RS256', 'typ' => 'JWT' ) ) ), '+/', '-_' ), '=' );
$claim_set = rtrim( strtr( base64_encode( wp_json_encode( array(
'iss'   => $sa['client_email'],
'scope' => 'https://www.googleapis.com/auth/webmasters.readonly',
'aud'   => 'https://oauth2.googleapis.com/token',
'iat'   => $now,
'exp'   => $now + 3600,
) ) ), '+/', '-_' ), '=' );
$sig_input = $header . '.' . $claim_set;
openssl_sign( $sig_input, $signature, $sa['private_key'], OPENSSL_ALGO_SHA256 );
$jwt = $sig_input . '.' . rtrim( strtr( base64_encode( $signature ), '+/', '-_' ), '=' );
$token_res = wp_remote_post( 'https://oauth2.googleapis.com/token', array(
'timeout' => 15,
'body'    => array( 'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt ),
) );
if ( is_wp_error( $token_res ) ) {
return $token_res;
}
$data = json_decode( wp_remote_retrieve_body( $token_res ), true );
if ( ! empty( $data['access_token'] ) ) {
return $data['access_token'];
}
$err = $data['error_description'] ?? $data['error'] ?? 'Unknown';
return new WP_Error( 'gsc_token', 'GSC token exchange failed: ' . $err );
}

public function save_snapshot( $keyword, $gsc_rows ) {
global $wpdb;
$table = MM_DB::table( 'ranking_data' );
foreach ( $gsc_rows as $row ) {
$wpdb->insert( $table, array(
'keyword'     => sanitize_text_field( $keyword ),
'page_url'    => esc_url_raw( $row['keys'][1] ?? '' ),
'position'    => (float) ( $row['position'] ?? 0 ),
'impressions' => (int) ( $row['impressions'] ?? 0 ),
'clicks'      => (int) ( $row['clicks'] ?? 0 ),
'ctr'         => (float) ( $row['ctr'] ?? 0 ),
'source'      => 'gsc',
'recorded_at' => current_time( 'Y-m-d' ),
), array( '%s', '%s', '%f', '%d', '%d', '%f', '%s', '%s' ) );
}
}

public function generate_report_html() {
global $wpdb;
$settings    = new Meesho_Master_Settings();
$today       = wp_date( 'd/m/Y' );
$site_name   = get_bloginfo( 'name' );
$subject_pfx = $settings->get( 'email_subject_prefix', 'Meesho Master Report' );
$week_ago    = gmdate( 'Y-m-d H:i:s', strtotime( '-7 days' ) );
$prev_week   = gmdate( 'Y-m-d H:i:s', strtotime( '-14 days' ) );

// a. Products this week vs last
$products_table = MM_DB::table( 'products' );
$staged_week    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$products_table} WHERE import_date >= %s AND status = 'staged'", $week_ago ) );
$pushed_week    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$products_table} WHERE import_date >= %s AND status = 'published'", $week_ago ) );

// b. SEO scores this week vs last
$score_table = MM_DB::table( 'seo_post_scores' );
$avg_now     = $wpdb->get_row( "SELECT AVG(seo_score) AS seo, AVG(aeo_score) AS aeo, AVG(geo_score) AS geo FROM {$score_table}" );
// History table for previous week average
$hist_table  = MM_DB::table( 'seo_score_history' );
$hist_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$hist_table}'" );
$avg_prev_seo = 0;
if ( $hist_exists ) {
	$avg_prev = $wpdb->get_row( $wpdb->prepare(
		"SELECT AVG(seo_score) AS seo FROM {$hist_table} WHERE recorded_at BETWEEN %s AND %s",
		$prev_week, $week_ago
	) );
	$avg_prev_seo = round( $avg_prev->seo ?? 0 );
}

// c. Suggestions
$suggestions_table = MM_DB::table( 'seo_suggestions' );
$pending           = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$suggestions_table} WHERE status = %s", 'pending' ) );
$applied_week      = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$suggestions_table} WHERE DATE(applied_at) >= %s", gmdate( 'Y-m-d', strtotime( '-7 days' ) ) ) );

// d. AI actions this week
$audit_table  = MM_DB::table( 'audit_log' );
$ai_actions   = (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT COUNT(*) FROM {$audit_table} WHERE created_at >= %s AND actor IN ('ai', 'copilot')",
	$week_ago
) );

// e. GSC top 5 keywords
$ranking_table = MM_DB::table( 'ranking_data' );
$ranking_table_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$ranking_table}'" );
$gsc_rows = array();
if ( $ranking_table_exists ) {
	$gsc_rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT keyword, SUM(impressions) AS total_impressions, AVG(position) AS avg_pos FROM {$ranking_table} WHERE recorded_at >= %s GROUP BY keyword ORDER BY total_impressions DESC LIMIT 5",
		gmdate( 'Y-m-d', strtotime( '-7 days' ) )
	) );
}

$avg_seo_now = round( $avg_now->seo ?? 0 );
$seo_delta   = $avg_seo_now - $avg_prev_seo;
$delta_str   = ( $seo_delta >= 0 ? '+' : '' ) . $seo_delta;

$html  = "<html><body style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto;color:#1e293b;'>";
$html .= "<h1 style='color:#6C2EB9;border-bottom:2px solid #6C2EB9;padding-bottom:8px;'>📊 {$subject_pfx} — {$today}</h1>";
$html .= "<p>Site: <strong>" . esc_html( $site_name ) . "</strong></p>";

$html .= "<h2 style='color:#334155;'>📦 Products This Week</h2><table style='width:100%;border-collapse:collapse;'>";
$html .= "<tr style='background:#f8fafc;'><td style='padding:8px;border:1px solid #e2e8f0;'>Staged</td><td style='padding:8px;border:1px solid #e2e8f0;font-weight:bold;'>{$staged_week}</td></tr>";
$html .= "<tr><td style='padding:8px;border:1px solid #e2e8f0;'>Pushed to WooCommerce</td><td style='padding:8px;border:1px solid #e2e8f0;font-weight:bold;'>{$pushed_week}</td></tr>";
$html .= "</table>";

$html .= "<h2 style='color:#334155;'>🔍 SEO Scores</h2><table style='width:100%;border-collapse:collapse;'>";
$html .= "<tr style='background:#f8fafc;'><td style='padding:8px;border:1px solid #e2e8f0;'>Avg SEO Score (now)</td><td style='padding:8px;border:1px solid #e2e8f0;font-weight:bold;'>{$avg_seo_now}/100 <span style='color:" . ( $seo_delta >= 0 ? '#16a34a' : '#dc2626' ) . ";'>{$delta_str} vs last week</span></td></tr>";
$html .= "<tr><td style='padding:8px;border:1px solid #e2e8f0;'>Avg AEO Score</td><td style='padding:8px;border:1px solid #e2e8f0;font-weight:bold;'>" . round( $avg_now->aeo ?? 0 ) . "/100</td></tr>";
$html .= "<tr style='background:#f8fafc;'><td style='padding:8px;border:1px solid #e2e8f0;'>Avg GEO Score</td><td style='padding:8px;border:1px solid #e2e8f0;font-weight:bold;'>" . round( $avg_now->geo ?? 0 ) . "/100</td></tr>";
$html .= "<tr><td style='padding:8px;border:1px solid #e2e8f0;'>Pending Suggestions</td><td style='padding:8px;border:1px solid #e2e8f0;font-weight:bold;'>{$pending}</td></tr>";
$html .= "<tr style='background:#f8fafc;'><td style='padding:8px;border:1px solid #e2e8f0;'>Applied This Week</td><td style='padding:8px;border:1px solid #e2e8f0;font-weight:bold;'>{$applied_week}</td></tr>";
$html .= "</table>";

$html .= "<h2 style='color:#334155;'>🤖 AI Copilot Actions This Week</h2>";
$html .= "<p style='font-size:14px;'>{$ai_actions} action(s) applied by AI/Copilot in the last 7 days.</p>";

if ( ! empty( $gsc_rows ) ) {
	$html .= "<h2 style='color:#334155;'>📈 Top Keywords (GSC, last 7 days)</h2><table style='width:100%;border-collapse:collapse;'>";
	$html .= "<tr style='background:#6C2EB9;color:#fff;'><th style='padding:8px;text-align:left;'>Keyword</th><th style='padding:8px;text-align:right;'>Impressions</th><th style='padding:8px;text-align:right;'>Avg Position</th></tr>";
	foreach ( $gsc_rows as $i => $row ) {
		$bg = $i % 2 === 0 ? '#f8fafc' : '#fff';
		$html .= "<tr style='background:{$bg};'><td style='padding:8px;border:1px solid #e2e8f0;'>" . esc_html( $row->keyword ) . "</td><td style='padding:8px;border:1px solid #e2e8f0;text-align:right;'>" . intval( $row->total_impressions ) . "</td><td style='padding:8px;border:1px solid #e2e8f0;text-align:right;'>" . round( $row->avg_pos, 1 ) . "</td></tr>";
	}
	$html .= "</table>";
}

$html .= "<hr style='margin:24px 0;border:none;border-top:1px solid #e2e8f0;'>";
$html .= "<p style='color:#94a3b8;font-size:11px;'>Generated by Meesho Master on " . esc_html( $today ) . " · " . esc_html( home_url() ) . "</p>";
$html .= "</body></html>";
return $html;
}

public function send_scheduled_report() {
$settings   = new Meesho_Master_Settings();
$recipients = $settings->get( 'email_recipients' );
if ( empty( $recipients ) ) {
return false;
}
$from = $settings->get( 'email_from_override' );
if ( empty( $from ) ) {
$from = get_option( 'admin_email' );
}
$subject_pfx = $settings->get( 'email_subject_prefix', 'Meesho Master Report' );
$subject     = $subject_pfx . ' — ' . wp_date( 'd/m/Y' );
add_filter( 'wp_mail_from', static function () use ( $from ) { return $from; } );
return wp_mail( $recipients, $subject, $this->generate_report_html(), array( 'Content-Type: text/html; charset=UTF-8' ) );
}

public function ajax_get_rankings() {
meesho_master_verify_ajax_nonce();
if ( ! current_user_can( 'manage_options' ) ) {
wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
}
global $wpdb;
$table = MM_DB::table( 'ranking_data' );
$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY recorded_at DESC LIMIT %d", 100 ) );
$latest_date = '';
if ( ! empty( $rows ) && ! empty( $rows[0]->recorded_at ) ) {
$latest_date = (string) $rows[0]->recorded_at;
}
wp_send_json_success( array(
'rows'            => $rows,
'latest_recorded' => $latest_date,
'generated_at'    => current_time( 'mysql' ),
) );
}

public function ajax_get_integration_status() {
meesho_master_verify_ajax_nonce();
if ( ! current_user_can( 'manage_options' ) ) {
wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
}
$available = class_exists( 'MM_Integrations' ) ? MM_Integrations::detect_available() : array();
$wc = class_exists( 'MM_Integrations' ) ? MM_Integrations::woocommerce_snapshot() : array( 'available' => false );
$ga = class_exists( 'MM_Integrations' ) ? MM_Integrations::google_analytics_snapshot() : array( 'available' => false );
$gsc = class_exists( 'MM_Integrations' ) ? MM_Integrations::search_console_snapshot() : array( 'available' => false );
$meta = class_exists( 'MM_Integrations' ) ? MM_Integrations::meta_snapshot() : array( 'available' => false );
wp_send_json_success( array(
'available' => $available,
'woocommerce' => $wc,
'ga4' => $ga,
'gsc' => $gsc,
'meta' => $meta,
'fetched_at' => current_time( 'mysql' ),
) );
}

public function ajax_add_keyword() {
meesho_master_verify_ajax_nonce();
if ( ! current_user_can( 'manage_options' ) ) {
wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
}
$keyword = sanitize_text_field( wp_unslash( $_POST['keyword'] ?? '' ) );
$force_refresh = ! empty( $_POST['force_refresh'] );
if ( '' === $keyword ) {
wp_send_json_error( array( 'message' => 'Keyword required' ), 400 );
}
$rows = $this->fetch_gsc_data( $keyword, $force_refresh );
if ( is_wp_error( $rows ) ) {
wp_send_json_error( array( 'message' => $rows->get_error_message() ), 400 );
}
$this->save_snapshot( $keyword, $rows );
wp_send_json_success( array( 'keyword' => $keyword, 'rows' => $rows, 'date' => wp_date( 'd/m/Y' ) ) );
}

public function ajax_send_report() {
meesho_master_verify_ajax_nonce();
if ( ! current_user_can( 'manage_options' ) ) {
wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
}
$settings   = new Meesho_Master_Settings();
$recipients = $settings->get( 'email_recipients' );
if ( empty( $recipients ) ) {
wp_send_json_error( array( 'message' => 'No recipients configured. Go to Settings → Email Reports and add email_recipients.' ) );
}
$result = $this->send_scheduled_report();
if ( $result ) {
wp_send_json_success( array( 'message' => 'Report sent to: ' . $recipients ) );
} else {
wp_send_json_error( array( 'message' => 'wp_mail() returned false. Check your SMTP settings. If using default PHP mail, it may be blocked by your host. Install WP Mail SMTP and configure an SMTP provider.' ) );
}
}

public function ajax_heatmap_insights() {
meesho_master_verify_ajax_nonce();
if ( ! current_user_can( 'manage_options' ) ) {
wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
}
$settings = new Meesho_Master_Settings();
$api_key  = $settings->get( 'openrouter_api_key' );
if ( empty( $api_key ) ) {
wp_send_json_error( array( 'message' => 'OpenRouter API key required' ), 400 );
}
$summary = array_slice( (array) $this->fetch_gsc_data( '' ), 0, 5 );
$response = wp_remote_post( 'https://openrouter.ai/api/v1/chat/completions', array(
'timeout' => 15,
'headers' => array( 'Authorization' => 'Bearer ' . $api_key, 'Content-Type' => 'application/json' ),
'body'    => wp_json_encode( array(
'model' => $settings->get( 'ai_model_seo' ) ?: 'openai/gpt-4o-mini',
'messages' => array(
array( 'role' => 'system', 'content' => 'Return only JSON array of {suggestion,priority,action}. No prose.' ),
array( 'role' => 'user', 'content' => 'Use this 30-day GSC summary to suggest 3-5 UX improvements: ' . wp_json_encode( $summary ) ),
),
) ),
) );
if ( is_wp_error( $response ) ) {
wp_send_json_error( array( 'message' => $response->get_error_message() ), 400 );
}
$payload = json_decode( wp_remote_retrieve_body( $response ), true );
$reply = trim( (string) ( $payload['choices'][0]['message']['content'] ?? '[]' ) );
$insights = json_decode( $reply, true );
wp_send_json_success( array( 'insights' => is_array( $insights ) ? $insights : array(), 'date' => wp_date( 'd/m/Y' ) ) );
}

// E2 — GA4 data: supports Site Kit proxy or Service Account JSON
public function ajax_fetch_ga4_data() {
meesho_master_verify_ajax_nonce();
if ( ! current_user_can( 'manage_options' ) ) {
wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
}
$settings    = new Meesho_Master_Settings();
$property_id = $settings->get( 'mm_ga4_property_id' );
if ( empty( $property_id ) ) {
wp_send_json_error( array( 'message' => '⚠️ GA4 not configured. Go to Settings → Google APIs and set your GA4 Property ID.' ) );
}
$range      = absint( $_POST['range'] ?? 30 );
$range      = in_array( $range, array( 7, 30, 90 ), true ) ? $range : 30;
$force_refresh = ! empty( $_POST['force_refresh'] );
$cache_enabled = 'yes' === $settings->get( 'mm_analytics_cache_enabled', 'yes' );
$cache_ttl_hours = (int) $settings->get( 'mm_analytics_cache_ttl_hours', 4 );
$cache_ttl_hours = min( 168, max( 1, $cache_ttl_hours ) );
$cache_key  = 'mm_ga4_data_' . $property_id . '_' . $range;
if ( $force_refresh ) {
delete_transient( $cache_key );
}
$cached     = get_transient( $cache_key );
if ( $cache_enabled && ! $force_refresh && false !== $cached ) {
if ( is_array( $cached ) ) {
$cached['cache_hit'] = true;
}
wp_send_json_success( $cached );
}
$ga4_mode = $settings->get( 'mm_ga4_mode', 'site_kit' );

// Mode A: proxy through Google Site Kit REST API
if ( 'site_kit' === $ga4_mode ) {
if ( ! class_exists( 'Google\Site_Kit\Core\REST_API\REST_Routes' ) ) {
wp_send_json_error( array( 'message' => 'Google Site Kit plugin is not active. Activate it or switch to Service Account mode in Settings.' ) );
}
$rest_url = rest_url( 'google-site-kit/v1/modules/analytics-4/data/report' );
$response = wp_remote_post( $rest_url, array(
'timeout' => 20,
'headers' => array( 'X-WP-Nonce' => wp_create_nonce( 'wp_rest' ), 'Content-Type' => 'application/json' ),
'body'    => wp_json_encode( array(
'startDate'  => gmdate( 'Y-m-d', strtotime( "-{$range} days" ) ),
'endDate'    => gmdate( 'Y-m-d' ),
'dimensions' => array( 'pagePath', 'deviceCategory' ),
'metrics'    => array( 'sessions', 'activeUsers', 'screenPageViews', 'bounceRate' ),
'limit'      => 10,
) ),
) );
} else {
// Mode B: Service Account JSON
$sa_json = $settings->get( 'mm_ga4_service_account_json' );
if ( empty( $sa_json ) ) {
wp_send_json_error( array( 'message' => 'Service Account JSON not configured. Paste it in Settings → Google APIs.' ) );
}
$sa = json_decode( $sa_json, true );
if ( ! $sa || empty( $sa['private_key'] ) || empty( $sa['client_email'] ) ) {
wp_send_json_error( array( 'message' => 'Invalid Service Account JSON.' ) );
}
// Build JWT for Google OAuth
$header    = rtrim( strtr( base64_encode( wp_json_encode( array( 'alg' => 'RS256', 'typ' => 'JWT' ) ) ), '+/', '-_' ), '=' );
$now       = time();
$claim_set = rtrim( strtr( base64_encode( wp_json_encode( array(
'iss'   => $sa['client_email'],
'scope' => 'https://www.googleapis.com/auth/analytics.readonly',
'aud'   => 'https://oauth2.googleapis.com/token',
'iat'   => $now,
'exp'   => $now + 3600,
) ) ), '+/', '-_' ), '=' );
$sig_input = $header . '.' . $claim_set;
$private_key = $sa['private_key'];
$signature = '';
if ( function_exists( 'openssl_sign' ) ) {
openssl_sign( $sig_input, $signature, $private_key, OPENSSL_ALGO_SHA256 );
$signature = rtrim( strtr( base64_encode( $signature ), '+/', '-_' ), '=' );
} else {
wp_send_json_error( array( 'message' => 'openssl_sign() is not available on this server. Cannot use Service Account auth.' ) );
}
$jwt = $sig_input . '.' . $signature;
$token_response = wp_remote_post( 'https://oauth2.googleapis.com/token', array(
'body' => array( 'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt ),
) );
if ( is_wp_error( $token_response ) ) {
wp_send_json_error( array( 'message' => 'Token fetch failed: ' . $token_response->get_error_message() ) );
}
$token_data   = json_decode( wp_remote_retrieve_body( $token_response ), true );
$access_token = $token_data['access_token'] ?? '';
if ( empty( $access_token ) ) {
wp_send_json_error( array( 'message' => 'Could not obtain GA4 access token. Check service account permissions.' ) );
}
$response = wp_remote_post( "https://analyticsdata.googleapis.com/v1beta/properties/{$property_id}:runReport", array(
'timeout' => 20,
'headers' => array( 'Authorization' => 'Bearer ' . $access_token, 'Content-Type' => 'application/json' ),
'body'    => wp_json_encode( array(
'dateRanges'  => array( array( 'startDate' => gmdate( 'Y-m-d', strtotime( "-{$range} days" ) ), 'endDate' => 'today' ) ),
'dimensions'  => array( array( 'name' => 'pagePath' ), array( 'name' => 'deviceCategory' ) ),
'metrics'     => array( array( 'name' => 'sessions' ), array( 'name' => 'activeUsers' ), array( 'name' => 'screenPageViews' ), array( 'name' => 'bounceRate' ) ),
'limit'       => 10,
) ),
) );
}
if ( is_wp_error( $response ) ) {
wp_send_json_error( array( 'message' => 'GA4 API error: ' . $response->get_error_message() ) );
}
$body = json_decode( wp_remote_retrieve_body( $response ), true );
if ( empty( $body ) ) {
wp_send_json_error( array( 'message' => 'Empty response from GA4 API. Check property ID and permissions.' ) );
}
$result = array(
'rows'        => $body['rows'] ?? array(),
'range'       => $range,
'property_id' => $property_id,
'cache_hit'   => false,
'fetched_at'  => current_time( 'mysql' ),
);
if ( $cache_enabled ) {
set_transient( $cache_key, $result, $cache_ttl_hours * HOUR_IN_SECONDS );
}
wp_send_json_success( $result );
}
}

