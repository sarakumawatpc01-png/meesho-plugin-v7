<?php

class Meesho_Master {
protected $plugin_name;
protected $version;

public function __construct() {
$this->plugin_name = 'meesho-master';
$this->version     = MEESHO_MASTER_VERSION;

$this->load_dependencies();
MM_DB::maybe_upgrade();
$this->define_admin_hooks();
$this->define_public_hooks();
$this->define_cron_hooks();
}

private function load_dependencies() {
require_once MEESHO_MASTER_PLUGIN_DIR . 'admin/class-meesho-admin.php';
require_once MEESHO_MASTER_PLUGIN_DIR . 'includes/class-mm-db.php';
require_once MEESHO_MASTER_PLUGIN_DIR . 'includes/class-mm-crypto.php';
require_once MEESHO_MASTER_PLUGIN_DIR . 'includes/class-mm-logger.php';
require_once MEESHO_MASTER_PLUGIN_DIR . 'includes/class-mm-dataforseo.php';
require_once MEESHO_MASTER_PLUGIN_DIR . 'includes/class-mm-seo-crawler.php';
require_once MEESHO_MASTER_PLUGIN_DIR . 'includes/class-mm-seo-scorer.php';
require_once MEESHO_MASTER_PLUGIN_DIR . 'includes/class-mm-seo-safety.php';
require_once MEESHO_MASTER_PLUGIN_DIR . 'includes/class-mm-seo-geo.php';
require_once MEESHO_MASTER_PLUGIN_DIR . 'includes/class-mm-schema-generator.php';
require_once MEESHO_MASTER_PLUGIN_DIR . 'includes/class-mm-seo-implementor.php';
require_once MEESHO_MASTER_PLUGIN_DIR . 'includes/class-mm-seo-analyzer.php';
require_once MEESHO_MASTER_PLUGIN_DIR . 'includes/class-mm-gsc.php';
require_once MEESHO_MASTER_PLUGIN_DIR . 'includes/modules/class-meesho-settings.php';
require_once MEESHO_MASTER_PLUGIN_DIR . 'includes/modules/class-meesho-undo.php';
require_once MEESHO_MASTER_PLUGIN_DIR . 'includes/modules/class-meesho-import.php';
require_once MEESHO_MASTER_PLUGIN_DIR . 'includes/modules/class-meesho-blogs.php';
require_once MEESHO_MASTER_PLUGIN_DIR . 'includes/modules/class-meesho-orders.php';
require_once MEESHO_MASTER_PLUGIN_DIR . 'includes/modules/class-meesho-seo.php';
require_once MEESHO_MASTER_PLUGIN_DIR . 'includes/modules/class-meesho-seo-analyzer.php';
require_once MEESHO_MASTER_PLUGIN_DIR . 'includes/modules/class-meesho-copilot.php';
require_once MEESHO_MASTER_PLUGIN_DIR . 'includes/modules/class-meesho-analytics.php';
}

private function define_admin_hooks() {
$plugin_admin = new Meesho_Master_Admin( $this->get_plugin_name(), $this->get_version() );
add_action( 'admin_menu', array( $plugin_admin, 'add_plugin_admin_menu' ) );
add_action( 'admin_enqueue_scripts', array( $plugin_admin, 'enqueue_styles' ) );
add_action( 'admin_enqueue_scripts', array( $plugin_admin, 'enqueue_scripts' ) );

new Meesho_Master_Settings();
		new MM_Undo();
		new Meesho_Master_Import();
		new Meesho_Master_Blogs();
		new Meesho_Master_Orders();
		new Meesho_Master_SEO();
		new Meesho_Master_Copilot();
		new Meesho_Master_Analytics();
		MM_Scheduler::init();
}

private function define_public_hooks() {
		// Add "Reviews from Meesho" tab to WooCommerce products
		add_filter( 'woocommerce_product_tabs', array( $this, 'add_meesho_reviews_tab' ) );
		// Enqueue frontend styles
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_public_styles' ) );
		// Register shortcode [meesho_reviews]
		add_shortcode( 'meesho_reviews', array( $this, 'shortcode_reviews' ) );
	}

	/**
	 * Enqueue public-facing styles for Meesho Reviews.
	 */
	public function enqueue_public_styles() {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}
		wp_enqueue_style(
			'meesho-reviews',
			MEESHO_MASTER_PLUGIN_URL . 'public/css/meesho-reviews.css',
			array(),
			$this->version,
			'all'
		);
	}

	private function define_cron_hooks() {
add_action( 'admin_notices', array( $this, 'check_wp_cron_health' ) );
}

public function check_wp_cron_health() {
if ( ! current_user_can( 'manage_options' ) ) {
return;
}
if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
echo '<div class="notice notice-warning is-dismissible"><p><strong>Meesho Master:</strong> WP Cron is disabled. SEO automation and reports may not run.</p></div>';
}
}

public function shortcode_reviews( $atts ) {
$atts = shortcode_atts( array( 'sku' => '' ), $atts );
$sku  = $atts['sku'];
if ( empty( $sku ) && function_exists( 'wc_get_product' ) && is_singular( 'product' ) ) {
$sku = get_post_meta( get_the_ID(), '_meesho_sku', true );
}
if ( empty( $sku ) ) {
return '<p>No Meesho SKU found.</p>';
}

$breakdown = get_post_meta( get_the_ID(), '_meesho_rating_breakdown', true );
$avg       = get_post_meta( get_the_ID(), '_meesho_avg_rating', true );
$count     = get_post_meta( get_the_ID(), '_meesho_review_count', true );
if ( ! is_array( $breakdown ) ) {
$breakdown = array( '5' => 0, '4' => 0, '3' => 0, '2' => 0, '1' => 0 );
}

$colors = array( '5' => '#10B981', '4' => '#34D399', '3' => '#F59E0B', '2' => '#F97316', '1' => '#EF4444' );
$html   = '<div class="meesho-review-breakdown" style="max-width:400px; font-family:Arial,sans-serif;">';
$html  .= '<div style="display:flex; align-items:center; gap:12px; margin-bottom:12px;">';
$html  .= '<span style="font-size:36px; font-weight:700; color:#1E293B;">' . esc_html( $avg ?: '0' ) . '</span>';
$html  .= '<div><div style="color:#F59E0B; font-size:18px;">★★★★★</div>';
$html  .= '<small style="color:#64748B;">' . intval( $count ) . ' reviews</small></div></div>';
for ( $star = 5; $star >= 1; $star-- ) {
$pct   = intval( $breakdown[ (string) $star ] ?? 0 );
$html .= '<div style="display:flex; align-items:center; gap:8px; margin-bottom:4px;">';
$html .= '<span style="width:16px; font-size:13px; font-weight:600;">' . $star . '</span>';
$html .= '<div style="flex:1; height:10px; background:#E2E8F0; border-radius:5px; overflow:hidden;">';
$html .= '<div style="width:' . $pct . '%; height:100%; background:' . $colors[ (string) $star ] . '; border-radius:5px;"></div></div>';
$html .= '<span style="width:32px; font-size:12px; color:#64748B; text-align:right;">' . $pct . '%</span>';
$html .= '</div>';
}
$html .= '</div>';

global $wpdb;
$reviews = $wpdb->get_results(
$wpdb->prepare(
'SELECT * FROM ' . MM_DB::table( 'reviews' ) . ' WHERE meesho_sku = %s ORDER BY id DESC LIMIT 5',
$sku
)
);
if ( $reviews ) {
$html .= '<div style="margin-top:16px;">';
foreach ( $reviews as $review ) {
$stars  = str_repeat( '★', intval( $review->star_rating ) ) . str_repeat( '☆', 5 - intval( $review->star_rating ) );
$date   = $review->review_date ? mysql2date( 'd/m/Y', $review->review_date ) : '';
$html  .= '<div style="border-top:1px solid #E2E8F0; padding:10px 0;">';
$html  .= '<strong>' . esc_html( $review->reviewer_name ) . '</strong> <span style="color:#F59E0B;">' . $stars . '</span>';
$html  .= '<br><small style="color:#64748B;">' . esc_html( $date ) . '</small>';
if ( ! empty( $review->review_text ) ) {
$html .= '<p style="margin:4px 0 0; font-size:14px;">' . esc_html( $review->review_text ) . '</p>';
}
$html .= '</div>';
}
$html .= '</div>';
}

return $html;
}

/**
 * Add "Reviews from Meesho" tab to WooCommerce product pages.
 */
public function add_meesho_reviews_tab( $tabs ) {
	global $post;
	$meesho_sku = get_post_meta( $post->ID, '_meesho_sku', true );
	if ( empty( $meesho_sku ) ) {
		return $tabs;
	}

	$avg_rating = get_post_meta( $post->ID, '_meesho_avg_rating', true );
	$review_count = get_post_meta( $post->ID, '_meesho_review_count', true );
	$tab_title = 'Reviews from Meesho';
	if ( $review_count ) {
		$tab_title .= ' (' . intval( $review_count ) . ')';
	}

	$tabs['meesho_reviews'] = array(
		'title'    => $tab_title,
		'priority' => 25,
		'callback' => array( $this, 'render_meesho_reviews_tab' ),
	);
	return $tabs;
}

/**
 * Render the "Reviews from Meesho" tab content.
 */
public function render_meesho_reviews_tab() {
	global $post, $wpdb;
	$meesho_sku = get_post_meta( $post->ID, '_meesho_sku', true );
	if ( empty( $meesho_sku ) ) {
		echo '<p>No Meesho reviews available.</p>';
		return;
	}

	// Output custom CSS if exists
	$custom_css = get_option( 'mm_meesho_reviews_css', '' );
	if ( ! empty( $custom_css ) ) {
		echo '<style id="mm-meesho-reviews-custom-css">' . wp_strip_all_tags( $custom_css ) . '</style>';
	}

	// Rating breakdown
	$avg       = get_post_meta( $post->ID, '_meesho_avg_rating', true );
	$count     = get_post_meta( $post->ID, '_meesho_review_count', true );
	$breakdown = get_post_meta( $post->ID, '_meesho_rating_breakdown', true );
	if ( ! is_array( $breakdown ) ) {
		$breakdown = array( '5' => 0, '4' => 0, '3' => 0, '2' => 0, '1' => 0 );
	}

	// Get star color from CSS variable or default
	$star_color = '#F59E0B'; // Default gold
	?>
	<div class="mm-meesho-reviews-tab">
		<div class="mm-reviews-summary">
			<div class="mm-avg-rating"><?php echo esc_html( $avg ?: '0' ); ?></div>
			<div class="mm-reviews-meta">
				<div class="mm-stars"><?php
					$full_stars = floor( $avg );
					$half_star   = ( $avg - $full_stars ) >= 0.5;
					echo str_repeat( '★', $full_stars ) . ( $half_star ? '☆' : '' ) . str_repeat( '☆', 5 - $full_stars - ( $half_star ? 1 : 0 ) );
				?></div>
				<div class="mm-review-count">Based on <?php echo intval( $count ); ?> reviews from Meesho</div>
			</div>
		</div>

		<div class="mm-reviews-content">
			<div class="mm-rating-breakdown">
				<?php for ( $star = 5; $star >= 1; $star-- ) :
					$pct = intval( $breakdown[ (string) $star ] ?? 0 );
					$bar_color = $this->get_rating_bar_color( $star );
					?>
					<div class="mm-rating-bar-row">
						<span class="mm-star-label"><?php echo $star; ?>★</span>
						<div class="mm-rating-bar">
							<div class="mm-rating-bar-fill" style="width:<?php echo $pct; ?>%; background:<?php echo $bar_color; ?>;"></div>
						</div>
						<span class="mm-rating-pct"><?php echo $pct; ?>%</span>
					</div>
				<?php endfor; ?>
			</div>

			<div class="mm-reviews-list">
				<?php
				$reviews = $wpdb->get_results( $wpdb->prepare(
					'SELECT * FROM ' . MM_DB::table( 'reviews' ) . ' WHERE meesho_sku = %s ORDER BY id DESC LIMIT 10',
					$meesho_sku
				) );
				if ( $reviews ) :
					foreach ( $reviews as $review ) :
						$stars  = str_repeat( '★', intval( $review->star_rating ) ) . str_repeat( '☆', 5 - intval( $review->star_rating ) );
						$date   = $review->review_date ? mysql2date( 'd/m/Y', $review->review_date ) : '';
						?>
						<div class="mm-review-item">
							<div class="mm-review-header">
								<div class="mm-reviewer-avatar"><?php echo esc_html( strtoupper( substr( $review->reviewer_name, 0, 1 ) ) ); ?></div>
								<div class="mm-reviewer-info">
									<div class="mm-reviewer-name"><?php echo esc_html( $review->reviewer_name ); ?></div>
									<div class="mm-review-stars"><?php echo $stars; ?></div>
								</div>
								<div class="mm-review-date"><?php echo esc_html( $date ); ?></div>
							</div>
							<?php if ( ! empty( $review->review_text ) ) : ?>
								<p class="mm-review-text"><?php echo esc_html( $review->review_text ); ?></p>
							<?php endif; ?>
							<?php if ( ! empty( $review->review_image_url ) ) : ?>
								<img src="<?php echo esc_url( $review->review_image_url ); ?>" class="mm-review-image" alt="Review photo">
							<?php endif; ?>
						</div>
					<?php endforeach;
				else :
					?>
					<p class="mm-no-reviews">No individual reviews available. Rating breakdown shown above.</p>
				<?php endif; ?>
			</div>
		</div>

		<div class="mm-reviews-disclaimer">
			ⓘ These reviews are sourced from Meesho and reflect customer experiences on that platform. They are displayed here for reference only.
		</div>
	</div>
	<?php
}

/**
 * Get CSS color for rating bar based on star level.
 */
private function get_rating_bar_color( $star ) {
	$colors = array(
		'5' => 'var(--mm-star-5-color, #10B981)',
		'4' => 'var(--mm-star-4-color, #34D399)',
		'3' => 'var(--mm-star-3-color, #F59E0B)',
		'2' => 'var(--mm-star-2-color, #F97316)',
		'1' => 'var(--mm-star-1-color, #EF4444)',
	);
	return $colors[ (string) $star ] ?? '#10B981';
}

public function run() {}
public function get_plugin_name() { return $this->plugin_name; }
public function get_version() { return $this->version; }
}
