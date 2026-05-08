<div class="mm-flex-between mm-mb-20">
	<h3 style="margin:0;">📝 Audit Logs & Undo</h3>
	<div class="mm-flex mm-gap-10">
		<select id="log_type_filter" class="mm-select" style="width:160px;" onchange="MeeshoMaster.loadLogs()">
			<option value="">All Types</option>
			<option value="meta_update">Meta Update</option>
			<option value="post_update">Post Update</option>
			<option value="product_import">Product Import</option>
			<option value="order_update">Order Update</option>
			<option value="schema_update">Schema Update</option>
			<option value="delete">Delete</option>
		</select>
		<select id="log_source_filter" class="mm-select" style="width:130px;" onchange="MeeshoMaster.loadLogs()">
			<option value="">All Sources</option>
			<option value="manual">Manual</option>
			<option value="ai">AI</option>
			<option value="copilot">Copilot</option>
			<option value="auto">Auto</option>
		</select>
		<button class="mm-btn mm-btn-outline" onclick="MeeshoMaster.loadLogs()">🔄 Refresh</button>
	</div>
</div>

<div class="mm-card" style="padding:0; overflow-x:auto;">
	<table class="mm-table">
		<thead>
			<tr>
				<th>Date</th>
				<th>Action Type</th>
				<th>Post / Order</th>
				<th>Source</th>
				<th>Changes</th>
				<th>Actions</th>
			</tr>
		</thead>
		<tbody id="logs_table_body">
			<tr><td colspan="6" style="text-align:center; padding:30px;" class="mm-text-muted">Loading logs...</td></tr>
		</tbody>
	</table>
</div>

<p class="mm-text-muted" style="font-size:12px; margin-top:10px;">
	⏰ Undo is available for 15 days after each action. After that, snapshot data is purged but log metadata is kept indefinitely.
</p>
