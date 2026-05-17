# Phase 1 — Architecture & Inventory Report

## Overview
Meesho Master is a WordPress/WooCommerce plugin with a single admin menu (“Meesho Master”) and multiple admin tabs (import/products/blogs/orders/seo/analytics/copilot/logs/settings). Public‑facing output is limited to a WooCommerce product reviews tab and a shortcode for rendering Meesho reviews.

## File layout
- `meesho-master.php`: plugin bootstrap, constants, shared AJAX nonce helper, activation/deactivation hooks.
- `includes/`: core services (DB, crypto, logger, scheduler, SEO engine, integrations, scraper).
- `includes/modules/`: feature modules (import/blogs/orders/seo/analytics/settings/copilot/undo).
- `admin/`: admin menu + tabbed UI partials + admin assets.
- `public/`: Meesho reviews CSS.

## Admin vs public separation
**Admin**
- Menu + tabs are registered in `admin/class-meesho-admin.php`.
- Module classes register all admin AJAX endpoints and dashboard behaviors.
- Admin assets: `admin/css/meesho-admin.css`, `admin/js/meesho-admin.js`.

**Public**
- WooCommerce product tab: `Meesho_Master::add_meesho_reviews_tab()`.
- Shortcode `[meesho_reviews]` for rendering review breakdown and recent reviews.
- Public asset: `public/css/meesho-reviews.css`.

## Hook inventory
### Admin hooks
- `admin_menu`, `admin_enqueue_scripts` for UI + assets.
- `admin_notices` for WP‑Cron health and SEO stale run notices.

### Public hooks
- `woocommerce_product_tabs` filter for Meesho reviews.
- `wp_enqueue_scripts` for frontend reviews CSS.
- `add_shortcode` for `[meesho_reviews]`.

### AJAX endpoints (admin‑only)
Import: `meesho_import_url`, `meesho_import_html`, `meesho_manual_sku`, `mm_list_staged`, `mm_get_staged`, `mm_save_staged`, `mm_push_to_wc`, `mm_delete_staged`, `mm_check_duplicate`, `mm_optimize_description`, `mm_ai_generate_title`, `mm_openrouter_models`.
Blogs: `mm_blog_generate`, `mm_blog_save`, `mm_blog_list_drafts`, `mm_blog_delete_draft`.
Orders: `meesho_get_orders`, `meesho_update_order`, `meesho_check_cod_risk`, `meesho_get_accounts`.
SEO: `meesho_run_seo_crawl`, `mm_run_seo_scan`, `meesho_get_seo_scores`, `meesho_get_suggestions`, `meesho_apply_suggestion`, `meesho_apply_all_safe`, `meesho_reject_suggestion`, `meesho_generate_llms_txt`, `mm_research_keywords`, `mm_list_targetable_posts`, `mm_seo_list_scores`, `mm_seo_score_trends`.
Analytics: `meesho_get_rankings`, `meesho_add_keyword`, `meesho_send_report`, `meesho_get_heatmap_insights`, `mm_fetch_ga4_data`.
Copilot: `mm_copilot_chat`, `mm_copilot_apply`, `mm_copilot_history`, `mm_copilot_undo_last`, `mm_copilot_list_undo_history`, `mm_copilot_upload_file`.
Settings/Logs: `meesho_save_settings`, `meesho_save_accounts`, `meesho_test_email`, `mm_test_api`, `mm_settings_diagnostics`, `mm_generate_image`, `mm_repair_database`, `mm_undo_action`, `mm_get_logs`.

### Cron/scheduled tasks
- SEO scans: `mm_seo_run_morning`, `mm_seo_run_evening`.
- Logs cleanup: `mm_purge_old_logs`.
- Reports: `mm_send_daily_report`, `mm_send_weekly_report`.

## Asset inventory
- Admin CSS: `admin/css/meesho-admin.css`
- Admin JS: `admin/js/meesho-admin.js`
- Public CSS: `public/css/meesho-reviews.css`
- Inline CSS in some admin tab partials (import recent cards, notices).

## Database inventory
Primary tables (MM_DB):
- `mm_seo_suggestions`, `mm_seo_post_scores`, `mm_seo_score_history`
- `mm_audit_log`, `mm_seo_runs`, `mm_copilot_threads`
- `mm_ranking_data`, `mm_products`, `mm_reviews`, `mm_orders`, `mm_customers`

Legacy tables are mapped for migration (`meesho_*`).

## External APIs & integrations
- OpenRouter: blog generation, SEO analysis, copilot chat, description/title tooling.
- Scrapling (optional): product scraping fallback.
- Google Search Console: rankings + keyword research.
- GA4 Data API: analytics widget.
- Hotjar: heatmap embed + AI analysis.
- DataForSEO: present as stub (not implemented).
- Google Ads / Meta / PageSpeed: settings scaffolding, partial integration.

## Architecture notes
- Admin flows are AJAX‑driven, with data stored in custom DB tables.
- Public functionality is intentionally minimal (reviews only) to reduce frontend footprint.
