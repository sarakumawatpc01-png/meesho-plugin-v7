<?php

if ( ! class_exists( 'MM_SEO_Crawler' ) ) {
class MM_SEO_Crawler {
public static function detect_seo_plugin() {
if ( defined( 'WPSEO_VERSION' ) ) {
return 'yoast';
}
if ( defined( 'RANK_MATH_VERSION' ) ) {
return 'rankmath';
}
if ( defined( 'AIOSEO_VERSION' ) || function_exists( 'aioseo' ) ) {
return 'aioseo';
}
return 'none';
}

public static function get_meta_keys() {
$map = array(
'yoast'    => array(
'title'     => '_yoast_wpseo_title',
'desc'      => '_yoast_wpseo_metadesc',
'keyword'   => '_yoast_wpseo_focuskw',
'canonical' => '_yoast_wpseo_canonical',
),
'rankmath' => array(
'title'     => 'rank_math_title',
'desc'      => 'rank_math_description',
'keyword'   => 'rank_math_focus_keyword',
'canonical' => 'rank_math_canonical_url',
),
'aioseo'   => array(
'title'     => '_aioseo_title',
'desc'      => '_aioseo_description',
'keyword'   => '_aioseo_keywords',
'canonical' => '_aioseo_canonical_url',
),
'none'     => array(
'title'     => '_mm_seo_title',
'desc'      => '_mm_seo_desc',
'keyword'   => '_mm_focus_keyword',
'canonical' => '_mm_canonical_url',
),
);

return $map[ self::detect_seo_plugin() ];
}

public static function collect_post_data( $post_id ) {
$post = get_post( $post_id );
if ( ! $post ) {
return array();
}

$content      = (string) $post->post_content;
$content_text = trim( wp_strip_all_tags( $content ) );
$meta_keys    = self::get_meta_keys();
$schema       = self::get_existing_schema( $post_id );
$headings     = array();
$alt_tags     = array();
$internal     = array();
$external     = array();
$missing_alts = 0;

if ( preg_match_all( '/<h([1-6])[^>]*>(.*?)<\/h\1>/is', $content, $matches ) ) {
foreach ( $matches[1] as $index => $level ) {
$headings[] = array(
'level' => 'H' . $level,
'text'  => trim( wp_strip_all_tags( $matches[2][ $index ] ) ),
);
}
}

if ( preg_match_all( '/<img[^>]+alt=["\']([^"\']*)["\'][^>]*>/i', $content, $matches ) ) {
$alt_tags = array_map( 'sanitize_text_field', $matches[1] );
}
if ( preg_match_all( '/<img(?![^>]*alt=)[^>]*>/i', $content, $matches ) ) {
$missing_alts = count( $matches[0] );
}

$site_url = home_url();
if ( preg_match_all( '/<a[^>]+href=["\']([^"\']+)["\'][^>]*>/i', $content, $matches ) ) {
foreach ( $matches[1] as $href ) {
if ( 0 === strpos( $href, $site_url ) || 0 === strpos( $href, '/' ) ) {
$internal[] = $href;
} else {
$external[] = $href;
}
}
}

$focus_keyword = self::resolve_focus_keyword( $post_id, $post, $meta_keys, $content_text );
$canonical     = get_post_meta( $post_id, $meta_keys['canonical'], true );
if ( '' === $canonical ) {
$canonical = get_permalink( $post_id );
}

$wc_data = array();
if ( 'product' === $post->post_type && function_exists( 'wc_get_product' ) ) {
$product = wc_get_product( $post_id );
if ( $product ) {
$wc_data = array(
'price'      => $product->get_price(),
'sku'        => $product->get_sku(),
'image'      => wp_get_attachment_url( $product->get_image_id() ),
'categories' => wp_get_post_terms( $post_id, 'product_cat', array( 'fields' => 'names' ) ),
);
}
}

return array(
'post_id'               => $post_id,
'post_type'             => $post->post_type,
'title'                 => (string) $post->post_title,
'excerpt'               => (string) $post->post_excerpt,
'content'               => $content,
'content_text'          => $content_text,
'word_count'            => str_word_count( $content_text ),
'meta_title'            => (string) get_post_meta( $post_id, $meta_keys['title'], true ),
'meta_desc'             => (string) get_post_meta( $post_id, $meta_keys['desc'], true ),
'focus_keyword'         => $focus_keyword,
'canonical'             => $canonical,
'permalink'             => get_permalink( $post_id ),
'headings'              => $headings,
'alt_tags'              => $alt_tags,
'missing_alts'          => $missing_alts,
'internal_links'        => array_values( array_unique( $internal ) ),
'external_links'        => array_values( array_unique( $external ) ),
'existing_schema'       => $schema['raw'],
'existing_schema_types' => $schema['types'],
'schema_source'         => $schema['source'],
'has_list'              => (bool) preg_match( '/<(ul|ol)\b/i', $content ),
'has_table'             => (bool) preg_match( '/<table\b/i', $content ),
'wc_data'               => $wc_data,
);
}

private static function resolve_focus_keyword( $post_id, $post, $meta_keys, $content_text ) {
$candidates = array(
get_post_meta( $post_id, '_mm_focus_keyword', true ),
get_post_meta( $post_id, $meta_keys['keyword'], true ),
get_post_meta( $post_id, '_meesho_focus_keyword', true ),
);

foreach ( $candidates as $candidate ) {
$candidate = trim( (string) $candidate );
if ( '' !== $candidate ) {
update_post_meta( $post_id, '_mm_focus_keyword', $candidate );
return $candidate;
}
}

$words = preg_split( '/\s+/', strtolower( sanitize_text_field( $post->post_title ) ) );
$words = array_values(
array_filter(
$words,
static function ( $word ) {
return strlen( $word ) > 3;
}
)
);
$fallback = trim( implode( ' ', array_slice( $words, 0, 4 ) ) );
if ( '' === $fallback && '' !== $content_text ) {
$fallback = trim( implode( ' ', array_slice( preg_split( '/\s+/', strtolower( $content_text ) ), 0, 4 ) ) );
}
update_post_meta( $post_id, '_mm_focus_keyword', $fallback );
return $fallback;
}

private static function get_existing_schema( $post_id ) {
$schema_meta_keys = array( '_mm_schema_json', '_meesho_schema_jsonld' );
foreach ( $schema_meta_keys as $key ) {
$raw = (string) get_post_meta( $post_id, $key, true );
if ( '' !== trim( $raw ) ) {
return array(
'raw'    => $raw,
'types'  => self::extract_schema_types( $raw ),
'source' => 'post_meta',
);
}
}

$content = (string) get_post_field( 'post_content', $post_id );
if ( preg_match( '/<script[^>]+application\/ld\+json[^>]*>(.*?)<\/script>/is', $content, $match ) ) {
$raw = trim( $match[1] );
return array(
'raw'    => $raw,
'types'  => self::extract_schema_types( $raw ),
'source' => 'post_content',
);
}

if ( 'none' !== self::detect_seo_plugin() ) {
return array(
'raw'    => '',
'types'  => array(),
'source' => 'seo_plugin',
);
}

return array(
'raw'    => '',
'types'  => array(),
'source' => '',
);
}

private static function extract_schema_types( $raw ) {
$decoded = json_decode( $raw, true );
if ( ! is_array( $decoded ) ) {
return array();
}

$items = isset( $decoded['@graph'] ) && is_array( $decoded['@graph'] ) ? $decoded['@graph'] : array( $decoded );
$types = array();
foreach ( $items as $item ) {
if ( is_array( $item ) && ! empty( $item['@type'] ) ) {
$types[] = $item['@type'];
}
}

return array_values( array_unique( $types ) );
}
}
}
