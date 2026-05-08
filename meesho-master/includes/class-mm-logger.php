<?php

if ( ! class_exists( 'MM_Logger' ) ) {
class MM_Logger {
public function log_before_change( $action_type, $target_type, $target_id, $old_value, $new_value, $suggestion_id = 0, $actor = 'manual', $note = '', $undoable = 1 ) {
global $wpdb;

$table = MM_DB::table( 'audit_log' );
// Auto-repair: ensure audit_log table exists before inserting
if ( $table && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
MM_DB::install();
}
return false !== $wpdb->insert(
$table,
array(
'action_type'   => sanitize_text_field( $action_type ),
'target_type'   => sanitize_text_field( $target_type ),
'target_id'     => (int) $target_id,
'suggestion_id' => (int) $suggestion_id,
'old_value'     => is_string( $old_value ) ? $old_value : wp_json_encode( $old_value ),
'new_value'     => is_string( $new_value ) ? $new_value : wp_json_encode( $new_value ),
'actor'         => sanitize_text_field( $actor ),
'actor_user_id' => get_current_user_id(),
'note'          => sanitize_textarea_field( $note ),
'undoable'      => (int) $undoable,
'undone'        => 0,
'purge_after'   => gmdate( 'Y-m-d H:i:s', strtotime( '+7 days', current_time( 'timestamp' ) ) ),
'created_at'    => current_time( 'mysql' ),
),
array( '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%d', '%d', '%s', '%s' )
) ? (int) $wpdb->insert_id : false;
}
}
}
