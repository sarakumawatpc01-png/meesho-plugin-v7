/* Meesho Master — admin JS — v6.2 (clean rewrite) */
(function () {
	'use strict';

	const MM = window.MeeshoMaster = window.MeeshoMaster || {};
	let currentCopilotThread = '';

	const $ = (sel, root = document) => root.querySelector(sel);
	const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));
	const escapeHtml = (v) => {
		const d = document.createElement('div');
		d.textContent = v == null ? '' : String(v);
		return d.innerHTML;
	};
	const normalizeVariationRows = (sizes, baseSku) => {
		const list = Array.isArray(sizes) ? sizes : [];
		return list.map((s) => {
			if (typeof s === 'string') {
				const sz = s.trim().toUpperCase();
				const skuSuffix = sz.replace(/[^A-Z0-9\-_]/g, '').replace(/\s+/g, '-');
				return { size: sz, sku: `${baseSku}-${skuSuffix}`, stock: '', available: true, oos: false, price: 0, mrp: 0 };
			}
			const sz = String((s && s.size) || '').trim().toUpperCase();
			const skuSuffix = sz.replace(/[^A-Z0-9\-_]/g, '').replace(/\s+/g, '-');
			return {
				size: sz,
				sku: (s && s.sku) ? String(s.sku) : `${baseSku}-${skuSuffix}`,
				stock: (s && (s.stock || s.stock === 0)) ? String(s.stock) : '',
				available: s && Object.prototype.hasOwnProperty.call(s, 'available') ? !!s.available : true,
				oos: !!(s && (s.oos || s.out_of_stock)),
				price: parseFloat((s && s.price) || 0) || 0,
				mrp: parseFloat((s && s.mrp) || 0) || 0,
			};
		}).filter((r) => r.size);
	};
	const readVariationRowsFromPanel = (id) => {
		return $$(`[data-mm-var-row="${id}"]`).map((row) => {
			const size = ($('[data-mm-var-size]', row) || {}).value || '';
			const sku = ($('[data-mm-var-sku]', row) || {}).value || '';
			const stockRaw = ($('[data-mm-var-stock]', row) || {}).value || '';
			const oos = !!(($('[data-mm-var-oos]', row) || {}).checked);
			const priceRaw = ($('[data-mm-var-price]', row) || {}).value || '';
			const mrpRaw = ($('[data-mm-var-mrp]', row) || {}).value || '';
			const stockNum = stockRaw === '' ? '' : Math.max(0, parseInt(stockRaw, 10) || 0);
			return {
				size: size.trim().toUpperCase(),
				sku: sku.trim(),
				stock: stockNum,
				available: !oos && (stockNum === '' ? true : stockNum > 0),
				oos,
				price: parseFloat(priceRaw) || 0,
				mrp: parseFloat(mrpRaw) || 0,
			};
		}).filter((v) => v.size);
	};
	const readReviewsFromPanel = (id) => {
		return $$(`[data-mm-review-row="${id}"]`).map((row) => ({
			reviewer_name: (($('[data-mm-review-name]', row) || {}).value || '').trim() || 'Customer',
			rating: Math.min(5, Math.max(1, parseInt((($('[data-mm-review-rating]', row) || {}).value || '5'), 10) || 5)),
			comment: (($('[data-mm-review-comment]', row) || {}).value || '').trim(),
			date: (($('[data-mm-review-date]', row) || {}).value || '').trim(),
			media: (($('[data-mm-review-media]', row) || {}).value || '').split(',').map((m) => m.trim()).filter(Boolean),
		})).filter((r) => r.comment || r.reviewer_name);
	};

	/* ============================================================
	 * Toast / notifications
	 * ============================================================ */
	MM.toast = function (msg, type = 'info') {
		let c = $('.mm-toast-container');
		if (!c) {
			c = document.createElement('div');
			c.className = 'mm-toast-container';
			c.style.cssText = 'position:fixed;top:40px;right:20px;z-index:99999;display:flex;flex-direction:column;gap:8px;';
			document.body.appendChild(c);
		}
		const t = document.createElement('div');
		t.className = 'mm-toast mm-toast-' + type;
		t.textContent = msg;
		t.style.cssText = 'padding:10px 14px;border-radius:6px;color:#fff;box-shadow:0 4px 12px rgba(0,0,0,0.15);max-width:380px;font-size:13px;background:' +
			(type === 'success' ? '#10b981' : type === 'error' ? '#ef4444' : '#3b82f6');
		c.appendChild(t);
		setTimeout(() => t.remove(), 5000);
	};

	/* ============================================================
	 * AJAX helpers
	 * ============================================================ */
	const ajaxPost = (action, data = {}) => {
		const fd = new FormData();
		fd.append('action', action);
		fd.append('nonce', meesho_ajax.nonce);
		Object.keys(data).forEach((k) => {
			fd.append(k, typeof data[k] === 'object' ? JSON.stringify(data[k]) : data[k]);
		});
		return fetch(meesho_ajax.ajax_url, { method: 'POST', body: fd, credentials: 'same-origin' })
			.then((r) => r.json());
	};

	// Legacy callback-style helper used by older inline calls.
	MM.ajax = function (action, data, ok, fail) {
		ajaxPost(action, data || {}).then((res) => {
			if (res && res.success) (ok || function () {})(res.data);
			else (fail || function () {})(res && res.data ? res.data : { message: 'Request failed' });
		}).catch((err) => (fail || function () {})({ message: err.message || 'Network error' }));
	};

	/* ============================================================
	 * Tab-load dispatcher (called when a tab is opened)
	 * ============================================================ */
	MM.onTabLoad = function (tab) {
		if (tab === 'products') MM.loadProducts && MM.loadProducts();
		if (tab === 'seo') MM.loadSuggestions && MM.loadSuggestions();
		if (tab === 'logs') MM.loadLogs && MM.loadLogs();
		if (tab === 'analytics') {
			MM.loadRankings && MM.loadRankings();
			MM.loadAnalyticsIntegrations && MM.loadAnalyticsIntegrations();
		}
		if (tab === 'orders') MM.loadOrders && MM.loadOrders();
	};

	/* ============================================================
	 * Orders tab
	 * ============================================================ */
	MM.orders = { page: 1, editing: null };

	MM.loadOrders = function (page = 1) {
		const tbody = $('#orders_table_body');
		if (!tbody) return;
		MM.orders.page = Math.max(1, parseInt(page, 10) || 1);
		const status = ($('#order_status_filter') || {}).value || '';
		const search = ($('#order_search') || {}).value || '';
		tbody.innerHTML = '<tr><td colspan="8" style="text-align:center; padding:30px;" class="mm-text-muted">Loading orders...</td></tr>';
		ajaxPost('meesho_get_orders', { page: MM.orders.page, status, search }).then((res) => {
			if (!res.success) {
				tbody.innerHTML = '<tr><td colspan="8" style="text-align:center; padding:30px;" class="mm-text-muted">Failed to load orders.</td></tr>';
				return;
			}
			const orders = (res.data && res.data.orders) || [];
			if (!orders.length) {
				tbody.innerHTML = '<tr><td colspan="8" style="text-align:center; padding:30px;" class="mm-text-muted">No orders found.</td></tr>';
				return;
			}
			const rows = orders.map((o) => {
				const items = Array.isArray(o.items) ? o.items : [];
				const itemHtml = items.length ? items.map((it) => {
					const quickLink = it.source_url
						? `<a href="${escapeHtml(it.source_url)}" target="_blank" class="mm-btn mm-btn-sm mm-btn-outline" style="padding:2px 6px;line-height:1.2;">🔗 Source</a>`
						: '';
					return `<div style="margin-bottom:6px;">
						<div><strong>${escapeHtml(it.name || '')}</strong></div>
						<div class="mm-text-muted" style="font-size:12px;">SKU: ${escapeHtml(it.sku || '—')} ${it.size ? ' | Size: ' + escapeHtml(it.size) : ''} | Qty: ${escapeHtml(it.qty || 1)}</div>
						${quickLink}
					</div>`;
				}).join('') : '<span class="mm-text-muted">No line items</span>';
				const rowStyle = o.sla_status === 'breached' ? ' style="background:#fff1f2;"' : '';
				const riskBadge = o.cod_risk === 'high' ? '<span class="mm-badge mm-badge-danger">RISK</span>' : '';
				return `<tr${rowStyle}>
					<td><strong>#${escapeHtml(o.wc_order_id)}</strong><div class="mm-text-muted" style="font-size:12px;">${escapeHtml(o.created_at || '')}</div></td>
					<td>${itemHtml}</td>
					<td>
						<div><strong>${escapeHtml(o.customer_name || '—')}</strong></div>
						<div style="font-size:12px;">${escapeHtml(o.phone || '')}</div>
						<div class="mm-text-muted" style="font-size:12px;">${escapeHtml((o.address || '').replace(/\s+/g, ' ').trim())}</div>
					</td>
					<td>
						<div>${escapeHtml(o.payment_method || '—')} ${riskBadge}</div>
						<div style="font-size:12px;" class="mm-text-muted">₹${escapeHtml(o.order_total || 0)}</div>
					</td>
					<td>
						<div><strong>${escapeHtml(o.fulfillment_status || '')}</strong></div>
						<div style="font-size:12px;" class="mm-text-muted">Meesho ID: ${escapeHtml(o.meesho_order_id || '—')}</div>
						<div style="font-size:12px;" class="mm-text-muted">Tracking: ${escapeHtml(o.tracking_id || '—')}</div>
					</td>
					<td>${escapeHtml(o.account_used || '—')}</td>
					<td>${o.sla_status === 'breached' ? '<span class="mm-badge mm-badge-danger">Breached</span>' : '<span class="mm-badge mm-badge-success">OK</span>'}</td>
					<td style="white-space:nowrap;">
						<button class="mm-btn mm-btn-sm mm-btn-outline" onclick="MeeshoMaster.openOrderEdit(${o.id})">✏️ Edit</button>
						<button class="mm-btn mm-btn-sm mm-btn-outline" onclick="MeeshoMaster.checkCODRisk(${o.wc_order_id})">🛡 COD</button>
					</td>
				</tr>`;
			});
			tbody.innerHTML = rows.join('');
		});
	};

	MM.openOrderEdit = function (id) {
		const modal = $('#order-edit-modal');
		if (!modal) return;
		const rows = $$('#orders_table_body tr');
		const target = rows.find((r) => r.querySelector('button[onclick*="openOrderEdit(' + id + ')"]'));
		if (!target) return MM.toast('Order row not found. Refresh and try again.', 'error');
		MM.orders.editing = id;
		const getText = (selector) => {
			const el = target.querySelector(selector);
			return el ? el.textContent.trim() : '';
		};
		($('#oe-order-id') || {}).textContent = String(id);
		($('#oe-meesho-id') || {}).value = (getText('td:nth-child(5) div:nth-child(2)').replace('Meesho ID: ', '').trim() || '');
		($('#oe-tracking') || {}).value = (getText('td:nth-child(5) div:nth-child(3)').replace('Tracking: ', '').trim() || '');
		($('#oe-status') || {}).value = (getText('td:nth-child(5) div:nth-child(1)').trim() || 'pending');
		const accountVal = getText('td:nth-child(6)').trim();
		const accountSelect = $('#oe-account');
		if (accountSelect) {
			ajaxPost('meesho_get_accounts').then((res) => {
				const accounts = (res.success && Array.isArray(res.data)) ? res.data : [];
				accountSelect.innerHTML = '<option value="">Select account...</option>' +
					accounts.map((a, idx) => `<option value="${escapeHtml(a.id || idx + 1)}">${escapeHtml(a.name || ('Account ' + (idx + 1)))}</option>`).join('');
				if (accountVal) {
					const match = Array.from(accountSelect.options).find((o) => o.textContent.trim() === accountVal || String(o.value) === accountVal);
					if (match) accountSelect.value = match.value;
				}
			});
		}
		modal.classList.add('active');
	};

	MM.submitOrderEdit = function () {
		const orderId = MM.orders.editing;
		if (!orderId) return MM.toast('No order selected.', 'error');
		const payload = {
			order_id: orderId,
			fulfillment_status: ($('#oe-status') || {}).value || 'pending',
			meesho_order_id: ($('#oe-meesho-id') || {}).value || '',
			tracking_id: ($('#oe-tracking') || {}).value || '',
			account_used: ($('#oe-account') || {}).value || '',
			notes: ($('#oe-notes') || {}).value || '',
		};
		ajaxPost('meesho_update_order', payload).then((res) => {
			if (!res.success) {
				MM.toast((res.data && res.data.message) ? res.data.message : 'Update failed.', 'error');
				return;
			}
			MM.toast('Order updated.', 'success');
			const modal = $('#order-edit-modal');
			if (modal) modal.classList.remove('active');
			MM.orders.editing = null;
			($('#oe-notes') || {}).value = '';
			MM.loadOrders(MM.orders.page);
		});
	};

	MM.checkCODRisk = function (wcOrderId) {
		ajaxPost('meesho_check_cod_risk', { wc_order_id: wcOrderId }).then((res) => {
			if (!res.success) {
				return MM.toast((res.data && res.data.message) ? res.data.message : 'Risk check failed.', 'error');
			}
			const reasons = (res.data && res.data.reasons) || [];
			if (!reasons.length) {
				MM.toast('COD risk: low.', 'success');
				return;
			}
			alert('High COD risk:\n\n- ' + reasons.join('\n- '));
		});
	};

	MM.backfillOrders = function () {
		if (!confirm('Backfill mm_orders from existing WooCommerce orders now?')) return;
		MM.toast('Backfilling orders…', 'info');
		ajaxPost('meesho_backfill_orders', { page: 1, limit: 200 }).then((res) => {
			if (!res.success) {
				return MM.toast((res.data && res.data.message) ? res.data.message : 'Backfill failed.', 'error');
			}
			const inserted = (res.data && res.data.inserted) || 0;
			const scanned = (res.data && res.data.scanned) || 0;
			const more = !!(res.data && res.data.has_more);
			const suffix = more ? ' Run again to process the next batch.' : '';
			MM.toast(`Backfill complete: ${inserted} inserted (${scanned} scanned).${suffix}`, 'success');
			MM.loadOrders(1);
		});
	};

	MM.loadOrderFailureLogs = function () {
		const wrap = $('#order_failure_logs_wrap');
		const body = $('#order_failure_logs_body');
		if (!wrap || !body) return;
		wrap.style.display = '';
		body.innerHTML = '<tr><td colspan="4" class="mm-text-muted" style="text-align:center;padding:16px;">Loading failure logs…</td></tr>';
		ajaxPost('mm_get_logs', { action_type: 'order_failure', source: 'auto', page: 1 }).then((res) => {
			if (!res.success) {
				body.innerHTML = '<tr><td colspan="4" class="mm-text-muted" style="text-align:center;padding:16px;">Failed to load failure logs.</td></tr>';
				return;
			}
			const logs = (res.data && res.data.logs) || [];
			if (!logs.length) {
				body.innerHTML = '<tr><td colspan="4" class="mm-text-muted" style="text-align:center;padding:16px;">No order failures logged yet.</td></tr>';
				return;
			}
			body.innerHTML = logs.map((log) => {
				let details = '';
				if (log.new_value) {
					try {
						const parsed = JSON.parse(log.new_value);
						details = parsed.error || parsed.context || '';
					} catch (e) {
						details = String(log.new_value).slice(0, 180);
					}
				}
				return `<tr>
					<td>${escapeHtml(log.created_at || '')}</td>
					<td>${escapeHtml(log.note || '')}</td>
					<td style="max-width:180px;word-break:break-word;">${escapeHtml(details || '—')}</td>
					<td style="max-width:240px;word-break:break-all;">${escapeHtml(log.actor || 'auto')}</td>
				</tr>`;
			}).join('');
		});
	};

	MM.toggleOrderFailureLogs = function () {
		const wrap = $('#order_failure_logs_wrap');
		if (!wrap) return;
		if (wrap.style.display === 'none' || !wrap.style.display) {
			MM.loadOrderFailureLogs();
		} else {
			wrap.style.display = 'none';
		}
	};

	MM.exportOrdersCsv = async function () {
		const pageLimit = 200;
		const csvEscape = (value) => {
			const str = value == null ? '' : String(value);
			const escaped = str.replace(/"/g, '""');
			if (/[",\n\r]/.test(escaped)) return `"${escaped}"`;
			return escaped;
		};
		let page = 1;
		let total = 0;
		let orders = [];
		while (true) {
			const res = await ajaxPost('meesho_get_orders', { page, limit: pageLimit, status: '', search: '' });
			if (!res.success) return MM.toast('Export failed.', 'error');
			const batch = (res.data && res.data.orders) || [];
			total = (res.data && res.data.total) || batch.length;
			orders = orders.concat(batch);
			if (!batch.length || orders.length >= total) break;
			page += 1;
		}
		if (!orders.length) return MM.toast('No orders to export.', 'info');
		const lines = ['wc_order_id,meesho_order_id,tracking_id,status,payment,total,customer,phone,created_at'];
		orders.forEach((o) => {
			lines.push([
				csvEscape(o.wc_order_id || ''),
				csvEscape(o.meesho_order_id || ''),
				csvEscape(o.tracking_id || ''),
				csvEscape(o.fulfillment_status || ''),
				csvEscape(o.payment_method || ''),
				csvEscape(o.order_total || 0),
				csvEscape(o.customer_name || ''),
				csvEscape(o.phone || ''),
				csvEscape(o.created_at || ''),
			].join(','));
		});
		const a = document.createElement('a');
		a.href = 'data:text/csv;charset=utf-8,' + encodeURIComponent(lines.join('\n'));
		a.download = 'meesho-orders.csv';
		a.click();
		MM.toast(`Exported ${orders.length} orders.`, 'success');
	};

	MM.taxonomies = { loaded: false, loading: null, categories: [], tags: [] };

	const renderTermOptions = (terms, selected) => {
		const selectedSet = new Set((selected || []).map((id) => String(id)));
		if (!Array.isArray(terms) || !terms.length) {
			return '<option value="">No terms found</option>';
		}
		return terms.map((t) => {
			const isSelected = selectedSet.has(String(t.id));
			return `<option value="${escapeHtml(t.id)}" ${isSelected ? 'selected' : ''}>${escapeHtml(t.name)}</option>`;
		}).join('');
	};

	const readMultiSelectValues = (el) => {
		if (!el) return [];
		return Array.from(el.selectedOptions || []).map((o) => parseInt(o.value, 10)).filter((v) => v);
	};

	MM.loadTaxonomies = function () {
		if (MM.taxonomies.loaded) {
			return Promise.resolve(MM.taxonomies);
		}
		if (MM.taxonomies.loading) {
			return MM.taxonomies.loading;
		}
		MM.taxonomies.loading = ajaxPost('mm_get_wc_taxonomies').then((res) => {
			if (res && res.success && res.data) {
				MM.taxonomies.categories = res.data.categories || [];
				MM.taxonomies.tags = res.data.tags || [];
				MM.taxonomies.loaded = true;
			} else {
				MM.taxonomies.categories = [];
				MM.taxonomies.tags = [];
			}
			return MM.taxonomies;
		});
		return MM.taxonomies.loading;
	};

	/* ============================================================
	 * Import tab — staging workflow
	 * ============================================================ */
	MM.bindImportTab = function () {
		const btnUrl = $('#btn_import_url');
		const btnHtml = $('#btn_import_html');
		const btnManual = $('#btn_manual_sku');
		const out = $('#import_results');
		const queueUrls = $('#mm_import_queue_urls');
		const queueAddBtn = $('#mm_import_queue_add_btn');
		const queueProcessBtn = $('#mm_import_queue_process_btn');
		const queueRefreshBtn = $('#mm_import_queue_refresh_btn');
		const queueStatus = $('#mm_import_queue_status');
		const queueList = $('#mm_import_queue_list');

		const renderQueue = (payload) => {
			if (!queueStatus || !queueList) return;
			const summary = (payload && payload.summary) || {};
			const items = (payload && payload.items) || [];
			queueStatus.textContent =
				`Total ${summary.total || 0} · Pending ${summary.pending || 0} · Processing ${summary.processing || 0} · Retry ${summary.retry || 0} · Done ${summary.done || 0} · Failed ${summary.failed || 0} · Duplicate ${summary.duplicate || 0}`;
			if (!items.length) {
				queueList.innerHTML = '<p class="mm-text-muted">Queue is empty.</p>';
				return;
			}
			queueList.innerHTML = '<table class="mm-table"><thead><tr><th>ID</th><th>URL</th><th>Status</th><th>Attempts</th><th>Last error</th></tr></thead><tbody>' +
				items.map((it) => `<tr>
					<td>${escapeHtml(it.id || '')}</td>
					<td style="max-width:360px;word-break:break-all;">${escapeHtml(it.url || '')}</td>
					<td>${escapeHtml(it.status || '')}</td>
					<td>${escapeHtml(it.attempts || 0)}</td>
					<td style="max-width:280px;word-break:break-word;">${escapeHtml(it.last_error || '')}</td>
				</tr>`).join('') +
				'</tbody></table>';
		};

		const refreshQueue = () => {
			if (!queueStatus || !queueList) return;
			queueStatus.textContent = 'Loading queue…';
			ajaxPost('mm_import_queue_status').then((res) => {
				if (!res.success) {
					queueStatus.textContent = 'Failed to load queue status.';
					return;
				}
				renderQueue(res.data || {});
			});
		};

		if (queueAddBtn) {
			queueAddBtn.addEventListener('click', () => {
				const urls = (queueUrls && queueUrls.value) ? queueUrls.value : '';
				if (!urls.trim()) return MM.toast('Add at least one URL to queue.', 'error');
				queueAddBtn.disabled = true;
				ajaxPost('mm_import_queue_add', { urls }).then((res) => {
					queueAddBtn.disabled = false;
					if (!res.success) return MM.toast('Failed to add queue items.', 'error');
					if (queueUrls) queueUrls.value = '';
					MM.toast(`Queue updated: ${res.data.added || 0} added, ${res.data.skipped || 0} skipped.`, 'success');
					renderQueue(res.data || {});
				});
			});
		}

		if (queueProcessBtn) {
			queueProcessBtn.addEventListener('click', () => {
				queueProcessBtn.disabled = true;
				queueProcessBtn.textContent = '⏳ Processing…';
				ajaxPost('mm_import_queue_process').then((res) => {
					queueProcessBtn.disabled = false;
					queueProcessBtn.textContent = '▶ Process Next';
					if (!res.success) {
						MM.toast('Queue processing failed.', 'error');
						return;
					}
					if (res.data && res.data.done) {
						MM.toast('Queue complete. No pending items.', 'info');
					} else {
						const item = (res.data && res.data.item) || {};
						MM.toast(`Processed #${item.id || ''}: ${item.status || 'done'}`, item.status === 'failed' ? 'error' : 'success');
					}
					renderQueue(res.data || {});
					setTimeout(() => MM.bindImportTab && MM.bindImportTab(), 500);
				});
			});
		}

		if (queueRefreshBtn) {
			queueRefreshBtn.addEventListener('click', refreshQueue);
		}
		if (queueStatus && queueList) {
			refreshQueue();
		}

		// Load recent staged products preview (v6.5 — full-card layout)
		const recentGrid = $('#mm_import_recent_grid');
		if (recentGrid) {
			ajaxPost('mm_list_staged').then((res) => {
				if (!res.success) { recentGrid.innerHTML = '<p class="mm-text-muted">No products staged yet.</p>'; return; }
				const items = (res.data || []).slice(0, 5);
				if (!items.length) {
					recentGrid.innerHTML = '<p class="mm-text-muted">No products staged yet. Scrape your first one above.</p>';
					return;
				}
				recentGrid.innerHTML = items.map((p) => {
					const ourPrice = p.our_price || (p.override_price && p.override_price > 0 ? p.override_price : (p.price || 0));
					const variations = (p.variation_rows || []).length ? p.variation_rows : normalizeVariationRows(p.sizes || [], p.sku || '');
					const sizes = variations.map((v) => v.size).filter(Boolean).join(', ') || '—';
					const stars = p.avg_rating ? '<span class="mm-recent-rating"><span class="star">★</span> ' + p.avg_rating + ' (' + (p.review_count || 0) + ')</span>' : '';
					const imgsHtml = (p.images_preview || []).slice(0, 4).map((u) => `<img src="${escapeHtml(u)}">`).join('');
					return `
					<div class="mm-recent-card">
						<img src="${escapeHtml(p.image || '')}" class="mm-recent-thumb" onerror="this.style.background='#f1f5f9';this.removeAttribute('src');">
						<div class="mm-recent-info">
							<h4>${escapeHtml(p.title || '(no title)')}</h4>
							${p.meesho_url ? `<a href="${escapeHtml(p.meesho_url)}" target="_blank" class="mm-recent-link">${escapeHtml(p.meesho_url)}</a>` : ''}
							<div class="mm-recent-row">
								<span><strong>Source:</strong> ₹${p.price || 0}</span>
								<span><strong>Our Price:</strong> <span class="mm-row-price-our">₹${ourPrice}</span></span>
								<span><strong>Rating:</strong> ${stars || '—'}</span>
							</div>
							<div class="mm-recent-row">
								<span><strong>Sizes:</strong> ${escapeHtml(sizes)}</span>
								<span><strong>Status:</strong> <span class="mm-status-badge mm-status-${p.status}">${p.status}</span></span>
								<span><strong>SKU:</strong> ${escapeHtml(p.sku)}</span>
							</div>
							<div class="mm-recent-row" style="font-size:12px;color:#64748b;">
								${p.images_count || 0} Images · ${p.review_count || 0} Reviews
							</div>
							<div class="mm-recent-actions">
								<button type="button" class="mm-btn mm-btn-ai mm-btn-sm" data-recent-ai="${p.id}">✨ AI Optimise</button>
								${p.status === 'staged' ? `<button type="button" class="mm-btn mm-btn-woo mm-btn-sm" data-recent-push="${p.id}">+ Import to Woo</button>` : ''}
								${p.wc_product_id ? `<a href="${escapeHtml(p.wc_listing_url || p.wc_edit_url || '#')}" target="_blank" class="mm-btn mm-btn-view mm-btn-sm">View listing</a>` : ''}
								${p.wc_product_id ? `<a href="${escapeHtml(p.wc_live_url || '#')}" target="_blank" class="mm-btn mm-btn-view mm-btn-sm">View LIVE</a>` : ''}
								<a href="?page=meesho-master&tab=products" class="mm-btn mm-btn-view mm-btn-sm">🔍 View All</a>
								<button type="button" class="mm-btn mm-btn-trash mm-btn-sm" data-recent-del="${p.id}" data-wc="${p.wc_product_id || 0}" title="Delete">🗑 Delete</button>
							</div>
							${imgsHtml ? `<div class="mm-recent-images-strip"><h5>Scraped Images</h5><div class="mm-recent-images-strip-grid">${imgsHtml}</div></div>` : ''}
							${(p.reviews_preview || []).length ? `<div class="mm-recent-reviews-strip"><h5>Reviews (marketplace refs removed)</h5>${p.reviews_preview.slice(0, 2).map((r) => `
								<div class="mm-recent-review">
									<div class="mm-recent-review-meta">
										<strong>${escapeHtml(r.reviewer_name || 'Customer')}</strong>
										<span>${'★'.repeat(parseInt(r.rating || 5))}${'☆'.repeat(5 - parseInt(r.rating || 5))}</span>
										<span>${escapeHtml(r.date || '')}</span>
									</div>
									<div>${escapeHtml(r.comment || '')}</div>
								</div>`).join('')}</div>` : ''}
						</div>
					</div>`;
				}).join('');
				recentGrid.querySelectorAll('[data-recent-push]').forEach((btn) => {
					btn.addEventListener('click', () => MM.pushProduct(btn.getAttribute('data-recent-push')));
				});
				recentGrid.querySelectorAll('[data-recent-ai]').forEach((btn) => {
					btn.addEventListener('click', () => {
						window.location.href = '?page=meesho-master&tab=products';
					});
				});
				recentGrid.querySelectorAll('[data-recent-del]').forEach((btn) => {
					btn.addEventListener('click', () => {
						const id  = btn.getAttribute('data-recent-del');
						const wc  = btn.getAttribute('data-wc');
						const hasWc = wc && wc !== '0';
						const msg = hasWc
							? 'Delete this product?\n\nOK = also move WooCommerce product to trash\nCancel = keep WC product'
							: 'Remove this staged product?';
						if (!confirm(msg)) return;
						ajaxPost('mm_delete_staged', { id, delete_wc: hasWc ? 1 : 0 }).then((res) => {
							MM.toast(res.success ? '🗑 Deleted.' : 'Delete failed.', res.success ? 'success' : 'error');
							if (res.success) MM.bindImportTab && MM.bindImportTab();
						});
					});
				});
			});
		}

		const showResult = (success, msg) => {
			if (!out) return;
			out.innerHTML = `<div class="mm-notice ${success ? 'mm-notice-success' : 'mm-notice-error'}">${msg}</div>`;
		};

		if (btnUrl) btnUrl.addEventListener('click', async () => {
			const url = ($('#meesho_url') || {}).value || '';
			if (!url.trim()) return MM.toast('Paste a Meesho URL first.', 'error');
			btnUrl.disabled = true;
			btnUrl.textContent = '⏳ Scraping…';
			const res = await ajaxPost('meesho_import_url', { url: url.trim() });
			btnUrl.disabled = false;
			btnUrl.textContent = '🚀 Scrape & Stage';
			if (res.success) {
				showResult(true, `✅ <strong>${escapeHtml(res.data.title || res.data.sku)}</strong> staged successfully. <a href="?page=meesho-master&tab=products">Open Products tab →</a>`);
				MM.toast('Scraped & staged. Refreshing recent…', 'success');
				setTimeout(() => MM.bindImportTab && MM.bindImportTab(), 600);
			} else {
				const msg = (res.data && res.data.message) ? res.data.message : (res.data || 'Failed');
				showResult(false, escapeHtml(msg));
				if (res.data && res.data.code === 'already_scraped') {
					MM.toast('Already in Products tab.', 'error');
				} else {
					MM.toast(msg, 'error');
				}
			}
		});

		if (btnHtml) btnHtml.addEventListener('click', async () => {
			const html = ($('#meesho_html') || {}).value || '';
			const product_url = ($('#meesho_url_for_html') || {}).value || '';
			if (!html.trim()) return MM.toast('Paste HTML source first.', 'error');
			btnHtml.disabled = true;
			btnHtml.textContent = '⏳ Parsing…';
			const res = await ajaxPost('meesho_import_html', { html, product_url });
			btnHtml.disabled = false;
			btnHtml.textContent = '📋 Parse HTML & Stage';
			if (res.success) {
				showResult(true, `✅ <strong>${escapeHtml(res.data.title || res.data.sku)}</strong> staged successfully. <a href="?page=meesho-master&tab=products">Open Products tab →</a>`);
				MM.toast('✅ Scraped successfully. Reloading recent…', 'success');
				// Refresh the Recently Scraped grid so the new item appears immediately
				setTimeout(() => MM.bindImportTab && MM.bindImportTab(), 600);
			} else {
				showResult(false, escapeHtml((res.data && res.data.message) ? res.data.message : (res.data || 'Failed')));
				MM.toast('❌ Scrape failed.', 'error');
			}
		});

		if (btnManual) btnManual.addEventListener('click', async () => {
			const sku = ($('#manual_sku_input') || {}).value || '';
			if (!sku.trim()) return MM.toast('Enter a numeric SKU.', 'error');
			const res = await ajaxPost('meesho_manual_sku', { sku, product_data: '{}' });
			showResult(res.success, escapeHtml((res.data && res.data.message) ? res.data.message : 'Done'));
		});
	};

	/* ============================================================
	 * Products tab
	 * ============================================================ */
	MM.products = { items: [], current: null };

	MM.loadProducts = function () {
		const grid = $('#mm_products_grid');
		if (!grid) return;
		grid.innerHTML = '<p class="mm-text-muted">Loading products…</p>';
		ajaxPost('mm_list_staged').then((res) => {
			if (!res.success) { grid.innerHTML = '<p class="mm-text-muted">Failed to load.</p>'; return; }
			MM.products.items = res.data || [];
			MM.renderProducts();
		});
	};

	MM.renderProducts = function () {
		const grid = $('#mm_products_grid');
		if (!grid) return;
		const filter = ($('#mm_products_filter') || {}).value || 'all';
		const search = (($('#mm_products_search') || {}).value || '').toLowerCase();
		const filtered = MM.products.items.filter((p) => {
			if (filter !== 'all' && p.status !== filter) return false;
			if (search && !((p.title || '').toLowerCase().includes(search) || String(p.sku).includes(search))) return false;
			return true;
		});
		if (!filtered.length) {
			grid.innerHTML = '<div class="mm-card"><div class="mm-empty-state">No products match. Use the Import tab to scrape your first one.</div></div>';
			return;
		}
		// Build table
		let html = '<table class="mm-products-table"><thead><tr>' +
			'<th>Image</th><th>Title</th><th>Source ₹</th><th>Our ₹</th>' +
			'<th>Sizes</th><th>Reviews</th><th>Status</th><th>Actions</th>' +
			'</tr></thead><tbody>';
		filtered.forEach((p) => {
			const variations = (p.variation_rows || []).length ? p.variation_rows : normalizeVariationRows(p.sizes || [], p.sku || '');
			const varSummary = variations.map((v) => `${v.size}:${v.sku || '-'}:${(v.stock || v.stock === 0) ? v.stock : '-'}:${(v.oos || v.available === false) ? 'OOS' : 'In'}`).join(' | ') || '—';
			const varShort = varSummary.length > 56 ? varSummary.substring(0, 56) + '…' : varSummary;
			const ourPrice = p.our_price || (p.override_price && p.override_price > 0 ? p.override_price : (p.price || 0));
			html += `<tr data-row-id="${p.id}">
				<td><img src="${escapeHtml(p.image || '')}" class="mm-row-thumb" onerror="this.style.background='#f1f5f9';this.removeAttribute('src');"></td>
				<td><a class="mm-row-title" data-mm-action="toggle" data-id="${p.id}">${escapeHtml(p.title || '(no title)')}</a><div style="color:#94a3b8;font-size:11px;margin-top:2px;">SKU ${escapeHtml(p.sku)}</div></td>
				<td><span class="mm-row-price-source">₹${p.price || 0}</span></td>
				<td><span class="mm-row-price-our">₹${ourPrice}</span></td>
				<td title="${escapeHtml(varSummary)}"><span class="mm-row-sizes">${escapeHtml(varShort)}</span></td>
				<td>${p.review_count || 0}${p.avg_rating ? ' ★' + p.avg_rating : ''}</td>
				<td><span class="mm-status-badge mm-status-${p.status}">${p.status}</span></td>
				<td><div class="mm-row-actions">
					<button type="button" class="mm-btn mm-btn-view" data-mm-action="toggle" data-id="${p.id}">View</button>
					<button type="button" class="mm-btn mm-btn-ai" data-mm-action="ai" data-id="${p.id}">✨ AI</button>
					${p.status === 'staged' ? `<button type="button" class="mm-btn mm-btn-woo" data-mm-action="push" data-id="${p.id}">+ Woo</button>` : ''}
					${p.wc_product_id ? `<a href="${escapeHtml(p.wc_listing_url || p.wc_edit_url || '#')}" target="_blank" class="mm-btn mm-btn-view">View listing</a>` : ''}
					${p.wc_product_id ? `<a href="${escapeHtml(p.wc_live_url || '#')}" target="_blank" class="mm-btn mm-btn-view">View LIVE</a>` : ''}
					<button type="button" class="mm-btn mm-btn-trash" data-mm-action="delete" data-id="${p.id}" data-wc="${p.wc_product_id || 0}">🗑</button>
				</div></td>
			</tr>
			<tr class="mm-product-edit-panel mm-hidden" data-panel-id="${p.id}">
				<td colspan="8"><div class="mm-product-edit-panel-inner" data-panel-content="${p.id}"><p class="mm-text-muted" style="grid-column:span 2;">Loading…</p></div></td>
			</tr>`;
		});
		html += '</tbody></table>';
		grid.innerHTML = html;

		grid.querySelectorAll('[data-mm-action]').forEach((btn) => {
			btn.addEventListener('click', (e) => {
				e.preventDefault();
				const action = btn.getAttribute('data-mm-action');
				const id = btn.getAttribute('data-id');
				if (action === 'toggle') MM.toggleEditPanel(id);
				else if (action === 'ai') { MM.openEditPanel(id, true); }
				else if (action === 'push') MM.pushProduct(id);
				else if (action === 'delete') MM.deleteProduct(id, btn.getAttribute('data-wc'));
			});
		});
	};

	MM.toggleEditPanel = function (id) {
		const panel = document.querySelector(`[data-panel-id="${id}"]`);
		if (!panel) return;
		if (panel.classList.contains('mm-hidden')) {
			MM.openEditPanel(id);
		} else {
			panel.classList.add('mm-hidden');
		}
	};

	MM.openEditPanel = function (id, scrollToAi) {
		const safeId = String(parseInt(id, 10) || 0);
		if (!safeId) return;
		// Close any other open panel first
		document.querySelectorAll('.mm-product-edit-panel').forEach((p) => {
			if (p.getAttribute('data-panel-id') !== safeId) p.classList.add('mm-hidden');
		});
		const panel = document.querySelector(`[data-panel-id="${safeId}"]`);
		const content = document.querySelector(`[data-panel-content="${safeId}"]`);
		if (!panel || !content) return;
		panel.classList.remove('mm-hidden');
		content.innerHTML = '<p class="mm-text-muted" style="grid-column:span 2;">Loading…</p>';
		Promise.all([ajaxPost('mm_get_staged', { id: safeId }), MM.loadTaxonomies()]).then(([res]) => {
			if (!res.success) { content.innerHTML = '<p>Load failed.</p>'; return; }
			MM.products.current = res.data;
			const d = res.data.data || {};
			const reviews = (d.reviews || []).slice(0, 20);
			const variationRows = normalizeVariationRows(d.sizes || [], res.data.sku || '');
			const categoryOptions = renderTermOptions(MM.taxonomies.categories, d.wc_categories || []);
			const tagOptions = renderTermOptions(MM.taxonomies.tags, d.wc_tags || []);
			const reviewsHtml = reviews.length ? reviews.map((r) => `
				<div class="mm-edit-review" data-mm-review-row="${safeId}">
					<div class="mm-edit-review-header">
						<input type="text" data-mm-review-name class="mm-input" value="${escapeHtml(r.reviewer_name || 'Customer')}" placeholder="Reviewer" style="max-width:180px;">
						<select data-mm-review-rating class="mm-input" style="max-width:90px;">
							<option value="5" ${parseInt(r.rating || 5) === 5 ? 'selected' : ''}>5★</option>
							<option value="4" ${parseInt(r.rating || 5) === 4 ? 'selected' : ''}>4★</option>
							<option value="3" ${parseInt(r.rating || 5) === 3 ? 'selected' : ''}>3★</option>
							<option value="2" ${parseInt(r.rating || 5) === 2 ? 'selected' : ''}>2★</option>
							<option value="1" ${parseInt(r.rating || 5) === 1 ? 'selected' : ''}>1★</option>
						</select>
					</div>
					<input type="text" data-mm-review-date class="mm-input mm-mt-10" value="${escapeHtml(r.date || '')}" placeholder="Date">
					<textarea data-mm-review-comment class="mm-textarea mm-mt-10" rows="2" placeholder="Review text">${escapeHtml(r.comment || '')}</textarea>
					<input type="text" data-mm-review-media class="mm-input mm-mt-10" value="${escapeHtml((r.media || []).join(', '))}" placeholder="Media URLs (comma-separated)">
				</div>
			`).join('') : '<p class="mm-text-muted">No reviews scraped.</p>';
			const variationRowsHtml = variationRows.length ? variationRows.map((v) => `
				<tr data-mm-var-row="${safeId}">
					<td><input type="text" data-mm-var-size class="mm-input" value="${escapeHtml(v.size || '')}" placeholder="Size"></td>
					<td><input type="text" data-mm-var-sku class="mm-input" value="${escapeHtml(v.sku || '')}" placeholder="SKU"></td>
					<td><input type="number" min="0" data-mm-var-stock class="mm-input" value="${escapeHtml(v.stock || v.stock === 0 ? v.stock : '')}" placeholder="Stock"></td>
					<td><input type="number" min="0" step="0.01" data-mm-var-price class="mm-input" value="${escapeHtml(v.price || '')}" placeholder="Price"></td>
					<td><input type="number" min="0" step="0.01" data-mm-var-mrp class="mm-input" value="${escapeHtml(v.mrp || '')}" placeholder="MRP"></td>
					<td style="text-align:center;"><input type="checkbox" data-mm-var-oos ${v.oos || v.available === false ? 'checked' : ''}></td>
				</tr>
			`).join('') : '';

			const attrsHtml = (d.attributes || []).length ? '<table style="width:100%;font-size:12px;">' +
				d.attributes.map((a) => `<tr><td style="padding:3px 6px;color:#64748b;">${escapeHtml(a.name)}</td><td style="padding:3px 6px;font-weight:500;">${escapeHtml(a.value)}</td></tr>`).join('') +
				'</table>' : '';

			content.innerHTML = `
				<div class="mm-edit-section">
					<h4>Title <span class="mm-text-muted" style="font-weight:normal;font-size:11px;">(SKU ${escapeHtml(res.data.sku)})</span></h4>
					<div class="mm-title-with-ai">
						<input type="text" class="mm-input" id="mm_field_title_${safeId}" value="${escapeHtml(d.title || '')}">
						<button type="button" class="mm-btn mm-btn-ai mm-btn-sm" data-mm-ai-title="${safeId}" title="Generate title using master prompt">✨ AI</button>
					</div>
					<div class="mm-grid mm-grid-2 mm-mt-10">
						<div><label class="mm-label">Source ₹</label><input type="number" step="0.01" class="mm-input" id="mm_field_price_${safeId}" value="${escapeHtml(d.price || '')}"></div>
						<div><label class="mm-label">Source MRP</label><input type="number" step="0.01" class="mm-input" id="mm_field_mrp_${safeId}" value="${escapeHtml(d.mrp || '')}"></div>
					</div>
					<div class="mm-card" style="background:#fff7ed;padding:10px;margin-top:10px;">
						<label class="mm-label">💰 Manual Override (overrides Settings markup rules)</label>
						<div class="mm-grid mm-grid-2">
							<input type="number" step="0.01" class="mm-input" id="mm_field_op_${safeId}" value="${escapeHtml(d.override_price || '')}" placeholder="Calculated: ₹${escapeHtml(res.data.our_price || d.price || 0)} — leave blank to use markup rules">
							<input type="number" step="0.01" class="mm-input" id="mm_field_om_${safeId}" value="${escapeHtml(d.override_mrp || '')}" placeholder="Override MRP">
						</div>
					</div>
					<div class="mm-card" style="background:#f8fafc;padding:10px;margin-top:10px;">
						<label class="mm-label">🏷 WooCommerce Categories</label>
						<select multiple id="mm_field_categories_${safeId}" class="mm-input mm-select" size="5">${categoryOptions}</select>
						<label class="mm-label mm-mt-10">🏷 WooCommerce Tags</label>
						<select multiple id="mm_field_tags_${safeId}" class="mm-input mm-select" size="5">${tagOptions}</select>
						<div class="mm-text-muted" style="font-size:11px;margin-top:6px;">Hold Ctrl/Cmd to select multiple.</div>
					</div>
					<label class="mm-label mm-mt-10">Description (HTML — same for all variations)</label>
					<textarea class="mm-textarea" id="mm_field_desc_${safeId}" rows="6">${escapeHtml(d.description || '')}</textarea>
					<button type="button" class="mm-btn mm-btn-ai mm-btn-sm mm-mt-10" data-mm-ai-desc="${safeId}">✨ AI Optimize Description</button>
					<div id="mm_optimize_status_${safeId}" class="mm-text-muted mm-mt-10" style="font-size:12px;"></div>
					<label class="mm-label mm-mt-10">Variations (Size / SKU / Stock / OOS)</label>
					<div style="overflow:auto;">
						<table style="width:100%;border-collapse:collapse;font-size:12px;">
							<thead><tr>
								<th style="text-align:left;padding:6px;border-bottom:1px solid #e2e8f0;">Size</th>
								<th style="text-align:left;padding:6px;border-bottom:1px solid #e2e8f0;">SKU</th>
								<th style="text-align:left;padding:6px;border-bottom:1px solid #e2e8f0;">Stock</th>
								<th style="text-align:left;padding:6px;border-bottom:1px solid #e2e8f0;">Price</th>
								<th style="text-align:left;padding:6px;border-bottom:1px solid #e2e8f0;">MRP</th>
								<th style="text-align:center;padding:6px;border-bottom:1px solid #e2e8f0;">OOS</th>
							</tr></thead>
							<tbody id="mm_var_table_${safeId}">${variationRowsHtml}</tbody>
						</table>
					</div>
					<div class="mm-mt-10" style="display:flex;gap:8px;align-items:center;">
						<button type="button" class="mm-btn mm-btn-outline mm-btn-sm" data-mm-var-add="${safeId}">+ Add Variation</button>
						<label style="display:flex;gap:6px;align-items:center;font-size:12px;color:#475569;">
							<input type="checkbox" id="mm_field_all_oos_${safeId}" ${d.all_out_of_stock ? 'checked' : ''}> Out of Stock for entire listing
						</label>
					</div>
				</div>
				<div class="mm-edit-section">
					<h4>📷 Images (${(d.images || []).length})</h4>
					<div class="mm-edit-images-grid">${(d.images || []).map((u) => `<img src="${escapeHtml(u)}">`).join('')}</div>
					<details class="mm-mt-10"><summary style="cursor:pointer;font-weight:600;">🎨 Generate AI Image</summary>
						<textarea class="mm-textarea mm-mt-10" id="mm_image_prompt_${safeId}" rows="2" placeholder="Leave blank to use master prompt with title auto-filled."></textarea>
						<button type="button" class="mm-btn mm-btn-ai mm-btn-sm mm-mt-10" data-mm-ai-image="${safeId}">🎨 Generate</button>
						<div id="mm_image_gen_status_${safeId}" class="mm-text-muted mm-mt-10" style="font-size:12px;"></div>
						<div id="mm_image_gen_result_${safeId}"></div>
					</details>
					${attrsHtml ? `<h4 class="mm-mt-10">📋 Product Attributes</h4>${attrsHtml}` : ''}
					<h4 class="mm-mt-10">⭐ Reviews (${d.reviews ? d.reviews.length : 0})</h4>
					<div class="mm-edit-reviews">${reviewsHtml}</div>
				</div>
				<div class="mm-edit-actions" style="grid-column:span 2;">
					<span class="mm-edit-status" id="mm_panel_status_${safeId}"></span>
					<button type="button" class="mm-btn mm-btn-outline" data-mm-cancel="${safeId}">Cancel</button>
					<button type="button" class="mm-btn mm-btn-primary" data-mm-save="${safeId}">💾 Save</button>
					<button type="button" class="mm-btn mm-btn-woo" data-mm-pushpanel="${safeId}">🚀 Push to WC</button>
				</div>`;

			content.querySelector(`[data-mm-cancel="${safeId}"]`).addEventListener('click', () => panel.classList.add('mm-hidden'));
			content.querySelector(`[data-mm-save="${safeId}"]`).addEventListener('click', () => MM.savePanel(safeId));
			content.querySelector(`[data-mm-pushpanel="${safeId}"]`).addEventListener('click', () => MM.savePanel(safeId, true));
			const aiTitle = content.querySelector(`[data-mm-ai-title="${safeId}"]`);
			if (aiTitle) aiTitle.addEventListener('click', () => MM.aiGenerateTitle(safeId));
			const aiDesc = content.querySelector(`[data-mm-ai-desc="${safeId}"]`);
			if (aiDesc) aiDesc.addEventListener('click', () => MM.aiOptimizeDesc(safeId));
			const aiImage = content.querySelector(`[data-mm-ai-image="${safeId}"]`);
			if (aiImage) aiImage.addEventListener('click', () => MM.aiGenerateImage(safeId));
			const addVar = content.querySelector(`[data-mm-var-add="${safeId}"]`);
			if (addVar) addVar.addEventListener('click', (e) => {
				e.preventDefault();
				const tbody = $(`#mm_var_table_${safeId}`);
				if (!tbody) return;
				const tr = document.createElement('tr');
				tr.setAttribute('data-mm-var-row', safeId);
				tr.innerHTML = `
					<td><input type="text" data-mm-var-size class="mm-input" placeholder="Size"></td>
					<td><input type="text" data-mm-var-sku class="mm-input" placeholder="SKU"></td>
					<td><input type="number" min="0" data-mm-var-stock class="mm-input" placeholder="Stock"></td>
					<td><input type="number" min="0" step="0.01" data-mm-var-price class="mm-input" placeholder="Price"></td>
					<td><input type="number" min="0" step="0.01" data-mm-var-mrp class="mm-input" placeholder="MRP"></td>
					<td style="text-align:center;"><input type="checkbox" data-mm-var-oos></td>
				`;
				tbody.appendChild(tr);
			});
			if (scrollToAi) {
				setTimeout(() => aiDesc && aiDesc.scrollIntoView({ behavior: 'smooth', block: 'center' }), 200);
			}
		});
	};

	MM.savePanel = function (id, thenPush) {
		const fields = {
			title: ($(`#mm_field_title_${id}`) || {}).value || '',
			price: parseFloat(($(`#mm_field_price_${id}`) || {}).value) || 0,
			mrp: parseFloat(($(`#mm_field_mrp_${id}`) || {}).value) || 0,
			description: ($(`#mm_field_desc_${id}`) || {}).value || '',
			sizes: readVariationRowsFromPanel(id),
			reviews: readReviewsFromPanel(id),
			override_price: parseFloat(($(`#mm_field_op_${id}`) || {}).value) || 0,
			override_mrp: parseFloat(($(`#mm_field_om_${id}`) || {}).value) || 0,
			all_out_of_stock: !!(($(`#mm_field_all_oos_${id}`) || {}).checked),
			wc_categories: readMultiSelectValues($(`#mm_field_categories_${id}`)),
			wc_tags: readMultiSelectValues($(`#mm_field_tags_${id}`)),
		};
		const status = $(`#mm_panel_status_${id}`);
		if (status) status.textContent = '⏳ Saving…';
		ajaxPost('mm_save_staged', { id, fields }).then((res) => {
			if (!res.success) {
				if (status) status.textContent = '❌ Save failed.';
				MM.toast('Save failed.', 'error');
				return;
			}
			if (status) status.textContent = '✅ Saved.';
			if (thenPush) {
				MM.pushProduct(id);
			} else {
				setTimeout(() => MM.loadProducts(), 400);
			}
		});
	};

	MM.aiGenerateTitle = function (id) {
		const titleField = $(`#mm_field_title_${id}`);
		const status = $(`#mm_panel_status_${id}`);
		if (!titleField) return;
		const current = titleField.value;
		if (status) status.textContent = '⏳ Generating title…';
		ajaxPost('mm_ai_generate_title', { current_title: current, sku: (MM.products.current && MM.products.current.sku) || '' }).then((res) => {
			if (res.success && res.data && res.data.title) {
				titleField.value = res.data.title;
				if (status) status.textContent = '✅ Title generated. Review + Save.';
			} else {
				if (status) status.textContent = '❌ ' + ((res.data && res.data.message) ? res.data.message : 'Failed');
			}
		});
	};

	MM.aiOptimizeDesc = function (id) {
		const descField = $(`#mm_field_desc_${id}`);
		const titleField = $(`#mm_field_title_${id}`);
		const status = $(`#mm_optimize_status_${id}`);
		if (!descField) return;
		if (status) status.textContent = '⏳ Optimizing…';
		ajaxPost('mm_optimize_description', {
			title: titleField.value,
			description: descField.value,
			preset: 'default',
		}).then((res) => {
			if (res.success && res.data && res.data.description) {
				descField.value = res.data.description;
				if (status) status.innerHTML = '✅ Optimized. Review + Save.';
			} else {
				if (status) status.textContent = '❌ ' + ((res.data && res.data.message) ? res.data.message : 'Failed');
			}
		});
	};

	MM.aiGenerateImage = function (id) {
		const safeId = String(parseInt(id, 10) || 0);
		const promptField = $(`#mm_image_prompt_${safeId}`);
		const status = $(`#mm_image_gen_status_${safeId}`);
		const out = $(`#mm_image_gen_result_${safeId}`);
		const titleField = $(`#mm_field_title_${safeId}`);
		const prompt = (promptField && promptField.value.trim()) || '';
		const title = (titleField && titleField.value) || '';
		if (status) status.textContent = '⏳ Generating image (30–60s)…';
		ajaxPost('mm_generate_image', { prompt, title }).then((res) => {
			if (res.success && res.data && res.data.image_url) {
				const url = res.data.image_url;
				if (status) status.innerHTML = '✅ Generated.';
				if (out) {
					out.innerHTML = '';
					const img = document.createElement('img');
					img.src = url;
					img.style.cssText = 'width:100%;max-width:280px;border-radius:6px;margin-top:8px;';
					const btn = document.createElement('button');
					btn.type = 'button';
					btn.className = 'mm-btn mm-btn-success mm-btn-sm mm-mt-10';
					btn.setAttribute('data-add-image', safeId);
					btn.textContent = '+ Add to Images';
					out.appendChild(img);
					out.appendChild(btn);
				}
				const addBtn = out && out.querySelector(`[data-add-image="${safeId}"]`);
				if (addBtn) addBtn.addEventListener('click', () => {
					ajaxPost('mm_get_staged', { id: safeId }).then((g) => {
						const data = (g.data && g.data.data) || {};
						const imgs = Array.isArray(data.images) ? data.images.slice() : [];
						imgs.push(url);
						ajaxPost('mm_save_staged', { id: safeId, fields: { images: imgs } }).then((s) => {
							MM.toast(s.success ? 'Image added.' : 'Failed.', s.success ? 'success' : 'error');
							if (s.success) MM.openEditPanel(safeId);
						});
					});
				});
			} else {
				if (status) status.textContent = '❌ ' + ((res.data && res.data.message) ? res.data.message : 'Failed');
			}
		});
	};

	MM.saveStaged = function () {
		const id = $('#mm_modal_id').value;
		if (!id) return;
		const fields = {
			title: $('#mm_modal_field_title').value,
			price: parseFloat($('#mm_modal_field_price').value) || 0,
			mrp: parseFloat($('#mm_modal_field_mrp').value) || 0,
			description: $('#mm_modal_field_description').value,
			sizes: $('#mm_modal_field_sizes').value.split(',').map((s) => s.trim()).filter(Boolean),
			override_price: parseFloat($('#mm_modal_field_override_price').value) || 0,
			override_mrp: parseFloat($('#mm_modal_field_override_mrp').value) || 0,
		};
		ajaxPost('mm_save_staged', { id, fields }).then((res) => {
			MM.toast(res.success ? 'Saved.' : 'Save failed.', res.success ? 'success' : 'error');
			if (res.success) MM.loadProducts();
		});
	};

	MM.pushProduct = function (id) {
		if (!confirm('Push this staged product to WooCommerce now? This will create a variable product with one variation per size.')) return;
		MM.toast('Pushing to WooCommerce…', 'info');
		ajaxPost('mm_push_to_wc', { id }).then((res) => {
			if (res.success) {
				MM.toast('Published! WC #' + (res.data.product_id || ''), 'success');
				MM.loadProducts();
			} else {
				const msg = (res.data && res.data.message) ? res.data.message : (res.data || 'unknown');
				MM.toast('Push failed: ' + msg, 'error');
			}
		});
	};

	MM.deleteProduct = function (id, wcId) {
		const hasWc = wcId && wcId !== '0';
		const trash = hasWc ? confirm('Also move the WooCommerce product to trash?\n\nOK = trash WC product\nCancel = keep WC product (just remove from plugin tracking)') : false;
		if (!confirm('Delete this product from the staging table?' + (hasWc && trash ? ' WC product will also be trashed.' : ''))) return;
		ajaxPost('mm_delete_staged', { id, delete_wc: trash ? 1 : 0 }).then((res) => {
			MM.toast(res.success ? 'Deleted.' : 'Delete failed.', res.success ? 'success' : 'error');
			if (res.success) MM.loadProducts();
		});
	};

	// Legacy stubs — modal removed in v6.5, kept so old callers don't crash
	MM.openProductModal = MM.openEditPanel;
	MM.closeProductModal = function () {};
	MM.saveStaged = function () {};
	MM.optimizeDescription = function () {};

	MM.bindProductsTab = function () {
		if (!$('#mm_products_grid')) return;
		MM.loadProducts();
		$('#mm_products_refresh') && $('#mm_products_refresh').addEventListener('click', MM.loadProducts);
		$('#mm_products_search') && $('#mm_products_search').addEventListener('input', MM.renderProducts);
		$('#mm_products_filter') && $('#mm_products_filter').addEventListener('change', MM.renderProducts);
	};

	/* ============================================================
	 * SEO tab — picker + suggestions queue
	 * ============================================================ */
	MM.seoTargets = { selected: [] };

	MM.initSeoPicker = function () {
		const search = $('#mm_target_search');
		const typeSel = $('#mm_target_post_type');
		const results = $('#mm_target_results');
		if (!search || !typeSel || !results) return;
		let timer = null;
		const run = () => {
			const q = search.value.trim();
			if (q.length < 2) { results.innerHTML = ''; return; }
			ajaxPost('mm_list_targetable_posts', { search: q, post_type: typeSel.value }).then((res) => {
				if (!res.success) { results.innerHTML = '<p class="mm-text-muted" style="padding:8px;">Search failed.</p>'; return; }
				const items = (res.data && res.data.posts) || [];
				if (!items.length) { results.innerHTML = '<p class="mm-text-muted" style="padding:8px;">No matches.</p>'; return; }
				results.innerHTML = items.map((p) => `
					<div style="padding:8px 12px; border-bottom:1px solid #f1f5f9; cursor:pointer; display:flex; justify-content:space-between; align-items:center;" data-pick-id="${p.id}" data-pick-title="${escapeHtml(p.title)}">
						<div>
							<strong>${escapeHtml(p.title)}</strong>
							<span class="mm-text-muted" style="font-size:11px;">[${p.type}] #${p.id}</span>
						</div>
						<div class="mm-text-muted" style="font-size:11px;">
							${p.seo_score !== null ? 'SEO ' + p.seo_score : 'never scanned'}
						</div>
					</div>`).join('');
				results.querySelectorAll('[data-pick-id]').forEach((row) => row.addEventListener('click', () => {
					const id = row.getAttribute('data-pick-id');
					const title = row.getAttribute('data-pick-title');
					if (!MM.seoTargets.selected.find((x) => x.id === id)) {
						MM.seoTargets.selected.push({ id, title });
						MM.renderSeoTargets();
					}
				}));
			});
		};
		search.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(run, 250); });
		typeSel.addEventListener('change', run);
		const clearBtn = $('#mm_target_clear_btn');
		if (clearBtn) clearBtn.addEventListener('click', () => {
			MM.seoTargets.selected = [];
			MM.renderSeoTargets();
		});
		const scanBtn = $('#mm_target_scan_btn');
		if (scanBtn) scanBtn.addEventListener('click', () => {
			const ids = MM.seoTargets.selected.map((s) => parseInt(s.id));
			if (!ids.length) return MM.toast('Pick at least one item.', 'error');
			MM.runSeoScan(ids);
		});
	};

	MM.renderSeoTargets = function () {
		const wrap = $('#mm_target_selected');
		if (!wrap) return;
		if (!MM.seoTargets.selected.length) {
			wrap.innerHTML = '<span class="mm-text-muted" style="font-size:12px;">No items selected — start typing in the search box.</span>';
			return;
		}
		wrap.innerHTML = MM.seoTargets.selected.map((s) =>
			`<span class="mm-status-badge" style="background:#e0f2fe; color:#075985; cursor:pointer;" data-rm="${s.id}">${escapeHtml(s.title)} #${s.id} ✕</span>`
		).join('');
		wrap.querySelectorAll('[data-rm]').forEach((el) => el.addEventListener('click', () => {
			const id = el.getAttribute('data-rm');
			MM.seoTargets.selected = MM.seoTargets.selected.filter((x) => x.id !== id);
			MM.renderSeoTargets();
		}));
	};

	MM.loadSuggestions = function () {
		// v6.5 — tab uses #seo_suggestions_body (was #suggestions_tbody — never matched).
		const tbody = $('#seo_suggestions_body') || $('#suggestions_tbody');
		if (!tbody) return;
		tbody.innerHTML = '<tr><td colspan="6" class="mm-text-muted" style="text-align:center;padding:20px;">Loading…</td></tr>';
		const priority = ($('#seo_priority_filter') || {}).value || '';
		const type = ($('#seo_type_filter') || {}).value || '';
		ajaxPost('meesho_get_suggestions', { priority, type }).then((res) => {
			if (!res.success) { tbody.innerHTML = '<tr><td colspan="6" class="mm-text-muted">Failed to load.</td></tr>'; return; }
			const rows = res.data || [];
			if (!rows.length) {
				tbody.innerHTML = '<tr><td colspan="6" class="mm-text-muted" style="text-align:center;padding:20px;">No pending suggestions. Run a scan from the Targeted Scan section above.</td></tr>';
				return;
			}
			tbody.innerHTML = rows.map((r) => {
				const editLink = r.post_id ? `<a href="${escapeHtml(r.edit_url || '#')}" target="_blank">${escapeHtml(r.post_title || ('#' + r.post_id))}</a>` : '—';
				return `<tr>
					<td><input type="checkbox" data-suggestion-id="${r.id}"></td>
					<td>${editLink}</td>
					<td>${escapeHtml(r.suggestion_type || '')}</td>
					<td>${escapeHtml((r.current_value || '').substring(0, 60))}</td>
					<td>${escapeHtml((r.suggested_value || '').substring(0, 80))}</td>
					<td>
						<button class="mm-btn mm-btn-sm mm-btn-success" onclick="MeeshoMaster.applySuggestion(${r.id})">Apply</button>
						<button class="mm-btn mm-btn-sm mm-btn-danger" onclick="MeeshoMaster.rejectSuggestion(${r.id})">Reject</button>
					</td>
				</tr>`;
			}).join('');
		});
	};

	// NOTE: loadScores, scanSelected, viewTrends, generateLLMs are defined below
	// in the v6.5 SEO fixes block (better implementations with edit/permalink links).

	// Targeted Scan dropdown loader — populates ALL pages/posts/products
	MM.loadAllTargetable = function () {
		const select = $('#mm_target_dropdown');
		if (!select) return;
		const type = ($('#mm_target_post_type') || {}).value || 'any';
		select.innerHTML = '<option>Loading…</option>';
		ajaxPost('mm_list_targetable_posts', { type, q: '', all: 1 }).then((res) => {
			if (!res.success) { select.innerHTML = '<option>Failed.</option>'; return; }
			const rows = res.data || [];
			if (!rows.length) { select.innerHTML = '<option>No items found.</option>'; return; }
			select.innerHTML = '<option value="">— Select an item to add —</option>' +
				rows.map((r) => `<option value="${r.id}" data-title="${escapeHtml(r.title)}" data-type="${escapeHtml(r.post_type)}">${escapeHtml(r.title)} <span style="opacity:0.6;">(${r.post_type})</span></option>`).join('');
		});
	};

	MM.applySuggestion = function (id) {
		ajaxPost('meesho_apply_suggestion', { id }).then((res) => {
			MM.toast(res.success ? 'Applied.' : 'Failed.', res.success ? 'success' : 'error');
			MM.loadSuggestions();
		});
	};

	MM.rejectSuggestion = function (id) {
		ajaxPost('meesho_reject_suggestion', { id }).then((res) => {
			MM.toast(res.success ? 'Rejected.' : 'Failed.', res.success ? 'success' : 'error');
			MM.loadSuggestions();
		});
	};

	MM.applyAllSafe = function () {
		if (!confirm('Apply all safe suggestions in bulk?')) return;
		ajaxPost('meesho_apply_all_safe').then((res) => {
			MM.toast(res.success ? ('Applied ' + (res.data.applied || 0)) : 'Failed.', res.success ? 'success' : 'error');
			MM.loadSuggestions();
		});
	};

	MM.bulkReject = function () {
		const ids = $$('[data-suggestion-id]:checked').map((cb) => cb.getAttribute('data-suggestion-id'));
		if (!ids.length) return MM.toast('Select rows first.', 'error');
		Promise.all(ids.map((id) => ajaxPost('meesho_reject_suggestion', { id }))).then(() => {
			MM.toast('Rejected ' + ids.length, 'success');
			MM.loadSuggestions();
		});
	};

	MM.toggleAllSuggestions = function () {
		const all = $('#select_all_suggestions');
		$$('[data-suggestion-id]').forEach((cb) => { cb.checked = !!(all && all.checked); });
	};

	MM.exportCSV = function () {
		ajaxPost('meesho_get_suggestions').then((res) => {
			if (!res.success) return MM.toast('Failed.', 'error');
			const rows = res.data || [];
			const csv = ['id,post_id,type,current,suggested,priority']
				.concat(rows.map((r) => [r.id, r.post_id, r.suggestion_type, JSON.stringify(r.current_value || ''), JSON.stringify(r.suggested_value || ''), r.priority].join(',')))
				.join('\n');
			const a = document.createElement('a');
			a.href = 'data:text/csv;charset=utf-8,' + encodeURIComponent(csv);
			a.download = 'mm-seo-suggestions.csv';
			a.click();
		});
	};

	MM.sortTable = function () { /* no-op stub for inline onclick — proper sort not yet implemented */ };

	/* ============================================================
	 * Logs tab
	 * ============================================================ */
	MM.loadLogs = function () {
		const tbody = $('#logs_table_body');
		if (!tbody) return;
		tbody.innerHTML = '<tr><td colspan="7" class="mm-text-muted" style="text-align:center;padding:20px;">Loading…</td></tr>';
		const actionType = ($('#log_type_filter') || {}).value || '';
		const source     = ($('#log_source_filter') || {}).value || '';
		const severity   = ($('#log_severity_filter') || {}).value || '';
		const q          = ($('#log_search_filter') || {}).value || '';
		const retention  = ($('#log_retention_filter') || {}).value || '0';
		ajaxPost('mm_get_logs', { action_type: actionType, source: source, severity, q, retention_days: retention }).then((res) => {
			if (!res.success) { tbody.innerHTML = '<tr><td colspan="7" class="mm-text-muted">Failed to load logs.</td></tr>'; return; }
			const rows = (res.data && res.data.logs) || [];
			if (!rows.length) { tbody.innerHTML = '<tr><td colspan="7" class="mm-text-muted" style="text-align:center;padding:20px;">No logs found.</td></tr>'; return; }
			tbody.innerHTML = rows.map((r) => {
				const postTitle = r.target_id ? `#${r.target_id}` : '—';
				const changes = (r.note || (r.new_value ? r.new_value.substring(0, 80) : '—'));
				const canUndo = r.undoable == '1' && r.undone != '1';
				return `<tr>
					<td>${escapeHtml(r.created_at || '')}</td>
					<td><span class="mm-badge mm-badge-${r.severity === 'high' ? 'danger' : (r.severity === 'medium' ? 'info' : 'success')}">${escapeHtml(r.severity || 'low')}</span></td>
					<td>${escapeHtml(r.action_type || '')}</td>
					<td>${escapeHtml(postTitle)}</td>
					<td>${escapeHtml(r.actor || r.source || '')}</td>
					<td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${escapeHtml(changes)}">${escapeHtml(changes)}</td>
					<td>${canUndo ? `<button class="mm-btn mm-btn-sm mm-btn-outline" onclick="ajaxPost&&ajaxPost('mm_undo_action',{log_id:${r.id}}).then(function(rr){MM.toast(rr.success?'Undone.':'Failed: '+((rr.data&&rr.data.message)?rr.data.message:''),rr.success?'success':'error');MM.loadLogs();})" >↩ Undo</button>` : (r.undone == '1' ? '<span style="color:#94a3b8;font-size:11px;">Undone</span>' : '—')}</td>
				</tr>`;
			}).join('');
		});
	};

	MM.exportLogsCsv = function () {
		const actionType = ($('#log_type_filter') || {}).value || '';
		const source     = ($('#log_source_filter') || {}).value || '';
		const severity   = ($('#log_severity_filter') || {}).value || '';
		const q          = ($('#log_search_filter') || {}).value || '';
		const retention  = ($('#log_retention_filter') || {}).value || '0';
		ajaxPost('mm_get_logs', { action_type: actionType, source: source, severity, q, retention_days: retention, export: 1, per_page: 200 })
			.then((res) => {
				if (!res.success || !res.data || !res.data.csv) return MM.toast('Export failed.', 'error');
				const a = document.createElement('a');
				a.href = 'data:text/csv;charset=utf-8,' + encodeURIComponent(res.data.csv);
				a.download = res.data.filename || 'meesho-audit-logs.csv';
				a.click();
				MM.toast('Logs exported.', 'success');
			});
	};

	/* ============================================================
	 * Analytics tab — rankings (stubbed-safe)
	 * ============================================================ */
	MM.loadRankings = function () {
		const tbody = $('#rankings_tbody');
		if (!tbody) return;
		tbody.innerHTML = '<tr><td colspan="6" class="mm-text-muted" style="text-align:center;padding:20px;">Loading…</td></tr>';
		ajaxPost('mm_get_rankings').then((res) => {
			if (!res.success) { tbody.innerHTML = '<tr><td colspan="6" class="mm-text-muted">No data.</td></tr>'; return; }
			const rows = (res.data && Array.isArray(res.data.rows)) ? res.data.rows : (Array.isArray(res.data) ? res.data : []);
			if (!rows.length) { tbody.innerHTML = '<tr><td colspan="6" class="mm-text-muted" style="text-align:center;padding:20px;">No rankings yet. Configure GSC in Settings.</td></tr>'; return; }
			tbody.innerHTML = rows.map((r) =>
				`<tr><td>${escapeHtml(r.keyword)}</td><td>${escapeHtml(r.page_url || r.page || '')}</td><td>${escapeHtml(r.position || '')}</td><td>${escapeHtml(r.delta || '—')}</td><td>${escapeHtml(r.impressions || '')}</td><td>${escapeHtml(r.ctr || '')}</td></tr>`
			).join('');
		});
	};

	MM.addKeyword = function (forceRefresh = false) {
		const input = $('#new_keyword');
		if (!input || !input.value.trim()) return MM.toast('Enter a keyword.', 'error');
		ajaxPost('mm_add_keyword', { keyword: input.value.trim(), force_refresh: forceRefresh ? 1 : 0 }).then((res) => {
			if (res.success) { input.value = ''; MM.loadRankings(); }
			else MM.toast('Failed.', 'error');
		});
	};

	MM.loadAnalyticsIntegrations = function () {
		const wrap = $('#mm_integrations_status');
		if (!wrap) return;
		wrap.innerHTML = '<p class="mm-text-muted">Loading integration status…</p>';
		ajaxPost('mm_get_integration_status').then((res) => {
			if (!res.success) {
				wrap.innerHTML = '<p class="mm-text-muted">Failed to load integration status.</p>';
				return;
			}
			const data = res.data || {};
			const available = data.available || {};
			const wc = data.woocommerce || {};
			const rows = [
				['WooCommerce', available.woocommerce ? 'Connected' : 'Not detected'],
				['Google Site Kit', available.site_kit ? 'Connected' : 'Not detected'],
				['RankMath', available.rankmath ? 'Connected' : 'Not detected'],
				['Google Search Console', data.gsc && data.gsc.available ? 'Configured' : 'Not configured'],
				['GA4', data.ga4 && data.ga4.available ? 'Configured' : 'Not configured'],
				['Meta', data.meta && data.meta.available ? 'Connected' : 'Not detected'],
			];
			wrap.innerHTML = `<div class="mm-card" style="padding:0;overflow:auto;">
				<table class="mm-table">
					<thead><tr><th>Integration</th><th>Status</th><th>Details</th></tr></thead>
					<tbody>
						${rows.map((r) => `<tr><td>${escapeHtml(r[0])}</td><td>${escapeHtml(r[1])}</td><td>${escapeHtml(r[0] === 'WooCommerce' ? ('Orders 30d: ' + (wc.orders_last_30d || 0) + ', Revenue 30d: ' + (wc.revenue_last_30d || 0)) : '—')}</td></tr>`).join('')}
					</tbody>
				</table>
			</div>`;
		});
	};

	MM.researchKeywords = function (postId) {
		const targetId = postId || ($('#seo_score_table_body tr') && $('#seo_score_table_body tr').getAttribute('data-post-id'));
		if (!targetId) return MM.toast('No post selected.', 'error');
		ajaxPost('mm_research_keywords', { post_id: targetId }).then((res) => {
			if (!res.success) return MM.toast('Research failed.', 'error');
			const keywords = Array.isArray(res.data) ? res.data : [];
			let c = $('#keyword_research_results');
			if (!c) {
				c = document.createElement('div');
				c.id = 'keyword_research_results';
				c.className = 'mm-card mm-mt-20';
				const host = $('#seo_score_table');
				if (host && host.parentElement) host.parentElement.appendChild(c);
			}
			c.innerHTML = '<h4>🔍 Keyword Research Results</h4>' + keywords.map((k) =>
				`<div class="mm-flex-between mm-mb-10"><span><strong>${escapeHtml(k.keyword)}</strong> <span class="mm-text-muted">(${escapeHtml(k.intent || 'unknown')}, relevance ${k.relevance || 0}%)</span></span><button class="mm-btn mm-btn-sm mm-btn-outline" onclick="MeeshoMaster.addKeywordFromResearch('${escapeHtml(k.keyword)}')">+ Add</button></div>`
			).join('');
		});
	};

	MM.addKeywordFromResearch = function (kw) {
		const input = $('#new_keyword');
		if (input) { input.value = kw; MM.addKeyword(); }
	};

	/* ============================================================
	 * Copilot tab
	 * ============================================================ */
	MM.appendCopilotMessage = function (role, html) {
		// v6.5 — tab uses #copilot_chat_history (was #copilot_history — never matched).
		const box = $('#copilot_chat_history') || $('#copilot_history');
		if (!box) return;
		const wrap = document.createElement('div');
		wrap.className = 'mm-copilot-msg mm-copilot-' + role;
		wrap.innerHTML = `<strong>${role === 'user' ? '👤 You' : '🤖 Copilot'}:</strong><div>${html}</div>`;
		wrap.style.cssText = 'padding:10px;margin:6px 0;border-radius:8px;background:' + (role === 'user' ? '#eff6ff' : '#f0fdf4') + ';';
		box.appendChild(wrap);
		box.scrollTop = box.scrollHeight;
	};

	MM.copilotAttachments = [];

	MM.sendCopilotMessage = function () {
		const input = $('#copilot_input');
		if (!input || !input.value.trim()) { MM.toast('Type a message first.', 'error'); return; }
		const msg = input.value.trim();
		// Build user bubble — show attachments too
		let userHtml = escapeHtml(msg);
		if (MM.copilotAttachments.length) {
			userHtml += MM.copilotAttachments.map((a) => a.is_image
				? `<br><img src="${escapeHtml(a.url)}" style="max-width:120px;max-height:80px;border-radius:4px;margin-top:4px;">`
				: `<br>📄 ${escapeHtml(a.name)}`).join('');
		}
		MM.appendCopilotMessage('user', userHtml);
		input.value = '';
		MM.appendCopilotMessage('bot', '<em>Thinking…</em>');
		const attachmentsSnapshot = MM.copilotAttachments.slice();
		ajaxPost('mm_copilot_chat', {
			message: msg,
			model: ($('#copilot_model_select') || {}).value || '',
			thread_key: currentCopilotThread,
			attachments: JSON.stringify(attachmentsSnapshot),
		}).then((res) => {
			const box = $('#copilot_chat_history') || $('#copilot_history');
			if (box && box.lastChild) box.lastChild.remove();
			if (!res.success) {
				MM.appendCopilotMessage('bot', '❌ ' + escapeHtml((res.data && res.data.message) ? res.data.message : 'Failed.'));
				return;
			}
			// Clear attachments after successful send
			MM.copilotAttachments = [];
			const attachmentsDiv = $('#copilot_attachments');
			if (attachmentsDiv) attachmentsDiv.innerHTML = '';
			currentCopilotThread = res.data.thread_key || currentCopilotThread;
			const actionsHtml = (res.data.actions || []).map((a) =>
				`<button class="mm-btn mm-btn-sm mm-btn-outline mm-mt-10" onclick="MeeshoMaster.applyCopilotAction(${escapeHtml(JSON.stringify(JSON.stringify(a)))})">Apply: ${escapeHtml(a.action || 'action')}</button>`
			).join(' ');
			MM.appendCopilotMessage('bot', escapeHtml(res.data.reply || '').replace(/\n/g, '<br>') + actionsHtml);
			if (currentCopilotThread) {
				ajaxPost('mm_copilot_queue_state', { thread_key: currentCopilotThread }).then((qs) => {
					if (!qs.success) return;
					const list = Object.values(qs.data || {});
					if (!list.length) return;
					const counts = list.reduce((acc, item) => {
						const k = item.state || 'queued';
						acc[k] = (acc[k] || 0) + 1;
						return acc;
					}, {});
					MM.appendCopilotMessage('bot', `<em>Queue:</em> pending ${counts.pending || 0}, approval ${counts.needs_approval || 0}, applied ${counts.applied || 0}, failed ${counts.failed || 0}.`);
				});
			}
		});
	};

	// v6.5 — Populate Copilot's model select with the same fetched OpenRouter list,
	// honoring the "free only" toggle, and selecting the saved default.
	MM._copilotAllModels = [];

	MM.loadCopilotModels = function () {
		const sel = $('#copilot_model_select');
		if (!sel) return;
		sel.innerHTML = '<option value="">⏳ Loading models…</option>';
		ajaxPost('mm_openrouter_models', { force: 0 }).then((res) => {
			if (!res.success) {
				sel.innerHTML = '<option value="">❌ Failed to load models — check OpenRouter API key in Settings</option>';
				return;
			}
			MM._copilotAllModels = (res.data && res.data.models) || [];
			const savedDefault = (res.data && res.data.assignments && res.data.assignments.copilot) || '';
			MM._renderCopilotModels(savedDefault);
		});
	};

	MM._renderCopilotModels = function (savedDefault) {
		const sel = $('#copilot_model_select');
		if (!sel) return;
		const freeCb = $('#copilot_free_only');
		const showFreeOnly = freeCb && freeCb.checked;
		const list = showFreeOnly ? MM._copilotAllModels.filter((m) => m.is_free) : MM._copilotAllModels;
		const current = savedDefault || sel.value || '';
		sel.innerHTML = '<option value="">— Use Settings default —</option>' +
			list.map((m) => `<option value="${escapeHtml(m.id)}" ${m.id === current ? 'selected' : ''}>${escapeHtml(m.id)}${m.is_free ? ' (free)' : ''}</option>`).join('');
	};

	MM.applyCopilotAction = function (actionJsonString) {
		let action;
		try { action = JSON.parse(actionJsonString); } catch (e) { return MM.toast('Bad action data.', 'error'); }
		ajaxPost('mm_copilot_apply', { action_data: JSON.stringify(action), approved: 1, thread_key: currentCopilotThread || '' }).then((res) => {
			MM.toast(res.success ? 'Applied.' : 'Failed.', res.success ? 'success' : 'error');
		});
	};

	/* ============================================================
	 * Settings — OpenRouter model fetch
	 * ============================================================ */
	MM.refreshOpenRouterModels = function (force) {
		const status = $('#mm_openrouter_status') || $('#mm_or_status');
		if (status) status.textContent = '⏳ Fetching models from OpenRouter…';
		ajaxPost('mm_openrouter_models', { force: force ? 1 : 0 }).then((res) => {
			if (!res.success) { if (status) status.textContent = '❌ ' + ((res.data && res.data.message) ? res.data.message : 'Failed.'); return; }
			const models = (res.data && res.data.models) || [];
			$$('select.mm-openrouter-select, select[data-mm-or-model]').forEach((sel) => {
				const current = sel.getAttribute('data-current') || sel.value;
				const showFreeOnly = ($('#mm_filter_free') || {}).checked;
				const list = showFreeOnly ? models.filter((m) => m.is_free) : models;
				sel.innerHTML = '<option value="">— select a model —</option>' + list.map((m) =>
					`<option value="${escapeHtml(m.id)}" ${m.id === current ? 'selected' : ''}>${m.is_free ? '🆓 ' : ''}${escapeHtml(m.name || m.id)}</option>`
				).join('');
			});
			if (status) status.textContent = `✅ Loaded ${models.length} models${res.data.from_cache ? ' (cached)' : ''}.`;
		});
	};

	/* ============================================================
	 * Boot
	 * ============================================================ */
	document.addEventListener('DOMContentLoaded', function () {
		MM.bindImportTab();
		MM.bindProductsTab();
		MM.initSeoPicker();
		// Auto-load suggestions if SEO tab is rendered
		if ($('#suggestions_tbody')) MM.loadSuggestions();
		// Logs tab auto-load
		if ($('#logs_table_body')) MM.loadLogs();
		// Orders tab auto-load
		if ($('#orders_table_body')) MM.loadOrders();
		// Settings — OpenRouter button (supports both old and new IDs)
		const orBtn = $('#mm_refresh_or_models') || $('#btn_refresh_openrouter_models');
		if (orBtn) orBtn.addEventListener('click', () => MM.refreshOpenRouterModels(true));
		// Free-only checkbox toggles a hidden input + re-renders dropdowns
		const freeCb = $('#mm_filter_free');
		if (freeCb) freeCb.addEventListener('change', () => {
			const hid = $('#mm_filter_free_hidden');
			if (hid) hid.value = freeCb.checked ? 'yes' : 'no';
			MM.refreshOpenRouterModels(false);
		});
		// Copilot send
		const sendBtn = $('#btn_copilot_send');
		if (sendBtn) sendBtn.addEventListener('click', MM.sendCopilotMessage);
		const copilotInput = $('#copilot_input');
		if (copilotInput) copilotInput.addEventListener('keydown', (e) => {
			if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); MM.sendCopilotMessage(); }
		});
		// D2 — load live models into copilot dropdown
		if ($('#copilot_model_select')) {
			MM.loadCopilotModels();
			const freeCbCopilot = $('#copilot_free_only');
			if (freeCbCopilot) freeCbCopilot.addEventListener('change', () => MM._renderCopilotModels(''));
		}
		// D4 — Copilot file upload
		const fileInput = $('#copilot_file_input');
		const attachmentsDiv = $('#copilot_attachments');
		if (fileInput) fileInput.addEventListener('change', () => {
			Array.from(fileInput.files).forEach((file) => {
				const fd = new FormData();
				fd.append('action', 'mm_copilot_upload_file');
				fd.append('nonce', meesho_ajax.nonce);
				fd.append('file', file);
				fetch(meesho_ajax.ajax_url, { method: 'POST', body: fd, credentials: 'same-origin' })
					.then((r) => r.json())
					.then((res) => {
						if (!res.success) { MM.toast('Upload failed: ' + (res.data && res.data.message ? res.data.message : 'Unknown error'), 'error'); return; }
						MM.copilotAttachments.push(res.data);
						const chip = document.createElement('div');
						chip.style.cssText = 'display:flex;align-items:center;gap:4px;background:#e0f2fe;padding:3px 8px;border-radius:12px;font-size:12px;';
						chip.innerHTML = (res.data.is_image
							? `<img src="${escapeHtml(res.data.url)}" style="width:24px;height:24px;object-fit:cover;border-radius:4px;">`
							: '📄') + ` ${escapeHtml(res.data.name)} <span data-rm-att="${res.data.attachment_id}" style="cursor:pointer;color:#ef4444;">✕</span>`;
						chip.querySelector('[data-rm-att]').addEventListener('click', () => {
							MM.copilotAttachments = MM.copilotAttachments.filter((a) => String(a.attachment_id) !== String(res.data.attachment_id));
							chip.remove();
						});
						if (attachmentsDiv) attachmentsDiv.appendChild(chip);
					})
					.catch(() => MM.toast('Upload failed.', 'error'));
			});
			fileInput.value = '';
		});
		// D3 — Undo history panel (last 25 actions, 7-day window)
		const undoBtn = $('#btn_copilot_undo');
		const undoPanel = $('#mm_undo_panel');
		if (undoBtn && undoPanel) {
			undoBtn.addEventListener('click', (e) => {
				e.stopPropagation();
				const visible = !undoPanel.classList.contains('mm-hidden');
				undoPanel.classList.toggle('mm-hidden');
				if (!visible) {
					const list = $('#mm_undo_list');
					list.innerHTML = 'Loading…';
					ajaxPost('mm_copilot_list_undo_history').then((res) => {
						if (!res.success || !res.data.length) {
							list.innerHTML = '<em>No undoable actions in the last 7 days.</em>';
							return;
						}
						list.innerHTML = res.data.map((r) => `
							<div style="display:flex;justify-content:space-between;align-items:center;padding:6px 0;border-bottom:1px solid #f1f5f9;opacity:${r.undone == '1' ? 0.4 : 1};">
								<div>
									<div style="font-size:13px;font-weight:600;">${escapeHtml(r.action_type)}</div>
									<div style="font-size:12px;color:#64748b;">${escapeHtml(r.label)} · ${escapeHtml(r.created_at)}</div>
								</div>
								${r.undone == '1'
									? '<span style="font-size:11px;color:#94a3b8;">Already undone</span>'
									: `<button class="mm-btn mm-btn-outline mm-btn-sm" data-undo-id="${r.id}">Undo</button>`
								}
							</div>`).join('');
						list.querySelectorAll('[data-undo-id]').forEach((b) => {
							b.addEventListener('click', () => {
								b.disabled = true;
								ajaxPost('mm_undo_action', { log_id: b.getAttribute('data-undo-id') }).then((r) => {
									MM.toast(r.success ? 'Action undone.' : 'Failed: ' + ((r.data && r.data.message) ? r.data.message : ''), r.success ? 'success' : 'error');
									undoPanel.classList.add('mm-hidden');
								});
							});
						});
					});
				}
			});
			document.addEventListener('click', () => undoPanel.classList.add('mm-hidden'));
		}
	});

	/* ============================================================
	 * v6.3 — Settings: Save button + API test buttons
	 * ============================================================ */
	MM.saveSettings = function () {
		const form = $('#meesho_settings_form');
		const status = $('#settings_save_status');
		if (!form) return;
		const fd = new FormData(form);
		fd.append('action', 'meesho_save_settings');
		fd.append('nonce', meesho_ajax.nonce);
		// Make sure unchecked checkboxes still send a value
		form.querySelectorAll('input[type=checkbox]').forEach((cb) => {
			if (!cb.checked && cb.name) fd.set(cb.name, '');
		});
		if (status) status.textContent = '⏳ Saving…';
		fetch(meesho_ajax.ajax_url, { method: 'POST', body: fd, credentials: 'same-origin' })
			.then((r) => r.json())
			.then((res) => {
				if (res.success) {
					if (status) status.textContent = '✅ Saved.';
					MM.toast('Settings saved.', 'success');
					setTimeout(() => { if (status) status.textContent = ''; }, 3000);
				} else {
					if (status) status.textContent = '❌ Failed.';
					MM.toast('Save failed: ' + ((res.data && res.data.message) ? res.data.message : 'unknown'), 'error');
				}
			})
			.catch((err) => {
				if (status) status.textContent = '❌ Network error.';
				MM.toast('Network error: ' + err.message, 'error');
			});
	};

	MM.testApi = function (service, btn) {
		const out = $('#api_test_results');
		if (!out) return;
		// Pull the corresponding input value (passed in same form row)
		let key = '';
		let extra = '';
		const row = btn.closest('.mm-form-row') || btn.parentElement;
		if (row) {
			const input = row.querySelector('input[type=password], input[type=text], textarea');
			if (input) key = input.value || '';
		}
		// Special case: dataforseo needs login + password
		if (service === 'dataforseo') {
			key = ($('input[name=dataforseo_login]') || {}).value || '';
			extra = ($('input[name=dataforseo_password]') || {}).value || '';
		}
		btn.disabled = true;
		const orig = btn.textContent;
		btn.textContent = '⏳ Testing…';
		ajaxPost('mm_test_api', { service, key, extra }).then((res) => {
			btn.disabled = false;
			btn.textContent = orig;
			const msg = (res.data && res.data.message) ? res.data.message : (res.data || 'No response');
			const detailsHtml = (res.success && res.data && res.data.details)
				? '<pre style="margin:6px 0 0; font-size:11px; white-space:pre-wrap;">' + escapeHtml(JSON.stringify(res.data.details, null, 2)) + '</pre>'
				: '';
			out.innerHTML = `<div class="mm-card" style="border-left:4px solid ${res.success ? '#10b981' : '#ef4444'};">
				<strong>${escapeHtml(service)}</strong>: ${escapeHtml(msg)}${detailsHtml}
			</div>`;
		});
	};

	/* ============================================================
	 * v6.3 — Image generation in Products modal
	 * ============================================================ */
	MM.generateImage = function () {
		if (!MM.products.current) return MM.toast('No product loaded.', 'error');
		const promptField = $('#mm_image_prompt');
		const status = $('#mm_image_gen_status');
		const out = $('#mm_image_gen_result');
		const prompt = (promptField && promptField.value.trim()) || '';
		const title = $('#mm_modal_field_title').value || '';
		if (status) status.textContent = '⏳ Generating image (this can take 30–60s)…';
		ajaxPost('mm_generate_image', { prompt, title }).then((res) => {
			if (res.success) {
				const url = res.data.image_url;
				if (status) status.innerHTML = '✅ Generated. <a href="' + escapeHtml(url) + '" target="_blank">Open ↗</a>';
				if (out) out.innerHTML = `<img src="${escapeHtml(url)}" style="max-width:100%; border-radius:6px; margin-top:8px;">
					<div class="mm-mt-10">
						<button type="button" class="mm-btn mm-btn-success mm-btn-sm" id="mm_image_use_btn">+ Add to Images</button>
					</div>`;
				const useBtn = $('#mm_image_use_btn');
				if (useBtn) useBtn.addEventListener('click', () => {
					// Append to scraped data images array via save_staged
					const id = $('#mm_modal_id').value;
					if (!id) return;
					ajaxPost('mm_get_staged', { id }).then((g) => {
						if (!g.success) return MM.toast('Failed to load product.', 'error');
						const data = g.data.data || {};
						const imgs = Array.isArray(data.images) ? data.images.slice() : [];
						imgs.push(url);
						const fields = { images: imgs };
						ajaxPost('mm_save_staged', { id, fields }).then((s) => {
							if (s.success) {
								MM.toast('Image added.', 'success');
								// Refresh modal images preview
								const imagesEl = $('#mm_modal_images');
								if (imagesEl) imagesEl.insertAdjacentHTML('beforeend', `<img src="${escapeHtml(url)}" class="mm-modal-image">`);
							} else {
								MM.toast('Failed to attach.', 'error');
							}
						});
					});
				});
			} else {
				if (status) status.textContent = '❌ ' + ((res.data && res.data.message) ? res.data.message : 'Failed');
			}
		});
	};

	/* ============================================================
	 * v6.3 — SEO diagnostics panel
	 * ============================================================ */
	MM.loadDiagnostics = function () {
		const wrap = $('#mm_seo_diagnostics');
		if (!wrap) return;
		ajaxPost('mm_settings_diagnostics').then((res) => {
			if (!res.success) return;
			const issues = (res.data && res.data.issues) || [];
			if (!issues.length) {
				wrap.innerHTML = '<div class="mm-card" style="border-left:4px solid #10b981;"><strong>✅ All systems go.</strong> No configuration issues detected.</div>';
				return;
			}
			wrap.innerHTML = '<div class="mm-card" style="border-left:4px solid #f59e0b;"><h4 style="margin:0 0 8px;">⚠️ Configuration issues</h4>' +
				issues.map((i) => {
					const color = i.severity === 'error' ? '#ef4444' : (i.severity === 'warning' ? '#f59e0b' : '#3b82f6');
					return `<div style="border-left:3px solid ${color}; padding:8px 12px; margin-bottom:8px; background:#fafafa;">
						<strong>[${escapeHtml(i.area)}]</strong> ${escapeHtml(i.message)}<br>
						<span class="mm-text-muted" style="font-size:12px;">→ ${escapeHtml(i.solution)}</span>
					</div>`;
				}).join('') + '</div>';
		});
	};

	/* ============================================================
	 * v6.3 — Bind v6.3 features after DOMContentLoaded
	 * ============================================================ */
	document.addEventListener('DOMContentLoaded', () => {
		// Save settings button (form submit)
		const saveBtn = $('#btn_save_settings');
		if (saveBtn) saveBtn.addEventListener('click', MM.saveSettings);
		const settingsForm = $('#meesho_settings_form');
		if (settingsForm) settingsForm.addEventListener('submit', (e) => { e.preventDefault(); MM.saveSettings(); });

		// API test buttons
		$$('.mm-test-api-btn').forEach((btn) => {
			btn.addEventListener('click', () => MM.testApi(btn.getAttribute('data-service'), btn));
		});

		// E2 — GA4 load traffic button
		const ga4Btn = $('#btn_load_ga4');
		if (ga4Btn) ga4Btn.addEventListener('click', () => {
			const range = ($('#ga4_range') || {}).value || '30';
			const content = $('#ga4_content');
			if (!content) return;
			ga4Btn.disabled = true;
			ga4Btn.textContent = '⏳ Loading…';
			content.innerHTML = '<p class="mm-text-muted" style="text-align:center;padding:20px;">Fetching GA4 data…</p>';
			ajaxPost('mm_fetch_ga4_data', { range }).then((res) => {
				ga4Btn.disabled = false;
				ga4Btn.textContent = 'Load Traffic Data';
				if (!res.success) {
					content.innerHTML = `<div class="mm-card" style="background:#fff1f2;border:1px solid #fecdd3;padding:12px;">${escapeHtml((res.data && res.data.message) ? res.data.message : 'Failed to load GA4 data.')}</div>`;
					return;
				}
				const rows = res.data.rows || [];
				const totals = { sessions: 0, users: 0, pageviews: 0, bounce: 0, bounceCount: 0 };
				rows.forEach((r) => {
					totals.sessions  += parseInt((r.metricValues || [])[0]?.value || 0, 10);
					totals.users     += parseInt((r.metricValues || [])[1]?.value || 0, 10);
					totals.pageviews += parseInt((r.metricValues || [])[2]?.value || 0, 10);
					const br = parseFloat((r.metricValues || [])[3]?.value || 0);
					if (br > 0) { totals.bounce += br; totals.bounceCount++; }
				});
				const avgBounce = totals.bounceCount ? (totals.bounce / totals.bounceCount * 100).toFixed(1) : '—';
				content.innerHTML = `
					<div class="mm-grid mm-grid-4 mm-mb-20" style="gap:12px;">
						<div class="mm-card" style="text-align:center;padding:12px;"><div style="font-size:24px;font-weight:700;color:#6C2EB9;">${totals.sessions.toLocaleString()}</div><div class="mm-text-muted" style="font-size:12px;">Sessions</div></div>
						<div class="mm-card" style="text-align:center;padding:12px;"><div style="font-size:24px;font-weight:700;color:#0ea5e9;">${totals.users.toLocaleString()}</div><div class="mm-text-muted" style="font-size:12px;">Active Users</div></div>
						<div class="mm-card" style="text-align:center;padding:12px;"><div style="font-size:24px;font-weight:700;color:#10b981;">${totals.pageviews.toLocaleString()}</div><div class="mm-text-muted" style="font-size:12px;">Page Views</div></div>
						<div class="mm-card" style="text-align:center;padding:12px;"><div style="font-size:24px;font-weight:700;color:#f59e0b;">${avgBounce}%</div><div class="mm-text-muted" style="font-size:12px;">Avg Bounce Rate</div></div>
					</div>
					<table class="mm-table"><thead><tr><th>Page Path</th><th>Device</th><th>Sessions</th><th>Users</th><th>Page Views</th><th>Bounce Rate</th></tr></thead><tbody>
					${rows.length ? rows.map((r) => {
						const dims = (r.dimensionValues || []);
						const mets = (r.metricValues || []);
						return `<tr><td>${escapeHtml(dims[0]?.value || '')}</td><td>${escapeHtml(dims[1]?.value || '')}</td><td>${escapeHtml(mets[0]?.value || '0')}</td><td>${escapeHtml(mets[1]?.value || '0')}</td><td>${escapeHtml(mets[2]?.value || '0')}</td><td>${(parseFloat(mets[3]?.value || 0) * 100).toFixed(1)}%</td></tr>`;
					}).join('') : '<tr><td colspan="6" class="mm-text-muted" style="text-align:center;padding:20px;">No data returned. Verify Property ID and permissions.</td></tr>'}
					</tbody></table>`;
			});
		});
		const ga4ForceBtn = $('#btn_load_ga4_force');
		if (ga4ForceBtn) ga4ForceBtn.addEventListener('click', () => {
			const range = ($('#ga4_range') || {}).value || '30';
			ajaxPost('mm_fetch_ga4_data', { range, force_refresh: 1 }).then((res) => {
				if (!res.success) return MM.toast((res.data && res.data.message) ? res.data.message : 'Force refresh failed.', 'error');
				MM.toast('GA4 cache refreshed.', 'success');
				const btn = $('#btn_load_ga4');
				if (btn) btn.click();
			});
		});
		const addKeywordBtn = $('#btn_add_keyword');
		if (addKeywordBtn) addKeywordBtn.addEventListener('click', () => MM.addKeyword(false));
		const refreshRankingsBtn = $('#btn_refresh_rankings');
		if (refreshRankingsBtn) refreshRankingsBtn.addEventListener('click', () => {
			const input = $('#new_keyword');
			if (input && input.value && input.value.trim()) return MM.addKeyword(true);
			MM.loadRankings();
		});
		const refreshIntegrationsBtn = $('#btn_refresh_integrations');
		if (refreshIntegrationsBtn) refreshIntegrationsBtn.addEventListener('click', MM.loadAnalyticsIntegrations);
		if ($('#mm_integrations_status')) MM.loadAnalyticsIntegrations();

		// E2 — GA4 mode toggle in settings tab
		const ga4Radios = $$('input[name="mm_ga4_mode"]');
		ga4Radios.forEach((r) => r.addEventListener('change', () => {
			const saRow = $('#mm_ga4_sa_row');
			if (saRow) saRow.style.display = r.value === 'service_account' ? '' : 'none';
		}));

		// F3 — GSC mode toggle in settings tab
		const gscRadios = $$('input[name="mm_gsc_mode"]');
		gscRadios.forEach((r) => r.addEventListener('change', () => {
			const saRow = $('#mm_gsc_sa_row');
			if (saRow) saRow.style.display = r.value === 'service_account' ? '' : 'none';
		}));

		// F2 — Email recipients inline test button
		const testRecipientsBtn = $('#btn_test_email_recipients');
		if (testRecipientsBtn) testRecipientsBtn.addEventListener('click', () => {
			const input = $('input[name="email_recipients"]');
			const recipients = input ? input.value.trim() : '';
			if (!recipients) { MM.toast('Enter at least one recipient email first.', 'error'); return; }
			testRecipientsBtn.disabled = true;
			ajaxPost('meesho_test_email', { recipients }).then((res) => {
				testRecipientsBtn.disabled = false;
				MM.toast(res.success ? '✅ Test email sent to: ' + recipients : ('❌ ' + ((res.data && res.data.message) ? res.data.message : 'Failed')), res.success ? 'success' : 'error');
			});
		});

		// Test email button
		const testEmailBtn = $('#btn_test_email');
		if (testEmailBtn) testEmailBtn.addEventListener('click', () => {
			ajaxPost('meesho_test_email').then((res) => {
				MM.toast(res.success ? 'Test email sent.' : ('Failed: ' + ((res.data && res.data.message) ? res.data.message : '')), res.success ? 'success' : 'error');
			});
		});

		// v6.4 — Repair Database button
		const repairBtn = $('#btn_repair_db');
		if (repairBtn) repairBtn.addEventListener('click', () => {
			if (!confirm('This will reinstall all plugin database tables. Existing data is preserved. Continue?')) return;
			repairBtn.disabled = true;
			const orig = repairBtn.textContent;
			repairBtn.textContent = '⏳ Repairing…';
			ajaxPost('mm_repair_database').then((res) => {
				repairBtn.disabled = false;
				repairBtn.textContent = orig;
				const msg = (res.data && res.data.message) ? res.data.message : (res.data || '—');
				MM.toast(msg, res.success ? 'success' : 'error');
				const out = $('#api_test_results');
				if (out) {
					const rows = (res.data && res.data.tables) || {};
					out.innerHTML = '<div class="mm-card"><h4>Database table status</h4><table style="width:100%; border-collapse:collapse;">' +
						Object.keys(rows).map(k => `<tr><td style="padding:4px 8px;">${escapeHtml(k)}</td><td style="padding:4px 8px;">${rows[k] ? '✅' : '❌'}</td></tr>`).join('') +
						'</table></div>';
				}
			});
		});

		// Generate llms.txt button
		const llmsBtn = $('#btn_generate_llms');
		if (llmsBtn) llmsBtn.addEventListener('click', () => {
			MM.saveSettings();
			setTimeout(() => {
				ajaxPost('meesho_generate_llms_txt').then((res) => {
					MM.toast(res.success ? 'llms.txt written.' : 'Failed.', res.success ? 'success' : 'error');
					if (res.success && $('#llms_preview')) {
						$('#llms_preview').textContent = (res.data && res.data.content) || $('#llms_preview').textContent;
					}
				});
			}, 600);
		});

		// SEO diagnostics — auto-load if panel present
		MM.loadDiagnostics();

		// Image generation button in product modal
		const imgBtn = $('#mm_image_generate_btn');
		if (imgBtn) imgBtn.addEventListener('click', MM.generateImage);

	});
	/* ============================================================
	 * v6.5 — SEO fixes (loadScores, scanSelected, viewTrends, generateLLMs)
	 * ============================================================ */

	// Fix the body ID mismatch first — old loadSuggestions uses `suggestions_tbody`
	// which doesn't exist in the rendered HTML (real id is `seo_suggestions_body`).
	MM.loadSuggestions = function () {
		const body = $('#seo_suggestions_body');
		if (!body) return;
		body.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:20px;color:#64748b;">Loading…</td></tr>';
		const filters = {
			priority: ($('#seo_priority_filter') || {}).value || '',
			type:     ($('#seo_type_filter') || {}).value || '',
			score:    ($('#seo_score_filter') || {}).value || '',
		};
		ajaxPost('meesho_get_suggestions', filters).then((res) => {
			if (!res.success || !res.data || !res.data.suggestions) {
				body.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:20px;color:#64748b;">No suggestions yet. Run a scan first.</td></tr>';
				return;
			}
			const items = res.data.suggestions;
			if (!items.length) {
				body.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:20px;color:#64748b;">Queue is empty. Run a SEO scan to populate.</td></tr>';
				return;
			}
			body.innerHTML = items.map((s) => `<tr>
				<td><input type="checkbox" data-sug-id="${s.id}"></td>
				<td><a href="${escapeHtml(s.edit_url || '#')}" target="_blank">${escapeHtml(s.post_title || '#' + s.post_id)}</a></td>
				<td>${escapeHtml(s.suggestion_type || '')}</td>
				<td><span class="mm-priority-${escapeHtml(s.priority)}">${escapeHtml(s.priority || '')}</span></td>
				<td style="font-size:12px;">${escapeHtml((s.suggestion_text || '').substring(0, 200))}</td>
				<td>
					<button class="mm-btn mm-btn-success mm-btn-sm" data-apply-sug="${s.id}">Apply</button>
					<button class="mm-btn mm-btn-outline mm-btn-sm" data-reject-sug="${s.id}">Reject</button>
				</td>
			</tr>`).join('');
			body.querySelectorAll('[data-apply-sug]').forEach((b) => {
				b.addEventListener('click', () => {
					ajaxPost('meesho_apply_suggestion', { suggestion_id: b.getAttribute('data-apply-sug') }).then((r) => {
						MM.toast(r.success ? 'Applied.' : 'Failed.', r.success ? 'success' : 'error');
						MM.loadSuggestions();
					});
				});
			});
			body.querySelectorAll('[data-reject-sug]').forEach((b) => {
				b.addEventListener('click', () => {
					ajaxPost('meesho_reject_suggestion', { suggestion_id: b.getAttribute('data-reject-sug') }).then((r) => {
						MM.toast(r.success ? 'Rejected.' : 'Failed.', r.success ? 'success' : 'error');
						MM.loadSuggestions();
					});
				});
			});
		});
	};

	// Populate the Dashboard table from seo_post_scores
	MM.loadScores = function () {
		const body = $('#seo_score_table_body');
		if (!body) return;
		body.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:20px;color:#64748b;">Loading…</td></tr>';
		ajaxPost('mm_seo_list_scores').then((res) => {
			if (!res.success || !res.data || !res.data.length) {
				body.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:20px;color:#64748b;">No scored posts yet. Run a scan to populate.</td></tr>';
				return;
			}
			body.innerHTML = res.data.map((row) => `<tr>
				<td>${row.post_id}</td>
				<td><a href="${escapeHtml(row.edit_url || '#')}" target="_blank">${escapeHtml(row.title || '#' + row.post_id)}</a><br><a href="${escapeHtml(row.permalink || '#')}" target="_blank" style="font-size:11px;color:#64748b;">${escapeHtml(row.permalink || '')}</a></td>
				<td>${row.seo_score !== null ? row.seo_score : '—'}</td>
				<td>${row.aeo_score !== null ? row.aeo_score : '—'}</td>
				<td>${row.geo_score !== null ? row.geo_score : '—'}</td>
				<td>
					<span class="mm-text-muted" style="font-size:11px;">${row.suggestion_count || 0} suggestions</span>
					<button class="mm-btn mm-btn-outline mm-btn-sm" data-rescan="${row.post_id}">↻ Rescan</button>
				</td>
			</tr>`).join('');
			body.querySelectorAll('[data-rescan]').forEach((b) => {
				b.addEventListener('click', () => {
					MM.runSeoScan([parseInt(b.getAttribute('data-rescan'))]);
				});
			});
		});
	};

	// Run a scan against an explicit post-id list (or full priority list when empty)
	MM.runSeoScan = function (postIds) {
		const status = $('#mm_target_status');
		if (status) status.innerHTML = '⏳ Running scan… (this can take 30s–2min depending on AI latency)';
		ajaxPost('meesho_run_seo_crawl', { post_ids: postIds || [] }).then((res) => {
			if (!res.success) {
				const msg = (res.data && res.data.message) ? res.data.message : (typeof res.data === 'string' ? res.data : 'Scan failed.');
				if (status) status.innerHTML = '❌ ' + escapeHtml(msg);
				MM.toast(msg, 'error');
				return;
			}
			const d = res.data || {};
			// res.data shape from run_scan: { processed, created, applied, failed, errors }
			const summary = `✅ Scan complete. Processed ${d.processed || 0}, ${d.created || 0} suggestions added, ${d.failed || 0} failed.`;
			if (status) status.innerHTML = summary;
			MM.toast(summary, 'success');
			MM.loadScores();
			MM.loadSuggestions();
		});
	};

	MM.scanSelected = function (forcedIds) {
		// forcedIds: array of post IDs (e.g. from Re-scan button in dashboard table)
		// Otherwise reads from the targeted scan picker
		let ids = Array.isArray(forcedIds) && forcedIds.length ? forcedIds.map(Number) : [];
		if (!ids.length) {
			ids = (MM.seoTargets && MM.seoTargets.selected) ? MM.seoTargets.selected.map((t) => t.id) : [];
		}
		if (!ids.length) {
			// Fallback: read from any selected checkboxes or targeted list
			document.querySelectorAll('#mm_target_selected [data-target-id]').forEach((el) => {
				ids.push(parseInt(el.getAttribute('data-target-id'), 10));
			});
		}
		if (!ids.length) {
			MM.toast('Pick at least one item from the Targeted Scan picker first.', 'error');
			return;
		}
		MM.runSeoScan(ids);
	};

	MM.viewTrends = function () {
		const out = $('#mm_target_status');
		ajaxPost('mm_seo_score_trends').then((res) => {
			if (!res.success) { MM.toast('Trends fetch failed.', 'error'); return; }
			const trends = res.data || [];
			if (!trends.length) {
				if (out) out.innerHTML = '<em>No trend data yet — runs are recorded as you scan over time.</em>';
				MM.toast('No trend data yet — run a few scans first.', 'info');
				return;
			}
			let html = '<h4>📊 Score Trends (last 30 days)</h4><table style="width:100%;border-collapse:collapse;">' +
				'<thead><tr style="background:#f8fafc;"><th style="text-align:left;padding:6px;">Date</th><th style="padding:6px;">Avg SEO</th><th style="padding:6px;">Avg AEO</th><th style="padding:6px;">Avg GEO</th><th style="padding:6px;">Pages</th></tr></thead><tbody>';
			trends.forEach((t) => {
				html += `<tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:6px;">${escapeHtml(t.day)}</td><td style="padding:6px;text-align:center;">${t.avg_seo}</td><td style="padding:6px;text-align:center;">${t.avg_aeo}</td><td style="padding:6px;text-align:center;">${t.avg_geo}</td><td style="padding:6px;text-align:center;">${t.pages}</td></tr>`;
			});
			html += '</tbody></table>';
			if (out) out.innerHTML = html;
		});
	};

	MM.generateLLMs = function () {
		ajaxPost('meesho_generate_llms_txt').then((res) => {
			MM.toast(res.success ? 'llms.txt written.' : 'Failed.', res.success ? 'success' : 'error');
			const pre = $('#llms_preview');
			if (pre && res.success && res.data && res.data.content) {
				pre.style.display = 'block';
				pre.textContent = res.data.content;
			}
		});
	};

	// Add to MM.bindSeoTab if it exists, else create
	const _origBindSeo = MM.bindSeoTab;
	MM.bindSeoTab = function () {
		if (typeof _origBindSeo === 'function') _origBindSeo();
		// Auto-load dashboard + suggestions
		MM.loadScores();
		MM.loadSuggestions();
		// Auto-load dropdown (full list of pages/posts/products)
		if (typeof MM.loadAllTargetable === 'function') MM.loadAllTargetable();
		// Re-load dropdown when post type changes
		const typeSel = $('#mm_target_post_type');
		if (typeSel) typeSel.addEventListener('change', () => {
			if (typeof MM.loadAllTargetable === 'function') MM.loadAllTargetable();
		});
		// Dropdown change → push into selected list
		const dropdown = $('#mm_target_dropdown');
		if (dropdown) dropdown.addEventListener('change', () => {
			const opt = dropdown.options[dropdown.selectedIndex];
			const id = parseInt(opt.value);
			if (!id) return;
			const title = opt.getAttribute('data-title') || opt.textContent;
			if (!MM.seoTargets) MM.seoTargets = { selected: [] };
			if (!MM.seoTargets.selected.find((x) => x.id === id)) {
				MM.seoTargets.selected.push({ id, title });
				MM.renderSeoTargets && MM.renderSeoTargets();
			}
			dropdown.selectedIndex = 0;
		});
		// Trends button (if not wired by inline onclick)
		const trendsButtons = document.querySelectorAll('button[onclick*="viewTrends"]');
		trendsButtons.forEach((b) => { b.removeAttribute('onclick'); b.addEventListener('click', MM.viewTrends); });
		// Manual scan button (the "Full Scan Now" that produced "[object Object]")
		const fullScanBtns = document.querySelectorAll('button[onclick*="mm_run_seo_scan"]');
		fullScanBtns.forEach((b) => { b.removeAttribute('onclick'); b.addEventListener('click', () => MM.runSeoScan([])); });
	};

	/* ============================================================
	 * v6.5.1 — Copilot: IDs corrected (copilot_input / copilot_chat_history)
	 * The original sendCopilotMessage already handles message display correctly.
	 * This override is now a no-op passthrough.
	 * ============================================================ */

	/* ============================================================
	 * v6.5.1 — Full Scan Now (fixed)
	 * ============================================================ */
	MM.fullScanNow = function () {
		const status = $('#mm_target_status');
		if (status) status.textContent = '⏳ Running full priority scan… (may take 1–3 min)';
		ajaxPost('meesho_run_seo_crawl', { post_ids: [] }).then((res) => {
			if (res.success) {
				const r = res.data || {};
				const msg = `✅ Full scan done: ${r.processed || 0} pages processed, ${r.created || 0} suggestions created, ${r.applied || 0} auto-applied.`;
				if (status) status.textContent = msg;
				MM.toast(msg, 'success');
				if (typeof MM.loadSuggestions === 'function') MM.loadSuggestions();
				if (typeof MM.loadScores === 'function') MM.loadScores();
			} else {
				const err = (res.data && res.data.message) ? res.data.message : (typeof res.data === 'string' ? res.data : 'Scan failed.');
				if (status) status.textContent = '❌ ' + err;
				MM.toast('Scan failed: ' + err, 'error');
			}
		});
	};

	/* ============================================================
	 * v6.5.1 — Blog tab JS (was missing — buttons did nothing)
	 * ============================================================ */
	MM.bindBlogsTab = function () {
		const generateBtn = $('#mm_blog_generate_btn');
		const saveBtn     = $('#mm_blog_save_btn');
		const statusEl    = $('#mm_blog_status');
		const draftsEl    = $('#mm_blog_drafts');
		const postStatusEl = $('#mm_blog_status_select');
		const scheduleWrapEl = $('#mm_blog_schedule_wrap');
		const scheduleInputEl = $('#mm_blog_schedule_at');
		const qualityEl = $('#mm_blog_quality_report');
		const saveBtnDefault = saveBtn ? saveBtn.textContent : '💾 Save Post';
		// 3s keeps feedback lively without flickering while waiting for AI responses.
		const statusUpdateIntervalMs = 3000;
		const minBlogWordCountTarget = 600;
		const minWordCountPenalty = 20;
		const evaluateBlogQuality = () => {
			const title = (($('#mm_blog_preview_title') || {}).value || '').trim();
			const content = (($('#mm_blog_preview_content') || {}).value || '').trim();
			const meta = (($('#mm_blog_preview_meta') || {}).value || '').trim();
			const keyword = (($('#mm_blog_keyword') || {}).value || '').trim().toLowerCase();
			const plainContent = content.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
			const wordCount = plainContent ? plainContent.split(' ').length : 0;
			let score = 100;
			const issues = [];
			if (title.length < 30 || title.length > 65) {
				score -= 15;
				issues.push('Title should ideally be 30–65 characters.');
			}
			if (!keyword) {
				score -= 10;
				issues.push('Primary keyword is missing.');
			} else if (title.toLowerCase().indexOf(keyword) === -1) {
				score -= 10;
				issues.push('Primary keyword is not present in title.');
			}
			if (wordCount < minBlogWordCountTarget) {
				score -= minWordCountPenalty;
				issues.push(`Content is short; target at least ~${minBlogWordCountTarget} words for stronger SEO/AEO coverage.`);
			}
			if (!/<h2[\s>]/i.test(content)) {
				score -= 10;
				issues.push('Missing H2 headings for structure.');
			}
			if (!/<ul[\s>]|<ol[\s>]/i.test(content)) {
				score -= 10;
				issues.push('No list detected; consider adding concise bullet points.');
			}
			if (meta.length < 120 || meta.length > 160) {
				score -= 10;
				issues.push('Meta description should be ~120–160 characters.');
			}
			score = Math.max(0, score);
			if (qualityEl) {
				const badge = score >= 80 ? 'mm-badge-success' : (score >= 60 ? 'mm-badge-warning' : 'mm-badge-danger');
				qualityEl.innerHTML = `<div><strong>Quality score:</strong> <span class="mm-badge ${badge}">${score}/100</span> <span class="mm-text-muted">(SEO/GEO/AIO checks)</span></div>` +
					(issues.length ? `<ul style="margin:8px 0 0 16px;">${issues.map((i) => `<li>${escapeHtml(i)}</li>`).join('')}</ul>` : '<div class="mm-text-muted" style="margin-top:6px;">No major issues found.</div>');
			}
			return { score, issues };
		};

		const syncScheduleVisibility = () => {
			if (!scheduleWrapEl || !postStatusEl) return;
			scheduleWrapEl.style.display = postStatusEl.value === 'future' ? '' : 'none';
			if (postStatusEl.value !== 'future' && scheduleInputEl) {
				scheduleInputEl.value = '';
			}
		};
		syncScheduleVisibility();
		if (postStatusEl) {
			postStatusEl.addEventListener('change', syncScheduleVisibility);
		}

		const loadDrafts = () => {
			if (!draftsEl) return;
			draftsEl.innerHTML = '<p class="mm-text-muted">Loading drafts…</p>';
			ajaxPost('mm_blog_list_drafts').then((res) => {
				if (!res.success || !res.data || !res.data.length) {
					draftsEl.innerHTML = '<p class="mm-text-muted">No drafts yet. Generate one above!</p>';
					return;
				}
				draftsEl.innerHTML = '<table class="mm-table" style="width:100%;"><thead><tr><th>Title</th><th>Words</th><th>Modified</th><th>Actions</th></tr></thead><tbody>' +
					res.data.map((d) => `<tr>
						<td><strong>${escapeHtml(d.title || '(untitled)')}</strong></td>
						<td>${d.word_count || '—'}</td>
						<td>${escapeHtml(d.modified || '')}</td>
						<td style="white-space:nowrap;">
							<a href="${escapeHtml(d.edit_url || '#')}" target="_blank" class="mm-btn mm-btn-sm mm-btn-outline">Edit ↗</a>
							<a href="${escapeHtml(d.preview || '#')}" target="_blank" class="mm-btn mm-btn-sm mm-btn-view">Preview</a>
							<button class="mm-btn mm-btn-sm mm-btn-trash" data-del-draft="${d.id}">🗑</button>
						</td>
					</tr>`).join('') +
					'</tbody></table>';
				draftsEl.querySelectorAll('[data-del-draft]').forEach((b) => {
					b.addEventListener('click', () => {
						if (!confirm('Trash this draft?')) return;
						ajaxPost('mm_blog_delete_draft', { id: b.getAttribute('data-del-draft') }).then((r) => {
							MM.toast(r.success ? 'Draft trashed.' : 'Failed.', r.success ? 'success' : 'error');
							if (r.success) loadDrafts();
						});
					});
				});
			});
		};

		if (draftsEl) loadDrafts();
		if (!generateBtn) return;

		generateBtn.addEventListener('click', async () => {
			const topic   = ($('#mm_blog_topic')    || {}).value || '';
			const keyword = ($('#mm_blog_keyword')  || {}).value || '';
			const length  = ($('#mm_blog_length')   || {}).value || 'medium';
			const tone    = ($('#mm_blog_tone')     || {}).value || 'warm';
			const extra   = ($('#mm_blog_extra')    || {}).value || '';
			const cat     = ($('#mm_blog_category') || {}).value || '';
			if (!topic.trim()) { MM.toast('Enter a topic first.', 'error'); return; }
			generateBtn.disabled = true;
			generateBtn.textContent = '⏳ Generating (30–90s)…';
			let statusTimer = null;
			if (statusEl) {
				const steps = [
					'⏳ Calling AI… please wait.',
					'🧠 Building outline and headings…',
					'✍️ Drafting content and formatting HTML…',
					'🔎 Finalizing SEO metadata…',
				];
				let idx = 0;
				statusEl.textContent = steps[idx];
				statusTimer = setInterval(() => {
					idx = Math.min(idx + 1, steps.length - 1);
					statusEl.textContent = steps[idx];
				}, statusUpdateIntervalMs);
			}
			if (saveBtn) saveBtn.style.display = 'none';
			const res = await ajaxPost('mm_blog_generate', { topic, keyword, length, tone, extra, category: cat });
			if (statusTimer) clearInterval(statusTimer);
			generateBtn.disabled = false;
			generateBtn.textContent = '✨ Generate Draft';
			if (!res.success) {
				const msg = (res.data && res.data.message) ? res.data.message : 'Generation failed.';
				if (statusEl) statusEl.textContent = '❌ ' + msg;
				MM.toast(msg, 'error');
				return;
			}
			const titleEl   = $('#mm_blog_preview_title');
			const contentEl = $('#mm_blog_preview_content');
			const metaEl    = $('#mm_blog_preview_meta');
			if (titleEl)   titleEl.value   = res.data.title   || topic;
			if (contentEl) contentEl.value = res.data.content || '';
			if (metaEl)    metaEl.value    = res.data.meta_description || '';
			if (statusEl) statusEl.textContent = `✅ Draft generated using ${escapeHtml(res.data.model || 'AI')}. Edit if needed, then click Save Post.`;
			if (saveBtn) saveBtn.style.display = '';
			MM.toast('Blog draft generated! Review and save.', 'success');
			evaluateBlogQuality();
		});

		if (saveBtn) saveBtn.addEventListener('click', async () => {
			const title   = ($('#mm_blog_preview_title')   || {}).value || '';
			const content = ($('#mm_blog_preview_content') || {}).value || '';
			const meta    = ($('#mm_blog_preview_meta')    || {}).value || '';
			const cat     = ($('#mm_blog_category')        || {}).value || '';
			const slug    = ($('#mm_blog_slug')            || {}).value || '';
			const status  = ($('#mm_blog_status_select')   || {}).value || 'draft';
			const tags    = ($('#mm_blog_tags')            || {}).value || '';
			const featuredImage = ($('#mm_blog_featured_image') || {}).value || '';
			const excerpt = ($('#mm_blog_excerpt')         || {}).value || '';
			const scheduleAt = ($('#mm_blog_schedule_at')  || {}).value || '';
			if (!title.trim() || !content.trim()) { MM.toast('Title and content are required.', 'error'); return; }
			if (status === 'future' && !scheduleAt) { MM.toast('Select a publish schedule date/time.', 'error'); return; }
			const quality = evaluateBlogQuality();
			if (quality.score < 60 && !confirm('Quality checks found major issues. Save anyway?')) { return; }
			saveBtn.disabled = true;
			saveBtn.textContent = '⏳ Saving…';
			const res = await ajaxPost('mm_blog_save', {
				title,
				content,
				meta_description: meta,
				category: cat,
				slug,
				status,
				tags,
				featured_image: featuredImage,
				excerpt,
				schedule_at: scheduleAt,
			});
			saveBtn.disabled = false;
			saveBtn.textContent = saveBtnDefault;
			if (res.success) {
				MM.toast('✅ Post saved!', 'success');
				const statusLabel = escapeHtml((res.data && res.data.post_status) || status);
				if (statusEl) statusEl.innerHTML = `✅ Saved (${statusLabel}). <a href="${escapeHtml(res.data.edit_url || '#')}" target="_blank">Edit in WordPress ↗</a>`;
				loadDrafts();
			} else {
				const msg = (res.data && res.data.message) ? res.data.message : 'Save failed.';
				MM.toast(msg, 'error');
			}
		});
		['#mm_blog_preview_title', '#mm_blog_preview_content', '#mm_blog_preview_meta', '#mm_blog_keyword'].forEach((selector) => {
			const el = $(selector);
			if (!el) return;
			el.addEventListener('input', evaluateBlogQuality);
		});
		evaluateBlogQuality();
	};

	// Bind Blogs tab + (re)bind SEO when those tabs are active
	document.addEventListener('DOMContentLoaded', () => {
		MM.bindBlogsTab();
		// Rebind SEO so loadScores + targeted-scan handler are wired
		if ($('#seo_score_table_body')) MM.bindSeoTab();
	});

})();
