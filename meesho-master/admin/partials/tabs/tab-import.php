<?php
/**
 * Import tab — v6.5
 * Scrapes products into the staging table. Live preview of recently
 * staged products with full action buttons.
 */
?>
<div class="mm-card">
	<h3>📥 Import Products</h3>
	<p class="mm-text-muted">
		Paste a product URL or HTML source. Items are <strong>staged</strong> first so you can review,
		edit, and optimize before pushing to WooCommerce. Manage staged items in the
		<a href="?page=meesho-master&tab=products"><strong>Products tab</strong></a>.
	</p>
</div>

<div class="mm-grid mm-grid-2">
	<div class="mm-card">
		<h3>🔗 Method 1: Import by URL <span class="mm-text-muted" style="font-size:13px;">(no account needed)</span></h3>
		<p class="mm-text-muted">Built-in scraper, no external service required.</p>
		<div class="mm-form-row">
			<label class="mm-label">Product URL</label>
			<input type="text" id="meesho_url" class="mm-input" placeholder="https://www.meesho.com/.../p/...">
		</div>
		<button class="mm-btn mm-btn-primary" id="btn_import_url">🚀 Scrape & Stage</button>
	</div>

	<div class="mm-card">
		<h3>📋 Method 2: Paste HTML (Fallback)</h3>
		<p class="mm-text-muted">If the URL scraper fails (rare). Open the page in your browser, view source, paste here.</p>
		<div class="mm-form-row">
			<label class="mm-label">Source URL (optional, helps with SKU)</label>
			<input type="text" id="meesho_url_for_html" class="mm-input" placeholder="https://...">
		</div>
		<div class="mm-form-row">
			<label class="mm-label">HTML Source</label>
			<textarea id="meesho_html" class="mm-textarea" rows="5" placeholder="<html>...</html>"></textarea>
		</div>
		<button class="mm-btn mm-btn-outline" id="btn_import_html">📋 Parse HTML & Stage</button>
	</div>
</div>

<div class="mm-card">
	<h3>🧵 Import Queue (Batch URLs)</h3>
	<p class="mm-text-muted">Queue multiple Meesho product URLs, then process them one-by-one with retry tracking and failure logging.</p>
	<div class="mm-form-row">
		<label class="mm-label">Queue URLs (one per line)</label>
		<textarea id="mm_import_queue_urls" class="mm-textarea" rows="4" placeholder="https://www.meesho.com/.../p/123456&#10;https://www.meesho.com/.../p/234567"></textarea>
	</div>
	<div class="mm-flex mm-gap-10">
		<button class="mm-btn mm-btn-outline" id="mm_import_queue_add_btn">➕ Add to Queue</button>
		<button class="mm-btn mm-btn-primary" id="mm_import_queue_process_btn">▶ Process Next</button>
		<button class="mm-btn mm-btn-outline" id="mm_import_queue_refresh_btn">🔄 Refresh Queue</button>
	</div>
	<div id="mm_import_queue_status" class="mm-text-muted mm-mt-10"></div>
	<div id="mm_import_queue_list" class="mm-mt-10"></div>
</div>

<div class="mm-card mm-hidden" id="manual_sku_section">
	<h3>⚠️ Manual SKU Entry</h3>
	<p class="mm-text-muted">SKU could not be extracted. Enter the numeric product number from the image URL.</p>
	<div class="mm-form-row" style="max-width: 300px;">
		<label class="mm-label">Numeric SKU</label>
		<input type="text" id="manual_sku_input" class="mm-input" placeholder="e.g. 389546965">
	</div>
	<button class="mm-btn mm-btn-primary" id="btn_manual_sku">Submit SKU</button>
</div>

<div id="import_results" class="mm-mt-20"></div>

<!-- v6.5 Recently Scraped — full-width card layout per user reference image -->
<div class="mm-mt-20">
	<h3 style="margin:0 0 10px;">📦 Recently Scraped</h3>
	<p class="mm-text-muted" style="margin:0 0 14px;font-size:13px;">Live preview of the latest staged items. Open the Products tab for full management.</p>
	<div id="mm_import_recent_grid">
		<p class="mm-text-muted">Loading…</p>
	</div>
</div>
