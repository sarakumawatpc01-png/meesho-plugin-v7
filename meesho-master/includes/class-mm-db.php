<?php

if ( ! class_exists( 'MM_DB' ) ) {
class MM_DB {
const VERSION = '6.5.1';

private static $tables = array(
'seo_suggestions'   => 'mm_seo_suggestions',
'seo_post_scores'   => 'mm_seo_post_scores',
'seo_score_history' => 'mm_seo_score_history',
'audit_log'         => 'mm_audit_log',
'seo_runs'          => 'mm_seo_runs',
'copilot_threads'   => 'mm_copilot_threads',
'ranking_data'      => 'mm_ranking_data',
'products'          => 'mm_products',
'reviews'           => 'mm_reviews',
'orders'            => 'mm_orders',
'customers'         => 'mm_customers',
);

private static $legacy_tables = array(
'seo_suggestions' => 'meesho_seo_suggestions',
'audit_log'       => 'meesho_audit_logs',
'seo_runs'        => 'meesho_run_history',
'products'        => 'meesho_products',
'reviews'         => 'meesho_reviews',
'orders'          => 'meesho_orders',
'customers'       => 'meesho_customers',
'ranking_data'    => 'meesho_gsc_snapshots',
);

public static function table( $key ) {
global $wpdb;

if ( ! isset( self::$tables[ $key ] ) ) {
return '';
}

$table = $wpdb->prefix . self::$tables[ $key ];
return self::is_safe_identifier( $table ) ? $table : '';
}

public static function legacy_table( $key ) {
global $wpdb;

if ( ! isset( self::$legacy_tables[ $key ] ) ) {
return '';
}

$table = $wpdb->prefix . self::$legacy_tables[ $key ];
return self::is_safe_identifier( $table ) ? $table : '';
}

public static function is_safe_identifier( $identifier ) {
return is_string( $identifier ) && (bool) preg_match( '/^[A-Za-z0-9_]+$/', $identifier );
}

public static function maybe_upgrade() {
	global $wpdb;
	$installed = get_option( 'meesho_master_db_version', '' );
	// Check version AND ensure all critical tables actually exist
	$needs_install = self::VERSION !== $installed;
	if ( ! $needs_install ) {
		foreach ( array( 'products', 'reviews', 'audit_log', 'seo_suggestions', 'seo_post_scores', 'copilot_threads' ) as $key ) {
			$tbl = self::table( $key );
			if ( $tbl && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl ) ) !== $tbl ) {
				$needs_install = true;
				break;
			}
		}
	}
	if ( $needs_install ) {
		self::install();
	}
}

public static function table_exists( $key_or_full ) {
	global $wpdb;
	$name = isset( self::$tables[ $key_or_full ] ) ? self::table( $key_or_full ) : $key_or_full;
	if ( ! $name ) {
		return false;
	}
	return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) ) === $name;
}

/**
 * Force a fresh install regardless of stored version. Used by the
 * "Repair Database" button in Settings.
 */
public static function force_reinstall() {
	delete_option( 'meesho_master_db_version' );
	self::install();
	return self::install_diagnostics();
}

/**
 * Returns a per-table existence map so the UI can show what's missing.
 */
public static function install_diagnostics() {
	$out = array();
	foreach ( self::$tables as $key => $_ ) {
		$out[ $key ] = self::table_exists( $key );
	}
	return $out;
}

public static function install() {
global $wpdb;

require_once ABSPATH . 'wp-admin/includes/upgrade.php';
$charset_collate = $wpdb->get_charset_collate();

$tables = array();
$tables[] = 'CREATE TABLE ' . self::table( 'seo_suggestions' ) . " (
id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
post_id BIGINT(20) UNSIGNED NOT NULL,
suggestion_type VARCHAR(60) NOT NULL,
current_value LONGTEXT NULL,
suggested_value LONGTEXT NULL,
reasoning TEXT NULL,
priority VARCHAR(10) NOT NULL DEFAULT 'medium',
confidence TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
safe_to_apply TINYINT(1) NOT NULL DEFAULT 0,
status VARCHAR(20) NOT NULL DEFAULT 'pending',
seo_score TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
aeo_score TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
geo_score TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
run_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
applied_at DATETIME NULL,
PRIMARY KEY (id),
KEY idx_post (post_id),
KEY idx_status (status),
KEY idx_type (suggestion_type(30)),
KEY idx_run (run_id)
) $charset_collate";
$tables[] = 'CREATE TABLE ' . self::table( 'seo_post_scores' ) . " (
id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
post_id BIGINT(20) UNSIGNED NOT NULL,
post_type VARCHAR(30) NOT NULL DEFAULT 'post',
seo_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
aeo_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
geo_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
focus_keyword VARCHAR(200) NOT NULL DEFAULT '',
last_scanned DATETIME NULL,
scan_count INT UNSIGNED NOT NULL DEFAULT 0,
PRIMARY KEY (id),
UNIQUE KEY uniq_post (post_id),
KEY idx_seo (seo_score),
KEY idx_last_scanned (last_scanned)
) $charset_collate";
$tables[] = 'CREATE TABLE ' . self::table( 'seo_score_history' ) . " (
id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
post_id BIGINT(20) UNSIGNED NOT NULL,
seo_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
aeo_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
geo_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
run_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
PRIMARY KEY (id),
KEY idx_post (post_id),
KEY idx_date (recorded_at)
) $charset_collate";
$tables[] = 'CREATE TABLE ' . self::table( 'audit_log' ) . " (
id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
action_type VARCHAR(60) NOT NULL,
target_type VARCHAR(30) NOT NULL DEFAULT '',
target_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
suggestion_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
old_value LONGTEXT NULL,
new_value LONGTEXT NULL,
actor VARCHAR(30) NOT NULL DEFAULT 'manual',
actor_user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
note TEXT NULL,
undoable TINYINT(1) NOT NULL DEFAULT 1,
undone TINYINT(1) NOT NULL DEFAULT 0,
purge_after DATETIME NULL,
created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
PRIMARY KEY (id),
KEY idx_target (target_type(20), target_id),
KEY idx_undoable (undoable, undone),
KEY idx_suggestion (suggestion_id)
) $charset_collate";
$tables[] = 'CREATE TABLE ' . self::table( 'seo_runs' ) . " (
id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
trigger_type VARCHAR(20) NOT NULL DEFAULT 'cron',
posts_scanned INT NOT NULL DEFAULT 0,
suggestions_created INT NOT NULL DEFAULT 0,
suggestions_applied INT NOT NULL DEFAULT 0,
failed_posts INT NOT NULL DEFAULT 0,
status VARCHAR(20) NOT NULL DEFAULT 'running',
error_log TEXT NULL,
started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
finished_at DATETIME NULL,
PRIMARY KEY (id),
KEY idx_status (status),
KEY idx_started (started_at)
) $charset_collate";
$tables[] = 'CREATE TABLE ' . self::table( 'copilot_threads' ) . " (
id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
thread_key VARCHAR(60) NOT NULL,
title TEXT NULL,
messages LONGTEXT NULL,
created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
PRIMARY KEY (id),
UNIQUE KEY uniq_thread (thread_key)
) $charset_collate";
$tables[] = 'CREATE TABLE ' . self::table( 'ranking_data' ) . " (
id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
keyword VARCHAR(300) NOT NULL,
page_url TEXT NULL,
position FLOAT NOT NULL DEFAULT 0,
impressions INT NOT NULL DEFAULT 0,
clicks INT NOT NULL DEFAULT 0,
ctr FLOAT NOT NULL DEFAULT 0,
source VARCHAR(20) NOT NULL DEFAULT 'gsc',
recorded_at DATE NOT NULL,
PRIMARY KEY (id),
KEY idx_keyword (keyword(100)),
KEY idx_date (recorded_at)
) $charset_collate";
$tables[] = 'CREATE TABLE ' . self::table( 'products' ) . " (
id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
meesho_sku VARCHAR(191) NOT NULL,
wc_product_id BIGINT(20) UNSIGNED NOT NULL,
meesho_url TEXT NULL,
title TEXT NULL,
scraped_data LONGTEXT NULL,
import_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
status VARCHAR(20) NOT NULL DEFAULT 'active',
PRIMARY KEY (id),
UNIQUE KEY uniq_sku (meesho_sku)
) $charset_collate";
$tables[] = 'CREATE TABLE ' . self::table( 'reviews' ) . " (
id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
meesho_sku VARCHAR(255) NOT NULL,
reviewer_name VARCHAR(255) NOT NULL,
review_text TEXT NULL,
star_rating TINYINT(3) UNSIGNED NOT NULL,
review_date DATETIME NULL,
review_images LONGTEXT NULL,
PRIMARY KEY (id),
KEY idx_sku (meesho_sku)
) $charset_collate";
$tables[] = 'CREATE TABLE ' . self::table( 'orders' ) . " (
id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
wc_order_id BIGINT(20) UNSIGNED NOT NULL,
meesho_order_id VARCHAR(255) NOT NULL DEFAULT '',
meesho_tracking_id VARCHAR(255) NOT NULL DEFAULT '',
meesho_account VARCHAR(255) NOT NULL DEFAULT '',
fulfillment_status VARCHAR(50) NOT NULL DEFAULT 'pending',
sla_flagged TINYINT(1) NOT NULL DEFAULT 0,
cod_risk_flag TINYINT(1) NOT NULL DEFAULT 0,
fulfilled_by VARCHAR(50) NOT NULL DEFAULT '',
notes TEXT NULL,
created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
PRIMARY KEY (id),
UNIQUE KEY uniq_wc_order (wc_order_id),
KEY idx_status (fulfillment_status)
) $charset_collate";
$tables[] = 'CREATE TABLE ' . self::table( 'customers' ) . " (
id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
phone_number VARCHAR(20) NOT NULL,
risk_score INT NOT NULL DEFAULT 0,
history_summary TEXT NULL,
created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
PRIMARY KEY (id),
UNIQUE KEY uniq_phone (phone_number)
) $charset_collate";

foreach ( $tables as $sql ) {
dbDelta( $sql );
}

// Add missing columns to existing tables for v6 compatibility
$wpdb->query( "ALTER TABLE " . self::table( 'seo_suggestions' ) . " ADD COLUMN IF NOT EXISTS suggestion_type VARCHAR(60) AFTER post_id" );
$wpdb->query( "ALTER TABLE " . self::table( 'seo_suggestions' ) . " ADD COLUMN IF NOT EXISTS seo_score TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER status" );
$wpdb->query( "ALTER TABLE " . self::table( 'seo_suggestions' ) . " ADD COLUMN IF NOT EXISTS aeo_score TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER seo_score" );
$wpdb->query( "ALTER TABLE " . self::table( 'seo_suggestions' ) . " ADD COLUMN IF NOT EXISTS geo_score TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER aeo_score" );
$wpdb->query( "ALTER TABLE " . self::table( 'seo_suggestions' ) . " ADD COLUMN IF NOT EXISTS run_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0 AFTER geo_score" );

// Migrate old 'type' column to 'suggestion_type' if needed
$wpdb->query( "UPDATE " . self::table( 'seo_suggestions' ) . " SET suggestion_type = type WHERE suggestion_type = '' AND type != ''" );

$wpdb->query( "ALTER TABLE " . self::table( 'seo_score_history' ) . " ADD COLUMN IF NOT EXISTS run_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0 AFTER geo_score" );

$wpdb->query( "ALTER TABLE " . self::table( 'copilot_threads' ) . " MODIFY COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP" );

$wpdb->query( "ALTER TABLE " . self::table( 'ranking_data' ) . " ADD COLUMN IF NOT EXISTS source VARCHAR(20) NOT NULL DEFAULT 'gsc' AFTER ctr" );

// Add v6 columns to mm_orders table
$wpdb->query( "ALTER TABLE " . self::table( 'orders' ) . " ADD COLUMN IF NOT EXISTS meesho_order_id VARCHAR(100) NOT NULL DEFAULT '' AFTER wc_order_id" );
$wpdb->query( "ALTER TABLE " . self::table( 'orders' ) . " ADD COLUMN IF NOT EXISTS meesho_tracking_id VARCHAR(100) NOT NULL DEFAULT '' AFTER meesho_order_id" );
$wpdb->query( "ALTER TABLE " . self::table( 'orders' ) . " ADD COLUMN IF NOT EXISTS meesho_account TINYINT NOT NULL DEFAULT 1 AFTER meesho_tracking_id" );
$wpdb->query( "ALTER TABLE " . self::table( 'orders' ) . " ADD COLUMN IF NOT EXISTS fulfillment_status VARCHAR(40) NOT NULL DEFAULT 'pending' AFTER meesho_account" );
$wpdb->query( "ALTER TABLE " . self::table( 'orders' ) . " ADD COLUMN IF NOT EXISTS sla_flagged TINYINT(1) NOT NULL DEFAULT 0 AFTER fulfillment_status" );
$wpdb->query( "ALTER TABLE " . self::table( 'orders' ) . " ADD COLUMN IF NOT EXISTS cod_risk_flag TINYINT(1) NOT NULL DEFAULT 0 AFTER sla_flagged" );
$wpdb->query( "ALTER TABLE " . self::table( 'orders' ) . " ADD COLUMN IF NOT EXISTS fulfilled_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0 AFTER cod_risk_flag" );

self::migrate_legacy_data();
update_option( 'meesho_master_db_version', self::VERSION );
}

private static function migrate_legacy_data() {
global $wpdb;

$seo_suggestions = self::table( 'seo_suggestions' );
$legacy_suggestions = self::legacy_table( 'seo_suggestions' );
if ( $legacy_suggestions && self::table_exists( $legacy_suggestions ) ) {
$wpdb->query( "INSERT IGNORE INTO {$seo_suggestions} (id, post_id, type, current_value, suggested_value, reasoning, priority, confidence, safe_to_apply, status, created_at, applied_at) SELECT id, post_id, type, current_value, suggested_value, reasoning, priority, confidence, safe_to_apply, status, STR_TO_DATE(created_at, '%d/%m/%Y'), NULLIF(STR_TO_DATE(applied_at, '%d/%m/%Y'), '0000-00-00 00:00:00') FROM {$legacy_suggestions}" );
}

$audit_log = self::table( 'audit_log' );
$legacy_audit = self::legacy_table( 'audit_log' );
if ( $legacy_audit && self::table_exists( $legacy_audit ) ) {
$wpdb->query( "INSERT IGNORE INTO {$audit_log} (id, action_type, target_type, target_id, old_value, new_value, actor, actor_user_id, purge_after, created_at) SELECT id, action_type, 'post', IFNULL(post_id, 0), old_value, new_value, source, IFNULL(user_id, 0), NULLIF(STR_TO_DATE(expires_at, '%d/%m/%Y'), '0000-00-00 00:00:00'), COALESCE(NULLIF(STR_TO_DATE(created_at, '%d/%m/%Y'), '0000-00-00 00:00:00'), NOW()) FROM {$legacy_audit}" );
}

$seo_runs = self::table( 'seo_runs' );
$legacy_runs = self::legacy_table( 'seo_runs' );
if ( $legacy_runs && self::table_exists( $legacy_runs ) ) {
$wpdb->query( "INSERT IGNORE INTO {$seo_runs} (id, trigger_type, posts_scanned, suggestions_created, suggestions_applied, failed_posts, status, error_log, started_at, finished_at) SELECT id, run_type, pages_processed, suggestions_generated, suggestions_applied, 0, CASE WHEN status = 'success' THEN 'done' ELSE status END, error_message, COALESCE(NULLIF(STR_TO_DATE(started_at, '%d/%m/%Y'), '0000-00-00 00:00:00'), NOW()), NULLIF(STR_TO_DATE(completed_at, '%d/%m/%Y'), '0000-00-00 00:00:00') FROM {$legacy_runs}" );
}

$products = self::table( 'products' );
$legacy_products = self::legacy_table( 'products' );
if ( $legacy_products && self::table_exists( $legacy_products ) ) {
$wpdb->query( "INSERT IGNORE INTO {$products} (id, meesho_sku, wc_product_id, meesho_url, import_date, status) SELECT id, meesho_sku, wc_product_id, meesho_url, COALESCE(NULLIF(STR_TO_DATE(import_date, '%d/%m/%Y'), '0000-00-00 00:00:00'), NOW()), status FROM {$legacy_products}" );
}

$reviews = self::table( 'reviews' );
$legacy_reviews = self::legacy_table( 'reviews' );
if ( $legacy_reviews && self::table_exists( $legacy_reviews ) ) {
$wpdb->query( "INSERT IGNORE INTO {$reviews} (id, meesho_sku, reviewer_name, review_text, star_rating, review_date, review_images) SELECT id, meesho_sku, reviewer_name, review_text, star_rating, NULLIF(STR_TO_DATE(review_date, '%d/%m/%Y'), '0000-00-00 00:00:00'), review_images FROM {$legacy_reviews}" );
}

$orders = self::table( 'orders' );
$legacy_orders = self::legacy_table( 'orders' );
if ( $legacy_orders && self::table_exists( $legacy_orders ) ) {
$wpdb->query( "INSERT IGNORE INTO {$orders} (id, wc_order_id, meesho_order_id, meesho_tracking_id, meesho_account, fulfillment_status, sla_flagged, notes, created_at, updated_at) SELECT id, wc_order_id, meesho_order_id, tracking_id, account_used, fulfillment_status, CASE WHEN sla_status = 'breached' THEN 1 ELSE 0 END, notes, COALESCE(NULLIF(STR_TO_DATE(created_at, '%d/%m/%Y'), '0000-00-00 00:00:00'), NOW()), COALESCE(NULLIF(STR_TO_DATE(updated_at, '%d/%m/%Y'), '0000-00-00 00:00:00'), NOW()) FROM {$legacy_orders}" );
}

$customers = self::table( 'customers' );
$legacy_customers = self::legacy_table( 'customers' );
if ( $legacy_customers && self::table_exists( $legacy_customers ) ) {
$wpdb->query( "INSERT IGNORE INTO {$customers} (id, phone_number, risk_score, history_summary, created_at) SELECT id, phone_number, risk_score, history_summary, COALESCE(NULLIF(STR_TO_DATE(created_at, '%d/%m/%Y'), '0000-00-00 00:00:00'), NOW()) FROM {$legacy_customers}" );
}

$ranking_data = self::table( 'ranking_data' );
$legacy_rankings = self::legacy_table( 'ranking_data' );
if ( $legacy_rankings && self::table_exists( $legacy_rankings ) ) {
$wpdb->query( "INSERT IGNORE INTO {$ranking_data} (keyword, page_url, position, impressions, ctr, source, recorded_at) SELECT keyword, url, position, impressions, ctr, 'gsc', COALESCE(NULLIF(STR_TO_DATE(snapshot_date, '%d/%m/%Y'), '0000-00-00 00:00:00'), CURDATE()) FROM {$legacy_rankings}" );
}
}

}
}
