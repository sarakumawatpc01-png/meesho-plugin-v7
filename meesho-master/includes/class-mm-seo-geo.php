<?php

if ( ! class_exists( 'MM_SEO_Geo' ) ) {
class MM_SEO_Geo {
public static function generate_llms_txt() {
if ( ! function_exists( 'WP_Filesystem' ) ) {
require_once ABSPATH . 'wp-admin/includes/file.php';
}
if ( ! WP_Filesystem() ) {
return new WP_Error( 'mm_filesystem_failed', 'WP_Filesystem could not be initialized.' );
}

$site_name    = get_bloginfo( 'name' );
$tagline      = get_bloginfo( 'description' );
$admin_email  = get_option( 'admin_email' );
$categories   = get_terms(
array(
'taxonomy'   => 'product_cat',
'hide_empty' => true,
'number'     => 3,
'fields'     => 'names',
)
);
$categories   = is_wp_error( $categories ) ? array() : $categories;
$about_suffix = ! empty( $categories ) ? ' Top categories: ' . implode( ', ', $categories ) . '.' : '';
$content      = sprintf(
"# %s — AI Crawler Access Policy\n# %s\n# Last updated: %s\n# Contact: %s\n\nUser-agent: GPTBot\nAllow: /\nDisallow: /wp-admin/\nDisallow: /cart/\nDisallow: /checkout/\nDisallow: /my-account/\nDisallow: /?s=\n\nUser-agent: ClaudeBot\nAllow: /\nDisallow: /wp-admin/\nDisallow: /cart/\nDisallow: /checkout/\nDisallow: /my-account/\nDisallow: /?s=\n\nUser-agent: PerplexityBot\nAllow: /\nDisallow: /wp-admin/\n\nUser-agent: Googlebot\nAllow: /\n\nSitemap: %s/sitemap.xml\n\n# About %s\n# %s%s\n",
$site_name,
$tagline,
wp_date( 'Y-m-d' ),
$admin_email,
home_url(),
$site_name,
$tagline,
$about_suffix
);

global $wp_filesystem;
$result = $wp_filesystem->put_contents( ABSPATH . 'llms.txt', $content, FS_CHMOD_FILE );
if ( ! $result ) {
return new WP_Error( 'mm_llms_write_failed', 'Unable to write llms.txt.' );
}

update_option( 'mm_llms_last_generated', current_time( 'mysql' ) );
return $content;
}

public static function llms_txt_exists() {
return file_exists( ABSPATH . 'llms.txt' );
}

public static function llms_txt_allows_major_bots() {
$content = self::get_llms_txt_content();
if ( '' === $content ) {
return false;
}
return false !== stripos( $content, 'User-agent: GPTBot' )
&& false !== stripos( $content, 'User-agent: ClaudeBot' )
&& false !== stripos( $content, 'Allow: /' );
}

public static function get_llms_txt_content() {
$path = ABSPATH . 'llms.txt';
if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
return '';
}
$content = file_get_contents( $path );
return false === $content ? '' : (string) $content;
}
}
}
