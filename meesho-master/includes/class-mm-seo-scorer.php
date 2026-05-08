<?php

if ( ! class_exists( 'MM_SEO_Scorer' ) ) {
class MM_SEO_Scorer {
public function score( $data ) {
return array(
'seo'       => $this->score_seo( $data ),
'aeo'       => $this->score_aeo( $data ),
'geo'       => $this->score_geo( $data ),
'keyword'   => (string) ( $data['focus_keyword'] ?? '' ),
'breakdown' => array(
'seo' => $this->seo_breakdown( $data ),
'aeo' => $this->aeo_breakdown( $data ),
'geo' => $this->geo_breakdown( $data ),
),
);
}

private function seo_breakdown( $data ) {
$keyword = strtolower( (string) ( $data['focus_keyword'] ?? '' ) );
$meta_title = (string) ( $data['meta_title'] ?? '' );
$meta_desc  = (string) ( $data['meta_desc'] ?? '' );
$content    = strtolower( (string) ( $data['content_text'] ?? '' ) );
$h1s        = array_values( array_filter( $data['headings'] ?? array(), static function ( $heading ) {
return 'H1' === ( $heading['level'] ?? '' );
} ) );
$h2s        = array_values( array_filter( $data['headings'] ?? array(), static function ( $heading ) {
return 'H2' === ( $heading['level'] ?? '' );
} ) );
$first_100_words = implode( ' ', array_slice( preg_split( '/\s+/', $content ), 0, 100 ) );
$word_count      = max( 1, (int) ( $data['word_count'] ?? 0 ) );
$keyword_count   = '' !== $keyword ? preg_match_all( '/\b' . preg_quote( $keyword, '/' ) . '\b/i', $content ) : 0;
$density         = $keyword_count > 0 ? ( $keyword_count / $word_count ) * 100 : 0;
$product_post    = 'product' === ( $data['post_type'] ?? '' );
$schema_types    = $data['existing_schema_types'] ?? array();

return array(
'meta' => min(
20,
( strlen( $meta_title ) >= 50 && strlen( $meta_title ) <= 60 ? 10 : 0 )
+ ( strlen( $meta_desc ) >= 120 && strlen( $meta_desc ) <= 160 ? 5 : 0 )
+ ( $keyword && false !== stripos( $meta_title, $keyword ) ? 3 : 0 )
+ ( $keyword && false !== stripos( $meta_desc, $keyword ) ? 2 : 0 )
),
'heading' => min(
15,
( 1 === count( $h1s ) ? 7 : 0 )
+ ( ! empty( $h1s ) && $keyword && false !== stripos( $h1s[0]['text'], $keyword ) ? 4 : 0 )
+ ( count( $h2s ) >= 2 ? 4 : 0 )
),
'keyword' => min(
15,
( $keyword && false !== strpos( $first_100_words, $keyword ) ? 7 : 0 )
+ ( $density >= 1 && $density <= 3 ? 8 : 0 )
),
'internal' => count( $data['internal_links'] ?? array() ) >= 3 ? 10 : ( count( $data['internal_links'] ?? array() ) >= 1 ? 5 : 0 ),
'content'  => $product_post
? ( $word_count >= 300 ? 20 : ( $word_count >= 150 ? 12 : 0 ) )
: ( $word_count >= 500 ? 20 : ( $word_count >= 300 ? 12 : 0 ) ),
'technical' => min(
20,
( 0 === (int) ( $data['missing_alts'] ?? 0 ) ? 8 : 0 )
+ ( ! empty( $schema_types ) ? 6 : 0 )
+ ( (string) ( $data['canonical'] ?? '' ) === (string) ( $data['permalink'] ?? '' ) ? 6 : 0 )
),
'product_schema_missing' => $product_post && ! in_array( 'Product', $schema_types, true ),
);
}

private function aeo_breakdown( $data ) {
$content      = (string) ( $data['content'] ?? '' );
$content_text = (string) ( $data['content_text'] ?? '' );
$question_count = preg_match_all( '/\?\s*<\/h[34]>\s*<p>[^<]{1,240}<\/p>/i', $content );
$heading_question_count = 0;
foreach ( $data['headings'] ?? array() as $heading ) {
if ( in_array( $heading['level'], array( 'H3', 'H4' ), true ) && false !== strpos( $heading['text'], '?' ) ) {
$heading_question_count++;
}
}
$sentences = preg_split( '/[.!?]+/', $content_text, -1, PREG_SPLIT_NO_EMPTY );
$avg_sentence_len = 999;
if ( ! empty( $sentences ) ) {
$avg_sentence_len = array_sum( array_map( 'str_word_count', $sentences ) ) / count( $sentences );
}
$first_200_words = implode( ' ', array_slice( preg_split( '/\s+/', $content_text ), 0, 200 ) );
$direct_answers = $question_count >= 3 ? 30 : ( $question_count >= 1 ? 15 : 0 );
$faq = ( in_array( 'FAQPage', $data['existing_schema_types'] ?? array(), true ) ? 10 : 0 ) + ( $heading_question_count >= 3 ? 10 : 0 );
$clarity = $avg_sentence_len <= 20 ? 20 : ( $avg_sentence_len <= 30 ? 10 : 0 );
$structure = ( ! empty( $data['has_list'] ) ? 8 : 0 ) + ( ! empty( $data['has_table'] ) ? 7 : 0 );
$snippet = preg_match( '/^[^.?!]{1,300}\b(is|are|means|refers to)\b[^.?!]{1,200}[.?!]/i', $first_200_words ) ? 8 : 0;
$snippet += ( ! empty( $data['has_list'] ) || ! empty( $data['has_table'] ) ? 7 : 0 );

if ( 'product' === ( $data['post_type'] ?? '' ) ) {
$faq       = min( 35, $faq + 15 );
$structure = min( 30, $structure + 15 );
$direct_answers = 0;
}

return array(
'direct_answers' => $direct_answers,
'faq'            => min( 35, $faq ),
'clarity'        => $clarity,
'structure'      => min( 'product' === ( $data['post_type'] ?? '' ) ? 30 : 15, $structure ),
'snippet'        => min( 15, $snippet ),
);
}

private function geo_breakdown( $data ) {
$content_text = (string) ( $data['content_text'] ?? '' );
$paragraphs   = array_values( array_filter( preg_split( '/\n\s*\n+|<\/p>/i', (string) ( $data['content'] ?? '' ) ) ) );
$citability_blocks = 0;
foreach ( $paragraphs as $paragraph ) {
$words = str_word_count( wp_strip_all_tags( $paragraph ) );
if ( $words >= 100 && $words <= 200 ) {
$citability_blocks++;
}
}
$factual_matches = preg_match_all( '/\d+%|\d+\s*(crore|lakh|million|billion|rupees|₹|rs)\b|\b\d{3,}\b/i', $content_text );
$brand_mention   = false !== stripos( $content_text, get_bloginfo( 'name' ) ) ? 10 : 0;
$authority       = preg_match( '/according to|source:|cited|updated on|published on/i', $content_text ) ? 10 : 0;
$structure       = ( count( $data['headings'] ?? array() ) >= 3 ? 8 : 0 ) + ( ! empty( $data['existing_schema_types'] ) ? 7 : 0 );
$llms_exists     = MM_SEO_Geo::llms_txt_exists() ? 10 : 0;
$llms_exists    += MM_SEO_Geo::llms_txt_allows_major_bots() ? 5 : 0;

return array(
'citability' => $citability_blocks >= 2 ? 30 : ( $citability_blocks >= 1 ? 15 : 0 ),
'factual'    => $factual_matches >= 3 ? 20 : ( $factual_matches >= 1 ? 10 : 0 ),
'brand'      => $brand_mention,
'authority'  => $authority,
'structure'  => min( 15, $structure ),
'llms'       => min( 15, $llms_exists ),
);
}

private function score_seo( $data ) {
$parts = $this->seo_breakdown( $data );
$total = $parts['meta'] + $parts['heading'] + $parts['keyword'] + $parts['internal'] + $parts['content'] + $parts['technical'];
if ( ! empty( $parts['product_schema_missing'] ) ) {
$total -= $parts['technical'];
}
return max( 0, min( 100, (int) $total ) );
}

private function score_aeo( $data ) {
$parts = $this->aeo_breakdown( $data );
return max( 0, min( 100, (int) array_sum( $parts ) ) );
}

private function score_geo( $data ) {
$parts = $this->geo_breakdown( $data );
return max( 0, min( 100, (int) array_sum( $parts ) ) );
}
}
}
