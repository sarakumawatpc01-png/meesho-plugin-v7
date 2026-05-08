<?php

if ( ! class_exists( 'MM_Schema_Generator' ) ) {
class MM_Schema_Generator {
public function generate( $post_id, $type = 'auto', $post_data = array() ) {
$post = get_post( $post_id );
if ( ! $post ) {
return '';
}
if ( empty( $post_data ) ) {
$post_data = MM_SEO_Crawler::collect_post_data( $post_id );
}
if ( 'auto' === $type ) {
$type = 'product' === $post->post_type ? 'Product' : 'Article';
}
if ( $this->has_type( $post_data['existing_schema'] ?? '', $type ) ) {
return '';
}
$method = 'build_' . strtolower( $type );
if ( ! method_exists( $this, $method ) ) {
return '';
}
$schema = $this->$method( $post, $post_data );
$json   = wp_json_encode( $schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
return null !== json_decode( $json, true ) ? $json : '';
}

private function has_type( $existing_schema, $type ) {
$decoded = json_decode( (string) $existing_schema, true );
if ( ! is_array( $decoded ) ) {
return false;
}
$items = isset( $decoded['@graph'] ) && is_array( $decoded['@graph'] ) ? $decoded['@graph'] : array( $decoded );
foreach ( $items as $item ) {
if ( is_array( $item ) && ( $item['@type'] ?? '' ) === $type ) {
return true;
}
}
return false;
}

private function build_article( $post ) {
return array(
'@context'      => 'https://schema.org',
'@type'         => 'Article',
'headline'      => $post->post_title,
'description'   => wp_trim_words( wp_strip_all_tags( $post->post_content ), 30 ),
'datePublished' => get_the_date( DATE_W3C, $post ),
'dateModified'  => get_the_modified_date( DATE_W3C, $post ),
'author'        => array(
'@type' => 'Person',
'name'  => get_the_author_meta( 'display_name', $post->post_author ),
),
);
}

private function build_product( $post, $post_data ) {
$product = function_exists( 'wc_get_product' ) ? wc_get_product( $post->ID ) : null;
$image   = '';
if ( $product ) {
$image = wp_get_attachment_url( $product->get_image_id() );
}
return array(
'@context'    => 'https://schema.org',
'@type'       => 'Product',
'name'        => $post->post_title,
'description' => wp_trim_words( wp_strip_all_tags( $post->post_content ), 40 ),
'image'       => $image,
'brand'       => array( '@type' => 'Brand', 'name' => get_bloginfo( 'name' ) ),
'sku'         => $product ? $product->get_sku() : ( $post_data['wc_data']['sku'] ?? '' ),
'offers'      => array(
'@type'         => 'Offer',
'price'         => $product ? $product->get_price() : ( $post_data['wc_data']['price'] ?? '' ),
'priceCurrency' => 'INR',
'availability'  => $product && $product->is_in_stock() ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
),
);
}

private function build_faq( $post ) {
preg_match_all( '/<h[2-4][^>]*>(.*?)<\/h[2-4]>\s*<p>(.*?)<\/p>/is', $post->post_content, $matches, PREG_SET_ORDER );
$entities = array();
foreach ( $matches as $match ) {
$question = trim( wp_strip_all_tags( $match[1] ) );
$answer   = trim( wp_strip_all_tags( $match[2] ) );
if ( false !== strpos( $question, '?' ) && '' !== $answer ) {
$entities[] = array(
'@type'          => 'Question',
'name'           => $question,
'acceptedAnswer' => array(
'@type' => 'Answer',
'text'  => $answer,
),
);
}
}
return array(
'@context'   => 'https://schema.org',
'@type'      => 'FAQPage',
'mainEntity' => $entities,
);
}

private function build_howto( $post ) {
preg_match_all( '/<li[^>]*>(.*?)<\/li>/is', $post->post_content, $matches );
$steps = array();
foreach ( $matches[1] as $index => $step ) {
$steps[] = array(
'@type' => 'HowToStep',
'position' => $index + 1,
'text' => trim( wp_strip_all_tags( $step ) ),
);
}
return array(
'@context' => 'https://schema.org',
'@type'    => 'HowTo',
'name'     => $post->post_title,
'step'     => $steps,
);
}

private function build_breadcrumblist( $post ) {
return array(
'@context'        => 'https://schema.org',
'@type'           => 'BreadcrumbList',
'itemListElement' => array(
array(
'@type'    => 'ListItem',
'position' => 1,
'name'     => get_bloginfo( 'name' ),
'item'     => home_url(),
),
array(
'@type'    => 'ListItem',
'position' => 2,
'name'     => $post->post_title,
'item'     => get_permalink( $post ),
),
),
);
}
}
}
