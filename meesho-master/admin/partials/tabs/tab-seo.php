<?php
/* Score dashboard - v6 compliant */
global $wpdb;
$score_table = MM_DB::table( 'seo_post_scores' );
$suggestions_table = MM_DB::table( 'seo_suggestions' );

$avg_seo = round( floatval( $wpdb->get_var( "SELECT AVG(seo_score) FROM {$score_table}" ) ) );
$avg_aeo = round( floatval( $wpdb->get_var( "SELECT AVG(aeo_score) FROM {$score_table}" ) ) );
$avg_geo = round( floatval( $wpdb->get_var( "SELECT AVG(geo_score) FROM {$score_table}" ) ) );
$pending  = intval( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$suggestions_table} WHERE status = %s", 'pending' ) ) );
$applied  = intval( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$suggestions_table} WHERE status = %s", 'applied' ) ) );

// SEO plugin status banner
$active_plugin = MM_SEO_Crawler::detect_seo_plugin();
$plugin_labels = array( 'yoast' => 'Yoast SEO', 'rankmath' => 'RankMath', 'aioseo' => 'AIOSEO', 'none' => 'No SEO Plugin' );
$plugin_status = $plugin_labels[ $active_plugin ] ?? $active_plugin;

if ( ! function_exists( 'mm_score_class' ) ) {
	function mm_score_class( $s ) { return $s >= 70 ? 'score-high' : ( $s >= 40 ? 'score-med' : 'score-low' ); }
}
?>

<!-- SEO Plugin Status Banner -->
<div class="mm-notice mm-notice-info mm-mb-20">
	<strong>Active SEO Plugin:</strong> <?php echo esc_html( $plugin_status ); ?>
	<span class="mm-text-muted">| Scores stored in <?php echo 'none' === $active_plugin ? 'MM fallback keys (_mm_seo_title, etc.)' : esc_html( $active_plugin ) . ' keys'; ?></span>
</div>

<!-- Score Dashboard -->
<div class="mm-grid mm-grid-4 mm-mb-20">
	<div class="mm-stat-card">
		<div class="mm-stat-value"><?php echo $avg_seo; ?></div>
		<div class="mm-stat-label">Avg SEO Score</div>
		<div class="mm-score-bar mm-mt-10"><div class="mm-score-bar-track"><div class="mm-score-bar-fill <?php echo mm_score_class($avg_seo); ?>" style="width:<?php echo $avg_seo; ?>%"></div></div></div>
	</div>
	<div class="mm-stat-card">
		<div class="mm-stat-value"><?php echo $avg_aeo; ?></div>
		<div class="mm-stat-label">Avg AEO Score</div>
		<div class="mm-score-bar mm-mt-10"><div class="mm-score-bar-track"><div class="mm-score-bar-fill <?php echo mm_score_class($avg_aeo); ?>" style="width:<?php echo $avg_aeo; ?>%"></div></div></div>
	</div>
	<div class="mm-stat-card">
		<div class="mm-stat-value"><?php echo $avg_geo; ?></div>
		<div class="mm-stat-label">Avg GEO Score</div>
		<div class="mm-score-bar mm-mt-10"><div class="mm-score-bar-track"><div class="mm-score-bar-fill <?php echo mm_score_class($avg_geo); ?>" style="width:<?php echo $avg_geo; ?>%"></div></div></div>
	</div>
	<div class="mm-stat-card">
		<div class="mm-stat-value"><?php echo $pending; ?></div>
		<div class="mm-stat-label">Pending Suggestions</div>
		<p class="mm-text-muted" style="font-size:11px; margin:4px 0 0;"><?php echo $applied; ?> applied total</p>
		<button class="mm-btn mm-btn-sm mm-btn-outline mm-mt-10" onclick="MeeshoMaster.exportCSV()">📥 Export CSV</button>
	</div>
</div>

<!-- v6.3 — Configuration diagnostics -->
<div id="mm_seo_diagnostics"></div>

<!-- Targeted-scan picker (v6.2) -->
<div class="mm-card mm-mb-20">
	<h3>🎯 Targeted SEO/AEO/GEO Scan</h3>
	<p class="mm-text-muted">Pick specific pages, posts, or products to scan instead of waiting for the auto-priority queue. Scans run immediately and queue suggestions below.</p>
	<div class="mm-grid mm-grid-2">
		<div class="mm-form-row">
			<label class="mm-label">Content Type</label>
			<select id="mm_target_post_type" class="mm-select">
				<option value="any">All types</option>
				<option value="post">Posts</option>
				<option value="page">Pages</option>
				<option value="product">Products (WooCommerce)</option>
			</select>
		</div>
		<div class="mm-form-row">
			<label class="mm-label">Pick from list <span class="mm-text-muted" style="font-weight:normal;font-size:11px;">(all items of selected type)</span></label>
			<select id="mm_target_dropdown" class="mm-select">
				<option>Loading…</option>
			</select>
		</div>
	</div>
	<div class="mm-form-row">
		<label class="mm-label">Or search by name <span class="mm-text-muted" style="font-weight:normal;font-size:11px;">(at least 2 chars)</span></label>
		<input type="text" id="mm_target_search" class="mm-input" placeholder="Type to search…">
	</div>
	<div class="mm-form-row">
		<label class="mm-label">Selected items (click to remove):</label>
		<div id="mm_target_selected" class="mm-flex mm-gap-10" style="flex-wrap:wrap; min-height:36px; padding:6px; border:1px dashed #cbd5e1; border-radius:6px; background:#f8fafc;">
			<span class="mm-text-muted" style="font-size:12px;">No items selected — pick from dropdown above.</span>
		</div>
	</div>
	<div id="mm_target_results" style="max-height:200px; overflow-y:auto; border:1px solid #e2e8f0; border-radius:6px; background:#fff;"></div>
	<div class="mm-mt-10 mm-flex mm-gap-10">
		<button class="mm-btn mm-btn-primary" id="mm_target_scan_btn">🔍 Scan Selected (SEO + AEO + GEO)</button>
		<button class="mm-btn mm-btn-outline" id="mm_target_clear_btn">Clear</button>
	</div>
	<div id="mm_target_status" class="mm-text-muted mm-mt-10" style="font-size:13px;"></div>
</div>

<!-- Filters -->
<div class="mm-flex-between mm-mb-20">
	<div>
		<h3 style="margin:0;">🔍 SEO / AEO / GEO Dashboard</h3>
		<p class="mm-text-muted" style="margin:4px 0 0;">Sortable table with score breakdown, trends, and actions.</p>
	</div>
	<div class="mm-flex mm-gap-10">
		<select id="seo_priority_filter" class="mm-select" style="width:140px;" onchange="MeeshoMaster.loadSuggestions()">
			<option value="">All Priorities</option>
			<option value="high">High</option>
			<option value="medium">Medium</option>
			<option value="low">Low</option>
		</select>
		<select id="seo_type_filter" class="mm-select" style="width:160px;" onchange="MeeshoMaster.loadSuggestions()">
			<option value="">All Types</option>
			<option value="meta_title">Meta Title</option>
			<option value="meta_desc">Meta Description</option>
			<option value="alt_tag">Alt Tag</option>
			<option value="schema">Schema</option>
			<option value="faq">FAQ</option>
			<option value="content">Content</option>
			<option value="internal_link">Internal Link</option>
			<option value="citability_block">Citability</option>
			<option value="statistics_inject">Statistics</option>
		</select>
		<select id="seo_score_filter" class="mm-select" style="width:160px;" onchange="MeeshoMaster.loadSuggestions()">
			<option value="">All Scores</option>
			<option value="high">High (70-100)</option>
			<option value="med">Medium (40-69)</option>
			<option value="low">Low (0-39)</option>
		</select>
		<button class="mm-btn mm-btn-danger" onclick="MeeshoMaster.bulkReject()">❌ Bulk Reject</button>
		<button class="mm-btn mm-btn-success" onclick="MeeshoMaster.applyAllSafe()">✅ Apply All Safe</button>
		<button class="mm-btn mm-btn-outline" onclick="MeeshoMaster.loadSuggestions()">🔄 Refresh</button>
		<button class="mm-btn mm-btn-outline" onclick="MeeshoMaster.researchKeywords()" style="margin-left:10px;">🔍 Research Keywords</button>
	</div>
</div>

<!-- Score Table (sortable) -->
<div class="mm-card" style="padding:0; overflow-x:auto;">
	<table class="mm-table" id="seo_score_table">
		<thead>
			<tr>
				<th class="sortable" onclick="MeeshoMaster.sortTable('seo_score_table', 0)">Post ID ↕</th>
				<th>Type</th>
				<th>SEO Score</th>
				<th>AEO Score</th>
				<th>GEO Score</th>
				<th>Focus Keyword</th>
				<th>Last Scanned</th>
				<th>Actions</th>
			</tr>
		</thead>
		<tbody id="seo_score_table_body">
			<tr><td colspan="8" style="text-align:center; padding:30px;" class="mm-text-muted">Loading scores...</td></tr>
		</tbody>
	</table>
</div>

<!-- Suggestions Queue -->
<div class="mm-mt-30">
	<h3>📋 Suggestions Queue</h3>
	<div class="mm-card" style="padding:0; overflow-x:auto;">
		<table class="mm-table">
			<thead>
				<tr>
					<th><input type="checkbox" id="select_all_suggestions" onchange="MeeshoMaster.toggleAllSuggestions()"></th>
					<th>Post ID</th>
					<th>Type</th>
					<th>Current → Suggested</th>
					<th>Confidence</th>
					<th>Priority</th>
					<th>Actions</th>
				</tr>
			</thead>
			<tbody id="seo_suggestions_body">
				<tr><td colspan="7" style="text-align:center; padding:30px;" class="mm-text-muted">Loading suggestions...</td></tr>
			</tbody>
		</table>
	</div>
</div>

<!-- Bottom tools -->
<div class="mm-grid mm-grid-3 mm-mt-20">
	<div class="mm-card">
		<h3>🖥️ Manual Scan</h3>
		<p class="mm-text-muted">Run a SEO/AEO/GEO batch analysis now (processes 5-10 pages).</p>
		<button class="mm-btn mm-btn-primary" onclick="MeeshoMaster.scanSelected()">
			▶️ Scan Selected Posts
		</button>
		<button class="mm-btn mm-btn-outline mm-ml-10" onclick="MeeshoMaster.fullScanNow()">
			🔄 Full Scan Now
		</button>
	</div>
	<div class="mm-card">
		<h3>📄 llms.txt</h3>
		<p class="mm-text-muted">Generate or update the AI crawler access rules file at <code>/llms.txt</code>.</p>
		<button class="mm-btn mm-btn-outline" id="btn_generate_llms" onclick="MeeshoMaster.generateLLMs()">Generate llms.txt</button>
		<pre id="llms_preview" style="margin-top:10px; max-height:200px; overflow:auto; display:none;"></pre>
	</div>
	<div class="mm-card">
		<h3>📊 Score Trends</h3>
		<p class="mm-text-muted">Daily averages across all scanned pages.</p>
		<button class="mm-btn mm-btn-outline" onclick="MeeshoMaster.viewTrends()">View Trends</button>
		<div id="mm_seo_trends" class="mm-mt-10"></div>
	</div>
</div>

<!-- Per-Post Slide-In Panel (v6) -->
<div id="post_detail_panel" class="mm-slide-panel">
	<div class="mm-slide-panel-header">
		<h3 id="post_detail_title">Post #0</h3>
		<button class="mm-btn-icon" onclick="MeeshoMaster.closePostPanel()">✕</button>
	</div>
	<div class="mm-slide-panel-body">
		<!-- Score Breakdown -->
		<div class="mm-mb-20">
			<h4>Score Breakdown</h4>
			<div class="mm-score-breakdown">
				<div class="mm-score-item">
					<span>SEO Score</span>
					<div class="mm-score-bar-track"><div class="mm-score-bar-fill" id="detail_seo_score" style="width:0%"></div></div>
					<span id="detail_seo_value">0</span>
				</div>
				<div class="mm-score-item">
					<span>AEO Score</span>
					<div class="mm-score-bar-track"><div class="mm-score-bar-fill" id="detail_aeo_score" style="width:0%"></div></div>
					<span id="detail_aeo_value">0</span>
				</div>
				<div class="mm-score-item">
					<span>GEO Score</span>
					<div class="mm-score-bar-track"><div class="mm-score-bar-fill" id="detail_geo_score" style="width:0%"></div></div>
					<span id="detail_geo_value">0</span>
				</div>
			</div>
		</div>

		<!-- Score Factors -->
		<div class="mm-mb-20">
			<h4>Score Factors</h4>
			<div id="detail_factors" class="mm-text-muted">Loading...</div>
		</div>

		<!-- Trend Chart (Placeholder) -->
		<div class="mm-mb-20">
			<h4>Score Trend (Last 10 Runs)</h4>
			<canvas id="trend_chart" width="400" height="200" style="max-width:100%;"></canvas>
		</div>

		<!-- Suggestions for this Post -->
		<div class="mm-mb-20">
			<h4>Suggestions</h4>
			<div id="detail_suggestions">Loading...</div>
		</div>

		<!-- Audit History -->
		<div class="mm-mb-20">
			<h4>Audit History</h4>
			<div id="detail_audit_log">Loading...</div>
		</div>

		<!-- Undo Button -->
		<div>
			<button class="mm-btn mm-btn-outline" id="btn_undo_post" onclick="MeeshoMaster.undoPostAction()">↩️ Undo Last Action</button>
		</div>
	</div>
</div>

<style>
.mm-slide-panel {
	position: fixed;
	top: 0;
	right: -500px;
	width: 450px;
	height: 100vh;
	background: #fff;
	box-shadow: -2px 0 10px rgba(0,0,0,0.1);
	transition: right 0.3s ease;
	z-index: 9999;
	overflow-y: auto;
}
.mm-slide-panel.active {
	right: 0;
}
.mm-slide-panel-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 15px 20px;
	border-bottom: 1px solid #e2e8f0;
}
.mm-slide-panel-body {
	padding: 20px;
}
.mm-score-breakdown {
	display: flex;
	flex-direction: column;
	gap: 10px;
}
.mm-score-item {
	display: flex;
	align-items: center;
	gap: 10px;
}
.mm-score-item span:first-child {
	width: 100px;
	font-size: 13px;
}
.mm-score-item span:last-child {
	width: 40px;
	text-align: right;
	font-weight: 600;
}
@media (max-width: 375px) {
	.mm-slide-panel {
		width: 100vw;
	}
}
</style>

<script>
MeeshoMaster.openPostPanel = function(postId) {
	const panel = document.getElementById('post_detail_panel');
	const title = document.getElementById('post_detail_title');
	title.textContent = 'Post #' + postId;
	panel.classList.add('active');
	
	// Load post details via AJAX
	MM.ajax('mm_get_post_details', { post_id: postId }, (data) => {
		// Update scores
		document.getElementById('detail_seo_score').style.width = data.seo_score + '%';
		document.getElementById('detail_seo_value').textContent = data.seo_score;
		document.getElementById('detail_aeo_score').style.width = data.aeo_score + '%';
		document.getElementById('detail_aeo_value').textContent = data.aeo_score;
		document.getElementById('detail_geo_score').style.width = data.geo_score + '%';
		document.getElementById('detail_geo_value').textContent = data.geo_score;
		
		// Update factors
		let factorsHtml = '';
		if (data.factors && data.factors.seo) {
			factorsHtml += '<div><strong>SEO:</strong> ' + Object.entries(data.factors.seo).map(([k,v]) => k + ': ' + v + '%').join(', ') + '</div>';
		}
		if (data.factors && data.factors.aeo) {
			factorsHtml += '<div><strong>AEO:</strong> ' + Object.entries(data.factors.aeo).map(([k,v]) => k + ': ' + v + '%').join(', ') + '</div>';
		}
		if (data.factors && data.factors.geo) {
			factorsHtml += '<div><strong>GEO:</strong> ' + Object.entries(data.factors.geo).map(([k,v]) => k + ': ' + v + '%').join(', ') + '</div>';
		}
		document.getElementById('detail_factors').innerHTML = factorsHtml || 'No factor data.';
		
		// Load suggestions for this post
		MM.ajax('mm_get_post_suggestions', { post_id: postId }, (suggestions) => {
			const container = document.getElementById('detail_suggestions');
			container.innerHTML = !suggestions.length ? 'No suggestions.' : suggestions.map(s => 
				'<div class="mm-card mm-mb-10"><div><strong>' + escapeHtml(s.type) + '</strong> <span class="mm-badge mm-badge-' + (s.priority === 'high' ? 'danger' : 'info') + '">' + escapeHtml(s.priority) + '</span></div>' +
				'<div class="mm-text-muted" style="font-size:12px;">' + escapeHtml(s.current_value || '').substring(0, 50) + ' → ' + escapeHtml(s.suggested_value || '').substring(0, 50) + '</div>' +
				'<button class="mm-btn mm-btn-sm mm-btn-success mm-mt-5" onclick="MeeshoMaster.applySuggestion(' + s.id + ')">Apply</button></div>'
			).join('');
		});
		
		// Load audit log for this post
		MM.ajax('mm_get_post_audit_log', { post_id: postId }, (logs) => {
			const container = document.getElementById('detail_audit_log');
			container.innerHTML = !logs.length ? 'No audit history.' : logs.map(log => 
				'<div class="mm-text-muted" style="font-size:12px; border-bottom:1px solid #f1f5f9; padding:5px 0;">' +
				escapeHtml(log.created_at) + ' - ' + escapeHtml(log.action_type) + 
				(log.undoable && !log.undone ? ' <button class="mm-btn mm-btn-sm mm-btn-outline" onclick="MeeshoMaster.undoLogAction(' + log.id + ')">Undo</button>' : '') +
				'</div>'
			).join('');
		});
	});
};

MeeshoMaster.closePostPanel = function() {
	document.getElementById('post_detail_panel').classList.remove('active');
};

MeeshoMaster.undoPostAction = function() {
	const postId = document.getElementById('post_detail_title').textContent.replace('Post #', '');
	MM.ajax('mm_undo_action', { log_id: 0, post_id: postId }, (msg) => {
		MM.toast(msg, 'success');
		MeeshoMaster.openPostPanel(postId);
	});
};
</script>
