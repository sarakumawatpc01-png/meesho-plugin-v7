<?php
/**
 * Blogs tab — v6.5 (NEW)
 * AI-powered blog generation. Lists existing posts, lets the user generate
 * a new draft from a topic prompt using master prompts in Settings.
 */
$settings = new Meesho_Master_Settings();
$blog_prompt = $settings->get( 'mm_prompt_blog_master' );
$blog_instr  = $settings->get( 'mm_blog_default_instructions' );
$blog_model  = $settings->get( 'mm_openrouter_model_blog' );
?>
<div class="mm-card">
	<h3>📝 AI Blog Generator</h3>
	<p class="mm-text-muted">Generate SEO-friendly blog drafts from a topic. Uses your <strong>Blog Writer master prompt</strong> + <strong>default instructions</strong> from Settings. Output goes to WordPress as a <code>draft</code> for you to review and publish.</p>
	<?php if ( empty( $blog_model ) ) : ?>
	<div class="mm-notice mm-notice-error" style="background:#fef2f2;color:#991b1b;padding:10px 14px;border-radius:6px;margin-top:10px;">
		⚠️ No AI model assigned for blog generation. <a href="?page=meesho-master&tab=settings">Open Settings</a> → AI Models → assign one.
	</div>
	<?php endif; ?>
</div>

<div class="mm-grid mm-grid-2">
	<div class="mm-card">
		<h3>✍️ Generate New Blog</h3>
		<div class="mm-form-row">
			<label class="mm-label">Topic / Title <span class="mm-text-muted">(required)</span></label>
			<input type="text" id="mm_blog_topic" class="mm-input" placeholder="e.g. 'How to style an Anarkali kurti for festive occasions'">
		</div>
		<div class="mm-form-row">
			<label class="mm-label">Primary Keyword</label>
			<input type="text" id="mm_blog_keyword" class="mm-input" placeholder="e.g. 'anarkali kurti styling'">
		</div>
		<div class="mm-grid mm-grid-2">
			<div class="mm-form-row">
				<label class="mm-label">Target Length</label>
				<select id="mm_blog_length" class="mm-select">
					<option value="short">Short (400–600 words)</option>
					<option value="medium" selected>Medium (800–1200 words)</option>
					<option value="long">Long (1500–2000 words)</option>
				</select>
			</div>
			<div class="mm-form-row">
				<label class="mm-label">Tone</label>
				<select id="mm_blog_tone" class="mm-select">
					<option value="warm">Warm & friendly</option>
					<option value="professional">Professional</option>
					<option value="storytelling">Storytelling</option>
					<option value="how_to">How-to / instructional</option>
				</select>
			</div>
		</div>
		<div class="mm-form-row">
			<label class="mm-label">Extra instructions (optional)</label>
			<textarea id="mm_blog_extra" class="mm-textarea" rows="3" placeholder="e.g. 'Mention 3 internal links to category pages. Include a Diwali angle. Add 4 FAQs.'"></textarea>
		</div>
		<div class="mm-form-row">
			<label class="mm-label">Category</label>
			<?php
			wp_dropdown_categories( array(
				'show_option_all' => '— Uncategorized —',
				'hide_empty'      => 0,
				'name'            => 'mm_blog_category',
				'id'              => 'mm_blog_category',
				'class'           => 'mm-select',
			) );
			?>
		</div>
		<div class="mm-grid mm-grid-2">
			<div class="mm-form-row">
				<label class="mm-label">Slug</label>
				<input type="text" id="mm_blog_slug" class="mm-input" placeholder="auto-from-title-if-empty">
			</div>
			<div class="mm-form-row">
				<label class="mm-label">Status</label>
				<select id="mm_blog_status_select" class="mm-select">
					<option value="draft" selected>Draft</option>
					<option value="pending">Pending Review</option>
					<option value="publish">Publish Now</option>
					<option value="future">Schedule</option>
				</select>
			</div>
		</div>
		<div class="mm-form-row">
			<label class="mm-label">Tags (comma-separated)</label>
			<input type="text" id="mm_blog_tags" class="mm-input" placeholder="fashion, festive wear, styling tips">
		</div>
		<div class="mm-grid mm-grid-2">
			<div class="mm-form-row">
				<label class="mm-label">Featured image URL (optional)</label>
				<input type="url" id="mm_blog_featured_image" class="mm-input" placeholder="https://example.com/image.jpg">
				<div class="mm-text-muted" style="font-size:11px;margin-top:4px;">If this URL already exists in WordPress Media Library, it will be set as featured image; otherwise only the URL is saved in post meta for later use.</div>
			</div>
			<div class="mm-form-row" id="mm_blog_schedule_wrap" style="display:none;">
				<label class="mm-label">Publish schedule</label>
				<input type="datetime-local" id="mm_blog_schedule_at" class="mm-input">
			</div>
		</div>
		<div class="mm-form-row">
			<label class="mm-label">Excerpt (optional)</label>
			<textarea id="mm_blog_excerpt" class="mm-textarea" rows="2" placeholder="Short excerpt for post summary cards."></textarea>
		</div>
		<button class="mm-btn mm-btn-primary" id="mm_blog_generate_btn">✨ Generate Draft</button>
		<button class="mm-btn mm-btn-outline" id="mm_blog_save_btn" style="display:none;">💾 Save Post</button>
		<div id="mm_blog_status" class="mm-text-muted mm-mt-10" style="font-size:13px;"></div>
		<div id="mm_blog_quality_report" class="mm-card mm-mt-10" style="background:#f8fafc;padding:12px;"></div>
	</div>

	<div class="mm-card">
		<h3>👁️ Preview / Edit</h3>
		<p class="mm-text-muted" style="font-size:12px;">Generated content appears here. Edit before saving.</p>
		<div class="mm-form-row">
			<label class="mm-label">Title</label>
			<input type="text" id="mm_blog_preview_title" class="mm-input" placeholder="(generated title appears here)">
		</div>
		<div class="mm-form-row">
			<label class="mm-label">Content (HTML)</label>
			<textarea id="mm_blog_preview_content" class="mm-textarea" rows="14" placeholder="(generated content appears here)" style="font-family:monospace;font-size:12px;"></textarea>
		</div>
		<div class="mm-form-row">
			<label class="mm-label">SEO Meta Description</label>
			<textarea id="mm_blog_preview_meta" class="mm-textarea" rows="2" placeholder="(generated meta description appears here)"></textarea>
		</div>
	</div>
</div>

<!-- Master prompts read-only preview -->
<div class="mm-card mm-mt-20">
	<h4>🎯 Active Prompt Configuration <span class="mm-text-muted" style="font-size:12px;font-weight:normal;">(edit in Settings)</span></h4>
	<details>
		<summary style="cursor:pointer;font-weight:600;">Master Prompt (system message)</summary>
		<pre style="background:#f8fafc;padding:10px;border-radius:6px;white-space:pre-wrap;font-size:12px;margin-top:8px;"><?php echo esc_html( $blog_prompt ?: '(empty — set in Settings)' ); ?></pre>
	</details>
	<details>
		<summary style="cursor:pointer;font-weight:600;">Default Instructions (appended to every prompt)</summary>
		<pre style="background:#f8fafc;padding:10px;border-radius:6px;white-space:pre-wrap;font-size:12px;margin-top:8px;"><?php echo esc_html( $blog_instr ?: '(empty — set in Settings)' ); ?></pre>
	</details>
</div>

<!-- Recent drafts list -->
<div class="mm-card mm-mt-20">
	<h3>📋 Recent Blog Drafts</h3>
	<div id="mm_blog_drafts">
		<p class="mm-text-muted">Loading…</p>
	</div>
</div>
