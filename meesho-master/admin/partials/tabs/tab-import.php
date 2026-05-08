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

<style>
.mm-recent-card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:18px;margin-bottom:14px;display:grid;grid-template-columns:180px 1fr;gap:18px}
.mm-recent-thumb{width:180px;height:180px;object-fit:cover;border-radius:8px;background:#f1f5f9;border:1px solid #e2e8f0}
.mm-recent-info h4{margin:0;font-size:18px;color:#0f172a;font-weight:700}
.mm-recent-link{color:#9F2089;font-size:12px;text-decoration:underline;display:block;margin:4px 0 12px;word-break:break-all}
.mm-recent-row{display:flex;gap:18px;font-size:13px;color:#475569;align-items:center;margin-bottom:6px;flex-wrap:wrap}
.mm-recent-row strong{color:#0f172a}
.mm-recent-row .mm-row-price-our{color:#10b981;font-weight:700}
.mm-recent-rating{display:inline-flex;align-items:center;gap:3px}
.mm-recent-rating .star{color:#f59e0b}
.mm-recent-actions{display:flex;gap:8px;margin-top:14px;flex-wrap:wrap}
.mm-recent-images-strip{margin-top:14px}
.mm-recent-images-strip h5{font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin:0 0 6px;font-weight:600}
.mm-recent-images-strip-grid{display:flex;gap:8px;flex-wrap:wrap}
.mm-recent-images-strip-grid img{width:80px;height:80px;object-fit:cover;border-radius:6px;border:1px solid #e2e8f0;background:#f1f5f9}
.mm-recent-reviews-strip{margin-top:14px}
.mm-recent-reviews-strip h5{font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin:0 0 6px;font-weight:600}
.mm-recent-review{padding:10px;border:1px solid #e2e8f0;border-radius:8px;margin-bottom:6px;background:#fafbfc;font-size:13px}
.mm-recent-review-meta{display:flex;gap:10px;font-size:11px;color:#94a3b8;margin-bottom:4px}
.mm-recent-review-meta strong{color:#0f172a;font-size:12px}
@media (max-width:768px){.mm-recent-card{grid-template-columns:1fr}}
</style>
