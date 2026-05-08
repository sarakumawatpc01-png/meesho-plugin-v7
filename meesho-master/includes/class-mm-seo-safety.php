<?php

if ( ! class_exists( 'MM_SEO_Safety' ) ) {
class MM_SEO_Safety {
public static function can_auto_apply( $suggestion ) {
$types = array( 'meta_title', 'meta_desc', 'alt_tag', 'internal_link' );
return ! empty( $suggestion['safe_to_apply'] )
&& (int) ( $suggestion['confidence'] ?? 0 ) >= 85
&& 'high' === ( $suggestion['priority'] ?? '' )
&& in_array( $suggestion['type'] ?? '', $types, true );
}

public static function can_auto_apply_schema( $suggestion, $existing_schema ) {
if ( (int) ( $suggestion['confidence'] ?? 0 ) < 85 ) {
return false;
}
if ( ! empty( $existing_schema ) ) {
return false;
}
$decoded = json_decode( (string) ( $suggestion['suggested_value'] ?? '' ), true );
return is_array( $decoded );
}
}
}
