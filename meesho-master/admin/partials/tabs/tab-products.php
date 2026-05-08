<?php
/**
 * Products tab — v6.5
 * Table layout matching user's reference screenshot. Inline expandable
 * edit panel (no modal overlay). Per-row action buttons: View, AI Optimize,
 * Import to WC, Delete.
 */
$presets = Meesho_Master_Import::optimizer_presets();
?>
<div class="mm-products-header">
	<h2 style="margin:0;">📦 Scraped Products</h2>
	<a href="?page=meesho-master&tab=import" class="mm-btn mm-btn-primary mm-btn-sm">+ Scrape More</a>
</div>

<div class="mm-card mm-mt-10">
	<div class="mm-info-banner">Showing source price vs your selling price. Click row title to view full details.</div>
	<div class="mm-flex mm-gap-10 mm-mt-10">
		<button class="mm-btn mm-btn-outline mm-btn-sm" id="mm_products_refresh">🔄 Refresh</button>
		<input type="text" id="mm_products_search" class="mm-input" placeholder="Search SKU or title…" style="max-width:280px;">
		<select id="mm_products_filter" class="mm-input" style="max-width:200px;">
			<option value="all">All statuses</option>
			<option value="staged">Staged only</option>
			<option value="published">Published only</option>
		</select>
	</div>
</div>

<div id="mm_products_grid" class="mm-mt-10">
	<p class="mm-text-muted">Loading products…</p>
</div>

<style>
.mm-products-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:14px}
.mm-info-banner{background:#eff6ff;color:#1e40af;padding:10px 14px;border-radius:6px;font-size:13px}
.mm-products-table{width:100%;border-collapse:collapse;background:#fff;border-radius:8px;overflow:hidden}
.mm-products-table thead th{background:#f8fafc;padding:10px 12px;text-align:left;font-size:11px;text-transform:uppercase;color:#64748b;font-weight:600;letter-spacing:0.5px;border-bottom:1px solid #e2e8f0}
.mm-products-table tbody td{padding:14px 12px;border-bottom:1px solid #f1f5f9;vertical-align:middle}
.mm-products-table tbody tr:hover{background:#fafbfc}
.mm-row-thumb{width:48px;height:48px;object-fit:cover;border-radius:6px;background:#f1f5f9;border:1px solid #e2e8f0}
.mm-row-title{color:#9F2089;text-decoration:underline;font-weight:600;cursor:pointer;font-size:14px}
.mm-row-title:hover{color:#7a1668}
.mm-row-price-source{color:#64748b;font-size:13px}
.mm-row-price-our{color:#10b981;font-weight:700;font-size:14px}
.mm-row-sizes{color:#64748b;font-size:13px;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.mm-row-actions{display:flex;gap:6px;align-items:center}
.mm-row-actions .mm-btn{padding:6px 10px;font-size:12px;border-radius:6px;line-height:1}
.mm-status-badge{display:inline-block;padding:3px 10px;border-radius:12px;font-size:11px;font-weight:600;text-transform:lowercase}
.mm-status-staged{background:#e0e7ff;color:#3730a3}
.mm-status-published{background:#d1fae5;color:#065f46}
.mm-btn-ai{background:linear-gradient(135deg,#ec4899,#9F2089);color:#fff;border:none}
.mm-btn-ai:hover{filter:brightness(1.1)}
.mm-btn-woo{background:#10b981;color:#fff;border:none}
.mm-btn-woo:hover{background:#059669}
.mm-btn-trash{background:#fff;color:#ef4444;border:1px solid #fecaca}
.mm-btn-view{background:#fff;color:#475569;border:1px solid #cbd5e1}
.mm-product-edit-panel{background:#fafbfc;border-bottom:1px solid #e2e8f0}
.mm-product-edit-panel-inner{padding:20px;display:grid;grid-template-columns:1fr 1fr;gap:18px}
.mm-edit-section h4{margin:0 0 8px;font-size:14px;color:#374151}
.mm-edit-images-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:8px}
.mm-edit-images-grid img{width:100%;height:80px;object-fit:cover;border-radius:6px;border:1px solid #e2e8f0;background:#f1f5f9}
.mm-edit-reviews{max-height:280px;overflow-y:auto}
.mm-edit-review{padding:10px;border:1px solid #e2e8f0;border-radius:6px;margin-bottom:8px;background:#fff}
.mm-edit-review-header{display:flex;justify-content:space-between;font-size:13px;margin-bottom:4px}
.mm-edit-review-stars{color:#f59e0b;font-size:12px}
.mm-edit-review-text{color:#374151;font-size:13px}
.mm-edit-review-media{display:flex;gap:6px;margin-top:6px;flex-wrap:wrap}
.mm-edit-review-media img{width:48px;height:48px;object-fit:cover;border-radius:4px}
.mm-title-with-ai{display:flex;gap:6px;align-items:flex-start}
.mm-title-with-ai input{flex:1}
.mm-edit-actions{padding:14px 20px;border-top:1px solid #e2e8f0;background:#fff;display:flex;justify-content:flex-end;gap:8px}
.mm-edit-status{flex:1;color:#64748b;font-size:13px;align-self:center}
.mm-empty-state{padding:40px;text-align:center;color:#64748b}
@media (max-width:1100px){.mm-product-edit-panel-inner{grid-template-columns:1fr}}
</style>
