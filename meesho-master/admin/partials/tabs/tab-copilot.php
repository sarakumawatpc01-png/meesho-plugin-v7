<div class="mm-chat-container">
<div class="mm-chat-header">
<span>🤖 Meesho Copilot</span>
<div class="mm-flex mm-gap-10" style="align-items:center;position:relative;">
<!-- D2: Replaced hardcoded options with single placeholder; JS populates live from OpenRouter -->
<select id="copilot_model_select" class="mm-select" style="width:200px; padding:4px 8px; font-size:12px; border-radius:6px; border:none;">
<option value="">Loading models…</option>
</select>
<!-- D2: Free-only filter checkbox -->
<label style="font-size:12px; display:flex; align-items:center; gap:4px; cursor:pointer;">
<input type="checkbox" id="copilot_free_only"> Free only
</label>
<!-- D3: Undo History button and panel -->
<div style="position:relative;">
<button class="mm-btn mm-btn-outline" id="btn_copilot_undo">↩ Undo History (25)</button>
<div id="mm_undo_panel" class="mm-hidden" style="position:absolute;right:0;top:100%;background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:12px;min-width:360px;max-height:300px;overflow-y:auto;z-index:100;box-shadow:0 4px 20px rgba(0,0,0,.1);">
  <p class="mm-text-muted" style="font-size:12px;margin:0 0 8px;">Last 25 undoable actions (within 7 days). Greyed items are already undone.</p>
  <div id="mm_undo_list">Loading…</div>
</div>
</div>
</div>
</div>
<div class="mm-chat-messages" id="copilot_chat_history">
<div class="mm-chat-msg mm-chat-msg-bot">👋 Hello! I'm your Meesho Master Copilot. Ask me to analyse content, suggest SEO improvements, or prepare admin actions.</div>
</div>
<!-- D4: File attachment preview row -->
<div id="copilot_attachments" class="mm-flex mm-gap-6" style="flex-wrap:wrap;padding:4px 10px 0;min-height:0;"></div>
<div class="mm-chat-input-area">
<!-- D4: Paperclip trigger -->
<label for="copilot_file_input" class="mm-btn mm-btn-outline mm-btn-sm" title="Attach image, PDF, or text file" style="cursor:pointer;padding:6px 10px;">
📎
</label>
<input type="file" id="copilot_file_input" accept="image/*,.pdf,.txt,.html" style="display:none;" multiple>
<input type="text" id="copilot_input" placeholder="Ask Copilot anything..." autocomplete="off">
<button class="mm-btn mm-btn-primary" id="btn_copilot_send">Send</button>
</div>
</div>
<?php $settings = new Meesho_Master_Settings(); $auto = $settings->get( 'copilot_auto_implement' ); ?>
<div class="mm-card mm-mt-20 mm-flex-between">
<div>
<strong>Auto-Implement Mode:</strong>
<span class="mm-badge <?php echo $auto === 'yes' ? 'mm-badge-success' : 'mm-badge-info'; ?>"><?php echo $auto === 'yes' ? 'ON — non-destructive actions auto-applied' : 'OFF — actions need approval'; ?></span>
</div>
<p class="mm-text-muted" style="margin:0; font-size:12px;">Secrets are scrubbed from Copilot output.</p>
</div>
