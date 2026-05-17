<?php $settings = new Meesho_Master_Settings(); $site_id = $settings->get( 'mm_hotjar_id' ); ?>
<div class="mm-grid mm-grid-2">
	<div class="mm-card">
		<div class="mm-flex-between" style="align-items:center;">
			<h3 style="margin:0;">🔌 Integrations Health</h3>
			<button class="mm-btn mm-btn-outline mm-btn-sm" id="btn_refresh_integrations">Refresh</button>
		</div>
		<div id="mm_integrations_status" class="mm-mt-10">
			<p class="mm-text-muted">Loading integration status…</p>
		</div>
	</div>
	<div class="mm-card">
		<h3>🔥 Heatmaps (Hotjar) - v6</h3>
		<?php if ( ! empty( $site_id ) ) : ?>
		<iframe src="https://insights.hotjar.com/sites/<?php echo intval( $site_id ); ?>/heatmaps" style="width:100%; height:400px; border:1px solid var(--mm-border); border-radius:8px;" loading="lazy"></iframe>
		<div class="mm-mt-10">
			<button class="mm-btn mm-btn-primary" id="btn_generate_heatmap">🤖 Analyse with AI</button>
		</div>
		<div id="heatmap_insights" class="mm-mt-10"></div>
		<?php else : ?>
		<p class="mm-text-muted">Configure your Hotjar Site ID (mm_hotjar_id) in Settings to enable heatmaps.</p>
		<?php endif; ?>
	</div>
	<div class="mm-card">
		<h3>📈 Ranking Tracker (GSC) - v6</h3>
		<?php if ( ! empty( $settings->get( 'mm_gsc_credentials' ) ) ) : ?>
		<div class="mm-form-row mm-flex mm-gap-10">
			<input type="text" id="new_keyword" class="mm-input" placeholder="Enter a keyword to track...">
			<button class="mm-btn mm-btn-primary" id="btn_add_keyword">Add</button>
			<button class="mm-btn mm-btn-outline" id="btn_refresh_rankings">Force Refresh</button>
		</div>
		<div class="mm-form-row mm-mt-10">
			<label class="mm-label">Competitor Domains (comma-separated)</label>
			<input type="text" id="competitor_domains" class="mm-input" placeholder="e.g. competitor1.com, competitor2.com">
		</div>
		<div id="rankings_list" class="mm-mt-10">
			<table class="mm-table">
				<thead><tr><th>Keyword</th><th>Page</th><th>Position</th><th>Δ vs Last Week</th><th>Impressions</th><th>CTR</th></tr></thead>
				<tbody id="rankings_tbody"><tr><td colspan="6" class="mm-text-muted">Loading rankings...</td></tr></tbody>
			</table>
		</div>
		<?php else : ?>
		<p class="mm-text-muted">Configure GSC credentials (mm_gsc_credentials) in Settings to track rankings.</p>
		<?php endif; ?>
	</div>
</div>

<!-- E2: GA4 Site Traffic Card -->
<div class="mm-card mm-mt-20">
	<div class="mm-flex-between" style="align-items:center;">
		<h3 style="margin:0;">📊 Site Traffic (GA4)</h3>
		<div class="mm-flex mm-gap-10" style="align-items:center;">
			<select id="ga4_range" class="mm-select" style="width:130px;">
				<option value="7">Last 7 days</option>
				<option value="30" selected>Last 30 days</option>
				<option value="90">Last 90 days</option>
			</select>
			<button class="mm-btn mm-btn-primary" id="btn_load_ga4">Load Traffic Data</button>
			<button class="mm-btn mm-btn-outline" id="btn_load_ga4_force">Force Refresh</button>
		</div>
	</div>
	<div id="ga4_content" class="mm-mt-20">
		<?php $prop = $settings->get( 'mm_ga4_property_id' ); ?>
		<?php if ( empty( $prop ) ) : ?>
		<div class="mm-card" style="background:#fff7ed;border:1px solid #fed7aa;padding:12px;">
			⚠️ GA4 not configured. Go to <strong>Settings → Google Analytics 4</strong> and set your GA4 Property ID and connect via Site Kit or Service Account JSON.
		</div>
		<?php else : ?>
		<p class="mm-text-muted" style="font-size:13px;">Click "Load Traffic Data" to fetch real-time data from GA4 Property <strong><?php echo esc_html( $prop ); ?></strong>.</p>
		<?php endif; ?>
	</div>
</div>

<div class="mm-card mm-mt-20">
	<h3>📧 Email Reports - v6</h3>
	<p class="mm-text-muted">Reports include: GSC traffic, orders & revenue, ranking Δ, SEO score changes, top/bottom 5 pages, AI actions taken.</p>
	<div class="mm-form-row">
		<label class="mm-label">Preview Report</label>
		<button class="mm-btn mm-btn-outline" id="btn_preview_report">👁 Preview</button>
	</div>
	<div class="mm-flex mm-gap-10 mm-mt-10">
		<button class="mm-btn mm-btn-primary" id="btn_send_report">📤 Send Report Now</button>
		<button class="mm-btn mm-btn-outline" id="btn_test_email">🧪 Send Test Email</button>
	</div>
	<div id="report_preview" class="mm-mt-10" style="display:none;"></div>
</div>
