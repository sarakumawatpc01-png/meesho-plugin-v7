<?php

class Meesho_Master_Settings {
private $option_key = 'meesho_master_settings';
private $accounts_key = 'meesho_master_accounts';
private $encrypted_fields = array(
	'openrouter_api_key', 'dataforseo_login', 'dataforseo_password', 'firecrawl_api_key',
	'gsc_client_id', 'gsc_client_secret', 'gsc_refresh_token', 'mm_gsc_credentials',
	'mm_openrouter_key', 'mm_google_analytics_key', 'mm_google_search_console_key',
	'mm_google_ads_developer_token', 'mm_google_pagespeed_key', 'mm_hotjar_id',
	'mm_meta_access_token', 'mm_image_provider_key',
	// F1 — Google Ads OAuth fields
	'mm_google_ads_client_secret', 'mm_google_ads_refresh_token',
	// F3 — GSC service account JSON
	'mm_gsc_service_account_json',
	// E2 — GA4 service account JSON
	'mm_ga4_service_account_json',
);
private $crypto;

public function __construct() {
	$this->crypto = new MM_Crypto();
	add_action( 'wp_ajax_meesho_save_settings', array( $this, 'ajax_save_settings' ) );
	add_action( 'wp_ajax_meesho_save_accounts', array( $this, 'ajax_save_accounts' ) );
	add_action( 'wp_ajax_meesho_test_email', array( $this, 'ajax_test_email' ) );
	// v6.3 — API test buttons
	add_action( 'wp_ajax_mm_test_api', array( $this, 'ajax_test_api' ) );
	// v6.3 — diagnostics for SEO tab
	add_action( 'wp_ajax_mm_settings_diagnostics', array( $this, 'ajax_diagnostics' ) );
	// v6.3 — image generation
	add_action( 'wp_ajax_mm_generate_image', array( $this, 'ajax_generate_image' ) );
	// v6.4 — DB repair
	add_action( 'wp_ajax_mm_repair_database', array( $this, 'ajax_repair_database' ) );
}

private function should_encrypt( $key ) {
return in_array( $key, $this->encrypted_fields, true ) || (bool) preg_match( '/(key|secret|credentials)$/i', $key );
}

public function encrypt( $plain ) { return $this->crypto->encrypt( $plain ); }
public function decrypt( $encoded ) { return $this->crypto->decrypt( $encoded ); }

public function get_all() {
$settings = get_option( $this->option_key, array() );
$settings = wp_parse_args( $settings, $this->defaults() );
foreach ( $settings as $key => $value ) {
if ( $this->should_encrypt( $key ) ) {
$settings[ $key ] = $this->decrypt( $value );
}
}
return $settings;
}

public function get( $key, $default = '' ) {
$all = $this->get_all();
return isset( $all[ $key ] ) ? $all[ $key ] : $default;
}

public function set( $key, $value ) {
$all = get_option( $this->option_key, array() );
$all[ $key ] = $this->should_encrypt( $key ) ? $this->encrypt( $value ) : $value;
update_option( $this->option_key, $all );
}

public function save_bulk( $data ) {
	$all = get_option( $this->option_key, array() );
	// Fields that need textarea-safe sanitization (preserves newlines)
	$textarea_keys = array(
		'mm_gsc_credentials',
		'mm_prompt_description_master', 'mm_prompt_image_master', 'mm_prompt_blog_master',
		'mm_prompt_seo_master', 'mm_blog_default_instructions',
		'llms_txt_config',
	);
	foreach ( $data as $key => $value ) {
		if ( in_array( $key, $textarea_keys, true ) ) {
			$clean = sanitize_textarea_field( wp_unslash( $value ) );
		} else {
			$clean = is_array( $value ) ? wp_json_encode( $value ) : sanitize_text_field( wp_unslash( $value ) );
		}
		$all[ $key ] = $this->should_encrypt( $key ) ? $this->encrypt( $clean ) : $clean;
	}
	update_option( $this->option_key, wp_parse_args( $all, $this->defaults() ) );
}

public function defaults() {
return array(
'pricing_markup_type'    => 'percentage',
'pricing_markup_value'   => '20',
'pricing_rounding'       => 'none',
'scrapling_url'          => '',
'scrapling_timeout'      => '30',
'openrouter_api_key'     => '',
'mm_openrouter_key'      => '',
'ai_model_seo'           => '',
'ai_model_blog'          => '',
'ai_model_image'         => '',
'ai_model_copilot'       => '',
'ai_model_schema'        => '',
'ai_model_aeo'           => '',
'ai_model_geo'           => '',
'mm_openrouter_model_seo'     => '',
'mm_openrouter_model_blog'    => '',
'mm_openrouter_model_image'   => '',
'mm_openrouter_model_copilot' => '',
'ai_show_free_only'      => 'no',
'mm_openrouter_show_free_only' => 'no',
'cod_risk_threshold'     => '2000',
'cod_repeat_window_hrs'  => '24',
'copilot_auto_implement' => 'no',
'mm_copilot_enabled'     => 'yes',
'automation_enabled'     => 'yes',
'automation_time_1'      => '08:00',
'automation_time_2'      => '20:00',
'automation_batch_size'  => '5',
'automation_delay_ms'    => '500',
'mm_seo_max_suggestions' => '10',
'email_recipients'       => '',
'email_from_override'    => '',
'email_frequency'        => 'daily',
'email_pdf_library'      => 'dompdf',
'hotjar_site_id'         => '',
'mm_hotjar_id'           => '',
// Google ecosystem - separate keys
'mm_google_analytics_id'    => '',
'mm_google_analytics_key'   => '',
'mm_google_search_console_key' => '',
'mm_google_ads_customer_id' => '',
'mm_google_ads_developer_token' => '',
'mm_google_pagespeed_key'   => '',
'mm_google_tag_manager_id'  => '',
// F1 — Google Ads OAuth (full API access)
'mm_google_ads_mcc_id'          => '',
'mm_google_ads_client_id'       => '',
'mm_google_ads_client_secret'   => '',
'mm_google_ads_refresh_token'   => '',
// F3 — GSC unified auth
'mm_gsc_mode'                   => 'site_kit',
'mm_gsc_service_account_json'   => '',
// Meta
'mm_meta_pixel_id'        => '',
'mm_meta_access_token'    => '',
// Image generation
'mm_image_provider'       => 'openrouter',
'mm_image_provider_key'   => '',
'mm_image_model'          => '',
// Prompt templates
'mm_prompt_description_master' => "You are an expert ecommerce copywriter for an Indian fashion brand. Output ONLY clean HTML with these rules:\n- Open with a 1-line hook\n- Add 4-6 bullet points covering fabric, fit, occasion, care\n- Include a closing line that addresses the buyer's intent\n- Avoid emojis unless the user asks\n- Use Indian English\n- Length: 80-150 words\n- The same description applies to ALL size/color variations — describe size info inside the description (e.g. 'Available in S, M, L, XL').",
'mm_prompt_image_master' => "Photorealistic product shot of {PRODUCT_TITLE}. Studio lighting, neutral grey background, full product visible, no text overlays, no watermarks, no people unless implied by product type. High detail fabric texture. 1024x1024.",
'mm_prompt_blog_master' => "You are a blog writer for an Indian fashion ecommerce store. Write in Indian English. Default rules:\n- 800-1200 words\n- 1 H1, 3-5 H2s, descriptive H3s as needed\n- Keyword density: 1.0-1.5% for primary keyword (no more)\n- Include 2-3 internal links to existing site pages where relevant\n- Add a FAQ section at the end with 3-5 Q&A\n- No fluff, no AI-tells like 'In conclusion', 'In today's fast-paced world'\n- Use real, specific Indian context (festivals, occasions, climate)",
'mm_prompt_seo_master' => "You analyze product/post content for SEO. Output JSON only with these keys: meta_title (under 60 chars), meta_description (under 160 chars), focus_keyword, secondary_keywords (array), schema_suggestions (array), aeo_qa (array of {q,a}). Be specific to the content; don't be generic.",
'mm_blog_default_instructions' => "Internal linking: link to /products, /blog, and one category page where it fits naturally. Keyword stuffing: max 1.5% density. Always end with FAQ. Match brand voice: warm, trustworthy, India-first.",
// GSC OAuth (legacy single block)
'gsc_client_id'          => '',
'gsc_client_secret'      => '',
'gsc_refresh_token'      => '',
'mm_gsc_credentials'     => '',
'dataforseo_login'       => '',
'dataforseo_password'    => '',
'firecrawl_api_key'      => '',
'llms_txt_config'        => "User-agent: GPTBot\nAllow: /\n\nUser-agent: ClaudeBot\nAllow: /\n",
);
}

public function get_accounts() {
$enc = get_option( $this->accounts_key, '' );
if ( '' === $enc ) {
return array();
}
$accounts = json_decode( $this->decrypt( $enc ), true );
return is_array( $accounts ) ? $accounts : array();
}

public function save_accounts( $accounts ) {
update_option( $this->accounts_key, $this->encrypt( wp_json_encode( array_slice( $accounts, 0, 4 ) ) ) );
}

public function calculate_selling_price( $meesho_price, $override_type = null, $override_value = null ) {
$type  = $override_type ? $override_type : ( $this->get( 'mm_markup_type' ) ?: $this->get( 'pricing_markup_type' ) ?: 'percentage' );
$value = $override_value ? $override_value : ( $this->get( 'mm_markup_value' ) ?: $this->get( 'pricing_markup_value' ) ?: '20' );
$price = 'flat' === $type ? (float) $meesho_price + (float) $value : (float) $meesho_price * ( 1 + ( (float) $value / 100 ) );
return $this->apply_rounding( $price );
}

public function apply_rounding( $price ) {
switch ( $this->get( 'pricing_rounding' ) ) {
case 'nearest_10': return ceil( $price / 10 ) * 10;
case 'nearest_50': return ceil( $price / 50 ) * 50;
case 'nearest_99': return floor( $price / 100 ) * 100 + 99;
case 'none': return round( $price, 2 );
// Legacy numeric values kept for backward compat
case '1': return ceil( $price );
case '5': return ceil( $price / 5 ) * 5;
case '9': return floor( $price / 10 ) * 10 + 9;
case '10': return ceil( $price / 10 ) * 10;
default: return round( $price, 2 );
}
}

public function ajax_save_settings() {
meesho_master_verify_ajax_nonce();
if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 ); }
$fields = $_POST;
unset( $fields['action'], $fields['nonce'] );
$this->save_bulk( $fields );
wp_send_json_success( 'Settings saved.' );
}

public function ajax_save_accounts() {
meesho_master_verify_ajax_nonce();
if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 ); }
$accounts = json_decode( wp_unslash( $_POST['accounts'] ?? '[]' ), true );
if ( ! is_array( $accounts ) ) { wp_send_json_error( array( 'message' => 'Invalid accounts data' ), 400 ); }
$clean = array();
foreach ( $accounts as $acc ) {
$clean[] = array(
'label' => sanitize_text_field( $acc['label'] ?? '' ),
'email' => sanitize_email( $acc['email'] ?? '' ),
'phone' => sanitize_text_field( $acc['phone'] ?? '' ),
'notes' => sanitize_textarea_field( $acc['notes'] ?? '' ),
);
}
$this->save_accounts( $clean );
wp_send_json_success( 'Accounts saved.' );
}

public function ajax_test_email() {
meesho_master_verify_ajax_nonce();
if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 ); }
$to = $this->get( 'email_recipients' );
if ( '' === $to ) { wp_send_json_error( array( 'message' => 'No email recipients configured' ), 400 ); }
$from = $this->get( 'email_from_override' );
if ( '' === $from ) { $from = get_option( 'admin_email' ); }
add_filter( 'wp_mail_from', static function () use ( $from ) { return $from; } );
$sent = wp_mail( $to, 'Meesho Master — Test Email (' . wp_date( 'd/m/Y' ) . ')', 'This is a test email from Meesho Master.', array( 'Content-Type: text/html; charset=UTF-8' ) );
$sent ? wp_send_json_success( 'Test email sent successfully.' ) : wp_send_json_error( array( 'message' => 'Failed to send test email.' ), 400 );
}

	/* ====================================================================
	 *  v6.3 — API Test Endpoints
	 * ==================================================================== */

	public function ajax_test_api() {
		meesho_master_verify_ajax_nonce();
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
		}
		$service = sanitize_key( $_POST['service'] ?? '' );
		$key     = sanitize_text_field( wp_unslash( $_POST['key'] ?? '' ) );
		$extra   = sanitize_text_field( wp_unslash( $_POST['extra'] ?? '' ) );

		if ( empty( $key ) ) {
			$key = $this->get( $this->get_key_field_for_service( $service ) );
		}
		if ( empty( $key ) ) {
			wp_send_json_error( array( 'message' => 'No key provided or saved for service ' . $service ) );
		}

		switch ( $service ) {
			case 'openrouter':
				$res = wp_remote_get( 'https://openrouter.ai/api/v1/auth/key', array(
					'timeout' => 15,
					'headers' => array( 'Authorization' => 'Bearer ' . $key ),
				) );
				if ( is_wp_error( $res ) ) {
					wp_send_json_error( array( 'message' => $res->get_error_message() ) );
				}
				$code = wp_remote_retrieve_response_code( $res );
				$body = json_decode( wp_remote_retrieve_body( $res ), true );
				if ( 200 === (int) $code && ! empty( $body['data'] ) ) {
					$d = $body['data'];
					wp_send_json_success( array(
						'ok'       => true,
						'message'  => '✅ OpenRouter key valid.',
						'details'  => array(
							'label'         => $d['label'] ?? '',
							'usage'         => $d['usage'] ?? 0,
							'limit'         => $d['limit'] ?? 'unlimited',
							'is_free_tier'  => $d['is_free_tier'] ?? false,
						),
					) );
				}
				wp_send_json_error( array( 'message' => '❌ Invalid key. HTTP ' . $code ) );
				return;

			case 'google_pagespeed':
				$res = wp_remote_get( 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed?url=' . rawurlencode( home_url() ) . '&key=' . rawurlencode( $key ), array( 'timeout' => 30 ) );
				if ( is_wp_error( $res ) ) {
					wp_send_json_error( array( 'message' => $res->get_error_message() ) );
				}
				$code = wp_remote_retrieve_response_code( $res );
				if ( 200 === (int) $code ) {
					wp_send_json_success( array( 'ok' => true, 'message' => '✅ PageSpeed Insights API key works.' ) );
				}
				$err = json_decode( wp_remote_retrieve_body( $res ), true );
				wp_send_json_error( array( 'message' => '❌ ' . ( $err['error']['message'] ?? 'HTTP ' . $code ) ) );
				return;

			case 'google_analytics':
				// Without OAuth we can only verify the API key syntactically + by ping
				if ( ! preg_match( '/^[A-Za-z0-9_-]{30,50}$/', $key ) ) {
					wp_send_json_error( array( 'message' => '❌ Key does not look like a valid Google API key.' ) );
				}
				wp_send_json_success( array(
					'ok'       => true,
					'message'  => 'ℹ️ Format looks valid. Full GA4 verification requires OAuth — connect Google Site Kit for that.',
					'details'  => array( 'note' => 'GA4 Data API needs OAuth, not just a key. Use Site Kit for proper integration.' ),
				) );
				return;

			case 'google_search_console':
				wp_send_json_success( array(
					'ok'      => true,
					'message' => 'ℹ️ GSC requires OAuth, not API keys. Paste full credentials JSON in the Search Console section instead.',
				) );
				return;

			case 'google_ads':
				if ( strlen( $key ) < 22 ) {
					wp_send_json_error( array( 'message' => '❌ Developer token looks too short.' ) );
				}
				wp_send_json_success( array(
					'ok'      => true,
					'message' => 'ℹ️ Developer token saved. Google Ads API also needs OAuth + customer ID. Use Google Listings & Ads plugin for full setup.',
				) );
				return;

			// F1 — Full OAuth-backed Google Ads test
			case 'google_ads_full':
				$refresh_token  = $this->get( 'mm_google_ads_refresh_token' );
				$client_id      = $this->get( 'mm_google_ads_client_id' );
				$client_secret  = $this->get( 'mm_google_ads_client_secret' );
				$developer_token = $this->get( 'mm_google_ads_developer_token' );
				$customer_id    = preg_replace( '/[^0-9]/', '', $this->get( 'mm_google_ads_customer_id' ) );

				if ( empty( $refresh_token ) || empty( $client_id ) || empty( $client_secret ) ) {
					wp_send_json_error( array( 'message' => '❌ Missing OAuth fields. Fill in Client ID, Client Secret, and Refresh Token.' ) );
				}
				if ( empty( $developer_token ) ) {
					wp_send_json_error( array( 'message' => '❌ Developer Token is required for the Google Ads API.' ) );
				}
				// Exchange refresh token for access token
				$token_res = wp_remote_post( 'https://oauth2.googleapis.com/token', array(
					'timeout' => 15,
					'body'    => array(
						'client_id'     => $client_id,
						'client_secret' => $client_secret,
						'refresh_token' => $refresh_token,
						'grant_type'    => 'refresh_token',
					),
				) );
				if ( is_wp_error( $token_res ) ) {
					wp_send_json_error( array( 'message' => '❌ Token exchange failed: ' . $token_res->get_error_message() ) );
				}
				$token_data   = json_decode( wp_remote_retrieve_body( $token_res ), true );
				$access_token = $token_data['access_token'] ?? '';
				if ( empty( $access_token ) ) {
					$err = $token_data['error_description'] ?? $token_data['error'] ?? 'Unknown error';
					wp_send_json_error( array( 'message' => '❌ Could not obtain access token: ' . $err . '. Regenerate your refresh token.' ) );
				}
				if ( empty( $customer_id ) ) {
					wp_send_json_success( array( 'ok' => true, 'message' => '✅ OAuth token valid. Add a Customer ID to test the Ads API endpoint.' ) );
				}
				// Call Google Ads API v17 CustomerService
				$ads_res = wp_remote_get(
					'https://googleads.googleapis.com/v17/customers/' . $customer_id,
					array(
						'timeout' => 15,
						'headers' => array(
							'Authorization'   => 'Bearer ' . $access_token,
							'developer-token' => $developer_token,
						),
					)
				);
				if ( is_wp_error( $ads_res ) ) {
					wp_send_json_error( array( 'message' => '❌ Ads API request failed: ' . $ads_res->get_error_message() ) );
				}
				$ads_code = wp_remote_retrieve_response_code( $ads_res );
				$ads_body = json_decode( wp_remote_retrieve_body( $ads_res ), true );
				if ( 200 === (int) $ads_code ) {
					$name = $ads_body['descriptiveName'] ?? $ads_body['id'] ?? $customer_id;
					wp_send_json_success( array( 'ok' => true, 'message' => '✅ Google Ads API connected. Account: ' . esc_html( $name ) ) );
				}
				$err_detail = $ads_body['error']['message'] ?? $ads_body['error']['status'] ?? 'HTTP ' . $ads_code;
				if ( 403 === (int) $ads_code ) {
					$err_detail .= ' — Your developer token may need Standard Access. Apply at https://developers.google.com/google-ads/api/docs/access-levels';
				} elseif ( 401 === (int) $ads_code ) {
					$err_detail .= ' — Check OAuth Client ID/Secret and regenerate the refresh token.';
				}
				wp_send_json_error( array( 'message' => '❌ ' . $err_detail ) );
				return;

			// F2 — Format-check tests (no live API possible without OAuth)
			case 'google_analytics_id':
				$id = $key ?: $this->get( 'mm_google_analytics_id' );
				if ( preg_match( '/^G-[A-Z0-9]{8,12}$/', $id ) ) {
					wp_send_json_success( array( 'ok' => true, 'message' => '✅ GA4 Measurement ID format valid. To verify data is flowing, open Google Analytics → Realtime and visit your site.' ) );
				}
				if ( preg_match( '/^UA-\d+-\d+$/', $id ) ) {
					wp_send_json_success( array( 'ok' => true, 'message' => '✅ Universal Analytics ID format valid. Note: UA properties stopped collecting data Jul 2023.' ) );
				}
				wp_send_json_error( array( 'message' => '❌ ID format invalid. Expected G-XXXXXXXXXX (GA4) or UA-XXXXXXXX-X (UA).' ) );
				return;

			case 'google_tag_manager_id':
				$id = $key ?: $this->get( 'mm_google_tag_manager_id' );
				if ( preg_match( '/^GTM-[A-Z0-9]{6,8}$/', $id ) ) {
					wp_send_json_success( array( 'ok' => true, 'message' => '✅ GTM Container ID format valid.' ) );
				}
				wp_send_json_error( array( 'message' => '❌ GTM ID format invalid. Expected GTM-XXXXXXX.' ) );
				return;

			case 'meta_pixel_id':
				$id = $key ?: $this->get( 'mm_meta_pixel_id' );
				if ( preg_match( '/^\d{12,16}$/', $id ) ) {
					wp_send_json_success( array( 'ok' => true, 'message' => '✅ Meta Pixel ID format valid (' . strlen( $id ) . ' digits).' ) );
				}
				wp_send_json_error( array( 'message' => '❌ Pixel ID format invalid. Expected 12–16 digits. Find it in Meta Events Manager.' ) );
				return;

			case 'google_ads_customer_id':
				$raw = $key ?: $this->get( 'mm_google_ads_customer_id' );
				$digits = preg_replace( '/[^0-9]/', '', $raw );
				if ( strlen( $digits ) !== 10 ) {
					wp_send_json_error( array( 'message' => '❌ Customer ID should be 10 digits (e.g. 123-456-7890). Found ' . strlen( $digits ) . ' digits.' ) );
				}
				// If full OAuth is set up, attempt a live test
				if ( $this->get( 'mm_google_ads_developer_token' ) && $this->get( 'mm_google_ads_refresh_token' ) ) {
					// Reuse the google_ads_full logic by resetting service key and recursing via direct call
					$_POST['service'] = 'google_ads_full';
					$this->ajax_test_api();
					return;
				}
				wp_send_json_success( array( 'ok' => true, 'message' => '✅ Customer ID format valid. Add Developer Token + OAuth fields for a live API test.' ) );
				return;

			case 'meta':
				$res = wp_remote_get( 'https://graph.facebook.com/v18.0/me?access_token=' . rawurlencode( $key ), array( 'timeout' => 15 ) );
				$code = wp_remote_retrieve_response_code( $res );
				$body = json_decode( wp_remote_retrieve_body( $res ), true );
				if ( 200 === (int) $code && ! empty( $body['id'] ) ) {
					wp_send_json_success( array( 'ok' => true, 'message' => '✅ Meta token valid for ID ' . $body['id'] ) );
				}
				wp_send_json_error( array( 'message' => '❌ ' . ( $body['error']['message'] ?? 'HTTP ' . $code ) ) );
				return;

			case 'hotjar':
				if ( ! preg_match( '/^[0-9]{6,10}$/', $key ) ) {
					wp_send_json_error( array( 'message' => '❌ Hotjar Site ID should be all digits, 6–10 chars.' ) );
				}
				wp_send_json_success( array(
					'ok'      => true,
					'message' => '✅ Hotjar Site ID format valid. Hotjar uses no API key — just embeds the script.',
				) );
				return;

			case 'dataforseo':
				$auth = base64_encode( $key . ':' . $extra ); // login:password
				$res = wp_remote_get( 'https://api.dataforseo.com/v3/appendix/user_data', array(
					'timeout' => 15,
					'headers' => array( 'Authorization' => 'Basic ' . $auth ),
				) );
				$code = wp_remote_retrieve_response_code( $res );
				if ( 200 === (int) $code ) {
					wp_send_json_success( array( 'ok' => true, 'message' => '✅ DataForSEO credentials valid.' ) );
				}
				wp_send_json_error( array( 'message' => '❌ DataForSEO returned HTTP ' . $code ) );
				return;

			default:
				wp_send_json_error( array( 'message' => 'Unknown service: ' . $service ) );
		}
	}

	private function get_key_field_for_service( $service ) {
		$map = array(
			'openrouter'             => 'mm_openrouter_key',
			'google_analytics'       => 'mm_google_analytics_key',
			'google_analytics_id'    => 'mm_google_analytics_id',
			'google_tag_manager_id'  => 'mm_google_tag_manager_id',
			'google_search_console'  => 'mm_google_search_console_key',
			'google_ads'             => 'mm_google_ads_developer_token',
			'google_ads_full'        => 'mm_google_ads_developer_token',
			'google_ads_customer_id' => 'mm_google_ads_customer_id',
			'google_pagespeed'       => 'mm_google_pagespeed_key',
			'meta'                   => 'mm_meta_access_token',
			'meta_pixel_id'          => 'mm_meta_pixel_id',
			'hotjar'                 => 'mm_hotjar_id',
			'dataforseo'             => 'dataforseo_login',
		);
		return $map[ $service ] ?? '';
	}

	/* ====================================================================
	 *  v6.3 — Diagnostics (used by SEO tab when something is missing)
	 * ==================================================================== */

	public function ajax_diagnostics() {
		meesho_master_verify_ajax_nonce();
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
		}
		$issues = array();

		if ( empty( $this->get( 'mm_openrouter_key' ) ) && empty( $this->get( 'openrouter_api_key' ) ) ) {
			$issues[] = array(
				'severity' => 'error',
				'area'     => 'AI / Copilot',
				'message'  => 'OpenRouter API key is missing.',
				'solution' => 'Settings → AI / API Keys → enter your OpenRouter key (get one at openrouter.ai). Without this, description optimizer, blog generation, and Copilot will not work.',
			);
		}
		if ( empty( $this->get( 'mm_openrouter_model_seo' ) ) ) {
			$issues[] = array(
				'severity' => 'warning',
				'area'     => 'SEO',
				'message'  => 'No AI model selected for SEO analysis.',
				'solution' => 'Settings → AI Models → click "Refresh Model List", then pick a model for "SEO Analysis & Suggestions".',
			);
		}
		if ( empty( $this->get( 'mm_google_search_console_key' ) ) && empty( $this->get( 'mm_gsc_credentials' ) ) ) {
			$issues[] = array(
				'severity' => 'info',
				'area'     => 'Rankings',
				'message'  => 'Google Search Console not connected.',
				'solution' => 'Settings → Google APIs → paste GSC credentials JSON (or install Google Site Kit and authorize). Without this, the Rankings table stays empty.',
			);
		}
		if ( empty( $this->get( 'mm_google_pagespeed_key' ) ) ) {
			$issues[] = array(
				'severity' => 'info',
				'area'     => 'Performance',
				'message'  => 'PageSpeed Insights API key not set.',
				'solution' => 'Settings → Google APIs → enter PageSpeed Insights key. This enables Core Web Vitals scoring in the SEO tab.',
			);
		}
		if ( ! class_exists( 'WooCommerce' ) ) {
			$issues[] = array(
				'severity' => 'error',
				'area'     => 'WooCommerce',
				'message'  => 'WooCommerce is not active.',
				'solution' => 'Install + activate WooCommerce. Without it, products cannot be pushed and Copilot integrations are limited.',
			);
		}
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			$issues[] = array(
				'severity' => 'warning',
				'area'     => 'Cron',
				'message'  => 'WordPress cron is disabled.',
				'solution' => 'Either remove DISABLE_WP_CRON from wp-config.php, or set up a real system cron (recommended for production). Scheduled SEO scans depend on this.',
			);
		}

		wp_send_json_success( array( 'issues' => $issues, 'count' => count( $issues ) ) );
	}

	/* ====================================================================
	 *  v6.3 — Image generation (OpenRouter / OpenAI compatible)
	 * ==================================================================== */

	public function ajax_generate_image() {
		meesho_master_verify_ajax_nonce();
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
		}
		$prompt = sanitize_textarea_field( wp_unslash( $_POST['prompt'] ?? '' ) );
		$title  = sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) );

		if ( empty( $prompt ) ) {
			$master = $this->get( 'mm_prompt_image_master' );
			$prompt = str_replace( '{PRODUCT_TITLE}', $title, $master );
		}
		if ( empty( $prompt ) ) {
			wp_send_json_error( array( 'message' => 'No prompt provided and no master prompt set in Settings.' ) );
		}

		$provider = $this->get( 'mm_image_provider' ) ?: 'openrouter';
		$key      = $this->get( 'mm_image_provider_key' );
		if ( empty( $key ) ) {
			$key = $this->get( 'mm_openrouter_key' );
		}
		if ( empty( $key ) ) {
			wp_send_json_error( array( 'message' => 'No image generation API key configured. Set mm_image_provider_key or mm_openrouter_key in Settings.' ) );
		}

		// OpenRouter image endpoint (uses OpenAI-compatible /images/generations)
		$model = $this->get( 'mm_image_model' );
		if ( empty( $model ) ) {
			$model = 'google/gemini-2.5-flash-image-preview';
		}
		$res = wp_remote_post( 'https://openrouter.ai/api/v1/chat/completions', array(
			'timeout' => 90,
			'headers' => array(
				'Authorization' => 'Bearer ' . $key,
				'Content-Type'  => 'application/json',
				'HTTP-Referer'  => home_url(),
				'X-Title'       => 'Meesho Master',
			),
			'body' => wp_json_encode( array(
				'model'    => $model,
				'modalities' => array( 'image', 'text' ),
				'messages' => array(
					array( 'role' => 'user', 'content' => $prompt ),
				),
			) ),
		) );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		// Pull the first image from the response (OpenRouter returns image URLs in message.images[])
		$image_url = '';
		if ( ! empty( $body['choices'][0]['message']['images'] ) ) {
			$first = $body['choices'][0]['message']['images'][0];
			$image_url = $first['image_url']['url'] ?? ( is_string( $first ) ? $first : '' );
		}
		if ( empty( $image_url ) ) {
			wp_send_json_error( array( 'message' => 'Image provider returned no image. Raw response: ' . wp_remote_retrieve_body( $res ) ) );
		}
		wp_send_json_success( array(
			'image_url' => $image_url,
			'prompt'    => $prompt,
			'model'     => $model,
		) );
	}

	/**
	 * v6.4 — Repair Database button. Force-reinstalls all plugin tables.
	 * Used when a user sees "Table doesn't exist" errors after upgrading.
	 */
	public function ajax_repair_database() {
		meesho_master_verify_ajax_nonce();
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
		}
		$diag = MM_DB::force_reinstall();
		$missing = array();
		foreach ( $diag as $key => $exists ) {
			if ( ! $exists ) {
				$missing[] = $key;
			}
		}
		if ( empty( $missing ) ) {
			wp_send_json_success( array(
				'message' => '✅ All ' . count( $diag ) . ' tables exist. Database is healthy.',
				'tables'  => $diag,
			) );
		}
		wp_send_json_error( array(
			'message' => '⚠️ Reinstall ran but ' . count( $missing ) . ' table(s) still missing: ' . implode( ', ', $missing ) . '. Check that your database user has CREATE TABLE permission.',
			'tables'  => $diag,
		) );
	}
}
