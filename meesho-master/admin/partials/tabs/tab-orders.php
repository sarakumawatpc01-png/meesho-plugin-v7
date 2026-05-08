<div class="mm-flex-between mm-mb-20">
	<h3 style="margin:0;">📋 Order Management</h3>
	<div class="mm-flex mm-gap-10">
		<input type="text" id="order_search" class="mm-input" style="width:200px;" placeholder="Search order / tracking ID..." onkeyup="if(event.key==='Enter')MeeshoMaster.loadOrders()">
		<select id="order_status_filter" class="mm-select" style="width:180px;" onchange="MeeshoMaster.loadOrders()">
			<option value="">All Statuses</option>
			<option value="pending">Pending Fulfillment</option>
			<option value="ordered_on_meesho">Ordered on Meesho</option>
			<option value="tracking_received">Tracking Received</option>
			<option value="dispatched">Dispatched</option>
			<option value="delivered">Delivered</option>
			<option value="cancelled">Cancelled</option>
			<option value="returned">Returned</option>
		</select>
		<button class="mm-btn mm-btn-outline" onclick="MeeshoMaster.loadOrders()">🔄 Refresh</button>
	</div>
</div>

<div class="mm-card" style="padding:0; overflow-x:auto;">
	<table class="mm-table">
		<thead>
			<tr>
				<th>Order</th>
				<th>Product / SKU / Size</th>
				<th>Customer Info</th>
				<th>Payment</th>
				<th>Fulfillment</th>
				<th>Meesho Account</th>
				<th>SLA</th>
				<th>Actions</th>
			</tr>
		</thead>
		<tbody id="orders_table_body">
			<tr><td colspan="8" style="text-align:center; padding:30px;" class="mm-text-muted">Loading orders...</td></tr>
		</tbody>
	</table>
</div>

<p class="mm-text-muted" style="font-size:12px; margin-top:10px;">
	🔴 Red rows = SLA breached (pending > 4 hours) &nbsp;|&nbsp;
	<span class="mm-badge mm-badge-danger">RISK</span> = High COD risk &nbsp;|&nbsp;
	📋 = Copy to clipboard
</p>

<!-- Order Edit Modal -->
<div class="mm-modal-overlay" id="order-edit-modal">
	<div class="mm-modal" style="max-width:560px;">
		<h3 style="color:var(--mm-primary-dark);">✏️ Update Order #<span id="oe-order-id"></span></h3>
		<div class="mm-form-row">
			<label class="mm-label">Fulfillment Status</label>
			<select id="oe-status" class="mm-select">
				<option value="pending">Pending Fulfillment</option>
				<option value="ordered_on_meesho">Ordered on Meesho</option>
				<option value="tracking_received">Tracking Received</option>
				<option value="dispatched">Dispatched</option>
				<option value="delivered">Delivered</option>
				<option value="cancelled">Cancelled</option>
				<option value="returned">Returned</option>
			</select>
		</div>
		<div class="mm-form-row">
			<label class="mm-label">Meesho Order ID</label>
			<input type="text" id="oe-meesho-id" class="mm-input" placeholder="e.g. MSH-12345678">
		</div>
		<div class="mm-form-row">
			<label class="mm-label">Tracking ID</label>
			<input type="text" id="oe-tracking" class="mm-input" placeholder="e.g. DTDC-987654">
		</div>
		<div class="mm-form-row">
			<label class="mm-label">Meesho Account Used</label>
			<select id="oe-account" class="mm-select">
				<option value="">Select account...</option>
			</select>
		</div>
		<div class="mm-form-row">
			<label class="mm-label">Add Note</label>
			<textarea id="oe-notes" class="mm-textarea" rows="2" placeholder="Optional transition note..."></textarea>
		</div>
		<div class="mm-modal-actions">
			<button class="mm-btn mm-btn-outline" onclick="document.getElementById('order-edit-modal').classList.remove('active')">Cancel</button>
			<button class="mm-btn mm-btn-primary" onclick="MeeshoMaster.submitOrderEdit()">💾 Save</button>
		</div>
	</div>
</div>
