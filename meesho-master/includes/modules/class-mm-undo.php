<?php

if ( ! class_exists( 'MM_Undo' ) ) {
class MM_Undo {
public function __construct() {
add_action( 'wp_ajax_mm_undo_action', array( $this, 'ajax_undo' ) );
add_action( 'wp_ajax_mm_get_logs', array( $this, 'ajax_get_logs' ) );
add_action( 'mm_purge_old_logs', array( $this, 'purge_expired_snapshots' ) );
if ( ! wp_next_scheduled( 'mm_purge_old_logs' ) ) {
wp_schedule_event( time(), 'weekly', 'mm_purge_old_logs' );
}
}

public function log_before_change( $action_type, $target_type, $target_id, $old_value, $new_value, $suggestion_id = 0, $actor = 'manual', $note = '', $undoable = 1 ) {
$logger = new MM_Logger();
return $logger->log_before_change( $action_type, $target_type, $target_id, $old_value, $new_value, $suggestion_id, $actor, $note, $undoable );
}

public function revert( $log_id ) {
global $wpdb;
$table = MM_DB::table( 'audit_log' );
$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $log_id ) );
if ( ! $row ) {
return new WP_Error( 'not_found', 'Log entry not found.' );
}
if ( ! (int) $row->undoable || (int) $row->undone ) {
return new WP_Error( 'not_undoable', 'This action can no longer be undone.' );
}
if ( null === $row->old_value ) {
return new WP_Error( 'expired', 'This action can no longer be undone — the 7-day window has expired.' );
}
$result = $this->apply_revert( $row );
if ( is_wp_error( $result ) ) {
return $result;
}
$wpdb->update( $table, array( 'undone' => 1 ), array( 'id' => $log_id ), array( '%d' ), array( '%d' ) );
( new MM_Logger() )->log_before_change( 'seo_undo', $row->target_type, $row->target_id, $row->new_value, $row->old_value, (int) $row->suggestion_id, 'manual', 'undo', 0 );
return true;
}

public function revert_last( $user_id ) {
global $wpdb;
$table = MM_DB::table( 'audit_log' );
$log_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE actor_user_id = %d AND undoable = 1 AND undone = 0 ORDER BY created_at DESC LIMIT 1", $user_id ) );
if ( ! $log_id ) {
return new WP_Error( 'not_found', 'No undoable action found.' );
}
return $this->revert( $log_id );
}

private function apply_revert( $row ) {
$target_id = (int) $row->target_id;
switch ( $row->action_type ) {
case 'seo_apply':
case 'schema_apply':
case 'copilot_edit':
case 'order_update':
if ( 'order' === $row->target_type ) {
global $wpdb;
$decoded = json_decode( $row->old_value, true );
if ( ! is_array( $decoded ) ) {
return new WP_Error( 'invalid_snapshot', 'Order snapshot is invalid.' );
}
return false === $wpdb->update( MM_DB::table( 'orders' ), $decoded, array( 'wc_order_id' => $target_id ) ) ? new WP_Error( 'db_error', 'Failed to restore order.' ) : true;
}
if ( false !== strpos( (string) $row->note, 'meta_' ) ) {
$meta_key = false !== strpos( (string) $row->note, 'desc' ) ? '_mm_seo_desc' : '_mm_seo_title';
update_post_meta( $target_id, $meta_key, $row->old_value );
$keys = MM_SEO_Crawler::get_meta_keys();
update_post_meta( $target_id, false !== strpos( (string) $row->note, 'desc' ) ? $keys['desc'] : $keys['title'], $row->old_value );
return true;
}
if ( false !== strpos( (string) $row->note, 'schema' ) ) {
update_post_meta( $target_id, '_mm_schema_json', $row->old_value );
return true;
}
wp_update_post( array( 'ID' => $target_id, 'post_content' => (string) $row->old_value ) );
return true;
default:
return new WP_Error( 'unsupported', 'Undo is not supported for this action type.' );
}
}

public function purge_expired_snapshots() {
global $wpdb;
$wpdb->query( 'UPDATE ' . MM_DB::table( 'audit_log' ) . ' SET old_value = NULL, undoable = 0 WHERE purge_after IS NOT NULL AND purge_after < NOW()' );
$wpdb->query( 'DELETE FROM ' . MM_DB::table( 'seo_score_history' ) . ' WHERE recorded_at < DATE_SUB(NOW(), INTERVAL 90 DAY)' );
}

public function ajax_undo() {
check_ajax_referer( 'mm_nonce', 'nonce' );
if ( ! current_user_can( 'manage_options' ) ) {
wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
}
$result = $this->revert( absint( $_POST['log_id'] ?? 0 ) );
if ( is_wp_error( $result ) ) {
wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
}
wp_send_json_success( 'Action undone successfully.' );
}

public function ajax_get_logs() {
check_ajax_referer( 'mm_nonce', 'nonce' );
if ( ! current_user_can( 'manage_options' ) ) {
wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
}
global $wpdb;
$table = MM_DB::table( 'audit_log' );
$where = array( '1=1' );
$params = array();
$action_type = sanitize_text_field( wp_unslash( $_POST['action_type'] ?? '' ) );
$source      = sanitize_text_field( wp_unslash( $_POST['source'] ?? '' ) );
if ( '' !== $action_type ) {
$where[]  = 'action_type = %s';
$params[] = $action_type;
}
if ( '' !== $source ) {
$where[]  = 'actor = %s';
$params[] = $source;
}
$page = max( 1, absint( $_POST['page'] ?? 1 ) );
$params[] = 50;
$params[] = ( $page - 1 ) * 50;
$sql = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY created_at DESC LIMIT %d OFFSET %d';
$logs = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ) );
foreach ( $logs as $log ) {
$log->post_id     = $log->target_id;
$log->source      = $log->actor;
$log->created_at  = mysql2date( 'd/m/Y H:i', $log->created_at );
}
$total_params = array_slice( $params, 0, -2 );
$total_sql = "SELECT COUNT(*) FROM {$table} WHERE " . implode( ' AND ', $where );
$total = empty( $total_params ) ? (int) $wpdb->get_var( $total_sql ) : (int) $wpdb->get_var( $wpdb->prepare( $total_sql, ...$total_params ) );
wp_send_json_success( array( 'logs' => $logs, 'total' => $total, 'page' => $page ) );
}
}
}
