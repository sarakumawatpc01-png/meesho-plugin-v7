<?php
/**
 * Settings tab — v6.3
 * - Save button now wired correctly
 * - API test buttons for each service
 * - Separate fields for GA, GSC, Google Ads, PageSpeed
 * - Prompt template fields (description, image, blog, SEO)
 * - Image generation provider config
 * - Master prompt for image generation
 * - Master blog instructions
 */
$settings = new Meesho_Master_Settings();
$all      = $settings->get_all();
$accounts = $settings->get_accounts();

$test_btn = function ( $service, $label = 'Test' ) {
	echo '<button type="button" class="mm-btn mm-btn-outline mm-btn-sm mm-test-api-btn" data-service="' . esc_attr( $service ) . '">🧪 ' . esc_html( $label ) . '</button>';
};
?>
<h3>⚙️ Settings</h3>

<form id="meesho_settings_form" onsubmit="return false;">
<div class="mm-grid mm-grid-2">

	<!-- API Keys -->
	<div class="mm-card">
		<h3>🔑 AI / OpenRouter</h3>
		<div class="mm-form-row">
			<label class="mm-label">OpenRouter API Key</label>
			<div class="mm-flex mm-gap-10">
				<input type="password" name="mm_openrouter_key" class="mm-input" value="<?php echo esc_attr( $settings->get( 'mm_openrouter_key' ) ); ?>" placeholder="sk-or-..." style="flex:1;">
				<?php $test_btn( 'openrouter' ); ?>
			</div>
			<p class="mm-text-muted" style="font-size:12px;">Used by description optimizer, SEO suggestions, Copilot, blog generation, image generation.</p>
		</div>
	</div>

	<!-- Pricing -->
	<div class="mm-card">
		<h3>💰 Pricing Markup</h3>
		<p class="mm-text-muted" style="font-size:12px;">Default rules. Each product has a manual price-override field on its edit modal that takes precedence.</p>
		<div class="mm-form-row">
			<label class="mm-label">Markup Type</label>
			<select name="mm_markup_type" class="mm-select">
				<option value="percentage" <?php selected( $settings->get( 'mm_markup_type' ), 'percentage' ); ?>>Percentage (%)</option>
				<option value="flat" <?php selected( $settings->get( 'mm_markup_type' ), 'flat' ); ?>>Flat Amount (₹)</option>
			</select>
		</div>
		<div class="mm-form-row">
			<label class="mm-label">Markup Value</label>
			<input type="number" name="mm_markup_value" class="mm-input" value="<?php echo esc_attr( $settings->get( 'mm_markup_value' ) ); ?>" step="0.01">
		</div>
		<div class="mm-form-row">
			<label class="mm-label">Rounding Rule</label>
			<select name="mm_price_round" class="mm-select">
				<option value="none" <?php selected( $settings->get( 'mm_price_round' ), 'none' ); ?>>No Rounding</option>
				<option value="nearest_10" <?php selected( $settings->get( 'mm_price_round' ), 'nearest_10' ); ?>>Nearest ₹10</option>
				<option value="nearest_50" <?php selected( $settings->get( 'mm_price_round' ), 'nearest_50' ); ?>>Nearest ₹50</option>
				<option value="nearest_99" <?php selected( $settings->get( 'mm_price_round' ), 'nearest_99' ); ?>>Nearest ₹99 (e.g. 199, 299)</option>
			</select>
		</div>
	</div>

	<!-- Google APIs (separate) -->
	<div class="mm-card" style="grid-column: span 2;">
		<h3>🔵 Google APIs <span class="mm-text-muted" style="font-size:13px; font-weight:normal;">— each service uses its own credential</span></h3>
		<p class="mm-text-muted" style="font-size:12px;">Each Google service requires its own credential. PageSpeed and YouTube can use simple API keys; Analytics/Ads/Search Console need OAuth (use Google Site Kit for those).</p>
		<div class="mm-grid mm-grid-2">
			<div class="mm-form-row">
				<label class="mm-label">PageSpeed Insights API Key</label>
				<div class="mm-flex mm-gap-10">
					<input type="password" name="mm_google_pagespeed_key" class="mm-input" value="<?php echo esc_attr( $settings->get( 'mm_google_pagespeed_key' ) ); ?>" placeholder="AIza..." style="flex:1;">
					<?php $test_btn( 'google_pagespeed' ); ?>
				</div>
			</div>
			<div class="mm-form-row">
				<label class="mm-label">Google Analytics 4 — Measurement ID</label>
				<div class="mm-flex mm-gap-10">
					<input type="text" name="mm_google_analytics_id" class="mm-input" value="<?php echo esc_attr( $settings->get( 'mm_google_analytics_id' ) ); ?>" placeholder="G-XXXXXXXXXX" style="flex:1;">
					<?php $test_btn( 'google_analytics_id' ); ?>
				</div>
				<p class="mm-text-muted" style="font-size:11px;">For client-side tracking only. For data fetching, install Google Site Kit.</p>
			</div>
			<div class="mm-form-row">
				<label class="mm-label">Google Tag Manager ID (optional)</label>
				<div class="mm-flex mm-gap-10">
					<input type="text" name="mm_google_tag_manager_id" class="mm-input" value="<?php echo esc_attr( $settings->get( 'mm_google_tag_manager_id' ) ); ?>" placeholder="GTM-XXXXXXX" style="flex:1;">
					<?php $test_btn( 'google_tag_manager_id' ); ?>
				</div>
			</div>
			<div class="mm-form-row">
				<label class="mm-label">Google Ads Customer ID</label>
				<p class="mm-text-muted" style="font-size:11px;">➡ Moved to <strong>Google Ads Manager</strong> card below.</p>
			</div>
			<div class="mm-form-row">
				<label class="mm-label">Search Console</label>
				<p class="mm-text-muted" style="font-size:11px;">➡ Moved to <strong>Google Search Console</strong> card below.</p>
			</div>
		</div>
	</div>

	<!-- GA4 Data API (E2) -->
	<div class="mm-card">
		<h3>📊 Google Analytics 4 — Data API</h3>
		<p class="mm-text-muted" style="font-size:12px;">Required to show traffic data in the Analytics tab. Choose one authentication method.</p>
		<div class="mm-form-row">
			<label class="mm-label">GA4 Property ID</label>
			<input type="text" name="mm_ga4_property_id" class="mm-input" value="<?php echo esc_attr( $settings->get( 'mm_ga4_property_id' ) ); ?>" placeholder="123456789">
			<p class="mm-text-muted" style="font-size:11px;">Found in Google Analytics → Admin → Property Settings → Property ID.</p>
		</div>
		<div class="mm-form-row">
			<label class="mm-label">Authentication Mode</label>
			<div class="mm-flex mm-gap-20">
				<label style="font-size:13px;cursor:pointer;">
					<input type="radio" name="mm_ga4_mode" value="site_kit" <?php checked( $settings->get( 'mm_ga4_mode', 'site_kit' ), 'site_kit' ); ?>> Use Google Site Kit (recommended)
				</label>
				<label style="font-size:13px;cursor:pointer;">
					<input type="radio" name="mm_ga4_mode" value="service_account" <?php checked( $settings->get( 'mm_ga4_mode', 'site_kit' ), 'service_account' ); ?>> Service Account JSON
				</label>
			</div>
			<p class="mm-text-muted" style="font-size:11px;">Site Kit: install &amp; connect the Google Site Kit plugin — no extra credentials needed here. Service Account: download JSON from Google Cloud Console → IAM &amp; Admin → Service Accounts → Keys.</p>
		</div>
		<div class="mm-form-row" id="mm_ga4_sa_row" style="<?php echo $settings->get( 'mm_ga4_mode', 'site_kit' ) === 'site_kit' ? 'display:none;' : ''; ?>">
			<label class="mm-label">Service Account JSON</label>
			<textarea name="mm_ga4_service_account_json" class="mm-textarea" rows="5" style="font-family:monospace;font-size:12px;" placeholder='{"type":"service_account","project_id":"...","private_key":"-----BEGIN RSA PRIVATE KEY-----..."}'><?php echo esc_textarea( $settings->get( 'mm_ga4_service_account_json' ) ); ?></textarea>
			<p class="mm-text-muted" style="font-size:11px;">Grant the service account <strong>Viewer</strong> role in Google Analytics Admin → Account Access Management.</p>
		</div>
	</div>

	<!-- F1: Google Ads Manager (OAuth) -->
	<div class="mm-card">
		<h3>📣 Google Ads Manager</h3>
		<?php
		$ads_refresh = $settings->get( 'mm_google_ads_refresh_token' );
		$ads_status  = $ads_refresh ? '<span class="mm-badge mm-badge-success">OAuth configured</span>' : '<span class="mm-badge mm-badge-info">Not connected</span>';
		?>
		<p class="mm-text-muted" style="font-size:12px;">Status: <?php echo $ads_status; ?></p>
		<div class="mm-grid mm-grid-2">
			<div class="mm-form-row">
				<label class="mm-label">Customer ID (10 digits)</label>
				<div class="mm-flex mm-gap-10">
					<input type="text" name="mm_google_ads_customer_id" class="mm-input" value="<?php echo esc_attr( $settings->get( 'mm_google_ads_customer_id' ) ); ?>" placeholder="123-456-7890" style="flex:1;">
					<?php $test_btn( 'google_ads_customer_id' ); ?>
				</div>
			</div>
			<div class="mm-form-row">
				<label class="mm-label">Manager (MCC) Account ID <span class="mm-text-muted" style="font-weight:normal;">(optional)</span></label>
				<input type="text" name="mm_google_ads_mcc_id" class="mm-input" value="<?php echo esc_attr( $settings->get( 'mm_google_ads_mcc_id' ) ); ?>" placeholder="987-654-3210">
			</div>
			<div class="mm-form-row">
				<label class="mm-label">Developer Token</label>
				<div class="mm-flex mm-gap-10">
					<input type="password" name="mm_google_ads_developer_token" class="mm-input" value="<?php echo esc_attr( $settings->get( 'mm_google_ads_developer_token' ) ); ?>" placeholder="22-char token" style="flex:1;">
				</div>
			</div>
			<div class="mm-form-row">
				<label class="mm-label">OAuth Client ID</label>
				<input type="text" name="mm_google_ads_client_id" class="mm-input" value="<?php echo esc_attr( $settings->get( 'mm_google_ads_client_id' ) ); ?>" placeholder="XXXXXXXX.apps.googleusercontent.com">
			</div>
			<div class="mm-form-row">
				<label class="mm-label">OAuth Client Secret</label>
				<input type="password" name="mm_google_ads_client_secret" class="mm-input" value="<?php echo esc_attr( $settings->get( 'mm_google_ads_client_secret' ) ); ?>" placeholder="GOCSPX-...">
			</div>
			<div class="mm-form-row">
				<label class="mm-label">OAuth Refresh Token</label>
				<div class="mm-flex mm-gap-10">
					<input type="password" name="mm_google_ads_refresh_token" class="mm-input" value="<?php echo esc_attr( $settings->get( 'mm_google_ads_refresh_token' ) ); ?>" placeholder="1//..." style="flex:1;">
					<?php $test_btn( 'google_ads_full' ); ?>
				</div>
			</div>
		</div>
		<div class="mm-card" style="background:#f0f9ff;border:1px solid #bae6fd;padding:12px;margin-top:12px;font-size:12px;color:#0369a1;">
			<strong>Note:</strong> Full Google Ads campaign management requires Google Ads API access.
			Apply at: <a href="https://developers.google.com/google-ads/api/docs/access-levels" target="_blank" rel="noopener">https://developers.google.com/google-ads/api/docs/access-levels</a><br>
			Standard access takes 2–4 weeks to approve. Basic access (read-only) is immediate.<br>
			For campaign management in WordPress, also consider installing the <strong>Google Listings &amp; Ads</strong> plugin which handles OAuth for you.
		</div>
	</div>

	<!-- F3: Search Console — unified auth -->
	<div class="mm-card">
		<h3>🔍 Google Search Console</h3>
		<p class="mm-text-muted" style="font-size:12px;">Choose how you want to authenticate with Google Search Console for ranking data.</p>
		<div class="mm-form-row">
			<label class="mm-label">Authentication Mode</label>
			<div class="mm-flex mm-gap-20">
				<label style="font-size:13px;cursor:pointer;">
					<input type="radio" name="mm_gsc_mode" value="site_kit" <?php checked( $settings->get( 'mm_gsc_mode', 'site_kit' ), 'site_kit' ); ?>> Use Google Site Kit (recommended)
				</label>
				<label style="font-size:13px;cursor:pointer;">
					<input type="radio" name="mm_gsc_mode" value="service_account" <?php checked( $settings->get( 'mm_gsc_mode', 'site_kit' ), 'service_account' ); ?>> Service Account JSON
				</label>
			</div>
			<p class="mm-text-muted" style="font-size:11px;">
				<strong>Site Kit:</strong> Install &amp; connect the Google Site Kit plugin — no extra credentials needed here.<br>
				<strong>Service Account:</strong> Download from Google Cloud Console → IAM &amp; Admin → Service Accounts → your account → Keys → Add Key → JSON.<br>
				Grant the service account <strong>Search Console Viewer</strong> role in Google Search Console → Settings → Users and Permissions.
			</p>
		</div>
		<div class="mm-form-row" id="mm_gsc_sa_row" style="<?php echo $settings->get( 'mm_gsc_mode', 'site_kit' ) === 'site_kit' ? 'display:none;' : ''; ?>">
			<label class="mm-label">Service Account JSON</label>
			<textarea name="mm_gsc_service_account_json" class="mm-textarea" rows="5" style="font-family:monospace;font-size:12px;" placeholder='{"type":"service_account","project_id":"...","private_key":"-----BEGIN RSA PRIVATE KEY-----..."}'><?php echo esc_textarea( $settings->get( 'mm_gsc_service_account_json' ) ); ?></textarea>
			<div class="mm-flex mm-gap-10 mm-mt-10">
				<?php $test_btn( 'google_search_console' ); ?>
			</div>
		</div>
	</div>

	<!-- Meta -->
	<div class="mm-card">
		<h3>📘 Meta (Facebook / Instagram)</h3>
		<div class="mm-form-row">
			<label class="mm-label">Meta Pixel ID</label>
			<div class="mm-flex mm-gap-10">
				<input type="text" name="mm_meta_pixel_id" class="mm-input" value="<?php echo esc_attr( $settings->get( 'mm_meta_pixel_id' ) ); ?>" placeholder="1234567890" style="flex:1;">
				<?php $test_btn( 'meta_pixel_id' ); ?>
			</div>
		</div>
		<div class="mm-form-row">
			<label class="mm-label">Meta Access Token</label>
			<div class="mm-flex mm-gap-10">
				<input type="password" name="mm_meta_access_token" class="mm-input" value="<?php echo esc_attr( $settings->get( 'mm_meta_access_token' ) ); ?>" placeholder="EAA..." style="flex:1;">
				<?php $test_btn( 'meta' ); ?>
			</div>
			<p class="mm-text-muted" style="font-size:11px;">For full Catalog/Ads API, also install <strong>Facebook for WooCommerce</strong>.</p>
		</div>
	</div>

	<!-- Hotjar / Other -->
	<div class="mm-card">
		<h3>🔶 Hotjar / DataForSEO</h3>
		<div class="mm-form-row">
			<label class="mm-label">Hotjar Site ID</label>
			<div class="mm-flex mm-gap-10">
				<input type="text" name="mm_hotjar_id" class="mm-input" value="<?php echo esc_attr( $settings->get( 'mm_hotjar_id' ) ); ?>" placeholder="1234567" style="flex:1;">
				<?php $test_btn( 'hotjar' ); ?>
			</div>
		</div>
		<div class="mm-form-row">
			<label class="mm-label">DataForSEO Login</label>
			<input type="text" name="dataforseo_login" class="mm-input" value="<?php echo esc_attr( $settings->get( 'dataforseo_login' ) ); ?>" placeholder="email@domain.com">
		</div>
		<div class="mm-form-row">
			<label class="mm-label">DataForSEO Password</label>
			<div class="mm-flex mm-gap-10">
				<input type="password" name="dataforseo_password" class="mm-input" value="<?php echo esc_attr( $settings->get( 'dataforseo_password' ) ); ?>" style="flex:1;">
				<?php $test_btn( 'dataforseo' ); ?>
			</div>
		</div>
	</div>

	<!-- AI Models -->
	<div class="mm-card" style="grid-column: span 2;">
		<h3>🤖 AI Model Assignments</h3>
		<p class="mm-text-muted" style="font-size:12px;">Pick a model for each task. List is fetched live from OpenRouter — click Refresh after entering your key.</p>
		<div class="mm-form-row">
			<button type="button" class="mm-btn mm-btn-outline mm-btn-sm" id="btn_refresh_openrouter_models">🔄 Refresh Model List</button>
			<label style="margin-left:12px;"><input type="checkbox" id="mm_filter_free" <?php checked( $settings->get( 'mm_openrouter_show_free_only' ), 'yes' ); ?>> Show only free models</label>
			<input type="hidden" name="mm_openrouter_show_free_only" id="mm_filter_free_hidden" value="<?php echo esc_attr( $settings->get( 'mm_openrouter_show_free_only' ) ?: 'no' ); ?>">
			<span id="mm_openrouter_status" class="mm-text-muted" style="margin-left:12px; font-size:12px;"></span>
		</div>
		<div class="mm-grid mm-grid-2">
			<?php
			$tasks = array(
				'seo'     => 'SEO Analysis & Suggestions',
				'blog'    => 'Blog & Page Generation',
				'image'   => 'Image Generation',
				'copilot' => 'Copilot Chat',
			);
			foreach ( $tasks as $key => $label ) :
				$current = $settings->get( "mm_openrouter_model_{$key}" );
			?>
			<div class="mm-form-row">
				<label class="mm-label"><?php echo esc_html( $label ); ?></label>
				<select name="mm_openrouter_model_<?php echo esc_attr( $key ); ?>" class="mm-select mm-openrouter-select" data-current="<?php echo esc_attr( $current ); ?>">
					<option value="<?php echo esc_attr( $current ); ?>"><?php echo esc_html( $current ?: '— click Refresh to load models —' ); ?></option>
				</select>
			</div>
			<?php endforeach; ?>
		</div>
	</div>

	<!-- Image generation -->
	<div class="mm-card" style="grid-column: span 2;">
		<h3>🖼️ Image Generation</h3>
		<div class="mm-grid mm-grid-2">
			<div class="mm-form-row">
				<label class="mm-label">Provider</label>
				<select name="mm_image_provider" class="mm-select">
					<option value="openrouter" <?php selected( $settings->get( 'mm_image_provider' ), 'openrouter' ); ?>>OpenRouter (uses your OpenRouter key)</option>
					<option value="openai" <?php selected( $settings->get( 'mm_image_provider' ), 'openai' ); ?>>OpenAI (separate key)</option>
				</select>
			</div>
			<div class="mm-form-row">
				<label class="mm-label">Provider Key Override (optional)</label>
				<input type="password" name="mm_image_provider_key" class="mm-input" value="<?php echo esc_attr( $settings->get( 'mm_image_provider_key' ) ); ?>" placeholder="leave blank to use OpenRouter key above">
			</div>
			<div class="mm-form-row" style="grid-column: span 2;">
				<label class="mm-label">Model (e.g. <code>google/gemini-2.5-flash-image-preview</code> or <code>openai/dall-e-3</code>)</label>
				<input type="text" name="mm_image_model" class="mm-input" value="<?php echo esc_attr( $settings->get( 'mm_image_model' ) ); ?>" placeholder="google/gemini-2.5-flash-image-preview">
			</div>
		</div>
		<div class="mm-form-row">
			<label class="mm-label">Master Image Prompt <span class="mm-text-muted" style="font-weight:normal;">(applied when no per-product prompt is given; <code>{PRODUCT_TITLE}</code> is replaced)</span></label>
			<textarea name="mm_prompt_image_master" class="mm-textarea" rows="4"><?php echo esc_textarea( $settings->get( 'mm_prompt_image_master' ) ); ?></textarea>
		</div>
	</div>

	<!-- Prompt templates -->
	<div class="mm-card" style="grid-column: span 2;">
		<h3>📝 Master Prompt Templates</h3>
		<p class="mm-text-muted" style="font-size:12px;">These prompts shape how the AI thinks for each task. Edit them to match your brand voice and rules.</p>
		<div class="mm-form-row">
			<label class="mm-label">Description Optimizer — Master Prompt</label>
			<textarea name="mm_prompt_description_master" class="mm-textarea" rows="6"><?php echo esc_textarea( $settings->get( 'mm_prompt_description_master' ) ); ?></textarea>
			<p class="mm-text-muted" style="font-size:11px;">Used as the <em>system</em> message when optimizing product descriptions. Same description applies to all variations.</p>
		</div>
		<div class="mm-form-row">
			<label class="mm-label">Blog Writer — Master Prompt</label>
			<textarea name="mm_prompt_blog_master" class="mm-textarea" rows="6"><?php echo esc_textarea( $settings->get( 'mm_prompt_blog_master' ) ); ?></textarea>
		</div>
		<div class="mm-form-row">
			<label class="mm-label">Blog Writer — Default Instructions</label>
			<textarea name="mm_blog_default_instructions" class="mm-textarea" rows="4"><?php echo esc_textarea( $settings->get( 'mm_blog_default_instructions' ) ); ?></textarea>
			<p class="mm-text-muted" style="font-size:11px;">Appended to every blog generation prompt. Cover interlinking rules, keyword density limits, FAQ requirements, brand voice, etc.</p>
		</div>
		<div class="mm-form-row">
			<label class="mm-label">SEO Analyzer — Master Prompt</label>
			<textarea name="mm_prompt_seo_master" class="mm-textarea" rows="4"><?php echo esc_textarea( $settings->get( 'mm_prompt_seo_master' ) ); ?></textarea>
		</div>
	</div>

	<!-- Email reports -->
	<div class="mm-card">
		<h3>📧 Email Reports</h3>
		<div class="mm-form-row">
			<label class="mm-label">Recipients (comma-separated)</label>
			<div class="mm-flex mm-gap-10">
				<input type="text" name="email_recipients" class="mm-input" value="<?php echo esc_attr( $settings->get( 'email_recipients' ) ); ?>" placeholder="admin@example.com" style="flex:1;">
				<button type="button" class="mm-btn mm-btn-outline" id="btn_test_email_recipients">🧪 Test</button>
			</div>
		</div>
		<div class="mm-form-row">
			<label class="mm-label">From Email Override</label>
			<input type="email" name="email_from_override" class="mm-input" value="<?php echo esc_attr( $settings->get( 'email_from_override' ) ); ?>">
		</div>
		<div class="mm-form-row">
			<label class="mm-label">Email Subject Prefix</label>
			<input type="text" name="email_subject_prefix" class="mm-input" value="<?php echo esc_attr( $settings->get( 'email_subject_prefix', 'Meesho Master Report' ) ); ?>" placeholder="Meesho Master Report">
			<p class="mm-text-muted" style="font-size:11px;">Appears before the date in the report subject line.</p>
		</div>
		<div class="mm-form-row">
			<label class="mm-label">Report Frequency</label>
			<select name="email_frequency" class="mm-select">
				<option value="daily" <?php selected( $settings->get( 'email_frequency' ), 'daily' ); ?>>Daily</option>
				<option value="weekly" <?php selected( $settings->get( 'email_frequency' ), 'weekly' ); ?>>Weekly</option>
			</select>
		</div>
		<button type="button" class="mm-btn mm-btn-outline" id="btn_test_email">🧪 Send Test Email</button>
	</div>

	<!-- Copilot -->
	<div class="mm-card">
		<h3>🤖 Copilot</h3>
		<div class="mm-form-row">
			<label><input type="checkbox" name="mm_copilot_enabled" value="yes" <?php checked( $settings->get( 'mm_copilot_enabled' ), 'yes' ); ?>> Enable Copilot</label>
		</div>
		<div class="mm-form-row">
			<label><input type="checkbox" name="copilot_auto_implement" value="yes" <?php checked( $settings->get( 'copilot_auto_implement' ), 'yes' ); ?>> Auto-apply non-destructive suggestions</label>
			<p class="mm-text-muted" style="font-size:11px;">If checked, Copilot will run safe actions immediately without an Apply click. Destructive actions still require confirmation.</p>
		</div>
	</div>

	<!-- Automation / Retention / Cache -->
	<div class="mm-card">
		<h3>🧩 Automation, Retention & Cache</h3>
		<div class="mm-form-row">
			<label class="mm-label">Internal Linking Mode</label>
			<select name="mm_internal_linking_mode" class="mm-select">
				<option value="off" <?php selected( $settings->get( 'mm_internal_linking_mode', 'suggest_only' ), 'off' ); ?>>Off</option>
				<option value="suggest_only" <?php selected( $settings->get( 'mm_internal_linking_mode', 'suggest_only' ), 'suggest_only' ); ?>>Suggest only (manual apply)</option>
				<option value="auto_safe" <?php selected( $settings->get( 'mm_internal_linking_mode', 'suggest_only' ), 'auto_safe' ); ?>>Auto-apply safe links</option>
			</select>
		</div>
		<div class="mm-form-row">
			<label class="mm-label">Import Retry Limit</label>
			<input type="number" min="1" max="10" name="mm_import_retry_limit" class="mm-input" value="<?php echo esc_attr( $settings->get( 'mm_import_retry_limit', '3' ) ); ?>">
		</div>
		<div class="mm-form-row">
			<label class="mm-label">Log Retention (days)</label>
			<input type="number" min="7" max="365" name="mm_log_retention_days" class="mm-input" value="<?php echo esc_attr( $settings->get( 'mm_log_retention_days', '90' ) ); ?>">
		</div>
		<div class="mm-form-row">
			<label><input type="checkbox" name="mm_streaming_enabled" value="yes" <?php checked( $settings->get( 'mm_streaming_enabled', 'yes' ), 'yes' ); ?>> Enable streaming UX features</label>
		</div>
		<div class="mm-form-row">
			<label><input type="checkbox" name="mm_analytics_cache_enabled" value="yes" <?php checked( $settings->get( 'mm_analytics_cache_enabled', 'yes' ), 'yes' ); ?>> Enable analytics cache</label>
		</div>
		<div class="mm-form-row">
			<label class="mm-label">Analytics Cache TTL (hours)</label>
			<input type="number" min="1" max="168" name="mm_analytics_cache_ttl_hours" class="mm-input" value="<?php echo esc_attr( $settings->get( 'mm_analytics_cache_ttl_hours', '4' ) ); ?>">
		</div>
		<div class="mm-form-row">
			<label class="mm-label">OpenRouter Model List Cache TTL (hours)</label>
			<input type="number" min="1" max="168" name="mm_openrouter_models_cache_hours" class="mm-input" value="<?php echo esc_attr( $settings->get( 'mm_openrouter_models_cache_hours', '12' ) ); ?>">
		</div>
	</div>

</div><!-- /.mm-grid -->

<!-- llms.txt — single section -->
<div class="mm-card mm-mt-20">
	<h3>🤖 llms.txt — AI crawler rules</h3>
	<p class="mm-text-muted">
		<code>llms.txt</code> tells AI crawlers (GPTBot, ClaudeBot, PerplexityBot, etc.) what they may access — like <code>robots.txt</code> but for AI training/answer engines.
		Edit the rules below, click <strong>Generate llms.txt</strong> to write the file to <code>&lt;your-site&gt;/llms.txt</code>.
	</p>
	<div class="mm-form-row">
		<label class="mm-label">Rules</label>
		<textarea name="llms_txt_config" class="mm-textarea" rows="8" style="font-family:monospace;"><?php echo esc_textarea( $settings->get( 'llms_txt_config' ) ); ?></textarea>
	</div>
	<div>
		<strong>Current /llms.txt on your site:</strong>
		<pre id="llms_preview" style="max-height:200px; overflow:auto; white-space:pre-wrap; background:#0f172a; color:#e2e8f0; padding:12px; border-radius:8px; margin-top:8px;"><?php echo esc_html( class_exists( 'MM_SEO_Geo' ) ? MM_SEO_Geo::get_llms_txt_content() : '(MM_SEO_Geo class not loaded)' ); ?></pre>
	</div>
	<button type="button" class="mm-btn mm-btn-outline mm-mt-10" id="btn_generate_llms">📄 Generate llms.txt</button>
</div>

<div class="mm-mt-20" style="text-align:right;">
	<span id="settings_save_status" class="mm-text-muted" style="margin-right:12px;"></span>
	<button type="button" class="mm-btn mm-btn-outline" id="btn_repair_db" style="margin-right:8px;">🔧 Repair Database</button>
	<button type="submit" class="mm-btn mm-btn-primary" id="btn_save_settings">💾 Save All Settings</button>
</div>

</form>

<div id="api_test_results" class="mm-mt-20"></div>
