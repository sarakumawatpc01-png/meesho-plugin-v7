<?php

if ( ! class_exists( 'MM_SEO_Implementor' ) ) {
class MM_SEO_Implementor {
public function apply( $suggestion, $actor = 'manual' ) {
global $wpdb;

$suggestion = is_object( $suggestion ) ? (array) $suggestion : (array) $suggestion;
$post_id    = (int) ( $suggestion['post_id'] ?? 0 );
$type       = (string) ( $suggestion['type'] ?? '' );
$suggested  = (string) ( $suggestion['suggested_value'] ?? '' );
$current    = (string) ( $suggestion['current_value'] ?? '' );
$suggestion_id = (int) ( $suggestion['id'] ?? 0 );
if ( ! $post_id || '' === $type ) {
return new WP_Error( 'mm_invalid_suggestion', 'Suggestion payload is incomplete.' );
}

$meta_keys = MM_SEO_Crawler::get_meta_keys();
switch ( $type ) {
case 'meta_title':
$this->log( 'seo_apply', 'post', $post_id, $current, $suggested, $suggestion_id, $actor, 'meta_title' );
update_post_meta( $post_id, '_mm_seo_title', $suggested );
update_post_meta( $post_id, $meta_keys['title'], $suggested );
break;
case 'meta_desc':
$this->log( 'seo_apply', 'post', $post_id, $current, $suggested, $suggestion_id, $actor, 'meta_desc' );
update_post_meta( $post_id, '_mm_seo_desc', $suggested );
update_post_meta( $post_id, $meta_keys['desc'], $suggested );
break;
case 'internal_link':
case 'content':
case 'citability_block':
case 'statistics_inject':
$old_content = (string) get_post_field( 'post_content', $post_id );
$new_content = $this->build_updated_content( $type, $old_content, $suggested, $current );
$this->log( 'seo_apply', 'post', $post_id, $old_content, $new_content, $suggestion_id, $actor, $type );
wp_update_post( array( 'ID' => $post_id, 'post_content' => $new_content ) );
break;
case 'schema':
case 'howto_schema':
case 'faq':
$json = trim( $suggested );
if ( null === json_decode( $json, true ) ) {
return new WP_Error( 'mm_invalid_schema', 'Schema suggestion is not valid JSON.' );
}
$this->log( 'schema_apply', 'post', $post_id, get_post_meta( $post_id, '_mm_schema_json', true ), $json, $suggestion_id, $actor, $type );
update_post_meta( $post_id, '_mm_schema_json', $json );
break;
case 'alt_tag':
$old_content = (string) get_post_field( 'post_content', $post_id );
$new_content = str_replace( $current, $suggested, $old_content );
$this->log( 'seo_apply', 'post', $post_id, $old_content, $new_content, $suggestion_id, $actor, 'alt_tag' );
wp_update_post( array( 'ID' => $post_id, 'post_content' => $new_content ) );
break;
default:
return new WP_Error( 'mm_unsupported_suggestion', 'Suggestion type is not supported.' );
}

if ( $suggestion_id ) {
$wpdb->update(
MM_DB::table( 'seo_suggestions' ),
array( 'status' => 'applied', 'applied_at' => current_time( 'mysql' ) ),
array( 'id' => $suggestion_id ),
array( '%s', '%s' ),
array( '%d' )
);
}

return true;
}

private function build_updated_content( $type, $old_content, $suggested, $current ) {
if ( 'internal_link' === $type && '' !== trim( $current ) && false !== strpos( $old_content, $current ) ) {
return str_replace( $current, $suggested, $old_content );
}
if ( 'citability_block' === $type ) {
return preg_replace( '/<\/p>/i', '</p>' . "\n\n<p>" . wp_kses_post( $suggested ) . '</p>', $old_content, 1 );
}
if ( 'statistics_inject' === $type ) {
return preg_replace( '/<\/p>/i', ' ' . esc_html( $suggested ) . '</p>', $old_content, 1 );
}
return '' !== trim( $suggested ) ? $suggested : $old_content;
}

private function log( $action_type, $target_type, $target_id, $old_value, $new_value, $suggestion_id, $actor, $note ) {
$logger = new MM_Logger();
$logger->log_before_change( $action_type, $target_type, $target_id, $old_value, $new_value, $suggestion_id, $actor, $note );
}
}
}
